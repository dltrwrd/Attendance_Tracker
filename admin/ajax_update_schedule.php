<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Handle inline updates via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    header('Content-Type: application/json');
    
    $scheduleId = (int)$_POST['schedule_id'];
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';
    
    // Validate field to prevent SQL injection
    $allowedFields = ['site', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    if (!in_array($field, $allowedFields)) {
        echo json_encode(['success' => false, 'message' => 'Invalid field']);
        exit;
    }
    
    try {
        // Update the specific field
        $stmt = $pdo->prepare("UPDATE schedule SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $scheduleId]);
        
        // Recalculate total hours
        $stmt = $pdo->prepare("SELECT monday, tuesday, wednesday, thursday, friday, saturday, sunday FROM schedule WHERE id = ?");
        $stmt->execute([$scheduleId]);
        $schedule = $stmt->fetch();
        
        $totalHours = 0;
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            if (!empty($schedule[$day])) {
                if (strpos($schedule[$day], '-') !== false) {
                    $totalHours += 8;
                } elseif (is_numeric($schedule[$day])) {
                    $totalHours += (float)$schedule[$day];
                } else {
                    $totalHours += 8;
                }
            }
        }
        
        // Update total hours
        $stmt = $pdo->prepare("UPDATE schedule SET total_sched = ? WHERE id = ?");
        $stmt->execute([$totalHours, $scheduleId]);
        
        echo json_encode([
            'success' => true, 
            'total_hours' => $totalHours
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);