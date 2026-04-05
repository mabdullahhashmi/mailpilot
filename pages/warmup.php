<?php
/**
 * Warm-up Dashboard
 */
$pageTitle = 'Email Warm-up';
require_once __DIR__ . '/../includes/header.php';

// Handle warmup restart for a completed account
if (isset($_GET['restart']) && is_numeric($_GET['restart'])) {
    $restartId = (int) $_GET['restart'];
    $token = $_GET['token'] ?? '';
    if (hash_equals(getCSRFToken(), $token)) {
        dbExecute(
            "UPDATE smtp_accounts SET warmup_status = 'active', warmup_current_day = 0, warmup_target_daily = 2, sent_today = 0, warmup_completed_at = NULL WHERE id = ? AND warmup_status = 'completed'",
            [$restartId]
        );
        setFlash('success', 'Warmup restarted from Day 1.');
        redirect($basePath . '/pages/warmup.php');
    }
}

// Fetch warmup stats
$totalWarmupAccounts = getCount('smtp_accounts', "warmup_status = 'active'");
$totalCompletedAccounts = getCount('smtp_accounts', "warmup_status = 'completed'");
$totalSeeds = getCount('smtp_accounts', "is_seed_account = 1");

// Fetch logs summary
$totalSent = getCount('warmup_logs');
$totalSavedFromSpam = getCount('warmup_logs', "spam_saved = 1");
$totalReplies = getCount('warmup_logs', "replied_at IS NOT NULL");

// Fetch active warmup accounts (active, completed, and seeds)
$accounts = dbFetchAll("SELECT id, label, from_email, warmup_status, warmup_current_day, warmup_target_daily, is_seed_account, sent_today, warmup_completed_at FROM smtp_accounts WHERE warmup_status IN ('active','completed') OR is_seed_account = 1 ORDER BY is_seed_account DESC, warmup_current_day DESC");

// Warmup health score (spam_saved rate + reply rate)
$healthStats = dbFetchOne("SELECT COUNT(*) as total, SUM(spam_saved) as rescued, SUM(replied_at IS NOT NULL) as replied FROM warmup_logs");
$healthScore = 0;
if (!empty($healthStats['total']) && $healthStats['total'] > 0) {
    $rescueRate = ($healthStats['rescued'] / $healthStats['total']) * 60;
    $replyRate  = ($healthStats['replied']  / $healthStats['total']) * 40;
    $healthScore = min(100, (int) round($rescueRate + $replyRate));
}

// Fetch recent logs
$logs = dbFetchAll("
    SELECT w.*, 
           s.from_email as sender_email, 
           r.from_email as receiver_email 
    FROM warmup_logs w
    JOIN smtp_accounts s ON w.sender_account_id = s.id
    JOIN smtp_accounts r ON w.receiver_account_id = r.id
    ORDER BY w.sent_at DESC 
    LIMIT 20
");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">🔥</span>Email Warm-up</h1>
        <div class="subtitle">Automated domain reputation building using the 30-day Smart Ramp-up Algorithm</div>
    </div>
    <a href="<?= $basePath ?>/pages/accounts.php" class="btn btn-outline">
        ⚙️ Configure Accounts
    </a>
</div>

<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-title">Active Warm-up Domains</div>
        <div class="stat-value"><?= $totalWarmupAccounts ?></div>
        <div class="stat-subtitle">Sending emails daily</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Completed Warm-ups</div>
        <div class="stat-value" style="color: var(--color-success);"><?= $totalCompletedAccounts ?></div>
        <div class="stat-subtitle">Ready for cold outreach</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Seed Accounts</div>
        <div class="stat-value"><?= $totalSeeds ?></div>
        <div class="stat-subtitle">Gmail/Outlook receivers</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Total Sent</div>
        <div class="stat-value" style="color: var(--color-primary);"><?= number_format($totalSent) ?></div>
        <div class="stat-subtitle">AI-generated emails</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Rescued from Spam</div>
        <div class="stat-value" style="color: var(--color-success);"><?= number_format($totalSavedFromSpam) ?></div>
        <div class="stat-subtitle">Massive reputation boost</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Warmup Health Score</div>
        <div class="stat-value" style="color: <?= $healthScore >= 70 ? 'var(--color-success)' : ($healthScore >= 40 ? '#f59e0b' : 'var(--color-danger)') ?>;"><?= $healthScore ?>%</div>
        <div class="stat-subtitle">Based on rescue + reply rate</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
    
    <!-- Accounts List -->
    <div class="card">
        <div class="card-header">
            <h3>Warm-up Accounts</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($accounts)): ?>
                <div style="padding: 24px; text-align: center; color: var(--text-muted);">
                    No accounts currently active in Warm-up.<br>
                    Go to <strong>SMTP Accounts</strong>, edit an account, and fill out the Warm-up & IMAP fields.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Role</th>
                            <th>Progress</th>
                            <th>Today</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($accounts as $acc): ?>
                        <tr>
                            <td>
                                <strong><?= e($acc['label']) ?></strong><br>
                                <span class="text-muted fs-sm"><?= e($acc['from_email']) ?></span>
                            </td>
                            <td>
                                <?php if ($acc['is_seed_account']): ?>
                                    <span class="badge" style="background:#10b981;color:#fff;">🌱 Seed</span>
                                <?php elseif ($acc['warmup_status'] === 'completed'): ?>
                                    <span class="badge" style="background:#10b981;color:#fff;">✅ Done</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#6366f1;color:#fff;">🔥 Sender</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$acc['is_seed_account']): ?>
                                    <?php if ($acc['warmup_status'] === 'completed'): ?>
                                        <div style="font-size: 13px; color: var(--color-success); font-weight: 600;">✅ Completed</div>
                                        <div class="text-muted fs-sm"><?= $acc['warmup_completed_at'] ? date('M j, Y', strtotime($acc['warmup_completed_at'])) : '' ?></div>
                                    <?php else: ?>
                                        <div style="font-size: 13px; margin-bottom: 4px;">Day <?= $acc['warmup_current_day'] ?> of 30</div>
                                        <div style="width: 100%; height: 6px; background: var(--border-color); border-radius: 3px; overflow: hidden;">
                                            <div style="height: 100%; background: var(--color-primary); width: <?= min(100, ($acc['warmup_current_day'] / 30) * 100) ?>%;"></div>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted fs-sm">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$acc['is_seed_account']): ?>
                                    <?php if ($acc['warmup_status'] === 'completed'): ?>
                                        <span style="color: var(--color-success); font-size: 13px;">50 / 50</span><br>
                                        <a href="?restart=<?= $acc['id'] ?>&token=<?= e(getCSRFToken()) ?>" style="font-size: 11px; color: #f59e0b;" onclick="return confirm('Restart warmup from Day 1 for this account?')">↺ Restart</a>
                                    <?php else: ?>
                                        <?= $acc['sent_today'] ?> / <?= $acc['warmup_target_daily'] ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Receiving
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity Log -->
    <div class="card">
        <div class="card-header">
            <h3>Recent Warm-up Activity</h3>
        </div>
        <div class="card-body" style="padding: 0; max-height: 400px; overflow-y: auto;">
            <?php if (empty($logs)): ?>
                <div style="padding: 24px; text-align: center; color: var(--text-muted);">
                    Waiting for the cron job to run and send the first warm-up emails...
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Route</th>
                            <th>Event</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log): ?>
                        <tr>
                            <td class="text-muted fs-sm"><?= timeAgo($log['sent_at']) ?></td>
                            <td style="font-size: 13px;">
                                <div><span style="color:#f59e0b">From:</span> <?= e($log['sender_email']) ?></div>
                                <div><span style="color:#10b981">To:</span> <?= e($log['receiver_email']) ?></div>
                            </td>
                            <td style="font-size: 13px;">
                                <?php if ($log['replied_at']): ?>
                                    <span style="color: var(--color-primary);">✓ Replied via Thread</span><br>
                                <?php else: ?>
                                    <span>Sent Message</span><br>
                                <?php endif; ?>
                                <?php if ($log['spam_saved']): ?>
                                    <span style="color: var(--color-success); font-weight: bold;">↑ Rescued from Spam</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>How It Works</h3>
    </div>
    <div class="card-body" style="line-height: 1.6; color: var(--text-secondary);">
        <p>1. <strong>Add Seeds:</strong> Ensure you have added at least 1 or 2 real Gmail/Outlook accounts as "Seed Accounts" in your SMTP settings. They act as anchors to build reputation with major providers.</p>
        <p>2. <strong>Enable Warm-up:</strong> Any other SMTP account with <span class="badge" style="background:#f59e0b;color:#fff;">Warm-up Status: Active</span> will begin the 14-day Smart Ramp-up.</p>
        <p>3. <strong>The Algorithm:</strong> MailPilot randomly generates free business inquiries (using the built-in Spintax engine). It sends them across your network, ensuring domains never email themselves. Over 30 days, the volume slowly and safely increases from 2 emails/day to 50 emails/day.</p>
        <p>4. <strong>Reputation Boost:</strong> MailPilot logs into the seed accounts via IMAP, pulls emails out of the Spam folder (if they land there), and generates organic replies to create threaded conversations.</p>
        <hr style="margin: 16px 0; border: 0; border-top: 1px solid var(--border-color);">
        <p style="margin: 0;"><strong>Cron Job Requirement:</strong> Make sure you have set up the secondary Cron Job in your hosting panel targeting <code>/cron/process-warmup.php</code>. It should run every 5 minutes (<code>*/5 * * * *</code>).</p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
