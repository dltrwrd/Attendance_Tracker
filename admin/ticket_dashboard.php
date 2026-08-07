<?php
ob_start(); // FIX: Start output buffering immediately to catch rogue whitespace from includes that corrupts JSON

include('../connection.php');
// New session handling based on dashboard.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/db.php'; // For PDO access to games_points

// FIX: Set default timezone to Asia/Manila for all PHP date() functions
date_default_timezone_set('Asia/Manila');

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// FIX: Safely get current user's sub_name using $con (MySQLi) instead of $pdo to prevent fatal errors
$sub_name = 'Admin';
if (isset($_SESSION['user_id'])) {
    $userQuery = "SELECT sub_name FROM users WHERE id = ?";
    if ($userStmt = $con->prepare($userQuery)) {
        $userStmt->bind_param("i", $_SESSION['user_id']);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        if ($userRow = $userResult->fetch_assoc()) {
            $sub_name = $userRow['sub_name'] ?? 'Admin';
        }
        $userStmt->close();
    }
}

// AJAX Endpoint for deleting ticket
if (isset($_POST['action']) && $_POST['action'] === 'delete_ticket') {
    // FIX: Strictly cast to int
    $ticketId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $response = ['success' => false, 'message' => 'Invalid request.'];

    if ($ticketId) {
        // 1. Fetch image path before deleting record
        $imgQuery = "SELECT issue_img FROM ticket WHERE id = ?";
        $imgStmt = $con->prepare($imgQuery);
        $imgStmt->bind_param("i", $ticketId);
        $imgStmt->execute();
        $imgResult = $imgStmt->get_result();
        $imgRow = $imgResult->fetch_assoc();
        
        // 2. Delete file if exists
        if ($imgRow && !empty($imgRow['issue_img'])) {
            $filePath = '../ticketing/' . $imgRow['issue_img'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // 3. Delete database record
        $query = "DELETE FROM ticket WHERE id = ?";
        $stmt = $con->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param("i", $ticketId);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Ticket and associated image deleted successfully.';
            } else {
                $response['message'] = 'Failed to delete ticket: ' . $stmt->error;
            }
        } else {
            $response['message'] = 'Failed to prepare statement: ' . $con->error;
        }
    }

    if (ob_get_length()) ob_clean(); // Wipes any accidental PHP notices to ensure valid JSON
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// AJAX Endpoint for updating ticket status
if (isset($_POST['action']) && $_POST['action'] === 'resolve_ticket') {
    // FIX: Strictly cast to int
    $ticketId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $resolution = $_POST['resolution'] ?? null;
    $response = ['success' => false, 'message' => 'Invalid request.'];

    if ($ticketId) {
        // FIX: Use PHP to format the current Manila time instead of relying on MySQL's NOW()
        $timeResolved = date('g:i A'); // Formats as '1:30 PM'

        $query = "UPDATE ticket SET Status = 'RESOLVED', TIME_RESOLVED = ?, resolution = ?, SLT_on_DUTY = ? WHERE id = ?";
        $stmt = $con->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param("sssi", $timeResolved, $resolution, $sub_name, $ticketId);
            if ($stmt->execute()) {
                // Add 10 points for resolving ticket (using PDO for games_points)
                $gpUserId = (int)$_SESSION['user_id'];
                $gpToday = date('Y-m-d');
                $gpCheck = $pdo->prepare("SELECT user_id FROM games_points WHERE user_id = ?");
                $gpCheck->execute([$gpUserId]);
                if ($gpCheck->fetch()) {
                    $pdo->prepare("UPDATE games_points SET points = points + 10 WHERE user_id = ?")->execute([$gpUserId]);
                } else {
                    $pdo->prepare("INSERT INTO games_points (user_id, points, last_reset_date) VALUES (?, 10, ?)")->execute([$gpUserId, $gpToday]);
                }

                $response['success'] = true;
                $response['message'] = 'Ticket resolved successfully.';
            } else {
                $response['message'] = 'Failed to resolve ticket: ' . $stmt->error;
            }
        } else {
            $response['message'] = 'Failed to prepare statement: ' . $con->error;
        }
    }

    if (ob_get_length()) ob_clean(); // Wipes any accidental PHP notices to ensure valid JSON
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// AJAX endpoint to get pending tickets count (for all pages)
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_count') {
    $pendingCount = getPendingTicketsCount();
    
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['pending_count' => $pendingCount]);
    exit;
}

// AJAX endpoint to fetch all ticket data
if (isset($_GET['action']) && $_GET['action'] === 'get_tickets_data') {
    function fetchAllTicketsForRealtime($con) {
        $query = "SELECT * FROM ticket ORDER BY STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s') DESC";
        $result = $con->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    $tickets = fetchAllTicketsForRealtime($con);
    
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['tickets' => $tickets]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_resolved_slt_data') {
    $month = $_GET['month'] ?? date('Y-m');
    $startDate = $month . '-01';
    $endDate = date('Y-m-d', strtotime($startDate . ' +1 month -1 day'));

    $query = "
        SELECT SLT_on_DUTY, COUNT(*) AS resolved_count
        FROM ticket
        WHERE Status = 'RESOLVED'
        AND STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s') BETWEEN ? AND ?
        GROUP BY SLT_on_DUTY
        ORDER BY resolved_count DESC
    ";

    $stmt = $con->prepare($query);
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);

    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['data' => $data]);
    exit;
}

function getPendingTicketsCount() {
    global $con;
    $query = "SELECT COUNT(*) as count FROM ticket WHERE Status = 'PENDING'";
    $stmt = $con->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

$pendingTicketsCount = getPendingTicketsCount();

require_once '../components/layout.php';
renderHead('Ticket Dashboard');
renderNavbar();
renderSidebar('ticket_dashboard' , $pendingTicketsCount ?? 0);

// Get counts for dashboard cards
function getCount($status = null, $date = null) {
    global $con;
    $query = "SELECT COUNT(*) as count FROM ticket";
    $conditions = [];
    $params = [];
    $types = '';
    if ($status) {
        $conditions[] = "Status = ?";
        $params[] = $status;
        $types .= 's';
    }
    if ($date) {
        $conditions[] = "DATE(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = ?";
        $params[] = $date;
        $types .= 's';
    }
    if (!empty($conditions)) {
        $query .= " WHERE " . implode(' AND ', $conditions);
    }
    $stmt = $con->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

$totalTicketsOverall = getCount();
$ticketsReceivedToday = getCount(null, date('Y-m-d'));
$ticketsResolvedToday = getCount('RESOLVED', date('Y-m-d'));
$pendingTicketsCount = getCount('PENDING');

$monthsQuery = "SELECT DISTINCT DATE_FORMAT(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s'), '%Y-%m') AS month FROM ticket WHERE Status = 'RESOLVED' ORDER BY month DESC";
$monthsResult = $con->query($monthsQuery);
$monthsWithData = $monthsResult->fetch_all(MYSQLI_ASSOC);

?>
<div class="pt-2 min-h-screen bg-gray-900 text-white font-sans relative">
    <main class="p-4 lg:p-6 w-full max-w-[1920px] mx-auto">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-white">Ticket Dashboard</h1>
                <p class="text-gray-400 text-sm mt-1">Overview of support tickets and performance metrics</p>
            </div>
            <div class="text-right">
                <div class="text-sm font-medium text-gray-300"><?= date('F j, Y') ?></div>
                <div id="realtime-clock" class="text-xs text-blue-400 font-mono mt-1"></div>
            </div>
        </div>
        
        <?php renderAlert(); ?>
        
        <!-- Dashboard Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Overall -->
            <a href="#" onclick="handleCardFilter('all'); return false;" 
               class="group relative bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-lg hover:shadow-xl hover:border-blue-500/50 transition-all duration-300 overflow-hidden">
                <div class="absolute right-0 top-0 h-24 w-24 bg-blue-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative flex justify-between items-start">
                    <div>
                        <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Overall Tickets</p>
                        <h3 class="text-3xl font-bold text-white mt-2" id="total-tickets-count"><?= $totalTicketsOverall ?></h3>
                    </div>
                    <div class="p-3 bg-blue-500/20 rounded-lg text-blue-400 group-hover:bg-blue-500 group-hover:text-white transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                </div>
            </a>

            <!-- Received Today -->
            <a href="#" onclick="handleCardFilter('received_today'); return false;"
               class="group relative bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-lg hover:shadow-xl hover:border-purple-500/50 transition-all duration-300 overflow-hidden">
                <div class="absolute right-0 top-0 h-24 w-24 bg-purple-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative flex justify-between items-start">
                    <div>
                        <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Received Today</p>
                        <h3 class="text-3xl font-bold text-white mt-2" id="received-today-count"><?= $ticketsReceivedToday ?></h3>
                    </div>
                    <div class="p-3 bg-purple-500/20 rounded-lg text-purple-400 group-hover:bg-purple-500 group-hover:text-white transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                </div>
            </a>

            <!-- Resolved Today -->
            <a href="#" onclick="handleCardFilter('resolved_today'); return false;"
               class="group relative bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-lg hover:shadow-xl hover:border-green-500/50 transition-all duration-300 overflow-hidden">
                <div class="absolute right-0 top-0 h-24 w-24 bg-green-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative flex justify-between items-start">
                    <div>
                        <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Resolved Today</p>
                        <h3 class="text-3xl font-bold text-white mt-2" id="resolved-today-count"><?= $ticketsResolvedToday ?></h3>
                    </div>
                    <div class="p-3 bg-green-500/20 rounded-lg text-green-400 group-hover:bg-green-500 group-hover:text-white transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </a>

            <!-- Pending -->
            <a href="#" onclick="handleCardFilter('pending'); return false;"
               class="group relative bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-lg hover:shadow-xl hover:border-yellow-500/50 transition-all duration-300 overflow-hidden">
                <div class="absolute right-0 top-0 h-24 w-24 bg-yellow-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative flex justify-between items-start">
                    <div>
                        <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Pending Tickets</p>
                        <h3 class="text-3xl font-bold text-white mt-2" id="pending-tickets-count"><?= $pendingTicketsCount ?></h3>
                    </div>
                    <div class="p-3 bg-yellow-500/20 rounded-lg text-yellow-400 group-hover:bg-yellow-500 group-hover:text-white transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </a>
        </div>

        <!-- Master / Detail Container (Table + Side Panel) -->
        <div class="flex flex-col xl:flex-row gap-6 mb-8 items-start relative w-full">
            
            <!-- Ticket Table Section (Will shrink to w-[70%] when preview is open) -->
            <div id="tableContainer" class="w-full bg-gray-800 rounded-xl border border-gray-700 shadow-xl overflow-hidden transition-all duration-300">
                <div class="p-5 md:p-6 border-b border-gray-700 flex flex-col gap-5 bg-gray-800/50 backdrop-blur-sm">
                    <!-- Top row: Title and Search -->
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <div>
                            <h2 class="text-xl font-bold text-white flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                                Ticket Records
                            </h2>
                        </div>
                        
                        <!-- Integrated Search Bar -->
                        <div class="relative w-full md:w-80">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                            <input type="text" id="searchInput" 
                                class="bg-gray-900 border border-gray-600 text-white text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 pr-10 p-2.5 transition-colors placeholder-gray-500" 
                                placeholder="Search Name, Emp ID, Work #...">
                            <button id="clearSearchBtn" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-white hidden transition-colors" onclick="document.getElementById('searchInput').value=''; document.getElementById('searchInput').dispatchEvent(new Event('input'));">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Advanced Filters -->
                    <div class="flex flex-wrap items-end gap-3 lg:gap-5 pt-1">
                        <!-- Date Range -->
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Date Range</label>
                            <div class="flex items-center gap-2">
                                <input type="date" id="filterStartDate" class="bg-gray-900 border border-gray-600 text-white text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-1.5">
                                <span class="text-gray-500 text-sm">to</span>
                                <input type="date" id="filterEndDate" class="bg-gray-900 border border-gray-600 text-white text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-1.5">
                            </div>
                        </div>

                        <!-- SLT -->
                        <div class="flex flex-col gap-1.5 min-w-[140px] flex-1 sm:flex-none">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">SLT</label>
                            <select id="filterSLT" class="bg-gray-900 border border-gray-600 text-white text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-1.5 cursor-pointer">
                                <option value="">All SLTs</option>
                            </select>
                        </div>

                        <!-- Concern -->
                        <div class="flex flex-col gap-1.5 min-w-[140px] flex-1 sm:flex-none">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Concern</label>
                            <select id="filterConcern" class="bg-gray-900 border border-gray-600 text-white text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-1.5 cursor-pointer">
                                <option value="">All Concerns</option>
                            </select>
                        </div>

                        <!-- Dept -->
                        <div class="flex flex-col gap-1.5 min-w-[140px] flex-1 sm:flex-none">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Dept</label>
                            <select id="filterDept" class="bg-gray-900 border border-gray-600 text-white text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 px-3 py-1.5 cursor-pointer">
                                <option value="">All Depts</option>
                            </select>
                        </div>

                        <!-- Status Toggle -->
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Status</label>
                            <div class="flex bg-gray-900 rounded-lg border border-gray-600 p-0.5">
                                <button id="btnFilterAll" onclick="setStatusFilter('ALL')" class="px-4 py-1.5 text-xs font-semibold rounded bg-blue-600 text-white shadow-sm transition-colors">All</button>
                                <button id="btnFilterPending" onclick="setStatusFilter('PENDING')" class="px-4 py-1.5 text-xs font-semibold rounded text-gray-400 hover:text-white hover:bg-gray-700 transition-colors">Pending</button>
                                <button id="btnFilterResolved" onclick="setStatusFilter('RESOLVED')" class="px-4 py-1.5 text-xs font-semibold rounded text-gray-400 hover:text-white hover:bg-gray-700 transition-colors">Resolved</button>
                            </div>
                        </div>

                        <div class="ml-auto flex items-end">
                            <button onclick="clearAdvancedFilters()" class="flex items-center gap-1.5 px-4 py-1.5 bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white text-sm rounded-lg border border-gray-600 transition-colors" title="Clear Filters">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h16v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>
                                Clear
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700 w-full" style="zoom: 95%">
                        <thead class="bg-gray-900/50">
                            <tr>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Work #</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Emp ID</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Name</th>
                                <th scope="col" class="hide-on-preview px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider transition-all">Station</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Dept</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Concern</th>
                                <th scope="col" class="hide-on-preview px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider transition-all">Received</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">SLT</th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Date</th>
                                <th scope="col" class="hide-on-preview px-6 py-4 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider transition-all"></th>
                            </tr>
                        </thead>
                        <tbody id="ticket-table-body" class="bg-gray-800 divide-y divide-gray-700">
                            <!-- Content injected via JS -->
                        </tbody>
                    </table>
                </div>
                
                <div class="bg-gray-800 border-t border-gray-700 p-4">
                    <nav id="pagination-controls" class="flex justify-center"></nav>
                </div>
            </div>

            <!-- Side Panel Preview (Initially Hidden, takes ~30% width) -->
            <div id="previewPanel" class="hidden w-full xl:w-[30%] flex-shrink-0 bg-gray-800 rounded-xl border border-gray-700 shadow-2xl overflow-hidden sticky top-6 animate-fade-in-up">
                <!-- Header -->
                <div class="p-4 border-b border-gray-700 flex justify-between items-center bg-gray-900/50">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Ticket Details
                    </h3>
                    <button onclick="closePreview()" class="text-gray-400 hover:text-white bg-gray-700 hover:bg-gray-600 rounded-lg p-1.5 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                
                <!-- Dynamic Content injected here -->
                <div id="previewContent" class="p-5 overflow-y-auto custom-scrollbar" style="max-height: calc(100vh - 220px);">
                </div>
                
                <!-- Dynamic Footer Actions -->
                <div id="previewFooter" class="p-4 border-t border-gray-700 bg-gray-900/50 flex flex-col gap-3">
                </div>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl p-6">
            <div class="flex flex-col sm:flex-row justify-between items-center mb-6">
                <div>
                    <h2 class="text-xl font-bold text-white flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        Performance Metrics
                    </h2>
                    <p class="text-sm text-gray-400 mt-1">Resolved tickets by SLT per month</p>
                </div>
                <div class="relative mt-4 sm:mt-0">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <select id="monthSelector" class="appearance-none bg-gray-700 text-white rounded-lg pl-10 pr-10 py-2 border border-gray-600 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition-colors cursor-pointer hover:bg-gray-600">
                        <?php 
                            $currentMonth = date('Y-m');
                            foreach($monthsWithData as $month): 
                            $selected = ($month['month'] == $currentMonth) ? 'selected' : '';
                        ?>
                            <option value="<?= $month['month'] ?>" <?= $selected ?>>
                                <?= date('F Y', strtotime($month['month'] . '-01')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="h-80 w-full">
                <canvas id="resolvedTicketsChart"></canvas>
            </div>
        </div>
        
        <!-- Image Viewer Modal (Kept for Fullscreen Previewing) -->
        <div id="imageViewerModal" class="fixed inset-0 z-[100] hidden bg-black/90 backdrop-blur-sm flex items-center justify-center transition-opacity duration-300 opacity-0">
            <!-- Toolbar -->
            <div class="absolute top-4 right-4 flex gap-2 z-[101]">
                <button onclick="adjustZoom(-0.25)" class="p-2 bg-gray-800/80 hover:bg-gray-700 text-white rounded-full transition border border-gray-600 shadow-lg" title="Zoom Out">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                    </svg>
                </button>
                <button onclick="resetZoom()" class="p-2 bg-gray-800/80 hover:bg-gray-700 text-white rounded-full transition border border-gray-600 text-sm font-bold w-10 h-10 flex items-center justify-center shadow-lg" title="Reset Zoom">
                    1x
                </button>
                <button onclick="adjustZoom(0.25)" class="p-2 bg-gray-800/80 hover:bg-gray-700 text-white rounded-full transition border border-gray-600 shadow-lg" title="Zoom In">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                </button>
                <div class="w-px h-10 bg-gray-700 mx-1"></div>
                <button onclick="closeImageViewer()" class="p-2 bg-red-600/80 hover:bg-red-600 text-white rounded-full transition shadow-lg border border-red-500" title="Close Viewer">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div id="imageViewerContainer" class="w-full h-full overflow-hidden flex items-center justify-center p-4 cursor-grab select-none" onmousedown="startDrag(event)">
                <img id="fullSizeImage" src="" alt="Full Size Issue" class="max-w-none transition-transform duration-200 ease-out origin-center" style="transform: scale(1);">
            </div>
        </div>
        
        <!-- Custom Confirmation Modal (Replaces default confirm() alerts) -->
        <div id="customConfirmModal" class="fixed inset-0 z-[150] hidden bg-black/80 backdrop-blur-sm flex items-center justify-center transition-opacity duration-300">
            <div class="bg-gray-800 border border-gray-700 rounded-xl p-6 shadow-2xl w-full max-w-sm">
                <h3 class="text-lg font-bold text-white mb-2">Confirm Action</h3>
                <p id="confirmModalMsg" class="text-gray-300 text-sm mb-6">Are you sure you want to proceed?</p>
                <div class="flex gap-3 justify-end">
                    <button onclick="closeConfirmModal()" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg text-sm transition-colors">Cancel</button>
                    <button id="btnConfirmYes" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-sm font-semibold transition-colors">Yes, Proceed</button>
                </div>
            </div>
        </div>

    </main>
</div>

<style>
    /* Add slight animation for opening preview */
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in-up {
        animation: fadeInUp 0.3s ease-out forwards;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Notification & Custom Confirm UI (Replaces alert/confirm)
    function showNotification(msg, isError = false) {
        const toast = document.createElement('div');
        toast.className = `fixed bottom-4 right-4 px-6 py-3 rounded-lg text-white shadow-xl z-[200] transition-opacity duration-300 ${isError ? 'bg-red-600' : 'bg-green-600'}`;
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    let confirmCallback = null;
    function showConfirmModal(msg, callback) {
        confirmCallback = callback;
        document.getElementById('confirmModalMsg').textContent = msg;
        document.getElementById('customConfirmModal').classList.remove('hidden');
    }
    function closeConfirmModal() {
        document.getElementById('customConfirmModal').classList.add('hidden');
        confirmCallback = null;
    }
    document.getElementById('btnConfirmYes').addEventListener('click', () => {
        if(confirmCallback) confirmCallback();
        closeConfirmModal();
    });

    // Simple real-time Manila clock
    function updateManilaClock() {
        const now = new Date();
        const manilaTime = now.toLocaleTimeString('en-US', {
            timeZone: 'Asia/Manila',
            hour12: true,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        document.getElementById('realtime-clock').textContent = manilaTime + ' (PH Time)';
    }

    updateManilaClock();
    setInterval(updateManilaClock, 1000);

    let allTickets = [];
    const ticketsPerPage = 10;
    let currentPage = 1;
    let searchQuery = '';
    let resolvedTicketsChart = null;
    let lastMaxTicketId = null;
    
    // Panel state
    let isPreviewOpen = false;
    let currentTicketId = null;

    // --- Filter State ---
    let filterParams = {
        startDate: '',
        endDate: '',
        slt: '',
        concern: '',
        dept: '',
        status: 'ALL'
    };

    // --- Search & Advanced Filters Logic ---
    const searchInput = document.getElementById('searchInput');
    const clearSearchBtn = document.getElementById('clearSearchBtn');

    searchInput.addEventListener('input', (e) => {
        searchQuery = e.target.value.toLowerCase().trim();
        clearSearchBtn.classList.toggle('hidden', searchQuery === '');
        currentPage = 1; 
        applyAndRender(); 
    });

    document.addEventListener('DOMContentLoaded', () => {
        const attachListener = (id, field) => {
            const el = document.getElementById(id);
            if(el) {
                el.addEventListener('change', (e) => {
                    filterParams[field] = e.target.value;
                    currentPage = 1;
                    applyAndRender();
                });
            }
        };
        attachListener('filterStartDate', 'startDate');
        attachListener('filterEndDate', 'endDate');
        attachListener('filterSLT', 'slt');
        attachListener('filterConcern', 'concern');
        attachListener('filterDept', 'dept');
    });

    function setStatusFilter(status) {
        filterParams.status = status;
        document.getElementById('btnFilterAll').className = status === 'ALL' ? 'px-4 py-1.5 text-xs font-semibold rounded bg-blue-600 text-white shadow-sm transition-colors' : 'px-4 py-1.5 text-xs font-semibold rounded text-gray-400 hover:text-white hover:bg-gray-700 transition-colors';
        document.getElementById('btnFilterPending').className = status === 'PENDING' ? 'px-4 py-1.5 text-xs font-semibold rounded bg-yellow-600 text-white shadow-sm transition-colors' : 'px-4 py-1.5 text-xs font-semibold rounded text-gray-400 hover:text-white hover:bg-gray-700 transition-colors';
        document.getElementById('btnFilterResolved').className = status === 'RESOLVED' ? 'px-4 py-1.5 text-xs font-semibold rounded bg-green-600 text-white shadow-sm transition-colors' : 'px-4 py-1.5 text-xs font-semibold rounded text-gray-400 hover:text-white hover:bg-gray-700 transition-colors';
        
        currentPage = 1;
        applyAndRender();
    }

    function getLocalYYYYMMDD() {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function handleCardFilter(filterType) {
        const today = getLocalYYYYMMDD();
        
        // Reset advanced filters first
        document.getElementById('filterSLT').value = ''; filterParams.slt = '';
        document.getElementById('filterConcern').value = ''; filterParams.concern = '';
        document.getElementById('filterDept').value = ''; filterParams.dept = '';
        document.getElementById('searchInput').value = ''; searchQuery = '';
        clearSearchBtn.classList.add('hidden');

        switch(filterType) {
            case 'all':
                document.getElementById('filterStartDate').value = ''; filterParams.startDate = '';
                document.getElementById('filterEndDate').value = ''; filterParams.endDate = '';
                setStatusFilter('ALL');
                break;
            case 'received_today':
                document.getElementById('filterStartDate').value = today; filterParams.startDate = today;
                document.getElementById('filterEndDate').value = today; filterParams.endDate = today;
                setStatusFilter('ALL');
                break;
            case 'resolved_today':
                document.getElementById('filterStartDate').value = today; filterParams.startDate = today;
                document.getElementById('filterEndDate').value = today; filterParams.endDate = today;
                setStatusFilter('RESOLVED');
                break;
            case 'pending':
                document.getElementById('filterStartDate').value = ''; filterParams.startDate = '';
                document.getElementById('filterEndDate').value = ''; filterParams.endDate = '';
                setStatusFilter('PENDING');
                break;
        }
    }

    function clearAdvancedFilters() {
        document.getElementById('filterStartDate').value = ''; filterParams.startDate = '';
        document.getElementById('filterEndDate').value = ''; filterParams.endDate = '';
        document.getElementById('filterSLT').value = ''; filterParams.slt = '';
        document.getElementById('filterConcern').value = ''; filterParams.concern = '';
        document.getElementById('filterDept').value = ''; filterParams.dept = '';
        document.getElementById('searchInput').value = ''; searchQuery = '';
        clearSearchBtn.classList.add('hidden');
        setStatusFilter('ALL');
    }

    // --- Image Viewer Logic ---
    let currentZoom = 1;
    let isDragging = false;
    let startX, startY, scrollLeft, scrollTop;
    let imgTranslateX = 0;
    let imgTranslateY = 0;

    function openImageViewer(src) {
        const modal = document.getElementById('imageViewerModal');
        const img = document.getElementById('fullSizeImage');
        
        img.src = src;
        currentZoom = 1;
        updateZoom();
        
        modal.classList.remove('hidden');
        setTimeout(() => modal.classList.remove('opacity-0'), 10);
        document.body.style.overflow = 'hidden'; 
    }

    function closeImageViewer() {
        const modal = document.getElementById('imageViewerModal');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.getElementById('fullSizeImage').src = '';
        }, 300);
        document.body.style.overflow = '';
    }

    function adjustZoom(delta) {
        currentZoom += delta;
        if (currentZoom < 0.25) currentZoom = 0.25;
        if (currentZoom > 5) currentZoom = 5;
        updateZoom();
    }

    function resetZoom() {
        currentZoom = 1;
        updateZoom();
    }

    function updateZoom() {
        const img = document.getElementById('fullSizeImage');
        if(currentZoom <= 1) {
            imgTranslateX = 0;
            imgTranslateY = 0;
        }
        img.style.transform = `translate(${imgTranslateX}px, ${imgTranslateY}px) scale(${currentZoom})`;
        
        const container = document.getElementById('imageViewerContainer');
        container.style.cursor = currentZoom > 1 ? 'grab' : 'default';
    }

    function startDrag(e) {
        if (currentZoom <= 1) return; 
        isDragging = true;
        document.getElementById('imageViewerContainer').style.cursor = 'grabbing';
        startX = e.clientX;
        startY = e.clientY;
        e.preventDefault(); 
    }

    document.addEventListener('mouseup', () => {
        if(isDragging) {
            isDragging = false;
            document.getElementById('imageViewerContainer').style.cursor = 'grab';
        }
    });

    document.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        e.preventDefault();
        const walkX = (e.clientX - startX); 
        const walkY = (e.clientY - startY);
        imgTranslateX += walkX;
        imgTranslateY += walkY;
        startX = e.clientX;
        startY = e.clientY;
        updateZoom();
    });

    document.getElementById('imageViewerContainer').addEventListener('wheel', (e) => {
        e.preventDefault();
        adjustZoom(e.deltaY < 0 ? 0.2 : -0.2);
    });

    document.getElementById('imageViewerContainer').addEventListener('click', (e) => {
        if (e.target.id === 'imageViewerContainer') closeImageViewer();
    });
    
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !document.getElementById('imageViewerModal').classList.contains('hidden')) {
            closeImageViewer();
        }
    });

    function getTodayDateString() {
        const today = new Date();
        const month = (today.getMonth() + 1).toString();
        const day = today.getDate().toString();
        const year = today.getFullYear();
        return `${month}/${day}/${year}`;
    }

    function populateDropdowns() {
        const sltSet = new Set();
        const concernSet = new Set();
        const deptSet = new Set();

        allTickets.forEach(t => {
            if(t.SLT_on_DUTY) sltSet.add(t.SLT_on_DUTY);
            if(t.Issues_Concerning) concernSet.add(t.Issues_Concerning);
            if(t.LOB) deptSet.add(t.LOB);
        });

        const fillSelect = (id, set, label) => {
            const select = document.getElementById(id);
            if (!select) return;
            const currentVal = select.value;
            let html = `<option value="">All ${label}</option>`;
            [...set].sort().forEach(val => {
                html += `<option value="${val}">${val}</option>`;
            });
            select.innerHTML = html;
            if(set.has(currentVal)) select.value = currentVal;
        };

        fillSelect('filterSLT', sltSet, 'SLTs');
        fillSelect('filterConcern', concernSet, 'Concerns');
        fillSelect('filterDept', deptSet, 'Depts');
    }

    async function fetchTicketData() {
        try {
            const response = await fetch('ticket_dashboard.php?action=get_tickets_data');
            const data = await response.json();
            allTickets = data.tickets;

            if (allTickets.length > 0) {
                const latestId = parseInt(allTickets[0].id);
                if (lastMaxTicketId === null) {
                    lastMaxTicketId = latestId;
                } else if (latestId > lastMaxTicketId) {
                    lastMaxTicketId = latestId;
                    console.log("New ticket received! ID:", latestId);
                }
            }

            populateDropdowns();

            // FIX: Prevent focus stealing. Only re-render the side panel if the ticket was 
            // resolved by someone else in the background while you were looking at it.
            if (isPreviewOpen && currentTicketId) {
                const updatedTicket = allTickets.find(t => t.id === currentTicketId);
                if (updatedTicket) {
                    const wasPending = document.getElementById('resolution_details') !== null;
                    const isNowResolved = updatedTicket.Status !== 'PENDING';
                    
                    if (wasPending && isNowResolved) {
                        populatePreviewPanel(updatedTicket);
                    }
                }
            }

            updateDashboard();
        } catch (error) {
            console.error('Error fetching ticket data:', error);
        }
    }

    function updateDashboard() {
        updateCardCounts();
        applyAndRender();
    }
    
    function updateCardCounts() {
        const todayDateString = getTodayDateString();
        
        const receivedTodayCount = allTickets.filter(ticket => 
            ticket.Timestamp.split(' ')[0] === todayDateString
        ).length;
        
        const resolvedTodayCount = allTickets.filter(ticket => 
            ticket.Status === 'RESOLVED' && 
            ticket.Timestamp.split(' ')[0] === todayDateString
        ).length;
        
        const pendingCount = allTickets.filter(ticket => ticket.Status === 'PENDING').length;
        
        document.getElementById('total-tickets-count').textContent = allTickets.length;
        document.getElementById('received-today-count').textContent = receivedTodayCount;
        document.getElementById('resolved-today-count').textContent = resolvedTodayCount;
        document.getElementById('pending-tickets-count').textContent = pendingCount;
        
        const badge = document.getElementById('pending-tickets-badge');
        if (badge) {
            if (pendingCount > 0) {
                badge.textContent = pendingCount > 9 ? '9+' : pendingCount;
                badge.style.display = 'flex';
                badge.classList.add('animate-pulse');
            } else {
                badge.style.display = 'none';
                badge.classList.remove('animate-pulse');
            }
        }
    }

    function applyAndRender() {
        let filtered = allTickets;

        // Apply Search Filter
        if (searchQuery) {
            filtered = filtered.filter(t => 
                (t.Work_Number && t.Work_Number.toLowerCase().includes(searchQuery)) ||
                (t.EID && t.EID.toLowerCase().includes(searchQuery)) ||
                (t.Employee_name && t.Employee_name.toLowerCase().includes(searchQuery)) ||
                (t.Issues_Concerning && t.Issues_Concerning.toLowerCase().includes(searchQuery)) ||
                (t.LOB && t.LOB.toLowerCase().includes(searchQuery)) ||
                (t.Status && t.Status.toLowerCase().includes(searchQuery))
            );
        }

        // Apply Status Filter
        if (filterParams.status !== 'ALL') {
            filtered = filtered.filter(t => t.Status.toUpperCase() === filterParams.status);
        }

        // Apply SLT Filter
        if (filterParams.slt) {
            filtered = filtered.filter(t => t.SLT_on_DUTY === filterParams.slt);
        }

        // Apply Concern Filter
        if (filterParams.concern) {
            filtered = filtered.filter(t => t.Issues_Concerning === filterParams.concern);
        }

        // Apply Dept Filter
        if (filterParams.dept) {
            filtered = filtered.filter(t => t.LOB === filterParams.dept);
        }

        // Apply Date Range Filter
        if (filterParams.startDate || filterParams.endDate) {
            filtered = filtered.filter(t => {
                if (!t.Timestamp) return false;
                try {
                    const datePart = t.Timestamp.split(' ')[0]; // format MM/DD/YYYY
                    const [m, d, y] = datePart.split('/');
                    const tDateStr = `${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`;
                    
                    if (filterParams.startDate && tDateStr < filterParams.startDate) return false;
                    if (filterParams.endDate && tDateStr > filterParams.endDate) return false;
                    return true;
                } catch(e) { return true; }
            });
        }

        const totalPages = Math.ceil(filtered.length / ticketsPerPage);
        // Constrain page bounds if search result shrinks pages
        if(currentPage > totalPages && totalPages > 0) currentPage = totalPages;
        if(currentPage < 1) currentPage = 1;

        const start = (currentPage - 1) * ticketsPerPage;
        const end = start + ticketsPerPage;
        const paginatedTickets = filtered.slice(start, end);
        
        renderTable(paginatedTickets);
        renderPagination(totalPages, currentPage);
    }

    function renderTable(ticketsToRender) {
        const tableBody = document.getElementById('ticket-table-body');
        let html = '';
        if (ticketsToRender.length === 0) {
            html = `<tr><td colspan="11" class="px-6 py-12 text-center">
                <div class="flex flex-col items-center justify-center">
                    <svg class="h-12 w-12 text-gray-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <p class="text-gray-400 text-lg">No tickets found matching your criteria</p>
                </div>
            </td></tr>`;
        } else {
            ticketsToRender.forEach(ticket => {
                const isPending = ticket.Status === 'PENDING';
                const statusBadge = isPending 
                    ? `<span class="px-3 py-1 inline-flex text-xs font-semibold rounded-full bg-yellow-500/20 text-yellow-300 border border-yellow-500/30">Pending</span>`
                    : `<span class="px-3 py-1 inline-flex text-xs font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/30">Resolved</span>`;
                
                const formattedTimestamp = formatTimestamp(ticket.Timestamp);
                
                const isActive = ticket.id === currentTicketId;
                const rowClass = isActive 
                    ? 'bg-blue-900/30 border-l-4 border-blue-500' 
                    : 'hover:bg-gray-700/50 border-l-4 border-transparent';
                
                const textHighlight = isActive ? 'text-blue-300' : 'text-blue-400 group-hover:text-blue-300';
                
                const hideColClass = isPreviewOpen ? 'hidden' : '';

                html += `
                    <tr class="${rowClass} transition duration-200 cursor-pointer group" onclick="openPreview(${JSON.stringify(ticket).replace(/"/g, '&quot;')})">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium ${textHighlight}">${ticket.Work_Number}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">${ticket.EID}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-200 font-medium">${ticket.Employee_name}</td>
                        <td class="hide-on-preview ${hideColClass} px-6 py-4 whitespace-nowrap text-sm text-gray-400 transition-all">${ticket.Station_Number}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">${ticket.LOB}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">${statusBadge}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300 max-w-[150px] truncate" title="${ticket.Issues_Concerning}">${ticket.Issues_Concerning}</td>
                        <td class="hide-on-preview ${hideColClass} px-6 py-4 whitespace-nowrap text-sm text-gray-400 transition-all">${ticket.TIME_RECEIVED}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">${ticket.SLT_on_DUTY || '-'}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">${formattedTimestamp}</td>
                        <td class="hide-on-preview ${hideColClass} px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500 transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline-block ${isActive ? 'opacity-100 text-blue-500' : 'opacity-0 group-hover:opacity-100'} transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </td>
                    </tr>
                `;
            });
        }
        tableBody.innerHTML = html;
    }

    function formatTimestamp(timestamp) {
        if (!timestamp) return 'N/A';
        try {
            const date = new Date(timestamp);
            if (isNaN(date.getTime())) return 'Invalid Date';
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (error) {
            return timestamp;
        }
    }

    function goToPage(page) {
        currentPage = page;
        applyAndRender();
    }

    function renderPagination(totalPages, page) {
        const paginationControls = document.getElementById('pagination-controls');
        if (totalPages <= 1) {
            paginationControls.innerHTML = '';
            return;
        }

        let html = '<ul class="flex items-center justify-center space-x-2">';
        
        const prevDisabled = page === 1 ? 'pointer-events-none opacity-50' : '';
        html += `<li>
            <a href="#" class="flex items-center justify-center w-9 h-9 rounded-lg border border-gray-700 bg-gray-800 text-gray-400 hover:bg-gray-700 hover:text-white transition-colors ${prevDisabled}" 
               onclick="goToPage(${Math.max(1, page - 1)}); return false;" title="Previous Page">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
        </li>`;
        
        const maxVisiblePages = 5;
        let startPage = Math.max(1, page - Math.floor(maxVisiblePages / 2));
        let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
        
        if (endPage - startPage + 1 < maxVisiblePages) {
            startPage = Math.max(1, endPage - maxVisiblePages + 1);
        }
        
        if (startPage > 1) {
            html += `<li><a href="#" class="flex items-center justify-center w-9 h-9 rounded-lg border border-gray-700 bg-gray-800 text-sm font-medium text-gray-300 hover:bg-gray-700 hover:text-white transition-colors" onclick="goToPage(1); return false;">1</a></li>`;
            if (startPage > 2) html += `<li><span class="flex items-center justify-center w-9 h-9 text-gray-500">...</span></li>`;
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === page 
                ? 'bg-blue-600 text-white border-blue-500 shadow-lg shadow-blue-500/30' 
                : 'bg-gray-800 border-gray-700 text-gray-300 hover:bg-gray-700 hover:text-white';
            html += `<li>
                <a href="#" class="flex items-center justify-center w-9 h-9 rounded-lg border text-sm font-medium transition-all ${activeClass}" 
                   onclick="goToPage(${i}); return false;">${i}</a>
            </li>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += `<li><span class="flex items-center justify-center w-9 h-9 text-gray-500">...</span></li>`;
            html += `<li><a href="#" class="flex items-center justify-center w-9 h-9 rounded-lg border border-gray-700 bg-gray-800 text-sm font-medium text-gray-300 hover:bg-gray-700 hover:text-white transition-colors" onclick="goToPage(${totalPages}); return false;">${totalPages}</a></li>`;
        }
        
        const nextDisabled = page === totalPages ? 'pointer-events-none opacity-50' : '';
        html += `<li>
            <a href="#" class="flex items-center justify-center w-9 h-9 rounded-lg border border-gray-700 bg-gray-800 text-gray-400 hover:bg-gray-700 hover:text-white transition-colors ${nextDisabled}" 
               onclick="goToPage(${Math.min(totalPages, page + 1)}); return false;" title="Next Page">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </a>
        </li>`;
        
        html += '</ul>';
        paginationControls.innerHTML = html;
    }

    // --- Side Panel Preview Logic (Replaces Modal) ---
    
    function openPreview(ticket) {
        currentTicketId = ticket.id;
        
        // Populate Panel before showing to prevent flicker
        populatePreviewPanel(ticket);
        
        if (!isPreviewOpen) {
            isPreviewOpen = true;
            // Transition Layout
            document.getElementById('tableContainer').classList.remove('w-full');
            document.getElementById('tableContainer').classList.add('xl:w-[70%]'); // Shrink Table container
            
            document.getElementById('previewPanel').classList.remove('hidden');
            document.getElementById('previewPanel').classList.add('flex', 'flex-col'); // Show Side panel
            
            // Hide columns
            document.querySelectorAll('.hide-on-preview').forEach(el => el.classList.add('hidden'));
        }
        
        // Re-render table to show active state
        applyAndRender();
    }

    function closePreview() {
        isPreviewOpen = false;
        currentTicketId = null;
        
        // Transition Layout Back
        document.getElementById('tableContainer').classList.add('w-full');
        document.getElementById('tableContainer').classList.remove('xl:w-[70%]');
        
        document.getElementById('previewPanel').classList.add('hidden');
        document.getElementById('previewPanel').classList.remove('flex', 'flex-col');
        
        // Show columns
        document.querySelectorAll('.hide-on-preview').forEach(el => el.classList.remove('hidden'));
        
        // Re-render table to remove active state
        applyAndRender();
    }

    function populatePreviewPanel(ticket) {
        const content = document.getElementById('previewContent');
        const footer = document.getElementById('previewFooter');
        
        const isPending = ticket.Status === 'PENDING';
        const statusBadge = isPending 
            ? `<span class="px-2 py-1 inline-flex text-[10px] font-semibold rounded-full bg-yellow-500/20 text-yellow-300 border border-yellow-500/30 uppercase">Pending</span>`
            : `<span class="px-2 py-1 inline-flex text-[10px] font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/30 uppercase">Resolved</span>`;

        // Dynamic Site Color Logic
        let siteBgColor = 'bg-indigo-500/10';
        let siteLabelColor = 'text-indigo-400/80';
        let siteBadgeColor = 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30';
        
        const siteName = (ticket.Site || '').toUpperCase();
        if (siteName.includes('KAWIT')) {
            siteBgColor = 'bg-blue-500/10';
            siteLabelColor = 'text-blue-400/80';
            siteBadgeColor = 'bg-blue-500/20 text-blue-300 border-blue-500/30';
        } else if (siteName.includes('BACOOR')) {
            siteBgColor = 'bg-red-500/10';
            siteLabelColor = 'text-red-400/80';
            siteBadgeColor = 'bg-red-500/20 text-red-300 border-red-500/30';
        }

        let imageHtml = '';
        if (ticket.issue_img) {
            const imgSrc = '../ticketing/' + ticket.issue_img;
            imageHtml = `
                <div class="mt-4 pt-4 border-t border-gray-700/50">
                    <h4 class="text-xs font-bold text-gray-400 mb-2 uppercase tracking-wider">Screenshot</h4>
                    <div class="bg-gray-900/50 p-2 rounded-xl border border-gray-700 flex justify-center group relative cursor-pointer" onclick="openImageViewer('${imgSrc}')">
                        <img src="${imgSrc}" alt="Issue Screenshot" class="max-h-40 object-contain rounded-lg hover:scale-105 transition-transform duration-300">
                        <div class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity rounded-lg">
                            <span class="text-white text-xs font-bold flex items-center gap-1">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                </svg>
                                Enlarge
                            </span>
                        </div>
                    </div>
                </div>
            `;
        }

        const detailsHtml = `
            <div class="space-y-4">
                <!-- Header block -->
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <h3 class="text-2xl font-bold text-white">#${ticket.Work_Number}</h3>
                        ${statusBadge}
                    </div>
                    <p class="text-blue-400 text-sm font-medium">${ticket.Issues_Concerning}</p>
                </div>

                <!-- Info Cards -->
                <div class="bg-gray-700/30 rounded-xl p-3 border border-gray-700 space-y-2 relative overflow-hidden">
                    <div class="absolute right-0 top-0 h-16 w-16 ${siteBgColor} rounded-bl-full -mr-2 -mt-2"></div>
                    <div class="flex justify-between items-start relative z-10">
                        <div>
                            <h4 class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Requester</h4>
                            <p class="text-white text-base font-semibold">${ticket.Employee_name}</p>
                            <p class="text-xs text-gray-400">ID: ${ticket.EID}</p>
                        </div>
                        <div class="text-right">
                            <h4 class="text-[10px] font-bold ${siteLabelColor} uppercase tracking-wider mb-1">Site</h4>
                            <span class="inline-flex items-center px-2 py-1 ${siteBadgeColor} border rounded text-xs font-bold shadow-sm">
                                <svg class="w-3 h-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                ${ticket.Site || 'N/A'}
                            </span>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 mt-2 pt-2 border-t border-gray-700/50 relative z-10">
                        <div>
                            <p class="text-[10px] text-gray-500 uppercase">Dept</p>
                            <p class="text-sm font-medium text-gray-300">${ticket.LOB}</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-500 uppercase">Manager</p>
                            <p class="text-sm font-medium text-gray-300">${ticket.OM}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-700/30 rounded-xl p-3 border border-gray-700 space-y-2">
                    <h4 class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Ticket Info</h4>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <p class="text-[10px] text-gray-500 uppercase">Received</p>
                            <p class="text-sm font-medium text-gray-300">${ticket.TIME_RECEIVED}</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-500 uppercase">Resolved</p>
                            <p class="text-sm font-medium text-gray-300">${ticket.TIME_RESOLVED || '-'}</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-500 uppercase">Station</p>
                            <p class="text-sm font-medium text-gray-300">${ticket.Station_Number}</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-500 uppercase">Urgency</p>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ${ticket.Urgency === 'High' ? 'bg-red-500/20 text-red-400' : 'bg-blue-500/20 text-blue-400'}">
                                ${ticket.Urgency || 'Normal'}
                            </span>
                        </div>
                    </div>
                    <div class="mt-2 pt-2 border-t border-gray-700/50">
                        <p class="text-[10px] text-gray-500 uppercase">SLT on Duty</p>
                        <p class="text-sm font-medium text-blue-400">${ticket.SLT_on_DUTY || 'Unassigned'}</p>
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1.5">Description</h4>
                    <div class="bg-gray-900/50 p-3 rounded-lg border border-gray-700 text-gray-300 text-sm leading-relaxed whitespace-pre-wrap break-words">${ticket.Issue_Details}</div>
                </div>

                ${imageHtml}

                <!-- Resolution Area -->
                ${isPending ? `
                    <div class="mt-4 pt-4 border-t border-gray-700/50">
                        <h4 class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            Resolution Notes
                        </h4>
                        <textarea id="resolution_details" rows="3" 
                            class="block w-full rounded-xl bg-gray-900 border border-gray-700 text-white placeholder-gray-500 focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-3 transition-colors"
                            placeholder="Type how you fixed this issue..."></textarea>
                    </div>
                ` : `
                    <div class="mt-4 pt-4 border-t border-gray-700/50">
                        <h4 class="text-[10px] font-bold text-green-400 uppercase tracking-wider mb-1.5">Resolution Notes</h4>
                        <div class="bg-green-900/10 p-3 rounded-lg border border-green-500/20 text-gray-300 text-sm leading-relaxed">
                            ${ticket.resolution || 'No notes provided.'}
                        </div>
                    </div>
                `}
            </div>
        `;
        
        content.innerHTML = detailsHtml;

        let footerHtml = '';
        if (isPending) {
            footerHtml = `
                <button onclick="resolveTicket(${ticket.id})" class="w-full flex justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow hover:bg-blue-500 transition-colors">
                    Resolve Ticket
                </button>
                <div class="flex gap-2 w-full mt-1">
                    <button onclick="closePreview()" class="flex-1 rounded-lg border border-gray-600 bg-gray-700 px-4 py-2 text-sm font-medium text-gray-300 shadow hover:bg-gray-600 transition-colors">
                        Cancel
                    </button>
                    <button onclick="deleteTicket(${ticket.id}, '${ticket.Work_Number}')" class="flex-1 rounded-lg bg-red-600/10 px-4 py-2 text-sm font-medium text-red-500 hover:bg-red-600/20 hover:text-red-400 transition-colors">
                        Delete
                    </button>
                </div>
            `;
        } else {
            footerHtml = `
                <div class="flex gap-2 w-full">
                    <button onclick="closePreview()" class="flex-1 rounded-lg border border-gray-600 bg-gray-700 px-4 py-2 text-sm font-medium text-gray-300 shadow hover:bg-gray-600 transition-colors">
                        Close
                    </button>
                    <button onclick="deleteTicket(${ticket.id}, '${ticket.Work_Number}')" class="flex-1 rounded-lg bg-red-600/10 px-4 py-2 text-sm font-medium text-red-500 hover:bg-red-600/20 hover:text-red-400 transition-colors">
                        Delete
                    </button>
                </div>
            `;
        }
        footer.innerHTML = footerHtml;
    }

    // FIX: Replaced native alert() and confirm() with custom HTML modals and toasts. Catch JSON Parse errors safely.
    async function executeResolve(ticketId, resolution) {
        const formData = new FormData();
        formData.append('action', 'resolve_ticket');
        formData.append('id', ticketId);
        formData.append('resolution', resolution);

        try {
            const response = await fetch('ticket_dashboard.php', { method: 'POST', body: formData });
            const text = await response.text();
            
            let result;
            try {
                result = JSON.parse(text);
            } catch(e) {
                console.error("Invalid JSON from server. Raw response:", text);
                showNotification("Server returned an invalid response. Check console (F12) for PHP errors.", true);
                return;
            }

            if (result.success) {
                closePreview();
                fetchTicketData();
                showNotification("Ticket resolved successfully!");
            } else {
                showNotification(result.message, true);
            }
        } catch (error) {
            console.error('Error:', error);
            showNotification('An error occurred. Please try again.', true);
        }
    }

    async function resolveTicket(ticketId) {
        const resolution = document.getElementById('resolution_details').value;
        
        if (resolution.trim() === '') {
            showNotification('Please provide details on what you did to fix the issue.', true);
            return;
        }
        
        showConfirmModal("Are you sure you want to resolve this ticket?", () => {
            executeResolve(ticketId, resolution);
        });
    }

    // FIX: Catch JSON Parse errors safely on deletion and replace native alert/confirm
    async function executeDelete(ticketId) {
        const formData = new FormData();
        formData.append('action', 'delete_ticket');
        formData.append('id', ticketId);

        try {
            const response = await fetch('ticket_dashboard.php', { method: 'POST', body: formData });
            const text = await response.text();
            
            let result;
            try {
                result = JSON.parse(text);
            } catch(e) {
                console.error("Invalid JSON from server. Raw response:", text);
                showNotification("Server returned an invalid response. Check console (F12) for PHP errors.", true);
                return;
            }

            if (result.success) {
                closePreview();
                fetchTicketData();
                showNotification("Ticket deleted successfully.");
            } else {
                showNotification(result.message, true);
            }
        } catch (error) {
            console.error('Error:', error);
            showNotification('An error occurred. Please try again.', true);
        }
    }

    async function deleteTicket(ticketId, workNumber) {
        showConfirmModal(`Are you sure you want to delete ticket ${workNumber}? This action cannot be undone.`, () => {
            executeDelete(ticketId);
        });
    }

    async function updateChart(month) {
        try {
            const response = await fetch(`ticket_dashboard.php?action=get_resolved_slt_data&month=${month}`);
            const result = await response.json();

            const labels = result.data.map(item => item.SLT_on_DUTY);
            const counts = result.data.map(item => item.resolved_count);

            if (resolvedTicketsChart) {
                resolvedTicketsChart.destroy();
            }

            const ctx = document.getElementById('resolvedTicketsChart').getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(59, 130, 246, 0.8)'); 
            gradient.addColorStop(1, 'rgba(59, 130, 246, 0.2)');

            resolvedTicketsChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Resolved Tickets',
                        data: counts,
                        backgroundColor: gradient,
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 0,
                        borderRadius: 6,
                        barThickness: 'flex',
                        maxBarThickness: 40
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(17, 24, 39, 0.9)',
                            padding: 12,
                            titleFont: { size: 14, family: "'Inter', sans-serif" },
                            bodyFont: { size: 13, family: "'Inter', sans-serif" },
                            cornerRadius: 8,
                            displayColors: false
                        }
                    },
                    scales: {
                        x: { ticks: { color: '#9CA3AF', font: { size: 12, family: "'Inter', sans-serif" } }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { color: '#9CA3AF', stepSize: 1, font: { size: 11, family: "'Inter', sans-serif" } }, grid: { color: 'rgba(55, 65, 81, 0.2)', borderDash: [5, 5] }, border: { display: false } }
                    },
                    animation: { duration: 1500, easing: 'easeOutQuart' }
                }
            });
        } catch (error) {
            console.error('Error fetching chart data:', error);
        }
    }
    
    document.addEventListener('DOMContentLoaded', () => {
        const monthSelector = document.getElementById('monthSelector');
        
        fetchTicketData();
        updateChart(monthSelector.value);
        
        setInterval(fetchTicketData, 15000); 

        monthSelector.addEventListener('change', (event) => {
            updateChart(event.target.value);
        });
    });

</script>

<?php renderFooter(); ?>