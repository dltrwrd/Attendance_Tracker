<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Set timezone to Manila for accurate "Today" calculations
$manilaTimezone = new DateTimeZone('Asia/Manila');
$manilaDateObj = new DateTime('now', $manilaTimezone);
$manilaToday = $manilaDateObj->format('Y-m-d');

// Get all filter parameters with proper fallbacks
$search = isset($_POST['search']) ? trim($_POST['search']) : '';
$page = isset($_POST['page']) ? (int)$_POST['page'] : (isset($_GET['page']) ? (int)$_GET['page'] : 1);
$type = isset($_POST['type']) ? $_POST['type'] : (isset($_GET['tab']) ? $_GET['tab'] : 'absenteeism');
$dateFrom = isset($_POST['date_from']) ? $_POST['date_from'] : (isset($_GET['from']) ? $_GET['from'] : '');
$dateTo = isset($_POST['date_to']) ? $_POST['date_to'] : (isset($_GET['to']) ? $_GET['to'] : '');
$department = isset($_POST['department']) ? $_POST['department'] : (isset($_GET['dept']) ? $_GET['dept'] : '');
$coverage = '';
if ($type === 'absenteeism') {
    $coverage = isset($_POST['coverage_1']) ? $_POST['coverage_1'] : (isset($_GET['cov']) ? $_GET['cov'] : '');
}

$cardFilter = isset($_POST['filter']) ? $_POST['filter'] : (isset($_GET['filter']) ? $_GET['filter'] : '');
$irFilter = isset($_POST['ir_filter']) ? $_POST['ir_filter'] : (isset($_GET['ir']) ? $_GET['ir'] : '');
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';

// Ensure we have the table and type from the parent file
if (!isset($type)) $type = 'absenteeism';
if (!isset($table)) {
    $table = ($type === 'tardiness') ? 'tardiness' : ($type === 'vto' ? 'vto_tracker' : 'absenteeism');
}
if (!isset($perPage)) $perPage = 15;

// === NEW: Calculate Today's Count for the counter on top right ===
$todayField = ($type === 'tardiness') ? 'date_of_incident' : ($type === 'vto' ? 'shift_date' : 'date_of_absent');
try {
    // Replaced CURDATE() with Manila's current date
    $todayCountStmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE DATE($todayField) = :manila_today");
    $todayCountStmt->execute([':manila_today' => $manilaToday]);
    $todayCount = $todayCountStmt->fetchColumn();
} catch (Exception $e) {
    $todayCount = 0;
}
$typeDisplay = $type === 'vto' ? 'VTO' : ucfirst($type);
// =================================================================

// Initialize where clauses and parameters
$whereClauses = [];
$params = [];

// Handle card filters (takes precedence over status dropdown)
if (!empty($cardFilter)) {
    switch($cardFilter) {
        case 'pending_emails':
            $whereClauses[] = "email_sent = 0";
            break;
        case 'pending_ir':
            if ($table === 'absenteeism') {
                // For absenteeism: exclude records where ir_form starts with YES or NO NEED
                $whereClauses[] = "ir_form NOT REGEXP '^(YES|NO NEED)'";
            } else {
                // For tardiness: exclude records where ir_form starts with YES or FOR ACCUMULATION
                $whereClauses[] = "ir_form NOT REGEXP '^(YES|FOR ACCUMULATION|NO NEED|EXPIRED)'";
            }
            break;
        case 'pending_coverage':
            $whereClauses[] = "coverage_1 = 'PENDING'";
            break;
        case 'uncovered_shift':
            $whereClauses[] = "coverage_1 = 'UNCOVERED'";
            // Only add date filter if not already filtered by date
            if (empty($dateFrom) && empty($dateTo)) {
                $dateField = ($table === 'tardiness') ? 'date_of_incident' : 'date_of_absent';
                $whereClauses[] = "$dateField = :today_date";
                $params[':today_date'] = $manilaToday; // Use Manila date
            }
            break;
        }
}
// If no card filter, check status dropdown
elseif (!empty($statusFilter)) {
    switch($statusFilter) {
        case 'pending_emails':
            $whereClauses[] = "email_sent = 0";
            break;
        case 'pending_ir':
            if ($table === 'absenteeism') {
                // For absenteeism: exclude records where ir_form starts with YES or NO NEED
                $whereClauses[] = "ir_form NOT REGEXP '^(YES|NO NEED)'";
            } else {
                // For tardiness: exclude records where ir_form starts with YES or FOR ACCUMULATION
                $whereClauses[] = "ir_form NOT REGEXP '^(YES|FOR ACCUMULATION|NO NEED)'";
            }
            break;
        case 'pending_coverage':
            $whereClauses[] = "coverage_1 = 'PENDING'";
            break;
        case 'uncovered_shift':
            $whereClauses[] = "coverage_1 = 'UNCOVERED'";
            // Only add date filter if no date range is specified
            if(empty($dateFrom) && empty($dateTo)) {
                $whereClauses[] = "date_of_absent = :status_today_date";
                $params[':status_today_date'] = $manilaToday; // Use Manila date
            }
            break;
    }
}

// Separate handling for coverage dropdown filter
if(!empty($coverage) && empty($cardFilter)) {
    $whereClauses[] = "coverage_1 = :coverage_1";
    $params[':coverage_1'] = $coverage;
}

// Add other filters (search, date range, department)
if (!empty($search)) {
    $whereClauses[] = "(employee_id LIKE :search OR full_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($dateFrom)) {
    $dateField = ($type === 'tardiness') ? 'date_of_incident' : ($type === 'vto' ? 'shift_date' : 'date_of_absent');
    $whereClauses[] = "$dateField >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if (!empty($dateTo)) {
    $dateField = ($type === 'tardiness') ? 'date_of_incident' : ($type === 'vto' ? 'shift_date' : 'date_of_absent');
    $whereClauses[] = "$dateField <= :date_to";
    $params[':date_to'] = $dateTo;
}

if (!empty($department)) {
    $whereClauses[] = "department = :department";
    $params[':department'] = $department;
}

// ONLY apply coverage filter for absenteeism
if ($type === 'absenteeism' && !empty($coverage)) {
    $whereClauses[] = "coverage_1 = :coverage_1";
    $params[':coverage_1'] = $coverage;
}

// Add this to the where clauses section
if (!empty($irFilter)) {
    $irFiltersArray = explode(',', $irFilter);
    $irOrClauses = [];
    
    foreach ($irFiltersArray as $idx => $singleIrFilter) {
        $singleIrFilter = trim($singleIrFilter);
        if (empty($singleIrFilter)) continue;

        if ($type === 'tardiness') {
            if ($singleIrFilter === 'FOR IR') {
                $irOrClauses[] = "ir_form = 'FOR IR'";
            } 
            elseif ($singleIrFilter === 'FOR ACCUMULATION') {
                $irOrClauses[] = "ir_form = 'FOR ACCUMULATION'";
            }
            elseif ($singleIrFilter === 'PENDING') {
                $irOrClauses[] = "ir_form LIKE 'PENDING%'";
            }
            elseif (strpos(strtoupper($singleIrFilter), 'NO NEED') === 0) {
                $irOrClauses[] = "ir_form = :ir_filter_no_need_$idx";
                $params[":ir_filter_no_need_$idx"] = $singleIrFilter;
            }
            // Handle specific pending dates
            elseif (preg_match('/PENDING \/ ([A-Z]{3,4} [0-9]{1,2})/', $singleIrFilter, $matches)) {
                $datePart = $matches[1];
                $irOrClauses[] = "ir_form LIKE :ir_filter_$idx";
                $params[":ir_filter_$idx"] = "PENDING / $datePart%";
            }
        } else {
            // Absenteeism filtering
            if ($singleIrFilter === 'FOR IR') {
                $irOrClauses[] = "ir_form = 'FOR IR'";
            }
            elseif (strpos(strtoupper($singleIrFilter), 'NO NEED') === 0) {
                $irOrClauses[] = "ir_form = :ir_filter_no_need_$idx";
                $params[":ir_filter_no_need_$idx"] = $singleIrFilter;
            }
            // Handle specific pending dates for absenteeism
            elseif (preg_match('/PENDING \/ ([A-Z]{3,4} [0-9]{1,2})/', $singleIrFilter, $matches)) {
                $datePart = $matches[1];
                $irOrClauses[] = "ir_form LIKE :ir_filter_$idx";
                $params[":ir_filter_$idx"] = "PENDING / $datePart%";
            }
        }
    }
    
    if (!empty($irOrClauses)) {
        $whereClauses[] = "(" . implode(' OR ', $irOrClauses) . ")";
    }
    
    // Add this condition to exclude "YES" records when filtering for specific IR statuses in tardiness
    if ($type === 'tardiness') {
        $excludeYes = false;
        foreach ($irFiltersArray as $singleIrFilter) {
            $singleIrFilter = trim($singleIrFilter);
            if (in_array($singleIrFilter, ['FOR IR', 'FOR ACCUMULATION', 'PENDING']) || strpos(strtoupper($singleIrFilter), 'NO NEED') === 0) {
                $excludeYes = true;
                break;
            }
        }
        if ($excludeYes) {
            $whereClauses[] = "ir_form != 'YES'";
        }
    }
}

$searchQuery = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM $table $searchQuery");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetchColumn();
} catch (PDOException $e) {
    $totalRecords = 0;
}

$totalPages = ceil($totalRecords / $perPage);
$page = min($page, max(1, $totalPages));
$offset = ($page - 1) * $perPage;

try {
    // Determine the order by clause
    $orderBy = "ORDER BY created_at DESC"; // Default ordering
    
    // If IR filter is applied, order by the extracted time from ir_form
    if (!empty($irFilter)) {
        // Extract and parse the time from ir_form for proper ordering
        $orderBy = "ORDER BY 
            CASE 
                WHEN ir_form LIKE '%AM%' OR ir_form LIKE '%PM%' THEN
                    STR_TO_DATE(
                        CONCAT(
                            SUBSTRING_INDEX(SUBSTRING_INDEX(ir_form, ' ', -2), ' ', 1),
                            ' ',
                            SUBSTRING_INDEX(ir_form, ' ', -1)
                        ),
                        '%l:%i %p'
                    )
                ELSE STR_TO_DATE('12:00 AM', '%l:%i %p')
            END ASC";
    }
    
    if (isset($_POST['export_csv'])) {
        $exportQuery = "SELECT * FROM $table $searchQuery $orderBy";
        $exportStmt = $pdo->prepare($exportQuery);
        foreach ($params as $key => $value) {
            $exportStmt->bindValue($key, $value);
        }
        $exportStmt->execute();
        $records = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build a friendly title based on the date range
        $tabLabel = ucfirst($type); // e.g. "Absenteeism", "Tardiness"
        if (!empty($dateFrom) && !empty($dateTo)) {
            $fromLabel = date('M j, Y', strtotime($dateFrom));
            $toLabel   = date('M j, Y', strtotime($dateTo));
            // Title month/year based on from date
            $titleMonth = date('F Y', strtotime($dateFrom));
        } elseif (!empty($dateFrom)) {
            $fromLabel  = date('M j, Y', strtotime($dateFrom));
            $toLabel    = '-';
            $titleMonth = date('F Y', strtotime($dateFrom));
        } elseif (!empty($dateTo)) {
            $fromLabel  = '-';
            $toLabel    = date('M j, Y', strtotime($dateTo));
            $titleMonth = date('F Y', strtotime($dateTo));
        } else {
            $fromLabel  = 'All';
            $toLabel    = 'All';
            $titleMonth = date('F Y');
        }

        $deptLabel    = !empty($department) ? $department : 'All';
        $totalEmp     = count($records);
        $exportDate   = date('m/d/Y G:i');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . strtolower($tabLabel) . '_export_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        
        // ---- Header block (matches the screenshot format) ----
        fputcsv($output, ["$tabLabel Export - $titleMonth"]);
        fputcsv($output, ['Period:', "$fromLabel to $toLabel"]);
        fputcsv($output, ['Department:', $deptLabel]);
        fputcsv($output, ['Total Emp', $totalEmp]);
        fputcsv($output, ['Export Date', $exportDate]);
        fputcsv($output, []); // blank separator row
        // ------------------------------------------------------

        if (count($records) > 0) {
            fputcsv($output, array_keys($records[0]));
            foreach ($records as $row) {
                fputcsv($output, $row);
            }
        } else {
            fputcsv($output, ['No records found matching filters.']);
        }
        fclose($output);
        exit;
    }
    
    $query = "SELECT * FROM $table $searchQuery $orderBy LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($query);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();

    // Only process for tardiness records - simplified version
    if ($type === 'tardiness') {
        // Group records by employee_id for display purposes only
        $employeeRecords = [];
        foreach ($records as $record) {
            $employeeId = $record['employee_id'];
            if (!isset($employeeRecords[$employeeId])) {
                $employeeRecords[$employeeId] = [];
            }
            $employeeRecords[$employeeId][] = $record;
        }

        // Just update the display array to match what's in the database
        foreach ($employeeRecords as $employeeId => $empRecords) {
            foreach ($empRecords as $updatedRecord) {
                foreach ($records as &$originalRecord) {
                    if ($originalRecord['id'] === $updatedRecord['id']) {
                        $originalRecord = $updatedRecord;
                        break;
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
    $records = [];
}
?>

<!-- START HTML RENDER -->
<div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden shadow">
    
    <!-- NEW HEADER: Today's Count & Column Toggler -->
    <div class="flex justify-between items-center p-4 bg-gray-800/80 border-b border-gray-700/50">
        <div class="text-sm font-bold text-gray-300 flex items-center gap-2">
            <i class="fas fa-calendar-day text-primary-400 text-lg"></i>
            Total <?= $typeDisplay ?> Today: 
            <span class="bg-primary-500/20 text-primary-400 px-3 py-1 rounded-md border border-primary-500/30 shadow-inner">
                <?= $todayCount ?>
            </span>
        </div>
        <div id="columnToggleContainer" class="relative z-20">
            <!-- Dropdown injected via JS -->
        </div>
    </div>
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 w-full transition-all duration-300" style="zoom:85%">
            <thead class="bg-gray-700">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-8 transition-all">
                        <input type="checkbox" id="selectAllCheckbox">
                    </th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">CXI Number</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-48 transition-all">Full Name</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40 transition-all">Department</th>
                    
                    <?php if ($type === 'absenteeism'): ?>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Supervisor</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Operations Manager</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Date of Absence</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Sanction</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Reason</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24 transition-all">Shift</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40 transition-all">Followed Procedure</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-64 transition-all">Coverage Details</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40 transition-all">Incident Report</th>
                    <?php elseif ($type === 'tardiness'): ?>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Operations Manager</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Date of Tardiness</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24 transition-all">Type</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-20 transition-all">Minutes</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24 transition-all">Shift</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-64 transition-all">Coverage Details</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40 transition-all">Incident Report</th>
                    <?php else: ?>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Shift Date</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24 transition-all">Shift</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-20 transition-all">Time In</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-20 transition-all">Time Out</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24 transition-all">Worked (mins)</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24 transition-all">VTO (mins)</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">VTO Type</th>
                    <?php endif; ?>
                    
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40 transition-all">Reported By</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Time Reported</th>
                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider w-32 transition-all">Actions</th>
                </tr>
            </thead>
            
            <tbody class="bg-gray-800 divide-y divide-gray-700">
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="<?= $type === 'absenteeism' ? 16 : ($type === 'tardiness' ? 14 : 15) ?>" class="px-6 py-8 text-center text-gray-400">
                            <i class="fas fa-users-slash text-3xl mb-3 opacity-50"></i>
                            <p class="text-lg">No records found</p>
                            <p class="text-sm mt-1" style="text-transform: uppercase;"><?= $type ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                    
                    <?php if ($type === 'vto'): ?>
                        <tr class="hover:bg-gray-700/50">
                            <td class="px-4 py-4 whitespace-nowrap text-center transition-all">
                                <input type="checkbox" class="record-checkbox" data-id="<?= $record['id'] ?>">
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                <div class="text-sm font-medium text-gray-100" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['employee_id'] ?? '') ?>"><?= htmlspecialchars($record['employee_id'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['full_name'] ?? '') ?>"><?= htmlspecialchars($record['full_name'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['department'] ?? '') ?>"><?= htmlspecialchars($record['department'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap transition-all">
                                <div class="text-sm text-gray-300"><?= isset($record['shift_date']) ? date('M d, Y', strtotime($record['shift_date'])) : '' ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap transition-all">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['shift'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap transition-all">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['time_in'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap transition-all">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['time_out'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap transition-all">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['mins_of_work'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap transition-all">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['vto_mins'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                <?php
                                $vtoColor = 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30';
                                $vtoTypeUpper = strtoupper(trim($record['vto_type'] ?? ''));
                                if ($vtoTypeUpper === 'REALTIME') {
                                    $vtoColor = 'bg-green-500/20 text-green-300 border border-green-500/30';
                                } elseif ($vtoTypeUpper === 'REALTIME - WD' || $vtoTypeUpper === 'REALTIME WD') {
                                    $vtoColor = 'bg-blue-500/20 text-blue-300 border border-blue-500/30';
                                }
                                ?>
                                <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $vtoColor ?>" title="<?= htmlspecialchars($record['vto_type'] ?? '') ?>">
                                    <?= htmlspecialchars($record['vto_type'] ?? '') ?> 
                                </span>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                <div class="text-sm text-gray-300" title="<?= htmlspecialchars($record['sub_name'] ?? '') ?>"><?= htmlspecialchars($record['sub_name'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap transition-all">
                                <div class="text-sm text-gray-300"><?= isset($record['timestamp']) ? date('g:i A', strtotime($record['timestamp'])) : '' ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-medium transition-all">
                                <?php if ($type === 'absenteeism'): ?>
                                    <?php if (!$record['email_sent']): ?>
                                        <a href="send_email.php?send_email=<?= $record['id'] ?>&type=<?= $type ?>" onclick="return confirmSendEmail(event, this.href)" title="Send Email" class="text-blue-500 hover:text-blue-400 mr-3">
                                            <i class="fas fa-envelope"></i>
                                        </a>
                                    <?php else: ?>
                                        <span title="Email sent on <?= date('M d, Y g:i A', strtotime($record['email_sent_at'])) ?>" class="text-green-500 mr-3">
                                            <i class="fas fa-check-circle"></i>
                                        </span>
                                    <?php endif; ?>
                                    <button onclick="copyAbsentReport(<?= $record['id'] ?>)" class="text-blue-400 hover:text-blue-300 mr-3" title="Copy Absent Report" style="background: none; border: none; cursor: pointer;">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                <?php endif; ?>
    
                                <!-- FIRE BUTTON -->
                                <a href="attendance.php?fire_employee=<?= $record['id'] ?>&type=<?= $type ?>" title="Fire Employee" class="text-red-600 hover:text-red-500 mr-3" onclick="return fireEmployee(<?= $record['id'] ?>, '<?= $type ?>')">
                                    <i class="fas fa-fire"></i>
                                </a>
    
                                <a href="vto_form.php?id=<?= $record['id'] ?>&type=<?= $type ?>" title="Edit record" class="text-primary-500 hover:text-primary-400 mr-3">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="#" onclick="event.preventDefault(); showHistoryModal(<?= $record['id'] ?>, '<?= $type ?>')" title="View History" class="text-purple-500 hover:text-purple-400 mr-3">
                                    <i class="fas fa-history"></i>
                                </a>
                                <a href="#" onclick="event.preventDefault(); showDeleteModal(<?= $record['id'] ?>, '<?= $type ?>')" class="text-red-500 hover:text-red-400" title="Delete record">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        
                    <?php else: ?>
                        <tr class="hover:bg-gray-700/50">
                            <td class="px-4 py-4 whitespace-nowrap text-center transition-all">
                                <input type="checkbox" class="record-checkbox" data-id="<?= $record['id'] ?>">
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                <div class="text-sm font-medium text-gray-100" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['employee_id']) ?>"><?= htmlspecialchars($record['employee_id']) ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['full_name']) ?>"><?= htmlspecialchars($record['full_name']) ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['department']) ?>"><?= htmlspecialchars($record['department']) ?></div>
                            </td>

                            <?php if ($type === 'absenteeism'): ?>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['supervisor']) ?>"><?= htmlspecialchars($record['supervisor']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['operation_manager']) ?>"><?= htmlspecialchars($record['operation_manager']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap transition-all">
                                    <div class="text-sm text-gray-300"><?= date('M d, Y', strtotime($record['date_of_absent'])) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['sanction']) ?>"><?= htmlspecialchars($record['sanction']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['reason']) ?>"><?= htmlspecialchars($record['reason']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap transition-all">
                                    <div class="text-sm text-gray-300"><?= htmlspecialchars($record['shift']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap transition-all">
                                    <?php
                                    $procVal = strtoupper($record['follow_call_in_procedure'] ?? '');
                                    if ($procVal === 'NO') {
                                        $procClass = 'bg-red-500/20 text-red-300 border border-red-500/30';
                                    } elseif (strpos($procVal, 'YES') !== false) {
                                        $procClass = 'bg-green-500/20 text-green-300 border border-green-500/30';
                                    } elseif (strpos($procVal, 'PENDING') !== false || strpos($procVal, 'ADVISE') !== false) {
                                        $procClass = 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30';
                                    } else {
                                        $procClass = 'bg-green-500/20 text-green-300 border border-green-500/30';
                                    }
                                    ?>
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $procClass ?>">
                                        <?= htmlspecialchars($record['follow_call_in_procedure']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                    <div class="text-sm text-gray-300" 
                                        title="<?php
                                        // Combine all coverage fields into one display for tooltip
                                        $coverageDetails = [];
                                        for ($i = 1; $i <= 4; $i++) {
                                            $coverageField = "coverage_{$i}";
                                            $coverageTypeField = "coverage_type_{$i}";
                                            $coverageDetailsField = "coverage_details_{$i}";
                                            
                                            if (!empty($record[$coverageField])) {
                                                $coverageText = htmlspecialchars($record[$coverageField]);
                                                $coverageType = htmlspecialchars($record[$coverageTypeField] ?? '');
                                                $coverageDetail = htmlspecialchars($record[$coverageDetailsField] ?? '');
                                                
                                                // Format based on coverage type
                                                if ($coverageType === 'PENDING') {
                                                    // For PENDING, just show "PENDING" without parentheses
                                                    $detail = $coverageText;
                                                } else {
                                                    $detail = $coverageText;
                                                    if (!empty($coverageType) && $coverageType !== '-' && $coverageType !== 'PENDING') {
                                                        $detail .= " ($coverageType";
                                                        if (!empty($coverageDetail) && $coverageDetail !== '-') {
                                                            $detail .= " - $coverageDetail";
                                                        }
                                                        $detail .= ")";
                                                    }
                                                }
                                                
                                                $coverageDetails[] = $detail;
                                            }
                                        }
                                        
                                        if (!empty($coverageDetails)) {
                                            echo implode(' | ', $coverageDetails);
                                        } else {
                                            echo '-';
                                        }
                                        ?>">
                                        <?php
                                        // Combine all coverage fields into one display for visible content
                                        $coverageDetails = [];
                                        for ($i = 1; $i <= 4; $i++) {
                                            $coverageField = "coverage_{$i}";
                                            $coverageTypeField = "coverage_type_{$i}";
                                            $coverageDetailsField = "coverage_details_{$i}";
                                            
                                            if (!empty($record[$coverageField])) {
                                                $coverageText = htmlspecialchars($record[$coverageField]);
                                                $coverageType = htmlspecialchars($record[$coverageTypeField] ?? '');
                                                $coverageDetail = htmlspecialchars($record[$coverageDetailsField] ?? '');
                                                
                                                // Format based on coverage type
                                                if ($coverageType === 'PENDING') {
                                                    // For PENDING, just show "PENDING" without parentheses
                                                    $detail = $coverageText;
                                                } else {
                                                    $detail = $coverageText;
                                                    if (!empty($coverageType) && $coverageType !== '-' && $coverageType !== 'PENDING') {
                                                        $detail .= " ($coverageType";
                                                        if (!empty($coverageDetail) && $coverageDetail !== '-') {
                                                            $detail .= " - $coverageDetail";
                                                        }
                                                        $detail .= ")";
                                                    }
                                                }
                                                
                                                $coverageDetails[] = $detail;
                                            }
                                        }
                                        
                                        if (!empty($coverageDetails)) {
                                            echo implode('<br>', $coverageDetails);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                    <div class="text-sm text-gray-300 flex items-center" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['ir_form'] ?? '') ?>">
                                        <?php if (strpos(strtoupper($record['ir_form'] ?? ''), 'FOR IR') !== false): ?>
                                            <div class="w-2 h-2 rounded-full bg-red-500 mr-2 flex-shrink-0 animate-pulse shadow-[0_0_5px_rgba(239,68,68,0.8)]" title="Action Required: For IR"></div>
                                        <?php endif; ?>
                                        <span class="truncate"><?= htmlspecialchars($record['ir_form'] ?? '') ?></span>
                                    </div>
                                </td>
                            <?php elseif ($type === 'tardiness'): ?>
                                <td class="px-4 py-4 whitespace-nowrap transition-all">
                                    <div class="text-sm text-gray-300"><?= $record['operation_manager'] ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap transition-all">
                                    <div class="text-sm text-gray-300"><?= date('M d, Y', strtotime($record['date_of_incident'])) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap transition-all">
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full  <?= $record['types'] === 'Late' ? 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30' : 'bg-red-500/20 text-red-300 border border-red-500/30' ?>">
                                        <?= $record['types'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap transition-all">
                                    <div class="text-sm text-gray-300"><?= $record['minutes_late'] ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap transition-all">
                                    <div class="text-sm text-gray-300"><?= $record['shift'] ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                    <div class="text-sm text-gray-300" 
                                        title="<?php
                                        if (!empty($record['coverage_1'])) {
                                            $coverageText = htmlspecialchars($record['coverage_1']);
                                            $coverageType = htmlspecialchars($record['coverage_type_1'] ?? '');
                                            $coverageDetail = htmlspecialchars($record['coverage_details_1'] ?? '');
                                            
                                            if ($coverageType === 'PENDING') {
                                                $detail = $coverageText;
                                            } else {
                                                $detail = $coverageText;
                                                if (!empty($coverageType) && $coverageType !== '-' && $coverageType !== 'PENDING') {
                                                    $detail .= " ($coverageType";
                                                    if (!empty($coverageDetail) && $coverageDetail !== '-') {
                                                        $detail .= " - $coverageDetail";
                                                    }
                                                    $detail .= ")";
                                                }
                                            }
                                            echo $detail;
                                        } else {
                                            echo '-';
                                        }
                                        ?>">
                                        <?php
                                        if (!empty($record['coverage_1'])) {
                                            echo $detail;
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                    <div class="text-sm text-gray-300 flex items-center" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['ir_form'] ?? '') ?>">
                                        <?php if (strpos(strtoupper($record['ir_form'] ?? ''), 'FOR IR') !== false): ?>
                                            <div class="w-2 h-2 rounded-full bg-red-500 mr-2 flex-shrink-0 animate-pulse shadow-[0_0_5px_rgba(239,68,68,0.8)]" title="Action Required: For IR"></div>
                                        <?php endif; ?>
                                        <span class="truncate"><?= htmlspecialchars($record['ir_form'] ?? '') ?></span>
                                    </div>
                                </td>
                            <?php endif; ?>
                            
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs transition-all">
                                <div class="text-sm text-gray-300" title="<?= htmlspecialchars($record['sub_name']) ?>"><?= htmlspecialchars($record['sub_name']) ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap transition-all">
                                <div class="text-sm text-gray-300"><?= date('g:i A', strtotime($record['timestamp'])) ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-medium transition-all">
                                <?php if (!$record['email_sent']): ?>
                                    <a href="send_email.php?send_email=<?= $record['id'] ?>&type=<?= $type ?>" onclick="return confirmSendEmail(event, this.href)" title="Send Email" class="text-blue-500 hover:text-blue-400 mr-3">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                <?php else: ?>
                                    <span title="Email sent on <?= date('M d, Y g:i A', strtotime($record['email_sent_at'])) ?>" class="text-green-500 mr-3">
                                        <i class="fas fa-check-circle"></i>
                                    </span>
                                <?php endif; ?>
                                    <?php if ($type === 'absenteeism'): ?>
                                        <button onclick="copyAbsentReport(<?= $record['id'] ?>)" 
                                                class="text-blue-400 hover:text-blue-300 mr-3" 
                                                title="Copy Absent Report"
                                                style="background: none; border: none; cursor: pointer;">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    <?php endif; ?>
                                
                                <!-- FIRE BUTTON -->
                                <a href="attendance.php?fire_employee=<?= $record['id'] ?>&type=<?= $type ?>" 
                                    title="Fire Employee" 
                                    class="text-red-600 hover:text-red-500 mr-3" 
                                    onclick="return fireEmployee(<?= $record['id'] ?>, '<?= $type ?>')">
                                        <i class="fas fa-fire"></i>
                                </a>
                                
                                <a href="attendance_form.php?id=<?= $record['id'] ?>&type=<?= $type ?>" title="Edit record" class="text-primary-500 hover:text-primary-400 mr-3">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="#" onclick="event.preventDefault(); showHistoryModal(<?= $record['id'] ?>, '<?= $type ?>')" title="View History" class="text-purple-500 hover:text-purple-400 mr-3">
                                    <i class="fas fa-history"></i>
                                </a>
                                <a href="#" onclick="event.preventDefault(); showDeleteModal(<?= $record['id'] ?>, '<?= $type ?>')" class="text-red-500 hover:text-red-400" title="Delete record">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="p-4 flex items-center justify-between">
    <div class="text-sm text-gray-400">
        Showing <?= ($offset + 1) ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> records
    </div>
    <div class="flex gap-1">
        <?php if ($page > 1): ?>
            <a href="#" data-page="1" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <a href="#" data-page="<?= $page - 1 ?>" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700">
                <i class="fas fa-angle-left"></i>
            </a>
        <?php endif; ?>

        <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="#" data-page="<?= $i ?>" class="pagination-link px-3 py-1 rounded-lg border <?= $i == $page ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-600 text-gray-300 hover:bg-gray-700' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="#" data-page="<?= $page + 1 ?>" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700">
                <i class="fas fa-angle-right"></i>
            </a>
            <a href="#" data-page="<?= $totalPages ?>" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div id="historyModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md">
        <!-- Card Header -->
        <div class="flex justify-between items-center px-6 py-6">
            <h3 class="text-lg font-bold text-gray-100">Activity History</h3>
        </div>
            
        <!-- Card Content -->
        <div class="pr-6">
            <!-- Timeline Container with Scroll -->
            <div class="pl-6 pb-4 max-h-[500px] overflow-y-auto scrollbar-hide">
                <!-- Scrollable History Items -->
                <div id="historyTableBody" class="space-y-6">
                    <!-- Activity items will be inserted here -->
                </div>
            </div>
        </div>
            
        <!-- Card Footer -->
        <div class="px-6 py-4 flex justify-end">
            <button onclick="closeHistoryModal()" class="px-4 py-2 bg-gray-600 text-gray-100 rounded-md hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors">
                Close
            </button>
        </div>
    </div>
</div>

<style>
    .scrollbar-hide::-webkit-scrollbar {
        display: none;
    }
    .scrollbar-hide {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
</style>

<script>
// Fire button functionality - simple confirmation
function confirmFire(recordId, recordType) {
    return confirm('Are you sure you want to fire this record?');
}

// History modal functions
function showHistoryModal(recordId, recordType) {
    const modal = document.getElementById('historyModal');
    const loader = `
        <div class="relative">
            <div class="absolute -left-2.5 top-0 h-5 w-5 rounded-full bg-gray-500 border-4 border-gray-800"></div>
            <div class="ml-4">
                <p class="text-sm text-gray-400">Loading history...</p>
            </div>
        </div>
    `;
    document.getElementById('historyTableBody').innerHTML = loader;
    modal.classList.remove('hidden');
    
    fetch(`get_history.php?record_id=${recordId}&type=${recordType}`)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            const tbody = document.getElementById('historyTableBody');
            tbody.innerHTML = '';
            
            if (data.length > 0) {
                data.forEach(activity => {
                    const initials = activity.sub_name.split(' ').map(n => n[0]).join('').toUpperCase();
                    const item = `
                        <div class="bg-gray-800 rounded-lg p-4 shadow-md border border-gray-700 mb-4 ">
                            <!-- User & Activity Info -->
                            <div class="flex items-start">
                                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold">
                                    ${initials.substring(0, 2)}
                                </div>
                                <div class="ml-4">
                                    <h4 class="text-base font-semibold text-gray-100">${activity.sub_name}</h4>
                                    <p class="text-sm text-gray-300 mt-1">${activity.activity_description}</p>
                                </div>
                            </div>
                            
                            <!-- Timeline Indicator & Timestamp -->
                            <div class="relative ml-10 mt-3">
                                <div class="absolute -left-2.5 top-0 h-5 w-5 rounded-full bg-blue-600 border-4 border-gray-800"></div>
                                <div class="ml-4">
                                    <p class="text-xs text-gray-400">
                                        ${new Date(activity.activity_time).toLocaleString('en-US', { 
                                            month: 'short', 
                                            day: 'numeric', 
                                            year: 'numeric',
                                            hour: '2-digit',
                                            minute: '2-digit'
                                        })}
                                    </p>
                                </div>
                            </div>
                        </div>
                    `;
                    tbody.insertAdjacentHTML('beforeend', item);
                });
            } else {
                tbody.innerHTML = `
                    <div class="relative">
                        <div class="absolute -left-2.5 top-0 h-5 w-5 rounded-full bg-gray-500 border-4 border-gray-800"></div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-400">No history found for this record</p>
                        </div>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            const tbody = document.getElementById('historyTableBody');
            tbody.innerHTML = `
                <div class="relative">
                    <div class="absolute -left-2.5 top-0 h-5 w-5 rounded-full bg-red-600 border-4 border-gray-800"></div>
                    <div class="ml-4">
                        <p class="text-sm text-red-400">Error loading history: ${error.message}</p>
                    </div>
                </div>
            `;
        });
}

function closeHistoryModal() {
    document.getElementById('historyModal').classList.add('hidden');
}

// Delete modal functions
function showDeleteModal(recordId, recordType = '') {
    const modal = `
        <div id="deleteModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md">
                <div class="px-6 py-6">
                    <h3 class="text-lg font-bold text-gray-100 mb-4">Confirm Deletion</h3>
                    <p class="text-gray-300 mb-4">Are you sure you want to delete this record?</p>
                    <form method="post" action="attendance.php?delete=${recordId}${recordType ? '&type=' + recordType : ''}" class="space-y-4">
                        <div>
                            <label for="delete_password" class="block text-sm font-medium text-gray-300 mb-1">To confirm please enter the KEY:</label>
                            <input type="password" name="delete_password" id="delete_password" 
                                   class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-gray-200" required>
                        </div>
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeDeleteModal()" 
                                    class="px-4 py-2 bg-gray-600 text-gray-100 rounded-md hover:bg-gray-500">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-500">
                                Delete
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modal);
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    if (modal) {
        modal.remove();
    }
}

// Close modal when clicking outside or pressing Escape
document.addEventListener('click', function(e) {
    if (e.target === document.getElementById('historyModal')) {
        closeHistoryModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('historyModal').classList.contains('hidden')) {
        closeHistoryModal();
    }
});


function copyAbsentReport(recordId) {
    // Get the current script's path
    const currentPath = window.location.pathname;
    // Get the directory of the current script
    const basePath = currentPath.substring(0, currentPath.lastIndexOf('/') + 1);
    
    // Construct the URL to the PHP file in the same directory
    fetch(basePath + '../admin/partials/get_absent_record.php?id=' + recordId)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const report = formatAbsentReport(data.record);
                navigator.clipboard.writeText(report)
                    .then(() => {
                        alert('Report copied to clipboard!');
                    })
                    .catch(err => {
                        console.error('Clipboard error:', err);
                        alert('Failed to copy to clipboard. Please try again.');
                    });
            } else {
                console.error('Failed to fetch record:', data.message);
                alert('Failed to fetch record: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while fetching the record. Check console for details.');
        });
}

function formatAbsentReport(record) {
    const absentDate = new Date(record.date_of_absent);
    const formattedDate = absentDate.toLocaleDateString('en-US', { 
        month: 'short', 
        day: 'numeric', 
        year: 'numeric' 
    }).toUpperCase().replace(',', '');
    
    const sanction = record.sanction || 'ABSENCE';
    const shiftTime = record.shift || '6:00 AM - 3:00 PM';
    
    // Get all coverage information with types
    let coverageEntries = [];
    
    // Check all 4 coverage fields
    for (let i = 1; i <= 4; i++) {
        const coverageField = `coverage_${i}`;
        const coverageTypeField = `coverage_type_${i}`;
        const coverageDetailsField = `coverage_details_${i}`;
        
        if (record[coverageField] && record[coverageField].trim() !== '') {
            let coverage = record[coverageField];
            const coverageType = record[coverageTypeField] || '';
            const coverageDetail = record[coverageDetailsField] || '';
            
            // Format based on coverage type
            if (coverageType === 'PENDING') {
                // For PENDING, just show the name
                coverageEntries.push(coverage);
            } else if (coverageType && coverageType !== '-' && coverageType !== '') {
                // For other types, include the type in parentheses
                if (coverageDetail && coverageDetail !== '-' && coverageDetail !== '') {
                    coverageEntries.push(`${coverage} (${coverageType} - ${coverageDetail})`);
                } else {
                    coverageEntries.push(`${coverage} (${coverageType})`);
                }
            } else {
                // No type specified
                coverageEntries.push(coverage);
            }
        }
    }
    
    // Join multiple coverage entries if they exist
    let coverageText = coverageEntries.length > 0 ? coverageEntries.join(' | ') : 'PENDING';
    
    // Format the report with proper line breaks
    return `${sanction}\n\n` +
           `Name of Employee: ${record.full_name || ''}\n` +
           `DEPARTMENT: ${record.department || ''}\n` +
           `SUPERVISOR: ${record.supervisor || ''}\n` +
           `OM: ${record.operation_manager || ''}\n` +
           `Date of Absenteeism: ${formattedDate}\n` +
           `Sanction: ${sanction}\n` +
           `Reason for Absence: ${record.reason || ''}\n` +
           `Scheduled Shift: ${shiftTime}\n` +
           `Covered/Uncovered?: ${coverageText}`;
}
</script>