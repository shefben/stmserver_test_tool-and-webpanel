<?php
/**
 * Admin Panel - Test Categories List
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $categoryId = intval($_POST['category_id'] ?? 0);
    if ($categoryId) {
        if ($db->deleteTestCategory($categoryId)) {
            setFlash('success', 'Category deleted. Tests in this category have been disabled.');
        } else {
            setFlash('error', 'Failed to delete category.');
        }
    }
    header('Location: ?page=admin_categories');
    exit;
}

// Get all categories with test counts
$categories = $db->getTestCategories();
$testTypes = $db->getTestTypes();

// Count tests per category
$testCounts = [];
foreach ($testTypes as $test) {
    $catId = $test['category_id'] ?? 'uncategorized';
    if (!isset($testCounts[$catId])) {
        $testCounts[$catId] = ['total' => 0, 'enabled' => 0, 'disabled' => 0];
    }
    $testCounts[$catId]['total']++;
    if ($test['is_enabled']) {
        $testCounts[$catId]['enabled']++;
    } else {
        $testCounts[$catId]['disabled']++;
    }
}
?>

<div class="admin-header">
    <div>
        <h1 class="page-title">Test Categories</h1>
        <p style="color: var(--text-muted); margin-top: 5px;">
            Manage test categories - organize tests into logical groups
        </p>
    </div>
    <div>
        <a href="?page=admin_category_edit" class="btn">Create New Category</a>
        <a href="?page=admin_tests" class="btn btn-secondary">&larr; Back to Test Management</a>
    </div>
</div>

<?= renderFlash() ?>

<div class="card">
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Category Name</th>
                    <th>Sort Order</th>
                    <th>Total Tests</th>
                    <th>Enabled</th>
                    <th>Disabled</th>
                    <th>Created</th>
                    <th class="no-sort">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                    <?php
                    $counts = $testCounts[$cat['id']] ?? ['total' => 0, 'enabled' => 0, 'disabled' => 0];
                    ?>
                    <tr>
                        <td><?= $cat['id'] ?></td>
                        <td><strong><?= e($cat['name']) ?></strong></td>
                        <td><?= $cat['sort_order'] ?></td>
                        <td><?= $counts['total'] ?></td>
                        <td><span style="color: var(--status-working);"><?= $counts['enabled'] ?></span></td>
                        <td><span style="color: var(--status-broken);"><?= $counts['disabled'] ?></span></td>
                        <td style="color: var(--text-muted); font-size: 12px;"><?= formatRelativeTime($cat['created_at']) ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="?page=admin_category_edit&id=<?= $cat['id'] ?>" class="btn btn-sm">Edit</a>
                                <a href="?page=admin_test_types&category=<?= $cat['id'] ?>" class="btn btn-sm btn-secondary">View Tests</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this category? All tests in this category will be disabled.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No categories found. <a href="?page=admin_category_edit">Create your first category</a>.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.action-buttons {
    display: flex;
    gap: 5px;
    flex-wrap: nowrap;
}

.btn-danger {
    background: linear-gradient(180deg, var(--status-broken) 0%, #a04040 100%);
    border-color: var(--status-broken);
}
.btn-danger:hover {
    background: linear-gradient(180deg, #d45050 0%, var(--status-broken) 100%);
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
