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
                        $retestInfo = $pendingRetestMap[$retestKey] ?? null;
                        $hasPendingRetest = $retestInfo !== null;
                        $retestNotes = $retestInfo['notes'] ?? '';
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
                            <td class="notes-cell rich-notes <?= $result['notes'] ? 'has-notes' : '' ?>"
                                data-full="<?= e($result['notes']) ?>"
                                data-test-key="<?= e($result['test_key']) ?>"
                                data-test-name="<?= e(getTestName($result['test_key'])) ?>"
                                data-status="<?= e($result['status']) ?>"
                                data-version="<?= e($result['client_version']) ?>"
                                data-tester="<?= e($result['tester']) ?>">
                                <?php if ($result['notes']): ?>
                                    <div class="notes-content"><?= e($result['notes']) ?></div>
                                    <span class="notes-read-more">... read more</span>
                                <?php else: ?>
                                    <span class="notes-empty">-</span>
                                <?php endif; ?>
                                <?php if ($hasPendingRetest && $retestNotes): ?>
                                    <div class="retest-notes">
                                        <span class="retest-notes-label">Retest notes:</span>
                                        <?= e($retestNotes) ?>
                                    </div>
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
    background-color: rgba(196, 181, 80, 0.25);
    box-shadow: 0 0 0 1px rgba(196, 181, 80, 0.3);
    color: #fff !important;
}

/* Fix dropdown options text color inside filter-active selects */
.form-group select.filter-active option {
    background-color: #3e4637;
    color: #d8ded3;
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

/* Retest notes display (visible to all users) */
.retest-notes {
    background: rgba(243, 156, 18, 0.15);
    border-left: 3px solid #f39c12;
    padding: 6px 8px;
    margin-top: 6px;
    font-size: 12px;
    color: #d8ded3;
    border-radius: 0 4px 4px 0;
}
.retest-notes-label {
    font-weight: bold;
    color: #f39c12;
    font-size: 10px;
    text-transform: uppercase;
    display: block;
    margin-bottom: 2px;
}

/* Notes cell truncation */
.notes-cell {
    position: relative;
}

.notes-cell .notes-content {
    max-height: 4.5em; /* Approximately 3 lines */
    overflow: hidden;
    line-height: 1.5;
}

.notes-cell.expanded .notes-content {
    max-height: none;
    overflow: visible;
}

.notes-cell .notes-read-more {
    display: none;
    color: #3498db;
    text-decoration: underline;
    cursor: pointer;
    font-size: 12px;
    margin-top: 4px;
}

.notes-cell .notes-read-more:hover {
    color: #2980b9;
}

.notes-cell.truncated .notes-read-more {
    display: inline-block;
}

.notes-cell.expanded .notes-read-more {
    display: none;
}
</style>

<?php if (isAdmin()): ?>
<!-- Retest Modal -->
<div id="retest-modal" class="retest-modal-overlay" style="display: none;">
    <div class="retest-modal-box">
        <div class="retest-modal-header">
            <h3>Flag Test for Retest</h3>
            <button type="button" class="retest-modal-close" onclick="closeRetestModal()">&times;</button>
        </div>
        <div class="retest-modal-body">
            <div class="retest-modal-info">
                <div class="retest-info-row">
                    <label>Test:</label>
                    <span id="retest-modal-test-key"></span>
                </div>
                <div class="retest-info-row">
                    <label>Version:</label>
                    <span id="retest-modal-version"></span>
                </div>
            </div>
            <div class="form-group">
                <label for="retest-notes">Notes <span style="color: #e74c3c;">*</span></label>
                <textarea id="retest-notes" rows="5" placeholder="Please explain what needs to be retested and why..."></textarea>
                <small class="form-hint">Required - describe what issue was found or why a retest is needed</small>
            </div>
        </div>
        <div class="retest-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeRetestModal()">Cancel</button>
            <button type="button" class="btn" id="retest-submit-btn" onclick="submitRetestRequest()">Submit Retest</button>
        </div>
    </div>
</div>

<style>
.retest-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.75);
    z-index: 10002;
    display: flex;
    align-items: center;
    justify-content: center;
}

.retest-modal-box {
    background: var(--bg-card);
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 50px rgba(0, 0, 0, 0.6);
    border-top: solid 2px #899281;
    border-bottom: solid 2px #292d23;
    border-left: solid 2px #899281;
    border-right: solid 2px #292d23;
    animation: retestModalSlideIn 0.2s ease-out;
}

@keyframes retestModalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.retest-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-accent);
    border-radius: 6px 6px 0 0;
}

.retest-modal-header h3 {
    margin: 0;
    color: var(--primary);
    font-size: 18px;
}

.retest-modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 28px;
    cursor: pointer;
    padding: 0 8px;
    line-height: 1;
}

.retest-modal-close:hover {
    color: #c45050;
}

.retest-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

.retest-modal-info {
    background: var(--bg-dark);
    border-radius: 6px;
    padding: 12px 15px;
    margin-bottom: 20px;
    border: 1px solid var(--border);
}

.retest-info-row {
    display: flex;
    gap: 10px;
    margin-bottom: 8px;
}

.retest-info-row:last-child {
    margin-bottom: 0;
}

.retest-info-row label {
    color: var(--text-muted);
    font-size: 13px;
    min-width: 60px;
}

.retest-info-row span {
    color: var(--text);
    font-weight: 500;
    font-family: monospace;
}

.retest-modal-body .form-group {
    margin-bottom: 0;
}

.retest-modal-body .form-group label {
    display: block;
    margin-bottom: 8px;
    color: var(--text);
    font-weight: 500;
}

.retest-modal-body textarea {
    width: 100%;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    padding: 10px 12px;
    font-size: 14px;
    font-family: inherit;
    resize: vertical;
}

.retest-modal-body textarea:focus {
    outline: none;
    border-color: var(--primary);
}

.retest-modal-body .form-hint {
    display: block;
    margin-top: 6px;
    font-size: 12px;
    color: var(--text-muted);
}

.retest-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
</style>

<script>
var currentRetestButton = null;
var currentRetestTestKey = null;
var currentRetestVersion = null;

document.addEventListener('DOMContentLoaded', function() {
    // Handle retest button clicks - open modal
    document.querySelectorAll('.btn-retest').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openRetestModal(this);
        });
    });
});

function openRetestModal(button) {
    currentRetestButton = button;
    currentRetestTestKey = button.dataset.testKey;
    currentRetestVersion = button.dataset.clientVersion;

    document.getElementById('retest-modal-test-key').textContent = currentRetestTestKey;
    document.getElementById('retest-modal-version').textContent = currentRetestVersion;
    document.getElementById('retest-notes').value = '';
    document.getElementById('retest-submit-btn').disabled = false;
    document.getElementById('retest-submit-btn').textContent = 'Submit Retest';

    document.getElementById('retest-modal').style.display = 'flex';
    document.getElementById('retest-notes').focus();
}

function closeRetestModal() {
    document.getElementById('retest-modal').style.display = 'none';
    currentRetestButton = null;
    currentRetestTestKey = null;
    currentRetestVersion = null;
}

function submitRetestRequest() {
    var notes = document.getElementById('retest-notes').value.trim();

    if (!notes) {
        alert('Please enter notes explaining what needs to be retested.');
        document.getElementById('retest-notes').focus();
        return;
    }

    var submitBtn = document.getElementById('retest-submit-btn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    fetch('api/retest_request.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            test_key: currentRetestTestKey,
            client_version: currentRetestVersion,
            notes: notes
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Save button reference before closing modal (closeRetestModal sets it to null)
            var buttonToReplace = currentRetestButton;

            // Close modal
            closeRetestModal();

            // Replace button with pending badge
            if (buttonToReplace) {
                var badge = document.createElement('span');
                badge.className = 'retest-badge';
                badge.title = 'Retest already requested';
                badge.textContent = 'Pending';
                buttonToReplace.parentNode.replaceChild(badge, buttonToReplace);

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
            }
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Retest';
        }
    })
    .catch(function(error) {
        alert('Error: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Retest';
    });
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('retest-modal').style.display === 'flex') {
        closeRetestModal();
    }
});

// Close on overlay click
document.getElementById('retest-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeRetestModal();
    }
});
</script>
<?php endif; ?>

<!-- Notes Detail Modal -->
<div id="notes-detail-modal" class="notes-detail-overlay" style="display: none;">
    <div class="notes-detail-box">
        <div class="notes-detail-header">
            <h3 id="notes-detail-title">Test Details</h3>
            <button type="button" class="notes-detail-close" onclick="closeNotesDetailModal()">&times;</button>
        </div>
        <div class="notes-detail-body">
            <div class="notes-detail-section">
                <label>Test Key</label>
                <div class="notes-detail-value" id="notes-detail-key"></div>
            </div>
            <div class="notes-detail-section">
                <label>Test Name</label>
                <div class="notes-detail-value" id="notes-detail-name"></div>
            </div>
            <div class="notes-detail-section">
                <label>Status</label>
                <div class="notes-detail-value" id="notes-detail-status"></div>
            </div>
            <div class="notes-detail-section">
                <label>Version</label>
                <div class="notes-detail-value" id="notes-detail-version"></div>
            </div>
            <div class="notes-detail-section">
                <label>Notes</label>
                <div class="notes-detail-value notes-detail-notes" id="notes-detail-notes"></div>
            </div>
        </div>
        <div class="notes-detail-footer">
            <button type="button" class="btn btn-sm btn-secondary" onclick="closeNotesDetailModal()">Close</button>
        </div>
    </div>
</div>

<style>
.notes-detail-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.75);
    z-index: 10001;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notes-detail-box {
    background: var(--bg-card, #3e4637);
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 50px rgba(0, 0, 0, 0.6);
    border-top: solid 2px #899281;
    border-bottom: solid 2px #292d23;
    border-left: solid 2px #899281;
    border-right: solid 2px #292d23;
    animation: notesDetailSlideIn 0.2s ease-out;
}

@keyframes notesDetailSlideIn {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.notes-detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-accent);
    border-radius: 6px 6px 0 0;
}

.notes-detail-header h3 {
    margin: 0;
    color: var(--primary);
    font-size: 18px;
}

.notes-detail-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 28px;
    cursor: pointer;
    padding: 0 8px;
    line-height: 1;
}

.notes-detail-close:hover {
    color: #c45050;
}

.notes-detail-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

.notes-detail-section {
    margin-bottom: 16px;
}

.notes-detail-section:last-child {
    margin-bottom: 0;
}

.notes-detail-section label {
    display: block;
    color: var(--text-muted);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.notes-detail-value {
    color: var(--text);
    font-size: 15px;
    line-height: 1.5;
    padding: 10px 12px;
    background: var(--bg-dark);
    border-radius: 4px;
    border: 1px solid var(--border);
}

.notes-detail-notes {
    white-space: pre-wrap;
    word-wrap: break-word;
    max-height: 250px;
    overflow-y: auto;
    font-family: monospace;
    font-size: 13px;
}

.notes-detail-footer {
    padding: 15px 20px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Make notes cells clickable */
.notes-cell.has-notes {
    cursor: pointer;
}

.notes-cell.has-notes:hover {
    background: rgba(126, 166, 75, 0.1);
}
</style>

<!-- Rich Notes Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Note: Rich notes (images) are NOT rendered in table cells - only in modals
    // This keeps the table clean and readable with truncated text

    // Strip image markers from table cell display (but keep raw content in data-full for modal)
    document.querySelectorAll('.notes-cell.rich-notes .notes-content').forEach(function(contentEl) {
        var text = contentEl.textContent;
        // Remove {{IMAGE:...}} markers from display
        var cleanText = text.replace(/\{\{IMAGE:[^}]+\}\}/g, '').trim();
        if (cleanText !== text) {
            contentEl.textContent = cleanText || '(image only)';
        }
    });

    // Notes truncation detection
    initNotesTruncation();

    // Add click handler for notes cells
    document.querySelectorAll('.notes-cell.has-notes').forEach(function(cell) {
        cell.addEventListener('click', function(e) {
            // If click was on read-more, don't open modal
            if (e.target.classList.contains('notes-read-more')) return;

            var testKey = this.getAttribute('data-test-key');
            var testName = this.getAttribute('data-test-name');
            var status = this.getAttribute('data-status');
            var version = this.getAttribute('data-version');
            var notes = this.getAttribute('data-full');

            showNotesDetailModal(testKey, testName, status, version, notes);
        });
    });
});

function initNotesTruncation() {
    document.querySelectorAll('.notes-cell .notes-content').forEach(function(content) {
        // Check if content is truncated (scrollHeight > clientHeight)
        var cell = content.closest('.notes-cell');
        if (!cell) return;

        // Skip cells that are already expanded (e.g., rich-notes-rendered)
        if (cell.classList.contains('expanded')) return;

        // Use setTimeout to ensure rendering is complete
        setTimeout(function() {
            if (content.scrollHeight > content.clientHeight) {
                cell.classList.add('truncated');
            }
        }, 0);
    });

    // Handle read more click
    document.querySelectorAll('.notes-cell .notes-read-more').forEach(function(readMore) {
        readMore.addEventListener('click', function(e) {
            e.stopPropagation();
            var cell = this.closest('.notes-cell');
            if (cell) {
                cell.classList.remove('truncated');
                cell.classList.add('expanded');
            }
        });
    });

function showNotesDetailModal(testKey, testName, status, version, notes) {
    document.getElementById('notes-detail-title').textContent = 'Test Details: ' + testKey;
    document.getElementById('notes-detail-key').textContent = testKey;
    document.getElementById('notes-detail-name').textContent = testName;
    document.getElementById('notes-detail-version').textContent = version;

    // Set status with badge styling
    var statusBadge = getStatusBadgeHtml(status);
    document.getElementById('notes-detail-status').innerHTML = statusBadge;

    // Render notes with rich formatting if available
    var notesEl = document.getElementById('notes-detail-notes');
    if (typeof RichNotesRenderer !== 'undefined' && RichNotesRenderer.hasRichContent(notes)) {
        notesEl.innerHTML = RichNotesRenderer.render(notes);
    } else {
        notesEl.textContent = notes || 'No notes';
    }

    document.getElementById('notes-detail-modal').style.display = 'flex';
}

function closeNotesDetailModal() {
    document.getElementById('notes-detail-modal').style.display = 'none';
}

function getStatusBadgeHtml(status) {
    var statusClass = status.toLowerCase().replace(/\s+/g, '-');
    return '<span class="status-badge ' + statusClass + '">' + status + '</span>';
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeNotesDetailModal();
    }
});

// Close on overlay click
document.getElementById('notes-detail-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeNotesDetailModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
