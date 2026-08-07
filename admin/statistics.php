<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../components/layout.php';

// Ensure user is logged in
if (!isLoggedIn()) {
    redirect(BASE_URL);
}

updateLastActivity();
include '../connection.php';

// Define pagination parameters
$ticketsPerPage = 10;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($currentPage - 1) * $ticketsPerPage;

// --- DATA FETCHING FUNCTIONS ---

function getTotalTicketsCount($con) {
    $result = mysqli_query($con, "SELECT COUNT(*) as total FROM ticket");
    return mysqli_fetch_assoc($result)['total'];
}

function getResolvedTicketsCount($con) {
    $result = mysqli_query($con, "SELECT COUNT(*) as total FROM ticket WHERE Status = 'RESOLVED'");
    return mysqli_fetch_assoc($result)['total'];
}

function getPendingTicketsCount($con) {
    $result = mysqli_query($con, "SELECT COUNT(*) as total FROM ticket WHERE Status = 'PENDING'");
    return mysqli_fetch_assoc($result)['total'];
}

// Avg Resolution Time (Kept for KPI Card)
function getResolutionStats($con) {
    $query = "SELECT TIME_RECEIVED, TIME_RESOLVED FROM ticket WHERE Status = 'RESOLVED' AND Issues_Concerning != 'HRIS Concern'";
    $result = mysqli_query($con, $query);
    $totalDiff = 0; $count = 0; $slaCompliant = 0;
    
    while($row = mysqli_fetch_assoc($result)) {
        if(empty($row['TIME_RECEIVED']) || empty($row['TIME_RESOLVED']) || $row['TIME_RESOLVED'] === 'PENDING') continue;
        
        $rec = strtotime($row['TIME_RECEIVED']);
        $res = strtotime($row['TIME_RESOLVED']);
        
        if ($res && $rec) {
            if ($res < $rec) $res += 86400; // Handle midnight crossover
            
            $diff = $res - $rec;
            $totalDiff += $diff;
            $count++;
            
            if ($diff <= 1800) $slaCompliant++; // Under 30 mins
        }
    }
    
    if ($count === 0) return ['text' => '0 mins', 'rate' => 0];
    
    $avgMins = round(($totalDiff / $count) / 60);
    $rate = round(($slaCompliant / $count) * 100);
    $text = $avgMins >= 60 ? floor($avgMins / 60) . "h " . ($avgMins % 60) . "m" : "{$avgMins} mins";
    
    return ['text' => $text, 'rate' => $rate];
}

// Top Resolvers (Filtered)
function getTopResolversFiltered($con, $month, $year) {
    $where = ["Status = 'RESOLVED'", "SLT_on_DUTY IS NOT NULL", "SLT_on_DUTY != 'PENDING'", "SLT_on_DUTY != ''"];
    if (!empty($month)) $where[] = "MONTH(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = " . intval($month);
    if (!empty($year)) $where[] = "YEAR(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = " . intval($year);
    
    $whereClause = "WHERE " . implode(' AND ', $where);
    $query = "SELECT SLT_on_DUTY, COUNT(*) as resolved_count 
              FROM ticket $whereClause 
              GROUP BY SLT_on_DUTY 
              ORDER BY resolved_count DESC";
    $result = mysqli_query($con, $query);
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// Top Employee Submitters (Filtered)
function getTopSubmittersFiltered($con, $month, $year) {
    $where = [];
    if (!empty($month)) $where[] = "MONTH(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = " . intval($month);
    if (!empty($year)) $where[] = "YEAR(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = " . intval($year);
    $whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
    
    $query = "SELECT Employee_name, LOB, COUNT(*) as ticket_count 
              FROM ticket $whereClause 
              GROUP BY Employee_name, LOB 
              ORDER BY ticket_count DESC LIMIT 8";
    $result = mysqli_query($con, $query);
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// Common Issues (Filtered)
function getCommonIssuesFiltered($con, $month, $year) {
    $where = ["Issues_Concerning IS NOT NULL", "Issues_Concerning != ''"];
    if (!empty($month)) $where[] = "MONTH(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = " . intval($month);
    if (!empty($year)) $where[] = "YEAR(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = " . intval($year);
    $whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
    
    $query = "SELECT Issues_Concerning, COUNT(*) as issue_count 
              FROM ticket $whereClause 
              GROUP BY Issues_Concerning 
              ORDER BY issue_count DESC LIMIT 12";
    $result = mysqli_query($con, $query);
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// Get Available Years for Filter (Existing Data Only)
function getAvailableYears($con) {
    $query = "SELECT DISTINCT YEAR(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) as year FROM ticket WHERE Timestamp IS NOT NULL AND Timestamp != '' ORDER BY year DESC";
    $result = mysqli_query($con, $query);
    $years = [];
    if($result) {
        foreach(mysqli_fetch_all($result, MYSQLI_ASSOC) as $row) {
            if ($row['year']) $years[] = $row['year'];
        }
    }
    return empty($years) ? [date('Y')] : $years;
}

// Get Available Months for Filter (Existing Data Only)
function getAvailableMonths($con) {
    $query = "SELECT DISTINCT MONTH(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) as month FROM ticket WHERE Timestamp IS NOT NULL AND Timestamp != '' ORDER BY month ASC";
    $result = mysqli_query($con, $query);
    $months = [];
    if($result) {
        foreach(mysqli_fetch_all($result, MYSQLI_ASSOC) as $row) {
            if ($row['month']) $months[] = $row['month'];
        }
    }
    return $months;
}

// Function to grab all distinct issues for the new filter dropdown
function getAllDistinctIssues($con) {
    $result = mysqli_query($con, "SELECT DISTINCT Issues_Concerning FROM ticket WHERE Issues_Concerning IS NOT NULL AND Issues_Concerning != '' ORDER BY Issues_Concerning ASC");
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// Static Breakdowns for Selectors/Charts
function getIssueBreakdown($con) {
    $result = mysqli_query($con, "SELECT Issues_Concerning, COUNT(*) as issue_count FROM ticket WHERE Issues_Concerning IS NOT NULL AND Issues_Concerning != '' GROUP BY Issues_Concerning ORDER BY issue_count DESC LIMIT 12");
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function getIssueBreakdownFiltered($con, $month, $year) {
    $where = ["Issues_Concerning IS NOT NULL", "Issues_Concerning != ''"];
    if (!empty($month)) $where[] = "MONTH(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = " . intval($month);
    if (!empty($year)) $where[] = "YEAR(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = " . intval($year);
    $whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
    
    $query = "SELECT Issues_Concerning, COUNT(*) as issue_count 
              FROM ticket $whereClause 
              GROUP BY Issues_Concerning 
              ORDER BY issue_count DESC LIMIT 12";
    $result = mysqli_query($con, $query);
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function getLOBBreakdown($con) {
    $result = mysqli_query($con, "SELECT LOB, COUNT(*) as count FROM ticket WHERE LOB IS NOT NULL AND LOB != '' GROUP BY LOB ORDER BY count DESC");
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function getUrgencyBreakdown($con) {
    $result = mysqli_query($con, "SELECT Urgency, COUNT(*) as count FROM ticket WHERE Urgency IS NOT NULL AND Urgency != '' GROUP BY Urgency ORDER BY count DESC");
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// Employee specific history summary
function getEmployeeSummary($con, $employee) {
    $summary = ['total' => 0, 'resolved' => 0, 'pending' => 0];
    $stmt = mysqli_prepare($con, "SELECT Status, COUNT(*) as count FROM ticket WHERE Employee_name = ? GROUP BY Status");
    mysqli_stmt_bind_param($stmt, "s", $employee);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while($row = mysqli_fetch_assoc($result)) {
        $summary['total'] += $row['count'];
        if ($row['Status'] === 'RESOLVED') $summary['resolved'] = $row['count'];
        if ($row['Status'] === 'PENDING') $summary['pending'] = $row['count'];
    }
    
    $issues = [];
    $stmt2 = mysqli_prepare($con, "SELECT DISTINCT Issues_Concerning FROM ticket WHERE Employee_name = ? AND Issues_Concerning != '' AND Issues_Concerning IS NOT NULL");
    mysqli_stmt_bind_param($stmt2, "s", $employee);
    mysqli_stmt_execute($stmt2);
    $result2 = mysqli_stmt_get_result($stmt2);
    while($row = mysqli_fetch_assoc($result2)) {
        $issues[] = $row['Issues_Concerning'];
    }
    
    return ['summary' => $summary, 'issues' => $issues];
}

// Filtered Data Functions for Master Table
function getFilteredTickets($con, $filters, $limit, $offset) {
    $where = [];
    $types = "";
    $params = [];

    // Implement search across multiple columns
    if (!empty($filters['search'])) {
        $searchTerm = "%" . $filters['search'] . "%";
        $where[] = "(Employee_name LIKE ? OR OM LIKE ? OR Issues_Concerning LIKE ? OR LOB LIKE ? OR Status LIKE ? OR id LIKE ?)";
        $types .= "ssssss";
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    if (!empty($filters['employee'])) { $where[] = "Employee_name = ?"; $types .= "s"; $params[] = $filters['employee']; }
    if (!empty($filters['issue'])) { $where[] = "Issues_Concerning = ?"; $types .= "s"; $params[] = $filters['issue']; }
    if (!empty($filters['lob'])) { $where[] = "LOB = ?"; $types .= "s"; $params[] = $filters['lob']; }
    if (!empty($filters['urgency'])) { $where[] = "Urgency = ?"; $types .= "s"; $params[] = $filters['urgency']; }
    if (!empty($filters['status'])) { $where[] = "Status = ?"; $types .= "s"; $params[] = strtoupper($filters['status']); }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $query = "SELECT * FROM ticket $whereClause ORDER BY STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s') DESC LIMIT ? OFFSET ?";
    
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = mysqli_prepare($con, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function getFilteredTicketsCount($con, $filters) {
    $where = [];
    $types = "";
    $params = [];

    if (!empty($filters['search'])) {
        $searchTerm = "%" . $filters['search'] . "%";
        $where[] = "(Employee_name LIKE ? OR OM LIKE ? OR Issues_Concerning LIKE ? OR LOB LIKE ? OR Status LIKE ? OR id LIKE ?)";
        $types .= "ssssss";
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    if (!empty($filters['employee'])) { $where[] = "Employee_name = ?"; $types .= "s"; $params[] = $filters['employee']; }
    if (!empty($filters['issue'])) { $where[] = "Issues_Concerning = ?"; $types .= "s"; $params[] = $filters['issue']; }
    if (!empty($filters['lob'])) { $where[] = "LOB = ?"; $types .= "s"; $params[] = $filters['lob']; }
    if (!empty($filters['urgency'])) { $where[] = "Urgency = ?"; $types .= "s"; $params[] = $filters['urgency']; }
    if (!empty($filters['status'])) { $where[] = "Status = ?"; $types .= "s"; $params[] = strtoupper($filters['status']); }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $query = "SELECT COUNT(*) as total FROM ticket $whereClause";
    
    $stmt = mysqli_prepare($con, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
}

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $response = [];
    $action = $_GET['action'] ?? '';
    
    if ($action === 'getFilteredTickets') {
        $page = (int)($_GET['page'] ?? 1);
        $offset = ($page - 1) * $ticketsPerPage;
        $filters = [
            'search' => $_GET['search'] ?? null,
            'employee' => $_GET['employee'] ?? null,
            'issue' => $_GET['issue'] ?? null,
            'status' => $_GET['status'] ?? null,
            'lob' => $_GET['lob'] ?? null,
            'urgency' => $_GET['urgency'] ?? null,
        ];
        $filters = array_filter($filters, function($value) { return $value !== null && $value !== ''; });

        $tickets = getFilteredTickets($con, $filters, $ticketsPerPage, $offset);
        $totalTickets = getFilteredTicketsCount($con, $filters);
        
        $response['tickets'] = $tickets;
        $response['totalPages'] = ceil($totalTickets / $ticketsPerPage);
        $response['currentPage'] = $page;
        $response['totalTicketsCount'] = $totalTickets;
    } 
    elseif ($action === 'getEmployeeSummary') {
        $response = getEmployeeSummary($con, $_GET['employee'] ?? '');
    }
    elseif ($action === 'getTopData') {
        $month = $_GET['month'] ?? '';
        $year = $_GET['year'] ?? '';
        $response['employees'] = getTopSubmittersFiltered($con, $month, $year);
        $response['issues'] = getCommonIssuesFiltered($con, $month, $year);
    }
    elseif ($action === 'getIssuesBreakdownData') {
        $month = $_GET['month'] ?? '';
        $year = $_GET['year'] ?? '';
        $response = getIssueBreakdownFiltered($con, $month, $year);
    }
    elseif ($action === 'getResolversData') {
        $month = $_GET['month'] ?? '';
        $year = $_GET['year'] ?? '';
        $response = getTopResolversFiltered($con, $month, $year);
    }
    elseif ($action === 'getTrendData') {
        $type = $_GET['type'] ?? 'monthly';
        if ($type === 'daily') {
            $query = "SELECT DATE_FORMAT(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s'), '%b %d') as label, 
                             DATE_FORMAT(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s'), '%Y-%m-%d') as sort_date, 
                             COUNT(*) as count 
                      FROM ticket WHERE Timestamp IS NOT NULL AND Timestamp != '' 
                      GROUP BY label, sort_date 
                      ORDER BY sort_date DESC LIMIT 14";
        } elseif ($type === 'weekly') {
            $query = "SELECT Week_Beginning as label, 
                             STR_TO_DATE(Week_Beginning, '%m/%d/%Y') as sort_date, 
                             COUNT(*) as count 
                      FROM ticket WHERE Week_Beginning IS NOT NULL AND Week_Beginning != '' 
                      GROUP BY label, sort_date 
                      ORDER BY sort_date DESC LIMIT 12";
        } else { // monthly
            $query = "SELECT DATE_FORMAT(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s'), '%b %Y') as label, 
                             DATE_FORMAT(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s'), '%Y-%m') as sort_date, 
                             COUNT(*) as count 
                      FROM ticket WHERE Timestamp IS NOT NULL AND Timestamp != '' 
                      GROUP BY label, sort_date 
                      ORDER BY sort_date DESC LIMIT 12";
        }
        $res = mysqli_query($con, $query);
        $data = mysqli_fetch_all($res, MYSQLI_ASSOC);
        $response = array_reverse($data); // Reverse to show chronological order
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Initial Data Load
$totalTicketsCount = getTotalTicketsCount($con);
$resolvedTicketsCount = getResolvedTicketsCount($con);
$pendingTicketsCount = getPendingTicketsCount($con);
$resolutionStats = getResolutionStats($con);

$availableYears = getAvailableYears($con);
$availableMonths = getAvailableMonths($con);
$allIssuesList = getAllDistinctIssues($con);
$issueBreakdown = getIssueBreakdown($con);
$lobBreakdown = getLOBBreakdown($con);
$urgencyBreakdown = getUrgencyBreakdown($con);

renderHead('Ticket Statistics');
renderNavbar();
renderSidebar('statistics');
?>

<style>
    .modal-scroll::-webkit-scrollbar { width: 6px; }
    .modal-scroll::-webkit-scrollbar-track { background: rgba(31, 41, 55, 0.5); border-radius: 4px; }
    .modal-scroll::-webkit-scrollbar-thumb { background: rgba(75, 85, 99, 0.8); border-radius: 4px; }
    .modal-scroll::-webkit-scrollbar-thumb:hover { background: rgba(107, 114, 128, 1); }
    .rotate-180 { transform: rotate(180deg); }
    .transition-transform { transition: transform 0.3s ease; }
</style>

<div class="pt-6 min-h-screen text-gray-200 bg-gray-900/95 pb-12">
    <main class="p-6 w-full lg:px-8 xl:px-12 mx-auto space-y-6 relative">
        
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 bg-gray-800/40 p-6 rounded-2xl border border-gray-700/50 backdrop-blur-sm">
            <div>
                <h1 class="text-3xl font-extrabold text-white tracking-tight flex items-center gap-3">
                    <i class="fas fa-chart-pie text-blue-500"></i> Analytics Dashboard
                </h1>
                <p class="text-gray-400 text-sm mt-2">Comprehensive breakdown of system performance, employee issues, and ticket trends.</p>
            </div>
            <div class="flex items-center gap-3 bg-gray-900/80 px-5 py-3 rounded-xl border border-gray-700 shadow-inner">
                <div class="text-right">
                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider"><?= date('F j, Y') ?></div>
                    <div id="realtime-clock" class="text-xl font-mono text-blue-400 font-bold leading-none mt-1"></div>
                </div>
                <div class="h-10 w-px bg-gray-700 mx-2"></div>
                <i class="fas fa-satellite-dish text-blue-500/50 text-2xl animate-pulse"></i>
            </div>
        </div>

        <!-- KPI Quick Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-gradient-to-br from-gray-800 to-gray-800/80 rounded-2xl p-6 border border-gray-700 shadow-lg relative overflow-hidden group cursor-pointer" onclick="applyFilter('status', '')">
                <div class="absolute -right-6 -top-6 text-blue-500/10 group-hover:text-blue-500/20 transition-colors duration-300">
                    <i class="fas fa-ticket-alt text-9xl"></i>
                </div>
                <p class="text-gray-400 text-sm font-bold uppercase tracking-wider mb-1">Total Volume</p>
                <h3 class="text-4xl font-black text-white relative z-10"><?= number_format($totalTicketsCount) ?></h3>
            </div>
            
            <div class="bg-gradient-to-br from-gray-800 to-gray-800/80 rounded-2xl p-6 border border-gray-700 shadow-lg relative overflow-hidden group cursor-pointer" onclick="applyFilter('status', 'resolved')">
                <div class="absolute -right-6 -top-6 text-green-500/10 group-hover:text-green-500/20 transition-colors duration-300">
                    <i class="fas fa-check-circle text-9xl"></i>
                </div>
                <p class="text-gray-400 text-sm font-bold uppercase tracking-wider mb-1">Resolved</p>
                <h3 class="text-4xl font-black text-white relative z-10"><?= number_format($resolvedTicketsCount) ?></h3>
                <div class="mt-2 text-xs font-medium text-green-400 bg-green-500/10 inline-block px-2 py-1 rounded">
                    <?= $totalTicketsCount > 0 ? round(($resolvedTicketsCount / $totalTicketsCount) * 100) : 0 ?>% Completion
                </div>
            </div>

            <div class="bg-gradient-to-br from-gray-800 to-gray-800/80 rounded-2xl p-6 border border-gray-700 shadow-lg relative overflow-hidden group cursor-pointer" onclick="applyFilter('status', 'pending')">
                <div class="absolute -right-6 -top-6 text-yellow-500/10 group-hover:text-yellow-500/20 transition-colors duration-300">
                    <i class="fas fa-clock text-9xl"></i>
                </div>
                <p class="text-gray-400 text-sm font-bold uppercase tracking-wider mb-1">Pending Actions</p>
                <h3 class="text-4xl font-black text-white relative z-10"><?= number_format($pendingTicketsCount) ?></h3>
                <div class="mt-2 text-xs font-medium text-yellow-400 bg-yellow-500/10 inline-block px-2 py-1 rounded">
                    Requires Attention
                </div>
            </div>

            <!-- Average Resolution Time KPI -->
            <div class="bg-gradient-to-br from-gray-800 to-gray-800/80 rounded-2xl p-6 border border-gray-700 shadow-lg relative overflow-hidden group">
                <div class="absolute -right-6 -top-6 text-purple-500/10 group-hover:text-purple-500/20 transition-colors duration-300">
                    <i class="fas fa-stopwatch text-9xl"></i>
                </div>
                <p class="text-gray-400 text-sm font-bold uppercase tracking-wider mb-1">Avg Resolution Time</p>
                <h3 class="text-4xl font-black text-white relative z-10"><?= $resolutionStats['text'] ?></h3>
                <div class="mt-2 text-xs font-medium text-purple-400 bg-purple-500/10 inline-block px-2 py-1 rounded">
                    <?= $resolutionStats['rate'] ?>% Under 30m SLA
                </div>
            </div>
        </div>

        <!-- Filter Bar for Insights -->
        <div class="flex flex-col sm:flex-row justify-between items-center mt-6 pt-4 border-t border-gray-700/50">
            <h2 class="text-2xl font-bold text-white flex items-center gap-2 mb-4 sm:mb-0">
                <i class="fas fa-lightbulb text-yellow-500"></i> Top Insights
            </h2>
            <div class="flex items-center gap-3">
                <select id="insight-month" onchange="loadTopInsights()" class="bg-gray-800 border border-gray-600 text-gray-300 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-2 outline-none">
                    <option value="">All Months</option>
                    <?php foreach($availableMonths as $m): ?>
                        <option value="<?= $m ?>"><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="insight-year" onchange="loadTopInsights()" class="bg-gray-800 border border-gray-600 text-gray-300 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-2 outline-none">
                    <option value="">All Years</option>
                    <?php foreach($availableYears as $y): ?>
                        <option value="<?= $y ?>"><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Insights Row 1: Employees and Common Issues Tables -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Who is having the most issues? -->
            <div class="bg-gray-800 rounded-2xl border border-gray-700 shadow-xl p-6 flex flex-col">
                <div class="mb-6">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas fa-users-slash text-blue-400"></i> Employees w/ Most Issues
                    </h3>
                    <p class="text-xs text-gray-400 mt-1">Click an employee to view their complete history.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" id="top-employees-container">
                    <!-- Populated via JS -->
                </div>
            </div>

            <!-- What issues are common? -->
            <div class="bg-gray-800 rounded-2xl border border-gray-700 shadow-xl p-6 flex flex-col">
                <div class="mb-6">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas fa-fire text-red-400"></i> Most Common Issues
                    </h3>
                    <p class="text-xs text-gray-400 mt-1">Top categories repeatedly reported.</p>
                </div>
                <div class="space-y-4 overflow-y-auto modal-scroll pr-2 max-h-[300px]" id="common-issues-container">
                    <!-- Populated via JS -->
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Issues Breakdown Pie Chart -->
            <div class="bg-gray-800 rounded-2xl border border-gray-700 shadow-xl p-6 flex flex-col">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <i class="fas fa-chart-pie text-purple-400"></i> Issues Breakdown
                        </h3>
                        <p class="text-xs text-gray-400 mt-1">Distribution of recorded issue categories.</p>
                    </div>
                    <div class="flex flex-col xl:flex-row gap-2">
                        <select id="chart-month" onchange="loadIssuesChart()" class="bg-gray-700 border border-gray-600 text-gray-300 text-xs rounded-lg focus:ring-purple-500 focus:border-purple-500 px-2 py-1.5 outline-none shadow-sm cursor-pointer w-full xl:w-auto">
                            <option value="">All Months</option>
                            <?php foreach($availableMonths as $m): ?>
                                <option value="<?= $m ?>"><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="chart-year" onchange="loadIssuesChart()" class="bg-gray-700 border border-gray-600 text-gray-300 text-xs rounded-lg focus:ring-purple-500 focus:border-purple-500 px-2 py-1.5 outline-none shadow-sm cursor-pointer w-full xl:w-auto">
                            <option value="">All Years</option>
                            <?php foreach($availableYears as $y): ?>
                                <option value="<?= $y ?>"><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex-1 relative min-h-[250px]">
                    <canvas id="issuesPieChart"></canvas>
                </div>
            </div>

            <!-- Volume Trends (Dynamic) -->
            <div class="bg-gray-800 rounded-2xl border border-gray-700 shadow-xl p-6 lg:col-span-2 flex flex-col">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <i class="fas fa-chart-line text-green-400"></i> Ticket Volume Trend
                        </h3>
                        <p class="text-xs text-gray-400 mt-1">System traffic over selected time periods.</p>
                    </div>
                    <select id="trend-type" onchange="loadTrendChart(this.value)" class="bg-gray-700 border border-gray-600 text-gray-300 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 px-3 py-1.5 outline-none shadow-sm cursor-pointer">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly" selected>Monthly</option>
                    </select>
                </div>
                <div class="relative flex-1 min-h-[250px]">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- NEW: Team Performance Row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-2">
            <!-- Top Resolvers List (Now Scrollable & Shows All) -->
            <div class="bg-gray-800 rounded-2xl border border-gray-700 shadow-xl p-6 flex flex-col h-[400px]">
                <div class="mb-4">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas fa-medal text-yellow-400"></i> Top Resolvers
                    </h3>
                    <p class="text-xs text-gray-400 mt-1">All SLT members by resolved tickets.</p>
                </div>
                <div class="space-y-3 overflow-y-auto modal-scroll pr-2 flex-1" id="resolvers-list-container">
                    <!-- Populated via JS -->
                </div>
            </div>

            <!-- Resolvers Bar Chart -->
            <div class="bg-gray-800 rounded-2xl border border-gray-700 shadow-xl p-6 lg:col-span-2 flex flex-col h-[400px]">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <i class="fas fa-chart-bar text-purple-400"></i> Monthly Top Resolvers
                        </h3>
                        <p class="text-xs text-gray-400 mt-1">Resolution volume by SLT member.</p>
                    </div>
                    <div class="flex gap-2 w-full sm:w-auto">
                        <select id="resolver-month" onchange="loadResolversData()" class="bg-gray-700 border border-gray-600 text-gray-300 text-xs rounded-lg focus:ring-purple-500 focus:border-purple-500 px-2 py-1.5 outline-none shadow-sm cursor-pointer flex-1 sm:flex-none">
                            <option value="">All Months</option>
                            <?php foreach($availableMonths as $m): ?>
                                <option value="<?= $m ?>"><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="resolver-year" onchange="loadResolversData()" class="bg-gray-700 border border-gray-600 text-gray-300 text-xs rounded-lg focus:ring-purple-500 focus:border-purple-500 px-2 py-1.5 outline-none shadow-sm cursor-pointer flex-1 sm:flex-none">
                            <option value="">All Years</option>
                            <?php foreach($availableYears as $y): ?>
                                <option value="<?= $y ?>"><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="relative flex-1">
                    <canvas id="resolversBarChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Master Data Table with Filters -->
        <div class="bg-gray-800 rounded-2xl border border-gray-700 shadow-xl overflow-hidden mt-8" id="master-table-section">
            <div class="p-5 border-b border-gray-700 bg-gray-800/80 flex flex-col xl:flex-row gap-4 justify-between items-start xl:items-center">
                <div class="flex items-center gap-3">
                    <div class="bg-blue-500/20 p-2 rounded-lg">
                        <i class="fas fa-table text-blue-400"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white">Data Explorer</h3>
                        <p class="text-xs text-gray-400">Filter and find specific tickets easily</p>
                    </div>
                </div>
                
                <form id="master-filter-form" class="flex flex-col lg:flex-row flex-wrap items-center gap-3 w-full xl:w-auto">
                    
                    <!-- Global Search Bar -->
                    <div class="relative w-full lg:w-64">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-500"></i>
                        </div>
                        <input type="text" name="search" id="search-input" placeholder="Search ID, OM, Issue..." class="bg-gray-900 border border-gray-600 text-gray-200 text-sm rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 px-3 py-2 outline-none transition-all shadow-sm">
                    </div>

                    <select name="lob" class="bg-gray-900 border border-gray-600 text-gray-300 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-2 outline-none w-full lg:w-auto">
                        <option value="">All Departments</option>
                        <?php foreach($lobBreakdown as $lob): ?>
                            <option value="<?= htmlspecialchars($lob['LOB']) ?>"><?= htmlspecialchars($lob['LOB']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status" class="bg-gray-900 border border-gray-600 text-gray-300 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-2 outline-none w-full lg:w-auto">
                        <option value="">All Statuses</option>
                        <option value="RESOLVED">Resolved</option>
                        <option value="PENDING">Pending</option>
                    </select>

                    <select name="urgency" class="bg-gray-900 border border-gray-600 text-gray-300 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-2 outline-none w-full lg:w-auto">
                        <option value="">All Urgencies</option>
                        <?php foreach($urgencyBreakdown as $urg): ?>
                            <option value="<?= htmlspecialchars($urg['Urgency']) ?>"><?= htmlspecialchars($urg['Urgency']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="issue" id="filter-issue" class="bg-gray-900 border border-gray-600 text-gray-300 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-2 outline-none w-full lg:w-auto">
                        <option value="">All Issues</option>
                        <?php foreach($allIssuesList as $iss): ?>
                            <option value="<?= htmlspecialchars($iss['Issues_Concerning']) ?>"><?= htmlspecialchars($iss['Issues_Concerning']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="hidden" name="employee" id="filter-employee" value="">

                    <button type="button" onclick="resetFilters()" class="px-4 py-2 text-sm font-medium text-gray-300 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors border border-gray-600 shadow-sm w-full lg:w-auto">
                        Reset
                    </button>
                </form>
            </div>

            <!-- Active Filter Chips -->
            <div id="active-filters-container" class="px-6 py-3 bg-gray-900/50 border-b border-gray-700 hidden flex-wrap gap-2 items-center">
                <span class="text-xs text-gray-500 font-semibold uppercase">Active Filters:</span>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full text-left">
                    <thead>
                        <tr class="bg-gray-900 text-gray-400 text-xs uppercase tracking-wider font-semibold">
                            <th class="py-4 px-6">ID</th>
                            <th class="py-4 px-6">Employee</th>
                            <th class="py-4 px-6">Department</th>
                            <th class="py-4 px-6">OM</th>
                            <th class="py-4 px-6">Issue</th>
                            <th class="py-4 px-6">Status</th>
                            <th class="py-4 px-6">Urgency</th>
                            <th class="py-4 px-6 text-right">Date</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-300 text-sm divide-y divide-gray-700/50" id="main-table-body">
                        <!-- Populated via AJAX -->
                    </tbody>
                </table>
            </div>
            
            <div class="p-5 border-t border-gray-700 bg-gray-800/80 flex justify-between items-center">
                <div class="text-sm text-gray-400" id="table-record-info">Loading...</div>
                <div id="pagination-controls" class="flex space-x-2"></div>
            </div>
        </div>

        <!-- ================= EMPLOYEE HISTORY MODAL ================= -->
        <div id="employeeModal" class="fixed inset-0 z-[100] hidden bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 sm:p-6 opacity-0 transition-opacity duration-300">
            <div class="bg-gray-800 rounded-2xl border border-gray-600 shadow-2xl w-full max-w-7xl max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300" id="employeeModalInner">
                
                <!-- Modal Header -->
                <div class="p-5 border-b border-gray-700 flex justify-between items-center bg-gray-900/80 relative overflow-hidden">
                    <div class="absolute right-0 top-0 w-32 h-32 bg-blue-500/10 rounded-bl-full -mr-10 -mt-10"></div>
                    <div class="relative z-10">
                        <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                            <i class="fas fa-user-circle text-blue-400 text-3xl"></i>
                            <span id="modal-employee-name">Employee</span>
                        </h2>
                        <p class="text-sm text-gray-400 mt-1">Complete ticket history and specific issue breakdown. Click a row to see full details.</p>
                    </div>
                    <button onclick="closeEmployeeModal()" class="text-gray-400 hover:text-white bg-gray-800 hover:bg-gray-700 w-10 h-10 rounded-full flex items-center justify-center transition-colors border border-gray-600 relative z-10">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>
                
                <!-- Modal Body -->
                <div class="p-6 overflow-y-auto modal-scroll flex-1 bg-gray-800/50">
                    
                    <!-- Stats Summary -->
                    <div class="grid grid-cols-3 gap-4 mb-8">
                        <div class="bg-gray-700/40 p-5 rounded-xl border border-gray-600/50 flex flex-col justify-center">
                            <div class="text-xs text-gray-400 uppercase font-semibold tracking-wider">Total Tickets</div>
                            <div class="text-3xl font-black text-white mt-1" id="modal-stat-total">0</div>
                        </div>
                        <div class="bg-green-500/10 p-5 rounded-xl border border-green-500/20 flex flex-col justify-center">
                            <div class="text-xs text-green-400 uppercase font-semibold tracking-wider">Resolved</div>
                            <div class="text-3xl font-black text-green-300 mt-1" id="modal-stat-resolved">0</div>
                        </div>
                        <div class="bg-yellow-500/10 p-5 rounded-xl border border-yellow-500/20 flex flex-col justify-center">
                            <div class="text-xs text-yellow-400 uppercase font-semibold tracking-wider">Pending</div>
                            <div class="text-3xl font-black text-yellow-300 mt-1" id="modal-stat-pending">0</div>
                        </div>
                    </div>

                    <!-- Modal Data Explorer -->
                    <div class="bg-gray-900/50 rounded-xl border border-gray-700 overflow-hidden shadow-inner">
                        <div class="p-4 border-b border-gray-700 flex flex-col sm:flex-row justify-between items-center gap-4">
                            <h3 class="text-lg font-bold text-white">Ticket History</h3>
                            <div class="flex items-center gap-3 w-full sm:w-auto">
                                <span class="text-sm text-gray-400 whitespace-nowrap"><i class="fas fa-filter mr-1"></i> Filter by Issue:</span>
                                <select id="modal-issue-filter" onchange="loadModalTableData(1)" class="w-full sm:w-auto bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2 outline-none">
                                    <option value="">All Issues Recorded</option>
                                </select>
                            </div>
                        </div>

                        <!-- Modal Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left">
                                <thead>
                                    <tr class="bg-gray-800/80 text-gray-400 text-xs uppercase tracking-wider font-semibold">
                                        <th class="py-3 px-5 w-8"></th>
                                        <th class="py-3 px-5">ID</th>
                                        <th class="py-3 px-5">Issue Category</th>
                                        <th class="py-3 px-5">Status</th>
                                        <th class="py-3 px-5">Urgency</th>
                                        <th class="py-3 px-5 text-right">Date Filed</th>
                                    </tr>
                                </thead>
                                <tbody class="text-gray-300 text-sm divide-y divide-gray-700/50" id="modal-table-body">
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="p-4 border-t border-gray-700 bg-gray-800/30 flex justify-between items-center">
                            <div class="text-xs text-gray-500" id="modal-table-record-info">Loading...</div>
                            <div id="modal-pagination-controls" class="flex space-x-1"></div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // --- Utility: Clock ---
    function updateClock() {
        document.getElementById('realtime-clock').textContent = new Date().toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    setInterval(updateClock, 1000); updateClock();

    // --- Chart Globals ---
    const chartColors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#ec4899', '#84cc16', '#f43f5e', '#14b8a6', '#6366f1', '#f97316'];
    let trendChartInstance = null;
    let issuesPieChartInstance = null;
    let resolversBarChartInstance = null;

    // 1. Issues Breakdown Pie Chart
    const issueDataRaw = <?= json_encode(array_slice($issueBreakdown, 0, 12)) ?>;
    issuesPieChartInstance = new Chart(document.getElementById('issuesPieChart'), {
        type: 'doughnut',
        data: {
            labels: issueDataRaw.map(d => d.Issues_Concerning),
            datasets: [{
                data: issueDataRaw.map(d => d.issue_count),
                backgroundColor: chartColors,
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '70%',
            plugins: { legend: { position: 'right', labels: { color: '#9ca3af', font: { size: 10 }, boxWidth: 10 } } }
        }
    });

    // Handle fetching filtered Issues Chart
    async function loadIssuesChart() {
        const month = document.getElementById('chart-month').value;
        const year = document.getElementById('chart-year').value;

        try {
            const res = await fetch(`statistics.php?action=getIssuesBreakdownData&month=${month}&year=${year}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
            const data = await res.json();
            
            if (issuesPieChartInstance) {
                issuesPieChartInstance.data.labels = data.map(d => d.Issues_Concerning);
                issuesPieChartInstance.data.datasets[0].data = data.map(d => d.issue_count);
                issuesPieChartInstance.update();
            }
        } catch (error) { console.error("Error loading issue breakdown chart:", error); }
    }

    // 2. Dynamic Trend Chart Logic
    async function loadTrendChart(type) {
        try {
            const res = await fetch(`statistics.php?action=getTrendData&type=${type}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
            const data = await res.json();
            
            const labels = data.map(d => d.label);
            const counts = data.map(d => d.count);

            if (trendChartInstance) {
                // Smooth transition update
                trendChartInstance.data.labels = labels;
                trendChartInstance.data.datasets[0].data = counts;
                trendChartInstance.update();
            } else {
                // Initial Chart Creation
                const ctx = document.getElementById('trendChart');
                trendChartInstance = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Tickets',
                            data: counts,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#10b981',
                            pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: '#9ca3af' } },
                            y: { grid: { color: 'rgba(75, 85, 99, 0.2)' }, ticks: { color: '#9ca3af', stepSize: 5 }, beginAtZero: true }
                        }
                    }
                });
            }
        } catch (error) { console.error("Error loading trend data:", error); }
    }

    // 3. Top Insights (Employees & Common Issues) Data Fetch
    async function loadTopInsights() {
        const month = document.getElementById('insight-month').value;
        const year = document.getElementById('insight-year').value;

        try {
            const res = await fetch(`statistics.php?action=getTopData&month=${month}&year=${year}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
            const data = await res.json();
            renderTopEmployees(data.employees);
            renderCommonIssues(data.issues);
        } catch (error) { console.error("Error loading insights:", error); }
    }

    function renderTopEmployees(employees) {
        const container = document.getElementById('top-employees-container');
        if (!employees || employees.length === 0) {
            container.innerHTML = `<div class="col-span-full text-center py-10 text-gray-500">No data found for this period.</div>`;
            return;
        }

        container.innerHTML = employees.map((emp, index) => {
            let rankColor = 'text-gray-500 bg-gray-700 border-gray-600';
            if (index === 0) rankColor = 'text-yellow-400 bg-yellow-400/10 border-yellow-400/30';
            else if (index === 1) rankColor = 'text-gray-300 bg-gray-300/10 border-gray-300/30';
            else if (index === 2) rankColor = 'text-orange-400 bg-orange-400/10 border-orange-400/30';

            return `
                <div class="flex items-center justify-between p-3 rounded-xl border border-gray-700 bg-gray-900/50 hover:bg-gray-700/80 transition-all cursor-pointer group" onclick="openEmployeeModal('${emp.Employee_name.replace(/'/g, "\\'")}')">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm border ${rankColor}">#${index + 1}</div>
                        <div>
                            <p class="text-sm font-bold text-white group-hover:text-blue-400 transition-colors">${emp.Employee_name}</p>
                            <p class="text-xs text-gray-400 font-medium">${emp.LOB || 'Unknown Dept'}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="text-lg font-black text-blue-400">${emp.ticket_count}</span>
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Tickets</p>
                    </div>
                </div>
            `;
        }).join('');
    }

    function renderCommonIssues(issues) {
        const container = document.getElementById('common-issues-container');
        if (!issues || issues.length === 0) {
            container.innerHTML = `<div class="text-center py-10 text-gray-500">No data found for this period.</div>`;
            return;
        }

        const maxCount = Math.max(...issues.map(i => parseInt(i.issue_count))) || 1;
        container.innerHTML = issues.map(issue => {
            const percentage = (issue.issue_count / maxCount) * 100;
            return `
                <div class="group cursor-pointer" onclick="applyFilter('issue', '${issue.Issues_Concerning.replace(/'/g, "\\'")}')">
                    <div class="flex justify-between text-sm mb-1">
                        <span class="font-semibold text-gray-200 group-hover:text-blue-400 transition-colors">${issue.Issues_Concerning}</span>
                        <span class="font-bold text-gray-400">${issue.issue_count}</span>
                    </div>
                    <div class="w-full bg-gray-900 rounded-full h-2">
                        <div class="bg-gradient-to-r from-blue-600 to-blue-400 h-2 rounded-full" style="width: ${percentage}%"></div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // 4. NEW: Top Resolvers Chart and List Logic
    async function loadResolversData() {
        const month = document.getElementById('resolver-month').value;
        const year = document.getElementById('resolver-year').value;

        try {
            const res = await fetch(`statistics.php?action=getResolversData&month=${month}&year=${year}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
            const data = await res.json();
            
            renderResolversList(data);
            updateResolversChart(data);
        } catch (error) { console.error("Error loading resolvers data:", error); }
    }

    function renderResolversList(resolvers) {
        const container = document.getElementById('resolvers-list-container');
        if (!resolvers || resolvers.length === 0) {
            container.innerHTML = `<div class="text-center py-6 text-gray-500">No data available for this period.</div>`;
            return;
        }

        container.innerHTML = resolvers.map((resolver, index) => {
            let medalColor = 'text-gray-500';
            let bgColor = 'bg-gray-900/50 border-gray-700';
            
            if (index === 0) { medalColor = 'text-yellow-400'; bgColor = 'bg-yellow-400/10 border-yellow-400/30'; }
            else if (index === 1) { medalColor = 'text-gray-300'; }
            else if (index === 2) { medalColor = 'text-orange-400'; }

            return `
                <div class="flex items-center justify-between p-3 rounded-xl border ${bgColor} transition-all">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-800 border border-gray-600 flex items-center justify-center font-bold text-xs text-gray-300">
                            ${index <= 2 ? `<i class="fas fa-award ${medalColor} text-lg"></i>` : `#${index + 1}`}
                        </div>
                        <p class="text-sm font-bold text-white">${resolver.SLT_on_DUTY}</p>
                    </div>
                    <div class="text-right">
                        <span class="text-lg font-black text-green-400">${resolver.resolved_count}</span>
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Resolved</p>
                    </div>
                </div>
            `;
        }).join('');
    }

    function updateResolversChart(resolvers) {
        const labels = resolvers.map(r => r.SLT_on_DUTY);
        const data = resolvers.map(r => r.resolved_count);

        if (resolversBarChartInstance) {
            resolversBarChartInstance.data.labels = labels;
            resolversBarChartInstance.data.datasets[0].data = data;
            resolversBarChartInstance.update();
        } else {
            const ctx = document.getElementById('resolversBarChart');
            resolversBarChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Tickets Resolved',
                        data: data,
                        backgroundColor: 'rgba(168, 85, 247, 0.5)',
                        borderColor: '#a855f7',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#9ca3af' } },
                        y: { grid: { color: 'rgba(75, 85, 99, 0.2)' }, ticks: { color: '#9ca3af', stepSize: 1 }, beginAtZero: true }
                    }
                }
            });
        }
    }


    // --- Master Table Logic ---
    const form = document.getElementById('master-filter-form');
    let searchTimeout = null;
    
    form.addEventListener('change', () => loadTableData(1));
    
    document.getElementById('search-input').addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            loadTableData(1);
        }, 500); 
    });

    function applyFilter(key, value) {
        let el = form.querySelector(`[name="${key}"]`);
        if (el) { el.value = value; }
        loadTableData(1);
        document.getElementById('master-table-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function resetFilters() {
        form.reset();
        document.getElementById('search-input').value = '';
        document.getElementById('filter-employee').value = '';
        document.getElementById('filter-issue').value = '';
        loadTableData(1);
    }

    function updateFilterChips(filters) {
        const container = document.getElementById('active-filters-container');
        let html = '<span class="text-xs text-gray-500 font-semibold uppercase">Active Filters:</span>';
        let hasActive = false;

        for (const [key, value] of Object.entries(filters)) {
            if (value && value !== '') {
                hasActive = true;
                html += `
                    <div class="bg-blue-600/20 text-blue-400 border border-blue-500/30 px-3 py-1 rounded-full text-xs font-medium flex items-center gap-2">
                        <span class="capitalize">${key}:</span> <span class="text-white">${value}</span>
                        <i class="fas fa-times cursor-pointer hover:text-red-400" onclick="applyFilter('${key}', '')"></i>
                    </div>`;
            }
        }
        
        container.innerHTML = html;
        if(hasActive) container.classList.remove('hidden');
        else container.classList.add('hidden');
    }

    async function loadTableData(page = 1) {
        const formData = new FormData(form);
        const params = new URLSearchParams();
        params.append('action', 'getFilteredTickets');
        params.append('page', page);
        
        const filterObj = {};
        for (let [key, value] of formData.entries()) {
            if (value) {
                params.append(key, value);
                filterObj[key] = value;
            }
        }
        
        updateFilterChips(filterObj);
        document.getElementById('main-table-body').innerHTML = `<tr><td colspan="8" class="text-center py-10"><i class="fas fa-spinner fa-spin text-blue-500 text-2xl"></i></td></tr>`;

        try {
            const response = await fetch(`statistics.php?${params.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
            const data = await response.json();
            
            renderTable(data.tickets);
            renderPagination(data.currentPage, data.totalPages, 'pagination-controls', 'loadTableData');
            
            document.getElementById('table-record-info').textContent = `Showing ${(page-1)*10 + (data.tickets.length > 0 ? 1 : 0)} to ${Math.min(page*10, data.totalTicketsCount)} of ${data.totalTicketsCount} entries`;

        } catch (error) { console.error("Error fetching data: ", error); }
    }

    function renderTable(tickets) {
        const tbody = document.getElementById('main-table-body');
        if (tickets.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-10 text-gray-500">No tickets found matching the criteria.</td></tr>`;
            return;
        }

        tbody.innerHTML = tickets.map(t => {
            const statusStyle = t.Status === 'RESOLVED' ? 'text-green-400 bg-green-400/10 ring-1 ring-green-400/30' : (t.Status === 'PENDING' ? 'text-yellow-400 bg-yellow-400/10 ring-1 ring-yellow-400/30' : 'text-red-400 bg-red-400/10 ring-1 ring-red-400/30');
            const urgencyStyle = t.Urgency === 'High' ? 'text-red-400 font-bold' : (t.Urgency === 'Medium' ? 'text-orange-400' : 'text-gray-400');
            
            return `
                <tr class="hover:bg-gray-700/30 transition-colors border-b border-gray-700/50">
                    <td class="py-4 px-6 font-mono text-xs text-gray-500">#${t.id}</td>
                    <td class="py-4 px-6 font-medium text-blue-400 hover:text-blue-300 cursor-pointer underline decoration-blue-500/30 underline-offset-4" onclick="openEmployeeModal('${t.Employee_name.replace(/'/g, "\\'")}')">${t.Employee_name}</td>
                    <td class="py-4 px-6 text-gray-400">${t.LOB || '-'}</td>
                    <td class="py-4 px-6 text-gray-400 font-medium">${t.OM || '-'}</td>
                    <td class="py-4 px-6 text-gray-300">${t.Issues_Concerning}</td>
                    <td class="py-4 px-6">
                        <span class="px-3 py-1 rounded text-[10px] font-bold uppercase tracking-wider ${statusStyle}">${t.Status}</span>
                    </td>
                    <td class="py-4 px-6 ${urgencyStyle} text-xs">${t.Urgency || 'Normal'}</td>
                    <td class="py-4 px-6 text-gray-500 text-xs text-right whitespace-nowrap">${t.Timestamp}</td>
                </tr>
            `;
        }).join('');
    }

    function renderPagination(current, total, containerId, functionName) {
        const controls = document.getElementById(containerId);
        let html = '';
        if (total <= 1) { controls.innerHTML = ''; return; }
        const btnBase = "px-3 py-1 text-sm font-medium rounded-md transition-colors border";
        html += `<button onclick="${functionName}(${current - 1})" class="${btnBase} ${current === 1 ? 'bg-gray-800 text-gray-600 border-gray-700 cursor-not-allowed' : 'bg-gray-800 text-gray-300 border-gray-600 hover:bg-gray-700'}" ${current === 1 ? 'disabled' : ''}>Prev</button>`;
        for(let i=1; i<=total; i++) {
            if(total > 7 && Math.abs(current - i) > 1 && i !== 1 && i !== total) {
                if(i === 2 || i === total - 1) html += `<span class="px-2 text-gray-500">...</span>`;
                continue;
            }
            if (i === current) {
                html += `<button class="${btnBase} bg-blue-600 text-white border-blue-500">${i}</button>`;
            } else {
                html += `<button onclick="${functionName}(${i})" class="${btnBase} bg-gray-800 text-gray-400 border-gray-700 hover:bg-gray-700">${i}</button>`;
            }
        }
        html += `<button onclick="${functionName}(${current + 1})" class="${btnBase} ${current === total ? 'bg-gray-800 text-gray-600 border-gray-700 cursor-not-allowed' : 'bg-gray-800 text-gray-300 border-gray-600 hover:bg-gray-700'}" ${current === total ? 'disabled' : ''}>Next</button>`;
        controls.innerHTML = html;
    }

    // ================= MODAL LOGIC =================

    let currentModalEmployee = '';

    async function openEmployeeModal(employeeName) {
        currentModalEmployee = employeeName;
        document.getElementById('modal-employee-name').textContent = employeeName;
        document.getElementById('modal-issue-filter').innerHTML = '<option value="">All Issues Recorded</option>'; 
        
        const modal = document.getElementById('employeeModal');
        const modalInner = document.getElementById('employeeModalInner');
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modalInner.classList.remove('scale-95');
        }, 10);

        try {
            const response = await fetch(`statistics.php?action=getEmployeeSummary&employee=${encodeURIComponent(employeeName)}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
            const data = await response.json();
            
            document.getElementById('modal-stat-total').textContent = data.summary.total;
            document.getElementById('modal-stat-resolved').textContent = data.summary.resolved;
            document.getElementById('modal-stat-pending').textContent = data.summary.pending;

            const select = document.getElementById('modal-issue-filter');
            data.issues.forEach(issue => {
                const opt = document.createElement('option');
                opt.value = issue;
                opt.textContent = issue;
                select.appendChild(opt);
            });
        } catch (err) { console.error("Error loading summary:", err); }

        loadModalTableData(1);
    }

    function closeEmployeeModal() {
        const modal = document.getElementById('employeeModal');
        const modalInner = document.getElementById('employeeModalInner');
        modal.classList.add('opacity-0');
        modalInner.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    }

    document.getElementById('employeeModal').addEventListener('click', (e) => {
        if (e.target.id === 'employeeModal') closeEmployeeModal();
    });

    function toggleDetails(rowId, iconId) {
        const row = document.getElementById(rowId);
        const icon = document.getElementById(iconId);
        
        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            icon.classList.add('rotate-180');
        } else {
            row.classList.add('hidden');
            icon.classList.remove('rotate-180');
        }
    }

    async function loadModalTableData(page = 1) {
        const issueFilter = document.getElementById('modal-issue-filter').value;
        const params = new URLSearchParams({
            action: 'getFilteredTickets',
            page: page,
            employee: currentModalEmployee
        });
        if (issueFilter) params.append('issue', issueFilter);

        document.getElementById('modal-table-body').innerHTML = `<tr><td colspan="6" class="text-center py-8"><i class="fas fa-spinner fa-spin text-blue-500 text-xl"></i></td></tr>`;

        try {
            const response = await fetch(`statistics.php?${params.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
            const data = await response.json();
            
            const tbody = document.getElementById('modal-table-body');
            if (data.tickets.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-8 text-gray-500">No tickets found.</td></tr>`;
            } else {
                tbody.innerHTML = data.tickets.map(t => {
                    const statusStyle = t.Status === 'RESOLVED' ? 'text-green-400 bg-green-400/10' : (t.Status === 'PENDING' ? 'text-yellow-400 bg-yellow-400/10' : 'text-red-400 bg-red-400/10');
                    
                    const sltName = (t.SLT_on_DUTY && t.SLT_on_DUTY !== 'PENDING') ? t.SLT_on_DUTY : 'Unassigned';
                    const resolvedTime = (t.TIME_RESOLVED && t.TIME_RESOLVED !== 'PENDING') ? t.TIME_RESOLVED : 'N/A';
                    const issueDetails = t.Issue_Details ? t.Issue_Details : 'No additional details provided.';
                    
                    const resolutionNotes = t.resolution || t.Resolution || t.Remarks || 'No resolution notes recorded yet.';

                    return `
                        <!-- Main Clickable Row -->
                        <tr class="hover:bg-gray-700/50 transition-colors cursor-pointer border-b border-gray-700/50" onclick="toggleDetails('details-${t.id}', 'icon-${t.id}')">
                            <td class="py-3 px-5 text-center text-gray-500">
                                <i id="icon-${t.id}" class="fas fa-chevron-down transition-transform text-xs"></i>
                            </td>
                            <td class="py-3 px-5 font-mono text-xs text-gray-500">#${t.id}</td>
                            <td class="py-3 px-5 text-gray-300 font-medium">${t.Issues_Concerning}</td>
                            <td class="py-3 px-5">
                                <span class="px-2 py-1 rounded text-[10px] font-bold uppercase ${statusStyle}">${t.Status}</span>
                            </td>
                            <td class="py-3 px-5 text-gray-400 text-xs">${t.Urgency || '-'}</td>
                            <td class="py-3 px-5 text-gray-500 text-xs text-right whitespace-nowrap">${t.Timestamp}</td>
                        </tr>
                        
                        <!-- Expandable Details Row -->
                        <tr id="details-${t.id}" class="hidden bg-gray-900/40 border-b border-gray-700/80 shadow-inner">
                            <td colspan="6" class="p-0">
                                <div class="px-6 py-5 border-l-2 border-blue-500 ml-4 my-2 rounded-r-lg bg-gray-800/30">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                                        <!-- Issue Details -->
                                        <div>
                                            <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest mb-2 flex items-center gap-1">
                                                <i class="fas fa-info-circle"></i> Issue Details
                                            </p>
                                            <p class="text-gray-300 bg-gray-900/50 p-4 rounded-lg border border-gray-700/50 whitespace-pre-wrap leading-relaxed">${issueDetails}</p>
                                        </div>
                                        
                                        <!-- Resolution Info -->
                                        <div>
                                            <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest mb-2 flex items-center gap-1">
                                                <i class="fas fa-clipboard-check"></i> Resolution Information
                                            </p>
                                            <div class="bg-gray-900/50 p-4 rounded-lg border border-gray-700/50 space-y-3">
                                                <div class="flex justify-between items-center pb-2 border-b border-gray-700/50">
                                                    <span class="text-gray-400 text-xs">Handled By:</span>
                                                    <span class="text-white font-semibold">${sltName}</span>
                                                </div>
                                                <div class="flex justify-between items-center pb-2 border-b border-gray-700/50">
                                                    <span class="text-gray-400 text-xs">Resolved At:</span>
                                                    <span class="text-white text-xs font-mono bg-gray-800 px-2 py-1 rounded">${resolvedTime}</span>
                                                </div>
                                                <div class="pt-1">
                                                    <span class="text-gray-400 text-xs block mb-1">Resolution / Remarks:</span>
                                                    <span class="text-gray-300 italic text-sm leading-relaxed">${resolutionNotes}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');
            }
            
            renderPagination(data.currentPage, data.totalPages, 'modal-pagination-controls', 'loadModalTableData');
            document.getElementById('modal-table-record-info').textContent = `Showing ${(page-1)*10 + (data.tickets.length > 0 ? 1 : 0)} to ${Math.min(page*10, data.totalTicketsCount)} of ${data.totalTicketsCount}`;

        } catch (error) { console.error("Error fetching modal data: ", error); }
    }

    // Initial loads
    document.addEventListener('DOMContentLoaded', () => {
        loadTableData(1);
        loadTrendChart('monthly');
        loadTopInsights();
        loadResolversData(); // NEW: Load top resolvers
    });
</script>

<?php renderFooter(); ?>