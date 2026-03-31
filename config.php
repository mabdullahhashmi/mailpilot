<?php
/**
 * Email Marketing Tool - Configuration
 * 
 * Update these settings before running install.php
 */

// ============================================================
// DATABASE CONFIGURATION
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'u717045813_mailpilot');
define('DB_USER', 'u717045813_mailpilot');
define('DB_PASS', 'mailpilot@ASgd134');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// APPLICATION CONFIGURATION
// ============================================================
define('APP_NAME', 'MailPilot');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://royalblue-tapir-258681.hostingersite.com'); // no trailing slash
define('APP_TIMEZONE', 'Asia/Karachi');

// ============================================================
// SECURITY
// ============================================================
// Change this to a random 32+ character string before first use!
define('ENCRYPTION_KEY', 'mP9x$kQ7vR2wF5nJ8bL3cY6dH0tA4eU1');
define('SESSION_NAME', 'mailpilot_session');
define('CSRF_TOKEN_NAME', 'csrf_token');

// ============================================================
// FILE UPLOADS
// ============================================================
define('UPLOAD_DIR', __DIR__ . '/assets/uploads/');
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('MAX_CSV_SIZE', 10 * 1024 * 1024); // 10MB

// ============================================================
// EMAIL QUEUE
// ============================================================
define('QUEUE_BATCH_SIZE', 5);      // Emails to process per cron run
define('MAX_RETRY_ATTEMPTS', 3);    // Max retries for failed emails
define('CRON_SECRET', 'mp_cron_7Xk9Qw2Rv5Tn'); // Protect cron endpoint

// ============================================================
// TIMEZONE
// ============================================================
date_default_timezone_set(APP_TIMEZONE);

// ============================================================
// ERROR REPORTING (set to 0 in production)
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// ============================================================
// SESSION CONFIGURATION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}
