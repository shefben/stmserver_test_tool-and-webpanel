<?php
/**
 * Single report detail page
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

// Get report ID
$reportId = intval($_GET['id'] ?? 0);

if (!$reportId) {
    setFlash('error', 'Invalid report ID');
    header('Location: ?page=reports');
    exit;
}

// Get report
$report = $db->getReport($reportId);

if (!$report) {
    setFlash('error', 'Report not found');
    header('Location: ?page=reports');
    exit;
}

// Get test results
$testResults = $db->getTestResults($reportId);
$reportStats = $db->getReportStats($reportId);

// Get revision count
$revisionCount = $report['revision_count'] ?? 0;

// Get attached logs
$attachedLogs = $db->getReportLogs($reportId);

// Organize by category
$categories = getTestCategories();
$resultsByKey = [];
foreach ($testResults as $result) {
    $resultsByKey[$result['test_key']] = $result;
}

// Get pending retest requests map for this version with notes
$pendingRetestMap = $db->getPendingRetestRequestsMap();
$clientVersion = $report['client_version'];

// Get pending retest requests with notes for this report
$pendingRetestRequests = $db->getPendingRetestsForClient($clientVersion);
$retestNotesMap = [];
foreach ($pendingRetestRequests as $req) {
    $key = $req['test_key'] . '|' . $req['client_version'];
    $retestNotesMap[$key] = $req['notes'] ?? '';
}

// Get report tags
$reportTags = $db->getReportTags($reportId);
$allTags = $db->getAllTags();

// Get version notifications for this report
$versionNotifications = $db->getNotificationsForVersionString(
    $report['client_version'],
    $report['commit_hash'] ?? null
);
?>

<div class="report-header">
    <div>
        <h1 class="page-title" style="margin-bottom: 10px;">
            Report #<?= $report['id'] ?>
            <?php if ($revisionCount > 0): ?>
                <span class="revision-indicator" title="This report has <?= $revisionCount ?> previous revision(s)">
                    v<?= $revisionCount + 1 ?>
                </span>
            <?php endif; ?>
        </h1>
        <p style="color: var(--text-muted);">
            <?= e($report['client_version']) ?>
            <?php if (!empty($report['restored_from'])): ?>
                <span class="restored-badge" title="Restored from revision #<?= $report['restored_from'] ?>">
                    ↺ Restored
                </span>
            <?php endif; ?>
        </p>
    </div>
    <div>
        <?php if ($revisionCount > 0): ?>
            <a href="?page=report_revisions&id=<?= $reportId ?>" class="btn btn-secondary">
                📜 View History (<?= $revisionCount ?>)
            </a>
        <?php endif; ?>
        <?php if (canEditReport($reportId)): ?>
            <a href="?page=edit_report&id=<?= $reportId ?>" class="btn">Edit Report</a>
        <?php endif; ?>
        <a href="?page=reports" class="btn btn-secondary">&larr; Back to Reports</a>
    </div>
</div>

<!-- Report Meta -->
<div class="report-meta">
    <div class="meta-item">
        <label>Client Version</label>
        <div class="value">
            <a href="?page=results&version=<?= urlencode($report['client_version']) ?>" class="meta-link">
                <?= e($report['client_version']) ?>
            </a>
        </div>
    </div>
    <div class="meta-item">
        <label>Tester</label>
        <div class="value">
            <a href="?page=results&tester=<?= urlencode($report['tester']) ?>" class="meta-link">
                <?= e($report['tester']) ?>
            </a>
        </div>
    </div>
    <div class="meta-item">
        <label>Current Revision</label>
        <div class="value">
            <?php if ($revisionCount > 0): ?>
                <a href="?page=report_revisions_view&id=<?= $reportId ?>" class="revision-link" title="View all revisions">
                    <?= $revisionCount ?>
                </a>
            <?php else: ?>
                <span style="color: var(--text-muted);">0</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="meta-item">
        <label>Test Type</label>
        <div class="value">
            <span class="status-badge" style="background: <?= $report['test_type'] === 'WAN' ? '#3498db' : '#9b59b6' ?>">
                <?= e($report['test_type']) ?>
            </span>
        </div>
    </div>
    <div class="meta-item">
        <label>Commit Hash</label>
        <div class="value" style="font-family: monospace; font-size: 14px;">
            <?php if (!empty($report['commit_hash'])): ?>
                <?php
                $revisionInfo = null;
                if (isGitHubConfigured()) {
                    $revisionInfo = getRevisionBySha($report['commit_hash']);
                }
                $commitDateTime = $revisionInfo ? formatRevisionDateTime($revisionInfo['ts']) : '';
                ?>
                <a href="?page=reports&commit=<?= urlencode($report['commit_hash']) ?>"
                   class="commit-hash-link"
                   title="<?= $commitDateTime ? e($commitDateTime) . ' - Click to view all reports for this commit' : 'View all reports for this commit' ?>"
                   data-sha="<?= e($report['commit_hash']) ?>"
                   onclick="showCommitPopup('<?= e($report['commit_hash']) ?>'); return false;">
                    <?= e(substr($report['commit_hash'], 0, 8)) ?>...
                </a>
                <?php if ($commitDateTime): ?>
                    <span style="font-size: 11px; color: var(--text-muted); margin-left: 8px;"><?= e($commitDateTime) ?></span>
                <?php endif; ?>
            <?php else: ?>
                N/A
            <?php endif; ?>
        </div>
    </div>
    <div class="meta-item">
        <label>Test Duration</label>
        <div class="value" style="font-family: monospace;">
            <?= formatDuration($report['test_duration'] ?? null) ?>
        </div>
    </div>
    <div class="meta-item">
        <label>Submitted</label>
        <div class="value"><?= formatDate($report['submitted_at']) ?></div>
    </div>
</div>

<!-- Version Notifications Section -->
<?php if (!empty($versionNotifications)): ?>
<div class="version-notifications-section">
    <div class="notifications-header">
        <span class="notifications-icon">⚠️</span>
        <span class="notifications-title">Known Issues / Notes (<?= count($versionNotifications) ?>)</span>
    </div>
    <div class="notifications-list">
        <?php
        // Display oldest at bottom, newest at top (reverse the order)
        $reversedNotifications = array_reverse($versionNotifications);
        foreach ($reversedNotifications as $notif):
        ?>
            <div class="notification-item">
                <div class="notification-name"><?= e($notif['name']) ?></div>
                <div class="notification-message"><?= nl2br(e($notif['message'])) ?></div>
                <div class="notification-meta">
                    <?php if (!empty($notif['commit_hash'])): ?>
                        <span class="meta-item">Commit: <?= e(substr($notif['commit_hash'], 0, 8)) ?></span>
                    <?php endif; ?>
                    <span class="meta-item">Added: <?= formatDate($notif['created_at']) ?></span>
                    <?php if (!empty($notif['created_by_name'])): ?>
                        <span class="meta-item">By: <?= e($notif['created_by_name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Report Tags Section -->
<div class="report-tags-section" id="report-tags-section">
    <div class="tags-display">
        <span class="tags-label">Tags:</span>
        <div class="tags-container" id="report-tags-container">
            <?php if (empty($reportTags)): ?>
                <span class="no-tags">No tags assigned</span>
            <?php else: ?>
                <?php foreach ($reportTags as $tag): ?>
                    <span class="report-tag" style="background-color: <?= e($tag['color']) ?>;" data-tag-id="<?= $tag['id'] ?>">
                        <?= e($tag['name']) ?>
                        <?php if (isAdmin()): ?>
                            <button type="button" class="tag-remove-btn" onclick="removeReportTag(<?= $tag['id'] ?>, '<?= e(addslashes($tag['name'])) ?>')" title="Remove tag">&times;</button>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (isAdmin()): ?>
            <div class="add-tag-dropdown">
                <button type="button" class="btn btn-sm btn-secondary add-tag-btn" onclick="toggleAddTagDropdown()">+ Add Tag</button>
                <div class="tag-dropdown-menu" id="tag-dropdown-menu" style="display: none;">
                    <?php foreach ($allTags as $tag):
                        $isAssigned = false;
                        foreach ($reportTags as $rt) {
                            if ($rt['id'] == $tag['id']) {
                                $isAssigned = true;
                                break;
                            }
                        }
                        if ($isAssigned) continue;
                    ?>
                        <div class="tag-dropdown-item" onclick="addReportTag(<?= $tag['id'] ?>, '<?= e(addslashes($tag['name'])) ?>', '<?= e($tag['color']) ?>')">
                            <span class="tag-color-preview" style="background-color: <?= e($tag['color']) ?>;"></span>
                            <span class="tag-name"><?= e($tag['name']) ?></span>
                            <?php if ($tag['description']): ?>
                                <span class="tag-desc"><?= e($tag['description']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($allTags) === count($reportTags)): ?>
                        <div class="tag-dropdown-empty">All tags assigned</div>
                    <?php endif; ?>
                    <div class="tag-dropdown-footer">
                        <a href="?page=admin_tags" class="tag-manage-link">Manage Tags</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Summary - Clickable -->
<div class="stats-grid" style="margin-bottom: 30px;">
    <a href="?page=results&report_id=<?= $reportId ?>&status=Working" class="stat-card working clickable-card">
        <div class="value"><?= $reportStats['working'] ?></div>
        <div class="label">Working</div>
        <div class="card-hint">Click to view</div>
    </a>
    <a href="?page=results&report_id=<?= $reportId ?>&status=Semi-working" class="stat-card semi clickable-card">
        <div class="value"><?= $reportStats['semi_working'] ?></div>
        <div class="label">Semi-working</div>
        <div class="card-hint">Click to view</div>
    </a>
    <a href="?page=results&report_id=<?= $reportId ?>&status=Not+working" class="stat-card broken clickable-card">
        <div class="value"><?= $reportStats['not_working'] ?></div>
        <div class="label">Not Working</div>
        <div class="card-hint">Click to view</div>
    </a>
    <a href="?page=results&report_id=<?= $reportId ?>&status=N/A" class="stat-card clickable-card">
        <div class="value" style="color: var(--status-na);"><?= $reportStats['na'] ?></div>
        <div class="label">N/A</div>
        <div class="card-hint">Click to view</div>
    </a>
</div>

<!-- Attached Logs Section -->
<?php if (!empty($attachedLogs)): ?>
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title" style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 20px;">📄</span>
        Attached Log Files (<?= count($attachedLogs) ?>)
    </h3>
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>Filename</th>
                    <th style="width: 160px;">Log Timestamp</th>
                    <th style="width: 100px;">Original Size</th>
                    <th style="width: 100px;">Compressed</th>
                    <th style="width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attachedLogs as $log): ?>
                    <tr>
                        <td style="font-family: monospace; font-size: 13px;"><?= e($log['filename']) ?></td>
                        <td><?= formatDate($log['log_datetime']) ?></td>
                        <td><?= number_format($log['size_original']) ?> B</td>
                        <td><?= number_format($log['size_compressed']) ?> B</td>
                        <td style="white-space: nowrap;">
                            <a href="api/download_log.php?id=<?= $log['id'] ?>" class="btn btn-sm" title="Download log file">
                                ⬇️ Download
                            </a>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="openLogViewer(<?= $log['id'] ?>, '<?= e(addslashes($log['filename'])) ?>')" title="View log file">
                                📄 Open
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Test Results by Category -->
<?php foreach ($categories as $categoryName => $tests): ?>
    <div class="card version-section">
        <h3>
            <a href="?page=results&category=<?= urlencode($categoryName) ?>" class="category-link">
                <?= e($categoryName) ?>
            </a>
        </h3>
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th style="width: 60px;">Key</th>
                        <th>Test Name</th>
                        <th style="width: 120px;">Status</th>
                        <th>Notes</th>
                        <?php if (isAdmin()): ?>
                            <th style="width: 100px;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tests as $testKey => $testInfo): ?>
                        <?php
                        $result = $resultsByKey[$testKey] ?? null;
                        $status = $result ? $result['status'] : 'N/A';
                        $notes = $result ? $result['notes'] : '';
                        $retestKey = $testKey . '|' . $clientVersion;
                        $hasPendingRetest = isset($pendingRetestMap[$retestKey]);
                        $retestNotes = $retestNotesMap[$retestKey] ?? '';
                        ?>
                        <tr class="test-row<?= $hasPendingRetest ? ' retest-pending' : '' ?>">
                            <td>
                                <a href="?page=results&test_key=<?= urlencode($testKey) ?>" class="test-key-link">
                                    <?= e($testKey) ?>
                                </a>
                                <?php if ($hasPendingRetest): ?>
                                    <span class="retest-indicator" title="Flagged for retest<?= $retestNotes ? ': ' . e($retestNotes) : '' ?>">&#x21BB;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?page=results&test_key=<?= urlencode($testKey) ?>" class="test-name-link">
                                    <div style="font-weight: 500;"><?= e($testInfo['name']) ?></div>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                                        <?= e($testInfo['expected']) ?>
                                    </div>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&test_key=<?= urlencode($testKey) ?>&status=<?= urlencode($status) ?>" class="status-link">
                                    <?= getStatusBadge($status) ?>
                                </a>
                            </td>
                            <td class="notes-cell" data-full="<?= e($notes) ?>">
                                <?= e($notes ?: '-') ?>
                                <?php if ($hasPendingRetest && $retestNotes): ?>
                                    <div class="retest-notes">
                                        <span class="retest-notes-label">Retest notes:</span>
                                        <?= e($retestNotes) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php if (isAdmin()): ?>
                                <td>
                                    <?php if ($hasPendingRetest): ?>
                                        <span class="retest-badge" title="Retest already requested">Pending</span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-retest"
                                                data-test-key="<?= e($testKey) ?>"
                                                data-client-version="<?= e($clientVersion) ?>"
                                                title="Flag this test for retest">
                                            Retest
                                        </button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<!-- Raw JSON (collapsible) -->
<div class="card">
    <h3 class="card-title" style="cursor: pointer;" onclick="document.getElementById('rawJson').classList.toggle('hidden');">
        Raw JSON Data <span style="font-size: 12px; color: var(--text-muted);">(click to toggle)</span>
    </h3>
    <div id="rawJson" class="hidden">
        <pre style="background: var(--bg-dark); padding: 15px; border-radius: 6px; overflow-x: auto; font-size: 12px; color: var(--text-muted);"><?= e($report['raw_json']) ?></pre>
    </div>
</div>

<!-- Comments Section -->
<?php
$comments = $db->getReportComments($reportId);
$commentCount = count($comments);
$currentUserId = getCurrentUser()['id'] ?? 0;
$currentUsername = getCurrentUser()['username'] ?? '';
$isUserAdmin = isAdmin();
?>
<div class="card" id="comments-section">
    <h3 class="card-title">
        <span style="font-size: 20px;">💬</span>
        Comments (<?= $commentCount ?>)
    </h3>

    <!-- Comment Form -->
    <?php if (isLoggedIn()): ?>
    <div class="comment-form-container">
        <form id="comment-form" class="comment-form">
            <input type="hidden" name="report_id" value="<?= $reportId ?>">
            <input type="hidden" id="reply-to-id" name="parent_comment_id" value="">
            <input type="hidden" id="quoted-text" name="quoted_text" value="">

            <div id="reply-indicator" class="reply-indicator hidden">
                <div class="reply-indicator-content">
                    <span class="reply-label">Replying to <strong id="reply-to-name"></strong>:</span>
                    <div id="reply-quote-preview" class="reply-quote-preview"></div>
                </div>
                <button type="button" class="btn-cancel-reply" onclick="cancelReply()">×</button>
            </div>

            <textarea id="comment-content" name="content" placeholder="Write a comment..." rows="3" required></textarea>

            <div class="comment-form-actions">
                <span class="comment-hint">Posting as <strong><?= e($currentUsername) ?></strong></span>
                <button type="submit" class="btn">Post Comment</button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <p style="color: var(--text-muted); padding: 15px 0;">
        <a href="?page=login">Log in</a> to leave a comment.
    </p>
    <?php endif; ?>

    <!-- Comments List -->
    <div id="comments-list" class="comments-list">
        <?php if (empty($comments)): ?>
            <p class="no-comments">No comments yet. Be the first to comment!</p>
        <?php else: ?>
            <?php foreach ($comments as $comment): ?>
                <?php
                $canManage = ($comment['user_id'] == $currentUserId || $isUserAdmin);
                $isOwner = ($comment['user_id'] == $currentUserId);
                ?>
                <div class="comment" id="comment-<?= $comment['id'] ?>" data-comment-id="<?= $comment['id'] ?>">
                    <div class="comment-header">
                        <div class="comment-author">
                            <span class="author-name"><?= e($comment['author_name']) ?></span>
                            <?php if ($comment['author_role'] === 'admin'): ?>
                                <span class="author-badge admin">Admin</span>
                            <?php endif; ?>
                        </div>
                        <div class="comment-meta">
                            <span class="comment-date" title="<?= $comment['created_at'] ?>">
                                <?= formatDate($comment['created_at']) ?>
                            </span>
                            <?php if ($comment['is_edited']): ?>
                                <span class="edited-badge" title="Edited on <?= $comment['updated_at'] ?>">(edited)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($comment['quoted_text']): ?>
                        <div class="comment-quote">
                            <div class="quote-header">
                                Replying to <?= e($comment['parent_author_name'] ?? 'deleted comment') ?>:
                            </div>
                            <div class="quote-content"><?= e($comment['quoted_text']) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="comment-content" id="comment-content-<?= $comment['id'] ?>">
                        <?= nl2br(e($comment['content'])) ?>
                    </div>

                    <div class="comment-actions">
                        <?php if (isLoggedIn()): ?>
                            <button type="button" class="btn-comment-action btn-reply"
                                    onclick="replyToComment(<?= $comment['id'] ?>, '<?= e(addslashes($comment['author_name'])) ?>', '<?= e(addslashes(substr($comment['content'], 0, 200))) ?>')">
                                Reply
                            </button>
                            <button type="button" class="btn-comment-action btn-quote"
                                    onclick="quoteComment(<?= $comment['id'] ?>, '<?= e(addslashes($comment['author_name'])) ?>')">
                                Quote
                            </button>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <button type="button" class="btn-comment-action btn-edit"
                                    onclick="editComment(<?= $comment['id'] ?>)">
                                Edit
                            </button>
                            <button type="button" class="btn-comment-action btn-delete"
                                    onclick="deleteComment(<?= $comment['id'] ?>)">
                                Delete
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.hidden { display: none; }

/* Version Notifications Section */
.version-notifications-section {
    background: #2a1f1f;
    border: 2px solid #e74c3c;
    border-radius: 8px;
    margin-bottom: 20px;
    overflow: hidden;
}

.notifications-header {
    background: rgba(231, 76, 60, 0.2);
    padding: 12px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid rgba(231, 76, 60, 0.3);
}

.notifications-icon {
    font-size: 20px;
}

.notifications-title {
    font-weight: 600;
    color: #e74c3c;
    font-size: 14px;
}

.notifications-list {
    padding: 15px 20px;
}

.notification-item {
    background: rgba(0, 0, 0, 0.2);
    border-left: 3px solid #e74c3c;
    border-radius: 0 6px 6px 0;
    padding: 12px 15px;
    margin-bottom: 12px;
}

.notification-item:last-child {
    margin-bottom: 0;
}

.notification-name {
    font-weight: 600;
    color: #f39c12;
    font-size: 14px;
    margin-bottom: 6px;
}

.notification-message {
    color: var(--text);
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 8px;
}

.notification-meta {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    font-size: 11px;
    color: var(--text-muted);
}

.notification-meta .meta-item {
    display: flex;
    gap: 4px;
}

/* Report Tags Section */
.report-tags-section {
    background: var(--bg-card);
    border-radius: 8px;
    padding: 12px 20px;
    margin-bottom: 20px;
    border: 1px solid var(--border);
}

.tags-display {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.tags-label {
    font-weight: 600;
    color: var(--text-muted);
    font-size: 13px;
}

.tags-container {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.no-tags {
    color: var(--text-muted);
    font-size: 13px;
    font-style: italic;
}

.report-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    animation: tagFadeIn 0.2s ease-out;
}

@keyframes tagFadeIn {
    from {
        opacity: 0;
        transform: scale(0.8);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.tag-remove-btn {
    background: none;
    border: none;
    color: rgba(255,255,255,0.7);
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    padding: 0 2px;
    margin-left: 2px;
    line-height: 1;
}

.tag-remove-btn:hover {
    color: #fff;
}

.add-tag-dropdown {
    position: relative;
}

.add-tag-btn {
    font-size: 12px;
    padding: 4px 10px;
}

.tag-dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    margin-top: 4px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 6px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.4);
    z-index: 1000;
    min-width: 220px;
    max-height: 300px;
    overflow-y: auto;
    animation: dropdownSlideIn 0.15s ease-out;
}

@keyframes dropdownSlideIn {
    from {
        opacity: 0;
        transform: translateY(-5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.tag-dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    cursor: pointer;
    transition: background 0.15s;
    border-bottom: 1px solid var(--border);
}

.tag-dropdown-item:last-of-type {
    border-bottom: none;
}

.tag-dropdown-item:hover {
    background: var(--bg-accent);
}

.tag-color-preview {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    flex-shrink: 0;
}

.tag-dropdown-item .tag-name {
    font-weight: 500;
    color: var(--text);
    font-size: 13px;
}

.tag-dropdown-item .tag-desc {
    font-size: 11px;
    color: var(--text-muted);
    margin-left: auto;
    max-width: 120px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.tag-dropdown-empty {
    padding: 15px;
    text-align: center;
    color: var(--text-muted);
    font-size: 13px;
    font-style: italic;
}

.tag-dropdown-footer {
    padding: 8px 12px;
    background: var(--bg-dark);
    border-top: 1px solid var(--border);
    text-align: center;
}

.tag-manage-link {
    font-size: 12px;
    color: var(--primary);
    text-decoration: none;
}

.tag-manage-link:hover {
    text-decoration: underline;
}

/* Revision indicators */
.revision-indicator {
    display: inline-block;
    background: var(--primary);
    color: white;
    font-size: 12px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    margin-left: 10px;
    vertical-align: middle;
}

.restored-badge {
    display: inline-block;
    background: #f39c12;
    color: white;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    margin-left: 8px;
}

/* Clickable cards */
.clickable-card {
    text-decoration: none;
    color: inherit;
    display: block;
    transition: transform 0.2s, box-shadow 0.2s;
    cursor: pointer;
}
.clickable-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}
.card-hint {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 8px;
    opacity: 0;
    transition: opacity 0.2s;
}
.clickable-card:hover .card-hint {
    opacity: 1;
}

/* Meta links */
.meta-link {
    color: var(--text);
    text-decoration: none;
    transition: color 0.2s;
}
.meta-link:hover {
    color: var(--primary);
}

/* Commit hash link */
.commit-hash-link {
    color: var(--primary);
    text-decoration: none;
    background: var(--bg-dark);
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s;
}
.commit-hash-link:hover {
    background: var(--primary);
    color: #fff;
}

/* Category links */
.category-link {
    color: var(--text);
    text-decoration: none;
    transition: color 0.2s;
}
.category-link:hover {
    color: var(--primary);
}

/* Test key links */
.test-key-link {
    font-family: monospace;
    font-weight: bold;
    color: var(--primary);
    text-decoration: none;
}
.test-key-link:hover {
    text-decoration: underline;
}

/* Test name links */
.test-name-link {
    text-decoration: none;
    color: inherit;
}
.test-name-link:hover {
    opacity: 0.8;
}

/* Status links */
.status-link {
    text-decoration: none;
}
.status-link:hover .status-badge {
    transform: scale(1.05);
}

/* Test row hover */
.test-row:hover {
    background: var(--bg-accent);
}

/* Retest indicator (visible to all users) */
.retest-indicator {
    display: inline-block;
    color: #f39c12;
    font-size: 14px;
    font-weight: bold;
    margin-left: 6px;
    vertical-align: middle;
    animation: retest-pulse 2s infinite;
}

@keyframes retest-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Row highlight for pending retest */
.test-row.retest-pending {
    background: rgba(243, 156, 18, 0.1);
}
.test-row.retest-pending:hover {
    background: rgba(243, 156, 18, 0.2);
}

/* Retest badge (shown in Actions column when already pending) */
.retest-badge {
    display: inline-block;
    background: #f39c12;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
}

/* Retest button */
.btn-retest {
    background: #3498db;
    color: #fff;
    border: none;
    transition: background 0.2s;
}
.btn-retest:hover {
    background: #2980b9;
}
.btn-retest:disabled {
    background: #7f8c8d;
    cursor: not-allowed;
}

/* Revision link */
.revision-link {
    display: inline-block;
    background: var(--primary);
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 4px;
    text-decoration: none;
    transition: all 0.2s;
}
.revision-link:hover {
    background: var(--primary-dark);
    transform: scale(1.05);
}

/* Retest notes display */
.retest-notes {
    margin-top: 8px;
    padding: 8px 10px;
    background: rgba(243, 156, 18, 0.15);
    border-left: 3px solid #f39c12;
    border-radius: 0 4px 4px 0;
    font-size: 12px;
    color: #f39c12;
}
.retest-notes-label {
    font-weight: 600;
    margin-right: 4px;
}

/* Comments Section Styles */
.comments-list {
    margin-top: 20px;
}

.no-comments {
    color: var(--text-muted);
    font-style: italic;
    padding: 20px 0;
    text-align: center;
}

.comment {
    background: var(--bg-dark);
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    border-left: 3px solid var(--border-bright);
    transition: border-color 0.2s;
}

.comment:hover {
    border-left-color: var(--primary);
}

.comment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.comment-author {
    display: flex;
    align-items: center;
    gap: 8px;
}

.author-name {
    font-weight: 600;
    color: var(--text);
}

.author-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 3px;
    text-transform: uppercase;
}

.author-badge.admin {
    background: var(--primary);
    color: #fff;
}

.comment-meta {
    display: flex;
    align-items: center;
    gap: 8px;
}

.comment-date {
    font-size: 12px;
    color: var(--text-muted);
}

.edited-badge {
    font-size: 11px;
    color: var(--text-muted);
    font-style: italic;
}

.comment-quote {
    background: var(--bg-accent);
    border-left: 3px solid var(--primary);
    padding: 10px 12px;
    margin-bottom: 10px;
    border-radius: 0 4px 4px 0;
    font-size: 13px;
}

.quote-header {
    font-size: 11px;
    color: var(--text-muted);
    margin-bottom: 4px;
}

.quote-content {
    color: var(--text-muted);
    font-style: italic;
}

.comment-content {
    color: var(--text);
    line-height: 1.6;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.comment-actions {
    margin-top: 10px;
    display: flex;
    gap: 8px;
}

.btn-comment-action {
    background: transparent;
    border: 1px solid var(--border-bright);
    color: var(--text-muted);
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-comment-action:hover {
    background: var(--bg-accent);
    color: var(--text);
}

.btn-reply:hover, .btn-quote:hover {
    border-color: var(--primary);
    color: var(--primary);
}

.btn-edit:hover {
    border-color: #3498db;
    color: #3498db;
}

.btn-delete:hover {
    border-color: #c45050;
    color: #c45050;
}

/* Comment Form */
.comment-form-container {
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border);
}

.comment-form textarea {
    width: 100%;
    background: var(--bg-dark);
    border: 1px solid var(--border-bright);
    border-radius: 6px;
    padding: 12px;
    color: var(--text);
    font-family: inherit;
    font-size: 14px;
    resize: vertical;
    min-height: 80px;
    transition: border-color 0.2s;
}

.comment-form textarea:focus {
    outline: none;
    border-color: var(--primary);
}

.comment-form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
}

.comment-hint {
    font-size: 12px;
    color: var(--text-muted);
}

.reply-indicator {
    background: var(--bg-accent);
    border-left: 3px solid var(--primary);
    padding: 10px 12px;
    margin-bottom: 10px;
    border-radius: 0 4px 4px 0;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.reply-indicator-content {
    flex: 1;
}

.reply-label {
    font-size: 12px;
    color: var(--text-muted);
}

.reply-quote-preview {
    font-size: 13px;
    color: var(--text-muted);
    font-style: italic;
    margin-top: 4px;
    max-height: 60px;
    overflow: hidden;
}

.btn-cancel-reply {
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 18px;
    cursor: pointer;
    padding: 0 5px;
    line-height: 1;
}

.btn-cancel-reply:hover {
    color: #c45050;
}

/* Edit mode */
.comment.editing {
    border-left-color: #3498db;
}

.comment.editing .comment-content {
    display: none;
}

.edit-form {
    margin-top: 10px;
}

.edit-form textarea {
    width: 100%;
    background: var(--bg-accent);
    border: 1px solid #3498db;
    border-radius: 6px;
    padding: 10px;
    color: var(--text);
    font-family: inherit;
    font-size: 14px;
    resize: vertical;
    min-height: 60px;
}

.edit-form-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.btn-save-edit {
    background: #3498db;
    color: #fff;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.btn-save-edit:hover {
    background: #2980b9;
}

.btn-cancel-edit {
    background: transparent;
    border: 1px solid var(--border-bright);
    color: var(--text-muted);
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.btn-cancel-edit:hover {
    background: var(--bg-accent);
}
</style>

<?php if (isAdmin()): ?>
<!-- Retest Modal Dialog -->
<div id="retest-modal" class="retest-modal-overlay" style="display: none;">
    <div class="retest-modal">
        <div class="retest-modal-header">
            <h3>Request Retest</h3>
            <button type="button" class="retest-modal-close" onclick="closeRetestModal()">&times;</button>
        </div>
        <div class="retest-modal-body">
            <p class="retest-modal-info">
                Flagging <strong id="retest-modal-test-key"></strong> for retest on version <strong id="retest-modal-version"></strong>
            </p>
            <div class="form-group">
                <label for="retest-modal-notes">Notes <span class="required">*</span></label>
                <textarea id="retest-modal-notes" placeholder="Explain what needs to be retested and why..." rows="4"></textarea>
                <p id="retest-modal-error" class="retest-modal-error" style="display: none;">Notes are required - please explain what needs to be retested.</p>
            </div>
        </div>
        <div class="retest-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeRetestModal()">Cancel</button>
            <button type="button" class="btn" id="retest-modal-submit" onclick="submitRetestRequest()">Flag for Retest</button>
        </div>
    </div>
</div>

<style>
.retest-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.retest-modal {
    background: var(--bg-card);
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    animation: modalSlideIn 0.2s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.retest-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid var(--border);
}

.retest-modal-header h3 {
    margin: 0;
    color: var(--text);
    font-size: 18px;
}

.retest-modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    line-height: 1;
}

.retest-modal-close:hover {
    color: var(--text);
}

.retest-modal-body {
    padding: 20px;
}

.retest-modal-info {
    margin: 0 0 15px 0;
    color: var(--text-muted);
    font-size: 14px;
}

.retest-modal-body .form-group {
    margin: 0;
}

.retest-modal-body label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text);
}

.retest-modal-body label .required {
    color: #c45050;
}

.retest-modal-body textarea {
    width: 100%;
    background: var(--bg-dark);
    border: 1px solid var(--border-bright);
    border-radius: 6px;
    padding: 12px;
    color: var(--text);
    font-family: inherit;
    font-size: 14px;
    resize: vertical;
    min-height: 100px;
    transition: border-color 0.2s;
}

.retest-modal-body textarea:focus {
    outline: none;
    border-color: var(--primary);
}

.retest-modal-body textarea.error {
    border-color: #c45050;
}

.retest-modal-error {
    margin: 8px 0 0 0;
    color: #c45050;
    font-size: 13px;
    font-weight: 500;
}

.retest-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 15px 20px;
    border-top: 1px solid var(--border);
    background: var(--bg-dark);
    border-radius: 0 0 8px 8px;
}

.retest-modal-footer .btn {
    min-width: 120px;
}

.retest-modal-footer .btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>

<script>
var currentRetestButton = null;
var currentRetestTestKey = '';
var currentRetestClientVersion = '';

document.addEventListener('DOMContentLoaded', function() {
    // Handle retest button clicks - open modal
    document.querySelectorAll('.btn-retest').forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentRetestButton = this;
            currentRetestTestKey = this.dataset.testKey;
            currentRetestClientVersion = this.dataset.clientVersion;
            openRetestModal();
        });
    });

    // Close modal on overlay click
    document.getElementById('retest-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeRetestModal();
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeRetestModal();
        }
    });

    // Clear error when typing
    document.getElementById('retest-modal-notes').addEventListener('input', function() {
        if (this.value.trim()) {
            this.classList.remove('error');
            document.getElementById('retest-modal-error').style.display = 'none';
        }
    });
});

function openRetestModal() {
    document.getElementById('retest-modal-test-key').textContent = currentRetestTestKey;
    document.getElementById('retest-modal-version').textContent = currentRetestClientVersion;
    document.getElementById('retest-modal-notes').value = '';
    document.getElementById('retest-modal-notes').classList.remove('error');
    document.getElementById('retest-modal-error').style.display = 'none';
    document.getElementById('retest-modal-submit').disabled = false;
    document.getElementById('retest-modal-submit').textContent = 'Flag for Retest';
    document.getElementById('retest-modal').style.display = 'flex';
    document.getElementById('retest-modal-notes').focus();
}

function closeRetestModal() {
    document.getElementById('retest-modal').style.display = 'none';
    currentRetestButton = null;
    currentRetestTestKey = '';
    currentRetestClientVersion = '';
}

function submitRetestRequest() {
    var notes = document.getElementById('retest-modal-notes').value.trim();
    var notesTextarea = document.getElementById('retest-modal-notes');
    var errorEl = document.getElementById('retest-modal-error');
    var submitBtn = document.getElementById('retest-modal-submit');

    // Validate notes
    if (!notes) {
        notesTextarea.classList.add('error');
        errorEl.style.display = 'block';
        notesTextarea.focus();
        return;
    }

    // Disable button and show loading state
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    fetch('api/retest_request.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            test_key: currentRetestTestKey,
            client_version: currentRetestClientVersion,
            notes: notes,
            report_id: <?= $reportId ?>
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Close modal
            closeRetestModal();

            // Replace button with pending badge
            if (currentRetestButton) {
                var badge = document.createElement('span');
                badge.className = 'retest-badge';
                badge.title = 'Retest already requested';
                badge.textContent = 'Pending';
                currentRetestButton.parentNode.replaceChild(badge, currentRetestButton);

                // Add visual indicator to the row
                var row = badge.closest('tr');
                row.classList.add('retest-pending');

                // Add indicator next to test key
                var keyCell = row.querySelector('.test-key-link');
                if (keyCell && !row.querySelector('.retest-indicator')) {
                    var indicator = document.createElement('span');
                    indicator.className = 'retest-indicator';
                    indicator.title = 'Flagged for retest: ' + notes;
                    indicator.innerHTML = '&#x21BB;';
                    keyCell.parentNode.appendChild(indicator);
                }

                // Add retest notes display to the notes cell
                var notesCell = row.querySelector('.notes-cell');
                if (notesCell) {
                    var retestNotesDiv = document.createElement('div');
                    retestNotesDiv.className = 'retest-notes';
                    retestNotesDiv.innerHTML = '<span class="retest-notes-label">Retest notes:</span> ' + escapeHtmlForRetest(notes);
                    notesCell.appendChild(retestNotesDiv);
                }
            }
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
            submitBtn.disabled = false;
            submitBtn.textContent = 'Flag for Retest';
        }
    })
    .catch(function(error) {
        alert('Error: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Flag for Retest';
    });
}

function escapeHtmlForRetest(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
<?php endif; ?>

<!-- Comments JavaScript -->
<?php if (isLoggedIn()): ?>
<script>
// Comment form submission
document.getElementById('comment-form')?.addEventListener('submit', function(e) {
    e.preventDefault();

    var form = this;
    var submitBtn = form.querySelector('button[type="submit"]');
    var content = document.getElementById('comment-content').value.trim();
    var reportId = form.querySelector('input[name="report_id"]').value;
    var parentId = document.getElementById('reply-to-id').value;
    var quotedText = document.getElementById('quoted-text').value;

    if (!content) {
        alert('Please enter a comment');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Posting...';

    fetch('api/comments.php?action=create', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            report_id: parseInt(reportId),
            content: content,
            parent_comment_id: parentId ? parseInt(parentId) : null,
            quoted_text: quotedText || null
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Add the new comment to the list
            addCommentToList(data.comment);

            // Clear form
            document.getElementById('comment-content').value = '';
            cancelReply();

            // Update comment count
            updateCommentCount(1);

            // Remove "no comments" message if present
            var noComments = document.querySelector('.no-comments');
            if (noComments) {
                noComments.remove();
            }
        } else {
            alert('Error: ' + (data.error || 'Failed to post comment'));
        }
    })
    .catch(function(error) {
        alert('Error: ' + error.message);
    })
    .finally(function() {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Post Comment';
    });
});

// Add a comment to the list
function addCommentToList(comment) {
    var list = document.getElementById('comments-list');
    var html = createCommentHtml(comment);
    list.insertAdjacentHTML('beforeend', html);

    // Scroll to the new comment
    var newComment = document.getElementById('comment-' + comment.id);
    if (newComment) {
        newComment.scrollIntoView({ behavior: 'smooth', block: 'center' });
        newComment.style.animation = 'highlight 2s ease-out';
    }
}

// Create HTML for a comment
function createCommentHtml(comment) {
    var quotedHtml = '';
    if (comment.quoted_text) {
        quotedHtml = '<div class="comment-quote">' +
            '<div class="quote-header">Replying to ' + escapeHtml(comment.parent_author_name || 'deleted comment') + ':</div>' +
            '<div class="quote-content">' + escapeHtml(comment.quoted_text) + '</div>' +
        '</div>';
    }

    var adminBadge = comment.author_role === 'admin' ?
        '<span class="author-badge admin">Admin</span>' : '';

    var editedBadge = comment.is_edited ?
        '<span class="edited-badge">(edited)</span>' : '';

    var actions = '';
    if (comment.can_manage) {
        actions = '<button type="button" class="btn-comment-action btn-reply" onclick="replyToComment(' + comment.id + ', \'' + escapeHtml(comment.author_name) + '\', \'' + escapeHtml(comment.content.substring(0, 200)) + '\')">Reply</button>' +
            '<button type="button" class="btn-comment-action btn-quote" onclick="quoteComment(' + comment.id + ', \'' + escapeHtml(comment.author_name) + '\')">Quote</button>' +
            '<button type="button" class="btn-comment-action btn-edit" onclick="editComment(' + comment.id + ')">Edit</button>' +
            '<button type="button" class="btn-comment-action btn-delete" onclick="deleteComment(' + comment.id + ')">Delete</button>';
    } else {
        actions = '<button type="button" class="btn-comment-action btn-reply" onclick="replyToComment(' + comment.id + ', \'' + escapeHtml(comment.author_name) + '\', \'' + escapeHtml(comment.content.substring(0, 200)) + '\')">Reply</button>' +
            '<button type="button" class="btn-comment-action btn-quote" onclick="quoteComment(' + comment.id + ', \'' + escapeHtml(comment.author_name) + '\')">Quote</button>';
    }

    return '<div class="comment" id="comment-' + comment.id + '" data-comment-id="' + comment.id + '">' +
        '<div class="comment-header">' +
            '<div class="comment-author">' +
                '<span class="author-name">' + escapeHtml(comment.author_name) + '</span>' +
                adminBadge +
            '</div>' +
            '<div class="comment-meta">' +
                '<span class="comment-date">' + formatDate(comment.created_at) + '</span>' +
                editedBadge +
            '</div>' +
        '</div>' +
        quotedHtml +
        '<div class="comment-content" id="comment-content-' + comment.id + '">' + escapeHtml(comment.content).replace(/\n/g, '<br>') + '</div>' +
        '<div class="comment-actions">' + actions + '</div>' +
    '</div>';
}

// Reply to a comment
function replyToComment(commentId, authorName, preview) {
    document.getElementById('reply-to-id').value = commentId;
    document.getElementById('reply-to-name').textContent = authorName;
    document.getElementById('reply-quote-preview').textContent = preview.length > 100 ? preview.substring(0, 100) + '...' : preview;
    document.getElementById('quoted-text').value = '';
    document.getElementById('reply-indicator').classList.remove('hidden');
    document.getElementById('comment-content').focus();

    // Scroll to comment form
    document.getElementById('comment-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Quote a comment (includes quoted text)
function quoteComment(commentId, authorName) {
    // Get the comment content
    var contentEl = document.getElementById('comment-content-' + commentId);
    if (!contentEl) return;

    var content = contentEl.innerText.trim();
    var preview = content.length > 200 ? content.substring(0, 200) + '...' : content;

    document.getElementById('reply-to-id').value = commentId;
    document.getElementById('reply-to-name').textContent = authorName;
    document.getElementById('reply-quote-preview').textContent = preview;
    document.getElementById('quoted-text').value = preview;
    document.getElementById('reply-indicator').classList.remove('hidden');
    document.getElementById('comment-content').focus();

    // Scroll to comment form
    document.getElementById('comment-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Cancel reply
function cancelReply() {
    document.getElementById('reply-to-id').value = '';
    document.getElementById('quoted-text').value = '';
    document.getElementById('reply-indicator').classList.add('hidden');
}

// Edit a comment
function editComment(commentId) {
    var commentEl = document.getElementById('comment-' + commentId);
    var contentEl = document.getElementById('comment-content-' + commentId);
    if (!commentEl || !contentEl) return;

    // Check if already editing
    if (commentEl.classList.contains('editing')) return;

    var currentContent = contentEl.innerText.trim();

    // Add editing class
    commentEl.classList.add('editing');

    // Hide action buttons temporarily
    var actionsEl = commentEl.querySelector('.comment-actions');
    actionsEl.style.display = 'none';

    // Create edit form
    var editForm = document.createElement('div');
    editForm.className = 'edit-form';
    editForm.innerHTML = '<textarea id="edit-textarea-' + commentId + '">' + escapeHtml(currentContent) + '</textarea>' +
        '<div class="edit-form-actions">' +
            '<button type="button" class="btn-save-edit" onclick="saveEdit(' + commentId + ')">Save</button>' +
            '<button type="button" class="btn-cancel-edit" onclick="cancelEdit(' + commentId + ')">Cancel</button>' +
        '</div>';

    contentEl.parentNode.insertBefore(editForm, actionsEl);

    // Focus textarea
    document.getElementById('edit-textarea-' + commentId).focus();
}

// Save edited comment
function saveEdit(commentId) {
    var textarea = document.getElementById('edit-textarea-' + commentId);
    if (!textarea) return;

    var newContent = textarea.value.trim();
    if (!newContent) {
        alert('Comment cannot be empty');
        return;
    }

    var saveBtn = textarea.parentNode.querySelector('.btn-save-edit');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';

    fetch('api/comments.php?action=edit', {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            comment_id: commentId,
            content: newContent
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Update the comment content
            var contentEl = document.getElementById('comment-content-' + commentId);
            contentEl.innerHTML = escapeHtml(newContent).replace(/\n/g, '<br>');

            // Add edited badge if not present
            var commentEl = document.getElementById('comment-' + commentId);
            var metaEl = commentEl.querySelector('.comment-meta');
            if (!metaEl.querySelector('.edited-badge')) {
                metaEl.insertAdjacentHTML('beforeend', '<span class="edited-badge">(edited)</span>');
            }

            cancelEdit(commentId);
        } else {
            alert('Error: ' + (data.error || 'Failed to save'));
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        }
    })
    .catch(function(error) {
        alert('Error: ' + error.message);
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
    });
}

// Cancel edit
function cancelEdit(commentId) {
    var commentEl = document.getElementById('comment-' + commentId);
    if (!commentEl) return;

    commentEl.classList.remove('editing');

    // Remove edit form
    var editForm = commentEl.querySelector('.edit-form');
    if (editForm) {
        editForm.remove();
    }

    // Show action buttons
    var actionsEl = commentEl.querySelector('.comment-actions');
    actionsEl.style.display = '';
}

// Delete a comment
function deleteComment(commentId) {
    if (!confirm('Are you sure you want to delete this comment?')) {
        return;
    }

    fetch('api/comments.php?action=delete&comment_id=' + commentId, {
        method: 'DELETE'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Remove the comment from the list
            var commentEl = document.getElementById('comment-' + commentId);
            if (commentEl) {
                commentEl.style.animation = 'fadeOut 0.3s ease-out';
                setTimeout(function() {
                    commentEl.remove();
                    updateCommentCount(-1);

                    // Show "no comments" if empty
                    var list = document.getElementById('comments-list');
                    if (!list.querySelector('.comment')) {
                        list.innerHTML = '<p class="no-comments">No comments yet. Be the first to comment!</p>';
                    }
                }, 300);
            }
        } else {
            alert('Error: ' + (data.error || 'Failed to delete'));
        }
    })
    .catch(function(error) {
        alert('Error: ' + error.message);
    });
}

// Update comment count in the header
function updateCommentCount(delta) {
    var header = document.querySelector('#comments-section .card-title');
    if (header) {
        var match = header.textContent.match(/\((\d+)\)/);
        if (match) {
            var count = parseInt(match[1]) + delta;
            header.innerHTML = '<span style="font-size: 20px;">💬</span> Comments (' + count + ')';
        }
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to format date
function formatDate(dateStr) {
    var date = new Date(dateStr);
    var now = new Date();
    var diff = now - date;
    var seconds = Math.floor(diff / 1000);
    var minutes = Math.floor(seconds / 60);
    var hours = Math.floor(minutes / 60);
    var days = Math.floor(hours / 24);

    if (seconds < 60) return 'just now';
    if (minutes < 60) return minutes + ' min ago';
    if (hours < 24) return hours + ' hr ago';
    if (days < 7) return days + ' day' + (days > 1 ? 's' : '') + ' ago';

    return date.toLocaleDateString();
}

// Add highlight animation
var style = document.createElement('style');
style.textContent = '@keyframes highlight { 0% { background: var(--primary); } 100% { background: var(--bg-dark); } } @keyframes fadeOut { 0% { opacity: 1; } 100% { opacity: 0; transform: translateX(-20px); } }';
document.head.appendChild(style);
</script>
<?php endif; ?>

<!-- Commit Hash Popup Script -->
<script>
function showCommitPopup(sha) {
    // Fetch revision details from API
    fetch('api/revisions.php?sha=' + encodeURIComponent(sha))
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.revision) {
                var revision = data.revision;
                var dateTime = revision.datetime || 'Unknown';
                var notes = revision.notes || 'No commit message';
                var files = revision.files || {};

                var content = '<div style="text-align: left;">';
                content += '<p><strong>Date:</strong> ' + escapeHtmlPopup(dateTime) + '</p>';
                content += '<p><strong>Commit Message:</strong></p>';
                content += '<pre style="white-space: pre-wrap; word-wrap: break-word; background: var(--bg-dark); padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;">' + escapeHtmlPopup(notes) + '</pre>';

                if (files.added && files.added.length > 0) {
                    content += '<p><strong>Files Added:</strong></p>';
                    content += '<ul style="max-height: 100px; overflow-y: auto; font-size: 12px;">';
                    files.added.forEach(function(f) { content += '<li>' + escapeHtmlPopup(f) + '</li>'; });
                    content += '</ul>';
                }

                if (files.removed && files.removed.length > 0) {
                    content += '<p><strong>Files Removed:</strong></p>';
                    content += '<ul style="max-height: 100px; overflow-y: auto; font-size: 12px;">';
                    files.removed.forEach(function(f) { content += '<li>' + escapeHtmlPopup(f) + '</li>'; });
                    content += '</ul>';
                }

                if (files.modified && files.modified.length > 0) {
                    content += '<p><strong>Files Modified:</strong></p>';
                    content += '<ul style="max-height: 100px; overflow-y: auto; font-size: 12px;">';
                    files.modified.forEach(function(f) { content += '<li>' + escapeHtmlPopup(f) + '</li>'; });
                    content += '</ul>';
                }

                content += '<p style="margin-top: 15px;"><a href="?page=reports&commit=' + encodeURIComponent(sha) + '" class="btn btn-sm">View All Reports for this Commit</a></p>';
                content += '</div>';

                showCommitPopupDialog('Revision: ' + sha.substring(0, 8), content);
            } else {
                // Fallback: just show a link to filter by commit
                var content = '<p>Revision details not available.</p>';
                content += '<p><a href="?page=reports&commit=' + encodeURIComponent(sha) + '" class="btn btn-sm">View All Reports for this Commit</a></p>';
                showCommitPopupDialog('Revision: ' + sha.substring(0, 8), content);
            }
        })
        .catch(function(error) {
            alert('Error fetching revision details: ' + error.message);
        });
}

function escapeHtmlPopup(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showCommitPopupDialog(title, content) {
    var existingPopup = document.getElementById('commit-popup-overlay');
    if (existingPopup) {
        existingPopup.remove();
    }

    var overlay = document.createElement('div');
    overlay.id = 'commit-popup-overlay';
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center;';

    var popup = document.createElement('div');
    popup.style.cssText = 'background: var(--bg-card); padding: 20px; border-radius: 8px; max-width: 600px; max-height: 80vh; overflow-y: auto; color: var(--text); box-shadow: 0 10px 40px rgba(0,0,0,0.5);';

    popup.innerHTML = '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">' +
        '<h3 style="margin: 0; color: var(--primary);">' + escapeHtmlPopup(title) + '</h3>' +
        '<button onclick="document.getElementById(\'commit-popup-overlay\').remove();" style="background: none; border: none; color: var(--text); font-size: 24px; cursor: pointer;">&times;</button>' +
        '</div>' + content;

    overlay.appendChild(popup);
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.remove();
        }
    });

    document.body.appendChild(overlay);
}
</script>

<!-- Log Viewer Modal -->
<div id="log-viewer-modal" class="log-viewer-overlay" style="display: none;">
    <div class="log-viewer-modal">
        <div class="log-viewer-header">
            <h3 id="log-viewer-title">Log Viewer</h3>
            <div class="log-viewer-controls">
                <button type="button" class="btn btn-sm" onclick="toggleLogWordWrap()" title="Toggle word wrap" id="wrap-toggle-btn">
                    ↔️ Wrap
                </button>
                <button type="button" class="btn btn-sm" onclick="copyLogContent()" title="Copy to clipboard">
                    📋 Copy
                </button>
                <button type="button" class="btn btn-sm" onclick="downloadCurrentLog()" title="Download file" id="download-log-btn">
                    ⬇️ Download
                </button>
                <button type="button" class="log-viewer-close" onclick="closeLogViewer()">&times;</button>
            </div>
        </div>
        <div class="log-viewer-info" id="log-viewer-info"></div>
        <div class="log-viewer-body">
            <div class="log-viewer-loading" id="log-viewer-loading">
                <span>Loading log file...</span>
            </div>
            <pre class="log-viewer-content" id="log-viewer-content"></pre>
        </div>
        <div class="log-viewer-footer">
            <div class="log-viewer-stats" id="log-viewer-stats"></div>
            <button type="button" class="btn btn-secondary" onclick="closeLogViewer()">Close</button>
        </div>
    </div>
</div>

<style>
/* Log Viewer Modal Styles */
.log-viewer-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.85);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.log-viewer-modal {
    background: var(--bg-card);
    border-radius: 8px;
    width: 95%;
    max-width: 1200px;
    height: 90vh;
    max-height: 900px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 50px rgba(0, 0, 0, 0.6);
    border-top: solid 2px #899281;
    border-bottom: solid 2px #292d23;
    border-left: solid 2px #899281;
    border-right: solid 2px #292d23;
    animation: logViewerSlideIn 0.2s ease-out;
}

@keyframes logViewerSlideIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.log-viewer-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-accent);
    border-radius: 6px 6px 0 0;
}

.log-viewer-header h3 {
    margin: 0;
    color: var(--primary);
    font-size: 16px;
    font-family: monospace;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 60%;
}

.log-viewer-controls {
    display: flex;
    gap: 8px;
    align-items: center;
}

.log-viewer-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 28px;
    cursor: pointer;
    padding: 0 8px;
    line-height: 1;
    margin-left: 10px;
}

.log-viewer-close:hover {
    color: #c45050;
}

.log-viewer-info {
    padding: 8px 20px;
    background: var(--bg-dark);
    font-size: 12px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.log-viewer-info .info-item {
    display: flex;
    gap: 6px;
}

.log-viewer-info .info-label {
    color: var(--text-muted);
}

.log-viewer-info .info-value {
    color: var(--text);
    font-family: monospace;
}

.log-viewer-body {
    flex: 1;
    overflow: hidden;
    position: relative;
    background: #1e2218;
}

.log-viewer-loading {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: var(--primary);
    font-size: 14px;
}

.log-viewer-content {
    margin: 0;
    padding: 15px 20px;
    font-family: 'Consolas', 'Courier New', monospace;
    font-size: 12px;
    line-height: 1.5;
    color: #b8c4ad;
    background: #1e2218;
    overflow: auto;
    height: 100%;
    white-space: pre;
    tab-size: 4;
}

.log-viewer-content.word-wrap {
    white-space: pre-wrap;
    word-wrap: break-word;
}

/* Log syntax highlighting */
.log-viewer-content .log-timestamp {
    color: #7ea64b;
}

.log-viewer-content .log-error {
    color: #c45050;
    font-weight: bold;
}

.log-viewer-content .log-warning {
    color: #c4b550;
}

.log-viewer-content .log-info {
    color: #3498db;
}

.log-viewer-content .log-debug {
    color: #808080;
}

.log-viewer-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 20px;
    border-top: 1px solid var(--border);
    background: var(--bg-dark);
    border-radius: 0 0 6px 6px;
}

.log-viewer-stats {
    font-size: 12px;
    color: var(--text-muted);
    display: flex;
    gap: 20px;
}

.log-viewer-stats span {
    display: flex;
    gap: 5px;
}

/* Wrap button active state */
#wrap-toggle-btn.active {
    background: var(--primary);
    color: #fff;
}
</style>

<script>
var currentLogId = null;
var currentLogFilename = null;
var currentLogContent = null;
var logWordWrap = false;

function openLogViewer(logId, filename) {
    currentLogId = logId;
    currentLogFilename = filename;
    currentLogContent = null;

    // Show modal
    document.getElementById('log-viewer-modal').style.display = 'flex';
    document.getElementById('log-viewer-title').textContent = filename;
    document.getElementById('log-viewer-content').textContent = '';
    document.getElementById('log-viewer-loading').style.display = 'block';
    document.getElementById('log-viewer-info').innerHTML = '';
    document.getElementById('log-viewer-stats').innerHTML = '';

    // Fetch log content
    fetch('api/view_log.php?id=' + logId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            document.getElementById('log-viewer-loading').style.display = 'none';

            if (data.success) {
                currentLogContent = data.content;

                // Display info
                var infoHtml = '<span class="info-item"><span class="info-label">File:</span><span class="info-value">' + escapeHtmlLog(data.filename) + '</span></span>';
                infoHtml += '<span class="info-item"><span class="info-label">Date:</span><span class="info-value">' + escapeHtmlLog(data.datetime || 'Unknown') + '</span></span>';
                infoHtml += '<span class="info-item"><span class="info-label">Size:</span><span class="info-value">' + formatBytes(data.size_original) + '</span></span>';
                document.getElementById('log-viewer-info').innerHTML = infoHtml;

                // Apply syntax highlighting
                var highlighted = highlightLogContent(data.content);
                document.getElementById('log-viewer-content').innerHTML = highlighted;

                // Calculate stats
                var lines = data.content.split('\n').length;
                var chars = data.content.length;
                document.getElementById('log-viewer-stats').innerHTML =
                    '<span><strong>Lines:</strong> ' + lines.toLocaleString() + '</span>' +
                    '<span><strong>Characters:</strong> ' + chars.toLocaleString() + '</span>';
            } else {
                document.getElementById('log-viewer-content').textContent = 'Error: ' + (data.error || 'Failed to load log file');
            }
        })
        .catch(function(error) {
            document.getElementById('log-viewer-loading').style.display = 'none';
            document.getElementById('log-viewer-content').textContent = 'Error: ' + error.message;
        });

    // Keyboard shortcuts
    document.addEventListener('keydown', logViewerKeyHandler);
}

function closeLogViewer() {
    document.getElementById('log-viewer-modal').style.display = 'none';
    document.removeEventListener('keydown', logViewerKeyHandler);
    currentLogId = null;
    currentLogFilename = null;
    currentLogContent = null;
}

function logViewerKeyHandler(e) {
    if (e.key === 'Escape') {
        closeLogViewer();
    } else if (e.key === 'w' && e.ctrlKey) {
        e.preventDefault();
        toggleLogWordWrap();
    }
}

function toggleLogWordWrap() {
    logWordWrap = !logWordWrap;
    var content = document.getElementById('log-viewer-content');
    var btn = document.getElementById('wrap-toggle-btn');

    if (logWordWrap) {
        content.classList.add('word-wrap');
        btn.classList.add('active');
    } else {
        content.classList.remove('word-wrap');
        btn.classList.remove('active');
    }
}

function copyLogContent() {
    if (!currentLogContent) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(currentLogContent).then(function() {
            showNotification('Log content copied to clipboard!', 'success');
        }).catch(function() {
            fallbackCopyLog();
        });
    } else {
        fallbackCopyLog();
    }
}

function fallbackCopyLog() {
    var textarea = document.createElement('textarea');
    textarea.value = currentLogContent;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showNotification('Log content copied to clipboard!', 'success');
    } catch (err) {
        showNotification('Failed to copy', 'error');
    }
    document.body.removeChild(textarea);
}

function downloadCurrentLog() {
    if (currentLogId) {
        window.location.href = 'api/download_log.php?id=' + currentLogId;
    }
}

function highlightLogContent(content) {
    // Escape HTML first
    var escaped = escapeHtmlLog(content);

    // Apply syntax highlighting for common log patterns
    // Timestamps: various formats
    escaped = escaped.replace(/(\d{4}[-\/]\d{2}[-\/]\d{2}[T\s]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)/g, '<span class="log-timestamp">$1</span>');
    escaped = escaped.replace(/(\[\d{2}:\d{2}:\d{2}\])/g, '<span class="log-timestamp">$1</span>');

    // Error patterns
    escaped = escaped.replace(/(\bERROR\b|\bFATAL\b|\bCRITICAL\b|\bFAILED\b|\bFAILURE\b|\bException\b)/gi, '<span class="log-error">$1</span>');

    // Warning patterns
    escaped = escaped.replace(/(\bWARN(?:ING)?\b|\bCAUTION\b)/gi, '<span class="log-warning">$1</span>');

    // Info patterns
    escaped = escaped.replace(/(\bINFO\b|\bNOTICE\b)/gi, '<span class="log-info">$1</span>');

    // Debug patterns
    escaped = escaped.replace(/(\bDEBUG\b|\bTRACE\b)/gi, '<span class="log-debug">$1</span>');

    return escaped;
}

function escapeHtmlLog(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    var k = 1024;
    var sizes = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Close on overlay click
document.getElementById('log-viewer-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeLogViewer();
    }
});
</script>

<!-- Report Tags JavaScript -->
<?php if (isAdmin()): ?>
<script>
var reportId = <?= $reportId ?>;

// Toggle add tag dropdown
function toggleAddTagDropdown() {
    var dropdown = document.getElementById('tag-dropdown-menu');
    if (dropdown.style.display === 'none') {
        dropdown.style.display = 'block';
        // Close when clicking outside
        setTimeout(function() {
            document.addEventListener('click', closeTagDropdownOnClickOutside);
        }, 10);
    } else {
        dropdown.style.display = 'none';
        document.removeEventListener('click', closeTagDropdownOnClickOutside);
    }
}

function closeTagDropdownOnClickOutside(e) {
    var dropdown = document.getElementById('tag-dropdown-menu');
    var btn = document.querySelector('.add-tag-btn');
    if (!dropdown.contains(e.target) && e.target !== btn) {
        dropdown.style.display = 'none';
        document.removeEventListener('click', closeTagDropdownOnClickOutside);
    }
}

// Add tag to report
function addReportTag(tagId, tagName, tagColor) {
    fetch('api/report_tags.php?action=add', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            report_id: reportId,
            tag_id: tagId
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Add tag to display
            var container = document.getElementById('report-tags-container');
            var noTags = container.querySelector('.no-tags');
            if (noTags) {
                noTags.remove();
            }

            var tagEl = document.createElement('span');
            tagEl.className = 'report-tag';
            tagEl.style.backgroundColor = tagColor;
            tagEl.setAttribute('data-tag-id', tagId);
            tagEl.innerHTML = escapeHtmlTag(tagName) +
                '<button type="button" class="tag-remove-btn" onclick="removeReportTag(' + tagId + ', \'' + escapeHtmlTag(tagName) + '\')" title="Remove tag">&times;</button>';
            container.appendChild(tagEl);

            // Remove from dropdown
            var dropdownItems = document.querySelectorAll('.tag-dropdown-item');
            dropdownItems.forEach(function(item) {
                if (item.onclick && item.onclick.toString().includes('addReportTag(' + tagId)) {
                    item.remove();
                }
            });

            // Check if dropdown is empty
            var remainingItems = document.querySelectorAll('.tag-dropdown-item').length;
            if (remainingItems === 0) {
                var dropdown = document.getElementById('tag-dropdown-menu');
                var footer = dropdown.querySelector('.tag-dropdown-footer');
                var emptyMsg = document.createElement('div');
                emptyMsg.className = 'tag-dropdown-empty';
                emptyMsg.textContent = 'All tags assigned';
                dropdown.insertBefore(emptyMsg, footer);
            }

            // Close dropdown
            document.getElementById('tag-dropdown-menu').style.display = 'none';

            showNotification('Tag "' + tagName + '" added', 'success');
        } else {
            showNotification('Error: ' + (data.error || 'Failed to add tag'), 'error');
        }
    })
    .catch(function(error) {
        showNotification('Error: ' + error.message, 'error');
    });
}

// Remove tag from report
function removeReportTag(tagId, tagName) {
    if (!confirm('Remove tag "' + tagName + '" from this report?')) {
        return;
    }

    fetch('api/report_tags.php?action=remove', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            report_id: reportId,
            tag_id: tagId
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Remove tag from display
            var tagEl = document.querySelector('.report-tag[data-tag-id="' + tagId + '"]');
            if (tagEl) {
                tagEl.style.animation = 'tagFadeOut 0.2s ease-out';
                setTimeout(function() {
                    tagEl.remove();

                    // Check if no tags left
                    var container = document.getElementById('report-tags-container');
                    if (!container.querySelector('.report-tag')) {
                        var noTags = document.createElement('span');
                        noTags.className = 'no-tags';
                        noTags.textContent = 'No tags assigned';
                        container.appendChild(noTags);
                    }
                }, 200);
            }

            // Refresh page to get updated dropdown (simpler than reconstructing)
            showNotification('Tag removed. Refreshing...', 'success');
            setTimeout(function() {
                location.reload();
            }, 500);
        } else {
            showNotification('Error: ' + (data.error || 'Failed to remove tag'), 'error');
        }
    })
    .catch(function(error) {
        showNotification('Error: ' + error.message, 'error');
    });
}

function escapeHtmlTag(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Add fade out animation
var tagStyle = document.createElement('style');
tagStyle.textContent = '@keyframes tagFadeOut { from { opacity: 1; transform: scale(1); } to { opacity: 0; transform: scale(0.8); } }';
document.head.appendChild(tagStyle);
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
