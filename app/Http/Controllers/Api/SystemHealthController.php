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
use App\Services\DailyPlannerService;
use App\Services\SchedulerService;
use App\Models\ContentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SystemHealthController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            // Queue stats
            $queuePending = DB::table('jobs')->count();
            $queueFailed = DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            $queuePending = 0;
            $queueFailed = 0;
        }

        // Cron timestamps
        $lastScheduler = SystemSetting::get('last_scheduler_run');
        $lastPlanner = SystemSetting::get('last_planner_run');
        $lastHealth = SystemSetting::get('last_health_run');
        $lastDns = SystemSetting::get('last_dns_run');

        // Entity counts
        $senders = SenderMailbox::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'paused' OR is_paused = 1 THEN 1 ELSE 0 END) as paused
        ")->first();

        $seeds = SeedMailbox::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
        ")->first();

        $campaigns = WarmupCampaign::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused
        ")->first();

        $domains = Domain::count();

        // Events today
        $eventsToday = WarmupEvent::whereDate('executed_at', today())
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'final_failed' THEN 1 ELSE 0 END) as failed
            ")->first();

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
        try {
            $autoPauseCount = DB::table('pause_rules')
                ->where('status', 'active')
                ->where('reason', '!=', 'manual')
                ->count();
        } catch (\Throwable $e) {
            $autoPauseCount = 0;
        }

        return response()->json([
            'queue' => [
                'pending_jobs' => $queuePending,
                'failed_jobs' => $queueFailed,
            ],
            'cron' => [
                'last_scheduler' => $lastScheduler,
                'last_planner' => $lastPlanner,
                'last_health' => $lastHealth,
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

    /**
     * Manually trigger the daily planner (creates threads + events for active campaigns).
     */
    public function triggerPlanner(Request $request, DailyPlannerService $planner): JsonResponse
    {
        $force = (bool) $request->input('force', false);
        try {
            $runs = $planner->planAllCampaigns($force);
            $results = [];
            foreach ($runs as $run) {
                $results[] = [
                    'campaign_id' => $run->warmup_campaign_id,
                    'new_threads' => $run->new_thread_target,
                    'replies' => $run->reply_target,
                    'status' => $run->status,
                ];
            }
            SystemSetting::set('last_planner_run', now()->toDateTimeString(), 'cron');

            // If no runs, build diagnostic info
            $diagnostic = null;
            if (count($runs) === 0) {
                $allCampaigns = WarmupCampaign::select('id', 'campaign_name', 'status', 'sender_mailbox_id', 'domain_id', 'warmup_profile_id')->get();
                $activeCampaigns = $allCampaigns->where('status', 'active');
                $diagnostic = [
                    'total_campaigns' => $allCampaigns->count(),
                    'active_campaigns' => $activeCampaigns->count(),
                    'campaign_statuses' => $allCampaigns->groupBy('status')->map->count(),
                    'campaigns' => $allCampaigns->map(fn($c) => [
                        'id' => $c->id,
                        'name' => $c->campaign_name,
                        'status' => $c->status,
                        'has_sender' => $c->sender_mailbox_id !== null,
                        'has_domain' => $c->domain_id !== null,
                        'has_profile' => $c->warmup_profile_id !== null,
                    ]),
                    'active_seeds' => SeedMailbox::where('status', 'active')->count(),
                    'active_senders' => SenderMailbox::where('status', 'active')->count(),
                ];

                $reason = 'Unknown';
                if ($allCampaigns->isEmpty()) {
                    $reason = 'No campaigns exist. Create a warmup campaign first.';
                } elseif ($activeCampaigns->isEmpty()) {
                    $statuses = $allCampaigns->pluck('status')->unique()->implode(', ');
                    $reason = "No active campaigns found. Existing campaign statuses: {$statuses}. Make sure your campaign status is 'active'.";
                } elseif (!$force) {
                    $reason = 'All active campaigns are already planned for the current warmup day. Use Force Re-Plan to override.';
                } else {
                    $reason = 'Active campaigns exist but planner created 0 runs. Check Laravel logs for errors (seeds, profile, or safety cap issues).';
                }

                Log::warning('DailyPlanner returned 0 runs', $diagnostic);

                return response()->json([
                    'success' => true,
                    'message' => $reason,
                    'runs' => [],
                    'diagnostic' => $diagnostic,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Daily planner executed. Planned ' . count($runs) . ' campaign(s).',
                'runs' => $results,
            ]);
        } catch (\Throwable $e) {
            Log::error('Manual planner trigger failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => 'Planner failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually trigger the event scheduler (processes due warmup events).
     */
    public function triggerScheduler(Request $request, SchedulerService $scheduler): JsonResponse
    {
        try {
            $defaultBatch = (int) SystemSetting::get('scheduler_batch_size', 20);
            $batchSize = (int) $request->input('batch_size', $defaultBatch);
            $batchSize = max(1, min($batchSize, 500));

            $run = $scheduler->processEvents($batchSize, 10);
            SystemSetting::set('last_scheduler_run', now()->toDateTimeString(), 'cron');

            $remainingDue = $run->summary['remaining_due'] ?? null;
            return response()->json([
                'success' => true,
                'message' => "Processed {$run->events_processed} events. Succeeded: {$run->events_succeeded}, Failed: {$run->events_failed}, Skipped: {$run->events_skipped}" . ($remainingDue !== null ? ". Remaining due: {$remainingDue}" : ''),
                'run' => [
                    'processed' => $run->events_processed,
                    'succeeded' => $run->events_succeeded,
                    'failed' => $run->events_failed,
                    'skipped' => $run->events_skipped,
                    'execution_time_ms' => $run->execution_time_ms,
                    'remaining_due' => $remainingDue,
                    'batch_size' => $batchSize,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Manual scheduler trigger failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => 'Scheduler failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Quick system readiness check - are all prerequisites met?
     */
    public function readinessCheck(): JsonResponse
    {
        $checks = [];

        // Check active senders
        $activeSenders = SenderMailbox::where('status', 'active')->count();
        $checks['senders'] = [
            'ok' => $activeSenders > 0,
            'detail' => $activeSenders > 0 ? "{$activeSenders} active sender(s)" : 'No active senders. Add at least one sender mailbox.',
        ];

        // Check active seeds
        $activeSeeds = SeedMailbox::where('status', 'active')->count();
        $checks['seeds'] = [
            'ok' => $activeSeeds > 0,
            'detail' => $activeSeeds > 0 ? "{$activeSeeds} active seed(s)" : 'No active seeds. Add seed mailboxes (Gmail/Outlook).',
        ];

        // Check content templates
        $activeTemplates = ContentTemplate::where('is_active', true)->count();
        $checks['templates'] = [
            'ok' => $activeTemplates > 0,
            'detail' => $activeTemplates > 0 ? "{$activeTemplates} active template(s)" : 'No templates. Run: php artisan warmup:seed-templates',
        ];

        // Check active campaigns
        $activeCampaigns = WarmupCampaign::where('status', 'active')->count();
        $checks['campaigns'] = [
            'ok' => $activeCampaigns > 0,
            'detail' => $activeCampaigns > 0 ? "{$activeCampaigns} active campaign(s)" : 'No active campaigns. Create and start a warmup campaign.',
        ];

        // Check pending events
        $pendingEvents = WarmupEvent::where('status', 'pending')->count();
        $checks['events'] = [
            'ok' => $pendingEvents > 0 || $activeCampaigns === 0,
            'detail' => $pendingEvents > 0 ? "{$pendingEvents} pending event(s)" : ($activeCampaigns > 0 ? 'No pending events. Run the daily planner first.' : 'N/A (no campaigns)'),
        ];

        // Check domains
        $domains = Domain::count();
        $checks['domains'] = [
            'ok' => $domains > 0,
            'detail' => $domains > 0 ? "{$domains} domain(s)" : 'No domains. Domains are auto-created when adding senders.',
        ];

        $allOk = collect($checks)->every(fn($c) => $c['ok']);

        return response()->json([
            'ready' => $allOk,
            'checks' => $checks,
        ]);
    }
}
