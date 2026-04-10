<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemDiagnosticService;
use App\Services\SeedHealthService;
use App\Services\SlotSchedulerService;
use App\Services\ContentGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiagnosticController extends Controller
{
    public function __construct(
        private SystemDiagnosticService $diagnostic,
        private SeedHealthService $seedHealth,
        private SlotSchedulerService $slotScheduler,
        private ContentGuardService $contentGuard,
    ) {}

    /**
     * Run live system diagnostic.
     */
    public function liveDiagnostic(): JsonResponse
    {
        return response()->json($this->diagnostic->runLiveDiagnostic());
    }

    /**
     * Get cron health status.
     */
    public function cronHealth(): JsonResponse
    {
        return response()->json($this->diagnostic->checkCronHealth());
    }

    /**
     * Fix stuck events.
     */
    public function fixStuckEvents(): JsonResponse
    {
        $fixed = $this->diagnostic->fixStuckEvents();
        return response()->json(['fixed' => $fixed, 'message' => "Released {$fixed} stuck event locks"]);
    }

    /**
     * Get today's send slots for a campaign.
     */
    public function todaySlots(int $campaignId): JsonResponse
    {
        return response()->json($this->slotScheduler->getTodaySlots($campaignId));
    }

    /**
     * Get slot statistics.
     */
    public function slotStats(Request $request, int $campaignId): JsonResponse
    {
        return response()->json($this->slotScheduler->getSlotStats(
            $campaignId,
            $request->query('from'),
            $request->query('to')
        ));
    }

    /**
     * Get seed health report.
     */
    public function seedHealthReport(): JsonResponse
    {
        return response()->json($this->seedHealth->getSeedHealthReport());
    }

    /**
     * Run seed health check.
     */
    public function runSeedHealthCheck(): JsonResponse
    {
        $results = $this->seedHealth->checkAllSeeds();
        return response()->json($results);
    }

    /**
     * Re-enable a disabled seed.
     */
    public function reEnableSeed(int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $this->seedHealth->reEnableSeed($seed);
        return response()->json(['message' => "Seed {$seed->email_address} re-enabled"]);
    }

    /**
     * Get content anti-pattern warnings for a sender.
     */
    public function contentWarnings(int $senderId): JsonResponse
    {
        $sender = \App\Models\SenderMailbox::findOrFail($senderId);
        return response()->json($this->contentGuard->checkAntiPatterns($sender));
    }

    /**
     * Get diagnostic snapshot history.
     */
    public function snapshotHistory(): JsonResponse
    {
        $snapshots = \App\Models\DiagnosticSnapshot::orderBy('snapshot_date', 'desc')
            ->limit(30)
            ->get();

        return response()->json($snapshots);
    }
}
