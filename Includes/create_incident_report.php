<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Itago ang errors sa production, ilagay sa log instead

require_once 'config.php';
require_once 'functions.php';

// Simulan ang session kung hindi pa nagsisimula
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON header muna bago mag-output ng kahit ano
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['slt_email']) || !isset($_SESSION['nickname'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access - Session expired']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Check required fields
$required_fields = ['record_id', 'type', 'employee_id', 'full_name', 'department', 'operation_manager', 'date_of_incident', 'shift'];
foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || empty($_POST[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

try {
    $pdo->beginTransaction();

    // Get form data
    $recordId = (int)$_POST['record_id'];
    $type = $_POST['type'];
    $employeeId = sanitizeInput($_POST['employee_id']);
    $fullName = sanitizeInput($_POST['full_name']);
    $department = sanitizeInput($_POST['department']);
    $operationManager = sanitizeInput($_POST['operation_manager']);
    $dateOfIncident = sanitizeInput($_POST['date_of_incident']);
    $shift = sanitizeInput($_POST['shift']);

    // Validate record exists
    $table = ($type === 'tardiness') ? 'tardiness' : 'absenteeism';
    $checkStmt = $pdo->prepare("SELECT id FROM $table WHERE id = ?");
    $checkStmt->execute([$recordId]);
    
    if (!$checkStmt->fetch()) {
        throw new Exception("Original record not found");
    }

    // Determine infraction based on type and data
    $infraction = '';
    $incidentDetails = '';

    if ($type === 'absenteeism') {
        $reason = isset($_POST['reason']) ? sanitizeInput($_POST['reason']) : 'No reason provided';
        $followCallInProcedure = isset($_POST['follow_call_in_procedure']) ? strtoupper(sanitizeInput($_POST['follow_call_in_procedure'])) : '';
        
        // Set infraction based on call-in procedure
        if (strpos($followCallInProcedure, 'NO') !== false) {
            $infraction = 'ATTENDANCE - Absences WITHOUT Notification';
        } else {
            $infraction = 'ATTENDANCE - Absences WITH Notification';
        }
        
        $incidentDetails = "Date of Absence: " . date('F j, Y', strtotime($dateOfIncident)) . 
                          "\nShift: " . $shift . 
                          "\nReason: " . $reason .
                          "\nCall-in Procedure: " . $followCallInProcedure;
    } else {
        $types = isset($_POST['types']) ? sanitizeInput($_POST['types']) : 'LATE';
        $minutesLate = isset($_POST['minutes_late']) ? (int)$_POST['minutes_late'] : 0;
        
        $infraction = 'ATTENDANCE - Tardiness';
        $incidentDetails = "Date of Incident: " . date('F j, Y', strtotime($dateOfIncident)) . 
                          "\nShift: " . $shift . 
                          "\nType: " . $types . 
                          "\nMinutes Late: " . $minutesLate . " minutes";
    }


    // Insert into incident_report table
    $stmt = $pdo->prepare("INSERT INTO incident_report 
        (email_address, employee_id, full_name, department, operation_manager, 
         infraction, reported_by, position, date_of_incident, shift, 
         incident_details, evidence, created_at, related_record_id, related_record_type) 
        VALUES 
        (:email_address, :employee_id, :full_name, :department, :operation_manager,
         :infraction, :reported_by, :position, :date_of_incident, :shift,
         :incident_details, :evidence, :created_at, :related_record_id, :related_record_type)");


    // Set timezone sa PHP
    date_default_timezone_set('Asia/Manila');
    
    $data = [
        'email_address' => $_SESSION['slt_email'],
        'employee_id' => $employeeId,
        'full_name' => strtoupper($fullName),
        'department' => strtoupper($department),
        'operation_manager' => strtoupper($operationManager),
        'infraction' => $infraction,
        'reported_by' => $_SESSION['nickname'],
        'position' => 'SLT',
        'date_of_incident' => $dateOfIncident,
        'shift' => strtoupper($shift),
        'incident_details' => $incidentDetails,
        'evidence' => '',
        'created_at' => date('Y-m-d H:i:s'),
        'related_record_id' => $recordId,
        'related_record_type' => $type
    ];

    $stmt->execute($data);
    $incidentReportId = $pdo->lastInsertId();

    // Update the original record's ir_form to "YES"
    $updateStmt = $pdo->prepare("UPDATE $table SET ir_form = 'YES' WHERE id = ?");
    $updateStmt->execute([$recordId]);

    // Log activity
    logActivity("Created incident report for {$data['full_name']}", $recordId, $type);

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Incident Report created successfully',
        'incident_report_id' => $incidentReportId
    ]);

} catch (Exception $e) {
    // Rollback transaction kung may error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log the actual error for debugging
    error_log("Error creating incident report: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error creating Incident Report: ' . $e->getMessage()
    ]);
}

exit();
?>