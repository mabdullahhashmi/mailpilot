<?php
/**
 * Migration: Warmup Schedule Table
 * Creates warmup_schedule for pre-planned daily email slots.
 * Run once: visit /migrate-warmup-schedule.php in browser
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "<pre>";
echo "=== Warmup Schedule Migration ===\n\n";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `warmup_schedule` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `sender_account_id` INT NOT NULL,
            `receiver_account_id` INT NOT NULL,
            `scheduled_at` DATETIME NOT NULL,
            `status` ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
            `warmup_log_id` INT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_scheduled` (`scheduled_at`),
            INDEX `idx_status` (`status`),
            INDEX `idx_sender` (`sender_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✅ warmup_schedule table created (or already exists)\n";

    // Also fix any seed accounts that were incorrectly set as warmup_status = 'active'
    // Seeds should always remain as seeds — reset warmup fields to neutral
    $fixed = $pdo->exec("
        UPDATE smtp_accounts
        SET warmup_status = 'idle', warmup_current_day = 0, warmup_target_daily = 0, sent_today = 0
        WHERE is_seed_account = 1 AND warmup_status = 'active'
    ");
    echo "✅ Fixed $fixed seed account(s) that were incorrectly marked as active warmup senders\n";

    echo "\n=== Migration Complete ===\n";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
echo "</pre>";
