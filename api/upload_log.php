<?php
/**
 * API endpoint for uploading log files to a report
 * Accepts file upload, compresses with gzip, stores in database
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Start session for web auth
session_start();

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Check authentication (session for web UI)
$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Get report ID
$reportId = intval($_POST['report_id'] ?? 0);

if (!$reportId) {
    http_response_code(400);
    echo json_encode(['error' => 'Report ID is required']);
    exit;
}

// Get database instance
$db = Database::getInstance();

// Check if report exists
$report = $db->getReport($reportId);
if (!$report) {
    http_response_code(404);
    echo json_encode(['error' => 'Report not found']);
    exit;
}

// Check if user can edit this report
if (!canEditReport($reportId)) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to edit this report']);
    exit;
}

// Check current log count for this report
$existingLogs = $db->getReportLogs($reportId);
if (count($existingLogs) >= 3) {
    http_response_code(400);
    echo json_encode(['error' => 'Maximum 3 log files per report. Please remove an existing log first.']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['log_file']) || $_FILES['log_file']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'No file uploaded';
    if (isset($_FILES['log_file'])) {
        switch ($_FILES['log_file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'File is too large';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMsg = 'File was only partially uploaded';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'No file was uploaded';
                break;
        }
    }
    http_response_code(400);
    echo json_encode(['error' => $errorMsg]);
    exit;
}

$file = $_FILES['log_file'];

// Validate file type (only .txt and .log files)
$allowedExtensions = ['txt', 'log'];
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($extension, $allowedExtensions)) {
    http_response_code(400);
    echo json_encode(['error' => 'Only .txt and .log files are allowed']);
    exit;
}

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File is too large. Maximum size is 5MB.']);
    exit;
}

// Read file content
$content = file_get_contents($file['tmp_name']);
if ($content === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read uploaded file']);
    exit;
}

// Get original size
$sizeOriginal = strlen($content);

// Compress content with gzip
$compressed = gzcompress($content, 9);
if ($compressed === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to compress file']);
    exit;
}

$sizeCompressed = strlen($compressed);

// Encode to base64 for database storage
$logDataBase64 = base64_encode($compressed);

// Get log datetime from file modification time if available
$logDatetime = date('Y-m-d H:i:s');

// Insert log into database
$success = $db->insertReportLog(
    $reportId,
    $file['name'],
    $logDatetime,
    $sizeOriginal,
    $sizeCompressed,
    $logDataBase64
);

if ($success) {
    // Get the updated log list
    $logs = $db->getReportLogs($reportId);

    echo json_encode([
        'success' => true,
        'message' => 'Log file uploaded successfully',
        'log' => [
            'filename' => $file['name'],
            'size_original' => $sizeOriginal,
            'size_compressed' => $sizeCompressed,
            'compression_ratio' => round(($sizeCompressed / $sizeOriginal) * 100, 1) . '%'
        ],
        'total_logs' => count($logs)
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save log file to database']);
}
