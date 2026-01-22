<?php
/**
 * User info API endpoint
 *
 * GET /api/user.php - Returns info about the authenticated user
 *
 * Response:
 * {
 *   "success": true,
 *   "user": {
 *     "username": "tester1",
 *     "role": "user"
 *   },
 *   "revisions": {
 *     "<sha>": {
 *       "notes": "commit message",
 *       "files": {"added":[], "removed":[], "modified":[]},
 *       "ts": unix_timestamp,
 *       "datetime": "formatted datetime string"
 *     },
 *     ...
 *   }
 * }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Require API key authentication
$username = requireApiAuth();

// Trigger GitHub revision check and get latest revisions
$revisions = [];
if (isGitHubConfigured()) {
    try {
        // Force refresh when user authenticates to check for new revisions
        $rawRevisions = getGitHubRevisions(true);

        // Format revisions for API response
        foreach ($rawRevisions as $sha => $data) {
            $revisions[$sha] = [
                'notes' => $data['notes'] ?? '',
                'files' => $data['files'] ?? ['added' => [], 'removed' => [], 'modified' => []],
                'ts' => $data['ts'] ?? 0,
                'datetime' => formatRevisionDateTime($data['ts'] ?? 0)
            ];
        }
    } catch (Exception $e) {
        // Log error but don't fail the request
        error_log("GitHub revision fetch error in user.php: " . $e->getMessage());
    }
}

// Return user info with revisions
jsonResponse([
    'success' => true,
    'user' => [
        'username' => $username,
    ],
    'revisions' => $revisions,
    'revisions_count' => count($revisions)
]);
