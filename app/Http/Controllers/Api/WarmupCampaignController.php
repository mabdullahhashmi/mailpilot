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

        if ($campaigns->isEmpty()) {
            return response()->json($campaigns);
        }

        $campaignIds = $campaigns->pluck('id');

        $failedCounts = \App\Models\WarmupEvent::whereIn('warmup_campaign_id', $campaignIds)
            ->whereIn('status', ['failed', 'final_failed'])
            ->selectRaw('warmup_campaign_id, COUNT(*) as failed_total')
            ->groupBy('warmup_campaign_id')
            ->pluck('failed_total', 'warmup_campaign_id');

        $latestFailedByCampaign = \App\Models\WarmupEvent::whereIn('warmup_campaign_id', $campaignIds)
            ->whereIn('status', ['failed', 'final_failed'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'warmup_campaign_id',
                'event_type',
                'status',
                'failure_reason',
                'updated_at',
            ])
            ->groupBy('warmup_campaign_id')
            ->map(fn ($items) => $items->first());

        $campaigns->transform(function ($campaign) use ($failedCounts, $latestFailedByCampaign) {
            $failedTotal = (int) ($failedCounts[$campaign->id] ?? 0);
            $latestFailed = $latestFailedByCampaign[$campaign->id] ?? null;
            $friendlyFailure = $this->friendlyFailureReason(
                $latestFailed?->failure_reason,
                $latestFailed?->event_type
            );

            $campaign->setAttribute('failed_tasks_total', $failedTotal);
            $campaign->setAttribute('latest_failed_task', $latestFailed ? [
                'id' => $latestFailed->id,
                'event_type' => $latestFailed->event_type,
                'status' => $latestFailed->status,
                'failure_reason' => $latestFailed->failure_reason,
                'friendly_title' => $friendlyFailure['title'],
                'friendly_reason' => $friendlyFailure['reason'],
                'failed_at' => $latestFailed->updated_at?->toIso8601String(),
            ] : null);

            return $campaign;
        });

        return response()->json($campaigns);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_name' => 'sometimes|string|max:255',
            'sender_mailbox_id' => 'required|exists:sender_mailboxes,id',
            'warmup_profile_id' => 'required|exists:warmup_profiles,id',
            'start_date' => 'nullable|date',
            'time_window_start' => 'sometimes|string',
            'time_window_end' => 'sometimes|string',
            'timezone' => 'nullable|string|max:64',
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
        if (!empty($validated['start_date'])) {
            $campaign->update(['start_date' => $validated['start_date']]);
        }
        if (array_key_exists('timezone', $validated)) {
            $campaign->update(['timezone' => $validated['timezone'] ?: null]);
        }

        // Auto-plan events immediately if start date is today or missing
        try {
            $campaign->refresh();
            if (!$campaign->start_date || $campaign->start_date->isSameDay(now()) || $campaign->start_date->isPast()) {
                $this->plannerService->planDay($campaign);
            }
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
            'start_date' => 'nullable|date',
            'time_window_start' => 'nullable|string',
            'time_window_end' => 'nullable|string',
            'timezone' => 'nullable|string|max:64',
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

                if (!empty($validated['start_date'])) {
                    $updates['start_date'] = $validated['start_date'];
                }

                if (array_key_exists('timezone', $validated)) {
                    $updates['timezone'] = $validated['timezone'] ?: null;
                }

                if (!empty($updates)) {
                    $campaign->update($updates);
                }

                // Auto-plan events immediately if start date is today or missing
                try {
                    $campaign->refresh();
                    if (!$campaign->start_date || $campaign->start_date->isSameDay(now()) || $campaign->start_date->isPast()) {
                        $this->plannerService->planDay($campaign);
                    }
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

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_ids' => 'required|array|min:1',
            'campaign_ids.*' => 'integer|exists:warmup_campaigns,id',
        ]);

        $campaigns = \App\Models\WarmupCampaign::whereIn('id', $validated['campaign_ids'])->get();
        $deleted = 0;

        foreach ($campaigns as $campaign) {
            $campaign->delete();
            $deleted++;
        }

        return response()->json(['message' => "$deleted campaigns deleted"]);
    }

    /**
     * Get scheduled timeline for a campaign — all events with times and countdowns.
     */
    public function schedule(Request $request, int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);
        $selectedDate = $request->query('date');
        $date = $selectedDate ? \Carbon\Carbon::parse($selectedDate)->toDateString() : today()->toDateString();

        $run = \App\Models\PlannerRun::where('warmup_campaign_id', $campaign->id)
            ->whereDate('plan_date', $date)
            ->first();

        $query = \App\Models\WarmupEvent::where('warmup_campaign_id', $campaign->id)
            ->with(['thread.senderMailbox', 'thread.seedMailbox']);

        if ($run) {
            $query->where('payload->planner_run_id', $run->id);
        } else {
            $query->whereDate('scheduled_at', $date);
        }

        $events = $query->orderBy('scheduled_at')->get();

        $plannerRunIds = collect($run ? [$run->id] : [])->merge(
            $events->pluck('payload')
                ->filter(fn($p) => is_array($p) && isset($p['planner_run_id']))
                ->map(fn($p) => (int) $p['planner_run_id'])
        )->unique()->values();

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
            $run = \App\Models\PlannerRun::where('warmup_campaign_id', $campaign->id)
                ->whereDate('plan_date', \Carbon\Carbon::parse($selectedDate)->toDateString())
                ->first();

            if ($run) {
                $query->where('payload->planner_run_id', $run->id);
            } else {
                $query->whereDate('scheduled_at', \Carbon\Carbon::parse($selectedDate)->toDateString());
            }
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

    private function friendlyFailureReason(?string $failureReason, ?string $eventType): array
    {
        $raw = trim((string) $failureReason);
        $normalized = strtolower($raw);

        if ($raw === '') {
            return [
                'title' => 'Task failed',
                'reason' => 'This task failed, but no detailed reason was saved.',
            ];
        }

        if (str_contains($normalized, 'message not found after 3 checks')) {
            return [
                'title' => 'Email not found',
                'reason' => 'The system could not find the expected email in the mailbox after 3 checks.',
            ];
        }

        if (str_contains($normalized, 'imap unavailable after 3 checks')) {
            return [
                'title' => 'Mailbox connection issue',
                'reason' => 'The mailbox (IMAP) was not reachable after 3 checks.',
            ];
        }

        if (
            str_contains($normalized, 'failed to authenticate on smtp server') ||
            str_contains($normalized, 'authenticationfailed') ||
            str_contains($normalized, 'username and password not accepted') ||
            str_contains($normalized, 'badcredentials')
        ) {
            return [
                'title' => 'Login failed',
                'reason' => 'Mailbox login failed. SMTP/IMAP credentials or app password may be incorrect.',
            ];
        }

        if (str_contains($normalized, 'modelnotfoundexception') && str_contains($normalized, 'app\\models\\thread')) {
            return [
                'title' => 'Conversation missing',
                'reason' => 'The related email conversation could not be found, so this task could not continue.',
            ];
        }

        if (str_contains($normalized, 'data truncated for column') && str_contains($normalized, 'outcome')) {
            return [
                'title' => 'Failure logging issue',
                'reason' => 'This task failed, and the system also hit a logging format issue while saving failure details.',
            ];
        }

        if (str_contains($normalized, 'contentguardservice::recordusage')) {
            return [
                'title' => 'Internal content check error',
                'reason' => 'A reply-content validation step failed due to an internal mismatch.',
            ];
        }

        $fallbackTitle = $eventType
            ? str_replace('_', ' ', ucfirst((string) $eventType)) . ' failed'
            : 'Task failed';

        return [
            'title' => $fallbackTitle,
            'reason' => 'This task failed due to a technical error. Open campaign detail to see full diagnostics.',
        ];
    }
}
