<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeedMailboxController extends Controller
{
    public function __construct(private SeedService $service) {}

    public function index(): JsonResponse
    {
        $seeds = \App\Models\SeedMailbox::orderBy('created_at', 'desc')->get();
        return response()->json($seeds);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_address' => 'required|email|unique:seed_mailboxes,email_address',
            'smtp_host' => 'required|string',
            'smtp_port' => 'required|integer',
            'smtp_username' => 'required|string',
            'smtp_password' => 'required|string',
            'smtp_encryption' => 'required|in:tls,ssl,none',
            'imap_host' => 'nullable|string',
            'imap_port' => 'nullable|integer',
            'imap_username' => 'nullable|string',
            'imap_password' => 'nullable|string',
            'provider' => 'nullable|string',
            'daily_interaction_cap' => 'nullable|integer|min:1',
        ]);

        $seed = $this->service->create($validated);
        return response()->json($seed, 201);
    }

    public function show(int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);
        return response()->json($seed);
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
            'provider' => 'nullable|string',
            'daily_interaction_cap' => 'nullable|integer|min:1',
        ]);

        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $updated = $this->service->update($seed, $validated);
        return response()->json($updated);
    }

    public function destroy(int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $seed->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $this->service->pause($seed, $request->input('reason', 'Manual pause'));
        return response()->json(['message' => 'Paused']);
    }

    public function resume(int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $this->service->resume($seed);
        return response()->json(['message' => 'Resumed']);
    }
}
