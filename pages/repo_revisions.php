<?php
/**
 * Repository Revisions (Commits) page
 * Shows test statistics grouped by commit hash
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

// Get commit stats
$commitStats = $db->getCommitStats();

// Calculate totals
$totalCommits = count($commitStats);
$totalReports = 0;
$totalWorking = 0;
$totalSemi = 0;
$totalBroken = 0;

foreach ($commitStats as $commit) {
    $totalReports += $commit['report_count'];
    $totalWorking += $commit['working'];
    $totalSemi += $commit['semi_working'];
    $totalBroken += $commit['not_working'];
}
?>

<h1 class="page-title">Repository Revisions</h1>

<!-- Summary Stats -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card primary">
        <div class="value"><?= number_format($totalCommits) ?></div>
        <div class="label">Total Revisions</div>
    </div>
    <a href="?page=reports" class="stat-card clickable-card">
        <div class="value"><?= number_format($totalReports) ?></div>
        <div class="label">Total Reports</div>
        <div class="card-hint">Click to view</div>
    </a>
    <a href="?page=results&status=Working" class="stat-card working clickable-card">
        <div class="value"><?= number_format($totalWorking) ?></div>
        <div class="label">Working Tests</div>
        <div class="card-hint">Click to view</div>
    </a>
    <a href="?page=results&status=Semi-working" class="stat-card semi clickable-card">
        <div class="value"><?= number_format($totalSemi) ?></div>
        <div class="label">Semi-working Tests</div>
        <div class="card-hint">Click to view</div>
    </a>
    <a href="?page=results&status=Not+working" class="stat-card broken clickable-card">
        <div class="value"><?= number_format($totalBroken) ?></div>
        <div class="label">Failed Tests</div>
        <div class="card-hint">Click to view</div>
    </a>
</div>

<?php if (empty($commitStats)): ?>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            No commit data available yet. <a href="?page=submit">Submit a report</a> or <a href="?page=create_report">Create a new report</a> with a commit hash to see revision statistics.
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th>Commit Hash</th>
                        <th>Reports</th>
                        <th>Working</th>
                        <th>Semi-working</th>
                        <th>Failed</th>
                        <th>Total Tests</th>
                        <th>Pass Rate</th>
                        <th>Last Report</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commitStats as $commit): ?>
                        <?php
                        $totalTests = $commit['working'] + $commit['semi_working'] + $commit['not_working'];
                        $passRate = $totalTests > 0 ? round((($commit['working'] + $commit['semi_working'] * 0.5) / $totalTests) * 100) : 0;
                        $shortHash = strlen($commit['commit_hash']) > 12 ? substr($commit['commit_hash'], 0, 12) . '...' : $commit['commit_hash'];
                        // Get revision datetime if available
                        $revisionInfo = null;
                        $commitDateTime = '';
                        if (isGitHubConfigured()) {
                            $revisionInfo = getRevisionBySha($commit['commit_hash']);
                            if ($revisionInfo) {
                                $commitDateTime = formatRevisionDateTime($revisionInfo['ts']);
                            }
                        }
                        ?>
                        <tr class="clickable-row" onclick="window.location='?page=results&commit=<?= urlencode($commit['commit_hash']) ?>'">
                            <td>
                                <a href="#" class="commit-link"
                                   title="<?= $commitDateTime ? e($commitDateTime) : e($commit['commit_hash']) ?>"
                                   onclick="event.stopPropagation(); showCommitPopup('<?= e($commit['commit_hash']) ?>'); return false;">
                                    <code><?= e($shortHash) ?></code>
                                </a>
                                <?php if ($commitDateTime): ?>
                                    <span class="commit-date-hint"><?= e($commitDateTime) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?page=reports&commit=<?= urlencode($commit['commit_hash']) ?>" class="stat-link" onclick="event.stopPropagation();">
                                    <?= $commit['report_count'] ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&commit=<?= urlencode($commit['commit_hash']) ?>&status=Working" class="stat-link working" onclick="event.stopPropagation();">
                                    <?= number_format($commit['working']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&commit=<?= urlencode($commit['commit_hash']) ?>&status=Semi-working" class="stat-link semi" onclick="event.stopPropagation();">
                                    <?= number_format($commit['semi_working']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&commit=<?= urlencode($commit['commit_hash']) ?>&status=Not+working" class="stat-link broken" onclick="event.stopPropagation();">
                                    <?= number_format($commit['not_working']) ?>
                                </a>
                            </td>
                            <td style="color: var(--text-muted);">
                                <?= number_format($commit['total_tests']) ?>
                            </td>
                            <td>
                                <div class="progress-bar" style="width: 80px; display: inline-block; vertical-align: middle;">
                                    <div class="fill" style="width: <?= $passRate ?>%; background: <?= $passRate >= 70 ? 'var(--status-working)' : ($passRate >= 40 ? 'var(--status-semi)' : 'var(--status-broken)') ?>;"></div>
                                </div>
                                <span style="margin-left: 8px;"><?= $passRate ?>%</span>
                            </td>
                            <td style="color: var(--text-muted); font-size: 12px;">
                                <?= formatRelativeTime($commit['last_report']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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

/* Stat links */
.stat-link {
    text-decoration: none;
    font-weight: bold;
    padding: 2px 8px;
    border-radius: 4px;
    transition: all 0.2s;
    color: var(--text);
}
.stat-link:hover {
    transform: scale(1.1);
    background: var(--bg-accent);
}
.stat-link.working { color: var(--status-working); }
.stat-link.working:hover { background: var(--status-working); color: #fff; }
.stat-link.semi { color: var(--status-semi); }
.stat-link.semi:hover { background: var(--status-semi); color: #fff; }
.stat-link.broken { color: var(--status-broken); }
.stat-link.broken:hover { background: var(--status-broken); color: #fff; }

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

/* Progress bar */
.progress-bar {
    height: 8px;
    background: var(--bg-accent);
    border-radius: 4px;
    overflow: hidden;
}
.progress-bar .fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s ease;
}

/* Commit date hint */
.commit-date-hint {
    display: block;
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 2px;
}
</style>

<script>
function showCommitPopup(sha) {
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

                content += '<p style="margin-top: 15px;"><a href="?page=results&commit=' + encodeURIComponent(sha) + '" class="btn btn-sm">View Test Results for this Commit</a></p>';
                content += '</div>';

                showCommitPopupDialog('Revision: ' + sha.substring(0, 8), content);
            } else {
                var content = '<p>Revision details not available.</p>';
                content += '<p><a href="?page=results&commit=' + encodeURIComponent(sha) + '" class="btn btn-sm">View Test Results for this Commit</a></p>';
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
    popup.style.cssText = 'background: rgba(76, 88, 68, 0.8); padding: 20px; border-radius: 8px; max-width: 600px; max-height: 80vh; overflow-y: auto; color: var(--text); box-shadow: 0 10px 40px rgba(0,0,0,0.5);';

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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
