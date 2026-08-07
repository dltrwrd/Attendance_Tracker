<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';
$recordIds = json_decode($_POST['record_ids'] ?? '[]', true);
$type = $_POST['type'] ?? 'absenteeism';

// Handle single record ID for reset_fire_trigger action
$recordId = $_POST['record_id'] ?? null;

if ($action !== 'reset_fire_trigger' && empty($recordIds)) {
    echo json_encode(['success' => false, 'message' => 'No records selected']);
    exit;
}

try {
    $table = ($type === 'tardiness') ? 'tardiness' : ($type === 'vto' ? 'vto_tracker' : 'absenteeism');
    
    switch ($action) {
        case 'no_need_email':
            // Mark as no need email
            $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
            $sql = "UPDATE $table SET email_sent = 1, email_sent_at = 'BYPASS' WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($recordIds);
            $updatedCount = $stmt->rowCount();
            
            echo json_encode([
                'success' => true, 
                'updated' => $updatedCount,
                'message' => "Updated $updatedCount record(s) successfully"
            ]);
            break;
            
        case 're_track_email':
            // Reset email status for re-tracking
            $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
            $sql = "UPDATE $table SET email_sent = 0, email_sent_at = NULL WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($recordIds);
            $updatedCount = $stmt->rowCount();
            
            echo json_encode([
                'success' => true, 
                'updated' => $updatedCount,
                'message' => "Updated $updatedCount record(s) successfully"
            ]);
            break;
            
        case 'reset_fire_trigger':
            // Reset fire_trigger for a single record
            if (!$recordId) {
                echo json_encode(['success' => false, 'message' => 'No record ID provided']);
                exit;
            }
            
            $sql = "UPDATE $table SET fire_trigger = NULL WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$recordId]);
            $updatedCount = $stmt->rowCount();
            
            echo json_encode([
                'success' => true, 
                'updated' => $updatedCount,
                'message' => "Fire trigger reset for record $recordId"
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }
    
} catch (PDOException $e) {
    error_log("Database error in update_attendance: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>