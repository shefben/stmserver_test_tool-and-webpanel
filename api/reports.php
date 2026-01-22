<?php
/**
 * API endpoint for fetching reports
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/test_keys.php';

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

// Authenticate via API key
if (!requireApiAuth()) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key. Include X-API-Key header.']);
    exit;
}

$db = Database::getInstance();

// Check if requesting a specific report
$reportId = intval($_GET['id'] ?? 0);

if ($reportId) {
    // Get single report with test results
    $report = $db->getReport($reportId);

    if (!$report) {
        http_response_code(404);
        echo json_encode(['error' => 'Report not found']);
        exit;
    }

    $testResults = $db->getTestResults($reportId);
    $stats = $db->getReportStats($reportId);

    // Format results
    $formattedResults = [];
    foreach ($testResults as $result) {
        $formattedResults[$result['test_key']] = [
            'status' => $result['status'],
            'notes' => strip_tags($result['notes'] ?? ''),
            'test_name' => getTestName($result['test_key'])
        ];
    }

    echo json_encode([
        'report' => [
            'id' => $report['id'],
            'tester' => $report['tester'],
            'commit_hash' => $report['commit_hash'],
            'test_type' => $report['test_type'],
            'client_version' => $report['client_version'],
            'submitted_at' => $report['submitted_at'],
            'stats' => $stats,
            'results' => $formattedResults
        ]
    ]);
} else {
    // Get list of reports
    $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
    $offset = max(0, intval($_GET['offset'] ?? 0));

    // Filters
    $filters = [];
    if (!empty($_GET['version'])) $filters['client_version'] = $_GET['version'];
    if (!empty($_GET['type'])) $filters['test_type'] = $_GET['type'];
    if (!empty($_GET['tester'])) $filters['tester'] = $_GET['tester'];

    $reports = $db->getReports($limit, $offset, $filters);
    $total = $db->countReports($filters);

    // Add stats to each report
    $formattedReports = [];
    foreach ($reports as $report) {
        $stats = $db->getReportStats($report['id']);
        $formattedReports[] = [
            'id' => $report['id'],
            'tester' => $report['tester'],
            'commit_hash' => $report['commit_hash'],
            'test_type' => $report['test_type'],
            'client_version' => $report['client_version'],
            'submitted_at' => $report['submitted_at'],
            'stats' => $stats
        ];
    }

    echo json_encode([
        'reports' => $formattedReports,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total
        ]
    ]);
}
