<?php
$host = 'localhost';
$dbname = 'u865665685_cxi_database';
$username = 'u865665685_cxi_database';
$password = 'Wea_dayaday05';

$con = mysqli_connect($host, $username, $password, $dbname);

if (!$con) {
    die("Connection failed: " . mysqli_connect_error());
}
?>