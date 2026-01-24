<?php
/**
 * GitHub Repository ZIP Download API
 *
 * Provides endpoints to get the ZIP download URL or redirect to download.
 * Uses GITHUB_OWNER, GITHUB_REPO, and GITHUB_TOKEN from config.php.
 *
 * Endpoints:
 *   GET /api/github_zip.php?action=url - Returns JSON with the download URL
 *   GET /api/github_zip.php?action=download - Redirects to the ZIP download
 *   GET /api/github_zip.php?action=info - Returns latest revision info with download URL
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/githubrepozipgrabber.php';
require_once __DIR__ . '/githubrevisiongrabber.php';

// Require login
requireLogin();

// Check if GitHub is configured
if (!isGitHubConfigured()) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'GitHub integration is not configured'
    ]);
    exit;
}

$action = $_GET['action'] ?? 'info';
$branch = $_GET['branch'] ?? 'main';

try {
    switch ($action) {
        case 'url':
            // Return just the download URL as JSON
            $url = getGitHubZipUrl($branch);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'url' => $url,
                'owner' => GITHUB_OWNER,
                'repo' => GITHUB_REPO,
                'branch' => $branch
            ]);
            break;

        case 'download':
            // Redirect to the ZIP download
            redirectToGitHubZip($branch);
            break;

        case 'info':
        default:
            // Return latest revision info along with download URL
            $info = getGitHubZipInfo($branch);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $info
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Get the GitHub ZIP download URL using config.php settings
 */
function getGitHubZipUrl(string $branch = 'main'): string {
    $cacheDir = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $redirector = new GitHubZipRedirector(
        GITHUB_TOKEN,
        'php-panel-github-zip-redirector',
        30,
        $cacheDir,
        60
    );

    return $redirector->getZipRedirectUrl(GITHUB_OWNER, GITHUB_REPO, $branch);
}

/**
 * Redirect to the GitHub ZIP download
 */
function redirectToGitHubZip(string $branch = 'main'): void {
    $cacheDir = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $redirector = new GitHubZipRedirector(
        GITHUB_TOKEN,
        'php-panel-github-zip-redirector',
        30,
        $cacheDir,
        60
    );

    $redirector->redirectToZip(GITHUB_OWNER, GITHUB_REPO, $branch);
}

/**
 * Get combined info: latest revision + download URL
 */
function getGitHubZipInfo(string $branch = 'main'): array {
    $cacheDir = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // Get the latest revision info
    $historyCache = new GitHubRepoHistoryCache(
        GITHUB_TOKEN,
        $cacheDir
    );

    $commits = $historyCache->getHistory(GITHUB_OWNER, GITHUB_REPO, [
        'branch' => $branch,
        'ttl_seconds' => 60,
        'max_commits' => 1,
        'full_details_limit' => 1
    ]);

    // Get the latest commit (first one after sorting)
    $latestSha = null;
    $latestCommit = null;
    $latestTs = 0;

    foreach ($commits as $sha => $commit) {
        $ts = $commit['ts'] ?? 0;
        if ($ts > $latestTs) {
            $latestTs = $ts;
            $latestSha = $sha;
            $latestCommit = $commit;
        }
    }

    // Get the download URL
    $redirector = new GitHubZipRedirector(
        GITHUB_TOKEN,
        'php-panel-github-zip-redirector',
        30,
        $cacheDir,
        60
    );

    $downloadUrl = $redirector->getZipRedirectUrl(GITHUB_OWNER, GITHUB_REPO, $branch);

    return [
        'owner' => GITHUB_OWNER,
        'repo' => GITHUB_REPO,
        'branch' => $branch,
        'download_url' => $downloadUrl,
        'latest_revision' => $latestSha ? [
            'sha' => $latestSha,
            'short_sha' => substr($latestSha, 0, 8),
            'message' => $latestCommit['notes'] ?? '',
            'timestamp' => $latestTs,
            'date' => $latestTs ? date('Y-m-d H:i:s', $latestTs) : null,
            'files' => $latestCommit['files'] ?? [
                'added' => [],
                'removed' => [],
                'modified' => []
            ]
        ] : null
    ];
}
