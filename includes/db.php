<?php
/**
 * Database connection and operations
 * Uses MySQL with PDO for database operations
 */

require_once __DIR__ . '/../config.php';

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $port = defined('DB_PORT') ? DB_PORT : '3306';
        $dsn = "mysql:host=" . DB_HOST . ";port=" . $port . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get PDO instance for direct queries
     */
    public function getPdo() {
        return $this->pdo;
    }

    /**
     * Insert a new report (automatically handles revisions)
     * Different commit hashes are treated as separate reports
     */
    public function insertReport($tester, $commitHash, $testType, $clientVersion, $rawJson, $testDuration = null, $steamuiVersion = null, $steamPkgVersion = null, $contentHash = null) {
        // Check for existing report with same tester+version+test_type+commit_hash
        // Different commit hashes will create new reports instead of updating existing ones
        $existingReport = $this->findExistingReport($tester, $clientVersion, $testType, $commitHash);

        if ($existingReport) {
            // Archive the existing report as a revision
            $this->archiveReportAsRevision($existingReport['id']);

            // Update the existing report with new data
            // Note: submitted_at is NOT updated - it preserves the original submission date
            // The last_modified column auto-updates on change to track when the report was last revised
            $stmt = $this->pdo->prepare("
                UPDATE reports SET
                    commit_hash = ?,
                    raw_json = ?,
                    test_duration = ?,
                    steamui_version = ?,
                    steam_pkg_version = ?,
                    content_hash = ?,
                    revision_count = revision_count + 1
                WHERE id = ?
            ");
            $stmt->execute([$commitHash, $rawJson, $testDuration, $steamuiVersion, $steamPkgVersion, $contentHash, $existingReport['id']]);

            // Delete old test results for this report
            $stmt = $this->pdo->prepare("DELETE FROM test_results WHERE report_id = ?");
            $stmt->execute([$existingReport['id']]);

            return $existingReport['id'];
        }

        // Create new report
        $stmt = $this->pdo->prepare("
            INSERT INTO reports (tester, commit_hash, test_type, client_version, submitted_at, raw_json, content_hash, test_duration, steamui_version, steam_pkg_version, revision_count)
            VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([$tester, $commitHash, $testType, $clientVersion, $rawJson, $contentHash, $testDuration, $steamuiVersion, $steamPkgVersion]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Update the content hash for a report
     * Called after test results have been inserted to compute the final hash
     */
    public function updateReportContentHash($reportId, $contentHash) {
        $stmt = $this->pdo->prepare("UPDATE reports SET content_hash = ? WHERE id = ?");
        return $stmt->execute([$contentHash, $reportId]);
    }

    /**
     * Find existing report for same tester+version+test_type+commit_hash
     * Different commit hashes are treated as separate reports
     * Archived reports are excluded - new submissions will create new reports
     */
    private function findExistingReport($tester, $clientVersion, $testType, $commitHash = null) {
        // Check if is_archived column exists (for backward compatibility)
        $hasArchivedColumn = $this->hasColumn('reports', 'is_archived');

        // If commit_hash is provided, include it in the lookup
        // This ensures different commits are treated as separate reports
        if ($commitHash !== null && $commitHash !== '') {
            if ($hasArchivedColumn) {
                $stmt = $this->pdo->prepare("
                    SELECT * FROM reports
                    WHERE tester = ? AND client_version = ? AND test_type = ? AND commit_hash = ?
                    AND (is_archived = 0 OR is_archived IS NULL)
                    LIMIT 1
                ");
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT * FROM reports
                    WHERE tester = ? AND client_version = ? AND test_type = ? AND commit_hash = ?
                    LIMIT 1
                ");
            }
            $stmt->execute([$tester, $clientVersion, $testType, $commitHash]);
        } else {
            // No commit hash - find any report without a commit hash
            if ($hasArchivedColumn) {
                $stmt = $this->pdo->prepare("
                    SELECT * FROM reports
                    WHERE tester = ? AND client_version = ? AND test_type = ? AND (commit_hash IS NULL OR commit_hash = '')
                    AND (is_archived = 0 OR is_archived IS NULL)
                    LIMIT 1
                ");
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT * FROM reports
                    WHERE tester = ? AND client_version = ? AND test_type = ? AND (commit_hash IS NULL OR commit_hash = '')
                    LIMIT 1
                ");
            }
            $stmt->execute([$tester, $clientVersion, $testType]);
        }
        return $stmt->fetch() ?: null;
    }

    /**
     * Check if a column exists in a table
     */
    private function hasColumn($table, $column) {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Archive a report as a revision (preserves history with diffs)
     */
    private function archiveReportAsRevision($reportId, $previousTestResults = null) {
        $report = $this->getReport($reportId);
        if (!$report) return false;

        // Get test results for this report
        $testResults = $this->getTestResults($reportId);

        // Get the current revision number (which will be assigned to this archive)
        $revisionNumber = $report['revision_count'];

        // Calculate diff from previous revision if available
        $changesDiff = null;
        if ($previousTestResults !== null) {
            $changesDiff = $this->calculateTestResultsDiff($previousTestResults, $testResults);
        } else {
            // Try to get previous revision to calculate diff
            $prevRevision = $this->getLatestRevision($reportId);
            if ($prevRevision) {
                $changesDiff = $this->calculateTestResultsDiff($prevRevision['test_results'], $testResults);
            }
        }

        // Create revision entry
        $stmt = $this->pdo->prepare("
            INSERT INTO report_revisions
            (report_id, revision_number, tester, commit_hash, test_type, client_version, steamui_version, steam_pkg_version, submitted_at, archived_at, raw_json, test_results, changes_diff)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([
            $reportId,
            $revisionNumber,
            $report['tester'],
            $report['commit_hash'],
            $report['test_type'],
            $report['client_version'],
            $report['steamui_version'] ?? null,
            $report['steam_pkg_version'] ?? null,
            $report['submitted_at'],
            $report['raw_json'],
            json_encode($testResults),
            $changesDiff ? json_encode($changesDiff) : null
        ]);

        return true;
    }

    /**
     * Calculate diff between two sets of test results
     */
    private function calculateTestResultsDiff($oldResults, $newResults) {
        $diff = [
            'changed' => [],
            'added' => [],
            'removed' => []
        ];

        // Index old results by test_key
        $oldByKey = [];
        foreach ($oldResults as $result) {
            $oldByKey[$result['test_key']] = $result;
        }

        // Index new results by test_key
        $newByKey = [];
        foreach ($newResults as $result) {
            $newByKey[$result['test_key']] = $result;
        }

        // Find changed and added
        foreach ($newByKey as $key => $newResult) {
            if (isset($oldByKey[$key])) {
                $oldResult = $oldByKey[$key];
                if ($oldResult['status'] !== $newResult['status'] ||
                    ($oldResult['notes'] ?? '') !== ($newResult['notes'] ?? '')) {
                    $diff['changed'][] = [
                        'test_key' => $key,
                        'old_status' => $oldResult['status'],
                        'new_status' => $newResult['status'],
                        'old_notes' => $oldResult['notes'] ?? '',
                        'new_notes' => $newResult['notes'] ?? ''
                    ];
                }
            } else {
                $diff['added'][] = [
                    'test_key' => $key,
                    'status' => $newResult['status'],
                    'notes' => $newResult['notes'] ?? ''
                ];
            }
        }

        // Find removed
        foreach ($oldByKey as $key => $oldResult) {
            if (!isset($newByKey[$key])) {
                $diff['removed'][] = [
                    'test_key' => $key,
                    'status' => $oldResult['status'],
                    'notes' => $oldResult['notes'] ?? ''
                ];
            }
        }

        // Return null if no changes
        if (empty($diff['changed']) && empty($diff['added']) && empty($diff['removed'])) {
            return null;
        }

        return $diff;
    }

    /**
     * Get the latest revision for a report
     */
    private function getLatestRevision($reportId) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM report_revisions
            WHERE report_id = ?
            ORDER BY revision_number DESC
            LIMIT 1
        ");
        $stmt->execute([$reportId]);
        $revision = $stmt->fetch();

        if ($revision) {
            $revision['test_results'] = json_decode($revision['test_results'], true) ?? [];
        }

        return $revision ?: null;
    }

    /**
     * Insert a test result
     */
    public function insertTestResult($reportId, $testKey, $status, $notes = '') {
        $stmt = $this->pdo->prepare("
            INSERT INTO test_results (report_id, test_key, status, notes)
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([$reportId, $testKey, $status, $notes]);
    }

    /**
     * Batch insert multiple test results (much faster for large reports)
     */
    public function insertTestResultsBatch($reportId, $results) {
        if (empty($results)) {
            return 0;
        }

        $values = [];
        $params = [];
        foreach ($results as $testKey => $result) {
            $values[] = "(?, ?, ?, ?)";
            $params[] = $reportId;
            $params[] = $testKey;
            $params[] = $result['status'] ?? '';
            $params[] = $result['notes'] ?? '';
        }

        $sql = "INSERT INTO test_results (report_id, test_key, status, notes) VALUES " . implode(", ", $values);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return count($results);
    }

    /**
     * Get reports with pagination and filters
     */
    public function getReports($limit = 20, $offset = 0, $filters = []) {
        $where = [];
        $params = [];
        $join = "";

        if (!empty($filters['client_version'])) {
            $where[] = "r.client_version = ?";
            $params[] = $filters['client_version'];
        }
        if (!empty($filters['test_type'])) {
            $where[] = "r.test_type = ?";
            $params[] = $filters['test_type'];
        }
        if (!empty($filters['tester'])) {
            $where[] = "r.tester = ?";
            $params[] = $filters['tester'];
        }
        if (!empty($filters['commit_hash'])) {
            $where[] = "r.commit_hash = ?";
            $params[] = $filters['commit_hash'];
        }
        if (!empty($filters['steamui_version'])) {
            $where[] = "r.steamui_version = ?";
            $params[] = $filters['steamui_version'];
        }
        if (!empty($filters['steam_pkg_version'])) {
            $where[] = "r.steam_pkg_version = ?";
            $params[] = $filters['steam_pkg_version'];
        }
        if (!empty($filters['tag_id'])) {
            $join = "INNER JOIN report_tag_assignments rta ON r.id = rta.report_id";
            $where[] = "rta.tag_id = ?";
            $params[] = (int)$filters['tag_id'];
        }

        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $this->pdo->prepare("
            SELECT r.* FROM reports r
            $join
            $whereClause
            ORDER BY r.submitted_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = (int)$limit;
        $params[] = (int)$offset;
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Count reports with filters
     */
    public function countReports($filters = []) {
        $where = [];
        $params = [];
        $join = "";

        if (!empty($filters['client_version'])) {
            $where[] = "r.client_version = ?";
            $params[] = $filters['client_version'];
        }
        if (!empty($filters['test_type'])) {
            $where[] = "r.test_type = ?";
            $params[] = $filters['test_type'];
        }
        if (!empty($filters['tester'])) {
            $where[] = "r.tester = ?";
            $params[] = $filters['tester'];
        }
        if (!empty($filters['commit_hash'])) {
            $where[] = "r.commit_hash = ?";
            $params[] = $filters['commit_hash'];
        }
        if (!empty($filters['steamui_version'])) {
            $where[] = "r.steamui_version = ?";
            $params[] = $filters['steamui_version'];
        }
        if (!empty($filters['steam_pkg_version'])) {
            $where[] = "r.steam_pkg_version = ?";
            $params[] = $filters['steam_pkg_version'];
        }
        if (!empty($filters['tag_id'])) {
            $join = "INNER JOIN report_tag_assignments rta ON r.id = rta.report_id";
            $where[] = "rta.tag_id = ?";
            $params[] = (int)$filters['tag_id'];
        }

        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM reports r $join $whereClause");
        $stmt->execute($params);
        $result = $stmt->fetch();

        return $result['count'];
    }

    /**
     * Get a single report
     */
    public function getReport($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get test results for a report
     */
    public function getTestResults($reportId) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM test_results
            WHERE report_id = ?
            ORDER BY CAST(test_key AS UNSIGNED), test_key
        ");
        $stmt->execute([$reportId]);
        return $stmt->fetchAll();
    }

    /**
     * Get stats for a specific report
     */
    public function getReportStats($reportId) {
        $stmt = $this->pdo->prepare("
            SELECT
                SUM(CASE WHEN status = 'Working' THEN 1 ELSE 0 END) as working,
                SUM(CASE WHEN status = 'Semi-working' THEN 1 ELSE 0 END) as semi_working,
                SUM(CASE WHEN status = 'Not working' THEN 1 ELSE 0 END) as not_working,
                SUM(CASE WHEN status = 'N/A' THEN 1 ELSE 0 END) as na
            FROM test_results
            WHERE report_id = ?
        ");
        $stmt->execute([$reportId]);
        $result = $stmt->fetch();

        return [
            'working' => (int)($result['working'] ?? 0),
            'semi_working' => (int)($result['semi_working'] ?? 0),
            'not_working' => (int)($result['not_working'] ?? 0),
            'na' => (int)($result['na'] ?? 0)
        ];
    }

    /**
     * Get overall statistics
     */
    public function getStats() {
        $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM reports");
        $totalReports = $stmt->fetch()['total'];

        $stmt = $this->pdo->query("
            SELECT
                SUM(CASE WHEN status = 'Working' THEN 1 ELSE 0 END) as working,
                SUM(CASE WHEN status = 'Semi-working' THEN 1 ELSE 0 END) as semi_working,
                SUM(CASE WHEN status = 'Not working' THEN 1 ELSE 0 END) as not_working
            FROM test_results
        ");
        $result = $stmt->fetch();

        return [
            'total_reports' => (int)$totalReports,
            'working' => (int)($result['working'] ?? 0),
            'semi_working' => (int)($result['semi_working'] ?? 0),
            'not_working' => (int)($result['not_working'] ?? 0)
        ];
    }

    /**
     * Get most problematic tests (highest failure rate)
     */
    public function getProblematicTests($limit = 10) {
        $stmt = $this->pdo->prepare("
            SELECT
                test_key,
                SUM(CASE WHEN status = 'Not working' THEN 1 ELSE 0 END) as fail_count,
                COUNT(*) as total_count,
                (SUM(CASE WHEN status = 'Not working' THEN 1 ELSE 0 END) / COUNT(*)) * 100 as fail_rate
            FROM test_results
            WHERE status != 'N/A' AND status != ''
            GROUP BY test_key
            HAVING fail_count > 0
            ORDER BY fail_rate DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get version trend data (stats grouped by version)
     */
    public function getVersionTrend() {
        $stmt = $this->pdo->query("
            SELECT
                r.client_version,
                SUM(CASE WHEN tr.status = 'Working' THEN 1 ELSE 0 END) as working,
                SUM(CASE WHEN tr.status = 'Semi-working' THEN 1 ELSE 0 END) as semi_working,
                SUM(CASE WHEN tr.status = 'Not working' THEN 1 ELSE 0 END) as not_working
            FROM reports r
            LEFT JOIN test_results tr ON r.id = tr.report_id
            GROUP BY r.client_version
            ORDER BY r.client_version
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get stats for each test key
     */
    public function getTestStats() {
        $stmt = $this->pdo->query("
            SELECT
                test_key,
                SUM(CASE WHEN status = 'Working' THEN 1 ELSE 0 END) as working,
                SUM(CASE WHEN status = 'Semi-working' THEN 1 ELSE 0 END) as semi_working,
                SUM(CASE WHEN status = 'Not working' THEN 1 ELSE 0 END) as not_working,
                COUNT(*) as total
            FROM test_results
            WHERE status != 'N/A' AND status != ''
            GROUP BY test_key
            ORDER BY CAST(test_key AS UNSIGNED), test_key
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get unique values from a column
     */
    public function getUniqueValues($table, $column) {
        // Whitelist allowed tables and columns
        $allowed = [
            'reports' => ['client_version', 'test_type', 'tester', 'commit_hash', 'steamui_version', 'steam_pkg_version'],
            'test_results' => ['test_key', 'status']
        ];

        if (!isset($allowed[$table]) || !in_array($column, $allowed[$table])) {
            return [];
        }

        $stmt = $this->pdo->query("SELECT DISTINCT `$column` FROM `$table` WHERE `$column` IS NOT NULL AND `$column` != '' ORDER BY `$column`");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get stats for a specific version
     */
    public function getVersionStats($version) {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(DISTINCT r.id) as report_count,
                SUM(CASE WHEN tr.status = 'Working' THEN 1 ELSE 0 END) as working,
                SUM(CASE WHEN tr.status = 'Semi-working' THEN 1 ELSE 0 END) as semi_working,
                SUM(CASE WHEN tr.status = 'Not working' THEN 1 ELSE 0 END) as not_working
            FROM reports r
            LEFT JOIN test_results tr ON r.id = tr.report_id
            WHERE r.client_version = ?
        ");
        $stmt->execute([$version]);
        $result = $stmt->fetch();

        return [
            'report_count' => (int)($result['report_count'] ?? 0),
            'working' => (int)($result['working'] ?? 0),
            'semi_working' => (int)($result['semi_working'] ?? 0),
            'not_working' => (int)($result['not_working'] ?? 0)
        ];
    }

    /**
     * Get average test duration for a specific version (in seconds)
     */
    public function getVersionAverageDuration($version) {
        $stmt = $this->pdo->prepare("
            SELECT AVG(test_duration) as avg_duration
            FROM reports
            WHERE client_version = ? AND test_duration IS NOT NULL AND test_duration > 0
        ");
        $stmt->execute([$version]);
        $result = $stmt->fetch();

        return $result['avg_duration'] ? (int)round($result['avg_duration']) : null;
    }

    /**
     * Get version matrix (most common status for each version/test combination)
     */
    public function getVersionMatrix() {
        $stmt = $this->pdo->query("
            SELECT
                r.client_version,
                tr.test_key,
                tr.status,
                COUNT(*) as count
            FROM reports r
            JOIN test_results tr ON r.id = tr.report_id
            WHERE tr.status IS NOT NULL AND tr.status != ''
            GROUP BY r.client_version, tr.test_key, tr.status
            ORDER BY r.client_version, tr.test_key
        ");
        $rows = $stmt->fetchAll();

        // Process to find most common status per version/test
        $counts = [];
        foreach ($rows as $row) {
            $key = $row['client_version'] . '|' . $row['test_key'];
            if (!isset($counts[$key]) || $row['count'] > $counts[$key]['count']) {
                $counts[$key] = [
                    'client_version' => $row['client_version'],
                    'test_key' => $row['test_key'],
                    'most_common_status' => $row['status'],
                    'count' => $row['count']
                ];
            }
        }

        return array_values($counts);
    }

    /**
     * Delete a report and its test results
     */
    public function deleteReport($id) {
        // Foreign key with ON DELETE CASCADE handles test_results
        $stmt = $this->pdo->prepare("DELETE FROM reports WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Archive a report - prevents future revisions from being added
     * New submissions with same criteria will create new reports instead
     */
    public function archiveReport($id) {
        // Ensure the column exists
        if (!$this->hasColumn('reports', 'is_archived')) {
            $this->ensureArchivedColumns();
        }

        $stmt = $this->pdo->prepare("UPDATE reports SET is_archived = 1, archived_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Unarchive a report - allows revisions to be added again
     */
    public function unarchiveReport($id) {
        if (!$this->hasColumn('reports', 'is_archived')) {
            return false;
        }

        $stmt = $this->pdo->prepare("UPDATE reports SET is_archived = 0, archived_at = NULL WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Check if a report is archived
     */
    public function isReportArchived($id) {
        if (!$this->hasColumn('reports', 'is_archived')) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT is_archived FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result && $result['is_archived'];
    }

    /**
     * Ensure is_archived and archived_at columns exist (auto-migration)
     */
    private function ensureArchivedColumns() {
        try {
            if (!$this->hasColumn('reports', 'is_archived')) {
                $this->pdo->exec("ALTER TABLE reports ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Archived reports do not receive new revisions'");
            }
            if (!$this->hasColumn('reports', 'archived_at')) {
                $this->pdo->exec("ALTER TABLE reports ADD COLUMN archived_at DATETIME DEFAULT NULL COMMENT 'When the report was archived'");
            }
        } catch (Exception $e) {
            // Ignore errors if columns already exist
        }
    }

    /**
     * Get filtered test results with report info
     */
    public function getFilteredResults($status = '', $version = '', $testKey = '', $tester = '', $reportId = 0, $commitHash = '') {
        $where = [];
        $params = [];

        if ($status) {
            $where[] = "tr.status = ?";
            $params[] = $status;
        }
        if ($version) {
            $where[] = "r.client_version = ?";
            $params[] = $version;
        }
        if ($testKey) {
            $where[] = "tr.test_key = ?";
            $params[] = $testKey;
        }
        if ($tester) {
            $where[] = "r.tester = ?";
            $params[] = $tester;
        }
        if ($reportId) {
            $where[] = "tr.report_id = ?";
            $params[] = $reportId;
        }
        if ($commitHash) {
            $where[] = "r.commit_hash = ?";
            $params[] = $commitHash;
        }

        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $this->pdo->prepare("
            SELECT
                tr.id,
                tr.report_id,
                tr.test_key,
                tr.status,
                tr.notes,
                r.client_version,
                r.tester,
                r.test_type,
                r.commit_hash,
                r.submitted_at
            FROM test_results tr
            JOIN reports r ON tr.report_id = r.id
            $whereClause
            ORDER BY r.submitted_at DESC, CAST(tr.test_key AS UNSIGNED), tr.test_key
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get results for a specific category
     */
    public function getResultsByCategory($category) {
        $categories = getTestCategories();
        if (!isset($categories[$category])) {
            return [];
        }

        $testKeys = array_keys($categories[$category]);
        $placeholders = str_repeat('?,', count($testKeys) - 1) . '?';

        $stmt = $this->pdo->prepare("
            SELECT
                tr.id,
                tr.report_id,
                tr.test_key,
                tr.status,
                tr.notes,
                r.client_version,
                r.tester,
                r.test_type,
                r.submitted_at
            FROM test_results tr
            JOIN reports r ON tr.report_id = r.id
            WHERE tr.test_key IN ($placeholders)
            ORDER BY r.submitted_at DESC, CAST(tr.test_key AS UNSIGNED), tr.test_key
        ");
        $stmt->execute($testKeys);
        return $stmt->fetchAll();
    }

    // =====================
    // USER MANAGEMENT
    // =====================

    /**
     * Initialize default admin if no users exist
     */
    public function initializeUsers() {
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM users");
        $count = $stmt->fetch()['count'];

        if ($count == 0) {
            $this->createUser('admin', 'steamtest2024', 'admin');
        }
    }

    /**
     * Get user by username
     */
    public function getUser($username) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get user by API key
     */
    public function getUserByApiKey($apiKey) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE api_key = ?");
        $stmt->execute([$apiKey]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all users
     */
    public function getUsers() {
        $stmt = $this->pdo->query("SELECT * FROM users ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    /**
     * Create a new user
     */
    public function createUser($username, $password, $role = 'user') {
        // Check if user exists
        if ($this->getUser($username)) {
            return false;
        }

        $apiKey = $this->generateApiKey();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, password, role, api_key, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$username, $passwordHash, $role, $apiKey]);

        return $this->getUser($username);
    }

    /**
     * Update user
     */
    public function updateUser($username, $data) {
        $user = $this->getUser($username);
        if (!$user) {
            return false;
        }

        $updates = [];
        $params = [];

        if (isset($data['password']) && !empty($data['password'])) {
            $updates[] = "password = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if (isset($data['role'])) {
            $updates[] = "role = ?";
            $params[] = $data['role'];
        }

        if (empty($updates)) {
            return true;
        }

        $params[] = $username;
        $stmt = $this->pdo->prepare("UPDATE users SET " . implode(", ", $updates) . " WHERE username = ?");
        return $stmt->execute($params);
    }

    /**
     * Delete user
     */
    public function deleteUser($username) {
        if ($username === 'admin') {
            return false;
        }

        $stmt = $this->pdo->prepare("DELETE FROM users WHERE username = ?");
        return $stmt->execute([$username]);
    }

    /**
     * Regenerate API key for user
     */
    public function regenerateApiKey($username) {
        $newKey = $this->generateApiKey();
        $stmt = $this->pdo->prepare("UPDATE users SET api_key = ? WHERE username = ?");
        if ($stmt->execute([$newKey, $username])) {
            return $newKey;
        }
        return false;
    }

    /**
     * Generate a random API key
     */
    private function generateApiKey() {
        return 'sk_' . bin2hex(random_bytes(24));
    }

    /**
     * Verify user password
     */
    public function verifyPassword($username, $password) {
        $user = $this->getUser($username);
        if (!$user) {
            return false;
        }
        return password_verify($password, $user['password']);
    }

    // =====================
    // REPORT MANAGEMENT
    // =====================

    /**
     * Update a report (creates revision when fields are modified)
     */
    public function updateReport($id, $data, $createRevision = true) {
        $report = $this->getReport($id);
        if (!$report) return false;

        $updates = [];
        $params = [];
        $hasChanges = false;

        if (isset($data['client_version']) && $data['client_version'] !== $report['client_version']) {
            $updates[] = "client_version = ?";
            $params[] = $data['client_version'];
            $hasChanges = true;
        }
        if (isset($data['test_type']) && $data['test_type'] !== $report['test_type']) {
            $updates[] = "test_type = ?";
            $params[] = $data['test_type'];
            $hasChanges = true;
        }
        if (isset($data['commit_hash']) && $data['commit_hash'] !== $report['commit_hash']) {
            $updates[] = "commit_hash = ?";
            $params[] = $data['commit_hash'];
            $hasChanges = true;
        }
        if (array_key_exists('steamui_version', $data) && $data['steamui_version'] !== ($report['steamui_version'] ?? null)) {
            $updates[] = "steamui_version = ?";
            $params[] = $data['steamui_version'];
            $hasChanges = true;
        }
        if (array_key_exists('steam_pkg_version', $data) && $data['steam_pkg_version'] !== ($report['steam_pkg_version'] ?? null)) {
            $updates[] = "steam_pkg_version = ?";
            $params[] = $data['steam_pkg_version'];
            $hasChanges = true;
        }

        if (empty($updates)) {
            return true;
        }

        // Create revision before making changes (if there are actual changes)
        if ($createRevision && $hasChanges) {
            $this->archiveReportAsRevision($id);
            $updates[] = "revision_count = revision_count + 1";
        }

        // Always update last_modified
        $updates[] = "last_modified = NOW()";

        $params[] = $id;
        $stmt = $this->pdo->prepare("UPDATE reports SET " . implode(", ", $updates) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    /**
     * Update a test result (creates revision when modified)
     */
    public function updateTestResult($id, $status, $notes = null, $createRevision = true) {
        // Get the current test result to check for changes
        $testResult = $this->getTestResult($id);
        if (!$testResult) return false;

        $hasChanges = false;
        if ($testResult['status'] !== $status) {
            $hasChanges = true;
        }
        if ($notes !== null && ($testResult['notes'] ?? '') !== $notes) {
            $hasChanges = true;
        }

        // Create revision before making changes (if there are actual changes)
        if ($createRevision && $hasChanges) {
            $this->archiveReportAsRevision($testResult['report_id']);
            // Increment revision count
            $stmt = $this->pdo->prepare("UPDATE reports SET revision_count = revision_count + 1, last_modified = NOW() WHERE id = ?");
            $stmt->execute([$testResult['report_id']]);
        } elseif ($hasChanges) {
            // Still update last_modified even if not creating revision
            $stmt = $this->pdo->prepare("UPDATE reports SET last_modified = NOW() WHERE id = ?");
            $stmt->execute([$testResult['report_id']]);
        }

        if ($notes !== null) {
            $stmt = $this->pdo->prepare("UPDATE test_results SET status = ?, notes = ? WHERE id = ?");
            return $stmt->execute([$status, $notes, $id]);
        } else {
            $stmt = $this->pdo->prepare("UPDATE test_results SET status = ? WHERE id = ?");
            return $stmt->execute([$status, $id]);
        }
    }

    /**
     * Get a single test result by ID
     */
    public function getTestResult($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM test_results WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // =====================
    // RETEST REQUESTS
    // =====================

    /**
     * Add a retest request for a specific test/blob combination
     */
    public function addRetestRequest($testKey, $clientVersion, $createdBy, $reason = '', $notes = '', $reportId = null, $reportRevision = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO retest_requests (report_id, report_revision, test_key, client_version, created_by, reason, notes, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$reportId, $reportRevision, $testKey, $clientVersion, $createdBy, $reason, $notes]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Get all retest requests
     */
    public function getRetestRequests($status = null) {
        if ($status !== null) {
            $stmt = $this->pdo->prepare("SELECT * FROM retest_requests WHERE status = ? ORDER BY created_at DESC");
            $stmt->execute([$status]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM retest_requests ORDER BY created_at DESC");
        }
        return $stmt->fetchAll();
    }

    /**
     * Get pending retest requests for a specific client/user (for API)
     */
    public function getPendingRetestsForClient($clientVersion = null) {
        // Join with reports table to get the tested commit hash
        $sql = "
            SELECT rr.*, r.commit_hash as tested_commit_hash
            FROM retest_requests rr
            LEFT JOIN reports r ON rr.report_id = r.id
            WHERE rr.status = 'pending'
        ";
        if ($clientVersion) {
            $sql .= " AND rr.client_version = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$clientVersion]);
        } else {
            $stmt = $this->pdo->query($sql);
        }
        return $stmt->fetchAll();
    }

    /**
     * Mark a retest request as completed
     */
    public function completeRetestRequest($id) {
        $stmt = $this->pdo->prepare("UPDATE retest_requests SET status = 'completed', completed_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Complete all pending retest requests for a specific client_version that match the given commit hash.
     * This is called when a report is submitted - if the submitted commit matches the retest's original commit,
     * the retest is considered fulfilled.
     *
     * @param string $clientVersion The client version
     * @param string $commitHash The commit hash from the submitted report
     * @return int Number of retest requests completed
     */
    public function completeRetestRequestsByCommit($clientVersion, $commitHash) {
        if (empty($clientVersion) || empty($commitHash)) {
            return 0;
        }

        // Find pending retest requests for this client version where the original report's commit hash matches
        $stmt = $this->pdo->prepare("
            UPDATE retest_requests rr
            INNER JOIN reports r ON rr.report_id = r.id
            SET rr.status = 'completed', rr.completed_at = NOW()
            WHERE rr.client_version = ?
              AND rr.status = 'pending'
              AND r.commit_hash = ?
        ");
        $stmt->execute([$clientVersion, $commitHash]);
        return $stmt->rowCount();
    }

    /**
     * Delete a retest request
     */
    public function deleteRetestRequest($id) {
        $stmt = $this->pdo->prepare("DELETE FROM retest_requests WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get a single retest request
     */
    public function getRetestRequest($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM retest_requests WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Check if a pending retest request exists for a specific test/version
     */
    public function hasPendingRetestRequest($testKey, $clientVersion) {
        $stmt = $this->pdo->prepare("
            SELECT id FROM retest_requests
            WHERE test_key = ? AND client_version = ? AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$testKey, $clientVersion]);
        return $stmt->fetch() !== false;
    }

    /**
     * Get all pending retest requests as a lookup map (test_key|client_version => data)
     * Optimized for batch checking in table views
     * Returns notes and other info so they can be displayed to all users
     */
    public function getPendingRetestRequestsMap() {
        $stmt = $this->pdo->query("
            SELECT test_key, client_version, notes, reason, created_by, created_at
            FROM retest_requests WHERE status = 'pending'
        ");
        $map = [];
        while ($row = $stmt->fetch()) {
            $key = $row['test_key'] . '|' . $row['client_version'];
            $map[$key] = [
                'notes' => $row['notes'] ?? '',
                'reason' => $row['reason'] ?? '',
                'created_by' => $row['created_by'] ?? '',
                'created_at' => $row['created_at'] ?? ''
            ];
        }
        return $map;
    }

    // =====================
    // FIXED TESTS
    // =====================

    /**
     * Mark a test as fixed (triggers retest with latest revision)
     */
    public function addFixedTest($testKey, $clientVersion, $fixedBy, $commitHash = '', $notes = '') {
        $stmt = $this->pdo->prepare("
            INSERT INTO fixed_tests (test_key, client_version, fixed_by, commit_hash, notes, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending_retest', NOW())
        ");
        $stmt->execute([$testKey, $clientVersion, $fixedBy, $commitHash, $notes]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Get all fixed tests
     */
    public function getFixedTests($status = null) {
        if ($status !== null) {
            $stmt = $this->pdo->prepare("SELECT * FROM fixed_tests WHERE status = ? ORDER BY created_at DESC");
            $stmt->execute([$status]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM fixed_tests ORDER BY created_at DESC");
        }
        return $stmt->fetchAll();
    }

    /**
     * Get pending fixed tests that need retest (for API)
     */
    public function getPendingFixedTests($clientVersion = null) {
        if ($clientVersion) {
            $stmt = $this->pdo->prepare("SELECT * FROM fixed_tests WHERE status = 'pending_retest' AND client_version = ?");
            $stmt->execute([$clientVersion]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM fixed_tests WHERE status = 'pending_retest'");
        }
        return $stmt->fetchAll();
    }

    /**
     * Mark a fixed test as verified
     */
    public function verifyFixedTest($id) {
        $stmt = $this->pdo->prepare("UPDATE fixed_tests SET status = 'verified', verified_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Delete a fixed test entry
     */
    public function deleteFixedTest($id) {
        $stmt = $this->pdo->prepare("DELETE FROM fixed_tests WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get a single fixed test
     */
    public function getFixedTest($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM fixed_tests WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get combined retest queue for API (both retest requests and fixed tests)
     */
    public function getRetestQueue($clientVersion = null) {
        $queue = [];

        // Add retest requests
        foreach ($this->getPendingRetestsForClient($clientVersion) as $request) {
            $queue[] = [
                'type' => 'retest',
                'id' => $request['id'],
                'test_key' => $request['test_key'],
                'client_version' => $request['client_version'],
                'reason' => $request['reason'],
                'notes' => $request['notes'] ?? '',
                'report_id' => $request['report_id'] ?? null,
                'report_revision' => $request['report_revision'] ?? null,
                'tested_commit_hash' => $request['tested_commit_hash'] ?? null,
                'latest_revision' => false,
                'created_at' => $request['created_at']
            ];
        }

        // Add fixed tests (these have latest_revision flag)
        foreach ($this->getPendingFixedTests($clientVersion) as $fixed) {
            $queue[] = [
                'type' => 'fixed',
                'id' => $fixed['id'],
                'test_key' => $fixed['test_key'],
                'client_version' => $fixed['client_version'],
                'reason' => 'Test marked as fixed - please verify',
                'commit_hash' => $fixed['commit_hash'],
                'latest_revision' => true,
                'created_at' => $fixed['created_at']
            ];
        }

        // Sort by created_at descending
        usort($queue, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $queue;
    }

    // =====================
    // REPORT REVISIONS
    // =====================

    /**
     * Get all revisions for a report
     */
    public function getReportRevisions($reportId) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM report_revisions
            WHERE report_id = ?
            ORDER BY archived_at DESC
        ");
        $stmt->execute([$reportId]);
        $revisions = $stmt->fetchAll();

        // Parse JSON test_results
        foreach ($revisions as &$revision) {
            $revision['test_results'] = json_decode($revision['test_results'], true) ?? [];
        }

        return $revisions;
    }

    /**
     * Get a single revision by ID
     */
    public function getRevision($revisionId) {
        $stmt = $this->pdo->prepare("SELECT * FROM report_revisions WHERE id = ?");
        $stmt->execute([$revisionId]);
        $revision = $stmt->fetch();

        if ($revision) {
            $revision['test_results'] = json_decode($revision['test_results'], true) ?? [];
        }

        return $revision ?: null;
    }

    /**
     * Get revision count for a report
     */
    public function getRevisionCount($reportId) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM report_revisions WHERE report_id = ?");
        $stmt->execute([$reportId]);
        return $stmt->fetch()['count'];
    }

    /**
     * Get all revisions (for admin view)
     */
    public function getAllRevisions($limit = 50, $offset = 0) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM report_revisions
            ORDER BY archived_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $revisions = $stmt->fetchAll();

        foreach ($revisions as &$revision) {
            $revision['test_results'] = json_decode($revision['test_results'], true) ?? [];
        }

        return $revisions;
    }

    /**
     * Count total revisions
     */
    public function countRevisions() {
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM report_revisions");
        return $stmt->fetch()['count'];
    }

    /**
     * Restore a revision as the current report
     */
    public function restoreRevision($revisionId) {
        $revision = $this->getRevision($revisionId);
        if (!$revision) return false;

        $reportId = $revision['report_id'];
        $currentReport = $this->getReport($reportId);
        if (!$currentReport) return false;

        // Archive current state before restoring
        $this->archiveReportAsRevision($reportId);

        // Restore the revision data to the main report
        $stmt = $this->pdo->prepare("
            UPDATE reports SET
                commit_hash = ?,
                submitted_at = ?,
                raw_json = ?,
                revision_count = revision_count + 1,
                restored_from = ?,
                restored_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $revision['commit_hash'],
            $revision['submitted_at'],
            $revision['raw_json'],
            $revisionId,
            $reportId
        ]);

        // Delete current test results
        $stmt = $this->pdo->prepare("DELETE FROM test_results WHERE report_id = ?");
        $stmt->execute([$reportId]);

        // Restore test results from revision
        foreach ($revision['test_results'] as $result) {
            $this->insertTestResult($reportId, $result['test_key'], $result['status'], $result['notes'] ?? '');
        }

        return true;
    }

    // =====================
    // REPORT LOG FILES
    // =====================

    /**
     * Insert a log file for a report
     * Log data should be base64-encoded gzip compressed content
     */
    public function insertReportLog($reportId, $filename, $logDatetime, $sizeOriginal, $sizeCompressed, $logDataBase64) {
        // Decode base64 to get the raw gzip binary data
        $logData = base64_decode($logDataBase64);
        if ($logData === false) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO report_logs (report_id, filename, log_datetime, size_original, size_compressed, log_data, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$reportId, $filename, $logDatetime, $sizeOriginal, $sizeCompressed, $logData]);
    }

    /**
     * Get all log files for a report (without the actual data)
     */
    public function getReportLogs($reportId) {
        $stmt = $this->pdo->prepare("
            SELECT id, report_id, filename, log_datetime, size_original, size_compressed, created_at
            FROM report_logs
            WHERE report_id = ?
            ORDER BY log_datetime DESC
        ");
        $stmt->execute([$reportId]);
        return $stmt->fetchAll();
    }

    /**
     * Get a single log file (with data for download)
     */
    public function getReportLog($logId) {
        $stmt = $this->pdo->prepare("SELECT * FROM report_logs WHERE id = ?");
        $stmt->execute([$logId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Delete all logs for a report
     */
    public function deleteReportLogs($reportId) {
        $stmt = $this->pdo->prepare("DELETE FROM report_logs WHERE report_id = ?");
        return $stmt->execute([$reportId]);
    }

    /**
     * Delete a single log by ID
     * Returns the report_id of the deleted log (for triggering revision), or null on failure
     */
    public function deleteReportLogById($logId) {
        // First get the log to find its report_id
        $log = $this->getReportLog($logId);
        if (!$log) {
            return null;
        }

        $reportId = $log['report_id'];
        $stmt = $this->pdo->prepare("DELETE FROM report_logs WHERE id = ?");
        if ($stmt->execute([$logId])) {
            return $reportId;
        }
        return null;
    }

    /**
     * Check if report has logs
     */
    public function hasReportLogs($reportId) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM report_logs WHERE report_id = ?");
        $stmt->execute([$reportId]);
        return $stmt->fetch()['count'] > 0;
    }

    // =====================
    // COMMIT/REVISION STATS
    // =====================

    /**
     * Get stats for each commit hash (repo revision)
     */
    public function getCommitStats() {
        $stmt = $this->pdo->query("
            SELECT
                r.commit_hash,
                COUNT(DISTINCT r.id) as report_count,
                MIN(r.submitted_at) as first_report,
                MAX(r.submitted_at) as last_report,
                SUM(CASE WHEN tr.status = 'Working' THEN 1 ELSE 0 END) as working,
                SUM(CASE WHEN tr.status = 'Semi-working' THEN 1 ELSE 0 END) as semi_working,
                SUM(CASE WHEN tr.status = 'Not working' THEN 1 ELSE 0 END) as not_working,
                SUM(CASE WHEN tr.status = 'N/A' THEN 1 ELSE 0 END) as na,
                COUNT(tr.id) as total_tests
            FROM reports r
            LEFT JOIN test_results tr ON r.id = tr.report_id
            WHERE r.commit_hash IS NOT NULL AND r.commit_hash != ''
            GROUP BY r.commit_hash
            ORDER BY last_report DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get reports for a specific commit hash
     */
    public function getReportsByCommit($commitHash) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM reports
            WHERE commit_hash = ?
            ORDER BY submitted_at DESC
        ");
        $stmt->execute([$commitHash]);
        return $stmt->fetchAll();
    }

    // =====================
    // TEST CATEGORIES
    // =====================

    /**
     * Get all test categories
     */
    public function getTestCategories() {
        $stmt = $this->pdo->query("SELECT * FROM test_categories ORDER BY sort_order, name");
        return $stmt->fetchAll();
    }

    /**
     * Get a single test category
     */
    public function getTestCategory($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM test_categories WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create a test category
     */
    public function createTestCategory($name, $sortOrder = 0) {
        $stmt = $this->pdo->prepare("INSERT INTO test_categories (name, sort_order) VALUES (?, ?)");
        $stmt->execute([$name, $sortOrder]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Update a test category
     */
    public function updateTestCategory($id, $name, $sortOrder = null) {
        if ($sortOrder !== null) {
            $stmt = $this->pdo->prepare("UPDATE test_categories SET name = ?, sort_order = ? WHERE id = ?");
            return $stmt->execute([$name, $sortOrder, $id]);
        } else {
            $stmt = $this->pdo->prepare("UPDATE test_categories SET name = ? WHERE id = ?");
            return $stmt->execute([$name, $id]);
        }
    }

    /**
     * Delete a test category (sets test types to disabled with null category)
     */
    public function deleteTestCategory($id) {
        // Disable tests in this category (foreign key will set to NULL)
        $stmt = $this->pdo->prepare("UPDATE test_types SET is_enabled = 0 WHERE category_id = ?");
        $stmt->execute([$id]);

        // Delete the category
        $stmt = $this->pdo->prepare("DELETE FROM test_categories WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get max sort order for categories
     */
    public function getMaxCategorySortOrder() {
        $stmt = $this->pdo->query("SELECT MAX(sort_order) as max_order FROM test_categories");
        $row = $stmt->fetch();
        return $row['max_order'] ?? 0;
    }

    // =====================
    // TEST TYPES
    // =====================

    /**
     * Get all test types
     */
    public function getTestTypes($enabledOnly = false) {
        $where = $enabledOnly ? "WHERE tt.is_enabled = 1" : "";
        $stmt = $this->pdo->query("
            SELECT tt.*, tc.name as category_name
            FROM test_types tt
            LEFT JOIN test_categories tc ON tt.category_id = tc.id
            $where
            ORDER BY tt.sort_order, tt.test_key
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get test types grouped by category
     */
    public function getTestTypesGrouped($enabledOnly = true) {
        $types = $this->getTestTypes($enabledOnly);
        $grouped = [];

        foreach ($types as $type) {
            $catName = $type['category_name'] ?? 'Uncategorized';
            if (!isset($grouped[$catName])) {
                $grouped[$catName] = [];
            }
            $grouped[$catName][$type['test_key']] = [
                'id' => $type['id'],
                'name' => $type['name'],
                'expected' => $type['description'],
                'category' => $catName,
                'is_enabled' => $type['is_enabled']
            ];
        }

        return $grouped;
    }

    /**
     * Get a single test type
     */
    public function getTestType($id) {
        $stmt = $this->pdo->prepare("
            SELECT tt.*, tc.name as category_name
            FROM test_types tt
            LEFT JOIN test_categories tc ON tt.category_id = tc.id
            WHERE tt.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get a test type by key
     */
    public function getTestTypeByKey($testKey) {
        $stmt = $this->pdo->prepare("
            SELECT tt.*, tc.name as category_name
            FROM test_types tt
            LEFT JOIN test_categories tc ON tt.category_id = tc.id
            WHERE tt.test_key = ?
        ");
        $stmt->execute([$testKey]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create a test type
     */
    public function createTestType($testKey, $name, $description, $categoryId = null, $sortOrder = 0) {
        $stmt = $this->pdo->prepare("
            INSERT INTO test_types (test_key, name, description, category_id, sort_order, is_enabled)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$testKey, $name, $description, $categoryId, $sortOrder]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Update a test type
     */
    public function updateTestType($id, $data) {
        $updates = [];
        $params = [];

        if (isset($data['test_key'])) {
            $updates[] = "test_key = ?";
            $params[] = $data['test_key'];
        }
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = $data['name'];
        }
        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $params[] = $data['description'];
        }
        if (array_key_exists('category_id', $data)) {
            $updates[] = "category_id = ?";
            $params[] = $data['category_id'];
        }
        if (isset($data['is_enabled'])) {
            $updates[] = "is_enabled = ?";
            $params[] = $data['is_enabled'];
        }
        if (isset($data['sort_order'])) {
            $updates[] = "sort_order = ?";
            $params[] = $data['sort_order'];
        }

        if (empty($updates)) {
            return true;
        }

        $params[] = $id;
        $stmt = $this->pdo->prepare("UPDATE test_types SET " . implode(", ", $updates) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    /**
     * Delete a test type
     */
    public function deleteTestType($id) {
        $stmt = $this->pdo->prepare("DELETE FROM test_types WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get max sort order for test types
     */
    public function getMaxTestTypeSortOrder() {
        $stmt = $this->pdo->query("SELECT MAX(sort_order) as max_order FROM test_types");
        $row = $stmt->fetch();
        return $row['max_order'] ?? 0;
    }

    /**
     * Check if test categories table exists and has data
     */
    public function hasTestCategoriesTable() {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM test_categories");
            $row = $stmt->fetch();
            return $row['count'] > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    // =====================
    // USER NOTIFICATIONS
    // =====================

    /**
     * Create a notification for a user
     */
    public function createNotification($userId, $type, $title, $message, $notes = null, $reportId = null, $testKey = null, $clientVersion = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO user_notifications (user_id, type, report_id, test_key, client_version, title, message, notes, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$userId, $type, $reportId, $testKey, $clientVersion, $title, $message, $notes]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Create a retest notification for a tester (finds user by tester name from report)
     */
    public function createRetestNotification($reportId, $testKey, $clientVersion, $notes = '', $createdBy = '', $reportRevision = null) {
        // Get the report to find the tester
        $report = $this->getReport($reportId);
        if (!$report) return false;

        $testerName = $report['tester'];

        // Find user by tester name
        $user = $this->getUser($testerName);
        if (!$user) return false;

        $title = 'Retest Required';
        $revisionInfo = $reportRevision !== null ? " (revision $reportRevision)" : "";
        $message = "Test $testKey for version $clientVersion requires retesting$revisionInfo.";
        if ($createdBy) {
            $message .= " Requested by $createdBy.";
        }

        return $this->createNotification(
            $user['id'],
            'retest',
            $title,
            $message,
            $notes,
            $reportId,
            $testKey,
            $clientVersion
        );
    }

    /**
     * Get notifications for a user
     */
    public function getUserNotifications($userId, $unreadOnly = false, $limit = 50) {
        $where = "user_id = ?";
        if ($unreadOnly) {
            $where .= " AND is_read = 0";
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM user_notifications
            WHERE $where
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get unread notification count for a user
     */
    public function getUnreadNotificationCount($userId) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM user_notifications
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch()['count'];
    }

    /**
     * Mark a notification as read
     */
    public function markNotificationRead($id) {
        $stmt = $this->pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllNotificationsRead($userId) {
        $stmt = $this->pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        return $stmt->execute([$userId]);
    }

    /**
     * Delete a notification
     */
    public function deleteNotification($id) {
        $stmt = $this->pdo->prepare("DELETE FROM user_notifications WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get a single notification
     */
    public function getNotification($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM user_notifications WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get user by ID
     */
    public function getUserById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update last_modified timestamp for a report
     */
    public function touchReportModified($reportId) {
        $stmt = $this->pdo->prepare("UPDATE reports SET last_modified = NOW() WHERE id = ?");
        return $stmt->execute([$reportId]);
    }

    /**
     * Get a revision by report ID and revision number
     */
    public function getRevisionByNumber($reportId, $revisionNumber) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM report_revisions
            WHERE report_id = ? AND revision_number = ?
        ");
        $stmt->execute([$reportId, $revisionNumber]);
        $revision = $stmt->fetch();

        if ($revision) {
            $revision['test_results'] = json_decode($revision['test_results'], true) ?? [];
            $revision['changes_diff'] = json_decode($revision['changes_diff'], true);
        }

        return $revision ?: null;
    }

    // =====================
    // REPORT COMMENTS
    // =====================

    /**
     * Ensure report_comments table exists
     */
    public function ensureCommentsTable() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS report_comments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    report_id INT NOT NULL,
                    user_id INT NOT NULL COMMENT 'Author of the comment',
                    parent_comment_id INT DEFAULT NULL COMMENT 'For replies/quotes - references parent comment',
                    content TEXT NOT NULL,
                    quoted_text TEXT DEFAULT NULL COMMENT 'Quoted text from parent comment',
                    is_edited TINYINT(1) NOT NULL DEFAULT 0,
                    is_deleted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL,
                    INDEX idx_report_id (report_id),
                    INDEX idx_user_id (user_id),
                    INDEX idx_parent_comment (parent_comment_id),
                    INDEX idx_created_at (created_at),
                    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (parent_comment_id) REFERENCES report_comments(id) ON DELETE SET NULL
                ) ENGINE=InnoDB
            ");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Add a comment to a report
     */
    public function addComment($reportId, $userId, $content, $parentCommentId = null, $quotedText = null) {
        // Ensure table exists
        $this->ensureCommentsTable();

        // Verify report exists
        $report = $this->getReport($reportId);
        if (!$report) return null;

        // Verify user exists
        $user = $this->getUserById($userId);
        if (!$user) return null;

        // If parent comment specified, verify it exists and isn't deleted
        if ($parentCommentId) {
            $parent = $this->getComment($parentCommentId);
            if (!$parent || $parent['is_deleted']) {
                $parentCommentId = null;
                $quotedText = null;
            }
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO report_comments (report_id, user_id, parent_comment_id, content, quoted_text, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$reportId, $userId, $parentCommentId, $content, $quotedText]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Get a single comment by ID
     */
    public function getComment($commentId) {
        $stmt = $this->pdo->prepare("
            SELECT c.*, u.username as author_name, u.role as author_role
            FROM report_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$commentId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all comments for a report (with author info)
     */
    public function getReportComments($reportId) {
        // Ensure table exists
        $this->ensureCommentsTable();

        $stmt = $this->pdo->prepare("
            SELECT
                c.*,
                u.username as author_name,
                u.role as author_role,
                pc.content as parent_content,
                pu.username as parent_author_name
            FROM report_comments c
            JOIN users u ON c.user_id = u.id
            LEFT JOIN report_comments pc ON c.parent_comment_id = pc.id
            LEFT JOIN users pu ON pc.user_id = pu.id
            WHERE c.report_id = ? AND c.is_deleted = 0
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$reportId]);
        return $stmt->fetchAll();
    }

    /**
     * Get comment count for a report
     */
    public function getReportCommentCount($reportId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count FROM report_comments
                WHERE report_id = ? AND is_deleted = 0
            ");
            $stmt->execute([$reportId]);
            return $stmt->fetch()['count'];
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Edit a comment
     */
    public function editComment($commentId, $newContent, $userId) {
        $comment = $this->getComment($commentId);
        if (!$comment) {
            return ['success' => false, 'error' => 'Comment not found'];
        }

        // Check if user owns the comment or is admin
        $user = $this->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        if ($comment['user_id'] != $userId && $user['role'] !== 'admin') {
            return ['success' => false, 'error' => 'Not authorized to edit this comment'];
        }

        $stmt = $this->pdo->prepare("
            UPDATE report_comments
            SET content = ?, updated_at = NOW(), is_edited = 1
            WHERE id = ?
        ");
        $stmt->execute([$newContent, $commentId]);

        return ['success' => true];
    }

    /**
     * Delete a comment (soft delete)
     */
    public function deleteComment($commentId, $userId) {
        $comment = $this->getComment($commentId);
        if (!$comment) {
            return ['success' => false, 'error' => 'Comment not found'];
        }

        // Check if user owns the comment or is admin
        $user = $this->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        if ($comment['user_id'] != $userId && $user['role'] !== 'admin') {
            return ['success' => false, 'error' => 'Not authorized to delete this comment'];
        }

        $stmt = $this->pdo->prepare("
            UPDATE report_comments
            SET is_deleted = 1, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$commentId]);

        return ['success' => true];
    }

    /**
     * Check if user can edit/delete a comment
     */
    public function canManageComment($commentId, $userId) {
        $comment = $this->getComment($commentId);
        if (!$comment) return false;

        $user = $this->getUserById($userId);
        if (!$user) return false;

        return ($comment['user_id'] == $userId || $user['role'] === 'admin');
    }

    // =====================
    // TEST TEMPLATES
    // =====================

    /**
     * Ensure test_templates table exists
     */
    public function ensureTemplatesTable() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS test_templates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    description TEXT,
                    test_keys JSON NOT NULL COMMENT 'Array of test keys included in this template',
                    created_by INT NOT NULL,
                    is_default TINYINT(1) NOT NULL DEFAULT 0,
                    is_system TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_name (name),
                    INDEX idx_is_default (is_default),
                    INDEX idx_created_by (created_by)
                ) ENGINE=InnoDB
            ");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get all test templates
     */
    public function getTestTemplates() {
        $this->ensureTemplatesTable();
        $stmt = $this->pdo->query("
            SELECT t.*, u.username as creator_name
            FROM test_templates t
            LEFT JOIN users u ON t.created_by = u.id
            ORDER BY t.is_default DESC, t.is_system DESC, t.name
        ");
        $templates = $stmt->fetchAll();

        foreach ($templates as &$template) {
            $template['test_keys'] = json_decode($template['test_keys'], true) ?? [];
        }

        return $templates;
    }

    /**
     * Get a single test template
     */
    public function getTestTemplate($id) {
        $this->ensureTemplatesTable();
        $stmt = $this->pdo->prepare("
            SELECT t.*, u.username as creator_name
            FROM test_templates t
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.id = ?
        ");
        $stmt->execute([$id]);
        $template = $stmt->fetch();

        if ($template) {
            $template['test_keys'] = json_decode($template['test_keys'], true) ?? [];
        }

        return $template ?: null;
    }

    /**
     * Get the default test template
     */
    public function getDefaultTemplate() {
        $this->ensureTemplatesTable();
        $stmt = $this->pdo->query("SELECT * FROM test_templates WHERE is_default = 1 LIMIT 1");
        $template = $stmt->fetch();

        if ($template) {
            $template['test_keys'] = json_decode($template['test_keys'], true) ?? [];
        }

        return $template ?: null;
    }

    /**
     * Create a test template
     */
    public function createTestTemplate($name, $description, $testKeys, $createdBy, $isDefault = false, $isSystem = false) {
        $this->ensureTemplatesTable();

        // If setting as default, clear other defaults first
        if ($isDefault) {
            $this->pdo->exec("UPDATE test_templates SET is_default = 0");
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO test_templates (name, description, test_keys, created_by, is_default, is_system)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $description, json_encode($testKeys), $createdBy, $isDefault ? 1 : 0, $isSystem ? 1 : 0]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Update a test template
     */
    public function updateTestTemplate($id, $name, $description, $testKeys, $isDefault = null) {
        $updates = ["name = ?", "description = ?", "test_keys = ?"];
        $params = [$name, $description, json_encode($testKeys)];

        if ($isDefault !== null) {
            if ($isDefault) {
                $this->pdo->exec("UPDATE test_templates SET is_default = 0");
            }
            $updates[] = "is_default = ?";
            $params[] = $isDefault ? 1 : 0;
        }

        $params[] = $id;
        $stmt = $this->pdo->prepare("UPDATE test_templates SET " . implode(", ", $updates) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    /**
     * Delete a test template
     */
    public function deleteTestTemplate($id) {
        // Don't allow deleting system templates
        $template = $this->getTestTemplate($id);
        if ($template && $template['is_system']) {
            return false;
        }

        $stmt = $this->pdo->prepare("DELETE FROM test_templates WHERE id = ? AND is_system = 0");
        return $stmt->execute([$id]);
    }

    /**
     * Create default template from current TEST_KEYS
     */
    public function createDefaultTemplateIfNotExists($userId) {
        $this->ensureTemplatesTable();

        // Check if default template already exists
        $existing = $this->getDefaultTemplate();
        if ($existing) {
            return $existing['id'];
        }

        // Create default template with all tests
        $allTestKeys = array_keys(TEST_KEYS);

        return $this->createTestTemplate(
            'Full Test Suite',
            'Complete test template including all available tests. This is the default template used when no other template is selected.',
            $allTestKeys,
            $userId,
            true,  // isDefault
            true   // isSystem
        );
    }

    // =====================
    // TEMPLATE-VERSION ASSIGNMENTS
    // =====================

    /**
     * Ensure test_template_versions junction table exists
     */
    public function ensureTemplateVersionsTable() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS test_template_versions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    template_id INT NOT NULL,
                    client_version_id INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_template_version (template_id, client_version_id),
                    INDEX idx_template_id (template_id),
                    INDEX idx_client_version_id (client_version_id)
                ) ENGINE=InnoDB
            ");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get client version IDs assigned to a template
     */
    public function getTemplateVersionIds($templateId) {
        $this->ensureTemplateVersionsTable();
        $stmt = $this->pdo->prepare("
            SELECT client_version_id FROM test_template_versions WHERE template_id = ?
        ");
        $stmt->execute([$templateId]);
        return array_column($stmt->fetchAll(), 'client_version_id');
    }

    /**
     * Get client versions assigned to a template (full objects)
     */
    public function getTemplateVersions($templateId) {
        $this->ensureTemplateVersionsTable();
        $this->ensureClientVersionsTable();
        $stmt = $this->pdo->prepare("
            SELECT cv.*
            FROM client_versions cv
            INNER JOIN test_template_versions ttv ON cv.id = ttv.client_version_id
            WHERE ttv.template_id = ?
            ORDER BY cv.sort_order ASC, cv.steam_date DESC
        ");
        $stmt->execute([$templateId]);
        $versions = $stmt->fetchAll();

        foreach ($versions as &$version) {
            $version['packages'] = json_decode($version['packages'], true) ?? [];
            $version['skip_tests'] = json_decode($version['skip_tests'], true) ?? [];
        }

        return $versions;
    }

    /**
     * Set client versions for a template (replaces existing assignments)
     */
    public function setTemplateVersions($templateId, $versionIds) {
        $this->ensureTemplateVersionsTable();

        // Delete existing assignments
        $stmt = $this->pdo->prepare("DELETE FROM test_template_versions WHERE template_id = ?");
        $stmt->execute([$templateId]);

        // Insert new assignments
        if (!empty($versionIds)) {
            $stmt = $this->pdo->prepare("
                INSERT INTO test_template_versions (template_id, client_version_id)
                VALUES (?, ?)
            ");
            foreach ($versionIds as $versionId) {
                $stmt->execute([$templateId, $versionId]);
            }
        }

        return true;
    }

    /**
     * Set a single template for a client version (one-to-one relationship)
     * Removes version from any existing template first, then assigns to new template
     * If templateId is 0 or null, just removes from all templates (uses default)
     *
     * @param int $clientVersionId The client version database ID
     * @param int|null $templateId The template ID to assign, or null/0 to use default
     * @return bool Success
     */
    public function setVersionTemplate($clientVersionId, $templateId = null) {
        $this->ensureTemplateVersionsTable();

        // First, remove this version from ALL templates (enforce one-to-one)
        $stmt = $this->pdo->prepare("DELETE FROM test_template_versions WHERE client_version_id = ?");
        $stmt->execute([$clientVersionId]);

        // If a specific template is requested (not default), assign it
        if ($templateId && $templateId > 0) {
            $stmt = $this->pdo->prepare("
                INSERT INTO test_template_versions (template_id, client_version_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$templateId, $clientVersionId]);
        }

        return true;
    }

    /**
     * Get template for a specific client version
     * Returns version-specific template if assigned, otherwise returns default template
     */
    public function getTemplateForVersion($clientVersionId) {
        $this->ensureTemplateVersionsTable();

        // First check for a version-specific template
        $stmt = $this->pdo->prepare("
            SELECT t.*, u.username as creator_name
            FROM test_templates t
            INNER JOIN test_template_versions ttv ON t.id = ttv.template_id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE ttv.client_version_id = ?
            LIMIT 1
        ");
        $stmt->execute([$clientVersionId]);
        $template = $stmt->fetch();

        // If no version-specific template, get the default
        if (!$template) {
            $template = $this->getDefaultTemplate();
        }

        if ($template) {
            // Only decode if test_keys is still a string (not already decoded)
            if (is_string($template['test_keys'])) {
                $template['test_keys'] = json_decode($template['test_keys'], true) ?? [];
            }
        }

        return $template ?: null;
    }

    /**
     * Get template for a client version by version_id string
     */
    public function getTemplateForVersionString($versionId) {
        $version = $this->getClientVersionByVersionId($versionId);
        if (!$version) {
            return $this->getDefaultTemplate();
        }
        return $this->getTemplateForVersion($version['id']);
    }

    /**
     * Get visible tests for a client version, filtered by applicable template.
     * Resolution: version-specific template → default template → all tests
     *
     * @param string|null $versionString The client version string
     * @param bool $returnMetadata If true, returns ['categories' => [...], 'template' => [...]]
     * @return array Categories array filtered by template, or full response with metadata
     */
    public function getVisibleTestsForVersion($versionString, $returnMetadata = false) {
        // Resolve template for this version
        $template = $versionString ? $this->getTemplateForVersionString($versionString) : null;

        // Get all test categories (from DB or TEST_KEYS constant)
        $allCategories = $this->hasTestCategoriesTable()
            ? $this->getTestTypesGrouped(true)
            : getTestCategories();

        // No template or empty test_keys = return all tests (no filtering)
        if (!$template || empty($template['test_keys'])) {
            if ($returnMetadata) {
                return [
                    'categories' => $allCategories,
                    'template' => null,
                    'filtered' => false
                ];
            }
            return $allCategories;
        }

        // Filter categories to only include tests in template
        // Normalize test keys to strings for consistent comparison
        $normalizedKeys = [];
        foreach ($template['test_keys'] as $key) {
            $normalizedKeys[(string)$key] = true;
        }
        $filteredCategories = [];

        foreach ($allCategories as $categoryName => $tests) {
            $filteredTests = [];
            foreach ($tests as $testKey => $testInfo) {
                if (isset($normalizedKeys[(string)$testKey])) {
                    $filteredTests[$testKey] = $testInfo;
                }
            }
            // Only include category if it has visible tests
            if (!empty($filteredTests)) {
                $filteredCategories[$categoryName] = $filteredTests;
            }
        }

        if ($returnMetadata) {
            return [
                'categories' => $filteredCategories,
                'template' => [
                    'id' => $template['id'],
                    'name' => $template['name'],
                    'is_default' => (bool)$template['is_default'],
                    'test_count' => count($template['test_keys']),
                    'test_keys' => $template['test_keys']
                ],
                'filtered' => true
            ];
        }

        return $filteredCategories;
    }

    /**
     * Get all templates with their version assignments
     */
    public function getTestTemplatesWithVersions() {
        $templates = $this->getTestTemplates();

        foreach ($templates as &$template) {
            $template['assigned_versions'] = $this->getTemplateVersionIds($template['id']);
        }

        return $templates;
    }

    /**
     * Get all test_keys that have been tested (submitted in reports) for specific client versions
     * Returns array of test_keys with their submission info
     */
    public function getTestedTestKeysForVersions($versionStrings = []) {
        if (empty($versionStrings)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($versionStrings), '?'));

        // Get all distinct test_keys that have been submitted for these versions
        // with status other than N/A (meaning they were actually tested)
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT tr.test_key, r.client_version, tr.status,
                   COUNT(DISTINCT r.id) as report_count,
                   MAX(r.submitted_at) as last_submitted
            FROM test_results tr
            INNER JOIN reports r ON tr.report_id = r.id
            WHERE r.client_version IN ($placeholders)
              AND tr.status != 'N/A'
              AND tr.status != ''
            GROUP BY tr.test_key, r.client_version, tr.status
            ORDER BY tr.test_key
        ");
        $stmt->execute($versionStrings);
        $results = $stmt->fetchAll();

        // Group by test_key with aggregated version info
        $testedKeys = [];
        foreach ($results as $row) {
            $key = $row['test_key'];
            if (!isset($testedKeys[$key])) {
                $testedKeys[$key] = [
                    'test_key' => $key,
                    'versions' => [],
                    'total_reports' => 0
                ];
            }
            $testedKeys[$key]['versions'][] = $row['client_version'];
            $testedKeys[$key]['total_reports'] += $row['report_count'];
        }

        // Remove duplicate versions
        foreach ($testedKeys as &$item) {
            $item['versions'] = array_unique($item['versions']);
        }

        return $testedKeys;
    }

    /**
     * Get test_keys that have not yet been tested for specific client versions
     * Uses the template's test_keys and subtracts already tested ones
     */
    public function getUntestedTestKeysForVersions($templateTestKeys, $versionStrings = []) {
        if (empty($versionStrings)) {
            return $templateTestKeys;
        }

        $testedKeys = $this->getTestedTestKeysForVersions($versionStrings);
        $testedKeyNames = array_keys($testedKeys);

        return array_values(array_diff($templateTestKeys, $testedKeyNames));
    }

    // =====================
    // REPORT TAGS
    // =====================

    /**
     * Ensure tags tables exist
     */
    public function ensureTagsTables() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS report_tags (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(50) NOT NULL UNIQUE,
                    color VARCHAR(7) NOT NULL DEFAULT '#808080',
                    description VARCHAR(255) DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_name (name)
                ) ENGINE=InnoDB
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS report_tag_assignments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    report_id INT NOT NULL,
                    tag_id INT NOT NULL,
                    assigned_by INT NOT NULL,
                    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_report_tag (report_id, tag_id),
                    INDEX idx_report_id (report_id),
                    INDEX idx_tag_id (tag_id)
                ) ENGINE=InnoDB
            ");

            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get all tags
     */
    public function getAllTags() {
        $this->ensureTagsTables();
        $stmt = $this->pdo->query("
            SELECT t.*, COUNT(rta.id) as usage_count
            FROM report_tags t
            LEFT JOIN report_tag_assignments rta ON t.id = rta.tag_id
            GROUP BY t.id
            ORDER BY t.name
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get a single tag
     */
    public function getTag($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM report_tags WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get tag by name
     */
    public function getTagByName($name) {
        $stmt = $this->pdo->prepare("SELECT * FROM report_tags WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create a tag
     */
    public function createTag($name, $color = '#808080', $description = null) {
        $this->ensureTagsTables();
        $stmt = $this->pdo->prepare("
            INSERT INTO report_tags (name, color, description)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$name, $color, $description]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Update a tag
     */
    public function updateTag($id, $name, $color, $description = null) {
        $stmt = $this->pdo->prepare("
            UPDATE report_tags SET name = ?, color = ?, description = ?
            WHERE id = ?
        ");
        return $stmt->execute([$name, $color, $description, $id]);
    }

    /**
     * Delete a tag
     */
    public function deleteTag($id) {
        // Cascade deletes handle assignments
        $stmt = $this->pdo->prepare("DELETE FROM report_tags WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get tags for a report
     */
    public function getReportTags($reportId) {
        $this->ensureTagsTables();
        $stmt = $this->pdo->prepare("
            SELECT t.*, rta.assigned_at, u.username as assigned_by_name
            FROM report_tags t
            JOIN report_tag_assignments rta ON t.id = rta.tag_id
            LEFT JOIN users u ON rta.assigned_by = u.id
            WHERE rta.report_id = ?
            ORDER BY t.name
        ");
        $stmt->execute([$reportId]);
        return $stmt->fetchAll();
    }

    /**
     * Add tag to report
     */
    public function addTagToReport($reportId, $tagId, $assignedBy) {
        $this->ensureTagsTables();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO report_tag_assignments (report_id, tag_id, assigned_by)
                VALUES (?, ?, ?)
            ");
            return $stmt->execute([$reportId, $tagId, $assignedBy]);
        } catch (PDOException $e) {
            // Duplicate key - tag already assigned
            return false;
        }
    }

    /**
     * Remove tag from report
     */
    public function removeTagFromReport($reportId, $tagId) {
        $stmt = $this->pdo->prepare("
            DELETE FROM report_tag_assignments
            WHERE report_id = ? AND tag_id = ?
        ");
        return $stmt->execute([$reportId, $tagId]);
    }

    /**
     * Get reports by tag
     */
    public function getReportsByTag($tagId, $limit = 50, $offset = 0) {
        $stmt = $this->pdo->prepare("
            SELECT r.*
            FROM reports r
            JOIN report_tag_assignments rta ON r.id = rta.report_id
            WHERE rta.tag_id = ?
            ORDER BY r.submitted_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$tagId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Initialize default tags
     */
    public function initializeDefaultTags() {
        $this->ensureTagsTables();

        $defaultTags = [
            ['verified', '#27ae60', 'Report has been verified by admin'],
            ['needs-review', '#f39c12', 'Report needs admin review'],
            ['regression', '#e74c3c', 'Report shows regression from previous version'],
            ['incomplete', '#95a5a6', 'Report is incomplete or missing tests'],
            ['milestone', '#9b59b6', 'Important milestone release'],
            ['bugfix', '#3498db', 'Report for a bugfix build'],
        ];

        foreach ($defaultTags as $tag) {
            if (!$this->getTagByName($tag[0])) {
                $this->createTag($tag[0], $tag[1], $tag[2]);
            }
        }
    }

    // =====================
    // CLIENT VERSIONS
    // =====================

    /**
     * Ensure client_versions table exists
     */
    public function ensureClientVersionsTable() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS client_versions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    version_id VARCHAR(255) NOT NULL UNIQUE,
                    display_name VARCHAR(255) DEFAULT NULL,
                    steam_date DATE DEFAULT NULL,
                    steam_time VARCHAR(20) DEFAULT NULL,
                    packages JSON,
                    skip_tests JSON,
                    sort_order INT NOT NULL DEFAULT 0,
                    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                    created_by INT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_version_id (version_id),
                    INDEX idx_steam_date (steam_date),
                    INDEX idx_sort_order (sort_order),
                    INDEX idx_is_enabled (is_enabled)
                ) ENGINE=InnoDB
            ");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Check if client_versions table exists
     */
    public function hasClientVersionsTable() {
        try {
            $this->pdo->query("SELECT 1 FROM client_versions LIMIT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get all client versions
     */
    public function getClientVersions($enabledOnly = false) {
        $this->ensureClientVersionsTable();
        $where = $enabledOnly ? "WHERE is_enabled = 1" : "";
        $stmt = $this->pdo->query("
            SELECT cv.*, u.username as creator_name
            FROM client_versions cv
            LEFT JOIN users u ON cv.created_by = u.id
            $where
            ORDER BY cv.sort_order ASC, cv.steam_date DESC
        ");
        $versions = $stmt->fetchAll();

        foreach ($versions as &$version) {
            $version['packages'] = json_decode($version['packages'], true) ?? [];
            $version['skip_tests'] = json_decode($version['skip_tests'], true) ?? [];
        }

        return $versions;
    }

    /**
     * Get a single client version by ID
     */
    public function getClientVersion($id) {
        $this->ensureClientVersionsTable();
        $stmt = $this->pdo->prepare("
            SELECT cv.*, u.username as creator_name
            FROM client_versions cv
            LEFT JOIN users u ON cv.created_by = u.id
            WHERE cv.id = ?
        ");
        $stmt->execute([$id]);
        $version = $stmt->fetch();

        if ($version) {
            $version['packages'] = json_decode($version['packages'], true) ?? [];
            $version['skip_tests'] = json_decode($version['skip_tests'], true) ?? [];
        }

        return $version ?: null;
    }

    /**
     * Get client version by version_id string
     * Tries exact match first, then falls back to matching without trailing commit hash
     */
    public function getClientVersionByVersionId($versionId) {
        $this->ensureClientVersionsTable();

        // First try exact match
        $stmt = $this->pdo->prepare("
            SELECT cv.*, u.username as creator_name
            FROM client_versions cv
            LEFT JOIN users u ON cv.created_by = u.id
            WHERE cv.version_id = ?
        ");
        $stmt->execute([$versionId]);
        $version = $stmt->fetch();

        // If no exact match, try matching without trailing commit hash
        // Report's client_version might have a hash suffix that the stored version_id doesn't
        if (!$version && $versionId) {
            $cleanedVersionId = preg_replace('/[\s_]+[(\[]?[0-9a-fA-F]{7,40}[)\]]?\s*$/', '', $versionId);
            $cleanedVersionId = trim($cleanedVersionId);

            if ($cleanedVersionId !== $versionId) {
                $stmt->execute([$cleanedVersionId]);
                $version = $stmt->fetch();
            }
        }

        // If still no match, try matching the stored version_id against our input
        // Stored version_id might have a hash suffix that our input doesn't
        if (!$version && $versionId) {
            $stmt = $this->pdo->prepare("
                SELECT cv.*, u.username as creator_name
                FROM client_versions cv
                LEFT JOIN users u ON cv.created_by = u.id
                WHERE cv.version_id LIKE CONCAT(?, '%')
                   OR ? LIKE CONCAT(cv.version_id, '%')
                LIMIT 1
            ");
            $stmt->execute([$versionId, $versionId]);
            $version = $stmt->fetch();
        }

        if ($version) {
            $version['packages'] = json_decode($version['packages'], true) ?? [];
            $version['skip_tests'] = json_decode($version['skip_tests'], true) ?? [];
        }

        return $version ?: null;
    }

    /**
     * Create a client version
     */
    public function createClientVersion($versionId, $displayName, $steamDate, $steamTime, $packages, $skipTests, $sortOrder, $isEnabled, $createdBy) {
        $this->ensureClientVersionsTable();
        $stmt = $this->pdo->prepare("
            INSERT INTO client_versions (version_id, display_name, steam_date, steam_time, packages, skip_tests, sort_order, is_enabled, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $versionId,
            $displayName ?: null,
            $steamDate ?: null,
            $steamTime ?: null,
            json_encode($packages ?: []),
            json_encode($skipTests ?: []),
            $sortOrder,
            $isEnabled ? 1 : 0,
            $createdBy
        ]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Update a client version
     */
    public function updateClientVersion($id, $versionId, $displayName, $steamDate, $steamTime, $packages, $skipTests, $sortOrder, $isEnabled) {
        $stmt = $this->pdo->prepare("
            UPDATE client_versions SET
                version_id = ?,
                display_name = ?,
                steam_date = ?,
                steam_time = ?,
                packages = ?,
                skip_tests = ?,
                sort_order = ?,
                is_enabled = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $versionId,
            $displayName ?: null,
            $steamDate ?: null,
            $steamTime ?: null,
            json_encode($packages ?: []),
            json_encode($skipTests ?: []),
            $sortOrder,
            $isEnabled ? 1 : 0,
            $id
        ]);
    }

    /**
     * Delete a client version
     */
    public function deleteClientVersion($id) {
        // Notifications are cascade deleted
        $stmt = $this->pdo->prepare("DELETE FROM client_versions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get max sort order for client versions
     */
    public function getMaxClientVersionSortOrder() {
        $this->ensureClientVersionsTable();
        $stmt = $this->pdo->query("SELECT MAX(sort_order) as max_order FROM client_versions");
        $row = $stmt->fetch();
        return $row['max_order'] ?? 0;
    }

    /**
     * Count client versions
     */
    public function countClientVersions($enabledOnly = false) {
        $this->ensureClientVersionsTable();
        $where = $enabledOnly ? "WHERE is_enabled = 1" : "";
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM client_versions $where");
        return $stmt->fetch()['count'];
    }

    // =====================
    // VERSION NOTIFICATIONS
    // =====================

    /**
     * Ensure version_notifications table exists
     */
    public function ensureVersionNotificationsTable() {
        $this->ensureClientVersionsTable();
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS version_notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_version_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    commit_hash VARCHAR(50) DEFAULT NULL,
                    created_by INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_client_version (client_version_id),
                    INDEX idx_commit_hash (commit_hash),
                    INDEX idx_created_at (created_at),
                    UNIQUE KEY unique_version_name (client_version_id, name)
                ) ENGINE=InnoDB
            ");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Check if version_notifications table exists
     */
    public function hasVersionNotificationsTable() {
        try {
            $this->pdo->query("SELECT 1 FROM version_notifications LIMIT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get all version notifications
     */
    public function getVersionNotifications($limit = 100, $offset = 0) {
        $this->ensureVersionNotificationsTable();
        $stmt = $this->pdo->prepare("
            SELECT vn.*, cv.version_id as client_version, u.username as created_by_name
            FROM version_notifications vn
            JOIN client_versions cv ON vn.client_version_id = cv.id
            JOIN users u ON vn.created_by = u.id
            ORDER BY vn.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Get a single version notification
     */
    public function getVersionNotification($id) {
        $this->ensureVersionNotificationsTable();
        $stmt = $this->pdo->prepare("
            SELECT vn.*, cv.version_id as client_version, u.username as created_by_name
            FROM version_notifications vn
            JOIN client_versions cv ON vn.client_version_id = cv.id
            JOIN users u ON vn.created_by = u.id
            WHERE vn.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get notifications for a specific client version
     * If commitHash is provided, also include notifications matching that commit
     */
    public function getNotificationsForVersion($clientVersionId, $commitHash = null) {
        $this->ensureVersionNotificationsTable();

        if ($commitHash) {
            // Get notifications that either have no commit hash OR match the provided commit hash
            $stmt = $this->pdo->prepare("
                SELECT vn.*, cv.version_id as client_version, u.username as created_by_name
                FROM version_notifications vn
                JOIN client_versions cv ON vn.client_version_id = cv.id
                JOIN users u ON vn.created_by = u.id
                WHERE vn.client_version_id = ?
                  AND (vn.commit_hash IS NULL OR vn.commit_hash = '' OR vn.commit_hash = ?)
                ORDER BY vn.created_at ASC
            ");
            $stmt->execute([$clientVersionId, $commitHash]);
        } else {
            // Get only notifications without a commit hash
            $stmt = $this->pdo->prepare("
                SELECT vn.*, cv.version_id as client_version, u.username as created_by_name
                FROM version_notifications vn
                JOIN client_versions cv ON vn.client_version_id = cv.id
                JOIN users u ON vn.created_by = u.id
                WHERE vn.client_version_id = ?
                  AND (vn.commit_hash IS NULL OR vn.commit_hash = '')
                ORDER BY vn.created_at ASC
            ");
            $stmt->execute([$clientVersionId]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Get notifications for a client version by version_id string
     */
    public function getNotificationsForVersionString($versionId, $commitHash = null) {
        $version = $this->getClientVersionByVersionId($versionId);
        if (!$version) {
            return [];
        }
        return $this->getNotificationsForVersion($version['id'], $commitHash);
    }

    /**
     * Create a version notification
     */
    public function createVersionNotification($clientVersionId, $name, $message, $commitHash, $createdBy) {
        $this->ensureVersionNotificationsTable();
        $stmt = $this->pdo->prepare("
            INSERT INTO version_notifications (client_version_id, name, message, commit_hash, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $clientVersionId,
            $name,
            $message,
            $commitHash ?: null,
            $createdBy
        ]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Update a version notification
     */
    public function updateVersionNotification($id, $clientVersionId, $name, $message, $commitHash) {
        $stmt = $this->pdo->prepare("
            UPDATE version_notifications SET
                client_version_id = ?,
                name = ?,
                message = ?,
                commit_hash = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $clientVersionId,
            $name,
            $message,
            $commitHash ?: null,
            $id
        ]);
    }

    /**
     * Delete a version notification
     */
    public function deleteVersionNotification($id) {
        $stmt = $this->pdo->prepare("DELETE FROM version_notifications WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Count version notifications
     */
    public function countVersionNotifications() {
        $this->ensureVersionNotificationsTable();
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM version_notifications");
        return $stmt->fetch()['count'];
    }

    /**
     * Get notifications grouped by client version for display
     */
    public function getNotificationsGroupedByVersion() {
        $this->ensureVersionNotificationsTable();
        $notifications = $this->getVersionNotifications(500, 0);
        $grouped = [];

        foreach ($notifications as $notification) {
            $versionId = $notification['client_version'];
            if (!isset($grouped[$versionId])) {
                $grouped[$versionId] = [];
            }
            $grouped[$versionId][] = $notification;
        }

        return $grouped;
    }

    // =====================
    // INVITE CODES
    // =====================

    /**
     * Ensure invite_codes table exists
     */
    public function ensureInviteCodesTable() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS invite_codes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(64) NOT NULL UNIQUE,
                    created_by INT NOT NULL COMMENT 'Admin who created this invite',
                    used_by INT DEFAULT NULL COMMENT 'User who used this invite',
                    expires_at DATETIME NOT NULL COMMENT 'Expiration time (3 days from creation)',
                    used_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_code (code),
                    INDEX idx_created_by (created_by),
                    INDEX idx_expires_at (expires_at),
                    INDEX idx_used_by (used_by),
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB
            ");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Generate a random invite code
     */
    private function generateInviteCode() {
        return 'INV-' . strtoupper(bin2hex(random_bytes(12)));
    }

    /**
     * Create a new invite code
     * @param int $createdBy User ID of the admin creating the invite
     * @param int $expiryDays Number of days until expiration (default: 3)
     * @return array|false The invite code record or false on failure
     */
    public function createInviteCode($createdBy, $expiryDays = 3) {
        $this->ensureInviteCodesTable();

        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));

        // Retry up to 3 times in case of extremely unlikely code collision
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $code = $this->generateInviteCode();

            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO invite_codes (code, created_by, expires_at, created_at)
                    VALUES (?, ?, ?, NOW())
                ");

                if ($stmt->execute([$code, $createdBy, $expiresAt])) {
                    // Use lastInsertId for reliable retrieval of the just-inserted row
                    $insertedId = $this->pdo->lastInsertId();
                    if ($insertedId) {
                        return $this->getInviteCodeById((int)$insertedId);
                    }
                    // Fallback to code lookup if lastInsertId fails
                    return $this->getInviteCode($code);
                }
            } catch (PDOException $e) {
                // Duplicate key error (code 23000) - regenerate and retry
                if ($e->getCode() == 23000) {
                    continue;
                }
                throw $e;
            }
        }

        return false;
    }

    /**
     * Get an invite code by its code string
     */
    public function getInviteCode($code) {
        $this->ensureInviteCodesTable();
        $stmt = $this->pdo->prepare("
            SELECT ic.*, u_created.username as created_by_username, u_used.username as used_by_username
            FROM invite_codes ic
            LEFT JOIN users u_created ON ic.created_by = u_created.id
            LEFT JOIN users u_used ON ic.used_by = u_used.id
            WHERE ic.code = ?
        ");
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get an invite code by ID
     */
    public function getInviteCodeById($id) {
        $this->ensureInviteCodesTable();
        $stmt = $this->pdo->prepare("
            SELECT ic.*, u_created.username as created_by_username, u_used.username as used_by_username
            FROM invite_codes ic
            LEFT JOIN users u_created ON ic.created_by = u_created.id
            LEFT JOIN users u_used ON ic.used_by = u_used.id
            WHERE ic.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all invite codes with pagination
     */
    public function getInviteCodes($limit = 50, $offset = 0, $filters = []) {
        $this->ensureInviteCodesTable();

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            switch ($filters['status']) {
                case 'valid':
                    $where[] = "ic.used_by IS NULL AND ic.expires_at > NOW()";
                    break;
                case 'used':
                    $where[] = "ic.used_by IS NOT NULL";
                    break;
                case 'expired':
                    $where[] = "ic.used_by IS NULL AND ic.expires_at <= NOW()";
                    break;
            }
        }

        if (!empty($filters['created_by'])) {
            $where[] = "ic.created_by = ?";
            $params[] = $filters['created_by'];
        }

        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $this->pdo->prepare("
            SELECT ic.*, u_created.username as created_by_username, u_used.username as used_by_username
            FROM invite_codes ic
            LEFT JOIN users u_created ON ic.created_by = u_created.id
            LEFT JOIN users u_used ON ic.used_by = u_used.id
            $whereClause
            ORDER BY ic.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Get count of invite codes
     */
    public function getInviteCodesCount($filters = []) {
        $this->ensureInviteCodesTable();

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            switch ($filters['status']) {
                case 'valid':
                    $where[] = "used_by IS NULL AND expires_at > NOW()";
                    break;
                case 'used':
                    $where[] = "used_by IS NOT NULL";
                    break;
                case 'expired':
                    $where[] = "used_by IS NULL AND expires_at <= NOW()";
                    break;
            }
        }

        if (!empty($filters['created_by'])) {
            $where[] = "created_by = ?";
            $params[] = $filters['created_by'];
        }

        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM invite_codes $whereClause");
        $stmt->execute($params);

        return $stmt->fetch()['count'];
    }

    /**
     * Validate an invite code for registration
     * @return array ['valid' => bool, 'error' => string|null, 'invite' => array|null]
     */
    public function validateInviteCode($code) {
        $invite = $this->getInviteCode($code);

        if (!$invite) {
            return ['valid' => false, 'error' => 'Invalid invite code', 'invite' => null];
        }

        if ($invite['used_by'] !== null) {
            return ['valid' => false, 'error' => 'This invite code has already been used', 'invite' => $invite];
        }

        if (strtotime($invite['expires_at']) < time()) {
            return ['valid' => false, 'error' => 'This invite code has expired', 'invite' => $invite];
        }

        return ['valid' => true, 'error' => null, 'invite' => $invite];
    }

    /**
     * Use an invite code (mark it as used and create user)
     * @return array ['success' => bool, 'error' => string|null, 'user' => array|null]
     */
    public function useInviteCode($code, $username, $password) {
        // Check if username is valid (do this before transaction to fail fast)
        if (strlen($username) < 3) {
            return ['success' => false, 'error' => 'Username must be at least 3 characters', 'user' => null];
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return ['success' => false, 'error' => 'Username can only contain letters, numbers, and underscores', 'user' => null];
        }

        // Check if username already exists
        if ($this->getUser($username)) {
            return ['success' => false, 'error' => 'Username already taken', 'user' => null];
        }

        // Check password
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Password must be at least 6 characters', 'user' => null];
        }

        // Start transaction for atomic invite code claim + user creation
        $this->pdo->beginTransaction();

        try {
            // Lock and validate the invite code within the transaction
            // This prevents race conditions where multiple users try to use the same code
            $stmt = $this->pdo->prepare("
                SELECT id, code, used_by, expires_at
                FROM invite_codes
                WHERE code = ?
                FOR UPDATE
            ");
            $stmt->execute([$code]);
            $invite = $stmt->fetch();

            if (!$invite) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Invalid invite code', 'user' => null];
            }

            if ($invite['used_by'] !== null) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'This invite code has already been used', 'user' => null];
            }

            if (strtotime($invite['expires_at']) < time()) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'This invite code has expired', 'user' => null];
            }

            // Create the user
            $user = $this->createUser($username, $password, 'user');
            if (!$user) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Failed to create user', 'user' => null];
            }

            // Mark invite code as used - use ID for precise targeting
            $stmt = $this->pdo->prepare("
                UPDATE invite_codes
                SET used_by = ?, used_at = NOW()
                WHERE id = ? AND used_by IS NULL
            ");
            $stmt->execute([$user['id'], $invite['id']]);

            // Verify exactly one row was updated
            if ($stmt->rowCount() !== 1) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Failed to claim invite code - it may have been used by another user', 'user' => null];
            }

            $this->pdo->commit();
            return ['success' => true, 'error' => null, 'user' => $user];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => 'Registration failed: ' . $e->getMessage(), 'user' => null];
        }
    }

    /**
     * Delete an invite code
     */
    public function deleteInviteCode($id) {
        $this->ensureInviteCodesTable();
        $stmt = $this->pdo->prepare("DELETE FROM invite_codes WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Delete expired and unused invite codes (cleanup)
     */
    public function cleanupExpiredInviteCodes() {
        $this->ensureInviteCodesTable();
        $stmt = $this->pdo->prepare("DELETE FROM invite_codes WHERE used_by IS NULL AND expires_at < NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Get invite code statistics
     */
    public function getInviteCodeStats() {
        $this->ensureInviteCodesTable();
        $stmt = $this->pdo->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN used_by IS NOT NULL THEN 1 ELSE 0 END) as used,
                SUM(CASE WHEN used_by IS NULL AND expires_at > NOW() THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN used_by IS NULL AND expires_at <= NOW() THEN 1 ELSE 0 END) as expired
            FROM invite_codes
        ");
        return $stmt->fetch();
    }

    // =====================
    // REGRESSION/PROGRESSION TRACKING
    // =====================

    /**
     * Get recent regressions from report revisions
     * A regression is when a test goes from a better status to a worse status
     * Status priority: Working (3) > Semi-working (2) > Not working (1)
     *
     * @param int $limit Maximum number of regressions to return
     * @return array List of recent regressions with details
     */
    public function getRecentRegressions($limit = 10) {
        $regressions = [];

        // Get recent revisions that have changes_diff
        $stmt = $this->pdo->prepare("
            SELECT rr.*, r.client_version, r.tester, r.test_type, r.commit_hash
            FROM report_revisions rr
            JOIN reports r ON rr.report_id = r.id
            WHERE rr.changes_diff IS NOT NULL
            ORDER BY rr.archived_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit * 5]); // Get more than needed since not all may have regressions
        $revisions = $stmt->fetchAll();

        $statusPriority = [
            'Working' => 3,
            'Semi-working' => 2,
            'Not working' => 1,
            'N/A' => 0,
            '' => 0
        ];

        foreach ($revisions as $revision) {
            $diff = json_decode($revision['changes_diff'], true);
            if (!$diff || empty($diff['changed'])) {
                continue;
            }

            foreach ($diff['changed'] as $change) {
                $oldPriority = $statusPriority[$change['old_status']] ?? 0;
                $newPriority = $statusPriority[$change['new_status']] ?? 0;

                // Regression: new status is worse (lower priority) than old status
                if ($newPriority < $oldPriority && $newPriority > 0) {
                    $regressions[] = [
                        'report_id' => $revision['report_id'],
                        'revision_number' => $revision['revision_number'],
                        'test_key' => $change['test_key'],
                        'old_status' => $change['old_status'],
                        'new_status' => $change['new_status'],
                        'client_version' => $revision['client_version'],
                        'tester' => $revision['tester'],
                        'commit_hash' => $revision['commit_hash'],
                        'archived_at' => $revision['archived_at']
                    ];

                    if (count($regressions) >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return $regressions;
    }

    /**
     * Get recent progressions from report revisions
     * A progression is when a test goes from a worse status to a better status
     *
     * @param int $limit Maximum number of progressions to return
     * @return array List of recent progressions with details
     */
    public function getRecentProgressions($limit = 10) {
        $progressions = [];

        // Get recent revisions that have changes_diff
        $stmt = $this->pdo->prepare("
            SELECT rr.*, r.client_version, r.tester, r.test_type, r.commit_hash
            FROM report_revisions rr
            JOIN reports r ON rr.report_id = r.id
            WHERE rr.changes_diff IS NOT NULL
            ORDER BY rr.archived_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit * 5]);
        $revisions = $stmt->fetchAll();

        $statusPriority = [
            'Working' => 3,
            'Semi-working' => 2,
            'Not working' => 1,
            'N/A' => 0,
            '' => 0
        ];

        foreach ($revisions as $revision) {
            $diff = json_decode($revision['changes_diff'], true);
            if (!$diff || empty($diff['changed'])) {
                continue;
            }

            foreach ($diff['changed'] as $change) {
                $oldPriority = $statusPriority[$change['old_status']] ?? 0;
                $newPriority = $statusPriority[$change['new_status']] ?? 0;

                // Progression: new status is better (higher priority) than old status
                // Only count if old status was a real status (not N/A)
                if ($newPriority > $oldPriority && $oldPriority > 0) {
                    $progressions[] = [
                        'report_id' => $revision['report_id'],
                        'revision_number' => $revision['revision_number'],
                        'test_key' => $change['test_key'],
                        'old_status' => $change['old_status'],
                        'new_status' => $change['new_status'],
                        'client_version' => $revision['client_version'],
                        'tester' => $revision['tester'],
                        'commit_hash' => $revision['commit_hash'],
                        'archived_at' => $revision['archived_at']
                    ];

                    if (count($progressions) >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return $progressions;
    }

    /**
     * Detect regressions in new test results compared to previous results
     * Used when submitting reports to create notifications
     *
     * @param array $oldResults Previous test results (from archived revision)
     * @param array $newResults New test results being submitted
     * @return array List of regressions found
     */
    public function detectRegressions($oldResults, $newResults) {
        $regressions = [];

        $statusPriority = [
            'Working' => 3,
            'Semi-working' => 2,
            'Not working' => 1,
            'N/A' => 0,
            '' => 0
        ];

        // Index old results by test_key
        $oldByKey = [];
        foreach ($oldResults as $result) {
            $key = $result['test_key'] ?? $result['key'] ?? null;
            if ($key) {
                $oldByKey[$key] = $result;
            }
        }

        // Check each new result against old
        foreach ($newResults as $result) {
            $key = $result['test_key'] ?? $result['key'] ?? null;
            if (!$key) continue;

            $newStatus = $result['status'] ?? '';
            $oldStatus = isset($oldByKey[$key]) ? ($oldByKey[$key]['status'] ?? '') : '';

            $oldPriority = $statusPriority[$oldStatus] ?? 0;
            $newPriority = $statusPriority[$newStatus] ?? 0;

            // Regression: new status is worse (lower priority) and both are real statuses
            if ($newPriority < $oldPriority && $newPriority > 0 && $oldPriority > 0) {
                $regressions[] = [
                    'test_key' => $key,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus
                ];
            }
        }

        return $regressions;
    }

    /**
     * Create regression notifications for detected regressions
     *
     * @param int $reportId Report ID
     * @param string $clientVersion Client version
     * @param string $tester Tester name
     * @param array $regressions List of regressions detected
     * @return int Number of notifications created
     */
    public function createRegressionNotifications($reportId, $clientVersion, $tester, $regressions) {
        $count = 0;

        foreach ($regressions as $regression) {
            $testKey = $regression['test_key'];
            $oldStatus = $regression['old_status'];
            $newStatus = $regression['new_status'];

            // Create notification for each admin
            $admins = $this->pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
            foreach ($admins as $admin) {
                try {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO user_notifications (user_id, type, report_id, test_key, client_version, title, message)
                        VALUES (?, 'regression', ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $admin['id'],
                        $reportId,
                        $testKey,
                        $clientVersion,
                        "Regression: Test $testKey",
                        "Test $testKey regressed from '$oldStatus' to '$newStatus' in $clientVersion (by $tester)"
                    ]);
                    $count++;
                } catch (PDOException $e) {
                    // Notification table may not have all columns, ignore
                }
            }
        }

        return $count;
    }

    // ========================================
    // Site Settings Methods
    // ========================================

    /**
     * Check if site_settings table exists
     */
    public function hasSettingsTable() {
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM site_settings LIMIT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Create the site_settings table if it doesn't exist
     */
    public function createSettingsTable() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS site_settings (
                setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                setting_value TEXT,
                setting_type ENUM('string', 'int', 'bool', 'json') NOT NULL DEFAULT 'string',
                description VARCHAR(255) DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ");

        // Insert default settings if they don't exist
        $defaults = [
            ['site_title', 'Steam Emulator Test Panel', 'string', 'Site title displayed in header and browser tab'],
            ['site_private', '0', 'bool', 'Require login for all pages (guests redirected to login)'],
            ['smtp_enabled', '0', 'bool', 'Enable SMTP email sending'],
            ['smtp_host', '', 'string', 'SMTP server hostname'],
            ['smtp_port', '587', 'int', 'SMTP server port'],
            ['smtp_username', '', 'string', 'SMTP authentication username'],
            ['smtp_password', '', 'string', 'SMTP authentication password'],
            ['smtp_encryption', 'tls', 'string', 'SMTP encryption (tls, ssl, or none)'],
            ['smtp_from_email', '', 'string', 'From email address for outgoing emails'],
            ['smtp_from_name', '', 'string', 'From name for outgoing emails'],
        ];

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_type, description)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($defaults as $setting) {
            $stmt->execute($setting);
        }
    }

    /**
     * Get a single setting value
     */
    public function getSetting($key, $default = null) {
        if (!$this->hasSettingsTable()) {
            return $default;
        }

        $stmt = $this->pdo->prepare("SELECT setting_value, setting_type FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();

        if (!$result) {
            return $default;
        }

        // Cast value based on type
        switch ($result['setting_type']) {
            case 'int':
                return (int)$result['setting_value'];
            case 'bool':
                return (bool)$result['setting_value'];
            case 'json':
                return json_decode($result['setting_value'], true);
            default:
                return $result['setting_value'];
        }
    }

    /**
     * Get all settings as an associative array
     */
    public function getAllSettings() {
        if (!$this->hasSettingsTable()) {
            return [];
        }

        $stmt = $this->pdo->query("SELECT setting_key, setting_value, setting_type, description FROM site_settings ORDER BY setting_key");
        $results = $stmt->fetchAll();

        $settings = [];
        foreach ($results as $row) {
            switch ($row['setting_type']) {
                case 'int':
                    $value = (int)$row['setting_value'];
                    break;
                case 'bool':
                    $value = (bool)$row['setting_value'];
                    break;
                case 'json':
                    $value = json_decode($row['setting_value'], true);
                    break;
                default:
                    $value = $row['setting_value'];
            }
            $settings[$row['setting_key']] = [
                'value' => $value,
                'type' => $row['setting_type'],
                'description' => $row['description']
            ];
        }

        return $settings;
    }

    /**
     * Set a setting value
     */
    public function setSetting($key, $value, $type = null, $description = null) {
        if (!$this->hasSettingsTable()) {
            $this->createSettingsTable();
        }

        // Handle value conversion for storage
        if ($type === 'bool') {
            $value = $value ? '1' : '0';
        } elseif ($type === 'json' && is_array($value)) {
            $value = json_encode($value);
        }

        if ($type === null) {
            // Update only the value
            $stmt = $this->pdo->prepare("
                INSERT INTO site_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $value]);
        } else {
            // Update value and type
            $stmt = $this->pdo->prepare("
                INSERT INTO site_settings (setting_key, setting_value, setting_type, description)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    setting_type = VALUES(setting_type),
                    description = COALESCE(VALUES(description), description)
            ");
            $stmt->execute([$key, $value, $type, $description]);
        }

        return true;
    }

    /**
     * Update multiple settings at once
     */
    public function updateSettings($settings) {
        if (!$this->hasSettingsTable()) {
            $this->createSettingsTable();
        }

        foreach ($settings as $key => $value) {
            // Get the current type for this setting
            $stmt = $this->pdo->prepare("SELECT setting_type FROM site_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $current = $stmt->fetch();

            $type = $current ? $current['setting_type'] : 'string';

            // Convert value for storage
            if ($type === 'bool') {
                $value = $value ? '1' : '0';
            } elseif ($type === 'json' && is_array($value)) {
                $value = json_encode($value);
            }

            $stmt = $this->pdo->prepare("
                UPDATE site_settings SET setting_value = ? WHERE setting_key = ?
            ");
            $stmt->execute([$value, $key]);
        }

        return true;
    }

    /**
     * Delete a setting
     */
    public function deleteSetting($key) {
        $stmt = $this->pdo->prepare("DELETE FROM site_settings WHERE setting_key = ?");
        return $stmt->execute([$key]);
    }

    /**
     * Check if the site is in private mode
     */
    public function isSitePrivate() {
        return (bool)$this->getSetting('site_private', false);
    }

    /**
     * Get the site title
     */
    public function getSiteTitle() {
        return $this->getSetting('site_title', PANEL_NAME);
    }

    // =====================
    // FLAG ACKNOWLEDGEMENTS
    // =====================

    /**
     * Ensure flag_acknowledgements table exists
     */
    public function ensureFlagAcknowledgementsTable() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS flag_acknowledgements (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    flag_type ENUM('retest', 'fixed') NOT NULL,
                    flag_id INT NOT NULL,
                    username VARCHAR(100) NOT NULL,
                    acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_ack (flag_type, flag_id, username),
                    INDEX idx_username (username),
                    INDEX idx_flag (flag_type, flag_id)
                ) ENGINE=InnoDB
            ");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get unacknowledged flags for a user (lightweight query for polling)
     */
    public function getUnacknowledgedFlags($username) {
        $this->ensureFlagAcknowledgementsTable();

        $flags = [];

        // Get pending retest requests not acknowledged by this user
        $stmt = $this->pdo->prepare("
            SELECT r.id, r.test_key, r.client_version, r.notes, r.created_at,
                   'retest' as flag_type
            FROM retest_requests r
            LEFT JOIN flag_acknowledgements fa
                ON fa.flag_type = 'retest' AND fa.flag_id = r.id AND fa.username = ?
            WHERE r.status = 'pending' AND fa.id IS NULL
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$username]);
        $retests = $stmt->fetchAll();

        // Get pending fixed tests not acknowledged by this user
        $stmt = $this->pdo->prepare("
            SELECT f.id, f.test_key, f.client_version, f.notes, f.commit_hash, f.created_at,
                   'fixed' as flag_type
            FROM fixed_tests f
            LEFT JOIN flag_acknowledgements fa
                ON fa.flag_type = 'fixed' AND fa.flag_id = f.id AND fa.username = ?
            WHERE f.status = 'pending_retest' AND fa.id IS NULL
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$username]);
        $fixed = $stmt->fetchAll();

        return array_merge($retests, $fixed);
    }

    /**
     * Acknowledge a flag notification for a user
     */
    public function acknowledgeFlagNotification($flagType, $flagId, $username) {
        $this->ensureFlagAcknowledgementsTable();

        if (!in_array($flagType, ['retest', 'fixed'])) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO flag_acknowledgements (flag_type, flag_id, username)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$flagType, $flagId, $username]);
            return $stmt->rowCount() > 0 || true; // Success even if already acknowledged
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Check if a flag has been acknowledged by a user
     */
    public function isFlagAcknowledged($flagType, $flagId, $username) {
        $this->ensureFlagAcknowledgementsTable();

        $stmt = $this->pdo->prepare("
            SELECT 1 FROM flag_acknowledgements
            WHERE flag_type = ? AND flag_id = ? AND username = ?
            LIMIT 1
        ");
        $stmt->execute([$flagType, $flagId, $username]);
        return $stmt->fetch() !== false;
    }
}

// Helper function
function getDb() {
    return Database::getInstance();
}

/**
 * Get test keys from database with fallback to static data
 */
function getTestKeys() {
    try {
        $db = Database::getInstance();
        if ($db->hasTestCategoriesTable()) {
            $types = $db->getTestTypes(true);
            $keys = [];
            foreach ($types as $type) {
                $keys[$type['test_key']] = $type['name'];
            }
            return $keys;
        }
    } catch (Exception $e) {
        // Fall through to static data
    }

    // Fallback to TEST_KEYS constant
    $keys = [];
    foreach (TEST_KEYS as $key => $test) {
        $keys[$key] = $test['name'];
    }
    return $keys;
}
