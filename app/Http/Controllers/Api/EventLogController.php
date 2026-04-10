<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoggingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventLogController extends Controller
{
    public function __construct(private LoggingService $logging) {}

    public function index(Request $request): JsonResponse
    {
        $query = \App\Models\WarmupEventLog::with(['event.campaign'])
            ->orderBy('created_at', 'desc');

        if ($status = $request->input('status')) {
            $query->where('outcome', $status);
        }
        if ($eventType = $request->input('event_type')) {
            $query->where('event_type', $eventType);
        }
        if ($date = $request->input('date')) {
            $query->whereDate('created_at', $date);
        }
        if ($campaignId = $request->input('campaign_id')) {
            $query->whereHas('event', fn($q) => $q->where('warmup_campaign_id', $campaignId));
        }

        $paginated = $query->paginate(50);

        // Attach stats
        $statsQuery = \App\Models\WarmupEventLog::query();
        if ($date) $statsQuery->whereDate('created_at', $date);

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'success' => (clone $statsQuery)->where('outcome', 'success')->count(),
            'failure' => (clone $statsQuery)->where('outcome', 'failure')->count(),
            'retry' => (clone $statsQuery)->where('outcome', 'retry')->count(),
            'skipped' => (clone $statsQuery)->where('outcome', 'skipped')->count(),
        ];

        $data = $paginated->toArray();
        $data['stats'] = $stats;

        return response()->json($data);
    }

    public function performance(): JsonResponse
    {
        $stats = $this->logging->getPerformanceStats(24);
        return response()->json($stats);
    }
}
