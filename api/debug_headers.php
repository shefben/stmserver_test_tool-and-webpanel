<?php
/**
 * Debug endpoint to see what headers are received
 * DELETE THIS FILE AFTER DEBUGGING
 */

header('Content-Type: application/json');

$debug = [
    'getallheaders' => function_exists('getallheaders') ? getallheaders() : 'not available',
    'apache_request_headers' => function_exists('apache_request_headers') ? apache_request_headers() : 'not available',
    'SERVER_vars' => [
        'HTTP_X_API_KEY' => $_SERVER['HTTP_X_API_KEY'] ?? 'not set',
        'HTTP_X_API_Key' => $_SERVER['HTTP_X_API_Key'] ?? 'not set',
        'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'not set',
    ],
    'GET_api_key' => $_GET['api_key'] ?? 'not set',
    'all_HTTP_headers' => array_filter($_SERVER, function($key) {
        return strpos($key, 'HTTP_') === 0;
    }, ARRAY_FILTER_USE_KEY),
];

echo json_encode($debug, JSON_PRETTY_PRINT);
