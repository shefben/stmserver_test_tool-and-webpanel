<?php
/**
 * Authentication handler
 * Now uses database-backed user management
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user']);
}

// Require login
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

// Require admin role
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        setFlash('error', 'Access denied. Admin privileges required.');
        header('Location: ?page=dashboard');
        exit;
    }
}

// Check if current user is admin
function isAdmin() {
    $user = getCurrentUser();
    return $user && $user['role'] === 'admin';
}

// Authenticate user
function authenticate($username, $password) {
    $db = Database::getInstance();

    // First try database users
    $user = $db->getUser($username);
    if ($user && $db->verifyPassword($username, $password)) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $username,
            'role' => $user['role'],
            'api_key' => $user['api_key']
        ];
        return true;
    }

    // Fallback to config users (for backward compatibility during migration)
    if (defined('AUTH_USERS') && isset(AUTH_USERS[$username])) {
        if (AUTH_USERS[$username]['password'] === $password) {
            $_SESSION['user'] = [
                'id' => 0,
                'username' => $username,
                'role' => ($username === 'admin') ? 'admin' : 'user',
                'api_key' => AUTH_USERS[$username]['api_key']
            ];
            return true;
        }
    }

    return false;
}

// Authenticate via API key
function authenticateApiKey($apiKey) {
    $db = Database::getInstance();

    // Try database users first
    $user = $db->getUserByApiKey($apiKey);
    if ($user) {
        return $user['username'];
    }

    // Fallback to config users
    if (defined('AUTH_USERS')) {
        foreach (AUTH_USERS as $username => $userData) {
            if ($userData['api_key'] === $apiKey) {
                return $username;
            }
        }
    }

    return false;
}

// Get current user
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

// Logout
function logout() {
    unset($_SESSION['user']);
    session_destroy();
}

// Check API authentication (for API endpoints)
function requireApiAuth() {
    $apiKey = null;

    // Priority 1: Check query/post params first (most reliable)
    if (!empty($_GET['api_key'])) {
        $apiKey = $_GET['api_key'];
    } elseif (!empty($_POST['api_key'])) {
        $apiKey = $_POST['api_key'];
    }

    // Priority 2: Check headers if no query param
    if (!$apiKey) {
        // Try getallheaders()
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach (['X-API-Key', 'x-api-key', 'X-Api-Key'] as $key) {
                if (!empty($headers[$key])) {
                    $apiKey = $headers[$key];
                    break;
                }
            }
        }
    }

    // Priority 3: $_SERVER HTTP_ prefix
    if (!$apiKey && !empty($_SERVER['HTTP_X_API_KEY'])) {
        $apiKey = $_SERVER['HTTP_X_API_KEY'];
    }

    if (!$apiKey) {
        http_response_code(401);
        echo json_encode(['error' => 'API key required']);
        exit;
    }

    $user = authenticateApiKey($apiKey);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }

    return $user;
}

// Can user edit a report?
function canEditReport($reportId) {
    $user = getCurrentUser();
    if (!$user) return false;

    // Admins can edit any report
    if ($user['role'] === 'admin') return true;

    // Users can only edit their own reports
    $db = Database::getInstance();
    $report = $db->getReport($reportId);
    if (!$report) return false;

    return $report['tester'] === $user['username'];
}

// Can user delete a report?
function canDeleteReport($reportId) {
    $user = getCurrentUser();
    if (!$user) return false;

    // Only admins can delete reports
    return $user['role'] === 'admin';
}
