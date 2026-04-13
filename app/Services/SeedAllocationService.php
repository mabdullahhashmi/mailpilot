<?php

namespace App\Services;

use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use App\Models\Domain;
use App\Models\SeedUsageLog;
use Illuminate\Support\Collection;

class SeedAllocationService
{
    /**
     * Get eligible seeds for a sender+domain, respecting:
     * - No same domain (sender and seed must differ)
     * - Seed not paused
     * - Seed daily cap not reached
     * - Pair repetition cooldown
     * - Provider diversity preference
     */
    public function getEligibleSeeds(SenderMailbox $sender, Domain $domain): Collection
    {
        $senderDomainName = explode('@', $sender->email_address)[1] ?? '';

        return SeedMailbox::where('status', 'active')
            ->where('email_address', 'NOT LIKE', "%@{$senderDomainName}")
            ->whereDoesntHave('pauseRules', function ($q) {
                $q->where('status', 'active')
                  ->where(function ($q2) {
                      $q2->whereNull('auto_resume_at')
                        ->orWhere('auto_resume_at', '>', now());
                  });
            })
            ->get()
            ->filter(function (SeedMailbox $seed) use ($sender) {
                return $this->hasNotExceededDailyCap($seed);
            });
    }

    /**
     * From eligible seeds, allocate N seeds with provider diversity.
     */
    public function allocateSeeds(SenderMailbox $sender, Domain $domain, Collection $eligibleSeeds, int $count): Collection
    {
        if ($count <= 0 || $eligibleSeeds->isEmpty()) {
            return collect();
        }

        // Strict anti-repeat pool first (avoid same sender-seed pairing in cooldown window).
        $strictPool = $eligibleSeeds
            ->filter(fn (SeedMailbox $seed) => $this->respectsPairCooldown($sender, $seed))
            ->values();

        // If strict pool is too small, gracefully fallback to all eligible seeds.
        $allocationPool = $strictPool->count() >= $count ? $strictPool : $eligibleSeeds->values();

        // Rank by recency/usage and then pick with provider diversity.
        $ranked = $this->rankSeedsForSender($sender, $allocationPool);
        $byProvider = $ranked
            ->groupBy(fn (SeedMailbox $seed) => $seed->provider_type ?: 'other')
            ->map(fn (Collection $group) => $group->values());

        $selected = collect();

        // Round-robin across providers to avoid overusing one provider's seeds.
        while ($selected->count() < $count) {
            $pickedInRound = false;

            foreach ($byProvider as $provider => $pool) {
                if ($selected->count() >= $count) {
                    break;
                }

                if ($pool->isEmpty()) {
                    continue;
                }

                $selected->push($pool->shift());
                $byProvider[$provider] = $pool;
                $pickedInRound = true;
            }

            if (!$pickedInRound) {
                break;
            }
        }

        $selected = $selected->values();

        // Log usage (updateOrCreate since unique constraint on [seed_mailbox_id, log_date])
        foreach ($selected as $seed) {
            $log = SeedUsageLog::updateOrCreate(
                ['seed_mailbox_id' => $seed->id, 'log_date' => today()],
                []
            );

            $log->increment('interactions_today');

            // Track per-sender and per-domain usage in JSON columns
            $perSender = $log->per_sender_usage ?? [];
            $perSender[$sender->id] = ($perSender[$sender->id] ?? 0) + 1;
            $perDomain = $log->per_domain_usage ?? [];
            $perDomain[$domain->id] = ($perDomain[$domain->id] ?? 0) + 1;

            $log->update([
                'per_sender_usage' => $perSender,
                'per_domain_usage' => $perDomain,
            ]);
        }

        return $selected;
    }

    private function rankSeedsForSender(SenderMailbox $sender, Collection $seeds): Collection
    {
        $seedIds = $seeds->pluck('id')->values();
        if ($seedIds->isEmpty()) {
            return collect();
        }

        $recentLogs = SeedUsageLog::whereIn('seed_mailbox_id', $seedIds)
            ->where('log_date', '>=', today()->subDays(14))
            ->orderByDesc('log_date')
            ->get()
            ->groupBy('seed_mailbox_id');

        $metrics = [];

        foreach ($seeds as $seed) {
            $logs = $recentLogs->get($seed->id, collect());

            $lastUsedBySenderDays = null;
            $interactionsToday = 0;
            $interactions14d = 0;

            foreach ($logs as $log) {
                $interactions14d += (int) ($log->interactions_today ?? 0);

                if ($log->log_date && $log->log_date->isToday()) {
                    $interactionsToday = (int) ($log->interactions_today ?? 0);
                }

                $perSender = $log->per_sender_usage ?? [];
                $senderUsage = (int) ($perSender[$sender->id] ?? $perSender[(string) $sender->id] ?? 0);

                if ($senderUsage > 0 && $log->log_date) {
                    $daysAgo = (int) $log->log_date->diffInDays(today());
                    $lastUsedBySenderDays = $lastUsedBySenderDays === null
                        ? $daysAgo
                        : min($lastUsedBySenderDays, $daysAgo);
                }
            }

            $metrics[$seed->id] = [
                'never_used_by_sender' => $lastUsedBySenderDays === null,
                'last_used_by_sender_days' => $lastUsedBySenderDays ?? -1,
                'interactions_today' => $interactionsToday,
                'interactions_14d' => $interactions14d,
            ];
        }

        return $seeds->sort(function (SeedMailbox $a, SeedMailbox $b) use ($metrics) {
            $ma = $metrics[$a->id] ?? [];
            $mb = $metrics[$b->id] ?? [];

            // Prefer seeds this sender has never used.
            if (($ma['never_used_by_sender'] ?? false) !== ($mb['never_used_by_sender'] ?? false)) {
                return ($mb['never_used_by_sender'] ?? false) <=> ($ma['never_used_by_sender'] ?? false);
            }

            // Then prefer seeds used longest ago by this sender.
            if (($ma['last_used_by_sender_days'] ?? -1) !== ($mb['last_used_by_sender_days'] ?? -1)) {
                return ($mb['last_used_by_sender_days'] ?? -1) <=> ($ma['last_used_by_sender_days'] ?? -1);
            }

            // Then prefer less-loaded seeds today and in the past 14 days.
            if (($ma['interactions_today'] ?? 0) !== ($mb['interactions_today'] ?? 0)) {
                return ($ma['interactions_today'] ?? 0) <=> ($mb['interactions_today'] ?? 0);
            }
            if (($ma['interactions_14d'] ?? 0) !== ($mb['interactions_14d'] ?? 0)) {
                return ($ma['interactions_14d'] ?? 0) <=> ($mb['interactions_14d'] ?? 0);
            }

            return $a->id <=> $b->id;
        })->values();
    }

    private function hasNotExceededDailyCap(SeedMailbox $seed): bool
    {
        $dailyCap = $seed->daily_total_interaction_cap ?? 20;

        $todayLog = SeedUsageLog::where('seed_mailbox_id', $seed->id)
            ->where('log_date', today())
            ->first();

        $todayCount = $todayLog?->interactions_today ?? 0;

        return $todayCount < $dailyCap;
    }

    private function respectsPairCooldown(SenderMailbox $sender, SeedMailbox $seed): bool
    {
        $cooldownDays = 3;

        // Check if this sender appeared in recent per_sender_usage
        $recentLogs = SeedUsageLog::where('seed_mailbox_id', $seed->id)
            ->where('log_date', '>=', today()->subDays($cooldownDays))
            ->get();

        foreach ($recentLogs as $log) {
            $perSender = $log->per_sender_usage ?? [];
            $usage = (int) ($perSender[$sender->id] ?? $perSender[(string) $sender->id] ?? 0);
            if ($usage > 0) {
                return false;
            }
        }

        // Fallback guard: if logs are incomplete, still avoid repeating same pair too soon.
        $recentPairThreadExists = \App\Models\Thread::where('sender_mailbox_id', $sender->id)
            ->where('seed_mailbox_id', $seed->id)
            ->whereDate('created_at', '>=', today()->subDays($cooldownDays))
            ->exists();

        if ($recentPairThreadExists) {
            return false;
        }

        return true;
    }
}
