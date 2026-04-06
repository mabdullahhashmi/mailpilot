<?php
/**
 * Advanced Warmup Reporting
 * Detailed logs: which email goes from which SMTP to which seed, with filters.
 */
$pageTitle = 'Warmup Reports';
require_once __DIR__ . '/../includes/header.php';

// ---- Filters ----
$filterAccount  = $_GET['account'] ?? '';
$filterReceiver = $_GET['receiver'] ?? '';
$filterStatus   = $_GET['status'] ?? '';   // sent, replied, spam_saved
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to'] ?? '';
$page           = max(1, (int)($_GET['pg'] ?? 1));
$perPage        = 50;
$offset         = ($page - 1) * $perPage;

// Build WHERE clauses
$where = [];
$params = [];

if ($filterAccount) {
    $where[] = "wl.sender_account_id = ?";
    $params[] = (int)$filterAccount;
}
if ($filterReceiver) {
    $where[] = "wl.receiver_account_id = ?";
    $params[] = (int)$filterReceiver;
}
if ($filterStatus === 'replied') {
    $where[] = "wl.replied_at IS NOT NULL";
} elseif ($filterStatus === 'spam_saved') {
    $where[] = "wl.spam_saved = 1";
} elseif ($filterStatus === 'pending') {
    $where[] = "wl.replied_at IS NULL AND wl.spam_saved = 0";
}
if ($filterDateFrom) {
    $where[] = "DATE(wl.created_at) >= ?";
    $params[] = $filterDateFrom;
}
if ($filterDateTo) {
    $where[] = "DATE(wl.created_at) <= ?";
    $params[] = $filterDateTo;
}

$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$totalLogs = (int) dbFetchValue("SELECT COUNT(*) FROM warmup_logs wl $whereSQL", $params);
$totalPages = max(1, ceil($totalLogs / $perPage));

// Fetch logs with joins
$logs = dbFetchAll("
    SELECT wl.*,
           s.label AS sender_label, s.from_email AS sender_email_addr,
           r.label AS receiver_label, r.from_email AS receiver_email_addr
    FROM warmup_logs wl
    LEFT JOIN smtp_accounts s ON wl.sender_account_id = s.id
    LEFT JOIN smtp_accounts r ON wl.receiver_account_id = r.id
    $whereSQL
    ORDER BY wl.created_at DESC
    LIMIT $perPage OFFSET $offset
", $params);

// Stats
$totalSent     = (int) dbFetchValue("SELECT COUNT(*) FROM warmup_logs");
$totalReplied  = (int) dbFetchValue("SELECT COUNT(*) FROM warmup_logs WHERE replied_at IS NOT NULL");
$totalSpam     = (int) dbFetchValue("SELECT COUNT(*) FROM warmup_logs WHERE spam_saved = 1");
$replyRate     = $totalSent > 0 ? round(($totalReplied / $totalSent) * 100, 1) : 0;
$spamRate      = $totalSent > 0 ? round(($totalSpam / $totalSent) * 100, 1) : 0;

// Accounts for filter dropdowns
$allAccounts = dbFetchAll("SELECT id, label, from_email, is_seed_account FROM smtp_accounts WHERE is_active = 1 ORDER BY label ASC");

// Per-account daily stats (last 7 days)
$dailyStats = dbFetchAll("
    SELECT DATE(wl.created_at) AS log_date,
           wl.sender_account_id,
           s.from_email AS sender_email_addr,
           COUNT(*) AS total_sent,
           SUM(CASE WHEN wl.replied_at IS NOT NULL THEN 1 ELSE 0 END) AS total_replied,
           SUM(CASE WHEN wl.spam_saved = 1 THEN 1 ELSE 0 END) AS total_spam_saved
    FROM warmup_logs wl
    LEFT JOIN smtp_accounts s ON wl.sender_account_id = s.id
    WHERE wl.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY log_date, wl.sender_account_id
    ORDER BY log_date DESC, sender_email_addr ASC
");

// Per-account warmup progress
$accountProgress = dbFetchAll("
    SELECT sa.id, sa.label, sa.from_email, sa.warmup_status, sa.warmup_current_day,
           sa.warmup_target_daily, sa.sent_today, sa.is_seed_account,
           COUNT(wl.id) AS total_sent,
           SUM(CASE WHEN wl.replied_at IS NOT NULL THEN 1 ELSE 0 END) AS total_replied,
           SUM(CASE WHEN wl.spam_saved = 1 THEN 1 ELSE 0 END) AS total_spam
    FROM smtp_accounts sa
    LEFT JOIN warmup_logs wl ON wl.sender_account_id = sa.id
    WHERE sa.warmup_status IN ('active','completed') AND sa.is_seed_account = 0
    GROUP BY sa.id
    ORDER BY sa.warmup_current_day DESC
");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">📈</span>Warmup Reports</h1>
        <div class="subtitle">Detailed warmup email tracking — every send from every SMTP to every seed, with filters</div>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card"><div class="stat-title">Total Warmup Emails</div><div class="stat-value"><?= number_format($totalSent) ?></div></div>
    <div class="stat-card"><div class="stat-title">Replies Received</div><div class="stat-value"><?= number_format($totalReplied) ?></div><div class="stat-subtitle"><?= $replyRate ?>% reply rate</div></div>
    <div class="stat-card"><div class="stat-title">Spam Rescued</div><div class="stat-value"><?= number_format($totalSpam) ?></div><div class="stat-subtitle"><?= $spamRate ?>% spam rate</div></div>
    <div class="stat-card"><div class="stat-title">Filtered Results</div><div class="stat-value"><?= number_format($totalLogs) ?></div><div class="stat-subtitle">Page <?= $page ?>/<?= $totalPages ?></div></div>
</div>

<!-- Per-Account Progress -->
<?php if (!empty($accountProgress)): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h3>Account Warmup Progress</h3></div>
    <div class="card-body" style="padding:0;">
        <table>
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Status</th>
                    <th>Day</th>
                    <th>Today</th>
                    <th>Total Sent</th>
                    <th>Replies</th>
                    <th>Spam Saved</th>
                    <th>Health</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accountProgress as $ap): ?>
                <tr>
                    <td>
                        <strong><?= e($ap['label']) ?></strong>
                        <div class="text-muted fs-sm"><?= e($ap['from_email']) ?></div>
                    </td>
                    <td>
                        <?php if ($ap['warmup_status'] === 'completed'): ?>
                            <span class="badge badge-success">Completed ✅</span>
                        <?php elseif ($ap['warmup_status'] === 'active'): ?>
                            <span class="badge badge-warning">Day <?= $ap['warmup_current_day'] ?>/30</span>
                        <?php else: ?>
                            <span class="badge badge-muted"><?= ucfirst($ap['warmup_status']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $ap['warmup_current_day'] ?>/30</td>
                    <td><?= $ap['sent_today'] ?>/<?= $ap['warmup_target_daily'] ?></td>
                    <td><?= number_format($ap['total_sent']) ?></td>
                    <td><?= number_format($ap['total_replied']) ?></td>
                    <td>
                        <?php if ($ap['total_spam'] > 0): ?>
                            <span style="color:var(--color-warning);"><?= $ap['total_spam'] ?></span>
                        <?php else: ?>
                            <span style="color:var(--color-success);">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $health = $ap['total_sent'] > 0 ? round((($ap['total_sent'] - $ap['total_spam']) / $ap['total_sent']) * 100) : 100;
                        $hColor = $health >= 90 ? 'var(--color-success)' : ($health >= 70 ? 'var(--color-warning)' : 'var(--color-danger)');
                        ?>
                        <span style="font-weight:700; color:<?= $hColor ?>;"><?= $health ?>%</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Daily Breakdown (Last 7 Days) -->
<?php if (!empty($dailyStats)): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h3>Daily Breakdown (Last 7 Days)</h3></div>
    <div class="card-body" style="padding:0; max-height:350px; overflow-y:auto;">
        <table>
            <thead>
                <tr><th>Date</th><th>Sender</th><th>Sent</th><th>Replied</th><th>Spam Rescued</th></tr>
            </thead>
            <tbody>
                <?php foreach ($dailyStats as $ds): ?>
                <tr>
                    <td><?= date('M j, Y', strtotime($ds['log_date'])) ?></td>
                    <td class="fs-sm"><?= e($ds['sender_email_addr'] ?? '—') ?></td>
                    <td><?= $ds['total_sent'] ?></td>
                    <td><?= $ds['total_replied'] ?></td>
                    <td>
                        <?php if ($ds['total_spam_saved'] > 0): ?>
                            <span style="color:var(--color-warning);"><?= $ds['total_spam_saved'] ?></span>
                        <?php else: ?>
                            0
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h3>🔍 Filter Warmup Logs</h3></div>
    <div class="card-body">
        <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <div class="form-group" style="margin:0; min-width:180px;">
                <label>Sender Account</label>
                <select name="account" class="form-control">
                    <option value="">All Senders</option>
                    <?php foreach ($allAccounts as $acc): ?>
                        <?php if (!$acc['is_seed_account']): ?>
                        <option value="<?= $acc['id'] ?>" <?= $filterAccount == $acc['id'] ? 'selected' : '' ?>>
                            <?= e($acc['label']) ?> (<?= e($acc['from_email']) ?>)
                        </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0; min-width:180px;">
                <label>Receiver / Seed</label>
                <select name="receiver" class="form-control">
                    <option value="">All Receivers</option>
                    <?php foreach ($allAccounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>" <?= $filterReceiver == $acc['id'] ? 'selected' : '' ?>>
                            <?= e($acc['label']) ?> (<?= e($acc['from_email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0; min-width:140px;">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="">All</option>
                    <option value="replied" <?= $filterStatus === 'replied' ? 'selected' : '' ?>>Replied</option>
                    <option value="spam_saved" <?= $filterStatus === 'spam_saved' ? 'selected' : '' ?>>Spam Rescued</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>
            <div class="form-group" style="margin:0; min-width:140px;">
                <label>From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($filterDateFrom) ?>">
            </div>
            <div class="form-group" style="margin:0; min-width:140px;">
                <label>To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($filterDateTo) ?>">
            </div>
            <button class="btn btn-primary" type="submit">Apply</button>
            <a href="?<?= '' ?>" class="btn btn-outline">Reset</a>
        </form>
    </div>
</div>

<!-- Log Table -->
<div class="card">
    <div class="card-header">
        <h3>Warmup Email Log (<?= number_format($totalLogs) ?> results)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($logs)): ?>
            <div style="padding:24px; text-align:center; color:var(--text-muted);">No warmup logs found matching your filters.</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Time</th>
                            <th>From (Sender)</th>
                            <th>To (Receiver/Seed)</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Replied</th>
                            <th>Spam?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $i => $log): ?>
                        <tr>
                            <td class="text-muted fs-sm"><?= $offset + $i + 1 ?></td>
                            <td class="fs-sm" style="white-space:nowrap;"><?= date('M j, g:i A', strtotime($log['created_at'])) ?></td>
                            <td>
                                <strong style="font-size:12px;"><?= e($log['sender_label'] ?? '—') ?></strong>
                                <div class="text-muted" style="font-size:11px;"><?= e($log['sender_email_addr'] ?? $log['sender_email'] ?? '—') ?></div>
                            </td>
                            <td>
                                <strong style="font-size:12px;"><?= e($log['receiver_label'] ?? '—') ?></strong>
                                <div class="text-muted" style="font-size:11px;"><?= e($log['receiver_email_addr'] ?? $log['receiver_email'] ?? '—') ?></div>
                            </td>
                            <td class="text-muted fs-sm" style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= e($log['subject'] ?? '—') ?>
                            </td>
                            <td>
                                <?php if ($log['replied_at']): ?>
                                    <span class="badge badge-success">Replied</span>
                                <?php elseif ($log['spam_saved']): ?>
                                    <span class="badge badge-warning">Rescued</span>
                                <?php else: ?>
                                    <span class="badge badge-muted">Sent</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted fs-sm">
                                <?= $log['replied_at'] ? date('M j, g:i A', strtotime($log['replied_at'])) : '—' ?>
                            </td>
                            <td>
                                <?php if ($log['spam_saved']): ?>
                                    <span style="color:var(--color-warning);" title="Rescued from spam">⚠️ Rescued</span>
                                <?php else: ?>
                                    <span style="color:var(--color-success);">✅</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:12px 16px; display:flex; justify-content:center; gap:6px; border-top:1px solid rgba(255,255,255,0.06);">
        <?php
        // Build query string for pagination
        $queryParts = [];
        if ($filterAccount) $queryParts[] = 'account=' . urlencode($filterAccount);
        if ($filterReceiver) $queryParts[] = 'receiver=' . urlencode($filterReceiver);
        if ($filterStatus) $queryParts[] = 'status=' . urlencode($filterStatus);
        if ($filterDateFrom) $queryParts[] = 'date_from=' . urlencode($filterDateFrom);
        if ($filterDateTo) $queryParts[] = 'date_to=' . urlencode($filterDateTo);
        $queryBase = !empty($queryParts) ? implode('&', $queryParts) . '&' : '';
        ?>

        <?php if ($page > 1): ?>
            <a href="?<?= $queryBase ?>pg=<?= $page - 1 ?>" class="btn btn-outline" style="padding:6px 12px; font-size:13px;">← Prev</a>
        <?php endif; ?>

        <?php
        $startP = max(1, $page - 3);
        $endP = min($totalPages, $page + 3);
        for ($p = $startP; $p <= $endP; $p++):
        ?>
            <a href="?<?= $queryBase ?>pg=<?= $p ?>" class="btn <?= $p === $page ? 'btn-primary' : 'btn-outline' ?>" style="padding:6px 12px; font-size:13px;"><?= $p ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="?<?= $queryBase ?>pg=<?= $page + 1 ?>" class="btn btn-outline" style="padding:6px 12px; font-size:13px;">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
