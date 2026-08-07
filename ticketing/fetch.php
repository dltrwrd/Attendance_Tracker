<?php
session_start();
header('Content-Type: application/json');

$con = new mysqli("localhost", "root", "", "cts");
date_default_timezone_set('Asia/Manila');

if ($con->connect_error) {
    echo json_encode(['error' => 'Database connection failed: ' . $con->connect_error]);
    exit();
}

$user_name = $_SESSION['short_name'] ?? '';

$sql = "SELECT Timestamp, Email_Address, Department, Site, Affected_employee, EID, Issues_Concerning, 
               Issue_Details, Station_Number, TIME_RECEIVED, TIME_RESOLVED, SLT_on_DUTY, 
               Week_Beginning, LOB, OM, Employee_name, Work_Number, Status, sub_cat, Urgency 
        FROM ticket 
        WHERE status = 'PENDING' 
        ORDER BY Timestamp DESC";

$result = $con->query($sql);

$tickets = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $tickets[] = $row;
    }
} else {
    echo json_encode(['error' => 'Error fetching tickets: ' . $con->error]);
    $con->close();
    exit();
}

echo json_encode($tickets);

$con->close();
?>