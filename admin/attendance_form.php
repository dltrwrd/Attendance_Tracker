<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : (isset($_POST['type']) ? $_POST['type'] : 'absenteeism');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
    $type = $_POST['type'];
    
    try {
        $pdo->beginTransaction();
        
        // Get current user's sub_name
        $userStmt = $pdo->prepare("SELECT sub_name FROM users WHERE id = ?");
        $userStmt->execute([$_SESSION['user_id']]);
        $user = $userStmt->fetch();
        $sub_name = $user['sub_name'];
        
        // Get employee details
        $employeeId = sanitizeInput($_POST['employee_id']);
        $employeeStmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
        $employeeStmt->execute([$employeeId]);
        $employee = $employeeStmt->fetch();
        
        if (!$employee) {
            throw new Exception("Employee not found");
        }
        
        $currentMonth = date('M Y');
        $currentDate = date('Y-m-d');
        $currentTime = date('g:i A');
        
        // Check for duplicate entry
        if ($type === 'absenteeism') {
            $dateField = strtoupper(sanitizeInput($_POST['date_of_absent']));
            
            // Check if a record already exists for this employee on this date (excluding current record if editing)
            $duplicateCheck = $pdo->prepare("SELECT id FROM absenteeism WHERE employee_id = ? AND date_of_absent = ? AND id != ?");
            $duplicateCheck->execute([$employeeId, $dateField, $id]);
            $existingRecord = $duplicateCheck->fetch();
            
            if ($existingRecord) {
                throw new Exception("An absenteeism record already exists for this ". $employee['full_name'] ." on the selected date " . date('F j, Y', strtotime($dateField)) .".");
            }
            
            // Process coverage entries for individual columns
            $coverageData = [];

            if (isset($_POST['coverage']) && is_array($_POST['coverage'])) {
                foreach ($_POST['coverage'] as $index => $coverage) {
                    $coverageText = strtoupper(trim(sanitizeInput($coverage)));
                    $coverageType = isset($_POST['coverage_type'][$index]) ? strtoupper(trim(sanitizeInput($_POST['coverage_type'][$index]))) : '-';
                    
                    // Fix: Properly handle detailed_coverage_type
                    $coverageDetails = '';
                    if (isset($_POST['detailed_coverage_type'][$index])) {
                        $coverageDetails = strtoupper(trim(sanitizeInput($_POST['detailed_coverage_type'][$index])));
                    }
                    
                    if (!empty($coverageText)) {
                        $coverageNumber = $index + 1;
                        $coverageData["coverage_{$coverageNumber}"] = $coverageText;
                        $coverageData["coverage_type_{$coverageNumber}"] = $coverageType;
                        $coverageData["coverage_details_{$coverageNumber}"] = $coverageDetails;
                    }
                }
                
                // Fill remaining coverage fields with empty values
                for ($i = count($_POST['coverage']) + 1; $i <= 4; $i++) {
                    $coverageData["coverage_{$i}"] = '';
                    $coverageData["coverage_type_{$i}"] = '-';
                    $coverageData["coverage_details_{$i}"] = '';
                }
            }
            
            // Prepare base data
            $data = [
                'month' => $currentMonth,
                'employee_id' => $employeeId,
                'full_name' => strtoupper($employee['full_name']),
                'department' => strtoupper($employee['department']),
                'supervisor' => strtoupper($employee['supervisor']),
                'operation_manager' => strtoupper($employee['operation_manager']),
                'email' => $employee['email'],
                'date_of_absent' => $dateField,
                'follow_call_in_procedure' => strtoupper(sanitizeInput($_POST['follow_call_in_procedure'])),
                'sanction' => strtoupper(sanitizeInput($_POST['sanction'])),
                'reason' => strtoupper($_POST['reason']),
                'shift' => strtoupper(sanitizeInput($_POST['shift'])),
                'ir_form' => strtoupper(sanitizeInput($_POST['ir_form'])),
                'timestamp' => $currentTime,
                'sub_name' => $sub_name
            ];
            
            // Merge coverage data with base data
            $data = array_merge($data, $coverageData);
            
            if ($id > 0) {
                // Update existing record
                $updateFields = [];
                $updateParams = [];
                
                foreach ($data as $key => $value) {
                    $updateFields[] = "$key = :$key";
                }
                $updateFields[] = "id = :id";
                
                $stmt = $pdo->prepare("UPDATE absenteeism SET " . implode(', ', $updateFields) . " WHERE id = :id");
                
                $data['id'] = $id;
                $stmt->execute($data);
                
                $_SESSION['success'] = "Absenteeism record updated successfully!";
            } else {
                // Insert new record
                $columns = implode(', ', array_keys($data));
                $placeholders = ':' . implode(', :', array_keys($data));
                
                $stmt = $pdo->prepare("INSERT INTO absenteeism ($columns) VALUES ($placeholders)");
                $stmt->execute($data);
                $recordId = $pdo->lastInsertId();
                
                $_SESSION['success'] = "Absenteeism record added successfully!";
            }
            
            // Log activity
            $action = $id > 0 ? 'updated' : 'created';
            $recordId = $id > 0 ? $id : $pdo->lastInsertId();
            logActivity("$action absenteeism record for {$data['full_name']}", $recordId, 'absenteeism');
            
            // Check if this employee has any pending IR forms
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM absenteeism 
                                WHERE employee_id = ? AND ir_form LIKE 'PENDING%'");
            $stmt->execute([$employeeId]);
            $pendingCount = $stmt->fetchColumn();
            
            if ($pendingCount > 0) {
                $_SESSION['check_pending_ir'] = $employeeId;
            }
            
        } else {
            // Tardiness form (unchanged)
            $dateField = strtoupper(sanitizeInput($_POST['date_of_incident']));
            $types = isset($_POST['types']) ? strtoupper(sanitizeInput($_POST['types'])) : 'LATE';
            
            // Check if a record already exists for this employee on this date (excluding current record if editing)
            $duplicateCheck = $pdo->prepare("SELECT id FROM tardiness WHERE employee_id = ? AND date_of_incident = ? AND types = ? AND id != ?");
            $duplicateCheck->execute([$employeeId, $dateField, $types, $id]);
            $existingRecord = $duplicateCheck->fetch();
            
            if ($existingRecord) {
                throw new Exception("A tardiness record already exists for this ". $employee['full_name'] ." on the selected date " . date('F j, Y', strtotime($dateField)) .".");
            }
            
            $data = [
                'month' => $currentMonth,
                'employee_id' => $employeeId,
                'full_name' => strtoupper($employee['full_name']),
                'department' => strtoupper($employee['department']),
                'supervisor' => strtoupper($employee['supervisor']),
                'operation_manager' => strtoupper($employee['operation_manager']),
                'email' => $employee['email'],
                'date_of_incident' => $dateField,
                'types' => $types,
                'minutes_late' => (int)$_POST['minutes_late'],
                'shift' => strtoupper(sanitizeInput($_POST['shift'])),
                'time_in' => strtoupper(sanitizeInput($_POST['time_in'])),
                'ir_form' => strtoupper(sanitizeInput($_POST['ir_form'])),
                'timestamp' => $currentTime,
                'sub_name' => $sub_name
            ];
            
            if ($id > 0) {
                // Update existing record
                $stmt = $pdo->prepare("UPDATE tardiness SET 
                    month = :month,
                    employee_id = :employee_id,
                    full_name = :full_name,
                    department = :department,
                    supervisor = :supervisor,
                    operation_manager = :operation_manager,
                    email = :email,
                    date_of_incident = :date_of_incident,
                    types = :types,
                    minutes_late = :minutes_late,
                    shift = :shift,
                    time_in = :time_in,
                    ir_form = :ir_form,
                    timestamp = :timestamp,
                    sub_name = :sub_name
                    WHERE id = :id");
                
                $data['id'] = $id;
                $stmt->execute($data);
                
                $_SESSION['success'] = "Tardiness record updated successfully!";
                logActivity("Updated tardiness of {$employee['full_name']}", $id, 'tardiness');
            } else {
                // Insert new record
                $stmt = $pdo->prepare("INSERT INTO tardiness 
                    (month, employee_id, full_name, department, supervisor, operation_manager, email, 
                    date_of_incident, types, minutes_late, shift, time_in, ir_form, timestamp, sub_name)
                    VALUES 
                    (:month, :employee_id, :full_name, :department, :supervisor, :operation_manager, :email, 
                    :date_of_incident, :types, :minutes_late, :shift, :time_in, :ir_form, :timestamp, :sub_name)");
                
                $stmt->execute($data);
                $recordId = $pdo->lastInsertId();
                
                $_SESSION['success'] = "Tardiness record added successfully!";
                logActivity("Created tardiness record for {$data['full_name']}", $recordId, 'tardiness');
            }
        }
        
        $pdo->commit();
        redirect('attendance.php?tab=' . $type);
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
        redirect($id ? 'attendance_form.php?id=' . $id . '&type=' . $type : 'attendance_form.php?action=create&type=' . $type);
    }
}

// Get record data
$record = null;
if ($id > 0) {
    try {
        $table = ($type === 'tardiness') ? 'tardiness' : 'absenteeism';
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        
        if (!$record) {
            $_SESSION['error'] = "Record not found";
            redirect('attendance.php?tab=' . $type);
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching record: " . $e->getMessage();
        redirect('attendance.php?tab=' . $type);
    }
} elseif ($action !== 'create') {
    redirect('attendance.php?tab=' . $type);
}

require_once '../components/layout.php';
renderHead($id ? 'Edit Record' : 'Add Record');
renderNavbar();
renderSidebar('attendance');
?>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold"><?= $id ? 'Edit Record' : 'Add New Record' ?></h1>
            <a href="attendance.php?tab=<?= $type ?>" class="text-gray-400 hover:text-white">
                <i class="fas fa-times fa-lg"></i>
            </a>
        </div>

        <?php renderAlert(); ?>

        <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 shadow">
            <form method="POST" id="attendanceForm">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="type" value="<?= $type ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="employee_id" class="block text-sm font-medium text-gray-300 mb-2">Employee ID</label>
                        <input type="text" id="employee_id" name="employee_id" style="text-transform: uppercase;"
                            class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                            value="<?= $record ? htmlspecialchars($record['employee_id']) : '' ?>" required
                            onchange="fetchEmployeeDetails(this.value)"
                            autocomplete="off">
                        <div id="employeeSearchResults" class="hidden absolute z-10 mt-1 w-full max-w-md bg-gray-800 border border-gray-700 rounded-lg shadow-lg"></div>
                    </div>
                    
                    <div>
                        <label for="full_name" class="block text-sm font-medium text-gray-300 mb-2">Full Name</label>
                        <input type="text" id="full_name" name="full_name" style="text-transform: uppercase;" 
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-400" 
                               value="<?= $record ? htmlspecialchars($record['full_name']) : '' ?>">
                    </div>
                    
                    <div>
                        <label for="department" class="block text-sm font-medium text-gray-300 mb-2">Department</label>
                        <input type="text" id="department" name="department" style="text-transform: uppercase;" 
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-400" 
                               value="<?= $record ? htmlspecialchars($record['department']) : '' ?>">
                    </div>
                    
                    <div>
                        <label for="supervisor" class="block text-sm font-medium text-gray-300 mb-2">Supervisor</label>
                        <input type="text" id="supervisor" name="supervisor" style="text-transform: uppercase;" 
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-400" 
                               value="<?= $record ? htmlspecialchars($record['supervisor']) : '' ?>">
                    </div>
                    
                    <div>
                        <label for="operation_manager" class="block text-sm font-medium text-gray-300 mb-2">Operations Manager</label>
                        <input type="text" id="operation_manager" name="operation_manager" style="text-transform: uppercase;" 
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-400" 
                               value="<?= $record ? htmlspecialchars($record['operation_manager']) : '' ?>">
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-300 mb-2">Email</label>
                        <input type="text" id="email" name="email"
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-400" 
                               value="<?= $record ? htmlspecialchars($record['email']) : '' ?>">
                    </div>
                    
                    <?php if ($type === 'absenteeism'): ?>
                        <div>
                            <label for="date_of_absent" class="block text-sm font-medium text-gray-300 mb-2">Date of Absence</label>
                            <input type="date" id="date_of_absent" name="date_of_absent" style="text-transform: uppercase;"
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   value="<?= $record ? htmlspecialchars($record['date_of_absent']) : '' ?>" required>
                        </div>
                        
                        <div>
                            <label for="follow_call_in_procedure" class="block text-sm font-medium text-gray-300 mb-2">Received advise in SLT number:</label>
                            <input type="text" id="follow_call_in_procedure" name="follow_call_in_procedure" style="text-transform: uppercase;"
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   value="<?= $record ? htmlspecialchars($record['follow_call_in_procedure']) : '' ?>">
                        </div>
                        
                        <div>
                            <label for="sanction" class="block text-sm font-medium text-gray-300 mb-2">Sanction</label>
                            <select id="sanction" name="sanction" style="text-transform: uppercase;"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" required>
                                <option value="ABSENCE" <?= $record && $record['sanction'] === 'ABSENCE' ? 'selected' : '' ?>>ABSENCE</option>
                                <option value="ABSENCE / CWD" <?= $record && $record['sanction'] === 'ABSENCE / CWD' ? 'selected' : '' ?>>ABSENCE / CWD</option>
                                <option value="ABSENCE / NCNS" <?= $record && $record['sanction'] === 'ABSENCE / NCNS' ? 'selected' : '' ?>>ABSENCE / NCNS</option>
                                <option value="ABSENCE / NCNS (LATE ADVISE)" <?= $record && $record['sanction'] === 'ABSENCE / NCNS (LATE ADVISE)' ? 'selected' : '' ?>>ABSENCE / NCNS (LATE ADVISE)</option>
                                <option value="ABSENCE / NCNS / CWD" <?= $record && $record['sanction'] === 'ABSENCE / NCNS / CWD' ? 'selected' : '' ?>>ABSENCE / NCNS / CWD</option>
                                <option value="ABSENCE / NCNS / CWD (LATE ADVISE)" <?= $record && $record['sanction'] === 'ABSENCE / NCNS / CWD (LATE ADVISE)' ? 'selected' : '' ?>>ABSENCE / NCNS / CWD (LATE ADVISE)</option>
                                <option value="PVL" <?= $record && $record['sanction'] === 'PVL' ? 'selected' : '' ?>>PVL</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="reason" class="block text-sm font-medium text-gray-300 mb-2">Reason</label>
                            <textarea id="reason" name="reason" style="text-transform: uppercase;"
                                      class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                      required><?= $record ? $record['reason'] : '' ?></textarea>
                        </div>

                        <!-- Coverage Section -->
                        <div class="md:col-span-2">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-lg font-medium text-gray-300">Coverage</h3>
                                <button type="button" onclick="addCoverageField()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center text-sm">
                                    <i class="fas fa-plus mr-2"></i> Add Coverage
                                </button>
                            </div>
                            
                            <div id="coverageContainer">
                                <?php
                                // Parse existing coverage data for editing
                                $coverages = [];
                                
                                if ($record) {
                                    for ($i = 1; $i <= 4; $i++) {
                                        $coverageField = "coverage_{$i}";
                                        $coverageTypeField = "coverage_type_{$i}";
                                        $coverageDetailsField = "coverage_details_{$i}";
                                        
                                        if (!empty($record[$coverageField]) || $i === 1) {
                                            $coverages[] = [
                                                'coverage' => $record[$coverageField] ?? '',
                                                'coverage_type' => $record[$coverageTypeField] ?? '-',
                                                'coverage_details' => $record[$coverageDetailsField] ?? '',
                                                'coverage_number' => $i
                                            ];
                                        }
                                    }
                                }
                                
                                // If no existing data, show one empty field
                                if (empty($coverages)) {
                                    $coverages = [['coverage' => '', 'coverage_type' => '-', 'coverage_details' => '', 'coverage_number' => 1]];
                                }
                                
                                foreach ($coverages as $index => $coverageData):
                                    $coverageNumber = $coverageData['coverage_number'];
                                    $isRDOT = ($coverageData['coverage_type'] === 'RDOT');
                                    $isDSOT = ($coverageData['coverage_type'] === 'DSOT');
                                    $currentDetails = $coverageData['coverage_details'];
                                ?>
                                <div class="coverage-field mb-4 p-4 border border-gray-700 rounded-lg bg-gray-750">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-300 mb-2">Coverage <?= $coverageNumber ?></label>
                                            <textarea name="coverage[]" style="text-transform: uppercase;"
                                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                                    required><?= htmlspecialchars($coverageData['coverage']) ?></textarea>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-300 mb-2">Coverage Type <?= $coverageNumber ?></label>
                                            <select name="coverage_type[]" class="coverage-type-select w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" required onchange="handleCoverageTypeChange(this)">
                                                <option value="-" <?= $coverageData['coverage_type'] === '-' ? 'selected' : '' ?>>-</option>
                                                <option value="NO NEED" <?= $coverageData['coverage_type'] === 'NO NEED' ? 'selected' : '' ?>>NO NEED</option>
                                                <option value="TRAINEE" <?= $coverageData['coverage_type'] === 'TRAINEE' ? 'selected' : '' ?>>TRAINEE</option>
                                                <option value="BACK UP" <?= $coverageData['coverage_type'] === 'BACK UP' ? 'selected' : '' ?>>BACK UP</option>
                                                <option value="PENDING" <?= $coverageData['coverage_type'] === 'PENDING' ? 'selected' : '' ?>>PENDING</option>
                                                <option value="DSOT" <?= $coverageData['coverage_type'] === 'DSOT' ? 'selected' : '' ?>>DSOT</option>
                                                <option value="RDOT" <?= $coverageData['coverage_type'] === 'RDOT' ? 'selected' : '' ?>>RDOT</option>
                                                <option value="AGENT MODE" <?= $coverageData['coverage_type'] === 'AGENT MODE' ? 'selected' : '' ?>>AGENT MODE</option>
                                                <option value="PVL" <?= $coverageData['coverage_type'] === 'PVL' ? 'selected' : '' ?>>PVL</option>
                                            </select>
                                            
                                            <!-- Single detailed coverage type selection that changes based on coverage type -->
                                            <div class="detailed-coverage mt-2 <?= ($isRDOT || $isDSOT) ? '' : 'hidden' ?>">
                                                <select name="detailed_coverage_type[]" 
                                                        class="detailed-select w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                                        <?= ($isRDOT || $isDSOT) ? 'required' : '' ?>
                                                        data-current-value="<?= htmlspecialchars($currentDetails) ?>">
                                                    <option value="">Select detailed type</option>
                                                    <?php if ($isRDOT): ?>
                                                        <option value="12HRS RDOT" <?= $currentDetails === '12HRS RDOT' ? 'selected' : '' ?>>12hrs RDOT</option>
                                                        <option value="8HRS RDOT" <?= $currentDetails === '8HRS RDOT' ? 'selected' : '' ?>>8hrs RDOT</option>
                                                        <option value="6HRS RDOT" <?= $currentDetails === '6HRS RDOT' ? 'selected' : '' ?>>6hrs RDOT</option>
                                                        <option value="4HRS RDOT" <?= $currentDetails === '4HRS RDOT' ? 'selected' : '' ?>>4hrs RDOT</option>
                                                        <option value="ADJUSTMENT" <?= $currentDetails === 'ADJUSTMENT' ? 'selected' : '' ?>>Adjustment</option>
                                                        <option value="COMMENDATION" <?= $currentDetails === 'COMMENDATION' ? 'selected' : '' ?>>Commendation</option>
                                                    <?php elseif ($isDSOT): ?>
                                                        <option value="12HRS OT DS" <?= $currentDetails === '12HRS OT DS' ? 'selected' : '' ?>>12hrs OT DS</option>
                                                        <option value="8HRS OT DS" <?= $currentDetails === '8HRS OT DS' ? 'selected' : '' ?>>8hrs OT DS</option>
                                                        <option value="6HRS OT DS" <?= $currentDetails === '6HRS OT DS' ? 'selected' : '' ?>>6hrs OT DS</option>
                                                        <option value="4HRS OT DS" <?= $currentDetails === '4HRS OT DS' ? 'selected' : '' ?>>4hrs OT DS</option>
                                                        <option value="ADJUSTMENT" <?= $currentDetails === 'ADJUSTMENT' ? 'selected' : '' ?>>Adjustment</option>
                                                        <option value="COMMENDATION" <?= $currentDetails === 'COMMENDATION' ? 'selected' : '' ?>>Commendation</option>
                                                    <?php else: ?>
                                                        <!-- Default options -->
                                                        <option value="12HRS RDOT">12hrs RDOT</option>
                                                        <option value="8HRS RDOT">8hrs RDOT</option>
                                                        <option value="6HRS RDOT">6hrs RDOT</option>
                                                        <option value="4HRS RDOT">4hrs RDOT</option>
                                                        <option value="12HRS OT DS">12hrs OT DS</option>
                                                        <option value="8HRS OT DS">8hrs OT DS</option>
                                                        <option value="6HRS OT DS">6hrs OT DS</option>
                                                        <option value="4HRS OT DS">4hrs OT DS</option>
                                                        <option value="ADJUSTMENT">Adjustment</option>
                                                        <option value="COMMENDATION">Commendation</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($index > 0): ?>
                                    <div class="mt-2 flex justify-end">
                                        <button type="button" onclick="removeCoverageField(this)" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm">
                                            <i class="fas fa-trash mr-1"></i> Remove
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div>
                            <label for="shift" class="block text-sm font-medium text-gray-300 mb-2">Shift</label>
                            <input type="text" id="shift" name="shift" style="text-transform: uppercase;"
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   value="<?= $record ? htmlspecialchars($record['shift']) : '' ?>" required>
                        </div>
                        
                        <div>
                            <label for="ir_form" class="block text-sm font-medium text-gray-300 mb-2">IR Form</label>
                            <input type="text" id="ir_form" name="ir_form" style="text-transform: uppercase;"
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   value="<?= $record ? htmlspecialchars($record['ir_form']) : '' ?>">
                        </div>
                    <?php else: ?>
                        <div>
                            <label for="date_of_incident" class="block text-sm font-medium text-gray-300 mb-2">Date of Tardiness</label>
                            <input type="date" id="date_of_incident" name="date_of_incident" style="text-transform: uppercase;"
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   value="<?= $record ? htmlspecialchars($record['date_of_incident']) : '' ?>" required>
                        </div>
                        
                        <div>
                            <label for="types" class="block text-sm font-medium text-gray-300 mb-2">Type</label>
                            <select id="types" name="types"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" required>
                                <option value="LATE" <?= $record && $record['types'] === 'LATE' ? 'selected' : '' ?>>LATE</option>
                                <option value="UNDERTIME" <?= $record && $record['types'] === 'UNDERTIME' ? 'selected' : '' ?>>UNDERTIME</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="minutes_late" class="block text-sm font-medium text-gray-300 mb-2">Minutes Late/Undertime</label>
                            <input type="number" id="minutes_late" name="minutes_late" style="text-transform: uppercase;"
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   value="<?= $record ? htmlspecialchars($record['minutes_late']) : '' ?>" required>
                        </div>
                        
                        <div>
                            <label for="shift" class="block text-sm font-medium text-gray-300 mb-2">Shift</label>
                            <input type="text" id="shift" name="shift" style="text-transform: uppercase;"
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   value="<?= $record ? htmlspecialchars($record['shift']) : '' ?>" required>
                        </div>
                        <div>
                            <label for="time_in" class="block text-sm font-medium text-gray-300 mb-2">Time In</label>
                            <input type="text" id="time_in" name="time_in" style="text-transform: uppercase;"
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   value="<?= $record ? htmlspecialchars($record['time_in']) : '' ?>" required>
                        </div>
                        
                        <div>
                            <label for="ir_form" class="block text-sm font-medium text-gray-300 mb-2">IR Form</label>
                            <input type="text" id="ir_form" name="ir_form" style="text-transform: uppercase;"
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                                   value="<?= $record ? htmlspecialchars($record['ir_form']) : '' ?>">
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="flex justify-between items-center pt-6">
                    <div class="flex space-x-3">
                        <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg flex items-center">
                            <i class="fas fa-save mr-2"></i> Save
                        </button>
                        <a href="attendance.php?tab=<?= $type ?>" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg flex items-center">
                            Cancel
                        </a>
                    </div>
                    
                    <!-- Bagong IR FORM Button na may functionality -->
                    <?php if ($id > 0): ?>
                    <button type="button" onclick="createIncidentReport()" class="bg-yellow-700 hover:bg-yellow-600 text-white px-6 py-2 rounded-lg flex items-center">
                        <i class="fas fa-file-alt mr-2"></i>
                        IR Form
                    </button>
                    <?php else: ?>
                    <button type="button" disabled class="bg-yellow-500/40 text-white px-6 py-2 rounded-lg flex items-center cursor-not-allowed">
                        <i class="fas fa-file-alt mr-2"></i>
                        IR Form
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </main>
</div>

<script>

// ========== INCIDENT REPORT CREATION ==========
function createIncidentReport() {
    if (!confirm('Are you sure you want to create an Incident Report for this record?')) {
        return;
    }

    // Collect data from the form
    const formData = new FormData();
    formData.append('record_id', <?= $id ?>);
    formData.append('type', '<?= $type ?>');
    formData.append('employee_id', document.getElementById('employee_id').value);
    formData.append('full_name', document.getElementById('full_name').value);
    formData.append('department', document.getElementById('department').value);
    formData.append('operation_manager', document.getElementById('operation_manager').value);
    formData.append('date_of_incident', document.getElementById('date_of_<?= $type === 'absenteeism' ? 'absent' : 'incident' ?>').value);
    formData.append('shift', document.getElementById('shift').value);
    
    <?php if ($type === 'absenteeism'): ?>
    formData.append('reason', document.getElementById('reason').value);
    formData.append('follow_call_in_procedure', document.getElementById('follow_call_in_procedure').value);
    <?php else: ?>
    formData.append('types', document.getElementById('types').value);
    formData.append('minutes_late', document.getElementById('minutes_late').value);
    <?php endif; ?>

    // Show loading state
    const irButton = document.querySelector('button[onclick="createIncidentReport()"]');
    const originalText = irButton.innerHTML;
    irButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Creating IR & NTE...';
    irButton.disabled = true;

    fetch('../includes/create_incident_report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let successMessage = data.message;
            
            if (data.nte_id) {
                successMessage += ` NTE #${data.nte_number} auto-generated.`;
            }
            
            alert(successMessage);
            
            // Update the IR form field to "YES"
            document.getElementById('ir_form').value = 'YES';
            
            // Optionally auto-save the form
            document.getElementById('attendanceForm').submit();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while creating the Incident Report.');
    })
    .finally(() => {
        // Restore button state
        irButton.innerHTML = originalText;
        irButton.disabled = false;
    });
}


// ========== ORIGINAL AUTO-FILL FUNCTIONS ==========
function searchEmployees(query) {
    if (query.length < 2) {
        document.getElementById('employeeSearchResults').classList.add('hidden');
        return;
    }

    fetch('../api/search_employees.php?query=' + encodeURIComponent(query))
        .then(response => response.json())
        .then(data => {
            const resultsContainer = document.getElementById('employeeSearchResults');
            resultsContainer.innerHTML = '';
            
            if (data.success && data.employees.length > 0) {
                data.employees.forEach(employee => {
                    const item = document.createElement('div');
                    item.className = 'px-4 py-2 hover:bg-gray-700 cursor-pointer border-b border-gray-700';
                    item.innerHTML = `
                        <div class="font-medium text-gray-200">${employee.employee_id}</div>
                        <div class="text-sm text-gray-400">${employee.full_name}</div>
                    `;
                    item.addEventListener('click', () => {
                        document.getElementById('employee_id').value = employee.employee_id;
                        fetchEmployeeDetails(employee.employee_id);
                        resultsContainer.classList.add('hidden');
                    });
                    resultsContainer.appendChild(item);
                });
                resultsContainer.classList.remove('hidden');
            } else {
                const item = document.createElement('div');
                item.className = 'px-4 py-2 text-gray-400';
                item.textContent = 'No employees found';
                resultsContainer.appendChild(item);
                resultsContainer.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// Add event listener for search
document.getElementById('employee_id').addEventListener('input', function() {
    searchEmployees(this.value);
});

// Hide results when clicking outside
document.addEventListener('click', function(e) {
    if (!document.getElementById('employee_id').contains(e.target) && 
        !document.getElementById('employeeSearchResults').contains(e.target)) {
        document.getElementById('employeeSearchResults').classList.add('hidden');
    }
});

function fetchEmployeeDetails(employeeId) {
    if (!employeeId) return;
    
    fetch('../api/get_employee.php?employee_id=' + encodeURIComponent(employeeId))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('full_name').value = data.employee.full_name;
                document.getElementById('department').value = data.employee.department;
                document.getElementById('supervisor').value = data.employee.supervisor;
                document.getElementById('operation_manager').value = data.employee.operation_manager;
                document.getElementById('email').value = data.employee.email;
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// Auto-fill employee details if editing
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($record): ?>
        fetchEmployeeDetails('<?= $record['employee_id'] ?>');
    <?php endif; ?>
});



// ========== COVERAGE FIELD MANAGEMENT ==========
let coverageFieldCount = <?= isset($coverages) ? count($coverages) : 1 ?>;

function addCoverageField() {
    const MAX_COVERAGE_FIELDS = 4;
    
    if (coverageFieldCount >= MAX_COVERAGE_FIELDS) {
        alert('Maximum of ' + MAX_COVERAGE_FIELDS + ' coverage fields allowed');
        return;
    }
    
    const container = document.getElementById('coverageContainer');
    const newField = document.createElement('div');
    newField.className = 'coverage-field mb-4 p-4 border border-gray-700 rounded-lg bg-gray-750';
    newField.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Coverage ${coverageFieldCount + 1}</label>
                <textarea name="coverage[]" style="text-transform: uppercase;"
                          class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                          required></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Coverage Type ${coverageFieldCount + 1}</label>
                <select name="coverage_type[]" class="coverage-type-select w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" required onchange="handleCoverageTypeChange(this)">
                    <option value="-">-</option>
                    <option value="NO NEED">NO NEED</option>
                    <option value="TRAINEE">TRAINEE</option>
                    <option value="BACK UP">BACK UP</option>
                    <option value="PENDING">PENDING</option>
                    <option value="DSOT">DSOT</option>
                    <option value="RDOT">RDOT</option>
                    <option value="AGENT MODE">AGENT MODE</option>
                    <option value="PVL">PVL</option>
                </select>
                
                <!-- Single detailed coverage type selection -->
                <div class="detailed-coverage mt-2 hidden">
                    <select name="detailed_coverage_type[]" class="detailed-select w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200">
                        <option value="">Select detailed type</option>
                        <!-- Options will be populated dynamically -->
                    </select>
                </div>
            </div>
        </div>
        <div class="mt-2 flex justify-end">
            <button type="button" onclick="removeCoverageField(this)" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm">
                <i class="fas fa-trash mr-1"></i> Remove
            </button>
        </div>
    `;
    container.appendChild(newField);
    coverageFieldCount++;
    
    // Update labels for all coverage fields
    updateCoverageLabels();
    
    // Hide "Add Coverage" button if limit reached
    updateAddButtonVisibility();
}

function removeCoverageField(button) {
    const field = button.closest('.coverage-field');
    field.remove();
    coverageFieldCount--;
    
    // Update labels for all coverage fields
    updateCoverageLabels();
    
    // Show "Add Coverage" button if under limit
    updateAddButtonVisibility();
}

function updateCoverageLabels() {
    const coverageFields = document.querySelectorAll('.coverage-field');
    coverageFields.forEach((field, index) => {
        const coverageNumber = index + 1;
        const coverageLabel = field.querySelector('label:first-child');
        const coverageTypeLabel = field.querySelector('label:nth-child(2)');
        
        if (coverageLabel) {
            coverageLabel.textContent = `Coverage ${coverageNumber}`;
        }
        if (coverageTypeLabel) {
            coverageTypeLabel.textContent = `Coverage Type ${coverageNumber}`;
        }
    });
}

function updateAddButtonVisibility() {
    const MAX_COVERAGE_FIELDS = 4;
    const addButton = document.querySelector('button[onclick="addCoverageField()"]');
    if (addButton) {
        if (coverageFieldCount >= MAX_COVERAGE_FIELDS) {
            addButton.style.display = 'none';
        } else {
            addButton.style.display = 'flex';
        }
    }
}

// ========== COVERAGE TYPE HANDLING ==========
function handleCoverageTypeChange(select) {
    const value = select.value;
    const fieldContainer = select.closest('.coverage-field');
    const detailedCoverageDiv = fieldContainer.querySelector('.detailed-coverage');
    const detailedSelect = fieldContainer.querySelector('.detailed-select');
    
    // Get the current value from the data attribute or the select itself
    const currentValue = detailedSelect.getAttribute('data-current-value') || detailedSelect.value;
    
    // Define options for RDOT and DSOT
    const rdotOptions = [
        {value: '12HRS RDOT', text: '12hrs RDOT'},
        {value: '8HRS RDOT', text: '8hrs RDOT'},
        {value: '6HRS RDOT', text: '6hrs RDOT'},
        {value: '4HRS RDOT', text: '4hrs RDOT'},
        {value: 'ADJUSTMENT', text: 'Adjustment'},
        {value: 'COMMENDATION', text: 'Commendation'}
    ];
    
    const dsotOptions = [
        {value: '12HRS OT DS', text: '12hrs OT DS'},
        {value: '8HRS OT DS', text: '8hrs OT DS'},
        {value: '6HRS OT DS', text: '6hrs OT DS'},
        {value: '4HRS OT DS', text: '4hrs OT DS'},
        {value: 'ADJUSTMENT', text: 'Adjustment'},
        {value: 'COMMENDATION', text: 'Commendation'}
    ];
    
    // Clear existing options but keep the first "Select detailed type" option
    detailedSelect.innerHTML = '<option value="">Select detailed type</option>';
    
    // Show/hide detailed coverage and populate options
    if (value === 'RDOT' || value === 'DSOT') {
        detailedCoverageDiv.classList.remove('hidden');
        detailedSelect.required = true;
        
        // Populate options based on coverage type
        const options = value === 'RDOT' ? rdotOptions : dsotOptions;
        options.forEach(option => {
            const optionElement = document.createElement('option');
            optionElement.value = option.value;
            optionElement.textContent = option.text;
            
            // Set selected if this matches the current value
            if (currentValue === option.value) {
                optionElement.selected = true;
            }
            
            detailedSelect.appendChild(optionElement);
        });
        
        // If no value is selected but we have a current value, try to set it
        if (detailedSelect.value === '' && currentValue && currentValue !== '') {
            detailedSelect.value = currentValue;
        }
    } else {
        detailedCoverageDiv.classList.add('hidden');
        detailedSelect.required = false;
        detailedSelect.value = '';
    }
}

// Initialize coverage type handlers for existing fields on page load
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize coverage functions if we're in absenteeism form
    const coverageContainer = document.getElementById('coverageContainer');
    if (coverageContainer) {
        const coverageTypeSelects = document.querySelectorAll('.coverage-type-select');
        coverageTypeSelects.forEach(select => {
            const value = select.value;
            
            // For existing fields with RDOT or DSOT, make sure the detailed section is visible
            if (value === 'RDOT' || value === 'DSOT') {
                const fieldContainer = select.closest('.coverage-field');
                const detailedCoverageDiv = fieldContainer.querySelector('.detailed-coverage');
                const detailedSelect = fieldContainer.querySelector('.detailed-select');
                
                if (detailedCoverageDiv) {
                    detailedCoverageDiv.classList.remove('hidden');
                }
                if (detailedSelect) {
                    detailedSelect.required = true;
                }
            }
            
            // Trigger change event to populate detailed options for existing fields
            handleCoverageTypeChange(select);
        });
        
        // Update button visibility on page load
        updateAddButtonVisibility();
    }
});


</script>

<?php renderFooter(); ?>