<?php
/**
 * API endpoint for checking if report hashes exist on the server.
 * Used by clients to determine if reports need to be (re)submitted.
 *
 * Request format (POST):
 * {
 *     "hashes": {
 *         "version_id_1": "sha256_hash_1",
 *         "version_id_2": "sha256_hash_2",
 *         ...
 *     },
 *     "tester": "tester_name",
 *     "test_type": "WAN|LAN|WAN/LAN",
 *     "commit_hash": "optional_commit_hash"
 * }
 *
 * Response format:
 * {
 *     "success": true,
 *     "results": {
 *         "version_id_1": {
 *             "exists": true|false,
 *             "hash_matches": true|false,
 *             "server_hash": "hash_on_server_or_null",
 *             "action": "skip|update|create"
 *         },
 *         ...
 *     }
 * }
 *
 * Actions:
 * - "skip": Report exists with matching hash, no need to submit
 * - "update": Report exists but hash differs, submit as new revision
 * - "create": Report doesn't exist, submit as new report
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Authenticate via API key - returns the username associated with the key
$authenticatedUser = requireApiAuth();
if (!$authenticatedUser) {
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

// Validate required fields
if (empty($json['hashes']) || !is_array($json['hashes'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid "hashes" field. Expected object mapping version_id to hash.']);
    exit;
}

$hashes = $json['hashes'];
// Resolve tester from the authenticated API key - ignore any tester field in the request
// This ensures we always look up reports belonging to the API key owner
$tester = $authenticatedUser;
$testType = $json['test_type'] ?? '';
$commitHash = $json['commit_hash'] ?? null;

// Initialize database
$db = Database::getInstance();
$pdo = $db->getPdo();

$results = [];

foreach ($hashes as $clientVersion => $clientHash) {
    // Find existing report for this tester+version+test_type+commit_hash
    // We use the same logic as insertReport to match reports
    if ($commitHash !== null && $commitHash !== '') {
        $stmt = $pdo->prepare("
            SELECT id, content_hash, revision_count
            FROM reports
            WHERE tester = ? AND client_version = ? AND test_type = ? AND commit_hash = ?
            LIMIT 1
        ");
        $stmt->execute([$tester, $clientVersion, $testType, $commitHash]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, content_hash, revision_count
            FROM reports
            WHERE tester = ? AND client_version = ? AND test_type = ? AND (commit_hash IS NULL OR commit_hash = '')
            LIMIT 1
        ");
        $stmt->execute([$tester, $clientVersion, $testType]);
    }

    $existingReport = $stmt->fetch();

    if ($existingReport) {
        $serverHash = $existingReport['content_hash'];
        $hashMatches = ($serverHash !== null && $serverHash === $clientHash);

        $results[$clientVersion] = [
            'exists' => true,
            'hash_matches' => $hashMatches,
            'server_hash' => $serverHash,
            'report_id' => (int)$existingReport['id'],
            'revision_count' => (int)$existingReport['revision_count'],
            'action' => $hashMatches ? 'skip' : 'update'
        ];
    } else {
        $results[$clientVersion] = [
            'exists' => false,
            'hash_matches' => false,
            'server_hash' => null,
            'report_id' => null,
            'revision_count' => 0,
            'action' => 'create'
        ];
    }
}

// Return results
echo json_encode([
    'success' => true,
    'results' => $results
]);
