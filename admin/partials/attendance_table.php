<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

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
                $whereClauses[] = "$dateField = CURDATE()";
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
                $whereClauses[] = "date_of_absent = CURDATE()";
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
    if ($type === 'tardiness') {
        if ($irFilter === 'FOR IR') {
            $whereClauses[] = "ir_form = 'FOR IR'";
        } 
        elseif ($irFilter === 'FOR ACCUMULATION') {
            $whereClauses[] = "ir_form = 'FOR ACCUMULATION'";
        }
        elseif ($irFilter === 'PENDING') {
            $whereClauses[] = "ir_form LIKE 'PENDING%'";
        }
        // Handle specific pending dates
        elseif (preg_match('/PENDING \/ ([A-Z]{3,4} [0-9]{1,2})/', $irFilter, $matches)) {
            $datePart = $matches[1];
            $whereClauses[] = "ir_form LIKE :ir_filter";
            $params[':ir_filter'] = "PENDING / $datePart%";
        }
    } else {
        // Absenteeism filtering
        if ($irFilter === 'FOR IR') {
            $whereClauses[] = "ir_form = 'FOR IR'";
        }
        elseif ($irFilter === 'NO NEED') {
            $whereClauses[] = "ir_form = 'NO NEED'";
        }
        // Handle specific pending dates for absenteeism
        elseif (preg_match('/PENDING \/ ([A-Z]{3,4} [0-9]{1,2})/', $irFilter, $matches)) {
            $datePart = $matches[1];
            $whereClauses[] = "ir_form LIKE :ir_filter";
            $params[':ir_filter'] = "PENDING / $datePart%";
        }
    }
    
    // Add this condition to exclude "YES" records when filtering for specific IR statuses in tardiness
    if ($type === 'tardiness' && in_array($irFilter, ['FOR IR', 'FOR ACCUMULATION', 'PENDING'])) {
        $whereClauses[] = "ir_form != 'YES'";
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
$page = min($page, $totalPages);
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
        // The actual IR status updates now happen immediately when records are created/updated
        // This section is kept minimal to ensure display consistency
        
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
        // No complex processing needed here anymore
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

<div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden shadow">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 w-full" style="zoom:85%">
            <thead class="bg-gray-700">
                <tr>
                    <?php if ($type !== 'vto'): ?>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-8">
                            <input type="checkbox" id="selectAllCheckbox">
                        </th>
                    <?php endif; ?>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">CXI Number</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-48">Full Name</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Department</th>
                    <?php if ($type === 'absenteeism'): ?>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Supervisor</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Operations Manager</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Date of Absence</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Sanction</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Reason</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24">Shift</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Followed Procedure</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-64">Coverage Details</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Incident Report</th>
                    <?php elseif ($type === 'tardiness'): ?>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Operations Manager</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Date of Tardiness</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24">Type</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-20">Minutes</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24">Shift</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Incident Report</th>
                    <?php else: ?>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Shift Date</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24">Shift</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-20">Time In</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-20">Time Out</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24">Worked (mins)</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24">VTO (mins)</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">VTO Type</th>
                    <?php endif; ?>
                    
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Reported By</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Time Reported</th>
                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="<?= $type === 'absenteeism' ? 16 : ($type === 'tardiness' ? 13 : 14) ?>" class="px-6 py-8 text-center text-gray-400">
                            <i class="fas fa-users-slash text-3xl mb-3 opacity-50"></i>
                            <p class="text-lg">No records found</p>
                            <p class="text-sm mt-1" style="text-transform: uppercase;"><?= $type ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                    <?php if ($type === 'vto'): ?>
                        <tr class="hover:bg-gray-700/50">
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                <div class="text-sm font-medium text-gray-100" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['employee_id'] ?? '') ?>"><?= htmlspecialchars($record['employee_id'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['full_name'] ?? '') ?>"><?= htmlspecialchars($record['full_name'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['department'] ?? '') ?>"><?= htmlspecialchars($record['department'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300"><?= isset($record['shift_date']) ? date('M d, Y', strtotime($record['shift_date'])) : '' ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['shift'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['time_in'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['time_out'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['mins_of_work'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['vto_mins'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $record['vto_type'] === 'REALTIME' ? 'bg-green-500/20 text-green-300 border border-green-500/30' : 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30' ?>" title="<?= htmlspecialchars($record['vto_type'] ?? '') ?>">
                                    <?= htmlspecialchars($record['vto_type'] ?? '') ?> 
                                </span>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                <div class="text-sm text-gray-300" title="<?= htmlspecialchars($record['sub_name'] ?? '') ?>"><?= htmlspecialchars($record['sub_name'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300"><?= isset($record['timestamp']) ? date('g:i A', strtotime($record['timestamp'])) : '' ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="vto_form.php?id=<?= $record['id'] ?? '' ?>" title="Edit record" class="text-primary-500 hover:text-primary-400 mr-3">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="#" onclick="event.preventDefault(); showHistoryModal(<?= $record['id'] ?? '' ?>, 'vto')" title="View History" class="text-purple-500 hover:text-purple-400 mr-3">
                                    <i class="fas fa-history"></i>
                                </a>
                                <a href="#" onclick="event.preventDefault(); showDeleteModal(<?= $record['id'] ?? '' ?>, 'vto')" class="text-red-500 hover:text-red-400" title="Delete record">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr class="hover:bg-gray-700/50">
                            <td class="px-4 py-4 whitespace-nowrap text-center">
                                <input type="checkbox" class="record-checkbox" data-id="<?= $record['id'] ?>">
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                <div class="text-sm font-medium text-gray-100" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['employee_id']) ?>"><?= htmlspecialchars($record['employee_id']) ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['full_name']) ?>"><?= htmlspecialchars($record['full_name']) ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['department']) ?>"><?= htmlspecialchars($record['department']) ?></div>
                            </td>

                            <?php if ($type === 'absenteeism'): ?>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['supervisor']) ?>"><?= htmlspecialchars($record['supervisor']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['operation_manager']) ?>"><?= htmlspecialchars($record['operation_manager']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-300"><?= date('M d, Y', strtotime($record['date_of_absent'])) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['sanction']) ?>"><?= htmlspecialchars($record['sanction']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['reason']) ?>"><?= htmlspecialchars($record['reason']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-300"><?= htmlspecialchars($record['shift']) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full  <?= $record['follow_call_in_procedure'] === 'NO' ? 'bg-red-500/20 text-red-300 border border-red-500/30' : 'bg-green-500/20 text-green-300 border border-green-500/30' ?>">
                                        <?= $record['follow_call_in_procedure'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
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
                                                
                                                $detail = $coverageText;
                                                if (!empty($coverageType) && $coverageType !== '-') {
                                                    $detail .= " ($coverageType";
                                                    if (!empty($coverageDetail)) {
                                                        $detail .= " - $coverageDetail";
                                                    }
                                                    $detail .= ")";
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
                                                
                                                $detail = $coverageText;
                                                if (!empty($coverageType) && $coverageType !== '-') {
                                                    $detail .= " ($coverageType";
                                                    if (!empty($coverageDetail)) {
                                                        $detail .= " - $coverageDetail";
                                                    }
                                                    $detail .= ")";
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
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= $record['ir_form'] ?>"><?= $record['ir_form'] ?></div>
                                </td>
                            <?php elseif ($type === 'tardiness'): ?>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-300"><?= $record['operation_manager'] ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-300"><?= date('M d, Y', strtotime($record['date_of_incident'])) ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full  <?= $record['types'] === 'Late' ? 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30' : 'bg-red-500/20 text-red-300 border border-red-500/30' ?>">
                                        <?= $record['types'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-300"><?= $record['minutes_late'] ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-300"><?= $record['shift'] ?></div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                    <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= $record['ir_form'] ?>"><?= $record['ir_form'] ?></div>
                                </td>
                            <?php endif; ?>
                            
                            <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                                <div class="text-sm text-gray-300" title="<?= htmlspecialchars($record['sub_name']) ?>"><?= htmlspecialchars($record['sub_name']) ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300"><?= date('g:i A', strtotime($record['timestamp'])) ?></div>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <?php if (!$record['email_sent']): ?>
                                    <a href="send_email.php?send_email=<?= $record['id'] ?>&type=<?= $type ?>" title="Send Email" class="text-blue-500 hover:text-blue-400 mr-3">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                <?php else: ?>
                                    <span title="Email sent on <?= date('M d, Y g:i A', strtotime($record['email_sent_at'])) ?>" class="text-green-500 mr-3">
                                        <i class="fas fa-check-circle"></i>
                                    </span>
                                <?php endif; ?>
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
<div class="mt-6 flex items-center justify-between">
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