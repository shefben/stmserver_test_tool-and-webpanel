<?php
/**
 * Admin Panel - Report Management
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();
$error = '';
$success = '';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $reportId = intval($_POST['report_id'] ?? 0);
        if ($reportId && $db->deleteReport($reportId)) {
            $success = "Report #$reportId deleted successfully.";
        } else {
            $error = "Failed to delete report.";
        }
    }

    if ($action === 'bulk_delete') {
        $reportIds = $_POST['report_ids'] ?? [];
        $deleted = 0;
        foreach ($reportIds as $id) {
            if ($db->deleteReport(intval($id))) {
                $deleted++;
            }
        }
        if ($deleted > 0) {
            $success = "Deleted $deleted report(s) successfully.";
        } else {
            $error = "No reports were deleted.";
        }
    }
}

// Pagination
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Filters
$filterVersion = $_GET['version'] ?? '';
$filterTester = $_GET['tester'] ?? '';

// Build filter array
$filters = [];
if ($filterVersion) $filters['client_version'] = $filterVersion;
if ($filterTester) $filters['tester'] = $filterTester;

// Get reports
$reports = $db->getReports($perPage, $offset, $filters);
$totalReports = $db->countReports($filters);
$totalPages = ceil($totalReports / $perPage);

// Get unique values for filters
$versions = $db->getUniqueValues('reports', 'client_version');
$testers = $db->getUniqueValues('reports', 'tester');
?>

<div class="report-header">
    <div>
        <h1 class="page-title">Report Management</h1>
        <p style="color: var(--text-muted);">Edit and delete test reports</p>
    </div>
    <a href="?page=admin" class="btn btn-secondary">&larr; Back to Admin</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="page" value="admin_reports">

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
            <label>Client Version</label>
            <select name="version">
                <option value="">All Versions</option>
                <?php foreach ($versions as $v): ?>
                    <option value="<?= e($v) ?>" <?= $filterVersion === $v ? 'selected' : '' ?>>
                        <?= e($v) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
            <label>Tester</label>
            <select name="tester">
                <option value="">All Testers</option>
                <?php foreach ($testers as $t): ?>
                    <option value="<?= e($t) ?>" <?= $filterTester === $t ? 'selected' : '' ?>>
                        <?= e($t) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-sm">Filter</button>
        <?php if ($filterVersion || $filterTester): ?>
            <a href="?page=admin_reports" class="btn btn-sm btn-secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Bulk Actions -->
<form method="POST" id="bulkForm">
    <input type="hidden" name="action" value="bulk_delete">

    <!-- Results count and bulk actions -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <p style="color: var(--text-muted); margin: 0;">
            Showing <?= number_format(count($reports)) ?> of <?= number_format($totalReports) ?> reports
        </p>
        <div class="bulk-actions" style="display: none;">
            <span id="selectedCount">0</span> selected
            <button type="submit" class="btn btn-sm btn-danger"
                    onclick="return confirm('Delete all selected reports? This cannot be undone.');">
                Delete Selected
            </button>
        </div>
    </div>

    <!-- Reports Table -->
    <div class="card">
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                        </th>
                        <th>ID</th>
                        <th>Client Version</th>
                        <th>Tester</th>
                        <th>Type</th>
                        <th>Results</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                No reports found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                            <?php $reportStats = $db->getReportStats($report['id']); ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="report_ids[]" value="<?= $report['id'] ?>"
                                           class="report-checkbox" onchange="updateBulkActions()">
                                </td>
                                <td>#<?= $report['id'] ?></td>
                                <td><?= e($report['client_version']) ?></td>
                                <td><?= e($report['tester']) ?></td>
                                <td>
                                    <span class="status-badge" style="background: <?= $report['test_type'] === 'WAN' ? '#3498db' : '#9b59b6' ?>">
                                        <?= e($report['test_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="mini-stat working"><?= $reportStats['working'] ?></span>
                                    <span class="mini-stat semi"><?= $reportStats['semi_working'] ?></span>
                                    <span class="mini-stat broken"><?= $reportStats['not_working'] ?></span>
                                </td>
                                <td style="color: var(--text-muted);"><?= formatDate($report['submitted_at']) ?></td>
                                <td class="actions-cell">
                                    <a href="?page=report_detail&id=<?= $report['id'] ?>" class="btn btn-sm btn-secondary">View</a>
                                    <a href="?page=edit_report&id=<?= $report['id'] ?>" class="btn btn-sm">Edit</a>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="deleteSingle(<?= $report['id'] ?>)">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=admin_reports&p=<?= $page - 1 ?>&version=<?= urlencode($filterVersion) ?>&tester=<?= urlencode($filterTester) ?>" class="btn btn-sm btn-secondary">&laquo; Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                    <a href="?page=admin_reports&p=<?= $i ?>&version=<?= urlencode($filterVersion) ?>&tester=<?= urlencode($filterTester) ?>"
                       class="btn btn-sm <?= $i === $page ? '' : 'btn-secondary' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=admin_reports&p=<?= $page + 1 ?>&version=<?= urlencode($filterVersion) ?>&tester=<?= urlencode($filterTester) ?>" class="btn btn-sm btn-secondary">Next &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</form>

<!-- Hidden form for single delete -->
<form method="POST" id="singleDeleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="report_id" id="deleteReportId">
</form>

<style>
/* Alert styles */
.alert {
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.alert-error {
    background: rgba(231, 76, 60, 0.2);
    border: 1px solid var(--status-broken);
    color: var(--status-broken);
}

.alert-success {
    background: rgba(39, 174, 96, 0.2);
    border: 1px solid var(--status-working);
    color: var(--status-working);
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

/* Actions cell */
.actions-cell {
    white-space: nowrap;
}

/* Danger button */
.btn-danger {
    background: var(--status-broken);
}

.btn-danger:hover {
    background: #c0392b;
}

/* Bulk actions */
.bulk-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Checkbox styling */
input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}
</style>

<script>
function toggleAll(checkbox) {
    const checkboxes = document.querySelectorAll('.report-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateBulkActions();
}

function updateBulkActions() {
    const checked = document.querySelectorAll('.report-checkbox:checked');
    const bulkActions = document.querySelector('.bulk-actions');
    const selectedCount = document.getElementById('selectedCount');

    if (checked.length > 0) {
        bulkActions.style.display = 'flex';
        selectedCount.textContent = checked.length;
    } else {
        bulkActions.style.display = 'none';
    }
}

function deleteSingle(reportId) {
    if (confirm('Delete report #' + reportId + '? This cannot be undone.')) {
        document.getElementById('deleteReportId').value = reportId;
        document.getElementById('singleDeleteForm').submit();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
