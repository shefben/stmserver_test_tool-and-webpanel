<?php
/**
 * Version compatibility matrix page
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

// Get version matrix data
$matrix = $db->getVersionMatrix();

// Get all unique versions and tests
$versions = $db->getUniqueValues('reports', 'client_version');
$testKeys = getSortedTestKeys();

// Get aggregated status for each version/test combination
$matrixData = [];
foreach ($matrix as $row) {
    $version = $row['client_version'];
    $testKey = $row['test_key'];
    $status = $row['most_common_status'];

    if (!isset($matrixData[$version])) {
        $matrixData[$version] = [];
    }
    $matrixData[$version][$testKey] = $status;
}

// Sort versions (newest first based on date in name)
usort($versions, function($a, $b) {
    // Try to extract date from version string
    preg_match('/(\d{4})[-_]?(\d{2})[-_]?(\d{2})?/', $a, $matchA);
    preg_match('/(\d{4})[-_]?(\d{2})[-_]?(\d{2})?/', $b, $matchB);

    if ($matchA && $matchB) {
        $dateA = $matchA[1] . ($matchA[2] ?? '01') . ($matchA[3] ?? '01');
        $dateB = $matchB[1] . ($matchB[2] ?? '01') . ($matchB[3] ?? '01');
        return strcmp($dateB, $dateA); // Descending
    }
    return strcmp($b, $a);
});
?>

<h1 class="page-title">Version Compatibility Matrix</h1>

<!-- Legend - Clickable -->
<div class="legend" style="margin-bottom: 20px;">
    <a href="?page=results&status=Working" class="legend-item clickable">
        <div class="legend-swatch" style="background: var(--status-working);"></div>
        <span>Working</span>
    </a>
    <a href="?page=results&status=Semi-working" class="legend-item clickable">
        <div class="legend-swatch" style="background: var(--status-semi);"></div>
        <span>Semi-working</span>
    </a>
    <a href="?page=results&status=Not+working" class="legend-item clickable">
        <div class="legend-swatch" style="background: var(--status-broken);"></div>
        <span>Not working</span>
    </a>
    <a href="?page=results&status=N/A" class="legend-item clickable">
        <div class="legend-swatch" style="background: var(--status-na);"></div>
        <span>N/A</span>
    </a>
    <div class="legend-item">
        <div class="legend-swatch" style="background: var(--border); opacity: 0.5;"></div>
        <span>No data</span>
    </div>
</div>

<?php if (empty($versions)): ?>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            No report data available yet. <a href="?page=submit">Submit a report</a> or <a href="?page=create_report">Create a new report</a> to populate the matrix.
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="matrix-container">
            <table class="matrix-table">
                <thead>
                    <tr>
                        <th style="position: sticky; left: 0; background: var(--bg-card); z-index: 10;">Test</th>
                        <?php foreach ($versions as $version): ?>
                            <?php
                            // Strip SECONDBLOB.BIN. prefix for display (case-insensitive)
                            $displayVersion = preg_replace('/^SECONDBLOB\.BIN\./i', '', $version);
                            ?>
                            <th class="version-header-cell">
                                <a href="?page=results&version=<?= urlencode($version) ?>" class="version-header-link" title="View all results for <?= e($version) ?>">
                                    <?= e($displayVersion) ?>
                                </a>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($testKeys as $testKey): ?>
                        <?php $testName = getTestName($testKey); ?>
                        <tr>
                            <td style="position: sticky; left: 0; background: var(--bg-card); z-index: 5; white-space: nowrap;">
                                <a href="?page=results&test_key=<?= urlencode($testKey) ?>" class="test-row-link">
                                    <span style="font-weight: bold; font-family: monospace; color: var(--primary);"><?= e($testKey) ?></span>
                                    <span style="color: var(--text-muted); margin-left: 8px;" title="<?= e($testName) ?>">
                                        <?= e(strlen($testName) > 25 ? substr($testName, 0, 25) . '...' : $testName) ?>
                                    </span>
                                </a>
                            </td>
                            <?php foreach ($versions as $version): ?>
                                <?php
                                $status = $matrixData[$version][$testKey] ?? null;
                                $cellClass = 'empty';
                                if ($status === 'Working') $cellClass = 'working';
                                elseif ($status === 'Semi-working') $cellClass = 'semi-working';
                                elseif ($status === 'Not working') $cellClass = 'not-working';
                                elseif ($status === 'N/A') $cellClass = 'na';
                                ?>
                                <td>
                                    <?php if ($status): ?>
                                        <a href="?page=results&version=<?= urlencode($version) ?>&test_key=<?= urlencode($testKey) ?>" class="matrix-cell-link">
                                            <div class="matrix-cell <?= $cellClass ?>"
                                                 data-tooltip="<?= e($testKey) ?>: <?= e($status) ?> (<?= e($version) ?>)">
                                            </div>
                                        </a>
                                    <?php else: ?>
                                        <div class="matrix-cell <?= $cellClass ?>"
                                             data-tooltip="<?= e($testKey) ?>: No data (<?= e($version) ?>)">
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Version Summary -->
    <h2 style="margin: 30px 0 20px;">Version Summary</h2>
    <div class="card">
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th>Version</th>
                        <th>Reports</th>
                        <th>Duration</th>
                        <th>Working</th>
                        <th>Semi-working</th>
                        <th>Not Working</th>
                        <th>Compatibility Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($versions as $version): ?>
                        <?php
                        $versionStats = $db->getVersionStats($version);
                        $total = $versionStats['working'] + $versionStats['semi_working'] + $versionStats['not_working'];
                        $score = $total > 0 ? round((($versionStats['working'] + $versionStats['semi_working'] * 0.5) / $total) * 100) : 0;
                        $avgDuration = $db->getVersionAverageDuration($version);
                        ?>
                        <tr class="clickable-row" onclick="window.location='?page=results&version=<?= urlencode($version) ?>'">
                            <td style="font-weight: 600;">
                                <a href="?page=results&version=<?= urlencode($version) ?>" class="version-link">
                                    <?= e($version) ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=reports&version=<?= urlencode($version) ?>" class="stat-link" onclick="event.stopPropagation();">
                                    <?= $versionStats['report_count'] ?>
                                </a>
                            </td>
                            <td style="color: var(--text-muted); font-family: monospace;">
                                <?= formatDuration($avgDuration) ?>
                            </td>
                            <td>
                                <a href="?page=results&version=<?= urlencode($version) ?>&status=Working" class="stat-link working" onclick="event.stopPropagation();">
                                    <?= $versionStats['working'] ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&version=<?= urlencode($version) ?>&status=Semi-working" class="stat-link semi" onclick="event.stopPropagation();">
                                    <?= $versionStats['semi_working'] ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&version=<?= urlencode($version) ?>&status=Not+working" class="stat-link broken" onclick="event.stopPropagation();">
                                    <?= $versionStats['not_working'] ?>
                                </a>
                            </td>
                            <td>
                                <div class="progress-bar" style="width: 100px; display: inline-block; vertical-align: middle;">
                                    <div class="fill" style="width: <?= $score ?>%; background: <?= $score >= 70 ? 'var(--status-working)' : ($score >= 40 ? 'var(--status-semi)' : 'var(--status-broken)') ?>;"></div>
                                </div>
                                <span style="margin-left: 10px;"><?= $score ?>%</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<style>
/* Version header cells - vertical text flowing upward */
.version-header-cell {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    transform: rotate(180deg);
    height: 120px;
    font-size: 11px;
    vertical-align: bottom;
    padding: 8px 4px !important;
    white-space: nowrap;
}
.version-header-cell .version-header-link {
    display: block;
}

/* Clickable legend */
.legend-item.clickable {
    text-decoration: none;
    color: var(--text-muted);
    transition: all 0.2s;
    padding: 5px 10px;
    border-radius: 4px;
}
.legend-item.clickable:hover {
    background: var(--bg-accent);
    color: var(--text);
}

/* Version header links */
.version-header-link {
    color: var(--text);
    text-decoration: none;
    transition: color 0.2s;
}
.version-header-link:hover {
    color: var(--primary);
}

/* Test row links */
.test-row-link {
    text-decoration: none;
    display: block;
}
.test-row-link:hover {
    opacity: 0.8;
}

/* Matrix cell links */
.matrix-cell-link {
    display: block;
}
.matrix-cell-link:hover .matrix-cell {
    transform: scale(1.2);
    box-shadow: 0 2px 8px rgba(0,0,0,0.4);
}

/* Clickable rows */
.clickable-row {
    cursor: pointer;
    transition: background 0.2s;
}
.clickable-row:hover {
    background: var(--bg-accent) !important;
}

/* Version link in summary */
.version-link {
    color: var(--text);
    text-decoration: none;
}
.version-link:hover {
    color: var(--primary);
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
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
