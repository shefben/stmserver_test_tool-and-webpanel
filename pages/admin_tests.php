<?php
/**
 * Admin Panel - Test Management Overview
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();

// Get counts
$categories = $db->getTestCategories();
$testTypes = $db->getTestTypes();
$enabledTests = array_filter($testTypes, fn($t) => $t['is_enabled']);
$disabledTests = array_filter($testTypes, fn($t) => !$t['is_enabled']);
?>

<div class="admin-header">
    <h1 class="page-title">Test Management</h1>
    <p style="color: var(--text-muted); margin-top: 5px;">
        Manage test categories and test types
    </p>
</div>

<!-- Stats Cards -->
<div class="stats-grid" style="margin-bottom: 30px;">
    <a href="?page=admin_categories" class="stat-card clickable-card" style="border-left: 4px solid var(--primary);">
        <div class="value"><?= count($categories) ?></div>
        <div class="label">Test Categories</div>
        <div class="card-hint">Click to manage</div>
    </a>
    <a href="?page=admin_test_types" class="stat-card clickable-card working">
        <div class="value"><?= count($enabledTests) ?></div>
        <div class="label">Enabled Tests</div>
        <div class="card-hint">Click to manage</div>
    </a>
    <a href="?page=admin_test_types&show=disabled" class="stat-card clickable-card" style="border-left: 4px solid var(--status-broken);">
        <div class="value"><?= count($disabledTests) ?></div>
        <div class="label">Disabled Tests</div>
        <div class="card-hint">Click to view</div>
    </a>
    <div class="stat-card">
        <div class="value"><?= count($testTypes) ?></div>
        <div class="label">Total Tests</div>
    </div>
</div>

<!-- Quick Actions -->
<div class="charts-grid">
    <div class="card">
        <h3 class="card-title">Test Categories</h3>
        <p style="color: var(--text-muted); margin-bottom: 20px;">
            Categories organize tests into logical groups. Deleting a category will disable all tests within it.
        </p>
        <div style="display: flex; gap: 10px;">
            <a href="?page=admin_categories" class="btn">View All Categories</a>
            <a href="?page=admin_category_edit" class="btn btn-secondary">Create New Category</a>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title">Test Types</h3>
        <p style="color: var(--text-muted); margin-bottom: 20px;">
            Test types define individual tests with their name, description, and category assignment.
        </p>
        <div style="display: flex; gap: 10px;">
            <a href="?page=admin_test_types" class="btn">View All Tests</a>
            <a href="?page=admin_test_type_edit" class="btn btn-secondary">Create New Test</a>
        </div>
    </div>
</div>

<!-- Categories Overview -->
<div class="card" style="margin-top: 30px;">
    <h3 class="card-title">Categories Overview</h3>
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>Category Name</th>
                    <th>Tests</th>
                    <th>Enabled</th>
                    <th>Disabled</th>
                    <th class="no-sort">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                    <?php
                    $catTests = array_filter($testTypes, fn($t) => $t['category_id'] == $cat['id']);
                    $catEnabled = array_filter($catTests, fn($t) => $t['is_enabled']);
                    $catDisabled = array_filter($catTests, fn($t) => !$t['is_enabled']);
                    ?>
                    <tr>
                        <td><strong><?= e($cat['name']) ?></strong></td>
                        <td><?= count($catTests) ?></td>
                        <td><span style="color: var(--status-working);"><?= count($catEnabled) ?></span></td>
                        <td><span style="color: var(--status-broken);"><?= count($catDisabled) ?></span></td>
                        <td>
                            <a href="?page=admin_category_edit&id=<?= $cat['id'] ?>" class="btn btn-sm">Edit</a>
                            <a href="?page=admin_test_types&category=<?= $cat['id'] ?>" class="btn btn-sm btn-secondary">View Tests</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No categories found. <a href="?page=admin_category_edit">Create one</a>.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
/* Clickable cards */
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
