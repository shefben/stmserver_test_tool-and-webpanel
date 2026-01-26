<?php
/**
 * API endpoint for test types and categories
 * Returns all enabled test types grouped by category for the test tool
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/test_keys.php';

// Authenticate via session (web UI) or API key (Python tool)
if (!isLoggedIn()) {
    // No session - try API key authentication
    requireApiAuth();
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// GET - Get all test types grouped by category
if ($method === 'GET') {
    $enabledOnly = !isset($_GET['all']); // By default only enabled tests
    $clientVersion = $_GET['client_version'] ?? null; // Optional: filter by version-specific template

    // Get version-specific template if client_version provided
    $templateTestKeys = null;
    $templateInfo = null;
    if ($clientVersion) {
        $template = $db->getTemplateForVersionString($clientVersion);
        if ($template && !empty($template['test_keys'])) {
            $templateTestKeys = $template['test_keys'];
            $templateInfo = [
                'id' => $template['id'],
                'name' => $template['name'],
                'is_default' => (bool)$template['is_default']
            ];
        }
    }

    // Try to get tests from database first
    $tests = [];
    $categories = [];

    try {
        if ($db->hasTestCategoriesTable()) {
            // Get from database
            $dbTests = $db->getTestTypes($enabledOnly);
            $dbCategories = $db->getTestCategories();

            // Build categories lookup
            $catLookup = [];
            foreach ($dbCategories as $cat) {
                $catLookup[$cat['id']] = [
                    'id' => $cat['id'],
                    'name' => $cat['name'],
                    'sort_order' => $cat['sort_order']
                ];
                $categories[] = [
                    'id' => $cat['id'],
                    'name' => $cat['name'],
                    'sort_order' => $cat['sort_order']
                ];
            }

            // Build tests array
            foreach ($dbTests as $test) {
                $tests[] = [
                    'test_key' => $test['test_key'],
                    'name' => $test['name'],
                    'description' => $test['description'] ?? '',
                    'category_id' => $test['category_id'],
                    'category_name' => $test['category_name'] ?? 'Uncategorized',
                    'sort_order' => $test['sort_order'],
                    'is_enabled' => (bool)$test['is_enabled']
                ];
            }

            // Sort tests by category sort_order, then by test sort_order
            usort($tests, function($a, $b) use ($catLookup) {
                $catSortA = isset($catLookup[$a['category_id']]) ? $catLookup[$a['category_id']]['sort_order'] : 999;
                $catSortB = isset($catLookup[$b['category_id']]) ? $catLookup[$b['category_id']]['sort_order'] : 999;
                if ($catSortA !== $catSortB) {
                    return $catSortA - $catSortB;
                }
                return $a['sort_order'] - $b['sort_order'];
            });
        }
    } catch (Exception $e) {
        // Fall through to static TEST_KEYS
    }

    // Fallback to TEST_KEYS constant if no database tests
    if (empty($tests)) {
        // Build from TEST_KEYS constant
        $catNames = [];
        foreach (TEST_KEYS as $key => $test) {
            $catName = $test['category'];
            if (!in_array($catName, $catNames)) {
                $catNames[] = $catName;
            }

            $tests[] = [
                'test_key' => $key,
                'name' => $test['name'],
                'description' => $test['expected'] ?? '',
                'category_id' => null,
                'category_name' => $catName,
                'sort_order' => 0,
                'is_enabled' => true
            ];
        }

        // Build categories from unique names
        foreach ($catNames as $idx => $name) {
            $categories[] = [
                'id' => $idx + 1,
                'name' => $name,
                'sort_order' => $idx
            ];
        }
    }

    // Filter tests by template if version-specific template is active
    if ($templateTestKeys !== null) {
        $tests = array_filter($tests, function($test) use ($templateTestKeys) {
            return in_array($test['test_key'], $templateTestKeys);
        });
        $tests = array_values($tests); // Re-index array
    }

    // Group tests by category for convenience
    $grouped = [];
    foreach ($tests as $test) {
        $catName = $test['category_name'];
        if (!isset($grouped[$catName])) {
            $grouped[$catName] = [];
        }
        $grouped[$catName][] = $test;
    }

    $response = [
        'success' => true,
        'categories' => $categories,
        'tests' => $tests,
        'grouped' => $grouped,
        'total_tests' => count($tests),
        'total_categories' => count($categories),
        'filtered' => ($templateTestKeys !== null && $templateInfo !== null && !$templateInfo['is_default'])
    ];

    // Include template info if a version-specific template was applied
    if ($templateInfo !== null) {
        $response['template'] = $templateInfo;
        $response['filtered'] = !$templateInfo['is_default']; // Only filtered if not default template
    }

    echo json_encode($response);
    exit;
}

// Unsupported method
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
