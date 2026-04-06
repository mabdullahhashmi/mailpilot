<?php
/**
 * Pre-Send Spam Analyzer
 * Analyze email content for spam risk before sending campaigns.
 */
$pageTitle = 'Spam Analyzer';
require_once __DIR__ . '/../includes/header.php';

// Load SMTP accounts for sender dropdown
$accounts = dbFetchAll("SELECT id, label, from_email FROM smtp_accounts WHERE is_active = 1 ORDER BY label ASC");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">🛡️</span>Pre-Send Spam Analyzer</h1>
        <div class="subtitle">Check your email content for spam triggers before sending — catch problems early</div>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
    <!-- Left: Input -->
    <div class="card">
        <div class="card-header"><h3>Email Content</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label>From (optional — enables domain authentication check)</label>
                <select id="spamFromAccount" class="form-control">
                    <option value="">— Select Sending Account —</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= e($acc['from_email']) ?>"><?= e($acc['label']) ?> (<?= e($acc['from_email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Subject Line</label>
                <input type="text" id="spamSubject" class="form-control" placeholder="Enter your email subject line...">
            </div>

            <div class="form-group">
                <label>Email Body (HTML or plain text)</label>
                <textarea id="spamBody" class="form-control" rows="14" placeholder="Paste your email body here..."></textarea>
            </div>

            <button class="btn btn-primary" id="analyzeBtn" onclick="analyzeSpam()" style="width:100%;">🔍 Analyze for Spam Risk</button>
        </div>
    </div>

    <!-- Right: Results -->
    <div>
        <!-- Score Card -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-body" id="scoreArea" style="text-align: center; padding: 30px;">
                <div style="color:var(--text-muted); font-size:14px;">Enter your email content and click Analyze</div>
            </div>
        </div>

        <!-- Issues List -->
        <div class="card">
            <div class="card-header"><h3>Issues Found</h3></div>
            <div class="card-body" id="issuesArea" style="padding:0;">
                <div style="padding:20px; text-align:center; color:var(--text-muted);">Results will appear here after analysis</div>
            </div>
        </div>
    </div>
</div>

<!-- Tips Section -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header"><h3>💡 Deliverability Best Practices</h3></div>
    <div class="card-body">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
            <div style="background:rgba(255,255,255,0.03); padding:14px; border-radius:8px; border-left:3px solid var(--color-success);">
                <strong style="color:var(--color-success);">✅ Subject Lines</strong>
                <ul style="margin:8px 0 0 16px; color:var(--text-muted); font-size:13px; line-height:1.8;">
                    <li>Keep under 60 characters</li>
                    <li>Avoid ALL CAPS and excessive punctuation</li>
                    <li>Don't use fake Re:/Fwd: prefixes</li>
                    <li>Be specific, not clickbaity</li>
                </ul>
            </div>
            <div style="background:rgba(255,255,255,0.03); padding:14px; border-radius:8px; border-left:3px solid var(--color-primary);">
                <strong style="color:var(--color-primary);">📝 Body Content</strong>
                <ul style="margin:8px 0 0 16px; color:var(--text-muted); font-size:13px; line-height:1.8;">
                    <li>Good text-to-image ratio (mostly text)</li>
                    <li>Use full URLs, never URL shorteners</li>
                    <li>Limit links to 2-3 maximum</li>
                    <li>Avoid spam trigger words</li>
                </ul>
            </div>
            <div style="background:rgba(255,255,255,0.03); padding:14px; border-radius:8px; border-left:3px solid var(--color-warning);">
                <strong style="color:var(--color-warning);">⚖️ Compliance</strong>
                <ul style="margin:8px 0 0 16px; color:var(--text-muted); font-size:13px; line-height:1.8;">
                    <li>Always include unsubscribe link</li>
                    <li>Add physical mailing address</li>
                    <li>Set up SPF, DKIM, DMARC records</li>
                    <li>Use custom domain (not Gmail/Yahoo)</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'JS'
async function analyzeSpam() {
    const subject = document.getElementById('spamSubject').value;
    const body = document.getElementById('spamBody').value;
    const fromEmail = document.getElementById('spamFromAccount').value;

    if (!subject && !body) {
        Toast.error('Enter a subject or body to analyze.');
        return;
    }

    const btn = document.getElementById('analyzeBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Analyzing...';

    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    const scoreArea = document.getElementById('scoreArea');
    const issuesArea = document.getElementById('issuesArea');

    try {
        const r = await apiCall(basePath + '/api/spam-analyze.php', {
            subject: subject,
            body: body,
            from_email: fromEmail
        });

        // Score Display
        let scoreColor, scoreEmoji, scoreLabel;
        if (r.score >= 80) { scoreColor = 'var(--color-success)'; scoreEmoji = '🟢'; scoreLabel = 'LOW RISK'; }
        else if (r.score >= 60) { scoreColor = 'var(--color-warning)'; scoreEmoji = '🟡'; scoreLabel = 'MEDIUM RISK'; }
        else if (r.score >= 40) { scoreColor = 'orange'; scoreEmoji = '🟠'; scoreLabel = 'HIGH RISK'; }
        else { scoreColor = 'var(--color-danger)'; scoreEmoji = '🔴'; scoreLabel = 'CRITICAL RISK'; }

        scoreArea.innerHTML = `
            <div style="font-size:56px; font-weight:800; color:${scoreColor}; line-height:1;">${r.score}%</div>
            <div style="font-size:18px; font-weight:600; color:${scoreColor}; margin:8px 0;">${scoreEmoji} ${scoreLabel}</div>
            <div style="display:flex; justify-content:center; gap:16px; margin-top:12px;">
                <div style="text-align:center;">
                    <div style="font-size:20px; font-weight:700; color:var(--color-danger);">${r.summary.high}</div>
                    <div style="font-size:11px; color:var(--text-muted);">High</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:20px; font-weight:700; color:var(--color-warning);">${r.summary.medium}</div>
                    <div style="font-size:11px; color:var(--text-muted);">Medium</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:20px; font-weight:700; color:var(--text-muted);">${r.summary.low}</div>
                    <div style="font-size:11px; color:var(--text-muted);">Low</div>
                </div>
            </div>
        `;

        // Issues List
        if (r.issues.length === 0) {
            issuesArea.innerHTML = '<div style="padding:24px; text-align:center; color:var(--color-success); font-size:15px;">✅ No spam issues found! Your email looks clean.</div>';
        } else {
            let html = '<div style="max-height:400px; overflow-y:auto;">';
            r.issues.forEach(issue => {
                let icon, color;
                if (issue.severity === 'high') { icon = '🔴'; color = 'var(--color-danger)'; }
                else if (issue.severity === 'medium') { icon = '🟡'; color = 'var(--color-warning)'; }
                else { icon = '⚪'; color = 'var(--text-muted)'; }

                html += `<div style="padding:10px 16px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; align-items:flex-start; gap:10px;">
                    <span style="flex-shrink:0;">${icon}</span>
                    <div>
                        <span style="background:rgba(255,255,255,0.06); font-size:10px; padding:2px 6px; border-radius:4px; color:${color}; font-weight:600; text-transform:uppercase;">${esc(issue.category)}</span>
                        <div style="margin-top:4px; font-size:13px; color:var(--text-primary);">${esc(issue.message)}</div>
                    </div>
                </div>`;
            });
            html += '</div>';
            issuesArea.innerHTML = html;
        }

        Toast.success('Analysis complete!');
    } catch (err) {
        scoreArea.innerHTML = `<div style="color:var(--color-danger);">${esc(err.message)}</div>`;
        Toast.error(err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '🔍 Analyze for Spam Risk';
    }
}

function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
