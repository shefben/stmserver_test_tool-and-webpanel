<?php
/**
 * Admin Panel - Retest & Fixed Test Management
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/test_keys.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();
$testKeys = getTestKeys();
$versions = $db->getUniqueValues('reports', 'client_version');
$currentUser = getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add retest request
    if ($action === 'add_retest') {
        $testKey = $_POST['test_key'] ?? '';
        $clientVersion = $_POST['client_version'] ?? '';
        $reason = $_POST['reason'] ?? '';

        if ($testKey && $clientVersion) {
            $id = $db->addRetestRequest($testKey, $clientVersion, $currentUser['username'], $reason);
            if ($id) {
                setFlash('success', 'Retest request added successfully.');
            } else {
                setFlash('error', 'Failed to add retest request.');
            }
        } else {
            setFlash('error', 'Test key and client version are required.');
        }
        header('Location: ?page=admin_retests');
        exit;
    }

    // Add fixed test
    if ($action === 'add_fixed') {
        $testKey = $_POST['test_key'] ?? '';
        $clientVersion = $_POST['client_version'] ?? '';
        $commitHash = $_POST['commit_hash'] ?? '';
        $notes = $_POST['notes'] ?? '';

        if ($testKey && $clientVersion) {
            $id = $db->addFixedTest($testKey, $clientVersion, $currentUser['username'], $commitHash, $notes);
            if ($id) {
                setFlash('success', 'Test marked as fixed. Clients will be notified to retest with latest revision.');
            } else {
                setFlash('error', 'Failed to mark test as fixed.');
            }
        } else {
            setFlash('error', 'Test key and client version are required.');
        }
        header('Location: ?page=admin_retests');
        exit;
    }

    // Complete retest request
    if ($action === 'complete_retest') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && $db->completeRetestRequest($id)) {
            setFlash('success', 'Retest request marked as completed.');
        } else {
            setFlash('error', 'Failed to complete retest request.');
        }
        header('Location: ?page=admin_retests');
        exit;
    }

    // Verify fixed test
    if ($action === 'verify_fixed') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && $db->verifyFixedTest($id)) {
            setFlash('success', 'Fixed test verified.');
        } else {
            setFlash('error', 'Failed to verify fixed test.');
        }
        header('Location: ?page=admin_retests');
        exit;
    }

    // Delete retest request
    if ($action === 'delete_retest') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && $db->deleteRetestRequest($id)) {
            setFlash('success', 'Retest request deleted.');
        } else {
            setFlash('error', 'Failed to delete retest request.');
        }
        header('Location: ?page=admin_retests');
        exit;
    }

    // Delete fixed test
    if ($action === 'delete_fixed') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && $db->deleteFixedTest($id)) {
            setFlash('success', 'Fixed test entry deleted.');
        } else {
            setFlash('error', 'Failed to delete fixed test entry.');
        }
        header('Location: ?page=admin_retests');
        exit;
    }
}

// Get current data
$pendingRetests = $db->getRetestRequests('pending');
$completedRetests = $db->getRetestRequests('completed');
$pendingFixed = $db->getFixedTests('pending_retest');
$verifiedFixed = $db->getFixedTests('verified');
?>

<div class="admin-header">
    <h1 class="page-title">Retest & Fixed Test Management</h1>
    <p style="color: var(--text-muted); margin-top: 5px;">
        Mark tests for retesting or mark failed tests as fixed. Changes are reported to clients via API.
    </p>
</div>

<!-- Stats Cards -->
<div class="stats-grid four-col">
    <div class="stat-card" style="border-left: 4px solid var(--primary);">
        <div class="value"><?= count($pendingRetests) ?></div>
        <div class="label">Pending Retests</div>
    </div>
    <div class="stat-card" style="border-left: 4px solid var(--full-green);">
        <div class="value"><?= count($completedRetests) ?></div>
        <div class="label">Completed Retests</div>
    </div>
    <div class="stat-card" style="border-left: 4px solid var(--status-semi);">
        <div class="value"><?= count($pendingFixed) ?></div>
        <div class="label">Pending Verification</div>
    </div>
    <div class="stat-card working">
        <div class="value"><?= count($verifiedFixed) ?></div>
        <div class="label">Verified Fixes</div>
    </div>
</div>

<!-- Add Forms -->
<div class="charts-grid">
    <!-- Add Retest Request -->
    <div class="card">
        <h3 class="card-title">Request Retest</h3>
        <p style="color: var(--text-muted); margin-bottom: 20px; font-size: 13px;">
            Request a specific test to be re-run by testers. Will appear in their retest queue.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="add_retest">

            <div class="form-group">
                <label for="retest_test_key">Test</label>
                <select name="test_key" id="retest_test_key" required>
                    <option value="">Select a test...</option>
                    <?php foreach ($testKeys as $key => $name): ?>
                        <option value="<?= e($key) ?>"><?= e($key) ?>: <?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="retest_version">Client Version (Blob)</label>
                <select name="client_version" id="retest_version" required>
                    <option value="">Select a version...</option>
                    <?php foreach ($versions as $version): ?>
                        <option value="<?= e($version) ?>"><?= e(shortVersionName($version)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="retest_reason">Reason (optional)</label>
                <input type="text" name="reason" id="retest_reason" placeholder="e.g., Needs verification after config change">
            </div>

            <button type="submit" class="btn">Add Retest Request</button>
        </form>
    </div>

    <!-- Add Fixed Test -->
    <div class="card">
        <h3 class="card-title">Mark Test as Fixed</h3>
        <p style="color: var(--text-muted); margin-bottom: 20px; font-size: 13px;">
            Mark a previously failing test as fixed. Clients will be prompted to retest with latest revision.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="add_fixed">

            <div class="form-group">
                <label for="fixed_test_key">Test</label>
                <select name="test_key" id="fixed_test_key" required>
                    <option value="">Select a test...</option>
                    <?php foreach ($testKeys as $key => $name): ?>
                        <option value="<?= e($key) ?>"><?= e($key) ?>: <?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="fixed_version">Client Version (Blob)</label>
                <select name="client_version" id="fixed_version" required>
                    <option value="">Select a version...</option>
                    <?php foreach ($versions as $version): ?>
                        <option value="<?= e($version) ?>"><?= e(shortVersionName($version)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="fixed_commit">Fix Commit Hash (optional)</label>
                <input type="text" name="commit_hash" id="fixed_commit" placeholder="e.g., abc123">
            </div>

            <div class="form-group">
                <label for="fixed_notes">Notes (optional)</label>
                <input type="text" name="notes" id="fixed_notes" placeholder="e.g., Fixed null pointer in auth handler">
            </div>

            <button type="submit" class="btn" style="background: linear-gradient(180deg, var(--full-green) 0%, #5a8a35 100%); border-color: var(--full-green);">
                Mark as Fixed
            </button>
        </form>
    </div>
</div>

<!-- Pending Retest Requests -->
<div class="card">
    <h3 class="card-title">Pending Retest Requests</h3>
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Test</th>
                    <th>Version</th>
                    <th>Reason</th>
                    <th>Created By</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingRetests)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No pending retest requests.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendingRetests as $retest): ?>
                        <tr>
                            <td>#<?= $retest['id'] ?></td>
                            <td>
                                <strong><?= e($retest['test_key']) ?></strong>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    <?= e($testKeys[$retest['test_key']] ?? 'Unknown') ?>
                                </div>
                            </td>
                            <td style="font-size: 12px;"><?= e(shortVersionName($retest['client_version'])) ?></td>
                            <td style="color: var(--text-muted); font-size: 13px;"><?= e($retest['reason'] ?: '-') ?></td>
                            <td><?= e($retest['created_by']) ?></td>
                            <td style="color: var(--text-muted); font-size: 12px;"><?= formatRelativeTime($retest['created_at']) ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="complete_retest">
                                    <input type="hidden" name="id" value="<?= $retest['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background: var(--full-green); border-color: var(--full-green);">Complete</button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this retest request?');">
                                    <input type="hidden" name="action" value="delete_retest">
                                    <input type="hidden" name="id" value="<?= $retest['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pending Fixed Tests (Awaiting Verification) -->
<div class="card">
    <h3 class="card-title">Fixed Tests Awaiting Verification</h3>
    <div class="table-container">
        <table class="sortable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Test</th>
                    <th>Version</th>
                    <th>Commit</th>
                    <th>Notes</th>
                    <th>Fixed By</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingFixed)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No fixed tests awaiting verification.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendingFixed as $fixed): ?>
                        <tr>
                            <td>#<?= $fixed['id'] ?></td>
                            <td>
                                <strong><?= e($fixed['test_key']) ?></strong>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    <?= e($testKeys[$fixed['test_key']] ?? 'Unknown') ?>
                                </div>
                            </td>
                            <td style="font-size: 12px;"><?= e(shortVersionName($fixed['client_version'])) ?></td>
                            <td><code style="background: var(--bg-dark); padding: 2px 6px; border-radius: 3px;"><?= e($fixed['commit_hash'] ?: '-') ?></code></td>
                            <td style="color: var(--text-muted); font-size: 13px;"><?= e($fixed['notes'] ?: '-') ?></td>
                            <td><?= e($fixed['fixed_by']) ?></td>
                            <td style="color: var(--text-muted); font-size: 12px;"><?= formatRelativeTime($fixed['created_at']) ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="verify_fixed">
                                    <input type="hidden" name="id" value="<?= $fixed['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background: var(--full-green); border-color: var(--full-green);">Verify</button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this fixed test entry?');">
                                    <input type="hidden" name="action" value="delete_fixed">
                                    <input type="hidden" name="id" value="<?= $fixed['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- API Usage Info -->
<div class="card">
    <h3 class="card-title">API Usage</h3>
    <p style="color: var(--text-muted); margin-bottom: 15px;">
        Clients can query the retest queue using the API endpoint:
    </p>
    <code style="background: var(--bg-dark); padding: 10px 15px; border-radius: 4px; display: block; margin-bottom: 15px;">
        GET <?= e(getBaseUrl()) ?>/api/retests.php
    </code>
    <p style="color: var(--text-muted); margin-bottom: 10px; font-size: 13px;">
        Headers: <code>X-API-Key: YOUR_API_KEY</code>
    </p>
    <p style="color: var(--text-muted); font-size: 13px;">
        Optional query param: <code>?client_version=VERSION</code> to filter by specific blob version.
    </p>

    <h4 style="margin-top: 20px; margin-bottom: 10px; font-size: 14px;">Response Example:</h4>
    <pre style="background: var(--bg-dark); padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; color: var(--text-muted);">{
  "success": true,
  "count": 2,
  "retest_queue": [
    {
      "type": "retest",
      "id": 1,
      "test_key": "3",
      "test_name": "Log into existing account",
      "client_version": "secondblob.bin.2004-01-15",
      "reason": "Needs verification",
      "latest_revision": false
    },
    {
      "type": "fixed",
      "id": 2,
      "test_key": "5",
      "test_name": "Change password",
      "client_version": "secondblob.bin.2004-02-01",
      "reason": "Test marked as fixed - please verify",
      "commit_hash": "abc123",
      "latest_revision": true
    }
  ]
}</pre>
</div>

<style>
.four-col {
    grid-template-columns: repeat(4, 1fr);
}

@media (max-width: 900px) {
    .four-col {
        grid-template-columns: repeat(2, 1fr);
    }
}

.btn-danger {
    background: linear-gradient(180deg, var(--status-broken) 0%, #a04040 100%);
    border-color: var(--status-broken);
}

.btn-danger:hover {
    background: linear-gradient(180deg, #d45050 0%, var(--status-broken) 100%);
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
