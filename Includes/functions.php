<?php
require_once 'config.php';
require_once 'db.php';

ob_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function redirect($url) {
    if (!headers_sent()) {
        header("Location: $url");
        exit();
    } else {
        echo "<script>window.location.href='$url';</script>";
        exit();
    }
}

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function updateLastActivity() {
    global $pdo;
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET last_activity = CONVERT_TZ(NOW(), 'SYSTEM', '+08:00') WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $_SESSION['last_activity'] = time();
        } catch (PDOException $e) {
            error_log("Update last activity error: " . $e->getMessage());
        }
    }
}

function logActivity($description, $recordId = null, $recordType = null) {
    global $pdo;
    
    if (isset($_SESSION['user_id'])) {
        // Get the user's sub_name if not already in session
        if (!isset($_SESSION['sub_name'])) {
            $stmt = $pdo->prepare("SELECT sub_name FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            $stmt->closeCursor();
            $_SESSION['sub_name'] = $user['sub_name'] ?? 'System';
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_history 
                (user_id, sub_name, activity_description, record_id, record_type, activity_time) 
                VALUES (?, ?, ?, ?, ?, CONVERT_TZ(NOW(), 'SYSTEM', '+08:00'))
            ");
            $stmt->execute([
                $_SESSION['user_id'], 
                $_SESSION['sub_name'], 
                $description,
                $recordId,
                $recordType
            ]);
            $stmt->closeCursor();
        } catch (PDOException $e) {
            error_log("Activity log error: " . $e->getMessage());
        }
    }
}


// Add this function to your functions.php file
function updateTardinessIRStatus($pdo, $employeeId = null) {
    try {
        // If specific employee is provided, only process that employee
        $whereClause = $employeeId ? "WHERE employee_id = :employee_id" : "";
        $params = $employeeId ? [':employee_id' => $employeeId] : [];
        
        // Get all tardiness records for the employee(s)
        $query = "SELECT * FROM tardiness $whereClause ORDER BY date_of_incident ASC";
        $stmt = $pdo->prepare($query);
        
        if ($employeeId) {
            $stmt->bindValue(':employee_id', $employeeId);
        }
        
        $stmt->execute();
        $allRecords = $stmt->fetchAll();
        
        // Group records by employee_id
        $employeeRecords = [];
        foreach ($allRecords as $record) {
            $empId = $record['employee_id'];
            if (!isset($employeeRecords[$empId])) {
                $employeeRecords[$empId] = [];
            }
            $employeeRecords[$empId][] = $record;
        }
        
        // Process each employee
        foreach ($employeeRecords as $currentEmployeeId => $empRecords) {
            // Sort all records by date
            usort($empRecords, function($a, $b) {
                return strtotime($a['date_of_incident']) - strtotime($b['date_of_incident']);
            });
            
            // Process each record as a starting point for a 30-day period
            for ($i = 0; $i < count($empRecords); $i++) {
                $startRecord = $empRecords[$i];
                $startDate = strtotime($startRecord['date_of_incident']);
                $endDate = strtotime('+30 days', $startDate);
                
                $periodRecords = [];
                $totalMinutes = 0;
                $lateCount = 0;
                $previousIRCount = 0;
                $accumulationRecords = [];
                
                // Find all records within this 30-day period
                for ($j = 0; $j < count($empRecords); $j++) {
                    $currentRecord = $empRecords[$j];
                    $currentDate = strtotime($currentRecord['date_of_incident']);
                    
                    // Count previous IRs that occurred BEFORE this period
                    if ($currentDate < $startDate && ($currentRecord['ir_form'] === 'FOR IR' || $currentRecord['ir_form'] === 'YES')) {
                        $previousIRCount++;
                    }
                    
                    // Include records within this period
                    if ($currentDate >= $startDate && $currentDate <= $endDate) {
                        $periodRecords[] = $currentRecord;
                        
                        // Accumulate FOR ACCUMULATION records within this period
                        if ($currentRecord['ir_form'] === 'FOR ACCUMULATION') {
                            $totalMinutes += (int)$currentRecord['minutes_late'];
                            $lateCount++;
                            $accumulationRecords[] = $currentRecord;
                        }
                    }
                }
                
                // Apply memo rules for this period based on previous IR count
                $shouldUpdateToIR = false;
                $newOffenseType = '';
                
                if ($previousIRCount === 0) {
                    // First FOR IR: 3 instances OR 60+ minutes
                    if ($lateCount >= 3 || $totalMinutes >= 60) {
                        $shouldUpdateToIR = true;
                        $newOffenseType = 'FIRST';
                    }
                } elseif ($previousIRCount === 1) {
                    // Second FOR IR: Another 3 instances OR 30+ minutes
                    if ($lateCount >= 3 || $totalMinutes >= 30) {
                        $shouldUpdateToIR = true;
                        $newOffenseType = 'SECOND';
                    }
                } 
                // For third and subsequent offenses, use the same logic as second offense
                elseif ($previousIRCount >= 2) {
                    // Subsequent FOR IR: Another 3 instances OR 30+ minutes
                    if ($lateCount >= 1 || $totalMinutes >= 1) {
                        $shouldUpdateToIR = true;
                        $newOffenseType = 'SUBSEQUENT';
                    }
                }
                
                // Update records if needed for this period
                if ($shouldUpdateToIR && !empty($accumulationRecords)) {
                    error_log("Updating IR for employee $currentEmployeeId - Previous IRs: $previousIRCount, Late Count: $lateCount, Total Minutes: $totalMinutes, Offense Type: $newOffenseType");
                    
                    foreach ($accumulationRecords as $record) {
                        try {
                            $updateStmt = $pdo->prepare("UPDATE tardiness SET ir_form = 'FOR IR' WHERE id = :id");
                            $updateStmt->bindValue(':id', $record['id']);
                            $updateStmt->execute();
                            error_log("Updated record ID {$record['id']} to FOR IR");
                        } catch (PDOException $e) {
                            error_log("Error updating tardiness record ID {$record['id']}: " . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error in updateTardinessIRStatus: " . $e->getMessage());
        return false;
    }
}


ob_end_flush();
