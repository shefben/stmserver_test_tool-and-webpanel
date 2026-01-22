<?php
/**
 * Git Revisions page
 * Shows paginated list of repository commits from GitHub
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if GitHub is configured
if (!isGitHubConfigured()) {
    ?>
    <h1 class="page-title">Git Revisions</h1>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            GitHub integration is not configured. Please configure it in the installation settings.
        </p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Get all revisions
$allRevisions = getGitHubRevisions();

// Sort by timestamp (newest first)
uasort($allRevisions, function($a, $b) {
    return ($b['ts'] ?? 0) - ($a['ts'] ?? 0);
});

// Convert to indexed array for pagination
$revisionsList = [];
foreach ($allRevisions as $sha => $data) {
    $revisionsList[] = [
        'sha' => $sha,
        'notes' => $data['notes'] ?? '',
        'files' => $data['files'] ?? ['added' => [], 'removed' => [], 'modified' => []],
        'ts' => $data['ts'] ?? 0,
    ];
}

// Pagination
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 25;
$totalRevisions = count($revisionsList);
$totalPages = ceil($totalRevisions / $perPage);
$offset = ($page - 1) * $perPage;

// Get current page of revisions
$pageRevisions = array_slice($revisionsList, $offset, $perPage);
?>

<h1 class="page-title">Git Revisions</h1>

<!-- Summary -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card primary">
        <div class="value"><?= number_format($totalRevisions) ?></div>
        <div class="label">Total Revisions</div>
    </div>
    <?php if (!empty($revisionsList)): ?>
    <div class="stat-card">
        <div class="value" style="font-size: 14px;"><?= formatRevisionDateTime($revisionsList[0]['ts']) ?></div>
        <div class="label">Latest Revision</div>
    </div>
    <div class="stat-card">
        <div class="value" style="font-size: 14px;"><?= formatRevisionDateTime(end($revisionsList)['ts']) ?></div>
        <div class="label">Earliest Cached</div>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($pageRevisions)): ?>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            No revisions found. The GitHub cache may need to be rebuilt.
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th style="width: 180px;">Date & Time</th>
                        <th style="width: 120px;">Revision Hash</th>
                        <th>Commit Message</th>
                        <th style="width: 100px;">Files Changed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pageRevisions as $rev): ?>
                        <?php
                        $shortSha = substr($rev['sha'], 0, 8);
                        $dateTime = formatRevisionDateTime($rev['ts']);
                        $notes = $rev['notes'] ?? '';
                        // Get first line of commit message
                        $firstLine = strtok($notes, "\n");
                        if (strlen($firstLine) > 80) {
                            $firstLine = substr($firstLine, 0, 77) . '...';
                        }
                        $files = $rev['files'] ?? [];
                        $fileCount = count($files['added'] ?? []) + count($files['removed'] ?? []) + count($files['modified'] ?? []);
                        ?>
                        <tr class="clickable-row" onclick="window.location='?page=git_revision_detail&sha=<?= urlencode($rev['sha']) ?>'">
                            <td>
                                <span class="revision-date"><?= e($dateTime) ?></span>
                            </td>
                            <td>
                                <a href="?page=git_revision_detail&sha=<?= urlencode($rev['sha']) ?>" class="commit-link" onclick="event.stopPropagation();">
                                    <code><?= e($shortSha) ?></code>
                                </a>
                            </td>
                            <td>
                                <span class="commit-message" title="<?= e($notes) ?>"><?= e($firstLine) ?></span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($fileCount > 0): ?>
                                    <span class="file-count"><?= $fileCount ?></span>
                                <?php else: ?>
                                    <span class="file-count empty">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination" style="margin-top: 20px; display: flex; justify-content: center; gap: 5px; flex-wrap: wrap;">
                <?php if ($page > 1): ?>
                    <a href="?page=git_revisions&p=1" class="page-link">&laquo; First</a>
                    <a href="?page=git_revisions&p=<?= $page - 1 ?>" class="page-link">&lsaquo; Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                    <a href="?page=git_revisions&p=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=git_revisions&p=<?= $page + 1 ?>" class="page-link">Next &rsaquo;</a>
                    <a href="?page=git_revisions&p=<?= $totalPages ?>" class="page-link">Last &raquo;</a>
                <?php endif; ?>
            </div>
            <div style="text-align: center; margin-top: 10px; color: var(--text-muted); font-size: 13px;">
                Showing <?= $offset + 1 ?>-<?= min($offset + $perPage, $totalRevisions) ?> of <?= $totalRevisions ?> revisions
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<style>
/* Clickable rows */
.clickable-row {
    cursor: pointer;
    transition: background 0.2s;
}
.clickable-row:hover {
    background: var(--bg-accent) !important;
}

/* Commit link */
.commit-link {
    text-decoration: none;
    color: var(--primary);
}
.commit-link:hover {
    text-decoration: underline;
}
.commit-link code {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 13px;
    background: var(--bg-accent);
    padding: 2px 6px;
    border-radius: 4px;
}

/* Revision date */
.revision-date {
    color: var(--text-muted);
    font-size: 13px;
}

/* Commit message */
.commit-message {
    color: var(--text);
    font-size: 13px;
}

/* File count */
.file-count {
    display: inline-block;
    background: var(--bg-accent);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}
.file-count.empty {
    color: var(--text-muted);
}

/* Pagination */
.page-link {
    display: inline-block;
    padding: 8px 12px;
    background: var(--bg-accent);
    color: var(--text);
    text-decoration: none;
    border-radius: 4px;
    font-size: 13px;
    transition: all 0.2s;
}
.page-link:hover {
    background: var(--primary);
    color: #fff;
}
.page-link.active {
    background: var(--primary);
    color: #fff;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
