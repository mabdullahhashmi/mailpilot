<?php

namespace App\Services;

use App\Models\SeedMailbox;
use App\Models\Thread;
use App\Models\WarmupEvent;
use App\Models\WarmupEventLog;
use App\Models\SystemAlert;
use Illuminate\Support\Facades\Log;

class SeedHealthService
{
    /**
     * Calculate and update seed health score.
     * Score: 0-100 based on reply success rate, IMAP availability, and interaction patterns.
     */
    public function calculateSeedHealth(SeedMailbox $seed): int
    {
        $score = 100;

        // Penalize high failure rate
        $totalInteractions = max(1, ($seed->total_replies_sent ?? 0) + ($seed->total_opens ?? 0) + ($seed->failed_interactions ?? 0));
        $failureRate = $seed->failed_interactions / $totalInteractions;
        $score -= min(40, (int)($failureRate * 100));

        // Penalize if no recent activity (stale seed)
        if ($seed->last_used_at && $seed->last_used_at->diffInDays(now()) > 7) {
            $staleDays = min(14, $seed->last_used_at->diffInDays(now()) - 7);
            $score -= $staleDays * 2; // -2 per stale day beyond 7
        }

        // Bonus for consistent replies
        if ($seed->total_replies_sent > 5) {
            $score += 5;
        }

        // Penalize if seed is overused (too many concurrent threads)
        $activeThreads = Thread::where('seed_mailbox_id', $seed->id)
            ->where('thread_status', 'active')
            ->count();

        if ($activeThreads > $seed->concurrent_thread_cap) {
            $score -= 10;
        }

        // IMAP test failure penalty
        $recentImapFailures = WarmupEventLog::where('outcome', 'failure')
            ->where('details', 'like', '%IMAP%')
            ->whereHas('event', fn ($q) => $q->where('actor_mailbox_id', $seed->id)->where('actor_type', 'seed'))
            ->where('created_at', '>=', now()->subDays(3))
            ->count();

        $score -= min(20, $recentImapFailures * 5);

        return max(0, min(100, $score));
    }

    /**
     * Run health check on all active seeds and auto-disable problematic ones.
     */
    public function checkAllSeeds(): array
    {
        $seeds = SeedMailbox::where('status', 'active')->get();
        $results = ['checked' => 0, 'disabled' => 0, 'warnings' => 0];

        foreach ($seeds as $seed) {
            $results['checked']++;

            $healthScore = $this->calculateSeedHealth($seed);
            $seed->update([
                'seed_health_score' => $healthScore,
                'last_health_check_at' => now(),
            ]);

            // Auto-disable if health score critically low
            if ($healthScore < 20 && $seed->failed_interactions >= 5) {
                $this->autoDisableSeed($seed, "Health score critically low ({$healthScore}/100) with {$seed->failed_interactions} failures");
                $results['disabled']++;
            }

            // Warn if score is degraded
            if ($healthScore < 50 && $healthScore >= 20) {
                $results['warnings']++;
                Log::warning("[SeedHealth] Seed #{$seed->id} ({$seed->email_address}) degraded: score {$healthScore}");
            }
        }

        return $results;
    }

    /**
     * Auto-disable a seed and create alert.
     */
    public function autoDisableSeed(SeedMailbox $seed, string $reason): void
    {
        $seed->update([
            'status' => 'disabled',
            'is_paused' => true,
            'auto_disabled_at' => now(),
            'auto_disable_reason' => $reason,
        ]);

        SystemAlert::create([
            'title' => "Seed auto-disabled: {$seed->email_address}",
            'message' => $reason,
            'severity' => 'warning',
            'context_type' => 'seed_mailbox',
            'context_id' => $seed->id,
        ]);

        Log::info("[SeedHealth] Auto-disabled seed #{$seed->id} ({$seed->email_address}): {$reason}");
    }

    /**
     * Record a failed interaction for a seed.
     */
    public function recordFailure(SeedMailbox $seed): void
    {
        $seed->increment('failed_interactions');

        // Check if this triggers auto-disable
        $healthScore = $this->calculateSeedHealth($seed);
        $seed->update(['seed_health_score' => $healthScore]);

        if ($healthScore < 20 && $seed->failed_interactions >= 5) {
            $this->autoDisableSeed($seed, "Auto-disabled after {$seed->failed_interactions} failures (score: {$healthScore})");
        }
    }

    /**
     * Record a successful interaction (open/reply).
     */
    public function recordSuccess(SeedMailbox $seed, string $type = 'reply'): void
    {
        if ($type === 'reply') {
            $seed->increment('total_replies_sent');
        } elseif ($type === 'open') {
            $seed->increment('total_opens');
        }
    }

    /**
     * Re-enable a previously disabled seed after manual review.
     */
    public function reEnableSeed(SeedMailbox $seed): void
    {
        $seed->update([
            'status' => 'active',
            'is_paused' => false,
            'auto_disabled_at' => null,
            'auto_disable_reason' => null,
            'failed_interactions' => 0,
            'seed_health_score' => 70,
        ]);
    }

    /**
     * Get seeds ranked by health score (worst first).
     */
    public function getSeedHealthReport(): array
    {
        $seeds = SeedMailbox::orderBy('seed_health_score', 'asc')->get();

        return $seeds->map(fn ($s) => [
            'id' => $s->id,
            'email' => $s->email_address,
            'status' => $s->status,
            'health_score' => $s->seed_health_score,
            'reply_quality' => $s->reply_quality_score,
            'total_interactions' => $s->total_interactions,
            'failed_interactions' => $s->failed_interactions,
            'total_replies' => $s->total_replies_sent,
            'last_used' => $s->last_used_at?->diffForHumans(),
            'auto_disabled_at' => $s->auto_disabled_at?->toDateTimeString(),
            'auto_disable_reason' => $s->auto_disable_reason,
        ])->toArray();
    }
}
