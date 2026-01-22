<?php
/**
 * API endpoint for getting report log metadata
 * Returns log list (without data) for a report, downloads a specific log, or deletes a log
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

// Handle DELETE request for removing a log
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? $_GET['action'] ?? '') === 'delete')) {
    // Get log_id from query string or POST body
    $logId = intval($_GET['log_id'] ?? $_POST['log_id'] ?? 0);

    if (!$logId) {
        http_response_code(400);
        echo json_encode(['error' => 'log_id parameter is required for deletion']);
        exit;
    }

    // Get the log to find its report
    $log = $db->getReportLog($logId);
    if (!$log) {
        http_response_code(404);
        echo json_encode(['error' => 'Log file not found']);
        exit;
    }

    // Check if user owns this report (only report owner or admin can delete)
    $report = $db->getReport($log['report_id']);
    $currentUser = getCurrentApiUser();

    if (!$report) {
        http_response_code(404);
        echo json_encode(['error' => 'Report not found']);
        exit;
    }

    // Check ownership or admin status
    if ($report['tester'] !== $currentUser['username'] && $currentUser['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'You can only delete logs from your own reports']);
        exit;
    }

    // Delete the log
    $reportId = $db->deleteReportLogById($logId);
    if ($reportId) {
        echo json_encode([
            'success' => true,
            'message' => 'Log file deleted successfully',
            'report_id' => $reportId
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete log file']);
    }
    exit;
}

// Get report ID from query string
$reportId = intval($_GET['report_id'] ?? 0);
$logId = intval($_GET['log_id'] ?? 0);

// If log_id is provided, return the specific log data (compressed, base64 encoded)
if ($logId) {
    $log = $db->getReportLog($logId);

    if (!$log) {
        http_response_code(404);
        echo json_encode(['error' => 'Log file not found']);
        exit;
    }

    // Return log data base64 encoded (already compressed in database)
    echo json_encode([
        'success' => true,
        'log' => [
            'id' => $log['id'],
            'report_id' => $log['report_id'],
            'filename' => $log['filename'],
            'log_datetime' => $log['log_datetime'],
            'size_original' => $log['size_original'],
            'size_compressed' => $log['size_compressed'],
            'data' => base64_encode($log['log_data'])
        ]
    ]);
    exit;
}

// Otherwise, return list of logs for a report
if (!$reportId) {
    http_response_code(400);
    echo json_encode(['error' => 'report_id parameter is required']);
    exit;
}

// Check if report exists
$report = $db->getReport($reportId);
if (!$report) {
    http_response_code(404);
    echo json_encode(['error' => 'Report not found']);
    exit;
}

// Get logs (without data)
$logs = $db->getReportLogs($reportId);

$logList = [];
foreach ($logs as $log) {
    $logList[] = [
        'id' => $log['id'],
        'filename' => $log['filename'],
        'log_datetime' => $log['log_datetime'],
        'size_original' => $log['size_original'],
        'size_compressed' => $log['size_compressed'],
        'created_at' => $log['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'report_id' => $reportId,
    'logs' => $logList
]);
