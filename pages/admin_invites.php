<?php
/**
 * Admin Panel - Invite Code Management
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();
$error = '';
$success = '';
$user = getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $count = max(1, min(10, intval($_POST['count'] ?? 1))); // 1-10 codes at a time
            $created = [];

            for ($i = 0; $i < $count; $i++) {
                $invite = $db->createInviteCode($user['id']);
                if ($invite) {
                    $created[] = $invite['code'];
                }
            }

            if (!empty($created)) {
                if (count($created) === 1) {
                    $success = "Invite code created: <code class='invite-code-display'>" . e($created[0]) . "</code>";
                } else {
                    $success = count($created) . " invite codes created:<br>";
                    foreach ($created as $code) {
                        $success .= "<code class='invite-code-display'>" . e($code) . "</code><br>";
                    }
                }
            } else {
                $error = "Failed to create invite code(s).";
            }
            break;

        case 'delete':
            $id = intval($_POST['id'] ?? 0);
            if ($id > 0 && $db->deleteInviteCode($id)) {
                $success = "Invite code deleted successfully.";
            } else {
                $error = "Failed to delete invite code.";
            }
            break;

        case 'cleanup':
            $deleted = $db->cleanupExpiredInviteCodes();
            $success = "Cleaned up {$deleted} expired invite code(s).";
            break;
    }
}

// Get filters
$statusFilter = $_GET['status'] ?? '';
$filters = [];
if ($statusFilter) {
    $filters['status'] = $statusFilter;
}

// Get invite codes
$invites = $db->getInviteCodes(50, 0, $filters);
$stats = $db->getInviteCodeStats();
?>

<div class="report-header">
    <div>
        <h1 class="page-title">Invite Codes</h1>
        <p style="color: var(--text-muted);">Create and manage registration invite codes</p>
    </div>
    <a href="?page=admin" class="btn btn-secondary">&larr; Back to Admin</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="stats-grid" style="margin-bottom: 30px;">
    <div class="stat-card" style="border-left: 4px solid var(--primary);">
        <div class="value" style="color: var(--primary);"><?= $stats['total'] ?? 0 ?></div>
        <div class="label">Total Codes</div>
    </div>
    <a href="?page=admin_invites&status=valid" class="stat-card clickable-card" style="border-left: 4px solid var(--status-working);">
        <div class="value" style="color: var(--status-working);"><?= $stats['valid'] ?? 0 ?></div>
        <div class="label">Valid</div>
    </a>
    <a href="?page=admin_invites&status=used" class="stat-card clickable-card" style="border-left: 4px solid #3498db;">
        <div class="value" style="color: #3498db;"><?= $stats['used'] ?? 0 ?></div>
        <div class="label">Used</div>
    </a>
    <a href="?page=admin_invites&status=expired" class="stat-card clickable-card" style="border-left: 4px solid var(--status-broken);">
        <div class="value" style="color: var(--status-broken);"><?= $stats['expired'] ?? 0 ?></div>
        <div class="label">Expired</div>
    </a>
</div>

<!-- Create Invite Form -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">Create Invite Code</h3>
    <form method="POST" class="create-invite-form">
        <input type="hidden" name="action" value="create">

        <div class="form-row">
            <div class="form-group">
                <label>Number of Codes</label>
                <select name="count">
                    <option value="1">1 code</option>
                    <option value="3">3 codes</option>
                    <option value="5">5 codes</option>
                    <option value="10">10 codes</option>
                </select>
            </div>
            <div class="form-group" style="align-self: flex-end;">
                <button type="submit" class="btn">Generate Invite Code(s)</button>
            </div>
        </div>

        <p class="form-hint">
            Invite codes expire after <strong>3 days</strong> and can only be used once.
        </p>
    </form>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <div class="filter-row">
        <span style="color: var(--text-muted);">Filter:</span>
        <a href="?page=admin_invites" class="filter-btn <?= !$statusFilter ? 'active' : '' ?>">All</a>
        <a href="?page=admin_invites&status=valid" class="filter-btn <?= $statusFilter === 'valid' ? 'active' : '' ?>">Valid</a>
        <a href="?page=admin_invites&status=used" class="filter-btn <?= $statusFilter === 'used' ? 'active' : '' ?>">Used</a>
        <a href="?page=admin_invites&status=expired" class="filter-btn <?= $statusFilter === 'expired' ? 'active' : '' ?>">Expired</a>

        <?php if (($stats['expired'] ?? 0) > 0): ?>
            <form method="POST" style="margin-left: auto;">
                <input type="hidden" name="action" value="cleanup">
                <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Delete all expired invite codes?');">
                    Cleanup Expired
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Invite Codes List -->
<div class="card">
    <h3 class="card-title">Invite Codes (<?= count($invites) ?>)</h3>
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Created</th>
                    <th>Expires</th>
                    <th>Used By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invites)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No invite codes found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invites as $invite): ?>
                        <?php
                            $isUsed = $invite['used_by'] !== null;
                            $isExpired = !$isUsed && strtotime($invite['expires_at']) < time();
                            $isValid = !$isUsed && !$isExpired;
                        ?>
                        <tr class="<?= $isUsed ? 'row-used' : ($isExpired ? 'row-expired' : '') ?>">
                            <td>#<?= $invite['id'] ?></td>
                            <td>
                                <code class="invite-code" id="code-<?= $invite['id'] ?>"><?= e($invite['code']) ?></code>
                                <?php if ($isValid): ?>
                                    <button type="button" class="btn btn-sm btn-secondary copy-btn"
                                            onclick="copyInviteCode('code-<?= $invite['id'] ?>')" title="Copy to clipboard">
                                        Copy
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isUsed): ?>
                                    <span class="status-badge used">Used</span>
                                <?php elseif ($isExpired): ?>
                                    <span class="status-badge expired">Expired</span>
                                <?php else: ?>
                                    <span class="status-badge valid">Valid</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($invite['created_by_username'] ?? 'Unknown') ?></td>
                            <td style="color: var(--text-muted);"><?= formatDate($invite['created_at']) ?></td>
                            <td style="color: var(--text-muted);">
                                <?= formatDate($invite['expires_at']) ?>
                                <?php if ($isValid): ?>
                                    <br><small style="color: var(--status-working);">
                                        <?= getTimeRemaining($invite['expires_at']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isUsed): ?>
                                    <strong><?= e($invite['used_by_username'] ?? 'Unknown') ?></strong>
                                    <br><small style="color: var(--text-muted);"><?= formatDate($invite['used_at']) ?></small>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$isUsed): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this invite code?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $invite['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Info Card -->
<div class="card" style="margin-top: 30px; background: rgba(126, 166, 75, 0.1); border: 1px solid var(--status-working);">
    <h3 class="card-title" style="color: var(--status-working);">How Invite Codes Work</h3>
    <ul class="info-list">
        <li>Create invite codes to allow new users to register accounts</li>
        <li>Each code can only be used <strong>once</strong></li>
        <li>Codes automatically expire after <strong>3 days</strong></li>
        <li>Share the registration link: <code><?= rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/') ?>/register.php</code></li>
        <li>Users will need a valid invite code to create an account</li>
    </ul>
</div>

<style>
/* Form styles */
.create-invite-form .form-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: flex-start;
}

.create-invite-form .form-group {
    flex: 1;
    min-width: 150px;
}

.form-hint {
    margin-top: 15px;
    color: var(--text-muted);
    font-size: 13px;
}

/* Invite code display */
.invite-code, .invite-code-display {
    font-family: monospace;
    font-size: 12px;
    background: var(--bg-dark);
    padding: 4px 8px;
    border-radius: 4px;
    display: inline-block;
    margin: 2px 0;
}

.invite-code-display {
    font-size: 14px;
    padding: 6px 12px;
    margin: 5px 0;
    user-select: all;
}

.copy-btn {
    margin-left: 5px;
    font-size: 10px;
    padding: 2px 6px;
}

/* Status badges */
.status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.valid {
    background: rgba(126, 166, 75, 0.2);
    color: var(--status-working);
}

.status-badge.used {
    background: rgba(52, 152, 219, 0.2);
    color: #3498db;
}

.status-badge.expired {
    background: rgba(196, 80, 80, 0.2);
    color: var(--status-broken);
}

/* Row states */
.row-used {
    opacity: 0.7;
}

.row-expired {
    opacity: 0.5;
    background: rgba(196, 80, 80, 0.05);
}

/* Filter row */
.filter-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.filter-btn {
    padding: 6px 12px;
    background: var(--bg-dark);
    border-radius: 4px;
    color: var(--text-muted);
    text-decoration: none;
    font-size: 13px;
    transition: all 0.2s;
}

.filter-btn:hover {
    background: var(--bg-accent);
    color: var(--text);
}

.filter-btn.active {
    background: var(--primary);
    color: var(--bg-dark);
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

/* Danger button */
.btn-danger {
    background: var(--status-broken);
}

.btn-danger:hover {
    background: #c0392b;
}

/* Info list */
.info-list {
    margin: 0;
    padding-left: 20px;
    color: var(--text-muted);
}

.info-list li {
    margin-bottom: 8px;
}

.info-list code {
    background: var(--bg-dark);
    padding: 2px 6px;
    border-radius: 3px;
    color: var(--primary);
}

/* Clickable card */
.clickable-card {
    text-decoration: none;
    color: inherit;
    display: block;
    transition: transform 0.2s, box-shadow 0.2s;
    cursor: pointer;
}
.clickable-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}
</style>

<script>
function copyInviteCode(elementId) {
    const text = document.getElementById(elementId).textContent;
    copyToClipboard(text); // Use app.js copyToClipboard which handles fallbacks
}
</script>

<?php
// Helper function for time remaining
function getTimeRemaining($expiresAt) {
    $remaining = strtotime($expiresAt) - time();
    if ($remaining <= 0) return 'Expired';

    $days = floor($remaining / 86400);
    $hours = floor(($remaining % 86400) / 3600);

    if ($days > 0) {
        return "{$days}d {$hours}h remaining";
    }

    $minutes = floor(($remaining % 3600) / 60);
    if ($hours > 0) {
        return "{$hours}h {$minutes}m remaining";
    }

    return "{$minutes}m remaining";
}
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
