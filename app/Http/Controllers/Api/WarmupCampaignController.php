<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SenderMailbox;
use App\Services\WarmupCampaignService;
use App\Services\ReportingService;
use App\Services\ReadinessScoringService;
use App\Services\DailyPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarmupCampaignController extends Controller
{
    public function __construct(
        private WarmupCampaignService $campaignService,
        private ReportingService $reportingService,
        private ReadinessScoringService $readinessService,
        private DailyPlannerService $plannerService,
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

    public function show(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::with(['senderMailbox', 'domain', 'profile', 'threads'])
            ->findOrFail($id);

        $report = $this->reportingService->campaignReport($campaign);
        $readiness = $this->readinessService->getReadinessSummary($campaign->senderMailbox);

        return response()->json([
            'campaign' => $campaign,
            'report' => $report,
            'readiness' => $readiness,
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
    public function schedule(int $id): JsonResponse
    {
        $campaign = \App\Models\WarmupCampaign::findOrFail($id);

        $events = \App\Models\WarmupEvent::where('warmup_campaign_id', $campaign->id)
            ->whereDate('scheduled_at', today())
            ->orderBy('scheduled_at')
            ->with(['thread.senderMailbox', 'thread.seedMailbox'])
            ->get()
            ->map(function ($event) {
                $sender = $event->thread?->senderMailbox;
                $seed = $event->thread?->seedMailbox;

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
                ];
            });

        return response()->json([
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->campaign_name,
            'server_time' => now()->toIso8601String(),
            'events' => $events,
        ]);
    }
}
