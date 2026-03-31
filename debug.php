<?php
// Debug script - shows errors from cron
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>Debug Cron</h2>";
echo "<p>PHP Version: " . phpversion() . "</p>";

// Test config
echo "<p>Loading config...</p>";
require_once __DIR__ . '/config.php';
echo "<p>✓ Config loaded. APP_URL = " . APP_URL . "</p>";

// Test DB
echo "<p>Loading DB...</p>";
require_once __DIR__ . '/includes/db.php';
echo "<p>✓ DB loaded.</p>";

// Test auth
echo "<p>Loading auth...</p>";
require_once __DIR__ . '/includes/auth.php';
echo "<p>✓ Auth loaded.</p>";

// Test functions
echo "<p>Loading functions...</p>";
require_once __DIR__ . '/includes/functions.php';
echo "<p>✓ Functions loaded.</p>";

// Test PHPMailer
echo "<p>Loading PHPMailer...</p>";
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';
echo "<p>✓ PHPMailer loaded.</p>";

// Test DB query
echo "<p>Testing DB query...</p>";
$pending = dbFetchAll("SELECT eq.id, eq.to_email, eq.status, eq.scheduled_at, c.status as campaign_status FROM email_queue eq JOIN campaigns c ON eq.campaign_id = c.id WHERE eq.status = 'pending' ORDER BY eq.scheduled_at ASC LIMIT 10");
echo "<p>✓ Found " . count($pending) . " pending emails:</p>";
echo "<pre>";
foreach ($pending as $p) {
    echo "ID: {$p['id']} | To: {$p['to_email']} | Status: {$p['status']} | Scheduled: {$p['scheduled_at']} | Campaign: {$p['campaign_status']}\n";
}
echo "</pre>";

// Check campaign status
$campaigns = dbFetchAll("SELECT id, name, status, scheduled_at FROM campaigns ORDER BY id DESC LIMIT 5");
echo "<h3>Campaigns:</h3><pre>";
foreach ($campaigns as $c) {
    echo "ID: {$c['id']} | {$c['name']} | Status: {$c['status']} | Scheduled: {$c['scheduled_at']}\n";
}
echo "</pre>";

echo "<p>Server time: " . date('Y-m-d H:i:s') . " (" . date_default_timezone_get() . ")</p>";
echo "<h3>All checks passed! ✓</h3>";
