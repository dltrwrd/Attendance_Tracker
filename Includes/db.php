<?php
require_once 'config.php';

// Default fallback credentials (local)
$host = 'localhost';
$dbname = 'cxi_slt_tracker';
$username = 'root';
$password = '';

// Dynamically override credentials using the gitignored connection.php if available
$connPath = dirname(__DIR__) . '/connection.php';
if (file_exists($connPath)) {
    try {
        include $connPath;
    } catch (Throwable $e) {
        // Log error and continue with local default credentials
        error_log("Database config error in connection.php: " . $e->getMessage());
    }
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, [PDO::ATTR_PERSISTENT => true]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}