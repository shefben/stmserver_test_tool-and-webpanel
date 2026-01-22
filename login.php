<?php
/**
 * Login page - OldSteam Theme
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (authenticate($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= PANEL_NAME ?></title>
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
        }

        .login-container {
            background: linear-gradient(180deg, var(--bg-accent) 0%, var(--bg-card) 100%);
            padding: 40px;
            border-radius: 4px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.05);
            width: 100%;
            max-width: 400px;
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

        .error {
            background: rgba(196, 80, 80, 0.2);
            border: 1px solid var(--status-broken);
            color: var(--status-broken);
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

        .register-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            font-size: 14px;
            color: var(--text-muted);
        }

        .register-link a {
            color: var(--primary);
            text-decoration: none;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        /* Steam-like decorative element */
        .steam-line {
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--primary), transparent);
            margin-bottom: 20px;
            border-radius: 2px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1><?= PANEL_NAME ?></h1>
            <div class="steam-line"></div>
            <p>Sign in to access the testing dashboard</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>

            <button type="submit">Sign In</button>
        </form>

        <div class="hint">
            <strong>Default credentials:</strong><br>
            Username: <code>admin</code><br>
            Password: <code>steamtest2024</code>
        </div>

        <div class="register-link">
            Don't have an account? <a href="register.php">Register with invite code</a>
        </div>
    </div>
</body>
</html>
