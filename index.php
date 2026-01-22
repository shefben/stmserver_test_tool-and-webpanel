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
    'edit_report',
    'profile',
    'notifications',
    'my_reports'
];

// Validate page
if (!in_array($page, $validPages)) {
    $page = 'dashboard';
}

// Include the appropriate page
$pageFile = __DIR__ . '/pages/' . $page . '.php';

if (file_exists($pageFile)) {
    require $pageFile;
} else {
    require __DIR__ . '/pages/dashboard.php';
}
