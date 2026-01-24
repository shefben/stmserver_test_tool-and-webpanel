<?php
declare(strict_types=1);

final class GitHubZipRedirector
{
    private string $token;
    private string $userAgent;
    private int $timeoutSeconds;

    // Optional cache of the redirect URL for a short time (avoid hammering GitHub on every click).
    private ?string $cacheDir;
    private int $cacheTtlSeconds;

    public function __construct(
        string $token,
        string $userAgent = 'php-panel-github-zip-redirector',
        int $timeoutSeconds = 30,
        ?string $cacheDir = null,
        int $cacheTtlSeconds = 60
    ) {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('GitHub token is required.');
        }
        $this->token = $token;
        $this->userAgent = $userAgent;
        $this->timeoutSeconds = $timeoutSeconds;

        if ($cacheDir !== null) {
            $cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
            if ($cacheDir === '' || !is_dir($cacheDir) || !is_writable($cacheDir)) {
                throw new InvalidArgumentException("Cache dir must exist and be writable: {$cacheDir}");
            }
        }
        $this->cacheDir = $cacheDir;
        $this->cacheTtlSeconds = max(0, $cacheTtlSeconds);
    }

    /**
     * Return a direct download URL (codeload redirect target) for a repo zip.
     * - $ref can be branch name, tag, or SHA. Use "master" if that's what your repo actually uses.
     * - If $ref is null/empty, GitHub uses default branch.
     */
    public function getZipRedirectUrl(string $owner, string $repo, ?string $ref = null): string
    {
        $owner = trim($owner);
        $repo  = trim($repo);
        $ref   = $ref !== null ? trim($ref) : '';

        if ($owner === '' || $repo === '') {
            throw new InvalidArgumentException('Owner and repo are required.');
        }

        // Small cache: (owner/repo/ref) -> redirect URL for N seconds
        $cacheKey = $this->cacheKey($owner, $repo, $ref);
        if ($cacheKey !== null) {
            $cached = $this->cacheRead($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // GitHub zipball endpoint (will 302 to codeload)
        $url = "https://api.github.com/repos/" . rawurlencode($owner) . "/" . rawurlencode($repo) . "/zipball";
        if ($ref !== '') {
            // GitHub supports /zipball/{ref}
            $url .= "/" . rawurlencode($ref);
        }

        $location = $this->fetchZipballLocation($url);

        if ($cacheKey !== null) {
            $this->cacheWrite($cacheKey, $location);
        }

        return $location;
    }

    /**
     * Convenience: issue the HTTP redirect to the user.
     * Call this ONLY after your panel auth check.
     */
    public function redirectToZip(string $owner, string $repo, ?string $ref = null): void
    {
        $location = $this->getZipRedirectUrl($owner, $repo, $ref);

        // Use 302 or 303. 302 is fine for GET downloads.
        header("Location: {$location}", true, 302);
        // Helps some proxies/browsers behave.
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        exit;
    }

    private function fetchZipballLocation(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        $headersOut = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // IMPORTANT: we want the Location header
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headersOut) {
                $headersOut[] = rtrim($line, "\r\n");
                return strlen($line);
            },
            CURLOPT_HTTPHEADER => [
                // GitHub likes a UA
                'User-Agent: ' . $this->userAgent,
                // Auth: Bearer works for fine-grained github_pat_* and classic ghp_*
                'Authorization: Bearer ' . $this->token,
                // Keep GitHub happy
                'Accept: application/vnd.github+json',
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

        // We EXPECT a redirect.
        if ($status >= 300 && $status < 400) {
            foreach ($headersOut as $h) {
                if (stripos($h, 'Location:') === 0) {
                    $loc = trim(substr($h, 9));
                    if ($loc !== '') {
                        return $loc;
                    }
                }
            }
            throw new RuntimeException("GitHub returned redirect but no Location header.");
        }

        // If it wasn't a redirect, it usually means auth/permissions problem.
        $preview = substr((string)$body, 0, 700);
        throw new RuntimeException("Expected 302 from GitHub zipball, got HTTP {$status}. Body preview: {$preview}");
    }

    private function cacheKey(string $owner, string $repo, string $ref): ?string
    {
        if ($this->cacheDir === null) return null;
        $k = strtolower($owner . '__' . $repo . '__' . ($ref !== '' ? $ref : 'default'));
        $k = preg_replace('/[^a-z0-9_.-]+/', '_', $k);
        return "gh_zip_redirect_{$k}.txt";
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . $key;
    }

    private function cacheRead(string $key): ?string
    {
        $path = $this->cachePath($key);
        if (!is_file($path)) return null;

        $raw = file_get_contents($path);
        if ($raw === false) return null;

        // Format: "<expires_epoch>\n<url>"
        $parts = explode("\n", $raw, 2);
        if (count($parts) < 2) return null;

        $expires = (int)trim($parts[0]);
        if ($expires > 0 && time() > $expires) return null;

        $url = trim($parts[1]);
        return $url !== '' ? $url : null;
    }

    private function cacheWrite(string $key, string $url): void
    {
        $path = $this->cachePath($key);
        $expires = time() + $this->cacheTtlSeconds;
        $data = $expires . "\n" . $url;

        // Atomic-ish write
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $data, LOCK_EX) === false) {
            return; // cache failure shouldn't block downloads
        }
        @rename($tmp, $path);
    }
}
