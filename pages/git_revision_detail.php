<?php
/**
 * Git Revision Detail page
 * Shows details of a single commit including notes and changed files
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if GitHub is configured
if (!isGitHubConfigured()) {
    ?>
    <h1 class="page-title">Revision Detail</h1>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            GitHub integration is not configured. Please configure it in the installation settings.
        </p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Get SHA from query string
$sha = $_GET['sha'] ?? '';
if (empty($sha)) {
    ?>
    <h1 class="page-title">Revision Detail</h1>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            No revision specified. <a href="?page=git_revisions">Browse all revisions</a>
        </p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Get revision data
$revision = getRevisionBySha($sha);

if (!$revision) {
    ?>
    <h1 class="page-title">Revision Detail</h1>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            Revision not found: <code><?= e($sha) ?></code>
            <br><br>
            <a href="?page=git_revisions">Browse all revisions</a>
        </p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$shortSha = substr($sha, 0, 8);
$dateTime = formatRevisionDateTime($revision['ts'] ?? 0);
$notes = $revision['notes'] ?? '';
$files = $revision['files'] ?? ['added' => [], 'removed' => [], 'modified' => []];
$addedCount = count($files['added'] ?? []);
$removedCount = count($files['removed'] ?? []);
$modifiedCount = count($files['modified'] ?? []);
$totalFiles = $addedCount + $removedCount + $modifiedCount;
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 class="page-title" style="margin: 0;">Revision: <?= e($shortSha) ?></h1>
    <a href="?page=git_revisions" class="btn btn-secondary">&larr; Back to Revisions</a>
</div>

<!-- Revision Info -->
<div class="card" style="margin-bottom: 20px;">
    <div class="revision-header">
        <div class="revision-meta">
            <div class="meta-row">
                <span class="meta-label">Full SHA:</span>
                <code class="sha-full"><?= e($sha) ?></code>
                <button class="btn btn-sm copy-btn" onclick="copyToClipboard('<?= e($sha) ?>')" title="Copy SHA">Copy</button>
            </div>
            <div class="meta-row">
                <span class="meta-label">Date & Time:</span>
                <span class="meta-value"><?= e($dateTime) ?></span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Files Changed:</span>
                <span class="meta-value">
                    <?php if ($totalFiles > 0): ?>
                        <span class="file-badge added">+<?= $addedCount ?></span>
                        <span class="file-badge removed">-<?= $removedCount ?></span>
                        <span class="file-badge modified">~<?= $modifiedCount ?></span>
                    <?php else: ?>
                        <span class="no-files">No file details available</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Commit Message -->
<div class="card" style="margin-bottom: 20px;">
    <h3 class="card-title">Commit Message</h3>
    <pre class="commit-notes"><?= e($notes ?: 'No commit message') ?></pre>
</div>

<!-- Changed Files -->
<?php if ($totalFiles > 0): ?>
    <div class="card">
        <h3 class="card-title">Changed Files (<?= $totalFiles ?>)</h3>

        <?php if (!empty($files['added'])): ?>
            <div class="file-section">
                <h4 class="file-section-title added">
                    <span class="icon">+</span> Added Files (<?= count($files['added']) ?>)
                </h4>
                <ul class="file-list">
                    <?php foreach ($files['added'] as $file): ?>
                        <li class="file-item added"><?= e($file) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($files['removed'])): ?>
            <div class="file-section">
                <h4 class="file-section-title removed">
                    <span class="icon">-</span> Removed Files (<?= count($files['removed']) ?>)
                </h4>
                <ul class="file-list">
                    <?php foreach ($files['removed'] as $file): ?>
                        <li class="file-item removed"><?= e($file) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($files['modified'])): ?>
            <div class="file-section">
                <h4 class="file-section-title modified">
                    <span class="icon">~</span> Modified Files (<?= count($files['modified']) ?>)
                </h4>
                <ul class="file-list">
                    <?php foreach ($files['modified'] as $file): ?>
                        <li class="file-item modified"><?= e($file) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 20px;">
            File change details are not available for this revision.
            <br><small>Only the most recent revisions have file details cached.</small>
        </p>
    </div>
<?php endif; ?>

<!-- Link to test results for this commit -->
<div class="card" style="margin-top: 20px;">
    <div style="display: flex; gap: 10px; justify-content: center;">
        <a href="?page=results&commit=<?= urlencode($sha) ?>" class="btn btn-primary">View Test Results for this Revision</a>
        <a href="?page=reports&commit=<?= urlencode($sha) ?>" class="btn btn-secondary">View Reports for this Revision</a>
    </div>
</div>

<style>
/* Revision header */
.revision-header {
    padding: 10px 0;
}
.revision-meta {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.meta-row {
    display: flex;
    align-items: center;
    gap: 12px;
}
.meta-label {
    color: var(--text-muted);
    min-width: 120px;
    font-weight: 500;
}
.meta-value {
    color: var(--text);
}
.sha-full {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 13px;
    background: var(--bg-accent);
    padding: 4px 8px;
    border-radius: 4px;
    word-break: break-all;
}
.copy-btn {
    padding: 4px 8px !important;
    font-size: 11px !important;
}

/* File badges */
.file-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    margin-right: 5px;
}
.file-badge.added {
    background: rgba(39, 174, 96, 0.2);
    color: #27ae60;
}
.file-badge.removed {
    background: rgba(231, 76, 60, 0.2);
    color: #e74c3c;
}
.file-badge.modified {
    background: rgba(241, 196, 15, 0.2);
    color: #f1c40f;
}
.no-files {
    color: var(--text-muted);
    font-style: italic;
}

/* Card title */
.card-title {
    margin: 0 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-size: 16px;
}

/* Commit notes */
.commit-notes {
    background: var(--bg-accent);
    padding: 15px;
    border-radius: 6px;
    white-space: pre-wrap;
    word-wrap: break-word;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 13px;
    line-height: 1.5;
    margin: 0;
    max-height: 300px;
    overflow-y: auto;
}

/* File sections */
.file-section {
    margin-bottom: 20px;
}
.file-section:last-child {
    margin-bottom: 0;
}
.file-section-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
}
.file-section-title .icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 4px;
    font-weight: bold;
}
.file-section-title.added { color: #27ae60; }
.file-section-title.added .icon { background: rgba(39, 174, 96, 0.2); }
.file-section-title.removed { color: #e74c3c; }
.file-section-title.removed .icon { background: rgba(231, 76, 60, 0.2); }
.file-section-title.modified { color: #f1c40f; }
.file-section-title.modified .icon { background: rgba(241, 196, 15, 0.2); }

/* File list */
.file-list {
    list-style: none;
    margin: 0;
    padding: 0;
    background: var(--bg-accent);
    border-radius: 6px;
    max-height: 300px;
    overflow-y: auto;
}
.file-item {
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.file-item:last-child {
    border-bottom: none;
}
.file-item::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.file-item.added::before { background: #27ae60; }
.file-item.removed::before { background: #e74c3c; }
.file-item.modified::before { background: #f1c40f; }

/* Buttons */
.btn {
    display: inline-block;
    padding: 10px 20px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    cursor: pointer;
    border: none;
    font-size: 14px;
    transition: all 0.2s;
}
.btn-primary {
    background: var(--primary);
    color: #fff;
}
.btn-primary:hover {
    background: var(--primary-dark, #5a9a2a);
}
.btn-secondary {
    background: var(--bg-accent);
    color: var(--text);
}
.btn-secondary:hover {
    background: var(--border);
}
.btn-sm {
    padding: 4px 10px;
    font-size: 12px;
}
</style>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Show brief feedback
        var btn = document.querySelector('.copy-btn');
        var originalText = btn.textContent;
        btn.textContent = 'Copied!';
        btn.style.background = 'var(--status-working)';
        btn.style.color = '#fff';
        setTimeout(function() {
            btn.textContent = originalText;
            btn.style.background = '';
            btn.style.color = '';
        }, 1500);
    }).catch(function(err) {
        console.error('Failed to copy:', err);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
