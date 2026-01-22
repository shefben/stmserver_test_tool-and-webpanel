<?php
/**
 * API endpoint for fetching statistics
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

// Get stats type
$type = $_GET['type'] ?? 'overview';

switch ($type) {
    case 'overview':
        // Overall statistics
        $stats = $db->getStats();
        $totalTests = $stats['working'] + $stats['semi_working'] + $stats['not_working'];

        echo json_encode([
            'stats' => [
                'total_reports' => $stats['total_reports'],
                'total_tests' => $totalTests,
                'working' => [
                    'count' => $stats['working'],
                    'percentage' => $totalTests > 0 ? round(($stats['working'] / $totalTests) * 100, 1) : 0
                ],
                'semi_working' => [
                    'count' => $stats['semi_working'],
                    'percentage' => $totalTests > 0 ? round(($stats['semi_working'] / $totalTests) * 100, 1) : 0
                ],
                'not_working' => [
                    'count' => $stats['not_working'],
                    'percentage' => $totalTests > 0 ? round(($stats['not_working'] / $totalTests) * 100, 1) : 0
                ]
            ]
        ]);
        break;

    case 'versions':
        // Stats by version
        $versionTrend = $db->getVersionTrend();
        $versions = [];

        foreach ($versionTrend as $v) {
            $total = $v['working'] + $v['semi_working'] + $v['not_working'];
            $versions[] = [
                'version' => $v['client_version'],
                'working' => $v['working'],
                'semi_working' => $v['semi_working'],
                'not_working' => $v['not_working'],
                'total' => $total,
                'compatibility_score' => $total > 0 ? round((($v['working'] + $v['semi_working'] * 0.5) / $total) * 100) : 0
            ];
        }

        echo json_encode(['versions' => $versions]);
        break;

    case 'tests':
        // Stats by test
        $testStats = $db->getTestStats();
        $tests = [];

        foreach ($testStats as $stat) {
            $total = $stat['working'] + $stat['semi_working'] + $stat['not_working'];
            $tests[] = [
                'test_key' => $stat['test_key'],
                'test_name' => getTestName($stat['test_key']),
                'working' => $stat['working'],
                'semi_working' => $stat['semi_working'],
                'not_working' => $stat['not_working'],
                'total' => $total,
                'pass_rate' => $total > 0 ? round(($stat['working'] / $total) * 100, 1) : 0,
                'fail_rate' => $total > 0 ? round(($stat['not_working'] / $total) * 100, 1) : 0
            ];
        }

        echo json_encode(['tests' => $tests]);
        break;

    case 'problematic':
        // Most problematic tests
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $problematic = $db->getProblematicTests($limit);

        $tests = [];
        foreach ($problematic as $test) {
            $tests[] = [
                'test_key' => $test['test_key'],
                'test_name' => getTestName($test['test_key']),
                'fail_rate' => round($test['fail_rate'], 1),
                'fail_count' => $test['fail_count'],
                'total_count' => $test['total_count']
            ];
        }

        echo json_encode(['problematic_tests' => $tests]);
        break;

    case 'matrix':
        // Version/test compatibility matrix
        $matrix = $db->getVersionMatrix();

        $formatted = [];
        foreach ($matrix as $row) {
            $version = $row['client_version'];
            if (!isset($formatted[$version])) {
                $formatted[$version] = [];
            }
            $formatted[$version][$row['test_key']] = $row['most_common_status'];
        }

        echo json_encode(['matrix' => $formatted]);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid stats type',
            'valid_types' => ['overview', 'versions', 'tests', 'problematic', 'matrix']
        ]);
}
