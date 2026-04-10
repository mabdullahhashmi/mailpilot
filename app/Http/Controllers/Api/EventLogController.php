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
        $campaignId = $request->input('campaign_id');
        $limit = min(100, $request->input('limit', 50));

        $logs = $this->logging->getRecentLogs($limit, $campaignId);
        return response()->json($logs);
    }

    public function performance(): JsonResponse
    {
        $stats = $this->logging->getPerformanceStats(24);
        return response()->json($stats);
    }
}
