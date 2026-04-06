<?php
/**
 * Warmup Email Open Tracking Pixel
 * 
 * Served as a transparent 1x1 GIF image.
 * When the recipient opens the email, this pixel loads and records the open.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$token = $_GET['t'] ?? '';

if ($token && strlen($token) <= 64 && preg_match('/^[a-f0-9]+$/', $token)) {
    try {
        $log = dbFetchOne("SELECT id, opened_at FROM warmup_logs WHERE open_tracking_token = ?", [$token]);
        if ($log) {
            // Increment counter every time
            dbExecute("UPDATE warmup_logs SET open_count = open_count + 1 WHERE id = ?", [$log['id']]);
            
            // First open: record timestamp and update status
            if (!$log['opened_at']) {
                dbExecute("UPDATE warmup_logs SET opened_at = NOW(), status = 'opened' WHERE id = ? AND status IN ('sent','delivered')", [$log['id']]);
            }
        }
    } catch (\Exception $e) {
        // Silently fail — never break the image
    }
}

// Serve a transparent 1x1 GIF pixel
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Minimal transparent 1x1 GIF (43 bytes)
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
