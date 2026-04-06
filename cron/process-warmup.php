<?php
/**
 * Cron Job: Process Email Warm-Up
 * 
 * Should be run every 5 minutes: star-slash-5 pattern in cron (every 5 minutes)
 * php process-warmup.php secret=YOUR_CRON_SECRET
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

// Warmup ramp-up constants (30-day programme, max 50 emails/day)
define('WARMUP_DAYS', 30);
define('WARMUP_MAX_DAILY', 50);

// 1. Verify Secret
$secret = '';
if (php_sapi_name() === 'cli') {
    foreach ($argv as $arg) {
        if (strpos($arg, 'secret=') === 0) {
            $secret = substr($arg, 7);
        }
    }
} else {
    $secret = $_GET['secret'] ?? '';
}

if ($secret !== CRON_SECRET) {
    if (php_sapi_name() !== 'cli') {
        echo "Invalid secret.";
    }
    exit;
}

echo "Starting Warm-up Engine...\n";
$now = new DateTime();

// ------------- ROUTINE 1: Daily Reset -------------
echo "Checking daily resets...\n";
$accounts = dbFetchAll("SELECT * FROM smtp_accounts WHERE warmup_status = 'active' OR is_seed_account = 1");
foreach ($accounts as $acc) {
    $lastReset = $acc['last_reset_date'] ? new DateTime($acc['last_reset_date']) : null;
    if (!$lastReset || $lastReset->format('Y-m-d') !== $now->format('Y-m-d')) {
        // New day — advance the warmup day counter
        $newDay = $acc['warmup_current_day'] + 1;

        // 30-day exponential ramp-up curve: Day 1 → 2/day, Day 30 → 50/day
        $target = max(2, (int) round(WARMUP_MAX_DAILY * pow(min($newDay, WARMUP_DAYS) / WARMUP_DAYS, 1.5)));

        if ($newDay > WARMUP_DAYS) {
            // Warmup programme complete — mark account as completed
            dbExecute(
                "UPDATE smtp_accounts SET sent_today = 0, last_reset_date = ?, warmup_current_day = ?, warmup_target_daily = ?, warmup_status = 'completed', warmup_completed_at = NOW() WHERE id = ?",
                [$now->format('Y-m-d'), WARMUP_DAYS, WARMUP_MAX_DAILY, $acc['id']]
            );
            echo "Warmup COMPLETED for account {$acc['id']} ({$acc['from_email']}).\n";
        } else {
            dbExecute(
                "UPDATE smtp_accounts SET sent_today = 0, last_reset_date = ?, warmup_current_day = ?, warmup_target_daily = ? WHERE id = ?",
                [$now->format('Y-m-d'), $newDay, $target, $acc['id']]
            );
            echo "Reset account {$acc['id']}. Day: {$newDay}/" . WARMUP_DAYS . ", Target: {$target}/day\n";
        }
    }
}

// Re-fetch state
$accounts = dbFetchAll("SELECT * FROM smtp_accounts WHERE warmup_status = 'active'");

// ------------- ROUTINE 2: Send Warm-up Emails -------------
echo "Processing Outbound Warm-up...\n";
// Shuffle for fair distribution across all accounts each cron tick
shuffle($accounts);
// Maximum sends per cron run to avoid overloading
$sendsThisRun = 0;
$maxSendsPerRun = 5; 

foreach ($accounts as $acc) {
    if ($sendsThisRun >= $maxSendsPerRun) break;
    if ($acc['sent_today'] >= $acc['warmup_target_daily']) continue;
    
    // We send emails periodically throughout the day. 
    // Roughly randomly chance of sending on a given 5-min tick
    // 288 ticks per day (5 mins). Prob = target / 288
    $probability = $acc['warmup_target_daily'] / 200; // slightly boosted so it actually finishes early
    
    if ((mt_rand() / mt_getrandmax()) > $probability) {
        continue; // Skip this tick, keeps sending natural
    }
    
    // Find a receiver account. Must be active warmup or a seed, AND not the same domain
    // Extract domain of sender
    $senderDomain = explode('@', $acc['from_email'])[1] ?? '';
    
    $receivers = dbFetchAll(
        "SELECT * FROM smtp_accounts 
         WHERE (warmup_status = 'active' OR is_seed_account = 1)
         AND is_active = 1
         AND from_email NOT LIKE ?",
        ["%@{$senderDomain}"]
    );
    
    if (empty($receivers)) {
        echo "No valid receivers found for {$acc['from_email']}\n";
        continue; // No one to send to
    }
    
    $receiver = $receivers[array_rand($receivers)];
    
    // Generate spinning content
    $content = SpintaxEngine::generateWarmupEmail();
    
    // Generate open-tracking token
    $openToken = bin2hex(random_bytes(16));
    $appUrl = APP_URL ?: 'https://royalblue-tapir-258681.hostingersite.com';
    $trackingPixel = '<img src="' . $appUrl . '/track/warmup-open.php?t=' . $openToken . '" width="1" height="1" style="display:none;" alt="" />';
    $bodyWithTracking = $content['body'] . $trackingPixel;
    
    // Send email
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $acc['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $acc['smtp_username'];
        $mail->Password   = decryptString($acc['smtp_password']);
        $mail->SMTPSecure = $acc['smtp_encryption'] ?: PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $acc['smtp_port'];
        
        // Timeout handling
        $mail->Timeout = 10;
        
        $mail->setFrom($acc['from_email'], $acc['from_name']);
        $mail->addAddress($receiver['from_email'], $receiver['from_name']);
        
        $mail->isHTML(true);
        $mail->Subject = $content['subject'];
        $mail->Body    = $bodyWithTracking;
        $mail->AltBody = strip_tags($content['body']);
        
        // Generate custom Message-ID to easily find it later
        $uniqueId = md5(uniqid(time()));
        $domain = explode('@', $acc['from_email'])[1];
        $messageId = "<{$uniqueId}@{$domain}>";
        $mail->MessageID = $messageId;
        
        // Custom header to bypass spam filters inside the private network slightly? 
        // No, we want real filters. Just track it.
        $mail->addCustomHeader('X-MailPilot-Warmup', '1');
        
        $mail->send();
        
        // Log it with full tracking data
        dbInsert(
            "INSERT INTO warmup_logs (sender_account_id, receiver_account_id, message_id, thread_id, open_tracking_token, subject, sender_email, sender_label, receiver_email, receiver_label, email_body, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent')",
            [$acc['id'], $receiver['id'], $messageId, $uniqueId, $openToken, $content['subject'], $acc['from_email'], $acc['label'] ?? $acc['from_name'], $receiver['from_email'], $receiver['label'] ?? $receiver['from_name'], $content['body']]
        );
        
        // Update stats
        dbExecute("UPDATE smtp_accounts SET sent_today = sent_today + 1 WHERE id = ?", [$acc['id']]);
        
        echo "Sent warm-up from {$acc['from_email']} to {$receiver['from_email']}\n";
        $sendsThisRun++;
        
    } catch (Exception $e) {
        echo "Failed to send warm-up from {$acc['from_email']}: {$mail->ErrorInfo}\n";
    }
}


// ------------- ROUTINE 3: IMAP Reading & Replying -------------
if (!function_exists('imap_open')) {
    echo "IMAP extension not installed. Skipping read phase.\n";
    exit;
}

echo "Processing IMAP Receivers...\n";
$allAccounts = dbFetchAll("SELECT * FROM smtp_accounts WHERE is_active = 1 AND imap_host IS NOT NULL AND imap_host != ''");

$readsThisRun = 0;
$maxReadsPerRun = 3;

foreach ($allAccounts as $acc) {
    if ($readsThisRun >= $maxReadsPerRun) break; // Don't crash server checking 100 inboxes
    
    // Only check randomly to save resources, unless it's a seed account (check more often)
    $chance = $acc['is_seed_account'] ? 0.5 : 0.2;
    if ((mt_rand() / mt_getrandmax()) > $chance) {
        continue;
    }
    
    $readsThisRun++;
    echo "Checking inbox: {$acc['from_email']}...\n";
    
    $host = $acc['imap_host'];
    $port = $acc['imap_port'] ?: 993;
    $encryption = $acc['imap_encryption'] === 'ssl' ? '/ssl' : ($acc['imap_encryption'] === 'tls' ? '/tls' : '');
    $user = $acc['imap_username'] ?: $acc['smtp_username'];
    $pass = $acc['imap_password'] ? decryptString($acc['imap_password']) : decryptString($acc['smtp_password']);
    
    $serverString = "{{$host}:{$port}/imap{$encryption}/novalidate-cert}";
    
    try {
        // Connect silently
        $inbox = @imap_open($serverString . 'INBOX', $user, $pass, OP_SILENT, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        
        if ($inbox) {
            // Find unread
            $emails = imap_search($inbox, 'UNSEEN');
            if ($emails) {
                foreach ($emails as $emailNo) {
                    $header = imap_fetch_overview($inbox, $emailNo, 0);
                    $fullHeader = imap_fetchheader($inbox, $emailNo);
                    
                    // Extract Message-ID
                    if (preg_match('/Message-ID:\s*(<[^>]+>)/i', $fullHeader, $matches)) {
                        $msgId = $matches[1];
                        
                        // Is this our warmup email?
                        $log = dbFetchOne("SELECT * FROM warmup_logs WHERE message_id = ? AND replied_at IS NULL", [$msgId]);
                        if ($log && $log['receiver_account_id'] == $acc['id']) {
                            
                            // It's a warmup match!
                            echo "Found warmup email in INBOX: $msgId\n";
                            
                            // Mark as delivered (reached inbox)
                            if (empty($log['delivered_at'])) {
                                dbExecute("UPDATE warmup_logs SET delivered_at = NOW(), status = CASE WHEN status = 'sent' THEN 'delivered' ELSE status END WHERE id = ?", [$log['id']]);
                            }
                            
                            // Mark as read
                            imap_setflag_full($inbox, $emailNo, "\\Seen");
                            
                            // 30% chance to reply (only if this seed account has SMTP configured)
                            if (mt_rand(1, 100) <= 30 && !empty($acc['smtp_host']) && !empty($acc['smtp_username']) && !empty($acc['smtp_password'])) {
                                // Fetch original sender
                                $senderAuth = dbFetchOne("SELECT * FROM smtp_accounts WHERE id = ?", [$log['sender_account_id']]);
                                
                                if ($senderAuth) {
                                    $replyBody = SpintaxEngine::generateReply();
                                    
                                    // Send reply via SMTP
                                    $mail = new PHPMailer(true);
                                    try {
                                        $mail->isSMTP();
                                        $mail->Host = $acc['smtp_host'];
                                        $mail->SMTPAuth = true;
                                        $mail->Username = $acc['smtp_username'];
                                        $mail->Password = decryptString($acc['smtp_password']);
                                        $mail->SMTPSecure = $acc['smtp_encryption'] ?: PHPMailer::ENCRYPTION_STARTTLS;
                                        $mail->Port = $acc['smtp_port'];
                                        
                                        $mail->setFrom($acc['from_email'], $acc['from_name']);
                                        $mail->addAddress($senderAuth['from_email'], $senderAuth['from_name']);
                                        
                                        $mail->isHTML(true);
                                        $mail->Subject = "Re: " . str_replace("Re: ", "", $header[0]->subject);
                                        $mail->Body = "<p>" . $replyBody . "</p>";
                                        
                                        // Crucial threading headers
                                        $mail->addCustomHeader('In-Reply-To', $msgId);
                                        $mail->addCustomHeader('References', $msgId);
                                        
                                        $mail->send();
                                        
                                        dbExecute("UPDATE warmup_logs SET replied_at = NOW(), status = 'replied' WHERE id = ?", [$log['id']]);
                                        echo "Replied to $msgId\n";
                                        
                                    } catch (Exception $e) {
                                        echo "Reply failed: {$mail->ErrorInfo}\n";
                                    }
                                }
                            }
                        }
                    }
                }
            }
            imap_close($inbox);
        } else {
            echo "Failed to connect to INBOX for {$acc['from_email']} - " . imap_last_error() . "\n";
        }
        
        // ------------------ SPAM FOLDER RESCUE ------------------
        // Common spam folders
        $spamFolders = ['Spam', 'Junk', '[Gmail]/Spam', 'Junk E-mail'];
        foreach ($spamFolders as $folder) {
            $spamBox = @imap_open($serverString . $folder, $user, $pass, OP_SILENT, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            if ($spamBox) {
                // Only check recent emails to avoid scanning a massive spam folder
                $since = date('d-M-Y', strtotime('-14 days'));
                $spamEmails = imap_search($spamBox, 'SINCE "' . $since . '"');
                if ($spamEmails) {
                    foreach ($spamEmails as $emailNo) {
                        $fullHeader = imap_fetchheader($spamBox, $emailNo);
                        if (preg_match('/Message-ID:\s*(<[^>]+>)/i', $fullHeader, $matches)) {
                            $msgId = $matches[1];
                            
                            $log = dbFetchOne("SELECT * FROM warmup_logs WHERE message_id = ? AND spam_saved = 0", [$msgId]);
                            if ($log && $log['receiver_account_id'] == $acc['id']) {
                                echo "⚠ RESCUING FROM SPAM: $msgId\n";
                                
                                // Move to Inbox!
                                imap_mail_move($spamBox, $emailNo, 'INBOX');
                                
                                // Mark as read
                                imap_setflag_full($spamBox, $emailNo, "\\Seen");
                                
                                dbExecute("UPDATE warmup_logs SET spam_saved = 1, status = 'spam' WHERE id = ?", [$log['id']]);
                            }
                        }
                    }
                    imap_expunge($spamBox);
                }
                imap_close($spamBox);
            }
        }
    } catch (Throwable $t) {
        echo "Exception during IMAP for {$acc['from_email']}: {$t->getMessage()}\n";
    }
}

echo "Warm-up Engine Complete.\n";
