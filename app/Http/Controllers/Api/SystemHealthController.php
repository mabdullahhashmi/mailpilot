<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use App\Models\Domain;
use App\Models\WarmupCampaign;
use App\Models\WarmupEvent;
use App\Models\SystemAlert;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SystemHealthController extends Controller
{
    public function index(): JsonResponse
    {
        // Queue stats
        $queuePending = DB::table('jobs')->count();
        $queueFailed = DB::table('failed_jobs')->count();

        // Cron timestamps
        $lastScheduler = SystemSetting::get('cron_last_scheduler_run');
        $lastPlanner = SystemSetting::get('cron_last_planner_run');
        $lastExecutor = SystemSetting::get('cron_last_executor_run');
        $lastDns = SystemSetting::get('cron_last_dns_check');

        // Entity counts
        $senders = SenderMailbox::selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = "paused" OR is_paused = 1 THEN 1 ELSE 0 END) as paused
        ')->first();

        $seeds = SeedMailbox::selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active
        ')->first();

        $campaigns = WarmupCampaign::selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = "paused" THEN 1 ELSE 0 END) as paused
        ')->first();

        $domains = Domain::count();

        // Events today
        $eventsToday = WarmupEvent::whereDate('executed_at', today())
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = "final_failed" THEN 1 ELSE 0 END) as failed
            ')->first();

        // Pending events
        $pendingEvents = WarmupEvent::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->count();

        // Recent alerts
        $recentAlerts = SystemAlert::where('is_dismissed', false)
            ->orderByDesc('created_at')
            ->take(10)
            ->get(['id', 'severity', 'title', 'message', 'created_at', 'is_read']);

        // Auto-pause count
        $autoPauseCount = DB::table('pause_rules')
            ->where('status', 'active')
            ->where('reason', '!=', 'manual')
            ->count();

        return response()->json([
            'queue' => [
                'pending_jobs' => $queuePending,
                'failed_jobs' => $queueFailed,
            ],
            'cron' => [
                'last_scheduler' => $lastScheduler,
                'last_planner' => $lastPlanner,
                'last_executor' => $lastExecutor,
                'last_dns_check' => $lastDns,
            ],
            'entities' => [
                'senders_total' => $senders->total ?? 0,
                'senders_active' => $senders->active ?? 0,
                'senders_paused' => $senders->paused ?? 0,
                'seeds_total' => $seeds->total ?? 0,
                'seeds_active' => $seeds->active ?? 0,
                'campaigns_total' => $campaigns->total ?? 0,
                'campaigns_active' => $campaigns->active ?? 0,
                'campaigns_paused' => $campaigns->paused ?? 0,
                'domains' => $domains,
            ],
            'events_today' => [
                'total' => $eventsToday->total ?? 0,
                'completed' => $eventsToday->completed ?? 0,
                'failed' => $eventsToday->failed ?? 0,
            ],
            'pending_events' => $pendingEvents,
            'auto_pause_count' => $autoPauseCount,
            'recent_alerts' => $recentAlerts,
        ]);
    }
}
