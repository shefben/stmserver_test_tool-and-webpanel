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
$testedTestKeys = [];
if ($action === 'edit' && $templateId) {
    $editTemplate = $db->getTestTemplate($templateId);
    if ($editTemplate) {
        $editTemplateVersionIds = $db->getTemplateVersionIds($templateId);

        // Get tested test keys for assigned versions
        if (!empty($editTemplateVersionIds)) {
            // Get version strings for the assigned version IDs
            $assignedVersionStrings = [];
            foreach ($clientVersions as $v) {
                if (in_array($v['id'], $editTemplateVersionIds)) {
                    $assignedVersionStrings[] = $v['version_id'];
                }
            }
            if (!empty($assignedVersionStrings)) {
                $testedTestKeys = $db->getTestedTestKeysForVersions($assignedVersionStrings);
            }
        }
    }
}

// Also prepare tested keys for new templates if versions are pre-selected (via URL param)
$preSelectedVersionIds = [];
if ($action === 'new' && isset($_GET['versions'])) {
    $preSelectedVersionIds = array_map('intval', explode(',', $_GET['versions']));
    if (!empty($preSelectedVersionIds)) {
        $assignedVersionStrings = [];
        foreach ($clientVersions as $v) {
            if (in_array($v['id'], $preSelectedVersionIds)) {
                $assignedVersionStrings[] = $v['version_id'];
            }
        }
        if (!empty($assignedVersionStrings)) {
            $testedTestKeys = $db->getTestedTestKeysForVersions($assignedVersionStrings);
        }
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
                <?php if (!empty($testedTestKeys)): ?>
                <div class="tested-legend">
                    <span class="legend-item"><span class="indicator tested"></span> Already tested for assigned versions (<?= count($testedTestKeys) ?> tests)</span>
                    <span class="legend-item"><span class="indicator untested"></span> Not yet tested</span>
                </div>
                <?php endif; ?>
                <div class="test-selection-toolbar">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllTests()">Select All</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="selectNoneTests()">Select None</button>
                    <?php if (!empty($testedTestKeys)): ?>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="selectUntestedOnly()">Select Untested Only</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="deselectTested()">Deselect Tested</button>
                    <?php endif; ?>
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
                                    $isTested = isset($testedTestKeys[$testKey]);
                                    $testedInfo = $isTested ? $testedTestKeys[$testKey] : null;
                                    $testedTitle = $isTested
                                        ? "Already tested in " . $testedInfo['total_reports'] . " report(s) for: " . implode(', ', $testedInfo['versions'])
                                        : $testInfo['expected'];
                                    ?>
                                    <label class="test-checkbox-label <?= $isTested ? 'is-tested' : 'is-untested' ?>" title="<?= e($testedTitle) ?>">
                                        <input type="checkbox" name="test_keys[]" value="<?= e($testKey) ?>"
                                               data-category="<?= e($categoryName) ?>"
                                               data-tested="<?= $isTested ? '1' : '0' ?>"
                                               <?= $checked ? 'checked' : '' ?>
                                               onchange="updateSelectedCount()">
                                        <span class="test-key"><?= e($testKey) ?></span>
                                        <span class="test-name"><?= e($testInfo['name']) ?></span>
                                        <?php if ($isTested): ?>
                                        <span class="tested-badge" title="<?= e($testedTitle) ?>">✓</span>
                                        <?php endif; ?>
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

/* Tested/Untested indicators */
.tested-legend {
    display: flex;
    gap: 20px;
    padding: 10px 15px;
    background: var(--bg-dark);
    border-radius: 6px;
    margin-bottom: 10px;
    font-size: 13px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.legend-item .indicator {
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

.legend-item .indicator.tested {
    background: var(--status-working);
}

.legend-item .indicator.untested {
    background: var(--bg-accent);
    border: 1px solid var(--border);
}

.test-checkbox-label.is-tested {
    background: rgba(39, 174, 96, 0.1);
    border-left: 3px solid var(--status-working);
}

.test-checkbox-label.is-tested:hover {
    background: rgba(39, 174, 96, 0.2);
}

.tested-badge {
    color: var(--status-working);
    font-size: 11px;
    margin-left: auto;
    font-weight: bold;
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

function selectUntestedOnly() {
    document.querySelectorAll('input[name="test_keys[]"]').forEach(function(cb) {
        // Select only if NOT tested (data-tested="0")
        cb.checked = cb.getAttribute('data-tested') !== '1';
    });
    updateSelectedCount();
}

function deselectTested() {
    document.querySelectorAll('input[name="test_keys[]"]').forEach(function(cb) {
        // Deselect if tested (data-tested="1")
        if (cb.getAttribute('data-tested') === '1') {
            cb.checked = false;
        }
    });
    updateSelectedCount();
}

// Update tested status when versions change
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();

    // Listen for version selection changes to reload tested tests via AJAX
    var versionSelect = document.getElementById('version_ids');
    if (versionSelect) {
        versionSelect.addEventListener('change', function() {
            updateTestedTestsForVersions();
        });
    }
});

function updateTestedTestsForVersions() {
    var versionSelect = document.getElementById('version_ids');
    if (!versionSelect) return;

    var selectedVersionIds = Array.from(versionSelect.selectedOptions).map(function(opt) {
        return opt.value;
    });

    if (selectedVersionIds.length === 0) {
        // No versions selected - remove all tested indicators
        document.querySelectorAll('.test-checkbox-label').forEach(function(label) {
            label.classList.remove('is-tested');
            label.classList.add('is-untested');
            var badge = label.querySelector('.tested-badge');
            if (badge) badge.remove();
            var input = label.querySelector('input');
            if (input) input.setAttribute('data-tested', '0');
        });
        // Hide legend
        var legend = document.querySelector('.tested-legend');
        if (legend) legend.style.display = 'none';
        return;
    }

    // Fetch tested tests for selected versions
    fetch('api/tested_tests.php?version_ids=' + selectedVersionIds.join(','))
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (!data.success) return;

            var testedKeys = data.tested_keys || {};

            // Update all test checkboxes
            document.querySelectorAll('.test-checkbox-label').forEach(function(label) {
                var input = label.querySelector('input[name="test_keys[]"]');
                if (!input) return;

                var testKey = input.value;
                var isTested = testedKeys.hasOwnProperty(testKey);

                label.classList.toggle('is-tested', isTested);
                label.classList.toggle('is-untested', !isTested);
                input.setAttribute('data-tested', isTested ? '1' : '0');

                // Add/remove tested badge
                var existingBadge = label.querySelector('.tested-badge');
                if (isTested && !existingBadge) {
                    var badge = document.createElement('span');
                    badge.className = 'tested-badge';
                    badge.textContent = '✓';
                    badge.title = 'Already tested in ' + testedKeys[testKey].total_reports + ' report(s)';
                    label.appendChild(badge);
                } else if (!isTested && existingBadge) {
                    existingBadge.remove();
                }
            });

            // Show/hide legend
            var legend = document.querySelector('.tested-legend');
            if (legend) {
                var testedCount = Object.keys(testedKeys).length;
                legend.style.display = testedCount > 0 ? 'flex' : 'none';
                var countSpan = legend.querySelector('.legend-item:first-child');
                if (countSpan && testedCount > 0) {
                    countSpan.innerHTML = '<span class="indicator tested"></span> Already tested for assigned versions (' + testedCount + ' tests)';
                }
            }
        })
        .catch(function(err) {
            console.error('Failed to fetch tested tests:', err);
        });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
