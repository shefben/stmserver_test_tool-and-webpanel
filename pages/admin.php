<?php
/**
 * Admin Panel - Main Dashboard
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();

// Get stats
$stats = $db->getStats();
$users = $db->getUsers();
$recentReports = $db->getReports(5);
$pendingRetests = count($db->getRetestRequests('pending'));
$pendingFixed = count($db->getFixedTests('pending_retest'));

// Get test types count
$testTypesCount = 0;
$testCategoriesCount = 0;
try {
    if ($db->hasTestCategoriesTable()) {
        $testTypes = $db->getTestTypes();
        $testTypesCount = count($testTypes);
        $testCategoriesCount = count($db->getTestCategories());
    }
} catch (Exception $e) {
    // Ignore errors
}
?>

<div class="admin-header">
    <h1 class="page-title">Administration Panel</h1>
</div>

<!-- Admin Stats -->
<div class="stats-grid">
    <a href="?page=admin_reports" class="stat-card primary clickable-card">
        <div class="value"><?= number_format($stats['total_reports']) ?></div>
        <div class="label">Total Reports</div>
        <div class="card-hint">Manage reports</div>
    </a>
    <a href="?page=admin_users" class="stat-card clickable-card" style="border-left: 4px solid #3498db;">
        <div class="value" style="color: #3498db;"><?= count($users) ?></div>
        <div class="label">Users</div>
        <div class="card-hint">Manage users</div>
    </a>
    <a href="?page=admin_retests" class="stat-card clickable-card" style="border-left: 4px solid var(--status-semi);">
        <div class="value" style="color: var(--status-semi);"><?= $pendingRetests + $pendingFixed ?></div>
        <div class="label">Pending Retests</div>
        <div class="card-hint">Manage retests</div>
    </a>
    <a href="?page=admin_tests" class="stat-card clickable-card" style="border-left: 4px solid #9b59b6;">
        <div class="value" style="color: #9b59b6;"><?= $testTypesCount ?></div>
        <div class="label">Test Types</div>
        <div class="card-hint">Manage tests</div>
    </a>
</div>

<!-- Admin Quick Links -->
<div class="charts-grid">
    <div class="card">
        <h3 class="card-title">Quick Actions</h3>
        <div class="admin-actions">
            <a href="?page=admin_users" class="admin-action-btn">
                <span class="action-icon">&#128100;</span>
                <span class="action-text">Manage Users</span>
                <span class="action-desc">Create, edit, and manage user accounts</span>
            </a>
            <a href="?page=admin_reports" class="admin-action-btn">
                <span class="action-icon">&#128196;</span>
                <span class="action-text">Manage Reports</span>
                <span class="action-desc">Edit and delete test reports</span>
            </a>
            <a href="?page=admin_retests" class="admin-action-btn">
                <span class="action-icon">&#128260;</span>
                <span class="action-text">Manage Retests</span>
                <span class="action-desc">Request retests and mark tests as fixed</span>
            </a>
            <a href="?page=admin_tests" class="admin-action-btn">
                <span class="action-icon">&#9881;</span>
                <span class="action-text">Manage Tests</span>
                <span class="action-desc">Categories and test type definitions</span>
            </a>
            <a href="?page=submit" class="admin-action-btn">
                <span class="action-icon">&#128228;</span>
                <span class="action-text">Submit Report</span>
                <span class="action-desc">Submit a new test report</span>
            </a>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title">Users Overview</h3>
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($users, 0, 5) as $u): ?>
                        <tr>
                            <td><?= e($u['username']) ?></td>
                            <td>
                                <span class="role-badge <?= $u['role'] ?>"><?= e(ucfirst($u['role'])) ?></span>
                            </td>
                            <td style="color: var(--text-muted);"><?= formatDate($u['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($users) > 5): ?>
            <div style="text-align: center; margin-top: 15px;">
                <a href="?page=admin_users" class="btn btn-sm btn-secondary">View All Users</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Reports for Management -->
<div class="card">
    <h3 class="card-title">Recent Reports</h3>
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Version</th>
                    <th>Tester</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentReports)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No reports yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentReports as $report): ?>
                        <tr>
                            <td>#<?= $report['id'] ?></td>
                            <td><?= e($report['client_version']) ?></td>
                            <td><?= e($report['tester']) ?></td>
                            <td style="color: var(--text-muted);"><?= formatDate($report['submitted_at']) ?></td>
                            <td>
                                <a href="?page=report_detail&id=<?= $report['id'] ?>" class="btn btn-sm btn-secondary">View</a>
                                <a href="?page=edit_report&id=<?= $report['id'] ?>" class="btn btn-sm">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="text-align: center; margin-top: 15px;">
        <a href="?page=admin_reports" class="btn btn-sm btn-secondary">Manage All Reports</a>
    </div>
</div>

<style>
/* Admin specific styles */
.admin-header {
    margin-bottom: 30px;
}

.admin-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.admin-action-btn {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: var(--bg-accent);
    border-radius: 8px;
    text-decoration: none;
    color: var(--text);
    transition: all 0.2s;
}

.admin-action-btn:hover {
    background: var(--primary);
    transform: translateX(5px);
}

.action-icon {
    font-size: 24px;
    width: 40px;
    text-align: center;
}

.action-text {
    font-weight: 600;
    font-size: 15px;
}

.action-desc {
    margin-left: auto;
    font-size: 12px;
    color: var(--text-muted);
}

.admin-action-btn:hover .action-desc {
    color: rgba(255,255,255,0.8);
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
.card-hint {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 8px;
    opacity: 0;
    transition: opacity 0.2s;
}
.clickable-card:hover .card-hint {
    opacity: 1;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
