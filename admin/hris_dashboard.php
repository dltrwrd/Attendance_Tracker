<?php

include('../connection.php');
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/db.php'; // For PDO access to games_points

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get current user's sub_name
$userStmt = $pdo->prepare("SELECT sub_name FROM users WHERE id = ?");
$userStmt->execute([$_SESSION['user_id']]);
$user = $userStmt->fetch();
$sub_name = $user['sub_name'];

// --- AJAX ENDPOINTS ---

// Delete Single Ticket
if (isset($_POST['action']) && $_POST['action'] === 'delete_ticket') {
    $ticketId = $_POST['id'] ?? null;
    $response = ['success' => false, 'message' => 'Invalid request.'];

    if ($ticketId) {
        $imgQuery = "SELECT issue_img FROM ticket WHERE id = ?";
        $imgStmt = $con->prepare($imgQuery);
        $imgStmt->bind_param("i", $ticketId);
        $imgStmt->execute();
        $imgResult = $imgStmt->get_result();
        $imgRow = $imgResult->fetch_assoc();
        
        if ($imgRow && !empty($imgRow['issue_img'])) {
            $filePath = '../ticketing/' . $imgRow['issue_img'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $query = "DELETE FROM ticket WHERE id = ?";
        $stmt = $con->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param("i", $ticketId);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Ticket deleted successfully.';
            } else {
                $response['message'] = 'Failed to delete ticket: ' . $stmt->error;
            }
        }
    }
    header('Content-Type: application/json'); echo json_encode($response); exit;
}

// Bulk Delete Tickets
if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
    $ids = json_decode($_POST['ids'], true);
    $response = ['success' => false, 'message' => 'Invalid request.'];
    
    if (is_array($ids) && count($ids) > 0) {
        $idList = implode(',', array_map('intval', $ids));
        
        // Remove images first
        $imgQuery = "SELECT issue_img FROM ticket WHERE id IN ($idList) AND issue_img IS NOT NULL AND issue_img != ''";
        $imgResult = $con->query($imgQuery);
        while($imgRow = $imgResult->fetch_assoc()) {
            $filePath = '../ticketing/' . $imgRow['issue_img'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        if ($con->query("DELETE FROM ticket WHERE id IN ($idList)")) {
            $response['success'] = true;
            $response['message'] = count($ids) . ' tickets deleted successfully.';
        } else {
            $response['message'] = 'Failed to delete tickets.';
        }
    }
    header('Content-Type: application/json'); echo json_encode($response); exit;
}

// Resolve Single Ticket
if (isset($_POST['action']) && $_POST['action'] === 'resolve_ticket') {
    $ticketId = $_POST['id'] ?? null;
    $resolution = $_POST['resolution'] ?? null;
    $response = ['success' => false, 'message' => 'Invalid request.'];

    if ($ticketId) {
        $query = "UPDATE ticket SET Status = 'RESOLVED', TIME_RESOLVED = DATE_FORMAT(CONVERT_TZ(NOW(), '+00:00', '+00:00'), '%l:%i %p'), resolution = ?, SLT_on_DUTY = ? WHERE id = ?";
        $stmt = $con->prepare($query);
        if ($stmt) {
            $stmt->bind_param("ssi", $resolution, $sub_name, $ticketId);
            if ($stmt->execute()) {
                // Add 10 points for resolving ticket (using PDO)
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
            }
        }
    }
    header('Content-Type: application/json'); echo json_encode($response); exit;
}

// Bulk Resolve Tickets
if (isset($_POST['action']) && $_POST['action'] === 'bulk_resolve') {
    $ids = json_decode($_POST['ids'], true);
    $resolution = $_POST['resolution'] ?? null;
    $response = ['success' => false, 'message' => 'Invalid request.'];
    
    if (is_array($ids) && count($ids) > 0 && $resolution) {
        $idList = implode(',', array_map('intval', $ids));
        $query = "UPDATE ticket SET Status = 'RESOLVED', TIME_RESOLVED = DATE_FORMAT(CONVERT_TZ(NOW(), '+00:00', '+00:00'), '%l:%i %p'), resolution = ?, SLT_on_DUTY = ? WHERE id IN ($idList)";
        $stmt = $con->prepare($query);
        if ($stmt) {
            $stmt->bind_param("ss", $resolution, $sub_name);
            if ($stmt->execute()) {
                // Add points for bulk resolve (using PDO)
                $pointsToAdd = count($ids) * 10;
                $gpUserId = (int)$_SESSION['user_id'];
                $gpToday = date('Y-m-d');
                $gpCheck = $pdo->prepare("SELECT user_id FROM games_points WHERE user_id = ?");
                $gpCheck->execute([$gpUserId]);
                if ($gpCheck->fetch()) {
                    $pdo->prepare("UPDATE games_points SET points = points + ? WHERE user_id = ?")->execute([$pointsToAdd, $gpUserId]);
                } else {
                    $pdo->prepare("INSERT INTO games_points (user_id, points, last_reset_date) VALUES (?, ?, ?)")->execute([$gpUserId, $pointsToAdd, $gpToday]);
                }

                $response['success'] = true;
                $response['message'] = count($ids) . ' tickets resolved successfully.';
            }
        }
    }
    header('Content-Type: application/json'); echo json_encode($response); exit;
}

// Fetch all HRIS ticket data
if (isset($_GET['action']) && $_GET['action'] === 'get_hris_tickets_data') {
    function fetchAllHrisTickets($con) {
        $query = "SELECT * FROM ticket WHERE Issues_Concerning = 'HRIS Concern' ORDER BY STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s') DESC";
        $result = $con->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    $tickets = fetchAllHrisTickets($con);
    header('Content-Type: application/json');
    echo json_encode(['tickets' => $tickets]);
    exit;
}

// Fetch employee suggestions for Deep Dive
if (isset($_GET['action']) && $_GET['action'] === 'search_employees') {
    $term = $_GET['term'] ?? '';
    $response = ['success' => false, 'data' => []];

    if (strlen($term) >= 2) {
        try {
            $stmt = $pdo->prepare("SELECT employee_id, full_name, department FROM employees WHERE full_name LIKE :term OR employee_id LIKE :term LIMIT 10");
            $stmt->execute(['term' => '%' . $term . '%']);
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['success'] = true;
        } catch (Exception $e) {
            $response['message'] = $e->getMessage();
        }
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get pending tickets count for the notification badge globally
function getPendingTicketsCount() {
    global $con;
    $query = "SELECT COUNT(*) as count FROM ticket WHERE Status = 'PENDING'";
    $stmt = $con->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

$pendingGlobalTicketsCount = getPendingTicketsCount();

require_once '../components/layout.php';
renderHead('HRIS Analytics');
renderNavbar();
renderSidebar('hris_dashboard' , $pendingGlobalTicketsCount ?? 0);

?>
<div class="pt-2 min-h-screen bg-gray-900 text-white font-sans">
    <main class="p-6 max-w-8xl mx-auto">
        
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-white flex items-center gap-3">
                    <i class="fas fa-users-cog text-purple-500"></i> HRIS Analytics
                </h1>
                <p class="text-gray-400 text-sm mt-1">Detailed statistical breakdown & bulk management</p>
            </div>
            <div class="text-right">
                <div class="text-sm font-medium text-gray-300"><?= date('F j, Y') ?></div>
                <div id="realtime-clock" class="text-xs text-purple-400 font-mono mt-1"></div>
            </div>
        </div>
        
        <?php renderAlert(); ?>

        <!-- Global Date Filter -->
        <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-lg p-4 mb-8 flex flex-col md:flex-row items-end gap-4 animate-fade-in-up">
            <div class="flex-1 md:flex-none">
                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1"><i class="far fa-calendar-alt mr-1"></i> Start Date</label>
                <input type="date" id="globalStartDate" class="w-full md:w-auto bg-gray-900 border border-gray-600 rounded-lg text-white px-3 py-2 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none">
            </div>
            <div class="flex-1 md:flex-none">
                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1"><i class="far fa-calendar-alt mr-1"></i> End Date</label>
                <input type="date" id="globalEndDate" class="w-full md:w-auto bg-gray-900 border border-gray-600 rounded-lg text-white px-3 py-2 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none">
            </div>
            <div class="flex gap-2 w-full md:w-auto">
                <button onclick="applyGlobalFilters()" class="flex-1 md:flex-none bg-purple-600 hover:bg-purple-500 text-white font-medium py-2 px-4 rounded-lg transition-colors flex items-center justify-center gap-2">
                    <i class="fas fa-filter"></i> Apply Global Filter
                </button>
                <button onclick="clearGlobalFilters()" class="md:flex-none bg-gray-700 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition-colors flex items-center justify-center" title="Clear Filters">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        
        <!-- HRIS Dashboard Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <a href="#" onclick="filterAndPaginate('all', 1); return false;" class="group relative bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-lg hover:shadow-xl hover:border-purple-500/50 transition-all duration-300 overflow-hidden">
                <div class="absolute right-0 top-0 h-24 w-24 bg-purple-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative flex justify-between items-start">
                    <div>
                        <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Total Filtered Tickets</p>
                        <h3 class="text-3xl font-bold text-white mt-2" id="total-hris-count">0</h3>
                    </div>
                    <div class="p-3 bg-purple-500/20 rounded-lg text-purple-400 group-hover:bg-purple-500 group-hover:text-white transition-colors">
                        <i class="fas fa-ticket-alt text-xl"></i>
                    </div>
                </div>
            </a>

            <a href="#" onclick="filterAndPaginate('pending', 1); return false;" class="group relative bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-lg hover:shadow-xl hover:border-yellow-500/50 transition-all duration-300 overflow-hidden">
                <div class="absolute right-0 top-0 h-24 w-24 bg-yellow-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative flex justify-between items-start">
                    <div>
                        <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Pending HRIS</p>
                        <h3 class="text-3xl font-bold text-white mt-2" id="pending-hris-count">0</h3>
                    </div>
                    <div class="p-3 bg-yellow-500/20 rounded-lg text-yellow-400 group-hover:bg-yellow-500 group-hover:text-white transition-colors">
                        <i class="fas fa-hourglass-half text-xl"></i>
                    </div>
                </div>
            </a>

            <a href="#" onclick="filterAndPaginate('schedule', 1); return false;" class="group relative bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-lg hover:shadow-xl hover:border-blue-500/50 transition-all duration-300 overflow-hidden">
                <div class="absolute right-0 top-0 h-24 w-24 bg-blue-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative flex justify-between items-start">
                    <div>
                        <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Schedule Concerns</p>
                        <h3 class="text-3xl font-bold text-white mt-2" id="schedule-count">0</h3>
                    </div>
                    <div class="p-3 bg-blue-500/20 rounded-lg text-blue-400 group-hover:bg-blue-500 group-hover:text-white transition-colors">
                        <i class="fas fa-calendar-alt text-xl"></i>
                    </div>
                </div>
            </a>

            <a href="#" onclick="filterAndPaginate('attendance', 1); return false;" class="group relative bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-lg hover:shadow-xl hover:border-pink-500/50 transition-all duration-300 overflow-hidden">
                <div class="absolute right-0 top-0 h-24 w-24 bg-pink-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
                <div class="relative flex justify-between items-start">
                    <div>
                        <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Attendance Bugs</p>
                        <h3 class="text-3xl font-bold text-white mt-2" id="attendance-count">0</h3>
                    </div>
                    <div class="p-3 bg-pink-500/20 rounded-lg text-pink-400 group-hover:bg-pink-500 group-hover:text-white transition-colors">
                        <i class="fas fa-fingerprint text-xl"></i>
                    </div>
                </div>
            </a>
        </div>

        <!-- Analytical Charts Section Row 1 -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Trend Chart -->
            <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl p-6 lg:col-span-2 relative">
                <h2 class="text-xl font-bold text-white flex items-center gap-2 mb-2">
                    <i class="fas fa-chart-line text-blue-400"></i> Dynamic Volume Trend
                </h2>
                <p class="text-xs text-gray-400 mb-4">Incoming vs Resolved across active date range</p>
                <div class="h-72 w-full">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <!-- Breakdown Chart -->
            <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl p-6 lg:col-span-1">
                <h2 class="text-xl font-bold text-white flex items-center gap-2 mb-2">
                    <i class="fas fa-chart-pie text-pink-400"></i> Category Breakdown
                </h2>
                <p class="text-xs text-gray-400 mb-4">Distribution within active dates</p>
                <div class="h-64 w-full flex justify-center items-center">
                    <canvas id="breakdownChart"></canvas>
                </div>
                <div id="breakdown-legend" class="mt-4 flex justify-center gap-4 text-xs">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>

        <!-- System Improvement Insights Row 2 -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl p-6 lg:col-span-1">
                <h2 class="text-lg font-bold text-white flex items-center gap-2 mb-2">
                    <i class="fas fa-user-times text-red-400"></i> Frequent Flyers
                </h2>
                <p class="text-xs text-gray-400 mb-4">Top 10 employees sending HRIS tickets</p>
                <div class="h-64 w-full">
                    <canvas id="topAgentsChart"></canvas>
                </div>
            </div>
            <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl p-6 lg:col-span-1">
                <h2 class="text-lg font-bold text-white flex items-center gap-2 mb-2">
                    <i class="fas fa-building text-yellow-400"></i> LOB Insights
                </h2>
                <p class="text-xs text-gray-400 mb-4">Issues distributed by Department</p>
                <div class="h-64 w-full">
                    <canvas id="deptInsightsChart"></canvas>
                </div>
            </div>
            <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl p-6 lg:col-span-1">
                <h2 class="text-lg font-bold text-white flex items-center gap-2 mb-2">
                    <i class="fas fa-calendar-day text-green-400"></i> Temporal Map
                </h2>
                <p class="text-xs text-gray-400 mb-4">Volume mapped by Day of the Week</p>
                <div class="h-64 w-full">
                    <canvas id="dayOfWeekChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Agent Deep Dive Lookup -->
        <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl mb-8 relative z-20">
            <div class="p-6 border-b border-gray-700 bg-gray-800/80 rounded-t-xl">
                <h2 class="text-xl font-bold text-white flex items-center gap-2 mb-2">
                    <i class="fas fa-search text-purple-400"></i> Agent Deep Dive
                </h2>
                <p class="text-xs text-gray-400 mb-4">Search an agent's entire history to review recurring errors.</p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                            <i class="fas fa-id-badge"></i>
                        </div>
                        <input type="text" id="agentSearchInput" placeholder="Enter Agent Name or EID (e.g. CXI00001)" 
                               autocomplete="off"
                               class="w-full pl-10 pr-4 py-3 bg-gray-900 border border-gray-600 rounded-xl text-white focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none transition-colors"
                               oninput="fetchEmployeeSuggestions(this.value)"
                               onkeypress="if(event.key === 'Enter') searchAgent()">
                        
                        <!-- Suggestion Dropdown -->
                        <ul id="agentSearchSuggestions" class="absolute z-50 w-full mt-1 bg-gray-800 border border-gray-600 rounded-xl shadow-2xl hidden max-h-60 overflow-y-auto divide-y divide-gray-700 custom-scrollbar">
                        </ul>
                    </div>
                    <button onclick="searchAgent()" class="bg-purple-600 hover:bg-purple-500 text-white font-medium py-3 px-6 rounded-xl transition-colors shadow-lg shadow-purple-500/20 flex items-center justify-center gap-2">
                        <i class="fas fa-search"></i> Search History
                    </button>
                </div>
            </div>
            <div id="agentResultsContainer" class="hidden p-6 bg-gray-900/50 rounded-b-xl"></div>
        </div>

        <!-- Master Ticket Table Section -->
        <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl overflow-hidden mb-8 relative z-10" id="hris-table-section">
            <div class="p-6 border-b border-gray-700 bg-gray-800/50 backdrop-blur-sm">
                <div class="flex justify-between items-start md:items-center flex-col md:flex-row gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-white flex items-center gap-2">
                            <i class="fas fa-list-alt text-purple-400"></i> Master Ticket List
                        </h2>
                        <p class="text-sm text-gray-400 mt-1" id="table-subtitle">Showing all HRIS tickets</p>
                    </div>

                    <!-- Bulk Actions Toolbar -->
                    <div id="bulkActionsToolbar" class="hidden flex gap-2 animate-fade-in-up">
                        <span class="bg-gray-900 border border-gray-700 text-gray-300 text-sm font-medium py-2 px-4 rounded-lg flex items-center">
                            <span id="selectedCountDisplay" class="text-purple-400 font-bold mr-2">0</span> Selected
                        </span>
                        <button onclick="openBulkResolveModal()" class="bg-green-600 hover:bg-green-500 text-white text-sm font-medium py-2 px-4 rounded-lg transition-colors shadow-lg flex items-center gap-2">
                            <i class="fas fa-check-double"></i> Bulk Resolve
                        </button>
                        <button onclick="executeBulkDelete()" class="bg-red-600/20 hover:bg-red-600/40 text-red-500 border border-red-500/30 text-sm font-medium py-2 px-4 rounded-lg transition-colors flex items-center gap-2">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    </div>
                </div>

                <!-- Advanced Table Filters -->
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                        <input type="text" id="tableSearchFilter" onkeyup="applyTableFilters()" placeholder="Search Name or Ticket #..." class="w-full pl-9 pr-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm text-white focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none">
                    </div>
                    <select id="tableLobFilter" onchange="applyTableFilters()" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm text-white focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none">
                        <option value="">All Departments (LOB)</option>
                        <!-- Injected by JS -->
                    </select>
                    <select id="tableStatusFilter" onchange="applyTableFilters()" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm text-white focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none">
                        <option value="">All Statuses</option>
                        <option value="PENDING">Pending</option>
                        <option value="RESOLVED">Resolved</option>
                    </select>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700 w-full" style="zoom: 95%">
                    <thead class="bg-gray-900/50">
                        <tr>
                            <th scope="col" class="px-4 py-4 text-center">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleAllCheckboxes(this)" class="w-4 h-4 rounded bg-gray-700 border-gray-600 text-purple-600 focus:ring-purple-500 cursor-pointer">
                            </th>
                            <th scope="col" class="px-4 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Work #</th>
                            <th scope="col" class="px-4 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Emp ID</th>
                            <th scope="col" class="px-4 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Name</th>
                            <th scope="col" class="px-4 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Sub-Category</th>
                            <th scope="col" class="px-4 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Status</th>
                            <th scope="col" class="px-4 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Details</th>
                            <th scope="col" class="px-4 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Date</th>
                            <th scope="col" class="px-4 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">SLT</th>
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
        
        <!-- Individual Ticket Modal -->
        <div id="ticketModal" class="fixed inset-0 z-50 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div class="relative transform overflow-hidden rounded-2xl bg-gray-800 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-3xl border border-gray-700">
                    <div class="px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                        <div id="modalContent"></div>
                    </div>
                    <div id="modalFooter" class="bg-gray-800/50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 border-t border-gray-700"></div>
                </div>
            </div>
        </div>

        <!-- Bulk Resolve Modal -->
        <div id="bulkResolveModal" class="fixed inset-0 z-50 overflow-y-auto hidden" aria-labelledby="bulk-modal-title" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div class="relative transform overflow-hidden rounded-2xl bg-gray-800 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-xl border border-gray-700">
                    <div class="px-6 pt-5 pb-4">
                        <h3 class="text-xl font-bold text-white flex items-center gap-2 mb-4">
                            <i class="fas fa-layer-group text-purple-400"></i> Bulk Resolve <span id="bulkModalCount" class="text-purple-400"></span> Tickets
                        </h3>
                        <p class="text-sm text-gray-400 mb-4">This action will resolve all selected tickets and apply the same resolution notes to each of them.</p>
                        
                        <!-- Canned Responses Quick Add -->
                        <div class="mb-4">
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Quick Insert (Optional)</label>
                            <select id="bulkCannedResponse" onchange="insertCannedResponse('bulk_resolution_details', this.value)" class="w-full bg-gray-900 border border-gray-600 rounded-lg text-white px-3 py-2 text-sm focus:border-purple-500 outline-none">
                                <option value="">Select a common resolution...</option>
                                <option value="Adjusted shift schedule in the system.">Adjusted shift schedule in the system.</option>
                                <option value="Cleared cache and synced attendance biometrics.">Cleared cache & synced attendance.</option>
                                <option value="Escalated to Level 2 Support.">Escalated to L2 Support.</option>
                                <option value="Manually corrected punch ins/outs.">Manually corrected punch ins/outs.</option>
                            </select>
                        </div>

                        <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Resolution Notes</label>
                        <textarea id="bulk_resolution_details" rows="4" 
                            class="block w-full rounded-xl bg-gray-900 border border-gray-600 text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 sm:text-sm p-3 outline-none transition-colors"
                            placeholder="Describe what was done to resolve these issues..."></textarea>
                    </div>
                    <div class="bg-gray-800/50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 border-t border-gray-700">
                        <button onclick="executeBulkResolve()" class="inline-flex w-full justify-center rounded-lg border border-transparent bg-green-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-green-500 sm:ml-3 sm:w-auto sm:text-sm transition-colors">
                            Confirm Bulk Resolve
                        </button>
                        <button onclick="closeBulkResolveModal()" class="mt-3 inline-flex w-full justify-center rounded-lg border border-gray-600 bg-gray-700 px-4 py-2 text-base font-medium text-gray-300 shadow-sm hover:bg-gray-600 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Image Viewer Modal -->
        <div id="imageViewerModal" class="fixed inset-0 z-[100] hidden bg-black/90 backdrop-blur-sm flex items-center justify-center transition-opacity duration-300 opacity-0">
            <div class="absolute top-4 right-4 flex gap-2 z-[101]">
                <button onclick="adjustZoom(-0.25)" class="p-2 bg-gray-800/80 hover:bg-gray-700 text-white rounded-full transition border border-gray-600 shadow-lg"><i class="fas fa-search-minus"></i></button>
                <button onclick="resetZoom()" class="p-2 bg-gray-800/80 hover:bg-gray-700 text-white rounded-full transition border border-gray-600 text-sm font-bold w-10 h-10 shadow-lg">1x</button>
                <button onclick="adjustZoom(0.25)" class="p-2 bg-gray-800/80 hover:bg-gray-700 text-white rounded-full transition border border-gray-600 shadow-lg"><i class="fas fa-search-plus"></i></button>
                <div class="w-px h-10 bg-gray-700 mx-1"></div>
                <button onclick="closeImageViewer()" class="p-2 bg-red-600/80 hover:bg-red-600 text-white rounded-full transition shadow-lg border border-red-500"><i class="fas fa-times"></i></button>
            </div>
            <div id="imageViewerContainer" class="w-full h-full overflow-hidden flex items-center justify-center p-4 cursor-grab select-none" onmousedown="startDrag(event)">
                <img id="fullSizeImage" src="" alt="Full Size Issue" class="max-w-none transition-transform duration-200 ease-out origin-center" style="transform: scale(1);">
            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // System Clock
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

    // Chart Global Config
    Chart.defaults.color = '#9CA3AF';
    Chart.defaults.font.family = "'Inter', sans-serif";

    // Globals
    let allHrisTickets = [];           // Raw from DB
    let globalFilteredTickets = [];    // Filtered by Date
    let tableFilteredTickets = [];     // Filtered by Date + Table Filters + Card Filter
    
    const ticketsPerPage = 10;
    let currentCardFilter = 'all';     // all, pending, schedule, attendance
    let currentPage = 1;
    let selectedTicketIds = new Set(); // For Bulk Actions

    // Chart instances
    let breakdownChartInstance = null;
    let trendChartInstance = null;
    let topAgentsChartInstance = null;
    let deptInsightsChartInstance = null;
    let dayOfWeekChartInstance = null;

    function categorizeHrisTicket(subCatValue) {
        if (!subCatValue || String(subCatValue).trim() === '') return 'Other';
        const cat = String(subCatValue).trim();
        if (cat.toLowerCase() === 'schedule') return 'Schedule';
        if (cat.toLowerCase() === 'attendance') return 'Attendance';
        return cat; 
    }

    // Parse 'm/d/Y H:i:s' to JS Date for accurate comparison
    function parseCustomDate(dateStr) {
        if(!dateStr) return null;
        const parts = dateStr.split(' ');
        const dateParts = parts[0].split('/');
        if(dateParts.length !== 3) return null;
        // month is 0-indexed in JS Date constructor, but here we can just use standardized formatting
        const m = dateParts[0].padStart(2, '0');
        const d = dateParts[1].padStart(2, '0');
        const y = dateParts[2];
        return new Date(`${y}-${m}-${d}T00:00:00`); 
    }

    async function fetchHrisData() {
        try {
            const response = await fetch('hris_dashboard.php?action=get_hris_tickets_data');
            const data = await response.json();
            allHrisTickets = data.tickets;

            allHrisTickets.forEach(t => {
                t.SubCategory = categorizeHrisTicket(t.sub_cat);
                t.ParsedDateObj = parseCustomDate(t.Timestamp); 
            });

            populateLobDropdown();
            applyGlobalFilters(); // This triggers UI, Charts, and Table updates
        } catch (error) {
            console.error('Error fetching HRIS ticket data:', error);
        }
    }

    // --- FILTERS ---

    function applyGlobalFilters() {
        const startInput = document.getElementById('globalStartDate').value;
        const endInput = document.getElementById('globalEndDate').value;

        if (!startInput && !endInput) {
            globalFilteredTickets = [...allHrisTickets];
        } else {
            let start = startInput ? new Date(startInput + 'T00:00:00') : new Date('1900-01-01');
            let end = endInput ? new Date(endInput + 'T23:59:59') : new Date('2100-01-01');

            globalFilteredTickets = allHrisTickets.filter(t => {
                if (!t.ParsedDateObj) return true;
                return t.ParsedDateObj >= start && t.ParsedDateObj <= end;
            });
        }
        
        updateAnalytics();
        applyTableFilters(); // Apply secondary filters to the new globally filtered set
    }

    function clearGlobalFilters() {
        document.getElementById('globalStartDate').value = '';
        document.getElementById('globalEndDate').value = '';
        applyGlobalFilters();
    }

    function populateLobDropdown() {
        const lobs = new Set();
        allHrisTickets.forEach(t => {
            if (t.LOB && t.LOB.trim() !== '') lobs.add(t.LOB.trim());
        });
        const select = document.getElementById('tableLobFilter');
        select.innerHTML = '<option value="">All Departments (LOB)</option>';
        Array.from(lobs).sort().forEach(lob => {
            select.innerHTML += `<option value="${lob}">${lob}</option>`;
        });
    }

    function applyTableFilters() {
        // Table Specific Filters
        const searchTerm = document.getElementById('tableSearchFilter').value.toLowerCase().trim();
        const lobFilter = document.getElementById('tableLobFilter').value;
        const statusFilter = document.getElementById('tableStatusFilter').value;

        tableFilteredTickets = globalFilteredTickets.filter(t => {
            // 1. Card Filter
            if (currentCardFilter === 'schedule' && t.SubCategory !== 'Schedule') return false;
            if (currentCardFilter === 'attendance' && t.SubCategory !== 'Attendance') return false;
            if (currentCardFilter === 'pending' && t.Status !== 'PENDING') return false;

            // 2. Search Filter (Name, EID, Work Number)
            if (searchTerm) {
                const searchStr = `${t.Employee_name} ${t.EID} ${t.Work_Number}`.toLowerCase();
                if (!searchStr.includes(searchTerm)) return false;
            }

            // 3. LOB Filter
            if (lobFilter && t.LOB !== lobFilter) return false;

            // 4. Status Filter
            if (statusFilter && t.Status !== statusFilter) return false;

            return true;
        });

        // Reset to page 1 on filter change
        currentPage = 1;
        renderTablePaginated();
    }

    function filterAndPaginate(cardType, page) {
        currentCardFilter = cardType;
        currentPage = page;
        
        let subtitle = 'Showing all HRIS tickets';
        if (cardType === 'schedule') subtitle = 'Showing Schedule Concerns';
        else if (cardType === 'attendance') subtitle = 'Showing Attendance Bugs/Issues';
        else if (cardType === 'pending') subtitle = 'Showing Pending HRIS Tickets';
        
        document.getElementById('table-subtitle').textContent = subtitle;

        // Ensure table search state doesn't wipe when clicking a card, reapply
        applyTableFilters();
    }

    // --- ANALYTICS & CHARTS ---

    function updateAnalytics() {
        let scheduleCount = 0;
        let attendanceCount = 0;
        let pendingCount = 0;

        globalFilteredTickets.forEach(t => {
            if (t.Status === 'PENDING') pendingCount++;
            if (t.SubCategory === 'Schedule') scheduleCount++;
            else if (t.SubCategory === 'Attendance') attendanceCount++;
        });

        document.getElementById('total-hris-count').textContent = globalFilteredTickets.length;
        document.getElementById('pending-hris-count').textContent = pendingCount;
        document.getElementById('schedule-count').textContent = scheduleCount;
        document.getElementById('attendance-count').textContent = attendanceCount;

        const otherCount = globalFilteredTickets.length - scheduleCount - attendanceCount;
        updateCharts(scheduleCount, attendanceCount, otherCount);
    }

    function updateCharts(sched, att, other) {
        // 1. BREAKDOWN CHART
        const bCtx = document.getElementById('breakdownChart').getContext('2d');
        if (breakdownChartInstance) breakdownChartInstance.destroy();
        breakdownChartInstance = new Chart(bCtx, {
            type: 'doughnut',
            data: {
                labels: ['Schedule', 'Attendance', 'Other'],
                datasets: [{
                    data: [sched, att, other],
                    backgroundColor: ['#3B82F6', '#EC4899', '#6B7280'],
                    borderWidth: 2, borderColor: '#1F2937'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '75%',
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(17, 24, 39, 0.9)', padding: 12, cornerRadius: 8 } }
            }
        });
        document.getElementById('breakdown-legend').innerHTML = `
            <div class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-blue-500 block"></span> Schedule (${sched})</div>
            <div class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-pink-500 block"></span> Attendance (${att})</div>
            <div class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-gray-500 block"></span> Other (${other})</div>
        `;

        // 2. DYNAMIC TREND LINE CHART
        const dateMap = {}; // { 'YYYY-MM-DD': { received: 0, resolved: 0 } }
        
        // Use last 7 days by default if no filter, otherwise aggregate dates dynamically
        const startInput = document.getElementById('globalStartDate').value;
        const endInput = document.getElementById('globalEndDate').value;
        
        let targetDates = [];
        let labels = [];
        
        if(!startInput && !endInput) {
            // Default 7 days
            for(let i=6; i>=0; i--) {
                const d = new Date(); d.setDate(d.getDate() - i);
                const iso = d.toISOString().split('T')[0];
                targetDates.push(iso);
                labels.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                dateMap[iso] = { received: 0, resolved: 0 };
            }
        } else {
            // Extract unique dates from filtered dataset, sorted. Limit to avoid massive x-axis.
            const uniqueDates = new Set();
            globalFilteredTickets.forEach(t => {
                if(t.ParsedDateObj) uniqueDates.add(t.ParsedDateObj.toISOString().split('T')[0]);
            });
            targetDates = Array.from(uniqueDates).sort();
            targetDates.forEach(iso => {
                const d = new Date(iso);
                labels.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                dateMap[iso] = { received: 0, resolved: 0 };
            });
        }

        globalFilteredTickets.forEach(t => {
            if(t.ParsedDateObj) {
                const iso = t.ParsedDateObj.toISOString().split('T')[0];
                if(dateMap[iso]) {
                    dateMap[iso].received++;
                    if(t.Status === 'RESOLVED') dateMap[iso].resolved++;
                }
            }
        });

        const createdData = targetDates.map(iso => dateMap[iso].received);
        const resolvedData = targetDates.map(iso => dateMap[iso].resolved);

        const tCtx = document.getElementById('trendChart').getContext('2d');
        if (trendChartInstance) trendChartInstance.destroy();
        trendChartInstance = new Chart(tCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Received', data: createdData, borderColor: '#A855F7', backgroundColor: 'rgba(168, 85, 247, 0.1)', borderWidth: 2, fill: true, tension: 0.4 },
                    { label: 'Resolved', data: resolvedData, borderColor: '#10B981', backgroundColor: 'transparent', borderWidth: 2, borderDash: [5, 5], tension: 0.4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: true }, tooltip: { mode: 'index', intersect: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(55, 65, 81, 0.2)' } }
                },
                interaction: { mode: 'nearest', axis: 'x', intersect: false }
            }
        });

        renderTopAgentsChart();
        renderDeptInsightsChart();
        renderDayOfWeekChart();
    }

    function renderTopAgentsChart() {
        const counts = {};
        globalFilteredTickets.forEach(t => {
            const name = t.Employee_name || t.EID;
            counts[name] = (counts[name] || 0) + 1;
        });

        const sorted = Object.entries(counts).sort((a,b) => b[1] - a[1]).slice(0, 10);
        const labels = sorted.map(s => s[0].length > 15 ? s[0].substring(0, 15) + '...' : s[0]);
        const data = sorted.map(s => s[1]);

        const ctx = document.getElementById('topAgentsChart').getContext('2d');
        if (topAgentsChartInstance) topAgentsChartInstance.destroy();
        topAgentsChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ label: 'Tickets', data: data, backgroundColor: 'rgba(248, 113, 113, 0.8)', borderRadius: 4 }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(55, 65, 81, 0.2)' } }, y: { grid: { display: false } } }
            }
        });
    }

    function renderDeptInsightsChart() {
        const depts = {};
        globalFilteredTickets.forEach(t => {
            const lob = t.LOB || 'Unknown';
            if (!depts[lob]) depts[lob] = { sched: 0, att: 0, total: 0 };
            depts[lob].total++;
            if(t.SubCategory === 'Schedule') depts[lob].sched++;
            else if(t.SubCategory === 'Attendance') depts[lob].att++;
        });

        const sortedDepts = Object.keys(depts).sort((a,b) => depts[b].total - depts[a].total).slice(0, 8);
        const labels = sortedDepts.map(l => l.length > 12 ? l.substring(0, 12) + '..' : l);
        const schedData = sortedDepts.map(l => depts[l].sched);
        const attData = sortedDepts.map(l => depts[l].att);

        const ctx = document.getElementById('deptInsightsChart').getContext('2d');
        if (deptInsightsChartInstance) deptInsightsChartInstance.destroy();
        deptInsightsChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Schedule', data: schedData, backgroundColor: '#3B82F6' },
                    { label: 'Attendance', data: attData, backgroundColor: '#EC4899' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { boxWidth: 12 } }, tooltip: { mode: 'index', intersect: false } },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(55, 65, 81, 0.2)' } }
                }
            }
        });
    }

    function renderDayOfWeekChart() {
        const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const counts = [0,0,0,0,0,0,0];

        globalFilteredTickets.forEach(t => {
            if (t.ParsedDateObj) counts[t.ParsedDateObj.getDay()]++;
        });

        const ctx = document.getElementById('dayOfWeekChart').getContext('2d');
        if (dayOfWeekChartInstance) dayOfWeekChartInstance.destroy();
        dayOfWeekChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: days,
                datasets: [{ label: 'Tickets', data: counts, backgroundColor: 'rgba(52, 211, 153, 0.8)', borderRadius: 4 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(55, 65, 81, 0.2)' } } }
            }
        });
    }

    // --- TABLE PAGINATION & RENDERING ---

    function renderTablePaginated() {
        const totalPages = Math.ceil(tableFilteredTickets.length / ticketsPerPage);
        const start = (currentPage - 1) * ticketsPerPage;
        const end = start + ticketsPerPage;
        const paginatedTickets = tableFilteredTickets.slice(start, end);
        
        renderTableBody(paginatedTickets);
        renderPagination(totalPages, currentPage);
        updateMasterCheckboxState();
    }

    function renderTableBody(ticketsToRender) {
        const tableBody = document.getElementById('ticket-table-body');
        let html = '';
        if (ticketsToRender.length === 0) {
            html = `<tr><td colspan="9" class="px-6 py-12 text-center">
                <i class="fas fa-box-open text-4xl text-gray-600 mb-4"></i>
                <p class="text-gray-400 text-lg">No HRIS tickets match filters</p>
            </td></tr>`;
        } else {
            ticketsToRender.forEach(ticket => {
                const isPending = ticket.Status === 'PENDING';
                const statusBadge = isPending 
                    ? `<span class="px-3 py-1 inline-flex text-xs font-semibold rounded-full bg-yellow-500/20 text-yellow-300 border border-yellow-500/30">Pending</span>`
                    : `<span class="px-3 py-1 inline-flex text-xs font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/30">Resolved</span>`;
                
                let catBadgeColor = 'bg-gray-500/20 text-gray-300 border-gray-500/30';
                if (ticket.SubCategory === 'Schedule') catBadgeColor = 'bg-blue-500/20 text-blue-300 border-blue-500/30';
                if (ticket.SubCategory === 'Attendance') catBadgeColor = 'bg-pink-500/20 text-pink-300 border-pink-500/30';
                const subCatBadge = `<span class="px-2 py-1 inline-flex text-xs font-medium rounded ${catBadgeColor} border">${ticket.SubCategory}</span>`;
                
                const isChecked = selectedTicketIds.has(ticket.id) ? 'checked' : '';
                
                html += `
                    <tr class="hover:bg-gray-700/50 transition duration-200 cursor-pointer group" onclick="openModal(${JSON.stringify(ticket).replace(/"/g, '&quot;')})">
                        <td class="px-4 py-4 text-center" onclick="event.stopPropagation()">
                            <input type="checkbox" value="${ticket.id}" ${isChecked} onchange="toggleTicketSelection(this, ${ticket.id})" class="ticket-checkbox w-4 h-4 rounded bg-gray-700 border-gray-600 text-purple-600 focus:ring-purple-500 cursor-pointer">
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-purple-400">${ticket.Work_Number}</td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-300">${ticket.EID}</td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-200 font-medium">${ticket.Employee_name}</td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm">${subCatBadge}</td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm">${statusBadge}</td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-400 max-w-xs truncate" title="${ticket.Issue_Details}">${ticket.Issue_Details}</td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-400">${ticket.Timestamp.split(' ')[0]}</td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-400">${ticket.SLT_on_DUTY || '-'}</td>
                    </tr>
                `;
            });
        }
        tableBody.innerHTML = html;
    }

    function renderPagination(totalPages, page) {
        const pc = document.getElementById('pagination-controls');
        if (totalPages <= 1) { pc.innerHTML = ''; return; }
        let html = '<ul class="flex items-center space-x-1">';
        html += `<li><a href="#" class="px-3 py-2 rounded-lg bg-gray-800 text-gray-400 hover:bg-gray-700 hover:text-white transition-colors ${page === 1 ? 'pointer-events-none opacity-50' : ''}" onclick="changePage(${Math.max(1, page - 1)}); return false;"><i class="fas fa-chevron-left"></i></a></li>`;
        
        let startP = Math.max(1, page - 2);
        let endP = Math.min(totalPages, startP + 4);
        if (endP - startP < 4) startP = Math.max(1, endP - 4);
        
        if (startP > 1) html += `<li><a href="#" class="px-3 py-2 rounded-lg bg-gray-700" onclick="changePage(1); return false;">1</a></li><li><span class="px-2 text-gray-500">...</span></li>`;
        
        for (let i = startP; i <= endP; i++) {
            const cls = i === page ? 'bg-purple-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600';
            html += `<li><a href="#" class="px-3 py-2 rounded-lg ${cls}" onclick="changePage(${i}); return false;">${i}</a></li>`;
        }
        
        if (endP < totalPages) html += `<li><span class="px-2 text-gray-500">...</span></li><li><a href="#" class="px-3 py-2 rounded-lg bg-gray-700" onclick="changePage(${totalPages}); return false;">${totalPages}</a></li>`;
        
        html += `<li><a href="#" class="px-3 py-2 rounded-lg bg-gray-800 text-gray-400 hover:bg-gray-700 hover:text-white transition-colors ${page === totalPages ? 'pointer-events-none opacity-50' : ''}" onclick="changePage(${Math.min(totalPages, page + 1)}); return false;"><i class="fas fa-chevron-right"></i></a></li></ul>`;
        pc.innerHTML = html;
    }

    function changePage(p) {
        currentPage = p;
        renderTablePaginated();
    }

    // --- BULK ACTIONS LOGIC ---

    function toggleTicketSelection(checkbox, id) {
        if (checkbox.checked) selectedTicketIds.add(id);
        else selectedTicketIds.delete(id);
        updateBulkActionsToolbar();
        updateMasterCheckboxState();
    }

    function toggleAllCheckboxes(masterCheckbox) {
        const isChecked = masterCheckbox.checked;
        // Select/Deselect ALL filtered tickets, not just the visible page
        tableFilteredTickets.forEach(t => {
            if (isChecked) selectedTicketIds.add(t.id);
            else selectedTicketIds.delete(t.id);
        });
        renderTablePaginated(); // re-render checkboxes
        updateBulkActionsToolbar();
    }

    function updateMasterCheckboxState() {
        const master = document.getElementById('selectAllCheckbox');
        if (tableFilteredTickets.length === 0) { master.checked = false; return; }
        const allFilteredSelected = tableFilteredTickets.every(t => selectedTicketIds.has(t.id));
        master.checked = allFilteredSelected;
    }

    function updateBulkActionsToolbar() {
        const toolbar = document.getElementById('bulkActionsToolbar');
        const countDisplay = document.getElementById('selectedCountDisplay');
        const count = selectedTicketIds.size;
        
        countDisplay.textContent = count;
        if (count > 0) {
            toolbar.classList.remove('hidden');
        } else {
            toolbar.classList.add('hidden');
        }
    }

    function insertCannedResponse(targetId, value) {
        if(value) {
            document.getElementById(targetId).value = value;
        }
    }

    function openBulkResolveModal() {
        document.getElementById('bulkModalCount').textContent = selectedTicketIds.size;
        document.getElementById('bulk_resolution_details').value = '';
        document.getElementById('bulkCannedResponse').value = '';
        document.getElementById('bulkResolveModal').classList.remove('hidden');
    }

    function closeBulkResolveModal() {
        document.getElementById('bulkResolveModal').classList.add('hidden');
    }

    async function executeBulkResolve() {
        const resolution = document.getElementById('bulk_resolution_details').value;
        if (resolution.trim() === '') { alert('Please provide a resolution note for these tickets.'); return; }
        
        const idsArray = Array.from(selectedTicketIds);
        const formData = new FormData();
        formData.append('action', 'bulk_resolve');
        formData.append('ids', JSON.stringify(idsArray));
        formData.append('resolution', resolution);

        try {
            const res = await fetch('hris_dashboard.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                selectedTicketIds.clear();
                updateBulkActionsToolbar();
                closeBulkResolveModal();
                fetchHrisData(); // Refresh all
            } else { alert(data.message); }
        } catch (err) { alert('Error executing bulk resolve.'); }
    }

    async function executeBulkDelete() {
        if (!confirm(`Are you sure you want to permanently delete ${selectedTicketIds.size} tickets?`)) return;
        
        const idsArray = Array.from(selectedTicketIds);
        const formData = new FormData();
        formData.append('action', 'bulk_delete');
        formData.append('ids', JSON.stringify(idsArray));

        try {
            const res = await fetch('hris_dashboard.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                selectedTicketIds.clear();
                updateBulkActionsToolbar();
                fetchHrisData();
            } else { alert(data.message); }
        } catch (err) { alert('Error executing bulk delete.'); }
    }

    // --- AGENT DEEP DIVE ---
    
    function searchAgent() {
        const term = document.getElementById('agentSearchInput').value.toLowerCase().trim();
        const container = document.getElementById('agentResultsContainer');
        if (!term) { container.classList.add('hidden'); return; }

        const filtered = allHrisTickets.filter(t => 
            (t.Employee_name && t.Employee_name.toLowerCase().includes(term)) || 
            (t.EID && t.EID.toLowerCase().includes(term))
        );

        if (filtered.length === 0) {
            container.innerHTML = `<div class="text-center py-8"><i class="fas fa-ghost text-4xl text-gray-600 mb-3"></i><p class="text-gray-400">No HRIS tickets found for "<strong>${term}</strong>".</p></div>`;
            container.classList.remove('hidden'); return;
        }

        const agentName = filtered[0].Employee_name;
        const agentEID = filtered[0].EID;
        const agentLOB = filtered[0].LOB;
        const agentOM = filtered[0].OM;
        let sched = 0, att = 0, oth = 0, htmlTableRows = '';

        filtered.forEach(t => {
            if(t.SubCategory === 'Schedule') sched++;
            else if(t.SubCategory === 'Attendance') att++; else oth++;

            const statusColor = t.Status === 'PENDING' ? 'text-yellow-400' : 'text-green-400';
            const catColor = t.SubCategory === 'Schedule' ? 'text-blue-400' : (t.SubCategory === 'Attendance' ? 'text-pink-400' : 'text-gray-400');
            
            // Highlight frequent flyers!
            const highVolumeWarning = filtered.length > 5 ? `<span class="px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded border border-red-500/30 font-bold ml-3"><i class="fas fa-exclamation-triangle"></i> Frequent Sender</span>` : '';

            htmlTableRows += `
                <tr class="hover:bg-gray-800 transition cursor-pointer" onclick="openModal(${JSON.stringify(t).replace(/"/g, '&quot;')})">
                    <td class="px-4 py-3 text-sm text-gray-300 font-mono">${t.Work_Number}</td>
                    <td class="px-4 py-3 text-sm ${catColor} font-medium">${t.SubCategory}</td>
                    <td class="px-4 py-3 text-xs text-gray-400 truncate max-w-[200px]">${t.Issue_Details}</td>
                    <td class="px-4 py-3 text-sm text-gray-400">${t.Timestamp.split(' ')[0]}</td>
                    <td class="px-4 py-3 text-sm font-semibold ${statusColor}">${t.Status}</td>
                </tr>
            `;
        });

        const warningBadge = filtered.length > 5 ? `<span class="mt-2 inline-block px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded border border-red-500/30 font-bold"><i class="fas fa-exclamation-triangle"></i> High Volume Sender</span>` : '';

        container.innerHTML = `
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="col-span-1 bg-gray-800 border border-gray-700 rounded-xl p-5">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="h-12 w-12 rounded-full bg-purple-500/20 text-purple-400 flex items-center justify-center text-xl font-bold">${agentName.charAt(0)}</div>
                        <div>
                            <h3 class="text-lg font-bold text-white leading-tight">${agentName}</h3>
                            <p class="text-sm text-gray-400 font-mono">${agentEID}</p>
                        </div>
                    </div>
                    <div class="space-y-2 text-sm text-gray-300">
                        <p><span class="text-gray-500 mr-2">Dept:</span> ${agentLOB}</p>
                        <p><span class="text-gray-500 mr-2">OM:</span> ${agentOM}</p>
                    </div>
                    ${warningBadge}
                </div>
                <div class="col-span-2 bg-gray-800 border border-gray-700 rounded-xl p-5 flex items-center">
                    <div class="w-full flex justify-between text-center divide-x divide-gray-700">
                        <div class="flex-1 px-2"><p class="text-xs text-gray-500 uppercase tracking-wider mb-1">Total</p><p class="text-3xl font-bold text-white">${filtered.length}</p></div>
                        <div class="flex-1 px-2"><p class="text-xs text-gray-500 uppercase tracking-wider mb-1">Schedule</p><p class="text-3xl font-bold text-blue-400">${sched}</p></div>
                        <div class="flex-1 px-2"><p class="text-xs text-gray-500 uppercase tracking-wider mb-1">Attendance</p><p class="text-3xl font-bold text-pink-400">${att}</p></div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-900 border border-gray-700 rounded-xl overflow-hidden">
                <div class="overflow-x-auto max-h-64 custom-scrollbar">
                    <table class="w-full text-left"><thead class="bg-gray-800 sticky top-0"><tr>
                        <th class="px-4 py-2 text-xs font-semibold text-gray-500">Ticket #</th>
                        <th class="px-4 py-2 text-xs font-semibold text-gray-500">Sub-Category</th>
                        <th class="px-4 py-2 text-xs font-semibold text-gray-500">Details</th>
                        <th class="px-4 py-2 text-xs font-semibold text-gray-500">Date</th>
                        <th class="px-4 py-2 text-xs font-semibold text-gray-500">Status</th>
                    </tr></thead><tbody class="divide-y divide-gray-800">${htmlTableRows}</tbody></table>
                </div>
            </div>`;
        container.classList.remove('hidden');
    }

    // --- SINGLE TICKET MODAL LOGIC ---

    function openModal(ticket) {
        const modal = document.getElementById('ticketModal');
        const content = document.getElementById('modalContent');
        const footer = document.getElementById('modalFooter');
        
        const isPending = ticket.Status === 'PENDING';
        const statusBadge = isPending ? `<span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-500/20 text-yellow-300 border border-yellow-500/30">Pending</span>` : `<span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/30">Resolved</span>`;
        let imgHtml = ticket.issue_img ? `<div class="mt-4"><h4 class="text-sm font-bold text-gray-300 mb-2">Attached Screenshot</h4><div class="bg-gray-900/50 p-2 rounded-xl border border-gray-700 flex justify-center group relative cursor-pointer" onclick="openImageViewer('../ticketing/${ticket.issue_img}')"><img src="../ticketing/${ticket.issue_img}" class="max-h-64 rounded-lg group-hover:scale-105 transition-transform"><div class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity rounded-lg"><span class="text-white text-sm font-bold flex items-center gap-2"><i class="fas fa-search-plus"></i> View</span></div></div></div>` : '';

        content.innerHTML = `
            <div class="space-y-6">
                <div class="flex justify-between items-start border-b border-gray-700 pb-4">
                    <div><div class="flex items-center gap-3"><h3 class="text-2xl font-bold text-white">#${ticket.Work_Number}</h3>${statusBadge}</div><p class="text-purple-400 mt-1 text-sm font-semibold">${ticket.SubCategory} Concern</p></div>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-white p-1 rounded-full hover:bg-gray-700"><i class="fas fa-times text-xl"></i></button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-gray-700/30 rounded-xl p-4 border border-gray-700"><h4 class="text-xs font-bold text-gray-500 uppercase mb-3">Employee Details</h4>
                        <div class="space-y-3"><div><p class="text-xs text-gray-400">Full Name</p><p class="text-lg font-semibold text-white">${ticket.Employee_name}</p></div>
                        <div class="grid grid-cols-2 gap-4"><div><p class="text-xs text-gray-400">ID</p><p class="text-sm font-medium text-gray-200">${ticket.EID}</p></div><div><p class="text-xs text-gray-400">Dept</p><p class="text-sm font-medium text-gray-200">${ticket.LOB}</p></div></div></div>
                    </div>
                    <div class="bg-gray-700/30 rounded-xl p-4 border border-gray-700"><h4 class="text-xs font-bold text-gray-500 uppercase mb-3">Ticket Metadata</h4>
                        <div class="space-y-3"><div class="grid grid-cols-2 gap-4"><div><p class="text-xs text-gray-400">Received</p><p class="text-sm font-medium text-gray-200">${ticket.TIME_RECEIVED}</p></div><div><p class="text-xs text-gray-400">Resolved</p><p class="text-sm font-medium text-gray-200">${ticket.TIME_RESOLVED || '-'}</p></div></div>
                        <div><p class="text-xs text-gray-400">SLT on Duty</p><p class="text-sm font-medium text-purple-400">${ticket.SLT_on_DUTY || 'Unassigned'}</p></div></div>
                    </div>
                </div>
                <div><h4 class="text-sm font-bold text-gray-300 mb-2">Issue Description</h4><div class="bg-gray-900/50 p-4 rounded-xl border border-gray-700 text-gray-300 text-sm whitespace-pre-line">${ticket.Issue_Details}</div>${imgHtml}</div>
                ${isPending ? `
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <h4 class="text-sm font-bold text-white flex items-center gap-2"><i class="fas fa-wrench text-purple-500"></i> Add Resolution</h4>
                            <select id="singleCannedResponse" onchange="insertCannedResponse('resolution_details', this.value)" class="bg-gray-900 border border-gray-600 rounded text-xs text-white p-1 focus:outline-none focus:border-purple-500">
                                <option value="">Quick Insert...</option>
                                <option value="Adjusted shift schedule in the system.">Adjusted Shift</option>
                                <option value="Cleared cache and synced attendance biometrics.">Synced Attendance</option>
                            </select>
                        </div>
                        <textarea id="resolution_details" rows="3" class="w-full rounded-xl bg-gray-700 border-gray-600 text-white placeholder-gray-400 focus:border-purple-500 p-3 outline-none" placeholder="Resolution steps..."></textarea>
                    </div>` : `
                    <div><h4 class="text-sm font-bold text-green-400 mb-2">Resolution Notes</h4><div class="bg-green-900/10 p-4 rounded-xl border border-green-500/20 text-gray-300 text-sm whitespace-pre-line">${ticket.resolution || '-'}</div></div>
                `}
            </div>`;
        
        footer.innerHTML = `
            <button onclick="deleteTicket(${ticket.id}, '${ticket.Work_Number}')" class="mr-auto inline-flex justify-center mx-3 rounded-lg bg-red-600/10 px-4 py-2 text-sm font-medium text-red-500 hover:bg-red-600/20">Delete</button>
            <button onclick="closeModal()" class="${isPending ? 'mt-3' : ''} inline-flex w-full justify-center rounded-lg bg-gray-700 px-4 py-2 font-medium text-gray-300 hover:bg-gray-600 sm:mt-0 sm:ml-3 sm:w-auto text-sm">Close</button>
            ${isPending ? `<button onclick="resolveTicket(${ticket.id})" class="inline-flex w-full justify-center rounded-lg bg-purple-600 px-4 py-2 font-medium text-white hover:bg-purple-500 sm:ml-3 sm:w-auto text-sm">Resolve Ticket</button>` : ''}
        `;
        modal.classList.remove('hidden');
    }

    function closeModal() { document.getElementById('ticketModal').classList.add('hidden'); }

    async function resolveTicket(ticketId) {
        const res = document.getElementById('resolution_details').value;
        if (!res.trim()) { alert('Please provide details on what you did.'); return; }
        const fd = new FormData(); fd.append('action', 'resolve_ticket'); fd.append('id', ticketId); fd.append('resolution', res);
        try {
            const req = await fetch('hris_dashboard.php', { method: 'POST', body: fd });
            const data = await req.json();
            if (data.success) { closeModal(); fetchHrisData(); } else alert(data.message);
        } catch (e) { alert('Error.'); }
    }

    async function deleteTicket(ticketId, workNum) {
        if (!confirm(`Delete ticket ${workNum}?`)) return;
        const fd = new FormData(); fd.append('action', 'delete_ticket'); fd.append('id', ticketId);
        try {
            const req = await fetch('hris_dashboard.php', { method: 'POST', body: fd });
            const data = await req.json();
            if (data.success) { closeModal(); fetchHrisData(); } else alert(data.message);
        } catch (e) { alert('Error.'); }
    }

    // --- IMAGE ZOOM (Unchanged) ---
    let currentZoom = 1; let isDragging = false; let startX, startY, imgTranslateX = 0, imgTranslateY = 0;
    function openImageViewer(src) {
        const m = document.getElementById('imageViewerModal'); document.getElementById('fullSizeImage').src = src; currentZoom = 1; updateZoom();
        m.classList.remove('hidden'); setTimeout(() => m.classList.remove('opacity-0'), 10); document.body.style.overflow = 'hidden'; 
    }
    function closeImageViewer() {
        const m = document.getElementById('imageViewerModal'); m.classList.add('opacity-0');
        setTimeout(() => { m.classList.add('hidden'); document.getElementById('fullSizeImage').src = ''; }, 300); document.body.style.overflow = '';
    }
    function adjustZoom(delta) { currentZoom = Math.max(0.25, Math.min(5, currentZoom + delta)); updateZoom(); }
    function resetZoom() { currentZoom = 1; updateZoom(); }
    function updateZoom() {
        const img = document.getElementById('fullSizeImage'); if(currentZoom <= 1) { imgTranslateX = 0; imgTranslateY = 0; }
        img.style.transform = `translate(${imgTranslateX}px, ${imgTranslateY}px) scale(${currentZoom})`; document.getElementById('imageViewerContainer').style.cursor = currentZoom > 1 ? 'grab' : 'default';
    }
    function startDrag(e) { if (currentZoom <= 1) return; isDragging = true; document.getElementById('imageViewerContainer').style.cursor = 'grabbing'; startX = e.clientX; startY = e.clientY; e.preventDefault(); }
    document.addEventListener('mouseup', () => { if(isDragging) { isDragging = false; document.getElementById('imageViewerContainer').style.cursor = 'grab'; } });
    document.addEventListener('mousemove', (e) => { if (!isDragging) return; e.preventDefault(); imgTranslateX += (e.clientX - startX); imgTranslateY += (e.clientY - startY); startX = e.clientX; startY = e.clientY; updateZoom(); });
    document.getElementById('imageViewerContainer').addEventListener('wheel', (e) => { e.preventDefault(); adjustZoom(e.deltaY < 0 ? 0.2 : -0.2); });
    document.getElementById('imageViewerContainer').addEventListener('click', (e) => { if (e.target.id === 'imageViewerContainer') closeImageViewer(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !document.getElementById('imageViewerModal').classList.contains('hidden')) closeImageViewer(); });

    // --- AUTOCOMPLETE LOGIC ---
    let suggestionTimeout = null;

    function fetchEmployeeSuggestions(term) {
        const container = document.getElementById('agentSearchSuggestions');

        if (!term || term.length < 2) {
            container.classList.add('hidden');
            return;
        }

        clearTimeout(suggestionTimeout);
        suggestionTimeout = setTimeout(async () => {
            try {
                const res = await fetch(`hris_dashboard.php?action=search_employees&term=${encodeURIComponent(term)}`);
                const json = await res.json();

                if (json.success && json.data.length > 0) {
                    let html = '';
                    json.data.forEach(emp => {
                        html += `
                            <li class="px-4 py-3 hover:bg-gray-700 cursor-pointer transition-colors flex justify-between items-center"
                                onclick="selectAgentSuggestion('${emp.employee_id}')">
                                <div>
                                    <p class="text-sm font-semibold text-white">${emp.full_name}</p>
                                    <p class="text-xs text-gray-400 font-mono">${emp.employee_id}</p>
                                </div>
                                <span class="text-xs bg-gray-900 px-2 py-1 rounded text-gray-400 border border-gray-600">${emp.department || 'No Dept'}</span>
                            </li>
                        `;
                    });
                    container.innerHTML = html;
                    container.classList.remove('hidden');
                } else {
                    container.classList.add('hidden');
                }
            } catch (e) {
                console.error("Error fetching suggestions", e);
            }
        }, 300); // 300ms debounce
    }

    function selectAgentSuggestion(eid) {
        document.getElementById('agentSearchInput').value = eid; 
        document.getElementById('agentSearchSuggestions').classList.add('hidden');
        searchAgent(); 
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        const suggestions = document.getElementById('agentSearchSuggestions');
        const input = document.getElementById('agentSearchInput');
        if (suggestions && input && e.target !== input && !suggestions.contains(e.target)) {
            suggestions.classList.add('hidden');
        }
    });

    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
        fetchHrisData();
        setInterval(fetchHrisData, 15000); 
    });
</script>

<?php renderFooter(); ?>