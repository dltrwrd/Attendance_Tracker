<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get current user's sub_name
        $userStmt = $pdo->prepare("SELECT sub_name FROM users WHERE id = ?");
        $userStmt->execute([$_SESSION['user_id']]);
        $user = $userStmt->fetch();
        $sub_name = $user['sub_name'];
        
        // Capture all employee details directly from POST
        $employeeId = sanitizeInput($_POST['employee_id']);
        $fullName = strtoupper(sanitizeInput($_POST['full_name']));
        $department = strtoupper(sanitizeInput($_POST['department']));
        $supervisor = strtoupper(sanitizeInput($_POST['supervisor']));
        $operationManager = strtoupper(sanitizeInput($_POST['operation_manager']));
        $email = isset($_POST['email']) ? sanitizeInput($_POST['email']) : '';
        
        // Check if the manual entry flag was triggered
        $isNewEmployee = isset($_POST['is_new_employee']) && $_POST['is_new_employee'] === '1';

        if ($isNewEmployee) {
            // Ensure employee truly doesn't exist before inserting
            $checkStmt = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ?");
            $checkStmt->execute([$employeeId]);
            if (!$checkStmt->fetch()) {
                $insertEmp = $pdo->prepare("INSERT INTO employees (employee_id, full_name, department, supervisor, operation_manager, email) VALUES (?, ?, ?, ?, ?, ?)");
                $insertEmp->execute([$employeeId, $fullName, $department, $supervisor, $operationManager, $email]);
            }
        } else {
            // Check if existing employee is found
            $employeeStmt = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ?");
            $employeeStmt->execute([$employeeId]);
            if (!$employeeStmt->fetch()) {
                throw new Exception("Employee not found in the database. Please use the 'Add Manually' option to register a new employee.");
            }
        }
        
        $currentMonth = date('M Y');
        $currentTime = date('g:i A');
        
        $data = [
            'month' => $currentMonth,
            'employee_id' => $employeeId,
            'full_name' => $fullName,
            'department' => $department,
            'supervisor' => $supervisor,
            'operation_manager' => $operationManager,
            'shift' => strtoupper(sanitizeInput($_POST['shift'])),
            'shift_date' => strtoupper(sanitizeInput($_POST['shift_date'])),
            'coverage' => strtoupper(sanitizeInput($_POST['coverage'])),
            'coverage_type' => strtoupper(sanitizeInput($_POST['coverage_type'])),
            'time_in' => sanitizeInput($_POST['time_in']),
            'time_out' => sanitizeInput($_POST['time_out']),
            'mins_of_work' => (int)$_POST['mins_of_work'],
            'vto_mins' => (int)$_POST['vto_mins'],
            'vto_type' => strtoupper(sanitizeInput($_POST['vto_type'])),
            'approved_by' => strtoupper(sanitizeInput($_POST['approved_by'])),
            'timestamp' => $currentTime,
            'sub_name' => $sub_name
        ];
        
        if ($id > 0) {
            // Update existing record
            $stmt = $pdo->prepare("UPDATE vto_tracker SET 
                month = :month,
                employee_id = :employee_id,
                full_name = :full_name,
                department = :department,
                supervisor = :supervisor,
                operation_manager = :operation_manager,
                shift = :shift,
                shift_date = :shift_date,
                coverage = :coverage,
                coverage_type = :coverage_type,
                time_in = :time_in,
                time_out = :time_out,
                mins_of_work = :mins_of_work,
                vto_mins = :vto_mins,
                vto_type = :vto_type,
                timestamp = :timestamp,
                sub_name = :sub_name,
                approved_by = :approved_by
                WHERE id = :id");
            
            $data['id'] = $id;
            $stmt->execute($data);
            
            $_SESSION['success'] = "VTO record updated successfully!";
            logActivity("Updated VTO record for {$fullName}", $id, 'vto');
        } else {
            // Insert new record
            $stmt = $pdo->prepare("INSERT INTO vto_tracker 
                (month, employee_id, full_name, department, supervisor, operation_manager, 
                shift, shift_date, coverage, coverage_type, time_in, time_out, mins_of_work, vto_mins, 
                vto_type, timestamp, sub_name, approved_by)
                VALUES 
                (:month, :employee_id, :full_name, :department, :supervisor, :operation_manager,
                :shift, :shift_date, :coverage, :coverage_type, :time_in, :time_out, :mins_of_work, :vto_mins,
                :vto_type, :timestamp, :sub_name, :approved_by)");
            
            $stmt->execute($data);
            $recordId = $pdo->lastInsertId();
            
            $_SESSION['success'] = "VTO record added successfully!";
            logActivity("Created VTO record for {$fullName}", $recordId, 'vto');
        }
        
        $pdo->commit();
        redirect('attendance.php?tab=vto');
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
        redirect($id ? 'vto_form.php?id=' . $id : 'vto_form.php?action=create');
    }
}

// Get record data
$record = null;
if ($id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM vto_tracker WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        
        if (!$record) {
            $_SESSION['error'] = "Record not found";
            redirect('attendance.php?tab=vto');
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching record: " . $e->getMessage();
        redirect('attendance.php?tab=vto');
    }
} elseif ($action !== 'create') {
    redirect('attendance.php?tab=vto');
}

require_once '../components/layout.php';
renderHead($id ? 'Edit VTO Record' : 'Add VTO Record');
renderNavbar();
renderSidebar('attendance');
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
    
    /* Scrollbar hide utility */
    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: rgba(17, 24, 39, 0.5);
        border-radius: 10px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: rgba(75, 85, 99, 0.8);
        border-radius: 10px;
    }

    /* Make entire date input clickable */
    .glass-input[type="date"] {
        position: relative;
    }
    .glass-input[type="date"]::-webkit-calendar-picker-indicator {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
</style>

<div class="pt-2 min-h-screen relative">
    <!-- Background blobs for glass effect depth -->
    <div class="absolute top-0 left-1/4 w-96 h-96 bg-blue-900/20 rounded-full mix-blend-multiply filter blur-3xl opacity-30 pointer-events-none z-0"></div>
    <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-purple-900/20 rounded-full mix-blend-multiply filter blur-3xl opacity-30 pointer-events-none z-0"></div>

    <!-- Widened form layout container -->
    <main class="p-6 relative z-10 w-full max-w-7xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-white"><?= $id ? 'Edit VTO Record' : 'Add VTO Record' ?></h1>
                <p class="text-gray-400 text-sm mt-1 uppercase tracking-wider">VTO TRACKER</p>
            </div>
            <a href="attendance.php?tab=vto" class="glass-panel p-2.5 rounded-full text-gray-400 hover:text-white transition-all duration-200 shadow-lg border border-gray-600/30 hover:bg-gray-700/50">
                <i class="fas fa-times fa-lg"></i>
            </a>
        </div>

        <?php renderAlert(); ?>

        <div class="glass-panel rounded-2xl border border-gray-700/50 p-8 shadow-2xl mb-10">
            <form method="POST" id="vtoForm">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="is_new_employee" id="is_new_employee" value="0">
                
                <!-- Layout Split: 2 Divs -->
                <div class="flex flex-col lg:flex-row gap-8">
                    
                    <!-- FIRST DIV: Employee Info (Left Sidebar-style) -->
                    <div class="w-full lg:w-1/3 bg-gray-800/30 p-6 rounded-2xl border border-gray-700/50 h-fit">
                        <h3 class="text-sm font-bold text-gray-300 uppercase tracking-wider mb-5 border-b border-gray-700/50 pb-3 flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="fas fa-id-card mr-2 text-primary-500"></i> Employee Information
                            </div>
                            <!-- Badge that appears during manual entry mode -->
                            <span id="newEmployeeBadge" class="hidden bg-primary-500/20 text-primary-400 text-[10px] px-2 py-1 rounded-full border border-primary-500/30">NEW EMPLOYEE MODE</span>
                        </h3>
                        <div class="space-y-5">
                            <!-- Employee ID -->
                            <div class="relative">
                                <label for="employee_id" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Employee ID</label>
                                <div class="relative">
                                    <input type="text" id="employee_id" name="employee_id" style="text-transform: uppercase;"
                                        class="glass-input w-full pl-10 pr-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100" 
                                        value="<?= $record ? htmlspecialchars($record['employee_id']) : '' ?>" required
                                        onchange="fetchEmployeeDetails(this.value)"
                                        autocomplete="off" placeholder="e.g., CXI0001">
                                    <div class="absolute left-3 top-3.5 text-gray-500 pointer-events-none">
                                        <i class="fas fa-id-badge"></i>
                                    </div>
                                </div>
                                <div id="employeeSearchResults" class="hidden absolute z-20 mt-1 w-full max-w-md glass-panel border border-gray-600/50 rounded-xl shadow-2xl overflow-hidden"></div>
                            </div>
                            
                            <!-- Full Name -->
                            <div>
                                <label for="full_name" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Full Name</label>
                                <input type="text" id="full_name" name="full_name" style="text-transform: uppercase;" readonly
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-300 bg-gray-900/40" 
                                       value="<?= $record ? htmlspecialchars($record['full_name']) : '' ?>" tabindex="-1">
                            </div>
                            
                            <!-- Department -->
                            <div>
                                <label for="department" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Department</label>
                                <input type="text" id="department" name="department" style="text-transform: uppercase;" readonly
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-300 bg-gray-900/40" 
                                       value="<?= $record ? htmlspecialchars($record['department']) : '' ?>" tabindex="-1">
                            </div>
                            
                            <!-- Supervisor -->
                            <div>
                                <label for="supervisor" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Supervisor</label>
                                <input type="text" id="supervisor" name="supervisor" style="text-transform: uppercase;" readonly
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-300 bg-gray-900/40" 
                                       value="<?= $record ? htmlspecialchars($record['supervisor']) : '' ?>" tabindex="-1">
                            </div>
                            
                            <!-- OM -->
                            <div>
                                <label for="operation_manager" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Operations Manager</label>
                                <input type="text" id="operation_manager" name="operation_manager" style="text-transform: uppercase;" readonly
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-300 bg-gray-900/40" 
                                       value="<?= $record ? htmlspecialchars($record['operation_manager']) : '' ?>" tabindex="-1">
                            </div>
                            
                            <!-- Email (Added for new user creation) -->
                            <div>
                                <label for="email" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Email Address</label>
                                <input type="email" id="email" name="email"
                                    class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-300 bg-gray-900/40" 
                                    value="" readonly tabindex="-1">
                            </div>
                        </div>
                    </div>

                    <!-- SECOND DIV: Record Data (Right Side Content) -->
                    <div class="w-full lg:w-2/3 bg-gray-800/30 p-6 rounded-2xl border border-gray-700/50">
                        <h3 class="text-sm font-bold text-gray-300 uppercase tracking-wider mb-5 border-b border-gray-700/50 pb-3 flex items-center">
                            <i class="fas fa-clipboard-list mr-2 text-primary-500"></i> Record Details
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            
                            <div>
                                <label for="shift_date" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Shift Date</label>
                                <input type="date" id="shift_date" name="shift_date" style="text-transform: uppercase;"
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100 cursor-pointer" 
                                       value="<?= $record ? htmlspecialchars($record['shift_date']) : '' ?>" required
                                       onclick="this.showPicker()">
                            </div>

                            <div>
                                <label for="shift" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Shift</label>
                                <input type="text" id="shift" name="shift" style="text-transform: uppercase;" placeholder=""
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100" 
                                       value="<?= $record ? htmlspecialchars($record['shift']) : '' ?>" required>
                            </div>
                            
                            <div>
                                <label for="coverage" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Coverage</label>
                                <input type="text" id="coverage" name="coverage" style="text-transform: uppercase;"
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100" 
                                       value="<?= $record ? htmlspecialchars($record['coverage']) : '' ?>" required>
                            </div>
                            
                            <div>
                                <label for="coverage_type" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Coverage Type</label>
                                <div class="relative">
                                    <select id="coverage_type" name="coverage_type"
                                            class="glass-input w-full pl-4 pr-10 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100 appearance-none" required>
                                        <option value="-" <?= $record && $record['coverage_type'] === '-' ? 'selected' : '' ?>>-</option>
                                        <option value="NO NEED" <?= $record && $record['coverage_type'] === 'NO NEED' ? 'selected' : '' ?>>NO NEED</option>
                                        <option value="TRAINEE" <?= $record && $record['coverage_type'] === 'TRAINEE' ? 'selected' : '' ?>>TRAINEE</option>
                                        <option value="BACK UP" <?= $record && $record['coverage_type'] === 'BACK UP' ? 'selected' : '' ?>>BACK UP</option>
                                        <option value="PENDING" <?= $record && $record['coverage_type'] === 'PENDING' ? 'selected' : '' ?>>PENDING</option>
                                        <option value="DSOT" <?= $record && $record['coverage_type'] === 'DSOT' ? 'selected' : '' ?>>DSOT</option>
                                        <option value="RDOT" <?= $record && $record['coverage_type'] === 'RDOT' ? 'selected' : '' ?>>RDOT</option>
                                        <option value="AGENT MODE" <?= $record && $record['coverage_type'] === 'AGENT MODE' ? 'selected' : '' ?>>AGENT MODE</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <label for="time_in" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Time In</label>
                                <input type="text" id="time_in" name="time_in" style="text-transform: uppercase;"
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100" 
                                       value="<?= $record ? htmlspecialchars($record['time_in']) : '' ?>" required>
                            </div>
                            
                            <div>
                                <label for="time_out" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Time Out</label>
                                <input type="text" id="time_out" name="time_out" style="text-transform: uppercase;"
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100" 
                                       value="<?= $record ? htmlspecialchars($record['time_out']) : '' ?>" required>
                            </div>
                            
                            <div>
                                <label for="mins_of_work" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Minutes Worked</label>
                                <input type="number" id="mins_of_work" name="mins_of_work" readonly
                                    class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-300 bg-gray-900/40" 
                                    value="<?= $record ? htmlspecialchars($record['mins_of_work']) : '' ?>" required tabindex="-1">
                            </div>

                            <div>
                                <label for="vto_mins" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">VTO Minutes</label>
                                <input type="number" id="vto_mins" name="vto_mins"
                                    class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100" 
                                    value="<?= $record ? htmlspecialchars($record['vto_mins']) : '' ?>" required>
                            </div>
                                                
                            <div>
                                <label for="vto_type" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">VTO Type</label>
                                <div class="relative">
                                    <select id="vto_type" name="vto_type"
                                            class="glass-input w-full pl-4 pr-10 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100 appearance-none" required>
                                        <option value="REALTIME" <?= $record && $record['vto_type'] === 'REALTIME' ? 'selected' : '' ?>>REALTIME</option>
                                        <option value="REALTIME - WD" <?= $record && $record['vto_type'] === 'REALTIME - WD' ? 'selected' : '' ?>>REALTIME - WD</option>
                                        <option value="PRE APPROVED" <?= $record && $record['vto_type'] === 'PRE APPROVED' ? 'selected' : '' ?>>PRE APPROVED</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <label for="approved_by" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Approved By</label>
                                <input type="text" id="approved_by" name="approved_by" style="text-transform: uppercase;"
                                       class="glass-input w-full px-4 py-3 rounded-xl transition-all duration-200 text-sm text-gray-100" 
                                       value="<?= $record ? htmlspecialchars($record['approved_by']) : '' ?>" required>
                            </div>
                        </div>
                    </div>
                </div> <!-- End Layout Split -->
                
                <!-- Bottom Action Area -->
                <div class="flex flex-col-reverse md:flex-row justify-between items-center gap-4 pt-8 mt-8 border-t border-gray-700/50">
                    <div class="flex space-x-3 w-full md:w-auto">
                        <button type="submit" class="flex-1 md:flex-none bg-primary-600 hover:bg-primary-500 text-white px-8 py-3 rounded-xl shadow-lg shadow-primary-900/30 transition-all font-semibold flex items-center justify-center tracking-wide">
                            <i class="fas fa-save mr-2"></i> Save Record
                        </button>
                        <a href="attendance.php?tab=vto" class="flex-1 md:flex-none bg-gray-700/50 hover:bg-gray-600 border border-gray-600 text-gray-200 px-8 py-3 rounded-xl transition-all font-medium flex items-center justify-center">
                            Cancel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </main>
</div>

<script>
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
                    item.className = 'px-4 py-3 hover:bg-gray-700/50 cursor-pointer border-b border-gray-700/50 transition-colors';
                    item.innerHTML = `
                        <div class="font-bold text-gray-200">${employee.employee_id}</div>
                        <div class="text-xs text-gray-400 uppercase">${employee.full_name}</div>
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
                item.className = 'px-4 py-4 text-sm flex flex-col gap-3 bg-gray-800/80';
                item.innerHTML = `
                    <span class="text-gray-400 text-xs uppercase tracking-wider font-semibold">Employee not found</span>
                    <button type="button" onclick="enableManualEntry()" class="bg-primary-600 hover:bg-primary-500 text-white px-4 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-all shadow-lg shadow-primary-900/30 flex items-center justify-center">
                        <i class="fas fa-user-plus mr-2"></i> Add Manually
                    </button>
                `;
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

// Function to enable manual entry for a new employee
function enableManualEntry() {
    document.getElementById('is_new_employee').value = '1';
    
    const fields = ['full_name', 'department', 'supervisor', 'operation_manager', 'email'];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.readOnly = false;
            el.removeAttribute('tabindex');
            // Remove readonly styling
            el.classList.remove('bg-gray-900/40', 'text-gray-300');
            // Add editable styling
            el.classList.add('bg-gray-700/50', 'text-gray-100', 'border-primary-500/50');
            el.value = ''; // clear existing value
        }
    });
    
    // Hide search results and show badge
    document.getElementById('employeeSearchResults').classList.add('hidden');
    document.getElementById('newEmployeeBadge').classList.remove('hidden');
    
    // Focus first manual field
    document.getElementById('full_name').focus();
}

// Function to lock fields back if a valid employee is loaded
function disableManualEntry() {
    document.getElementById('is_new_employee').value = '0';
    
    const fields = ['full_name', 'department', 'supervisor', 'operation_manager', 'email'];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.readOnly = true;
            el.setAttribute('tabindex', '-1');
            // Restore readonly styling
            el.classList.add('bg-gray-900/40', 'text-gray-300');
            el.classList.remove('bg-gray-700/50', 'text-gray-100', 'border-primary-500/50');
        }
    });
    
    document.getElementById('newEmployeeBadge').classList.add('hidden');
}

// Auto-fill employee details if editing
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($record): ?>
        fetchEmployeeDetails('<?= $record['employee_id'] ?>');
    <?php endif; ?>
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
                if(document.getElementById('email')) document.getElementById('email').value = data.employee.email || '';
                
                // Re-lock fields in case manual entry was previously enabled
                disableManualEntry();
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}


// Event listeners for auto-calculating based on Shift and VTO Type
document.getElementById('vto_type').addEventListener('change', autoComputeTimes);
document.getElementById('shift').addEventListener('change', autoComputeTimes);
document.getElementById('shift').addEventListener('blur', autoComputeTimes);

// Also keep standard calculation listeners
document.getElementById('time_in').addEventListener('change', calculateWorkTime);
document.getElementById('time_out').addEventListener('change', calculateWorkTime);
document.getElementById('time_in').addEventListener('input', calculateWorkTime);
document.getElementById('time_out').addEventListener('input', calculateWorkTime);

function autoComputeTimes() {
    const shiftVal = document.getElementById('shift').value.trim();
    const vtoType = document.getElementById('vto_type').value;

    if (!shiftVal || !vtoType) return;

    // Parse the shift string (e.g., "11:00 AM - 8:00 PM")
    const timePattern = /(\d{1,2}:\d{2}\s*(?:AM|PM)?)\s*-\s*(\d{1,2}:\d{2}\s*(?:AM|PM)?)/i;
    const match = shiftVal.match(timePattern);

    if (match) {
        const startTimeStr = match[1].trim();
        const endTimeStr = match[2].trim();

        // Check if user selected PRE APPROVED or REALTIME - WD
        if (vtoType === 'PRE APPROVED' || vtoType === 'REALTIME - WD') {
            document.getElementById('time_in').value = startTimeStr;
            document.getElementById('time_out').value = startTimeStr;
        } 
        // If user selected standard REALTIME
        else if (vtoType === 'REALTIME') {
            document.getElementById('time_in').value = startTimeStr;
            
            // Calculate halfway point dynamically based on shift duration (240 for 8hr shift, 360 for 12hr shift)
            const shiftDuration = calculateShiftDuration(shiftVal);
            const startMins = timeToMinutes(startTimeStr);
            const midpointMins = startMins + (shiftDuration / 2);
            
            document.getElementById('time_out').value = minutesToTime(midpointMins);
        }

        // Trigger work time calculation after auto-filling
        calculateWorkTime();
    }
}

// Helper function to format minutes back into HH:MM AM/PM
function minutesToTime(mins) {
    mins = Math.round(mins);
    mins = mins % 1440; // Wrap around midnight
    if (mins < 0) mins += 1440;
    
    let hours = Math.floor(mins / 60);
    let minutes = mins % 60;
    let ampm = hours >= 12 ? 'PM' : 'AM';
    
    hours = hours % 12;
    if (hours === 0) hours = 12;
    
    let minStr = minutes < 10 ? '0' + minutes : minutes;
    return `${hours}:${minStr} ${ampm}`;
}

function calculateWorkTime() {
    const timeIn = document.getElementById('time_in').value;
    const timeOut = document.getElementById('time_out').value;
    const shift = document.getElementById('shift').value.toUpperCase();
    
    if (timeIn && timeOut && shift) {
        // First, calculate shift duration in minutes
        const shiftDuration = calculateShiftDuration(shift);
        
        // Special case: Same time in/out (full VTO)
        if (timeIn === timeOut) {
            document.getElementById('mins_of_work').value = 0;
            document.getElementById('vto_mins').value = shiftDuration;
            return;
        }
        
        // Convert time strings to minutes since midnight
        const inMinutes = timeToMinutes(timeIn);
        const outMinutes = timeToMinutes(timeOut);
        
        // Determine if this is a night shift (look for AM in shift end time)
        const isNightShift = shift.includes('AM') || 
                           (shift.includes('-') && shift.split('-')[1].includes('AM'));
        
        let minutesWorked;
        
        if (isNightShift) {
            // Night shift calculation (may cross midnight)
            if (outMinutes > inMinutes) {
                // Same day (e.g., 7:00 PM to 11:00 PM)
                minutesWorked = outMinutes - inMinutes;
            } else {
                // Crosses midnight (e.g., 7:00 PM to 5:00 AM)
                minutesWorked = (1440 - inMinutes) + outMinutes; // 1440 = minutes in a day
            }
        } else {
            // Day shift calculation
            if (outMinutes >= inMinutes) {
                minutesWorked = outMinutes - inMinutes;
            } else {
                // Invalid time range for day shift
                document.getElementById('mins_of_work').value = '';
                document.getElementById('vto_mins').value = '';
                return;
            }
        }
        
        // Apply 1-hour deduction for break time in specific cases
        if ((shiftDuration === 480 && minutesWorked === 300) || 
            (shiftDuration === 720 && minutesWorked === 420)) {
            minutesWorked -= 60;
        }
        
        // Set the calculated values
        document.getElementById('mins_of_work').value = minutesWorked;
        document.getElementById('vto_mins').value = Math.max(0, shiftDuration - minutesWorked);
    }
}

// Calculate shift duration in minutes based on shift pattern
function calculateShiftDuration(shiftPattern) {
    // Extract start and end times from shift pattern
    const timePattern = /(\d{1,2}:\d{2}\s*(?:AM|PM)?)\s*-\s*(\d{1,2}:\d{2}\s*(?:AM|PM)?)/i;
    const match = shiftPattern.match(timePattern);
    
    if (match) {
        const startTime = match[1].trim();
        const endTime = match[2].trim();
        
        const startMinutes = timeToMinutes(startTime);
        let endMinutes = timeToMinutes(endTime);
        
        // Handle overnight shifts
        if (endMinutes <= startMinutes) {
            endMinutes += 1440; // Add 24 hours
        }
        
        const duration = endMinutes - startMinutes;
        
        // Return common shift durations (12hr=720, 9hr=540, 8hr=480)
        if (duration >= 660 && duration <= 760) return 720; // 12hr shift
        if (duration >= 420 && duration <= 560) return 480; // 9hr shift
        
        return duration; // Return exact duration if not standard
    }
    
    return 480; // Default to 8hr shift if pattern not recognized
}

// Convert time string to minutes since midnight
function timeToMinutes(timeStr) {
    if (!timeStr) return 0;
    
    // Normalize the time string
    timeStr = timeStr.trim().toUpperCase();
    let isPM = false;
    
    // Handle AM/PM
    if (timeStr.includes('PM')) {
        isPM = true;
        timeStr = timeStr.replace('PM', '').trim();
    } else if (timeStr.includes('AM')) {
        timeStr = timeStr.replace('AM', '').trim();
    }
    
    // Split hours and minutes
    const parts = timeStr.split(':');
    let hours = parseInt(parts[0]) || 0;
    const minutes = parseInt(parts[1]) || 0;
    
    // Convert to 24-hour format
    if (isPM && hours < 12) hours += 12;
    if (!isPM && hours === 12) hours = 0;
    
    return hours * 60 + minutes;
}

// Initialize calculation when page loads
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($record): ?>
        fetchEmployeeDetails('<?= $record['employee_id'] ?>');
        // Calculate work time if editing existing record with time values
        if (document.getElementById('time_in').value && document.getElementById('time_out').value) {
            calculateWorkTime();
        }
    <?php endif; ?>
});
</script>

<?php renderFooter(); ?>