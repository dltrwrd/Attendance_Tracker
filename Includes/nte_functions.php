<?php
require_once 'config.php';

// Function to determine violation instance with 90-day cleansing
function getViolationInstance($employee_id, $current_date, $infraction_type) {
    global $pdo;
    
    // First, get the nature_of_offense from the infraction mapping
    $infraction_mapping = [
        'ATTENDANCE - Tardiness' => 'TARDINESS',
        'ATTENDANCE - Absences WITH Notification' => 'ABSENCE WITH NOTIFICATION',
        'ATTENDANCE - Absences WITHOUT Notification' => 'ABSENCE WITHOUT NOTIFICATION', 
        'OTHER HANDBOOK VIOLATION' => 'Non-adherence to prescribed work schedule, such as, but not limited to the following:'
    ];
    
    $nature_of_offense = $infraction_mapping[$infraction_type] ?? $infraction_type;
    
    $sql = "SELECT nte.violation_instance, nte.cleansing_end_date, nte.id as nte_id,
                   i.nature_of_offense, i.rule_section
            FROM notice_to_explain nte
            JOIN infractions i ON nte.infraction_id = i.id
            WHERE nte.employee_id = ? 
            AND nte.nte_status != 'draft'
            AND nte.cleansing_end_date >= ?
            AND i.nature_of_offense = ?
            ORDER BY nte.date_issued DESC 
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$employee_id, $current_date, $nature_of_offense]);
    $result = $stmt->fetch();
    
    if ($result) {
        $previous_instance = $result['violation_instance'];
        $next_instance = escalateInstance($previous_instance);
        
        error_log("Previous violation found for $employee_id: $previous_instance -> $next_instance (Cleansing until: " . $result['cleansing_end_date'] . ")");
        
        return [
            'instance' => $next_instance,
            'previous_nte_id' => $result['nte_id'],
            'within_cleansing' => true
        ];
    } else {
        error_log("No previous violation found for $employee_id within cleansing period. Starting with 1st instance.");
        
        return [
            'instance' => '1st',
            'previous_nte_id' => null,
            'within_cleansing' => false
        ];
    }
}

function escalateInstance($current_instance) {
    $escalation = [
        '1st' => '2nd',
        '2nd' => '3rd', 
        '3rd' => '4th',
        '4th' => '4th'
    ];
    return $escalation[$current_instance];
}

// Function to get sanction based on instance
function getSanctionByInstance($infraction_id, $instance) {
    global $pdo;
    
    $sql = "SELECT first_instance, second_instance, third_instance, fourth_instance 
            FROM infractions 
            WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$infraction_id]);
    $infraction = $stmt->fetch();
    
    switch($instance) {
        case '1st': return $infraction['first_instance'];
        case '2nd': return $infraction['second_instance'];
        case '3rd': return $infraction['third_instance'];
        case '4th': return $infraction['fourth_instance'];
        default: return $infraction['first_instance'];
    }
}

// Function to determine which infraction rule applies
function determineInfractionRule($infraction_type, $incident_details = '') {
    global $pdo;
    
    error_log("=== DETERMINE INFRACTION RULE ===");
    error_log("Infraction Type: " . $infraction_type);
    error_log("Incident Details: " . $incident_details);
    
    // Direct rule section mapping - SIMPLIFIED AND FIXED
    $rule_mapping = [
        'ATTENDANCE - Tardiness' => 'RULE I Section 1-A',
        'ATTENDANCE - Absences WITH Notification' => 'RULE I- Section 3', 
        'ATTENDANCE - Absences WITHOUT Notification' => 'RULE I- Section 2-A', // Default to 2-A, days logic will override if needed
        'OTHER HANDBOOK VIOLATION' => 'RULE I-Section 4-B'
    ];
    
    $rule_section = $rule_mapping[$infraction_type] ?? '';
    
    if (!$rule_section) {
        error_log("No rule mapping found for: " . $infraction_type);
        return null;
    }
    
    // Special handling for absence without notification based on days
    if ($infraction_type === 'ATTENDANCE - Absences WITHOUT Notification') {
        // Check if we can determine days from incident details
        $days = 0;
        if (preg_match('/(\d+)\s*day/i', $incident_details, $matches)) {
            $days = (int)$matches[1];
            error_log("Found days in incident details: " . $days);
        }
        
        if ($days >= 3) {
            $rule_section = 'RULE I- Section 2-B';
            error_log("Using RULE I- Section 2-B for " . $days . " days NCNS");
        } else {
            $rule_section = 'RULE I- Section 2-A';
            error_log("Using RULE I- Section 2-A for " . $days . " days NCNS");
        }
    }
    
    error_log("Final rule section: " . $rule_section);
    
    // Get the infraction ID
    $sql = "SELECT id FROM infractions WHERE rule_section = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$rule_section]);
    $result = $stmt->fetch();
    
    error_log("Infraction ID result: " . ($result ? "FOUND ID: " . $result['id'] : "NOT FOUND"));
    
    return $result ? $result['id'] : null;
}

// Function to get infraction details
function getInfractionDetails($infraction_id) {
    global $pdo;
    
    $sql = "SELECT rule_section, nature_of_offense, stipulation, specific_offenses 
            FROM infractions WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$infraction_id]);
    return $stmt->fetch();
}

// Function to auto-create NTE
function createNTEFromIR($ir_id, $employee_id, $full_name, $department, $supervisor, $operation_manager, 
                        $date_of_incident, $shift, $incident_details, $infraction_type) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // 1. Determine infraction rule (PASS INCIDENT_DETAILS FOR BETTER MATCHING)
        $infraction_id = determineInfractionRule($infraction_type, $incident_details);
        
        if (!$infraction_id) {
            throw new Exception("No matching infraction rule found for: " . $infraction_type);
        }
        
        // 2. Get violation instance
        $current_date = date('Y-m-d');
        $violation_instance_data = getViolationInstance($employee_id, $current_date, $infraction_type);
        
        // 3. Get sanction
        $sanction_proposed = getSanctionByInstance($infraction_id, $violation_instance_data['instance']);
        
        // 4. Get infraction details
        $infraction_details = getInfractionDetails($infraction_id);
        
        // 5. Calculate cleansing end date (90 days from current date)
        $cleansing_end_date = date('Y-m-d', strtotime($current_date . ' + 90 days'));
        
        // 6. Insert NTE
        $sql = "INSERT INTO notice_to_explain 
                (ir_id, employee_id, full_name, department, supervisor, operation_manager,
                 date_of_incident, shift, incident_details,
                 infraction_id, rule_section, nature_of_offense, stipulation, specific_offenses,
                 violation_instance, sanction_proposed, previous_nte_id, cleansing_end_date, nte_status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $ir_id, $employee_id, $full_name, $department, $supervisor, $operation_manager,
            $date_of_incident, $shift, $incident_details,
            $infraction_id, $infraction_details['rule_section'], $infraction_details['nature_of_offense'],
            $infraction_details['stipulation'], $infraction_details['specific_offenses'],
            $violation_instance_data['instance'], $sanction_proposed, 
            $violation_instance_data['previous_nte_id'], $cleansing_end_date
        ]);
        
        $nte_id = $pdo->lastInsertId();
        $pdo->commit();
        
        return $nte_id;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Function to check if NTE exists for IR
function getNTEByIRId($ir_id) {
    global $pdo;
    
    $sql = "SELECT id FROM notice_to_explain WHERE ir_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ir_id]);
    return $stmt->fetch();
}
?>