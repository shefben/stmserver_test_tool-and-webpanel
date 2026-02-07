<?php
/**
 * Admin Panel - Database Backup & Import
 * Selective export wizard: choose categories, then specific items to export
 * Import: Execute SQL statements from an uploaded .sql file
 */

// Category labels for display
define('EXPORT_CATEGORY_LABELS', [
    'reports'         => 'Reports',
    'users'           => 'Users',
    'client_versions' => 'Client Versions',
    'tests'           => 'Tests',
    'templates'       => 'Templates',
    'tags'            => 'Tags',
    'retests'         => 'Retest Information',
]);

// All tables that have AUTO_INCREMENT id columns
define('AUTO_INCREMENT_TABLES', [
    'users', 'reports', 'test_results', 'report_revisions', 'report_logs',
    'report_comments', 'test_templates', 'report_tags', 'report_tag_assignments',
    'retest_requests', 'fixed_tests', 'user_notifications', 'version_notifications',
    'invite_codes', 'client_versions', 'test_template_versions',
]);

/**
 * Export selected data as SQL file (called from index.php before HTML output)
 */
function handle_panel_export() {
    if (!isLoggedIn() || !isAdmin()) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }

    $selection = json_decode($_POST['export_selection'] ?? '{}', true);
    if (empty($selection)) {
        http_response_code(400);
        echo 'No selection provided';
        return;
    }

    $db = Database::getInstance();
    $pdo = $db->getPdo();
    $timestamp = date('Y-m-d_His');
    $filename = "steam_test_panel_export_{$timestamp}.sql";

    header('Content-Type: application/sql; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo "-- Steam Test Panel Selective Export\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Categories: " . implode(', ', array_keys($selection)) . "\n";
    echo "-- ==========================================\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach ($selection as $category => $itemIds) {
        if (empty($itemIds) || !is_array($itemIds)) continue;

        echo "-- ===========================================\n";
        echo "-- Category: " . (EXPORT_CATEGORY_LABELS[$category] ?? $category) . "\n";
        echo "-- ===========================================\n\n";

        switch ($category) {
            case 'reports':
                $ids = array_map('intval', $itemIds);
                $inList = implode(',', $ids);
                export_table_rows($pdo, 'reports', "id IN ({$inList})");
                export_table_rows($pdo, 'test_results', "report_id IN ({$inList})");
                export_table_rows($pdo, 'report_revisions', "report_id IN ({$inList})");
                export_table_rows($pdo, 'report_logs', "report_id IN ({$inList})");
                export_table_rows($pdo, 'report_comments', "report_id IN ({$inList})");
                export_table_rows($pdo, 'report_tag_assignments', "report_id IN ({$inList})");
                break;

            case 'users':
                $ids = array_map('intval', $itemIds);
                $inList = implode(',', $ids);
                export_table_rows($pdo, 'users', "id IN ({$inList})");
                export_table_rows($pdo, 'user_notifications', "user_id IN ({$inList})");
                export_table_rows($pdo, 'invite_codes', "created_by IN ({$inList}) OR used_by IN ({$inList})");
                break;

            case 'client_versions':
                $ids = array_map('intval', $itemIds);
                $inList = implode(',', $ids);
                export_table_rows($pdo, 'client_versions', "id IN ({$inList})");
                export_table_rows($pdo, 'version_notifications', "client_version_id IN ({$inList})");
                break;

            case 'tests':
                $keys = array_map(function($k) use ($pdo) { return $pdo->quote($k); }, $itemIds);
                $keyList = implode(',', $keys);
                export_table_rows($pdo, 'test_results', "test_key IN ({$keyList})");
                break;

            case 'templates':
                $ids = array_map('intval', $itemIds);
                $inList = implode(',', $ids);
                export_table_rows($pdo, 'test_templates', "id IN ({$inList})");
                export_table_rows($pdo, 'test_template_versions', "template_id IN ({$inList})");
                break;

            case 'tags':
                $ids = array_map('intval', $itemIds);
                $inList = implode(',', $ids);
                export_table_rows($pdo, 'report_tags', "id IN ({$inList})");
                export_table_rows($pdo, 'report_tag_assignments', "tag_id IN ({$inList})");
                break;

            case 'retests':
                $retestIds = [];
                $fixedIds = [];
                foreach ($itemIds as $id) {
                    if (strpos($id, 'retest_') === 0) {
                        $retestIds[] = intval(substr($id, 7));
                    } elseif (strpos($id, 'fixed_') === 0) {
                        $fixedIds[] = intval(substr($id, 6));
                    }
                }
                if (!empty($retestIds)) {
                    export_table_rows($pdo, 'retest_requests', "id IN (" . implode(',', $retestIds) . ")");
                }
                if (!empty($fixedIds)) {
                    export_table_rows($pdo, 'fixed_tests', "id IN (" . implode(',', $fixedIds) . ")");
                }
                break;
        }
    }

    echo "\nSET FOREIGN_KEY_CHECKS = 1;\n";
}

/**
 * Export rows from a table matching a WHERE clause using REPLACE INTO
 */
function export_table_rows($pdo, $table, $where) {
    try {
        $rows = $pdo->query("SELECT * FROM `{$table}` WHERE {$where}")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "-- Table: {$table} (no matching rows)\n\n";
            return;
        }

        echo "-- -------------------------------------------\n";
        echo "-- Table: {$table} (" . count($rows) . " rows)\n";
        echo "-- -------------------------------------------\n\n";

        $columns = array_keys($rows[0]);
        $colList = '`' . implode('`, `', $columns) . '`';

        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $pdo->quote($value);
                }
            }
            echo "REPLACE INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $values) . ");\n";
        }
        echo "\n";
    } catch (Exception $e) {
        echo "-- Error exporting {$table}: " . $e->getMessage() . "\n\n";
    }
}

/**
 * Split SQL into individual statements (respects quoted strings)
 */
function panel_split_sql($sql) {
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inString) {
            $current .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $current .= $sql[++$i];
                continue;
            }
            if ($ch === $stringChar) {
                if ($i + 1 < $len && $sql[$i + 1] === $stringChar) {
                    $current .= $sql[++$i];
                    continue;
                }
                $inString = false;
            }
            continue;
        }

        if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            $eol = strpos($sql, "\n", $i);
            if ($eol === false) break;
            $i = $eol;
            continue;
        }

        if ($ch === '\'' || $ch === '"') {
            $inString = true;
            $stringChar = $ch;
            $current .= $ch;
            continue;
        }

        if ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') $statements[] = $trimmed;
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') $statements[] = $trimmed;

    return $statements;
}

// Only render page UI if included normally (not for download)
if (basename($_SERVER['SCRIPT_FILENAME']) !== 'admin_backup.php'):

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

requireAdmin();

$db = Database::getInstance();
$pdo = $db->getPdo();
$error = '';
$success = '';

// Handle import POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_sql') {
    if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['sql_file']['error'] ?? 'unknown';
        $error = "Upload failed (error code: {$err})";
    } else {
        $file = $_FILES['sql_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext !== 'sql') {
            $error = 'Only .sql files are accepted.';
        } else {
            $sql = file_get_contents($file['tmp_name']);
            if ($sql === false || trim($sql) === '') {
                $error = 'The uploaded file is empty or could not be read.';
            } else {
                $importMode = $_POST['import_mode'] ?? 'full';
                $statements = panel_split_sql($sql);
                $executed = 0;
                $skipped = 0;
                $errors = [];

                $isDDL = function($stmt) {
                    return preg_match('/^(CREATE|DROP|ALTER|TRUNCATE)\s/i', ltrim($stmt));
                };

                foreach ($statements as $stmt) {
                    $trimmed = trim($stmt);
                    if ($trimmed === '') continue;

                    // Skip DDL in data-only mode
                    if ($importMode === 'data_only' && $isDDL($trimmed)) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $pdo->exec($trimmed);
                        $executed++;
                    } catch (PDOException $e) {
                        $errors[] = htmlspecialchars(substr($trimmed, 0, 80)) . '... &mdash; ' . htmlspecialchars($e->getMessage());
                    }
                }

                // Fix auto_increment for all tables to prevent sequence conflicts
                $aiFixed = 0;
                foreach (AUTO_INCREMENT_TABLES as $table) {
                    try {
                        $maxId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$table}`")->fetchColumn();
                        if ($maxId > 0) {
                            $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . ($maxId + 1));
                            $aiFixed++;
                        }
                    } catch (Exception $e) {
                        // Table might not exist yet or have different structure
                    }
                }

                $success = "Import complete: {$executed} statement(s) executed from <strong>" . e($file['name']) . "</strong>.";
                if ($aiFixed > 0) {
                    $success .= "<br>Auto-increment sequences fixed for {$aiFixed} table(s).";
                }
                if ($skipped > 0) {
                    $success .= "<br>{$skipped} DDL statement(s) skipped (data-only mode).";
                }
                if (!empty($errors)) {
                    $success .= '<br><span style="color:var(--status-semi);">' . count($errors) . ' statement(s) had errors (see below).</span>';
                }
            }
        }
    }
}

// Table stats for display
$allTables = array_merge(AUTO_INCREMENT_TABLES, ['site_settings']);
sort($allTables);
$stats = [];
$totalRows = 0;
foreach ($allTables as $table) {
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $stats[$table] = $count;
        $totalRows += $count;
    } catch (Exception $e) {
        $stats[$table] = '&mdash;';
    }
}
?>

<div class="report-header">
    <div>
        <h1 class="page-title">Backup &amp; Import</h1>
        <p style="color: var(--text-muted);">Selectively export data or import from an SQL backup file</p>
    </div>
    <a href="?page=admin" class="btn btn-secondary">&larr; Back to Admin</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="card" style="margin-bottom: 30px; border: 1px solid var(--status-semi);">
    <h3 class="card-title" style="color: var(--status-semi);">Import Errors (<?= count($errors) ?>)</h3>
    <div style="max-height: 300px; overflow-y: auto; font-size: 12px; font-family: monospace; background: var(--bg-dark); padding: 12px; border-radius: 4px;">
        <?php foreach (array_slice($errors, 0, 50) as $e): ?>
            <div style="padding: 4px 0; border-bottom: 1px solid var(--border);"><?= $e ?></div>
        <?php endforeach; ?>
        <?php if (count($errors) > 50): ?>
            <div style="padding: 8px 0; color: var(--text-muted);">... and <?= count($errors) - 50 ?> more</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Hidden form for triggering export download via POST -->
<form id="exportForm" method="POST" action="?page=admin_backup&download=1" style="display:none;">
    <input type="hidden" name="export_selection" id="exportSelectionInput">
</form>

<!-- ===== EXPORT: Step 1 - Category Selection ===== -->
<div id="exportStep1" class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">Export Database &mdash; Step 1: Choose Categories</h3>
    <p style="color: var(--text-muted); margin-bottom: 20px;">
        Select which types of data you would like to export. Only <code>INSERT</code> statements are generated (no table structure).
    </p>

    <div class="export-checkboxes">
        <?php foreach (EXPORT_CATEGORY_LABELS as $key => $label): ?>
        <label class="export-checkbox-label">
            <input type="checkbox" class="export-category-cb" value="<?= $key ?>">
            <span><?= e($label) ?></span>
        </label>
        <?php endforeach; ?>
    </div>

    <div style="margin-top: 20px; display: flex; gap: 10px; align-items: center;">
        <button id="exportNextBtn" class="btn" disabled>Next &rarr;</button>
        <span id="exportStep1Hint" style="color: var(--text-muted); font-size: 13px;">Select at least one category</span>
    </div>

    <!-- Database overview -->
    <details style="margin-top: 25px;">
        <summary style="cursor: pointer; color: var(--text-muted); font-size: 13px;">View database table counts</summary>
        <div class="table-container" style="margin-top: 10px;">
            <table class="sortable">
                <thead>
                    <tr><th>Table</th><th style="text-align: right;">Rows</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $table => $count): ?>
                    <tr>
                        <td><code><?= e($table) ?></code></td>
                        <td style="text-align: right;"><?= $count ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?= count($stats) ?> tables</th>
                        <th style="text-align: right;"><?= number_format($totalRows) ?> total rows</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </details>
</div>

<!-- ===== EXPORT: Step 2 - Item Selection ===== -->
<div id="exportStep2" class="card" style="margin-bottom: 30px; display: none;">
    <h3 class="card-title">Export Database &mdash; Step 2: Select Items</h3>
    <p style="color: var(--text-muted); margin-bottom: 20px;">
        Choose specific items to include in the export. Use the list boxes below to select entries from each category.
        Hold <kbd>Ctrl</kbd> (or <kbd>Cmd</kbd>) to select multiple items.
    </p>

    <div id="exportLoading" style="text-align: center; padding: 30px; display: none;">
        <div class="spinner"></div>
        <p style="color: var(--text-muted); margin-top: 10px;">Loading items...</p>
    </div>

    <div id="exportListboxes"></div>

    <div style="margin-top: 25px; display: flex; gap: 10px; align-items: center;">
        <button id="exportBackBtn" class="btn btn-secondary">&larr; Back</button>
        <button id="exportBtn" class="btn" disabled>Export Selected</button>
        <span id="exportStep2Hint" style="color: var(--text-muted); font-size: 13px;"></span>
    </div>
</div>

<!-- ===== IMPORT ===== -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">Import Database</h3>
    <p style="color: var(--text-muted); margin-bottom: 10px;">
        Upload a <code>.sql</code> file exported from another panel instance. Supports both full backups (with <code>CREATE TABLE</code>)
        and selective exports (with <code>REPLACE INTO</code>). Auto-increment sequences are automatically fixed after import.
    </p>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import_sql">
        <div class="form-group">
            <label for="sql_file">SQL File</label>
            <input type="file" id="sql_file" name="sql_file" accept=".sql" required
                   style="padding: 10px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-dark); color: var(--text); width: 100%;">
        </div>

        <div class="form-group">
            <label>Import Mode</label>
            <div class="import-mode-options">
                <label class="import-mode-option">
                    <input type="radio" name="import_mode" value="full" checked>
                    <div>
                        <strong>Full Restore</strong>
                        <span class="import-mode-desc">Drops and recreates all tables, then inserts data. Use this to fully restore from a legacy full backup.</span>
                    </div>
                </label>
                <label class="import-mode-option">
                    <input type="radio" name="import_mode" value="data_only">
                    <div>
                        <strong>Data Only (Recommended for selective exports)</strong>
                        <span class="import-mode-desc">Skips DROP/CREATE TABLE statements and only executes INSERT, REPLACE, and SET statements. Use this for selective exports or migrating data between servers.</span>
                    </div>
                </label>
            </div>
        </div>

        <div class="info-box" style="background: rgba(231, 76, 60, 0.1); border-color: var(--status-broken); margin-bottom: 15px;">
            <p style="margin: 0;"><strong>Warning:</strong> Full Restore mode will drop and replace all existing tables. Data Only mode uses REPLACE INTO which overwrites matching rows. Auto-increment sequences are fixed automatically after import.</p>
        </div>

        <button type="submit" class="btn" onclick="return confirm('Are you sure you want to import this SQL file? This may overwrite existing data.')">
            Import SQL File
        </button>
    </form>
</div>

<style>
/* Export wizard styles */
.export-checkboxes {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}
.export-checkbox-label {
    display: flex !important;
    align-items: center;
    gap: 10px;
    padding: 12px 15px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 6px;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    font-weight: 500;
}
.export-checkbox-label:hover {
    border-color: var(--primary);
    background: rgba(196, 181, 80, 0.05);
}
.export-checkbox-label input:checked + span {
    color: var(--primary);
}
.export-checkbox-label input {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    accent-color: var(--primary);
}

/* Listbox category sections */
.export-category-section {
    margin-bottom: 25px;
    padding: 15px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 6px;
}
.export-category-section h4 {
    margin: 0 0 10px 0;
    color: var(--primary);
    font-size: 15px;
}
.export-listbox {
    width: 100%;
    min-height: 120px;
    max-height: 250px;
    padding: 0;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    font-family: monospace;
    font-size: 12px;
}
.export-listbox option {
    padding: 6px 10px;
    border-bottom: 1px solid var(--border);
}
.export-listbox option:checked {
    background: rgba(196, 181, 80, 0.25);
    color: var(--text);
}
.export-listbox option:hover {
    background: rgba(196, 181, 80, 0.1);
}
.export-listbox-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    align-items: center;
}
.export-listbox-actions .btn-sm {
    padding: 4px 12px;
    font-size: 12px;
}
.export-listbox-count {
    margin-left: auto;
    font-size: 12px;
    color: var(--text-muted);
}

/* Spinner */
.spinner {
    display: inline-block;
    width: 28px;
    height: 28px;
    border: 3px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Kbd styling */
kbd {
    display: inline-block;
    padding: 2px 6px;
    font-size: 11px;
    font-family: monospace;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 3px;
    color: var(--text-muted);
}

/* Shared styles */
.info-box {
    border-radius: 6px;
    padding: 15px;
    border: 1px solid #3498db;
}
.info-box p { color: var(--text); }
.info-box code {
    background: var(--bg-dark);
    padding: 2px 6px;
    border-radius: 3px;
}
.form-group { margin-bottom: 20px; }
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text-muted);
}
.import-mode-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.import-mode-option {
    display: flex !important;
    align-items: flex-start;
    gap: 10px;
    padding: 12px;
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 6px;
    cursor: pointer;
    transition: border-color 0.2s;
}
.import-mode-option:hover { border-color: var(--primary); }
.import-mode-option input[type="radio"] {
    margin-top: 3px;
    flex-shrink: 0;
}
.import-mode-option strong {
    display: block;
    color: var(--text);
    margin-bottom: 4px;
}
.import-mode-desc {
    display: block;
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 400;
}
</style>

<script>
(function() {
    var step1 = document.getElementById('exportStep1');
    var step2 = document.getElementById('exportStep2');
    var nextBtn = document.getElementById('exportNextBtn');
    var backBtn = document.getElementById('exportBackBtn');
    var exportBtn = document.getElementById('exportBtn');
    var exportForm = document.getElementById('exportForm');
    var exportInput = document.getElementById('exportSelectionInput');
    var listboxContainer = document.getElementById('exportListboxes');
    var loadingEl = document.getElementById('exportLoading');
    var step1Hint = document.getElementById('exportStep1Hint');
    var step2Hint = document.getElementById('exportStep2Hint');
    var checkboxes = document.querySelectorAll('.export-category-cb');

    var categoryLabels = <?= json_encode(EXPORT_CATEGORY_LABELS) ?>;

    // Enable/disable Next button based on checkbox state
    function updateNextBtn() {
        var anyChecked = false;
        checkboxes.forEach(function(cb) {
            if (cb.checked) anyChecked = true;
        });
        nextBtn.disabled = !anyChecked;
        step1Hint.textContent = anyChecked ? '' : 'Select at least one category';
    }

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', updateNextBtn);
    });

    // Step 1 -> Step 2
    nextBtn.addEventListener('click', function() {
        var selected = [];
        checkboxes.forEach(function(cb) {
            if (cb.checked) selected.push(cb.value);
        });

        if (selected.length === 0) return;

        step1.style.display = 'none';
        step2.style.display = '';
        listboxContainer.innerHTML = '';
        loadingEl.style.display = '';
        exportBtn.disabled = true;
        step2Hint.textContent = 'Loading...';

        // Fetch items for all selected categories in parallel
        var fetches = selected.map(function(cat) {
            return fetch('api/export_items.php?category=' + encodeURIComponent(cat))
                .then(function(r) { return r.json(); })
                .then(function(data) { return { category: cat, items: data.items || [] }; })
                .catch(function() { return { category: cat, items: [] }; });
        });

        Promise.all(fetches).then(function(results) {
            loadingEl.style.display = 'none';
            listboxContainer.innerHTML = '';

            results.forEach(function(result) {
                var section = document.createElement('div');
                section.className = 'export-category-section';

                var title = document.createElement('h4');
                title.textContent = categoryLabels[result.category] || result.category;
                section.appendChild(title);

                if (result.items.length === 0) {
                    var empty = document.createElement('p');
                    empty.style.color = 'var(--text-muted)';
                    empty.style.fontSize = '13px';
                    empty.textContent = 'No items found for this category.';
                    section.appendChild(empty);
                    listboxContainer.appendChild(section);
                    return;
                }

                var select = document.createElement('select');
                select.multiple = true;
                select.className = 'export-listbox';
                select.setAttribute('data-category', result.category);
                select.size = Math.min(result.items.length, 12);

                result.items.forEach(function(item) {
                    var opt = document.createElement('option');
                    opt.value = item.id;
                    opt.textContent = item.label;
                    select.appendChild(opt);
                });

                select.addEventListener('change', updateExportBtn);
                section.appendChild(select);

                // Select All / Unselect All buttons + count
                var actions = document.createElement('div');
                actions.className = 'export-listbox-actions';

                var selAllBtn = document.createElement('button');
                selAllBtn.type = 'button';
                selAllBtn.className = 'btn btn-sm';
                selAllBtn.textContent = 'Select All';
                selAllBtn.addEventListener('click', function() {
                    Array.from(select.options).forEach(function(o) { o.selected = true; });
                    updateExportBtn();
                });

                var unselBtn = document.createElement('button');
                unselBtn.type = 'button';
                unselBtn.className = 'btn btn-sm btn-secondary';
                unselBtn.textContent = 'Unselect All';
                unselBtn.addEventListener('click', function() {
                    Array.from(select.options).forEach(function(o) { o.selected = false; });
                    updateExportBtn();
                });

                var countSpan = document.createElement('span');
                countSpan.className = 'export-listbox-count';
                countSpan.setAttribute('data-total', result.items.length);
                countSpan.textContent = '0 / ' + result.items.length + ' selected';

                actions.appendChild(selAllBtn);
                actions.appendChild(unselBtn);
                actions.appendChild(countSpan);
                section.appendChild(actions);

                listboxContainer.appendChild(section);
            });

            updateExportBtn();
        });
    });

    // Step 2 -> Step 1
    backBtn.addEventListener('click', function() {
        step2.style.display = 'none';
        step1.style.display = '';
    });

    // Update export button state and selection counts
    function updateExportBtn() {
        var listboxes = listboxContainer.querySelectorAll('.export-listbox');
        var totalSelected = 0;
        var allHaveSelection = true;

        listboxes.forEach(function(sel) {
            var selectedCount = sel.selectedOptions.length;
            totalSelected += selectedCount;

            // Update count display
            var section = sel.closest('.export-category-section');
            var countSpan = section.querySelector('.export-listbox-count');
            if (countSpan) {
                var total = countSpan.getAttribute('data-total');
                countSpan.textContent = selectedCount + ' / ' + total + ' selected';
            }

            if (selectedCount === 0) allHaveSelection = false;
        });

        // Enable export if at least one item is selected anywhere
        exportBtn.disabled = (totalSelected === 0);
        if (totalSelected === 0) {
            step2Hint.textContent = 'Select at least one item to export';
        } else {
            step2Hint.textContent = totalSelected + ' item(s) selected for export';
        }
    }

    // Export button - build selection and submit form
    exportBtn.addEventListener('click', function() {
        var selection = {};
        var listboxes = listboxContainer.querySelectorAll('.export-listbox');

        listboxes.forEach(function(sel) {
            var cat = sel.getAttribute('data-category');
            var ids = Array.from(sel.selectedOptions).map(function(o) { return o.value; });
            if (ids.length > 0) {
                selection[cat] = ids;
            }
        });

        if (Object.keys(selection).length === 0) return;

        exportInput.value = JSON.stringify(selection);
        exportForm.submit();
    });
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
endif;
?>
