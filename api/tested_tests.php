<?php
/**
 * API endpoint for getting tested tests for specific versions
 * Used by admin_templates.php to dynamically update tested indicators
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Require authentication (session-based for internal use)
session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();

// GET - Get tested tests for specific version IDs
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $versionIds = $_GET['version_ids'] ?? '';

    if (empty($versionIds)) {
        echo json_encode(['success' => true, 'tested_keys' => []]);
        exit;
    }

    $versionIdArray = array_filter(array_map('intval', explode(',', $versionIds)));

    if (empty($versionIdArray)) {
        echo json_encode(['success' => true, 'tested_keys' => []]);
        exit;
    }

    // Get version strings for these IDs
    $versions = $db->getClientVersions(false);
    $versionStrings = [];
    foreach ($versions as $v) {
        if (in_array($v['id'], $versionIdArray)) {
            $versionStrings[] = $v['version_id'];
        }
    }

    if (empty($versionStrings)) {
        echo json_encode(['success' => true, 'tested_keys' => []]);
        exit;
    }

    // Get tested test keys
    $testedKeys = $db->getTestedTestKeysForVersions($versionStrings);

    echo json_encode([
        'success' => true,
        'tested_keys' => $testedKeys,
        'version_count' => count($versionStrings)
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
