<?php
require_once 'config.php';

// Function to determine violation instance with 90-day cleansing
function getViolationInstance($employee_id, $current_date, $infraction_type) {
    global $pdo;
    
    $sql = "SELECT nte.violation_instance, nte.cleansing_end_date, nte.id as nte_id
            FROM notice_to_explain nte
            JOIN infractions i ON nte.infraction_id = i.id
            WHERE nte.employee_id = ? 
            AND nte.nte_status != 'draft'
            AND nte.cleansing_end_date >= ?
            AND i.nature_of_offense LIKE ?
            ORDER BY nte.date_issued DESC 
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $infraction_pattern = "%" . $infraction_type . "%";
    $stmt->execute([$employee_id, $current_date, $infraction_pattern]);
    $result = $stmt->fetch();
    
    if ($result) {
        $previous_instance = $result['violation_instance'];
        $next_instance = escalateInstance($previous_instance);
        
        return [
            'instance' => $next_instance,
            'previous_nte_id' => $result['nte_id'],
            'within_cleansing' => true
        ];
    } else {
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
function determineInfractionRule($infraction_type) {
    global $pdo;
    
    $infraction_mapping = [
        'ATTENDANCE - Tardiness' => 'TARDINESS',
        'ATTENDANCE - Absences WITH Notification' => 'ABSENCE WITH NOTIFICATION',
        'ATTENDANCE - Absences WITHOUT Notification' => 'ABSENCE WITHOUT NOTIFICATION',
        'OTHER HANDBOOK VIOLATION' => 'NON-ADHERENCE'
    ];
    
    $search_term = $infraction_mapping[$infraction_type] ?? $infraction_type;
    
    $sql = "SELECT id FROM infractions 
            WHERE nature_of_offense LIKE ? 
            OR specific_offenses LIKE ? 
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $search_pattern = "%" . $search_term . "%";
    $stmt->execute([$search_pattern, $search_pattern]);
    $result = $stmt->fetch();
    
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
function createNTEFromIR($ir_id, $employee_id, $full_name, $department, $operation_manager, 
                        $date_of_incident, $shift, $incident_details, $infraction_type) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // 1. Determine infraction rule
        $infraction_id = determineInfractionRule($infraction_type);
        
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
                (ir_id, employee_id, full_name, department, operation_manager,
                 date_of_incident, shift, incident_details,
                 infraction_id, rule_section, nature_of_offense, stipulation, specific_offenses,
                 violation_instance, sanction_proposed, previous_nte_id, cleansing_end_date, nte_status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $ir_id, $employee_id, $full_name, $department, $operation_manager,
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