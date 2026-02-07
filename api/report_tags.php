<?php
/**
 * API endpoint for managing report tags
 * Handles adding/removing tags from reports
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Start session for authentication (config.php may have already started it)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Require login
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Require admin for tag modifications
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$db = Database::getInstance();
$user = getCurrentUser();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get':
            // Get tags for a specific report
            $reportId = intval($_GET['report_id'] ?? 0);
            if (!$reportId) {
                throw new Exception('Report ID required');
            }

            $tags = $db->getReportTags($reportId);
            $allTags = $db->getAllTags();

            echo json_encode([
                'success' => true,
                'report_tags' => $tags,
                'all_tags' => $allTags
            ]);
            break;

        case 'add':
            // Add a tag to a report
            if ($method !== 'POST') {
                throw new Exception('POST method required');
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $reportId = intval($input['report_id'] ?? 0);
            $tagId = intval($input['tag_id'] ?? 0);

            if (!$reportId || !$tagId) {
                throw new Exception('Report ID and Tag ID required');
            }

            // Verify report exists
            $report = $db->getReport($reportId);
            if (!$report) {
                throw new Exception('Report not found');
            }

            // Verify tag exists
            $tag = $db->getTag($tagId);
            if (!$tag) {
                throw new Exception('Tag not found');
            }

            $result = $db->addTagToReport($reportId, $tagId, $user['id']);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Tag added successfully',
                    'tag' => $tag
                ]);
            } else {
                throw new Exception('Tag already assigned or failed to add');
            }
            break;

        case 'remove':
            // Remove a tag from a report
            if ($method !== 'POST' && $method !== 'DELETE') {
                throw new Exception('POST or DELETE method required');
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $reportId = intval($input['report_id'] ?? 0);
            $tagId = intval($input['tag_id'] ?? 0);

            if (!$reportId || !$tagId) {
                throw new Exception('Report ID and Tag ID required');
            }

            $result = $db->removeTagFromReport($reportId, $tagId);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Tag removed successfully'
                ]);
            } else {
                throw new Exception('Failed to remove tag');
            }
            break;

        case 'list_all':
            // Get all available tags
            $tags = $db->getAllTags();
            echo json_encode([
                'success' => true,
                'tags' => $tags
            ]);
            break;

        default:
            throw new Exception('Invalid action. Use: get, add, remove, list_all');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
