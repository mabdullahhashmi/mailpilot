<?php
/**
 * Cron Job: Process Email Warm-Up
 *
 * CORRECT FLOW:
 *   1. Only real SMTP senders (is_seed_account = 0) send warmup emails.
 *   2. Emails go TO seed accounts (is_seed_account = 1) — never from seed to sender.
 *   3. Daily schedule is pre-planned at start of each day (exact send-times stored in warmup_schedule).
 *   4. Cron sends only slots that are due (scheduled_at <= NOW(), status = 'pending').
 *   5. Seeds reply ONLY AFTER the email has been opened (opened_at IS NOT NULL) with 15-min delay.
 *
 * Run every 5 minutes: (star)/5 * * * * php process-warmup.php secret=YOUR_SECRET
 */

set_time_limit(300);
ignore_user_abort(true);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/spintax.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

define('WARMUP_DAYS', 30);
define('WARMUP_MAX_DAILY', 50);

// ── Auth ──────────────────────────────────────────────────────────────────────
$secret = php_sapi_name() === 'cli'
    ? (function() { global $argv; foreach ($argv as $a) { if (strpos($a,'secret=')===0) return substr($a,7); } return ''; })()
    : ($_GET['secret'] ?? '');

if ($secret !== CRON_SECRET) { echo "Invalid secret."; exit; }

$now = new DateTime();
echo "=== Warm-up Engine [{$now->format('Y-m-d H:i:s')}] ===\n";

// =============================================================================
// ROUTINE 1 — Daily reset & day-counter advance (SENDERS only, never seeds)
// =============================================================================
echo "\n[1] Daily reset check...\n";

$senderAccounts = dbFetchAll(
    "SELECT * FROM smtp_accounts WHERE warmup_status = 'active' AND is_seed_account = 0 AND is_active = 1"
);

foreach ($senderAccounts as $acc) {
    $lastReset = $acc['last_reset_date'] ? new DateTime($acc['last_reset_date']) : null;

    // Bootstrap: account newly activated with no day assigned yet
    if ((int)$acc['warmup_current_day'] === 0 || !$lastReset) {
        dbExecute(
            "UPDATE smtp_accounts SET sent_today = 0, last_reset_date = ?, warmup_current_day = 1, warmup_target_daily = 2 WHERE id = ?",
            [$now->format('Y-m-d'), $acc['id']]
        );
        echo "  Bootstrapped [{$acc['from_email']}] -> Day 1, target 2/day\n";
        continue;
    }

    // New calendar day -> advance day counter
    if ($lastReset->format('Y-m-d') !== $now->format('Y-m-d')) {
        $newDay = (int)$acc['warmup_current_day'] + 1;
        $target = max(2, (int) round(WARMUP_MAX_DAILY * pow(min($newDay, WARMUP_DAYS) / WARMUP_DAYS, 1.5)));

        if ($newDay > WARMUP_DAYS) {
            dbExecute(
                "UPDATE smtp_accounts SET sent_today = 0, last_reset_date = ?, warmup_current_day = ?, warmup_target_daily = ?, warmup_status = 'completed', warmup_completed_at = NOW() WHERE id = ?",
                [$now->format('Y-m-d'), WARMUP_DAYS, WARMUP_MAX_DAILY, $acc['id']]
            );
            echo "  COMPLETED [{$acc['from_email']}]\n";
        } else {
            dbExecute(
                "UPDATE smtp_accounts SET sent_today = 0, last_reset_date = ?, warmup_current_day = ?, warmup_target_daily = ? WHERE id = ?",
                [$now->format('Y-m-d'), $newDay, $target, $acc['id']]
            );
            echo "  Advanced [{$acc['from_email']}] -> Day {$newDay}/30, target {$target}/day\n";
        }
    }
}

// =============================================================================
// ROUTINE 2 — Generate today's schedule (if not already done for each sender)
// =============================================================================
echo "\n[2] Schedule generation for {$now->format('Y-m-d')}...\n";

// Re-fetch after resets
$senderAccounts = dbFetchAll(
    "SELECT * FROM smtp_accounts WHERE warmup_status = 'active' AND is_seed_account = 0 AND is_active = 1"
);

// Seeds are the ONLY valid receivers
$seedAccounts = dbFetchAll(
    "SELECT * FROM smtp_accounts WHERE is_seed_account = 1 AND is_active = 1"
);

if (empty($seedAccounts)) {
    echo "  WARNING: No seed accounts found. Add Gmail/Outlook seed accounts to start warmup.\n";
} else {
    foreach ($senderAccounts as $sender) {
        $dailyTarget = (int)$sender['warmup_target_daily'];
        if ($dailyTarget <= 0) continue;

        // Already scheduled today?
        $existingCount = (int) dbFetchValue(
            "SELECT COUNT(*) FROM warmup_schedule WHERE sender_account_id = ? AND DATE(scheduled_at) = ?",
            [$sender['id'], $now->format('Y-m-d')]
        );

        if ($existingCount > 0) {
            echo "  [{$sender['from_email']}] Already scheduled ({$existingCount} slots)\n";
            continue;
        }

        // Filter out seeds on same domain as sender
        $senderDomain = explode('@', $sender['from_email'])[1] ?? '';
        $validSeeds   = array_values(array_filter($seedAccounts, function($s) use ($senderDomain) {
            return (explode('@', $s['from_email'])[1] ?? '') !== $senderDomain;
        }));

        if (empty($validSeeds)) {
            echo "  [{$sender['from_email']}] No valid seeds (all same domain). Skipping.\n";
            continue;
        }

        // Spread sends evenly across 08:00-20:00 with jitter
        $sendWindowMinutes = 12 * 60;
        $startMinutes      = 8 * 60;
        $slotInterval      = (int) floor($sendWindowMinutes / $dailyTarget);
        $today             = $now->format('Y-m-d');
        $slotsCreated      = 0;

        for ($i = 0; $i < $dailyTarget; $i++) {
            $baseOffset   = $startMinutes + $i * $slotInterval;
            $jitter       = mt_rand(-8, 8);
            $totalMinutes = max(0, min(1439, $baseOffset + $jitter));
            $sendH = str_pad((int)floor($totalMinutes / 60), 2, '0', STR_PAD_LEFT);
            $sendM = str_pad($totalMinutes % 60, 2, '0', STR_PAD_LEFT);
            $scheduledAt = "{$today} {$sendH}:{$sendM}:00";

            // Rotate seeds so load is spread equally
            $seed = $validSeeds[$i % count($validSeeds)];

            dbInsert(
                "INSERT INTO warmup_schedule (sender_account_id, receiver_account_id, scheduled_at, status) VALUES (?, ?, ?, 'pending')",
                [$sender['id'], $seed['id'], $scheduledAt]
            );
            $slotsCreated++;
        }

        echo "  [{$sender['from_email']}] Created {$slotsCreated} slots (target {$dailyTarget}/day)\n";
    }
}

// =============================================================================
// ROUTINE 3 — Execute due scheduled slots (sender -> seed only)
// =============================================================================
echo "\n[3] Sending due emails...\n";

$dueSlots = dbFetchAll(
    "SELECT ws.*,
            s.smtp_host, s.smtp_port, s.smtp_encryption, s.smtp_username, s.smtp_password,
            s.from_email AS sender_email, s.from_name AS sender_name, s.label AS sender_label,
            r.from_email AS receiver_email, r.from_name AS receiver_name, r.label AS receiver_label
     FROM warmup_schedule ws
     JOIN smtp_accounts s ON ws.sender_account_id  = s.id AND s.is_seed_account = 0
     JOIN smtp_accounts r ON ws.receiver_account_id = r.id AND r.is_seed_account = 1
     WHERE ws.status = 'pending'
       AND ws.scheduled_at <= NOW()
       AND DATE(ws.scheduled_at) = CURDATE()
       AND s.warmup_status = 'active'
     ORDER BY ws.scheduled_at ASC
     LIMIT 5"
);

if (empty($dueSlots)) {
    echo "  No due slots right now.\n";
}

foreach ($dueSlots as $slot) {
    $content       = SpintaxEngine::generateWarmupEmail();
    $openToken     = bin2hex(random_bytes(16));
    $appUrl        = rtrim(APP_URL, '/');
    $trackingPixel = '<img src="' . $appUrl . '/track/warmup-open.php?t=' . $openToken . '" width="1" height="1" style="display:none;" alt="" />';
    $bodyWithPixel = $content['body'] . $trackingPixel;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $slot['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $slot['smtp_username'];
        $mail->Password   = decryptString($slot['smtp_password']);
        $mail->SMTPSecure = $slot['smtp_encryption'] ?: PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $slot['smtp_port'];
        $mail->Timeout    = 10;

        $mail->setFrom($slot['sender_email'], $slot['sender_name']);
        $mail->addAddress($slot['receiver_email'], $slot['receiver_name']);

        $mail->isHTML(true);
        $mail->Subject = $content['subject'];
        $mail->Body    = $bodyWithPixel;
        $mail->AltBody = strip_tags($content['body']);

        $uniqueId  = md5(uniqid((string)time(), true));
        $domain    = explode('@', $slot['sender_email'])[1] ?? 'example.com';
        $messageId = "<{$uniqueId}@{$domain}>";
        $mail->MessageID = $messageId;
        $mail->addCustomHeader('X-MailPilot-Warmup', '1');

        $mail->send();

        $logId = dbInsert(
            "INSERT INTO warmup_logs (sender_account_id, receiver_account_id, message_id, thread_id,
             open_tracking_token, subject, sender_email, sender_label, receiver_email, receiver_label,
             email_body, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent')",
            [
                $slot['sender_account_id'], $slot['receiver_account_id'],
                $messageId, $uniqueId, $openToken,
                $content['subject'],
                $slot['sender_email'], $slot['sender_label'] ?: $slot['sender_name'],
                $slot['receiver_email'], $slot['receiver_label'] ?: $slot['receiver_name'],
                $content['body']
            ]
        );

        dbExecute("UPDATE warmup_schedule SET status = 'sent', warmup_log_id = ? WHERE id = ?", [$logId, $slot['id']]);
        dbExecute("UPDATE smtp_accounts SET sent_today = sent_today + 1 WHERE id = ?", [$slot['sender_account_id']]);

        echo "  Sent: {$slot['sender_email']} -> {$slot['receiver_email']}\n";

    } catch (Exception $e) {
        dbExecute("UPDATE warmup_schedule SET status = 'failed' WHERE id = ?", [$slot['id']]);
        echo "  Failed [{$slot['sender_email']}]: {$mail->ErrorInfo}\n";
    }
}

// =============================================================================
// ROUTINE 4 — IMAP: check seed inboxes, reply ONLY after email is opened
// =============================================================================
if (!function_exists('imap_open')) {
    echo "\n[4] IMAP not available. Skipping.\n";
    echo "\n=== Warm-up Engine Complete ===\n";
    exit;
}

echo "\n[4] IMAP checks on seed accounts...\n";

$seedsWithImap = dbFetchAll(
    "SELECT * FROM smtp_accounts WHERE is_seed_account = 1 AND is_active = 1 AND imap_host IS NOT NULL AND imap_host != ''"
);

$readsThisRun  = 0;
$maxReadsPerRun = 4;

foreach ($seedsWithImap as $acc) {
    if ($readsThisRun >= $maxReadsPerRun) break;
    if ((mt_rand() / mt_getrandmax()) > 0.7) continue; // 70% chance to check each run

    $readsThisRun++;
    echo "  Inbox: {$acc['from_email']}...\n";

    $host         = $acc['imap_host'];
    $port         = $acc['imap_port'] ?: 993;
    $enc          = $acc['imap_encryption'] === 'ssl' ? '/ssl' : ($acc['imap_encryption'] === 'tls' ? '/tls' : '');
    $user         = $acc['imap_username'] ?: $acc['smtp_username'];
    $pass         = $acc['imap_password'] ? decryptString($acc['imap_password']) : decryptString($acc['smtp_password']);
    $serverString = "{{$host}:{$port}/imap{$enc}/novalidate-cert}";

    try {
        $inbox = @imap_open($serverString . 'INBOX', $user, $pass, OP_SILENT, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

        if ($inbox) {
            $emails = imap_search($inbox, 'UNSEEN');
            if ($emails) {
                foreach ($emails as $emailNo) {
                    $header     = imap_fetch_overview($inbox, $emailNo, 0);
                    $fullHeader = imap_fetchheader($inbox, $emailNo);

                    if (!preg_match('/Message-ID:\s*(<[^>]+>)/i', $fullHeader, $matches)) continue;
                    $msgId = $matches[1];

                    $log = dbFetchOne(
                        "SELECT * FROM warmup_logs WHERE message_id = ? AND receiver_account_id = ?",
                        [$msgId, $acc['id']]
                    );

                    if (!$log) continue;

                    echo "    Found warmup email: {$msgId}\n";

                    // Mark delivered
                    if (empty($log['delivered_at'])) {
                        dbExecute(
                            "UPDATE warmup_logs SET delivered_at = NOW(), status = CASE WHEN status = 'sent' THEN 'delivered' ELSE status END WHERE id = ?",
                            [$log['id']]
                        );
                    }

                    imap_setflag_full($inbox, $emailNo, "\\Seen");

                    // REPLY: only after opened_at is set AND 15+ minutes have passed since open
                    $alreadyReplied = !empty($log['replied_at']);
                    $wasOpened      = !empty($log['opened_at']);

                    if (!$alreadyReplied && $wasOpened) {
                        $minutesSinceOpen = (time() - strtotime($log['opened_at'])) / 60;
                        if ($minutesSinceOpen >= 15) {
                            if (mt_rand(1, 100) <= 40 && !empty($acc['smtp_host'])) {
                                $senderAuth = dbFetchOne(
                                    "SELECT * FROM smtp_accounts WHERE id = ? AND is_seed_account = 0",
                                    [$log['sender_account_id']]
                                );
                                if ($senderAuth) {
                                    $replyMail = new PHPMailer(true);
                                    try {
                                        $replyMail->isSMTP();
                                        $replyMail->Host       = $acc['smtp_host'];
                                        $replyMail->SMTPAuth   = true;
                                        $replyMail->Username   = $acc['smtp_username'];
                                        $replyMail->Password   = decryptString($acc['smtp_password']);
                                        $replyMail->SMTPSecure = $acc['smtp_encryption'] ?: PHPMailer::ENCRYPTION_STARTTLS;
                                        $replyMail->Port       = $acc['smtp_port'];
                                        $replyMail->Timeout    = 10;

                                        $replyMail->setFrom($acc['from_email'], $acc['from_name']);
                                        $replyMail->addAddress($senderAuth['from_email'], $senderAuth['from_name']);
                                        $replyMail->isHTML(true);
                                        $replyMail->Subject = "Re: " . str_replace("Re: ", "", $header[0]->subject ?? '');
                                        $replyMail->Body    = "<p>" . SpintaxEngine::generateReply() . "</p>";
                                        $replyMail->addCustomHeader('In-Reply-To', $msgId);
                                        $replyMail->addCustomHeader('References', $msgId);

                                        $replyMail->send();
                                        dbExecute(
                                            "UPDATE warmup_logs SET replied_at = NOW(), status = 'replied' WHERE id = ?",
                                            [$log['id']]
                                        );
                                        echo "    Replied to {$msgId} ({$minutesSinceOpen} min after open)\n";
                                    } catch (Exception $e) {
                                        echo "    Reply failed: {$replyMail->ErrorInfo}\n";
                                    }
                                }
                            }
                        } else {
                            echo "    Opened but waiting for reply delay ({$minutesSinceOpen}/15 min)\n";
                        }
                    } elseif (!$alreadyReplied && !$wasOpened) {
                        echo "    Not opened yet — reply withheld\n";
                    }
                }
            }
            imap_close($inbox);
        } else {
            echo "    Connect failed: " . imap_last_error() . "\n";
        }

        // Spam folder rescue
        $spamFolders = ['Spam', 'Junk', '[Gmail]/Spam', 'Junk E-mail'];
        foreach ($spamFolders as $folder) {
            $spamBox = @imap_open($serverString . $folder, $user, $pass, OP_SILENT, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            if (!$spamBox) continue;
            $since      = date('d-M-Y', strtotime('-14 days'));
            $spamEmails = imap_search($spamBox, 'SINCE "' . $since . '"');
            if ($spamEmails) {
                foreach ($spamEmails as $emailNo) {
                    $fullHeader = imap_fetchheader($spamBox, $emailNo);
                    if (!preg_match('/Message-ID:\s*(<[^>]+>)/i', $fullHeader, $matches)) continue;
                    $msgId = $matches[1];
                    $log = dbFetchOne(
                        "SELECT * FROM warmup_logs WHERE message_id = ? AND receiver_account_id = ? AND spam_saved = 0",
                        [$msgId, $acc['id']]
                    );
                    if ($log) {
                        echo "    Rescuing from spam: {$msgId}\n";
                        imap_mail_move($spamBox, $emailNo, 'INBOX');
                        imap_setflag_full($spamBox, $emailNo, "\\Seen");
                        dbExecute("UPDATE warmup_logs SET spam_saved = 1, status = 'spam' WHERE id = ?", [$log['id']]);
                    }
                }
                imap_expunge($spamBox);
            }
            imap_close($spamBox);
        }

    } catch (Throwable $t) {
        echo "    Exception: {$t->getMessage()}\n";
    }
}

echo "\n=== Warm-up Engine Complete ===\n";
