<?php

namespace App\Services;

use App\Models\WarmupCampaign;
use App\Models\PlannerRun;
use App\Models\SeedMailbox;
use App\Models\SeedUsageLog;
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

        // Determine working window with randomization
        $workingWindow = $this->randomizer->workingWindow(
            $sender->working_hours_start,
            $sender->working_hours_end
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
            ],
        ]);

        // Create new threads and schedule initial events
        $this->createNewThreadEvents($campaign, $plannerRun, $eligibleSeeds, $newThreadBudget, $workingWindow);

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
            // Skip if already planned today (unless forced)
            if (!$force) {
                $alreadyPlanned = PlannerRun::where('warmup_campaign_id', $campaign->id)
                    ->where('plan_date', today())
                    ->exists();

                if ($alreadyPlanned) {
                    Log::info("DailyPlanner: Skipping campaign #{$campaign->id} — already planned today. Use force=true to override.");
                    continue;
                }
            } else {
                // Force: delete today's existing plan runs so we can re-plan fresh
                $deleted = PlannerRun::where('warmup_campaign_id', $campaign->id)
                    ->where('plan_date', today())
                    ->delete();
                Log::info("DailyPlanner: Force re-planning campaign #{$campaign->id} (deleted {$deleted} old plan runs)");
            }

            try {
                // Advance day if needed
                if ($campaign->start_date->diffInDays(today()) + 1 > $campaign->current_day_number) {
                    $this->campaignService->advanceDay($campaign);
                    $campaign->refresh();
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

    private function createNewThreadEvents(
        WarmupCampaign $campaign,
        PlannerRun $plannerRun,
        \Illuminate\Support\Collection $seeds,
        int $count,
        array $window
    ): void {
        if ($count <= 0 || $seeds->isEmpty()) return;

        $selectedSeeds = $this->seedAllocator->allocateSeeds(
            $campaign->senderMailbox,
            $campaign->domain,
            $seeds,
            $count
        );

        foreach ($selectedSeeds as $seed) {
            $thread = $this->threadService->createThread(
                $campaign,
                $campaign->senderMailbox,
                $seed
            );

            $scheduledAt = $this->randomizer->scheduledTime(
                $window['start'],
                $window['end'],
                $campaign->senderMailbox->timezone
            );

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
        }
    }

    private function createReplyEvents(
        WarmupCampaign $campaign,
        PlannerRun $plannerRun,
        \Illuminate\Support\Collection $threads,
        array $window
    ): void {
        foreach ($threads as $thread) {
            $nextActor = $thread->next_actor_type;
            $eventType = $nextActor === 'sender' ? 'sender_reply' : 'seed_reply';

            $actorId = $nextActor === 'sender' ? $thread->sender_mailbox_id : $thread->seed_mailbox_id;
            $recipientId = $nextActor === 'sender' ? $thread->seed_mailbox_id : $thread->sender_mailbox_id;

            // Reply delay: schedule with randomized delay from last message
            $lastMessage = $thread->messages()->latest('sent_at')->first();
            $delayMinutes = $this->randomizer->replyDelay($campaign->profile);

            $scheduledAt = $lastMessage && $lastMessage->sent_at
                ? $lastMessage->sent_at->addMinutes($delayMinutes)
                : $this->randomizer->scheduledTime($window['start'], $window['end'], $campaign->senderMailbox->timezone);

            // Ensure within working window
            $scheduledAt = max($scheduledAt, now());

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
}
