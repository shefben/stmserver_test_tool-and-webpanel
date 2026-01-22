<?php
/**
 * Cross-Version Comparison Matrix
 * Compare test results between two versions side-by-side with regression/progression detection
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

// Get all versions for selection
$versions = $db->getUniqueValues('reports', 'client_version');

// Sort versions (newest first based on date in name)
usort($versions, function($a, $b) {
    preg_match('/(\d{4})[-_]?(\d{2})[-_]?(\d{2})?/', $a, $matchA);
    preg_match('/(\d{4})[-_]?(\d{2})[-_]?(\d{2})?/', $b, $matchB);

    if ($matchA && $matchB) {
        $dateA = $matchA[1] . ($matchA[2] ?? '01') . ($matchA[3] ?? '01');
        $dateB = $matchB[1] . ($matchB[2] ?? '01') . ($matchB[3] ?? '01');
        return strcmp($dateB, $dateA);
    }
    return strcmp($b, $a);
});

// Get selected versions from URL
$version1 = $_GET['v1'] ?? '';
$version2 = $_GET['v2'] ?? '';
$showOnlyChanges = isset($_GET['changes_only']) && $_GET['changes_only'] === '1';

// Get comparison data if both versions selected
$comparisonData = [];
$regressions = [];
$progressions = [];
$testKeys = getSortedTestKeys();
$categories = getTestCategories();

if ($version1 && $version2) {
    // Get aggregated status for each version
    $matrix = $db->getVersionMatrix();

    $v1Data = [];
    $v2Data = [];

    foreach ($matrix as $row) {
        if ($row['client_version'] === $version1) {
            $v1Data[$row['test_key']] = $row['most_common_status'];
        } elseif ($row['client_version'] === $version2) {
            $v2Data[$row['test_key']] = $row['most_common_status'];
        }
    }

    // Status priority for comparison (higher is better)
    $statusPriority = [
        'Working' => 3,
        'Semi-working' => 2,
        'Not working' => 1,
        'N/A' => 0,
        '' => 0
    ];

    // Build comparison data
    foreach ($testKeys as $testKey) {
        $status1 = $v1Data[$testKey] ?? '';
        $status2 = $v2Data[$testKey] ?? '';

        $priority1 = $statusPriority[$status1] ?? 0;
        $priority2 = $statusPriority[$status2] ?? 0;

        $change = 'unchanged';
        if ($status1 !== $status2 && $status1 && $status2) {
            if ($priority2 > $priority1) {
                $change = 'progression';
                $progressions[] = $testKey;
            } elseif ($priority2 < $priority1) {
                $change = 'regression';
                $regressions[] = $testKey;
            }
        } elseif ($status1 !== $status2) {
            $change = 'new_data';
        }

        if (!$showOnlyChanges || $change !== 'unchanged') {
            $comparisonData[$testKey] = [
                'status1' => $status1,
                'status2' => $status2,
                'change' => $change
            ];
        }
    }
}
?>

<h1 class="page-title">Cross-Version Comparison</h1>

<!-- Version Selection Form -->
<div class="card">
    <form method="GET" class="comparison-form">
        <input type="hidden" name="page" value="compare_versions">

        <div class="form-row">
            <div class="form-group">
                <label for="v1">Base Version (Older)</label>
                <select name="v1" id="v1" required>
                    <option value="">Select version...</option>
                    <?php foreach ($versions as $v): ?>
                        <option value="<?= e($v) ?>" <?= $v === $version1 ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="align-self: flex-end; padding-bottom: 5px;">
                <span class="comparison-arrow">→</span>
            </div>

            <div class="form-group">
                <label for="v2">Target Version (Newer)</label>
                <select name="v2" id="v2" required>
                    <option value="">Select version...</option>
                    <?php foreach ($versions as $v): ?>
                        <option value="<?= e($v) ?>" <?= $v === $version2 ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group checkbox-group">
                <label>
                    <input type="checkbox" name="changes_only" value="1" <?= $showOnlyChanges ? 'checked' : '' ?>>
                    Show only changes
                </label>
            </div>

            <div class="form-group" style="align-self: flex-end;">
                <button type="submit" class="btn">Compare</button>
                <button type="button" class="btn btn-secondary" onclick="swapVersions()">⇆ Swap</button>
            </div>
        </div>
    </form>
</div>

<?php if ($version1 && $version2): ?>
    <!-- Summary Stats -->
    <div class="stats-grid" style="margin-bottom: 20px;">
        <div class="stat-card">
            <div class="value" style="color: var(--text-muted);"><?= count($testKeys) ?></div>
            <div class="label">Total Tests</div>
        </div>
        <div class="stat-card working">
            <div class="value"><?= count($progressions) ?></div>
            <div class="label">Progressions</div>
            <div class="stat-hint">Tests that improved</div>
        </div>
        <div class="stat-card broken">
            <div class="value"><?= count($regressions) ?></div>
            <div class="label">Regressions</div>
            <div class="stat-hint">Tests that got worse</div>
        </div>
        <div class="stat-card">
            <div class="value" style="color: var(--status-semi);"><?= count($testKeys) - count($progressions) - count($regressions) ?></div>
            <div class="label">Unchanged</div>
        </div>
    </div>

    <!-- Regression/Progression Alerts -->
    <?php if (!empty($regressions)): ?>
        <div class="alert alert-regression">
            <strong>⚠️ Regressions Detected (<?= count($regressions) ?>):</strong>
            <span class="alert-tests">
                <?php foreach ($regressions as $key): ?>
                    <a href="?page=results&test_key=<?= urlencode($key) ?>" class="test-key-badge"><?= e($key) ?></a>
                <?php endforeach; ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if (!empty($progressions)): ?>
        <div class="alert alert-progression">
            <strong>✅ Progressions (<?= count($progressions) ?>):</strong>
            <span class="alert-tests">
                <?php foreach ($progressions as $key): ?>
                    <a href="?page=results&test_key=<?= urlencode($key) ?>" class="test-key-badge"><?= e($key) ?></a>
                <?php endforeach; ?>
            </span>
        </div>
    <?php endif; ?>

    <!-- Comparison Table -->
    <div class="card">
        <h3 class="card-title">Comparison Results</h3>
        <div class="table-container">
            <table class="sortable comparison-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">Key</th>
                        <th>Test Name</th>
                        <th style="width: 140px;"><?= e($version1) ?></th>
                        <th style="width: 60px;">Change</th>
                        <th style="width: 140px;"><?= e($version2) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $currentCategory = '';
                    foreach ($categories as $categoryName => $tests):
                        $categoryHasChanges = false;
                        foreach ($tests as $testKey => $testInfo):
                            if (isset($comparisonData[$testKey])):
                                $categoryHasChanges = true;
                            endif;
                        endforeach;

                        if (!$categoryHasChanges && $showOnlyChanges) continue;
                    ?>
                        <tr class="category-row">
                            <td colspan="5"><?= e($categoryName) ?></td>
                        </tr>
                        <?php foreach ($tests as $testKey => $testInfo):
                            if (!isset($comparisonData[$testKey]) && $showOnlyChanges) continue;

                            $data = $comparisonData[$testKey] ?? ['status1' => '', 'status2' => '', 'change' => 'no_data'];
                            $rowClass = '';
                            if ($data['change'] === 'regression') $rowClass = 'row-regression';
                            elseif ($data['change'] === 'progression') $rowClass = 'row-progression';
                        ?>
                            <tr class="<?= $rowClass ?>">
                                <td>
                                    <a href="?page=results&test_key=<?= urlencode($testKey) ?>" class="test-key-link">
                                        <?= e($testKey) ?>
                                    </a>
                                </td>
                                <td>
                                    <div style="font-weight: 500;"><?= e($testInfo['name']) ?></div>
                                </td>
                                <td><?= $data['status1'] ? getStatusBadge($data['status1']) : '<span class="no-data">No data</span>' ?></td>
                                <td class="change-cell">
                                    <?php if ($data['change'] === 'regression'): ?>
                                        <span class="change-indicator regression" title="Regression">↓</span>
                                    <?php elseif ($data['change'] === 'progression'): ?>
                                        <span class="change-indicator progression" title="Progression">↑</span>
                                    <?php elseif ($data['change'] === 'new_data'): ?>
                                        <span class="change-indicator new" title="New data">●</span>
                                    <?php else: ?>
                                        <span class="change-indicator unchanged" title="Unchanged">=</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $data['status2'] ? getStatusBadge($data['status2']) : '<span class="no-data">No data</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif (!empty($versions)): ?>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            Select two versions above to compare their test results side-by-side.
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 40px;">
            No report data available yet. <a href="?page=submit">Submit a report</a> or <a href="?page=create_report">Create a new report</a> to enable comparisons.
        </p>
    </div>
<?php endif; ?>

<style>
/* Comparison Form Styles */
.comparison-form {
    margin: 0;
}

.comparison-form .form-row {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    flex-wrap: wrap;
}

.comparison-form .form-group {
    flex: 1;
    min-width: 180px;
}

.comparison-form .checkbox-group {
    flex: 0 0 auto;
    align-self: flex-end;
    padding-bottom: 5px;
}

.comparison-form .checkbox-group label {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-size: 13px;
}

.comparison-arrow {
    font-size: 24px;
    color: var(--primary);
    font-weight: bold;
}

/* Alert Styles */
.alert {
    padding: 15px 20px;
    border-radius: 6px;
    margin-bottom: 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.alert-regression {
    background: rgba(196, 80, 80, 0.15);
    border: 1px solid var(--status-broken);
    color: var(--status-broken);
}

.alert-progression {
    background: rgba(126, 166, 75, 0.15);
    border: 1px solid var(--status-working);
    color: var(--status-working);
}

.alert-tests {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-left: 10px;
}

.test-key-badge {
    display: inline-block;
    padding: 2px 8px;
    background: var(--bg-dark);
    border-radius: 4px;
    font-family: monospace;
    font-size: 12px;
    text-decoration: none;
    color: var(--primary);
    transition: all 0.2s;
}

.test-key-badge:hover {
    background: var(--primary);
    color: #fff;
}

/* Comparison Table Styles */
.comparison-table .category-row {
    background: var(--bg-accent);
}

.comparison-table .category-row td {
    font-weight: bold;
    color: var(--primary);
    font-size: 13px;
    padding: 8px 14px;
    border-bottom: 2px solid var(--border);
}

.row-regression {
    background: rgba(196, 80, 80, 0.1);
}

.row-progression {
    background: rgba(126, 166, 75, 0.1);
}

.change-cell {
    text-align: center;
}

.change-indicator {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-weight: bold;
    font-size: 16px;
}

.change-indicator.regression {
    background: var(--status-broken);
    color: #fff;
}

.change-indicator.progression {
    background: var(--status-working);
    color: #fff;
}

.change-indicator.new {
    background: var(--status-semi);
    color: #fff;
    font-size: 12px;
}

.change-indicator.unchanged {
    background: var(--bg-dark);
    color: var(--text-muted);
}

.no-data {
    color: var(--text-muted);
    font-style: italic;
    font-size: 12px;
}

/* Stat hint */
.stat-hint {
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 4px;
}
</style>

<script>
function swapVersions() {
    var v1 = document.getElementById('v1');
    var v2 = document.getElementById('v2');
    var temp = v1.value;
    v1.value = v2.value;
    v2.value = temp;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
