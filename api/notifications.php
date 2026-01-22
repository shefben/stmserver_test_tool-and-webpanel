<?php
/**
 * API endpoint for user notifications
 * Supports getting notifications and marking them as read
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';

// Try API key first, then session
$user = null;
$apiKey = null;

// Check for API key in header
if (isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'];
} elseif (isset($_GET['api_key'])) {
    $apiKey = $_GET['api_key'];
}

$db = Database::getInstance();

if ($apiKey) {
    $user = $db->getUserByApiKey($apiKey);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }
} elseif (isLoggedIn()) {
    $user = getCurrentUser();
    // Get full user data from DB
    $user = $db->getUser($user['username']);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Get notifications
    $unreadOnly = isset($_GET['unread']) && $_GET['unread'] === 'true';
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

    $notifications = $db->getUserNotifications($user['id'], $limit);
    $unreadCount = $db->getUnreadNotificationCount($user['id']);

    if ($unreadOnly) {
        $notifications = array_filter($notifications, function($n) {
            return !$n['is_read'];
        });
        $notifications = array_values($notifications);
    }

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'notifications' => $notifications
    ]);
    exit;
}

if ($method === 'POST') {
    // Parse JSON body
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $action = $data['action'] ?? '';

    if ($action === 'mark_read') {
        $notificationId = (int)($data['notification_id'] ?? 0);
        if ($notificationId) {
            $db->markNotificationRead($notificationId);
            echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing notification_id']);
        }
        exit;
    }

    if ($action === 'mark_all_read') {
        $db->markAllNotificationsRead($user['id']);
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
