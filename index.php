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
    'submit',
    'create_report',
    'versions',
    'tests',
    'results',
    'admin',
    'admin_users',
    'admin_reports',
    'admin_retests',
    'admin_tests',
    'admin_categories',
    'admin_category_edit',
    'admin_test_types',
    'admin_test_type_edit',
    'edit_report',
    'profile',
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
