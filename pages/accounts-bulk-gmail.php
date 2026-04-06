<?php
/**
 * Bulk Gmail Account Import
 */
$pageTitle = 'Bulk Gmail Import';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">📥</span>Bulk Gmail Import</h1>
        <div class="subtitle">Paste rows from Google Sheets: email and app password</div>
    </div>
    <a href="<?= $basePath ?>/pages/accounts.php" class="btn btn-outline">← Back to SMTP Accounts</a>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3>Paste Data</h3>
    </div>
    <div class="card-body">
        <p class="text-muted fs-sm" style="margin-bottom: 12px;">
            Paste two columns from Google Sheets. Supported delimiters: tab (recommended), comma, or semicolon.
        </p>
        <div class="form-group">
            <label>Expected columns: Email, App Password</label>
            <textarea id="bulkInput" class="form-control" rows="10" placeholder="user1@gmail.com	abcd efgh ijkl mnop
user2@gmail.com	qrst uvwx yzab cdef"></textarea>
        </div>

        <div class="form-row">
            <div class="form-group" style="margin: 0;">
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="markAsSeed" style="width: auto;">
                    Mark all imported Gmail accounts as Seed
                </label>
                <div class="form-hint">Enable this if these Gmail accounts should act as warm-up receivers/repliers.</div>
            </div>
        </div>

        <div style="display:flex; gap: 8px; margin-top: 12px;">
            <button class="btn btn-outline" type="button" onclick="previewBulkRows()">Preview Rows</button>
            <button class="btn btn-primary" type="button" id="createBulkBtn" onclick="createBulkAccounts()">Create Accounts</button>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3>Preview</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <div id="previewEmpty" style="padding: 24px; text-align:center; color: var(--text-muted);">
            No rows parsed yet. Paste data and click Preview Rows.
        </div>
        <div id="previewTableWrap" class="table-wrapper" style="display:none;">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Email</th>
                        <th>App Password</th>
                    </tr>
                </thead>
                <tbody id="previewBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Result</h3>
    </div>
    <div class="card-body">
        <div id="resultBox" class="text-muted">No import run yet.</div>
    </div>
</div>

<?php
$pageScript = <<<'JS'
let parsedRows = [];

function parseBulkInput(raw) {
    const lines = raw
        .split(/\r?\n/)
        .map(line => line.trim())
        .filter(Boolean);

    const rows = [];
    for (const line of lines) {
        let parts = [];
        let delimiter = '';
        if (line.includes('\t')) {
            parts = line.split('\t');
            delimiter = '\t';
        } else if (line.includes(',')) {
            parts = line.split(',');
            delimiter = ',';
        } else if (line.includes(';')) {
            parts = line.split(';');
            delimiter = ';';
        } else {
            continue;
        }

        if (parts.length < 2) continue;

        const email = (parts[0] || '').trim();
    const appPassword = parts.slice(1).join(delimiter).trim();
        if (!email || !appPassword) continue;

        rows.push({ email, app_password: appPassword });
    }

    return rows;
}

function previewBulkRows() {
    const raw = document.getElementById('bulkInput').value || '';
    parsedRows = parseBulkInput(raw);

    const previewEmpty = document.getElementById('previewEmpty');
    const previewWrap = document.getElementById('previewTableWrap');
    const previewBody = document.getElementById('previewBody');

    if (!parsedRows.length) {
        previewWrap.style.display = 'none';
        previewEmpty.style.display = 'block';
        previewEmpty.textContent = 'No valid rows found. Make sure each row has email and app password.';
        return;
    }

    previewBody.innerHTML = parsedRows.map((row, idx) => {
        const masked = row.app_password.length > 4
            ? '*'.repeat(Math.max(4, row.app_password.length - 4)) + row.app_password.slice(-4)
            : '****';
        return `<tr><td>${idx + 1}</td><td>${escapeHtml(row.email)}</td><td>${escapeHtml(masked)}</td></tr>`;
    }).join('');

    previewEmpty.style.display = 'none';
    previewWrap.style.display = 'block';
    Toast.success(`Parsed ${parsedRows.length} row(s).`);
}

function escapeHtml(str) {
    return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

async function createBulkAccounts() {
    if (!parsedRows.length) {
        previewBulkRows();
    }

    if (!parsedRows.length) {
        Toast.error('Please paste valid rows first.');
        return;
    }

    const btn = document.getElementById('createBulkBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Creating...';

    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    const resultBox = document.getElementById('resultBox');

    try {
        const result = await apiCall(basePath + '/api/smtp-bulk-gmail-save.php', {
            rows: parsedRows,
            is_seed_account: document.getElementById('markAsSeed').checked ? 1 : 0
        });

        const created = Number(result.created || 0);
        const skipped = Number(result.skipped || 0);
        const failed = Array.isArray(result.errors) ? result.errors.length : 0;

        resultBox.innerHTML = `
            <div style="color: var(--color-success); margin-bottom: 8px;"><strong>Created:</strong> ${created}</div>
            <div style="color: var(--text-secondary); margin-bottom: 8px;"><strong>Skipped:</strong> ${skipped}</div>
            <div style="color: ${failed ? 'var(--color-danger)' : 'var(--text-secondary)'};"><strong>Failed:</strong> ${failed}</div>
            ${failed ? `<div style="margin-top:10px; font-size:12px; color: var(--text-muted); white-space: pre-wrap;">${escapeHtml(result.errors.join('\n'))}</div>` : ''}
        `;

        Toast.success(result.message || 'Bulk import completed.');
    } catch (err) {
        resultBox.innerHTML = `<span style="color: var(--color-danger);">${escapeHtml(err.message || 'Import failed')}</span>`;
        Toast.error(err.message || 'Import failed');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Create Accounts';
    }
}
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
