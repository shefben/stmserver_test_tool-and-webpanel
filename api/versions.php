<?php
/**
 * API endpoint for client versions and version notifications
 * Returns available client versions and their notifications for the test tool
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Authenticate via API key
if (!requireApiAuth()) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key. Include X-API-Key header.']);
    exit;
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// GET - Get all client versions (optionally with notifications)
if ($method === 'GET') {
    $enabledOnly = !isset($_GET['all']); // By default only enabled versions
    $includeNotifications = isset($_GET['notifications']) || isset($_GET['include_notifications']);

    try {
        $versions = $db->getClientVersions($enabledOnly);

        // Transform for API output
        $output = [];
        foreach ($versions as $v) {
            $item = [
                'id' => $v['version_id'],
                'packages' => $v['packages'] ?: [],
                'steam_date' => $v['steam_date'],
                'steam_time' => $v['steam_time'],
                'skip_tests' => [], // Deprecated: use templates instead (api/tests.php?client_version=X)
                'display_name' => $v['display_name'],
                'sort_order' => $v['sort_order'],
                'is_enabled' => (bool)$v['is_enabled']
            ];

            // Include notifications if requested
            if ($includeNotifications) {
                $notifications = $db->getNotificationsForVersion($v['id']);
                $item['notifications'] = array_map(function($n) {
                    return [
                        'id' => $n['id'],
                        'name' => $n['name'],
                        'message' => $n['message'],
                        'commit_hash' => $n['commit_hash'],
                        'created_at' => $n['created_at']
                    ];
                }, $notifications);
                $item['notification_count'] = count($notifications);
            }

            $output[] = $item;
        }

        echo json_encode([
            'success' => true,
            'versions' => $output,
            'total' => count($output)
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch versions: ' . $e->getMessage()]);
        exit;
    }
}

// POST - Get notifications for a specific version (used when starting tests)
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        // Try form data
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    // Get notifications for a version
    if ($action === 'get_notifications') {
        $versionId = $input['version_id'] ?? '';
        $commitHash = $input['commit_hash'] ?? null;

        if (!$versionId) {
            http_response_code(400);
            echo json_encode(['error' => 'version_id is required']);
            exit;
        }

        try {
            $notifications = $db->getNotificationsForVersionString($versionId, $commitHash);

            // Format for display (oldest first, as per requirement)
            $formatted = array_map(function($n) {
                return [
                    'id' => $n['id'],
                    'name' => $n['name'],
                    'message' => $n['message'],
                    'commit_hash' => $n['commit_hash'],
                    'created_at' => $n['created_at'],
                    'created_by' => $n['created_by_name']
                ];
            }, $notifications);

            echo json_encode([
                'success' => true,
                'version_id' => $versionId,
                'notifications' => $formatted,
                'count' => count($formatted)
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch notifications: ' . $e->getMessage()]);
            exit;
        }
    }

    // Get notifications for multiple versions at once (batch request)
    if ($action === 'get_notifications_batch') {
        $versionIds = $input['version_ids'] ?? [];
        $commitHash = $input['commit_hash'] ?? null;

        if (!is_array($versionIds) || empty($versionIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'version_ids array is required']);
            exit;
        }

        try {
            $result = [];
            foreach ($versionIds as $versionId) {
                $notifications = $db->getNotificationsForVersionString($versionId, $commitHash);
                if (!empty($notifications)) {
                    $result[$versionId] = array_map(function($n) {
                        return [
                            'id' => $n['id'],
                            'name' => $n['name'],
                            'message' => $n['message'],
                            'commit_hash' => $n['commit_hash'],
                            'created_at' => $n['created_at']
                        ];
                    }, $notifications);
                }
            }

            echo json_encode([
                'success' => true,
                'notifications_by_version' => $result,
                'versions_with_notifications' => array_keys($result)
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch notifications: ' . $e->getMessage()]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action. Valid actions: get_notifications, get_notifications_batch']);
    exit;
}

// Unsupported method
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
