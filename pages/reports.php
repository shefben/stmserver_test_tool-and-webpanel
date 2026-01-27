<?php
/**
 * Reports listing page
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

// Handle delete action (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_report') {
    if (isAdmin()) {
        $reportId = intval($_POST['report_id'] ?? 0);
        if ($reportId > 0 && $db->deleteReport($reportId)) {
            setFlash('success', "Report #$reportId deleted successfully.");
        } else {
            setFlash('error', "Failed to delete report.");
        }
    } else {
        setFlash('error', "You do not have permission to delete reports.");
    }
    // Redirect to prevent form resubmission
    header('Location: ?page=reports');
    exit;
}

// Pagination
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Filters
$filterVersion = $_GET['version'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterTester = $_GET['tester'] ?? '';
$filterCommit = $_GET['commit'] ?? '';
$filterSteamuiVersion = $_GET['steamui'] ?? '';
$filterSteamPkgVersion = $_GET['steampkg'] ?? '';
$filterTag = $_GET['tag'] ?? '';

// Build filter array
$filters = [];
if ($filterVersion) $filters['client_version'] = $filterVersion;
if ($filterType) $filters['test_type'] = $filterType;
if ($filterTester) $filters['tester'] = $filterTester;
if ($filterCommit) $filters['commit_hash'] = $filterCommit;
if ($filterSteamuiVersion) $filters['steamui_version'] = $filterSteamuiVersion;
if ($filterSteamPkgVersion) $filters['steam_pkg_version'] = $filterSteamPkgVersion;
if ($filterTag) $filters['tag_id'] = intval($filterTag);

// Get reports
$reports = $db->getReports($perPage, $offset, $filters);
$totalReports = $db->countReports($filters);
$totalPages = ceil($totalReports / $perPage);

// Get unique values for filters
$versions = $db->getUniqueValues('reports', 'client_version');
$testers = $db->getUniqueValues('reports', 'tester');
$commits = $db->getUniqueValues('reports', 'commit_hash');
$steamuiVersions = $db->getUniqueValues('reports', 'steamui_version');
$steamPkgVersions = $db->getUniqueValues('reports', 'steam_pkg_version');

// Get all tags for filter dropdown
$allTags = $db->getAllTags();

// Get tags for all displayed reports in one query
$reportIds = array_column($reports, 'id');
$reportTagsMap = [];
if (!empty($reportIds)) {
    foreach ($reportIds as $rid) {
        $reportTagsMap[$rid] = $db->getReportTags($rid);
    }
}
?>

<h1 class="page-title">Reports</h1>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="page" value="reports">

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
            <label>Test Type</label>
            <select name="type">
                <option value="">All Types</option>
                <option value="WAN" <?= $filterType === 'WAN' ? 'selected' : '' ?>>WAN</option>
                <option value="LAN" <?= $filterType === 'LAN' ? 'selected' : '' ?>>LAN</option>
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

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
            <label>Commit</label>
            <select name="commit">
                <option value="">All Commits</option>
                <?php foreach ($commits as $c): ?>
                    <option value="<?= e($c) ?>" <?= $filterCommit === $c ? 'selected' : '' ?>>
                        <?= e($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
            <label>SteamUI Version</label>
            <select name="steamui">
                <option value="">All SteamUI</option>
                <?php foreach ($steamuiVersions as $v): ?>
                    <option value="<?= e($v) ?>" <?= $filterSteamuiVersion === $v ? 'selected' : '' ?>>
                        <?= e($v) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
            <label>Steam PKG Version</label>
            <select name="steampkg">
                <option value="">All Steam PKG</option>
                <?php foreach ($steamPkgVersions as $v): ?>
                    <option value="<?= e($v) ?>" <?= $filterSteamPkgVersion === $v ? 'selected' : '' ?>>
                        <?= e($v) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
            <label>Tag</label>
            <select name="tag">
                <option value="">All Tags</option>
                <?php foreach ($allTags as $tag): ?>
                    <option value="<?= $tag['id'] ?>" <?= $filterTag == $tag['id'] ? 'selected' : '' ?>>
                        <?= e($tag['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-sm">Filter</button>
        <?php if ($filterVersion || $filterType || $filterTester || $filterCommit || $filterSteamuiVersion || $filterSteamPkgVersion || $filterTag): ?>
            <a href="?page=reports" class="btn btn-sm btn-secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Results count -->
<p style="color: var(--text-muted); margin-bottom: 15px;">
    Showing <?= number_format(count($reports)) ?> of <?= number_format($totalReports) ?> reports
</p>

<!-- Reports Table -->
<div class="card">
    <div class="table-container">
        <table class="sortable reports-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Client Version</th>
                    <th>SteamUI</th>
                    <th>Steam</th>
                    <th>Commit Revision Hash</th>
                    <th>Tester</th>
                    <th>Type</th>
                    <th>Duration</th>
                    <th>Working</th>
                    <th>Partial</th>
                    <th>Broken</th>
                    <th>Submitted</th>
                    <th>Report Revision</th>
                    <th>Last Modified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="15" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No reports found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <?php
                        $reportStats = $db->getReportStats($report['id']);
                        ?>
                        <?php $tags = $reportTagsMap[$report['id']] ?? []; ?>
                        <tr class="clickable-row" onclick="window.location='?page=report_detail&id=<?= $report['id'] ?>'">
                            <td>
                                <div class="report-id-cell">
                                    <a href="?page=report_detail&id=<?= $report['id'] ?>" class="report-id-link" onclick="event.stopPropagation();">
                                        #<?= $report['id'] ?>
                                    </a>
                                </div>
                                <?php if (($report['revision_count'] ?? 0) > 0): ?>
                                    <a href="?page=report_revisions&id=<?= $report['id'] ?>" class="revision-badge-small revision-below" onclick="event.stopPropagation();" title="<?= $report['revision_count'] ?> revision(s)">
                                        v<?= ($report['revision_count'] ?? 0) + 1 ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($tags)): ?>
                                    <div class="report-tags-inline">
                                        <?php foreach ($tags as $tag): ?>
                                            <a href="?page=reports&tag=<?= $tag['id'] ?>" class="tag-mini" style="background-color: <?= e($tag['color']) ?>;" onclick="event.stopPropagation();" title="<?= e($tag['name']) ?>">
                                                <?= e($tag['name']) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?page=results&version=<?= urlencode($report['client_version']) ?>" class="version-link" onclick="event.stopPropagation();">
                                    <?= e($report['client_version']) ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($report['steamui_version'])): ?>
                                    <a href="?page=reports&steamui=<?= urlencode($report['steamui_version']) ?>" class="version-link" onclick="event.stopPropagation();" title="Filter by this SteamUI version">
                                        <?= e($report['steamui_version']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($report['steam_pkg_version'])): ?>
                                    <a href="?page=reports&steampkg=<?= urlencode($report['steam_pkg_version']) ?>" class="version-link" onclick="event.stopPropagation();" title="Filter by this Steam PKG version">
                                        <?= e($report['steam_pkg_version']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($report['commit_hash'])): ?>
                                    <a href="?page=reports&commit=<?= urlencode($report['commit_hash']) ?>" class="commit-link" onclick="event.stopPropagation();" title="Filter by this commit">
                                        <?= e($report['commit_hash']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?page=results&tester=<?= urlencode($report['tester']) ?>" class="tester-link" onclick="event.stopPropagation();">
                                    <?= e($report['tester']) ?>
                                </a>
                            </td>
                            <td>
                                <span class="status-badge" style="background: <?= $report['test_type'] === 'WAN' ? '#3498db' : '#9b59b6' ?>">
                                    <?= e($report['test_type']) ?>
                                </span>
                            </td>
                            <td style="color: var(--text-muted); font-family: monospace;">
                                <?= formatDuration($report['test_duration'] ?? null) ?>
                            </td>
                            <td>
                                <a href="?page=results&report_id=<?= $report['id'] ?>&status=Working" class="stat-link working" onclick="event.stopPropagation();">
                                    <?= $reportStats['working'] ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&report_id=<?= $report['id'] ?>&status=Semi-working" class="stat-link semi" onclick="event.stopPropagation();">
                                    <?= $reportStats['semi_working'] ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&report_id=<?= $report['id'] ?>&status=Not+working" class="stat-link broken" onclick="event.stopPropagation();">
                                    <?= $reportStats['not_working'] ?>
                                </a>
                            </td>
                            <td><?= formatDate($report['submitted_at']) ?></td>
                            <td>
                                <a href="?page=report_revisions&id=<?= $report['id'] ?>" class="revision-link" onclick="event.stopPropagation();" title="View revision history">
                                    <?= ($report['revision_count'] ?? 0) ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                $lastModified = $report['last_modified'] ?? $report['submitted_at'];
                                $isModified = $lastModified !== $report['submitted_at'];
                                ?>
                                <span <?= $isModified ? 'style="color: var(--primary);" title="Modified since submission"' : '' ?>>
                                    <?= formatDate($lastModified) ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions-cell">
                                    <a href="?page=report_detail&id=<?= $report['id'] ?>" class="btn btn-sm btn-secondary" onclick="event.stopPropagation();">
                                        View
                                    </a>
                                    <?php if (canEditReport($report['id'])): ?>
                                        <a href="?page=edit_report&id=<?= $report['id'] ?>" class="btn btn-sm" onclick="event.stopPropagation();">
                                            Edit
                                        </a>
                                    <?php endif; ?>
                                    <a href="?page=retest_report&id=<?= $report['id'] ?>" class="btn btn-sm btn-secondary" onclick="event.stopPropagation();" title="Request retest for this report">
                                        Retest
                                    </a>
                                    <?php if (isAdmin()): ?>
                                        <button type="button" class="btn btn-sm btn-danger btn-delete-report"
                                                data-report-id="<?= $report['id'] ?>"
                                                onclick="event.stopPropagation(); deleteReport(<?= $report['id'] ?>);"
                                                title="Delete this report">
                                            Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <?php
        $paginationParams = http_build_query(array_filter([
            'page' => 'reports',
            'version' => $filterVersion,
            'type' => $filterType,
            'tester' => $filterTester,
            'commit' => $filterCommit,
            'steamui' => $filterSteamuiVersion,
            'steampkg' => $filterSteamPkgVersion,
            'tag' => $filterTag,
        ]));
        ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= $paginationParams ?>&p=<?= $page - 1 ?>" class="btn btn-sm btn-secondary">&laquo; Prev</a>
            <?php endif; ?>

            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);

            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
                <a href="?<?= $paginationParams ?>&p=<?= $i ?>"
                   class="btn btn-sm <?= $i === $page ? '' : 'btn-secondary' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?<?= $paginationParams ?>&p=<?= $page + 1 ?>" class="btn btn-sm btn-secondary">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
/* Wider layout for reports page */
.container {
    max-width: 1800px;
}

/* Fixed column widths for reports table */
.reports-table th:nth-child(1),
.reports-table td:nth-child(1) { width: 30px; min-width: 30px; text-align: center; } /* ID */
.reports-table th:nth-child(2),
.reports-table td:nth-child(2) {  width: 170px; min-width: 170px; text-align: center; } /* Client Version */
.reports-table th:nth-child(3),
.reports-table td:nth-child(3) { width: 70px; min-width: 70px; max-width: 110px; text-align: center; } /* SteamUI */
.reports-table th:nth-child(4),
.reports-table td:nth-child(4) { width: 30px; min-width: 30px; max-width: 120px; text-align: center; } /* Steam PKG */
.reports-table th:nth-child(5),
.reports-table td:nth-child(5) { width: 160px; min-width: 160px; text-align: center; } /* Commit */
.reports-table th:nth-child(6),
.reports-table td:nth-child(6) { width: 60px; min-width: 60px; text-align: center; } /* Tester */
.reports-table th:nth-child(7),
.reports-table td:nth-child(7) { width: 60px; min-width: 60px; text-align: center;} /* Type */
.reports-table th:nth-child(8),
.reports-table td:nth-child(8) { width: 70px; min-width: 70px; text-align: center; } /* Duration */
.reports-table th:nth-child(9),
.reports-table td:nth-child(9),
.reports-table th:nth-child(10),
.reports-table td:nth-child(10),
.reports-table th:nth-child(11),
.reports-table td:nth-child(11) { width:70px; min-width: 70px; text-align: center; } /* Working/Partial/Broken */
.reports-table th:nth-child(12),
.reports-table td:nth-child(12) { width: 90px; min-width: 90px; white-space: nowrap; text-align: center; } /* Submitted */
.reports-table th:nth-child(13),
.reports-table td:nth-child(13) { width: 100px; min-width: 100px; text-align: center; } /* Revision */
.reports-table th:nth-child(14),
.reports-table td:nth-child(14) { width: 90px; min-width: 90px; white-space: nowrap; text-align: center; } /* Last Modified */
.reports-table th:nth-child(15),
.reports-table td:nth-child(15) { width: 100px; min-width: 100px; text-align: center; } /* Actions */

.reports-table {
    table-layout: fixed;
    width: 100%;
}

/* Actions column styling */
.actions-cell {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: flex-start;
}

.actions-cell .btn {
    width: 100%;
}

/* Clickable rows */
.clickable-row {
    cursor: pointer;
    transition: background 0.2s;
}
.clickable-row:hover {
    background: var(--bg-accent) !important;
}

/* Report ID link */
.report-id-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: bold;
}
.report-id-link:hover {
    text-decoration: underline;
}

/* Version link */
.version-link {
    color: var(--text);
    text-decoration: none;
}
.version-link:hover {
    color: var(--primary);
}

/* Tester link */
.tester-link {
    color: var(--text);
    text-decoration: none;
}
.tester-link:hover {
    color: var(--primary);
}

/* Commit link */
.commit-link {
    font-family: monospace;
    font-size: 12px;
    color: var(--primary);
    text-decoration: none;
    background: var(--bg-dark);
    padding: 2px 6px;
    border-radius: 3px;
    word-break: break-all;
}
.commit-link:hover {
    background: var(--primary);
    color: #fff;
}

/* Stat links */
.stat-link {
    text-decoration: none;
    font-weight: bold;
    padding: 2px 8px;
    border-radius: 4px;
    transition: all 0.2s;
    display: inline-block;
}
.stat-link:hover {
    transform: scale(1.1);
}
.stat-link.working { color: var(--status-working); }
.stat-link.working:hover { background: var(--status-working); color: #fff; }
.stat-link.semi { color: var(--status-semi); }
.stat-link.semi:hover { background: var(--status-semi); color: #fff; }
.stat-link.broken { color: var(--status-broken); }
.stat-link.broken:hover { background: var(--status-broken); color: #fff; }

/* Revision badge */
.revision-badge-small {
    display: inline-block;
    background: var(--primary);
    color: white;
    font-size: 10px;
    font-weight: 600;
    padding: 1px 5px;
    border-radius: 3px;
    margin-left: 5px;
    text-decoration: none;
    vertical-align: middle;
}
.revision-badge-small:hover {
    background: var(--primary-dark, #c0392b);
}

/* Revision badge displayed below ID */
.revision-badge-small.revision-below {
    display: block;
    margin-left: 0;
    margin-top: 4px;
    text-align: center;
    width: fit-content;
}

/* Revision link in column */
.revision-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    background: var(--bg-dark);
    transition: all 0.2s;
}
.revision-link:hover {
    background: var(--primary);
    color: #fff;
}

/* Report ID cell with tags */
.report-id-cell {
    display: flex;
    align-items: center;
    gap: 5px;
}

/* Tags inline display */
.report-tags-inline {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-top: 4px;
}

.tag-mini {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 600;
    color: #fff;
    text-shadow: 0 1px 1px rgba(0,0,0,0.3);
    text-decoration: none;
    transition: all 0.15s;
    white-space: nowrap;
}

.tag-mini:hover {
    transform: scale(1.1);
    box-shadow: 0 2px 5px rgba(0,0,0,0.3);
}
</style>

<?php if (isAdmin()): ?>
<!-- Delete Report Form (hidden) -->
<form id="deleteReportForm" method="POST" action="?page=reports" style="display: none;">
    <input type="hidden" name="action" value="delete_report">
    <input type="hidden" name="report_id" id="deleteReportId">
</form>

<script>
function deleteReport(reportId) {
    if (confirm('Delete report #' + reportId + '? This action cannot be undone.')) {
        document.getElementById('deleteReportId').value = reportId;
        document.getElementById('deleteReportForm').submit();
    }
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
