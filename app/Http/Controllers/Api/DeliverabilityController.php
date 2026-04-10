<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlacementTestService;
use App\Services\BounceIntelligenceService;
use App\Services\ReputationService;
use App\Services\SendingStrategyService;
use App\Models\SenderMailbox;
use App\Models\Domain;
use App\Models\PlacementTest;
use App\Models\BounceEvent;
use App\Models\ReputationScore;
use App\Models\SendingStrategyLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeliverabilityController extends Controller
{
    public function __construct(
        private PlacementTestService $placementService,
        private BounceIntelligenceService $bounceService,
        private ReputationService $reputationService,
        private SendingStrategyService $strategyService,
    ) {}

    // ── Command Center Overview ──

    public function overview(): JsonResponse
    {
        $placement = [];
        $bounces = [];
        $reputation = [];
        $strategy = [];

        try { $placement = $this->placementService->getOverallStats(); } catch (\Throwable $e) {}
        try { $bounces = $this->bounceService->getOverallBounceStats(7); } catch (\Throwable $e) {}
        try { $reputation = $this->reputationService->getDashboardData(); } catch (\Throwable $e) {}
        try { $strategy = $this->strategyService->getDashboardData(); } catch (\Throwable $e) {}

        // Overall risk assessment
        $avgPlacement = $placement['avg_score'] ?? 0;
        $totalBounces = $bounces['total'] ?? 0;
        $avgReputation = $reputation['domains']['avg_score'] ?? 0;
        $criticalDomains = $reputation['domains']['critical'] ?? 0;
        $criticalSenders = $reputation['senders']['critical'] ?? 0;

        $overallStatus = 'healthy';
        if ($criticalDomains > 0 || $criticalSenders > 0 || $avgPlacement < 30) {
            $overallStatus = 'critical';
        } elseif ($totalBounces > 10 || $avgPlacement < 50 || $avgReputation < 50) {
            $overallStatus = 'warning';
        }

        return response()->json([
            'overall_status' => $overallStatus,
            'placement' => $placement,
            'bounces' => $bounces,
            'reputation' => $reputation,
            'strategy' => $strategy,
            'senders' => SenderMailbox::where('status', 'active')
                ->get(['id', 'email_address'])
                ->map(fn ($s) => ['id' => $s->id, 'email' => $s->email_address])
                ->values(),
        ]);
    }

    // ── Placement Test Endpoints ──

    public function runPlacementTest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sender_mailbox_id' => 'required|exists:sender_mailboxes,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $sender = SenderMailbox::findOrFail($request->sender_mailbox_id);
        $test = $this->placementService->runTest($sender);

        return response()->json([
            'test' => $test,
            'results' => $test->results,
        ]);
    }

    public function placementHistory(int $senderId): JsonResponse
    {
        $sender = SenderMailbox::findOrFail($senderId);

        return response()->json([
            'trend' => $this->placementService->getPlacementTrend($sender),
            'tests' => PlacementTest::where('sender_mailbox_id', $senderId)
                ->orderByDesc('created_at')
                ->take(20)
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'status' => $t->status,
                    'score' => $t->placement_score,
                    'inbox' => $t->inbox_count,
                    'spam' => $t->spam_count,
                    'missing' => $t->missing_count,
                    'seeds_tested' => $t->seeds_tested,
                    'date' => $t->completed_at?->format('Y-m-d H:i') ?? $t->created_at->format('Y-m-d H:i'),
                ]),
        ]);
    }

    public function placementTestDetail(int $testId): JsonResponse
    {
        $test = PlacementTest::with('results.seedMailbox:id,email_address,provider_type')->findOrFail($testId);

        return response()->json([
            'test' => [
                'id' => $test->id,
                'sender_id' => $test->sender_mailbox_id,
                'status' => $test->status,
                'score' => $test->placement_score,
                'inbox' => $test->inbox_count,
                'spam' => $test->spam_count,
                'missing' => $test->missing_count,
                'started_at' => $test->started_at?->format('Y-m-d H:i'),
                'completed_at' => $test->completed_at?->format('Y-m-d H:i'),
            ],
            'results' => $test->results->map(fn ($r) => [
                'seed_email' => $r->seedMailbox?->email_address,
                'provider' => $r->provider,
                'result' => $r->result,
                'delivery_time' => $r->delivery_time_seconds,
            ]),
        ]);
    }

    // ── Bounce Intelligence Endpoints ──

    public function bounceOverview(): JsonResponse
    {
        return response()->json([
            'stats' => $this->bounceService->getOverallBounceStats(7),
            'root_causes' => $this->bounceService->getRootCauseSummary(7),
        ]);
    }

    public function senderBounces(int $senderId): JsonResponse
    {
        $sender = SenderMailbox::findOrFail($senderId);

        return response()->json(
            $this->bounceService->getSenderBounceAnalytics($sender, 30)
        );
    }

    public function suppressionCandidates(): JsonResponse
    {
        return response()->json([
            'candidates' => $this->bounceService->getSuppressionCandidates(),
        ]);
    }

    public function suppressEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email:rfc,dns',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $suppressed = $this->bounceService->suppressEmail($request->email);

        return response()->json([
            'suppressed' => $suppressed,
            'email' => $request->email,
        ]);
    }

    public function bounceLog(Request $request): JsonResponse
    {
        $query = BounceEvent::with('senderMailbox:id,email_address')
            ->orderByDesc('bounced_at');

        if ($request->sender_id) {
            $query->where('sender_mailbox_id', $request->sender_id);
        }
        if ($request->bounce_type) {
            $query->where('bounce_type', $request->bounce_type);
        }
        if ($request->provider) {
            $query->where('provider', $request->provider);
        }

        $bounces = $query->take(100)->get();

        return response()->json([
            'bounces' => $bounces->map(fn ($b) => [
                'id' => $b->id,
                'sender' => $b->senderMailbox?->email_address,
                'recipient' => $b->recipient_email,
                'type' => $b->bounce_type,
                'code' => $b->bounce_code,
                'message' => $b->bounce_message,
                'provider' => $b->provider,
                'suppressed' => $b->is_suppressed,
                'date' => $b->bounced_at?->format('Y-m-d H:i'),
            ]),
        ]);
    }

    // ── Reputation Endpoints ──

    public function reputationOverview(): JsonResponse
    {
        return response()->json(
            $this->reputationService->getDashboardData()
        );
    }

    public function domainReputation(int $domainId): JsonResponse
    {
        $domain = Domain::findOrFail($domainId);

        return response()->json([
            'domain' => [
                'id' => $domain->id,
                'name' => $domain->domain_name,
                'reputation_score' => $domain->reputation_score,
                'risk_level' => $domain->reputation_risk_level,
                'dns' => [
                    'spf' => $domain->spf_status,
                    'dkim' => $domain->dkim_status,
                    'dmarc' => $domain->dmarc_status,
                    'mx' => $domain->mx_status,
                ],
            ],
            'trend' => $this->reputationService->getReputationTrend($domainId, null, 30),
            'dns_audit' => $this->reputationService->getDnsAuditHistory($domain),
        ]);
    }

    public function senderReputation(int $senderId): JsonResponse
    {
        $sender = SenderMailbox::findOrFail($senderId);

        return response()->json([
            'sender' => [
                'id' => $sender->id,
                'email' => $sender->email_address,
                'reputation_score' => $sender->reputation_score,
                'risk_level' => $sender->reputation_risk,
                'placement_score' => $sender->placement_score,
            ],
            'trend' => $this->reputationService->getReputationTrend(null, $senderId, 30),
        ]);
    }

    public function runReputationScan(): JsonResponse
    {
        $results = $this->reputationService->runFullScan();

        return response()->json([
            'message' => 'Reputation scan completed',
            'results' => $results,
        ]);
    }

    // ── Sending Strategy Endpoints ──

    public function strategyOverview(): JsonResponse
    {
        return response()->json(
            $this->strategyService->getDashboardData()
        );
    }

    public function analyzeSender(int $senderId): JsonResponse
    {
        $sender = SenderMailbox::findOrFail($senderId);
        $log = $this->strategyService->analyze($sender);

        return response()->json([
            'recommendation' => $log->recommendation,
            'current_cap' => $log->current_daily_cap,
            'recommended_cap' => $log->recommended_daily_cap,
            'reasoning' => $log->reasoning,
            'metrics' => $log->metrics_snapshot,
        ]);
    }

    public function applyStrategy(int $senderId): JsonResponse
    {
        $sender = SenderMailbox::findOrFail($senderId);
        $log = $this->strategyService->analyzeAndApply($sender);

        return response()->json([
            'applied' => $log->was_applied,
            'recommendation' => $log->recommendation,
            'new_cap' => $log->recommended_daily_cap,
            'reasoning' => $log->reasoning,
        ]);
    }

    public function senderStrategyHistory(int $senderId): JsonResponse
    {
        $sender = SenderMailbox::findOrFail($senderId);

        return response()->json([
            'history' => $this->strategyService->getSenderHistory($sender),
        ]);
    }

    public function runStrategyAnalysis(Request $request): JsonResponse
    {
        $autoApply = $request->boolean('auto_apply', false);
        $results = $this->strategyService->analyzeAll($autoApply);

        return response()->json([
            'message' => 'Strategy analysis completed',
            'results' => $results,
        ]);
    }
}
