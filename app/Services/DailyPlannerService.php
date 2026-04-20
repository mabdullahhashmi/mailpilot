<?php

namespace App\Services;

use App\Models\WarmupCampaign;
use App\Models\PlannerRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DailyPlannerService
{
    public function __construct(
        private ThreadService $threadService,
        private SeedAllocationService $seedAllocator,
        private SafetyService $safety,
        private RandomizationService $randomizer,
        private WarmupCampaignService $campaignService,
        private SlotSchedulerService $slotScheduler,
    ) {}

    /**
     * Run the daily planner for a single campaign.
     * Called once per day per active campaign.
     */
    public function planDay(WarmupCampaign $campaign): PlannerRun
    {
        $profile = $campaign->profile;
        $sender = $campaign->senderMailbox;
        $day = $campaign->current_day_number;

        if (!$profile) {
            throw new \RuntimeException("Campaign #{$campaign->id} has no warmup profile assigned.");
        }
        if (!$sender) {
            throw new \RuntimeException("Campaign #{$campaign->id} has no sender mailbox assigned.");
        }

        // Resolve domain: use campaign's domain or auto-detect from sender email
        $domain = $campaign->domain;
        if (!$domain) {
            $senderDomain = explode('@', $sender->email_address)[1] ?? null;
            if ($senderDomain) {
                $domain = \App\Models\Domain::firstOrCreate(
                    ['domain_name' => $senderDomain],
                    ['status' => 'active']
                );
                $campaign->update(['domain_id' => $domain->id]);
                $sender->update(['domain_id' => $domain->id]);
            } else {
                throw new \RuntimeException("Campaign #{$campaign->id}: Cannot determine domain from sender email.");
            }
        }

        // Strict day-boundary policy: prevent old pending events from spilling into a new plan day.
        $staleCleanup = $this->cancelStalePendingEvents($campaign);
        if (($staleCleanup['events_cancelled'] ?? 0) > 0) {
            Log::warning("DailyPlanner: Campaign #{$campaign->id} cancelled {$staleCleanup['events_cancelled']} stale pending event(s) from previous day(s)", [
                'campaign_id' => $campaign->id,
                'slots_skipped' => $staleCleanup['slots_skipped'] ?? 0,
            ]);
        }

        // Get rules for today from profile
        $dayRules = $profile->getRulesForDay($day);
        $maxNewThreads = $dayRules['max_new_threads'];
        $maxReplies = $dayRules['max_replies'];
        $maxTotal = $dayRules['max_total'];

        // Apply safety caps (with ramp-down awareness)
        $effectiveSendCap = $this->safety->getRampDownCap($sender);
        $maxNewThreads = min($maxNewThreads, $effectiveSendCap);
        $maxNewThreads = $this->safety->applySenderCap($sender, $maxNewThreads, 'send');
        $maxReplies = $this->safety->applySenderCap($sender, $maxReplies, 'reply');
        $maxTotal = min($maxTotal, $maxNewThreads + $maxReplies);

        // Check domain cap
        $domainBudget = $this->safety->getDomainRemainingBudget($domain);
        $maxTotal = min($maxTotal, $domainBudget);

        // Select eligible seeds
        $eligibleSeeds = $this->seedAllocator->getEligibleSeeds($sender, $domain);

        if ($eligibleSeeds->isEmpty()) {
            Log::warning("DailyPlanner: No eligible seeds for campaign #{$campaign->id}");
        }

        // Use campaign time window (user-specified) if set, otherwise sender working hours
        $windowStart = $campaign->time_window_start ?: $sender->working_hours_start ?: '08:00';
        $windowEnd = $campaign->time_window_end ?: $sender->working_hours_end ?: '22:00';

        // Determine working window with slight randomization
        $workingWindow = $this->randomizer->workingWindow(
            $windowStart,
            $windowEnd
        );

        // Get continuation threads
        $continuationThreads = $this->threadService->getContinuationThreads(
            $campaign,
            $maxReplies
        );
        $actualRepliesPossible = min($maxReplies, $continuationThreads->count());

        // Recalculate new threads budget
        $newThreadBudget = min($maxNewThreads, $maxTotal - $actualRepliesPossible);
        $newThreadBudget = max(0, $newThreadBudget);

        // Create planner run record
        $plannerRun = PlannerRun::create([
            'warmup_campaign_id' => $campaign->id,
            'plan_date' => today(),
            'warmup_day_number' => $day,
            'warmup_stage' => $campaign->current_stage,
            'total_action_budget' => $maxTotal,
            'new_thread_target' => $newThreadBudget,
            'reply_target' => $actualRepliesPossible,
            'eligible_seed_ids' => $eligibleSeeds->pluck('id')->toArray(),
            'provider_distribution' => $profile->provider_distribution,
            'working_window_start' => $workingWindow['start'],
            'working_window_end' => $workingWindow['end'],
            'status' => 'planned',
            'notes' => [
                'continuation_threads' => $continuationThreads->count(),
                'domain_budget_remaining' => $domainBudget,
                'stale_events_cancelled' => $staleCleanup['events_cancelled'] ?? 0,
                'stale_slots_skipped' => $staleCleanup['slots_skipped'] ?? 0,
            ],
        ]);

        // Create new threads and schedule initial events
        $plannedNewThreads = $this->createNewThreadEvents($campaign, $plannerRun, $eligibleSeeds, $newThreadBudget, $workingWindow);

        if ($plannedNewThreads !== $newThreadBudget) {
            $notes = $plannerRun->notes ?? [];
            $notes['new_thread_target_requested'] = $newThreadBudget;
            $notes['new_thread_target_effective'] = $plannedNewThreads;

            $plannerRun->update([
                'new_thread_target' => $plannedNewThreads,
                'notes' => $notes,
            ]);
        }

        // Schedule reply events for continuation threads
        $this->createReplyEvents($campaign, $plannerRun, $continuationThreads, $workingWindow);

        $plannerRun->update(['status' => 'executing']);

        return $plannerRun;
    }

    /**
     * Run daily planner for all active campaigns.
     */
    public function planAllCampaigns(bool $force = false): array
    {
        $campaigns = $this->campaignService->getActiveCampaigns();
        $runs = [];
        $errors = [];

        Log::info("DailyPlanner: Found {$campaigns->count()} active campaign(s)");

        foreach ($campaigns as $campaign) {
            try {
                // Advance day using campaign day-duration (24h default, 60m for test profile).
                $targetDay = $this->resolveTargetDayNumber($campaign);
                $this->advanceCampaignToDay($campaign, $targetDay);

                // Skip if already planned for the current warmup day (unless forced).
                if (!$force) {
                    $alreadyPlanned = PlannerRun::where('warmup_campaign_id', $campaign->id)
                        ->where('warmup_day_number', $campaign->current_day_number)
                        ->exists();

                    if ($alreadyPlanned) {
                        Log::info("DailyPlanner: Skipping campaign #{$campaign->id} — already planned for warmup day #{$campaign->current_day_number}. Use force=true to override.");
                        continue;
                    }
                } else {
                    // Force: clean the current warmup day's plan run(s) and linked events/threads.
                    $existingRuns = PlannerRun::where('warmup_campaign_id', $campaign->id)
                        ->where('warmup_day_number', $campaign->current_day_number)
                        ->pluck('id');

                    if ($existingRuns->isNotEmpty()) {
                        $eventQuery = \App\Models\WarmupEvent::where('warmup_campaign_id', $campaign->id)
                            ->whereIn('status', ['pending', 'locked', 'final_failed', 'executing'])
                            ->where(function ($q) use ($existingRuns) {
                                foreach ($existingRuns as $runId) {
                                    $q->orWhere('payload->planner_run_id', (int) $runId);
                                }
                            });

                        $eventIds = $eventQuery->pluck('id');

                        // Delete associated send slots
                        \App\Models\SendSlot::whereIn('warmup_event_id', $eventIds)->delete();

                        // Gather affected threads before deleting events
                        $affectedThreadIds = \App\Models\WarmupEvent::whereIn('id', $eventIds)
                            ->whereNotNull('thread_id')
                            ->pluck('thread_id')
                            ->unique();

                        // Delete the events
                        \App\Models\WarmupEvent::whereIn('id', $eventIds)->delete();

                        // Delete affected threads only if they have no completed events.
                        $orphanThreadIds = \App\Models\Thread::whereIn('id', $affectedThreadIds)
                            ->whereDoesntHave('events', function ($q) {
                                $q->where('status', 'completed');
                            })
                            ->pluck('id');

                        \App\Models\ThreadMessage::whereIn('thread_id', $orphanThreadIds)->delete();
                        \App\Models\Thread::whereIn('id', $orphanThreadIds)->delete();

                        // Delete the run rows
                        PlannerRun::whereIn('id', $existingRuns)->delete();

                        Log::info("DailyPlanner: Force cleaned campaign #{$campaign->id} day #{$campaign->current_day_number}: deleted " . count($existingRuns) . " plan runs, " . count($eventIds) . " events, " . count($orphanThreadIds) . " orphan threads");
                    }
                }

                $runs[] = $this->planDay($campaign);
            } catch (\Throwable $e) {
                $errors[] = "Campaign #{$campaign->id} ({$campaign->campaign_name}): {$e->getMessage()}";
                Log::error("DailyPlanner failed for campaign #{$campaign->id}: {$e->getMessage()}", [
                    'exception' => $e,
                    'campaign' => $campaign->toArray(),
                ]);
            }
        }

        // If there are errors and no runs, throw so the controller can report them
        if (empty($runs) && !empty($errors)) {
            throw new \RuntimeException('Planner failed for all campaigns: ' . implode(' | ', $errors));
        }

        return $runs;
    }

    private function resolveTargetDayNumber(WarmupCampaign $campaign): int
    {
        $dayDurationMinutes = (int) ($campaign->day_duration_minutes ?? 1440);
        $dayDurationMinutes = max(30, min(1440, $dayDurationMinutes));
        $planned = max(1, (int) ($campaign->planned_duration_days ?? 1));

        if ($dayDurationMinutes >= 1440) {
            $target = (int) ($campaign->start_date?->diffInDays(today()) + 1);
        } else {
            $startedAt = $campaign->created_at ?? now();
            $elapsedMinutes = (int) $startedAt->diffInMinutes(now());
            $target = intdiv($elapsedMinutes, $dayDurationMinutes) + 1;
        }

        // Allow one step above planned duration so maintenance transition can happen.
        return max(1, min($planned + 1, $target));
    }

    private function cancelStalePendingEvents(WarmupCampaign $campaign): array
    {
        $skipReason = 'Auto-cancelled stale pending task from previous day plan.';

        $staleEventIds = \App\Models\WarmupEvent::where('warmup_campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'locked', 'executing'])
            ->whereDate('created_at', '<', today())
            ->pluck('id');

        if ($staleEventIds->isEmpty()) {
            return ['events_cancelled' => 0, 'slots_skipped' => 0];
        }

        $eventsCancelled = \App\Models\WarmupEvent::whereIn('id', $staleEventIds)
            ->update([
                'status' => 'cancelled',
                'failure_reason' => $skipReason,
                'lock_token' => null,
                'lock_expires_at' => null,
            ]);

        $slotsSkipped = \App\Models\SendSlot::whereIn('warmup_event_id', $staleEventIds)
            ->whereIn('status', ['planned', 'executing'])
            ->update([
                'status' => 'skipped',
                'skip_reason' => $skipReason,
            ]);

        return [
            'events_cancelled' => (int) $eventsCancelled,
            'slots_skipped' => (int) $slotsSkipped,
        ];
    }

    private function advanceCampaignToDay(WarmupCampaign $campaign, int $targetDay): void
    {
        if ($targetDay <= $campaign->current_day_number) {
            return;
        }

        $guard = 0;
        while ($campaign->current_day_number < $targetDay && $guard < 200) {
            $this->campaignService->advanceDay($campaign);
            $campaign->refresh();
            $guard++;
        }
    }

    private function createNewThreadEvents(
        WarmupCampaign $campaign,
        PlannerRun $plannerRun,
        \Illuminate\Support\Collection $seeds,
        int $count,
        array $window
    ): int {
        if ($count <= 0 || $seeds->isEmpty()) return 0;

        $selectedSeeds = $this->seedAllocator->allocateSeeds(
            $campaign->senderMailbox,
            $campaign->domain,
            $seeds,
            $count
        );

        $selectedSeeds = $selectedSeeds->values();
        $scheduledSlots = $this->buildSpreadScheduleSlots($campaign, $window, $selectedSeeds->count());

        if ($selectedSeeds->count() < $count) {
            Log::warning("DailyPlanner: Campaign #{$campaign->id} requested {$count} new thread(s), allocated {$selectedSeeds->count()} due to seed availability/capacity");
        }

        $createdCount = 0;

        foreach ($selectedSeeds as $index => $seed) {
            $thread = $this->threadService->createThread(
                $campaign,
                $campaign->senderMailbox,
                $seed
            );

            $scheduledAt = $scheduledSlots[$index] ?? $this->pickScheduledAt($campaign, $window);

            $event = \App\Models\WarmupEvent::create([
                'event_type' => 'sender_send_initial',
                'actor_type' => 'sender',
                'actor_mailbox_id' => $campaign->sender_mailbox_id,
                'recipient_type' => 'seed',
                'recipient_mailbox_id' => $seed->id,
                'thread_id' => $thread->id,
                'warmup_campaign_id' => $campaign->id,
                'scheduled_at' => $scheduledAt,
                'status' => 'pending',
                'priority' => 5,
                'payload' => ['planner_run_id' => $plannerRun->id],
            ]);

            // Create visible send slot
            $this->slotScheduler->createSlot(
                $campaign, $campaign->sender_mailbox_id, $seed->id,
                $thread->id, 'initial_send', $scheduledAt, $event->id
            );

            $createdCount++;
        }

        return $createdCount;
    }

    private function createReplyEvents(
        WarmupCampaign $campaign,
        PlannerRun $plannerRun,
        \Illuminate\Support\Collection $threads,
        array $window
    ): void {
        $scheduleTimezone = $campaign->timezone ?: ($campaign->senderMailbox->timezone ?: 'UTC');

        foreach ($threads as $thread) {
            $hasPendingReplyEvent = \App\Models\WarmupEvent::where('thread_id', $thread->id)
                ->whereIn('event_type', ['seed_reply', 'sender_reply'])
                ->whereIn('status', ['pending', 'locked', 'executing'])
                ->exists();

            if ($hasPendingReplyEvent) {
                Log::info("DailyPlanner: Skip duplicate reply event for thread #{$thread->id} (pending reply already exists)");
                continue;
            }

            $nextActor = $thread->next_actor_type;
            $eventType = $nextActor === 'sender' ? 'sender_reply' : 'seed_reply';

            $actorId = $nextActor === 'sender' ? $thread->sender_mailbox_id : $thread->seed_mailbox_id;
            $recipientId = $nextActor === 'sender' ? $thread->seed_mailbox_id : $thread->sender_mailbox_id;

            // Reply delay: schedule with randomized delay from last message
            $lastMessage = $thread->messages()->latest('sent_at')->first();
            $delayMinutes = $this->randomizer->replyDelay($campaign->profile);

            $scheduledAt = $lastMessage && $lastMessage->sent_at
                ? $lastMessage->sent_at->addMinutes($delayMinutes)
                : $this->randomizer->scheduledTime($window['start'], $window['end'], $scheduleTimezone);

            // Ensure continuation replies stay inside campaign working window.
            $scheduledAt = $this->alignScheduledAtToWorkingWindow($campaign, $scheduledAt, $window, $scheduleTimezone);

            \App\Models\WarmupEvent::create([
                'event_type' => $eventType,
                'actor_type' => $nextActor,
                'actor_mailbox_id' => $actorId,
                'recipient_type' => $nextActor === 'sender' ? 'seed' : 'sender',
                'recipient_mailbox_id' => $recipientId,
                'thread_id' => $thread->id,
                'warmup_campaign_id' => $campaign->id,
                'scheduled_at' => $scheduledAt,
                'status' => 'pending',
                'priority' => 4,
                'payload' => ['planner_run_id' => $plannerRun->id],
            ]);

            // Create visible reply slot
            $this->slotScheduler->createSlot(
                $campaign, $thread->sender_mailbox_id, $thread->seed_mailbox_id,
                $thread->id, 'reply', $scheduledAt
            );
        }
    }

    private function getDayDurationMinutes(WarmupCampaign $campaign): int
    {
        return max(30, min(1440, (int) ($campaign->day_duration_minutes ?? 1440)));
    }

    private function isAcceleratedMode(WarmupCampaign $campaign): bool
    {
        return $this->getDayDurationMinutes($campaign) < 1440;
    }

    private function pickScheduledAt(WarmupCampaign $campaign, array $window): Carbon
    {
        if (!$this->isAcceleratedMode($campaign)) {
            $timezone = $campaign->timezone ?: ($campaign->senderMailbox->timezone ?: 'UTC');
            return $this->randomizer->scheduledTime(
                $window['start'],
                $window['end'],
                $timezone
            );
        }

        $duration = $this->getDayDurationMinutes($campaign);
        $startAt = now();
        $endAt = now()->addMinutes($duration);

        return $this->randomizer->scheduledTimeBetween($startAt, $endAt);
    }

    private function clampToAcceleratedWindow(WarmupCampaign $campaign, Carbon $scheduledAt, array $window): Carbon
    {
        if (!$this->isAcceleratedMode($campaign)) {
            return $scheduledAt;
        }

        $duration = $this->getDayDurationMinutes($campaign);
        $startAt = now();
        $endAt = now()->addMinutes($duration);

        if ($scheduledAt->lt($startAt) || $scheduledAt->gt($endAt)) {
            return $this->randomizer->scheduledTimeBetween($startAt, $endAt);
        }

        return $scheduledAt;
    }

    private function buildSpreadScheduleSlots(WarmupCampaign $campaign, array $window, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($this->isAcceleratedMode($campaign)) {
            $duration = $this->getDayDurationMinutes($campaign);
            return $this->evenlySpreadBetween(now(), now()->addMinutes($duration), $count);
        }

        $timezone = $campaign->timezone ?: ($campaign->senderMailbox->timezone ?: 'UTC');
        $nowTz = now()->setTimezone($timezone);

        [$windowStartAt, $windowEndAt] = $this->resolveWindowBoundsForDate(
            $nowTz->toDateString(),
            $window,
            $timezone
        );

        if ($windowEndAt->lte($nowTz)) {
            [$windowStartAt, $windowEndAt] = $this->resolveWindowBoundsForDate(
                $nowTz->copy()->addDay()->toDateString(),
                $window,
                $timezone
            );
        }

        if ($windowStartAt->lt($nowTz)) {
            $windowStartAt = $nowTz->copy()->addSeconds(random_int(5, 45));
        }

        return $this->evenlySpreadBetween($windowStartAt, $windowEndAt, $count);
    }

    private function evenlySpreadBetween(Carbon $startAt, Carbon $endAt, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $start = $startAt->copy();
        $end = $endAt->copy();

        if ($end->lte($start)) {
            return array_map(
                fn () => $start->copy(),
                range(1, $count)
            );
        }

        $totalSeconds = max(1, $start->diffInSeconds($end));
        $slotSize = max(1, intdiv($totalSeconds, $count));
        $slots = [];

        for ($i = 0; $i < $count; $i++) {
            $slotStart = $start->copy()->addSeconds($slotSize * $i);
            $slotEnd = $i === $count - 1
                ? $end->copy()
                : $start->copy()->addSeconds($slotSize * ($i + 1));

            if ($slotEnd->lte($slotStart)) {
                $slotEnd = $slotStart->copy()->addSeconds(1);
            }

            $startTs = $slotStart->getTimestamp();
            $endTs = $slotEnd->getTimestamp();
            $centerTs = intdiv($startTs + $endTs, 2);
            $jitterLimit = min(600, max(5, (int) floor(($endTs - $startTs) * 0.35)));
            $candidateTs = $centerTs + random_int(-$jitterLimit, $jitterLimit);

            $maxTs = max($startTs, $endTs - 1);
            $candidateTs = max($startTs, min($candidateTs, $maxTs));

            $slots[] = Carbon::createFromTimestamp($candidateTs, $start->getTimezone());
        }

        return $slots;
    }

    private function alignScheduledAtToWorkingWindow(
        WarmupCampaign $campaign,
        Carbon $scheduledAt,
        array $window,
        string $timezone
    ): Carbon {
        if ($this->isAcceleratedMode($campaign)) {
            return $this->clampToAcceleratedWindow($campaign, $scheduledAt, $window);
        }

        $candidate = $scheduledAt->copy()->setTimezone($timezone);
        $nowTz = now()->setTimezone($timezone);

        if ($candidate->lt($nowTz)) {
            $candidate = $nowTz;
        }

        [$windowStartAt, $windowEndAt] = $this->resolveWindowBoundsForDate(
            $candidate->toDateString(),
            $window,
            $timezone
        );

        if ($candidate->lt($windowStartAt)) {
            return $windowStartAt->copy()->addSeconds(random_int(15, 180));
        }

        if ($candidate->gt($windowEndAt)) {
            [$nextStartAt] = $this->resolveWindowBoundsForDate(
                $candidate->copy()->addDay()->toDateString(),
                $window,
                $timezone
            );

            return $nextStartAt->copy()->addSeconds(random_int(30, 240));
        }

        return $candidate;
    }

    private function resolveWindowBoundsForDate(string $date, array $window, string $timezone): array
    {
        $windowStartAt = Carbon::parse($date . ' ' . $window['start'], $timezone);
        $windowEndAt = Carbon::parse($date . ' ' . $window['end'], $timezone);

        if ($windowEndAt->lte($windowStartAt)) {
            $windowEndAt->addDay();
        }

        return [$windowStartAt, $windowEndAt];
    }
}
