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
                <label>Client Version *</label>
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

    <!-- Template Selection -->
    <?php if (!empty($templates)): ?>
    <div class="card" style="margin-bottom: 30px;">
        <h3 class="card-title" style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 20px;">📋</span>
            Test Template
        </h3>
        <p style="color: var(--text-muted); margin-bottom: 15px;">
            Apply a template to quickly set up tests. Tests in the template will be set to the specified default status,
            and tests NOT in the template will be set to N/A.
        </p>

        <div class="form-row">
            <div class="form-group" style="flex: 2;">
                <label>Select Template</label>
                <select id="template_select" onchange="onTemplateChange()">
                    <option value="">-- No template (manual entry) --</option>
                    <?php foreach ($templates as $template): ?>
                        <option value="<?= $template['id'] ?>"
                                data-test-keys="<?= e(json_encode($template['test_keys'])) ?>"
                                <?= $template['is_default'] ? 'data-default="1"' : '' ?>>
                            <?= e($template['name']) ?>
                            <?php if ($template['is_default']): ?> (Default)<?php endif; ?>
                            <?php if ($template['is_system']): ?> [System]<?php endif; ?>
                            (<?= count($template['test_keys']) ?> tests)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex: 1;">
                <label>Default Status for Template Tests</label>
                <select id="template_default_status">
                    <option value="Working">Working</option>
                    <option value="Semi-working">Semi-working</option>
                    <option value="Not working">Not working</option>
                    <option value="">No change (keep current)</option>
                </select>
            </div>
            <div class="form-group" style="flex: 0 0 auto; display: flex; align-items: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="applyTemplate()" id="apply_template_btn" disabled>
                    Apply Template
                </button>
            </div>
        </div>

        <div id="template_description" style="margin-top: 10px; padding: 10px; background: var(--bg-accent); border-radius: 4px; display: none;">
            <strong>Template:</strong> <span id="template_name"></span><br>
            <span id="template_desc" style="color: var(--text-muted);"></span>
        </div>

        <!-- Version-based skip tests (auto-applies when version is entered) -->
        <?php if (!empty($clientVersions)): ?>
        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border);">
            <p style="color: var(--text-muted); font-size: 13px;">
                <strong>💡 Tip:</strong> Some client versions have pre-configured skip tests. When you select a matching
                client version above, tests that are known to be N/A for that version will be automatically marked.
            </p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Log Files Upload -->
    <div class="card" style="margin-bottom: 30px;">
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
                                <th style="width: 60px; min-width: 60px;">Key</th>
                                <th style="width: 30%; min-width: 200px;">Test Name</th>
                                <th style="width: 150px; min-width: 150px;">Status</th>
                                <th style="width: auto;">Notes</th>
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
                                        <div class="notes-input-wrapper">
                                            <textarea name="notes[<?= e($testKey) ?>]"
                                                      placeholder="Notes (drag &amp; drop images)"
                                                      class="notes-input notes-textarea"
                                                      rows="1"><?= e($currentNotes) ?></textarea>
                                            <div class="notes-image-preview" style="display: none;"></div>
                                        </div>
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
</style>

<script>
// Update select color on change
document.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', function() {
        // Replace all spaces with dashes for proper class name
        this.className = 'status-select ' + this.value.toLowerCase().replace(/ /g, '-');
    });
});

// Set all statuses
function setAllStatus(status) {
    document.querySelectorAll('.status-select').forEach(select => {
        select.value = status;
        // Replace all spaces with dashes for proper class name
        select.className = 'status-select ' + status.toLowerCase().replace(/ /g, '-');
    });
}

// ==================== Template Functions ====================

// Template data from PHP
var templatesData = <?= json_encode(array_map(function($t) {
    return [
        'id' => $t['id'],
        'name' => $t['name'],
        'description' => $t['description'],
        'test_keys' => $t['test_keys'],
        'is_default' => $t['is_default']
    ];
}, $templates), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Client version skip tests data
var clientVersionsData = <?= json_encode(array_map(function($v) {
    return [
        'id' => $v['id'],
        'version_id' => $v['version_id'],
        'skip_tests' => json_decode($v['skip_tests'] ?? '[]', true) ?: []
    ];
}, $clientVersions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Handle template selection change
function onTemplateChange() {
    var select = document.getElementById('template_select');
    var applyBtn = document.getElementById('apply_template_btn');
    var descDiv = document.getElementById('template_description');

    if (select.value) {
        applyBtn.disabled = false;
        // Find template data
        var template = templatesData.find(t => t.id == select.value);
        if (template) {
            document.getElementById('template_name').textContent = template.name;
            document.getElementById('template_desc').textContent = template.description || 'No description';
            descDiv.style.display = 'block';
        }
    } else {
        applyBtn.disabled = true;
        descDiv.style.display = 'none';
    }
}

// Apply selected template
function applyTemplate() {
    var select = document.getElementById('template_select');
    var defaultStatus = document.getElementById('template_default_status').value;

    if (!select.value) {
        alert('Please select a template first.');
        return;
    }

    // Find template data
    var template = templatesData.find(t => t.id == select.value);
    if (!template) {
        alert('Template not found.');
        return;
    }

    var testKeys = template.test_keys || [];
    var testKeysSet = new Set(testKeys);
    var applied = 0;
    var skipped = 0;

    // Apply to all status selects
    document.querySelectorAll('.status-select').forEach(select => {
        // Extract test key from the select name: status[test_key]
        var match = select.name.match(/status\[([^\]]+)\]/);
        if (!match) return;

        var testKey = match[1];

        if (testKeysSet.has(testKey)) {
            // Test is in template - set to default status (if specified)
            if (defaultStatus) {
                select.value = defaultStatus;
                select.className = 'status-select ' + defaultStatus.toLowerCase().replace(' ', '-');
            }
            applied++;
        } else {
            // Test is NOT in template - set to N/A
            select.value = 'N/A';
            select.className = 'status-select n/a';
            skipped++;
        }
    });

    alert('Template applied!\n\n' + applied + ' tests set to "' + (defaultStatus || 'unchanged') + '"\n' + skipped + ' tests set to "N/A"');
}

// Apply version-based skip tests when client version changes
function applyVersionSkipTests() {
    var versionSelect = document.querySelector('select[name="client_version"]');
    if (!versionSelect) return;

    var version = versionSelect.value.trim();
    if (!version) return;

    // Find matching client version
    var clientVersion = clientVersionsData.find(v =>
        v.version_id === version || v.version_id.includes(version) || version.includes(v.version_id)
    );

    if (!clientVersion || !clientVersion.skip_tests || clientVersion.skip_tests.length === 0) {
        return;
    }

    var skipTests = new Set(clientVersion.skip_tests);
    var skipped = 0;

    document.querySelectorAll('.status-select').forEach(select => {
        var match = select.name.match(/status\[([^\]]+)\]/);
        if (!match) return;

        var testKey = match[1];
        if (skipTests.has(testKey)) {
            select.value = 'N/A';
            select.className = 'status-select n/a';
            skipped++;
        }
    });

    if (skipped > 0) {
        console.log('Auto-skipped ' + skipped + ' tests for version: ' + version);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add change listener to client version dropdown for auto-skip
    var versionSelect = document.querySelector('select[name="client_version"]');
    if (versionSelect) {
        versionSelect.addEventListener('change', applyVersionSkipTests);
    }

    // Initialize template selector state
    onTemplateChange();
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
    var wrapper = textarea.closest('.notes-input-wrapper');
    var previewContainer = wrapper.querySelector('.notes-image-preview');

    for (var i = 0; i < files.length; i++) {
        var file = files[i];

        // Only process image files
        if (!file.type.startsWith('image/')) {
            continue;
        }

        // Check file size (max 2MB per image)
        if (file.size > 2 * 1024 * 1024) {
            alert('Image "' + file.name + '" is too large. Maximum size is 2MB.');
            continue;
        }

        // Read file and convert to base64
        (function(f, ta, pc) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var dataUri = e.target.result;

                // Insert image marker at cursor position or end
                var marker = '{{IMAGE:' + dataUri + '}}';
                insertTextAtCursor(ta, marker);

                // Auto-expand textarea if needed
                autoExpandTextarea(ta);

                // Show preview
                showImagePreview(pc, dataUri, ta, marker);
            };
            reader.readAsDataURL(f);
        })(file, textarea, previewContainer);
    }
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
    container.style.display = 'flex';

    var thumb = document.createElement('div');
    thumb.className = 'notes-image-thumb';

    var img = document.createElement('img');
    img.src = dataUri;
    img.alt = 'Embedded image';
    img.title = 'Click to view full size';
    img.onclick = function() {
        openImagePreviewModal(dataUri);
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
