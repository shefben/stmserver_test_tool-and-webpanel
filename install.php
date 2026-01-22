<?php
/**
 * Steam Emulator Test Panel - Installation Script
 * Run this once to set up the database and create the admin account
 */

// Prevent running if already installed
$configFile = __DIR__ . '/config.php';
$lockFile = __DIR__ . '/data/.installed';

if (file_exists($lockFile)) {
    $isCli = php_sapi_name() === 'cli';
    if ($isCli) {
        die("Installation already completed. Delete data/.installed to reinstall.\n");
    } else {
        // Show a proper HTML page for web access
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Already Installed - Steam Emulator Test Panel</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #3e4637;
                    color: #eff6ee;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .container {
                    background: #4c5844;
                    padding: 40px;
                    border-radius: 12px;
                    max-width: 500px;
                    width: 100%;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                    text-align: center;
                }
                h1 {
                    color: #c4b550;
                    margin-bottom: 10px;
                    font-size: 24px;
                }
                p {
                    color: #a0aa95;
                    margin-bottom: 20px;
                    line-height: 1.6;
                }
                .info-box {
                    background: #3e4637;
                    padding: 15px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    font-size: 13px;
                    color: #a0aa95;
                }
                code {
                    background: #2d3229;
                    padding: 2px 6px;
                    border-radius: 4px;
                    font-family: monospace;
                    color: #c4b550;
                }
                a.btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #7ea64b;
                    color: #fff;
                    text-decoration: none;
                    border-radius: 6px;
                    font-weight: 600;
                    margin: 5px;
                }
                a.btn:hover {
                    background: #8eb65b;
                }
                a.btn.secondary {
                    background: #5a6a50;
                }
                a.btn.secondary:hover {
                    background: #6a7a60;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Panel Already Installed</h1>
                <p>The Steam Emulator Test Panel has already been installed and configured.</p>

                <div class="info-box">
                    To reinstall, delete the file:<br>
                    <code>data/.installed</code>
                </div>

                <a href="index.php" class="btn">Go to Panel</a>
                <a href="index.php?page=login" class="btn secondary">Login</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Check if running from CLI or web
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    // CLI Installation
    echo "===========================================\n";
    echo " Steam Emulator Test Panel - Installation\n";
    echo "===========================================\n\n";

    // Get database settings
    echo "Database Configuration\n";
    echo "----------------------\n";

    echo "Database Host [localhost]: ";
    $dbHost = trim(fgets(STDIN)) ?: 'localhost';

    echo "Database Port [3306]: ";
    $dbPort = trim(fgets(STDIN)) ?: '3306';

    echo "Database Name [steam_test_panel]: ";
    $dbName = trim(fgets(STDIN)) ?: 'steam_test_panel';

    echo "Database Username [root]: ";
    $dbUser = trim(fgets(STDIN)) ?: 'root';

    echo "Database Password: ";
    // Try to hide password input on Unix systems
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $dbPass = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $dbPass = trim(fgets(STDIN));
    }

    echo "\nPanel Configuration\n";
    echo "-------------------\n";

    echo "Panel Title [Steam Emulator Test Panel]: ";
    $panelTitle = trim(fgets(STDIN)) ?: 'Steam Emulator Test Panel';

    echo "\nGitHub Integration (Optional)\n";
    echo "-----------------------------\n";

    echo "GitHub Repository Owner (username): ";
    $githubOwner = trim(fgets(STDIN)) ?: '';

    echo "GitHub Repository Name: ";
    $githubRepo = trim(fgets(STDIN)) ?: '';

    echo "GitHub Personal Access Token: ";
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $githubToken = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $githubToken = trim(fgets(STDIN));
    }

    echo "\nAdmin Account Configuration\n";
    echo "---------------------------\n";

    echo "Admin Username [admin]: ";
    $adminUser = trim(fgets(STDIN)) ?: 'admin';

    echo "Admin Password: ";
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $adminPass = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $adminPass = trim(fgets(STDIN));
    }

    if (empty($adminPass)) {
        die("Error: Admin password cannot be empty.\n");
    }

    echo "Confirm Admin Password: ";
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $adminPassConfirm = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $adminPassConfirm = trim(fgets(STDIN));
    }

    if ($adminPass !== $adminPassConfirm) {
        die("Error: Passwords do not match.\n");
    }

} else {
    // Web Installation
    session_start();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $dbHost = $_POST['db_host'] ?? 'localhost';
        $dbPort = $_POST['db_port'] ?? '3306';
        $dbName = $_POST['db_name'] ?? 'steam_test_panel';
        $dbUser = $_POST['db_user'] ?? 'root';
        $dbPass = $_POST['db_pass'] ?? '';
        $panelTitle = $_POST['panel_title'] ?? 'Steam Emulator Test Panel';
        $githubOwner = $_POST['github_owner'] ?? '';
        $githubRepo = $_POST['github_repo'] ?? '';
        $githubToken = $_POST['github_token'] ?? '';
        $adminUser = $_POST['admin_user'] ?? 'admin';
        $adminPass = $_POST['admin_pass'] ?? '';
        $adminPassConfirm = $_POST['admin_pass_confirm'] ?? '';

        $error = null;
        if (empty($adminPass)) {
            $error = "Admin password cannot be empty.";
        } elseif ($adminPass !== $adminPassConfirm) {
            $error = "Passwords do not match.";
        }

        // If there's a validation error, redirect back to form
        if ($error !== null) {
            header("Location: install.php?error=" . urlencode($error));
            exit;
        }
    } else {
        // Show installation form
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Install - Steam Emulator Test Panel</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #3e4637;
                    color: #eff6ee;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .container {
                    background: #4c5844;
                    padding: 40px;
                    border-radius: 12px;
                    max-width: 500px;
                    width: 100%;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                }
                h1 {
                    color: #c4b550;
                    margin-bottom: 10px;
                    font-size: 24px;
                }
                h2 {
                    color: #a0aa95;
                    font-size: 14px;
                    font-weight: normal;
                    margin-bottom: 30px;
                }
                .section {
                    margin-bottom: 25px;
                }
                .section-title {
                    color: #c4b550;
                    font-size: 14px;
                    font-weight: 600;
                    margin-bottom: 15px;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                }
                label {
                    display: block;
                    margin-bottom: 5px;
                    color: #a0aa95;
                    font-size: 13px;
                }
                input[type="text"],
                input[type="password"],
                input[type="number"] {
                    width: 100%;
                    padding: 10px 12px;
                    border: 1px solid #5a6a50;
                    border-radius: 6px;
                    background: #3e4637;
                    color: #eff6ee;
                    font-size: 14px;
                    margin-bottom: 12px;
                }
                input:focus {
                    outline: none;
                    border-color: #c4b550;
                }
                .row {
                    display: flex;
                    gap: 15px;
                }
                .row > div {
                    flex: 1;
                }
                button {
                    width: 100%;
                    padding: 12px;
                    background: #c4b550;
                    color: #3e4637;
                    border: none;
                    border-radius: 6px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: background 0.2s;
                }
                button:hover {
                    background: #d4c560;
                }
                .error {
                    background: #c45050;
                    color: white;
                    padding: 10px 15px;
                    border-radius: 6px;
                    margin-bottom: 20px;
                    font-size: 14px;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Steam Emulator Test Panel</h1>
                <h2>Installation Wizard</h2>

                <?php if (isset($_GET['error'])): ?>
                    <div class="error"><?= htmlspecialchars($_GET['error']) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="section">
                        <div class="section-title">Database Configuration</div>

                        <div class="row">
                            <div>
                                <label>Database Host</label>
                                <input type="text" name="db_host" value="localhost" required>
                            </div>
                            <div style="max-width: 100px;">
                                <label>Port</label>
                                <input type="number" name="db_port" value="3306" required>
                            </div>
                        </div>

                        <label>Database Name</label>
                        <input type="text" name="db_name" value="steam_test_panel" required>

                        <label>Database Username</label>
                        <input type="text" name="db_user" value="root" required>

                        <label>Database Password</label>
                        <input type="password" name="db_pass" value="">
                    </div>

                    <div class="section">
                        <div class="section-title">Panel Settings</div>

                        <label>Panel Title</label>
                        <input type="text" name="panel_title" value="Steam Emulator Test Panel" required>
                    </div>

                    <div class="section">
                        <div class="section-title">GitHub Integration (Optional)</div>
                        <p style="font-size: 12px; color: #a0aa95; margin-bottom: 15px;">
                            Configure GitHub integration to track commit revisions. Leave blank to skip.
                        </p>

                        <label>GitHub Repository Owner (username)</label>
                        <input type="text" name="github_owner" value="" placeholder="e.g., octocat">

                        <label>GitHub Repository Name</label>
                        <input type="text" name="github_repo" value="" placeholder="e.g., my-emulator">

                        <label>GitHub Personal Access Token</label>
                        <input type="password" name="github_token" value="" placeholder="ghp_xxxxxxxxxxxx">
                    </div>

                    <div class="section">
                        <div class="section-title">Admin Account</div>

                        <label>Admin Username</label>
                        <input type="text" name="admin_user" value="admin" required>

                        <label>Admin Password</label>
                        <input type="password" name="admin_pass" required>

                        <label>Confirm Password</label>
                        <input type="password" name="admin_pass_confirm" required>
                    </div>

                    <button type="submit">Install</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Perform installation
try {
    // Test database connection (without selecting database first)
    $dsn = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($isCli) echo "\nConnected to MySQL server.\n";

    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    if ($isCli) echo "Database '$dbName' ready.\n";

    // Create tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            api_key VARCHAR(64) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_api_key (api_key)
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tester VARCHAR(100) NOT NULL,
            commit_hash VARCHAR(50) DEFAULT NULL,
            test_type VARCHAR(20) NOT NULL,
            client_version VARCHAR(255) NOT NULL,
            steamui_version VARCHAR(100) DEFAULT NULL COMMENT 'SteamUI package version',
            steam_pkg_version VARCHAR(100) DEFAULT NULL COMMENT 'Steam package version',
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            raw_json LONGTEXT,
            test_duration INT DEFAULT NULL COMMENT 'Test duration in seconds',
            revision_count INT NOT NULL DEFAULT 0,
            restored_from INT DEFAULT NULL,
            restored_at DATETIME DEFAULT NULL,
            last_modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last time report or tests were modified',
            INDEX idx_tester (tester),
            INDEX idx_client_version (client_version),
            INDEX idx_test_type (test_type),
            INDEX idx_submitted_at (submitted_at),
            INDEX idx_tester_version_type (tester, client_version, test_type),
            INDEX idx_steamui_version (steamui_version),
            INDEX idx_steam_pkg_version (steam_pkg_version),
            INDEX idx_last_modified (last_modified)
        ) ENGINE=InnoDB
    ");

    // Add test_duration column if it doesn't exist (for upgrades from older versions)
    try {
        $pdo->exec("ALTER TABLE reports ADD COLUMN test_duration INT DEFAULT NULL COMMENT 'Test duration in seconds' AFTER raw_json");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }

    // Add steamui_version and steam_pkg_version columns if they don't exist (for upgrades)
    try {
        $pdo->exec("ALTER TABLE reports ADD COLUMN steamui_version VARCHAR(100) DEFAULT NULL COMMENT 'SteamUI package version' AFTER client_version");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE reports ADD COLUMN steam_pkg_version VARCHAR(100) DEFAULT NULL COMMENT 'Steam package version' AFTER steamui_version");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE reports ADD INDEX idx_steamui_version (steamui_version)");
    } catch (PDOException $e) {
        // Index might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE reports ADD INDEX idx_steam_pkg_version (steam_pkg_version)");
    } catch (PDOException $e) {
        // Index might already exist, ignore
    }

    // Add last_modified column if it doesn't exist (for upgrades)
    try {
        $pdo->exec("ALTER TABLE reports ADD COLUMN last_modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last time report or tests were modified' AFTER restored_at");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE reports ADD INDEX idx_last_modified (last_modified)");
    } catch (PDOException $e) {
        // Index might already exist, ignore
    }

    // Add report_logs table if it doesn't exist (for upgrades from older versions)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS report_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_id INT NOT NULL,
                filename VARCHAR(255) NOT NULL,
                log_datetime DATETIME NOT NULL COMMENT 'Original log file datetime',
                size_original INT NOT NULL COMMENT 'Original file size in bytes',
                size_compressed INT NOT NULL COMMENT 'Compressed size in bytes',
                log_data LONGBLOB NOT NULL COMMENT 'Gzip compressed log content',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_report_id (report_id),
                INDEX idx_log_datetime (log_datetime),
                FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    } catch (PDOException $e) {
        // Table might already exist or other issue, ignore
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS test_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            test_key VARCHAR(10) NOT NULL,
            status VARCHAR(20) NOT NULL,
            notes TEXT,
            INDEX idx_report_id (report_id),
            INDEX idx_test_key (test_key),
            INDEX idx_status (status),
            FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_revisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            revision_number INT NOT NULL DEFAULT 0 COMMENT 'Sequential revision number starting at 0',
            tester VARCHAR(100) NOT NULL,
            commit_hash VARCHAR(50) DEFAULT NULL,
            test_type VARCHAR(20) NOT NULL,
            client_version VARCHAR(255) NOT NULL,
            steamui_version VARCHAR(100) DEFAULT NULL,
            steam_pkg_version VARCHAR(100) DEFAULT NULL,
            submitted_at DATETIME NOT NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            raw_json LONGTEXT,
            test_results JSON COMMENT 'Full test results for this revision',
            changes_diff JSON COMMENT 'JSON diff of what changed from previous revision',
            INDEX idx_report_id (report_id),
            INDEX idx_archived_at (archived_at),
            INDEX idx_revision_number (report_id, revision_number),
            FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    // Add revision_number column to report_revisions if it doesn't exist (for upgrades)
    try {
        $pdo->exec("ALTER TABLE report_revisions ADD COLUMN revision_number INT NOT NULL DEFAULT 0 COMMENT 'Sequential revision number starting at 0' AFTER report_id");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE report_revisions ADD COLUMN steamui_version VARCHAR(100) DEFAULT NULL AFTER client_version");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE report_revisions ADD COLUMN steam_pkg_version VARCHAR(100) DEFAULT NULL AFTER steamui_version");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE report_revisions ADD COLUMN changes_diff JSON COMMENT 'JSON diff of what changed from previous revision' AFTER test_results");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE report_revisions ADD INDEX idx_revision_number (report_id, revision_number)");
    } catch (PDOException $e) {
        // Index might already exist, ignore
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS retest_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT DEFAULT NULL COMMENT 'Associated report ID',
            report_revision INT DEFAULT NULL COMMENT 'Report revision when retest was requested',
            test_key VARCHAR(10) NOT NULL,
            client_version VARCHAR(255) NOT NULL,
            created_by VARCHAR(100) NOT NULL,
            reason TEXT,
            notes TEXT COMMENT 'Admin notes for tester explaining what needs retesting',
            status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_client_version (client_version),
            INDEX idx_created_at (created_at),
            INDEX idx_report_id (report_id)
        ) ENGINE=InnoDB
    ");

    // Add report_id, report_revision, and notes columns to retest_requests if they don't exist (for upgrades)
    try {
        $pdo->exec("ALTER TABLE retest_requests ADD COLUMN report_id INT DEFAULT NULL COMMENT 'Associated report ID' AFTER id");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE retest_requests ADD COLUMN report_revision INT DEFAULT NULL COMMENT 'Report revision when retest was requested' AFTER report_id");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE retest_requests ADD COLUMN notes TEXT COMMENT 'Admin notes for tester explaining what needs retesting' AFTER reason");
    } catch (PDOException $e) {
        // Column might already exist, ignore
    }
    try {
        $pdo->exec("ALTER TABLE retest_requests ADD INDEX idx_report_id (report_id)");
    } catch (PDOException $e) {
        // Index might already exist, ignore
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fixed_tests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_key VARCHAR(10) NOT NULL,
            client_version VARCHAR(255) NOT NULL,
            fixed_by VARCHAR(100) NOT NULL,
            commit_hash VARCHAR(50) DEFAULT NULL,
            notes TEXT,
            status ENUM('pending_retest', 'verified') NOT NULL DEFAULT 'pending_retest',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at DATETIME DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_client_version (client_version),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB
    ");

    // Test categories table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS test_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sort_order (sort_order)
        ) ENGINE=InnoDB
    ");

    // Test types table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS test_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_key VARCHAR(10) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            category_id INT DEFAULT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_test_key (test_key),
            INDEX idx_category_id (category_id),
            INDEX idx_is_enabled (is_enabled),
            INDEX idx_sort_order (sort_order),
            FOREIGN KEY (category_id) REFERENCES test_categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB
    ");

    // User notifications table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL COMMENT 'Target user for notification',
            type ENUM('retest', 'fixed', 'info') NOT NULL DEFAULT 'retest',
            report_id INT DEFAULT NULL COMMENT 'Associated report ID',
            test_key VARCHAR(10) DEFAULT NULL,
            client_version VARCHAR(255) DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            notes TEXT COMMENT 'Admin notes associated with notification',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME DEFAULT NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_is_read (is_read),
            INDEX idx_created_at (created_at),
            INDEX idx_user_unread (user_id, is_read),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    if ($isCli) echo "Tables created successfully.\n";

    // Populate initial test categories and test types from TEST_KEYS
    require_once __DIR__ . '/includes/test_keys.php';

    // Get unique categories
    $categories = [];
    $sortOrder = 0;
    foreach (TEST_KEYS as $key => $test) {
        $cat = $test['category'];
        if (!isset($categories[$cat])) {
            $categories[$cat] = ++$sortOrder;
        }
    }

    // Insert categories
    $categoryIds = [];
    foreach ($categories as $catName => $order) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO test_categories (name, sort_order) VALUES (?, ?)");
        $stmt->execute([$catName, $order]);

        // Get the ID
        $stmt = $pdo->prepare("SELECT id FROM test_categories WHERE name = ?");
        $stmt->execute([$catName]);
        $row = $stmt->fetch();
        if ($row) {
            $categoryIds[$catName] = $row['id'];
        }
    }

    if ($isCli) echo "Test categories populated.\n";

    // Insert test types
    $testSortOrder = 0;
    foreach (TEST_KEYS as $key => $test) {
        $catId = $categoryIds[$test['category']] ?? null;
        $stmt = $pdo->prepare("INSERT IGNORE INTO test_types (test_key, name, description, category_id, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$key, $test['name'], $test['expected'], $catId, ++$testSortOrder]);
    }

    if ($isCli) echo "Test types populated.\n";

    // Create admin user
    $apiKey = 'sk_' . bin2hex(random_bytes(24));
    $passwordHash = password_hash($adminPass, PASSWORD_DEFAULT);

    // Check if admin user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$adminUser]);

    if ($stmt->fetch()) {
        // Update existing admin
        $stmt = $pdo->prepare("UPDATE users SET password = ?, api_key = ?, role = 'admin' WHERE username = ?");
        $stmt->execute([$passwordHash, $apiKey, $adminUser]);
        if ($isCli) echo "Admin user '$adminUser' updated.\n";
    } else {
        // Create new admin
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role, api_key, created_at) VALUES (?, ?, 'admin', ?, NOW())");
        $stmt->execute([$adminUser, $passwordHash, $apiKey]);
        if ($isCli) echo "Admin user '$adminUser' created.\n";
    }

    // Update config.php - replace only the database config values
    if (!file_exists($configFile)) {
        throw new Exception("config.php not found at: $configFile");
    }

    $configContent = file_get_contents($configFile);
    if ($configContent === false) {
        throw new Exception("Failed to read config.php. Check file permissions on: $configFile");
    }

    // Escape values for safe insertion into PHP strings
    $escapedDbHost = addslashes($dbHost);
    $escapedDbPort = addslashes($dbPort);
    $escapedDbName = addslashes($dbName);
    $escapedDbUser = addslashes($dbUser);
    $escapedDbPass = addslashes($dbPass);
    $escapedPanelTitle = addslashes($panelTitle);

    // Replace database configuration values using regex
    $configContent = preg_replace(
        "/define\('DB_HOST',\s*'[^']*'\);/",
        "define('DB_HOST', '{$escapedDbHost}');",
        $configContent
    );
    $configContent = preg_replace(
        "/define\('DB_PORT',\s*'[^']*'\);/",
        "define('DB_PORT', '{$escapedDbPort}');",
        $configContent
    );
    $configContent = preg_replace(
        "/define\('DB_NAME',\s*'[^']*'\);/",
        "define('DB_NAME', '{$escapedDbName}');",
        $configContent
    );
    $configContent = preg_replace(
        "/define\('DB_USER',\s*'[^']*'\);/",
        "define('DB_USER', '{$escapedDbUser}');",
        $configContent
    );
    $configContent = preg_replace(
        "/define\('DB_PASS',\s*'[^']*'\);/",
        "define('DB_PASS', '{$escapedDbPass}');",
        $configContent
    );
    $configContent = preg_replace(
        "/define\('PANEL_NAME',\s*'[^']*'\);/",
        "define('PANEL_NAME', '{$escapedPanelTitle}');",
        $configContent
    );

    // Update GitHub configuration
    $escapedGithubOwner = addslashes($githubOwner);
    $escapedGithubRepo = addslashes($githubRepo);
    $escapedGithubToken = addslashes($githubToken);

    $configContent = preg_replace(
        "/define\('GITHUB_OWNER',\s*'[^']*'\);/",
        "define('GITHUB_OWNER', '{$escapedGithubOwner}');",
        $configContent
    );
    $configContent = preg_replace(
        "/define\('GITHUB_REPO',\s*'[^']*'\);/",
        "define('GITHUB_REPO', '{$escapedGithubRepo}');",
        $configContent
    );
    $configContent = preg_replace(
        "/define\('GITHUB_TOKEN',\s*'[^']*'\);/",
        "define('GITHUB_TOKEN', '{$escapedGithubToken}');",
        $configContent
    );

    $writeResult = file_put_contents($configFile, $configContent);
    if ($writeResult === false) {
        throw new Exception("Failed to write config.php. Check file permissions on: $configFile");
    }
    if ($isCli) echo "Configuration updated in config.php\n";

    // Create data directory and lock file
    if (!is_dir(__DIR__ . '/data')) {
        mkdir(__DIR__ . '/data', 0755, true);
    }

    // Create cache directory
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // Build GitHub revision cache during installation (if configured)
    $githubCacheResult = null;
    if (!empty($githubOwner) && !empty($githubRepo) && !empty($githubToken)) {
        if ($isCli) {
            echo "\nBuilding GitHub revision cache...\n";
        }

        try {
            require_once __DIR__ . '/api/githubrevisiongrabber.php';

            $ghCache = new GitHubRepoHistoryCache($githubToken, $cacheDir);
            $commitCount = $ghCache->buildInitialCache($githubOwner, $githubRepo, [
                'branch' => 'main',
                'max_commits' => 500,
                'full_details_limit' => 50
            ]);
            $githubCacheResult = "Cached $commitCount commits from GitHub repository.";

            if ($isCli) {
                echo "  $githubCacheResult\n";
            }
        } catch (Exception $e) {
            $githubCacheResult = "Warning: Could not build GitHub cache: " . $e->getMessage();
            if ($isCli) {
                echo "  $githubCacheResult\n";
            }
        }
    }

    file_put_contents($lockFile, date('Y-m-d H:i:s'));

    if ($isCli) {
        echo "\n===========================================\n";
        echo " Installation Complete!\n";
        echo "===========================================\n\n";
        echo "Admin Username: $adminUser\n";
        echo "Admin API Key:  $apiKey\n";
        if ($githubCacheResult) {
            echo "\nGitHub: $githubCacheResult\n";
        }
        echo "\nYou can now access the panel at your web server.\n";
        echo "Don't forget to save your API key!\n\n";
    } else {
        // Web success page
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Installation Complete - Steam Emulator Test Panel</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #3e4637;
                    color: #eff6ee;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .container {
                    background: #4c5844;
                    padding: 40px;
                    border-radius: 12px;
                    max-width: 500px;
                    width: 100%;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                    text-align: center;
                }
                h1 {
                    color: #7ea64b;
                    margin-bottom: 10px;
                    font-size: 24px;
                }
                h2 {
                    color: #a0aa95;
                    font-size: 14px;
                    font-weight: normal;
                    margin-bottom: 30px;
                }
                .info {
                    background: #3e4637;
                    padding: 20px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    text-align: left;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 8px 0;
                    border-bottom: 1px solid #5a6a50;
                }
                .info-row:last-child {
                    border-bottom: none;
                }
                .info-label {
                    color: #a0aa95;
                }
                .info-value {
                    color: #c4b550;
                    font-family: monospace;
                    word-break: break-all;
                }
                .warning {
                    background: #c4b550;
                    color: #3e4637;
                    padding: 12px;
                    border-radius: 6px;
                    margin-bottom: 20px;
                    font-size: 13px;
                }
                a.btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #c4b550;
                    color: #3e4637;
                    text-decoration: none;
                    border-radius: 6px;
                    font-weight: 600;
                }
                a.btn:hover {
                    background: #d4c560;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Installation Complete!</h1>
                <h2>Steam Emulator Test Panel is ready to use</h2>

                <div class="info">
                    <div class="info-row">
                        <span class="info-label">Admin Username:</span>
                        <span class="info-value"><?= htmlspecialchars($adminUser) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">API Key:</span>
                        <span class="info-value"><?= htmlspecialchars($apiKey) ?></span>
                    </div>
                    <?php if ($githubCacheResult): ?>
                    <div class="info-row">
                        <span class="info-label">GitHub:</span>
                        <span class="info-value" style="color: <?= strpos($githubCacheResult, 'Warning') === 0 ? '#e67e22' : '#7ea64b' ?>;"><?= htmlspecialchars($githubCacheResult) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="warning">
                    Save your API key now! You'll need it to submit test reports.
                </div>

                <a href="index.php" class="btn">Go to Login</a>
            </div>
        </body>
        </html>
        <?php
    }

} catch (PDOException $e) {
    $errorMsg = "Database error: " . $e->getMessage();

    if ($isCli) {
        die("\nError: $errorMsg\n");
    } else {
        header("Location: install.php?error=" . urlencode($errorMsg));
        exit;
    }
} catch (Exception $e) {
    $errorMsg = $e->getMessage();

    if ($isCli) {
        die("\nError: $errorMsg\n");
    } else {
        header("Location: install.php?error=" . urlencode($errorMsg));
        exit;
    }
}
