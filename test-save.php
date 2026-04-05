<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Test inserting an SMTP account manually
try {
    $label = 'Test';
    $host = 'test';
    $port = 465;
    $encryption = 'ssl';
    $username = 'test';
    $password = 'test';
    $fromName = 'test';
    $fromEmail = 'test';
    $dailyLimit = 0;
    $imapHost = 'test';
    $imapPort = 993;
    $imapEncryption = 'ssl';
    $imapUsername = 'test';
    $isSeedAccount = 0;

    // Check if columns exist
    $res = dbFetchAll("SHOW COLUMNS FROM smtp_accounts");
    $cols = array_column($res, 'Field');
    echo "Columns: " . implode(', ', $cols) . "\n<br>";

    $encImapPass = encryptString('test');
    
    $newId = dbInsert(
        "INSERT INTO smtp_accounts (label, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, from_name, from_email, daily_limit, imap_host, imap_port, imap_encryption, imap_username, imap_password, is_seed_account) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $label, $host, $port, $encryption, $username, encryptString($password), $fromName, $fromEmail, $dailyLimit,
            $imapHost, $imapPort, $imapEncryption, $imapUsername, $encImapPass, $isSeedAccount
        ]
    );
    echo "Success: $newId";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . " on line " . $e->getLine();
}
