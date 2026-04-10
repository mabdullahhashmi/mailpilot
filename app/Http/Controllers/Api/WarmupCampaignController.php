<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SenderMailbox;
use App\Services\WarmupCampaignService;
use App\Services\ReportingService;
use App\Services\ReadinessScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarmupCampaignController extends Controller
{
    public function __construct(
        private WarmupCampaignService $campaignService,
        private ReportingService $reportingService,
        private ReadinessScoringService $readinessService,
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
}
