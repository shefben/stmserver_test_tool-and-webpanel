<?php
/**
 * Tests breakdown page - All clickable
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

// Get test statistics
$testStats = $db->getTestStats();

// Organize stats by test key
$statsByKey = [];
foreach ($testStats as $stat) {
    $statsByKey[$stat['test_key']] = $stat;
}

// Get categories
$categories = getTestCategories();

// Calculate category summaries
$categorySummaries = [];
foreach ($categories as $categoryName => $tests) {
    $categoryWorking = 0;
    $categorySemi = 0;
    $categoryBroken = 0;
    $categoryTotal = 0;

    foreach ($tests as $testKey => $testInfo) {
        if (isset($statsByKey[$testKey])) {
            $stat = $statsByKey[$testKey];
            $categoryWorking += $stat['working'];
            $categorySemi += $stat['semi_working'];
            $categoryBroken += $stat['not_working'];
            $categoryTotal += $stat['total'];
        }
    }

    $categorySummaries[$categoryName] = [
        'working' => $categoryWorking,
        'semi' => $categorySemi,
        'broken' => $categoryBroken,
        'total' => $categoryTotal,
        'test_keys' => array_keys($tests)
    ];
}
?>

<h1 class="page-title">Test Breakdown</h1>

<!-- Category Summary Chart -->
<div class="charts-grid" style="margin-bottom: 30px;">
    <div class="chart-card">
        <h3 class="card-title">Results by Category</h3>
        <div class="chart-container">
            <canvas id="categoryChart"></canvas>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title">Category Overview</h3>
        <div class="table-container">
            <table class="sortable">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Tests</th>
                        <th>Working</th>
                        <th>Semi</th>
                        <th>Broken</th>
                        <th>Pass Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categorySummaries as $catName => $summary): ?>
                        <?php
                        $passRate = $summary['total'] > 0
                            ? round((($summary['working'] + $summary['semi'] * 0.5) / $summary['total']) * 100)
                            : 0;
                        $testKeysParam = implode(',', $summary['test_keys']);
                        ?>
                        <tr class="clickable-row" onclick="window.location='?page=results&category=<?= urlencode($catName) ?>'">
                            <td style="font-weight: 600;">
                                <a href="?page=results&category=<?= urlencode($catName) ?>" class="category-link">
                                    <?= e($catName) ?>
                                </a>
                            </td>
                            <td><?= count($categories[$catName]) ?></td>
                            <td>
                                <a href="?page=results&category=<?= urlencode($catName) ?>&status=Working" class="stat-link working">
                                    <?= $summary['working'] ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&category=<?= urlencode($catName) ?>&status=Semi-working" class="stat-link semi">
                                    <?= $summary['semi'] ?>
                                </a>
                            </td>
                            <td>
                                <a href="?page=results&category=<?= urlencode($catName) ?>&status=Not+working" class="stat-link broken">
                                    <?= $summary['broken'] ?>
                                </a>
                            </td>
                            <td>
                                <div class="progress-bar" style="width: 80px; display: inline-block; vertical-align: middle;">
                                    <div class="fill" style="width: <?= $passRate ?>%; background: <?= $passRate >= 70 ? 'var(--status-working)' : ($passRate >= 40 ? 'var(--status-semi)' : 'var(--status-broken)') ?>;"></div>
                                </div>
                                <span style="margin-left: 10px;"><?= $passRate ?>%</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Individual Tests by Category -->
<?php foreach ($categories as $categoryName => $tests): ?>
    <div class="card" style="margin-bottom: 20px;">
        <h3 class="card-title">
            <a href="?page=results&category=<?= urlencode($categoryName) ?>" class="category-header-link">
                <?= e($categoryName) ?>
                <span class="category-count"><?= count($tests) ?> tests</span>
            </a>
        </h3>
        <div class="table-container">
            <table class="sortable test-breakdown-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">Test Key</th>
                        <th style="width: 25%;">Name</th>
                        <th>Expected</th>
                        <th style="width: 70px; text-align: center;">Working</th>
                        <th style="width: 70px; text-align: center;">Semi</th>
                        <th style="width: 70px; text-align: center;">Broken</th>
                        <th style="width: 70px; text-align: center;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tests as $testKey => $testInfo): ?>
                        <?php
                        $stat = $statsByKey[$testKey] ?? null;
                        $working = $stat ? $stat['working'] : 0;
                        $semi = $stat ? $stat['semi_working'] : 0;
                        $broken = $stat ? $stat['not_working'] : 0;
                        $total = $working + $semi + $broken;
                        ?>
                        <tr class="clickable-row" onclick="window.location='?page=results&test_key=<?= urlencode($testKey) ?>'">
                            <td>
                                <span class="test-key-badge"><?= e($testKey) ?></span>
                            </td>
                            <td style="font-weight: 600;">
                                <a href="?page=results&test_key=<?= urlencode($testKey) ?>" style="color: inherit; text-decoration: none;">
                                    <?= e($testInfo['name']) ?>
                                </a>
                            </td>
                            <td style="color: #899281; font-size: 11px;"><?= e($testInfo['expected']) ?></td>
                            <td>
                                <?php if ($total > 0): ?>
                                    <a href="?page=results&test_key=<?= urlencode($testKey) ?>&status=Working" class="stat-link working" onclick="event.stopPropagation();">
                                        <?= $working ?>
                                    </a>
                                <?php else: ?>
                                    <span class="no-data">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($total > 0): ?>
                                    <a href="?page=results&test_key=<?= urlencode($testKey) ?>&status=Semi-working" class="stat-link semi" onclick="event.stopPropagation();">
                                        <?= $semi ?>
                                    </a>
                                <?php else: ?>
                                    <span class="no-data">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($total > 0): ?>
                                    <a href="?page=results&test_key=<?= urlencode($testKey) ?>&status=Not+working" class="stat-link broken" onclick="event.stopPropagation();">
                                        <?= $broken ?>
                                    </a>
                                <?php else: ?>
                                    <span class="no-data">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($total > 0): ?>
                                    <?= $total ?>
                                <?php else: ?>
                                    <span class="no-data">No data</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<style>
/* Category header link */
.category-header-link {
    color: #c4b550;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 15px;
}
.category-header-link:hover {
    color: #E3E41F;
    text-decoration: none;
}
.category-count {
    font-size: 11px;
    font-weight: normal;
    color: #899281;
    background: #3e4637;
    padding: 3px 10px;
    border: 1px solid #292d23;
}

/* Test key badge in table */
.test-key-badge {
    font-family: 'Consolas', 'Courier New', monospace;
    font-weight: bold;
    color: #c4b550;
    background: #3e4637;
    padding: 2px 8px;
    font-size: 11px;
    border: 1px solid #292d23;
}

.no-data {
    color: #75806f;
    font-size: 11px;
    font-style: italic;
}

/* Fixed column widths for test breakdown tables (7 columns) - not the Category Overview table (6 columns) */
.card > .table-container > table.sortable.test-breakdown-table {
    table-layout: fixed;
    width: 100%;
}

/* Test Key column */
.card > .table-container > table.sortable.test-breakdown-table th:nth-child(1),
.card > .table-container > table.sortable.test-breakdown-table td:nth-child(1) {
    width: 80px;
    min-width: 80px;
}

/* Name column */
.card > .table-container > table.sortable.test-breakdown-table th:nth-child(2),
.card > .table-container > table.sortable.test-breakdown-table td:nth-child(2) {
    width: 25%;
    min-width: 150px;
}

/* Expected column - wrap text if needed */
.card > .table-container > table.sortable.test-breakdown-table th:nth-child(3),
.card > .table-container > table.sortable.test-breakdown-table td:nth-child(3) {
    width: auto;
    word-wrap: break-word;
    white-space: normal;
    line-height: 1.3;
}

/* Working/Semi/Broken/Total columns */
.card > .table-container > table.sortable.test-breakdown-table th:nth-child(4),
.card > .table-container > table.sortable.test-breakdown-table td:nth-child(4),
.card > .table-container > table.sortable.test-breakdown-table th:nth-child(5),
.card > .table-container > table.sortable.test-breakdown-table td:nth-child(5),
.card > .table-container > table.sortable.test-breakdown-table th:nth-child(6),
.card > .table-container > table.sortable.test-breakdown-table td:nth-child(6),
.card > .table-container > table.sortable.test-breakdown-table th:nth-child(7),
.card > .table-container > table.sortable.test-breakdown-table td:nth-child(7) {
    width: 70px;
    min-width: 70px;
    text-align: center;
}
</style>

<script>
// Category chart data
<?php
$chartData = [];
foreach ($categorySummaries as $catName => $summary) {
    // Shorten category names for chart
    $shortName = $catName;
    if (strlen($shortName) > 15) {
        $parts = explode(' ', $shortName);
        $shortName = implode(' ', array_slice($parts, 0, 2));
    }
    $chartData[] = [
        'key' => $shortName,
        'fullName' => $catName,
        'working' => $summary['working'],
        'semi' => $summary['semi'],
        'broken' => $summary['broken']
    ];
}
?>

const categoryData = <?= json_encode($chartData) ?>;

// Create category chart with click handlers
const categoryCtx = document.getElementById('categoryChart');
if (categoryCtx && categoryData.length > 0) {
    new Chart(categoryCtx, {
        type: 'bar',
        data: {
            labels: categoryData.map(d => d.key),
            datasets: [
                {
                    label: 'Working',
                    data: categoryData.map(d => d.working),
                    backgroundColor: '#27ae60'
                },
                {
                    label: 'Semi-working',
                    data: categoryData.map(d => d.semi),
                    backgroundColor: '#f39c12'
                },
                {
                    label: 'Not working',
                    data: categoryData.map(d => d.broken),
                    backgroundColor: '#e74c3c'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#292d23',
                        font: {
                            weight: 'bold'
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        title: function(items) {
                            const idx = items[0].dataIndex;
                            return categoryData[idx].fullName;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#292d23',
                        font: {
                            weight: 'bold'
                        }
                    }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255,255,255,0.05)'
                    },
                    ticks: {
                        color: '#292d23',
                        font: {
                            weight: 'bold'
                        }
                    }
                }
            },
            onClick: function(event, elements) {
                if (elements.length > 0) {
                    const index = elements[0].index;
                    const category = categoryData[index].fullName;
                    window.location.href = '?page=results&category=' + encodeURIComponent(category);
                }
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
