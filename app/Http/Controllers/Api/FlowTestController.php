<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlowTestRun;
use App\Models\SeedMailbox;
use App\Models\SenderMailbox;
use App\Services\FlowTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowTestController extends Controller
{
    public function __construct(private FlowTestService $flowTests) {}

    public function meta(): JsonResponse
    {
        $senders = SenderMailbox::where('status', 'active')
            ->orderBy('email_address')
            ->get(['id', 'email_address', 'status']);

        $seeds = SeedMailbox::where('status', 'active')
            ->orderBy('email_address')
            ->get(['id', 'email_address', 'provider_type', 'status']);

        return response()->json([
            'senders' => $senders,
            'seeds' => $seeds,
            'defaults' => [
                'phase_count' => 3,
                'open_delay_seconds' => 20,
                'star_delay_seconds' => 10,
                'reply_delay_seconds' => 20,
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        $runs = FlowTestRun::with(['senderMailbox:id,email_address'])
            ->withCount([
                'steps as steps_total',
                'steps as steps_pending' => fn($q) => $q->whereIn('status', ['pending', 'executing']),
                'steps as steps_completed' => fn($q) => $q->where('status', 'completed'),
                'steps as steps_failed' => fn($q) => $q->where('status', 'failed'),
                'steps as steps_skipped' => fn($q) => $q->where('status', 'skipped'),
            ])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'runs' => $runs,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $run = FlowTestRun::with([
            'senderMailbox:id,email_address',
            'steps.seedMailbox:id,email_address,provider_type',
        ])->findOrFail($id);

        return response()->json([
            'run' => $run,
            'steps' => $run->steps,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sender_mailbox_id' => 'required|integer|exists:sender_mailboxes,id',
            'seed_ids' => 'required|array|min:1|max:3',
            'seed_ids.*' => 'required|integer|distinct|exists:seed_mailboxes,id',
            'phase_count' => 'required|integer|min:1|max:5',
            'open_delay_seconds' => 'nullable|integer|min:1|max:300',
            'star_delay_seconds' => 'nullable|integer|min:0|max:120',
            'reply_delay_seconds' => 'nullable|integer|min:1|max:300',
        ]);

        $sender = SenderMailbox::where('status', 'active')->findOrFail($validated['sender_mailbox_id']);

        $run = $this->flowTests->createRun(
            $sender,
            $validated['seed_ids'],
            (int) $validated['phase_count'],
            (int) ($validated['open_delay_seconds'] ?? 20),
            (int) ($validated['star_delay_seconds'] ?? 10),
            (int) ($validated['reply_delay_seconds'] ?? 20),
            auth()->id()
        );

        return response()->json([
            'success' => true,
            'message' => 'Flow test run queued. Steps will execute automatically with second-based delays.',
            'run_id' => $run->id,
        ]);
    }
}
