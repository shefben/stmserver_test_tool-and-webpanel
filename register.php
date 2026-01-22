<?php
/**
 * Registration page - Requires valid invite code
 * OldSteam Theme
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/db.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$db = Database::getInstance();
$error = '';
$success = '';

// Get invite code from URL or form
$inviteCode = $_GET['code'] ?? $_POST['invite_code'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inviteCode = trim($_POST['invite_code'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate inputs
    if (empty($inviteCode)) {
        $error = 'Invite code is required';
    } elseif (empty($username)) {
        $error = 'Username is required';
    } elseif (empty($password)) {
        $error = 'Password is required';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        // Try to register with the invite code
        $result = $db->useInviteCode($inviteCode, $username, $password);

        if ($result['success']) {
            $success = 'Account created successfully! You can now log in.';
            // Clear the invite code so form shows success state
            $inviteCode = '';
        } else {
            $error = $result['error'];
        }
    }
}

// Pre-validate invite code if provided via GET
$inviteValid = false;
$inviteError = '';
if ($inviteCode && !$success) {
    $validation = $db->validateInviteCode($inviteCode);
    $inviteValid = $validation['valid'];
    if (!$inviteValid) {
        $inviteError = $validation['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?= PANEL_NAME ?></title>
    <style>
        /* OldSteam Theme Colors */
        :root {
            --bg-dark: #3e4637;
            --bg-card: #4c5844;
            --bg-accent: #5a6a50;
            --primary: #c4b550;
            --primary-hover: #d4c560;
            --primary-dark: #91863c;
            --text: #eff6ee;
            --text-muted: #a0aa95;
            --border: #282e22;
            --border-bright: #808080;
            --status-working: #7ea64b;
            --status-broken: #c45050;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--bg-dark) 0%, var(--bg-card) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text);
            padding: 20px;
        }

        .register-container {
            background: linear-gradient(180deg, var(--bg-accent) 0%, var(--bg-card) 100%);
            padding: 40px;
            border-radius: 4px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.05);
            width: 100%;
            max-width: 450px;
            border: 2px solid var(--border);
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            font-size: 24px;
            color: var(--primary);
            margin-bottom: 8px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        }

        .logo p {
            color: var(--text-muted);
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 500;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 3px;
            background: var(--bg-dark);
            color: var(--text);
            font-size: 16px;
            transition: border-color 0.3s, box-shadow 0.3s;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.3);
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.3), 0 0 0 2px rgba(196, 181, 80, 0.2);
        }

        input[type="text"]::placeholder,
        input[type="password"]::placeholder {
            color: var(--text-muted);
        }

        input.valid {
            border-color: var(--status-working);
        }

        input.invalid {
            border-color: var(--status-broken);
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: 1px solid var(--primary);
            border-radius: 3px;
            color: var(--bg-dark);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.2), 0 2px 4px rgba(0,0,0,0.2);
        }

        button:hover {
            background: linear-gradient(180deg, var(--primary-hover) 0%, var(--primary) 100%);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.3), 0 2px 6px rgba(0,0,0,0.3);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .error {
            background: rgba(196, 80, 80, 0.2);
            border: 1px solid var(--status-broken);
            color: var(--status-broken);
            padding: 12px;
            border-radius: 3px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .success {
            background: rgba(126, 166, 75, 0.2);
            border: 1px solid var(--status-working);
            color: var(--status-working);
            padding: 12px;
            border-radius: 3px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .hint {
            margin-top: 20px;
            padding: 15px;
            background: rgba(126, 166, 75, 0.1);
            border: 1px solid var(--status-working);
            border-radius: 3px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .hint.error-hint {
            background: rgba(196, 80, 80, 0.1);
            border-color: var(--status-broken);
        }

        .hint strong {
            color: var(--text);
        }

        .hint code {
            background: var(--bg-dark);
            padding: 2px 6px;
            border-radius: 2px;
            color: var(--primary);
            border: 1px solid var(--border);
        }

        .steam-line {
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--primary), transparent);
            margin-bottom: 20px;
            border-radius: 2px;
        }

        .link-row {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .link-row a {
            color: var(--primary);
            text-decoration: none;
        }

        .link-row a:hover {
            text-decoration: underline;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .password-requirements {
            margin-top: 15px;
            padding: 12px;
            background: var(--bg-dark);
            border-radius: 3px;
            font-size: 12px;
        }

        .password-requirements h4 {
            color: var(--text-muted);
            margin-bottom: 8px;
            font-size: 11px;
            text-transform: uppercase;
        }

        .password-requirements ul {
            margin: 0;
            padding-left: 18px;
            color: var(--text-muted);
        }

        .password-requirements li {
            margin-bottom: 4px;
        }

        .invite-status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            font-size: 12px;
        }

        .invite-status.valid {
            color: var(--status-working);
        }

        .invite-status.invalid {
            color: var(--status-broken);
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <h1><?= PANEL_NAME ?></h1>
            <div class="steam-line"></div>
            <p>Create a new account</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success">
                <?= htmlspecialchars($success) ?>
                <br><br>
                <a href="login.php" style="color: var(--status-working); font-weight: 600;">Click here to log in &rarr;</a>
            </div>
        <?php else: ?>
            <form method="POST" id="registerForm">
                <div class="form-group">
                    <label for="invite_code">Invite Code</label>
                    <input type="text" id="invite_code" name="invite_code"
                           placeholder="INV-XXXXXXXXXXXX"
                           value="<?= htmlspecialchars($inviteCode) ?>"
                           required
                           class="<?= $inviteCode ? ($inviteValid ? 'valid' : 'invalid') : '' ?>">
                    <?php if ($inviteCode): ?>
                        <?php if ($inviteValid): ?>
                            <div class="invite-status valid">&#10003; Valid invite code</div>
                        <?php else: ?>
                            <div class="invite-status invalid">&#10007; <?= htmlspecialchars($inviteError) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="form-hint">Enter the invite code you received</p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           placeholder="Choose a username"
                           pattern="[a-zA-Z0-9_]+"
                           minlength="3"
                           maxlength="100"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           required
                           title="Letters, numbers, and underscores only">
                    <p class="form-hint">3+ characters, letters, numbers, and underscores only</p>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password"
                           placeholder="Create a password"
                           minlength="6"
                           required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           placeholder="Confirm your password"
                           minlength="6"
                           required>
                </div>

                <div class="password-requirements">
                    <h4>Password Requirements</h4>
                    <ul>
                        <li>At least 6 characters long</li>
                    </ul>
                </div>

                <button type="submit" style="margin-top: 20px;">Create Account</button>
            </form>

            <?php if (!$inviteCode || !$inviteValid): ?>
                <div class="hint <?= $inviteCode && !$inviteValid ? 'error-hint' : '' ?>">
                    <strong>Need an invite code?</strong><br>
                    Contact an administrator to get an invite code for registration.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="link-row">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>

    <script>
        // Client-side password match validation
        const form = document.getElementById('registerForm');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');

        if (form) {
            form.addEventListener('submit', function(e) {
                if (password.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    confirmPassword.focus();
                }
            });

            confirmPassword.addEventListener('input', function() {
                if (this.value && password.value !== this.value) {
                    this.classList.add('invalid');
                    this.classList.remove('valid');
                } else if (this.value && password.value === this.value) {
                    this.classList.add('valid');
                    this.classList.remove('invalid');
                } else {
                    this.classList.remove('valid', 'invalid');
                }
            });
        }
    </script>
</body>
</html>
