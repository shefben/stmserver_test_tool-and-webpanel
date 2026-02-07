<?php
/**
 * Main router / entry point
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Get requested page
$page = $_GET['page'] ?? 'dashboard';

// Whitelist of valid pages
$validPages = [
    'dashboard',
    'reports',
    'report_detail',
    'report_revisions',
    'repo_revisions',
    'git_revisions',
    'git_revision_detail',
    'submit',
    'create_report',
    'compare_versions',
    'versions',
    'tests',
    'results',
    'admin',
    'admin_users',
    'admin_reports',
    'admin_retests',
    'admin_tests',
    'admin_templates',
    'admin_categories',
    'admin_category_edit',
    'admin_test_types',
    'admin_test_type_edit',
    'admin_tags',
    'admin_versions',
    'admin_version_notifications',
    'admin_invites',
    'admin_settings',
    'admin_backup',
    'edit_report',
    'profile',
    'notifications',
    'my_reports'
];

// Validate page
if (!in_array($page, $validPages)) {
    $page = 'dashboard';
}

// Handle database export download (must run before any HTML output)
// Uses POST with selection JSON from the export wizard form
if ($page === 'admin_backup' && isset($_GET['download']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/pages/admin_backup.php';
    handle_panel_export();
    exit;
}

// Include the appropriate page
$pageFile = __DIR__ . '/pages/' . $page . '.php';

if (file_exists($pageFile)) {
    require $pageFile;
} else {
    require __DIR__ . '/pages/dashboard.php';
}
