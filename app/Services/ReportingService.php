<?php

namespace App\Services;

use App\Models\WarmupCampaign;
use App\Models\WarmupEvent;
use App\Models\WarmupEventLog;
use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use App\Models\Domain;
use App\Models\Thread;
use App\Models\MailboxHealthLog;
use Illuminate\Support\Facades\DB;

class ReportingService
{
    /**
     * Get campaign summary report.
     */
    public function campaignReport(WarmupCampaign $campaign): array
    {
        $events = WarmupEvent::where('warmup_campaign_id', $campaign->id);

        $totalEvents = $events->count();
        $completed = (clone $events)->where('status', 'completed')->count();
        $failed = (clone $events)->where('status', 'final_failed')->count();
        $pending = (clone $events)->where('status', 'pending')->count();

        $threads = Thread::where('warmup_campaign_id', $campaign->id);
        $totalThreads = $threads->count();
        $closedThreads = (clone $threads)->where('thread_status', 'closed')->count();

        return [
            'campaign_id' => $campaign->id,
            'status' => $campaign->status,
            'current_day' => $campaign->current_day_number,
            'current_stage' => $campaign->current_stage,
            'total_events' => $totalEvents,
            'completed_events' => $completed,
            'failed_events' => $failed,
            'pending_events' => $pending,
            'success_rate' => $totalEvents > 0 ? round(($completed / $totalEvents) * 100, 1) : 0,
            'total_threads' => $totalThreads,
            'closed_threads' => $closedThreads,
        ];
    }

    /**
     * Get sender mailbox report.
     */
    public function senderReport(SenderMailbox $sender): array
    {
        $healthLogs = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->orderBy('log_date', 'desc')
            ->take(30)
            ->get();

        $avgHealth = $healthLogs->avg('health_score') ?? 0;
        $totalSends = $healthLogs->sum('sends_today');
        $totalReplies = $healthLogs->sum('replies_today');
        $totalBounces = $healthLogs->sum('bounces_today');

        return [
            'mailbox_id' => $sender->id,
            'email' => $sender->email_address,
            'status' => $sender->status,
            'avg_health_30d' => round($avgHealth, 1),
            'total_sends_30d' => $totalSends,
            'total_replies_30d' => $totalReplies,
            'total_bounces_30d' => $totalBounces,
            'reply_rate' => $totalSends > 0 ? round(($totalReplies / $totalSends) * 100, 1) : 0,
            'bounce_rate' => $totalSends > 0 ? round(($totalBounces / $totalSends) * 100, 1) : 0,
            'daily_trend' => $healthLogs->map(fn($l) => [
                'date' => $l->log_date->format('Y-m-d'),
                'score' => $l->health_score,
                'sends' => $l->sends_today,
                'replies' => $l->replies_today,
            ])->toArray(),
        ];
    }

    /**
     * Get domain report.
     */
    public function domainReport(Domain $domain): array
    {
        $senders = $domain->senderMailboxes;

        return [
            'domain_id' => $domain->id,
            'domain_name' => $domain->domain_name,
            'health_score' => $domain->health_score,
            'dns_status' => $domain->dns_check_results,
            'sender_count' => $senders->count(),
            'active_senders' => $senders->where('status', 'active')->count(),
            'daily_cap' => $domain->daily_sending_cap,
            'today_usage' => WarmupEvent::whereHas('warmupCampaign', fn($q) => $q->where('domain_id', $domain->id))
                ->where('status', 'completed')
                ->whereDate('executed_at', today())
                ->count(),
        ];
    }

    /**
     * Get daily activity report (for dashboard).
     */
    public function dailyActivityReport(?string $date = null): array
    {
        $date = $date ? \Carbon\Carbon::parse($date) : today();

        $events = WarmupEvent::whereDate('executed_at', $date)
            ->where('status', 'completed')
            ->get();

        $byType = $events->groupBy('event_type')->map->count();

        return [
            'date' => $date->format('Y-m-d'),
            'total_events' => $events->count(),
            'events_by_type' => $byType->toArray(),
            'unique_senders' => $events->unique('actor_mailbox_id')->count(),
            'unique_threads' => $events->unique('thread_id')->count(),
        ];
    }

    /**
     * Get weekly summary for all campaigns.
     */
    public function weeklySummary(): array
    {
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        return [
            'period' => $startOfWeek->format('M d') . ' - ' . $endOfWeek->format('M d, Y'),
            'active_campaigns' => WarmupCampaign::where('status', 'active')->count(),
            'total_events' => WarmupEvent::where('status', 'completed')
                ->whereBetween('executed_at', [$startOfWeek, $endOfWeek])
                ->count(),
            'new_threads' => Thread::whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->count(),
            'active_senders' => SenderMailbox::where('status', 'active')->count(),
            'active_seeds' => SeedMailbox::where('status', 'active')->count(),
        ];
    }
}
