<?php
/**
 * Create Report Page
 * Allows users to manually create a new test report
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();
$user = getCurrentUser();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientVersion = trim($_POST['client_version'] ?? '');
    $testType = $_POST['test_type'] ?? 'WAN';
    $commitHash = trim($_POST['commit_hash'] ?? '');
    $steamuiVersion = preg_replace('/[^0-9]/', '', $_POST['steamui_version'] ?? '');
    $steamPkgVersion = preg_replace('/[^0-9]/', '', $_POST['steam_pkg_version'] ?? '');
    // Limit to 9 digits
    $steamuiVersion = substr($steamuiVersion, 0, 9);
    $steamPkgVersion = substr($steamPkgVersion, 0, 9);
    $tester = $user['username'];
    $statuses = $_POST['status'] ?? [];
    $notes = $_POST['notes'] ?? [];

    if (empty($clientVersion)) {
        $error = 'Client version is required.';
    } else {
        // Check if at least one test has a status other than N/A
        $hasTests = false;
        foreach ($statuses as $status) {
            if ($status !== 'N/A') {
                $hasTests = true;
                break;
            }
        }

        if (!$hasTests) {
            $error = 'Please fill in at least one test result.';
        } else {
            try {
                // Build a results array for raw_json
                $resultsForJson = [];
                foreach ($statuses as $testKey => $status) {
                    $resultsForJson[$testKey] = [
                        'status' => $status,
                        'notes' => $notes[$testKey] ?? ''
                    ];
                }

                $rawJson = json_encode([
                    'metadata' => [
                        'tester_name' => $tester,
                        'commit_hash' => $commitHash,
                        'test_type' => $testType,
                        'steamui_version' => $steamuiVersion ?: null,
                        'steam_pkg_version' => $steamPkgVersion ?: null
                    ],
                    'tests' => [
                        $clientVersion => $resultsForJson
                    ],
                    'manual_entry' => true,
                    'created_at' => date('c')
                ]);

                // Create the report
                $reportId = $db->insertReport(
                    $tester,
                    $commitHash,
                    $testType,
                    $clientVersion,
                    $rawJson,
                    null, // No test duration for manual entries
                    $steamuiVersion ?: null,
                    $steamPkgVersion ?: null
                );

                if ($reportId) {
                    // Insert test results
                    foreach ($statuses as $testKey => $status) {
                        $note = $notes[$testKey] ?? '';
                        $db->insertTestResult($reportId, $testKey, $status, $note);
                    }

                    setFlash('success', 'Report created successfully!');
                    header('Location: ?page=report_detail&id=' . $reportId);
                    exit;
                } else {
                    $error = 'Failed to create report. Please try again.';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Get test categories and types for the form
// Try to use database-stored test types first, fall back to static TEST_KEYS
try {
    if ($db->hasTestCategoriesTable()) {
        $categories = $db->getTestTypesGrouped(true);
    } else {
        $categories = getTestCategories();
    }
} catch (Exception $e) {
    $categories = getTestCategories();
}

// Fetch latest GitHub revisions (this will update the cache if needed)
$revisionOptions = [];
$revisionsData = [];
if (isGitHubConfigured()) {
    $revisionsData = getGitHubRevisions(true); // Force refresh when page loads
    $revisionOptions = getRevisionDropdownOptions();
}
?>

<div class="report-header">
    <div>
        <h1 class="page-title">Create New Report</h1>
        <p style="color: var(--text-muted);">
            Manually create a new test report
        </p>
    </div>
    <div>
        <a href="?page=my_reports" class="btn btn-secondary">&larr; My Reports</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<form method="POST">
    <!-- Report Metadata -->
    <div class="card" style="margin-bottom: 30px;">
        <h3 class="card-title">Report Information</h3>

        <div class="form-row">
            <div class="form-group">
                <label>Client Version *</label>
                <input type="text" name="client_version" value="<?= e($_POST['client_version'] ?? '') ?>" required
                       placeholder="e.g., 1.0.0 or Steam Client v1234">
                <small style="color: var(--text-muted);">The version of the client being tested</small>
            </div>
            <div class="form-group">
                <label>Test Type</label>
                <select name="test_type">
                    <option value="WAN" <?= ($_POST['test_type'] ?? 'WAN') === 'WAN' ? 'selected' : '' ?>>WAN</option>
                    <option value="LAN" <?= ($_POST['test_type'] ?? '') === 'LAN' ? 'selected' : '' ?>>LAN</option>
                </select>
            </div>
            <div class="form-group">
                <label>Commit Hash</label>
                <?php if (!empty($revisionOptions)): ?>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <select name="commit_hash" id="commit_hash_select" style="flex: 1;">
                            <option value="">-- Select a revision --</option>
                            <?php foreach ($revisionOptions as $opt): ?>
                                <option value="<?= e($opt['hash']) ?>" <?= ($_POST['commit_hash'] ?? '') === $opt['hash'] ? 'selected' : '' ?>>
                                    <?= e($opt['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a href="#" onclick="showRevisionNotes(); return false;" class="btn btn-sm btn-secondary" title="View Revision Notes">Notes</a>
                    </div>
                    <small style="color: var(--text-muted);">Select a git revision or leave blank</small>
                <?php else: ?>
                    <input type="text" name="commit_hash" value="<?= e($_POST['commit_hash'] ?? '') ?>" placeholder="Optional">
                    <small style="color: var(--text-muted);">Git commit hash if known</small>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>SteamUI Version</label>
                <input type="text" name="steamui_version" value="<?= e($_POST['steamui_version'] ?? '') ?>"
                       placeholder="e.g., 123456789" pattern="[0-9]*" maxlength="9"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 9)">
                <small style="color: var(--text-muted);">Numbers only, max 9 digits</small>
            </div>
            <div class="form-group">
                <label>Steam PKG Version</label>
                <input type="text" name="steam_pkg_version" value="<?= e($_POST['steam_pkg_version'] ?? '') ?>"
                       placeholder="e.g., 123456789" pattern="[0-9]*" maxlength="9"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 9)">
                <small style="color: var(--text-muted);">Numbers only, max 9 digits</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Tester</label>
                <input type="text" value="<?= e($user['username']) ?>" disabled>
                <small style="color: var(--text-muted);">Automatically set to your username</small>
            </div>
            <div class="form-group">
                <label>Submission Date</label>
                <input type="text" value="<?= date('F j, Y g:i A') ?>" disabled>
                <small style="color: var(--text-muted);">Automatically set to current date/time</small>
            </div>
        </div>
    </div>

    <!-- Log Files Note -->
    <div class="card" style="margin-bottom: 30px;">
        <h3 class="card-title" style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 20px;">📄</span>
            Debug Log Files
        </h3>
        <p style="color: var(--text-muted);">
            After creating your report, you can attach up to 3 debug log files (.txt or .log) by editing the report.
            Log files will be compressed automatically for efficient storage.
        </p>
    </div>

    <!-- Test Results -->
    <div class="card">
        <h3 class="card-title">Test Results</h3>
        <p style="color: var(--text-muted); margin-bottom: 20px;">
            Set the status for each test. Tests left as "N/A" will be recorded but not counted in statistics.
        </p>

        <div class="quick-actions" style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" class="btn btn-sm btn-secondary" onclick="setAllStatus('Working')">Set All Working</button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="setAllStatus('Not working')">Set All Not Working</button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="setAllStatus('N/A')">Set All N/A</button>
        </div>

        <?php foreach ($categories as $categoryName => $tests): ?>
            <div class="category-section">
                <h4 class="category-title"><?= e($categoryName) ?></h4>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 60px;">Key</th>
                                <th>Test Name</th>
                                <th style="width: 180px;">Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tests as $testKey => $testInfo): ?>
                                <?php
                                $currentStatus = $_POST['status'][$testKey] ?? 'N/A';
                                $currentNotes = $_POST['notes'][$testKey] ?? '';
                                ?>
                                <tr>
                                    <td style="font-family: monospace; font-weight: bold; color: var(--primary);">
                                        <?= e($testKey) ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?= e($testInfo['name']) ?></div>
                                        <?php if (!empty($testInfo['expected'])): ?>
                                            <div style="font-size: 12px; color: var(--text-muted);">
                                                <?= e($testInfo['expected']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select name="status[<?= e($testKey) ?>]" class="status-select <?= strtolower(str_replace(' ', '-', $currentStatus)) ?>">
                                            <option value="Working" <?= $currentStatus === 'Working' ? 'selected' : '' ?>>Working</option>
                                            <option value="Partially Working" <?= $currentStatus === 'Partially Working' ? 'selected' : '' ?>>Partially Working</option>
                                            <option value="Semi-working" <?= $currentStatus === 'Semi-working' ? 'selected' : '' ?>>Semi-working</option>
                                            <option value="Not working" <?= $currentStatus === 'Not working' ? 'selected' : '' ?>>Not Working</option>
                                            <option value="N/A" <?= $currentStatus === 'N/A' ? 'selected' : '' ?>>N/A</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="notes[<?= e($testKey) ?>]"
                                               value="<?= e($currentNotes) ?>"
                                               placeholder="Optional notes"
                                               class="notes-input">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="form-actions" style="margin-top: 20px;">
            <button type="submit" class="btn btn-lg">Create Report</button>
            <a href="?page=my_reports" class="btn btn-secondary btn-lg">Cancel</a>
        </div>
    </div>
</form>

<style>
/* Form styles */
.form-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.form-row .form-group {
    flex: 1;
    min-width: 200px;
}

/* Category section */
.category-section {
    margin-bottom: 30px;
}

.category-title {
    color: var(--primary);
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}

/* Status select */
.status-select {
    width: 100%;
    padding: 6px 10px;
    border-radius: 4px;
    border: 1px solid var(--border);
    background: var(--bg-dark);
    color: var(--text);
    font-size: 13px;
}

.status-select.working { border-color: var(--status-working); }
.status-select.partially-working { border-color: var(--status-semi); }
.status-select.semi-working { border-color: var(--status-semi); }
.status-select.not-working { border-color: var(--status-broken); }
.status-select.n\/a { border-color: var(--status-na); }

/* Notes input */
.notes-input {
    width: 100%;
    padding: 6px 10px;
    border-radius: 4px;
    border: 1px solid var(--border);
    background: var(--bg-dark);
    color: var(--text);
    font-size: 13px;
}

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

/* Large button - uses global .btn-lg from style.css */

/* Form actions */
.form-actions {
    text-align: center;
    padding: 20px;
    background: var(--bg-accent);
    border-radius: 8px;
    display: flex;
    gap: 15px;
    justify-content: center;
}
</style>

<script>
// Update select color on change
document.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', function() {
        this.className = 'status-select ' + this.value.toLowerCase().replace(' ', '-');
    });
});

// Set all statuses
function setAllStatus(status) {
    document.querySelectorAll('.status-select').forEach(select => {
        select.value = status;
        select.className = 'status-select ' + status.toLowerCase().replace(' ', '-');
    });
}

// Revision data from PHP
var revisionsData = <?= json_encode($revisionsData) ?>;

// Show revision notes popup
function showRevisionNotes() {
    var select = document.getElementById('commit_hash_select');
    if (!select) {
        alert('No revision dropdown available.');
        return;
    }

    var sha = select.value;
    if (!sha) {
        alert('Please select a revision first.');
        return;
    }

    var revision = revisionsData[sha];
    if (!revision) {
        alert('Revision data not found.');
        return;
    }

    // Format the content
    var dateTime = revision.ts ? new Date(revision.ts * 1000).toLocaleString() : 'Unknown';
    var notes = revision.notes || 'No commit message';
    var files = revision.files || {};

    var content = '<div style="text-align: left;">';
    content += '<p><strong>Date:</strong> ' + escapeHtml(dateTime) + '</p>';
    content += '<p><strong>Commit Message:</strong></p>';
    content += '<pre style="white-space: pre-wrap; word-wrap: break-word; background: var(--bg-dark); padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;">' + escapeHtml(notes) + '</pre>';

    if (files.added && files.added.length > 0) {
        content += '<p><strong>Files Added:</strong></p>';
        content += '<ul style="max-height: 100px; overflow-y: auto; font-size: 12px;">';
        files.added.forEach(function(f) { content += '<li>' + escapeHtml(f) + '</li>'; });
        content += '</ul>';
    }

    if (files.removed && files.removed.length > 0) {
        content += '<p><strong>Files Removed:</strong></p>';
        content += '<ul style="max-height: 100px; overflow-y: auto; font-size: 12px;">';
        files.removed.forEach(function(f) { content += '<li>' + escapeHtml(f) + '</li>'; });
        content += '</ul>';
    }

    if (files.modified && files.modified.length > 0) {
        content += '<p><strong>Files Modified:</strong></p>';
        content += '<ul style="max-height: 100px; overflow-y: auto; font-size: 12px;">';
        files.modified.forEach(function(f) { content += '<li>' + escapeHtml(f) + '</li>'; });
        content += '</ul>';
    }

    content += '</div>';

    showPopup('Revision: ' + sha.substring(0, 8), content);
}

// Helper function to escape HTML
function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show popup dialog
function showPopup(title, content) {
    // Remove existing popup if any
    var existingPopup = document.getElementById('revision-popup-overlay');
    if (existingPopup) {
        existingPopup.remove();
    }

    var overlay = document.createElement('div');
    overlay.id = 'revision-popup-overlay';
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center;';

    var popup = document.createElement('div');
    popup.style.cssText = 'background: var(--bg-card); padding: 20px; border-radius: 8px; max-width: 600px; max-height: 80vh; overflow-y: auto; color: var(--text); box-shadow: 0 10px 40px rgba(0,0,0,0.5);';

    popup.innerHTML = '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">' +
        '<h3 style="margin: 0; color: var(--primary);">' + escapeHtml(title) + '</h3>' +
        '<button onclick="document.getElementById(\'revision-popup-overlay\').remove();" style="background: none; border: none; color: var(--text); font-size: 24px; cursor: pointer;">&times;</button>' +
        '</div>' + content;

    overlay.appendChild(popup);
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.remove();
        }
    });

    document.body.appendChild(overlay);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
