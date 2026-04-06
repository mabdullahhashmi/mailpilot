<?php
/**
 * API: Pre-Send Spam Analyzer
 * Analyzes email content for spam risk factors before sending.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireAuth();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$subject = trim($input['subject'] ?? '');
$body = trim($input['body'] ?? '');
$fromEmail = trim($input['from_email'] ?? '');

if (empty($subject) && empty($body)) {
    echo json_encode(['success' => false, 'error' => 'Provide subject and/or body to analyze.']);
    exit;
}

$issues = [];
$score = 100; // Start at 100 (perfect), deduct for issues

// ==================== SUBJECT ANALYSIS ====================
if (!empty($subject)) {
    // 1. ALL CAPS subject
    if (strlen($subject) > 5 && strtoupper($subject) === $subject) {
        $issues[] = ['severity' => 'high', 'category' => 'Subject', 'message' => 'Subject is ALL CAPS — triggers spam filters'];
        $score -= 15;
    }

    // 2. Excessive punctuation
    if (preg_match('/[!?]{2,}/', $subject)) {
        $issues[] = ['severity' => 'medium', 'category' => 'Subject', 'message' => 'Multiple exclamation/question marks in subject'];
        $score -= 8;
    }

    // 3. Spam trigger words in subject
    $subjectTriggers = [
        'free' => 5, 'buy now' => 10, 'act now' => 10, 'limited time' => 8,
        'click here' => 8, 'earn money' => 12, 'make money' => 12, 'cash' => 5,
        'winner' => 10, 'congratulations' => 10, 'urgent' => 8, 'discount' => 5,
        'guaranteed' => 8, 'no obligation' => 8, 'risk free' => 8, 'lowest price' => 8,
        'order now' => 8, 'special promotion' => 7, 'offer expires' => 7,
        'dear friend' => 10, 'this is not spam' => 15, 'unsubscribe' => 3,
        'viagra' => 20, 'cialis' => 20, 'lottery' => 15, 'nigerian' => 15,
        'million dollars' => 15, 'wire transfer' => 12, 'double your' => 10,
        'no cost' => 7, 'while supplies last' => 7, 'apply now' => 5,
        'call now' => 7, '100% free' => 12, 'best price' => 6
    ];

    $subjectLower = strtolower($subject);
    foreach ($subjectTriggers as $trigger => $penalty) {
        if (strpos($subjectLower, $trigger) !== false) {
            $issues[] = ['severity' => $penalty >= 10 ? 'high' : 'medium', 'category' => 'Subject', 'message' => "Spam trigger word: \"{$trigger}\""];
            $score -= $penalty;
        }
    }

    // 4. Subject length
    if (strlen($subject) > 100) {
        $issues[] = ['severity' => 'low', 'category' => 'Subject', 'message' => 'Subject is very long (' . strlen($subject) . ' chars) — may get truncated'];
        $score -= 3;
    }
    if (strlen($subject) < 5) {
        $issues[] = ['severity' => 'medium', 'category' => 'Subject', 'message' => 'Subject is very short — may look suspicious'];
        $score -= 5;
    }

    // 5. Re:/Fwd: fake threading
    if (preg_match('/^(Re|Fwd|FW):/i', $subject)) {
        $issues[] = ['severity' => 'medium', 'category' => 'Subject', 'message' => 'Fake Re:/Fwd: prefix — often flagged by filters'];
        $score -= 7;
    }
}

// ==================== BODY ANALYSIS ====================
if (!empty($body)) {
    $bodyPlain = strip_tags($body);
    $bodyLower = strtolower($bodyPlain);

    // 6. Body spam trigger words
    $bodyTriggers = [
        'click here' => 5, 'buy now' => 7, 'act now' => 7, 'limited offer' => 5,
        'earn extra' => 7, 'earn money' => 7, 'make money online' => 10,
        'as seen on' => 5, 'work from home' => 7, 'no questions asked' => 6,
        'free gift' => 8, 'winner' => 7, 'credit card' => 5, 'passwords' => 5,
        'social security' => 10, 'bank account' => 7, 'wire transfer' => 8,
        'nigerian prince' => 20, 'please help' => 3, 'this is not spam' => 12,
        'opt in' => 3, 'unsolicited' => 5
    ];

    foreach ($bodyTriggers as $trigger => $penalty) {
        if (strpos($bodyLower, $trigger) !== false) {
            $issues[] = ['severity' => $penalty >= 8 ? 'high' : 'medium', 'category' => 'Body', 'message' => "Spam trigger phrase: \"{$trigger}\""];
            $score -= $penalty;
        }
    }

    // 7. ALL CAPS in body (excessive)
    $words = preg_split('/\s+/', $bodyPlain);
    $capsWords = array_filter($words, function($w) { return strlen($w) > 3 && strtoupper($w) === $w && preg_match('/[A-Z]/', $w); });
    $capsPercent = count($words) > 0 ? (count($capsWords) / count($words)) * 100 : 0;
    if ($capsPercent > 30) {
        $issues[] = ['severity' => 'high', 'category' => 'Body', 'message' => 'Excessive CAPS usage (' . round($capsPercent) . '% of words)'];
        $score -= 12;
    } elseif ($capsPercent > 15) {
        $issues[] = ['severity' => 'medium', 'category' => 'Body', 'message' => 'High CAPS usage (' . round($capsPercent) . '% of words)'];
        $score -= 6;
    }

    // 8. Too many links
    $linkCount = preg_match_all('/(https?:\/\/|www\.)/i', $body);
    if ($linkCount > 5) {
        $issues[] = ['severity' => 'high', 'category' => 'Body', 'message' => "Too many links ($linkCount) — looks like spam"];
        $score -= 10;
    } elseif ($linkCount > 3) {
        $issues[] = ['severity' => 'medium', 'category' => 'Body', 'message' => "Multiple links ($linkCount) — use sparingly"];
        $score -= 5;
    }

    // 9. URL shorteners
    $shorteners = ['bit.ly', 'tinyurl.com', 'goo.gl', 't.co', 'ow.ly', 'is.gd', 'buff.ly', 'rebrand.ly'];
    foreach ($shorteners as $shortener) {
        if (stripos($body, $shortener) !== false) {
            $issues[] = ['severity' => 'high', 'category' => 'Body', 'message' => "URL shortener detected ($shortener) — use full URLs instead"];
            $score -= 10;
            break;
        }
    }

    // 10. Image-to-text ratio (HTML body)
    if ($body !== $bodyPlain) { // Has HTML
        $imageCount = preg_match_all('/<img/i', $body);
        $textLength = strlen($bodyPlain);
        if ($imageCount > 0 && $textLength < 100) {
            $issues[] = ['severity' => 'high', 'category' => 'HTML', 'message' => 'Image-heavy with very little text — major spam signal'];
            $score -= 15;
        } elseif ($imageCount > 3 && $textLength < 300) {
            $issues[] = ['severity' => 'medium', 'category' => 'HTML', 'message' => 'High image-to-text ratio — add more text content'];
            $score -= 7;
        }

        // 11. Inline styles with suspicious properties
        if (preg_match('/display\s*:\s*none/i', $body)) {
            $issues[] = ['severity' => 'high', 'category' => 'HTML', 'message' => 'Hidden content (display:none) — major spam signal'];
            $score -= 15;
        }
        if (preg_match('/font-size\s*:\s*[01]px/i', $body)) {
            $issues[] = ['severity' => 'high', 'category' => 'HTML', 'message' => 'Tiny invisible text (font-size:0-1px) — deceptive'];
            $score -= 15;
        }
        if (preg_match('/color\s*:\s*#?(fff|ffffff|white)\s*;/i', $body) && preg_match('/background\s*:\s*#?(fff|ffffff|white)/i', $body)) {
            $issues[] = ['severity' => 'medium', 'category' => 'HTML', 'message' => 'White text on white background detected'];
            $score -= 10;
        }

        // 12. Forms in email
        if (preg_match('/<form/i', $body)) {
            $issues[] = ['severity' => 'high', 'category' => 'HTML', 'message' => 'Form element in email — almost always blocked'];
            $score -= 15;
        }

        // 13. JavaScript
        if (preg_match('/<script/i', $body)) {
            $issues[] = ['severity' => 'high', 'category' => 'HTML', 'message' => 'JavaScript in email — will be stripped/flagged by all clients'];
            $score -= 20;
        }

        // 14. iframes
        if (preg_match('/<iframe/i', $body)) {
            $issues[] = ['severity' => 'high', 'category' => 'HTML', 'message' => 'iframe in email — blocked by all major providers'];
            $score -= 15;
        }
    }

    // 15. Unsubscribe link check
    if (stripos($body, 'unsubscribe') === false && stripos($body, 'opt out') === false && stripos($body, 'opt-out') === false) {
        $issues[] = ['severity' => 'medium', 'category' => 'Compliance', 'message' => 'No unsubscribe/opt-out link found — required by CAN-SPAM & GDPR'];
        $score -= 8;
    }

    // 16. Physical address check (CAN-SPAM)
    $hasAddress = preg_match('/(\d+\s+[\w\s]+(?:street|st|avenue|ave|road|rd|boulevard|blvd|drive|dr|lane|ln|way|court|ct))/i', $bodyPlain);
    if (!$hasAddress && strlen($bodyPlain) > 200) {
        $issues[] = ['severity' => 'low', 'category' => 'Compliance', 'message' => 'No physical mailing address — recommended for CAN-SPAM compliance'];
        $score -= 3;
    }

    // 17. Body length
    if (strlen($bodyPlain) < 50) {
        $issues[] = ['severity' => 'medium', 'category' => 'Body', 'message' => 'Very short email body — may look like spam or phishing'];
        $score -= 5;
    }

    // 18. Excessive exclamation marks in body
    $exclamationCount = substr_count($bodyPlain, '!');
    if ($exclamationCount > 5) {
        $issues[] = ['severity' => 'medium', 'category' => 'Body', 'message' => "Too many exclamation marks ($exclamationCount) — looks overly promotional"];
        $score -= 5;
    }

    // 19. Dollar/currency spam patterns
    if (preg_match('/\$\d{1,3}(,\d{3})+|\$\d{4,}/i', $bodyPlain)) {
        $issues[] = ['severity' => 'medium', 'category' => 'Body', 'message' => 'Large dollar amount mentioned — common spam pattern'];
        $score -= 5;
    }

    // 20. ALL CAPS sentences
    if (preg_match('/[A-Z\s]{20,}/', $bodyPlain)) {
        $issues[] = ['severity' => 'medium', 'category' => 'Body', 'message' => 'Long ALL CAPS text segments detected'];
        $score -= 5;
    }
}

// ==================== SENDER ANALYSIS ====================
if (!empty($fromEmail)) {
    $domain = explode('@', $fromEmail)[1] ?? '';

    // 21. Free email providers for business email
    $freeProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'mail.com', 'protonmail.com'];
    if (in_array(strtolower($domain), $freeProviders)) {
        $issues[] = ['severity' => 'low', 'category' => 'Sender', 'message' => "Sending from free provider ($domain) — use a custom domain for better deliverability"];
        $score -= 3;
    }

    // 22. Check SPF/DKIM/DMARC if domain provided
    if ($domain) {
        // Quick MX check
        if (!checkdnsrr($domain, 'MX')) {
            $issues[] = ['severity' => 'high', 'category' => 'Sender', 'message' => "No MX records for $domain — emails will likely bounce"];
            $score -= 15;
        }

        // Quick SPF check
        $txtRecords = @dns_get_record($domain, DNS_TXT);
        $hasSPF = false;
        if ($txtRecords) {
            foreach ($txtRecords as $txt) {
                if (stripos($txt['txt'] ?? '', 'v=spf1') !== false) {
                    $hasSPF = true;
                    break;
                }
            }
        }
        if (!$hasSPF) {
            $issues[] = ['severity' => 'high', 'category' => 'Sender', 'message' => "No SPF record for $domain — set up SPF to improve deliverability"];
            $score -= 10;
        }

        // Quick DMARC check
        $dmarcRecords = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
        $hasDMARC = false;
        if ($dmarcRecords) {
            foreach ($dmarcRecords as $txt) {
                if (stripos($txt['txt'] ?? '', 'v=DMARC1') !== false) {
                    $hasDMARC = true;
                    break;
                }
            }
        }
        if (!$hasDMARC) {
            $issues[] = ['severity' => 'medium', 'category' => 'Sender', 'message' => "No DMARC record for $domain — recommended for authentication"];
            $score -= 7;
        }
    }
}

// Clamp score
$score = max(0, min(100, $score));

// Risk level
$riskLevel = 'low';
if ($score < 40) $riskLevel = 'critical';
elseif ($score < 60) $riskLevel = 'high';
elseif ($score < 80) $riskLevel = 'medium';

// Sort issues by severity
$severityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
usort($issues, function($a, $b) use ($severityOrder) {
    return ($severityOrder[$a['severity']] ?? 3) - ($severityOrder[$b['severity']] ?? 3);
});

echo json_encode([
    'success' => true,
    'score' => $score,
    'risk_level' => $riskLevel,
    'issue_count' => count($issues),
    'issues' => $issues,
    'summary' => [
        'high' => count(array_filter($issues, fn($i) => $i['severity'] === 'high')),
        'medium' => count(array_filter($issues, fn($i) => $i['severity'] === 'medium')),
        'low' => count(array_filter($issues, fn($i) => $i['severity'] === 'low')),
    ]
]);
