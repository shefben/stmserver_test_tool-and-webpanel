<?php
/**
 * User Profile Page
 * View API key, change password, see own reports
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();
$currentUser = getCurrentUser();

$error = '';
$success = '';

// Get user data from database
$userData = $db->getUser($currentUser['username']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'All password fields are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif (!$db->verifyPassword($currentUser['username'], $currentPassword)) {
            $error = 'Current password is incorrect.';
        } else {
            if ($db->updateUser($currentUser['username'], ['password' => $newPassword])) {
                $success = 'Password changed successfully.';
            } else {
                $error = 'Failed to change password.';
            }
        }
    }

    if ($action === 'regenerate_key') {
        $newKey = $db->regenerateApiKey($currentUser['username']);
        if ($newKey) {
            $success = 'API key regenerated successfully. Your new key is shown below.';
            // Refresh user data
            $userData = $db->getUser($currentUser['username']);
            // Update session
            $_SESSION['user']['api_key'] = $newKey;
        } else {
            $error = 'Failed to regenerate API key.';
        }
    }
}

// Get user's reports
$userReports = $db->getReports(10, 0, ['tester' => $currentUser['username']]);
$totalUserReports = $db->countReports(['tester' => $currentUser['username']]);
?>

<div class="report-header">
    <div>
        <h1 class="page-title">My Profile</h1>
        <p style="color: var(--text-muted);">Manage your account settings and API key</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div class="charts-grid">
    <!-- Account Info -->
    <div class="card">
        <h3 class="card-title">Account Information</h3>
        <div class="profile-info">
            <div class="info-row">
                <label>Username</label>
                <div class="value"><?= e($currentUser['username']) ?></div>
            </div>
            <div class="info-row">
                <label>Role</label>
                <div class="value">
                    <span class="role-badge <?= $currentUser['role'] ?? 'user' ?>">
                        <?= e(ucfirst($currentUser['role'] ?? 'user')) ?>
                    </span>
                </div>
            </div>
            <?php if ($userData): ?>
                <div class="info-row">
                    <label>Account Created</label>
                    <div class="value"><?= formatDate($userData['created_at'], true) ?></div>
                </div>
            <?php endif; ?>
            <div class="info-row">
                <label>Reports Submitted</label>
                <div class="value"><?= $totalUserReports ?></div>
            </div>
        </div>
    </div>

    <!-- API Key -->
    <div class="card">
        <h3 class="card-title">API Key</h3>
        <p style="color: var(--text-muted); margin-bottom: 15px;">
            Use this key to submit reports via the API or Python script.
        </p>

        <div class="api-key-display">
            <code id="apiKey"><?= e($userData['api_key'] ?? $currentUser['api_key']) ?></code>
            <button type="button" class="btn btn-sm" onclick="copyApiKey()">Copy</button>
        </div>

        <form method="POST" style="margin-top: 20px;">
            <input type="hidden" name="action" value="regenerate_key">
            <button type="submit" class="btn btn-secondary"
                    onclick="return confirm('Regenerate your API key? Your current key will stop working immediately.');">
                Regenerate API Key
            </button>
        </form>

        <div class="api-usage" style="margin-top: 20px;">
            <h4>Usage Example</h4>
            <pre><code>python submit_report.py \
  --url http://your-server/test_api/api/submit.php \
  --api-key <?= e($userData['api_key'] ?? $currentUser['api_key']) ?> \
  --file session_results.json</code></pre>
        </div>
    </div>
</div>

<!-- Change Password -->
<div class="card" style="margin-top: 30px;">
    <h3 class="card-title">Change Password</h3>
    <form method="POST" style="max-width: 400px;">
        <input type="hidden" name="action" value="change_password">

        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required>
        </div>

        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" required minlength="6"
                   placeholder="Min 6 characters">
        </div>

        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" required minlength="6">
        </div>

        <button type="submit" class="btn">Change Password</button>
    </form>
</div>

<!-- My Reports -->
<div class="card" style="margin-top: 30px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 class="card-title" style="margin-bottom: 0;">My Reports (<?= $totalUserReports ?>)</h3>
        <a href="?page=my_reports" class="btn btn-sm">Manage All Reports</a>
    </div>
    <?php if (empty($userReports)): ?>
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            You haven't submitted any reports yet. <a href="?page=submit">Submit one now</a>.
        </p>
    <?php else: ?>
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Client Version</th>
                        <th>Type</th>
                        <th>Results</th>
                        <th>Submitted</th>
                        <th class="no-sort">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userReports as $report): ?>
                        <?php $reportStats = $db->getReportStats($report['id']); ?>
                        <tr>
                            <td>#<?= $report['id'] ?></td>
                            <td><?= e(shortVersionName($report['client_version'])) ?></td>
                            <td>
                                <span class="status-badge" style="background: <?= $report['test_type'] === 'WAN' ? '#3498db' : '#9b59b6' ?>">
                                    <?= e($report['test_type']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="mini-stat working"><?= $reportStats['working'] ?></span>
                                <span class="mini-stat semi"><?= $reportStats['semi_working'] ?></span>
                                <span class="mini-stat broken"><?= $reportStats['not_working'] ?></span>
                            </td>
                            <td style="color: var(--text-muted);"><?= formatDate($report['submitted_at']) ?></td>
                            <td>
                                <a href="?page=report_detail&id=<?= $report['id'] ?>" class="btn btn-sm btn-secondary">View</a>
                                <a href="?page=edit_report&id=<?= $report['id'] ?>" class="btn btn-sm">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalUserReports > 10): ?>
            <div style="text-align: center; margin-top: 15px;">
                <a href="?page=my_reports" class="btn btn-sm btn-secondary">View All My Reports</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
/* Profile info */
.profile-info {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.info-row {
    display: flex;
    align-items: center;
    gap: 15px;
}

.info-row label {
    width: 150px;
    color: var(--text-muted);
}

.info-row .value {
    font-weight: 500;
}

/* API key display */
.api-key-display {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--bg-dark);
    padding: 15px;
    border-radius: 8px;
}

.api-key-display code {
    flex: 1;
    font-family: monospace;
    font-size: 13px;
    word-break: break-all;
}

/* API usage */
.api-usage {
    background: var(--bg-dark);
    padding: 15px;
    border-radius: 8px;
}

.api-usage h4 {
    margin-bottom: 10px;
    font-size: 14px;
}

.api-usage pre {
    margin: 0;
    font-size: 12px;
    overflow-x: auto;
}

.api-usage code {
    color: var(--text-muted);
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

/* Mini stats */
.mini-stat {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
    color: #fff;
    margin-right: 2px;
}

.mini-stat.working { background: var(--status-working); }
.mini-stat.semi { background: var(--status-semi); }
.mini-stat.broken { background: var(--status-broken); }

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
</style>

<script>
function copyApiKey() {
    const apiKey = document.getElementById('apiKey').textContent;
    navigator.clipboard.writeText(apiKey).then(() => {
        alert('API key copied to clipboard!');
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
