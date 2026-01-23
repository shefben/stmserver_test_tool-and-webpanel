<?php
/**
 * Admin page for managing test templates/presets
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin role
if (!isAdmin()) {
    setFlash('error', 'Access denied. Admin privileges required.');
    header('Location: ?page=dashboard');
    exit;
}

$db = Database::getInstance();
$user = getCurrentUser();

// Initialize default template if not exists
$db->createDefaultTemplateIfNotExists($user['id']);

// Handle actions
$action = $_GET['action'] ?? '';
$templateId = intval($_GET['id'] ?? 0);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $testKeys = $_POST['test_keys'] ?? [];
        $isDefault = isset($_POST['is_default']) && $_POST['is_default'] === '1';
        $versionIds = $_POST['version_ids'] ?? [];

        if (!$name) {
            setFlash('error', 'Template name is required.');
        } elseif (empty($testKeys)) {
            setFlash('error', 'Please select at least one test.');
        } else {
            $id = $db->createTestTemplate($name, $description, $testKeys, $user['id'], $isDefault);
            if ($id) {
                // Set version assignments
                $db->setTemplateVersions($id, array_map('intval', $versionIds));
                setFlash('success', "Template '$name' created successfully.");
                header('Location: ?page=admin_templates');
                exit;
            } else {
                setFlash('error', 'Failed to create template.');
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $testKeys = $_POST['test_keys'] ?? [];
        $isDefault = isset($_POST['is_default']) && $_POST['is_default'] === '1';
        $versionIds = $_POST['version_ids'] ?? [];

        $template = $db->getTestTemplate($id);
        if (!$template) {
            setFlash('error', 'Template not found.');
        } elseif (!$name) {
            setFlash('error', 'Template name is required.');
        } elseif (empty($testKeys)) {
            setFlash('error', 'Please select at least one test.');
        } else {
            if ($db->updateTestTemplate($id, $name, $description, $testKeys, $isDefault)) {
                // Update version assignments
                $db->setTemplateVersions($id, array_map('intval', $versionIds));
                setFlash('success', "Template '$name' updated successfully.");
                header('Location: ?page=admin_templates');
                exit;
            } else {
                setFlash('error', 'Failed to update template.');
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $template = $db->getTestTemplate($id);
        if (!$template) {
            setFlash('error', 'Template not found.');
        } elseif ($template['is_system']) {
            setFlash('error', 'Cannot delete system templates.');
        } else {
            if ($db->deleteTestTemplate($id)) {
                setFlash('success', "Template '{$template['name']}' deleted.");
            } else {
                setFlash('error', 'Failed to delete template.');
            }
        }
        header('Location: ?page=admin_templates');
        exit;
    }
}

// Get all templates with version assignments
$templates = $db->getTestTemplatesWithVersions();
$categories = getTestCategories();
$allTestKeys = getSortedTestKeys();
$clientVersions = $db->getClientVersions(true); // Get enabled versions only

// Editing a template?
$editTemplate = null;
$editTemplateVersionIds = [];
if ($action === 'edit' && $templateId) {
    $editTemplate = $db->getTestTemplate($templateId);
    if ($editTemplate) {
        $editTemplateVersionIds = $db->getTemplateVersionIds($templateId);
    }
}
?>

<h1 class="page-title">Test Templates</h1>

<div class="admin-nav" style="margin-bottom: 20px;">
    <a href="?page=admin" class="btn btn-sm <?= !$action ? '' : 'btn-secondary' ?>">← Back to Admin</a>
    <a href="?page=admin_templates&action=new" class="btn btn-sm <?= $action === 'new' ? '' : 'btn-secondary' ?>">+ New Template</a>
</div>

<?php if ($action === 'new' || $action === 'edit'): ?>
    <!-- Create/Edit Form -->
    <div class="card">
        <h3><?= $action === 'edit' ? 'Edit Template' : 'Create New Template' ?></h3>
        <form method="POST">
            <input type="hidden" name="action" value="<?= $action === 'edit' ? 'update' : 'create' ?>">
            <?php if ($editTemplate): ?>
                <input type="hidden" name="id" value="<?= $editTemplate['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="name">Template Name</label>
                <input type="text" id="name" name="name" value="<?= e($editTemplate['name'] ?? '') ?>" required
                       placeholder="e.g., Quick Test, Account Tests Only">
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="2"
                          placeholder="Describe when to use this template..."><?= e($editTemplate['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_default" value="1"
                        <?= ($editTemplate['is_default'] ?? false) ? 'checked' : '' ?>>
                    Set as default template
                </label>
            </div>

            <div class="form-group">
                <label for="version_ids">Assign to Client Versions</label>
                <p class="form-help">When this template is assigned to specific versions, it will override the default template for those versions when the test client loads tests.</p>
                <select id="version_ids" name="version_ids[]" multiple class="multi-select" size="6">
                    <?php foreach ($clientVersions as $version): ?>
                        <?php
                        $selected = in_array($version['id'], $editTemplateVersionIds) ? 'selected' : '';
                        $displayName = $version['display_name'] ?: $version['version_id'];
                        if ($version['steam_date']) {
                            $displayName .= ' (' . $version['steam_date'] . ')';
                        }
                        ?>
                        <option value="<?= $version['id'] ?>" <?= $selected ?>><?= e($displayName) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">Hold Ctrl/Cmd to select multiple versions. Leave empty to use this template globally when set as default.</p>
            </div>

            <div class="form-group">
                <label>Select Tests</label>
                <div class="test-selection-toolbar">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllTests()">Select All</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="selectNoneTests()">Select None</button>
                    <span class="selected-count" id="selected-count">0 selected</span>
                </div>

                <div class="test-selection-grid">
                    <?php foreach ($categories as $categoryName => $tests): ?>
                        <div class="test-category-group">
                            <div class="category-header">
                                <label>
                                    <input type="checkbox" class="category-checkbox" data-category="<?= e($categoryName) ?>"
                                           onchange="toggleCategory(this)">
                                    <?= e($categoryName) ?>
                                </label>
                            </div>
                            <div class="category-tests">
                                <?php foreach ($tests as $testKey => $testInfo): ?>
                                    <?php
                                    $checked = $editTemplate
                                        ? in_array($testKey, $editTemplate['test_keys'])
                                        : ($action === 'new');
                                    ?>
                                    <label class="test-checkbox-label" title="<?= e($testInfo['expected']) ?>">
                                        <input type="checkbox" name="test_keys[]" value="<?= e($testKey) ?>"
                                               data-category="<?= e($categoryName) ?>"
                                               <?= $checked ? 'checked' : '' ?>
                                               onchange="updateSelectedCount()">
                                        <span class="test-key"><?= e($testKey) ?></span>
                                        <span class="test-name"><?= e($testInfo['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn"><?= $action === 'edit' ? 'Update Template' : 'Create Template' ?></button>
                <a href="?page=admin_templates" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- Templates List -->
    <div class="card">
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Tests</th>
                        <th>Versions</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($templates)): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No templates found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($templates as $template): ?>
                            <tr>
                                <td>
                                    <strong><?= e($template['name']) ?></strong>
                                    <?php if ($template['is_default']): ?>
                                        <span class="badge badge-primary" style="margin-left: 5px;">Default</span>
                                    <?php endif; ?>
                                    <?php if ($template['is_system']): ?>
                                        <span class="badge badge-secondary" style="margin-left: 5px;">System</span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width: 300px; color: var(--text-muted);">
                                    <?= e($template['description'] ?: '-') ?>
                                </td>
                                <td>
                                    <span class="test-count-badge"><?= count($template['test_keys']) ?> tests</span>
                                </td>
                                <td>
                                    <?php
                                    $versionCount = count($template['assigned_versions'] ?? []);
                                    if ($versionCount > 0):
                                    ?>
                                        <span class="version-count-badge"><?= $versionCount ?> version<?= $versionCount > 1 ? 's' : '' ?></span>
                                    <?php else: ?>
                                        <span class="version-count-badge version-all">All (default)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($template['creator_name'] ?? 'System') ?></td>
                                <td>
                                    <a href="?page=admin_templates&action=edit&id=<?= $template['id'] ?>" class="btn btn-sm">Edit</a>
                                    <?php if (!$template['is_system']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this template?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $template['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<style>
.test-selection-toolbar {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 15px;
    padding: 10px;
    background: var(--bg-dark);
    border-radius: 6px;
}

.selected-count {
    margin-left: auto;
    color: var(--primary);
    font-weight: bold;
}

.test-selection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 15px;
}

.test-category-group {
    background: var(--bg-dark);
    border-radius: 6px;
    padding: 12px;
    border: 1px solid var(--border);
}

.category-header {
    font-weight: bold;
    color: var(--primary);
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
}

.category-header label {
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}

.category-tests {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.test-checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 8px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
}

.test-checkbox-label:hover {
    background: var(--bg-accent);
}

.test-checkbox-label input:checked + .test-key {
    color: var(--primary);
}

.test-key {
    font-family: monospace;
    font-weight: bold;
    min-width: 30px;
}

.test-name {
    color: var(--text-muted);
    font-size: 12px;
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.test-count-badge {
    display: inline-block;
    padding: 3px 10px;
    background: var(--bg-accent);
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
    color: var(--primary);
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
}

.badge-primary {
    background: var(--primary);
    color: #000;
}

.badge-secondary {
    background: var(--bg-accent);
    color: var(--text-muted);
}

.multi-select {
    width: 100%;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: 8px;
}

.multi-select option {
    padding: 6px 8px;
    border-radius: 4px;
    margin: 2px 0;
}

.multi-select option:checked {
    background: var(--primary) linear-gradient(0deg, var(--primary) 0%, var(--primary) 100%);
    color: #000;
}

.form-help {
    color: var(--text-muted);
    font-size: 13px;
    margin-bottom: 10px;
}

.form-hint {
    color: var(--text-muted);
    font-size: 12px;
    margin-top: 6px;
}

.version-count-badge {
    display: inline-block;
    padding: 3px 10px;
    background: var(--bg-accent);
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
    color: var(--secondary);
}

.version-count-badge.version-all {
    color: var(--text-muted);
    font-weight: normal;
}
</style>

<script>
function updateSelectedCount() {
    var checked = document.querySelectorAll('input[name="test_keys[]"]:checked').length;
    document.getElementById('selected-count').textContent = checked + ' selected';

    // Update category checkboxes
    var categories = document.querySelectorAll('.category-checkbox');
    categories.forEach(function(cb) {
        var cat = cb.getAttribute('data-category');
        var catTests = document.querySelectorAll('input[name="test_keys[]"][data-category="' + cat + '"]');
        var catChecked = document.querySelectorAll('input[name="test_keys[]"][data-category="' + cat + '"]:checked');
        cb.checked = catTests.length === catChecked.length;
        cb.indeterminate = catChecked.length > 0 && catChecked.length < catTests.length;
    });
}

function toggleCategory(checkbox) {
    var cat = checkbox.getAttribute('data-category');
    var tests = document.querySelectorAll('input[name="test_keys[]"][data-category="' + cat + '"]');
    tests.forEach(function(t) {
        t.checked = checkbox.checked;
    });
    updateSelectedCount();
}

function selectAllTests() {
    document.querySelectorAll('input[name="test_keys[]"]').forEach(function(cb) {
        cb.checked = true;
    });
    updateSelectedCount();
}

function selectNoneTests() {
    document.querySelectorAll('input[name="test_keys[]"]').forEach(function(cb) {
        cb.checked = false;
    });
    updateSelectedCount();
}

// Initialize count on page load
document.addEventListener('DOMContentLoaded', updateSelectedCount);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
