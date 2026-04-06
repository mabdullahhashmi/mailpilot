<?php
/**
 * Migration: Warmup Open Tracking & Enhanced Logs
 * Adds opened_at, open_count, email_body, status, delivered_at to warmup_logs
 * 
 * Run once: visit /migrate-warmup-tracking.php in browser
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "<pre>";
echo "=== Warmup Tracking Enhancement Migration ===\n\n";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cols = [
        "ADD COLUMN `opened_at` DATETIME DEFAULT NULL AFTER `replied_at`",
        "ADD COLUMN `open_count` INT NOT NULL DEFAULT 0 AFTER `opened_at`",
        "ADD COLUMN `email_body` TEXT DEFAULT NULL AFTER `receiver_email`",
        "ADD COLUMN `status` ENUM('scheduled','sent','delivered','opened','replied','spam','failed') NOT NULL DEFAULT 'sent' AFTER `spam_saved`",
        "ADD COLUMN `delivered_at` DATETIME DEFAULT NULL AFTER `sent_at`",
        "ADD COLUMN `open_tracking_token` VARCHAR(64) DEFAULT NULL AFTER `thread_id`",
        "ADD COLUMN `sender_label` VARCHAR(255) DEFAULT NULL AFTER `sender_email`",
        "ADD COLUMN `receiver_label` VARCHAR(255) DEFAULT NULL AFTER `receiver_email`",
    ];

    foreach ($cols as $col) {
        try {
            $pdo->exec("ALTER TABLE `warmup_logs` $col");
            echo "✅ $col\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "⏭️  Already exists, skipping: $col\n";
            } else {
                throw $e;
            }
        }
    }

    // Add index on open_tracking_token
    try {
        $pdo->exec("ALTER TABLE `warmup_logs` ADD INDEX `idx_open_token` (`open_tracking_token`)");
        echo "✅ Added index on open_tracking_token\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⏭️  Index already exists\n";
        } else {
            throw $e;
        }
    }

    // Backfill status for existing rows
    $pdo->exec("UPDATE warmup_logs SET status = 'replied' WHERE replied_at IS NOT NULL AND status = 'sent'");
    $pdo->exec("UPDATE warmup_logs SET status = 'spam' WHERE spam_saved = 1 AND replied_at IS NULL AND status = 'sent'");
    echo "\n✅ Backfilled status for existing warmup_logs\n";

    // Rename sent_at -> created_at if sent_at exists (it's the original column)
    // Actually, check if created_at exists. If warmup_logs uses sent_at, we keep it consistent.
    $columns = $pdo->query("SHOW COLUMNS FROM warmup_logs")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('created_at', $columns) && in_array('sent_at', $columns)) {
        echo "\nNote: Table uses 'sent_at' as creation timestamp (no migration needed)\n";
    }

    echo "\n=== Migration Complete ===\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
echo "</pre>";
