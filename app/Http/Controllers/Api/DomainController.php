<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\DomainService;
use App\Services\DNSCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
    public function __construct(
        private DomainService $domainService,
        private DNSCheckService $dnsService,
    ) {}

    public function index(): JsonResponse
    {
        $domains = Domain::with('senderMailboxes')
            ->when($this->tenantUserId(), fn ($query, $ownerId) => $query->where('user_id', $ownerId))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($domains);
    }

    public function store(Request $request): JsonResponse
    {
        $ownerId = $this->tenantUserId() ?? auth()->id();

        $validated = $request->validate([
            'domain_name' => [
                'required',
                'string',
                Rule::unique('domains', 'domain_name')->where(fn ($query) => $query->where('user_id', $ownerId)),
            ],
            'daily_sending_cap' => 'nullable|integer|min:1',
            'daily_domain_cap' => 'nullable|integer|min:1',
        ]);

        if (isset($validated['daily_sending_cap']) && !isset($validated['daily_domain_cap'])) {
            $validated['daily_domain_cap'] = $validated['daily_sending_cap'];
        }

        $domain = $this->domainService->create($validated, $ownerId);
        return response()->json($domain, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $domain = $this->ownedDomainQuery()->findOrFail($id);

        $validated = $request->validate([
            'domain_name' => [
                'sometimes',
                'string',
                Rule::unique('domains', 'domain_name')
                    ->ignore($domain->id)
                    ->where(fn ($query) => $query->where('user_id', $domain->user_id)),
            ],
            'daily_sending_cap' => 'nullable|integer|min:1',
            'daily_domain_cap' => 'nullable|integer|min:1',
        ]);

        if (isset($validated['daily_sending_cap']) && !isset($validated['daily_domain_cap'])) {
            $validated['daily_domain_cap'] = $validated['daily_sending_cap'];
        }

        $updated = $this->domainService->update($domain, $validated);

        return response()->json($updated);
    }

    public function show(int $id): JsonResponse
    {
        $domain = $this->ownedDomainQuery()->with(['senderMailboxes', 'healthLogs' => fn($q) => $q->latest()->take(30)])
            ->findOrFail($id);

        return response()->json($domain);
    }

    public function checkDns(int $id): JsonResponse
    {
        $domain = $this->ownedDomainQuery()->findOrFail($id);
        $results = $this->domainService->checkDns($domain);
        return response()->json($results);
    }

    public function destroy(int $id): JsonResponse
    {
        $domain = $this->ownedDomainQuery()->findOrFail($id);
        $domain->delete();
        return response()->json(['message' => 'Deleted']);
    }

    private function tenantUserId(): ?int
    {
        $user = auth()->user();
        return $user && $user->isAdmin() ? null : auth()->id();
    }

    private function ownedDomainQuery()
    {
        return Domain::query()->when($this->tenantUserId(), fn ($query, $ownerId) => $query->where('user_id', $ownerId));
    }
}
