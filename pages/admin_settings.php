<?php
/**
 * Admin Panel - Site Settings
 */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';

// Require admin access
requireAdmin();

$db = Database::getInstance();
$error = '';
$success = '';

// Ensure settings table exists
if (!$db->hasSettingsTable()) {
    $db->createSettingsTable();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_general':
            $siteTitle = trim($_POST['site_title'] ?? '');
            $sitePrivate = isset($_POST['site_private']) ? 1 : 0;

            if (empty($siteTitle)) {
                $error = 'Site title cannot be empty.';
            } else {
                $db->updateSettings([
                    'site_title' => $siteTitle,
                    'site_private' => $sitePrivate
                ]);
                $success = 'General settings saved successfully.';
            }
            break;

        case 'save_database':
            // Note: Database settings affect config.php which is read-only at runtime
            // This section displays current settings but changes must be made to config.php
            $error = 'Database settings cannot be changed from this interface. Please edit config.php directly.';
            break;

        case 'save_smtp':
            $smtpEnabled = isset($_POST['smtp_enabled']) ? 1 : 0;
            $smtpHost = trim($_POST['smtp_host'] ?? '');
            $smtpPort = (int)($_POST['smtp_port'] ?? 587);
            $smtpUsername = trim($_POST['smtp_username'] ?? '');
            $smtpPassword = $_POST['smtp_password'] ?? '';
            $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';
            $smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
            $smtpFromName = trim($_POST['smtp_from_name'] ?? '');

            // Validate if SMTP is enabled
            if ($smtpEnabled) {
                if (empty($smtpHost)) {
                    $error = 'SMTP host is required when SMTP is enabled.';
                    break;
                }
                if ($smtpPort < 1 || $smtpPort > 65535) {
                    $error = 'SMTP port must be between 1 and 65535.';
                    break;
                }
                if (!empty($smtpFromEmail) && !filter_var($smtpFromEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Invalid from email address.';
                    break;
                }
            }

            // Only update password if a new one was provided
            $settings = [
                'smtp_enabled' => $smtpEnabled,
                'smtp_host' => $smtpHost,
                'smtp_port' => $smtpPort,
                'smtp_username' => $smtpUsername,
                'smtp_encryption' => $smtpEncryption,
                'smtp_from_email' => $smtpFromEmail,
                'smtp_from_name' => $smtpFromName
            ];

            if (!empty($smtpPassword)) {
                $settings['smtp_password'] = $smtpPassword;
            }

            $db->updateSettings($settings);
            $success = 'SMTP settings saved successfully.';
            break;

        case 'test_smtp':
            // Test SMTP connection
            $smtpHost = $db->getSetting('smtp_host', '');
            $smtpEnabled = $db->getSetting('smtp_enabled', false);

            if (!$smtpEnabled || empty($smtpHost)) {
                $error = 'SMTP is not configured. Please save SMTP settings first.';
            } else {
                // Simple connection test
                $smtpPort = $db->getSetting('smtp_port', 587);
                $timeout = 5;

                $errno = 0;
                $errstr = '';
                $connection = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, $timeout);

                if ($connection) {
                    fclose($connection);
                    $success = "SMTP connection test successful! Connected to $smtpHost:$smtpPort";
                } else {
                    $error = "SMTP connection failed: $errstr (Error $errno)";
                }
            }
            break;
    }
}

// Get current settings
$settings = $db->getAllSettings();

// Helper function to get setting value
function getSettingValue($settings, $key, $default = '') {
    return isset($settings[$key]) ? $settings[$key]['value'] : $default;
}
?>

<div class="report-header">
    <div>
        <h1 class="page-title">Site Settings</h1>
        <p style="color: var(--text-muted);">Configure site options, database, and email settings</p>
    </div>
    <a href="?page=admin" class="btn btn-secondary">&larr; Back to Admin</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<!-- General Settings -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">General Settings</h3>
    <form method="POST">
        <input type="hidden" name="action" value="save_general">

        <div class="form-group">
            <label for="site_title">Site Title</label>
            <input type="text" id="site_title" name="site_title"
                   value="<?= e(getSettingValue($settings, 'site_title', PANEL_NAME)) ?>"
                   placeholder="Enter site title" required>
            <p class="form-hint">Displayed in the header and browser tab</p>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="site_private" value="1"
                       <?= getSettingValue($settings, 'site_private', false) ? 'checked' : '' ?>>
                <span class="checkbox-text">Private Site Mode</span>
            </label>
            <p class="form-hint">When enabled, guests will be redirected to the login page. Only logged-in users can access the site.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Save General Settings</button>
        </div>
    </form>
</div>

<!-- Database Settings (Read-Only Display) -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">Database Settings</h3>
    <div class="info-box">
        <p><strong>Note:</strong> Database settings are configured in <code>config.php</code> and cannot be changed from this interface for security reasons.</p>
    </div>

    <div class="settings-display">
        <div class="setting-row">
            <span class="setting-label">Host:</span>
            <span class="setting-value"><code><?= e(DB_HOST) ?></code></span>
        </div>
        <div class="setting-row">
            <span class="setting-label">Port:</span>
            <span class="setting-value"><code><?= e(defined('DB_PORT') ? DB_PORT : '3306') ?></code></span>
        </div>
        <div class="setting-row">
            <span class="setting-label">Database:</span>
            <span class="setting-value"><code><?= e(DB_NAME) ?></code></span>
        </div>
        <div class="setting-row">
            <span class="setting-label">Username:</span>
            <span class="setting-value"><code><?= e(DB_USER) ?></code></span>
        </div>
        <div class="setting-row">
            <span class="setting-label">Password:</span>
            <span class="setting-value"><code>••••••••</code></span>
        </div>
        <div class="setting-row">
            <span class="setting-label">Charset:</span>
            <span class="setting-value"><code><?= e(DB_CHARSET) ?></code></span>
        </div>
    </div>

    <div class="form-actions" style="margin-top: 20px;">
        <a href="#" class="btn btn-secondary" onclick="alert('To change database settings, edit config.php on the server.'); return false;">
            Edit config.php
        </a>
    </div>
</div>

<!-- SMTP Settings -->
<div class="card" style="margin-bottom: 30px;">
    <h3 class="card-title">SMTP Email Settings</h3>
    <form method="POST">
        <input type="hidden" name="action" value="save_smtp">

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="smtp_enabled" value="1" id="smtp_enabled"
                       <?= getSettingValue($settings, 'smtp_enabled', false) ? 'checked' : '' ?>
                       onchange="toggleSmtpFields()">
                <span class="checkbox-text">Enable SMTP</span>
            </label>
            <p class="form-hint">Enable sending emails via SMTP server</p>
        </div>

        <div id="smtp_fields" class="smtp-fields <?= getSettingValue($settings, 'smtp_enabled', false) ? '' : 'disabled' ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="smtp_host">SMTP Host</label>
                    <input type="text" id="smtp_host" name="smtp_host"
                           value="<?= e(getSettingValue($settings, 'smtp_host', '')) ?>"
                           placeholder="smtp.example.com">
                </div>
                <div class="form-group" style="max-width: 150px;">
                    <label for="smtp_port">Port</label>
                    <input type="number" id="smtp_port" name="smtp_port"
                           value="<?= e(getSettingValue($settings, 'smtp_port', 587)) ?>"
                           min="1" max="65535">
                </div>
                <div class="form-group" style="max-width: 150px;">
                    <label for="smtp_encryption">Encryption</label>
                    <select id="smtp_encryption" name="smtp_encryption">
                        <option value="tls" <?= getSettingValue($settings, 'smtp_encryption', 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= getSettingValue($settings, 'smtp_encryption', 'tls') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= getSettingValue($settings, 'smtp_encryption', 'tls') === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="smtp_username">Username</label>
                    <input type="text" id="smtp_username" name="smtp_username"
                           value="<?= e(getSettingValue($settings, 'smtp_username', '')) ?>"
                           placeholder="user@example.com" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="smtp_password">Password</label>
                    <input type="password" id="smtp_password" name="smtp_password"
                           placeholder="<?= getSettingValue($settings, 'smtp_password', '') ? '••••••••' : 'Enter password' ?>"
                           autocomplete="new-password">
                    <p class="form-hint">Leave blank to keep current password</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="smtp_from_email">From Email</label>
                    <input type="email" id="smtp_from_email" name="smtp_from_email"
                           value="<?= e(getSettingValue($settings, 'smtp_from_email', '')) ?>"
                           placeholder="noreply@example.com">
                </div>
                <div class="form-group">
                    <label for="smtp_from_name">From Name</label>
                    <input type="text" id="smtp_from_name" name="smtp_from_name"
                           value="<?= e(getSettingValue($settings, 'smtp_from_name', '')) ?>"
                           placeholder="Steam Test Panel">
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Save SMTP Settings</button>
            <button type="submit" name="action" value="test_smtp" class="btn btn-secondary">Test Connection</button>
        </div>
    </form>
</div>

<!-- Info Card -->
<div class="card" style="background: rgba(126, 166, 75, 0.1); border: 1px solid var(--status-working);">
    <h3 class="card-title" style="color: var(--status-working);">Settings Information</h3>
    <ul class="info-list">
        <li><strong>Site Title:</strong> Changes the name displayed in the header and browser tab</li>
        <li><strong>Private Mode:</strong> When enabled, unauthenticated users are redirected to the login page</li>
        <li><strong>Database:</strong> Settings are in config.php and require server access to modify</li>
        <li><strong>SMTP:</strong> Configure email sending for notifications (optional feature)</li>
    </ul>
</div>

<style>
/* Form styles */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text-muted);
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="password"],
.form-group input[type="number"],
.form-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--bg-dark);
    color: var(--text);
    font-size: 14px;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(196, 181, 80, 0.2);
}

.form-hint {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 5px;
}

.form-row {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.form-row .form-group {
    flex: 1;
    min-width: 200px;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
}

/* Checkbox styles */
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.checkbox-text {
    font-weight: 600;
    color: var(--text);
}

/* Settings display */
.settings-display {
    background: var(--bg-dark);
    border-radius: 6px;
    padding: 15px 20px;
}

.setting-row {
    display: flex;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
}

.setting-row:last-child {
    border-bottom: none;
}

.setting-label {
    width: 120px;
    color: var(--text-muted);
    font-weight: 500;
}

.setting-value code {
    background: var(--bg-accent);
    padding: 2px 8px;
    border-radius: 3px;
    font-family: monospace;
}

/* Info box */
.info-box {
    background: rgba(52, 152, 219, 0.1);
    border: 1px solid #3498db;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 20px;
}

.info-box p {
    margin: 0;
    color: var(--text);
}

.info-box code {
    background: var(--bg-dark);
    padding: 2px 6px;
    border-radius: 3px;
    color: var(--primary);
}

/* SMTP fields toggle */
.smtp-fields.disabled {
    opacity: 0.5;
    pointer-events: none;
}

/* Alert styles */
.alert {
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.alert-error {
    background: rgba(231, 76, 60, 0.2);
    border: 1px solid var(--status-broken);
    color: var(--status-broken);
}

.alert-success {
    background: rgba(39, 174, 96, 0.2);
    border: 1px solid var(--status-working);
    color: var(--status-working);
}

/* Info list */
.info-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.info-list li {
    padding: 8px 0;
    border-bottom: 1px solid rgba(126, 166, 75, 0.2);
}

.info-list li:last-child {
    border-bottom: none;
}
</style>

<script>
function toggleSmtpFields() {
    const enabled = document.getElementById('smtp_enabled').checked;
    const fields = document.getElementById('smtp_fields');

    if (enabled) {
        fields.classList.remove('disabled');
    } else {
        fields.classList.add('disabled');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', toggleSmtpFields);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
