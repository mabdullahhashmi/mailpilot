<?php

namespace App\Services;

use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use App\Models\Domain;
use App\Models\SeedUsageLog;
use App\Models\PauseRule;
use App\Models\Thread;
use Illuminate\Support\Collection;

class SeedAllocationService
{
    private const PREFERRED_UNIQUE_SEEDS_PER_DOMAIN = 1;
    private const MAX_UNIQUE_SEEDS_PER_DOMAIN = 2;

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
     * Build an explainable eligibility report for a sender/domain.
     * Includes per-seed reasons so UI can show why a seed is blocked.
     */
    public function getEligibilityReport(SenderMailbox $sender, Domain $domain, ?string $date = null): array
    {
        $forDate = $date
            ? \Carbon\Carbon::parse($date)->toDateString()
            : today()->toDateString();

        $senderDomainName = strtolower(explode('@', $sender->email_address)[1] ?? ($domain->domain_name ?? ''));

        $seeds = SeedMailbox::orderBy('email_address')
            ->get([
                'id', 'email_address', 'provider_type', 'status',
                'daily_total_interaction_cap',
            ]);

        $seedIds = $seeds->pluck('id')->values();

        if ($seedIds->isEmpty()) {
            return [
                'date' => $forDate,
                'summary' => [
                    'total' => 0,
                    'eligible_base' => 0,
                    'eligible_strict' => 0,
                    'blocked' => 0,
                    'blocked_same_domain' => 0,
                    'blocked_paused' => 0,
                    'blocked_daily_cap' => 0,
                    'blocked_cooldown' => 0,
                ],
                'seeds' => [],
            ];
        }

        $logsBySeed = SeedUsageLog::whereIn('seed_mailbox_id', $seedIds)
            ->where('log_date', $forDate)
            ->get()
            ->keyBy('seed_mailbox_id');

        $pausedSeedIds = PauseRule::where('pausable_type', SeedMailbox::class)
            ->whereIn('pausable_id', $seedIds)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('auto_resume_at')
                    ->orWhere('auto_resume_at', '>', now());
            })
            ->pluck('pausable_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $cooldownDays = 3;
        $cooldownFromDate = \Carbon\Carbon::parse($forDate)->subDays($cooldownDays)->toDateString();

        $recentLogsBySeed = SeedUsageLog::whereIn('seed_mailbox_id', $seedIds)
            ->where('log_date', '>=', $cooldownFromDate)
            ->get()
            ->groupBy('seed_mailbox_id');

        $recentPairThreadSeedIds = Thread::where('sender_mailbox_id', $sender->id)
            ->whereIn('seed_mailbox_id', $seedIds)
            ->whereDate('created_at', '>=', $cooldownFromDate)
            ->pluck('seed_mailbox_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $rows = [];

        foreach ($seeds as $seed) {
            $email = strtolower((string) $seed->email_address);
            $isActive = $seed->status === 'active';
            $isSameDomain = $senderDomainName !== '' && str_ends_with($email, "@{$senderDomainName}");
            $isPaused = $pausedSeedIds->has((int) $seed->id);

            $dailyCap = (int) ($seed->daily_total_interaction_cap ?? 20);
            $usedToday = (int) ($logsBySeed->get((int) $seed->id)?->interactions_today ?? 0);
            $withinDailyCap = $usedToday < $dailyCap;

            $pairCooldownOk = true;

            $recentLogs = $recentLogsBySeed->get((int) $seed->id, collect());
            foreach ($recentLogs as $log) {
                $perSender = $log->per_sender_usage ?? [];
                $usage = (int) ($perSender[$sender->id] ?? $perSender[(string) $sender->id] ?? 0);
                if ($usage > 0) {
                    $pairCooldownOk = false;
                    break;
                }
            }

            if ($pairCooldownOk && $recentPairThreadSeedIds->has((int) $seed->id)) {
                $pairCooldownOk = false;
            }

            $eligibleBase = $isActive && !$isSameDomain && !$isPaused && $withinDailyCap;
            $eligibleStrict = $eligibleBase && $pairCooldownOk;

            $reasons = [];
            if (!$isActive) {
                $reasons[] = 'seed_not_active';
            }
            if ($isSameDomain) {
                $reasons[] = 'same_domain';
            }
            if ($isPaused) {
                $reasons[] = 'paused';
            }
            if (!$withinDailyCap) {
                $reasons[] = 'daily_cap_reached';
            }
            if (!$pairCooldownOk) {
                $reasons[] = 'sender_seed_cooldown';
            }

            $rows[] = [
                'seed_id' => (int) $seed->id,
                'seed_email' => $seed->email_address,
                'provider_type' => $seed->provider_type,
                'status' => $seed->status,
                'daily_cap' => $dailyCap,
                'used_today' => $usedToday,
                'remaining_today' => max(0, $dailyCap - $usedToday),
                'same_domain' => $isSameDomain,
                'paused' => $isPaused,
                'cooldown_blocked' => !$pairCooldownOk,
                'eligible_base' => $eligibleBase,
                'eligible_strict' => $eligibleStrict,
                'reasons' => $reasons,
            ];
        }

        $summary = [
            'total' => count($rows),
            'eligible_base' => count(array_filter($rows, fn ($r) => $r['eligible_base'])),
            'eligible_strict' => count(array_filter($rows, fn ($r) => $r['eligible_strict'])),
            'blocked' => count(array_filter($rows, fn ($r) => !$r['eligible_base'])),
            'blocked_same_domain' => count(array_filter($rows, fn ($r) => $r['same_domain'])),
            'blocked_paused' => count(array_filter($rows, fn ($r) => $r['paused'])),
            'blocked_daily_cap' => count(array_filter($rows, fn ($r) => $r['used_today'] >= $r['daily_cap'])),
            'blocked_cooldown' => count(array_filter($rows, fn ($r) => $r['cooldown_blocked'])),
        ];

        return [
            'date' => $forDate,
            'summary' => $summary,
            'seeds' => $rows,
        ];
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
        $domainConstrainedRanked = $this->applyDomainSeedDiversity($ranked);
        $remainingCapacity = $this->buildRemainingDailyCapacity($domainConstrainedRanked);
        $byProvider = $domainConstrainedRanked
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

                $candidate = $pool->first();
                if (($remainingCapacity[$candidate->id] ?? 0) <= 0) {
                    $byProvider[$provider] = $pool->slice(1)->values();
                    continue;
                }

                $selected->push($candidate);
                $remainingCapacity[$candidate->id] = max(0, ($remainingCapacity[$candidate->id] ?? 0) - 1);
                $byProvider[$provider] = $pool->slice(1)->values();
                $pickedInRound = true;
            }

            if (!$pickedInRound) {
                break;
            }
        }

        // If unique seeds are fewer than requested, reuse ranked seeds in a balanced way
        // so profile targets can still be met while respecting each seed's daily cap.
        if ($selected->count() < $count) {
            $selected = $this->topUpWithRepeatSeeds($selected, $domainConstrainedRanked, $remainingCapacity, $count);
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

    private function buildRemainingDailyCapacity(Collection $seeds): array
    {
        $seedIds = $seeds->pluck('id')->values();

        if ($seedIds->isEmpty()) {
            return [];
        }

        $todayLogs = SeedUsageLog::whereIn('seed_mailbox_id', $seedIds)
            ->where('log_date', today())
            ->get()
            ->keyBy('seed_mailbox_id');

        $remaining = [];

        foreach ($seeds as $seed) {
            $dailyCap = (int) ($seed->daily_total_interaction_cap ?? 20);
            $usedToday = (int) ($todayLogs->get($seed->id)?->interactions_today ?? 0);

            $remaining[$seed->id] = max(0, $dailyCap - $usedToday);
        }

        return $remaining;
    }

    /**
     * Prefer one unique seed per seed-domain first, then allow a second unique seed
     * per domain if needed. This keeps domain diversity natural and bounded.
     */
    private function applyDomainSeedDiversity(Collection $ranked): Collection
    {
        if ($ranked->isEmpty()) {
            return $ranked;
        }

        $selected = collect();
        $selectedIds = [];

        for ($passLimit = self::PREFERRED_UNIQUE_SEEDS_PER_DOMAIN; $passLimit <= self::MAX_UNIQUE_SEEDS_PER_DOMAIN; $passLimit++) {
            $perDomainCounts = [];

            foreach ($selected as $alreadySelected) {
                $domain = $this->extractSeedDomainKey($alreadySelected);
                $perDomainCounts[$domain] = ($perDomainCounts[$domain] ?? 0) + 1;
            }

            foreach ($ranked as $seed) {
                if (isset($selectedIds[$seed->id])) {
                    continue;
                }

                $domain = $this->extractSeedDomainKey($seed);
                $domainCount = (int) ($perDomainCounts[$domain] ?? 0);

                if ($domainCount >= $passLimit || $domainCount >= self::MAX_UNIQUE_SEEDS_PER_DOMAIN) {
                    continue;
                }

                $selected->push($seed);
                $selectedIds[$seed->id] = true;
                $perDomainCounts[$domain] = $domainCount + 1;
            }
        }

        return $selected->values();
    }

    private function extractSeedDomainKey(SeedMailbox $seed): string
    {
        $email = strtolower(trim((string) $seed->email_address));

        if ($email === '' || !str_contains($email, '@')) {
            return 'seed-' . (string) $seed->id;
        }

        $domain = trim((string) substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' ? $domain : 'seed-' . (string) $seed->id;
    }

    private function topUpWithRepeatSeeds(Collection $selected, Collection $ranked, array &$remainingCapacity, int $count): Collection
    {
        $reusePool = $ranked
            ->filter(fn (SeedMailbox $seed) => ($remainingCapacity[$seed->id] ?? 0) > 0)
            ->values();

        if ($reusePool->isEmpty()) {
            return $selected;
        }

        $cursor = 0;
        $guard = 0;
        $maxIterations = max($count * 10, 100);

        while ($selected->count() < $count && $reusePool->isNotEmpty() && $guard < $maxIterations) {
            $seed = $reusePool[$cursor % $reusePool->count()];
            $remaining = (int) ($remainingCapacity[$seed->id] ?? 0);

            if ($remaining <= 0) {
                $reusePool = $reusePool
                    ->reject(fn (SeedMailbox $s) => ($remainingCapacity[$s->id] ?? 0) <= 0)
                    ->values();
                $cursor++;
                $guard++;
                continue;
            }

            $last = $selected->last();
            if ($reusePool->count() > 1 && $last && $last->id === $seed->id) {
                $cursor++;
                $guard++;
                continue;
            }

            $selected->push($seed);
            $remainingCapacity[$seed->id] = $remaining - 1;

            if (($remainingCapacity[$seed->id] ?? 0) <= 0) {
                $reusePool = $reusePool
                    ->reject(fn (SeedMailbox $s) => $s->id === $seed->id)
                    ->values();
            }

            $cursor++;
            $guard++;
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
