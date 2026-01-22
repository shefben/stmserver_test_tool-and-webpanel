<?php
/**
 * Dashboard page
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

// Get overall statistics
$stats = $db->getStats();
$recentReports = $db->getReports(5);
$problematicTests = $db->getProblematicTests(10);
$versionTrend = $db->getVersionTrend();

// Calculate percentages
$totalTests = $stats['working'] + $stats['semi_working'] + $stats['not_working'];
$workingPct = $totalTests > 0 ? round(($stats['working'] / $totalTests) * 100, 1) : 0;
$semiPct = $totalTests > 0 ? round(($stats['semi_working'] / $totalTests) * 100, 1) : 0;
$brokenPct = $totalTests > 0 ? round(($stats['not_working'] / $totalTests) * 100, 1) : 0;
?>

<h1 class="page-title"><?= e(PANEL_NAME) ?></h1>

<!-- Stats Cards - All Clickable -->
<div class="stats-grid">
    <a href="?page=reports" class="stat-card primary clickable-card">
        <div class="value"><?= number_format($stats['total_reports']) ?></div>
        <div class="label">Total Reports</div>
        <div class="card-hint">Click to view all reports</div>
    </a>
    <a href="?page=results&status=Working" class="stat-card working clickable-card">
        <div class="value"><?= $workingPct ?>%</div>
        <div class="label">Working (<?= number_format($stats['working']) ?>)</div>
        <div class="card-hint">Click to view all working tests</div>
    </a>
    <a href="?page=results&status=Semi-working" class="stat-card semi clickable-card">
        <div class="value"><?= $semiPct ?>%</div>
        <div class="label">Semi-working (<?= number_format($stats['semi_working']) ?>)</div>
        <div class="card-hint">Click to view all semi-working tests</div>
    </a>
    <a href="?page=results&status=Not+working" class="stat-card broken clickable-card">
        <div class="value"><?= $brokenPct ?>%</div>
        <div class="label">Not Working (<?= number_format($stats['not_working']) ?>)</div>
        <div class="card-hint">Click to view all failed tests</div>
    </a>
</div>

<!-- Charts Row -->
<div class="charts-grid">
    <!-- Status Distribution -->
    <div class="chart-card">
        <h3 class="card-title">Status Distribution</h3>
        <div class="chart-container">
            <canvas id="statusChart"></canvas>
        </div>
        <div class="chart-links">
            <a href="?page=results&status=Working" class="chart-link working">Working</a>
            <a href="?page=results&status=Semi-working" class="chart-link semi">Semi-working</a>
            <a href="?page=results&status=Not+working" class="chart-link broken">Not working</a>
        </div>
    </div>

    <!-- Version Trend -->
    <div class="chart-card">
        <h3 class="card-title">Test Results by Version</h3>
        <div class="chart-container">
            <canvas id="versionChart"></canvas>
        </div>
        <div class="chart-links" style="flex-wrap: wrap;">
            <?php foreach (array_slice($versionTrend, 0, 6) as $v): ?>
                <a href="?page=results&version=<?= urlencode($v['client_version']) ?>" class="chart-link version">
                    <?= e($v['client_version']) ?>
                </a>
            <?php endforeach; ?>
            <?php if (count($versionTrend) > 6): ?>
                <a href="?page=versions" class="chart-link">View all versions...</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Second Row -->
<div class="charts-grid">
    <!-- Problematic Tests -->
    <div class="chart-card">
        <h3 class="card-title">Most Problematic Tests</h3>
        <div class="chart-container">
            <canvas id="problematicChart"></canvas>
        </div>
        <div class="problematic-list">
            <?php foreach (array_slice($problematicTests, 0, 5) as $test): ?>
                <a href="?page=results&test_key=<?= urlencode($test['test_key']) ?>&status=Not+working" class="problematic-item">
                    <span class="test-key"><?= e($test['test_key']) ?></span>
                    <span class="test-name"><?= e(getTestName($test['test_key'])) ?></span>
                    <span class="fail-rate"><?= round($test['fail_rate'], 1) ?>% fail</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Recent Reports -->
    <div class="card">
        <h3 class="card-title">Recent Reports</h3>
        <?php if (empty($recentReports)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 40px;">
                No reports submitted yet. <a href="?page=submit">Submit one now</a>.
            </p>
        <?php else: ?>
            <ul class="recent-list">
                <?php foreach ($recentReports as $report): ?>
                    <?php $reportStats = $db->getReportStats($report['id']); ?>
                    <li>
                        <div class="info">
                            <div class="title">
                                <a href="?page=report_detail&id=<?= $report['id'] ?>">
                                    <?= e($report['client_version']) ?>
                                </a>
                            </div>
                            <div class="subtitle">
                                by <a href="?page=results&tester=<?= urlencode($report['tester']) ?>"><?= e($report['tester']) ?></a>
                                &bull; <?= e($report['test_type']) ?>
                            </div>
                        </div>
                        <div class="report-mini-stats">
                            <a href="?page=results&report_id=<?= $report['id'] ?>&status=Working" class="mini-stat working" title="Working"><?= $reportStats['working'] ?></a>
                            <a href="?page=results&report_id=<?= $report['id'] ?>&status=Semi-working" class="mini-stat semi" title="Semi-working"><?= $reportStats['semi_working'] ?></a>
                            <a href="?page=results&report_id=<?= $report['id'] ?>&status=Not+working" class="mini-stat broken" title="Not working"><?= $reportStats['not_working'] ?></a>
                        </div>
                        <div class="time"><?= formatDate($report['submitted_at']) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div style="text-align: center; margin-top: 15px;">
                <a href="?page=reports" class="btn btn-sm btn-secondary">View All Reports</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Legend - Clickable -->
<div class="legend">
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
</div>

<style>
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

/* Chart links */
.chart-links {
    display: flex;
    gap: 10px;
    margin-top: 15px;
    justify-content: center;
}
.chart-link {
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    text-decoration: none;
    background: var(--bg-accent);
    color: var(--text-muted);
    transition: all 0.2s;
    border: 1px solid var(--border);
}
.chart-link:hover {
    color: var(--text);
    transform: scale(1.05);
}
.chart-link.working:hover { background: var(--status-working); color: #fff; border-color: var(--status-working); }
.chart-link.semi:hover { background: var(--status-semi); color: var(--bg-dark); border-color: var(--status-semi); }
.chart-link.broken:hover { background: var(--status-broken); color: #fff; border-color: var(--status-broken); }
.chart-link.version:hover { background: var(--primary); color: var(--bg-dark); border-color: var(--primary); }

/* Problematic list */
.problematic-list {
    margin-top: 15px;
}
.problematic-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    margin-bottom: 5px;
    background: var(--bg-accent);
    border-radius: 4px;
    text-decoration: none;
    color: var(--text);
    transition: all 0.2s;
    border: 1px solid var(--border);
}
.problematic-item:hover {
    background: var(--status-broken);
    color: #fff;
    border-color: var(--status-broken);
}
.problematic-item .test-key {
    font-family: monospace;
    font-weight: bold;
    margin-right: 10px;
    color: var(--primary);
}
.problematic-item:hover .test-key {
    color: #fff;
}
.problematic-item .test-name {
    flex: 1;
    font-size: 13px;
    color: var(--text-muted);
}
.problematic-item:hover .test-name {
    color: rgba(255,255,255,0.9);
}
.problematic-item .fail-rate {
    font-size: 12px;
    font-weight: bold;
    color: var(--status-broken);
}
.problematic-item:hover .fail-rate {
    color: #fff;
}

/* Mini stats in recent reports */
.report-mini-stats {
    display: flex;
    gap: 5px;
    margin-right: 15px;
}
.mini-stat {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    text-decoration: none;
    color: #fff;
    transition: transform 0.2s;
}
.mini-stat:hover {
    transform: scale(1.15);
}
.mini-stat.working { background: var(--status-working); }
.mini-stat.semi { background: var(--status-semi); color: var(--bg-dark); }
.mini-stat.broken { background: var(--status-broken); }

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
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<!-- Chart initialization - must be after footer which loads app.js -->
<script>
// Wait for everything to load
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure Chart.js and app.js are fully loaded
    setTimeout(initDashboardCharts, 100);
});

function initDashboardCharts() {
    // Check if Chart.js is available
    if (typeof Chart === 'undefined') {
        console.error('Chart.js not loaded');
        return;
    }

    // Status colors matching OldSteam theme
    const STATUS_COLORS = {
        'Working': '#7ea64b',
        'Semi-working': '#c4b550',
        'Not working': '#c45050',
        'N/A': '#808080'
    };

    const THEME_COLORS = {
        text: '#a0aa95',
        textLight: '#eff6ee',
        grid: 'rgba(160, 170, 149, 0.1)',
        background: '#4c5844',
        primary: '#c4b550',
        border: '#282e22'
    };

    // Configure Chart.js defaults
    Chart.defaults.color = THEME_COLORS.text;
    Chart.defaults.borderColor = THEME_COLORS.grid;

    // Status distribution data
    const statusData = {
        'Working': <?= $stats['working'] ?>,
        'Semi-working': <?= $stats['semi_working'] ?>,
        'Not working': <?= $stats['not_working'] ?>
    };

    // Create status pie chart
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(statusData),
                datasets: [{
                    data: Object.values(statusData),
                    backgroundColor: Object.keys(statusData).map(k => STATUS_COLORS[k]),
                    borderWidth: 2,
                    borderColor: THEME_COLORS.border
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            color: THEME_COLORS.text
                        }
                    }
                },
                cutout: '60%',
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const status = Object.keys(statusData)[index];
                        window.location.href = '?page=results&status=' + encodeURIComponent(status);
                    }
                }
            }
        });
        console.log('Status chart created');
    }

    // Version trend data
    <?php
    $versions = [];
    $workingData = [];
    $semiData = [];
    $brokenData = [];

    foreach ($versionTrend as $v) {
        $versions[] = $v['client_version'];
        $workingData[] = $v['working'];
        $semiData[] = $v['semi_working'];
        $brokenData[] = $v['not_working'];
    }
    ?>

    const versionLabelsRaw = <?= json_encode($versions) ?>;
    // Strip 'secondblob.bin.' prefix from version labels for display
    const versionLabels = versionLabelsRaw.map(label => label.replace('secondblob.bin.', ''));
    const versionDatasets = [
        { label: 'Working', data: <?= json_encode($workingData) ?> },
        { label: 'Semi-working', data: <?= json_encode($semiData) ?> },
        { label: 'Not working', data: <?= json_encode($brokenData) ?> }
    ];

    // Create version chart
    const versionCtx = document.getElementById('versionChart');
    if (versionCtx && versionLabels.length > 0) {
        new Chart(versionCtx, {
            type: 'line',
            data: {
                labels: versionLabels,
                datasets: versionDatasets.map((ds) => ({
                    label: ds.label,
                    data: ds.data,
                    borderColor: STATUS_COLORS[ds.label],
                    backgroundColor: 'transparent',
                    tension: 0.4,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: STATUS_COLORS[ds.label],
                    pointBorderColor: THEME_COLORS.border,
                    pointBorderWidth: 2
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: THEME_COLORS.text
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: THEME_COLORS.grid
                        },
                        ticks: {
                            color: THEME_COLORS.text
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: THEME_COLORS.text,
                            maxRotation: 90,
                            minRotation: 90,
                            font: {
                                size: 10
                            }
                        }
                    }
                },
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        // Use raw labels for URL navigation
                        const version = versionLabelsRaw[index];
                        window.location.href = '?page=results&version=' + encodeURIComponent(version);
                    }
                }
            }
        });
        console.log('Version chart created');
    }

    // Problematic tests data
    <?php
    $problematicData = [];
    foreach ($problematicTests as $test) {
        $problematicData[] = [
            'key' => $test['test_key'],
            'name' => getTestName($test['test_key']),
            'failRate' => round($test['fail_rate'], 1)
        ];
    }
    ?>

    const problematicTestsData = <?= json_encode($problematicData) ?>;

    // Create problematic tests chart
    const problematicCtx = document.getElementById('problematicChart');
    if (problematicCtx && problematicTestsData.length > 0) {
        new Chart(problematicCtx, {
            type: 'bar',
            data: {
                labels: problematicTestsData.map(t => t.key),
                datasets: [{
                    label: 'Failure Rate %',
                    data: problematicTestsData.map(t => t.failRate),
                    backgroundColor: STATUS_COLORS['Not working'],
                    borderRadius: 4,
                    borderWidth: 1,
                    borderColor: THEME_COLORS.border
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: THEME_COLORS.background,
                        titleColor: THEME_COLORS.primary,
                        bodyColor: THEME_COLORS.textLight,
                        borderColor: THEME_COLORS.text,
                        borderWidth: 1,
                        callbacks: {
                            title: function(items) {
                                const idx = items[0].dataIndex;
                                return problematicTestsData[idx].name;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        grid: {
                            color: THEME_COLORS.grid
                        },
                        ticks: {
                            color: THEME_COLORS.text
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: THEME_COLORS.text
                        }
                    }
                },
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const testKey = problematicTestsData[index].key;
                        window.location.href = '?page=results&test_key=' + encodeURIComponent(testKey) + '&status=Not+working';
                    }
                }
            }
        });
        console.log('Problematic tests chart created');
    }
}
</script>
