<?php
/**
 * Admin page for managing client versions
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
$db->ensureClientVersionsTable();
$db->ensureTemplatesTable();
$db->ensureTemplateVersionsTable();

// Get all available templates for dropdown
$allTemplates = $db->getTestTemplates();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $versionId = trim($_POST['version_id'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $steamDate = trim($_POST['steam_date'] ?? '');
        $steamTime = trim($_POST['steam_time'] ?? '');
        $steamPkgVersion = preg_replace('/[^0-9]/', '', $_POST['steam_pkg_version'] ?? '');
        $steamuiPkgVersion = preg_replace('/[^0-9]/', '', $_POST['steamui_pkg_version'] ?? '');
        $templateId = intval($_POST['template_id'] ?? 0);
        $sortOrder = intval($_POST['sort_order'] ?? 0);
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;

        // Build packages array from the two version fields
        $packages = [];
        if ($steamPkgVersion) {
            $packages[] = 'Steam_' . $steamPkgVersion;
        }
        if ($steamuiPkgVersion) {
            $packages[] = 'SteamUI_' . $steamuiPkgVersion;
        }

        if (!$versionId) {
            setFlash('error', 'Version ID is required.');
        } elseif ($db->getClientVersionByVersionId($versionId)) {
            setFlash('error', 'A version with this ID already exists.');
        } else {
            $id = $db->createClientVersion(
                $versionId,
                $displayName,
                $steamDate ?: null,
                $steamTime ?: null,
                $packages,
                [], // Empty skip_tests - replaced by templates
                $sortOrder,
                $isEnabled,
                $_SESSION['user_id']
            );
            if ($id) {
                // Assign template to this version (one-to-one relationship)
                $db->setVersionTemplate($id, $templateId ?: null);
                setFlash('success', "Client version created successfully.");
            } else {
                setFlash('error', 'Failed to create client version.');
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $versionId = trim($_POST['version_id'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $steamDate = trim($_POST['steam_date'] ?? '');
        $steamTime = trim($_POST['steam_time'] ?? '');
        $steamPkgVersion = preg_replace('/[^0-9]/', '', $_POST['steam_pkg_version'] ?? '');
        $steamuiPkgVersion = preg_replace('/[^0-9]/', '', $_POST['steamui_pkg_version'] ?? '');
        $templateId = intval($_POST['template_id'] ?? 0);
        $sortOrder = intval($_POST['sort_order'] ?? 0);
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;

        // Build packages array from the two version fields
        $packages = [];
        if ($steamPkgVersion) {
            $packages[] = 'Steam_' . $steamPkgVersion;
        }
        if ($steamuiPkgVersion) {
            $packages[] = 'SteamUI_' . $steamuiPkgVersion;
        }

        $version = $db->getClientVersion($id);
        if (!$version) {
            setFlash('error', 'Version not found.');
        } elseif (!$versionId) {
            setFlash('error', 'Version ID is required.');
        } else {
            if ($db->updateClientVersion($id, $versionId, $displayName, $steamDate ?: null, $steamTime ?: null, $packages, [], $sortOrder, $isEnabled)) {
                // Update template assignment (one-to-one relationship)
                $db->setVersionTemplate($id, $templateId ?: null);
                setFlash('success', "Client version updated successfully.");
            } else {
                setFlash('error', 'Failed to update client version.');
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $version = $db->getClientVersion($id);
        if (!$version) {
            setFlash('error', 'Version not found.');
        } else {
            if ($db->deleteClientVersion($id)) {
                setFlash('success', "Client version '{$version['version_id']}' deleted.");
            } else {
                setFlash('error', 'Failed to delete version.');
            }
        }
    } elseif ($action === 'import_defaults') {
        // Import versions from the Python VERSIONS list
        $defaultVersions = [
            ["id" => "secondblob.bin.2002-02-25 07_42_30 - Steam 2002 Beta v0 Released (C)", "packages" => ["Steam_0", "SteamUI_06001000"], "steam_date" => "2002-02-25", "steam_time" => "07_42_30", "skip_tests" => ["2a", "2b", "2c", "2f", "4", "5", "6", "7", "8", "9", "10", "12a", "12d", "12e", "12f", "13", "14a", "14b", "14c", "23", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2003-01-13 23_03_03 - Steam 2003 Beta v1 Released", "packages" => ["Steam_0", "Platform_0"], "steam_date" => "2003-01-13", "steam_time" => "23_03_03", "skip_tests" => ["2a", "2b", "2c", "2f", "4", "5", "6", "7", "8", "9", "10", "12d", "12e", "12f", "13", "14a", "14b","14c", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2003-01-20 03_39_01 - Steam 2003 Beta v2 Released", "packages" => ["Steam_1", "Platform_2"], "steam_date" => "2003-01-20", "steam_time" => "03_39_01", "skip_tests" => ["2a", "2b", "2c", "2f", "5", "6", "7", "8", "9", "10", "12d", "12e", "12f", "13", "14a", "14b", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2003-06-10 05_01_47", "packages" => ["Steam_4", "Platform_10"], "steam_date" => "2003-06-10", "steam_time" => "05_01_47", "skip_tests" => ["2a", "2b", "2c", "2f", "5", "6", "7", "8", "9", "10", "12d", "12e", "12f", "13", "14a", "14b", "15", "16", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2003-09-12 07_07_07 - Platform added to Steam (Steam released) (C)", "packages" => ["Steam_0", "SteamUI_0"], "steam_date" => "2003-09-12", "steam_time" => "07_07_07", "skip_tests" => ["2a", "2b", "2c", "2f", "4", "7", "8", "9", "12d", "12e", "12f", "13", "14a", "14b", "15", "16", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2003-11-12 19_21_23 - Game engine update (C)", "packages" => ["Steam_0", "SteamUI_2"], "steam_date" => "2003-11-12", "steam_time" => "19_21_23", "skip_tests" => ["2a", "2b", "2c", "2f", "7", "8", "9", "12d", "12e", "12f", "13", "14a", "14b", "15", "16", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2004-01-08 00_55_57 - Steam update released (C)", "packages" => ["Steam_2", "SteamUI_5"], "steam_date" => "2004-01-08", "steam_time" => "00_55_57", "skip_tests" => ["2a","2b", "2c", "2f", "7", "8", "9", "12d", "12e", "12f", "13", "14a", "14b", "15", "16", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2004-04-19 05_47_56", "packages" => ["Steam_3", "SteamUI_7"], "steam_date" => "2004-04-19", "steam_time" => "05_47_56", "skip_tests" => ["2a", "2b", "2c", "2f", "7", "8", "9", "12d", "12e", "12f", "15", "16", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2004-06-29 01_40_35", "packages" => ["Steam_6", "SteamUI_20"], "steam_date" => "2004-06-29", "steam_time" => "01_40_35", "skip_tests" => ["8", "12d", "12e", "12f", "15", "16", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2004-08-14 15_09_26", "packages" => ["Steam_8", "SteamUI_24"], "steam_date" => "2004-08-14", "steam_time" => "15_09_26", "skip_tests" => ["8", "15", "16", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2004-08-24 02_52_13", "packages" => ["Steam_9", "SteamUI_25"], "steam_date" => "2004-08-24", "steam_time" => "02_52_13", "skip_tests" => ["8", "15", "16", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2004-11-20 16_01_17", "packages" => ["Steam_10", "SteamUI_35"], "steam_date" => "2004-11-20", "steam_time" => "16_01_17", "skip_tests" => ["8", "15", "16", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2005-09-28 14_44_48", "packages" => ["Steam_14", "SteamUI_51"], "steam_date" => "2005-09-28", "steam_time" => "14_44_48", "skip_tests" => ["8", "15", "16", "17", "24", "25", "26", "27", "28"]],
            ["id" => "secondblob.bin.2006-03-14 07_11_07 - Steam (Friends Beta Launch)", "packages" => ["Steam_14", "SteamUI_120"], "steam_date" => "2006-03-14", "steam_time" => "07_11_07", "skip_tests" => ["8", "15", "16", "17", "18", "19", "20", "21", "22", "23"]],
            ["id" => "secondblob.bin.2006-06-01 17_01_48 - Half-Life 2 Episode One (Released to Steam)", "packages" => ["Steam_14", "SteamUI_147"], "steam_date" => "2006-06-01", "steam_time" => "17_01_48", "skip_tests" => ["8", "15", "16", "17", "18", "19", "20", "21", "22", "23"]],
            ["id" => "secondblob.bin.2007-08-16 21_48_27 - Bioshock (Pre-loading Begins) Lost Planet EC (DX10, Mappack #2)", "packages" => ["Steam_36", "SteamUI_336"], "steam_date" => "2007-08-16", "steam_time" => "21_48_27", "skip_tests" => ["8", "15", "16", "17", "18", "19", "20", "21", "22", "23"]],
            ["id" => "secondblob.bin.2008-04-30 00_58_27 - Team Fortress 2 (Goldrush Update)", "packages" => ["Steam_46", "SteamUI_494"], "steam_date" => "2008-04-30", "steam_time" => "00_58_27", "skip_tests" => ["15", "16", "17", "18", "19", "20", "21", "22", "23"]],
            ["id" => "secondblob.bin.2009-10-30 01_21_37 - Team Fortress 2  (Haunted Hallowe'en Special)", "packages" => ["Steam_55", "SteamUI_1003"], "steam_date" => "2009-10-30", "steam_time" => "01_21_37", "skip_tests" => ["15", "16", "17", "18", "19", "20", "21", "22", "23"]],
            ["id" => "secondblob.bin.2010-02-23 19_58_43", "packages" => ["Steam_56", "SteamUI_1098"], "steam_date" => "2010-02-23", "steam_time" => "19_58_43", "skip_tests" => ["15", "16", "17", "18", "19", "20", "21", "22", "23"]],
            ["id" => "secondblob.bin.2010-04-29 00_30_18 - Team Fortress 2 (Added cp_freight)", "packages" => ["Steam_60", "SteamUI_1218"], "steam_date" => "2010-04-29", "steam_time" => "00_30_18", "skip_tests" => ["15", "16", "17", "18", "19", "20", "21", "22", "23"]],
            ["id" => "secondblob.bin.2010-06-07 20_34_48", "packages" => ["Steam_61", "SteamUI_1238"], "steam_date" => "2010-06-07", "steam_time" => "20_34_48", "skip_tests" => ["15", "16", "17", "18", "19", "20", "21", "22", "23"]],
            ["id" => "secondblob.bin.2011-04-25 20_48_38", "packages" => ["Steam_64", "SteamUI_1533"], "steam_date" => "2011-04-25", "steam_time" => "20_48_38", "skip_tests" => ["15", "16", "17", "18", "19", "20", "21", "22", "23"]],
        ];

        $imported = 0;
        $skipped = 0;
        foreach ($defaultVersions as $idx => $v) {
            if (!$db->getClientVersionByVersionId($v['id'])) {
                $db->createClientVersion(
                    $v['id'],
                    null,
                    $v['steam_date'],
                    $v['steam_time'],
                    $v['packages'],
                    $v['skip_tests'],
                    $idx,
                    true,
                    $_SESSION['user_id']
                );
                $imported++;
            } else {
                $skipped++;
            }
        }
        setFlash('success', "Imported $imported versions. $skipped already existed.");
    }

    header('Location: ?page=admin_versions');
    exit;
}

// Get all versions
$versions = $db->getClientVersions();
$maxSortOrder = $db->getMaxClientVersionSortOrder();
?>

<h1 class="page-title">Client Versions</h1>

<div class="admin-nav" style="margin-bottom: 20px;">
    <a href="?page=admin" class="btn btn-sm btn-secondary">&larr; Back to Admin</a>
    <a href="?page=admin_version_notifications" class="btn btn-sm btn-secondary">Version Notifications &rarr;</a>
</div>

<?php if (empty($versions)): ?>
<div class="card" style="margin-bottom: 20px;">
    <h3>Import Default Versions</h3>
    <p>No client versions found. You can import the default Steam client versions to get started.</p>
    <form method="POST" style="display: inline;">
        <input type="hidden" name="action" value="import_defaults">
        <button type="submit" class="btn">Import Default Versions</button>
    </form>
</div>
<?php endif; ?>

<div class="versions-layout">
    <!-- Create New Version Form -->
    <div class="card">
        <h3>Add New Version</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label for="version_id">Version ID *</label>
                <input type="text" id="version_id" name="version_id" required placeholder="e.g., secondblob.bin.2004-01-15">
                <small class="form-hint">Full version identifier string</small>
            </div>

            <div class="form-group">
                <label for="display_name">Display Name</label>
                <input type="text" id="display_name" name="display_name" placeholder="e.g., Steam 2004 Release">
                <small class="form-hint">Optional friendly name</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="steam_date">Steam Date</label>
                    <input type="date" id="steam_date" name="steam_date">
                </div>
                <div class="form-group">
                    <label for="steam_time">Steam Time</label>
                    <input type="text" id="steam_time" name="steam_time" placeholder="HH_MM_SS">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="max-width: 150px;">
                    <label for="steam_pkg_version">Steam PKG</label>
                    <input type="text" id="steam_pkg_version" name="steam_pkg_version"
                           placeholder="e.g., 14" pattern="[0-9]*" maxlength="5"
                           style="text-align: center; font-family: monospace;"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 5)">
                    <small class="form-hint">Package number only</small>
                </div>
                <div class="form-group" style="max-width: 150px;">
                    <label for="steamui_pkg_version">SteamUI PKG</label>
                    <input type="text" id="steamui_pkg_version" name="steamui_pkg_version"
                           placeholder="e.g., 120" pattern="[0-9]*" maxlength="5"
                           style="text-align: center; font-family: monospace;"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 5)">
                    <small class="form-hint">Package number only</small>
                </div>
            </div>

            <div class="form-group">
                <label for="template_id">Test Template</label>
                <select id="template_id" name="template_id">
                    <option value="0">-- Use Default Template --</option>
                    <?php foreach ($allTemplates as $template): ?>
                        <?php if (!$template['is_default']): ?>
                        <option value="<?= $template['id'] ?>">
                            <?= e($template['name']) ?> (<?= count($template['test_keys'] ?? []) ?> tests)
                        </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <small class="form-hint">Select a template to limit visible tests for this version</small>
            </div>

            <input type="hidden" name="sort_order" value="<?= $maxSortOrder + 1 ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_enabled" checked>
                        Enabled
                    </label>
                </div>
            </div>

            <button type="submit" class="btn">Add Version</button>
        </form>
    </div>

    <!-- Versions List -->
    <div class="card">
        <div class="card-header-flex">
            <h3>Existing Versions (<?= count($versions) ?>)</h3>
            <?php if (!empty($versions)): ?>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Import default versions? Existing versions will not be overwritten.');">
                <input type="hidden" name="action" value="import_defaults">
                <button type="submit" class="btn btn-sm btn-secondary">Import Defaults</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (empty($versions)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 20px;">No versions configured yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Version ID</th>
                            <th>Date</th>
                            <th>Packages</th>
                            <th>Template</th>
                            <th style="width: 80px;">Status</th>
                            <th style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($versions as $version):
                            // Get template for this version
                            $versionTemplate = $db->getTemplateForVersion($version['id']);
                        ?>
                            <tr class="version-row <?= $version['is_enabled'] ? '' : 'disabled-row' ?>" id="version-<?= $version['id'] ?>">
                                <td class="version-id">
                                    <span class="version-id-text" title="<?= e($version['version_id']) ?>">
                                        <?= e(strlen($version['version_id']) > 60 ? substr($version['version_id'], 0, 60) . '...' : $version['version_id']) ?>
                                    </span>
                                    <?php if ($version['display_name']): ?>
                                        <br><small class="text-muted"><?= e($version['display_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= $version['steam_date'] ? e($version['steam_date']) : '-' ?></td>
                                <td>
                                    <small><?= e(implode(', ', $version['packages'] ?: [])) ?></small>
                                </td>
                                <td>
                                    <?php if ($versionTemplate): ?>
                                        <small class="<?= $versionTemplate['is_default'] ? 'text-muted' : '' ?>">
                                            <?= e($versionTemplate['name']) ?>
                                            <?php if (!$versionTemplate['is_default']): ?>
                                                (<?= count($versionTemplate['test_keys'] ?? []) ?> tests)
                                            <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <small class="text-muted">Default</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($version['is_enabled']): ?>
                                        <span class="badge badge-success">Enabled</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <?php
                                    // Add template_id to version data for edit modal
                                    $versionWithTemplate = $version;
                                    $versionWithTemplate['template_id'] = $versionTemplate ? $versionTemplate['id'] : 0;
                                ?>
                                <td>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openEditModal(<?= htmlspecialchars(json_encode($versionWithTemplate)) ?>)">Edit</button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this version? This will also delete all associated notifications.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $version['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Del</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Version</h3>
            <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">

            <div class="form-group">
                <label for="edit_version_id">Version ID *</label>
                <input type="text" id="edit_version_id" name="version_id" required>
            </div>

            <div class="form-group">
                <label for="edit_display_name">Display Name</label>
                <input type="text" id="edit_display_name" name="display_name">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="edit_steam_date">Steam Date</label>
                    <input type="date" id="edit_steam_date" name="steam_date">
                </div>
                <div class="form-group">
                    <label for="edit_steam_time">Steam Time</label>
                    <input type="text" id="edit_steam_time" name="steam_time">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="max-width: 150px;">
                    <label for="edit_steam_pkg_version">Steam PKG</label>
                    <input type="text" id="edit_steam_pkg_version" name="steam_pkg_version"
                           placeholder="e.g., 14" pattern="[0-9]*" maxlength="5"
                           style="text-align: center; font-family: monospace;"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 5)">
                </div>
                <div class="form-group" style="max-width: 150px;">
                    <label for="edit_steamui_pkg_version">SteamUI PKG</label>
                    <input type="text" id="edit_steamui_pkg_version" name="steamui_pkg_version"
                           placeholder="e.g., 120" pattern="[0-9]*" maxlength="5"
                           style="text-align: center; font-family: monospace;"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 5)">
                </div>
            </div>

            <div class="form-group">
                <label for="edit_template_id">Test Template</label>
                <select id="edit_template_id" name="template_id">
                    <option value="0">-- Use Default Template --</option>
                    <?php foreach ($allTemplates as $template): ?>
                        <?php if (!$template['is_default']): ?>
                        <option value="<?= $template['id'] ?>">
                            <?= e($template['name']) ?> (<?= count($template['test_keys'] ?? []) ?> tests)
                        </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <input type="hidden" id="edit_sort_order" name="sort_order">
            <div class="form-row">
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_enabled" id="edit_is_enabled">
                        Enabled
                    </label>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<style>
.versions-layout {
    display: grid;
    grid-template-columns: 400px 1fr;
    gap: 20px;
}

@media (max-width: 1200px) {
    .versions-layout {
        grid-template-columns: 1fr;
    }
}

.form-row {
    display: flex;
    gap: 15px;
}

.form-row .form-group {
    flex: 1;
}

.form-hint {
    display: block;
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 4px;
}

.card-header-flex {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.card-header-flex h3 {
    margin: 0;
}

.disabled-row {
    opacity: 0.6;
}

.version-id-text {
    font-family: monospace;
    font-size: 12px;
}

.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
}

.badge-success {
    background: var(--green);
    color: #fff;
}

.badge-secondary {
    background: var(--text-muted);
    color: #fff;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding-top: 28px;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
}

/* Modal styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.7);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.modal-content {
    background: var(--bg-card);
    border-radius: 8px;
    padding: 20px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-muted);
}

.modal-close:hover {
    color: var(--text);
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}
</style>

<script>
function openEditModal(version) {
    document.getElementById('edit_id').value = version.id;
    document.getElementById('edit_version_id').value = version.version_id;
    document.getElementById('edit_display_name').value = version.display_name || '';
    document.getElementById('edit_steam_date').value = version.steam_date || '';
    document.getElementById('edit_steam_time').value = version.steam_time || '';

    // Parse packages array to extract version numbers
    var steamPkg = '';
    var steamuiPkg = '';
    (version.packages || []).forEach(function(pkg) {
        if (pkg.indexOf('Steam_') === 0 && pkg.indexOf('SteamUI_') !== 0) {
            steamPkg = pkg.replace('Steam_', '');
        } else if (pkg.indexOf('SteamUI_') === 0) {
            steamuiPkg = pkg.replace('SteamUI_', '');
        }
    });
    document.getElementById('edit_steam_pkg_version').value = steamPkg;
    document.getElementById('edit_steamui_pkg_version').value = steamuiPkg;

    document.getElementById('edit_template_id').value = version.template_id || 0;
    document.getElementById('edit_sort_order').value = version.sort_order;
    document.getElementById('edit_is_enabled').checked = version.is_enabled == 1;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

// Close modal on background click
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
