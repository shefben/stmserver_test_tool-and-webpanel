<?php
/**
 * API endpoint for downloading attached log files
 * Decompresses gzip data and serves as plain text file
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

// Get log ID
$logId = intval($_GET['id'] ?? 0);

if (!$logId) {
    http_response_code(400);
    die('Invalid log ID');
}

// Get database instance
$db = Database::getInstance();

// Get the log file
$log = $db->getReportLog($logId);

if (!$log) {
    http_response_code(404);
    die('Log file not found');
}

// Decompress the gzip data
$decompressedContent = @gzuncompress($log['log_data']);

// If gzuncompress fails, try gzdecode (for gzip format vs zlib format)
if ($decompressedContent === false) {
    $decompressedContent = @gzdecode($log['log_data']);
}

if ($decompressedContent === false) {
    http_response_code(500);
    die('Failed to decompress log data');
}

// Set headers for file download
$filename = $log['filename'];
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($decompressedContent));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Output the decompressed content
echo $decompressedContent;
