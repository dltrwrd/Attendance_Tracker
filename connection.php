<?php
$host = 'localhost';
$dbname = 'auth_system';
$username = 'root';
$password = '';

$con = mysqli_connect($host, $username, $password, $dbname);

if (!$con) {
    die("Connection failed: " . mysqli_connect_error());
}
?>