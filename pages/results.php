<?php
/**
 * Filtered test results page
 * Shows test results filtered by status, version, test key, etc.
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

// Get filter parameters
$filterStatus = $_GET['status'] ?? '';
$filterVersion = $_GET['version'] ?? '';
$filterTestKey = $_GET['test_key'] ?? '';
$filterTester = $_GET['tester'] ?? '';
$filterReportId = intval($_GET['report_id'] ?? 0);
$filterCategory = $_GET['category'] ?? '';
$filterCommit = $_GET['commit'] ?? '';

// Pagination
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 50;

// Get all test results with filters
if ($filterCategory) {
    // Filter by category - get all test keys for this category
    $allResults = $db->getResultsByCategory($filterCategory);
    // Apply additional status filter if specified
    if ($filterStatus) {
        $allResults = array_filter($allResults, fn($r) => $r['status'] === $filterStatus);
        $allResults = array_values($allResults);
    }
} else {
    $allResults = $db->getFilteredResults($filterStatus, $filterVersion, $filterTestKey, $filterTester, $filterReportId, $filterCommit);
}
$totalResults = count($allResults);
$totalPages = ceil($totalResults / $perPage);

// Paginate
$results = array_slice($allResults, ($page - 1) * $perPage, $perPage);

// Build page title
$titleParts = [];
if ($filterStatus) $titleParts[] = $filterStatus;
if ($filterVersion) $titleParts[] = $filterVersion;
if ($filterTestKey) $titleParts[] = 'Test ' . $filterTestKey . ' (' . getTestName($filterTestKey) . ')';
if ($filterTester) $titleParts[] = 'by ' . $filterTester;
if ($filterReportId) $titleParts[] = 'Report #' . $filterReportId;
if ($filterCommit) $titleParts[] = 'Commit ' . $filterCommit;

$pageTitle = $titleParts ? implode(' - ', $titleParts) : 'All Test Results';

// Get filter options
// Get client versions from the database client_versions table (not just from reports)
$clientVersionsDb = $db->getClientVersions(true); // Get enabled versions only
$versions = array_map(function($v) { return $v['version_id']; }, $clientVersionsDb);

// Get testers - use all users from the database
$allUsers = $db->getUsers();
$testers = array_map(function($u) { return $u['username']; }, $allUsers);

// Get commits from GitHub revision cache (if configured)
$commits = [];
if (isGitHubConfigured()) {
    $revisionsData = getGitHubRevisions();
    $commits = array_keys($revisionsData);
}
// Fall back to commits from reports if no GitHub data
if (empty($commits)) {
    $commits = $db->getUniqueValues('reports', 'commit_hash');
}

$testKeys = getSortedTestKeys();

// Get pending retest requests map
$pendingRetestMap = $db->getPendingRetestRequestsMap();

// Build current filter URL for pagination
$filterParams = http_build_query(array_filter([
    'page' => 'results',
    'status' => $filterStatus,
    'version' => $filterVersion,
    'test_key' => $filterTestKey,
    'tester' => $filterTester,
    'report_id' => $filterReportId ?: null,
    'commit' => $filterCommit
]));
?>

<div class="report-header">
    <div>
        <h1 class="page-title" style="margin-bottom: 10px;">
            <?= e($pageTitle) ?>
        </h1>
        <p style="color: var(--text-muted);">
            <?= number_format($totalResults) ?> test result<?= $totalResults !== 1 ? 's' : '' ?> found
        </p>
    </div>
    <a href="?page=dashboard" class="btn btn-secondary">&larr; Back to Dashboard</a>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="page" value="results">

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 120px;">
            <label>Status</label>
            <select name="status" class="<?= $filterStatus ? 'filter-active' : '' ?>">
                <option value="">All Statuses</option>
                <option value="Working" <?= $filterStatus === 'Working' ? 'selected' : '' ?>>Working</option>
                <option value="Semi-working" <?= $filterStatus === 'Semi-working' ? 'selected' : '' ?>>Semi-working</option>
                <option value="Not working" <?= $filterStatus === 'Not working' ? 'selected' : '' ?>>Not working</option>
                <option value="N/A" <?= $filterStatus === 'N/A' ? 'selected' : '' ?>>N/A</option>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
            <label>Client Version</label>
            <select name="version" class="<?= $filterVersion ? 'filter-active' : '' ?>">
                <option value="">All Versions</option>
                <?php foreach ($versions as $v): ?>
                    <option value="<?= e($v) ?>" <?= $filterVersion === $v ? 'selected' : '' ?>>
                        <?= e($v) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
            <label>Test</label>
            <select name="test_key" class="<?= $filterTestKey ? 'filter-active' : '' ?>">
                <option value="">All Tests</option>
                <?php foreach ($testKeys as $key): ?>
                    <option value="<?= e($key) ?>" <?= $filterTestKey === $key ? 'selected' : '' ?>>
                        <?= e($key) ?> - <?= e(getTestName($key)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 120px;">
            <label>Tester</label>
            <select name="tester" class="<?= $filterTester ? 'filter-active' : '' ?>">
                <option value="">All Testers</option>
                <?php foreach ($testers as $t): ?>
                    <option value="<?= e($t) ?>" <?= $filterTester === $t ? 'selected' : '' ?>>
                        <?= e($t) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 180px;">
            <label>Commit</label>
            <select name="commit" class="<?= $filterCommit ? 'filter-active' : '' ?>">
                <option value="">All Commits</option>
                <?php
                // Show commits with date/time if we have revision data
                $revData = isGitHubConfigured() ? getGitHubRevisions() : [];
                foreach ($commits as $c):
                    if (empty($c)) continue;
                    $shortSha = substr($c, 0, 8);
                    $label = $shortSha;
                    if (isset($revData[$c]) && !empty($revData[$c]['ts'])) {
                        $label = $shortSha . ' - ' . date('Y-m-d H:i', $revData[$c]['ts']);
                    }
                ?>
                    <option value="<?= e($c) ?>" <?= $filterCommit === $c ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-sm">Filter</button>
        <?php if ($filterStatus || $filterVersion || $filterTestKey || $filterTester || $filterReportId || $filterCommit): ?>
            <a href="?page=results" class="btn btn-sm btn-secondary">Clear All</a>
        <?php endif; ?>
    </form>
</div>

<!-- Summary Stats -->
<?php
$statusCounts = ['Working' => 0, 'Semi-working' => 0, 'Not working' => 0, 'N/A' => 0];
foreach ($allResults as $r) {
    if (isset($statusCounts[$r['status']])) {
        $statusCounts[$r['status']]++;
    }
}
?>
<?php
// Build base URL params preserving other filters (but not status)
$baseParams = [];
if ($filterVersion) $baseParams['version'] = $filterVersion;
if ($filterTestKey) $baseParams['test_key'] = $filterTestKey;
if ($filterTester) $baseParams['tester'] = $filterTester;
if ($filterReportId) $baseParams['report_id'] = $filterReportId;
if ($filterCategory) $baseParams['category'] = $filterCategory;
if ($filterCommit) $baseParams['commit'] = $filterCommit;

function buildStatusLink($status, $baseParams) {
    $params = $baseParams;
    $params['status'] = $status;
    return '?page=results&' . http_build_query($params);
}
?>
<div class="stats-grid" style="margin-bottom: 20px;">
    <a href="<?= buildStatusLink('Working', $baseParams) ?>" class="stat-card working clickable-card" style="<?= $filterStatus === 'Working' ? 'border: 2px solid var(--status-working);' : '' ?>">
        <div class="value"><?= $statusCounts['Working'] ?></div>
        <div class="label">Working</div>
    </a>
    <a href="<?= buildStatusLink('Semi-working', $baseParams) ?>" class="stat-card semi clickable-card" style="<?= $filterStatus === 'Semi-working' ? 'border: 2px solid var(--status-semi);' : '' ?>">
        <div class="value"><?= $statusCounts['Semi-working'] ?></div>
        <div class="label">Semi-working</div>
    </a>
    <a href="<?= buildStatusLink('Not working', $baseParams) ?>" class="stat-card broken clickable-card" style="<?= $filterStatus === 'Not working' ? 'border: 2px solid var(--status-broken);' : '' ?>">
        <div class="value"><?= $statusCounts['Not working'] ?></div>
        <div class="label">Not Working</div>
    </a>
    <a href="<?= buildStatusLink('N/A', $baseParams) ?>" class="stat-card clickable-card" style="<?= $filterStatus === 'N/A' ? 'border: 2px solid var(--status-na);' : '' ?>">
        <div class="value" style="color: var(--status-na);"><?= $statusCounts['N/A'] ?></div>
        <div class="label">N/A</div>
    </a>
</div>

<!-- Results Table -->
<div class="card">
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>Report</th>
                    <th>Client Version</th>
                    <th>Commit</th>
                    <th>Test</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Tester</th>
                    <th>Date</th>
                    <?php if (isAdmin()): ?>
                        <th style="width: 100px;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)): ?>
                    <tr>
                        <td colspan="<?= isAdmin() ? '9' : '8' ?>" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No test results found matching your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($results as $result): ?>
                        <?php
                        $retestKey = $result['test_key'] . '|' . $result['client_version'];
                        $hasPendingRetest = isset($pendingRetestMap[$retestKey]);
                        ?>
                        <tr class="<?= $hasPendingRetest ? 'retest-pending' : '' ?>">
                            <td>
                                <a href="?page=report_detail&id=<?= $result['report_id'] ?>" style="color: var(--primary);">
                                    #<?= $result['report_id'] ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&version=<?= urlencode($result['client_version']) ?>" style="color: var(--text);">
                                    <?= e($result['client_version']) ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($result['commit_hash'])): ?>
                                    <a href="?page=results&commit=<?= urlencode($result['commit_hash']) ?>" style="font-family: monospace; font-size: 12px; color: var(--primary); background: var(--bg-dark); padding: 2px 6px; border-radius: 3px; text-decoration: none;">
                                        <?= e($result['commit_hash']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?page=results&test_key=<?= urlencode($result['test_key']) ?>" style="color: var(--text);">
                                    <span style="font-family: monospace; font-weight: bold; color: var(--primary);"><?= e($result['test_key']) ?></span>
                                    <?php if ($hasPendingRetest): ?>
                                        <span class="retest-indicator" title="Flagged for retest">&#x21BB;</span>
                                    <?php endif; ?>
                                    <span style="color: var(--text-muted); margin-left: 5px;"><?= e(getTestName($result['test_key'])) ?></span>
                                </a>
                            </td>
                            <td><?= getStatusBadge($result['status']) ?></td>
                            <td class="notes-cell rich-notes" data-full="<?= e($result['notes']) ?>">
                                <?php if ($result['notes']): ?>
                                    <div class="notes-content"><?= e($result['notes']) ?></div>
                                <?php else: ?>
                                    <span class="notes-empty">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?page=results&tester=<?= urlencode($result['tester']) ?>" style="color: var(--text-muted);">
                                    <?= e($result['tester']) ?>
                                </a>
                            </td>
                            <td style="color: var(--text-muted); white-space: nowrap;">
                                <?= formatDate($result['submitted_at']) ?>
                            </td>
                            <?php if (isAdmin()): ?>
                                <td>
                                    <?php if ($hasPendingRetest): ?>
                                        <span class="retest-badge" title="Retest already requested">Pending</span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-retest"
                                                data-test-key="<?= e($result['test_key']) ?>"
                                                data-client-version="<?= e($result['client_version']) ?>"
                                                title="Flag this test for retest">
                                            Retest
                                        </button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
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
                <a href="?<?= $filterParams ?>&p=<?= $page - 1 ?>" class="btn btn-sm btn-secondary">&laquo; Prev</a>
            <?php endif; ?>

            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);

            if ($startPage > 1): ?>
                <a href="?<?= $filterParams ?>&p=1" class="btn btn-sm btn-secondary">1</a>
                <?php if ($startPage > 2): ?>
                    <span style="color: var(--text-muted); padding: 0 10px;">...</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="?<?= $filterParams ?>&p=<?= $i ?>"
                   class="btn btn-sm <?= $i === $page ? '' : 'btn-secondary' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <span style="color: var(--text-muted); padding: 0 10px;">...</span>
                <?php endif; ?>
                <a href="?<?= $filterParams ?>&p=<?= $totalPages ?>" class="btn btn-sm btn-secondary"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?<?= $filterParams ?>&p=<?= $page + 1 ?>" class="btn btn-sm btn-secondary">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
/* Active filter highlight */
.form-group select.filter-active {
    border-color: #c4b550 !important;
    background-color: rgba(196, 181, 80, 0.15);
    box-shadow: 0 0 0 1px rgba(196, 181, 80, 0.3);
}

/* Retest indicator (visible to all users) */
.retest-indicator {
    display: inline-block;
    color: #f39c12;
    font-size: 14px;
    font-weight: bold;
    margin-left: 6px;
    vertical-align: middle;
    animation: retest-pulse 2s infinite;
}

@keyframes retest-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Row highlight for pending retest */
tr.retest-pending {
    background: rgba(243, 156, 18, 0.1);
}
tr.retest-pending:hover {
    background: rgba(243, 156, 18, 0.2);
}

/* Retest badge (shown in Actions column when already pending) */
.retest-badge {
    display: inline-block;
    background: #f39c12;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
}

/* Retest button */
.btn-retest {
    background: #3498db;
    color: #fff;
    border: none;
    transition: background 0.2s;
}
.btn-retest:hover {
    background: #2980b9;
}
.btn-retest:disabled {
    background: #7f8c8d;
    cursor: not-allowed;
}
</style>

<?php if (isAdmin()): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle retest button clicks
    document.querySelectorAll('.btn-retest').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var testKey = this.dataset.testKey;
            var clientVersion = this.dataset.clientVersion;
            var button = this;

            if (!confirm('Flag test ' + testKey + ' for retest on version ' + clientVersion + '?')) {
                return;
            }

            button.disabled = true;
            button.textContent = '...';

            fetch('api/retest_request.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    test_key: testKey,
                    client_version: clientVersion
                })
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    // Replace button with pending badge
                    var badge = document.createElement('span');
                    badge.className = 'retest-badge';
                    badge.title = 'Retest already requested';
                    badge.textContent = 'Pending';
                    button.parentNode.replaceChild(badge, button);

                    // Add visual indicator to the row
                    var row = badge.closest('tr');
                    row.classList.add('retest-pending');

                    // Add indicator next to test key
                    var testCell = row.querySelector('td:nth-child(4) a');
                    if (testCell && !row.querySelector('.retest-indicator')) {
                        var keySpan = testCell.querySelector('span:first-child');
                        if (keySpan) {
                            var indicator = document.createElement('span');
                            indicator.className = 'retest-indicator';
                            indicator.title = 'Flagged for retest';
                            indicator.innerHTML = '&#x21BB;';
                            keySpan.parentNode.insertBefore(indicator, keySpan.nextSibling);
                        }
                    }
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    button.disabled = false;
                    button.textContent = 'Retest';
                }
            })
            .catch(function(error) {
                alert('Error: ' + error.message);
                button.disabled = false;
                button.textContent = 'Retest';
            });
        });
    });
});
</script>
<?php endif; ?>

<!-- Rich Notes Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize rich notes rendering for notes cells
    if (typeof RichNotesRenderer !== 'undefined') {
        document.querySelectorAll('.notes-cell.rich-notes').forEach(function(cell) {
            var contentEl = cell.querySelector('.notes-content');
            if (!contentEl) return;

            var rawContent = cell.getAttribute('data-full') || contentEl.textContent;

            // Check if content has rich formatting
            if (RichNotesRenderer.hasRichContent(rawContent)) {
                contentEl.innerHTML = RichNotesRenderer.render(rawContent);
                cell.classList.add('rich-notes-rendered');
                cell.classList.add('expanded');
                cell.style.cursor = 'auto';
                cell.removeAttribute('title');
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
