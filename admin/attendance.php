<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

// Store the current URL so external pages (like Edit Record or Send Email) can redirect back here preserving filters
$_SESSION['attendance_return_url'] = $_SERVER['REQUEST_URI'];

// ==========================================
// AJAX ACTION: Generate Absence Report
// ==========================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'generate_report') {
    header('Content-Type: application/json');
    try {
        $targetDate = $_GET['date'] ?? date('Y-m-d');
        
        // Fetch absenteeism records for the target date
        $stmt = $pdo->prepare("SELECT * FROM absenteeism WHERE date_of_absent = ?");
        $stmt->execute([$targetDate]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $reportData = [];
        $totalAbsences = 0;
        $totalPending = 0;
        $totalUncovered = 0;
        $pendingLOBs = [];
        
        foreach ($results as $row) {
            $om = '';
            
            // 1. Check direct row properties first
            $omKeys = ['operation_manager', 'operations_manager', 'ops_manager', 'om_name', 'om', 'manager'];
            foreach ($omKeys as $k) {
                if (isset($row[$k]) && trim($row[$k]) !== '') {
                    $om = trim($row[$k]);
                    break;
                }
            }
            
            // 2. Fallback to querying the employees table if OM is still missing 
            if (empty($om) && !empty($row['employee_id'])) {
                $empStmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
                $empStmt->execute([$row['employee_id']]);
                $empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($empRow) {
                    foreach ($omKeys as $k) {
                        if (isset($empRow[$k]) && trim($empRow[$k]) !== '') {
                            $om = trim($empRow[$k]);
                            break;
                        }
                    }
                }
            }
            
            if (empty($om)) {
                $om = 'UNKNOWN OM';
            }
            
            // Do the same fallback strategy for Department / LOB
            $lob = '';
            $lobKeys = ['department', 'lob', 'campaign', 'account'];
            foreach ($lobKeys as $k) {
                if (isset($row[$k]) && trim($row[$k]) !== '') {
                    $lob = trim($row[$k]);
                    break;
                }
            }
            
            if (empty($lob) && isset($empRow) && $empRow) {
                 foreach ($lobKeys as $k) {
                    if (isset($empRow[$k]) && trim($empRow[$k]) !== '') {
                        $lob = trim($empRow[$k]);
                        break;
                    }
                }
            }
            
            if (empty($lob)) $lob = 'UNKNOWN LOB';

            // Exclude CXI Mngt LOB
            if (strtolower(trim($lob)) === 'cxi mngt') {
                continue;
            }

            // Exclude SLT for BARRAMEDA, APRIL and CXI SM for MUÑOZ, JAN KENNETH
            $cleanLob = strtolower(trim($lob));
            $cleanOm = str_replace(['Ñ', 'ñ'], 'n', $om);
            $cleanOm = strtolower(trim($cleanOm));
            $cleanOm = preg_replace('/\s+/', ' ', $cleanOm);

            if ($cleanLob === 'slt' && strpos($cleanOm, 'barrameda') !== false && strpos($cleanOm, 'april') !== false) {
                continue;
            }
            if ($cleanLob === 'cxi sm' && strpos($cleanOm, 'munoz') !== false && (strpos($cleanOm, 'jan') !== false || strpos($cleanOm, 'kenneth') !== false)) {
                continue;
            }

            $cov = strtoupper(trim($row['coverage_1'] ?? ''));
            
            if (!isset($reportData[$om])) {
                $reportData[$om] = ['total' => 0, 'covered' => 0, 'lobs' => []];
            }
            if (!isset($reportData[$om]['lobs'][$lob])) {
                $reportData[$om]['lobs'][$lob] = ['covered' => 0, 'pending' => 0, 'uncovered' => 0];
            }
            
            $reportData[$om]['total']++;
            $totalAbsences++;
            
            if ($cov === 'PENDING') {
                $reportData[$om]['lobs'][$lob]['pending']++;
                $totalPending++;
                $pendingLOBs[$lob] = true;
            } elseif ($cov === 'UNCOVERED') {
                $reportData[$om]['lobs'][$lob]['uncovered']++;
                $totalUncovered++;
            } elseif (!in_array($cov, ['N/A', '-', ''])) {
                // If it's not pending, uncovered, or n/a/empty, it's considered covered
                // (Note: 'NO NEED' and 'NO NEED COVERAGE' are now counted as covered)
                $reportData[$om]['covered']++;
                $reportData[$om]['lobs'][$lob]['covered']++;
            }
        }
        
        // Build the text output
        $manila_tz = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $manila_tz);
        $dateObj = new DateTime($targetDate, $manila_tz);
        $formattedDate = $dateObj->format('F d, Y');
        $formattedTime = $now->format('h:i A');
        
        $greeting = "Good morning";
        $hour = (int)$now->format('H');
        if ($hour >= 12 && $hour < 18) {
            $greeting = "Good afternoon";
        } elseif ($hour >= 18) {
            $greeting = "Good evening";
        }

        $output = "{$greeting} boss @⁨Phay Briones-Barrameda⁩, here is the Coverage Summary for {$formattedDate} as of {$formattedTime}:\n\n";

        if (empty($reportData)) {
            $output .= "No absences recorded for " . $dateObj->format('m/d/Y') . ".";
        } else {
            foreach ($reportData as $om => $data) {
                $output .= "OM  " . strtoupper($om) . " - " . $data['covered'] . "/" . $data['total'] . " COVERED SHIFT\n";
                
                foreach ($data['lobs'] as $lob => $counts) {
                    $lobLine = strtoupper($lob);
                    $parts = [];
                    if ($counts['covered'] > 0) $parts[] = $counts['covered'] . " COVERED SHIFT";
                    if ($counts['pending'] > 0) $parts[] = $counts['pending'] . " PENDING COVERAGE";
                    if ($counts['uncovered'] > 0) $parts[] = $counts['uncovered'] . " UNCOVERED SHIFT";
                    
                    if (!empty($parts)) {
                        $output .= $lobLine . " - " . implode(" | ", $parts) . "\n";
                    }
                }
                $output .= "\n";
            }
            
            $output .= "Total Absences: " . $totalAbsences . " \n";
            
            $footerParts = [];
            if ($totalPending > 0) {
                $lobNames = implode(" / ", array_keys($pendingLOBs));
                $footerParts[] = $totalPending . " Pending Coverages for " . $lobNames;
            }
            if ($totalUncovered > 0) {
                $footerParts[] = $totalUncovered . " UNCOVERED SHIFT";
            }
            
            if (!empty($footerParts)) {
                $output .= implode(" / ", $footerParts);
            }
        }
        
        echo json_encode(['success' => true, 'report' => $output]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
// ==========================================

// ==========================================
// AJAX ACTION: Bulk Fire
// ==========================================
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'bulk_fire') {
    header('Content-Type: application/json');
    try {
        $ids = json_decode($_POST['record_ids'], true);
        $type = $_POST['type'] ?? 'absenteeism';
        $table = ($type === 'tardiness') ? 'tardiness' : ($type === 'vto' ? 'vto_tracker' : 'absenteeism');
        
        if (is_array($ids) && count($ids) > 0) {
            $manila_time = new DateTime('now', new DateTimeZone('Asia/Manila'));
            $trigger_date = $manila_time->format('Y-m-d H:i:s');
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // First get employee_ids to log
            $selStmt = $pdo->prepare("SELECT id, employee_id FROM $table WHERE id IN ($placeholders)");
            $selStmt->execute($ids);
            $recordsToFire = $selStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($recordsToFire as $rec) {
                logActivity("Fire autonote of {$rec['employee_id']} from {$type} (Bulk)");
            }
            
            $sql = "UPDATE $table SET fire_trigger = 'fire', trigger_date = ? WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $params = array_merge([$trigger_date], $ids);
            $stmt->execute($params);
            
            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No records provided']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
// ==========================================

// ==========================================
// AJAX ACTION: Bulk Queue Email (tardiness/absenteeism infraction notices)
// ==========================================
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'bulk_queue_email') {
    header('Content-Type: application/json');
    require_once '../includes/infraction_email.php';
    require_once '../includes/ensure_email_queue_table.php';
    try {
        $ids = json_decode($_POST['record_ids'], true);
        $type = $_POST['type'] ?? '';

        if (!in_array($type, ['tardiness', 'absenteeism'], true)) {
            echo json_encode(['success' => false, 'message' => 'Bulk email is only available for tardiness and absenteeism records']);
            exit;
        }
        if (!is_array($ids) || count($ids) === 0) {
            echo json_encode(['success' => false, 'message' => 'No records provided']);
            exit;
        }

        ensureEmailQueueTable($pdo);

        $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$_SESSION['user_id']]);
        $sender = $userStmt->fetch() ?: [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Only records that haven't been emailed yet, same guard as the single Send Email button
        $stmt = $pdo->prepare("SELECT * FROM $type WHERE id IN ($placeholders) AND email_sent = 0");
        $stmt->execute($ids);
        $records = $stmt->fetchAll();

        // Records that already have an unfinished queue entry (e.g. admin clicked twice
        // before the first batch finished) — skip them instead of double-queuing.
        $alreadyQueuedStmt = $pdo->prepare("SELECT source_id FROM email_queue WHERE source_type = ? AND source_id IN ($placeholders) AND status IN ('pending', 'sending')");
        $alreadyQueuedStmt->execute(array_merge([$type], $ids));
        $alreadyQueued = array_flip($alreadyQueuedStmt->fetchAll(PDO::FETCH_COLUMN));

        $insert = $pdo->prepare("INSERT INTO email_queue (to_emails, cc_emails, subject, body, source_type, source_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $queued = 0;
        $skipped = count($ids) - count($records); // already email_sent = 1

        foreach ($records as $record) {
            if (isset($alreadyQueued[$record['id']])) {
                $skipped++;
                continue;
            }
            $built = buildInfractionEmail($pdo, $type, $record, $sender);
            if (!$built) {
                $skipped++;
                continue;
            }
            $insert->execute([
                implode(',', $built['to']),
                implode(',', $built['cc']),
                $built['subject'],
                $built['body'],
                $type,
                $record['id'],
                $_SESSION['user_id'],
            ]);
            $queued++;
        }

        if ($queued > 0) {
            logActivity("Bulk-queued {$queued} infraction email(s) for {$type}");
        }

        echo json_encode(['success' => true, 'queued' => $queued, 'skipped' => $skipped]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
// ==========================================

// Get statistics
$stats = [
    'pending_emails' => 0, 'pending_emails_today' => 0,
    'pending_ir' => 0, 'pending_ir_today' => 0,
    'uncovered_shift' => 0, 'uncovered_shift_today' => 0, 
    'pending_coverage' => 0, 'pending_coverage_today' => 0
];

try {
    $currentTab = isset($_GET['tab']) ? $_GET['tab'] : 'absenteeism';
    $todayDate = date('Y-m-d');

    // Helper functions to fetch overall and today's stats safely
    $getAbsentStats = function($condition) use ($pdo, $todayDate) {
        $total = 0; $today = 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM absenteeism WHERE $condition");
            $total = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM absenteeism WHERE ($condition) AND date_of_absent = ?");
            $stmt->execute([$todayDate]);
            $today = (int)$stmt->fetchColumn();
        } catch (Exception $e) {} // Silently handle and keep values at 0 if fails
        
        return ['total' => $total, 'today' => $today];
    };

    $getTardyStats = function($condition) use ($pdo, $todayDate) {
        $total = 0; $today = 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM tardiness WHERE $condition");
            $total = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tardiness WHERE ($condition) AND date_of_incident = ?");
            $stmt->execute([$todayDate]);
            $today = (int)$stmt->fetchColumn();
        } catch (Exception $e) {} 
        
        return ['total' => $total, 'today' => $today];
    };

    // Helper function specifically to parse the 'ir_form' text for today's Pending IR count
    $getPendingIRStats = function($table) use ($pdo) {
        $total = 0; 
        $todayCount = 0;
        try {
            $stmt = $pdo->query("SELECT ir_form FROM $table WHERE ir_form = 'FOR IR' OR ir_form LIKE 'PENDING%'");
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = count($records);

            $todayMonthFull = strtoupper(date('F'));
            $todayMonthShort = strtoupper(date('M'));
            $todayDayZero = date('d');
            $todayDayNoZero = date('j');

            // Construct parts for regex (e.g., match JUNE or JUN)
            $months = preg_quote($todayMonthFull, '/') . '|' . preg_quote($todayMonthShort, '/');
            if ($todayMonthShort === 'SEP') {
                $months .= '|SEPT';
            }
            $days = $todayDayZero . '|' . $todayDayNoZero;

            // Regex pattern to extract formatting variations like "PENDING / JUNE 12", "PENDING/JUN 12", etc.
            $patternRegex = '/^PENDING\s*\/\s*(?:' . $months . ')\s+(?:' . $days . ')\b/i';

            foreach ($records as $row) {
                $ir = trim($row['ir_form']);
                if (preg_match($patternRegex, $ir)) {
                    $todayCount++;
                }
            }
        } catch (Exception $e) {}
        return ['total' => $total, 'today' => $todayCount];
    };

    // 1. Pending Emails
    if ($currentTab === 'absenteeism') {
        $s = $getAbsentStats("email_sent = 0");
        $stats['pending_emails'] = $s['total']; $stats['pending_emails_today'] = $s['today'];
    } elseif ($currentTab === 'tardiness') {
        $s = $getTardyStats("email_sent = 0");
        $stats['pending_emails'] = $s['total']; $stats['pending_emails_today'] = $s['today'];
    } else {
        $s1 = $getAbsentStats("email_sent = 0"); $s2 = $getTardyStats("email_sent = 0");
        $stats['pending_emails'] = $s1['total'] + $s2['total']; 
        $stats['pending_emails_today'] = $s1['today'] + $s2['today'];
    }
    
    // 2. Pending IR Forms (Uses string parsing on ir_form column instead of date check)
    if ($currentTab === 'absenteeism') {
        $s = $getPendingIRStats('absenteeism');
        $stats['pending_ir'] = $s['total']; $stats['pending_ir_today'] = $s['today'];
    } elseif ($currentTab === 'tardiness') {
        $s = $getPendingIRStats('tardiness');
        $stats['pending_ir'] = $s['total']; $stats['pending_ir_today'] = $s['today'];
    } else {
        $s1 = $getPendingIRStats('absenteeism'); 
        $s2 = $getPendingIRStats('tardiness');
        $stats['pending_ir'] = $s1['total'] + $s2['total']; 
        $stats['pending_ir_today'] = $s1['today'] + $s2['today'];
    }

    // 3. Pending Coverage
    $s = $getAbsentStats("coverage_1 = 'PENDING'");
    $stats['pending_coverage'] = $s['total']; $stats['pending_coverage_today'] = $s['today'];

    // 4. Uncovered Shift
    $s = $getAbsentStats("coverage_1 = 'UNCOVERED'");
    $stats['uncovered_shift'] = $s['total']; $stats['uncovered_shift_today'] = $s['today'];
    
} catch (Exception $e) {
    // If there's a wider error, keep default 0 values
}

// Handle deletion
if (isset($_GET['delete'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $id = (int)$_GET['delete'];
    $type = isset($_GET['type']) ? $_GET['type'] : 'absenteeism';
    
    $requiredPassword = "SLT@2025"; 
    $providedPassword = $_POST['delete_password'] ?? '';
    
    if (empty($providedPassword) || $providedPassword !== $requiredPassword) {
        $_SESSION['error'] = "Incorrect or missing password for deletion";
        $params = $_GET;
        unset($params['delete'], $params['delete_password']);
        $queryString = http_build_query($params);
        redirect('attendance.php?' . $queryString);
        exit();
    }
    
    try {
        $table = ($type === 'tardiness') ? 'tardiness' : ($type === 'vto' ? 'vto_tracker' : 'absenteeism');
        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success'] = "Record deleted successfully!";
        } else {
            $_SESSION['error'] = "Record not found";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting record: " . $e->getMessage();
    }
    
    $params = $_GET;
    unset($params['delete']); 
    $queryString = http_build_query($params);
    redirect('attendance.php?' . $queryString);
}

// Handle fire employee
if (isset($_GET['fire_employee'])) {
    $id = (int)$_GET['fire_employee'];
    $type = isset($_GET['type']) ? $_GET['type'] : 'absenteeism';
    
    try {
        $table = ($type === 'tardiness') ? 'tardiness' : ($type === 'vto' ? 'vto_tracker' : 'absenteeism');
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        
        if ($record) {
            $manila_time = new DateTime('now', new DateTimeZone('Asia/Manila'));
            $trigger_date = $manila_time->format('Y-m-d H:i:s');
            
            $updateStmt = $pdo->prepare("UPDATE $table SET fire_trigger = 'fire', trigger_date = ? WHERE id = ?");
            $updateStmt->execute([$trigger_date, $id]);
            
            logActivity("Fire autonote of {$record['employee_id']} from {$type}");
            $_SESSION['success'] = "Autonote has been fired!";
        } else {
            $_SESSION['error'] = "Record not found";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error autonote: " . $e->getMessage();
    }
    
    $params = $_GET;
    unset($params['fire_employee']);
    $queryString = http_build_query($params);
    redirect('attendance.php?' . $queryString);
}

if (isset($_SESSION['check_pending_ir'])) {
    $employeeId = $_SESSION['check_pending_ir'];
    unset($_SESSION['check_pending_ir']);
    
    try {
        $stmt = $pdo->prepare("SELECT id, employee_id, full_name, date_of_absent, ir_form FROM absenteeism 
                      WHERE employee_id = ? AND ir_form LIKE 'PENDING%' 
                      ORDER BY date_of_absent");
        $stmt->execute([$employeeId]);
        $pendingIRs = $stmt->fetchAll();
        
        if (count($pendingIRs) > 0) {
            $showPendingIRModal = true;
            $pendingIRData = $pendingIRs;
            
            $stmt = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ?");
            $stmt->execute([$employeeId]);
            $employee = $stmt->fetch();
            $employeeName = $employee ? $employee['full_name'] : '';
        }
    } catch (PDOException $e) {
        error_log("Error checking pending IRs: " . $e->getMessage());
    }
}

require_once '../components/layout.php';
renderHead('Attendance Tracker');
renderNavbar();
renderSidebar('attendance');

$currentTab = isset($_GET['tab']) ? $_GET['tab'] : 'absenteeism';
?>

<style>
    /* Glassmorphism utility classes and animations */
    .glass-panel {
        background: rgba(31, 41, 55, 0.65);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.08);
        transform: translateZ(0); 
        -webkit-transform: translateZ(0);
        will-change: transform, backdrop-filter;
        backface-visibility: hidden;
    }

    .glass-panel-solid {
        background: rgba(31, 41, 55, 0.85);
        border: 1px solid rgba(255, 255, 255, 0.08);
    }

    .glass-input {
        background: rgba(17, 24, 39, 0.5);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #f3f4f6;
    }
    .glass-input:focus {
        background: rgba(17, 24, 39, 0.8);
        border-color: #3b82f6;
    }
    
    .filter-button.active {
        background: rgba(14, 165, 233, 0.1) !important;
        border-color: rgba(14, 165, 233, 0.5) !important;
        box-shadow: 0 0 15px rgba(14, 165, 233, 0.15);
    }
    .filter-button.active h3, .filter-button.active p {
        color: #f3f4f6;
    }

    .scrollbar-hide::-webkit-scrollbar { display: none; }
    .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }

    /* New Animated Banner CSS */
    @keyframes bannerFadeInDown {
        0% { opacity: 0; transform: translateY(-30px); filter: blur(10px); }
        100% { opacity: 1; transform: translateY(0); filter: blur(0); }
    }
    .animate-banner {
        opacity: 0; /* Starts hidden, filled in by forwards */
        animation: bannerFadeInDown 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    
    /* Optional staggering for cards below the banner */
    @keyframes cardFadeInUp {
        0% { opacity: 0; transform: translateY(20px); }
        100% { opacity: 1; transform: translateY(0); }
    }
    .animate-card-1 { animation: cardFadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.1s forwards; opacity: 0; }
    .animate-card-2 { animation: cardFadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards; opacity: 0; }
    .animate-card-3 { animation: cardFadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.3s forwards; opacity: 0; }
    .animate-card-4 { animation: cardFadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.4s forwards; opacity: 0; }
</style>

<div class="pt-2 min-h-screen relative bg-gray-900">
    <main class="p-6 relative z-10 max-w-8xl mx-auto">
        
        <!-- Animated Glassmorphic Header Banner Section -->
        <div class="glass-panel bg-gradient-to-br from-gray-800/90 to-gray-900/90 p-6 md:p-8 rounded-3xl mb-8 shadow-2xl relative overflow-hidden animate-banner border-l-4 border-l-primary-500">
            <!-- Decorative light bloom -->
            <div class="absolute -right-20 -top-20 w-64 h-64 bg-primary-500/10 rounded-full blur-3xl pointer-events-none"></div>
            
            <div class="flex flex-col xl:flex-row justify-between items-start xl:items-center gap-6 relative z-10">
                <!-- Title Area -->
                <div class="flex items-center gap-5">
                    <div class="w-14 h-14 rounded-2xl bg-primary-500/20 flex items-center justify-center border border-primary-500/30 shadow-inner flex-shrink-0">
                        <i class="fas fa-clipboard-user text-2xl text-primary-400"></i>
                    </div>
                    <div>
                        <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-white drop-shadow-md">Attendance Tracker</h1>
                        <p class="text-gray-400 text-sm mt-1.5 font-medium">Manage and track employee attendance, VTO, and IRs with real-time updates</p>
                    </div>
                </div>
                
                <!-- Action Buttons Area -->
                <div class="flex flex-wrap items-center gap-3 w-full xl:w-auto mt-2 xl:mt-0">
                    <button id="clubInIRBtn" class="hidden glass-panel hover:bg-purple-600/80 text-purple-300 hover:text-white px-4 py-2.5 rounded-xl border-purple-500/30 transition-all shadow-lg text-sm font-medium flex items-center">
                        <i class="fas fa-object-group mr-2"></i> Club in IR
                    </button>
                    <button id="noNeedEmailBtn" class="hidden glass-panel hover:bg-yellow-600/80 text-yellow-300 hover:text-white px-4 py-2.5 rounded-xl border-yellow-500/30 transition-all shadow-lg text-sm font-medium flex items-center">
                        <i class="fas fa-envelope mr-2"></i> No Need Email
                    </button>
                    <button id="reTrackEmailBtn" class="hidden glass-panel hover:bg-blue-600/80 text-blue-300 hover:text-white px-4 py-2.5 rounded-xl border-blue-500/30 transition-all shadow-lg text-sm font-medium flex items-center">
                        <i class="fas fa-redo-alt mr-2"></i> Re-track Email
                    </button>

                    <button id="bulkEmailBtn" class="hidden glass-panel hover:bg-indigo-600/80 text-indigo-300 hover:text-white px-4 py-2.5 rounded-xl border-indigo-500/30 transition-all shadow-lg text-sm font-medium flex items-center">
                        <i class="fas fa-envelope-open-text mr-2"></i> Bulk Email
                    </button>

                    <button id="bulkFireBtn" class="hidden glass-panel hover:bg-red-600/80 text-red-300 hover:text-white px-4 py-2.5 rounded-xl border-red-500/30 transition-all shadow-lg text-sm font-medium flex items-center">
                        <i class="fas fa-fire mr-2"></i> Bulk Fire
                    </button>
                    
                    <!-- NEW COPY ABSENCE REPORT BUTTON -->
                    <?php if ($currentTab === 'absenteeism'): ?>
                    <button id="copyReportBtn" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2.5 rounded-xl shadow-lg shadow-emerald-900/30 transition-all flex items-center font-medium text-sm">
                        <i class="fas fa-copy mr-2"></i> Copy Absence Report
                    </button>
                    <?php endif; ?>
                    
                    <a href="<?= $currentTab === 'vto' ? 'vto_form.php' : 'attendance_form.php' ?>?action=create&type=<?= $currentTab ?>" class="bg-primary-600 hover:bg-primary-500 text-white px-5 py-2.5 rounded-xl shadow-lg shadow-primary-900/30 transition-all flex items-center font-medium text-sm">
                        <i class="fas fa-plus mr-2"></i> Add New Record
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            
            <!-- Pending Emails -->
            <button type="button" name="filter" value="pending_emails" 
                class="animate-card-1 filter-button glass-panel rounded-2xl p-6 shadow-xl hover:bg-gray-800/80 transition-all duration-200 text-left group overflow-hidden relative <?= ($currentTab === 'vto') ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= ($currentTab === 'vto') ? 'disabled' : '' ?>>
                <div class="absolute right-0 top-0 h-24 w-24 bg-primary-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative z-10 flex items-center justify-between">
                    <div>
                        <h3 class="text-gray-400 text-xs font-bold uppercase tracking-wider">Pending Emails</h3>
                        <div class="mt-1">
                            <div class="flex items-baseline gap-2">
                                <span class="text-3xl font-bold text-primary-400"><?= $stats['pending_emails_today'] ?></span>
                                <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Today</span>
                            </div>
                            <div class="flex items-baseline gap-1.5 opacity-80">
                                <span class="text-sm font-bold text-gray-300"><?= $stats['pending_emails'] ?></span>
                                <span class="text-[9px] text-gray-500 font-semibold uppercase tracking-wider">Overall</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Emails not yet sent</p>
                    </div>
                    <div class="bg-primary-500/20 p-3 rounded-xl border border-primary-500/20 shadow-inner group-hover:bg-primary-500/30 transition-colors">
                        <i class="fas fa-envelope text-primary-400 text-xl"></i>
                    </div>
                </div>
            </button>
        
            <!-- Pending IR Forms -->
            <button type="button" name="filter" value="pending_ir" 
                class="animate-card-2 filter-button glass-panel rounded-2xl p-6 shadow-xl hover:bg-gray-800/80 transition-all duration-200 text-left group overflow-hidden relative <?= ($currentTab === 'vto') ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= ($currentTab === 'vto') ? 'disabled' : '' ?>>
                <div class="absolute right-0 top-0 h-24 w-24 bg-yellow-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative z-10 flex items-center justify-between">
                    <div>
                        <h3 class="text-gray-400 text-xs font-bold uppercase tracking-wider">Pending IR Forms</h3>
                        <div class="mt-1">
                            <div class="flex items-baseline gap-2">
                                <span class="text-3xl font-bold text-yellow-400"><?= $stats['pending_ir_today'] ?></span>
                                <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Today</span>
                            </div>
                            <div class="flex items-baseline gap-1.5 opacity-80">
                                <span class="text-sm font-bold text-gray-300"><?= $stats['pending_ir'] ?></span>
                                <span class="text-[9px] text-gray-500 font-semibold uppercase tracking-wider">Overall</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Forms not submitted</p>
                    </div>
                    <div class="bg-yellow-500/20 p-3 rounded-xl border border-yellow-500/20 shadow-inner group-hover:bg-yellow-500/30 transition-colors">
                        <i class="fas fa-file-alt text-yellow-400 text-xl"></i>
                    </div>
                </div>
            </button>
        
            <!-- Pending Coverage -->
            <button type="button" name="filter" value="pending_coverage" 
                class="animate-card-3 filter-button glass-panel rounded-2xl p-6 shadow-xl hover:bg-gray-800/80 transition-all duration-200 text-left group overflow-hidden relative <?= ($currentTab === 'tardiness' || $currentTab === 'vto') ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= ($currentTab === 'tardiness' || $currentTab === 'vto') ? 'disabled' : '' ?>>
                <div class="absolute right-0 top-0 h-24 w-24 bg-orange-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative z-10 flex items-center justify-between">
                    <div>
                        <h3 class="text-gray-400 text-xs font-bold uppercase tracking-wider">Pending Coverage</h3>
                        <div class="mt-1">
                            <div class="flex items-baseline gap-2">
                                <span class="text-3xl font-bold text-orange-400"><?= $stats['pending_coverage_today'] ?></span>
                                <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Today</span>
                            </div>
                            <div class="flex items-baseline gap-1.5 opacity-80">
                                <span class="text-sm font-bold text-gray-300"><?= $stats['pending_coverage'] ?></span>
                                <span class="text-[9px] text-gray-500 font-semibold uppercase tracking-wider">Overall</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Shift not yet covered</p>
                    </div>
                    <div class="bg-orange-500/20 p-3 rounded-xl border border-orange-500/20 shadow-inner group-hover:bg-orange-500/30 transition-colors">
                        <i class="fas fa-clock text-orange-400 text-xl"></i>
                    </div>
                </div>
            </button>
        
            <!-- Uncovered Shift -->
            <button type="button" name="filter" value="uncovered_shift" 
                class="animate-card-4 filter-button glass-panel rounded-2xl p-6 shadow-xl hover:bg-gray-800/80 transition-all duration-200 text-left group overflow-hidden relative <?= ($currentTab === 'tardiness' || $currentTab === 'vto') ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= ($currentTab === 'tardiness' || $currentTab === 'vto') ? 'disabled' : '' ?>>
                <div class="absolute right-0 top-0 h-24 w-24 bg-red-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative z-10 flex items-center justify-between">
                    <div>
                        <h3 class="text-gray-400 text-xs font-bold uppercase tracking-wider">Uncovered Shift</h3>
                        <div class="mt-1">
                            <div class="flex items-baseline gap-2">
                                <span class="text-3xl font-bold text-red-400"><?= $stats['uncovered_shift_today'] ?></span>
                                <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Today</span>
                            </div>
                            <!-- Invisible div to maintain consistent button height relative to other cards -->
                            <div class="flex items-baseline gap-1.5 invisible">
                                <span class="text-sm font-bold">0</span>
                                <span class="text-[9px] font-semibold uppercase">Overall</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Pending unstaffed shifts</p>
                    </div>
                    <div class="bg-red-500/20 p-3 rounded-xl border border-red-500/20 shadow-inner group-hover:bg-red-500/30 transition-colors">
                        <i class="fas fa-book text-red-400 text-xl"></i>
                    </div>
                </div>
            </button>
        </div>

        <form method="get" action="attendance.php" id="mainFilterForm">
            <input type="hidden" name="tab" value="<?= $currentTab ?>">
            <?php if (!empty($_GET['search'])): ?><input type="hidden" name="search" value="<?= htmlspecialchars($_GET['search']) ?>"><?php endif; ?>
            <?php if (!empty($_GET['from'])): ?><input type="hidden" name="from" value="<?= htmlspecialchars($_GET['from']) ?>"><?php endif; ?>
            <?php if (!empty($_GET['to'])): ?><input type="hidden" name="to" value="<?= htmlspecialchars($_GET['to']) ?>"><?php endif; ?>
            <?php if (!empty($_GET['dept'])): ?><input type="hidden" name="dept" value="<?= htmlspecialchars($_GET['dept']) ?>"><?php endif; ?>
            <?php if (!empty($_GET['cov'])): ?><input type="hidden" name="cov" value="<?= htmlspecialchars($_GET['cov']) ?>"><?php endif; ?>
            <?php if (!empty($_GET['ir'])): ?><input type="hidden" name="ir" value="<?= htmlspecialchars($_GET['ir']) ?>"><?php endif; ?>
            <?php if (!empty($_GET['filter'])): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($_GET['filter']) ?>"><?php endif; ?>
            <?php if (!empty($_GET['page'])): ?><input type="hidden" name="page" value="<?= htmlspecialchars($_GET['page']) ?>"><?php endif; ?>
        </form>

        <!-- Glassmorphic Tabs -->
        <div class="mb-8 flex space-x-1 glass-panel p-1 rounded-xl w-max shadow-lg">
            <a href="?tab=absenteeism" class="<?= $currentTab === 'absenteeism' ? 'bg-primary-600/20 text-primary-400 shadow-sm border border-primary-500/30' : 'text-gray-400 hover:text-gray-200 hover:bg-gray-700/50 border border-transparent' ?> px-6 py-2.5 rounded-lg text-sm font-semibold transition-all duration-200">
                Absenteeism
            </a>
            <a href="?tab=tardiness" class="<?= $currentTab === 'tardiness' ? 'bg-primary-600/20 text-primary-400 shadow-sm border border-primary-500/30' : 'text-gray-400 hover:text-gray-200 hover:bg-gray-700/50 border border-transparent' ?> px-6 py-2.5 rounded-lg text-sm font-semibold transition-all duration-200">
                Tardiness
            </a>
            <a href="?tab=vto" class="<?= $currentTab === 'vto' ? 'bg-primary-600/20 text-primary-400 shadow-sm border border-primary-500/30' : 'text-gray-400 hover:text-gray-200 hover:bg-gray-700/50 border border-transparent' ?> px-6 py-2.5 rounded-lg text-sm font-semibold transition-all duration-200">
                VTO Tracker
            </a>
        </div>

        <?php renderAlert(); ?>
        
        <!-- Filter Container -->
        <div class="glass-panel p-5 rounded-2xl mb-8 shadow-xl relative z-20">
            <div class="flex items-center justify-between mb-4 px-1">
                <h3 class="text-gray-300 font-semibold text-sm flex items-center gap-2">
                    <i class="fas fa-filter text-gray-500"></i> Record Filters
                </h3>
            </div>
            
            <div class="overflow-x-auto">
            <div class="grid gap-4 items-center" style="grid-template-columns: 2fr 1.2fr 1.2fr 1.5fr <?= ($currentTab !== 'vto') ? '1.5fr ' : '' ?>1.8fr auto; min-width: 1000px;">   
                <div class="relative">
                    <input type="text" id="searchInput" 
                        class="glass-input w-full pl-10 pr-4 py-2.5 rounded-xl shadow-inner transition-colors duration-200 text-sm"
                        placeholder="Search employee ID or name..."
                        value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                    <div class="absolute left-3 top-3 text-gray-500"><i class="fas fa-search"></i></div>
                </div>
                
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500"><i class="fas fa-calendar-alt"></i></div>
                    <input type="date" id="dateFrom" title="Date From"
                        class="glass-input w-full pl-10 pr-4 py-2.5 rounded-xl shadow-inner transition-colors duration-200 text-sm"
                        value="<?= isset($_GET['from']) ? htmlspecialchars($_GET['from']) : '' ?>">
                </div>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500"><i class="fas fa-calendar-check"></i></div>
                    <input type="date" id="dateTo" title="Date To"
                        class="glass-input w-full pl-10 pr-4 py-2.5 rounded-xl shadow-inner transition-colors duration-200 text-sm"
                        value="<?= isset($_GET['to']) ? htmlspecialchars($_GET['to']) : '' ?>">
                </div>
                
                <div class="relative">
                    <select id="departmentFilter" class="glass-input w-full px-4 py-2.5 rounded-xl appearance-none shadow-inner transition-colors duration-200 text-sm">
                        <option value="">All Departments</option>
                        <?php
                        $deptDateFrom = isset($_GET['from']) ? $_GET['from'] : '';
                        $deptDateTo   = isset($_GET['to'])   ? $_GET['to']   : '';
                        $deptTable    = ($currentTab === 'tardiness') ? 'tardiness' : (($currentTab === 'absenteeism') ? 'absenteeism' : null);
                        if ($deptTable) {
                            $deptDateField = ($currentTab === 'tardiness') ? 'date_of_incident' : 'date_of_absent';
                            $deptWhere  = [];
                            $deptParams = [];
                            if (!empty($deptDateFrom)) { $deptWhere[] = "$deptDateField >= :df"; $deptParams[':df'] = $deptDateFrom; }
                            if (!empty($deptDateTo))   { $deptWhere[] = "$deptDateField <= :dt"; $deptParams[':dt'] = $deptDateTo; }
                            $deptSql = "SELECT DISTINCT department FROM $deptTable" . (!empty($deptWhere) ? ' WHERE ' . implode(' AND ', $deptWhere) : '') . " ORDER BY department";
                            $deptStmt = $pdo->prepare($deptSql);
                            foreach ($deptParams as $k => $v) { $deptStmt->bindValue($k, $v); }
                            $deptStmt->execute();
                            while ($row = $deptStmt->fetch()) {
                                $selected = (isset($_GET['dept']) && $_GET['dept'] === $row['department']) ? 'selected' : '';
                                echo '<option value="'.htmlspecialchars($row['department']).'" '.$selected.'>'.htmlspecialchars($row['department']).'</option>';
                            }
                        } else {
                            $stmt = $pdo->query("SELECT DISTINCT department FROM absenteeism UNION SELECT DISTINCT department FROM tardiness ORDER BY department");
                            while ($row = $stmt->fetch()) {
                                $selected = (isset($_GET['dept']) && $_GET['dept'] === $row['department']) ? 'selected' : '';
                                echo '<option value="'.htmlspecialchars($row['department']).'" '.$selected.'>'.htmlspecialchars($row['department']).'</option>';
                            }
                        }
                        ?>
                    </select>
                    <div class="absolute right-3 top-3 text-gray-500 pointer-events-none"><i class="fas fa-chevron-down text-xs"></i></div>
                </div>
                
                <?php if ($currentTab !== 'vto'): ?>
                <div class="relative">
                    <select id="coverageFilter" class="glass-input w-full px-4 py-2.5 rounded-xl appearance-none shadow-inner transition-colors duration-200 text-sm">
                        <option value="">All Coverage</option>
                        <?php
                        $filterValues = ['UNCOVERED', 'PENDING', 'NO NEED', 'N/A', '-'];
                        foreach ($filterValues as $value) {
                            $selected = (isset($_GET['cov']) && $_GET['cov'] === $value) ? 'selected' : '';
                            echo '<option value="'.htmlspecialchars($value).'" '.$selected.'>'.htmlspecialchars($value).'</option>';
                        }
                        ?>
                    </select>
                    <div class="absolute right-3 top-3 text-gray-500 pointer-events-none"><i class="fas fa-chevron-down text-xs"></i></div>
                </div>
                <?php endif; ?>

                <div class="relative z-[100] <?= ($currentTab === 'vto') ? 'opacity-50 cursor-not-allowed' : '' ?>" id="irFilterWrapper">
                    <!-- Trigger Button -->
                    <button type="button" id="irFilterBtn" <?= ($currentTab === 'vto') ? 'disabled' : '' ?>
                        class="glass-input w-full px-4 py-2.5 rounded-xl shadow-inner transition-colors duration-200 text-sm flex items-center justify-between gap-2"
                        onclick="toggleIrDropdown(event)">
                        <span id="irFilterLabel" class="truncate text-gray-300">All IR Forms</span>
                        <i class="fas fa-chevron-down text-gray-500 text-xs flex-shrink-0 transition-transform duration-200" id="irFilterChevron"></i>
                    </button>

                    <!-- Dropdown Panel -->
                    <div id="irFilterDropdown" class="hidden bg-gray-900 border border-gray-700/60 rounded-xl shadow-2xl overflow-hidden" style="min-width:240px;">
                        <!-- Search inside dropdown -->
                        <div class="px-3 pt-3 pb-2 border-b border-gray-700/40">
                            <input type="text" id="irSearchInput" placeholder="Search IR form..."
                                class="w-full bg-gray-800/60 border border-gray-700/50 rounded-lg px-3 py-1.5 text-xs text-gray-200 placeholder-gray-500 outline-none focus:ring-1 focus:ring-primary-500"
                                oninput="filterIrOptions(this.value)">
                        </div>
                        <!-- Options list -->
                        <div id="irOptionsList" class="overflow-y-auto custom-scrollbar py-1" style="max-height:220px;">
                            <?php
                            $selectedIrs  = isset($_GET['ir']) ? explode(',', $_GET['ir']) : [];
                            $irDateFrom   = isset($_GET['from']) ? $_GET['from'] : '';
                            $irDateTo     = isset($_GET['to'])   ? $_GET['to']   : '';

                            if ($currentTab === 'tardiness' || $currentTab === 'absenteeism') {
                                $irTable      = ($currentTab === 'tardiness') ? 'tardiness' : 'absenteeism';
                                $irDateField  = ($currentTab === 'tardiness') ? 'date_of_incident' : 'date_of_absent';
                                $irWhere      = ["ir_form IS NOT NULL", "ir_form != ''"];
                                $irParams     = [];
                                if (!empty($irDateFrom)) {
                                    $irWhere[]              = "$irDateField >= :ir_date_from";
                                    $irParams[':ir_date_from'] = $irDateFrom;
                                }
                                if (!empty($irDateTo)) {
                                    $irWhere[]              = "$irDateField <= :ir_date_to";
                                    $irParams[':ir_date_to'] = $irDateTo;
                                }
                                $irSql  = "SELECT DISTINCT ir_form FROM $irTable WHERE " . implode(' AND ', $irWhere);
                                $irStmt = $pdo->prepare($irSql);
                                foreach ($irParams as $k => $v) { $irStmt->bindValue($k, $v); }
                                $irStmt->execute();
                                $allIrForms = $irStmt->fetchAll(PDO::FETCH_COLUMN);

                                $stdOpts    = [];
                                $noNeedOpts = [];
                                $pendOpts   = [];
                                foreach ($allIrForms as $irForm) {
                                    if ($currentTab === 'tardiness') {
                                        if (in_array($irForm, ['FOR IR', 'FOR ACCUMULATION', 'PENDING'])) {
                                            $stdOpts[$irForm] = $irForm;
                                        } elseif (strpos(strtoupper(trim($irForm)), 'NO NEED') === 0) {
                                            $noNeedOpts[$irForm] = $irForm;
                                        } elseif (preg_match('/PENDING \/ ([A-Z]{3,4} [0-9]{1,2})/', $irForm, $m)) {
                                            $pk = 'PENDING / ' . $m[1];
                                            $pendOpts[$pk] = $pk;
                                        }
                                    } else {
                                        if ($irForm === 'FOR IR') {
                                            $stdOpts[$irForm] = $irForm;
                                        } elseif (strpos(strtoupper(trim($irForm)), 'NO NEED') === 0) {
                                            $noNeedOpts[$irForm] = $irForm;
                                        } elseif (preg_match('/PENDING \/ ([A-Z]{3,4} [0-9]{1,2})/', $irForm, $m)) {
                                            $pk = 'PENDING / ' . $m[1];
                                            $pendOpts[$pk] = $pk;
                                        }
                                    }
                                }
                                krsort($pendOpts); ksort($noNeedOpts);
                                $allRendered = array_merge(array_values($stdOpts), array_values($pendOpts), array_values($noNeedOpts));
                                $allRendered = array_unique($allRendered);

                                if (empty($allRendered)) {
                                    echo '<p class="text-xs text-gray-500 px-4 py-3">No IR forms found.</p>';
                                } else {
                                    foreach ($allRendered as $val) {
                                        $checked = in_array($val, $selectedIrs) ? 'checked' : '';
                                        $safeVal = htmlspecialchars($val);
                                        echo "<label class=\"ir-option flex items-center gap-2.5 px-3 py-1.5 cursor-pointer hover:bg-white/5 rounded transition-colors text-xs text-gray-300 group\" data-label=\"$safeVal\">"
                                           . "<input type=\"checkbox\" class=\"ir-checkbox rounded border-gray-600 bg-gray-800 text-primary-500 focus:ring-primary-500 focus:ring-offset-0 focus:ring-1\" value=\"$safeVal\" $checked>"
                                           . "<span class=\"truncate\">$safeVal</span>"
                                           . "</label>";
                                    }
                                }
                            }
                            ?>
                        </div>
                        <!-- Footer actions -->
                        <div class="px-3 py-2 border-t border-gray-700/40 flex justify-between items-center">
                            <span id="irSelCount" class="text-xs text-gray-500">0 selected</span>
                            <button type="button" onclick="clearIrFilter()" class="text-xs text-red-400 hover:text-red-300 transition-colors">Clear</button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 shrink-0">
                    <button type="button" onclick="exportToCsv()" class="px-4 py-2.5 bg-green-500/10 hover:bg-green-500/20 text-green-400 hover:text-green-300 border border-green-500/20 hover:border-green-500/40 rounded-xl transition-colors duration-200 flex items-center justify-center gap-2 text-sm font-medium shadow-inner">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button type="button" id="clearFiltersBtn" class="px-4 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 hover:text-red-300 border border-red-500/20 hover:border-red-500/40 rounded-xl transition-colors duration-200 flex items-center justify-center gap-2 text-sm font-medium shadow-inner">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>
            </div>
        </div>

        <div id="attendanceTableContainer" class="glass-panel-solid rounded-2xl overflow-hidden shadow-2xl relative z-10">
            <?php include 'partials/attendance_table.php'; ?>
        </div>

        <?php if (isset($showPendingIRModal) && $showPendingIRModal): ?>
        <div id="pendingIRModal" class="fixed inset-0 bg-gray-900/80 backdrop-blur-sm flex items-center justify-center z-50 transition-opacity">
            <div class="glass-panel-solid rounded-2xl shadow-2xl w-full max-w-4xl transform scale-100 transition-transform">
                <div class="px-8 py-8">
                    <h3 class="text-xl font-bold text-white mb-2">Pending IR Forms Found for <?= htmlspecialchars($employeeName) ?></h3>
                    <p class="text-gray-400 mb-6 text-sm">This agent has <span class="text-yellow-400 font-bold"><?= count($pendingIRData) ?></span> pending IR form(s). Would you like to update them all?</p>
                    
                    <div class="mb-6">
                        <label for="irFormUpdate" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">New IR Form Status</label>
                        <input type="text" id="irFormUpdate" class="glass-input w-full px-4 py-3 rounded-xl shadow-inner focus:ring-2 focus:ring-primary-500 focus:outline-none transition-all" 
                            placeholder="e.g., PENDING / Current Status">
                    </div>
                    
                    <div class="overflow-x-auto mb-8 bg-gray-900/50 rounded-xl border border-gray-700/50">
                        <table class="min-w-full divide-y divide-gray-700/50">
                            <thead class="bg-gray-800/50">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Employee ID</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Full Name</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Current Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-700/50">
                                <?php foreach ($pendingIRData as $ir): ?>
                                <tr class="hover:bg-gray-800/30 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-200"><?= htmlspecialchars($ir['employee_id']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300"><?= htmlspecialchars($ir['full_name']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400"><?= date('M d, Y', strtotime($ir['date_of_absent'])) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-400"><?= htmlspecialchars($ir['ir_form']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="flex justify-end space-x-4">
                        <button onclick="closePendingIRModal()" class="px-6 py-2.5 bg-gray-700/50 text-gray-300 rounded-xl hover:bg-gray-600 hover:text-white border border-gray-600 transition-all font-medium">
                            Skip
                        </button>
                        <button onclick="updateAllPendingIRs()" class="px-6 py-2.5 bg-primary-600 text-white rounded-xl hover:bg-primary-500 shadow-lg shadow-primary-900/30 transition-all font-medium">
                            Update All
                        </button>
                    </div>
                </div>
            </div>
        </div>

<script>
function closePendingIRModal() { document.getElementById('pendingIRModal').remove(); }

function updateAllPendingIRs() {
    const newStatus = document.getElementById('irFormUpdate').value;
    if (!newStatus.trim()) { alert('Please enter a valid IR form status'); return; }
    
    fetch('../includes/update_pending_irs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employee_id: '<?= $employeeId ?>', new_status: newStatus })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Successfully updated ' + data.updated + ' records');
            closePendingIRModal();
            if (data.has_pending) location.reload(); 
        } else alert('Error: ' + data.message);
    }).catch(error => { console.error('Error:', error); alert('An error occurred while updating records'); });
}
</script>
<?php endif; ?>
    </main>
</div>

<script>

// NEW FUNCTION: Handle Column Visibility Toggling
function initColumnToggler(tableType) {
    const table = document.querySelector('#attendanceTableContainer table');
    const toggleContainer = document.getElementById('columnToggleContainer');
    
    if (!table || !toggleContainer) return;

    const storageKey = 'attendance_hidden_cols_' + tableType;
    let hiddenCols = JSON.parse(localStorage.getItem(storageKey) || '[]');

    const thead = table.querySelector('thead tr');
    const headers = thead.querySelectorAll('th');
    
    // Function to apply hidden classes to table columns
    function applyHiddenColumns(hiddenArray) {
        const rows = table.querySelectorAll('tr');
        rows.forEach(row => {
            const cells = row.children;
            Array.from(cells).forEach((cell, index) => {
                if (hiddenArray.includes(index)) {
                    cell.classList.add('hidden');
                } else {
                    cell.classList.remove('hidden');
                }
            });
        });
    }

    // Apply initially
    applyHiddenColumns(hiddenCols);

    // Build the Dropdown UI for column selection
    let html = `
        <button id="colToggleBtn" class="bg-gray-700/80 hover:bg-gray-600 text-gray-300 px-3 py-1.5 rounded-lg text-sm font-medium flex items-center gap-2 border border-gray-600 transition-colors">
            <i class="fas fa-columns"></i> Columns <i class="fas fa-chevron-down text-[10px] ml-1"></i>
        </button>
        <div id="colToggleDropdown" class="hidden absolute right-0 mt-2 w-64 bg-gray-800 border border-gray-700 rounded-xl shadow-2xl z-50 p-2 max-h-[300px] overflow-y-auto custom-scrollbar origin-top-right">
            <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 px-2 pt-1">Toggle Columns</div>
    `;
    
    headers.forEach((th, index) => {
        // Skip checkbox column (0) and actions column (last one)
        if (index === 0 || index === headers.length - 1) return;
        
        const colName = th.textContent.trim();
        if (!colName) return; // skip empty headers

        const isChecked = !hiddenCols.includes(index) ? 'checked' : '';
        
        html += `
            <label class="flex items-center gap-3 p-2 hover:bg-gray-700/50 rounded-lg cursor-pointer text-sm text-gray-300 transition-colors">
                <input type="checkbox" class="col-toggle-cb rounded border-gray-600 text-primary-500 focus:ring-primary-500 bg-gray-900" data-col-index="${index}" ${isChecked}>
                <span class="truncate" title="${colName}">${colName}</span>
            </label>
        `;
    });
    html += '</div>';
    toggleContainer.innerHTML = html;

    // Add Event Listeners for the toggler
    const btn = document.getElementById('colToggleBtn');
    const dropdown = document.getElementById('colToggleDropdown');
    
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
    });
    
    document.addEventListener('click', (e) => {
        if (!toggleContainer.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });

    document.querySelectorAll('.col-toggle-cb').forEach(cb => {
        cb.addEventListener('change', function() {
            const colIndex = parseInt(this.dataset.colIndex);
            if (this.checked) {
                // Remove from hidden array
                hiddenCols = hiddenCols.filter(i => i !== colIndex);
            } else {
                // Add to hidden array
                if (!hiddenCols.includes(colIndex)) hiddenCols.push(colIndex);
            }
            // Save to localStorage and apply
            localStorage.setItem(storageKey, JSON.stringify(hiddenCols));
            applyHiddenColumns(hiddenCols);
        });
    });
}

// ── IR Dropdown: global functions (called by inline onclick attrs) ──────────
function getSelectedIrValues() {
    return Array.from(document.querySelectorAll('#irOptionsList .ir-checkbox:checked'))
                .map(cb => cb.value);
}

function updateIrLabel() {
    const sel     = getSelectedIrValues();
    const label   = document.getElementById('irFilterLabel');
    const counter = document.getElementById('irSelCount');
    if (label)   label.textContent   = sel.length === 0 ? 'All IR Forms' : sel.length + ' selected';
    if (counter) counter.textContent = sel.length + ' selected';
}

function positionIrDropdown() {
    const btn = document.getElementById('irFilterBtn');
    const dd  = document.getElementById('irFilterDropdown');
    if (!btn || !dd) return;
    const rect = btn.getBoundingClientRect();
    const scrollY = window.scrollY || window.pageYOffset;
    const scrollX = window.scrollX || window.pageXOffset;
    // Use absolute (document) coords so backdrop-filter/transform on parents don't break it
    dd.style.position = 'absolute';
    dd.style.top      = (rect.bottom + scrollY + 4) + 'px';
    dd.style.left     = (rect.left + scrollX) + 'px';
    dd.style.width    = rect.width + 'px';
    dd.style.zIndex   = '99999';
    dd.style.minWidth = '240px';
}

function toggleIrDropdown(e) {
    e.stopPropagation();
    const dd      = document.getElementById('irFilterDropdown');
    const chevron = document.getElementById('irFilterChevron');
    if (!dd) return;
    const isHidden = dd.classList.contains('hidden');
    if (isHidden) {
        // Teleport to body so no overflow/transform parent clips it
        if (dd.parentElement !== document.body) {
            document.body.appendChild(dd);
        }
        dd.classList.remove('hidden');
        positionIrDropdown();
        if (chevron) chevron.style.transform = 'rotate(180deg)';
        const si = document.getElementById('irSearchInput');
        if (si) si.focus();
    } else {
        dd.classList.add('hidden');
        if (chevron) chevron.style.transform = '';
    }
}


function filterIrOptions(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('#irOptionsList .ir-option').forEach(lbl => {
        lbl.style.display = (lbl.dataset.label || '').toLowerCase().includes(q) ? '' : 'none';
    });
}

// clearIrFilter is exposed globally; it delegates to handleDropdownFilter
// which is stored in window._irHandleDropdown once DOMContentLoaded runs.
function clearIrFilter() {
    document.querySelectorAll('#irOptionsList .ir-checkbox').forEach(cb => cb.checked = false);
    updateIrLabel();
    if (typeof window._irHandleDropdown === 'function') window._irHandleDropdown();
}

function exportToCsv() {
    const urlParams = new URLSearchParams(window.location.search);
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'partials/export_csv.php';

    const appendInput = (name, value) => {
        if (value === null || value === undefined) return;
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    appendInput('type', urlParams.get('tab') || 'absenteeism');
    
    const searchVal = document.getElementById('searchInput') ? document.getElementById('searchInput').value : '';
    if (searchVal) appendInput('search', searchVal);
    
    const dateFrom = document.getElementById('dateFrom') ? document.getElementById('dateFrom').value : '';
    if (dateFrom) appendInput('date_from', dateFrom);
    
    const dateTo = document.getElementById('dateTo') ? document.getElementById('dateTo').value : '';
    if (dateTo) appendInput('date_to', dateTo);
    
    const dept = document.getElementById('departmentFilter') ? document.getElementById('departmentFilter').value : '';
    if (dept) appendInput('department', dept);
    
    const cov = document.getElementById('coverageFilter') ? document.getElementById('coverageFilter').value : '';
    if (cov) appendInput('coverage', cov);
    
    const irs = getSelectedIrValues();
    if (irs.length > 0) appendInput('ir_filter', irs.join(','));
    
    const cardFilter = urlParams.get('filter');
    if (cardFilter) appendInput('filter', cardFilter);

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
// ────────────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {
    
    // Copy Absence Report Logic
    const copyReportBtn = document.getElementById('copyReportBtn');
    if (copyReportBtn) {
        copyReportBtn.addEventListener('click', function() {
            openCopyReportModal();
        });
    }

    const modalGenerateReportBtn = document.getElementById('modalGenerateReportBtn');
    const reportDateInput = document.getElementById('reportDateInput');
    
    if (reportDateInput) {
        // Open the native date picker when clicking anywhere on the input box
        reportDateInput.addEventListener('click', function() {
            try { this.showPicker(); } catch (e) {}
        });
        reportDateInput.addEventListener('focus', function() {
            try { this.showPicker(); } catch (e) {}
        });
    }

    if (modalGenerateReportBtn) {
        modalGenerateReportBtn.addEventListener('click', function() {
            const btn = this;
            const originalHTML = btn.innerHTML;
            const selectedDate = document.getElementById('reportDateInput').value;
            
            // Show loading state
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Generating...';
            btn.disabled = true;
            
            fetch(`attendance.php?ajax_action=generate_report&date=${selectedDate}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        navigator.clipboard.writeText(data.report).then(() => {
                            btn.innerHTML = '<i class="fas fa-check mr-2"></i> Copied to Clipboard!';
                            btn.classList.replace('bg-emerald-600', 'bg-emerald-500');
                            
                            setTimeout(() => {
                                btn.innerHTML = originalHTML;
                                btn.classList.replace('bg-emerald-500', 'bg-emerald-600');
                                btn.disabled = false;
                                closeCopyReportModal();
                            }, 1500);
                        }).catch(err => {
                            console.error('Clipboard write failed:', err);
                            alert('Failed to copy to clipboard. Check permissions.');
                            resetBtn();
                        });
                    } else {
                        alert('Error generating report: ' + (data.message || 'Unknown error'));
                        resetBtn();
                    }
                })
                .catch(error => {
                    console.error('Error fetching report:', error);
                    alert('Network error occurred while fetching the report.');
                    resetBtn();
                });
                
            function resetBtn() {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        });
    }


    // Existing Checkbox logic
    document.addEventListener('change', function(e) {
        if (e.target.id === 'selectAllCheckbox') {
            const isChecked = e.target.checked;
            document.querySelectorAll('.record-checkbox').forEach(checkbox => checkbox.checked = isChecked);
            toggleActionButtons();
        }
        if (e.target.classList.contains('record-checkbox')) toggleActionButtons();
    });
    
    const noNeedEmailBtn = document.getElementById('noNeedEmailBtn');
    if (noNeedEmailBtn) {
        noNeedEmailBtn.addEventListener('click', function() {
            const selectedIds = Array.from(document.querySelectorAll('.record-checkbox')).filter(cb => cb.checked).map(cb => cb.getAttribute('data-id'));
            if (selectedIds.length === 0) return alert('Please select at least one record.');
            if (!confirm(`Are you sure you want to mark ${selectedIds.length} record(s) as "No Need Email"?`)) return;
            
            const formData = new FormData();
            formData.append('action', 'no_need_email');
            formData.append('record_ids', JSON.stringify(selectedIds));
            formData.append('type', '<?= $currentTab ?>');
            
            fetch('../includes/update_attendance.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => { if (data.success) { alert(`Successfully updated ${data.updated} record(s).`); location.reload(); } else alert('Error: ' + data.message); })
            .catch(error => alert('An error occurred while updating records.'));
        });
    }
    
    const reTrackEmailBtn = document.getElementById('reTrackEmailBtn');
    if (reTrackEmailBtn) {
        reTrackEmailBtn.addEventListener('click', function() {
            const selectedIds = Array.from(document.querySelectorAll('.record-checkbox')).filter(cb => cb.checked).map(cb => cb.getAttribute('data-id'));
            if (selectedIds.length === 0) return alert('Please select at least one record.');
            if (!confirm(`Are you sure you want to re-track email for ${selectedIds.length} record(s)? This will reset the email status.`)) return;
            
            const formData = new FormData();
            formData.append('action', 're_track_email');
            formData.append('record_ids', JSON.stringify(selectedIds));
            formData.append('type', '<?= $currentTab ?>');
            
            fetch('../includes/update_attendance.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => { if (data.success) { alert(`Successfully reset email status for ${data.updated} record(s).`); location.reload(); } else alert('Error: ' + data.message); })
            .catch(error => alert('An error occurred while updating records.'));
        });
    }
    
    const bulkEmailBtn = document.getElementById('bulkEmailBtn');
    if (bulkEmailBtn) {
        bulkEmailBtn.addEventListener('click', function() {
            const selectedIds = Array.from(document.querySelectorAll('.record-checkbox')).filter(cb => cb.checked).map(cb => cb.getAttribute('data-id'));
            if (selectedIds.length === 0) return alert('Please select at least one record.');

            showConfirmationModal(
                'Bulk Email',
                `Queue infraction emails for ${selectedIds.length} record(s)? Sending happens in the background so the page stays responsive.`,
                function() {
                    const formData = new FormData();
                    formData.append('ajax_action', 'bulk_queue_email');
                    formData.append('record_ids', JSON.stringify(selectedIds));
                    formData.append('type', '<?= $currentTab ?>');

                    fetch('attendance.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) return alert('Error: ' + data.message);

                        const originalHTML = bulkEmailBtn.innerHTML;
                        bulkEmailBtn.disabled = true;

                        // Drive the worker to completion here (batches of 20) instead of firing
                        // once — a >20-record selection would otherwise leave the rest sitting
                        // pending until Task Scheduler's next run or another button click.
                        function processBatch() {
                            fetch('../includes/send_email_queue.php', { credentials: 'same-origin' })
                                .then(r => r.json())
                                .then(res => {
                                    if (res.error) {
                                        alert('Error while sending: ' + res.error);
                                        bulkEmailBtn.innerHTML = originalHTML;
                                        bulkEmailBtn.disabled = false;
                                        return;
                                    }
                                    const counts = res.counts || {};
                                    const stillPending = (counts.pending || 0) + (counts.sending || 0);
                                    if (stillPending > 0) {
                                        bulkEmailBtn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i> Sending... ${stillPending} left`;
                                        setTimeout(processBatch, 500);
                                    } else {
                                        bulkEmailBtn.innerHTML = originalHTML;
                                        bulkEmailBtn.disabled = false;
                                        alert(`Done. Sent ${counts.sent || 0}, failed ${counts.failed || 0}.`);
                                        location.reload();
                                    }
                                })
                                .catch(() => {
                                    bulkEmailBtn.innerHTML = originalHTML;
                                    bulkEmailBtn.disabled = false;
                                    alert('Network error while sending emails.');
                                });
                        }
                        processBatch();
                    })
                    .catch(error => alert('An error occurred while queuing emails.'));
                }
            );
        });
    }

    const bulkFireBtn = document.getElementById('bulkFireBtn');
    if (bulkFireBtn) {
        bulkFireBtn.addEventListener('click', function() {
            const selectedIds = Array.from(document.querySelectorAll('.record-checkbox')).filter(cb => cb.checked).map(cb => cb.getAttribute('data-id'));
            if (selectedIds.length === 0) return alert('Please select at least one record.');
            
            showConfirmationModal(
                'Bulk Fire Employees',
                `Are you sure you want to fire ${selectedIds.length} record(s)?`,
                function() {
                    selectedIds.forEach(id => autoResetFireTrigger(id, '<?= $currentTab ?>'));
                    
                    const formData = new FormData();
                    formData.append('ajax_action', 'bulk_fire');
                    formData.append('record_ids', JSON.stringify(selectedIds));
                    formData.append('type', '<?= $currentTab ?>');
                    
                    fetch('attendance.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => { 
                        if (data.success) { 
                            location.reload(); 
                        } else alert('Error: ' + data.message); 
                    })
                    .catch(error => alert('An error occurred while updating records.'));
                }
            );
        });
    }

    function toggleActionButtons() {
        const anyChecked = Array.from(document.querySelectorAll('.record-checkbox')).some(cb => cb.checked);
        if (anyChecked) {
            if ('<?= $currentTab ?>' !== 'vto') {
                if (noNeedEmailBtn) noNeedEmailBtn.classList.remove('hidden');
                if (reTrackEmailBtn) reTrackEmailBtn.classList.remove('hidden');
                if (bulkEmailBtn) bulkEmailBtn.classList.remove('hidden');
            }
            if (bulkFireBtn) bulkFireBtn.classList.remove('hidden');
        } else {
            if (noNeedEmailBtn) noNeedEmailBtn.classList.add('hidden');
            if (reTrackEmailBtn) reTrackEmailBtn.classList.add('hidden');
            if (bulkEmailBtn) bulkEmailBtn.classList.add('hidden');
            if (bulkFireBtn) bulkFireBtn.classList.add('hidden');
        }
    }
    
    const searchInput = document.getElementById('searchInput');
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    const departmentFilter = document.getElementById('departmentFilter');
    const coverageFilter = document.getElementById('coverageFilter');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    
    let searchTimeout;
    let currentFilter = '';

    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('irFilterWrapper');
        const dd      = document.getElementById('irFilterDropdown');
        if (wrapper && dd && !wrapper.contains(e.target) && !dd.contains(e.target)) {
            dd.classList.add('hidden');
            const chevron = document.getElementById('irFilterChevron');
            if (chevron) chevron.style.transform = '';
        }
    });

    // Keep dropdown aligned when page scrolls or window resizes
    window.addEventListener('scroll', function() {
        const dd = document.getElementById('irFilterDropdown');
        if (dd && !dd.classList.contains('hidden')) positionIrDropdown();
    }, true);
    window.addEventListener('resize', function() {
        const dd = document.getElementById('irFilterDropdown');
        if (dd && !dd.classList.contains('hidden')) positionIrDropdown();
    });

    // Re-fetch IR form list whenever date changes
    function fetchIRForms() {
        const tab  = new URLSearchParams(window.location.search).get('tab') || 'absenteeism';
        if (tab === 'vto') return;
        const from = dateFrom ? dateFrom.value : '';
        const to   = dateTo   ? dateTo.value   : '';
        const url  = `partials/get_ir_forms.php?type=${tab}&date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}`;
        const currentlySelected = getSelectedIrValues();

        fetch(url)
        .then(r => r.json())
        .then(items => {
            const list = document.getElementById('irOptionsList');
            if (!list) return;
            if (items.length === 0) {
                list.innerHTML = '<p class="text-xs text-gray-500 px-4 py-3">No IR forms found for selected dates.</p>';
                updateIrLabel();
                return;
            }
            list.innerHTML = items.map(val => {
                const safe    = val.replace(/"/g, '&quot;');
                const checked = currentlySelected.includes(val) ? 'checked' : '';
                return `<label class="ir-option flex items-center gap-2.5 px-3 py-1.5 cursor-pointer hover:bg-white/5 rounded transition-colors text-xs text-gray-300" data-label="${safe}">`
                     + `<input type="checkbox" class="ir-checkbox rounded border-gray-600 bg-gray-800 text-primary-500 focus:ring-primary-500 focus:ring-offset-0 focus:ring-1" value="${safe}" ${checked}>`
                     + `<span class="truncate">${safe}</span></label>`;
            }).join('');
            // Re-bind change listeners for newly created checkboxes
            bindIrCheckboxListeners();
            updateIrLabel();
        })
        .catch(() => {});
    }

    function bindIrCheckboxListeners() {
        document.querySelectorAll('#irOptionsList .ir-checkbox').forEach(cb => {
            cb.removeEventListener('change', onIrCheckboxChange);
            cb.addEventListener('change', onIrCheckboxChange);
        });
    }

    function onIrCheckboxChange() {
        updateIrLabel();
        handleDropdownFilter();
    }
    // ────────────────────────────────────────────────────────────────────────

    // NEW FUNCTION: Highlights PENDING text, adds a red pulsing dot, and applies an "unread" background to the row
    function addPendingDots() {
        let coverageColIndex = -1;
        // Find the Coverage column index dynamically from the headers
        const headers = document.querySelectorAll('#attendanceTableContainer th');
        headers.forEach((th, index) => {
            if (th.textContent.toUpperCase().includes('COVERAGE')) {
                coverageColIndex = index;
            }
        });

        // If the coverage column exists on the current page, check its rows
        if (coverageColIndex !== -1) {
            const rows = document.querySelectorAll('#attendanceTableContainer tbody tr');
            rows.forEach(row => {
                const cell = row.cells[coverageColIndex];
                // Proceed only if the cell hasn't been checked yet
                if (cell && !cell.dataset.checkedPending) {
                    if (cell.textContent.toUpperCase().includes('PENDING') && !cell.innerHTML.includes('animate-pulse')) {
                        // Make the cell itself position: relative so the dot aligns to the top right of the cell bounding box
                        cell.classList.add('relative');
                        
                        // Highlight the entire row (like an unread email)
                        row.classList.add('bg-orange-500/10');
                        
                        // Safely change the color of the word "PENDING" to orange without breaking HTML attributes
                        cell.innerHTML = cell.innerHTML.replace(/\b(PENDING)\b(?![^<]*>)/gi, '<span class="text-orange-400 font-bold">$1</span>');

                        // Append the pulsing red dot to the cell
                        cell.insertAdjacentHTML('beforeend', 
                            `<span class="absolute top-2 right-2 h-2 w-2 rounded-full bg-red-500 shadow-[0_0_6px_rgba(239,68,68,0.9)] animate-pulse" title="Pending Action Required"></span>`
                        );
                    }
                    // Mark cell as processed
                    cell.dataset.checkedPending = 'true';
                }
            });
        }
    }

    function loadFilteredData(page = 1) {
        const urlParams = new URLSearchParams(window.location.search);
        const formData = new FormData();
        
        formData.append('search', searchInput ? searchInput.value : '');
        
        if (currentFilter) {
            formData.append('filter', currentFilter);
            if (currentFilter !== 'pending_coverage' && coverageFilter) coverageFilter.value = '';
        } else {
            if (departmentFilter) formData.append('department', departmentFilter.value);
            if (coverageFilter) formData.append('coverage_1', coverageFilter.value);
            const irVals = getSelectedIrValues().join(',');
            if (irVals) formData.append('ir_filter', irVals);
        }

        formData.append('date_from', dateFrom ? dateFrom.value : '');
        formData.append('date_to', dateTo ? dateTo.value : '');
        const currentTab = urlParams.get('tab') || 'absenteeism';
        formData.append('type', currentTab);
        formData.append('page', page);
        
        fetch('partials/attendance_table.php', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(data => {
            document.getElementById('attendanceTableContainer').innerHTML = data;
            setupPaginationLinks();
            addPendingDots(); // Inject dots after new data is loaded
            
            // Re-initialize column toggler after DOM replace
            initColumnToggler(currentTab);
        });
    }

    function setupPaginationLinks() {
        document.querySelectorAll('.pagination-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const page = this.getAttribute('data-page');
                loadFilteredData(page);
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('page', page);
                history.pushState(null, '', '?' + urlParams.toString());
            });
        });
    }
            
    function handleCardFilter(filterValue) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.delete('dept'); urlParams.delete('cov'); urlParams.delete('filter'); urlParams.delete('ir');
        
        if(departmentFilter) departmentFilter.value = '';
        if(coverageFilter) coverageFilter.value = '';
        // Clear IR checkboxes
        document.querySelectorAll('#irOptionsList .ir-checkbox').forEach(cb => cb.checked = false);
        updateIrLabel();
        
        currentFilter = filterValue;
        urlParams.set('filter', filterValue);
        
        document.querySelectorAll('.filter-button').forEach(btn => btn.classList.remove('active'));
        event.currentTarget.classList.add('active');
        
        history.pushState(null, '', '?' + urlParams.toString());
        loadFilteredData();
    }

    function handleDropdownFilter() {
        const urlParams = new URLSearchParams(window.location.search);
        currentFilter = '';
        urlParams.delete('filter');
        
        if (departmentFilter && departmentFilter.value) urlParams.set('dept', departmentFilter.value); else urlParams.delete('dept');
        if (coverageFilter && coverageFilter.value) urlParams.set('cov', coverageFilter.value); else urlParams.delete('cov');
        const irVals = getSelectedIrValues().join(',');
        if (irVals) urlParams.set('ir', irVals); else urlParams.delete('ir');
        if (dateFrom && dateFrom.value) urlParams.set('from', dateFrom.value); else urlParams.delete('from');
        if (dateTo && dateTo.value) urlParams.set('to', dateTo.value); else urlParams.delete('to');
        
        document.querySelectorAll('.filter-button').forEach(btn => btn.classList.remove('active'));
        history.pushState(null, '', '?' + urlParams.toString());
        loadFilteredData();
    }
    // Expose to global clearIrFilter bridge
    window._irHandleDropdown = handleDropdownFilter;

    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'absenteeism';
            window.location.href = 'attendance.php?tab=' + tab;
        });
    }

    document.querySelectorAll('.filter-button').forEach(btn => { btn.addEventListener('click', function(e) { handleCardFilter(this.value); }); });
    if(departmentFilter) departmentFilter.addEventListener('change', handleDropdownFilter);
    if(coverageFilter) coverageFilter.addEventListener('change', handleDropdownFilter);
    // Bind initial IR checkboxes
    bindIrCheckboxListeners();
    updateIrLabel();
    
    if(searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const urlParams = new URLSearchParams(window.location.search);
                if (currentFilter) urlParams.set('filter', currentFilter);
                else {
                    if (departmentFilter && departmentFilter.value) urlParams.set('dept', departmentFilter.value);
                    if (coverageFilter && coverageFilter.value) urlParams.set('cov', coverageFilter.value);
                    const irVals = getSelectedIrValues().join(',');
                    if (irVals) urlParams.set('ir', irVals); else urlParams.delete('ir');
                }
                if (searchInput.value) urlParams.set('search', searchInput.value); else urlParams.delete('search');
                history.pushState(null, '', '?' + urlParams.toString());
                loadFilteredData();
            }, 300);
        });
    }
    
    function fetchDepartments() {
        const tab  = new URLSearchParams(window.location.search).get('tab') || 'absenteeism';
        if (tab === 'vto') return;
        const from = dateFrom ? dateFrom.value : '';
        const to   = dateTo   ? dateTo.value   : '';
        const url  = `partials/get_departments.php?type=${tab}&date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}`;
        const currentDept = departmentFilter ? departmentFilter.value : '';
        fetch(url)
        .then(r => r.json())
        .then(items => {
            if (!departmentFilter) return;
            departmentFilter.innerHTML = '<option value="">All Departments</option>';
            items.forEach(dept => {
                const opt = document.createElement('option');
                opt.value = dept;
                opt.textContent = dept;
                if (dept === currentDept) opt.selected = true;
                departmentFilter.appendChild(opt);
            });
        })
        .catch(() => {});
    }

    if(dateFrom) dateFrom.addEventListener('change', function() { fetchIRForms(); fetchDepartments(); handleDropdownFilter(); });
    if(dateTo)   dateTo.addEventListener('change',   function() { fetchIRForms(); fetchDepartments(); handleDropdownFilter(); });

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('filter')) {
        currentFilter = urlParams.get('filter');
        const activeBtn = document.querySelector(`.filter-button[value="${currentFilter}"]`);
        if(activeBtn) activeBtn.classList.add('active');
    }
    
    loadFilteredData(urlParams.get('page') || 1);
    
    // Inject dots for initially loaded table server-side
    setTimeout(addPendingDots, 100); 
});
</script>

<script>
function showDeleteModal(recordId, recordType = '') {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('delete', recordId);
    if (recordType) urlParams.set('type', recordType);
    
    const actionUrl = `${window.location.pathname}?${urlParams.toString()}`;
    const modal = `
        <div id="deleteModal" class="fixed inset-0 bg-gray-900/80 backdrop-blur-sm flex items-center justify-center z-50 transition-opacity">
            <div class="glass-panel-solid rounded-2xl shadow-2xl w-full max-w-md transform scale-100 transition-transform">
                <div class="px-8 py-8">
                    <div class="flex items-center justify-center w-12 h-12 rounded-full bg-red-500/20 text-red-400 mb-4 mx-auto border border-red-500/30">
                        <i class="fas fa-exclamation-triangle text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white text-center mb-2">Confirm Deletion</h3>
                    <p class="text-gray-400 text-center mb-6 text-sm">Are you sure you want to permanently delete this record? This action cannot be undone.</p>
                    <form method="post" action="${actionUrl}" class="space-y-5">
                        <div>
                            <label for="delete_password" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Authorization Key Required</label>
                            <input type="password" name="delete_password" id="delete_password" 
                                   class="glass-input w-full px-4 py-3 rounded-xl shadow-inner focus:ring-2 focus:ring-red-500 focus:outline-none transition-all text-center tracking-widest" placeholder="••••••••" required>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" onclick="closeDeleteModal()" class="w-full px-4 py-2.5 bg-gray-700/50 text-gray-300 rounded-xl hover:bg-gray-600 hover:text-white border border-gray-600 transition-all font-medium">Cancel</button>
                            <button type="submit" class="w-full px-4 py-2.5 bg-red-600 text-white rounded-xl hover:bg-red-500 shadow-lg shadow-red-900/30 transition-all font-medium">Confirm Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modal);
    setTimeout(() => document.getElementById('delete_password').focus(), 100);
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    if (modal) modal.remove();
}

function showHistoryModal(recordId, recordType) {
    const modal = document.getElementById('historyModal');
    const loader = `<div class="flex items-center justify-center py-10"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-500"></div><span class="ml-3 text-gray-400 text-sm">Loading history timeline...</span></div>`;
    document.getElementById('historyTableBody').innerHTML = loader;
    modal.classList.remove('hidden');
    
    fetch(`get_history.php?record_id=${recordId}&type=${recordType}`)
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('historyTableBody');
            if (data.length > 0) {
                let timelineHtml = '<div class="relative border-l border-gray-700 ml-4 space-y-8 pb-4">';
                data.forEach(activity => {
                    const initials = activity.sub_name.split(' ').map(n => n[0]).join('').toUpperCase();
                    timelineHtml += `
                        <div class="relative pl-6">
                            <span class="absolute -left-[17px] top-1 h-8 w-8 rounded-full bg-primary-900/50 border border-primary-500/50 flex items-center justify-center shadow-[0_0_10px_rgba(14,165,233,0.3)]">
                                <span class="text-[10px] font-bold text-primary-300">${initials.substring(0, 2)}</span>
                            </span>
                            <div class="glass-panel-solid p-4 rounded-xl shadow-md transition-colors hover:bg-gray-800/60">
                                <div class="flex justify-between items-start mb-1">
                                    <h4 class="text-sm font-bold text-gray-200">${activity.sub_name}</h4>
                                    <span class="text-xs font-medium text-primary-400 bg-primary-500/10 px-2 py-1 rounded-md border border-primary-500/20">
                                        ${new Date(activity.activity_time).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                    </span>
                                </div>
                                <p class="text-sm text-gray-400 leading-relaxed">${activity.activity_description}</p>
                            </div>
                        </div>`;
                });
                timelineHtml += '</div>';
                tbody.innerHTML = timelineHtml;
            } else {
                tbody.innerHTML = `<div class="text-center py-10"><i class="fas fa-history text-4xl text-gray-600 mb-3"></i><p class="text-sm text-gray-400">No history found for this record.</p></div>`;
            }
        })
        .catch(error => {
            document.getElementById('historyTableBody').innerHTML = `<div class="bg-red-500/10 border border-red-500/20 rounded-xl p-4 text-center"><i class="fas fa-exclamation-circle text-red-400 text-2xl mb-2"></i><p class="text-sm text-red-300">Error loading history: ${error.message}</p></div>`;
        });
}

function closeHistoryModal() { document.getElementById('historyModal').classList.add('hidden'); }

document.addEventListener('click', function(e) { if (e.target === document.getElementById('historyModal')) closeHistoryModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && !document.getElementById('historyModal').classList.contains('hidden')) closeHistoryModal(); });

function autoResetFireTrigger(recordId, recordType) {
    setTimeout(() => {
        const formData = new FormData();
        formData.append('action', 'reset_fire_trigger');
        formData.append('record_id', recordId);
        formData.append('type', recordType);
        fetch('../includes/update_attendance.php', { method: 'POST', body: formData }).catch(error => console.error(error));
    }, 90000); 
}

function fireEmployee(recordId, recordType) {
    showConfirmationModal(
        'Fire Employee',
        'Do you want to fire this record?',
        function() {
            autoResetFireTrigger(recordId, recordType);
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('fire_employee', recordId);
            urlParams.set('type', recordType);
            window.location.href = `${window.location.pathname}?${urlParams.toString()}`;
        },
        'fa-fire',
        'red'
    );
    return false;
}

function confirmSendEmail(event, url) {
    event.preventDefault();
    // Carry the page's current (possibly pushState-only) URL along, so send_email.php can
    // redirect back to exactly what's on screen instead of resetting filters to a bare tab.
    const separator = url.includes('?') ? '&' : '?';
    const urlWithReturn = url + separator + 'return_url=' + encodeURIComponent(window.location.href);
    showConfirmationModal(
        'Send Email Notification',
        'Are you sure you want to send the email notification for this record?',
        function() {
            window.location.href = urlWithReturn;
        },
        'fa-envelope',
        'blue'
    );
    return false;
}

let confirmActionCallback = null;

function showConfirmationModal(title, text, proceedCallback, iconClass = 'fa-fire', themeColor = 'red') {
    document.getElementById('confirmModalTitle').textContent = title;
    document.getElementById('confirmModalText').textContent = text;
    confirmActionCallback = proceedCallback;

    const iconEl = document.getElementById('confirmModalIcon');
    const iconContainer = document.getElementById('confirmModalIconContainer');
    const proceedBtn = document.getElementById('confirmProceedBtn');

    if (iconEl && iconContainer && proceedBtn) {
        iconEl.className = 'fas ' + iconClass + ' text-xl animate-pulse';
        
        if (themeColor === 'blue') {
            iconContainer.className = 'w-12 h-12 rounded-xl bg-blue-500/10 flex items-center justify-center border border-blue-500/20 flex-shrink-0';
            iconEl.classList.add('text-blue-400');
            proceedBtn.className = 'px-5 py-2.5 bg-blue-600/90 hover:bg-blue-500 text-white rounded-xl text-sm font-bold transition-all shadow-lg hover:shadow-blue-500/20';
        } else {
            iconContainer.className = 'w-12 h-12 rounded-xl bg-red-500/10 flex items-center justify-center border border-red-500/20 flex-shrink-0';
            iconEl.classList.add('text-red-500');
            proceedBtn.className = 'px-5 py-2.5 bg-red-600/90 hover:bg-red-500 text-white rounded-xl text-sm font-bold transition-all shadow-lg hover:shadow-red-500/20';
        }
    }

    const modal = document.getElementById('confirmationModal');
    const modalBox = modal.querySelector('div');
    
    modal.classList.remove('hidden');
    void modal.offsetWidth; // Force reflow
    modal.classList.remove('opacity-0');
    modalBox.classList.remove('scale-95');
    modalBox.classList.add('scale-100');
}

function closeConfirmationModal() {
    const modal = document.getElementById('confirmationModal');
    const modalBox = modal.querySelector('div');
    
    modal.classList.add('opacity-0');
    modalBox.classList.add('scale-95');
    modalBox.classList.remove('scale-100');
    setTimeout(() => {
        modal.classList.add('hidden');
        confirmActionCallback = null;
    }, 300);
}

// Bind events for Confirmation Modal
document.addEventListener('click', function(e) {
    if (e.target) {
        if (e.target.id === 'confirmCancelBtn' || e.target.closest('#confirmCancelBtn')) {
            closeConfirmationModal();
        }
        if (e.target.id === 'confirmProceedBtn' || e.target.closest('#confirmProceedBtn')) {
            if (confirmActionCallback) {
                confirmActionCallback();
            }
            closeConfirmationModal();
        }
    }
    if (e.target === document.getElementById('confirmationModal')) closeConfirmationModal();
});
document.addEventListener('keydown', function(e) {
    if (!document.getElementById('confirmationModal').classList.contains('hidden')) {
        if (e.key === 'Escape') {
            closeConfirmationModal();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (confirmActionCallback) {
                confirmActionCallback();
            }
            closeConfirmationModal();
        }
    }
});

function openCopyReportModal() {
    const modal = document.getElementById('copyReportModal');
    if (!modal) return;
    const modalBox = modal.querySelector('div');
    
    modal.classList.remove('hidden');
    void modal.offsetWidth; 
    modal.classList.remove('opacity-0');
    modalBox.classList.remove('scale-95');
    modalBox.classList.add('scale-100');
}

function closeCopyReportModal() {
    const modal = document.getElementById('copyReportModal');
    if (!modal) return;
    const modalBox = modal.querySelector('div');
    
    modal.classList.add('opacity-0');
    modalBox.classList.add('scale-95');
    modalBox.classList.remove('scale-100');
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

document.addEventListener('click', function(e) { 
    if (e.target === document.getElementById('copyReportModal')) closeCopyReportModal(); 
});
document.addEventListener('keydown', function(e) { 
    if (e.key === 'Escape' && !document.getElementById('copyReportModal').classList.contains('hidden')) closeCopyReportModal(); 
});
</script>

<div id="historyModal" class="hidden fixed inset-0 bg-gray-900/80 backdrop-blur-sm flex items-center justify-center z-50 transition-opacity">
    <div class="glass-panel-solid rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col max-h-[80vh]">
        <div class="flex justify-between items-center px-6 py-5 border-b border-gray-700/50 bg-gray-800/50">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fas fa-stream text-primary-400"></i> Activity Timeline
            </h3>
            <button onclick="closeHistoryModal()" class="text-gray-400 hover:text-white transition-colors p-1 rounded-full hover:bg-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto custom-scrollbar flex-1">
            <div id="historyTableBody" class="space-y-6"></div>
        </div>
        <div class="px-6 py-4 bg-gray-800/50 border-t border-gray-700/50 flex justify-end">
            <button onclick="closeHistoryModal()" class="px-5 py-2.5 bg-gray-700/50 text-gray-300 rounded-xl hover:bg-gray-600 hover:text-white transition-colors border border-gray-600 shadow-sm font-medium text-sm">Close</button>
        </div>
    </div>
</div>

<!-- COPY ABSENCE REPORT MODAL -->
<div id="copyReportModal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center transition-opacity opacity-0">
    <div class="bg-gray-850 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-all duration-300 border border-gray-700/50">
        <!-- Header -->
        <div class="flex justify-between items-center px-6 py-5 border-b border-gray-700/50 bg-gray-850">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fas fa-copy text-emerald-400"></i> Copy Absence Report
            </h3>
            <button onclick="closeCopyReportModal()" class="text-gray-400 hover:text-white transition-colors p-1 rounded-full hover:bg-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <!-- Body -->
        <div class="p-6 bg-gray-800/40">
            <label for="reportDateInput" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Select Date for Report</label>
            <input type="date" id="reportDateInput" value="<?= date('Y-m-d') ?>" class="w-full bg-gray-900/50 border border-gray-600 rounded-xl text-white px-4 py-3 focus:ring-2 focus:ring-emerald-500 outline-none transition-all text-sm font-medium cursor-pointer">
            <p class="text-xs text-gray-500 mt-2">The report will generate the coverage summary for the selected date.</p>
        </div>
        
        <!-- Footer -->
        <div class="bg-gray-850 px-6 py-4 flex justify-end gap-3 border-t border-gray-700/50">
            <button onclick="closeCopyReportModal()" class="px-5 py-2.5 bg-gray-750 text-gray-300 rounded-xl hover:bg-gray-700 hover:text-white transition-colors border border-gray-600/50 shadow-sm font-medium text-sm">
                Cancel
            </button>
            <button id="modalGenerateReportBtn" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl shadow-lg shadow-emerald-950/20 transition-all flex items-center font-medium text-sm">
                <i class="fas fa-file-alt mr-2"></i> Generate & Copy
            </button>
        </div>
    </div>
</div>

<!-- CUSTOM REUSABLE CONFIRMATION MODAL -->
<div id="confirmationModal" class="hidden fixed inset-0 z-[100] bg-black/60 backdrop-blur-sm flex items-center justify-center transition-all duration-300 opacity-0">
    <div class="bg-gray-900/95 backdrop-blur-xl rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-all duration-300 border border-white/10 p-6">
        <div class="flex items-start gap-4">
            <div id="confirmModalIconContainer" class="w-12 h-12 rounded-xl bg-red-500/10 flex items-center justify-center border border-red-500/20 flex-shrink-0">
                <i id="confirmModalIcon" class="fas fa-fire text-red-500 text-xl animate-pulse"></i>
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-bold text-white tracking-wide" id="confirmModalTitle">Confirm Action</h3>
                <p class="text-sm text-slate-300 mt-2 leading-relaxed" id="confirmModalText">Are you sure you want to proceed?</p>
            </div>
        </div>
        <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-white/5">
            <button id="confirmCancelBtn" class="px-5 py-2.5 bg-slate-800/80 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl text-sm font-bold transition-all border border-white/5">
                Cancel
            </button>
            <button id="confirmProceedBtn" class="px-5 py-2.5 bg-red-600/90 hover:bg-red-500 text-white rounded-xl text-sm font-bold transition-all shadow-lg hover:shadow-red-500/20">
                Proceed
            </button>
        </div>
    </div>
</div>

<?php renderFooter(); ?>