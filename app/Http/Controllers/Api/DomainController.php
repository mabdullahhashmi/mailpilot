<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DomainService;
use App\Services\DNSCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function __construct(
        private DomainService $domainService,
        private DNSCheckService $dnsService,
    ) {}

    public function index(): JsonResponse
    {
        $domains = \App\Models\Domain::with('senderMailboxes')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($domains);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain_name' => 'required|string|unique:domains,domain_name',
            'daily_sending_cap' => 'nullable|integer|min:1',
        ]);

        $domain = $this->domainService->create($validated);
        return response()->json($domain, 201);
    }

    public function show(int $id): JsonResponse
    {
        $domain = \App\Models\Domain::with(['senderMailboxes', 'healthLogs' => fn($q) => $q->latest()->take(30)])
            ->findOrFail($id);

        return response()->json($domain);
    }

    public function checkDns(int $id): JsonResponse
    {
        $domain = \App\Models\Domain::findOrFail($id);
        $results = $this->domainService->runDnsCheck($domain);
        return response()->json($results);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->domainService->delete($id);
        return response()->json(['message' => 'Deleted']);
    }
}
