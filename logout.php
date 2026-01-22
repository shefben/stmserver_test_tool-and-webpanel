<?php
/**
 * Logout handler
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

logout();

header('Location: login.php');
exit;
