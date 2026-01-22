<?php
/**
 * Global Search API endpoint
 * Searches across reports, tests, versions, and testers
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/test_keys.php';

header('Content-Type: application/json');

// Get search query
$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode(['success' => false, 'error' => 'Query too short']);
    exit;
}

$db = Database::getInstance();
$results = [];
$searchLower = strtolower($query);

// Search tests by key or name
foreach (TEST_KEYS as $key => $test) {
    $keyMatch = stripos($key, $query) !== false;
    $nameMatch = stripos($test['name'], $query) !== false;
    $categoryMatch = stripos($test['category'], $query) !== false;

    if ($keyMatch || $nameMatch || $categoryMatch) {
        $results[] = [
            'category' => 'Tests',
            'type' => 'Test ' . $key,
            'title' => $test['name'],
            'subtitle' => $test['category'],
            'url' => '?page=results&test_key=' . urlencode($key)
        ];
    }
}

// Search versions
$versions = $db->getUniqueValues('reports', 'client_version');
foreach ($versions as $version) {
    if (stripos($version, $query) !== false) {
        $results[] = [
            'category' => 'Versions',
            'type' => 'Version',
            'title' => $version,
            'subtitle' => null,
            'url' => '?page=results&version=' . urlencode($version)
        ];
    }
}

// Search testers
$testers = $db->getUniqueValues('reports', 'tester');
foreach ($testers as $tester) {
    if (stripos($tester, $query) !== false) {
        $results[] = [
            'category' => 'Testers',
            'type' => 'Tester',
            'title' => $tester,
            'subtitle' => null,
            'url' => '?page=reports&tester=' . urlencode($tester)
        ];
    }
}

// Search commit hashes
$commits = $db->getUniqueValues('reports', 'commit_hash');
foreach ($commits as $commit) {
    if ($commit && stripos($commit, $query) !== false) {
        $results[] = [
            'category' => 'Commits',
            'type' => 'Commit',
            'title' => substr($commit, 0, 8) . '...',
            'subtitle' => $commit,
            'url' => '?page=reports&commit_hash=' . urlencode($commit)
        ];
    }
}

// Search reports by ID if query is numeric
if (is_numeric($query)) {
    $report = $db->getReport((int)$query);
    if ($report) {
        $results[] = [
            'category' => 'Reports',
            'type' => 'Report #' . $report['id'],
            'title' => $report['client_version'],
            'subtitle' => 'By ' . $report['tester'] . ' on ' . date('Y-m-d', strtotime($report['submitted_at'])),
            'url' => '?page=report_detail&id=' . $report['id']
        ];
    }
}

// Search recent reports (limit to 10)
$pdo = $db->getPdo();
$stmt = $pdo->prepare("
    SELECT id, client_version, tester, commit_hash, submitted_at
    FROM reports
    WHERE client_version LIKE ? OR tester LIKE ? OR commit_hash LIKE ?
    ORDER BY submitted_at DESC
    LIMIT 10
");
$likeQuery = '%' . $query . '%';
$stmt->execute([$likeQuery, $likeQuery, $likeQuery]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($reports as $report) {
    // Avoid duplicates with ID search
    if (is_numeric($query) && (int)$query === (int)$report['id']) continue;

    $results[] = [
        'category' => 'Reports',
        'type' => 'Report #' . $report['id'],
        'title' => $report['client_version'],
        'subtitle' => 'By ' . $report['tester'] . ' on ' . date('Y-m-d', strtotime($report['submitted_at'])),
        'url' => '?page=report_detail&id=' . $report['id']
    ];
}

// Search test results with notes containing the query (limit to 5)
$stmt = $pdo->prepare("
    SELECT tr.id, tr.test_key, tr.status, tr.notes, r.id as report_id, r.client_version
    FROM test_results tr
    JOIN reports r ON tr.report_id = r.id
    WHERE tr.notes LIKE ?
    ORDER BY r.submitted_at DESC
    LIMIT 5
");
$stmt->execute([$likeQuery]);
$noteResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($noteResults as $result) {
    $testName = getTestName($result['test_key']);
    $results[] = [
        'category' => 'Test Notes',
        'type' => 'Test ' . $result['test_key'],
        'title' => $testName . ' (' . $result['status'] . ')',
        'subtitle' => 'In report #' . $result['report_id'] . ' - ' . $result['client_version'],
        'url' => '?page=report_detail&id=' . $result['report_id'] . '#test-' . $result['test_key']
    ];
}

// Limit total results
$results = array_slice($results, 0, 25);

echo json_encode([
    'success' => true,
    'query' => $query,
    'results' => $results,
    'count' => count($results)
]);
