<?php
/**
 * Reports listing page
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

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

// Build filter array
$filters = [];
if ($filterVersion) $filters['client_version'] = $filterVersion;
if ($filterType) $filters['test_type'] = $filterType;
if ($filterTester) $filters['tester'] = $filterTester;
if ($filterCommit) $filters['commit_hash'] = $filterCommit;
if ($filterSteamuiVersion) $filters['steamui_version'] = $filterSteamuiVersion;
if ($filterSteamPkgVersion) $filters['steam_pkg_version'] = $filterSteamPkgVersion;

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

        <button type="submit" class="btn btn-sm">Filter</button>
        <?php if ($filterVersion || $filterType || $filterTester || $filterCommit || $filterSteamuiVersion || $filterSteamPkgVersion): ?>
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
                    <th>Steam PKG</th>
                    <th>Commit</th>
                    <th>Tester</th>
                    <th>Type</th>
                    <th>Duration</th>
                    <th>Working</th>
                    <th>Partial</th>
                    <th>Broken</th>
                    <th>Submitted</th>
                    <th>Revision</th>
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
                        <tr class="clickable-row" onclick="window.location='?page=report_detail&id=<?= $report['id'] ?>'">
                            <td>
                                <a href="?page=report_detail&id=<?= $report['id'] ?>" class="report-id-link" onclick="event.stopPropagation();">
                                    #<?= $report['id'] ?>
                                </a>
                                <?php if (($report['revision_count'] ?? 0) > 0): ?>
                                    <a href="?page=report_revisions&id=<?= $report['id'] ?>" class="revision-badge-small" onclick="event.stopPropagation();" title="<?= $report['revision_count'] ?> revision(s)">
                                        v<?= ($report['revision_count'] ?? 0) + 1 ?>
                                    </a>
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
/* Fixed column widths for reports table */
.reports-table th:nth-child(1),
.reports-table td:nth-child(1) { width: 70px; min-width: 70px; } /* ID */
.reports-table th:nth-child(2),
.reports-table td:nth-child(2) { min-width: 200px; } /* Client Version */
.reports-table th:nth-child(3),
.reports-table td:nth-child(3) { width: 75px; min-width: 75px; max-width: 85px; } /* SteamUI - 7 chars max */
.reports-table th:nth-child(4),
.reports-table td:nth-child(4) { width: 60px; min-width: 60px; max-width: 70px; } /* Steam PKG - 5 chars max */
.reports-table th:nth-child(5),
.reports-table td:nth-child(5) { width: 80px; min-width: 80px; } /* Commit */
.reports-table th:nth-child(6),
.reports-table td:nth-child(6) { width: 80px; min-width: 80px; } /* Tester */
.reports-table th:nth-child(7),
.reports-table td:nth-child(7) { width: 50px; min-width: 50px; } /* Type */
.reports-table th:nth-child(8),
.reports-table td:nth-child(8) { width: 75px; min-width: 75px; } /* Duration */
.reports-table th:nth-child(9),
.reports-table td:nth-child(9),
.reports-table th:nth-child(10),
.reports-table td:nth-child(10),
.reports-table th:nth-child(11),
.reports-table td:nth-child(11) { width: 45px; min-width: 45px; text-align: center; } /* Working/Partial/Broken */
.reports-table th:nth-child(12),
.reports-table td:nth-child(12) { width: 130px; min-width: 130px; } /* Submitted */
.reports-table th:nth-child(13),
.reports-table td:nth-child(13) { width: 55px; min-width: 55px; text-align: center; } /* Revision */
.reports-table th:nth-child(14),
.reports-table td:nth-child(14) { width: 130px; min-width: 130px; } /* Last Modified */
.reports-table th:nth-child(15),
.reports-table td:nth-child(15) { width: 140px; min-width: 140px; } /* Actions */

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
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
