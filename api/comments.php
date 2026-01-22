<?php
/**
 * Report Comments API
 * Handles AJAX operations for blog-style comments on reports
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$db = Database::getInstance();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($db, $action);
            break;
        case 'POST':
            handlePostRequest($db, $action, $userId);
            break;
        case 'PUT':
            handlePutRequest($db, $action, $userId);
            break;
        case 'DELETE':
            handleDeleteRequest($db, $action, $userId);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Handle GET requests
 */
function handleGetRequest($db, $action) {
    switch ($action) {
        case 'list':
            // Get comments for a report
            $reportId = intval($_GET['report_id'] ?? 0);
            if (!$reportId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Report ID required']);
                return;
            }

            $comments = $db->getReportComments($reportId);

            // Format comments for frontend
            $formatted = [];
            foreach ($comments as $comment) {
                $formatted[] = formatComment($comment);
            }

            echo json_encode(['success' => true, 'comments' => $formatted]);
            break;

        case 'get':
            // Get a single comment
            $commentId = intval($_GET['comment_id'] ?? 0);
            if (!$commentId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Comment ID required']);
                return;
            }

            $comment = $db->getComment($commentId);
            if (!$comment) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Comment not found']);
                return;
            }

            echo json_encode(['success' => true, 'comment' => formatComment($comment)]);
            break;

        case 'count':
            // Get comment count for a report
            $reportId = intval($_GET['report_id'] ?? 0);
            if (!$reportId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Report ID required']);
                return;
            }

            $count = $db->getReportCommentCount($reportId);
            echo json_encode(['success' => true, 'count' => $count]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}

/**
 * Handle POST requests (create comment)
 */
function handlePostRequest($db, $action, $userId) {
    // Get JSON body
    $input = json_decode(file_get_contents('php://input'), true);

    if ($action !== 'create' && $action !== '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        return;
    }

    $reportId = intval($input['report_id'] ?? 0);
    $content = trim($input['content'] ?? '');
    $parentCommentId = !empty($input['parent_comment_id']) ? intval($input['parent_comment_id']) : null;
    $quotedText = !empty($input['quoted_text']) ? trim($input['quoted_text']) : null;

    if (!$reportId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Report ID required']);
        return;
    }

    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment content required']);
        return;
    }

    // Limit content length
    if (strlen($content) > 10000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment too long (max 10000 characters)']);
        return;
    }

    // Limit quoted text length
    if ($quotedText && strlen($quotedText) > 500) {
        $quotedText = substr($quotedText, 0, 497) . '...';
    }

    $commentId = $db->addComment($reportId, $userId, $content, $parentCommentId, $quotedText);

    if (!$commentId) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create comment']);
        return;
    }

    // Return the created comment
    $comment = $db->getComment($commentId);
    echo json_encode(['success' => true, 'comment' => formatComment($comment)]);
}

/**
 * Handle PUT requests (edit comment)
 */
function handlePutRequest($db, $action, $userId) {
    // Get JSON body
    $input = json_decode(file_get_contents('php://input'), true);

    if ($action !== 'edit' && $action !== '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        return;
    }

    $commentId = intval($input['comment_id'] ?? 0);
    $content = trim($input['content'] ?? '');

    if (!$commentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment ID required']);
        return;
    }

    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment content required']);
        return;
    }

    // Limit content length
    if (strlen($content) > 10000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment too long (max 10000 characters)']);
        return;
    }

    $result = $db->editComment($commentId, $content, $userId);

    if (!$result['success']) {
        http_response_code(403);
        echo json_encode($result);
        return;
    }

    // Return the updated comment
    $comment = $db->getComment($commentId);
    echo json_encode(['success' => true, 'comment' => formatComment($comment)]);
}

/**
 * Handle DELETE requests
 */
function handleDeleteRequest($db, $action, $userId) {
    $commentId = intval($_GET['comment_id'] ?? 0);

    if (!$commentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Comment ID required']);
        return;
    }

    $result = $db->deleteComment($commentId, $userId);

    if (!$result['success']) {
        http_response_code(403);
        echo json_encode($result);
        return;
    }

    echo json_encode(['success' => true]);
}

/**
 * Format a comment for JSON response
 */
function formatComment($comment) {
    global $db;

    $currentUser = getCurrentUser();
    $canManage = false;

    if ($currentUser) {
        $canManage = ($comment['user_id'] == $currentUser['id'] || $currentUser['role'] === 'admin');
    }

    return [
        'id' => (int)$comment['id'],
        'report_id' => (int)$comment['report_id'],
        'user_id' => (int)$comment['user_id'],
        'author_name' => $comment['author_name'],
        'author_role' => $comment['author_role'],
        'content' => $comment['content'],
        'parent_comment_id' => $comment['parent_comment_id'] ? (int)$comment['parent_comment_id'] : null,
        'quoted_text' => $comment['quoted_text'],
        'parent_author_name' => $comment['parent_author_name'] ?? null,
        'is_edited' => (bool)$comment['is_edited'],
        'created_at' => $comment['created_at'],
        'updated_at' => $comment['updated_at'],
        'can_manage' => $canManage
    ];
}
