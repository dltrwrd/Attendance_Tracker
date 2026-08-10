<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized');
}

// Set a flag that attendance_table.php can read to know it should output CSV
$_POST['export_csv'] = true;

// Require attendance_table.php - it will handle building the query, fetching results, and outputting the CSV
require 'attendance_table.php';
