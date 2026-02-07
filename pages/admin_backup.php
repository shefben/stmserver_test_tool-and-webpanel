<?php
/**
 * Admin Panel - Database Backup & Import
 * Export: SQL file with CREATE TABLE + INSERT statements for the entire panel database
 * Import: Execute SQL statements from an uploaded .sql file
 */

// Tables in dependency order (foreign keys respected)
define('PANEL_EXPORT_TABLES', [
    'users',
    'client_versions',
    'test_templates',
    'test_template_versions',
    'reports',
    'test_results',
    'report_revisions',
    'report_logs',
    'report_comments',
    'report_tags',
    'report_tag_assignments',
    'retest_requests',
    'fixed_tests',
    'user_notifications',
    'version_notifications',
    'invite_codes',
    'site_settings',
]);

/**
 * Handle the SQL file download (called from index.php before HTML)
 */
function handle_panel_export() {
    // Must be admin
    if (!isLoggedIn() || !isAdmin()) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }

    $db = Database::getInstance();
    $pdo = $db->getPdo();
    $timestamp = date('Y-m-d_His');
    $filename = "steam_test_panel_backup_{$timestamp}.sql";

    header('Content-Type: application/sql; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo "-- Steam Test Panel Database Backup\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "-- ==========================================\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach (PANEL_EXPORT_TABLES as $table) {
        // Check table exists
        try {
            $check = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            if ($check->rowCount() === 0) continue;
        } catch (Exception $e) {
            continue;
        }

        echo "-- -------------------------------------------\n";
        echo "-- Table: {$table}\n";
        echo "-- -------------------------------------------\n\n";

        // CREATE TABLE statement
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        echo "DROP TABLE IF EXISTS `{$table}`;\n";
        echo $create['Create Table'] . ";\n\n";

        // INSERT statements
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "-- (no data)\n\n";
            continue;
        }

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
            echo "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $values) . ");\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
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

                // Classify statements as DDL or DML
                $isDDL = function($stmt) {
                    $upper = strtoupper(ltrim($stmt));
                    return preg_match('/^(CREATE|DROP|ALTER|TRUNCATE)\s/i', $upper);
                };

                // In data-only mode, skip DDL (DROP/CREATE TABLE) statements
                // This allows importing data into existing tables on another server
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

                $success = "Import complete: {$executed} statement(s) executed from <strong>" . e($file['name']) . "</strong>.";
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

// Table stats
$stats = [];
$totalRows = 0;
foreach (PANEL_EXPORT_TABLES as $table) {
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
        <p style="color: var(--text-muted);">Export the entire database or import from an SQL backup file</p>
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

<!-- Export -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">Export Database</h3>
    <p style="color: var(--text-muted); margin-bottom: 15px;">
        Download a complete SQL backup containing <code>CREATE TABLE</code> and <code>INSERT</code> statements for all panel tables.
    </p>

    <div class="table-container" style="margin-bottom: 20px;">
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

    <a href="?page=admin_backup&download=1" class="btn">Download SQL Backup</a>
</div>

<!-- Import -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">Import Database</h3>
    <p style="color: var(--text-muted); margin-bottom: 10px;">
        Upload a <code>.sql</code> file exported from another panel instance to import or migrate data between servers.
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
                        <span class="import-mode-desc">Drops and recreates all tables, then inserts data. Use this to fully restore from a backup.</span>
                    </div>
                </label>
                <label class="import-mode-option">
                    <input type="radio" name="import_mode" value="data_only">
                    <div>
                        <strong>Data Only (Migration)</strong>
                        <span class="import-mode-desc">Skips DROP/CREATE TABLE statements and only executes INSERT and SET statements. Use this to import reports and data into an existing database on another server.</span>
                    </div>
                </label>
            </div>
        </div>

        <div class="info-box" style="background: rgba(231, 76, 60, 0.1); border-color: var(--status-broken); margin-bottom: 15px;">
            <p style="margin: 0;"><strong>Warning:</strong> Full Restore mode will drop and replace all existing tables. Data Only mode may cause duplicate key errors if data already exists. Make sure you have a backup first.</p>
        </div>

        <button type="submit" class="btn" onclick="return confirm('Are you sure you want to import this SQL file? This may overwrite existing data.')">
            Import SQL File
        </button>
    </form>
</div>

<style>
.info-box {
    border-radius: 6px;
    padding: 15px;
    border: 1px solid #3498db;
}
.info-box p {
    color: var(--text);
}
.info-box code {
    background: var(--bg-dark);
    padding: 2px 6px;
    border-radius: 3px;
}
.form-group {
    margin-bottom: 20px;
}
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
.import-mode-option:hover {
    border-color: var(--primary);
}
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

<?php
require_once __DIR__ . '/../includes/footer.php';
endif;
?>
