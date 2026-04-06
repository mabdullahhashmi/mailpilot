<?php
/**
 * API: Check Domain Reputation (SPF/DKIM/DMARC/Blacklists/MX)
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
}

validateCSRF($input['csrf_token'] ?? '');

$smtpAccountId = (int)($input['smtp_account_id'] ?? 0);

if (!$smtpAccountId) {
    jsonResponse(['success' => false, 'message' => 'No SMTP account specified.'], 400);
}

$account = dbFetchOne("SELECT * FROM smtp_accounts WHERE id = ?", [$smtpAccountId]);
if (!$account) {
    jsonResponse(['success' => false, 'message' => 'Account not found.'], 404);
}

$domain = strtolower(trim(explode('@', $account['from_email'])[1] ?? ''));
if (!$domain) {
    jsonResponse(['success' => false, 'message' => 'Could not extract domain from email.'], 400);
}

// ---------- 1. MX Check ----------
$mxValid = false;
$mxRecords = [];
if (checkdnsrr($domain, 'MX')) {
    $mxValid = true;
    getmxrr($domain, $mxRecords);
}

// ---------- 2. SPF Check ----------
$spfStatus = 'missing';
$spfRecord = '';
$txtRecords = @dns_get_record($domain, DNS_TXT);
if ($txtRecords) {
    foreach ($txtRecords as $txt) {
        $entry = $txt['txt'] ?? '';
        if (stripos($entry, 'v=spf1') === 0) {
            $spfRecord = $entry;
            // Basic validation
            if (strpos($entry, '-all') !== false || strpos($entry, '~all') !== false) {
                $spfStatus = 'pass';
            } else {
                $spfStatus = 'fail'; // +all or missing enforcement
            }
            break;
        }
    }
}

// ---------- 3. DKIM Check ----------
// Common DKIM selectors to probe
$dkimStatus = 'missing';
$dkimSelector = '';
$dkimSelectors = ['default', 'google', 'selector1', 'selector2', 'mail', 'dkim', 'k1', 's1', 's2', 'hostinger'];
foreach ($dkimSelectors as $sel) {
    $dkimHost = "{$sel}._domainkey.{$domain}";
    $dkimRecords = @dns_get_record($dkimHost, DNS_TXT);
    if ($dkimRecords && !empty($dkimRecords[0]['txt'])) {
        $dkimStatus = 'pass';
        $dkimSelector = $sel;
        break;
    }
    // Also try CNAME
    $dkimCname = @dns_get_record($dkimHost, DNS_CNAME);
    if ($dkimCname && !empty($dkimCname[0]['target'])) {
        $dkimStatus = 'pass';
        $dkimSelector = $sel;
        break;
    }
}

// ---------- 4. DMARC Check ----------
$dmarcStatus = 'missing';
$dmarcRecord = '';
$dmarcHost = "_dmarc.{$domain}";
$dmarcRecords = @dns_get_record($dmarcHost, DNS_TXT);
if ($dmarcRecords) {
    foreach ($dmarcRecords as $rec) {
        $entry = $rec['txt'] ?? '';
        if (stripos($entry, 'v=DMARC1') === 0) {
            $dmarcRecord = $entry;
            if (preg_match('/p=(reject|quarantine)/i', $entry)) {
                $dmarcStatus = 'pass';
            } else {
                $dmarcStatus = 'fail'; // p=none is weak
            }
            break;
        }
    }
}

// ---------- 5. Blacklist Check ----------
$blacklists = [
    'zen.spamhaus.org',
    'bl.spamcop.net',
    'b.barracudacentral.org',
    'dnsbl.sorbs.net',
    'spam.dnsbl.sorbs.net',
    'cbl.abuseat.org',
    'dnsbl-1.uceprotect.net',
    'psbl.surriel.com',
];

$blacklistCount = 0;
$blacklistsFound = [];

// Resolve domain to IP for blacklist checking
$domainIp = @gethostbyname($domain);
$reversedIp = '';
if ($domainIp && $domainIp !== $domain) {
    $parts = explode('.', $domainIp);
    $reversedIp = implode('.', array_reverse($parts));
}

if ($reversedIp) {
    foreach ($blacklists as $bl) {
        $lookup = "{$reversedIp}.{$bl}";
        $result = @dns_get_record($lookup, DNS_A);
        if ($result && !empty($result)) {
            $blacklistCount++;
            $blacklistsFound[] = $bl;
        }
    }
}

// ---------- 6. Overall Score ----------
$score = 0;
if ($mxValid) $score += 15;
if ($spfStatus === 'pass') $score += 25;
if ($dkimStatus === 'pass') $score += 25;
if ($dmarcStatus === 'pass') $score += 20;
if ($blacklistCount === 0) $score += 15;

// Save to DB
dbInsert(
    "INSERT INTO reputation_checks (domain, smtp_account_id, spf_status, dkim_status, dmarc_status, blacklist_count, blacklists_found, mx_valid, overall_score)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [$domain, $smtpAccountId, $spfStatus, $dkimStatus, $dmarcStatus, $blacklistCount, implode(',', $blacklistsFound), $mxValid ? 1 : 0, $score]
);

jsonResponse([
    'success' => true,
    'domain' => $domain,
    'mx_valid' => $mxValid,
    'mx_records' => $mxRecords,
    'spf' => ['status' => $spfStatus, 'record' => $spfRecord],
    'dkim' => ['status' => $dkimStatus, 'selector' => $dkimSelector],
    'dmarc' => ['status' => $dmarcStatus, 'record' => $dmarcRecord],
    'blacklists' => ['count' => $blacklistCount, 'found' => $blacklistsFound, 'total_checked' => count($blacklists)],
    'overall_score' => $score,
]);
