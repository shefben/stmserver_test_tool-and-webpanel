<?php
/**
 * Lightweight API endpoint for polling flag notifications
 *
 * This is a minimal, low-overhead endpoint designed for frequent polling
 * by the Python client to check for new flags without freezing the UI.
 *
 * GET - Check for unacknowledged flags for the authenticated user
 * POST - Acknowledge a flag (mark as viewed)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';

// Authenticate via API key
$user = requireApiAuth();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$username = $user['username'];

// GET - Check for unacknowledged flags (lightweight query)
if ($method === 'GET') {
    try {
        // Get count of unacknowledged flags for this user
        $flags = $db->getUnacknowledgedFlags($username);

        echo json_encode([
            'success' => true,
            'count' => count($flags),
            'flags' => $flags
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// POST - Acknowledge a flag
if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $flagType = $data['type'] ?? '';  // 'retest' or 'fixed'
    $flagId = intval($data['id'] ?? 0);

    if (!$flagType || !$flagId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing type or id parameter']);
        exit;
    }

    try {
        $result = $db->acknowledgeFlagNotification($flagType, $flagId, $username);

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Flag acknowledged'
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Flag not found or already acknowledged']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
