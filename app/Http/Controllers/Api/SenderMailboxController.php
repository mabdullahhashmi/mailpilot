<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SenderMailboxController extends Controller
{
    public function __construct(private MailboxService $service) {}

    public function index(): JsonResponse
    {
        $mailboxes = \App\Models\SenderMailbox::with(['domain', 'warmupCampaigns'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($mailboxes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_address' => 'required|email|unique:sender_mailboxes,email_address',
            'smtp_host' => 'required|string',
            'smtp_port' => 'required|integer',
            'smtp_username' => 'required|string',
            'smtp_password' => 'required|string',
            'smtp_encryption' => 'required|in:tls,ssl,none',
            'imap_host' => 'nullable|string',
            'imap_port' => 'nullable|integer',
            'imap_username' => 'nullable|string',
            'imap_password' => 'nullable|string',
            'imap_encryption' => 'nullable|in:tls,ssl,none',
            'provider' => 'nullable|string',
            'daily_sending_cap' => 'nullable|integer|min:1',
            'warmup_target_daily' => 'nullable|integer|min:1',
            'working_hours_start' => 'nullable|string',
            'working_hours_end' => 'nullable|string',
            'timezone' => 'nullable|string',
        ]);

        $mailbox = $this->service->create($validated);
        return response()->json($mailbox, 201);
    }

    public function show(int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::with(['domain', 'warmupCampaigns', 'healthLogs' => fn($q) => $q->latest()->take(30)])
            ->findOrFail($id);

        return response()->json($mailbox);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'smtp_host' => 'sometimes|string',
            'smtp_port' => 'sometimes|integer',
            'smtp_username' => 'sometimes|string',
            'smtp_password' => 'sometimes|string',
            'smtp_encryption' => 'sometimes|in:tls,ssl,none',
            'imap_host' => 'nullable|string',
            'imap_port' => 'nullable|integer',
            'imap_username' => 'nullable|string',
            'imap_password' => 'nullable|string',
            'imap_encryption' => 'nullable|in:tls,ssl,none',
            'provider' => 'nullable|string',
            'daily_sending_cap' => 'nullable|integer|min:1',
            'warmup_target_daily' => 'nullable|integer|min:1',
            'working_hours_start' => 'nullable|string',
            'working_hours_end' => 'nullable|string',
            'timezone' => 'nullable|string',
        ]);

        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $updated = $this->service->update($mailbox, $validated);
        return response()->json($updated);
    }

    public function destroy(int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $mailbox->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function testSmtp(int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $result = $this->service->testSmtp($mailbox);
        return response()->json($result);
    }

    public function testImap(int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $result = $this->service->testImap($mailbox);
        return response()->json($result);
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $this->service->pause($mailbox, $request->input('reason', 'Manual pause'));
        return response()->json(['message' => 'Paused']);
    }

    public function resume(int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $this->service->resume($mailbox);
        return response()->json(['message' => 'Resumed']);
    }
}
