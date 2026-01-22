<?php
/**
 * API endpoint for viewing attached log files in the browser
 * Returns decompressed content as JSON for display in popup
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// Get log ID
$logId = intval($_GET['id'] ?? 0);

if (!$logId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid log ID']);
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get the log file
$log = $db->getReportLog($logId);

if (!$log) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Log file not found']);
    exit;
}

// Decompress the gzip data
$decompressedContent = @gzuncompress($log['log_data']);

// If gzuncompress fails, try gzdecode (for gzip format vs zlib format)
if ($decompressedContent === false) {
    $decompressedContent = @gzdecode($log['log_data']);
}

if ($decompressedContent === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to decompress log data']);
    exit;
}

// Return the content as JSON
echo json_encode([
    'success' => true,
    'filename' => $log['filename'],
    'datetime' => $log['log_datetime'],
    'size_original' => $log['size_original'],
    'content' => $decompressedContent
]);
