<?php
$host = 'localhost';
$dbname = 'cxi_slt_tracker';
$username = 'root';
$password = '';

$con = mysqli_connect($host, $username, $password, $dbname);

if (!$con) {
    die("Connection failed: " . mysqli_connect_error());
}
?>