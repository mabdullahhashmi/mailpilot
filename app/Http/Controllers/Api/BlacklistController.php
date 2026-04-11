<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlacklistController extends Controller
{
    /**
     * Check domains/IPs against major DNS blacklists.
     */
    public function check(Request $request): JsonResponse
    {
        $target = $request->input('target', '');

        $blacklists = [
            'zen.spamhaus.org',
            'b.barracudacentral.org',
            'bl.spamcop.net',
            'dnsbl.sorbs.net',
            'spam.dnsbl.sorbs.net',
            'cbl.abuseat.org',
            'dnsbl-1.uceprotect.net',
            'psbl.surriel.com',
        ];

        $ip = $this->resolveToIp($target);
        $results = [];
        $listedCount = 0;

        if ($ip) {
            $reversed = implode('.', array_reverse(explode('.', $ip)));

            foreach ($blacklists as $bl) {
                $lookup = "{$reversed}.{$bl}";
                $result = @dns_get_record($lookup, DNS_A);
                $listed = !empty($result);
                if ($listed) $listedCount++;

                $results[] = [
                    'blacklist' => $bl,
                    'listed' => $listed,
                    'lookup' => $lookup,
                ];
            }
        }

        return response()->json([
            'target' => $target,
            'resolved_ip' => $ip,
            'total_checked' => count($blacklists),
            'listed_count' => $listedCount,
            'clean' => $listedCount === 0,
            'results' => $results,
        ]);
    }

    /**
     * Check all sender domains against blacklists.
     */
    public function checkAll(): JsonResponse
    {
        $domains = \App\Models\Domain::pluck('domain_name')->unique()->toArray();
        $results = [];

        foreach ($domains as $domain) {
            $ip = $this->resolveToIp($domain);
            $listed = 0;
            $total = 0;

            if ($ip) {
                $reversed = implode('.', array_reverse(explode('.', $ip)));
                $blacklists = ['zen.spamhaus.org', 'b.barracudacentral.org', 'bl.spamcop.net', 'cbl.abuseat.org'];

                foreach ($blacklists as $bl) {
                    $total++;
                    $result = @dns_get_record("{$reversed}.{$bl}", DNS_A);
                    if (!empty($result)) $listed++;
                }
            }

            $results[] = [
                'domain' => $domain,
                'ip' => $ip,
                'checked' => $total,
                'listed' => $listed,
                'clean' => $listed === 0,
            ];
        }

        return response()->json($results);
    }

    private function resolveToIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $records = @dns_get_record($host, DNS_A);
        if ($records && !empty($records[0]['ip'])) {
            return $records[0]['ip'];
        }

        $ip = @gethostbyname($host);
        return ($ip !== $host) ? $ip : null;
    }
}
