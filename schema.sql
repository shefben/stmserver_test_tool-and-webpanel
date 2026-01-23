-- Steam Emulator Test Panel - MySQL Schema
-- Run this to create the database and tables

CREATE DATABASE IF NOT EXISTS steam_test_panel
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE steam_test_panel;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    api_key VARCHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_api_key (api_key)
) ENGINE=InnoDB;

-- Reports table
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
) ENGINE=InnoDB;

-- Migration: Add test_duration column if not exists (for existing installations)
-- ALTER TABLE reports ADD COLUMN IF NOT EXISTS test_duration INT DEFAULT NULL COMMENT 'Test duration in seconds' AFTER raw_json;

-- Migration: Add steamui_version and steam_pkg_version columns if not exists (for existing installations)
-- ALTER TABLE reports ADD COLUMN IF NOT EXISTS steamui_version VARCHAR(100) DEFAULT NULL COMMENT 'SteamUI package version' AFTER client_version;
-- ALTER TABLE reports ADD COLUMN IF NOT EXISTS steam_pkg_version VARCHAR(100) DEFAULT NULL COMMENT 'Steam package version' AFTER steamui_version;
-- ALTER TABLE reports ADD INDEX idx_steamui_version (steamui_version);
-- ALTER TABLE reports ADD INDEX idx_steam_pkg_version (steam_pkg_version);

-- Test results table
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
) ENGINE=InnoDB;

-- Report revisions table (historical snapshots with diffs for efficiency)
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
) ENGINE=InnoDB;

-- Retest requests table
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
) ENGINE=InnoDB;

-- Fixed tests table
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
) ENGINE=InnoDB;

-- Report log files table (compressed logs attached to reports)
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
) ENGINE=InnoDB;

-- User notifications table
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
) ENGINE=InnoDB;

-- Report comments table (blog-style threaded comments)
CREATE TABLE IF NOT EXISTS report_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    user_id INT NOT NULL COMMENT 'Author of the comment',
    parent_comment_id INT DEFAULT NULL COMMENT 'For replies/quotes - references parent comment',
    content TEXT NOT NULL,
    quoted_text TEXT DEFAULT NULL COMMENT 'Quoted text from parent comment',
    is_edited TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    INDEX idx_report_id (report_id),
    INDEX idx_user_id (user_id),
    INDEX idx_parent_comment (parent_comment_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES report_comments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Test templates/presets table
CREATE TABLE IF NOT EXISTS test_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    test_keys JSON NOT NULL COMMENT 'Array of test keys included in this template',
    created_by INT NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether this is the default template',
    is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'System templates cannot be deleted',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_is_default (is_default),
    INDEX idx_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Report tags/labels table
CREATE TABLE IF NOT EXISTS report_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    color VARCHAR(7) NOT NULL DEFAULT '#808080' COMMENT 'Hex color code for display',
    description VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB;

-- Report-tag associations (many-to-many)
CREATE TABLE IF NOT EXISTS report_tag_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    tag_id INT NOT NULL,
    assigned_by INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_report_tag (report_id, tag_id),
    INDEX idx_report_id (report_id),
    INDEX idx_tag_id (tag_id),
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES report_tags(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Insert default admin user (password: steamtest2024)
INSERT INTO users (username, password, role, api_key, created_at)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    'sk_test_de399dc5ef7e6340e721e355a72a0484',
    NOW()
) ON DUPLICATE KEY UPDATE username = username;

-- Client versions table (managed list of supported client versions)
CREATE TABLE IF NOT EXISTS client_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'Full version string (e.g., secondblob.bin.2004-01-15)',
    display_name VARCHAR(255) DEFAULT NULL COMMENT 'Optional friendly display name',
    steam_date DATE DEFAULT NULL COMMENT 'Steam version date',
    steam_time VARCHAR(20) DEFAULT NULL COMMENT 'Steam version time',
    packages JSON COMMENT 'Array of package names (e.g., ["Steam_0", "SteamUI_06001000"])',
    skip_tests JSON COMMENT 'Array of test keys to skip for this version',
    sort_order INT NOT NULL DEFAULT 0 COMMENT 'Sort order (lower = newer)',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether this version is active for testing',
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_version_id (version_id),
    INDEX idx_steam_date (steam_date),
    INDEX idx_sort_order (sort_order),
    INDEX idx_is_enabled (is_enabled),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Version notifications table (quick notes/known issues per version)
CREATE TABLE IF NOT EXISTS version_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_version_id INT NOT NULL COMMENT 'References client_versions.id',
    name VARCHAR(255) NOT NULL COMMENT 'Unique name/title for this notification',
    message TEXT NOT NULL COMMENT 'Notification content (supports HTML and BBCode)',
    commit_hash VARCHAR(50) DEFAULT NULL COMMENT 'Optional: only show on reports with this commit hash',
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_version (client_version_id),
    INDEX idx_commit_hash (commit_hash),
    INDEX idx_created_at (created_at),
    UNIQUE KEY unique_version_name (client_version_id, name),
    FOREIGN KEY (client_version_id) REFERENCES client_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Invite codes table (for user registration)
CREATE TABLE IF NOT EXISTS invite_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    created_by INT NOT NULL COMMENT 'Admin who created this invite',
    used_by INT DEFAULT NULL COMMENT 'User who used this invite',
    expires_at DATETIME NOT NULL COMMENT 'Expiration time (3 days from creation)',
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_created_by (created_by),
    INDEX idx_expires_at (expires_at),
    INDEX idx_used_by (used_by),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Test template to client version assignments (many-to-many)
-- When a template is assigned to specific versions, it overrides the default template for those versions
CREATE TABLE IF NOT EXISTS test_template_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    client_version_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_template_version (template_id, client_version_id),
    INDEX idx_template_id (template_id),
    INDEX idx_client_version_id (client_version_id),
    FOREIGN KEY (template_id) REFERENCES test_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (client_version_id) REFERENCES client_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Insert default report tags
INSERT INTO report_tags (name, color, description) VALUES
    ('verified', '#27ae60', 'Report has been verified by admin'),
    ('needs-review', '#f39c12', 'Report needs admin review'),
    ('regression', '#e74c3c', 'Report shows regression from previous version'),
    ('incomplete', '#95a5a6', 'Report is incomplete or missing tests'),
    ('milestone', '#9b59b6', 'Important milestone release'),
    ('bugfix', '#3498db', 'Report for a bugfix build')
ON DUPLICATE KEY UPDATE name = name;

-- Site settings table (key-value store for configuration)
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT,
    setting_type ENUM('string', 'int', 'bool', 'json') NOT NULL DEFAULT 'string',
    description VARCHAR(255) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insert default settings
INSERT INTO site_settings (setting_key, setting_value, setting_type, description) VALUES
    ('site_title', 'Steam Emulator Test Panel', 'string', 'Site title displayed in header and browser tab'),
    ('site_private', '0', 'bool', 'Require login for all pages (guests redirected to login)'),
    ('smtp_enabled', '0', 'bool', 'Enable SMTP email sending'),
    ('smtp_host', '', 'string', 'SMTP server hostname'),
    ('smtp_port', '587', 'int', 'SMTP server port'),
    ('smtp_username', '', 'string', 'SMTP authentication username'),
    ('smtp_password', '', 'string', 'SMTP authentication password'),
    ('smtp_encryption', 'tls', 'string', 'SMTP encryption (tls, ssl, or none)'),
    ('smtp_from_email', '', 'string', 'From email address for outgoing emails'),
    ('smtp_from_name', '', 'string', 'From name for outgoing emails')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
