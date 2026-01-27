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
    // Limit to 5 digits
    $steamuiVersion = substr($steamuiVersion, 0, 5);
    $steamPkgVersion = substr($steamPkgVersion, 0, 5);
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

// Get templates for the template selector
$templates = [];
try {
    $templates = $db->getTestTemplates();
} catch (Exception $e) {
    // Templates table may not exist yet
}
$defaultTemplate = null;
foreach ($templates as $t) {
    if ($t['is_default']) {
        $defaultTemplate = $t;
        break;
    }
}

// Get client versions for version-based template matching
$clientVersions = [];
try {
    $clientVersions = $db->getClientVersions();
} catch (Exception $e) {
    // Client versions table may not exist
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
                <label>Client Version * <span style="color: var(--status-broken); font-size: 11px;">(Select first to continue)</span></label>
                <select name="client_version" id="client_version" required>
                    <option value="">-- Select a client version --</option>
                    <?php if (!empty($clientVersions)): ?>
                        <?php foreach ($clientVersions as $version): ?>
                            <?php
                            $versionId = $version['version_id'] ?? '';
                            $displayName = trim($version['display_name'] ?? '');
                            $label = $displayName !== '' && $displayName !== $versionId
                                ? $displayName . ' (' . $versionId . ')'
                                : $versionId;
                            ?>
                            <option value="<?= e($versionId) ?>" <?= ($_POST['client_version'] ?? '') === $versionId ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No client versions available</option>
                    <?php endif; ?>
                </select>
                <small style="color: var(--text-muted);">Select the client version being tested</small>
            </div>
            <div class="form-group disabled-until-version">
                <label>Test Type</label>
                <select name="test_type" disabled>
                    <option value="WAN" <?= ($_POST['test_type'] ?? 'WAN') === 'WAN' ? 'selected' : '' ?>>WAN</option>
                    <option value="LAN" <?= ($_POST['test_type'] ?? '') === 'LAN' ? 'selected' : '' ?>>LAN</option>
                </select>
            </div>
            <div class="form-group disabled-until-version">
                <label>Commit Hash</label>
                <?php if (!empty($revisionOptions)): ?>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <select name="commit_hash" id="commit_hash_select" style="flex: 1;" disabled>
                            <option value="">-- Select a revision --</option>
                            <?php foreach ($revisionOptions as $opt): ?>
                                <option value="<?= e($opt['hash']) ?>" <?= ($_POST['commit_hash'] ?? '') === $opt['hash'] ? 'selected' : '' ?>>
                                    <?= e($opt['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a href="#" onclick="showRevisionNotes(); return false;" class="btn btn-sm btn-secondary disabled-until-version-btn" title="View Revision Notes">Notes</a>
                    </div>
                    <small style="color: var(--text-muted);">Select a git revision or leave blank</small>
                <?php else: ?>
                    <input type="text" name="commit_hash" value="<?= e($_POST['commit_hash'] ?? '') ?>" placeholder="Optional" disabled>
                    <small style="color: var(--text-muted);">Git commit hash if known</small>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group disabled-until-version" style="max-width: 180px;">
                <label>SteamUI PKG Version</label>
                <input type="text" name="steamui_version" value="<?= e($_POST['steamui_version'] ?? '') ?>"
                       placeholder="e.g., 12345" pattern="[0-9]*" maxlength="5" style="text-align: center; font-family: monospace;"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 5)" disabled>
            </div>
            <div class="form-group disabled-until-version" style="max-width: 180px;">
                <label>Steam PKG Version</label>
                <input type="text" name="steam_pkg_version" value="<?= e($_POST['steam_pkg_version'] ?? '') ?>"
                       placeholder="e.g., 12345" pattern="[0-9]*" maxlength="5" style="text-align: center; font-family: monospace;"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 5)" disabled>
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

    <!-- Auto-Applied Template Banner (shown when version is selected) -->
    <div id="template-banner" class="template-banner" style="display: none; margin-bottom: 30px;">
        <span class="template-icon">📋</span>
        <span id="template-banner-text">Template auto-applied</span>
    </div>

    <!-- Log Files Upload -->
    <div class="card disabled-section-until-version" id="log-upload-section" style="margin-bottom: 30px;">
        <h3 class="card-title" style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 20px;">📄</span>
            Debug Log Files
        </h3>
        <p style="color: var(--text-muted); margin-bottom: 15px;">
            Attach up to 3 debug log files (.log extension only). Maximum total size: 25MB.
        </p>

        <div class="log-upload-container">
            <div id="log-drop-zone" class="file-drop-zone" onclick="document.getElementById('log-file-input').click();">
                <div class="drop-zone-content" id="drop-zone-content">
                    <div style="font-size: 48px; margin-bottom: 10px; opacity: 0.6;">📁</div>
                    <p class="drop-zone-text">Drag &amp; drop log files here</p>
                    <p style="color: var(--text-muted); font-size: 12px; margin-bottom: 15px;">or</p>
                    <button type="button" class="btn btn-secondary" onclick="event.stopPropagation(); document.getElementById('log-file-input').click();">
                        Browse Files
                    </button>
                    <p style="color: var(--text-muted); font-size: 11px; margin-top: 10px;">
                        .log files only • Max 3 files • 25MB total
                    </p>
                </div>
            </div>
            <input type="file" id="log-file-input" name="log_files[]" multiple accept=".log"
                   style="display: none;" onchange="handleLogFileSelect(this.files)">

            <div id="log-files-list" style="margin-top: 15px; display: none;">
                <h4 style="font-size: 12px; color: var(--primary); margin-bottom: 10px;">Selected Files:</h4>
                <div id="log-files-items"></div>
                <div style="margin-top: 10px; font-size: 11px; color: var(--text-muted);">
                    Total size: <span id="log-files-total-size">0 KB</span> / 25 MB
                </div>
            </div>
        </div>
    </div>

    <!-- Test Results - Initially hidden until version selected -->
    <div class="card disabled-section-until-version" id="test-results-section">
        <h3 class="card-title">Test Results</h3>

        <!-- Placeholder shown when no version selected -->
        <div id="no-version-placeholder" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
            <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">📋</div>
            <p style="font-size: 16px; margin-bottom: 10px;">Please select a client version first</p>
            <p style="font-size: 13px;">Test questions will be loaded based on the version's assigned template.</p>
        </div>

        <!-- Loaded tests container -->
        <div id="tests-container" style="display: none;">
            <p style="color: var(--text-muted); margin-bottom: 20px;">
                Set the status for each test. Tests left as "N/A" will be recorded but not counted in statistics.
            </p>

            <div class="quick-actions" style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="btn btn-sm btn-secondary" onclick="setAllStatus('Working')">Set All Working</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="setAllStatus('Not working')">Set All Not Working</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="setAllStatus('N/A')">Set All N/A</button>
            </div>

            <!-- Tests will be loaded here via AJAX -->
            <div id="tests-list"></div>

            <div class="form-actions" style="margin-top: 20px;">
                <button type="submit" class="btn btn-lg" id="submit-btn" disabled>Create Report</button>
                <a href="?page=my_reports" class="btn btn-secondary btn-lg">Cancel</a>
            </div>
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

/* Consistent table column widths */
.category-section table {
    table-layout: fixed;
    width: 100%;
}

.category-section table th:nth-child(1),
.category-section table td:nth-child(1) {
    width: 60px;
    min-width: 60px;
}

.category-section table th:nth-child(2),
.category-section table td:nth-child(2) {
    width: 30%;
    min-width: 200px;
}

.category-section table th:nth-child(3),
.category-section table td:nth-child(3) {
    width: 150px;
    min-width: 150px;
}

.category-section table th:nth-child(4),
.category-section table td:nth-child(4) {
    width: auto;
}

/* Log upload styles */
.log-upload-container .file-drop-zone {
    flex-direction: column;
}

.log-upload-container .drop-zone-content {
    flex-direction: column;
    display: flex;
}

.log-upload-container .btn-remove {
    min-width: auto;
    width: 24px;
    height: 24px;
    padding: 0;
    border-top: solid 1px #a05555;
    border-bottom: solid 1px #663333;
    border-left: solid 1px #a05555;
    border-right: solid 1px #663333;
    background: #8b4444;
    color: white;
    font-size: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.log-upload-container .btn-remove:hover {
    background: #9b5454;
}

/* Status select */
.status-select {
    width: 100%;
    padding: 6px 10px;
    border-radius: 4px;
    border: 2px solid #899281;
    outline: 1px solid #292d23;
    background: var(--bg-dark);
    color: var(--text);
    font-size: 13px;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.2);
}

.status-select.working { border-color: var(--status-working); }
.status-select.partially-working { border-color: var(--status-semi); }
.status-select.semi-working { border-color: var(--status-semi); }
.status-select.not-working { border-color: var(--status-broken); }
.status-select.n\/a { border-color: var(--status-na); }

/* Notes input wrapper */
.notes-input-wrapper {
    position: relative;
}

/* BBCode toolbar */
.bbcode-toolbar {
    display: flex;
    gap: 2px;
    margin-bottom: 4px;
    padding: 3px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 4px 4px 0 0;
    border-bottom: none;
}

.bbcode-btn {
    padding: 3px 8px;
    font-size: 12px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 3px;
    color: var(--text);
    cursor: pointer;
    transition: all 0.15s ease;
    line-height: 1;
    min-width: 26px;
    text-align: center;
}

.bbcode-btn:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

.bbcode-btn:active {
    transform: scale(0.95);
}

/* Adjust textarea when toolbar is present */
.notes-input-wrapper .notes-textarea {
    border-radius: 0 0 4px 4px;
}

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

/* Notes textarea specific */
.notes-textarea {
    min-height: 32px;
    max-height: 150px;
    resize: vertical;
    font-family: inherit;
    line-height: 1.4;
    transition: border-color 0.2s, box-shadow 0.2s;
}

/* Drag over state */
.notes-textarea.dragover {
    border-color: var(--primary) !important;
    box-shadow: 0 0 0 2px rgba(126, 166, 75, 0.3);
    background: rgba(126, 166, 75, 0.1);
}

/* Image preview container */
.notes-image-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
    padding: 6px;
    background: var(--bg-dark);
    border: 1px dashed var(--border);
    border-radius: 4px;
}

.notes-image-preview:empty {
    display: none !important;
}

/* Image thumbnail in notes */
.notes-image-thumb {
    position: relative;
    display: inline-block;
}

.notes-image-thumb img {
    max-width: 60px;
    max-height: 60px;
    border: 2px solid var(--border);
    border-radius: 4px;
    cursor: pointer;
    transition: border-color 0.2s, transform 0.2s;
}

.notes-image-thumb img:hover {
    border-color: var(--primary);
    transform: scale(1.05);
}

.notes-image-thumb .remove-image {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 18px;
    height: 18px;
    background: #c45050;
    color: white;
    border: none;
    border-radius: 50%;
    font-size: 12px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
}

.notes-image-thumb:hover .remove-image {
    opacity: 1;
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

/* Template auto-filter banner */
.template-banner {
    background: linear-gradient(135deg, rgba(126, 166, 75, 0.15) 0%, rgba(126, 166, 75, 0.05) 100%);
    border: 1px solid rgba(126, 166, 75, 0.4);
    border-radius: 8px;
    padding: 12px 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text);
}

.template-banner .template-icon {
    font-size: 18px;
}

.template-banner .template-name {
    font-weight: 600;
    color: var(--primary);
}

.template-banner .template-count {
    font-size: 13px;
    color: var(--text-muted);
    margin-left: auto;
}

/* Disabled sections until version selected */
.disabled-section-until-version {
    opacity: 0.5;
    pointer-events: none;
    position: relative;
}

.disabled-section-until-version::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.1);
    border-radius: 8px;
}

.disabled-section-until-version.enabled {
    opacity: 1;
    pointer-events: auto;
}

.disabled-section-until-version.enabled::after {
    display: none;
}

/* Disabled form groups */
.disabled-until-version {
    opacity: 0.5;
}

.disabled-until-version.enabled {
    opacity: 1;
}

.disabled-until-version-btn {
    pointer-events: none;
    opacity: 0.5;
}

.disabled-until-version-btn.enabled {
    pointer-events: auto;
    opacity: 1;
}

/* Loading spinner for tests */
.tests-loading {
    text-align: center;
    padding: 40px;
    color: var(--text-muted);
}

.tests-loading .spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 3px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 15px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<script>
// Helper function to escape HTML - defined first for use throughout
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Set all statuses (works with dynamically created selects)
function setAllStatus(status) {
    document.querySelectorAll('.status-select').forEach(function(select) {
        select.value = status;
        select.className = 'status-select ' + status.toLowerCase().replace(/ /g, '-');
    });
}

// ==================== Version Selection and Form Enabling ====================

// Called when client version changes - loads tests and enables form
function onVersionChange() {
    var versionSelect = document.querySelector('select[name="client_version"]');
    var version = versionSelect ? versionSelect.value : '';

    if (!version) {
        disableFormSections();
        hideTemplateBanner();
        showNoVersionPlaceholder();
        return;
    }

    // Enable form sections
    enableFormSections();

    // Show loading state
    showTestsLoading();

    // Fetch tests for this version via API
    fetch('api/tests.php?client_version=' + encodeURIComponent(version))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                renderTests(data);
                if (data.template) {
                    showTemplateBanner(data.template.name, data.tests.length);
                } else {
                    hideTemplateBanner();
                }
            } else {
                showTestsError('Failed to load tests: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(function(err) {
            console.error('Error loading tests:', err);
            showTestsError('Error loading tests. Please try again.');
        });
}

// Disable all form sections until version is selected
function disableFormSections() {
    // Disable form groups
    document.querySelectorAll('.disabled-until-version').forEach(function(el) {
        el.classList.remove('enabled');
        var inputs = el.querySelectorAll('input, select, textarea');
        inputs.forEach(function(input) { input.disabled = true; });
    });

    // Disable buttons
    document.querySelectorAll('.disabled-until-version-btn').forEach(function(el) {
        el.classList.remove('enabled');
    });

    // Disable sections
    document.querySelectorAll('.disabled-section-until-version').forEach(function(el) {
        el.classList.remove('enabled');
    });

    // Disable submit button
    var submitBtn = document.getElementById('submit-btn');
    if (submitBtn) submitBtn.disabled = true;
}

// Enable all form sections after version is selected
function enableFormSections() {
    // Enable form groups
    document.querySelectorAll('.disabled-until-version').forEach(function(el) {
        el.classList.add('enabled');
        var inputs = el.querySelectorAll('input, select, textarea');
        inputs.forEach(function(input) { input.disabled = false; });
    });

    // Enable buttons
    document.querySelectorAll('.disabled-until-version-btn').forEach(function(el) {
        el.classList.add('enabled');
    });

    // Enable sections
    document.querySelectorAll('.disabled-section-until-version').forEach(function(el) {
        el.classList.add('enabled');
    });

    // Enable submit button
    var submitBtn = document.getElementById('submit-btn');
    if (submitBtn) submitBtn.disabled = false;
}

// Show placeholder when no version selected
function showNoVersionPlaceholder() {
    document.getElementById('no-version-placeholder').style.display = 'block';
    document.getElementById('tests-container').style.display = 'none';
}

// Show loading spinner while tests load
function showTestsLoading() {
    document.getElementById('no-version-placeholder').style.display = 'none';
    document.getElementById('tests-container').style.display = 'block';
    document.getElementById('tests-list').innerHTML =
        '<div class="tests-loading">' +
        '<div class="spinner"></div>' +
        '<p>Loading tests...</p>' +
        '</div>';
}

// Show error message
function showTestsError(message) {
    document.getElementById('tests-list').innerHTML =
        '<div class="alert alert-error">' + escapeHtml(message) + '</div>';
}

// Render tests from API response
function renderTests(data) {
    var container = document.getElementById('tests-list');
    var html = '';

    // Group tests by category
    var grouped = data.grouped || {};

    for (var categoryName in grouped) {
        if (!grouped.hasOwnProperty(categoryName)) continue;
        var tests = grouped[categoryName];

        html += '<div class="category-section">';
        html += '<h4 class="category-title">' + escapeHtml(categoryName) + '</h4>';
        html += '<div class="table-container"><table>';
        html += '<thead><tr>';
        html += '<th style="width: 60px; min-width: 60px;">Key</th>';
        html += '<th style="width: 30%; min-width: 200px;">Test Name</th>';
        html += '<th style="width: 150px; min-width: 150px;">Status</th>';
        html += '<th style="width: auto;">Notes</th>';
        html += '</tr></thead><tbody>';

        tests.forEach(function(test) {
            var testKey = test.test_key;
            var testName = test.name;
            var testDesc = test.description || '';

            html += '<tr data-test-key="' + escapeHtml(testKey) + '">';
            html += '<td style="font-family: monospace; font-weight: bold; color: var(--primary);">' + escapeHtml(testKey) + '</td>';
            html += '<td>';
            html += '<div style="font-weight: 500;">' + escapeHtml(testName) + '</div>';
            if (testDesc) {
                html += '<div style="font-size: 12px; color: var(--text-muted);">' + escapeHtml(testDesc) + '</div>';
            }
            html += '</td>';
            html += '<td>';
            html += '<select name="status[' + escapeHtml(testKey) + ']" class="status-select n/a">';
            html += '<option value="Working">Working</option>';
            html += '<option value="Partially Working">Partially Working</option>';
            html += '<option value="Semi-working">Semi-working</option>';
            html += '<option value="Not working">Not Working</option>';
            html += '<option value="N/A" selected>N/A</option>';
            html += '</select>';
            html += '</td>';
            html += '<td>';
            html += '<div class="notes-input-wrapper">';
            html += '<div class="bbcode-toolbar">';
            html += '<button type="button" class="bbcode-btn" data-tag="b" title="Bold [b][/b]"><b>B</b></button>';
            html += '<button type="button" class="bbcode-btn" data-tag="i" title="Italic [i][/i]"><i>I</i></button>';
            html += '<button type="button" class="bbcode-btn" data-tag="u" title="Underline [u][/u]"><u>U</u></button>';
            html += '<button type="button" class="bbcode-btn" data-tag="code" title="Code [code][/code]">&#x27E8;&#x27E9;</button>';
            html += '<button type="button" class="bbcode-btn" data-tag="url" title="Link [url=][/url]">&#x1F517;</button>';
            html += '<button type="button" class="bbcode-btn" data-tag="img" title="Image [img][/img]">&#x1F5BC;</button>';
            html += '</div>';
            html += '<textarea name="notes[' + escapeHtml(testKey) + ']" placeholder="Notes (BBCode, markdown, drag &amp; drop images)" class="notes-input notes-textarea" rows="1"></textarea>';
            html += '<div class="notes-image-preview" style="display: none;"></div>';
            html += '</div>';
            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div></div>';
    }

    container.innerHTML = html;

    // Re-initialize event listeners for dynamically created elements
    initStatusSelectListeners();
    initBBCodeToolbar();
    initNotesImageDragDrop();
}

// Initialize status select change listeners
function initStatusSelectListeners() {
    document.querySelectorAll('.status-select').forEach(function(select) {
        select.addEventListener('change', function() {
            this.className = 'status-select ' + this.value.toLowerCase().replace(/ /g, '-');
        });
    });
}

// ==================== BBCode Toolbar Functions ====================

function initBBCodeToolbar() {
    document.querySelectorAll('.bbcode-btn').forEach(function(btn) {
        // Remove existing listeners by cloning
        var newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);

        newBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var wrapper = this.closest('.notes-input-wrapper');
            var textarea = wrapper.querySelector('.notes-textarea');
            var tag = this.dataset.tag;

            if (tag === 'url') {
                insertBBCodeUrl(textarea);
            } else if (tag === 'img') {
                insertBBCodeImg(textarea);
            } else {
                insertBBCodeTag(textarea, tag);
            }
        });
    });
}

function insertBBCodeTag(textarea, tag) {
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var selectedText = textarea.value.substring(start, end);
    var before = textarea.value.substring(0, start);
    var after = textarea.value.substring(end);

    var openTag = '[' + tag + ']';
    var closeTag = '[/' + tag + ']';
    var newText = openTag + selectedText + closeTag;

    textarea.value = before + newText + after;

    if (selectedText) {
        textarea.selectionStart = start + openTag.length;
        textarea.selectionEnd = start + openTag.length + selectedText.length;
    } else {
        textarea.selectionStart = start + openTag.length;
        textarea.selectionEnd = start + openTag.length;
    }
    textarea.focus();
}

function insertBBCodeUrl(textarea) {
    var url = prompt('Enter URL:', 'https://');
    if (!url) return;

    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var selectedText = textarea.value.substring(start, end) || 'link text';
    var before = textarea.value.substring(0, start);
    var after = textarea.value.substring(end);

    var bbcode = '[url=' + url + ']' + selectedText + '[/url]';
    textarea.value = before + bbcode + after;

    textarea.selectionStart = start;
    textarea.selectionEnd = start + bbcode.length;
    textarea.focus();
}

function insertBBCodeImg(textarea) {
    var url = prompt('Enter image URL:', 'https://');
    if (!url) return;

    var start = textarea.selectionStart;
    var before = textarea.value.substring(0, start);
    var after = textarea.value.substring(textarea.selectionEnd);

    var bbcode = '[img]' + url + '[/img]';
    textarea.value = before + bbcode + after;

    textarea.selectionStart = start + bbcode.length;
    textarea.selectionEnd = start + bbcode.length;
    textarea.focus();
}

// Show template banner with info
function showTemplateBanner(templateName, testCount) {
    var banner = document.getElementById('template-banner');
    var bannerText = document.getElementById('template-banner-text');
    if (banner && bannerText) {
        bannerText.innerHTML = 'Template: <span class="template-name">' +
            escapeHtml(templateName || 'Version-specific') + '</span>' +
            '<span class="template-count">' + testCount + ' tests visible</span>';
        banner.style.display = 'flex';
    }
}

// Hide template banner
function hideTemplateBanner() {
    var banner = document.getElementById('template-banner');
    if (banner) {
        banner.style.display = 'none';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add change listener to client version dropdown
    var versionSelect = document.querySelector('select[name="client_version"]');
    if (versionSelect) {
        versionSelect.addEventListener('change', onVersionChange);
        // Check if version already selected (e.g., after form validation error)
        if (versionSelect.value) {
            onVersionChange();
        } else {
            // Initially disable form sections
            disableFormSections();
        }
    }
});

// ==================== Log File Upload Functions ====================

var selectedLogFiles = [];
var MAX_LOG_FILES = 3;
var MAX_TOTAL_SIZE = 25 * 1024 * 1024; // 25MB

// Initialize drag and drop
document.addEventListener('DOMContentLoaded', function() {
    var dropZone = document.getElementById('log-drop-zone');
    if (dropZone) {
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
            handleLogFileSelect(e.dataTransfer.files);
        });
    }
});

// Handle file selection
function handleLogFileSelect(files) {
    var validFiles = [];
    var errors = [];

    for (var i = 0; i < files.length; i++) {
        var file = files[i];

        // Check extension
        if (!file.name.toLowerCase().endsWith('.log')) {
            errors.push(file.name + ': Only .log files are allowed');
            continue;
        }

        validFiles.push(file);
    }

    // Check max files limit
    var totalFiles = selectedLogFiles.length + validFiles.length;
    if (totalFiles > MAX_LOG_FILES) {
        var canAdd = MAX_LOG_FILES - selectedLogFiles.length;
        if (canAdd > 0) {
            errors.push('Only ' + canAdd + ' more file(s) can be added. Maximum is ' + MAX_LOG_FILES + ' files.');
            validFiles = validFiles.slice(0, canAdd);
        } else {
            errors.push('Maximum ' + MAX_LOG_FILES + ' files allowed. Remove some files first.');
            validFiles = [];
        }
    }

    // Check total size
    var currentSize = selectedLogFiles.reduce(function(sum, f) { return sum + f.size; }, 0);
    var newSize = validFiles.reduce(function(sum, f) { return sum + f.size; }, 0);

    if (currentSize + newSize > MAX_TOTAL_SIZE) {
        errors.push('Total file size exceeds 25MB limit');
        // Try to add files that fit
        var tempFiles = [];
        var tempSize = currentSize;
        for (var j = 0; j < validFiles.length; j++) {
            if (tempSize + validFiles[j].size <= MAX_TOTAL_SIZE) {
                tempFiles.push(validFiles[j]);
                tempSize += validFiles[j].size;
            }
        }
        validFiles = tempFiles;
    }

    // Add valid files
    selectedLogFiles = selectedLogFiles.concat(validFiles);

    // Update display
    updateLogFilesDisplay();

    // Show errors if any
    if (errors.length > 0) {
        alert('Some files could not be added:\n\n' + errors.join('\n'));
    }
}

// Update the display of selected files
function updateLogFilesDisplay() {
    var listContainer = document.getElementById('log-files-list');
    var itemsContainer = document.getElementById('log-files-items');
    var totalSizeSpan = document.getElementById('log-files-total-size');
    var dropZoneContent = document.getElementById('drop-zone-content');

    if (selectedLogFiles.length === 0) {
        listContainer.style.display = 'none';
        dropZoneContent.style.display = 'flex';
        return;
    }

    listContainer.style.display = 'block';

    // Build file list HTML
    var html = '';
    var totalSize = 0;

    for (var i = 0; i < selectedLogFiles.length; i++) {
        var file = selectedLogFiles[i];
        totalSize += file.size;
        var sizeStr = formatFileSize(file.size);

        html += '<div class="log-file-item" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: var(--bg-dark); border: 1px solid var(--border); margin-bottom: 6px;">';
        html += '<div style="display: flex; align-items: center; gap: 10px;">';
        html += '<span style="font-size: 18px;">📄</span>';
        html += '<div>';
        html += '<div style="font-weight: 500; color: var(--text);">' + escapeHtml(file.name) + '</div>';
        html += '<div style="font-size: 11px; color: var(--text-muted);">' + sizeStr + '</div>';
        html += '</div>';
        html += '</div>';
        html += '<button type="button" class="btn-remove" onclick="removeLogFile(' + i + ')" title="Remove file">&times;</button>';
        html += '</div>';
    }

    itemsContainer.innerHTML = html;
    totalSizeSpan.textContent = formatFileSize(totalSize);

    // Update hidden file input with actual files via DataTransfer
    updateFileInput();
}

// Remove a file from the list
function removeLogFile(index) {
    selectedLogFiles.splice(index, 1);
    updateLogFilesDisplay();
}

// Format file size
function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

// Update the file input with selected files
function updateFileInput() {
    var fileInput = document.getElementById('log-file-input');
    var dataTransfer = new DataTransfer();

    for (var i = 0; i < selectedLogFiles.length; i++) {
        dataTransfer.items.add(selectedLogFiles[i]);
    }

    fileInput.files = dataTransfer.files;
}

// Revision data from PHP
var revisionsData = <?= json_encode($revisionsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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
    popup.style.cssText = 'background: rgba(76, 88, 68, 0.8); padding: 20px; border-radius: 8px; max-width: 600px; max-height: 80vh; overflow-y: auto; color: var(--text); box-shadow: 0 10px 40px rgba(0,0,0,0.5);';

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

// ==================== Image Drag & Drop for Notes ====================

// Initialize image drag/drop on all notes textareas
document.addEventListener('DOMContentLoaded', function() {
    initNotesImageDragDrop();
});

function initNotesImageDragDrop() {
    document.querySelectorAll('.notes-textarea').forEach(function(textarea) {
        // Prevent default drag behaviors
        textarea.addEventListener('dragenter', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });

        textarea.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            // Check if dragging images
            if (e.dataTransfer.types.includes('Files')) {
                this.classList.add('dragover');
            }
        });

        textarea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });

        textarea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');

            var files = e.dataTransfer.files;
            if (files.length > 0) {
                handleNotesImageDrop(this, files);
            }
        });

        // Also handle paste for images
        textarea.addEventListener('paste', function(e) {
            var items = e.clipboardData.items;
            for (var i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    e.preventDefault();
                    var file = items[i].getAsFile();
                    handleNotesImageDrop(this, [file]);
                    break;
                }
            }
        });
    });
}

function handleNotesImageDrop(textarea, files) {
    for (var i = 0; i < files.length; i++) {
        var file = files[i];

        // Only process image files
        if (!file.type.startsWith('image/')) {
            continue;
        }

        // Check file size (max 5MB per image)
        if (file.size > 5 * 1024 * 1024) {
            alert('Image "' + file.name + '" is too large. Maximum size is 5MB.');
            continue;
        }

        // Upload to server
        uploadImageToServer(file, textarea);
    }
}

function uploadImageToServer(file, textarea) {
    var formData = new FormData();
    formData.append('image', file);
    formData.append('action', 'upload_image');

    // Show uploading indicator
    var originalPlaceholder = textarea.placeholder;
    textarea.placeholder = 'Uploading image...';
    textarea.disabled = true;

    fetch('api/upload_image.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        textarea.placeholder = originalPlaceholder;
        textarea.disabled = false;

        if (data.success) {
            // Insert BBCode image with thumbnail linking to full image
            var bbcode = '[url=' + data.url + '][img]' + data.thumbnail_url + '[/img][/url]';
            insertTextAtCursor(textarea, bbcode);
            autoExpandTextarea(textarea);

            // Show preview
            var wrapper = textarea.closest('.notes-input-wrapper');
            var previewContainer = wrapper.querySelector('.notes-image-preview');
            showImagePreviewFromUrl(previewContainer, data.thumbnail_url, data.url, textarea, bbcode);
        } else {
            alert('Upload failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(function(error) {
        textarea.placeholder = originalPlaceholder;
        textarea.disabled = false;
        alert('Upload failed: ' + error.message);
    });
}

function insertTextAtCursor(textarea, text) {
    var startPos = textarea.selectionStart;
    var endPos = textarea.selectionEnd;
    var before = textarea.value.substring(0, startPos);
    var after = textarea.value.substring(endPos);

    // Add newline before if not at start and not already preceded by newline
    if (before.length > 0 && !before.endsWith('\n')) {
        text = '\n' + text;
    }

    textarea.value = before + text + after;

    // Move cursor after inserted text
    var newPos = before.length + text.length;
    textarea.selectionStart = newPos;
    textarea.selectionEnd = newPos;
    textarea.focus();
}

function autoExpandTextarea(textarea) {
    // Temporarily reset height to auto to get scrollHeight
    textarea.style.height = 'auto';
    var newHeight = Math.min(textarea.scrollHeight, 150); // max 150px
    textarea.style.height = newHeight + 'px';
}

function showImagePreview(container, dataUri, textarea, marker) {
    showImagePreviewFromUrl(container, dataUri, dataUri, textarea, marker);
}

function showImagePreviewFromUrl(container, thumbnailUrl, fullUrl, textarea, marker) {
    container.style.display = 'flex';

    var thumb = document.createElement('div');
    thumb.className = 'notes-image-thumb';

    var img = document.createElement('img');
    img.src = thumbnailUrl;
    img.alt = 'Uploaded image';
    img.title = 'Click to view full size';
    img.onclick = function() {
        window.open(fullUrl, '_blank');
    };

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'remove-image';
    removeBtn.innerHTML = '&times;';
    removeBtn.title = 'Remove image';
    removeBtn.onclick = function(e) {
        e.stopPropagation();
        // Remove the marker from textarea
        textarea.value = textarea.value.replace(marker, '');
        // Remove the thumbnail
        thumb.remove();
        // Hide preview container if empty
        if (container.children.length === 0) {
            container.style.display = 'none';
        }
        // Re-adjust textarea height
        autoExpandTextarea(textarea);
    };

    thumb.appendChild(img);
    thumb.appendChild(removeBtn);
    container.appendChild(thumb);
}

function openImagePreviewModal(dataUri) {
    // Remove existing modal if any
    var existingModal = document.getElementById('image-preview-modal');
    if (existingModal) {
        existingModal.remove();
    }

    var modal = document.createElement('div');
    modal.id = 'image-preview-modal';
    modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 30000; display: flex; align-items: center; justify-content: center; cursor: pointer;';

    var content = document.createElement('div');
    content.style.cssText = 'position: relative; max-width: 90%; max-height: 90%; cursor: default;';

    var img = document.createElement('img');
    img.src = dataUri;
    img.style.cssText = 'max-width: 100%; max-height: 85vh; border: 3px solid var(--border); border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5);';

    var closeBtn = document.createElement('button');
    closeBtn.innerHTML = '&times;';
    closeBtn.style.cssText = 'position: absolute; top: -15px; right: -15px; width: 36px; height: 36px; background: var(--bg-card); color: var(--text); border: 2px solid var(--border); border-radius: 50%; font-size: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center;';
    closeBtn.onclick = function() {
        modal.remove();
    };

    content.appendChild(img);
    content.appendChild(closeBtn);
    modal.appendChild(content);

    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.remove();
        }
    });

    // Close on Escape
    var closeOnEscape = function(e) {
        if (e.key === 'Escape') {
            modal.remove();
            document.removeEventListener('keydown', closeOnEscape);
        }
    };
    document.addEventListener('keydown', closeOnEscape);

    document.body.appendChild(modal);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
