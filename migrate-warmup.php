<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Altering smtp_accounts...\n";
    $pdo->exec("
        ALTER TABLE `smtp_accounts`
        ADD COLUMN `imap_host` VARCHAR(255) DEFAULT NULL AFTER `smtp_password`,
        ADD COLUMN `imap_port` INT DEFAULT 993 AFTER `imap_host`,
        ADD COLUMN `imap_encryption` ENUM('ssl','tls','') DEFAULT 'ssl' AFTER `imap_port`,
        ADD COLUMN `imap_username` VARCHAR(255) DEFAULT NULL AFTER `imap_encryption`,
        ADD COLUMN `imap_password` TEXT DEFAULT NULL AFTER `imap_username`,
        ADD COLUMN `warmup_status` ENUM('idle','active') NOT NULL DEFAULT 'idle' AFTER `imap_password`,
        ADD COLUMN `warmup_current_day` INT NOT NULL DEFAULT 0 AFTER `warmup_status`,
        ADD COLUMN `warmup_target_daily` INT NOT NULL DEFAULT 0 AFTER `warmup_current_day`,
        ADD COLUMN `is_seed_account` TINYINT(1) NOT NULL DEFAULT 0 AFTER `warmup_target_daily`
    ");

    echo "Creating warmup_logs...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `warmup_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `sender_account_id` INT NOT NULL,
            `receiver_account_id` INT NOT NULL,
            `message_id` VARCHAR(255) NOT NULL,
            `thread_id` VARCHAR(255) DEFAULT NULL,
            `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `replied_at` DATETIME DEFAULT NULL,
            `spam_saved` TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_sender (`sender_account_id`),
            INDEX idx_receiver (`receiver_account_id`),
            INDEX idx_message (`message_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    echo "Migration complete.\n";

} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Migration already applied or columns exist.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
