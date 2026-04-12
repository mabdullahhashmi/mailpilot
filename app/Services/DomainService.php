<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainHealthLog;

class DomainService
{
    public function create(array $data): Domain
    {
        return Domain::create($data);
    }

    public function update(Domain $domain, array $data): Domain
    {
        $domain->update($data);
        return $domain->refresh();
    }

    public function checkDns(Domain $domain): array
    {
        $dnsService = app(DNSCheckService::class);
        $results = $dnsService->checkAll($domain->domain_name);
        $score = $dnsService->calculateScore($results);

        $spfStatus = $results['spf']['status'] ?? 'missing';
        $dkimStatus = $results['dkim']['status'] ?? 'missing';
        $dmarcStatus = $results['dmarc']['status'] ?? 'missing';
        $mxStatus = $results['mx']['status'] ?? 'missing';

        $domain->update([
            'spf_status' => $spfStatus === 'valid' ? 'pass' : ($spfStatus === 'weak' ? 'weak' : 'fail'),
            'dkim_status' => $dkimStatus === 'valid' ? 'pass' : 'fail',
            'dmarc_status' => $dmarcStatus === 'valid' ? 'pass' : 'fail',
            'mx_status' => $mxStatus === 'valid' ? 'pass' : 'fail',
            'domain_health_score' => $score,
            'dns_last_checked_at' => now(),
        ]);

        return [
            'domain_id' => $domain->id,
            'domain' => $domain->domain_name,
            'results' => $results,
            'score' => $score,
            'checked_at' => now()->toISOString(),
        ];
    }

    public function recalculateHealthScore(Domain $domain): void
    {
        $score = 0;
        if ($domain->spf_status === 'pass') $score += 25;
        if ($domain->dkim_status === 'pass') $score += 25;
        if ($domain->dmarc_status === 'pass') $score += 25;
        if ($domain->mx_status === 'pass') $score += 25;

        $domain->update(['domain_health_score' => $score]);
    }

    public function logDailyHealth(Domain $domain): void
    {
        DomainHealthLog::updateOrCreate(
            ['domain_id' => $domain->id, 'log_date' => today()],
            [
                'active_sender_count' => $domain->activeSenderCount(),
                'daily_action_count' => $this->getTodayActionCount($domain),
                'dns_health' => [
                    'spf' => $domain->spf_status,
                    'dkim' => $domain->dkim_status,
                    'dmarc' => $domain->dmarc_status,
                    'mx' => $domain->mx_status,
                ],
                'readiness_score' => $domain->readiness_score,
            ]
        );
    }

    public function getTodayActionCount(Domain $domain): int
    {
        return $domain->threads()
            ->whereDate('created_at', today())
            ->count();
    }

    public function canAcceptMoreActions(Domain $domain): bool
    {
        return $this->getTodayActionCount($domain) < $domain->daily_domain_cap;
    }
}
