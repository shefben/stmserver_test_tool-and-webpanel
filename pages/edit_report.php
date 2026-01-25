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

// Get categories
$categories = getTestCategories();

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
                                            <textarea name="notes[<?= $resultId ?>]"
                                                      placeholder="Optional notes (supports markdown, code blocks, images)"
                                                      class="notes-input notes-textarea"
                                                      rows="2"><?= e($currentNotes) ?></textarea>
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
</style>

<script>
// Update select color on change
document.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', function() {
        this.className = 'status-select ' + this.value.toLowerCase().replace(' ', '-');
    });
});

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
            closeRetestModal();

            // Replace button with pending badge
            if (currentRetestButton) {
                var badge = document.createElement('span');
                badge.className = 'retest-badge';
                badge.title = 'Retest already requested';
                badge.textContent = 'Pending';
                currentRetestButton.parentNode.replaceChild(badge, currentRetestButton);

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
