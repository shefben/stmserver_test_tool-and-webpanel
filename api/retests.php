<?php
/**
 * API endpoint for retest queue
 * Clients query this to check if there are tests they need to retest
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/test_keys.php';

// Authenticate via API key
if (!requireApiAuth()) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key. Include X-API-Key header.']);
    exit;
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

// GET - Query retest queue
if ($method === 'GET') {
    $clientVersion = $_GET['client_version'] ?? null;

    $queue = $db->getRetestQueue($clientVersion);

    // Add test names to the queue items
    $testKeys = getTestKeys();
    foreach ($queue as &$item) {
        $item['test_name'] = $testKeys[$item['test_key']] ?? 'Unknown Test';
    }

    echo json_encode([
        'success' => true,
        'count' => count($queue),
        'retest_queue' => $queue
    ]);
    exit;
}

// POST - Mark a retest as completed
if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }

    $type = $data['type'] ?? '';
    $id = $data['id'] ?? 0;
    $newStatus = $data['new_status'] ?? '';

    if (!$type || !$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing type or id parameter']);
        exit;
    }

    if ($type === 'retest') {
        $result = $db->completeRetestRequest($id);
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Retest request marked as completed']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Retest request not found']);
        }
    } elseif ($type === 'fixed') {
        // If the new test passed, mark as verified
        if ($newStatus === 'Working') {
            $result = $db->verifyFixedTest($id);
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Fixed test verified as working']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Fixed test not found']);
            }
        } else {
            // Test still failing, keep it in the queue
            echo json_encode([
                'success' => true,
                'message' => 'Fixed test still failing - remaining in queue',
                'status' => 'pending_retest'
            ]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type. Use "retest" or "fixed"']);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method not allowed. Use GET to query, POST to update.']);
