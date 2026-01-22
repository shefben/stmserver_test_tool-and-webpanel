<?php
/**
 * API endpoint for fetching GitHub revision data
 *
 * GET /api/revisions.php - Returns all revisions
 * GET /api/revisions.php?sha=<hash> - Returns specific revision details
 * GET /api/revisions.php?refresh=1 - Force refresh from GitHub
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

// Check if GitHub is configured
if (!isGitHubConfigured()) {
    echo json_encode([
        'success' => false,
        'error' => 'GitHub integration not configured',
        'revisions' => []
    ]);
    exit;
}

$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] == '1';
$specificSha = $_GET['sha'] ?? null;

try {
    if ($specificSha) {
        // Return single revision details
        $revisions = getGitHubRevisions($forceRefresh);
        $revision = $revisions[$specificSha] ?? null;

        if ($revision) {
            echo json_encode([
                'success' => true,
                'sha' => $specificSha,
                'revision' => [
                    'notes' => $revision['notes'] ?? '',
                    'files' => $revision['files'] ?? ['added' => [], 'removed' => [], 'modified' => []],
                    'ts' => $revision['ts'] ?? 0,
                    'datetime' => formatRevisionDateTime($revision['ts'] ?? 0)
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Revision not found'
            ]);
        }
    } else {
        // Return all revisions
        $revisions = getGitHubRevisions($forceRefresh);

        // Format for API response
        $formattedRevisions = [];
        foreach ($revisions as $sha => $data) {
            $formattedRevisions[$sha] = [
                'notes' => $data['notes'] ?? '',
                'files' => $data['files'] ?? ['added' => [], 'removed' => [], 'modified' => []],
                'ts' => $data['ts'] ?? 0,
                'datetime' => formatRevisionDateTime($data['ts'] ?? 0)
            ];
        }

        // Also return dropdown options format
        $options = getRevisionDropdownOptions();

        echo json_encode([
            'success' => true,
            'count' => count($formattedRevisions),
            'revisions' => $formattedRevisions,
            'options' => $options
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch revisions: ' . $e->getMessage()
    ]);
}
