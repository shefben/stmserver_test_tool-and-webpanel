<?php
/**
 * API endpoint for submitting reports
 */

// Allow more time for large report submissions
set_time_limit(120);

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/test_keys.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Authenticate via API key
if (!requireApiAuth()) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key. Include X-API-Key header.']);
    exit;
}

// Get JSON body
$rawBody = file_get_contents('php://input');
$json = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

// Parse the test tool JSON format - returns array of all versions
$allReports = parseTestToolJson($json);

if (!$allReports || empty($allReports)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid report format',
        'expected' => [
            'metadata' => [
                'tester_name' => 'string',
                'commit_hash' => 'string (optional)',
                'test_type' => 'WAN or LAN'
            ],
            'tests' => [
                'Version Name' => [
                    '1' => ['status' => 'Working|Semi-working|Not working|N/A', 'notes' => 'string'],
                    // ... more tests
                ]
            ]
        ]
    ]);
    exit;
}

// Initialize database
$db = Database::getInstance();

// Insert ALL reports (one per version)
$insertedReports = [];
$failedReports = [];

foreach ($allReports as $parsed) {
    $reportId = $db->insertReport(
        $parsed['tester'],
        $parsed['commit_hash'],
        $parsed['test_type'],
        $parsed['client_version'],
        $rawBody,
        $parsed['test_duration'] ?? null,
        $parsed['steamui_version'] ?? null,
        $parsed['steam_pkg_version'] ?? null
    );

    if ($reportId) {
        // Clean notes before batch insert
        $cleanedResults = [];
        foreach ($parsed['results'] as $testKey => $result) {
            $cleanedResults[$testKey] = [
                'status' => $result['status'],
                'notes' => cleanNotes($result['notes'] ?? '')
            ];
        }

        // Batch insert test results (much faster than one-by-one)
        $insertedCount = $db->insertTestResultsBatch($reportId, $cleanedResults);

        // Insert attached log files (if any)
        $logsCount = 0;
        if (!empty($parsed['attached_logs']) && is_array($parsed['attached_logs'])) {
            foreach ($parsed['attached_logs'] as $log) {
                if (!empty($log['filename']) && !empty($log['data'])) {
                    $logDatetime = $log['datetime'] ?? date('Y-m-d H:i:s');
                    $sizeOriginal = $log['size_original'] ?? 0;
                    $sizeCompressed = $log['size_compressed'] ?? 0;
                    if ($db->insertReportLog($reportId, $log['filename'], $logDatetime, $sizeOriginal, $sizeCompressed, $log['data'])) {
                        $logsCount++;
                    }
                }
            }
        }

        // Check for regressions and create notifications
        $regressionsDetected = 0;
        try {
            // Get the latest revision (which was just archived if this was an update)
            $pdo = $db->getPdo();
            $stmt = $pdo->prepare("
                SELECT test_results, changes_diff
                FROM report_revisions
                WHERE report_id = ?
                ORDER BY revision_number DESC
                LIMIT 1
            ");
            $stmt->execute([$reportId]);
            $latestRevision = $stmt->fetch();

            if ($latestRevision && $latestRevision['test_results']) {
                $oldResults = json_decode($latestRevision['test_results'], true) ?? [];

                // Convert new results to array format for comparison
                $newResultsArray = [];
                foreach ($cleanedResults as $testKey => $result) {
                    $newResultsArray[] = [
                        'test_key' => $testKey,
                        'status' => $result['status']
                    ];
                }

                // Detect regressions
                $regressions = $db->detectRegressions($oldResults, $newResultsArray);

                if (!empty($regressions)) {
                    $regressionsDetected = count($regressions);
                    // Create notifications for admins
                    $db->createRegressionNotifications(
                        $reportId,
                        $parsed['client_version'],
                        $parsed['tester'],
                        $regressions
                    );
                }
            }
        } catch (Exception $e) {
            // Regression detection failed, but don't fail the submission
            error_log("Regression detection error: " . $e->getMessage());
        }

        $insertedReports[] = [
            'report_id' => $reportId,
            'client_version' => $parsed['client_version'],
            'tests_recorded' => $insertedCount,
            'logs_attached' => $logsCount,
            'regressions_detected' => $regressionsDetected,
            'view_url' => getBaseUrl() . '/?page=report_detail&id=' . $reportId
        ];
    } else {
        $failedReports[] = $parsed['client_version'];
    }
}

if (empty($insertedReports)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save reports to database']);
    exit;
}

// Return success
http_response_code(201);
echo json_encode([
    'success' => true,
    'reports_created' => count($insertedReports),
    'reports' => $insertedReports,
    'failed' => $failedReports
]);
