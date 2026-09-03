<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
header("Cache-Control: max-age=5");
header("X-Accel-Expires: 5");

$type = isset($_GET['type']) ? $_GET['type'] : 'absenteeism';

try {
    if ($type === 'absenteeism') {
        $stmt = $pdo->prepare("SELECT * FROM absenteeism ORDER BY date_of_absent ASC");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Custom sorting for timestamp (VARCHAR to time conversion)
        usort($data, function($a, $b) {
            // Convert VARCHAR timestamp to time for proper comparison
            $timeA = strtotime($a['timestamp']);
            $timeB = strtotime($b['timestamp']);
            
            // First compare by date
            $dateCompare = strcmp($a['date_of_absent'], $b['date_of_absent']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            
            // If same date, compare by time
            return $timeA - $timeB;
        });
        
    } elseif ($type === 'tardiness') {
        $stmt = $pdo->prepare("SELECT * FROM tardiness ORDER BY date_of_incident ASC");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        usort($data, function($a, $b) {
            $timeA = strtotime($a['timestamp']);
            $timeB = strtotime($b['timestamp']);
            
            $dateCompare = strcmp($a['date_of_incident'], $b['date_of_incident']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            
            return $timeA - $timeB;
        });
        
    } elseif ($type === 'vto_tracker') {
        $stmt = $pdo->prepare("SELECT * FROM vto_tracker ORDER BY shift_date ASC");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        usort($data, function($a, $b) {
            $timeA = strtotime($a['timestamp']);
            $timeB = strtotime($b['timestamp']);
            
            $dateCompare = strcmp($a['shift_date'], $b['shift_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            
            return $timeA - $timeB;
        });
        
    } elseif ($type === 'headset_tracker') {
        $stmt = $pdo->prepare("SELECT * FROM headset_tracker ORDER BY date_issued ASC");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        usort($data, function($a, $b) {
            $timeA = strtotime($a['created_at']);
            $timeB = strtotime($b['created_at']);

            $dateCompare = strcmp($a['date_issued'], $b['date_issued']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            
            return $timeA - $timeB;
        });
        
    } elseif ($type === 'incident_report') {
        $stmt = $pdo->prepare("SELECT * FROM incident_report");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'ticket') {
        $stmt = $pdo->prepare("SELECT * FROM ticket");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        throw new Exception("Invalid data type requested");
    }
    // NOTE: fire_trigger is deliberately NOT cleared here.
    //
    // This used to NULL every fired row on read, which made a fire single-shot: once any request
    // read it, it was gone from MySQL whether or not Apps Script actually managed to write the
    // note. A run that timed out, overlapped another run, or died mid-way lost the fire for good.
    //
    // Expiry is owned solely by includes/reset_fire_triggers.php (cron), which clears fires older
    // than its threshold. That leaves a retry window: a fire missed by one fetch is picked up by
    // the next. Re-firing is safe -- addNoteTofile/stackShiftLines both dedupe before writing.
    echo json_encode(['success' => true, 'data' => $data]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>