<?php
/**
 * Helper functions
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/test_keys.php';

// Get status color
function getStatusColor($status) {
    return STATUS_COLORS[$status] ?? STATUS_COLORS[''];
}

// Get status badge HTML
function getStatusBadge($status) {
    $color = getStatusColor($status);
    $text = $status ?: 'N/A';
    return "<span class=\"status-badge\" style=\"background-color: {$color}\">{$text}</span>";
}

// Format date
function formatDate($datetime) {
    if (!$datetime) return 'N/A';
    $date = new DateTime($datetime);
    return $date->format('M j, Y g:i A');
}

// Format relative time
function formatRelativeTime($datetime) {
    if (!$datetime) return 'N/A';
    $date = new DateTime($datetime);
    $now = new DateTime();
    $diff = $now->diff($date);

    if ($diff->d == 0) {
        if ($diff->h == 0) {
            if ($diff->i == 0) {
                return 'Just now';
            }
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        }
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->d < 7) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    } else {
        return $date->format('M j, Y');
    }
}

// Sanitize output
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Convert markdown code blocks (```code```) to HTML <pre><code> tags.
 *
 * @param string $text Text containing markdown code blocks
 * @return string Text with code blocks converted to HTML
 */
function convertMarkdownCodeBlocksToHtml($text) {
    // Match ```language\ncode\n``` or ```code``` patterns
    // Language is optional, handles \r\n line endings
    $pattern = '/```(\w*)[\r\n]*([\s\S]*?)```/';

    return preg_replace_callback($pattern, function($match) {
        $lang = $match[1] ?? '';
        $code = $match[2] ?? '';

        // Skip if the content is empty or just whitespace
        if (trim($code) === '') {
            return $match[0];
        }

        // Trim leading/trailing newlines from code content
        $code = trim($code, "\r\n");

        // Escape HTML entities in the code
        $code = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

        // Build HTML - use data-language attribute for optional language
        $langAttr = $lang ? ' data-language="' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"' : '';

        return '<pre class="code-block"' . $langAttr . '><code>' . $code . '</code></pre>';
    }, $text);
}

// Clean notes while preserving markdown-style formatting
// Handles Qt rich text HTML conversion to plain text/markdown
// Converts markdown code blocks (```) to HTML <pre><code> tags
function cleanNotes($notes) {
    if (empty($notes)) return '';

    // Repair corrupted image markers from the old \bimage\b filter
    $notes = preg_replace_callback('/\{\{:data:\/([^}]+)\}\}/', function($m) {
        return '{{IMAGE:data:image/' . $m[1] . '}}';
    }, $notes);

    // Check if this already looks like markdown (not HTML)
    // Convert markdown code blocks to HTML before further processing
    $hasMarkdownCodeBlocks = preg_match('/```[\s\S]*?```/', $notes);
    $hasMarkdownImages = preg_match('/!\[[^\]]*\]\([^)]+\)/', $notes);
    $hasImageMarkers = preg_match('/\[image:data:image\//', $notes) || preg_match('/\{\{IMAGE:data:image\//', $notes);

    if ($hasMarkdownCodeBlocks || $hasMarkdownImages || $hasImageMarkers) {
        // Clean up Qt CSS first
        $text = str_replace('p, li { white-space: pre-wrap; }', '', $notes);

        // Convert markdown code blocks (```) to HTML <pre><code> tags
        if ($hasMarkdownCodeBlocks) {
            $text = convertMarkdownCodeBlocksToHtml($text);
        }

        return trim($text);
    }

    // Replace embedded images IN-PLACE with {{IMAGE:...}} markers to preserve position
    // Qt sends images as: <a href="data:image/png;base64,..."><img src="..."/></a>
    // We deduplicate so only the first occurrence of each image is kept
    $seenImages = [];

    // Match anchor-wrapped images: <a href="data:image/...">...<img .../>...</a>
    $notes = preg_replace_callback(
        '/<a\s+[^>]*href=["\']?(data:image\/[^"\'>\s]+)["\']?[^>]*>[\s\S]*?<\/a>/i',
        function($match) use (&$seenImages) {
            $dataUri = $match[1];
            if (in_array($dataUri, $seenImages)) {
                return '';  // duplicate, remove
            }
            $seenImages[] = $dataUri;
            return '{{IMAGE:' . $dataUri . '}}';
        },
        $notes
    );

    // Match standalone img tags with data URIs (not already captured by anchor replacement)
    $notes = preg_replace_callback(
        '/<img\s+[^>]*src=["\']?(data:image\/[^"\'>\s]+)["\']?[^>]*\/?>/i',
        function($match) use (&$seenImages) {
            $dataUri = $match[1];
            if (in_array($dataUri, $seenImages)) {
                return '';  // duplicate (already captured from anchor)
            }
            $seenImages[] = $dataUri;
            return '{{IMAGE:' . $dataUri . '}}';
        },
        $notes
    );

    // Preserve <pre><code> blocks - clean them up and add proper class for styling
    // Strip syntax highlighting spans from inside but keep the pre/code structure
    $codeBlocks = [];
    $notes = preg_replace_callback(
        '/<pre[^>]*>\s*<code[^>]*>([\s\S]*?)<\/code>\s*<\/pre>/i',
        function($match) use (&$codeBlocks) {
            // Extract code content from <pre><code> block
            $codeHtml = $match[1];
            // Strip inner HTML tags (like syntax highlighting spans)
            $codeText = strip_tags($codeHtml);
            // Decode HTML entities
            $codeText = html_entity_decode($codeText, ENT_QUOTES, 'UTF-8');
            // Re-escape for safe HTML
            $codeText = htmlspecialchars($codeText, ENT_QUOTES, 'UTF-8');
            // Store placeholder and the clean code block with proper class
            $placeholder = "__CODE_BLOCK_" . count($codeBlocks) . "__";
            $codeBlocks[] = '<pre class="code-block"><code>' . $codeText . '</code></pre>';
            return $placeholder;
        },
        $notes
    );

    // Convert structural HTML elements to newlines before stripping tags
    // This preserves paragraph breaks, line breaks, and content ordering
    $notes = preg_replace('/<br\s*\/?>/i', "\n", $notes);
    $notes = preg_replace('/<\/p>/i', "\n", $notes);
    $notes = preg_replace('/<\/div>/i', "\n", $notes);
    // Handle Qt rich text HTML - convert to plain text
    $text = strip_tags($notes);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    // Remove Qt rich text CSS that leaks through
    $text = str_replace('p, li { white-space: pre-wrap; }', '', $text);
    $text = trim($text);

    // Restore code blocks with proper HTML
    foreach ($codeBlocks as $i => $block) {
        $placeholder = "__CODE_BLOCK_{$i}__";
        $text = str_replace($placeholder, $block, $text);
    }

    // Clean up multiple newlines that may result from replacements
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = trim($text);

    return $text;
}

// Truncate text
function truncate($text, $length = 100) {
    if (strlen($text) <= $length) return $text;
    return substr($text, 0, $length) . '...';
}

// Format duration from seconds to human readable
function formatDuration($seconds) {
    if ($seconds === null || $seconds === 0) return '-';

    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
    } elseif ($minutes > 0) {
        return sprintf('%dm %ds', $minutes, $secs);
    } else {
        return sprintf('%ds', $secs);
    }
}

// Calculate percentage
function percent($part, $total) {
    if ($total == 0) return 0;
    return round(($part / $total) * 100, 1);
}

// Extract short version name
function shortVersionName($fullVersion) {
    // Extract date and descriptive part
    if (preg_match('/secondblob\.bin\.(\d{4}-\d{2}-\d{2}).*?(?:-\s*(.+))?$/', $fullVersion, $matches)) {
        $date = $matches[1];
        $desc = isset($matches[2]) ? ' - ' . trim($matches[2]) : '';
        return $date . $desc;
    }
    return $fullVersion;
}

// Clean client version string by removing any trailing commit hash
// Commit hashes are 7-40 character hexadecimal strings
function cleanClientVersion($version) {
    if (empty($version)) return '';

    // Remove trailing commit hash patterns:
    // - " abc123def" (space followed by 7+ hex chars)
    // - " [abc123def]" (bracketed hash)
    // - " (abc123def)" (parenthesized hash)
    // - "_abc123def" (underscore followed by hash)
    $cleaned = preg_replace('/[\s_]+[(\[]?[0-9a-fA-F]{7,40}[)\]]?\s*$/', '', $version);

    return trim($cleaned);
}

// Convert {{IMAGE:data:image/...;base64,...}} markers to inline HTML images for editing
// Images are wrapped in a span with contenteditable=false so they can be selected and deleted as a unit
function convertImageMarkersToHtml($text) {
    if (empty($text)) return '';

    // Escape HTML first to prevent XSS, but we'll handle our image markers specially
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Convert escaped image markers back to actual images
    // Pattern matches: {{IMAGE:data:image/TYPE;base64,DATA}}
    // After escaping, this becomes: {{IMAGE:data:image/TYPE;base64,DATA}}
    $pattern = '/\{\{IMAGE:(data:image\/[a-zA-Z]+;base64,[A-Za-z0-9+\/=]+)\}\}/';

    $result = preg_replace_callback($pattern, function($matches) {
        $dataUri = $matches[1];
        // Create an inline image that can be selected and deleted
        // Using a wrapper span with contenteditable=false makes it behave as a single unit
        return '<span class="inline-image-wrapper" contenteditable="false" data-image-marker="{{IMAGE:' . htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8') . '}}"><img src="' . htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8') . '" class="inline-note-image" alt="Embedded image"></span>';
    }, $escaped);

    // Convert newlines to <br> for proper display
    $result = nl2br($result);

    return $result;
}

// Convert HTML content with inline images back to text with {{IMAGE:...}} markers
// Used when saving contenteditable content back to the database
function convertHtmlToImageMarkers($html) {
    if (empty($html)) return '';

    // First, convert <br> tags back to newlines
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);

    // Replace inline image wrappers with their original markers
    // Pattern: <span class="inline-image-wrapper"...data-image-marker="{{IMAGE:...}}">...</span>
    $text = preg_replace_callback('/<span[^>]*data-image-marker="([^"]+)"[^>]*>.*?<\/span>/s', function($matches) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }, $text);

    // Also handle case where just <img> tags with data URIs exist (from paste/drop)
    $text = preg_replace_callback('/<img[^>]*src="(data:image\/[^"]+)"[^>]*>/i', function($matches) {
        return '{{IMAGE:' . $matches[1] . '}}';
    }, $text);

    // Strip any remaining HTML tags
    $text = strip_tags($text);

    // Decode HTML entities
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    // Normalize multiple newlines
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

// Parse the raw JSON from test tool - returns ALL versions as an array of reports
// Handles session_results.json format from the PyQt test tool
function parseTestToolJson($data) {
    // If passed a string, decode it first
    if (is_string($data)) {
        $data = json_decode($data, true);
    }
    if (!$data || !is_array($data)) return null;

    // Extract metadata
    $meta = $data['meta'] ?? [];

    // Determine test type from WAN/LAN flags
    $testType = '';
    if (!empty($meta['WAN']) && !empty($meta['LAN'])) {
        $testType = 'WAN/LAN';
    } elseif (!empty($meta['WAN'])) {
        $testType = 'WAN';
    } elseif (!empty($meta['LAN'])) {
        $testType = 'LAN';
    }

    // Get results - this is keyed by version ID
    $rawResults = $data['results'] ?? [];

    // If no results, return null
    if (empty($rawResults)) {
        return null;
    }

    // Get timing data
    $timing = $data['timing'] ?? [];

    // Get attached logs (keyed by version ID)
    $attachedLogs = $data['attached_logs'] ?? [];

    // Get per-version package info (keyed by version ID)
    // This maps version IDs to their Steam/SteamUI package versions
    $versionPackages = $data['version_packages'] ?? [];

    // Get per-version commit hashes (keyed by version ID)
    // This allows different versions to have been tested against different commits
    $versionCommits = $data['version_commits'] ?? [];

    // Parse ALL versions from results
    $allReports = [];
    foreach ($rawResults as $clientVersion => $testResults) {
        // Flatten results into expected format
        // Notes are stored with HTML intact - strip only when outputting to API
        $flatResults = [];
        foreach ($testResults as $testKey => $testData) {
            $flatResults[$testKey] = [
                'status' => $testData['status'] ?? '',
                'notes' => $testData['notes'] ?? ''
            ];
        }

        // Extract test duration from timing data (stored in seconds)
        // Timing is keyed by version ID, so get the timing for this version
        $testDuration = null;
        if (!empty($timing) && isset($timing[$clientVersion])) {
            // Get timing for this specific version
            $versionTiming = $timing[$clientVersion];
            if (is_numeric($versionTiming)) {
                $testDuration = (int)$versionTiming;
            } elseif (is_array($versionTiming) && isset($versionTiming['total'])) {
                $testDuration = (int)$versionTiming['total'];
            } elseif (is_array($versionTiming) && isset($versionTiming['duration'])) {
                $testDuration = (int)$versionTiming['duration'];
            }
        }

        // Get attached logs for this version
        $versionLogs = $attachedLogs[$clientVersion] ?? [];

        // Get per-version package versions (preferred), fall back to global meta
        $versionPkgInfo = $versionPackages[$clientVersion] ?? [];
        $steamuiVersion = $versionPkgInfo['steamui_version'] ?? $meta['steamui_version'] ?? null;
        $steamPkgVersion = $versionPkgInfo['steam_pkg_version'] ?? $meta['steam_pkg_version'] ?? null;

        // Strip prefixes like 'steam_' or 'steamui_' and extract only numeric part
        if ($steamuiVersion) {
            $steamuiVersion = preg_replace('/^(steamui_?|steam_?)/i', '', $steamuiVersion);
            $steamuiVersion = preg_replace('/[^0-9]/', '', $steamuiVersion);
            $steamuiVersion = $steamuiVersion ?: null;
        }
        if ($steamPkgVersion) {
            $steamPkgVersion = preg_replace('/^(steam_?|steamui_?)/i', '', $steamPkgVersion);
            $steamPkgVersion = preg_replace('/[^0-9]/', '', $steamPkgVersion);
            $steamPkgVersion = $steamPkgVersion ?: null;
        }

        // Use per-version commit if available, fall back to global meta commit
        $commitHash = $versionCommits[$clientVersion] ?? $meta['commit'] ?? '';

        $allReports[] = [
            'tester' => $meta['tester'] ?? 'Unknown',
            'commit_hash' => $commitHash,
            'test_type' => $testType,
            'client_version' => $clientVersion,
            'steamui_version' => $steamuiVersion,
            'steam_pkg_version' => $steamPkgVersion,
            'results' => $flatResults,
            'test_duration' => $testDuration,
            'attached_logs' => $versionLogs,
            // Also preserve raw data for reference
            'raw_meta' => $meta,
            'raw_timing' => $timing,
            'raw_completed' => $data['completed'] ?? []
        ];
    }

    return $allReports;
}

// Generate API key
function generateApiKey() {
    return 'sk_' . bin2hex(random_bytes(24));
}

// JSON response helper
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Flash message helpers
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Render flash message
function renderFlash() {
    $flash = getFlash();
    if (!$flash) return '';

    $type = $flash['type'];
    $message = e($flash['message']);

    return "<div class=\"flash-message {$type}\">{$message}</div>";
}

// Get current page
function getCurrentPage() {
    return $_GET['page'] ?? 'dashboard';
}

// Get site title from settings (with fallback to PANEL_NAME constant)
function getSiteTitle() {
    static $siteTitle = null;
    if ($siteTitle === null) {
        try {
            $db = Database::getInstance();
            $siteTitle = $db->getSiteTitle();
        } catch (Exception $e) {
            $siteTitle = PANEL_NAME;
        }
    }
    return $siteTitle;
}

// Check if site is in private mode
function isSitePrivate() {
    static $isPrivate = null;
    if ($isPrivate === null) {
        try {
            $db = Database::getInstance();
            $isPrivate = $db->isSitePrivate();
        } catch (Exception $e) {
            $isPrivate = false;
        }
    }
    return $isPrivate;
}

// Enforce private mode - redirects to login if site is private and user not logged in
function enforcePrivateMode() {
    if (isSitePrivate() && !isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

// Get base URL for the application
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    // Clean up the path
    $scriptDir = rtrim($scriptDir, '/\\');
    return $protocol . '://' . $host . $scriptDir;
}

// Build URL with params
function buildUrl($page, $params = []) {
    $url = "?page={$page}";
    foreach ($params as $key => $value) {
        $url .= "&{$key}=" . urlencode($value);
    }
    return $url;
}

/**
 * Recursively sort array keys for consistent JSON output
 * This matches Python's json.dumps(sort_keys=True) behavior
 */
function sortKeysRecursive($data) {
    if (!is_array($data)) {
        return $data;
    }

    // Check if this is an associative array (dict) or sequential array (list)
    $isAssoc = array_keys($data) !== range(0, count($data) - 1);

    if ($isAssoc) {
        // Sort associative arrays by key
        ksort($data, SORT_STRING);
    }

    // Recursively sort child arrays
    foreach ($data as $key => $value) {
        $data[$key] = sortKeysRecursive($value);
    }

    return $data;
}

/**
 * Compute a content hash for report data.
 * This MUST match Python's compute_version_hash() function exactly.
 *
 * Python implementation:
 *   data_to_hash = {'results': results_data, 'logs': attached_logs or []}
 *   json_str = json.dumps(data_to_hash, sort_keys=True, ensure_ascii=True)
 *   return hashlib.sha256(json_str.encode('utf-8')).hexdigest()
 *
 * @param array $resultsData The test results data (keyed by test key)
 * @param array $attachedLogs Optional array of attached log entries
 * @return string SHA256 hash hex string
 */
function computeReportContentHash(array $resultsData, array $attachedLogs = []): string {
    // Normalize notes to canonical format before hashing.
    // This strips images and converts code blocks to markdown,
    // producing identical output regardless of Python/PHP HTML differences.
    $normalizedResults = [];
    foreach ($resultsData as $testKey => $testData) {
        $normalizedResults[$testKey] = [
            'status' => $testData['status'] ?? '',
            'notes' => normalizeNotesForHash($testData['notes'] ?? '')
        ];
    }

    // Create the same structure as Python
    $dataToHash = [
        'logs' => $attachedLogs,
        'results' => $normalizedResults
    ];

    // Sort keys recursively to match Python's sort_keys=True
    $dataToHash = sortKeysRecursive($dataToHash);

    // Encode to JSON with settings to match Python's ensure_ascii=True
    // JSON_UNESCAPED_SLASHES matches Python default, but we need ASCII escaping
    // PHP's default json_encode already escapes non-ASCII to \uXXXX format
    $jsonStr = json_encode($dataToHash, JSON_UNESCAPED_SLASHES);

    // Compute SHA256 hash
    return hash('sha256', $jsonStr);
}

/**
 * Normalize notes to a canonical format for hash comparison.
 *
 * Strips all image data and converts code blocks to markdown so that
 * Python and PHP produce identical output regardless of HTML formatting
 * differences. This MUST match Python's normalize_notes_for_hash() exactly.
 *
 * @param string $notes Cleaned notes (output of cleanNotes())
 * @return string Canonical plain text with markdown code blocks, no images
 */
function normalizeNotesForHash(string $notes): string {
    if (empty($notes)) return '';

    // Remove all image markers and image data
    $text = preg_replace('/\{\{IMAGE:[^}]*\}\}/', '', $notes);
    // Also remove any corrupted markers from old \bimage\b filter
    $text = preg_replace('/\{\{:data:\/[^}]*\}\}/', '', $text);
    // Remove any remaining HTML image/anchor tags with data URIs
    $text = preg_replace('/<a\s+[^>]*href=["\']?data:image\/[^>]*>[\s\S]*?<\/a>/i', '', $text);
    $text = preg_replace('/<img\s+[^>]*src=["\']?data:image\/[^>]*\/?>/i', '', $text);

    // Convert HTML code blocks to markdown
    $text = preg_replace_callback(
        '/<pre[^>]*>\s*<code[^>]*>([\s\S]*?)<\/code>\s*<\/pre>/i',
        function($match) {
            // Decode HTML entities to get raw code text
            $code = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
            return "```\n" . $code . "\n```";
        },
        $text
    );

    // Strip any remaining HTML tags
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    // Normalize whitespace
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

// Pagination helper
function getPagination($currentPage, $totalPages, $basePage) {
    $html = '<div class="pagination">';

    if ($currentPage > 1) {
        $html .= '<a href="' . buildUrl($basePage, ['p' => $currentPage - 1]) . '" class="btn btn-sm">Prev</a>';
    }

    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= '<a href="' . buildUrl($basePage, ['p' => $i]) . '" class="btn btn-sm' . $active . '">' . $i . '</a>';
    }

    if ($currentPage < $totalPages) {
        $html .= '<a href="' . buildUrl($basePage, ['p' => $currentPage + 1]) . '" class="btn btn-sm">Next</a>';
    }

    $html .= '</div>';
    return $html;
}

// Count status totals from results
function countStatusTotals($results) {
    $totals = [
        'Working' => 0,
        'Semi-working' => 0,
        'Not working' => 0,
        'N/A' => 0
    ];

    foreach ($results as $version => $tests) {
        foreach ($tests as $testKey => $testData) {
            $status = $testData['status'] ?? '';
            if (isset($totals[$status])) {
                $totals[$status]++;
            }
        }
    }

    return $totals;
}

/**
 * Check if GitHub integration is configured
 */
function isGitHubConfigured() {
    return defined('GITHUB_OWNER') && defined('GITHUB_REPO') && defined('GITHUB_TOKEN')
        && GITHUB_OWNER !== '' && GITHUB_REPO !== '' && GITHUB_TOKEN !== '';
}

/**
 * Get GitHub revision history from cache
 * Returns array of commits keyed by SHA with structure:
 * [
 *   "<sha>" => [
 *     "notes" => "commit message",
 *     "files" => ["added"=>[], "removed"=>[], "modified"=>[]],
 *     "ts" => unix_timestamp
 *   ],
 *   ...
 * ]
 * Ordered by timestamp (newest first)
 */
function getGitHubRevisions($forceRefresh = false) {
    if (!isGitHubConfigured()) {
        return [];
    }

    require_once __DIR__ . '/../api/githubrevisiongrabber.php';

    $cacheDir = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    try {
        $cache = new GitHubRepoHistoryCache(
            GITHUB_TOKEN,
            $cacheDir
        );

        $ttl = $forceRefresh ? 0 : 60; // 0 TTL forces refresh
        $commits = $cache->getHistory(GITHUB_OWNER, GITHUB_REPO, [
            'branch' => 'main',
            'ttl_seconds' => $ttl,
            'max_commits' => 5000
        ]);

        // Sort by timestamp (newest first)
        uasort($commits, function($a, $b) {
            return ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0);
        });

        return $commits;
    } catch (Exception $e) {
        error_log("GitHub revision fetch error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get a single revision's details by SHA
 */
function getRevisionBySha($sha) {
    $revisions = getGitHubRevisions();
    return $revisions[$sha] ?? null;
}

/**
 * Format revision timestamp to readable date/time
 */
function formatRevisionDateTime($timestamp) {
    if (!$timestamp) return 'Unknown';
    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Get revisions as dropdown options array
 * Returns array of ['hash' => sha, 'label' => 'sha - date time', 'ts' => timestamp]
 */
function getRevisionDropdownOptions() {
    $revisions = getGitHubRevisions();
    $options = [];

    foreach ($revisions as $sha => $data) {
        $dateTime = formatRevisionDateTime($data['ts'] ?? 0);
        $shortSha = substr($sha, 0, 8);
        $options[] = [
            'hash' => $sha,
            'label' => $shortSha . ' - ' . $dateTime,
            'ts' => $data['ts'] ?? 0
        ];
    }

    return $options;
}

/**
 * Get latest GitHub revision info for the floating box
 * Returns array with latest revision and download info, or null if not configured
 */
function getLatestGitHubRevisionInfo() {
    if (!isGitHubConfigured()) {
        return null;
    }

    $revisions = getGitHubRevisions();
    if (empty($revisions)) {
        return null;
    }

    // Get the first (latest) revision
    $latestSha = array_key_first($revisions);
    $latestCommit = $revisions[$latestSha];

    return [
        'sha' => $latestSha,
        'short_sha' => substr($latestSha, 0, 8),
        'message' => $latestCommit['notes'] ?? '',
        'timestamp' => $latestCommit['ts'] ?? 0,
        'date' => isset($latestCommit['ts']) ? date('Y-m-d H:i:s', $latestCommit['ts']) : null,
        'files' => $latestCommit['files'] ?? [
            'added' => [],
            'removed' => [],
            'modified' => []
        ],
        'owner' => GITHUB_OWNER,
        'repo' => GITHUB_REPO
    ];
}

/**
 * Check if current page should show the GitHub floating box
 * Returns false for admin and profile pages
 */
function shouldShowGitHubFloatingBox() {
    $currentPage = getCurrentPage();

    // List of pages where the floating box should NOT be shown
    $excludedPages = [
        'admin',
        'admin_users',
        'admin_reports',
        'admin_retests',
        'admin_tests',
        'admin_templates',
        'admin_categories',
        'admin_test_types',
        'admin_tags',
        'admin_versions',
        'admin_version_notifications',
        'admin_invites',
        'admin_settings',
        'profile'
    ];

    return !in_array($currentPage, $excludedPages);
}
