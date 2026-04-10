<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DNSCheckService
{
    /**
     * Run all DNS checks for a domain.
     */
    public function checkAll(string $domain): array
    {
        return [
            'spf' => $this->checkSPF($domain),
            'dkim' => $this->checkDKIM($domain),
            'dmarc' => $this->checkDMARC($domain),
            'mx' => $this->checkMX($domain),
        ];
    }

    /**
     * Check SPF record.
     */
    public function checkSPF(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_TXT);

        if (!$records) {
            return ['status' => 'missing', 'record' => null, 'score' => 0];
        }

        foreach ($records as $record) {
            if (isset($record['txt']) && str_starts_with($record['txt'], 'v=spf1')) {
                $hasAll = str_contains($record['txt'], '-all') || str_contains($record['txt'], '~all');
                return [
                    'status' => $hasAll ? 'valid' : 'weak',
                    'record' => $record['txt'],
                    'score' => $hasAll ? 25 : 15,
                ];
            }
        }

        return ['status' => 'missing', 'record' => null, 'score' => 0];
    }

    /**
     * Check DKIM record.
     */
    public function checkDKIM(string $domain, string $selector = 'default'): array
    {
        $selectors = [$selector, 'google', 'k1', 's1', 's2', 'mail', 'dkim'];
        foreach ($selectors as $sel) {
            $dkimDomain = "{$sel}._domainkey.{$domain}";
            $records = @dns_get_record($dkimDomain, DNS_TXT);

            if ($records) {
                foreach ($records as $record) {
                    if (isset($record['txt']) && str_contains($record['txt'], 'v=DKIM1')) {
                        return [
                            'status' => 'valid',
                            'selector' => $sel,
                            'record' => $record['txt'],
                            'score' => 25,
                        ];
                    }
                }
            }
        }

        return ['status' => 'missing', 'selector' => null, 'record' => null, 'score' => 0];
    }

    /**
     * Check DMARC record.
     */
    public function checkDMARC(string $domain): array
    {
        $dmarcDomain = "_dmarc.{$domain}";
        $records = @dns_get_record($dmarcDomain, DNS_TXT);

        if (!$records) {
            return ['status' => 'missing', 'record' => null, 'policy' => null, 'score' => 0];
        }

        foreach ($records as $record) {
            if (isset($record['txt']) && str_starts_with($record['txt'], 'v=DMARC1')) {
                $policy = 'none';
                if (preg_match('/p=(reject|quarantine|none)/', $record['txt'], $matches)) {
                    $policy = $matches[1];
                }

                $score = match ($policy) {
                    'reject' => 25,
                    'quarantine' => 20,
                    'none' => 10,
                    default => 5,
                };

                return [
                    'status' => 'valid',
                    'record' => $record['txt'],
                    'policy' => $policy,
                    'score' => $score,
                ];
            }
        }

        return ['status' => 'missing', 'record' => null, 'policy' => null, 'score' => 0];
    }

    /**
     * Check MX records.
     */
    public function checkMX(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX);

        if (!$records || empty($records)) {
            return ['status' => 'missing', 'records' => [], 'provider' => null, 'score' => 0];
        }

        $mxHosts = collect($records)->sortBy('pri')->pluck('target')->toArray();
        $provider = $this->detectProvider($mxHosts);

        return [
            'status' => 'valid',
            'records' => $mxHosts,
            'provider' => $provider,
            'score' => 25,
        ];
    }

    /**
     * Calculate total DNS health score (0-100).
     */
    public function calculateScore(array $results): int
    {
        $score = 0;
        foreach ($results as $check) {
            $score += $check['score'] ?? 0;
        }
        return min(100, $score);
    }

    private function detectProvider(array $mxHosts): ?string
    {
        $firstHost = strtolower($mxHosts[0] ?? '');

        if (str_contains($firstHost, 'google') || str_contains($firstHost, 'gmail')) {
            return 'google';
        }
        if (str_contains($firstHost, 'outlook') || str_contains($firstHost, 'microsoft')) {
            return 'microsoft';
        }
        if (str_contains($firstHost, 'zoho')) {
            return 'zoho';
        }
        if (str_contains($firstHost, 'protonmail') || str_contains($firstHost, 'proton')) {
            return 'proton';
        }
        if (str_contains($firstHost, 'namecheap') || str_contains($firstHost, 'privateemail')) {
            return 'namecheap';
        }

        return null;
    }
}
