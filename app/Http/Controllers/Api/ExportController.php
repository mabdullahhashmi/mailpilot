<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarmupEventLog;
use App\Models\WarmupEvent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function eventLogs(Request $request): StreamedResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'campaign_id' => 'nullable|integer',
            'sender_id' => 'nullable|integer',
            'outcome' => 'nullable|string',
        ]);

        $query = WarmupEventLog::query()
            ->join('warmup_events', 'warmup_event_logs.warmup_event_id', '=', 'warmup_events.id')
            ->select([
                'warmup_event_logs.id',
                'warmup_events.warmup_campaign_id',
                'warmup_events.sender_mailbox_id',
                'warmup_events.seed_mailbox_id',
                'warmup_events.event_type',
                'warmup_event_logs.outcome',
                'warmup_event_logs.details',
                'warmup_event_logs.execution_time_ms',
                'warmup_event_logs.created_at',
            ]);

        if ($request->from) {
            $query->where('warmup_event_logs.created_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('warmup_event_logs.created_at', '<=', $request->to . ' 23:59:59');
        }
        if ($request->campaign_id) {
            $query->where('warmup_events.warmup_campaign_id', $request->campaign_id);
        }
        if ($request->sender_id) {
            $query->where('warmup_events.sender_mailbox_id', $request->sender_id);
        }
        if ($request->outcome) {
            $query->where('warmup_event_logs.outcome', $request->outcome);
        }

        $query->orderByDesc('warmup_event_logs.created_at');

        $filename = 'event-logs-' . now()->format('Y-m-d-His') . '.csv';

        return new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Log ID', 'Campaign ID', 'Sender ID', 'Seed ID',
                'Event Type', 'Outcome', 'Details', 'Exec Time (ms)', 'Timestamp'
            ]);

            $query->chunk(500, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->id,
                        $log->warmup_campaign_id,
                        $log->sender_mailbox_id,
                        $log->seed_mailbox_id,
                        $log->event_type,
                        $log->outcome,
                        $log->details,
                        $log->execution_time_ms,
                        $log->created_at,
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function senderHealth(Request $request): StreamedResponse
    {
        $query = \App\Models\MailboxHealthLog::query()
            ->join('sender_mailboxes', 'mailbox_health_logs.sender_mailbox_id', '=', 'sender_mailboxes.id')
            ->select([
                'mailbox_health_logs.id',
                'sender_mailboxes.email',
                'mailbox_health_logs.log_date',
                'mailbox_health_logs.warmup_day',
                'mailbox_health_logs.sent_today',
                'mailbox_health_logs.replied_today',
                'mailbox_health_logs.health_score',
                'mailbox_health_logs.readiness_score',
                'mailbox_health_logs.failed_events',
                'mailbox_health_logs.auth_failures',
                'mailbox_health_logs.smtp_status',
                'mailbox_health_logs.imap_status',
            ])
            ->orderByDesc('mailbox_health_logs.log_date');

        if ($request->sender_id) {
            $query->where('mailbox_health_logs.sender_mailbox_id', $request->sender_id);
        }

        $filename = 'sender-health-' . now()->format('Y-m-d-His') . '.csv';

        return new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'ID', 'Email', 'Date', 'Warmup Day', 'Sent', 'Replied',
                'Health Score', 'Readiness', 'Failed', 'Auth Fails', 'SMTP', 'IMAP'
            ]);

            $query->chunk(500, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->id,
                        $log->email,
                        $log->log_date,
                        $log->warmup_day,
                        $log->sent_today,
                        $log->replied_today,
                        $log->health_score,
                        $log->readiness_score,
                        $log->failed_events,
                        $log->auth_failures,
                        $log->smtp_status,
                        $log->imap_status,
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
