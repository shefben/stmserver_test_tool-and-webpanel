<?php
/**
 * Admin Panel - User Management
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();
$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'user';

            if (empty($username) || empty($password)) {
                $error = 'Username and password are required.';
            } elseif (strlen($username) < 3) {
                $error = 'Username must be at least 3 characters.';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
            } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                $error = 'Username can only contain letters, numbers, and underscores.';
            } else {
                $result = $db->createUser($username, $password, $role);
                if ($result) {
                    $success = "User '$username' created successfully. API Key: " . $result['api_key'];
                } else {
                    $error = "User '$username' already exists.";
                }
            }
            break;

        case 'update':
            $username = $_POST['username'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $newRole = $_POST['role'] ?? '';

            $updateData = [];
            if (!empty($newPassword)) {
                if (strlen($newPassword) < 6) {
                    $error = 'Password must be at least 6 characters.';
                } else {
                    $updateData['password'] = $newPassword;
                }
            }
            if (!empty($newRole)) {
                $updateData['role'] = $newRole;
            }

            if (!$error && !empty($updateData)) {
                if ($db->updateUser($username, $updateData)) {
                    $success = "User '$username' updated successfully.";
                } else {
                    $error = "Failed to update user '$username'.";
                }
            }
            break;

        case 'regenerate_key':
            $username = $_POST['username'] ?? '';
            $newKey = $db->regenerateApiKey($username);
            if ($newKey) {
                $success = "New API key for '$username': $newKey";
            } else {
                $error = "Failed to regenerate API key for '$username'.";
            }
            break;

        case 'delete':
            $username = $_POST['username'] ?? '';
            if ($username === 'admin') {
                $error = "Cannot delete the admin user.";
            } elseif ($db->deleteUser($username)) {
                $success = "User '$username' deleted successfully.";
            } else {
                $error = "Failed to delete user '$username'.";
            }
            break;

        case 'promote':
            $username = $_POST['username'] ?? '';
            if ($username === 'admin') {
                $error = "Admin user is already an administrator.";
            } elseif ($db->updateUser($username, ['role' => 'admin'])) {
                $success = "User '$username' has been promoted to administrator.";
            } else {
                $error = "Failed to promote user '$username'.";
            }
            break;

        case 'demote':
            $username = $_POST['username'] ?? '';
            if ($username === 'admin') {
                $error = "Cannot demote the primary admin user.";
            } elseif ($db->updateUser($username, ['role' => 'user'])) {
                $success = "User '$username' has been demoted to regular user.";
            } else {
                $error = "Failed to demote user '$username'.";
            }
            break;
    }
}

// Get all users
$users = $db->getUsers();
?>

<div class="report-header">
    <div>
        <h1 class="page-title">User Management</h1>
        <p style="color: var(--text-muted);">Create and manage user accounts and API keys</p>
    </div>
    <a href="?page=admin" class="btn btn-secondary">&larr; Back to Admin</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<!-- Create User Form -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">Create New User</h3>
    <form method="POST" class="create-user-form">
        <input type="hidden" name="action" value="create">

        <div class="form-row">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required minlength="3" pattern="[a-zA-Z0-9_]+"
                       placeholder="Enter username" title="Letters, numbers, and underscores only">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required minlength="6"
                       placeholder="Min 6 characters">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group" style="align-self: flex-end;">
                <button type="submit" class="btn">Create User</button>
            </div>
        </div>
    </form>
</div>

<!-- Users List -->
<div class="card">
    <h3 class="card-title">All Users (<?= count($users) ?>)</h3>
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>API Key</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>#<?= $u['id'] ?></td>
                        <td style="font-weight: 600;"><?= e($u['username']) ?></td>
                        <td>
                            <span class="role-badge <?= $u['role'] ?>"><?= e(ucfirst($u['role'])) ?></span>
                        </td>
                        <td>
                            <code class="api-key" id="key-<?= $u['id'] ?>"><?= e($u['api_key']) ?></code>
                            <button type="button" class="btn btn-sm btn-secondary copy-btn"
                                    onclick="copyToClipboard('key-<?= $u['id'] ?>')" title="Copy to clipboard">
                                Copy
                            </button>
                        </td>
                        <td style="color: var(--text-muted);"><?= formatDate($u['created_at']) ?></td>
                        <td class="actions-cell">
                            <button type="button" class="btn btn-sm" onclick="showEditModal('<?= e($u['username']) ?>', '<?= e($u['role']) ?>')">
                                Edit
                            </button>
                            <?php if ($u['username'] !== 'admin'): ?>
                                <?php if ($u['role'] === 'user'): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Promote <?= e($u['username']) ?> to administrator? They will have full admin access.');">
                                        <input type="hidden" name="action" value="promote">
                                        <input type="hidden" name="username" value="<?= e($u['username']) ?>">
                                        <button type="submit" class="btn btn-sm btn-promote" title="Promote to Admin">Promote</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Demote <?= e($u['username']) ?> to regular user? They will lose admin access.');">
                                        <input type="hidden" name="action" value="demote">
                                        <input type="hidden" name="username" value="<?= e($u['username']) ?>">
                                        <button type="submit" class="btn btn-sm btn-demote" title="Demote to User">Demote</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Regenerate API key for <?= e($u['username']) ?>? The old key will stop working.');">
                                <input type="hidden" name="action" value="regenerate_key">
                                <input type="hidden" name="username" value="<?= e($u['username']) ?>">
                                <button type="submit" class="btn btn-sm btn-secondary">New Key</button>
                            </form>
                            <?php if ($u['username'] !== 'admin'): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete user <?= e($u['username']) ?>? This cannot be undone.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="username" value="<?= e($u['username']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h3>Edit User</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="username" id="edit-username">

            <div class="form-group">
                <label>Username</label>
                <input type="text" id="edit-username-display" disabled>
            </div>

            <div class="form-group">
                <label>New Password (leave blank to keep current)</label>
                <input type="password" name="new_password" minlength="6" placeholder="Min 6 characters">
            </div>

            <div class="form-group">
                <label>Role</label>
                <select name="role" id="edit-role">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="hideEditModal()">Cancel</button>
                <button type="submit" class="btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Form styles */
.create-user-form .form-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: flex-start;
}

.create-user-form .form-group {
    flex: 1;
    min-width: 150px;
}

/* API key display */
.api-key {
    font-family: monospace;
    font-size: 11px;
    background: var(--bg-dark);
    padding: 4px 8px;
    border-radius: 4px;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: inline-block;
    vertical-align: middle;
}

.copy-btn {
    margin-left: 5px;
    font-size: 10px;
    padding: 2px 6px;
}

/* Actions cell */
.actions-cell {
    white-space: nowrap;
}

.actions-cell form {
    margin-left: 5px;
}

/* Danger button */
.btn-danger {
    background: var(--status-broken);
}

.btn-danger:hover {
    background: #c0392b;
}

/* Promote button */
.btn-promote {
    background: linear-gradient(180deg, var(--full-green) 0%, #5a8a35 100%);
    border-color: var(--full-green);
}

.btn-promote:hover {
    background: linear-gradient(180deg, #8eb65b 0%, var(--full-green) 100%);
}

/* Demote button */
.btn-demote {
    background: linear-gradient(180deg, var(--status-semi) 0%, #8a7a30 100%);
    border-color: var(--status-semi);
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

/* Modal styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: var(--bg-card);
    padding: 30px;
    border-radius: 10px;
    width: 100%;
    max-width: 400px;
}

.modal-content h3 {
    margin-bottom: 20px;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

/* Role badges */
.role-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.role-badge.admin {
    background: var(--primary);
    color: #fff;
}

.role-badge.user {
    background: #3498db;
    color: #fff;
}
</style>

<script>
function copyToClipboard(elementId) {
    const text = document.getElementById(elementId).textContent;
    navigator.clipboard.writeText(text).then(() => {
        alert('API key copied to clipboard!');
    });
}

function showEditModal(username, role) {
    document.getElementById('edit-username').value = username;
    document.getElementById('edit-username-display').value = username;
    document.getElementById('edit-role').value = role;
    document.getElementById('editModal').style.display = 'flex';
}

function hideEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Close modal on outside click
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideEditModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
