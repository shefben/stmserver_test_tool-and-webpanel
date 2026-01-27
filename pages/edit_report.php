<?php
/**
 * Edit Report Page
 * Users can edit their own reports, admins can edit any report
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();
$user = getCurrentUser();

// Get report ID
$reportId = intval($_GET['id'] ?? 0);

if (!$reportId) {
    setFlash('error', 'Invalid report ID');
    header('Location: ?page=reports');
    exit;
}

// Get report
$report = $db->getReport($reportId);

if (!$report) {
    setFlash('error', 'Report not found');
    header('Location: ?page=reports');
    exit;
}

// Check permissions
if (!canEditReport($reportId)) {
    setFlash('error', 'You do not have permission to edit this report.');
    header('Location: ?page=report_detail&id=' . $reportId);
    exit;
}

// Get test results
$testResults = $db->getTestResults($reportId);

// Get attached logs
$attachedLogs = $db->getReportLogs($reportId);

// Organize by key for easy lookup
$resultsByKey = [];
foreach ($testResults as $result) {
    $resultsByKey[$result['test_key']] = $result;
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_meta') {
        // Update report metadata
        $steamuiVersion = preg_replace('/[^0-9]/', '', $_POST['steamui_version'] ?? '');
        $steamPkgVersion = preg_replace('/[^0-9]/', '', $_POST['steam_pkg_version'] ?? '');
        // Limit to 9 digits
        $steamuiVersion = substr($steamuiVersion, 0, 9);
        $steamPkgVersion = substr($steamPkgVersion, 0, 9);

        $updateData = [
            'client_version' => trim($_POST['client_version'] ?? ''),
            'test_type' => $_POST['test_type'] ?? '',
            'commit_hash' => trim($_POST['commit_hash'] ?? ''),
            'steamui_version' => $steamuiVersion ?: null,
            'steam_pkg_version' => $steamPkgVersion ?: null
        ];

        if (empty($updateData['client_version'])) {
            $error = 'Client version is required.';
        } else {
            if ($db->updateReport($reportId, $updateData)) {
                $success = 'Report metadata updated successfully.';
                // Refresh report data
                $report = $db->getReport($reportId);
            } else {
                $error = 'Failed to update report.';
            }
        }
    }

    if ($action === 'update_results') {
        // Update test results
        $statuses = $_POST['status'] ?? [];
        $notes = $_POST['notes'] ?? [];
        $updated = 0;

        foreach ($statuses as $resultId => $status) {
            $note = $notes[$resultId] ?? '';
            if ($db->updateTestResult(intval($resultId), $status, $note)) {
                $updated++;
            }
        }

        if ($updated > 0) {
            $success = "Updated $updated test result(s).";
            // Refresh test results
            $testResults = $db->getTestResults($reportId);
            $resultsByKey = [];
            foreach ($testResults as $result) {
                $resultsByKey[$result['test_key']] = $result;
            }
        } else {
            $error = 'No changes were made.';
        }
    }
}

// Get categories filtered by template for this version
$templateData = $db->getVisibleTestsForVersion($report['client_version'], true);
$categories = $templateData['categories'];
$appliedTemplate = $templateData['template'];

// Get pending retest requests map for this version
$pendingRetestMap = $db->getPendingRetestRequestsMap();
$clientVersion = $report['client_version'];
?>

<div class="report-header">
    <div>
        <h1 class="page-title">Edit Report #<?= $reportId ?></h1>
        <p style="color: var(--text-muted);">
            <?= e($report['client_version']) ?> by <?= e($report['tester']) ?>
        </p>
    </div>
    <div>
        <a href="?page=report_detail&id=<?= $reportId ?>" class="btn btn-secondary">View Report</a>
        <?php if (isAdmin()): ?>
            <a href="?page=admin_reports" class="btn btn-secondary">&larr; Back to Admin</a>
        <?php else: ?>
            <a href="?page=reports" class="btn btn-secondary">&larr; Back to Reports</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($appliedTemplate && !$appliedTemplate['is_default']): ?>
<div class="template-banner" style="margin-bottom: 20px;">
    <span class="template-icon">📋</span>
    <span>Template: <strong class="template-name"><?= e($appliedTemplate['name']) ?></strong></span>
    <span class="template-count"><?= count($appliedTemplate['test_keys']) ?> tests visible</span>
</div>
<?php endif; ?>

<!-- Report Metadata -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">Report Information</h3>
    <form method="POST">
        <input type="hidden" name="action" value="update_meta">

        <div class="form-row">
            <div class="form-group">
                <label>Client Version *</label>
                <input type="text" name="client_version" value="<?= e($report['client_version']) ?>" required>
            </div>
            <div class="form-group">
                <label>Test Type</label>
                <select name="test_type">
                    <option value="WAN" <?= $report['test_type'] === 'WAN' ? 'selected' : '' ?>>WAN</option>
                    <option value="LAN" <?= $report['test_type'] === 'LAN' ? 'selected' : '' ?>>LAN</option>
                </select>
            </div>
            <div class="form-group">
                <label>Commit Hash</label>
                <input type="text" name="commit_hash" value="<?= e($report['commit_hash']) ?>" placeholder="Optional">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>SteamUI Version</label>
                <input type="text" name="steamui_version" value="<?= e($report['steamui_version'] ?? '') ?>"
                       placeholder="e.g., 123456789" pattern="[0-9]*" maxlength="9"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 9)">
                <small style="color: var(--text-muted);">Numbers only, max 9 digits</small>
            </div>
            <div class="form-group">
                <label>Steam PKG Version</label>
                <input type="text" name="steam_pkg_version" value="<?= e($report['steam_pkg_version'] ?? '') ?>"
                       placeholder="e.g., 123456789" pattern="[0-9]*" maxlength="9"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 9)">
                <small style="color: var(--text-muted);">Numbers only, max 9 digits</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Tester</label>
                <input type="text" value="<?= e($report['tester']) ?>" disabled>
                <small style="color: var(--text-muted);">Tester cannot be changed.</small>
            </div>
            <div class="form-group">
                <label>Submitted</label>
                <input type="text" value="<?= formatDate($report['submitted_at'], true) ?>" disabled>
            </div>
        </div>

        <button type="submit" class="btn">Update Report Info</button>
    </form>
</div>

<!-- Attached Log Files Section -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title" style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 20px;">📄</span>
        Attached Log Files (<?= count($attachedLogs) ?>/3)
    </h3>
    <p style="color: var(--text-muted); margin-bottom: 15px;">
        Attach up to 3 debug log files (.txt or .log) to this report. Max 5MB per file.
    </p>

    <!-- Existing Logs -->
    <?php if (!empty($attachedLogs)): ?>
    <div class="existing-logs" style="margin-bottom: 20px;">
        <table>
            <thead>
                <tr>
                    <th>Filename</th>
                    <th style="width: 100px;">Original</th>
                    <th style="width: 100px;">Compressed</th>
                    <th style="width: 180px;">Actions</th>
                </tr>
            </thead>
            <tbody id="existingLogsBody">
                <?php foreach ($attachedLogs as $log): ?>
                <tr id="log-row-<?= $log['id'] ?>">
                    <td style="font-family: monospace; font-size: 13px;"><?= e($log['filename']) ?></td>
                    <td><?= number_format($log['size_original']) ?> B</td>
                    <td><?= number_format($log['size_compressed']) ?> B</td>
                    <td>
                        <a href="api/download_log.php?id=<?= $log['id'] ?>" class="btn btn-sm btn-secondary">Download</a>
                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteLog(<?= $log['id'] ?>)">Remove</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Upload New Log -->
    <?php if (count($attachedLogs) < 3): ?>
    <div class="log-upload-area">
        <div id="logDropZone" class="log-drop-zone">
            <div class="drop-zone-content">
                <span style="font-size: 32px;">📁</span>
                <p>Drag & drop a log file here</p>
                <p style="color: var(--text-muted); font-size: 12px;">or</p>
                <button type="button" id="logBrowseBtn" class="btn btn-sm">Browse Files</button>
                <p style="color: var(--text-muted); font-size: 11px; margin-top: 10px;">.txt or .log files, max 5MB</p>
            </div>
            <div id="logFileSelected" class="file-selected" style="display: none;">
                <span id="logFileName" style="font-family: monospace;"></span>
                <button type="button" id="logRemoveFile" class="btn btn-sm btn-danger">Remove</button>
            </div>
        </div>
        <input type="file" id="logFileInput" accept=".txt,.log" style="display: none;">
        <button type="button" id="logUploadBtn" class="btn" style="margin-top: 15px; display: none;" onclick="uploadLogFile()">
            Upload Log File
        </button>
    </div>
    <?php else: ?>
    <p style="color: var(--text-muted); font-style: italic;">Maximum log files reached. Remove a log to add another.</p>
    <?php endif; ?>
</div>

<!-- Test Results -->
<div class="card">
    <h3 class="card-title">Test Results</h3>
    <p style="color: var(--text-muted); margin-bottom: 20px;">
        Update the status and notes for each test. Changes are saved when you click "Save All Changes".
    </p>

    <form method="POST">
        <input type="hidden" name="action" value="update_results">

        <?php foreach ($categories as $categoryName => $tests): ?>
            <div class="category-section">
                <h4 class="category-title"><?= e($categoryName) ?></h4>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 60px;">Key</th>
                                <th>Test Name</th>
                                <th style="width: 150px;">Status</th>
                                <th>Notes</th>
                                <?php if (isAdmin()): ?>
                                    <th style="width: 100px;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tests as $testKey => $testInfo): ?>
                                <?php
                                $result = $resultsByKey[$testKey] ?? null;
                                $currentStatus = $result ? $result['status'] : 'N/A';
                                $currentNotes = $result ? $result['notes'] : '';
                                $resultId = $result ? $result['id'] : null;
                                $retestKey = $testKey . '|' . $clientVersion;
                                $hasPendingRetest = isset($pendingRetestMap[$retestKey]);
                                ?>
                                <?php if ($resultId): ?>
                                    <tr class="<?= $hasPendingRetest ? 'retest-pending' : '' ?>">
                                        <td style="font-family: monospace; font-weight: bold; color: var(--primary);">
                                            <?= e($testKey) ?>
                                            <?php if ($hasPendingRetest): ?>
                                                <span class="retest-indicator" title="Flagged for retest">&#x21BB;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 500;"><?= e($testInfo['name']) ?></div>
                                            <div style="font-size: 12px; color: var(--text-muted);">
                                                <?= e($testInfo['expected']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <select name="status[<?= $resultId ?>]" class="status-select <?= strtolower(str_replace(' ', '-', $currentStatus)) ?>">
                                                <option value="Working" <?= $currentStatus === 'Working' ? 'selected' : '' ?>>Working</option>
                                                <option value="Partially Working" <?= $currentStatus === 'Partially Working' ? 'selected' : '' ?>>Partially Working</option>
                                                <option value="Semi-working" <?= $currentStatus === 'Semi-working' ? 'selected' : '' ?>>Semi-working</option>
                                                <option value="Not working" <?= $currentStatus === 'Not working' ? 'selected' : '' ?>>Not Working</option>
                                                <option value="N/A" <?= $currentStatus === 'N/A' ? 'selected' : '' ?>>N/A</option>
                                            </select>
                                        </td>
                                        <td>
                                            <div class="notes-input-wrapper">
                                                <div class="bbcode-toolbar">
                                                    <button type="button" class="bbcode-btn" data-tag="b" title="Bold [b][/b]"><b>B</b></button>
                                                    <button type="button" class="bbcode-btn" data-tag="i" title="Italic [i][/i]"><i>I</i></button>
                                                    <button type="button" class="bbcode-btn" data-tag="u" title="Underline [u][/u]"><u>U</u></button>
                                                    <button type="button" class="bbcode-btn" data-tag="code" title="Code [code][/code]">⟨⟩</button>
                                                    <button type="button" class="bbcode-btn bbcode-btn-url" data-tag="url" title="Link [url=][/url]">🔗</button>
                                                    <button type="button" class="bbcode-btn bbcode-btn-img" data-tag="img" title="Image [img][/img]">🖼️</button>
                                                    <button type="button" class="bbcode-btn bbcode-btn-expand" data-action="expand" title="Expand editor">⛶</button>
                                                </div>
                                                <input type="hidden" name="notes[<?= $resultId ?>]" class="notes-hidden-input" value="<?= e($currentNotes) ?>">
                                                <div class="notes-editor notes-input"
                                                     contenteditable="true"
                                                     data-placeholder="Notes (supports BBCode, markdown, drag & drop images)"
                                                     data-result-id="<?= $resultId ?>"><?= convertImageMarkersToHtml($currentNotes) ?></div>
                                                <div class="notes-image-preview" style="display: none;"></div>
                                            </div>
                                        </td>
                                        <?php if (isAdmin()): ?>
                                            <td>
                                                <?php if ($hasPendingRetest): ?>
                                                    <span class="retest-badge" title="Retest already requested">Pending</span>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-retest"
                                                            data-test-key="<?= e($testKey) ?>"
                                                            data-client-version="<?= e($clientVersion) ?>"
                                                            title="Flag this test for retest">
                                                        Retest
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="form-actions" style="margin-top: 20px;">
            <button type="submit" class="btn btn-lg">Save All Changes</button>
        </div>
    </form>
</div>

<!-- Expand Notes Modal -->
<div id="expandNotesModal" class="expand-modal-overlay">
    <div class="expand-modal-content">
        <div class="expand-modal-header">
            <h3>Edit Notes</h3>
            <span id="expandModalTestInfo" style="color: var(--text-muted); font-size: 13px;"></span>
        </div>
        <div class="expand-modal-toolbar">
            <button type="button" class="bbcode-btn" data-tag="b" title="Bold [b][/b]"><b>B</b></button>
            <button type="button" class="bbcode-btn" data-tag="i" title="Italic [i][/i]"><i>I</i></button>
            <button type="button" class="bbcode-btn" data-tag="u" title="Underline [u][/u]"><u>U</u></button>
            <button type="button" class="bbcode-btn" data-tag="code" title="Code [code][/code]">⟨⟩</button>
            <button type="button" class="bbcode-btn bbcode-btn-url" data-tag="url" title="Link [url=][/url]">🔗</button>
            <button type="button" class="bbcode-btn bbcode-btn-img" data-tag="img" title="Image [img][/img]">🖼️</button>
        </div>
        <div id="expandModalEditor" class="expand-modal-editor" contenteditable="true"></div>
        <div class="expand-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeExpandModal(false)">Cancel</button>
            <button type="button" class="btn" onclick="closeExpandModal(true)">OK</button>
        </div>
    </div>
</div>

<style>
/* Template banner */
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
    color: var(--primary);
}

.template-banner .template-count {
    font-size: 13px;
    color: var(--text-muted);
    margin-left: auto;
}

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
    border: 2px solid #899281;
    outline: 1px solid #292d23;
    background: var(--bg-dark);
    color: var(--text);
    font-size: 13px;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.2);
    cursor: pointer;
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

/* Notes textarea for multi-line support */
.notes-textarea {
    min-height: 40px;
    max-height: 200px;
    resize: vertical;
    font-family: inherit;
    line-height: 1.4;
    white-space: pre-wrap;
}

.notes-textarea:focus {
    border-color: var(--primary);
    outline: none;
    min-height: 80px;
}

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

/* Image preview area */
.notes-image-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 6px;
    padding: 6px;
    background: rgba(0,0,0,0.2);
    border-radius: 4px;
}

.notes-image-thumb {
    position: relative;
    width: 60px;
    height: 60px;
}

.notes-image-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 4px;
    cursor: pointer;
    border: 1px solid var(--border);
}

.notes-image-thumb .remove-img {
    position: absolute;
    top: -5px;
    right: -5px;
    width: 18px;
    height: 18px;
    background: #e74c3c;
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    font-size: 12px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Drag over state */
.notes-textarea.dragover {
    border-color: var(--primary);
    background: rgba(196, 181, 80, 0.1);
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
}

/* Log upload styles */
.log-drop-zone {
    border: 2px dashed var(--border);
    border-radius: 8px;
    padding: 30px;
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
    background: var(--bg-dark);
}

.log-drop-zone:hover {
    border-color: var(--primary);
    background: var(--bg-accent);
}

.log-drop-zone.dragover {
    border-color: var(--primary);
    background: rgba(196, 181, 80, 0.1);
}

.log-drop-zone .drop-zone-content p {
    margin: 8px 0;
    color: var(--text-muted);
}

.log-drop-zone .file-selected {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 15px;
}

/* Danger button */
.btn-danger {
    background: var(--status-broken);
    color: white;
    border-color: var(--status-broken);
}

.btn-danger:hover {
    background: #a94442;
    border-color: #a94442;
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

/* Modal overlay */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-content {
    background: var(--bg-card);
    border-radius: 8px;
    padding: 25px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
}

.modal-content h3 {
    margin: 0 0 15px 0;
    color: var(--primary);
}

.modal-content textarea {
    background: var(--bg-dark);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 10px;
    border-radius: 4px;
    font-family: inherit;
    font-size: 14px;
}

.modal-content textarea:focus {
    border-color: var(--primary);
    outline: none;
}

/* Contenteditable notes editor */
.notes-editor {
    min-height: 40px;
    max-height: 200px;
    overflow-y: auto;
    resize: vertical;
    font-family: inherit;
    line-height: 1.4;
    white-space: pre-wrap;
    word-wrap: break-word;
    padding: 6px 10px;
    border-radius: 0 0 4px 4px;
    border: 1px solid var(--border);
    background: var(--bg-dark);
    color: var(--text);
    font-size: 13px;
}

.notes-editor:focus {
    border-color: var(--primary);
    outline: none;
    min-height: 80px;
}

.notes-editor:empty:before {
    content: attr(data-placeholder);
    color: var(--text-muted);
    pointer-events: none;
}

/* Inline image styles */
.inline-image-wrapper {
    display: inline-block;
    vertical-align: middle;
    margin: 2px 4px;
    position: relative;
    cursor: default;
    border: 2px solid transparent;
    border-radius: 4px;
    transition: border-color 0.15s;
}

.inline-image-wrapper:hover {
    border-color: var(--primary);
}

.inline-image-wrapper.selected,
.inline-image-wrapper:focus {
    border-color: #e74c3c;
    outline: none;
}

.inline-note-image {
    max-width: 150px;
    max-height: 100px;
    border-radius: 3px;
    display: block;
}

/* Expand button styling */
.bbcode-btn-expand {
    margin-left: auto;
    font-size: 14px;
}

/* Expand notes modal */
.expand-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    z-index: 10001;
    justify-content: center;
    align-items: center;
}

.expand-modal-content {
    background: var(--bg-card);
    border-radius: 8px;
    padding: 20px;
    width: 90%;
    max-width: 900px;
    height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
}

.expand-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}

.expand-modal-header h3 {
    margin: 0;
    color: var(--primary);
}

.expand-modal-toolbar {
    display: flex;
    gap: 4px;
    margin-bottom: 10px;
    padding: 6px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 4px 4px 0 0;
    border-bottom: none;
    flex-wrap: wrap;
}

.expand-modal-toolbar .bbcode-btn {
    padding: 6px 12px;
    font-size: 14px;
}

.expand-modal-editor {
    flex: 1;
    min-height: 200px;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 0 0 4px 4px;
    background: var(--bg-dark);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-wrap: break-word;
    overflow-y: auto;
}

.expand-modal-editor:focus {
    border-color: var(--primary);
    outline: none;
}

.expand-modal-editor .inline-note-image {
    max-width: 300px;
    max-height: 200px;
}

.expand-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid var(--border);
}
</style>

<script>
// Update select color on change
document.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', function() {
        this.className = 'status-select ' + this.value.toLowerCase().replace(' ', '-');
    });
});

// ==================== Expand Notes Modal ====================
var currentExpandEditor = null;
var expandModal = document.getElementById('expandNotesModal');
var expandModalEditor = document.getElementById('expandModalEditor');

function openExpandModal(editor) {
    currentExpandEditor = editor;
    var wrapper = editor.closest('.notes-input-wrapper');
    var toolbar = wrapper.querySelector('.bbcode-toolbar');
    var testRow = editor.closest('tr');
    var testKey = testRow ? testRow.querySelector('td:first-child').textContent.trim() : '';
    var testName = testRow ? testRow.querySelector('td:nth-child(2) div:first-child').textContent.trim() : '';

    // Set info text
    document.getElementById('expandModalTestInfo').textContent = testKey ? 'Test ' + testKey + ': ' + testName : '';

    // Copy content to modal editor
    expandModalEditor.innerHTML = editor.innerHTML;

    // Show modal
    expandModal.style.display = 'flex';
    expandModalEditor.focus();
}

function closeExpandModal(saveChanges) {
    if (saveChanges && currentExpandEditor) {
        // Copy content back to original editor
        currentExpandEditor.innerHTML = expandModalEditor.innerHTML;
        // Sync to hidden input
        syncEditorToHiddenInput(currentExpandEditor);
    }
    expandModal.style.display = 'none';
    currentExpandEditor = null;
}

// Close modal on overlay click
expandModal.addEventListener('click', function(e) {
    if (e.target === expandModal) {
        closeExpandModal(false);
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && expandModal.style.display === 'flex') {
        closeExpandModal(false);
    }
});

// ==================== BBCode Toolbar Functions ====================

// Initialize BBCode toolbar buttons for inline editors
document.querySelectorAll('.notes-input-wrapper .bbcode-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var action = this.dataset.action;
        var tag = this.dataset.tag;
        var wrapper = this.closest('.notes-input-wrapper');
        var editor = wrapper.querySelector('.notes-editor');

        if (action === 'expand') {
            openExpandModal(editor);
        } else if (tag === 'url') {
            insertBBCodeUrlContentEditable(editor);
        } else if (tag === 'img') {
            insertBBCodeImgContentEditable(editor);
        } else if (tag) {
            insertBBCodeTagContentEditable(editor, tag);
        }
    });
});

// Initialize BBCode toolbar buttons for modal
document.querySelectorAll('.expand-modal-toolbar .bbcode-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var tag = this.dataset.tag;

        if (tag === 'url') {
            insertBBCodeUrlContentEditable(expandModalEditor);
        } else if (tag === 'img') {
            insertBBCodeImgContentEditable(expandModalEditor);
        } else if (tag) {
            insertBBCodeTagContentEditable(expandModalEditor, tag);
        }
    });
});

function insertBBCodeTagContentEditable(editor, tag) {
    var selection = window.getSelection();
    var selectedText = selection.toString();
    var openTag = '[' + tag + ']';
    var closeTag = '[/' + tag + ']';
    var newText = openTag + selectedText + closeTag;

    // Insert at cursor position
    if (selection.rangeCount > 0) {
        var range = selection.getRangeAt(0);
        range.deleteContents();
        var textNode = document.createTextNode(newText);
        range.insertNode(textNode);

        // Position cursor after inserted text
        range.setStartAfter(textNode);
        range.setEndAfter(textNode);
        selection.removeAllRanges();
        selection.addRange(range);
    }
    editor.focus();
    syncEditorToHiddenInput(editor);
}

function insertBBCodeUrlContentEditable(editor) {
    var url = prompt('Enter URL:', 'https://');
    if (!url) return;

    var selection = window.getSelection();
    var selectedText = selection.toString() || 'link text';
    var bbcode = '[url=' + url + ']' + selectedText + '[/url]';

    if (selection.rangeCount > 0) {
        var range = selection.getRangeAt(0);
        range.deleteContents();
        var textNode = document.createTextNode(bbcode);
        range.insertNode(textNode);

        range.setStartAfter(textNode);
        range.setEndAfter(textNode);
        selection.removeAllRanges();
        selection.addRange(range);
    }
    editor.focus();
    syncEditorToHiddenInput(editor);
}

function insertBBCodeImgContentEditable(editor) {
    var url = prompt('Enter image URL:', 'https://');
    if (!url) return;

    var bbcode = '[img]' + url + '[/img]';

    var selection = window.getSelection();
    if (selection.rangeCount > 0) {
        var range = selection.getRangeAt(0);
        range.deleteContents();
        var textNode = document.createTextNode(bbcode);
        range.insertNode(textNode);

        range.setStartAfter(textNode);
        range.setEndAfter(textNode);
        selection.removeAllRanges();
        selection.addRange(range);
    }
    editor.focus();
    syncEditorToHiddenInput(editor);
}

// ==================== Contenteditable Editor Functions ====================

// Convert editor HTML content to text with image markers
function editorHtmlToText(html) {
    if (!html) return '';

    // Create a temporary element to parse HTML
    var temp = document.createElement('div');
    temp.innerHTML = html;

    // Replace inline image wrappers with their markers
    temp.querySelectorAll('.inline-image-wrapper').forEach(function(wrapper) {
        var marker = wrapper.getAttribute('data-image-marker');
        if (marker) {
            wrapper.replaceWith(marker);
        }
    });

    // Replace standalone images (from paste/drop) with markers
    temp.querySelectorAll('img').forEach(function(img) {
        var src = img.getAttribute('src');
        if (src && src.startsWith('data:image/')) {
            img.replaceWith('{{IMAGE:' + src + '}}');
        }
    });

    // Convert <br> to newlines
    temp.querySelectorAll('br').forEach(function(br) {
        br.replaceWith('\n');
    });

    // Convert <div> and <p> to newlines (Chrome wraps lines in divs)
    temp.querySelectorAll('div, p').forEach(function(block) {
        if (block.previousSibling) {
            block.insertAdjacentText('beforebegin', '\n');
        }
    });

    // Get text content
    var text = temp.textContent || temp.innerText || '';

    // Clean up multiple newlines
    text = text.replace(/\n{3,}/g, '\n\n');

    return text.trim();
}

// Sync contenteditable editor to hidden input
function syncEditorToHiddenInput(editor) {
    var wrapper = editor.closest('.notes-input-wrapper');
    if (!wrapper) return;

    var hiddenInput = wrapper.querySelector('.notes-hidden-input');
    if (!hiddenInput) return;

    var text = editorHtmlToText(editor.innerHTML);
    hiddenInput.value = text;
}

// Initialize editors - sync on input and blur
document.querySelectorAll('.notes-editor').forEach(function(editor) {
    // Sync on input
    editor.addEventListener('input', function() {
        syncEditorToHiddenInput(this);
    });

    // Sync on blur
    editor.addEventListener('blur', function() {
        syncEditorToHiddenInput(this);
    });

    // Handle keyboard for deleting images
    editor.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' || e.key === 'Delete') {
            var selection = window.getSelection();
            if (selection.rangeCount > 0) {
                var range = selection.getRangeAt(0);

                // Check if we're about to delete an image wrapper
                if (range.collapsed) {
                    var node = range.startContainer;
                    var offset = range.startOffset;

                    // Check adjacent nodes for image wrappers
                    if (e.key === 'Backspace' && offset > 0) {
                        var prevNode = node.childNodes ? node.childNodes[offset - 1] : null;
                        if (prevNode && prevNode.classList && prevNode.classList.contains('inline-image-wrapper')) {
                            e.preventDefault();
                            prevNode.remove();
                            syncEditorToHiddenInput(this);
                            return;
                        }
                    } else if (e.key === 'Delete') {
                        var nextNode = node.childNodes ? node.childNodes[offset] : null;
                        if (nextNode && nextNode.classList && nextNode.classList.contains('inline-image-wrapper')) {
                            e.preventDefault();
                            nextNode.remove();
                            syncEditorToHiddenInput(this);
                            return;
                        }
                    }
                }
            }
        }
    });
});

// ==================== Image Drag & Drop Functions ====================

function initNotesImageDragDrop() {
    document.querySelectorAll('.notes-editor').forEach(function(editor) {
        editor.addEventListener('dragenter', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });

        editor.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (e.dataTransfer.types.includes('Files')) {
                this.classList.add('dragover');
            }
        });

        editor.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });

        editor.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');

            var files = e.dataTransfer.files;
            if (files.length > 0) {
                handleNotesImageDrop(this, files);
            }
        });

        editor.addEventListener('paste', function(e) {
            var items = e.clipboardData.items;
            for (var i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    e.preventDefault();
                    var file = items[i].getAsFile();
                    handleNotesImageDropAsInline(this, file);
                    break;
                }
            }
        });
    });
}

function handleNotesImageDrop(editor, files) {
    for (var i = 0; i < files.length; i++) {
        var file = files[i];
        if (!file.type.startsWith('image/')) continue;
        if (file.size > 5 * 1024 * 1024) {
            alert('Image "' + file.name + '" is too large. Maximum size is 5MB.');
            continue;
        }
        handleNotesImageDropAsInline(editor, file);
    }
}

// Handle image drop/paste by embedding as base64 inline image
function handleNotesImageDropAsInline(editor, file) {
    var reader = new FileReader();
    reader.onload = function(e) {
        var dataUri = e.target.result;
        insertInlineImage(editor, dataUri);
    };
    reader.readAsDataURL(file);
}

function insertInlineImage(editor, dataUri) {
    // Create the inline image wrapper
    var wrapper = document.createElement('span');
    wrapper.className = 'inline-image-wrapper';
    wrapper.setAttribute('contenteditable', 'false');
    wrapper.setAttribute('data-image-marker', '{{IMAGE:' + dataUri + '}}');

    var img = document.createElement('img');
    img.src = dataUri;
    img.className = 'inline-note-image';
    img.alt = 'Embedded image';

    wrapper.appendChild(img);

    // Insert at cursor position
    var selection = window.getSelection();
    if (selection.rangeCount > 0) {
        var range = selection.getRangeAt(0);
        range.deleteContents();
        range.insertNode(wrapper);

        // Move cursor after the image
        range.setStartAfter(wrapper);
        range.setEndAfter(wrapper);
        selection.removeAllRanges();
        selection.addRange(range);
    } else {
        editor.appendChild(wrapper);
    }

    editor.focus();
    syncEditorToHiddenInput(editor);
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
            showImagePreview(textarea, data.thumbnail_url, data.url);
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

    if (before.length > 0 && !before.endsWith('\n')) {
        text = '\n' + text;
    }

    textarea.value = before + text + after;
    var newPos = before.length + text.length;
    textarea.selectionStart = newPos;
    textarea.selectionEnd = newPos;
    textarea.focus();
}

function showImagePreview(textarea, thumbnailUrl, fullUrl) {
    var wrapper = textarea.closest('.notes-input-wrapper');
    var previewContainer = wrapper.querySelector('.notes-image-preview');
    if (!previewContainer) return;

    previewContainer.style.display = 'flex';

    var thumb = document.createElement('div');
    thumb.className = 'notes-image-thumb';

    var img = document.createElement('img');
    img.src = thumbnailUrl;
    img.alt = 'Uploaded image';
    img.title = 'Click to view full size';
    img.onclick = function() { window.open(fullUrl, '_blank'); };

    thumb.appendChild(img);
    previewContainer.appendChild(thumb);
}

// Initialize on page load
initNotesImageDragDrop();

// Log file upload functionality
const reportId = <?= $reportId ?>;
let selectedLogFile = null;

document.addEventListener('DOMContentLoaded', function() {
    const logDropZone = document.getElementById('logDropZone');
    const logFileInput = document.getElementById('logFileInput');
    const logBrowseBtn = document.getElementById('logBrowseBtn');
    const logUploadBtn = document.getElementById('logUploadBtn');
    const logFileSelected = document.getElementById('logFileSelected');
    const logFileName = document.getElementById('logFileName');
    const logRemoveFile = document.getElementById('logRemoveFile');
    const dropZoneContent = logDropZone ? logDropZone.querySelector('.drop-zone-content') : null;

    if (!logDropZone || !logFileInput) return;

    // Browse button click
    if (logBrowseBtn) {
        logBrowseBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            logFileInput.click();
        });
    }

    // Drop zone click
    logDropZone.addEventListener('click', function(e) {
        if (e.target.id === 'logBrowseBtn' || e.target.closest('#logBrowseBtn') ||
            e.target.id === 'logRemoveFile' || e.target.closest('.file-selected')) {
            return;
        }
        logFileInput.click();
    });

    // Drag and drop
    logDropZone.addEventListener('dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
        logDropZone.classList.add('dragover');
    });

    logDropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        logDropZone.classList.add('dragover');
    });

    logDropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (!logDropZone.contains(e.relatedTarget)) {
            logDropZone.classList.remove('dragover');
        }
    });

    logDropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        logDropZone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            const ext = file.name.split('.').pop().toLowerCase();
            if (ext === 'txt' || ext === 'log') {
                handleLogFileSelect(file);
            } else {
                showNotification('Only .txt and .log files are allowed', 'error');
            }
        }
    });

    // File input change
    logFileInput.addEventListener('change', function() {
        if (logFileInput.files.length > 0) {
            handleLogFileSelect(logFileInput.files[0]);
        }
    });

    // Remove file button
    if (logRemoveFile) {
        logRemoveFile.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            selectedLogFile = null;
            logFileInput.value = '';
            if (dropZoneContent) dropZoneContent.style.display = '';
            if (logFileSelected) logFileSelected.style.display = 'none';
            if (logUploadBtn) logUploadBtn.style.display = 'none';
        });
    }

    function handleLogFileSelect(file) {
        // Check file size (5MB max)
        if (file.size > 5 * 1024 * 1024) {
            showNotification('File is too large. Maximum size is 5MB.', 'error');
            return;
        }
        selectedLogFile = file;
        if (dropZoneContent) dropZoneContent.style.display = 'none';
        if (logFileSelected) logFileSelected.style.display = 'flex';
        if (logFileName) logFileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
        if (logUploadBtn) logUploadBtn.style.display = '';
    }
});

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

function uploadLogFile() {
    if (!selectedLogFile) {
        showNotification('Please select a file first', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('report_id', reportId);
    formData.append('log_file', selectedLogFile);

    const uploadBtn = document.getElementById('logUploadBtn');
    if (uploadBtn) {
        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Uploading...';
    }

    fetch('api/upload_log.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Log file uploaded successfully!', 'success');
            // Reload page to show new log
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(data.error || 'Failed to upload file', 'error');
            if (uploadBtn) {
                uploadBtn.disabled = false;
                uploadBtn.textContent = 'Upload Log File';
            }
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        showNotification('Failed to upload file', 'error');
        if (uploadBtn) {
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Upload Log File';
        }
    });
}

function deleteLog(logId) {
    if (!confirm('Are you sure you want to remove this log file?')) {
        return;
    }

    fetch('api/delete_log.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'log_id=' + logId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Log file removed', 'success');
            // Remove the row from the table
            const row = document.getElementById('log-row-' + logId);
            if (row) row.remove();
            // Reload page to update counts and show upload zone if needed
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification(data.error || 'Failed to remove file', 'error');
        }
    })
    .catch(error => {
        console.error('Delete error:', error);
        showNotification('Failed to remove file', 'error');
    });
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = 'flash-message ' + type;
    notification.textContent = message;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.animation = 'fadeIn 0.3s ease';
    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

<?php if (isAdmin()): ?>
// Retest modal functionality
var retestModal = null;
var currentRetestButton = null;

function showRetestModal(testKey, clientVersion, button) {
    currentRetestButton = button;

    // Create modal if it doesn't exist
    if (!retestModal) {
        retestModal = document.createElement('div');
        retestModal.id = 'retestModal';
        retestModal.className = 'modal-overlay';
        retestModal.innerHTML = `
            <div class="modal-content">
                <h3>Flag Test for Retest</h3>
                <p id="retestModalInfo" style="color: var(--text-muted); margin-bottom: 15px;"></p>
                <div class="form-group">
                    <label>Notes for Tester <span style="color: var(--status-broken);">*</span></label>
                    <textarea id="retestNotes" rows="4" placeholder="Explain what needs to be retested and why..." style="width: 100%; resize: vertical;"></textarea>
                    <small style="color: var(--text-muted);">These notes will be shown to the tester and included in their notification.</small>
                </div>
                <div class="modal-actions" style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeRetestModal()">Cancel</button>
                    <button type="button" class="btn" id="retestSubmitBtn" onclick="submitRetestRequest()">Create Retest Request</button>
                </div>
            </div>
        `;
        document.body.appendChild(retestModal);

        // Close on overlay click
        retestModal.addEventListener('click', function(e) {
            if (e.target === retestModal) closeRetestModal();
        });
    }

    // Update modal info
    document.getElementById('retestModalInfo').textContent = 'Test: ' + testKey + ' | Version: ' + clientVersion;
    document.getElementById('retestNotes').value = '';
    retestModal.dataset.testKey = testKey;
    retestModal.dataset.clientVersion = clientVersion;

    // Show modal
    retestModal.style.display = 'flex';
    document.getElementById('retestNotes').focus();
}

function closeRetestModal() {
    if (retestModal) {
        retestModal.style.display = 'none';
    }
    currentRetestButton = null;
}

function submitRetestRequest() {
    var testKey = retestModal.dataset.testKey;
    var clientVersion = retestModal.dataset.clientVersion;
    var notes = document.getElementById('retestNotes').value.trim();

    if (!notes) {
        showNotification('Notes are required - please explain what needs to be retested', 'error');
        document.getElementById('retestNotes').focus();
        return;
    }

    var submitBtn = document.getElementById('retestSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';

    fetch('api/retest_request.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            test_key: testKey,
            client_version: clientVersion,
            notes: notes,
            report_id: reportId
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Save button reference before closing modal (closeRetestModal sets it to null)
            var buttonToReplace = currentRetestButton;

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

                // Add indicator next to test key if not already present
                var keyCell = row.querySelector('td:first-child');
                if (keyCell && !row.querySelector('.retest-indicator')) {
                    var indicator = document.createElement('span');
                    indicator.className = 'retest-indicator';
                    indicator.title = 'Flagged for retest';
                    indicator.innerHTML = '&#x21BB;';
                    keyCell.appendChild(indicator);
                }
            }

            showNotification('Retest request created', 'success');
        } else {
            showNotification('Error: ' + (data.error || 'Unknown error'), 'error');
        }
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Retest Request';
    })
    .catch(function(error) {
        showNotification('Error: ' + error.message, 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Retest Request';
    });
}

// Handle retest button clicks
document.querySelectorAll('.btn-retest').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var testKey = this.dataset.testKey;
        var clientVersion = this.dataset.clientVersion;
        showRetestModal(testKey, clientVersion, this);
    });
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
