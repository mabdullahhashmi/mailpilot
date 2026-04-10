<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\DNSCheckService;
use Illuminate\Http\JsonResponse;

class DnsHealthController extends Controller
{
    public function __construct(private DNSCheckService $dnsService) {}

    /**
     * Get DNS health overview for all domains.
     */
    public function index(): JsonResponse
    {
        $domains = Domain::with('senderMailboxes')->orderBy('domain_name')->get();

        $data = $domains->map(function ($domain) {
            return [
                'id' => $domain->id,
                'domain_name' => $domain->domain_name,
                'spf_status' => $domain->spf_status ?? 'unknown',
                'dkim_status' => $domain->dkim_status ?? 'unknown',
                'dmarc_status' => $domain->dmarc_status ?? 'unknown',
                'mx_status' => $domain->mx_status ?? 'unknown',
                'health_score' => $domain->domain_health_score ?? 0,
                'dns_last_checked_at' => $domain->dns_last_checked_at,
                'sender_count' => $domain->senderMailboxes->count(),
                'is_healthy' => $domain->isDnsHealthy(),
            ];
        });

        return response()->json($data);
    }

    /**
     * Run DNS checks for a specific domain and return results.
     */
    public function check(int $id): JsonResponse
    {
        $domain = Domain::findOrFail($id);
        $results = $this->dnsService->checkAll($domain->domain_name);
        $score = $this->dnsService->calculateScore($results);

        $domain->update([
            'spf_status' => $results['spf']['status'] === 'valid' ? 'pass' : ($results['spf']['status'] === 'weak' ? 'weak' : 'fail'),
            'dkim_status' => $results['dkim']['status'] === 'valid' ? 'pass' : 'fail',
            'dmarc_status' => $results['dmarc']['status'] === 'valid' ? 'pass' : 'fail',
            'mx_status' => $results['mx']['status'] === 'valid' ? 'pass' : 'fail',
            'domain_health_score' => $score,
            'dns_last_checked_at' => now(),
        ]);

        return response()->json([
            'domain' => $domain->domain_name,
            'results' => $results,
            'score' => $score,
            'checked_at' => now()->toISOString(),
        ]);
    }

    /**
     * Check all domains at once.
     */
    public function checkAll(): JsonResponse
    {
        $domains = Domain::all();
        $results = [];

        foreach ($domains as $domain) {
            $dnsResults = $this->dnsService->checkAll($domain->domain_name);
            $score = $this->dnsService->calculateScore($dnsResults);

            $domain->update([
                'spf_status' => $dnsResults['spf']['status'] === 'valid' ? 'pass' : ($dnsResults['spf']['status'] === 'weak' ? 'weak' : 'fail'),
                'dkim_status' => $dnsResults['dkim']['status'] === 'valid' ? 'pass' : 'fail',
                'dmarc_status' => $dnsResults['dmarc']['status'] === 'valid' ? 'pass' : 'fail',
                'mx_status' => $dnsResults['mx']['status'] === 'valid' ? 'pass' : 'fail',
                'domain_health_score' => $score,
                'dns_last_checked_at' => now(),
            ]);

            $results[] = [
                'domain' => $domain->domain_name,
                'score' => $score,
                'spf' => $dnsResults['spf']['status'],
                'dkim' => $dnsResults['dkim']['status'],
                'dmarc' => $dnsResults['dmarc']['status'],
                'mx' => $dnsResults['mx']['status'],
            ];
        }

        return response()->json($results);
    }
}
