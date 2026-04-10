<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SenderMailbox;
use App\Models\MailboxHealthLog;
use App\Services\HealthService;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SenderHealthController extends Controller
{
    public function __construct(
        private HealthService $healthService,
        private ReportingService $reportingService,
    ) {}

    /**
     * Get health overview for all senders.
     */
    public function index(): JsonResponse
    {
        $senders = SenderMailbox::with(['domain'])->get();

        $data = $senders->map(function ($sender) {
            $report = $this->reportingService->senderReport($sender);
            return [
                'id' => $sender->id,
                'email' => $sender->email_address,
                'provider' => $sender->provider_type,
                'status' => $sender->status,
                'domain' => $sender->domain?->domain_name,
                'health_score' => $sender->health_score ?? 50,
                'avg_health_30d' => $report['avg_health_30d'],
                'total_sends_30d' => $report['total_sends_30d'],
                'total_replies_30d' => $report['total_replies_30d'],
                'total_bounces_30d' => $report['total_bounces_30d'],
                'reply_rate' => $report['reply_rate'],
                'bounce_rate' => $report['bounce_rate'],
            ];
        });

        return response()->json($data);
    }

    /**
     * Get detailed health history for a single sender.
     */
    public function show(int $id): JsonResponse
    {
        $sender = SenderMailbox::findOrFail($id);
        $report = $this->reportingService->senderReport($sender);

        $healthLogs = MailboxHealthLog::where('sender_mailbox_id', $id)
            ->orderBy('log_date', 'asc')
            ->take(60)
            ->get()
            ->map(fn($l) => [
                'date' => $l->log_date->format('Y-m-d'),
                'score' => $l->health_score ?? 50,
                'sends' => $l->sent_today ?? 0,
                'replies' => $l->replied_today ?? 0,
                'bounces' => $l->failed_events ?? 0,
            ]);

        return response()->json([
            'sender' => [
                'id' => $sender->id,
                'email' => $sender->email_address,
                'status' => $sender->status,
                'provider' => $sender->provider_type,
                'health_score' => $sender->health_score ?? 50,
                'domain' => $sender->domain?->domain_name,
            ],
            'report' => $report,
            'history' => $healthLogs,
        ]);
    }
}
