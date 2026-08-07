<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

// 1. Determine logged-in user from the users table (for default fallback)
$loggedInUser = 'Unknown RTA';
try {
    $userStmt = $pdo->prepare("SELECT sub_name FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch();
    if ($user) {
        $loggedInUser = $user['sub_name'];
    }
} catch (PDOException $e) {
    error_log("Error fetching user: " . $e->getMessage());
}

// Fetch all users for the turnover dropdown & RTA selection filter
$allUsers = [];
try {
    $allUsersStmt = $pdo->query("SELECT id, sub_name FROM users ORDER BY sub_name ASC");
    $allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching all users: " . $e->getMessage());
}

// 2. Default filter values & Specific RTA Selection
$sub_name = isset($_GET['rta_name']) && !empty(trim($_GET['rta_name'])) ? sanitizeInput($_GET['rta_name']) : $loggedInUser;
$shiftDate = isset($_GET['shift_date']) ? sanitizeInput($_GET['shift_date']) : date('Y-m-d');
$startTime = isset($_GET['start_time']) ? sanitizeInput($_GET['start_time']) : '06:00';
$endTime = isset($_GET['end_time']) ? sanitizeInput($_GET['end_time']) : '15:00';
$actionFilter = isset($_GET['action_filter']) ? sanitizeInput($_GET['action_filter']) : '';

// 3. Calculate exact DateTime boundaries for the query (In Manila Time)
$startDateTime = $shiftDate . ' ' . $startTime . ':00';
$endDateTime = $shiftDate . ' ' . $endTime . ':00';

// If end time is mathematically "less" than start time, it means the shift crossed midnight into the next day
if (strtotime($endTime) < strtotime($startTime)) {
    $endDateTime = date('Y-m-d H:i:s', strtotime($endDateTime . ' +1 day'));
}

$details = [];
$counts = [
    'absences' => 0,
    'lates' => 0,
    'vto' => 0,
    'emails' => 0,
    'tickets' => 0
];
$error = null;

try {
    // A. Absences Tracked
    // Using DATE_ADD(created_at, INTERVAL 8 HOUR) to convert UTC database timestamps to Manila time for accurate boundary checking
    $stmt = $pdo->prepare("SELECT 'Absence Tracked' as action_type, employee_id, full_name, DATE_ADD(created_at, INTERVAL 8 HOUR) as action_time, reason as details FROM absenteeism WHERE TRIM(sub_name) = TRIM(:sub_name) AND DATE_ADD(created_at, INTERVAL 8 HOUR) BETWEEN :start AND :end");
    $stmt->execute([':sub_name' => $sub_name, ':start' => $startDateTime, ':end' => $endDateTime]);
    $absences = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts['absences'] = count($absences);
    $details = array_merge($details, $absences);

    // B. Lates Tracked
    $stmt = $pdo->prepare("SELECT 'Late Tracked' as action_type, employee_id, full_name, DATE_ADD(created_at, INTERVAL 8 HOUR) as action_time, CONCAT(minutes_late, ' mins late') as details FROM tardiness WHERE TRIM(sub_name) = TRIM(:sub_name) AND DATE_ADD(created_at, INTERVAL 8 HOUR) BETWEEN :start AND :end");
    $stmt->execute([':sub_name' => $sub_name, ':start' => $startDateTime, ':end' => $endDateTime]);
    $lates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts['lates'] = count($lates);
    $details = array_merge($details, $lates);

    // C. VTO Tracked
    $stmt = $pdo->prepare("SELECT 'VTO Tracked' as action_type, employee_id, full_name, DATE_ADD(created_at, INTERVAL 8 HOUR) as action_time, CONCAT(vto_mins, ' mins (', vto_type, ')') as details FROM vto_tracker WHERE TRIM(sub_name) = TRIM(:sub_name) AND DATE_ADD(created_at, INTERVAL 8 HOUR) BETWEEN :start AND :end");
    $stmt->execute([':sub_name' => $sub_name, ':start' => $startDateTime, ':end' => $endDateTime]);
    $vto = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts['vto'] = count($vto);
    $details = array_merge($details, $vto);

    // D. Emails Sent (From Absenteeism)
    $stmt = $pdo->prepare("SELECT 'Email Sent (Absence)' as action_type, employee_id, full_name, email_sent_at as action_time, 'Sent absence notification' as details FROM absenteeism WHERE TRIM(sub_name) = TRIM(:sub_name) AND email_sent = 1 AND email_sent_at BETWEEN :start AND :end");
    $stmt->execute([':sub_name' => $sub_name, ':start' => $startDateTime, ':end' => $endDateTime]);
    $emails_abs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts['emails'] += count($emails_abs);
    $details = array_merge($details, $emails_abs);

    // E. Emails Sent (From Tardiness)
    $stmt = $pdo->prepare("SELECT 'Email Sent (Late)' as action_type, employee_id, full_name, email_sent_at as action_time, 'Sent tardiness notification' as details FROM tardiness WHERE TRIM(sub_name) = TRIM(:sub_name) AND email_sent = 1 AND email_sent_at BETWEEN :start AND :end");
    $stmt->execute([':sub_name' => $sub_name, ':start' => $startDateTime, ':end' => $endDateTime]);
    $emails_late = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts['emails'] += count($emails_late);
    $details = array_merge($details, $emails_late);

    // F. Tickets Resolved 
    // Ticket.Timestamp is generated via PHP as Manila time, so we leave it alone and just parse it.
    $stmt = $pdo->prepare("SELECT 'Ticket Resolved' as action_type, EID as employee_id, Employee_name as full_name, STR_TO_DATE(Timestamp, '%c/%e/%Y %H:%i:%s') as action_time, Issues_Concerning as details FROM ticket WHERE TRIM(SLT_on_DUTY) = TRIM(:sub_name) AND Status = 'RESOLVED' AND STR_TO_DATE(Timestamp, '%c/%e/%Y %H:%i:%s') BETWEEN :start AND :end");
    $stmt->execute([':sub_name' => $sub_name, ':start' => $startDateTime, ':end' => $endDateTime]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts['tickets'] = count($tickets);
    $details = array_merge($details, $tickets);

    // Sort the merged details array by action_time descending (newest first)
    usort($details, function($a, $b) {
        return strtotime($b['action_time']) - strtotime($a['action_time']);
    });

} catch (PDOException $e) {
    error_log("Error fetching shift report details: " . $e->getMessage());
    $error = "Database Error: " . $e->getMessage();
}

// Filter the array if a card filter was clicked
if (!empty($actionFilter)) {
    $details = array_filter($details, function($row) use ($actionFilter) {
        switch ($actionFilter) {
            case 'absences': return $row['action_type'] === 'Absence Tracked';
            case 'lates': return $row['action_type'] === 'Late Tracked';
            case 'vto': return $row['action_type'] === 'VTO Tracked';
            case 'emails': return strpos($row['action_type'], 'Email Sent') !== false;
            case 'tickets': return $row['action_type'] === 'Ticket Resolved';
            default: return true;
        }
    });
    // Re-index array after filtering for proper pagination
    $details = array_values($details);
}

$totalActions = $counts['absences'] + $counts['lates'] + $counts['vto'] + $counts['emails'] + $counts['tickets'];

// --- Pagination Logic ---
$recordsPerPage = 15;
$totalRecords = count($details);
$totalPages = ceil($totalRecords / $recordsPerPage);
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;

if ($currentPage < 1) $currentPage = 1;
if ($currentPage > $totalPages && $totalPages > 0) $currentPage = $totalPages;

$offset = ($currentPage - 1) * $recordsPerPage;
$paginatedDetails = array_slice($details, $offset, $recordsPerPage);
// ------------------------

// Helper for UI styling
$actionStyles = [
    'Absence Tracked' => ['color' => 'text-red-400', 'bg' => 'bg-red-500/10', 'icon' => 'fa-user-times', 'border' => 'border-red-500/20'],
    'Late Tracked' => ['color' => 'text-yellow-400', 'bg' => 'bg-yellow-500/10', 'icon' => 'fa-clock', 'border' => 'border-yellow-500/20'],
    'VTO Tracked' => ['color' => 'text-green-400', 'bg' => 'bg-green-500/10', 'icon' => 'fa-door-open', 'border' => 'border-green-500/20'],
    'Email Sent (Absence)' => ['color' => 'text-blue-400', 'bg' => 'bg-blue-500/10', 'icon' => 'fa-envelope', 'border' => 'border-blue-500/20'],
    'Email Sent (Late)' => ['color' => 'text-blue-400', 'bg' => 'bg-blue-500/10', 'icon' => 'fa-envelope', 'border' => 'border-blue-500/20'],
    'Ticket Resolved' => ['color' => 'text-purple-400', 'bg' => 'bg-purple-500/10', 'icon' => 'fa-check-circle', 'border' => 'border-purple-500/20'],
];

// Helper to generate the base URL for filter links
$cardBaseUrlParams = $_GET;
unset($cardBaseUrlParams['action_filter']);
unset($cardBaseUrlParams['page']);
$cardBaseUrl = 'shift_report.php?' . http_build_query($cardBaseUrlParams);

require_once '../components/layout.php';
renderHead('My Shift Report');
renderNavbar();
renderSidebar('shift_report'); 
?>

<style>
    /* Glassmorphism utility classes and animations */
    .glass-panel {
        background: rgba(31, 41, 55, 0.4);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
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
        outline: none;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
    }
    .spreadsheet-cell {
        background: transparent;
        border: none;
        box-shadow: none;
    }
    .spreadsheet-cell:focus {
        background: rgba(59, 130, 246, 0.1);
        outline: 2px solid #3b82f6;
        outline-offset: -2px;
        box-shadow: none;
    }
    /* Scrollbar for modal */
    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: rgba(31, 41, 55, 0.5);
        border-radius: 10px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: rgba(75, 85, 99, 0.8);
        border-radius: 10px;
    }
</style>

<div class="pt-2 min-h-screen relative overflow-hidden">
    <!-- Background blobs for glass effect depth -->
    <div class="absolute top-0 left-1/4 w-96 h-96 bg-blue-900/20 rounded-full mix-blend-multiply filter blur-3xl opacity-30 pointer-events-none z-0"></div>
    <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-purple-900/20 rounded-full mix-blend-multiply filter blur-3xl opacity-30 pointer-events-none z-0"></div>

    <main class="p-6 relative z-10 max-w-7xl mx-auto">
        
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div class="flex items-center gap-5">
                <!-- User Avatar Badge -->
                <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center text-2xl font-bold text-white shadow-lg border border-white/10">
                    <?= strtoupper(substr(trim($sub_name), 0, 3)) ?>
                </div>
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-white">Shift Report</h1>
                    <p class="text-gray-400 text-sm mt-1">Activity breakdown for <span class="text-primary-400 font-semibold"><?= htmlspecialchars($sub_name) ?></span></p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <a href="attendance.php" class="glass-panel hover:bg-gray-700/60 text-gray-300 hover:text-white px-5 py-2.5 rounded-xl transition-all shadow-lg text-sm font-medium flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Tracker
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-500/20 border border-red-500/40 text-red-200 px-4 py-4 rounded-xl mb-6 flex items-start gap-3 shadow-lg shadow-red-900/20">
                <i class="fas fa-exclamation-triangle mt-1 text-red-400"></i>
                <div>
                    <p class="font-semibold">Error Querying Database</p>
                    <p class="text-sm opacity-90"><?= htmlspecialchars($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filter Container -->
        <div class="glass-panel p-6 rounded-2xl mb-8 shadow-xl">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-gray-300 font-bold text-sm uppercase tracking-wider flex items-center gap-2">
                    <i class="fas fa-clock text-primary-500"></i> Define Shift Details
                </h3>
            </div>
            
            <form method="GET" action="shift_report.php" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-5 items-end">
                
                <!-- Target RTA -->
                <div>
                    <label for="rta_name" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Select SLT</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <select id="rta_name" name="rta_name" class="glass-input w-full pl-10 pr-10 py-3 rounded-xl shadow-inner transition-colors duration-200 text-sm appearance-none">
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= htmlspecialchars($u['sub_name']) ?>" <?= $sub_name === $u['sub_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['sub_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                            <i class="fas fa-chevron-down text-xs"></i>
                        </div>
                    </div>
                </div>

                <!-- Shift Date -->
                <div>
                    <label for="shift_date" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Shift Date</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <input type="date" id="shift_date" name="shift_date" 
                               class="glass-input w-full pl-10 pr-4 py-3 rounded-xl shadow-inner transition-colors duration-200 text-sm"
                               value="<?= htmlspecialchars($shiftDate) ?>" required>
                    </div>
                </div>

                <!-- Shift Start -->
                <div>
                    <label for="start_time" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Time Start</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                            <i class="fas fa-hourglass-start"></i>
                        </div>
                        <input type="time" id="start_time" name="start_time" 
                               class="glass-input w-full pl-10 pr-4 py-3 rounded-xl shadow-inner transition-colors duration-200 text-sm"
                               value="<?= htmlspecialchars($startTime) ?>" required>
                    </div>
                </div>

                <!-- Shift End -->
                <div>
                    <label for="end_time" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Time End</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-500">
                            <i class="fas fa-hourglass-end"></i>
                        </div>
                        <input type="time" id="end_time" name="end_time" 
                               class="glass-input w-full pl-10 pr-4 py-3 rounded-xl shadow-inner transition-colors duration-200 text-sm"
                               value="<?= htmlspecialchars($endTime) ?>" required>
                    </div>
                </div>

                <!-- Submit Button -->
                <div>
                    <button type="submit" class="w-full px-4 py-3 bg-primary-600 hover:bg-primary-500 text-white rounded-xl shadow-lg shadow-primary-900/30 transition-all font-semibold flex items-center justify-center gap-2 text-sm tracking-wide">
                        <i class="fas fa-sync-alt"></i> Generate Report
                    </button>
                </div>
            </form>
            
            <div class="mt-4 pt-4 border-t border-gray-700/50 flex justify-between items-center text-xs text-gray-400">
                <p><i class="fas fa-info-circle text-blue-400 mr-1"></i> If Time End is earlier than Time Start, the system assumes the shift crossed midnight into the next day.</p>
                <p class="font-mono text-primary-400 bg-primary-500/10 px-2 py-1 rounded border border-primary-500/20">Shift Details: <?= date('M d, Y h:i A', strtotime($startDateTime)) ?> - <?= date('M d, Y h:i A', strtotime($endDateTime)) ?></p>
            </div>
        </div>

        <!-- Shift Totals Cards (Acting as Filters) -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <!-- Total Absences -->
            <a href="<?= $cardBaseUrl ?>&action_filter=absences" class="glass-panel rounded-2xl p-5 shadow-xl relative overflow-hidden group block transition-all <?= $actionFilter === 'absences' ? 'border-red-500/50 bg-red-500/10 shadow-[0_0_15px_rgba(239,68,68,0.2)]' : 'hover:bg-gray-800/50' ?>">
                <div class="absolute right-0 top-0 h-16 w-16 bg-red-500/10 rounded-bl-full -mr-2 -mt-2 transition-transform group-hover:scale-110 <?= $actionFilter === 'absences' ? 'scale-110 bg-red-500/20' : '' ?>"></div>
                <h3 class="text-gray-400 text-[10px] font-bold uppercase tracking-wider <?= $actionFilter === 'absences' ? 'text-red-300' : '' ?>">Absence Tracked</h3>
                <p class="text-2xl font-bold mt-1 text-white"><?= number_format($counts['absences']) ?></p>
            </a>
            
            <!-- Total Lates -->
            <a href="<?= $cardBaseUrl ?>&action_filter=lates" class="glass-panel rounded-2xl p-5 shadow-xl relative overflow-hidden group block transition-all <?= $actionFilter === 'lates' ? 'border-yellow-500/50 bg-yellow-500/10 shadow-[0_0_15px_rgba(234,179,8,0.2)]' : 'hover:bg-gray-800/50' ?>">
                <div class="absolute right-0 top-0 h-16 w-16 bg-yellow-500/10 rounded-bl-full -mr-2 -mt-2 transition-transform group-hover:scale-110 <?= $actionFilter === 'lates' ? 'scale-110 bg-yellow-500/20' : '' ?>"></div>
                <h3 class="text-gray-400 text-[10px] font-bold uppercase tracking-wider <?= $actionFilter === 'lates' ? 'text-yellow-300' : '' ?>">Lates Tracked</h3>
                <p class="text-2xl font-bold mt-1 text-white"><?= number_format($counts['lates']) ?></p>
            </a>

            <!-- Total VTO -->
            <a href="<?= $cardBaseUrl ?>&action_filter=vto" class="glass-panel rounded-2xl p-5 shadow-xl relative overflow-hidden group block transition-all <?= $actionFilter === 'vto' ? 'border-green-500/50 bg-green-500/10 shadow-[0_0_15px_rgba(34,197,94,0.2)]' : 'hover:bg-gray-800/50' ?>">
                <div class="absolute right-0 top-0 h-16 w-16 bg-green-500/10 rounded-bl-full -mr-2 -mt-2 transition-transform group-hover:scale-110 <?= $actionFilter === 'vto' ? 'scale-110 bg-green-500/20' : '' ?>"></div>
                <h3 class="text-gray-400 text-[10px] font-bold uppercase tracking-wider <?= $actionFilter === 'vto' ? 'text-green-300' : '' ?>">VTO Tracked</h3>
                <p class="text-2xl font-bold mt-1 text-white"><?= number_format($counts['vto']) ?></p>
            </a>
            
            <!-- Total Emails -->
            <a href="<?= $cardBaseUrl ?>&action_filter=emails" class="glass-panel rounded-2xl p-5 shadow-xl relative overflow-hidden group block transition-all <?= $actionFilter === 'emails' ? 'border-blue-500/50 bg-blue-500/10 shadow-[0_0_15px_rgba(59,130,246,0.2)]' : 'hover:bg-gray-800/50' ?>">
                <div class="absolute right-0 top-0 h-16 w-16 bg-blue-500/10 rounded-bl-full -mr-2 -mt-2 transition-transform group-hover:scale-110 <?= $actionFilter === 'emails' ? 'scale-110 bg-blue-500/20' : '' ?>"></div>
                <h3 class="text-gray-400 text-[10px] font-bold uppercase tracking-wider <?= $actionFilter === 'emails' ? 'text-blue-300' : '' ?>">Emails Sent</h3>
                <p class="text-2xl font-bold mt-1 text-white"><?= number_format($counts['emails']) ?></p>
            </a>
            
            <!-- Total Tickets -->
            <a href="<?= $cardBaseUrl ?>&action_filter=tickets" class="glass-panel rounded-2xl p-5 shadow-xl relative overflow-hidden group block transition-all <?= $actionFilter === 'tickets' ? 'border-purple-500/50 bg-purple-500/10 shadow-[0_0_15px_rgba(168,85,247,0.2)]' : 'hover:bg-gray-800/50' ?>">
                <div class="absolute right-0 top-0 h-16 w-16 bg-purple-500/10 rounded-bl-full -mr-2 -mt-2 transition-transform group-hover:scale-110 <?= $actionFilter === 'tickets' ? 'scale-110 bg-purple-500/20' : '' ?>"></div>
                <h3 class="text-gray-400 text-[10px] font-bold uppercase tracking-wider <?= $actionFilter === 'tickets' ? 'text-purple-300' : '' ?>">Tix Resolved</h3>
                <p class="text-2xl font-bold mt-1 text-white"><?= number_format($counts['tickets']) ?></p>
            </a>
        </div>

        <!-- Shift Turnover Setup Section -->
        <div class="glass-panel p-6 rounded-2xl mb-8 shadow-xl">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-gray-300 font-bold text-sm uppercase tracking-wider flex items-center gap-2">
                    <i class="fas fa-exchange-alt text-primary-500"></i> Shift Turnover Details
                </h3>
            </div>

            <div class="space-y-6">
                <!-- Turnover Selection Trigger and Display -->
                <div class="w-full">
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Turning Over To</label>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" onclick="openTurnoverModal()" class="glass-input px-5 py-2.5 rounded-xl shadow-sm transition-all duration-200 text-sm flex items-center gap-2 text-gray-300 hover:text-white hover:bg-gray-700/50">
                            <i class="fas fa-user-plus text-primary-400"></i> Select Users...
                        </button>
                        
                        <!-- Pills Container -->
                        <div id="selected_users_container" class="flex flex-wrap items-center gap-2">
                            <!-- Dynamic user pills injected via JS -->
                        </div>
                    </div>
                    <!-- Hidden input to store selected IDs if form submission is added later -->
                    <input type="hidden" name="turnover_users" id="hidden_turnover_users" value="">
                </div>

                <!-- Spreadsheet Remarks -->
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Remarks / Summary</label>
                    <div class="border border-gray-600/50 rounded-xl overflow-hidden bg-gray-900/30">
                        <table class="w-full text-left border-collapse">
                            <tbody>
                                <tr class="border-b border-gray-600/50 hover:bg-gray-800/30 transition-colors">
                                    <td class="py-3 px-4 w-1/3 md:w-1/4 bg-gray-800/50 text-xs font-semibold text-gray-400 uppercase tracking-wider border-r border-gray-600/50 align-middle">Last Roll Call</td>
                                    <td class="p-0 align-middle">
                                        <input type="text" value="none" class="spreadsheet-cell w-full h-full px-4 py-3 text-gray-200 text-sm transition-all" placeholder="Enter remarks...">
                                    </td>
                                </tr>
                                <tr class="border-b border-gray-600/50 hover:bg-gray-800/30 transition-colors">
                                    <td class="py-3 px-4 w-1/3 md:w-1/4 bg-gray-800/50 text-xs font-semibold text-gray-400 uppercase tracking-wider border-r border-gray-600/50 align-middle">Pending Coverage</td>
                                    <td class="p-0 align-middle">
                                        <input type="text" value="YES" class="spreadsheet-cell w-full h-full px-4 py-3 text-gray-200 text-sm transition-all" placeholder="Enter remarks...">
                                    </td>
                                </tr>
                                <tr class="border-b border-gray-600/50 hover:bg-gray-800/30 transition-colors">
                                    <td class="py-3 px-4 w-1/3 md:w-1/4 bg-gray-800/50 text-xs font-semibold text-gray-400 uppercase tracking-wider border-r border-gray-600/50 align-middle">Pending Email</td>
                                    <td class="p-0 align-middle">
                                        <input type="text" value="NO" class="spreadsheet-cell w-full h-full px-4 py-3 text-gray-200 text-sm transition-all" placeholder="Enter remarks...">
                                    </td>
                                </tr>
                                <tr class="hover:bg-gray-800/30 transition-colors">
                                    <td class="py-3 px-4 w-1/3 md:w-1/4 bg-gray-800/50 text-xs font-semibold text-gray-400 uppercase tracking-wider border-r border-gray-600/50 align-middle">Pending IR</td>
                                    <td class="p-0 align-middle">
                                        <input type="text" value="NO" class="spreadsheet-cell w-full h-full px-4 py-3 text-gray-200 text-sm transition-all" placeholder="Enter remarks...">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Specific Issues and Remarks/Notes -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-4">
                    <!-- Specific Issues -->
                    <div>
                        <label for="specific_issues" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Specific Issues Encountered During Shift</label>
                        <textarea id="specific_issues" name="specific_issues" rows="3" 
                               class="glass-input w-full px-4 py-3 rounded-xl shadow-inner transition-colors duration-200 text-sm custom-scrollbar"
                               placeholder="List any specific issues encountered..."></textarea>
                    </div>

                    <!-- Remarks/Notes -->
                    <div>
                        <label for="remarks_notes" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Remarks / Notes</label>
                        <textarea id="remarks_notes" name="remarks_notes" rows="3" 
                               class="glass-input w-full px-4 py-3 rounded-xl shadow-inner transition-colors duration-200 text-sm custom-scrollbar"
                               placeholder="Enter any additional remarks or notes..."></textarea>
                    </div>
                </div>

            </div>
        </div>

        <!-- Detailed Action Log Table -->
        <div class="glass-panel rounded-2xl overflow-hidden shadow-2xl mb-8">
            <div class="px-6 py-5 border-b border-gray-700/50 bg-gray-800/40 flex justify-between items-center">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fas fa-list-ol text-primary-400"></i> Detailed Activity Log
                </h3>
                <div class="flex items-center gap-3">
                    <span class="text-xs font-semibold text-gray-400 bg-gray-700/50 px-3 py-1.5 rounded-full border border-gray-600">
                        <?= $totalRecords ?> records found
                    </span>
                    <?php if (!empty($actionFilter)): ?>
                        <a href="<?= $cardBaseUrl ?>" class="text-xs font-semibold text-red-400 bg-red-500/10 hover:bg-red-500/20 px-3 py-1.5 rounded-full border border-red-500/30 transition-colors flex items-center gap-1 shadow-sm">
                            <i class="fas fa-times"></i> Clear Filter
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700/50">
                    <thead class="bg-gray-900/40">
                        <tr>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider w-40">Time (Action)</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider w-48">Action Type</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider w-32">Emp ID</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider w-48">Employee Name</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Details / Description</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700/50 bg-gray-800/20">
                        <?php if (empty($paginatedDetails)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-16 text-center text-gray-400">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="bg-gray-800/50 p-5 rounded-full mb-3 border border-gray-700">
                                            <i class="fas fa-mug-hot text-3xl text-gray-500"></i>
                                        </div>
                                        <p class="text-lg font-medium text-gray-300">No activity recorded</p>
                                        <p class="text-sm mt-1">No one has encoded anything for this specific selection.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($paginatedDetails as $row): 
                                $style = $actionStyles[$row['action_type']] ?? ['color' => 'text-gray-400', 'bg' => 'bg-gray-500/10', 'icon' => 'fa-info-circle', 'border' => 'border-gray-500/20'];
                            ?>
                            <tr class="hover:bg-gray-700/30 transition-colors duration-150">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-200">
                                        <?= date('h:i A', strtotime($row['action_time'])) ?>
                                    </div>
                                    <div class="text-[10px] text-gray-500 mt-0.5">
                                        <?= date('M d, Y', strtotime($row['action_time'])) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium <?= $style['bg'] ?> <?= $style['color'] ?> <?= $style['border'] ?> border">
                                        <i class="fas <?= $style['icon'] ?> mr-1.5"></i>
                                        <?= htmlspecialchars($row['action_type']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-sm font-mono text-gray-300"><?= htmlspecialchars($row['employee_id']) ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-200" style="text-transform: uppercase;">
                                        <?= htmlspecialchars($row['full_name']) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-400 truncate max-w-md" title="<?= htmlspecialchars($row['details']) ?>">
                                        <?= htmlspecialchars($row['details']) ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Controls -->
            <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 border-t border-gray-700/50 bg-gray-800/40 flex items-center justify-between">
                <div class="text-sm text-gray-400">
                    Showing <?= ($offset + 1) ?> to <?= min($offset + $recordsPerPage, $totalRecords) ?> of <?= $totalRecords ?> records
                </div>
                
                <div class="flex gap-1">
                    <?php 
                        // Preserve URL parameters for pagination links
                        $urlParams = $_GET;
                        unset($urlParams['page']); // Remove current page from base parameters
                        $baseUrl = 'shift_report.php?' . http_build_query($urlParams) . '&page=';
                    ?>
                    
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= $baseUrl . '1' ?>" class="px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors flex items-center justify-center">
                            <i class="fas fa-angle-double-left text-xs"></i>
                        </a>
                        <a href="<?= $baseUrl . ($currentPage - 1) ?>" class="px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors flex items-center justify-center">
                            <i class="fas fa-angle-left text-xs"></i>
                        </a>
                    <?php endif; ?>

                    <?php 
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="<?= $baseUrl . $i ?>" class="px-3 py-1 rounded-lg border <?= $i == $currentPage ? 'bg-primary-600 border-primary-600 text-white shadow-md shadow-primary-900/50' : 'border-gray-600 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="<?= $baseUrl . ($currentPage + 1) ?>" class="px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors flex items-center justify-center">
                            <i class="fas fa-angle-right text-xs"></i>
                        </a>
                        <a href="<?= $baseUrl . $totalPages ?>" class="px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors flex items-center justify-center">
                            <i class="fas fa-angle-double-right text-xs"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- Turnover Selection Modal -->
<div id="turnoverModal" class="fixed inset-0 z-[100] hidden bg-black/60 backdrop-blur-sm flex items-center justify-center transition-opacity duration-300 opacity-0">
    <div class="glass-panel rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform transition-transform scale-95 border border-gray-700/50">
        <div class="px-6 py-4 border-b border-gray-700/50 bg-gray-800/60 flex justify-between items-center">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fas fa-users text-primary-400"></i> Select Turnover Users
            </h3>
            <button type="button" onclick="closeTurnoverModal()" class="text-gray-400 hover:text-white transition-colors bg-gray-800/50 rounded-full p-2 hover:bg-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-4 max-h-96 overflow-y-auto custom-scrollbar bg-gray-900/30">
            <div class="space-y-1">
                <?php foreach ($allUsers as $u): ?>
                    <label class="flex items-center p-3 rounded-xl hover:bg-gray-700/40 cursor-pointer border border-transparent hover:border-gray-600/50 transition-all group">
                        <div class="relative flex items-center justify-center">
                            <input type="checkbox" value="<?= htmlspecialchars($u['id']) ?>" data-name="<?= htmlspecialchars($u['sub_name']) ?>" class="turnover-checkbox w-5 h-5 text-primary-600 bg-gray-800 border-gray-600 rounded focus:ring-primary-500 focus:ring-2 cursor-pointer transition-all">
                        </div>
                        <div class="ml-3 flex flex-col">
                            <span class="text-sm font-medium text-gray-200 group-hover:text-white transition-colors"><?= htmlspecialchars($u['sub_name']) ?></span>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-700/50 bg-gray-800/60 flex justify-end">
            <button type="button" onclick="closeTurnoverModal()" class="px-6 py-2 bg-primary-600 hover:bg-primary-500 text-white rounded-xl shadow-lg shadow-primary-900/30 transition-all font-semibold text-sm">
                Done
            </button>
        </div>
    </div>
</div>

<script>
    // Modal Open/Close Logic
    function openTurnoverModal() {
        const modal = document.getElementById('turnoverModal');
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.querySelector('.glass-panel').classList.remove('scale-95');
            modal.querySelector('.glass-panel').classList.add('scale-100');
        }, 10);
    }

    function closeTurnoverModal() {
        const modal = document.getElementById('turnoverModal');
        modal.classList.add('opacity-0');
        modal.querySelector('.glass-panel').classList.remove('scale-100');
        modal.querySelector('.glass-panel').classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    }

    // Handle Checkbox Changes
    document.querySelectorAll('.turnover-checkbox').forEach(cb => {
        cb.addEventListener('change', updateTurnoverSelection);
    });

    function updateTurnoverSelection() {
        const container = document.getElementById('selected_users_container');
        const hiddenInput = document.getElementById('hidden_turnover_users');
        container.innerHTML = ''; // clear current UI
        
        let selectedIds = [];

        document.querySelectorAll('.turnover-checkbox:checked').forEach(cb => {
            const id = cb.value;
            const name = cb.getAttribute('data-name');
            selectedIds.push(id);

            // Create tag/pill
            const pill = document.createElement('div');
            pill.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary-500/20 border border-primary-500/40 text-primary-300 text-xs font-semibold shadow-sm animate-fade-in transition-all hover:bg-primary-500/30';
            pill.innerHTML = `
                ${name}
                <button type="button" onclick="removeTurnoverUser('${id}')" class="text-primary-400 hover:text-red-400 focus:outline-none transition-colors ml-1 p-0.5 rounded-full hover:bg-red-400/10 flex items-center justify-center">
                    <i class="fas fa-times text-[10px]"></i>
                </button>
            `;
            container.appendChild(pill);
        });

        // Update hidden field
        hiddenInput.value = selectedIds.join(',');
    }

    // Function to remove via "X" button on the pill
    function removeTurnoverUser(id) {
        const cb = document.querySelector(`.turnover-checkbox[value="${id}"]`);
        if (cb) {
            cb.checked = false;
            updateTurnoverSelection();
        }
    }

    // Close modal when clicking outside
    document.getElementById('turnoverModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeTurnoverModal();
        }
    });
</script>

<?php renderFooter(); ?>