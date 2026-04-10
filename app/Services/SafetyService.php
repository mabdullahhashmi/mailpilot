<?php

namespace App\Services;

use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use App\Models\Domain;
use App\Models\WarmupEvent;
use App\Models\PauseRule;
use App\Models\WarmupCampaign;
use Illuminate\Support\Facades\Log;

class SafetyService
{
    /**
     * Apply sender daily cap. Returns the adjusted budget.
     */
    public function applySenderCap(SenderMailbox $sender, int $requested, string $actionType): int
    {
        $cap = $actionType === 'send'
            ? $sender->warmup_target_daily
            : ($sender->warmup_target_daily * 2); // replies can be double sends

        $used = WarmupEvent::where('actor_mailbox_id', $sender->id)
            ->where('actor_type', 'sender')
            ->where('status', 'completed')
            ->whereDate('executed_at', today())
            ->count();

        return max(0, min($requested, $cap - $used));
    }

    /**
     * Get remaining budget for a domain today.
     */
    public function getDomainRemainingBudget(Domain $domain): int
    {
        $cap = $domain->daily_sending_cap ?? 50;

        $used = WarmupEvent::whereHas('warmupCampaign', function ($q) use ($domain) {
            $q->where('domain_id', $domain->id);
        })
        ->where('status', 'completed')
        ->whereDate('executed_at', today())
        ->count();

        return max(0, $cap - $used);
    }

    /**
     * Assert that sender can send. Throws on failure.
     */
    public function assertCanSend(SenderMailbox $sender, Domain $domain): void
    {
        if ($sender->status !== 'active') {
            throw new \RuntimeException("Sender #{$sender->id} is not active (status: {$sender->status})");
        }

        if ($this->applySenderCap($sender, 1, 'send') < 1) {
            throw new \RuntimeException("Sender #{$sender->id} has reached daily send cap");
        }

        if ($this->getDomainRemainingBudget($domain) < 1) {
            throw new \RuntimeException("Domain #{$domain->id} has reached daily cap");
        }

        $pauseRule = PauseRule::where('pauseable_type', SenderMailbox::class)
            ->where('pauseable_id', $sender->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('pause_until')
                  ->orWhere('pause_until', '>', now());
            })
            ->first();

        if ($pauseRule) {
            throw new \RuntimeException("Sender #{$sender->id} is paused: {$pauseRule->reason}");
        }
    }

    /**
     * Assert that seed can interact.
     */
    public function assertCanInteract(SeedMailbox $seed, Domain $domain): void
    {
        if ($seed->status !== 'active') {
            throw new \RuntimeException("Seed #{$seed->id} is not active (status: {$seed->status})");
        }
    }

    /**
     * Handle a final failure event (all retries exhausted).
     * Auto-pause sender if consecutive failures exceed threshold.
     */
    public function handleEventFinalFailure(WarmupEvent $event): void
    {
        $threshold = 3; // Auto-pause after 3 consecutive final failures

        $recentFailures = WarmupEvent::where('actor_mailbox_id', $event->actor_mailbox_id)
            ->where('actor_type', $event->actor_type)
            ->where('status', 'final_failed')
            ->where('executed_at', '>=', now()->subHours(24))
            ->count();

        if ($recentFailures >= $threshold) {
            $modelClass = $event->actor_type === 'sender'
                ? SenderMailbox::class
                : SeedMailbox::class;

            $mailbox = $modelClass::find($event->actor_mailbox_id);

            if ($mailbox) {
                $mailbox->update(['status' => 'paused']);

                PauseRule::create([
                    'pauseable_type' => $modelClass,
                    'pauseable_id' => $event->actor_mailbox_id,
                    'reason' => "Auto-paused: {$recentFailures} consecutive failures in 24h",
                    'pause_type' => 'error_threshold',
                    'is_active' => true,
                    'pause_until' => now()->addHours(24),
                ]);

                Log::warning("SafetyService: Auto-paused {$event->actor_type} #{$event->actor_mailbox_id} after {$recentFailures} failures");
            }
        }
    }

    /**
     * Check growth rate safety. Returns true if within safe bounds.
     */
    public function checkGrowthRate(WarmupCampaign $campaign): bool
    {
        $maxGrowthPercent = 30; // Max 30% daily growth

        $yesterday = WarmupEvent::where('warmup_campaign_id', $campaign->id)
            ->where('status', 'completed')
            ->whereDate('executed_at', today()->subDay())
            ->count();

        $today = WarmupEvent::where('warmup_campaign_id', $campaign->id)
            ->where('status', 'completed')
            ->whereDate('executed_at', today())
            ->count();

        if ($yesterday === 0) return true;

        $growthRate = (($today - $yesterday) / $yesterday) * 100;

        return $growthRate <= $maxGrowthPercent;
    }
}
