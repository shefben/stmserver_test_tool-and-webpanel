<?php
/**
 * API endpoint for creating retest requests from the panel
 * Admin-only endpoint - uses session authentication
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Require session-based admin authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin privileges required']);
    exit;
}

// Parse JSON body
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

$testKey = trim($data['test_key'] ?? '');
$clientVersion = trim($data['client_version'] ?? '');
$reason = trim($data['reason'] ?? 'Requested retest from panel');
$notes = trim($data['notes'] ?? '');
$reportId = isset($data['report_id']) ? intval($data['report_id']) : null;

// Validate required fields
if (empty($testKey)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing test_key parameter']);
    exit;
}

if (empty($clientVersion)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing client_version parameter']);
    exit;
}

// Notes are required for retest requests
if (empty($notes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Notes are required - please explain what needs to be retested']);
    exit;
}

$db = Database::getInstance();

// Check if there's already a pending retest request for this test/version
if ($db->hasPendingRetestRequest($testKey, $clientVersion)) {
    http_response_code(409);
    echo json_encode([
        'error' => 'A pending retest request already exists for this test and version',
        'success' => false
    ]);
    exit;
}

// Get the current user
$user = getCurrentUser();
$createdBy = $user['username'];

// Get the report revision if we have a report_id
$reportRevision = null;
if ($reportId) {
    $report = $db->getReport($reportId);
    if ($report) {
        $reportRevision = $report['revision_count'] ?? 0;
    }
}

// Create the retest request with notes, report_id, and revision
$requestId = $db->addRetestRequest($testKey, $clientVersion, $createdBy, $reason, $notes, $reportId, $reportRevision);

if ($requestId) {
    // If we have a report_id, create a notification for the tester
    if ($reportId && isset($report)) {
        $db->createRetestNotification($reportId, $testKey, $clientVersion, $notes, $createdBy, $reportRevision);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Retest request created successfully',
        'request_id' => $requestId,
        'test_key' => $testKey,
        'client_version' => $clientVersion,
        'created_by' => $createdBy,
        'notes' => $notes,
        'report_revision' => $reportRevision
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to create retest request'
    ]);
}
