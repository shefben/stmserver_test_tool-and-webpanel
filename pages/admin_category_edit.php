<?php
/**
 * Admin Panel - Create/Edit Test Category
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();

$categoryId = intval($_GET['id'] ?? 0);
$isEdit = $categoryId > 0;
$category = null;

if ($isEdit) {
    $category = $db->getTestCategory($categoryId);
    if (!$category) {
        setFlash('error', 'Category not found.');
        header('Location: ?page=admin_categories');
        exit;
    }
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);

    if (empty($name)) {
        $error = 'Category name is required.';
    } else {
        if ($isEdit) {
            if ($db->updateTestCategory($categoryId, $name, $sortOrder)) {
                setFlash('success', 'Category updated successfully.');
                header('Location: ?page=admin_categories');
                exit;
            } else {
                $error = 'Failed to update category.';
            }
        } else {
            try {
                $newId = $db->createTestCategory($name, $sortOrder);
                if ($newId) {
                    setFlash('success', 'Category created successfully.');
                    header('Location: ?page=admin_categories');
                    exit;
                } else {
                    $error = 'Failed to create category.';
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = 'A category with this name already exists.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get max sort order for default
$maxSortOrder = $db->getMaxCategorySortOrder();
?>

<div class="admin-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit Category' : 'Create New Category' ?></h1>
        <p style="color: var(--text-muted); margin-top: 5px;">
            <?= $isEdit ? 'Update category details' : 'Add a new test category' ?>
        </p>
    </div>
    <a href="?page=admin_categories" class="btn btn-secondary">&larr; Back to Categories</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" style="max-width: 500px;">
        <div class="form-group">
            <label for="name">Category Name *</label>
            <input type="text" name="name" id="name" required
                   value="<?= e($category['name'] ?? '') ?>"
                   placeholder="e.g., Account Management">
            <small style="color: var(--text-muted);">The name displayed for this category</small>
        </div>

        <div class="form-group">
            <label for="sort_order">Sort Order</label>
            <input type="number" name="sort_order" id="sort_order"
                   value="<?= e($category['sort_order'] ?? ($maxSortOrder + 1)) ?>"
                   min="0">
            <small style="color: var(--text-muted);">Lower numbers appear first (0, 1, 2, ...)</small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn"><?= $isEdit ? 'Update Category' : 'Create Category' ?></button>
            <a href="?page=admin_categories" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<div class="card" style="margin-top: 30px;">
    <h3 class="card-title" style="color: var(--status-broken);">Danger Zone</h3>
    <p style="color: var(--text-muted); margin-bottom: 15px;">
        Deleting this category will disable all tests that belong to it. The tests will remain in the system but will be marked as disabled until assigned to another category.
    </p>
    <form method="POST" action="?page=admin_categories" onsubmit="return confirm('Are you sure you want to delete this category? All tests in this category will be disabled.');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="category_id" value="<?= $categoryId ?>">
        <button type="submit" class="btn btn-danger">Delete Category</button>
    </form>
</div>
<?php endif; ?>

<style>
.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

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

.btn-danger {
    background: linear-gradient(180deg, var(--status-broken) 0%, #a04040 100%);
    border-color: var(--status-broken);
}
.btn-danger:hover {
    background: linear-gradient(180deg, #d45050 0%, var(--status-broken) 100%);
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
