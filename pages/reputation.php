<?php
/**
 * Domain Reputation & Sender Health
 */
$pageTitle = 'Reputation Manager';
require_once __DIR__ . '/../includes/header.php';

// All SMTP accounts
$accounts = dbFetchAll("SELECT id, label, from_email, warmup_status, is_seed_account FROM smtp_accounts WHERE is_active = 1 ORDER BY label ASC");

// Latest check per domain
$latestChecks = dbFetchAll("
    SELECT rc.* FROM reputation_checks rc
    INNER JOIN (SELECT domain, MAX(id) as max_id FROM reputation_checks GROUP BY domain) latest 
    ON rc.id = latest.max_id
    ORDER BY rc.checked_at DESC
");

// History
$checkHistory = dbFetchAll("SELECT rc.*, s.from_email FROM reputation_checks rc LEFT JOIN smtp_accounts s ON rc.smtp_account_id = s.id ORDER BY rc.checked_at DESC LIMIT 30");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">🏆</span>Reputation Manager</h1>
        <div class="subtitle">SPF, DKIM, DMARC checks, blacklist monitoring, sender health scores</div>
    </div>
</div>

<!-- Run Check Panel -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header"><h3>Run Reputation Check</h3></div>
    <div class="card-body">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div class="form-group" style="margin:0; flex:2; min-width:220px;">
                <label>Select SMTP Account</label>
                <select id="repCheckAccount" class="form-control">
                    <option value="">— Select Account —</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>"><?= e($acc['label']) ?> (<?= e($acc['from_email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" id="runCheckBtn" onclick="runReputationCheck()">🔍 Run Check</button>
            <button class="btn btn-outline" id="runAllBtn" onclick="runAllChecks()">🔍 Check All Accounts</button>
        </div>
    </div>
</div>

<!-- Live Result -->
<div class="card" style="margin-bottom: 20px; display:none;" id="liveResultCard">
    <div class="card-header"><h3>Check Result</h3></div>
    <div class="card-body" id="liveResultBody"></div>
</div>

<!-- Latest Scores per Domain -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header"><h3>Domain Health Scores</h3></div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($latestChecks)): ?>
            <div style="padding:24px; text-align:center; color:var(--text-muted);">No checks run yet. Select an account and run a check above.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Domain</th><th>MX</th><th>SPF</th><th>DKIM</th><th>DMARC</th><th>Blacklists</th><th>Score</th><th>Last Checked</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($latestChecks as $ch): ?>
                    <tr>
                        <td><strong><?= e($ch['domain']) ?></strong></td>
                        <td><?= $ch['mx_valid'] ? '<span style="color:var(--color-success);">✅</span>' : '<span style="color:var(--color-danger);">❌</span>' ?></td>
                        <td><?= statusIcon($ch['spf_status']) ?></td>
                        <td><?= statusIcon($ch['dkim_status']) ?></td>
                        <td><?= statusIcon($ch['dmarc_status']) ?></td>
                        <td>
                            <?php if ($ch['blacklist_count'] > 0): ?>
                                <span style="color:var(--color-danger); font-weight:700;"><?= $ch['blacklist_count'] ?> found</span>
                            <?php else: ?>
                                <span style="color:var(--color-success);">Clean ✅</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $scoreColor = $ch['overall_score'] >= 80 ? 'var(--color-success)' : ($ch['overall_score'] >= 50 ? 'var(--color-warning)' : 'var(--color-danger)');
                            ?>
                            <span style="font-weight:700; color:<?= $scoreColor ?>;"><?= $ch['overall_score'] ?>%</span>
                        </td>
                        <td class="text-muted fs-sm"><?= timeAgo($ch['checked_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Check History -->
<div class="card">
    <div class="card-header"><h3>Check History</h3></div>
    <div class="card-body" style="padding:0; max-height: 400px; overflow-y: auto;">
        <?php if (empty($checkHistory)): ?>
            <div style="padding:24px; text-align:center; color:var(--text-muted);">No history yet.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Domain</th><th>Account</th><th>Score</th><th>Blacklists</th><th>Checked</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($checkHistory as $h): ?>
                    <tr>
                        <td style="font-size:13px;"><?= e($h['domain']) ?></td>
                        <td class="text-muted fs-sm"><?= e($h['from_email'] ?? '—') ?></td>
                        <td>
                            <?php
                            $sc = $h['overall_score'];
                            $col = $sc >= 80 ? 'var(--color-success)' : ($sc >= 50 ? 'var(--color-warning)' : 'var(--color-danger)');
                            ?>
                            <span style="font-weight:600; color:<?= $col ?>;"><?= $sc ?>%</span>
                        </td>
                        <td class="text-muted fs-sm"><?= $h['blacklist_count'] ?></td>
                        <td class="text-muted fs-sm"><?= timeAgo($h['checked_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php
function statusIcon($status) {
    switch ($status) {
        case 'pass': return '<span style="color:var(--color-success);">✅ Pass</span>';
        case 'fail': return '<span style="color:var(--color-danger);">❌ Fail</span>';
        case 'missing': return '<span style="color:var(--color-warning);">⚠️ Missing</span>';
        default: return '<span style="color:var(--text-muted);">—</span>';
    }
}

$pageScript = <<<'JS'
async function runReputationCheck() {
    const accountId = document.getElementById('repCheckAccount').value;
    if (!accountId) { Toast.error('Select an SMTP account first.'); return; }

    const btn = document.getElementById('runCheckBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Checking...';

    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    const card = document.getElementById('liveResultCard');
    const body = document.getElementById('liveResultBody');

    try {
        const r = await apiCall(basePath + '/api/reputation-check.php', { smtp_account_id: parseInt(accountId) });
        card.style.display = 'block';
        const sc = r.overall_score;
        const col = sc >= 80 ? 'var(--color-success)' : (sc >= 50 ? 'var(--color-warning)' : 'var(--color-danger)');

        body.innerHTML = `
            <div style="text-align:center; margin-bottom:16px;">
                <div style="font-size:40px; font-weight:800; color:${col};">${sc}%</div>
                <div class="text-muted">Overall Score for <strong>${esc(r.domain)}</strong></div>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:12px;">
                <div class="stat-card"><div class="stat-title">MX Records</div><div class="stat-value">${r.mx_valid ? '✅ Valid' : '❌ Missing'}</div></div>
                <div class="stat-card"><div class="stat-title">SPF</div><div class="stat-value">${statusLabel(r.spf.status)}</div><div class="stat-subtitle" style="font-size:11px; word-break:break-all;">${esc(r.spf.record || 'Not found')}</div></div>
                <div class="stat-card"><div class="stat-title">DKIM</div><div class="stat-value">${statusLabel(r.dkim.status)}</div><div class="stat-subtitle">Selector: ${esc(r.dkim.selector || 'N/A')}</div></div>
                <div class="stat-card"><div class="stat-title">DMARC</div><div class="stat-value">${statusLabel(r.dmarc.status)}</div><div class="stat-subtitle" style="font-size:11px; word-break:break-all;">${esc(r.dmarc.record || 'Not found')}</div></div>
                <div class="stat-card"><div class="stat-title">Blacklists</div><div class="stat-value" style="color:${r.blacklists.count > 0 ? 'var(--color-danger)' : 'var(--color-success)'};">${r.blacklists.count === 0 ? 'Clean ✅' : r.blacklists.count + ' found'}</div><div class="stat-subtitle">${r.blacklists.count > 0 ? esc(r.blacklists.found.join(', ')) : 'Checked ' + r.blacklists.total_checked + ' lists'}</div></div>
            </div>
        `;
        Toast.success('Reputation check completed!');
    } catch (err) {
        body.innerHTML = `<div style="color:var(--color-danger);">${esc(err.message)}</div>`;
        card.style.display = 'block';
        Toast.error(err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '🔍 Run Check';
    }
}

async function runAllChecks() {
    const select = document.getElementById('repCheckAccount');
    const options = Array.from(select.options).filter(o => o.value);
    if (!options.length) { Toast.error('No accounts available.'); return; }

    const btn = document.getElementById('runAllBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Checking all...';

    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    let completed = 0;

    for (const opt of options) {
        try {
            await apiCall(basePath + '/api/reputation-check.php', { smtp_account_id: parseInt(opt.value) });
            completed++;
        } catch (e) {}
    }

    Toast.success(`Checked ${completed}/${options.length} accounts. Refreshing...`);
    btn.disabled = false;
    btn.innerHTML = '🔍 Check All Accounts';
    setTimeout(() => location.reload(), 1500);
}

function statusLabel(s) {
    if (s === 'pass') return '<span style="color:var(--color-success);">✅ Pass</span>';
    if (s === 'fail') return '<span style="color:var(--color-danger);">❌ Fail</span>';
    if (s === 'missing') return '<span style="color:var(--color-warning);">⚠️ Missing</span>';
    return '—';
}

function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
