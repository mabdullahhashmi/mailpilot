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
                $q->where('is_active', true)
                  ->where(function ($q2) {
                      $q2->whereNull('pause_until')
                        ->orWhere('pause_until', '>', now());
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
        $byProvider = $eligibleSeeds->groupBy('provider');

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

        // Log usage
        foreach ($selected as $seed) {
            SeedUsageLog::create([
                'seed_mailbox_id' => $seed->id,
                'sender_mailbox_id' => $sender->id,
                'domain_id' => $domain->id,
                'used_date' => today(),
                'action_type' => 'allocated',
            ]);
        }

        return $selected;
    }

    private function hasNotExceededDailyCap(SeedMailbox $seed): bool
    {
        $dailyCap = $seed->daily_total_interaction_cap ?? 20;

        $todayCount = SeedUsageLog::where('seed_mailbox_id', $seed->id)
            ->where(function ($q) {
                $q->where('used_date', today())
                  ->orWhere('log_date', today());
            })
            ->count();

        return $todayCount < $dailyCap;
    }

    private function respectsPairCooldown(SenderMailbox $sender, SeedMailbox $seed): bool
    {
        $cooldownDays = 2;

        $recentPairing = SeedUsageLog::where('seed_mailbox_id', $seed->id)
            ->where('sender_mailbox_id', $sender->id)
            ->where('used_date', '>=', today()->subDays($cooldownDays))
            ->exists();

        return !$recentPairing;
    }
}
