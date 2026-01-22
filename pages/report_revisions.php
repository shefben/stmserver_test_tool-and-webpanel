<?php
/**
 * Report Revision History Page
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/test_keys.php';

$db = Database::getInstance();
$testKeys = getTestKeys();

// Get report ID
$reportId = (int)($_GET['id'] ?? 0);

if (!$reportId) {
    header('Location: ?page=reports');
    exit;
}

$report = $db->getReport($reportId);
if (!$report) {
    header('Location: ?page=reports');
    exit;
}

// Handle restore action (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'restore') {
        $revisionId = (int)($_POST['revision_id'] ?? 0);
        if ($revisionId && $db->restoreRevision($revisionId)) {
            setFlash('success', 'Revision restored successfully.');
        } else {
            setFlash('error', 'Failed to restore revision.');
        }
        header('Location: ?page=report_revisions&id=' . $reportId);
        exit;
    }
}

// Get revisions
$revisions = $db->getReportRevisions($reportId);
?>

<div class="report-header">
    <div>
        <h1 class="page-title">Revision History</h1>
        <p style="color: var(--text-muted);">
            Report #<?= $reportId ?> - <?= e($report['client_version']) ?>
            by <?= e($report['tester']) ?>
        </p>
    </div>
    <div>
        <a href="?page=report_detail&id=<?= $reportId ?>" class="btn btn-secondary">&larr; Back to Report</a>
    </div>
</div>

<?= renderFlash() ?>

<!-- All Revisions Table -->
<div class="card">
    <h3 class="card-title">All Revisions</h3>
    <div class="table-container">
        <table class="revisions-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Client Version</th>
                    <th>Submission Date</th>
                    <th>Rev #</th>
                    <th>Type</th>
                    <th>Tester</th>
                    <th>Commit</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Current (latest) revision - this is the active report
                $currentRevNumber = ($report['revision_count'] ?? 0);
                ?>
                <tr class="current-revision-row">
                    <td>
                        <a href="?page=report_detail&id=<?= $report['id'] ?>" class="report-id-link">
                            #<?= $report['id'] ?>
                        </a>
                        <span class="revision-badge current">Latest</span>
                    </td>
                    <td><?= e($report['client_version']) ?></td>
                    <td><?= formatDate($report['submitted_at']) ?></td>
                    <td class="revision-number"><?= $currentRevNumber ?></td>
                    <td>
                        <span class="status-badge" style="background: <?= $report['test_type'] === 'WAN' ? '#3498db' : '#9b59b6' ?>">
                            <?= e($report['test_type']) ?>
                        </span>
                    </td>
                    <td><?= e($report['tester']) ?></td>
                    <td>
                        <?php if (!empty($report['commit_hash'])): ?>
                            <code><?= e($report['commit_hash']) ?></code>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="actions-cell">
                            <a href="?page=report_detail&id=<?= $report['id'] ?>" class="btn btn-sm btn-secondary">View</a>
                            <?php if (canEditReport($report['id'])): ?>
                                <a href="?page=edit_report&id=<?= $report['id'] ?>" class="btn btn-sm">Edit</a>
                            <?php endif; ?>
                            <a href="?page=retest_report&id=<?= $report['id'] ?>" class="btn btn-sm btn-secondary" title="Request retest">Retest</a>
                        </div>
                    </td>
                </tr>

                <?php if (!empty($revisions)): ?>
                    <?php foreach ($revisions as $index => $revision): ?>
                        <?php
                        $revNumber = $revision['revision_number'] ?? (count($revisions) - $index - 1);
                        ?>
                        <tr class="archived-revision-row">
                            <td>
                                <span style="color: var(--text-muted);">#<?= $revision['id'] ?></span>
                                <span class="revision-badge archived">Archived</span>
                            </td>
                            <td><?= e($revision['client_version']) ?></td>
                            <td><?= formatDate($revision['submitted_at']) ?></td>
                            <td class="revision-number"><?= $revNumber ?></td>
                            <td>
                                <span class="status-badge" style="background: <?= ($revision['test_type'] ?? '') === 'WAN' ? '#3498db' : '#9b59b6' ?>">
                                    <?= e($revision['test_type'] ?? '-') ?>
                                </span>
                            </td>
                            <td><?= e($revision['tester'] ?? $report['tester']) ?></td>
                            <td>
                                <?php if (!empty($revision['commit_hash'])): ?>
                                    <code><?= e($revision['commit_hash']) ?></code>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions-cell">
                                    <button type="button" class="btn btn-sm btn-secondary"
                                            onclick="toggleRevisionDetails(<?= $revision['id'] ?>)">
                                        View
                                    </button>
                                    <?php if (isAdmin()): ?>
                                        <form method="POST" style="display: inline;"
                                              onsubmit="return confirm('Restore this revision? The current version will be archived.');">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="revision_id" value="<?= $revision['id'] ?>">
                                            <button type="submit" class="btn btn-sm" title="Restore this revision">Restore</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <!-- Expandable details row -->
                        <tr class="revision-details-row" id="revision-<?= $revision['id'] ?>" style="display: none;">
                            <td colspan="8">
                                <div class="revision-details-content">
                                    <div class="revision-meta-inline">
                                        <?php if (!empty($revision['steamui_version'])): ?>
                                            <span><strong>SteamUI:</strong> <?= e($revision['steamui_version']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($revision['steam_pkg_version'])): ?>
                                            <span><strong>Steam PKG:</strong> <?= e($revision['steam_pkg_version']) ?></span>
                                        <?php endif; ?>
                                        <span><strong>Archived:</strong> <?= formatRelativeTime($revision['archived_at']) ?></span>
                                        <span><strong>Tests:</strong> <?= count($revision['test_results']) ?> results</span>
                                    </div>

                                    <?php
                                    $changesDiff = null;
                                    if (!empty($revision['changes_diff'])) {
                                        $changesDiff = is_string($revision['changes_diff']) ? json_decode($revision['changes_diff'], true) : $revision['changes_diff'];
                                    }
                                    ?>
                                    <?php if (!empty($changesDiff) && !empty($changesDiff['tests'])): ?>
                                        <div class="changes-summary">
                                            <strong>Changes when this revision was archived:</strong>
                                            <div class="changes-list">
                                                <?php foreach ($changesDiff['tests'] as $testKey => $change): ?>
                                                    <div class="change-item">
                                                        <span class="change-test"><?= e($testKey) ?></span>
                                                        <?php if (isset($change['status'])): ?>
                                                            <span class="change-arrow">&rarr;</span>
                                                            <?= getStatusBadge($change['status']['from']) ?>
                                                            <span class="change-arrow">&rarr;</span>
                                                            <?= getStatusBadge($change['status']['to']) ?>
                                                        <?php endif; ?>
                                                        <?php if (isset($change['notes'])): ?>
                                                            <span class="change-notes-badge" title="Notes changed">notes changed</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <table class="revision-table">
                                        <thead>
                                            <tr>
                                                <th>Test</th>
                                                <th>Status</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($revision['test_results'] as $result): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= e($result['test_key']) ?></strong>
                                                        <div style="font-size: 11px; color: var(--text-muted);">
                                                            <?= e($testKeys[$result['test_key']] ?? '') ?>
                                                        </div>
                                                    </td>
                                                    <td><?= getStatusBadge($result['status']) ?></td>
                                                    <td style="font-size: 12px; max-width: 300px;">
                                                        <?= e(truncate(cleanNotes($result['notes']), 150)) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (empty($revisions)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 20px; color: var(--text-muted);">
                            No archived revisions. This report has not been revised yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
/* Revisions table styling */
.revisions-table {
    width: 100%;
    border-collapse: collapse;
}

.revisions-table th,
.revisions-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid #292d23;
}

.revisions-table th {
    background: #5a6a50;
    font-weight: bold;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.current-revision-row {
    background: rgba(196, 181, 80, 0.1);
}

.archived-revision-row {
    background: rgba(62, 70, 55, 0.5);
}

.revision-number {
    font-weight: bold;
    color: #c4b550;
    text-align: center;
}

.revision-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    margin-left: 6px;
    vertical-align: middle;
}

.revision-badge.current {
    background: #7ea64b;
    color: white;
}

.revision-badge.archived {
    background: #5a6a50;
    color: #b8c4ad;
}

.report-id-link {
    color: #c4b550;
    text-decoration: none;
    font-weight: bold;
}
.report-id-link:hover {
    text-decoration: underline;
}

/* Actions cell */
.actions-cell {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

/* Revision details row */
.revision-details-row td {
    padding: 0 !important;
    background: #3e4637;
}

.revision-details-content {
    padding: 15px 20px;
    border-top: 1px solid #292d23;
}

.revision-meta-inline {
    display: flex;
    gap: 20px;
    font-size: 12px;
    color: #b8c4ad;
    margin-bottom: 15px;
    flex-wrap: wrap;
}

.revision-table {
    width: 100%;
    font-size: 12px;
    margin-top: 15px;
}

.revision-table th,
.revision-table td {
    padding: 8px;
    text-align: left;
    border-bottom: 1px solid #292d23;
}

.revision-table th {
    background: #4c5844;
    font-weight: 600;
    font-size: 11px;
}

/* Changes summary */
.changes-summary {
    margin: 15px 0;
    padding: 10px 12px;
    background: #4c5844;
    border-left: 3px solid #c4b550;
}

.changes-summary > strong {
    display: block;
    margin-bottom: 8px;
    font-size: 11px;
    color: #b8c4ad;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.changes-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.change-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
}

.change-test {
    font-weight: 600;
    color: #c4b550;
    min-width: 45px;
}

.change-arrow {
    color: #b8c4ad;
    font-size: 11px;
}

.change-notes-badge {
    font-size: 9px;
    background: #5a6a50;
    color: #b8c4ad;
    padding: 2px 6px;
    border-radius: 3px;
    margin-left: 8px;
}

code {
    background: #3e4637;
    padding: 2px 6px;
    font-family: 'Consolas', 'Courier New', monospace;
    font-size: 11px;
    color: #c4b550;
}
</style>

<script>
function toggleRevisionDetails(id) {
    const el = document.getElementById('revision-' + id);
    if (el.style.display === 'none') {
        el.style.display = 'block';
    } else {
        el.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
