<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SenderMailbox;
use App\Models\WarmupCampaign;
use App\Services\WarmupCampaignService;
use App\Services\ReportingService;
use App\Services\ReadinessScoringService;
use App\Services\DailyPlannerService;
use App\Services\SeedAllocationService;
use App\Models\PlannerRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarmupCampaignController extends Controller
{
    public function __construct(
        private WarmupCampaignService $campaignService,
        private ReportingService $reportingService,
        private ReadinessScoringService $readinessService,
        private DailyPlannerService $plannerService,
        private SeedAllocationService $seedAllocator,
    ) {}

    public function index(): JsonResponse
    {
        $campaigns = \App\Models\WarmupCampaign::with(['senderMailbox', 'domain', 'profile'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($campaigns);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_name' => 'sometimes|string|max:255',
            'sender_mailbox_id' => 'required|exists:sender_mailboxes,id',
            'warmup_profile_id' => 'required|exists:warmup_profiles,id',
            'time_window_start' => 'sometimes|string',
            'time_window_end' => 'sometimes|string',
        ]);

        $campaign = $this->campaignService->start(
            SenderMailbox::findOrFail($validated['sender_mailbox_id']),
            $validated['warmup_profile_id']
        );

        if (!empty($validated['campaign_name'])) {
            $campaign->update(['campaign_name' => $validated['campaign_name']]);
        }
        if (!empty($validated['time_window_start'])) {
            $campaign->update(['time_window_start' => $validated['time_window_start']]);
        }
        if (!empty($validated['time_window_end'])) {
            $campaign->update(['time_window_end' => $validated['time_window_end']]);
        }

        // Auto-plan events immediately after campaign creation
        try {
            $campaign->refresh();
            $this->plannerService->planDay($campaign);
        } catch (\Throwable $e) {
            \Log::warning('Auto-plan after campaign creation failed: ' . $e->getMessage());
        }

        return response()->json($campaign->fresh(), 201);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sender_mailbox_ids' => 'required|array|min:1|max:300',
            'sender_mailbox_ids.*' => 'required|integer|distinct|exists:sender_mailboxes,id',
            'warmup_profile_id' => 'required|exists:warmup_profiles,id',
            'campaign_name_prefix' => 'nullable|string|max:255',
            'time_window_start' => 'nullable|string',
            'time_window_end' => 'nullable|string',
            'skip_existing_active' => 'nullable|boolean',
        ]);

        $skipExistingActive = (bool) ($validated['skip_existing_active'] ?? true);
        $campaignNamePrefix = trim((string) ($validated['campaign_name_prefix'] ?? ''));

        $senderIds = array_values($validated['sender_mailbox_ids']);
        $sendersById = SenderMailbox::whereIn('id', $senderIds)->get()->keyBy('id');

        $created = [];
        $errors = [];
        $skipped = 0;

        foreach ($senderIds as $position => $senderId) {
            $lineNumber = $position + 1;
            $sender = $sendersById->get($senderId);

            if (!$sender) {
                $errors[] = "Row {$lineNumber}: sender #{$senderId} not found";
                $skipped++;
                continue;
            }

            if ($sender->status !== 'active') {
                $errors[] = "Row {$lineNumber}: {$sender->email_address} is not active";
                $skipped++;
                continue;
            }

            if ($skipExistingActive) {
                $hasRunningCampaign = WarmupCampaign::where('sender_mailbox_id', $sender->id)
                    ->whereIn('status', ['active', 'paused'])
                    ->exists();

                if ($hasRunningCampaign) {
                    $errors[] = "Row {$lineNumber}: {$sender->email_address} already has an active/paused campaign";
                    $skipped++;
                    continue;
                }
            }

            try {
                $campaign = $this->campaignService->start($sender, (int) $validated['warmup_profile_id']);

                $updates = [];
                if ($campaignNamePrefix !== '') {
                    $localPart = explode('@', $sender->email_address)[0] ?? ('sender-' . $sender->id);
                    $updates['campaign_name'] = trim($campaignNamePrefix . ' - ' . $localPart);
                }

                if (!empty($validated['time_window_start'])) {
                    $updates['time_window_start'] = $validated['time_window_start'];
                }

                if (!empty($validated['time_window_end'])) {
                    $updates['time_window_end'] = $validated['time_window_end'];
                }

                if (!empty($updates)) {
                    $campaign->update($updates);
                }

                // Auto-plan events for each created campaign
                try {
                    $campaign->refresh();
                    $this->plannerService->planDay($campaign);
                } catch (\Throwable $e) {
                    \Log::warning("Bulk campaign auto-plan failed for campaign #{$campaign->id}: " . $e->getMessage());
                }

                $created[] = [
                    'campaign_id' => $campaign->id,
                    'sender_mailbox_id' => $sender->id,
                    'sender_email' => $sender->email_address,
                    'campaign_name' => $campaign->campaign_name,
                    'status' => $campaign->status,
                ];
            } catch (\Throwable $e) {
                $errors[] = "Row {$lineNumber}: {$sender->email_address} - {$e->getMessage()}";
                $skipped++;
            }
        }

        return response()->json([
            'imported' => count($created),
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 100),
            'created' => $created,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::with([
            'senderMailbox:id,email_address,provider_type,status,current_warmup_day,daily_send_cap',
            'domain:id,domain_name',
            'profile:id,profile_name,day_rules,profile_type',
            'threads' => function ($q) {
                $q->with([
                    'seedMailbox:id,email_address,provider_type,status',
                    'senderMailbox:id,email_address,provider_type,status',
                ])->orderByDesc('created_at');
            },
        ])
            ->findOrFail($id);

        $report = $this->reportingService->campaignReport($campaign);
        $readiness = $this->readinessService->getReadinessSummary($campaign->senderMailbox);
        $dailyRecords = $this->buildDailyRecords($campaign->id);

        return response()->json([
            'campaign' => $campaign,
            'report' => $report,
            'readiness' => $readiness,
            'daily_records' => $dailyRecords,
        ]);
    }

    public function pause(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        $this->campaignService->pause($campaign);
        return response()->json(['message' => 'Campaign paused']);
    }

    public function resume(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        $this->campaignService->resume($campaign);
        return response()->json(['message' => 'Campaign resumed']);
    }

    public function stop(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        $this->campaignService->stop($campaign);
        return response()->json(['message' => 'Campaign stopped']);
    }

    public function restart(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        $this->campaignService->restart($campaign);
        return response()->json(['message' => 'Campaign restarted']);
    }

    public function report(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        return response()->json($this->reportingService->campaignReport($campaign));
    }

    public function startCampaign(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        $campaign->update(['status' => 'active']);
        return response()->json(['message' => 'Campaign started']);
    }

    public function destroy(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        $campaign->delete();
        return response()->json(['message' => 'Campaign deleted']);
    }

    /**
     * Get scheduled timeline for a campaign — all events with times and countdowns.
     */
    public function schedule(Request $request, int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        $selectedDate = $request->query('date');
        $date = $selectedDate ? \Carbon\Carbon::parse($selectedDate)->toDateString() : today()->toDateString();

        $events = \App\Models\WarmupEvent::where('warmup_campaign_id', $campaign->id)
            ->whereDate('scheduled_at', $date)
            ->orderBy('scheduled_at')
            ->with(['thread.senderMailbox', 'thread.seedMailbox'])
            ->get();

        $plannerRunIds = $events
            ->pluck('payload')
            ->filter(fn($p) => is_array($p) && isset($p['planner_run_id']))
            ->map(fn($p) => (int) $p['planner_run_id'])
            ->unique()
            ->values();

        $runsById = PlannerRun::whereIn('id', $plannerRunIds)->get()->keyBy('id');

        $events = $events->map(function ($event) use ($runsById) {
                $sender = $event->thread?->senderMailbox;
                $seed = $event->thread?->seedMailbox;
                $plannerRunId = is_array($event->payload) ? ($event->payload['planner_run_id'] ?? null) : null;
                $run = $plannerRunId ? $runsById->get((int) $plannerRunId) : null;

                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'status' => $event->status,
                    'scheduled_at' => $event->scheduled_at?->toIso8601String(),
                    'executed_at' => $event->executed_at?->toIso8601String(),
                    'thread_id' => $event->thread_id,
                    'sender_email' => $sender?->email_address,
                    'seed_email' => $seed?->email_address,
                    'subject' => $event->thread?->subject_line,
                    'priority' => $event->priority,
                    'failure_reason' => $event->failure_reason,
                    'planner_run_id' => $plannerRunId,
                    'warmup_day_number' => $run?->warmup_day_number,
                    'plan_date' => $run?->plan_date?->format('Y-m-d'),
                ];
            });

        return response()->json([
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->campaign_name,
            'server_time' => now()->toIso8601String(),
            'selected_date' => $date,
            'events' => $events,
        ]);
    }

    /**
     * Full event ledger for campaign detail page.
     */
    public function events(Request $request, int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        $selectedDate = $request->query('date');

        $query = \App\Models\WarmupEvent::where('warmup_campaign_id', $campaign->id)
            ->with(['thread.senderMailbox', 'thread.seedMailbox'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if ($selectedDate) {
            $query->whereDate('scheduled_at', \Carbon\Carbon::parse($selectedDate)->toDateString());
        }

        $events = $query->limit(1000)->get()->map(function ($event) {
            $sender = $event->thread?->senderMailbox;
            $seed = $event->thread?->seedMailbox;

            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'scheduled_at' => $event->scheduled_at?->toIso8601String(),
                'executed_at' => $event->executed_at?->toIso8601String(),
                'failure_reason' => $event->failure_reason,
                'thread_id' => $event->thread_id,
                'sender_email' => $sender?->email_address,
                'seed_email' => $seed?->email_address,
                'subject' => $event->thread?->subject_line,
                'thread_status' => $event->thread?->thread_status,
                'planned_message_count' => $event->thread?->planned_message_count,
                'actual_message_count' => $event->thread?->actual_message_count,
            ];
        });

        return response()->json([
            'campaign_id' => $campaign->id,
            'events' => $events,
        ]);
    }

    /**
     * Seed eligibility breakdown for campaign sender/domain.
     */
    public function seedEligibility(Request $request, int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::with([
            'senderMailbox:id,email_address',
            'domain:id,domain_name',
        ])->findOrFail($id);

        if (!$campaign->senderMailbox || !$campaign->domain) {
            return response()->json([
                'campaign_id' => $campaign->id,
                'message' => 'Campaign is missing sender or domain configuration.',
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
            ], 422);
        }

        $date = $request->query('date');
        $report = $this->seedAllocator->getEligibilityReport($campaign->senderMailbox, $campaign->domain, $date);

        return response()->json([
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->campaign_name,
            'sender_email' => $campaign->senderMailbox->email_address,
            'domain_name' => $campaign->domain->domain_name,
            'date' => $report['date'],
            'summary' => $report['summary'],
            'seeds' => $report['seeds'],
        ]);
    }

    private function buildDailyRecords(int $campaignId): array
    {
        return PlannerRun::where('warmup_campaign_id', $campaignId)
            ->orderByDesc('plan_date')
            ->orderByDesc('id')
            ->limit(90)
            ->get()
            ->map(function (PlannerRun $run) {
                $newThreads = (int) ($run->new_thread_target ?? 0);
                $replies = (int) ($run->reply_target ?? 0);

                return [
                    'id' => $run->id,
                    'plan_date' => $run->plan_date?->format('Y-m-d'),
                    'warmup_day_number' => (int) ($run->warmup_day_number ?? 0),
                    'warmup_stage' => $run->warmup_stage,
                    'new_thread_target' => $newThreads,
                    'reply_target' => $replies,
                    'total_action_budget' => (int) ($run->total_action_budget ?? 0),
                    'planned_new_plus_replies' => $newThreads + $replies,
                    'actual_new_threads' => (int) ($run->actual_new_threads ?? 0),
                    'actual_replies' => (int) ($run->actual_replies ?? 0),
                    'actual_total_actions' => (int) ($run->actual_total_actions ?? 0),
                    'eligible_seed_count' => is_array($run->eligible_seed_ids) ? count($run->eligible_seed_ids) : 0,
                    'status' => $run->status,
                    'working_window_start' => $run->working_window_start,
                    'working_window_end' => $run->working_window_end,
                ];
            })
            ->values()
            ->toArray();
    }
}
