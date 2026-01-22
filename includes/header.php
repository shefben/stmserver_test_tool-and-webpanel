<?php
/**
 * Header template
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

// Require login for all pages
requireLogin();

$currentPage = getCurrentPage();
$user = getCurrentUser();

// Get unread notification count
$db = Database::getInstance();
$unreadNotificationCount = 0;
if (isset($user['id'])) {
    $unreadNotificationCount = $db->getUnreadNotificationCount($user['id']);
}

// Ensure role is set in session (for users logged in before role system was added)
if (!isset($user['role'])) {
    $db = Database::getInstance();
    $dbUser = $db->getUser($user['username']);
    if ($dbUser) {
        $_SESSION['user']['role'] = $dbUser['role'];
        $user = getCurrentUser();
    } else {
        // Default to 'user' role if not in database
        $_SESSION['user']['role'] = ($user['username'] === 'admin') ? 'admin' : 'user';
        $user = getCurrentUser();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= PANEL_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-inner">
                <a href="?page=dashboard" class="logo"><?= PANEL_NAME ?></a>

                <nav class="nav">
                    <a href="?page=dashboard" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
                    <a href="?page=reports" class="<?= $currentPage === 'reports' ? 'active' : '' ?>">Reports</a>
                    <a href="?page=create_report" class="<?= $currentPage === 'create_report' ? 'active' : '' ?>">Create Report</a>
                    <a href="?page=submit" class="<?= $currentPage === 'submit' ? 'active' : '' ?>">Submit</a>
                    <a href="?page=my_reports" class="<?= $currentPage === 'my_reports' ? 'active' : '' ?>">My Reports</a>
                    <a href="?page=versions" class="<?= $currentPage === 'versions' ? 'active' : '' ?>">Versions</a>
                    <a href="?page=repo_revisions" class="<?= $currentPage === 'repo_revisions' ? 'active' : '' ?>">Commits</a>
                    <a href="?page=git_revisions" class="<?= in_array($currentPage, ['git_revisions', 'git_revision_detail']) ? 'active' : '' ?>">Git History</a>
                    <a href="?page=tests" class="<?= $currentPage === 'tests' ? 'active' : '' ?>">Tests</a>
                    <?php if (isAdmin()): ?>
                        <span class="nav-divider">|</span>
                        <a href="?page=admin" class="<?= in_array($currentPage, ['admin', 'admin_users', 'admin_reports']) ? 'active' : '' ?> admin-link">Admin</a>
                    <?php endif; ?>
                </nav>

                <div class="user-info">
                    <a href="?page=notifications" class="notification-bell" title="Notifications">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        <?php if ($unreadNotificationCount > 0): ?>
                            <span class="notification-bubble"><?= $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?page=profile" class="user-link">
                        <span><?= e($user['username']) ?></span>
                        <?php if (isset($user['role'])): ?>
                            <span class="role-badge <?= $user['role'] ?>"><?= e(ucfirst($user['role'])) ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="logout.php" class="btn btn-sm btn-secondary">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <?= renderFlash() ?>

    <main class="main">
        <div class="container">
