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
                return $this->hasNotExceededDailyCap($seed)
                    && $this->respectsPairCooldown($sender, $seed);
            });
    }

    /**
     * From eligible seeds, allocate N seeds with provider diversity.
     */
    public function allocateSeeds(SenderMailbox $sender, Domain $domain, Collection $eligibleSeeds, int $count): Collection
    {
        if ($eligibleSeeds->count() <= $count) {
            return $eligibleSeeds;
        }

        // Group by provider
        $byProvider = $eligibleSeeds->groupBy('provider_type');

        $selected = collect();
        $remaining = $count;

        // Distribute across providers proportionally
        $providerCount = $byProvider->count();
        $perProvider = max(1, intdiv($count, $providerCount));

        foreach ($byProvider as $provider => $seeds) {
            $take = min($perProvider, $remaining, $seeds->count());
            $picked = $seeds->shuffle()->take($take);
            $selected = $selected->merge($picked);
            $remaining -= $take;

            if ($remaining <= 0) break;
        }

        // Fill remainder from any remaining seeds
        if ($remaining > 0) {
            $usedIds = $selected->pluck('id');
            $leftover = $eligibleSeeds->whereNotIn('id', $usedIds)->shuffle()->take($remaining);
            $selected = $selected->merge($leftover);
        }

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
        $cooldownDays = 2;

        // Check if this sender appeared in recent per_sender_usage
        $recentLogs = SeedUsageLog::where('seed_mailbox_id', $seed->id)
            ->where('log_date', '>=', today()->subDays($cooldownDays))
            ->get();

        foreach ($recentLogs as $log) {
            $perSender = $log->per_sender_usage ?? [];
            if (isset($perSender[$sender->id]) && $perSender[$sender->id] > 0) {
                return false;
            }
        }

        return true;
    }
}
