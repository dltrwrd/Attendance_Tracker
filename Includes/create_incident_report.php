<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'config.php';
require_once 'functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
$required_fields = ['record_id', 'type', 'employee_id', 'full_name', 'department', 'supervisor', 'operation_manager', 'date_of_incident', 'shift'];
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
    $supervisor = sanitizeInput($_POST['supervisor']);
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

        // In create_incident_report.php, replace the absenteeism section with:

        if ($type === 'absenteeism') {
            $reason = isset($_POST['reason']) ? sanitizeInput($_POST['reason']) : 'No reason provided';
            $followCallInProcedure = isset($_POST['follow_call_in_procedure']) ? strtoupper(sanitizeInput($_POST['follow_call_in_procedure'])) : '';
            
            // Set infraction based on call-in procedure
            if (strpos($followCallInProcedure, 'NO') !== false) {
                $infraction = 'ATTENDANCE - Absences WITHOUT Notification';
                
                // Check days for NCNS
                $days = 0;
                if (preg_match('/(\d+)\s*day/i', $reason, $matches)) {
                    $days = (int)$matches[1];
                }
                
                if ($days >= 3) {
                    $infraction_id = 4; // RULE I- Section 2-B
                    $rule_section = 'RULE I- Section 2-B';
                    $nature_of_offense = 'ABSENCE WITHOUT NOTIFICATION';
                    $stipulation = 'B';
                    $specific_offenses = '3 days (or more) No Call No Show (NCNS) / Absence Without Official Leave (AWOL) and / or failure to report for work and advise immediate superior regarding the absence within 4 hours before the scheduled shift or schedule.';
                } else {
                    $infraction_id = 3; // RULE I- Section 2-A  
                    $rule_section = 'RULE I- Section 2-A';
                    $nature_of_offense = 'ABSENCE WITHOUT NOTIFICATION';
                    $stipulation = 'A';
                    $specific_offenses = '1-2 consecutive days No Call No Show (NCNS) / Absence Without Official Leave (AWOL) and / or failure to report for work and advise immediate superior regarding the absence within 4 hours before the scheduled shift or schedule.';
                }
            } else {
                $infraction = 'ATTENDANCE - Absences WITH Notification';
                $infraction_id = 5; // RULE I- Section 3
                $rule_section = 'RULE I- Section 3';
                $nature_of_offense = 'ABSENCE WITH NOTIFICATION';
                $stipulation = '3';
                $specific_offenses = 'ABSENCE WITH NOTIFICATION (4 Hrs before the shift)/ Unauthorized Absence. Absences without valid supporting documents such as but not limited to, medical certificate, police reports and the likes.';
            }
            
            // Get violation instance and sanction
            require_once 'nte_functions.php';
            $violation_instance_data = getViolationInstance($employeeId, date('Y-m-d'), $infraction);
            $sanction_proposed = getSanctionByInstance($infraction_id, $violation_instance_data['instance']);
            
            $incidentDetails = "Date of Absence: " . date('F j, Y', strtotime($dateOfIncident)) . 
                            "\nShift: " . $shift . 
                            "\nReason: " . $reason .
                            "\nCall-in Procedure: " . $followCallInProcedure;
        } else {
            $types = isset($_POST['types']) ? sanitizeInput($_POST['types']) : 'LATE';
            $minutesLate = isset($_POST['minutes_late']) ? (int)$_POST['minutes_late'] : 0;
            
            $infraction = 'ATTENDANCE - Tardiness';
            
            // Use nte_functions to determine the correct infraction details
            require_once 'nte_functions.php';
            $infraction_id = determineInfractionRule($infraction, $incidentDetails);
            
            if (!$infraction_id) {
                throw new Exception("No matching infraction rule found for: " . $infraction);
            }
            
            // Get the infraction details from the database
            $infraction_details = getInfractionDetails($infraction_id);
            
            // Get violation instance and sanction
            $violation_instance_data = getViolationInstance($employeeId, date('Y-m-d'), $infraction);
            
            $rule_section = $infraction_details['rule_section'];
            $nature_of_offense = $infraction_details['nature_of_offense'];
            $stipulation = $infraction_details['stipulation'];
            $specific_offenses = $infraction_details['specific_offenses'];
            $sanction_proposed = getSanctionByInstance($infraction_id, $violation_instance_data['instance']);
            
            $incidentDetails = "Date of Incident: " . date('F j, Y', strtotime($dateOfIncident)) . 
                            "\nShift: " . $shift . 
                            "\nType: " . $types . 
                            "\nMinutes Late: " . $minutesLate . " minutes";
        }

    // Insert into incident_report table
    $stmt = $pdo->prepare("INSERT INTO incident_report 
        (email_address, employee_id, full_name, department, supervisor, operation_manager, 
         infraction, reported_by, position, date_of_incident, shift, 
         incident_details, evidence, created_at, related_record_id, related_record_type) 
        VALUES 
        (:email_address, :employee_id, :full_name, :department, :supervisor, :operation_manager,
         :infraction, :reported_by, :position, :date_of_incident, :shift,
         :incident_details, :evidence, :created_at, :related_record_id, :related_record_type)");

    date_default_timezone_set('Asia/Manila');
    
    $data = [
        'email_address' => $_SESSION['slt_email'],
        'employee_id' => $employeeId,
        'full_name' => strtoupper($fullName),
        'department' => strtoupper($department),
        'supervisor' => strtoupper($supervisor),
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

    // ========== AUTO-CREATE NTE FROM THIS IR ==========
    $nte_id = null;
    $nte_success = false;
    $nte_error = '';
    $nte_number = '';
    
    try {
        // Check if notice_to_explain table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'notice_to_explain'")->fetch(PDO::FETCH_ASSOC);
        
        if (!$tableCheck) {
            throw new Exception("notice_to_explain table does not exist.");
        }

        // Generate NTE number
        $nte_number = 'NTE-' . date('Ymd-His');
        
        // Calculate cleansing end date (3 months from now)
        $cleansing_end_date = date('Y-m-d', strtotime('+3 months'));
        
        // Insert into notice_to_explain table
        $nteStmt = $pdo->prepare("INSERT INTO notice_to_explain 
            (ir_id, employee_id, full_name, department, supervisor, operation_manager, 
             date_of_incident, shift, incident_details, infraction_id, 
             rule_section, nature_of_offense, stipulation, specific_offenses, 
             sanction_proposed, violation_instance, date_issued, cleansing_end_date, nte_status, created_at) 
            VALUES 
            (:ir_id, :employee_id, :full_name, :department, :supervisor, :operation_manager,
             :date_of_incident, :shift, :incident_details, :infraction_id,
             :rule_section, :nature_of_offense, :stipulation, :specific_offenses,
             :sanction_proposed, :violation_instance, :date_issued, :cleansing_end_date, :nte_status, :created_at)");
        
        // In the NTE creation section, replace the hardcoded violation_instance:
        $nteData = [
            'ir_id' => $incidentReportId,
            'employee_id' => $employeeId,
            'full_name' => strtoupper($fullName),
            'department' => strtoupper($department),
            'supervisor' => strtoupper($supervisor),
            'operation_manager' => strtoupper($operationManager),
            'date_of_incident' => $dateOfIncident,
            'shift' => strtoupper($shift),
            'incident_details' => $incidentDetails,
            'infraction_id' => $infraction_id,
            'rule_section' => $rule_section,
            'nature_of_offense' => $nature_of_offense,
            'stipulation' => $stipulation,
            'specific_offenses' => $specific_offenses,
            'sanction_proposed' => $sanction_proposed,
            'violation_instance' => $violation_instance_data['instance'], // Use dynamic instance
            'date_issued' => date('Y-m-d'),
            'cleansing_end_date' => $cleansing_end_date,
            'nte_status' => 'draft',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $nteStmt->execute($nteData);
        $nte_id = $pdo->lastInsertId();
        $nte_success = true;
        
    } catch (Exception $e) {
        $nte_success = false;
        $nte_error = $e->getMessage();
        error_log("NTE Auto-creation failed for IR #$incidentReportId: " . $e->getMessage());
        
        // Log detailed error information for debugging
        error_log("NTE Error Details: " . print_r([
            'employee_id' => $employeeId,
            'infraction_id' => $infraction_id,
            'error_message' => $e->getMessage()
        ], true));
    }

    // Update the original record's ir_form to "YES"
    $updateStmt = $pdo->prepare("UPDATE $table SET ir_form = 'YES' WHERE id = ?");
    $updateStmt->execute([$recordId]);

    // Log activity
    logActivity("Created incident report for {$data['full_name']}", $recordId, $type);

    $pdo->commit();

    // Prepare response
    $response = [
        'success' => true, 
        'message' => 'Incident Report created successfully',
        'incident_report_id' => $incidentReportId
    ];

    // Add NTE information to response
    if ($nte_success && $nte_id) {
        $response['nte_id'] = $nte_id;
        $response['nte_number'] = $nte_number;
        $response['message'] = "Incident Report created successfully! NTE #$nte_number auto-generated.";
    } else {
        $response['nte_warning'] = $nte_error;
        $response['message'] = "Incident Report created successfully! (NTE generation failed: " . $nte_error . ")";
    }

    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Error creating incident report: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error creating Incident Report: ' . $e->getMessage()
    ]);
}

exit();
?>