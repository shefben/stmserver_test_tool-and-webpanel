<?php
/**
 * Admin Panel - Create/Edit Test Type
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();

$testId = intval($_GET['id'] ?? 0);
$isEdit = $testId > 0;
$testType = null;

if ($isEdit) {
    $testType = $db->getTestType($testId);
    if (!$testType) {
        setFlash('error', 'Test type not found.');
        header('Location: ?page=admin_test_types');
        exit;
    }
}

// Get categories for dropdown
$categories = $db->getTestCategories();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $testKey = trim($_POST['test_key'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categoryId = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;

    if (empty($testKey)) {
        $error = 'Test key is required.';
    } elseif (empty($name)) {
        $error = 'Test name is required.';
    } else {
        if ($isEdit) {
            $data = [
                'test_key' => $testKey,
                'name' => $name,
                'description' => $description,
                'category_id' => $categoryId,
                'sort_order' => $sortOrder,
                'is_enabled' => $isEnabled
            ];

            try {
                if ($db->updateTestType($testId, $data)) {
                    setFlash('success', 'Test type updated successfully.');
                    header('Location: ?page=admin_test_types');
                    exit;
                } else {
                    $error = 'Failed to update test type.';
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = 'A test with this key already exists.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        } else {
            try {
                $newId = $db->createTestType($testKey, $name, $description, $categoryId, $sortOrder);
                if ($newId) {
                    setFlash('success', 'Test type created successfully.');
                    header('Location: ?page=admin_test_types');
                    exit;
                } else {
                    $error = 'Failed to create test type.';
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = 'A test with this key already exists.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get max sort order for default
$maxSortOrder = $db->getMaxTestTypeSortOrder();
?>

<div class="admin-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit Test Type' : 'Create New Test Type' ?></h1>
        <p style="color: var(--text-muted); margin-top: 5px;">
            <?= $isEdit ? 'Update test type details' : 'Add a new test definition' ?>
        </p>
    </div>
    <a href="?page=admin_test_types" class="btn btn-secondary">&larr; Back to Test Types</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" style="max-width: 600px;">
        <div class="form-row">
            <div class="form-group" style="max-width: 150px;">
                <label for="test_key">Test Key *</label>
                <input type="text" name="test_key" id="test_key" required
                       value="<?= e($testType['test_key'] ?? '') ?>"
                       placeholder="e.g., 1, 2a, 14b"
                       maxlength="10">
                <small style="color: var(--text-muted);">Unique identifier</small>
            </div>

            <div class="form-group" style="flex: 1;">
                <label for="name">Test Name *</label>
                <input type="text" name="name" id="name" required
                       value="<?= e($testType['name'] ?? '') ?>"
                       placeholder="e.g., Create a new account">
            </div>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea name="description" id="description" rows="3"
                      placeholder="e.g., Account is created and automatically logged into, no errors in the Steam client logs."><?= e($testType['description'] ?? '') ?></textarea>
            <small style="color: var(--text-muted);">Describe the expected outcome of this test</small>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="category_id">Category</label>
                <select name="category_id" id="category_id">
                    <option value="">-- No Category --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= ($testType['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: var(--text-muted);">Group this test under a category</small>
            </div>

            <div class="form-group" style="max-width: 120px;">
                <label for="sort_order">Sort Order</label>
                <input type="number" name="sort_order" id="sort_order"
                       value="<?= e($testType['sort_order'] ?? ($maxSortOrder + 1)) ?>"
                       min="0">
            </div>
        </div>

        <?php if ($isEdit): ?>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_enabled" value="1" <?= ($testType['is_enabled'] ?? 1) ? 'checked' : '' ?>>
                    Test is enabled
                </label>
                <small style="color: var(--text-muted);">Disabled tests won't appear in report forms</small>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn"><?= $isEdit ? 'Update Test Type' : 'Create Test Type' ?></button>
            <a href="?page=admin_test_types" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<div class="card" style="margin-top: 30px;">
    <h3 class="card-title" style="color: var(--status-broken);">Danger Zone</h3>
    <p style="color: var(--text-muted); margin-bottom: 15px;">
        Deleting this test type will permanently remove it from the system. Existing test results referencing this test key will remain but may show as "Unknown Test".
    </p>
    <form method="POST" action="?page=admin_test_types" onsubmit="return confirm('Are you sure you want to delete this test type? This cannot be undone.');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="test_id" value="<?= $testId ?>">
        <button type="submit" class="btn btn-danger">Delete Test Type</button>
    </form>
</div>
<?php endif; ?>

<style>
.form-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.form-row .form-group {
    flex: 1;
    min-width: 150px;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
}

textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--bg-dark);
    color: var(--text);
    font-size: 14px;
    font-family: inherit;
    resize: vertical;
}

textarea:focus {
    outline: none;
    border-color: var(--primary);
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
