<?php
/**
 * My Reports Page
 * Users can view, edit, and delete their own reports
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();
$currentUser = getCurrentUser();

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $reportId = intval($_POST['report_id'] ?? 0);

    if ($reportId) {
        // Verify ownership
        $report = $db->getReport($reportId);
        if ($report && $report['tester'] === $currentUser['username']) {
            if ($db->deleteReport($reportId)) {
                setFlash('success', 'Report #' . $reportId . ' has been deleted.');
            } else {
                setFlash('error', 'Failed to delete report.');
            }
        } else {
            setFlash('error', 'You do not have permission to delete this report.');
        }
    }
    header('Location: ?page=my_reports');
    exit;
}

// Pagination
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get user's reports
$filters = ['tester' => $currentUser['username']];
$reports = $db->getReports($perPage, $offset, $filters);
$totalReports = $db->countReports($filters);
$totalPages = ceil($totalReports / $perPage);

// Calculate stats
$totalWorking = 0;
$totalSemi = 0;
$totalBroken = 0;

foreach ($reports as $report) {
    $stats = $db->getReportStats($report['id']);
    $totalWorking += $stats['working'];
    $totalSemi += $stats['semi_working'];
    $totalBroken += $stats['not_working'];
}
?>

<div class="report-header">
    <div>
        <h1 class="page-title">My Reports</h1>
        <p style="color: var(--text-muted);">Manage your submitted test reports</p>
    </div>
    <a href="?page=submit" class="btn">Submit New Report</a>
</div>

<?= renderFlash() ?>

<!-- Stats Summary -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card primary">
        <div class="value"><?= number_format($totalReports) ?></div>
        <div class="label">Total Reports</div>
    </div>
    <a href="?page=results&tester=<?= urlencode($currentUser['username']) ?>&status=Working" class="stat-card working clickable-card">
        <div class="value"><?= number_format($totalWorking) ?></div>
        <div class="label">Working Tests</div>
        <div class="card-hint">Click to view</div>
    </a>
    <a href="?page=results&tester=<?= urlencode($currentUser['username']) ?>&status=Semi-working" class="stat-card semi clickable-card">
        <div class="value"><?= number_format($totalSemi) ?></div>
        <div class="label">Semi-working Tests</div>
        <div class="card-hint">Click to view</div>
    </a>
    <a href="?page=results&tester=<?= urlencode($currentUser['username']) ?>&status=Not+working" class="stat-card broken clickable-card">
        <div class="value"><?= number_format($totalBroken) ?></div>
        <div class="label">Failed Tests</div>
        <div class="card-hint">Click to view</div>
    </a>
</div>

<!-- Reports Table -->
<div class="card">
    <?php if (empty($reports)): ?>
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            You haven't submitted any reports yet. <a href="?page=submit">Submit your first report</a> or <a href="?page=create_report">Create a new report</a>.
        </p>
    <?php else: ?>
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Client Version</th>
                        <th>Commit</th>
                        <th>Type</th>
                        <th>Results</th>
                        <th>Submitted</th>
                        <th class="no-sort">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <?php $reportStats = $db->getReportStats($report['id']); ?>
                        <tr>
                            <td>
                                <a href="?page=report_detail&id=<?= $report['id'] ?>" class="report-link">
                                    #<?= $report['id'] ?>
                                </a>
                                <?php if (($report['revision_count'] ?? 0) > 0): ?>
                                    <span class="revision-badge" title="Has <?= $report['revision_count'] ?> revision(s)">
                                        v<?= $report['revision_count'] + 1 ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(shortVersionName($report['client_version'])) ?></td>
                            <td>
                                <?php if (!empty($report['commit_hash'])): ?>
                                    <code class="commit-hash"><?= e(substr($report['commit_hash'], 0, 8)) ?></code>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="type-badge <?= strtolower($report['test_type']) ?>">
                                    <?= e($report['test_type']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="mini-stat working"><?= $reportStats['working'] ?></span>
                                <span class="mini-stat semi"><?= $reportStats['semi_working'] ?></span>
                                <span class="mini-stat broken"><?= $reportStats['not_working'] ?></span>
                            </td>
                            <td style="color: var(--text-muted); font-size: 12px;">
                                <?= formatRelativeTime($report['submitted_at']) ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="?page=report_detail&id=<?= $report['id'] ?>" class="btn btn-sm btn-secondary" title="View Report">View</a>
                                    <a href="?page=edit_report&id=<?= $report['id'] ?>" class="btn btn-sm" title="Edit Report">Edit</a>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete Report #<?= $report['id'] ?>? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete Report">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=my_reports&p=<?= $page - 1 ?>" class="btn btn-sm btn-secondary">&laquo; Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                if ($startPage > 1): ?>
                    <a href="?page=my_reports&p=1" class="btn btn-sm btn-secondary">1</a>
                    <?php if ($startPage > 2): ?>
                        <span style="color: var(--text-muted); padding: 0 10px;">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <a href="?page=my_reports&p=<?= $i ?>"
                       class="btn btn-sm <?= $i === $page ? '' : 'btn-secondary' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span style="color: var(--text-muted); padding: 0 10px;">...</span>
                    <?php endif; ?>
                    <a href="?page=my_reports&p=<?= $totalPages ?>" class="btn btn-sm btn-secondary"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=my_reports&p=<?= $page + 1 ?>" class="btn btn-sm btn-secondary">Next &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
/* Clickable cards */
.clickable-card {
    text-decoration: none;
    color: inherit;
    display: block;
    transition: transform 0.2s, box-shadow 0.2s;
    cursor: pointer;
}
.clickable-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}
.card-hint {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 8px;
    opacity: 0;
    transition: opacity 0.2s;
}
.clickable-card:hover .card-hint {
    opacity: 1;
}

/* Report link */
.report-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: bold;
}
.report-link:hover {
    text-decoration: underline;
}

/* Revision badge */
.revision-badge {
    display: inline-block;
    background: var(--primary);
    color: white;
    font-size: 10px;
    font-weight: 600;
    padding: 1px 5px;
    border-radius: 3px;
    margin-left: 5px;
    vertical-align: middle;
}

/* Commit hash */
.commit-hash {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 12px;
    background: var(--bg-accent);
    padding: 2px 6px;
    border-radius: 4px;
    color: var(--primary);
}

/* Type badge */
.type-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.type-badge.wan {
    background: #3498db;
    color: #fff;
}
.type-badge.lan {
    background: #9b59b6;
    color: #fff;
}

/* Mini stats */
.mini-stat {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
    color: #fff;
    margin-right: 2px;
}
.mini-stat.working { background: var(--status-working); }
.mini-stat.semi { background: var(--status-semi); }
.mini-stat.broken { background: var(--status-broken); }

/* Action buttons */
.action-buttons {
    display: flex;
    gap: 5px;
    flex-wrap: nowrap;
}

/* Danger button */
.btn-danger {
    background: linear-gradient(180deg, var(--status-broken) 0%, #a04040 100%);
    border-color: var(--status-broken);
}
.btn-danger:hover {
    background: linear-gradient(180deg, #d45050 0%, var(--status-broken) 100%);
}

/* Flash messages */
.flash-message {
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}
.flash-message.success {
    background: rgba(39, 174, 96, 0.2);
    border: 1px solid var(--status-working);
    color: var(--status-working);
}
.flash-message.error {
    background: rgba(231, 76, 60, 0.2);
    border: 1px solid var(--status-broken);
    color: var(--status-broken);
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
