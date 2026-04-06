<?php
/**
 * API: Bulk Save Gmail SMTP Accounts
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
    jsonResponse(['success' => false, 'message' => 'Invalid request data'], 400);
}

validateCSRF($input['csrf_token'] ?? '');

$rows = $input['rows'] ?? [];
$isSeedAccount = !empty($input['is_seed_account']) ? 1 : 0;

if (!is_array($rows) || empty($rows)) {
    jsonResponse(['success' => false, 'message' => 'No rows provided.'], 400);
}

if (count($rows) > 500) {
    jsonResponse(['success' => false, 'message' => 'Too many rows. Maximum 500 per import.'], 400);
}

$created = 0;
$skipped = 0;
$errors = [];

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
        dbInsert(
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
    } catch (Exception $e) {
        $errors[] = "Row {$lineNumber}: " . $e->getMessage();
    }
}

$message = "Bulk import completed. Created {$created}, skipped {$skipped}, failed " . count($errors) . ".";
jsonResponse([
    'success' => true,
    'message' => $message,
    'created' => $created,
    'skipped' => $skipped,
    'errors' => $errors,
]);
