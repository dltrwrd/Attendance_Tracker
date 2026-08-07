<?php
// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session
session_start();

// Set default timezone for display purposes
date_default_timezone_set('Asia/Manila');

// Define a constant for database timezone (UTC recommended)
define('DB_TIMEZONE', 'UTC');

// Constants
// Dynamically determine the base URL path (e.g. '/' on production, or '/cxi-slt-tracker/' on local XAMPP)
$basePath = '/';
if (isset($_SERVER['HTTP_HOST'])) {
    $host = $_SERVER['HTTP_HOST'];
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        $projectDir = str_replace('\\', '/', dirname(__DIR__));
        $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
        if (stripos($projectDir, $docRoot) === 0) {
            $subDir = substr($projectDir, strlen($docRoot));
            $subDir = '/' . ltrim(str_replace('\\', '/', $subDir), '/');
            $basePath = rtrim($subDir, '/') . '/';
        } else {
            $basePath = '/cxi-slt-tracker/';
        }
    }
}
define('BASE_URL', $basePath);
define('ADMIN_URL', BASE_URL . 'admin/dashboard.php');