<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use App\Services\ReadinessScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private ReportingService $reporting,
        private ReadinessScoringService $readiness,
    ) {}

    public function overview(): JsonResponse
    {
        $weekly = $this->reporting->weeklySummary();
        $today = $this->reporting->dailyActivityReport();

        return response()->json([
            'weekly' => $weekly,
            'today' => $today,
        ]);
    }

    public function senderReadiness(): JsonResponse
    {
        $senders = \App\Models\SenderMailbox::where('status', 'active')->get();

        $data = $senders->map(function ($sender) {
            return array_merge(
                ['id' => $sender->id, 'email' => $sender->email_address],
                $this->readiness->getReadinessSummary($sender)
            );
        });

        return response()->json($data);
    }

    public function activityChart(Request $request): JsonResponse
    {
        $days = $request->input('days', 14);
        $data = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = today()->subDays($i)->format('Y-m-d');
            $data[] = $this->reporting->dailyActivityReport($date);
        }

        return response()->json($data);
    }
}
