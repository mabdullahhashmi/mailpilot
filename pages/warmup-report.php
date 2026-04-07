<?php
/**
 * Premium Warmup Dashboard & Reports
 * Full visibility: schedules, per-email tracking, open/reply/spam status,
 * sender→seed detail, content preview, date/label/account filters.
 */
$pageTitle = 'Warmup Reports';
require_once __DIR__ . '/../includes/header.php';

// ============================================================
// DATA: All SMTP accounts
// ============================================================
$allAccounts = dbFetchAll("SELECT id, label, from_email, is_seed_account, warmup_status, warmup_current_day, warmup_target_daily, sent_today, from_name FROM smtp_accounts WHERE is_active = 1 ORDER BY is_seed_account ASC, label ASC");
$senders  = array_filter($allAccounts, fn($a) => !$a['is_seed_account'] && in_array($a['warmup_status'], ['active','completed']));
$seeds    = array_filter($allAccounts, fn($a) => $a['is_seed_account']);

// ============================================================
// FILTERS
// ============================================================
$fSender   = $_GET['sender'] ?? '';
$fReceiver = $_GET['receiver'] ?? '';
$fStatus   = $_GET['status'] ?? '';
$fDateFrom = $_GET['from'] ?? '';
$fDateTo   = $_GET['to'] ?? '';
$fSearch   = trim($_GET['q'] ?? '');
$page      = max(1, (int)($_GET['pg'] ?? 1));
$perPage   = 30;
$offset    = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($fSender) {
    $where[] = "wl.sender_account_id = ?";
    $params[] = (int)$fSender;
}
if ($fReceiver) {
    $where[] = "wl.receiver_account_id = ?";
    $params[] = (int)$fReceiver;
}
if ($fStatus && $fStatus !== 'all') {
    $where[] = "wl.status = ?";
    $params[] = $fStatus;
}
if ($fDateFrom) {
    $where[] = "DATE(wl.sent_at) >= ?";
    $params[] = $fDateFrom;
}
if ($fDateTo) {
    $where[] = "DATE(wl.sent_at) <= ?";
    $params[] = $fDateTo;
}
if ($fSearch) {
    $where[] = "(wl.subject LIKE ? OR wl.sender_email LIKE ? OR wl.receiver_email LIKE ?)";
    $searchTerm = "%{$fSearch}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$totalLogs  = (int) dbFetchValue("SELECT COUNT(*) FROM warmup_logs wl $whereSQL", $params);
$totalPages = max(1, ceil($totalLogs / $perPage));

$logs = dbFetchAll("SELECT wl.* FROM warmup_logs wl $whereSQL ORDER BY wl.sent_at DESC LIMIT $perPage OFFSET $offset", $params);

// ============================================================
// GLOBAL STATS
// ============================================================
$statsAll = dbFetchOne("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN opened_at IS NOT NULL OR open_count > 0 THEN 1 ELSE 0 END) as ct_opened,
    SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as ct_replied,
    SUM(CASE WHEN spam_saved = 1 THEN 1 ELSE 0 END) as ct_spam
    FROM warmup_logs") ?: ['total'=>0,'ct_opened'=>0,'ct_replied'=>0,'ct_spam'=>0];

$total = max(1, $statsAll['total']);
$openRate  = round(($statsAll['ct_opened'] / $total) * 100, 1);
$replyRate = round(($statsAll['ct_replied'] / $total) * 100, 1);
$spamRate  = round(($statsAll['ct_spam'] / $total) * 100, 1);
$inboxRate = $total > 0 ? round((($total - $statsAll['ct_spam']) / $total) * 100, 1) : 100;

// ============================================================
// ACCOUNT PROGRESS CARDS
// ============================================================
$accountStats = dbFetchAll("
    SELECT sa.id, sa.label, sa.from_email, sa.warmup_status, sa.warmup_current_day,
           sa.warmup_target_daily, sa.sent_today, sa.is_seed_account, sa.from_name,
           COALESCE(ws.total_sent, 0) as total_sent,
           COALESCE(ws.total_opened, 0) as total_opened,
           COALESCE(ws.total_replied, 0) as total_replied,
           COALESCE(ws.total_spam, 0) as total_spam
    FROM smtp_accounts sa
    LEFT JOIN (
        SELECT sender_account_id,
               COUNT(*) as total_sent,
               SUM(CASE WHEN opened_at IS NOT NULL OR open_count > 0 THEN 1 ELSE 0 END) as total_opened,
               SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as total_replied,
               SUM(CASE WHEN spam_saved = 1 THEN 1 ELSE 0 END) as total_spam
        FROM warmup_logs GROUP BY sender_account_id
    ) ws ON ws.sender_account_id = sa.id
    WHERE sa.warmup_status IN ('active','completed') AND sa.is_seed_account = 0
    ORDER BY sa.warmup_status = 'active' DESC, sa.warmup_current_day DESC
");

// ============================================================
// TODAY'S EMAILS
// ============================================================
$todaySent = dbFetchAll("
    SELECT wl.sender_email, wl.sender_label, wl.receiver_email, wl.receiver_label,
           wl.subject, wl.status, wl.sent_at, wl.opened_at, wl.replied_at, wl.spam_saved, wl.open_count
    FROM warmup_logs wl
    WHERE DATE(wl.sent_at) = CURDATE()
    ORDER BY wl.sent_at DESC
");

// Today's full schedule from warmup_schedule table (exact time slots)
$todaySchedule = dbFetchAll("
    SELECT ws.id, ws.scheduled_at, ws.status,
           s.from_email AS sender_email, s.label AS sender_label, s.from_name AS sender_name,
           s.warmup_current_day AS sender_day, s.warmup_target_daily AS sender_target,
           r.from_email AS receiver_email, r.label AS receiver_label, r.from_name AS receiver_name,
           wl.subject, wl.status AS log_status, wl.opened_at, wl.replied_at, wl.open_count, wl.spam_saved
    FROM warmup_schedule ws
    JOIN smtp_accounts s ON ws.sender_account_id = s.id
    JOIN smtp_accounts r ON ws.receiver_account_id = r.id
    LEFT JOIN warmup_logs wl ON ws.warmup_log_id = wl.id
    WHERE DATE(ws.scheduled_at) = CURDATE()
    ORDER BY ws.scheduled_at ASC
") ?: [];

// Per-sender summary from schedule
$schedulePreview = [];
foreach ($senders as $s) {
    if ($s['warmup_status'] !== 'active') continue;
    $remaining = max(0, $s['warmup_target_daily'] - $s['sent_today']);
    $schedulePreview[] = [
        'sender'       => $s['label'] ?: $s['from_email'],
        'sender_email' => $s['from_email'],
        'remaining'    => $remaining,
        'target'       => $s['warmup_target_daily'],
        'sent'         => $s['sent_today'],
        'day'          => $s['warmup_current_day'],
    ];
}

// Daily trend (last 14 days)
$dailyTrend = dbFetchAll("
    SELECT DATE(sent_at) as log_date,
           COUNT(*) as total,
           SUM(CASE WHEN opened_at IS NOT NULL OR open_count > 0 THEN 1 ELSE 0 END) as opened,
           SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied,
           SUM(CASE WHEN spam_saved = 1 THEN 1 ELSE 0 END) as spam
    FROM warmup_logs
    WHERE sent_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY log_date ORDER BY log_date ASC
");

// Sender→Seed pair analysis
$pairStats = dbFetchAll("
    SELECT wl.sender_email, wl.receiver_email,
           COUNT(*) as total,
           SUM(CASE WHEN wl.opened_at IS NOT NULL OR wl.open_count > 0 THEN 1 ELSE 0 END) as opened,
           SUM(CASE WHEN wl.replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied,
           SUM(CASE WHEN wl.spam_saved = 1 THEN 1 ELSE 0 END) as spam
    FROM warmup_logs wl
    GROUP BY wl.sender_email, wl.receiver_email
    ORDER BY total DESC LIMIT 20
");

// ============================================================
// STATUS BADGE HELPER
// ============================================================
function wuStatusBadge($log) {
    $status = $log['status'] ?? 'sent';
    if (!empty($log['replied_at']) && $status !== 'replied') $status = 'replied';
    elseif (!empty($log['opened_at']) && !in_array($status, ['replied'])) $status = 'opened';
    elseif ($log['spam_saved'] && !in_array($status, ['replied','opened'])) $status = 'spam';
    
    $map = [
        'scheduled'  => ['⏳ Scheduled', 'wu-s-sent'],
        'sent'       => ['📤 Sent', 'wu-s-sent'],
        'delivered'  => ['📬 Delivered', 'wu-s-delivered'],
        'opened'     => ['👁 Opened', 'wu-s-opened'],
        'replied'    => ['💬 Replied', 'wu-s-replied'],
        'spam'       => ['⚠️ Spam→Rescued', 'wu-s-spam'],
        'failed'     => ['❌ Failed', 'wu-s-failed'],
    ];
    $m = $map[$status] ?? ['📤 Sent', 'wu-s-sent'];
    return '<span class="wu-s ' . $m[1] . '">' . $m[0] . '</span>';
}
?>

<style>
.wu-s { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.wu-s-sent { background:rgba(59,130,246,0.15); color:#60a5fa; }
.wu-s-delivered { background:rgba(34,197,94,0.12); color:#4ade80; }
.wu-s-opened { background:rgba(251,191,36,0.15); color:#fbbf24; }
.wu-s-replied { background:rgba(34,197,94,0.2); color:#22c55e; }
.wu-s-spam { background:rgba(239,68,68,0.15); color:#f87171; }
.wu-s-failed { background:rgba(107,114,128,0.2); color:#9ca3af; }
.wu-bar { height:6px; border-radius:3px; background:rgba(255,255,255,0.06); overflow:hidden; }
.wu-bar-fill { height:100%; border-radius:3px; transition:width .5s; }
.wu-tabs { display:flex; gap:0; border-bottom:2px solid rgba(255,255,255,0.08); margin-bottom:16px; overflow-x:auto; }
.wu-tab { padding:10px 18px; cursor:pointer; font-size:13px; font-weight:600; color:var(--text-muted); border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .2s; white-space:nowrap; }
.wu-tab:hover { color:var(--text-primary); }
.wu-tab.active { color:var(--color-primary); border-bottom-color:var(--color-primary); }
.wu-panel { display:none; }
.wu-panel.active { display:block; }
.wu-erow { padding:10px 14px; border-bottom:1px solid rgba(255,255,255,0.04); display:grid; grid-template-columns: 36px 1.4fr 1.2fr 1fr 90px 70px 60px; gap:10px; align-items:center; font-size:13px; }
.wu-erow:hover { background:rgba(255,255,255,0.015); }
.wu-ehead { font-weight:700; font-size:10px; text-transform:uppercase; color:var(--text-muted); letter-spacing:.5px; background:rgba(255,255,255,0.025); }
.wu-ehead:hover { background:rgba(255,255,255,0.025); }
.wu-preview-btn { background:none; border:1px solid rgba(255,255,255,0.1); color:var(--text-muted); padding:2px 8px; border-radius:4px; cursor:pointer; font-size:11px; }
.wu-preview-btn:hover { border-color:var(--color-primary); color:var(--color-primary); }
.wu-trend-bar { display:flex; align-items:flex-end; gap:3px; height:80px; }
.wu-trend-col { flex:1; border-radius:2px 2px 0 0; min-width:14px; transition:height .3s; cursor:default; }
.wu-scard { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:10px; padding:14px; }
.wu-pair { display:grid; grid-template-columns:1fr 1fr 55px 55px 55px 55px; gap:6px; padding:8px 14px; border-bottom:1px solid rgba(255,255,255,0.04); font-size:12px; align-items:center; }
@media(max-width:768px) { .wu-erow, .wu-pair { grid-template-columns:1fr; gap:4px; } }
</style>

<!-- ============ HEADER STATS ============ -->
<div class="page-header" style="margin-bottom:14px;">
    <div>
        <h1><span class="header-icon">🔥</span>Warmup Dashboard</h1>
        <div class="subtitle">Real-time warmup analytics — every email tracked from sender to seed</div>
    </div>
</div>

<div style="display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:18px;">
    <div class="stat-card" style="text-align:center;">
        <div class="stat-title">Total Sent</div>
        <div class="stat-value"><?= number_format($statsAll['total']) ?></div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div class="stat-title">Inbox Rate</div>
        <div class="stat-value" style="color:<?= $inboxRate >= 90 ? 'var(--color-success)' : ($inboxRate >= 70 ? 'var(--color-warning)' : 'var(--color-danger)') ?>;"><?= $inboxRate ?>%</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div class="stat-title">Open Rate</div>
        <div class="stat-value" style="color:<?= $openRate >= 50 ? 'var(--color-success)' : 'var(--color-warning)' ?>;"><?= $openRate ?>%</div>
        <div class="stat-subtitle"><?= number_format($statsAll['ct_opened']) ?> opened</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div class="stat-title">Reply Rate</div>
        <div class="stat-value" style="color:<?= $replyRate >= 20 ? 'var(--color-success)' : 'var(--color-warning)' ?>;"><?= $replyRate ?>%</div>
        <div class="stat-subtitle"><?= number_format($statsAll['ct_replied']) ?> replied</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div class="stat-title">Spam Rate</div>
        <div class="stat-value" style="color:<?= $spamRate <= 5 ? 'var(--color-success)' : 'var(--color-danger)' ?>;"><?= $spamRate ?>%</div>
        <div class="stat-subtitle"><?= number_format($statsAll['ct_spam']) ?> rescued</div>
    </div>
</div>

<!-- ============ TABS ============ -->
<div class="wu-tabs">
    <div class="wu-tab active" onclick="switchTab('schedule',this)">📅 Today's Schedule</div>
    <div class="wu-tab" onclick="switchTab('accounts',this)">📊 Account Progress</div>
    <div class="wu-tab" onclick="switchTab('emails',this)">📧 Email Log</div>
    <div class="wu-tab" onclick="switchTab('trends',this)">📈 Trends</div>
    <div class="wu-tab" onclick="switchTab('pairs',this)">🔗 Sender↔Seed</div>
</div>

<!-- ==================== TAB: TODAY'S SCHEDULE ==================== -->
<div class="wu-panel active" id="panel-schedule">
    <?php if (!empty($schedulePreview)): ?>
    <div class="card" style="margin-bottom:14px;">
        <div class="card-header"><h3>⏰ Today's Sender Progress</h3></div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:12px;">
                <?php foreach ($schedulePreview as $sp): ?>
                <div class="wu-scard">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <div>
                            <strong style="font-size:13px;"><?= e($sp['sender']) ?></strong>
                            <div class="text-muted" style="font-size:11px;"><?= e($sp['sender_email']) ?></div>
                        </div>
                        <span class="badge badge-warning" style="font-size:10px;">Day <?= $sp['day'] ?>/30</span>
                    </div>
                    <div class="wu-bar" style="margin-bottom:6px;">
                        <div class="wu-bar-fill" style="width:<?= $sp['target'] > 0 ? min(100, ($sp['sent']/$sp['target'])*100) : 0 ?>%; background:var(--color-primary);"></div>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted);">
                        <span><?= $sp['sent'] ?>/<?= $sp['target'] ?> sent</span>
                        <span style="color:var(--color-warning);"><?= $sp['remaining'] ?> remaining</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Full day schedule with exact times -->
    <div class="card">
        <div class="card-header">
            <h3>📅 Today's Full Schedule (<?= count($todaySchedule) ?> slots)</h3>
            <span class="text-muted" style="font-size:11px;">Sender → Seed · Pre-planned exact send times</span>
        </div>
        <div class="card-body" style="padding:0; overflow-x:auto;">
            <?php if (empty($todaySchedule)): ?>
                <div style="padding:30px; text-align:center; color:var(--text-muted);">
                    <div style="font-size:40px; margin-bottom:8px;">📭</div>
                    <div>No schedule yet for today.</div>
                    <div style="font-size:12px; margin-top:6px;">Run the warmup cron once and it will auto-generate today's exact send slots.</div>
                </div>
            <?php else: ?>
                <div style="display:grid; grid-template-columns:80px 1fr 1fr 1fr 100px 70px; gap:10px; padding:8px 14px; font-weight:700; font-size:10px; text-transform:uppercase; color:var(--text-muted); letter-spacing:.5px; background:rgba(255,255,255,.025);">
                    <div>Time</div><div>Sender</div><div>→ Seed</div><div>Subject</div><div>Status</div><div>Opens</div>
                </div>
                <?php foreach ($todaySchedule as $slot): ?>
                <?php
                    $slotTime  = date('g:i A', strtotime($slot['scheduled_at']));
                    $isPast    = strtotime($slot['scheduled_at']) < time();
                    $slotStatus = $slot['status'];
                    $logStatus  = $slot['log_status'] ?? null;
                    // Determine display status badge
                    if ($slotStatus === 'sent' && $logStatus) {
                        $displayLog = ['status' => $logStatus, 'opened_at' => $slot['opened_at'], 'replied_at' => $slot['replied_at'], 'spam_saved' => $slot['spam_saved'] ?? 0];
                        $badge = wuStatusBadge($displayLog);
                    } elseif ($slotStatus === 'pending' && $isPast) {
                        $badge = '<span class="wu-s" style="background:rgba(239,68,68,.12);color:#f87171;">⏳ Overdue</span>';
                    } elseif ($slotStatus === 'pending') {
                        $badge = '<span class="wu-s" style="background:rgba(99,102,241,.12);color:#818cf8;">🕒 Scheduled</span>';
                    } elseif ($slotStatus === 'failed') {
                        $badge = '<span class="wu-s wu-s-failed">❌ Failed</span>';
                    } else {
                        $badge = '<span class="wu-s wu-s-sent">📤 Sent</span>';
                    }
                ?>
                <div style="display:grid; grid-template-columns:80px 1fr 1fr 1fr 100px 70px; gap:10px; padding:9px 14px; border-bottom:1px solid rgba(255,255,255,.04); font-size:12px; align-items:center; <?= !$isPast && $slotStatus==='pending' ? 'opacity:.7;' : '' ?>">
                    <div style="font-weight:700; color:<?= $isPast ? 'var(--text-primary)' : 'var(--color-primary)' ?>; font-size:11px;"><?= $slotTime ?></div>
                    <div>
                        <div style="font-weight:600;"><?= e($slot['sender_label'] ?: $slot['sender_name']) ?></div>
                        <div class="text-muted" style="font-size:10px;"><?= e($slot['sender_email']) ?></div>
                    </div>
                    <div>
                        <div style="font-weight:600; color:var(--color-primary);"><?= e($slot['receiver_label'] ?: $slot['receiver_name']) ?></div>
                        <div class="text-muted" style="font-size:10px;"><?= e($slot['receiver_email']) ?></div>
                    </div>
                    <div style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--text-muted);" title="<?= e($slot['subject'] ?? '') ?>">
                        <?= $slot['subject'] ? e($slot['subject']) : '—' ?>
                    </div>
                    <div><?= $badge ?></div>
                    <div style="text-align:center;">
                        <?php if (($slot['open_count'] ?? 0) > 0): ?>
                            <span style="color:var(--color-warning); font-weight:700;"><?= $slot['open_count'] ?>x</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================== TAB: ACCOUNT PROGRESS ==================== -->
<div class="wu-panel" id="panel-accounts">
    <?php if (empty($accountStats)): ?>
        <div class="card"><div class="card-body" style="text-align:center; padding:30px; color:var(--text-muted);">No warmup accounts found. Set warmup_status to 'active' on your SMTP accounts.</div></div>
    <?php else: ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(340px,1fr)); gap:14px;">
        <?php foreach ($accountStats as $as): ?>
        <div class="card" style="margin:0;">
            <div class="card-body" style="padding:16px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                    <div>
                        <div style="font-weight:700; font-size:14px;"><?= e($as['label'] ?: $as['from_name']) ?></div>
                        <div class="text-muted" style="font-size:12px;"><?= e($as['from_email']) ?></div>
                    </div>
                    <?php if ($as['warmup_status'] === 'completed'): ?>
                        <span class="badge badge-success">✅ Done</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Day <?= $as['warmup_current_day'] ?>/30</span>
                    <?php endif; ?>
                </div>
                <?php $pct = min(100, ($as['warmup_current_day']/30)*100); ?>
                <div class="wu-bar" style="margin-bottom:12px; height:8px;">
                    <div class="wu-bar-fill" style="width:<?= $pct ?>%; background:linear-gradient(90deg, var(--color-primary), var(--color-success));"></div>
                </div>
                <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:8px; text-align:center;">
                    <div>
                        <div style="font-size:18px; font-weight:700;"><?= number_format($as['total_sent']) ?></div>
                        <div class="text-muted" style="font-size:10px;">Sent</div>
                    </div>
                    <div>
                        <div style="font-size:18px; font-weight:700; color:var(--color-warning);"><?= number_format($as['total_opened']) ?></div>
                        <div class="text-muted" style="font-size:10px;">Opened</div>
                    </div>
                    <div>
                        <div style="font-size:18px; font-weight:700; color:var(--color-success);"><?= number_format($as['total_replied']) ?></div>
                        <div class="text-muted" style="font-size:10px;">Replied</div>
                    </div>
                    <div>
                        <div style="font-size:18px; font-weight:700; color:<?= $as['total_spam'] > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;"><?= number_format($as['total_spam']) ?></div>
                        <div class="text-muted" style="font-size:10px;">Spam</div>
                    </div>
                </div>
                <div style="margin-top:10px; padding-top:10px; border-top:1px solid rgba(255,255,255,0.06); font-size:12px; display:flex; justify-content:space-between; color:var(--text-muted);">
                    <span>Today: <?= $as['sent_today'] ?>/<?= $as['warmup_target_daily'] ?></span>
                    <?php
                    $eOR = $as['total_sent'] > 0 ? round(($as['total_opened']/$as['total_sent'])*100) : 0;
                    $eRR = $as['total_sent'] > 0 ? round(($as['total_replied']/$as['total_sent'])*100) : 0;
                    ?>
                    <span>Open: <?= $eOR ?>% · Reply: <?= $eRR ?>%</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($seeds)): ?>
    <div class="card" style="margin-top:14px;">
        <div class="card-header"><h3>🌱 Seed Accounts (<?= count($seeds) ?>)</h3></div>
        <div class="card-body" style="padding:0;">
            <table>
                <thead><tr><th>Label</th><th>Email</th><th>Role</th></tr></thead>
                <tbody>
                    <?php foreach ($seeds as $sd): ?>
                    <tr>
                        <td style="font-weight:600;"><?= e($sd['label'] ?: $sd['from_name']) ?></td>
                        <td class="text-muted"><?= e($sd['from_email']) ?></td>
                        <td><span class="badge badge-success">Active Seed</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ==================== TAB: EMAIL LOG ==================== -->
<div class="wu-panel" id="panel-emails">
    <div class="card" style="margin-bottom:14px;">
        <div class="card-body" style="padding:12px 16px;">
            <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                <input type="hidden" name="tab" value="emails">
                <div class="form-group" style="margin:0; min-width:155px;">
                    <label style="font-size:11px;">Sender</label>
                    <select name="sender" class="form-control" style="padding:6px 8px; font-size:12px;">
                        <option value="">All Senders</option>
                        <?php foreach ($senders as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $fSender == $s['id'] ? 'selected' : '' ?>><?= e($s['label'] ?: $s['from_email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0; min-width:155px;">
                    <label style="font-size:11px;">Seed</label>
                    <select name="receiver" class="form-control" style="padding:6px 8px; font-size:12px;">
                        <option value="">All Seeds</option>
                        <?php foreach ($seeds as $sd): ?>
                        <option value="<?= $sd['id'] ?>" <?= $fReceiver == $sd['id'] ? 'selected' : '' ?>><?= e($sd['label'] ?: $sd['from_email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0; min-width:110px;">
                    <label style="font-size:11px;">Status</label>
                    <select name="status" class="form-control" style="padding:6px 8px; font-size:12px;">
                        <option value="all">All</option>
                        <option value="sent" <?= $fStatus==='sent'?'selected':'' ?>>Sent</option>
                        <option value="delivered" <?= $fStatus==='delivered'?'selected':'' ?>>Delivered</option>
                        <option value="opened" <?= $fStatus==='opened'?'selected':'' ?>>Opened</option>
                        <option value="replied" <?= $fStatus==='replied'?'selected':'' ?>>Replied</option>
                        <option value="spam" <?= $fStatus==='spam'?'selected':'' ?>>Spam</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0; min-width:125px;">
                    <label style="font-size:11px;">From</label>
                    <input type="date" name="from" class="form-control" style="padding:6px 8px; font-size:12px;" value="<?= e($fDateFrom) ?>">
                </div>
                <div class="form-group" style="margin:0; min-width:125px;">
                    <label style="font-size:11px;">To</label>
                    <input type="date" name="to" class="form-control" style="padding:6px 8px; font-size:12px;" value="<?= e($fDateTo) ?>">
                </div>
                <div class="form-group" style="margin:0; min-width:150px;">
                    <label style="font-size:11px;">Search</label>
                    <input type="text" name="q" class="form-control" style="padding:6px 8px; font-size:12px;" placeholder="Subject / email..." value="<?= e($fSearch) ?>">
                </div>
                <button class="btn btn-primary" style="padding:6px 16px; font-size:12px;">Filter</button>
                <a href="?tab=emails" class="btn btn-outline" style="padding:6px 12px; font-size:12px;">Reset</a>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>📧 Warmup Emails (<?= number_format($totalLogs) ?>)</h3></div>
        <div class="card-body" style="padding:0; overflow-x:auto;">
            <?php if (empty($logs)): ?>
                <div style="padding:30px; text-align:center; color:var(--text-muted);">No emails match your filters.</div>
            <?php else: ?>
                <div class="wu-erow wu-ehead">
                    <div>#</div><div>From → To</div><div>Subject</div><div>Status</div><div>Opens</div><div>Reply</div><div>View</div>
                </div>
                <?php foreach ($logs as $i => $log): ?>
                <div class="wu-erow">
                    <div class="text-muted" style="font-size:11px;"><?= $offset + $i + 1 ?></div>
                    <div>
                        <div style="font-size:12px;">
                            <span style="font-weight:600;"><?= e($log['sender_label'] ?: $log['sender_email'] ?: '—') ?></span>
                            <span class="text-muted" style="font-size:10px;"> → </span>
                            <span style="color:var(--color-primary); font-size:12px;"><?= e($log['receiver_label'] ?: $log['receiver_email'] ?: '—') ?></span>
                        </div>
                        <div class="text-muted" style="font-size:10px;"><?= date('M j, g:i A', strtotime($log['sent_at'])) ?></div>
                    </div>
                    <div style="font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($log['subject'] ?? '') ?>"><?= e($log['subject'] ?? '—') ?></div>
                    <div>
                        <?= wuStatusBadge($log) ?>
                        <?php if (!empty($log['opened_at'])): ?>
                            <div class="text-muted" style="font-size:9px; margin-top:2px;"><?= date('M j g:iA', strtotime($log['opened_at'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:center;">
                        <?php if (($log['open_count'] ?? 0) > 0): ?>
                            <span style="font-weight:700; color:var(--color-warning);"><?= $log['open_count'] ?>x</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:center;">
                        <?php if (!empty($log['replied_at'])): ?>
                            <span style="color:var(--color-success);">✅</span>
                            <div class="text-muted" style="font-size:9px;"><?= date('g:iA', strtotime($log['replied_at'])) ?></div>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if (!empty($log['email_body'])): ?>
                        <button class="wu-preview-btn" onclick="previewEmail(<?= $log['id'] ?>)">👁</button>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:10px;">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div style="padding:10px 14px; display:flex; justify-content:center; gap:4px; border-top:1px solid rgba(255,255,255,0.06);">
            <?php
            $qp = ['tab=emails'];
            if ($fSender) $qp[] = 'sender=' . urlencode($fSender);
            if ($fReceiver) $qp[] = 'receiver=' . urlencode($fReceiver);
            if ($fStatus) $qp[] = 'status=' . urlencode($fStatus);
            if ($fDateFrom) $qp[] = 'from=' . urlencode($fDateFrom);
            if ($fDateTo) $qp[] = 'to=' . urlencode($fDateTo);
            if ($fSearch) $qp[] = 'q=' . urlencode($fSearch);
            $qBase = implode('&', $qp) . '&';
            ?>
            <?php if ($page > 1): ?><a href="?<?= $qBase ?>pg=<?= $page-1 ?>" class="btn btn-outline" style="padding:4px 10px; font-size:12px;">←</a><?php endif; ?>
            <?php for ($p = max(1,$page-3); $p <= min($totalPages,$page+3); $p++): ?>
                <a href="?<?= $qBase ?>pg=<?= $p ?>" class="btn <?= $p===$page?'btn-primary':'btn-outline' ?>" style="padding:4px 10px; font-size:12px;"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?><a href="?<?= $qBase ?>pg=<?= $page+1 ?>" class="btn btn-outline" style="padding:4px 10px; font-size:12px;">→</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==================== TAB: TRENDS ==================== -->
<div class="wu-panel" id="panel-trends">
    <div class="card">
        <div class="card-header"><h3>📊 14-Day Trend</h3></div>
        <div class="card-body">
            <?php if (empty($dailyTrend)): ?>
                <div style="text-align:center; color:var(--text-muted); padding:20px;">No data yet. Trends appear after warmup emails are sent.</div>
            <?php else: ?>
                <?php $maxDay = max(array_column($dailyTrend, 'total') ?: [1]); ?>
                <div class="wu-trend-bar">
                    <?php foreach ($dailyTrend as $dt): ?>
                    <?php $h = max(5, ($dt['total']/$maxDay)*100); ?>
                    <div class="wu-trend-col" style="height:<?= $h ?>%; background:linear-gradient(to top, var(--color-primary), rgba(99,102,241,.4));" 
                         title="<?= date('M j', strtotime($dt['log_date'])) ?>: <?= $dt['total'] ?> sent, <?= $dt['opened'] ?> opened, <?= $dt['replied'] ?> replied">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-muted); margin-top:6px;">
                    <span><?= date('M j', strtotime($dailyTrend[0]['log_date'])) ?></span>
                    <span><?= date('M j', strtotime(end($dailyTrend)['log_date'])) ?></span>
                </div>
                <table style="margin-top:14px;">
                    <thead><tr><th>Date</th><th>Sent</th><th>Opened</th><th>Replied</th><th>Spam</th><th>Open%</th><th>Reply%</th></tr></thead>
                    <tbody>
                        <?php foreach (array_reverse($dailyTrend) as $dt): ?>
                        <tr>
                            <td style="font-size:12px;"><?= date('M j, Y', strtotime($dt['log_date'])) ?></td>
                            <td style="font-weight:600;"><?= $dt['total'] ?></td>
                            <td style="color:var(--color-warning);"><?= $dt['opened'] ?></td>
                            <td style="color:var(--color-success);"><?= $dt['replied'] ?></td>
                            <td style="color:<?= $dt['spam'] > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;"><?= $dt['spam'] ?></td>
                            <td class="text-muted"><?= $dt['total'] > 0 ? round(($dt['opened']/$dt['total'])*100) : 0 ?>%</td>
                            <td class="text-muted"><?= $dt['total'] > 0 ? round(($dt['replied']/$dt['total'])*100) : 0 ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================== TAB: SENDER↔SEED ==================== -->
<div class="wu-panel" id="panel-pairs">
    <div class="card">
        <div class="card-header"><h3>🔗 Sender → Seed Pair Analysis</h3></div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($pairStats)): ?>
                <div style="padding:24px; text-align:center; color:var(--text-muted);">No pair data yet.</div>
            <?php else: ?>
                <div class="wu-pair wu-ehead">
                    <div>Sender</div><div>Seed</div><div>Sent</div><div>Opened</div><div>Replied</div><div>Spam</div>
                </div>
                <?php foreach ($pairStats as $pr): ?>
                <div class="wu-pair">
                    <div style="font-weight:600; overflow:hidden; text-overflow:ellipsis;"><?= e($pr['sender_email']) ?></div>
                    <div style="color:var(--color-primary); overflow:hidden; text-overflow:ellipsis;"><?= e($pr['receiver_email']) ?></div>
                    <div style="font-weight:600;"><?= $pr['total'] ?></div>
                    <div style="color:var(--color-warning);"><?= $pr['opened'] ?></div>
                    <div style="color:var(--color-success);"><?= $pr['replied'] ?></div>
                    <div style="color:<?= $pr['spam'] > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;"><?= $pr['spam'] ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Email Preview Modal -->
<div id="emailPreviewModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:9999; background:rgba(0,0,0,.7); align-items:center; justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:var(--bg-card); border-radius:12px; max-width:600px; width:90%; max-height:80vh; overflow-y:auto; border:1px solid rgba(255,255,255,.1);">
        <div style="padding:14px 18px; border-bottom:1px solid rgba(255,255,255,.06); display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:15px;">📧 Email Preview</h3>
            <button onclick="document.getElementById('emailPreviewModal').style.display='none'" style="background:none; border:none; color:var(--text-muted); font-size:20px; cursor:pointer;">✕</button>
        </div>
        <div id="emailPreviewContent" style="padding:18px;"></div>
    </div>
</div>

<?php
// Prepare JS data for preview
$logsJson = [];
foreach ($logs as $l) {
    $logsJson[$l['id']] = [
        'subject' => $l['subject'] ?? '',
        'body' => $l['email_body'] ?? '',
        'sender' => $l['sender_email'] ?? '',
        'sender_label' => $l['sender_label'] ?? '',
        'receiver' => $l['receiver_email'] ?? '',
        'receiver_label' => $l['receiver_label'] ?? '',
        'sent_at' => $l['sent_at'],
        'opened_at' => $l['opened_at'] ?? null,
        'replied_at' => $l['replied_at'] ?? null,
        'open_count' => $l['open_count'] ?? 0,
    ];
}

$pageScript = "var _wuData = " . json_encode($logsJson, JSON_HEX_TAG) . ";\n";
$pageScript .= <<<'JS'
function switchTab(name, el) {
    document.querySelectorAll('.wu-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.wu-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + name).classList.add('active');
    el.classList.add('active');
    const url = new URL(window.location);
    url.searchParams.set('tab', name);
    history.replaceState(null, '', url);
}

// Restore tab
(function() {
    const tab = new URLSearchParams(location.search).get('tab');
    if (tab && document.getElementById('panel-' + tab)) {
        document.querySelectorAll('.wu-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.wu-panel').forEach(p => p.classList.remove('active'));
        document.getElementById('panel-' + tab).classList.add('active');
        document.querySelectorAll('.wu-tab').forEach(t => {
            if (t.onclick && t.onclick.toString().includes("'" + tab + "'")) t.classList.add('active');
        });
    }
})();

function previewEmail(id) {
    const d = _wuData[id];
    if (!d) return;
    const modal = document.getElementById('emailPreviewModal');
    document.getElementById('emailPreviewContent').innerHTML = `
        <div style="margin-bottom:12px;">
            <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase;">Subject</div>
            <div style="font-size:15px; font-weight:600;">${E(d.subject)}</div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px;">
            <div>
                <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase;">From</div>
                <div style="font-size:13px; font-weight:600;">${E(d.sender_label || d.sender)}</div>
                <div style="font-size:11px; color:var(--text-muted);">${E(d.sender)}</div>
            </div>
            <div>
                <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase;">To (Seed)</div>
                <div style="font-size:13px; font-weight:600; color:var(--color-primary);">${E(d.receiver_label || d.receiver)}</div>
                <div style="font-size:11px; color:var(--text-muted);">${E(d.receiver)}</div>
            </div>
        </div>
        <div style="display:flex; gap:12px; margin-bottom:14px; font-size:11px;">
            <span class="text-muted">📤 Sent: ${E(d.sent_at)}</span>
            ${d.opened_at ? '<span style="color:var(--color-warning);">👁 Opened: ' + E(d.opened_at) + ' (' + d.open_count + 'x)</span>' : '<span class="text-muted">👁 Not opened</span>'}
            ${d.replied_at ? '<span style="color:var(--color-success);">💬 Replied: ' + E(d.replied_at) + '</span>' : '<span class="text-muted">💬 No reply</span>'}
        </div>
        <div style="background:rgba(255,255,255,.03); border-radius:8px; padding:14px; border:1px solid rgba(255,255,255,.06);">
            <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">Email Body</div>
            <div style="font-size:13px; line-height:1.7;">${d.body || '<span style="color:var(--text-muted);">Body not available (older emails)</span>'}</div>
        </div>
    `;
    modal.style.display = 'flex';
}

function E(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
