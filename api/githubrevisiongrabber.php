<?php
declare(strict_types=1);

/**
 * GitHub Repository History Cache
 *
 * Efficiently caches commit history from a GitHub repository.
 * Optimized for incremental updates - only fetches new commits since last check.
 *
 * Usage:
 *   $cache = new GitHubRepoHistoryCache($token, $cacheDir);
 *   $commits = $cache->getHistory($owner, $repo, ['branch' => 'main']);
 */
final class GitHubRepoHistoryCache
{
    private string $token;
    private string $cacheDir;
    private string $userAgent;
    private int $timeoutSeconds;

    public function __construct(
        string $token,
        string $cacheDir,
        string $userAgent = 'php-panel-github-history-cache',
        int $timeoutSeconds = 60
    ) {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('GitHub token is required.');
        }
        $cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        if ($cacheDir === '' || !is_dir($cacheDir) || !is_writable($cacheDir)) {
            throw new InvalidArgumentException("Cache dir must exist and be writable: {$cacheDir}");
        }

        $this->token = $token;
        $this->cacheDir = $cacheDir;
        $this->userAgent = $userAgent;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Returns commit dictionary keyed by SHA:
     * [
     *   "<sha>" => [
     *     "notes" => "...",
     *     "files" => ["added"=>[], "removed"=>[], "modified"=>[]],
     *     "ts" => 1700000000
     *   ],
     *   ...
     * ]
     *
     * Options:
     * - branch: string (default "main")
     * - ttl_seconds: int (default 60) -> minimum time between GitHub checks
     * - max_commits: int (default 500) -> cap cache growth
     * - full_details_limit: int (default 50) -> only fetch file lists for this many recent commits
     */
    public function getHistory(string $owner, string $repo, array $options = []): array
    {
        $owner = trim($owner);
        $repo  = trim($repo);
        if ($owner === '' || $repo === '') {
            throw new InvalidArgumentException('Owner and repo are required.');
        }

        $branch = trim((string)($options['branch'] ?? 'main'));
        if ($branch === '') $branch = 'main';

        $ttl = (int)($options['ttl_seconds'] ?? 60);
        if ($ttl < 0) $ttl = 0;

        $maxCommits = (int)($options['max_commits'] ?? 500);
        if ($maxCommits < 50) $maxCommits = 50;

        // Only fetch full file details for recent commits (reduces API calls significantly)
        $fullDetailsLimit = (int)($options['full_details_limit'] ?? 50);
        if ($fullDetailsLimit < 10) $fullDetailsLimit = 10;

        $cachePath = $this->cachePath($owner, $repo, $branch);
        $lockPath  = $cachePath . '.lock';

        $lockFp = fopen($lockPath, 'c+');
        if ($lockFp === false) {
            throw new RuntimeException("Unable to open lock file: {$lockPath}");
        }

        // Exclusive lock to avoid races under concurrent panel requests
        if (!flock($lockFp, LOCK_EX)) {
            fclose($lockFp);
            throw new RuntimeException("Unable to lock: {$lockPath}");
        }

        try {
            $cache = $this->readCache($cachePath);

            $now = time();
            $lastChecked = (int)($cache['last_checked'] ?? 0);

            // TTL gate: if checked recently, return cache without hitting GitHub
            if ($cache && ($ttl > 0) && ($now - $lastChecked) < $ttl) {
                return $cache['commits'] ?? [];
            }

            // 1) cheap: determine current HEAD sha for branch (1 API call)
            $currentHead = $this->fetchBranchHeadSha($owner, $repo, $branch);

            // no cache yet -> build initial cache
            if (!$cache) {
                $newCache = [
                    'schema' => 2,
                    'owner' => $owner,
                    'repo' => $repo,
                    'branch' => $branch,
                    'head_sha' => $currentHead,
                    'last_checked' => $now,
                    'commits' => [],
                ];

                // Initial fill: grab recent commits efficiently
                $this->appendCommitsEfficient($newCache, $owner, $repo, $branch, $maxCommits, $fullDetailsLimit);
                $this->writeCacheAtomic($cachePath, $newCache);
                return $newCache['commits'];
            }

            $cachedHead = (string)($cache['head_sha'] ?? '');
            $cache['last_checked'] = $now;

            // If head unchanged, just update last_checked and return
            if ($cachedHead !== '' && hash_equals($cachedHead, $currentHead)) {
                $this->writeCacheAtomic($cachePath, $cache);
                return $cache['commits'] ?? [];
            }

            // Head changed -> fetch only new commits until we hit cached head
            $this->appendNewCommitsUntil($cache, $owner, $repo, $branch, $cachedHead, $maxCommits, $fullDetailsLimit);

            // Update head_sha to current
            $cache['head_sha'] = $currentHead;

            // Enforce max_commits cap
            $this->pruneCache($cache, $maxCommits);

            $this->writeCacheAtomic($cachePath, $cache);
            return $cache['commits'] ?? [];
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Force a full cache rebuild. Use during installation.
     * Returns the number of commits cached.
     */
    public function buildInitialCache(string $owner, string $repo, array $options = []): int
    {
        $owner = trim($owner);
        $repo  = trim($repo);
        if ($owner === '' || $repo === '') {
            throw new InvalidArgumentException('Owner and repo are required.');
        }

        $branch = trim((string)($options['branch'] ?? 'main'));
        if ($branch === '') $branch = 'main';

        $maxCommits = (int)($options['max_commits'] ?? 500);
        if ($maxCommits < 50) $maxCommits = 50;

        $fullDetailsLimit = (int)($options['full_details_limit'] ?? 50);
        if ($fullDetailsLimit < 10) $fullDetailsLimit = 10;

        $cachePath = $this->cachePath($owner, $repo, $branch);
        $lockPath  = $cachePath . '.lock';

        $lockFp = fopen($lockPath, 'c+');
        if ($lockFp === false) {
            throw new RuntimeException("Unable to open lock file: {$lockPath}");
        }

        if (!flock($lockFp, LOCK_EX)) {
            fclose($lockFp);
            throw new RuntimeException("Unable to lock: {$lockPath}");
        }

        try {
            $currentHead = $this->fetchBranchHeadSha($owner, $repo, $branch);

            $newCache = [
                'schema' => 2,
                'owner' => $owner,
                'repo' => $repo,
                'branch' => $branch,
                'head_sha' => $currentHead,
                'last_checked' => time(),
                'commits' => [],
            ];

            $this->appendCommitsEfficient($newCache, $owner, $repo, $branch, $maxCommits, $fullDetailsLimit);
            $this->writeCacheAtomic($cachePath, $newCache);

            return count($newCache['commits']);
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Check if cache exists and is valid
     */
    public function hasCachedData(string $owner, string $repo, string $branch = 'main'): bool
    {
        $cachePath = $this->cachePath($owner, $repo, $branch);
        $cache = $this->readCache($cachePath);
        return $cache !== null && !empty($cache['commits']);
    }

    private function cachePath(string $owner, string $repo, string $branch): string
    {
        $key = strtolower($owner . '__' . $repo . '__' . $branch);
        $key = preg_replace('/[^a-z0-9_.-]+/', '_', $key);
        return $this->cacheDir . DIRECTORY_SEPARATOR . "gh_history_{$key}.json";
    }

    private function readCache(string $path): ?array
    {
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') return null;

        $data = json_decode($raw, true);
        if (!is_array($data)) return null;

        if (!isset($data['commits']) || !is_array($data['commits'])) {
            $data['commits'] = [];
        }
        return $data;
    }

    private function writeCacheAtomic(string $path, array $data): void
    {
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('json_encode failed for cache');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Failed writing temp cache: {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed renaming cache temp to final: {$path}");
        }
    }

    private function pruneCache(array &$cache, int $maxCommits): void
    {
        $commits = $cache['commits'] ?? [];
        if (!is_array($commits) || count($commits) <= $maxCommits) return;

        // Sort SHAs by ts descending (newest first), keep top $maxCommits
        $items = [];
        foreach ($commits as $sha => $info) {
            $ts = is_array($info) ? (int)($info['ts'] ?? 0) : 0;
            $items[] = [$sha, $ts];
        }

        usort($items, function($a, $b) {
            return $b[1] <=> $a[1];
        });

        $keep = array_slice($items, 0, $maxCommits);
        $new = [];
        foreach ($keep as [$sha, $_ts]) {
            $new[$sha] = $commits[$sha];
        }
        $cache['commits'] = $new;
    }

    /**
     * Efficient initial fill using commits list endpoint.
     * - Uses message from list response (no extra API call needed)
     * - Only fetches file details for the most recent commits
     */
    private function appendCommitsEfficient(array &$cache, string $owner, string $repo, string $branch, int $maxCommits, int $fullDetailsLimit): void
    {
        $url = $this->commitsListUrl($owner, $repo, [
            'sha' => $branch,
            'per_page' => '100',
        ]);

        $commitCount = 0;

        while ($url !== '' && $commitCount < $maxCommits) {
            [$rows, $headers] = $this->ghGetJson($url);
            if (!is_array($rows)) break;

            foreach ($rows as $item) {
                if ($commitCount >= $maxCommits) break;
                if (!is_array($item)) continue;

                $sha = (string)($item['sha'] ?? '');
                if ($sha === '' || isset($cache['commits'][$sha])) continue;

                // Extract data available from list endpoint (no extra API call)
                $message = (string)($item['commit']['message'] ?? '');
                $date = (string)($item['commit']['author']['date'] ?? '');
                $ts = 0;
                if ($date !== '') {
                    $t = strtotime($date);
                    if ($t !== false) $ts = (int)$t;
                }
                if ($ts <= 0) $ts = time();

                // Only fetch full details (file lists) for recent commits
                if ($commitCount < $fullDetailsLimit) {
                    // Fetch file details for this commit
                    $files = $this->fetchCommitFiles($owner, $repo, $sha);
                } else {
                    // For older commits, skip file details to save API calls
                    $files = ['added' => [], 'removed' => [], 'modified' => []];
                }

                $cache['commits'][$sha] = [
                    'notes' => $message,
                    'files' => $files,
                    'ts' => $ts,
                ];

                $commitCount++;
            }

            $url = $this->parseLinkNext($headers) ?? '';
        }
    }

    /**
     * Incremental update: fetch commits from HEAD backwards until we see stopSha.
     * Much more efficient than initial fill since it only fetches new commits.
     */
    private function appendNewCommitsUntil(array &$cache, string $owner, string $repo, string $branch, string $stopSha, int $maxCommits, int $fullDetailsLimit): void
    {
        $url = $this->commitsListUrl($owner, $repo, [
            'sha' => $branch,
            'per_page' => '100',
        ]);

        $newCommitCount = 0;

        while ($url !== '' && count($cache['commits']) < $maxCommits) {
            [$rows, $headers] = $this->ghGetJson($url);
            if (!is_array($rows)) break;

            $hitStop = false;

            foreach ($rows as $item) {
                if (!is_array($item)) continue;
                $sha = (string)($item['sha'] ?? '');
                if ($sha === '') continue;

                // Stop when we hit the previously cached HEAD
                if ($stopSha !== '' && hash_equals($sha, $stopSha)) {
                    $hitStop = true;
                    break;
                }

                // Skip if already cached
                if (isset($cache['commits'][$sha])) continue;

                // Extract data from list endpoint
                $message = (string)($item['commit']['message'] ?? '');
                $date = (string)($item['commit']['author']['date'] ?? '');
                $ts = 0;
                if ($date !== '') {
                    $t = strtotime($date);
                    if ($t !== false) $ts = (int)$t;
                }
                if ($ts <= 0) $ts = time();

                // Fetch file details for new commits (they're recent, so worth having)
                // But limit to avoid too many API calls if there's a big gap
                if ($newCommitCount < $fullDetailsLimit) {
                    $files = $this->fetchCommitFiles($owner, $repo, $sha);
                } else {
                    $files = ['added' => [], 'removed' => [], 'modified' => []];
                }

                $cache['commits'][$sha] = [
                    'notes' => $message,
                    'files' => $files,
                    'ts' => $ts,
                ];

                $newCommitCount++;
            }

            if ($hitStop) break;

            $url = $this->parseLinkNext($headers) ?? '';
        }
    }

    private function fetchBranchHeadSha(string $owner, string $repo, string $branch): string
    {
        $url = $this->commitsListUrl($owner, $repo, [
            'sha' => $branch,
            'per_page' => '1',
        ]);

        [$rows, $_headers] = $this->ghGetJson($url);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            throw new RuntimeException('Failed to fetch branch HEAD SHA');
        }

        $sha = (string)($rows[0]['sha'] ?? '');
        if ($sha === '') {
            throw new RuntimeException('Branch HEAD SHA missing in response');
        }
        return $sha;
    }

    /**
     * Fetch only the file changes for a commit (optimized - smaller response than full detail)
     */
    private function fetchCommitFiles(string $owner, string $repo, string $sha): array
    {
        $detailUrl = "https://api.github.com/repos/" . rawurlencode($owner) . "/" . rawurlencode($repo) . "/commits/" . rawurlencode($sha);

        try {
            [$detail, $_headers] = $this->ghGetJson($detailUrl);
        } catch (RuntimeException $e) {
            // If we can't fetch details, return empty files rather than failing
            return ['added' => [], 'removed' => [], 'modified' => []];
        }

        if (!is_array($detail)) {
            return ['added' => [], 'removed' => [], 'modified' => []];
        }

        $files = $detail['files'] ?? [];
        if (!is_array($files)) $files = [];

        $added = [];
        $removed = [];
        $modified = [];

        foreach ($files as $f) {
            if (!is_array($f)) continue;
            $filename = (string)($f['filename'] ?? '');
            $status   = (string)($f['status'] ?? '');
            if ($filename === '' || $status === '') continue;

            if ($status === 'added') {
                $added[] = $filename;
            } elseif ($status === 'removed') {
                $removed[] = $filename;
            } else {
                $modified[] = $filename;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
        ];
    }

    private function commitsListUrl(string $owner, string $repo, array $params): string
    {
        $base = "https://api.github.com/repos/" . rawurlencode($owner) . "/" . rawurlencode($repo) . "/commits";
        return $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Returns [decodedJson, headersArray]
     */
    private function ghGetJson(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        $headersOut = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headersOut) {
                $headersOut[] = rtrim($line, "\r\n");
                return strlen($line);
            },
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $this->token,
                'User-Agent: ' . $this->userAgent,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("curl_exec failed: {$err}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            $preview = substr((string)$body, 0, 700);
            throw new RuntimeException("GitHub HTTP {$status}. Body preview: {$preview}");
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            $preview = substr((string)$body, 0, 700);
            throw new RuntimeException("Invalid JSON from GitHub. Body preview: {$preview}");
        }

        return [$json, $headersOut];
    }

    private function parseLinkNext(array $headers): ?string
    {
        foreach ($headers as $h) {
            if (stripos($h, 'Link:') === 0) {
                $value = trim(substr($h, 5));
                foreach (explode(',', $value) as $part) {
                    $part = trim($part);
                    if (preg_match('/<([^>]+)>\s*;\s*rel="next"/i', $part, $m)) {
                        return $m[1];
                    }
                }
            }
        }
        return null;
    }
}
