<?php
/**
 * Admin Panel - Test Types List
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $testId = intval($_POST['test_id'] ?? 0);
        if ($testId && $db->deleteTestType($testId)) {
            setFlash('success', 'Test type deleted.');
        } else {
            setFlash('error', 'Failed to delete test type.');
        }
        header('Location: ?page=admin_test_types');
        exit;
    }

    if ($action === 'toggle_enabled') {
        $testId = intval($_POST['test_id'] ?? 0);
        $enabled = intval($_POST['enabled'] ?? 0);
        if ($testId && $db->updateTestType($testId, ['is_enabled' => $enabled])) {
            setFlash('success', $enabled ? 'Test enabled.' : 'Test disabled.');
        } else {
            setFlash('error', 'Failed to update test.');
        }
        header('Location: ?page=admin_test_types' . (isset($_GET['category']) ? '&category=' . $_GET['category'] : '') . (isset($_GET['show']) ? '&show=' . $_GET['show'] : ''));
        exit;
    }
}

// Filters
$filterCategory = isset($_GET['category']) ? intval($_GET['category']) : null;
$showDisabled = isset($_GET['show']) && $_GET['show'] === 'disabled';

// Get all test types and categories
$allTestTypes = $db->getTestTypes();
$categories = $db->getTestCategories();

// Filter test types
$testTypes = $allTestTypes;
if ($filterCategory) {
    $testTypes = array_filter($testTypes, fn($t) => $t['category_id'] == $filterCategory);
}
if ($showDisabled) {
    $testTypes = array_filter($testTypes, fn($t) => !$t['is_enabled']);
}

// Create category lookup
$categoryLookup = [];
foreach ($categories as $cat) {
    $categoryLookup[$cat['id']] = $cat['name'];
}

// Get selected category name
$selectedCategoryName = $filterCategory && isset($categoryLookup[$filterCategory]) ? $categoryLookup[$filterCategory] : '';
?>

<div class="admin-header">
    <div>
        <h1 class="page-title">
            Test Types
            <?php if ($selectedCategoryName): ?>
                <span style="color: var(--text-muted); font-size: 18px;">- <?= e($selectedCategoryName) ?></span>
            <?php endif; ?>
            <?php if ($showDisabled): ?>
                <span style="color: var(--status-broken); font-size: 18px;">(Disabled Only)</span>
            <?php endif; ?>
        </h1>
        <p style="color: var(--text-muted); margin-top: 5px;">
            Manage individual test definitions
        </p>
    </div>
    <div>
        <a href="?page=admin_test_type_edit" class="btn">Create New Test</a>
        <a href="?page=admin_tests" class="btn btn-secondary">&larr; Back to Test Management</a>
    </div>
</div>

<?= renderFlash() ?>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="page" value="admin_test_types">

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 200px;">
            <label>Category</label>
            <select name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>>
                        <?= e($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
            <label>Show</label>
            <select name="show">
                <option value="">All Tests</option>
                <option value="disabled" <?= $showDisabled ? 'selected' : '' ?>>Disabled Only</option>
            </select>
        </div>

        <button type="submit" class="btn btn-sm">Filter</button>
        <?php if ($filterCategory || $showDisabled): ?>
            <a href="?page=admin_test_types" class="btn btn-sm btn-secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>Key</th>
                    <th>Test Name</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Order</th>
                    <th class="no-sort">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($testTypes as $test): ?>
                    <tr class="<?= !$test['is_enabled'] ? 'disabled-row' : '' ?>">
                        <td>
                            <code class="test-key"><?= e($test['test_key']) ?></code>
                        </td>
                        <td><strong><?= e($test['name']) ?></strong></td>
                        <td>
                            <?php if ($test['category_name']): ?>
                                <a href="?page=admin_test_types&category=<?= $test['category_id'] ?>" class="category-link">
                                    <?= e($test['category_name']) ?>
                                </a>
                            <?php else: ?>
                                <span style="color: var(--status-broken);">Uncategorized</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width: 300px; color: var(--text-muted); font-size: 13px;">
                            <?= e(truncate($test['description'] ?? '', 100)) ?>
                        </td>
                        <td>
                            <?php if ($test['is_enabled']): ?>
                                <span class="status-badge" style="background: var(--status-working);">Enabled</span>
                            <?php else: ?>
                                <span class="status-badge" style="background: var(--status-broken);">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $test['sort_order'] ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="?page=admin_test_type_edit&id=<?= $test['id'] ?>" class="btn btn-sm">Edit</a>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_enabled">
                                    <input type="hidden" name="test_id" value="<?= $test['id'] ?>">
                                    <input type="hidden" name="enabled" value="<?= $test['is_enabled'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary">
                                        <?= $test['is_enabled'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this test type? This cannot be undone.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="test_id" value="<?= $test['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($testTypes)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No test types found.
                            <?php if ($filterCategory || $showDisabled): ?>
                                <a href="?page=admin_test_types">View all tests</a> or
                            <?php endif; ?>
                            <a href="?page=admin_test_type_edit">create one</a>.
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

.disabled-row {
    opacity: 0.6;
}

.test-key {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 13px;
    background: var(--bg-accent);
    padding: 2px 6px;
    border-radius: 4px;
    color: var(--primary);
    font-weight: bold;
}

.category-link {
    color: var(--text);
    text-decoration: none;
}
.category-link:hover {
    color: var(--primary);
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
