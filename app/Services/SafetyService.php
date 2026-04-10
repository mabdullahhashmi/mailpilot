<?php

namespace App\Services;

use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use App\Models\Domain;
use App\Models\WarmupEvent;
use App\Models\PauseRule;
use App\Models\WarmupCampaign;
use App\Models\SystemAlert;
use App\Models\MailboxHealthLog;
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

        $pauseRule = PauseRule::where('pausable_type', SenderMailbox::class)
            ->where('pausable_id', $sender->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('auto_resume_at')
                  ->orWhere('auto_resume_at', '>', now());
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
                    'pausable_type' => $modelClass,
                    'pausable_id' => $event->actor_mailbox_id,
                    'reason' => 'repeated_failures',
                    'details' => "Auto-paused: {$recentFailures} consecutive failures in 24h",
                    'paused_at' => now(),
                    'auto_resume_at' => now()->addHours(24),
                    'status' => 'active',
                ]);

                SystemAlert::fire(
                    'critical',
                    'Mailbox Auto-Paused',
                    "{$event->actor_type} #{$event->actor_mailbox_id} ({$mailbox->email_address}) auto-paused after {$recentFailures} consecutive failures in 24h",
                    $event->actor_type === 'sender' ? 'sender_mailbox' : 'seed_mailbox',
                    $event->actor_mailbox_id
                );

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

    /**
     * Check bounce and spam rate thresholds and auto-pause sender if exceeded.
     * Called after each bounce or spam report.
     */
    public function checkDeliverabilityThresholds(SenderMailbox $sender): ?string
    {
        if (!$sender->auto_pause_on_threshold) {
            return null;
        }

        $log = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->where('log_date', today())
            ->first();

        if (!$log || $log->sends_today < 3) {
            return null; // Not enough data to evaluate
        }

        $bounceRate = ($log->bounces_today / $log->sends_today) * 100;
        $spamRate = ($log->spam_reports_today / $log->sends_today) * 100;
        $reason = null;

        if ($bounceRate >= $sender->bounce_rate_threshold) {
            $reason = "Bounce rate {$bounceRate}% exceeds threshold {$sender->bounce_rate_threshold}%";
        } elseif ($spamRate >= $sender->spam_rate_threshold) {
            $reason = "Spam rate {$spamRate}% exceeds threshold {$sender->spam_rate_threshold}%";
        }

        if ($reason) {
            $this->autoPauseSender($sender, $reason);
            return $reason;
        }

        return null;
    }

    /**
     * Auto-pause a sender with tracking and alert.
     */
    public function autoPauseSender(SenderMailbox $sender, string $reason): void
    {
        $sender->update([
            'status' => 'paused',
            'is_paused' => true,
            'auto_paused_at' => now(),
            'auto_pause_reason' => $reason,
            'ramp_down_active' => true,
            'consecutive_clean_days' => 0,
        ]);

        PauseRule::create([
            'pausable_type' => SenderMailbox::class,
            'pausable_id' => $sender->id,
            'reason' => 'threshold_breach',
            'details' => $reason,
            'paused_at' => now(),
            'auto_resume_at' => now()->addHours(48),
            'status' => 'active',
        ]);

        SystemAlert::create([
            'title' => "Sender auto-paused: {$sender->email_address}",
            'message' => $reason,
            'severity' => 'critical',
            'context_type' => 'sender_mailbox',
            'context_id' => $sender->id,
        ]);

        Log::warning("[Safety] Auto-paused sender #{$sender->id} ({$sender->email_address}): {$reason}");
    }

    /**
     * Apply ramp-down: reduce daily capacity to a percentage after being un-paused.
     * Returns the effective daily cap after ramp-down.
     */
    public function getRampDownCap(SenderMailbox $sender): int
    {
        if (!$sender->ramp_down_active) {
            return $sender->daily_send_cap;
        }

        // Gradual recovery: start at ramp_down_percentage%, add 10% per clean day
        $recoveryPercent = min(100, $sender->ramp_down_percentage + ($sender->consecutive_clean_days * 10));
        $effectiveCap = max(1, (int)ceil($sender->daily_send_cap * $recoveryPercent / 100));

        // If fully recovered, disable ramp-down
        if ($recoveryPercent >= 100) {
            $sender->update([
                'ramp_down_active' => false,
                'ramp_down_percentage' => 50,
            ]);
        }

        return $effectiveCap;
    }

    /**
     * End-of-day: check if sender had a clean day (no bounces/spam) and increment counter.
     * Called by the health cron.
     */
    public function evaluateDailyRecovery(SenderMailbox $sender): void
    {
        if (!$sender->ramp_down_active) {
            return;
        }

        $log = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->where('log_date', today())
            ->first();

        if (!$log) return;

        $isClean = $log->bounces_today === 0 && $log->spam_reports_today === 0 && $log->sends_today > 0;

        if ($isClean) {
            $sender->increment('consecutive_clean_days');
            Log::info("[Safety] Sender #{$sender->id} clean day #{$sender->consecutive_clean_days}");
        } else {
            $sender->update(['consecutive_clean_days' => 0]);
        }
    }
}
