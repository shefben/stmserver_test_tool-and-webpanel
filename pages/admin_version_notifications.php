<?php
/**
 * Admin page for managing version notifications (Quick Notes / Known Issues)
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../api/githubrevisiongrabber.php';

// Require admin role
if (!isAdmin()) {
    setFlash('error', 'Access denied. Admin privileges required.');
    header('Location: ?page=dashboard');
    exit;
}

$db = Database::getInstance();
$db->ensureVersionNotificationsTable();

// Load GitHub commits for commit hash dropdown
$commits = [];
if (defined('GITHUB_TOKEN') && GITHUB_TOKEN !== '' && defined('GITHUB_OWNER') && GITHUB_OWNER !== '') {
    try {
        $cacheDir = __DIR__ . '/../cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $cache = new GitHubRepoHistoryCache(GITHUB_TOKEN, $cacheDir);
            $commits = $cache->getHistory(GITHUB_OWNER, GITHUB_REPO, ['branch' => 'main', 'ttl_seconds' => 300]);
            // Sort by timestamp descending (newest first)
            uasort($commits, function($a, $b) {
                return ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0);
            });
        }
    } catch (Exception $e) {
        // Silently ignore GitHub errors
    }
}

// Get all client versions for dropdown
$clientVersions = $db->getClientVersions(false); // Get all, including disabled

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $clientVersionId = intval($_POST['client_version_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $commitHash = trim($_POST['commit_hash'] ?? '');

        if (!$clientVersionId) {
            setFlash('error', 'Please select a client version.');
        } elseif (!$name) {
            setFlash('error', 'Notification name is required.');
        } elseif (!$message) {
            setFlash('error', 'Notification message is required.');
        } else {
            $version = $db->getClientVersion($clientVersionId);
            if (!$version) {
                setFlash('error', 'Selected client version not found.');
            } else {
                try {
                    $id = $db->createVersionNotification(
                        $clientVersionId,
                        $name,
                        $message,
                        $commitHash ?: null,
                        $_SESSION['user_id']
                    );
                    if ($id) {
                        setFlash('success', "Notification '$name' created successfully.");
                    } else {
                        setFlash('error', 'Failed to create notification.');
                    }
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        setFlash('error', 'A notification with this name already exists for this version.');
                    } else {
                        setFlash('error', 'Database error: ' . $e->getMessage());
                    }
                }
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $clientVersionId = intval($_POST['client_version_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $commitHash = trim($_POST['commit_hash'] ?? '');

        $notification = $db->getVersionNotification($id);
        if (!$notification) {
            setFlash('error', 'Notification not found.');
        } elseif (!$clientVersionId) {
            setFlash('error', 'Please select a client version.');
        } elseif (!$name) {
            setFlash('error', 'Notification name is required.');
        } elseif (!$message) {
            setFlash('error', 'Notification message is required.');
        } else {
            try {
                if ($db->updateVersionNotification($id, $clientVersionId, $name, $message, $commitHash ?: null)) {
                    setFlash('success', "Notification updated successfully.");
                } else {
                    setFlash('error', 'Failed to update notification.');
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    setFlash('error', 'A notification with this name already exists for this version.');
                } else {
                    setFlash('error', 'Database error: ' . $e->getMessage());
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $notification = $db->getVersionNotification($id);
        if (!$notification) {
            setFlash('error', 'Notification not found.');
        } else {
            if ($db->deleteVersionNotification($id)) {
                setFlash('success', "Notification '{$notification['name']}' deleted.");
            } else {
                setFlash('error', 'Failed to delete notification.');
            }
        }
    }

    header('Location: ?page=admin_version_notifications');
    exit;
}

// Get all notifications
$notifications = $db->getVersionNotifications(200, 0);
?>

<h1 class="page-title">Version Notifications</h1>

<div class="admin-nav" style="margin-bottom: 20px;">
    <a href="?page=admin" class="btn btn-sm btn-secondary">&larr; Back to Admin</a>
    <a href="?page=admin_versions" class="btn btn-sm btn-secondary">&larr; Client Versions</a>
</div>

<p class="page-description">
    Create quick notes and known issues for specific client versions. These notifications will be displayed
    to testers when they start testing a version, and shown on reports matching the version (and optionally commit hash).
</p>

<?php if (empty($clientVersions)): ?>
<div class="alert alert-warning">
    <strong>No client versions configured.</strong>
    <a href="?page=admin_versions">Add client versions</a> before creating notifications.
</div>
<?php else: ?>

<div class="notifications-layout">
    <!-- Create New Notification Form -->
    <div class="card">
        <h3>Create New Notification</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label for="client_version_id">Client Version *</label>
                <select id="client_version_id" name="client_version_id" required>
                    <option value="">-- Select Version --</option>
                    <?php foreach ($clientVersions as $v): ?>
                        <option value="<?= $v['id'] ?>">
                            <?= e(strlen($v['version_id']) > 70 ? substr($v['version_id'], 0, 70) . '...' : $v['version_id']) ?>
                            <?php if (!$v['is_enabled']): ?> (Disabled)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="name">Notification Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g., Known Auth Issue">
                <small class="form-hint">Unique identifier for this notification</small>
            </div>

            <div class="form-group">
                <label for="message">Message * <span class="hint">(HTML & BBCode supported)</span></label>
                <textarea id="message" name="message" rows="6" required placeholder="Describe the issue or note..."></textarea>
                <small class="form-hint">
                    Supports HTML tags (&lt;b&gt;, &lt;i&gt;, &lt;a&gt;, etc.) and BBCode ([b], [i], [url], etc.)
                </small>
            </div>

            <div class="form-group">
                <label for="commit_hash">Commit Hash (Optional)</label>
                <?php if (!empty($commits)): ?>
                    <select id="commit_hash" name="commit_hash">
                        <option value="">-- All Commits (No Filter) --</option>
                        <?php
                        $count = 0;
                        foreach ($commits as $sha => $commit):
                            if ($count++ > 100) break; // Limit dropdown size
                            $shortSha = substr($sha, 0, 7);
                            $notes = $commit['notes'] ?? '';
                            $firstLine = strtok($notes, "\n");
                            if (strlen($firstLine) > 60) {
                                $firstLine = substr($firstLine, 0, 60) . '...';
                            }
                        ?>
                            <option value="<?= e($sha) ?>"><?= e($shortSha) ?> - <?= e($firstLine) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" id="commit_hash" name="commit_hash" placeholder="Full commit SHA (optional)">
                <?php endif; ?>
                <small class="form-hint">If set, notification only shows on reports with this commit hash</small>
            </div>

            <button type="submit" class="btn">Create Notification</button>
        </form>
    </div>

    <!-- Notifications List -->
    <div class="card">
        <h3>Existing Notifications (<?= count($notifications) ?>)</h3>

        <?php if (empty($notifications)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 20px;">No notifications created yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Created By</th>
                            <th>Name</th>
                            <th>Client Version</th>
                            <th>Commit</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notifications as $n): ?>
                            <tr>
                                <td>
                                    <small><?= date('Y-m-d H:i', strtotime($n['created_at'])) ?></small>
                                </td>
                                <td><?= e($n['created_by_name']) ?></td>
                                <td>
                                    <strong><?= e($n['name']) ?></strong>
                                </td>
                                <td>
                                    <small class="monospace" title="<?= e($n['client_version']) ?>">
                                        <?= e(strlen($n['client_version']) > 30 ? substr($n['client_version'], 0, 30) . '...' : $n['client_version']) ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($n['commit_hash']): ?>
                                        <code class="commit-hash"><?= e(substr($n['commit_hash'], 0, 7)) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">All</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="viewNotification(<?= htmlspecialchars(json_encode($n)) ?>)">View</button>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openEditModal(<?= htmlspecialchars(json_encode($n)) ?>)">Edit</button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this notification?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $n['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Del</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- View Modal -->
<div id="viewModal" class="modal" style="display: none;">
    <div class="modal-content modal-wide">
        <div class="modal-header">
            <h3 id="view_title">Notification</h3>
            <button type="button" class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="notification-view">
            <div class="view-meta">
                <span><strong>Version:</strong> <span id="view_version"></span></span>
                <span><strong>Commit:</strong> <span id="view_commit"></span></span>
                <span><strong>Created:</strong> <span id="view_date"></span></span>
            </div>
            <div class="notification-message-box" id="view_message"></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal" style="display: none;">
    <div class="modal-content modal-wide">
        <div class="modal-header">
            <h3>Edit Notification</h3>
            <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">

            <div class="form-group">
                <label for="edit_client_version_id">Client Version *</label>
                <select id="edit_client_version_id" name="client_version_id" required>
                    <option value="">-- Select Version --</option>
                    <?php foreach ($clientVersions as $v): ?>
                        <option value="<?= $v['id'] ?>">
                            <?= e(strlen($v['version_id']) > 70 ? substr($v['version_id'], 0, 70) . '...' : $v['version_id']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="edit_name">Notification Name *</label>
                <input type="text" id="edit_name" name="name" required>
            </div>

            <div class="form-group">
                <label for="edit_message">Message * <span class="hint">(HTML & BBCode supported)</span></label>
                <textarea id="edit_message" name="message" rows="6" required></textarea>
            </div>

            <div class="form-group">
                <label for="edit_commit_hash">Commit Hash (Optional)</label>
                <?php if (!empty($commits)): ?>
                    <select id="edit_commit_hash" name="commit_hash">
                        <option value="">-- All Commits (No Filter) --</option>
                        <?php
                        $count = 0;
                        foreach ($commits as $sha => $commit):
                            if ($count++ > 100) break;
                            $shortSha = substr($sha, 0, 7);
                            $notes = $commit['notes'] ?? '';
                            $firstLine = strtok($notes, "\n");
                            if (strlen($firstLine) > 60) {
                                $firstLine = substr($firstLine, 0, 60) . '...';
                            }
                        ?>
                            <option value="<?= e($sha) ?>"><?= e($shortSha) ?> - <?= e($firstLine) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" id="edit_commit_hash" name="commit_hash">
                <?php endif; ?>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<style>
.page-description {
    color: var(--text-muted);
    margin-bottom: 20px;
}

.notifications-layout {
    display: grid;
    grid-template-columns: 450px 1fr;
    gap: 20px;
}

@media (max-width: 1200px) {
    .notifications-layout {
        grid-template-columns: 1fr;
    }
}

.form-hint {
    display: block;
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 4px;
}

.hint {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: normal;
}

.monospace {
    font-family: monospace;
}

.commit-hash {
    background: var(--bg-dark);
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
}

.alert {
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.alert-warning {
    background: rgba(196, 181, 80, 0.2);
    border: 1px solid var(--primary);
    color: var(--text);
}

.alert a {
    color: var(--primary);
}

/* Modal styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.7);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.modal-content {
    background: var(--bg-card);
    border-radius: 8px;
    padding: 20px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-content.modal-wide {
    max-width: 700px;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-muted);
}

.modal-close:hover {
    color: var(--text);
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

/* View modal specific */
.notification-view {
    background: var(--bg-dark);
    border-radius: 6px;
    padding: 15px;
}

.view-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 15px;
    font-size: 13px;
}

.view-meta span {
    color: var(--text-muted);
}

.notification-message-box {
    background: var(--bg-card);
    border: 2px solid #c45050;
    border-radius: 6px;
    padding: 15px;
    margin-top: 10px;
}
</style>

<script>
function viewNotification(notification) {
    document.getElementById('view_title').textContent = notification.name;
    document.getElementById('view_version').textContent = notification.client_version;
    document.getElementById('view_commit').textContent = notification.commit_hash ? notification.commit_hash.substring(0, 7) : 'All commits';
    document.getElementById('view_date').textContent = notification.created_at;
    document.getElementById('view_message').innerHTML = notification.message;
    document.getElementById('viewModal').style.display = 'flex';
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

function openEditModal(notification) {
    document.getElementById('edit_id').value = notification.id;
    document.getElementById('edit_client_version_id').value = notification.client_version_id;
    document.getElementById('edit_name').value = notification.name;
    document.getElementById('edit_message').value = notification.message;

    // Handle commit hash field (could be select or input)
    var commitField = document.getElementById('edit_commit_hash');
    if (commitField.tagName === 'SELECT') {
        // Try to select the matching option, or set to empty
        var found = false;
        for (var i = 0; i < commitField.options.length; i++) {
            if (commitField.options[i].value === notification.commit_hash) {
                commitField.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) {
            commitField.selectedIndex = 0; // Select "All Commits"
        }
    } else {
        commitField.value = notification.commit_hash || '';
    }

    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeViewModal();
        closeEditModal();
    }
});

// Close modals on background click
document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewModal();
});
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
