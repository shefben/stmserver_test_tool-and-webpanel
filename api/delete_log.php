<?php
/**
 * API endpoint for deleting a log file from a report
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Start session for web auth (config.php may have already started it)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Only accept POST or DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST or DELETE.']);
    exit;
}

// Check authentication (session for web UI)
$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Get log ID from POST or query string
$logId = intval($_POST['log_id'] ?? $_GET['id'] ?? 0);

if (!$logId) {
    http_response_code(400);
    echo json_encode(['error' => 'Log ID is required']);
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get the log to find its report
$log = $db->getReportLog($logId);
if (!$log) {
    http_response_code(404);
    echo json_encode(['error' => 'Log file not found']);
    exit;
}

$reportId = $log['report_id'];

// Check if user can edit this report
if (!canEditReport($reportId)) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to edit this report']);
    exit;
}

// Delete the log
$stmt = $db->getPdo()->prepare("DELETE FROM report_logs WHERE id = ?");
$success = $stmt->execute([$logId]);

if ($success) {
    // Get the updated log list
    $logs = $db->getReportLogs($reportId);

    echo json_encode([
        'success' => true,
        'message' => 'Log file deleted successfully',
        'total_logs' => count($logs)
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete log file']);
}
