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

-- Insert default admin user (password: steamtest2024)
INSERT INTO users (username, password, role, api_key, created_at)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    'sk_test_de399dc5ef7e6340e721e355a72a0484',
    NOW()
) ON DUPLICATE KEY UPDATE username = username;
