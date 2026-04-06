<?php
/**
 * API: Bulk Save Gmail SMTP Accounts
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['success' => false, 'message' => 'Invalid request data'], 400);
}

validateCSRF($input['csrf_token'] ?? '');

$rows = $input['rows'] ?? [];
$isSeedAccount = !empty($input['is_seed_account']) ? 1 : 0;
$sendTestEmail = !empty($input['send_test_email']);
$testToEmailRaw = trim((string)($input['test_to_email'] ?? ''));

if (!is_array($rows) || empty($rows)) {
    jsonResponse(['success' => false, 'message' => 'No rows provided.'], 400);
}

if (count($rows) > 500) {
    jsonResponse(['success' => false, 'message' => 'Too many rows. Maximum 500 per import.'], 400);
}

if ($sendTestEmail && count($rows) > 100) {
    jsonResponse(['success' => false, 'message' => 'When SMTP testing is enabled, maximum 100 rows per import is allowed.'], 400);
}

if ($testToEmailRaw !== '' && !filter_var($testToEmailRaw, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Test recipient email is invalid.'], 400);
}

$created = 0;
$skipped = 0;
$errors = [];
$testResults = [];

foreach ($rows as $idx => $row) {
    $lineNumber = $idx + 1;
    $email = strtolower(trim((string) ($row['email'] ?? '')));
    $appPasswordRaw = trim((string) ($row['app_password'] ?? ''));

    if (!$email || !$appPasswordRaw) {
        $errors[] = "Row {$lineNumber}: Missing email or app password.";
        continue;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Row {$lineNumber}: Invalid email format ({$email}).";
        continue;
    }

    // Accept app passwords with spaces and normalize before storing.
    $appPassword = str_replace(' ', '', $appPasswordRaw);
    if (strlen($appPassword) < 12) {
        $errors[] = "Row {$lineNumber}: App password looks too short.";
        continue;
    }

    $exists = dbFetchOne(
        "SELECT id FROM smtp_accounts WHERE LOWER(smtp_username) = ? OR LOWER(from_email) = ? LIMIT 1",
        [$email, $email]
    );
    if ($exists) {
        $skipped++;
        continue;
    }

    $label = 'Gmail - ' . $email;
    $fromName = strstr($email, '@', true) ?: $email;

    try {
        $newAccountId = dbInsert(
            "INSERT INTO smtp_accounts
            (label, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, from_name, from_email, daily_limit,
             imap_host, imap_port, imap_encryption, imap_username, imap_password, is_seed_account, warmup_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $label,
                'smtp.gmail.com',
                587,
                'tls',
                $email,
                encryptString($appPassword),
                $fromName,
                $email,
                0,
                'imap.gmail.com',
                993,
                'ssl',
                $email,
                encryptString($appPassword),
                $isSeedAccount,
                'active'
            ]
        );
        $created++;

        if ($sendTestEmail) {
            $testToEmail = $testToEmailRaw !== '' ? $testToEmailRaw : $email;
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = $email;
                $mail->Password = $appPassword;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->Timeout = 20;

                $mail->setFrom($email, $fromName);
                $mail->addAddress($testToEmail);
                $mail->isHTML(true);
                $mail->Subject = 'SMTP Test - ' . APP_NAME;
                $mail->Body = '<p>SMTP test passed for <strong>' . e($email) . '</strong>.</p><p>Sent via bulk Gmail import verification.</p>';
                $mail->AltBody = 'SMTP test passed for ' . $email . '. Sent via bulk Gmail import verification.';
                $mail->CharSet = 'UTF-8';

                $mail->send();
                $testResults[] = [
                    'account_id' => (int)$newAccountId,
                    'email' => $email,
                    'success' => true,
                    'message' => 'Test email sent to ' . $testToEmail,
                ];
            } catch (Exception $e) {
                $testResults[] = [
                    'account_id' => (int)$newAccountId,
                    'email' => $email,
                    'success' => false,
                    'message' => 'SMTP test failed: ' . $e->getMessage(),
                ];
            }
        }
    } catch (Exception $e) {
        $errors[] = "Row {$lineNumber}: " . $e->getMessage();
    }
}

$message = "Bulk import completed. Created {$created}, skipped {$skipped}, failed " . count($errors) . ".";
if ($sendTestEmail) {
    $passed = count(array_filter($testResults, function ($r) { return !empty($r['success']); }));
    $failed = count($testResults) - $passed;
    $message .= " SMTP tests: {$passed} passed, {$failed} failed.";
}
jsonResponse([
    'success' => true,
    'message' => $message,
    'created' => $created,
    'skipped' => $skipped,
    'errors' => $errors,
    'test_results' => $testResults,
]);
