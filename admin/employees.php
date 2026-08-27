<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

// Automatically normalize empty departments/managers to 'TBA' to keep the database healthy
try {
    $pdo->exec("UPDATE employees SET department = 'TBA' WHERE department = '' OR department IS NULL");
    $pdo->exec("UPDATE employees SET supervisor = 'TBA' WHERE supervisor = '' OR supervisor IS NULL");
    $pdo->exec("UPDATE employees SET operation_manager = 'TBA' WHERE operation_manager = '' OR operation_manager IS NULL");
} catch (PDOException $e) {}

if (isset($_GET['delete'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $employeeId = (int)$_GET['delete'];
    $requiredPassword = "SLT@2025"; 
    $providedPassword = $_POST['delete_password'] ?? '';
    
    if (empty($providedPassword) || $providedPassword !== $requiredPassword) {
        $_SESSION['error'] = "Incorrect or missing password for deletion";
        redirect('employees.php');
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        if ($stmt->rowCount() > 0) $_SESSION['success'] = "Employee deleted successfully!";
        else $_SESSION['error'] = "Employee not found";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting employee: " . $e->getMessage();
    }
    redirect('employees.php');
}

if (isset($_GET['toggle_status'])) {
    $employeeId = (int)$_GET['toggle_status'];
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        $currentStatus = $stmt->fetchColumn();
        $newStatus = $currentStatus ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE employees SET is_active = ? WHERE id = ?");
        $stmt->execute([$newStatus, $employeeId]);
        $_SESSION['success'] = "Employee status updated successfully!";
    } catch (PDOException $e) { $_SESSION['error'] = "Error updating employee status: " . $e->getMessage(); }
    redirect('employees.php');
}

// Bulk Team Update Logic
if (isset($_POST['bulk_update_team'])) {
    $selectedEmployees = $_POST['selected_employees'] ?? [];
    
    if (empty($selectedEmployees)) {
        $_SESSION['error'] = "Please select at least one employee to update.";
        redirect('employees.php');
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        $updatedCount = 0;
        
        $updateFields = [];
        $updateParams = [];
        
        // Map form fields to DB columns safely
        $allowedFields = [
            'department' => 'department',
            'supervisor' => 'supervisor',
            'operation_manager' => 'operation_manager',
            'cxi_id' => 'employee_id',
            'email' => 'email'
        ];
        
        foreach ($allowedFields as $postKey => $dbColumn) {
            if (!empty(trim($_POST[$postKey]))) {
                $updateFields[] = "$dbColumn = ?";
                $updateParams[] = sanitizeInput($_POST[$postKey]);
            }
        }
        
        // Handle explicit status dropdown
        if (isset($_POST['status_update']) && $_POST['status_update'] !== 'no_change') {
            $updateFields[] = "is_active = ?";
            $updateParams[] = ($_POST['status_update'] === 'active') ? 1 : 0;
        }
        
        if (!empty($updateFields)) {
            // Append IDs for the WHERE clause
            foreach ($selectedEmployees as $employeeId) {
                $params = $updateParams;
                $params[] = (int)$employeeId;
                $sql = "UPDATE employees SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $updatedCount++;
            }
            $pdo->commit();
            $_SESSION['success'] = "Successfully applied changes to $updatedCount agents!";
        } else {
            $_SESSION['error'] = "No changes were entered.";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Update failed. If you modified CXI Numbers or Emails, ensure they are unique. Error: " . $e->getMessage();
    }
    
    redirect('employees.php');
    exit();
}

try {
    // Top Row Statistic Cards
    $statsStmt = $pdo->query("SELECT SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as total_active FROM employees");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Smart Relational Checks
    $invalidCxiCount = $pdo->query("SELECT COUNT(id) FROM employees WHERE employee_id NOT REGEXP '^(CXI|COM)[0-9]{5}$' AND employee_id != ''")->fetchColumn();
    $orphanSupervisorsCount = $pdo->query("SELECT COUNT(e1.id) FROM employees e1 INNER JOIN employees e2 ON e1.supervisor = e2.full_name WHERE e1.is_active = 1 AND e2.is_active = 0")->fetchColumn();
    $orphanOmsCount = $pdo->query("SELECT COUNT(e1.id) FROM employees e1 INNER JOIN employees e2 ON e1.operation_manager = e2.full_name WHERE e1.is_active = 1 AND e2.is_active = 0")->fetchColumn();
    $tbaAgentsCount = $pdo->query("SELECT COUNT(id) FROM employees WHERE (supervisor = 'TBA' OR operation_manager = 'TBA' OR department = 'TBA')")->fetchColumn();

    // Dropdown Data
    $deptStmt = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
    $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $supervisorStmt = $pdo->query("SELECT DISTINCT supervisor FROM employees WHERE supervisor IS NOT NULL AND supervisor != '' ORDER BY supervisor");
    $supervisors = $supervisorStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $opManagerStmt = $pdo->query("SELECT DISTINCT operation_manager FROM employees WHERE operation_manager IS NOT NULL AND operation_manager != '' ORDER BY operation_manager");
    $operationManagers = $opManagerStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $departments = []; $supervisors = []; $operationManagers = []; $stats = ['total_active' => 0];
    $orphanSupervisorsCount = 0; $orphanOmsCount = 0; $invalidCxiCount = 0; $tbaAgentsCount = 0;
}

require_once '../components/layout.php';
renderHead('Manage Employees');
renderNavbar();
renderSidebar('employees');
?>

<div class="pt-2 min-h-screen pb-10 w-full">
    <main class="w-full mx-auto px-4 md:px-8">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4 mt-4">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-100">Manage Agents</h1>
            <div class="flex flex-wrap gap-2">
                <button type="button" onclick="openImportModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center shadow transition-colors font-medium">
                    <i class="fas fa-file-import mr-2"></i> Import CSV
                </button>
                <button type="button" id="editTeamBtn" onclick="showEditTeamModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center shadow transition-colors font-medium hidden">
                    <i class="fas fa-users-cog mr-2"></i> Bulk Update
                </button>
                <a href="employee.php?action=create" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg flex items-center shadow transition-colors font-medium">
                    <i class="fas fa-plus mr-2"></i> Add New Agent
                </a>
            </div>
        </div>

        <?php renderAlert(); ?>
        
        <!-- Interactive Quick Filters -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <div id="card-active" onclick="toggleQuickFilter('active')" class="insight-card cursor-pointer bg-gray-800 border border-gray-700 rounded-xl p-4 flex items-center shadow-md group transition-all duration-200 hover:-translate-y-1 hover:border-blue-500">
                <div class="p-3 bg-blue-500/20 text-blue-400 rounded-lg mr-4"><i class="fas fa-users text-xl"></i></div>
                <div>
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-wider">Active Agents</p>
                    <div class="flex items-baseline">
                        <p class="text-2xl font-bold text-gray-100"><?= number_format($stats['total_active'] ?? 0) ?></p>
                        <span class="text-[10px] ml-2 text-blue-400 uppercase tracking-wider font-bold opacity-0 group-hover:opacity-100 transition-opacity">Click to Filter</span>
                    </div>
                </div>
            </div>
            
            <div id="card-inactive_sup" onclick="toggleQuickFilter('inactive_sup')" class="insight-card cursor-pointer bg-gray-800 border <?= $orphanSupervisorsCount > 0 ? 'border-red-500/50' : 'border-gray-700' ?> rounded-xl p-4 flex items-center shadow-md group transition-all duration-200 hover:-translate-y-1 <?= $orphanSupervisorsCount > 0 ? 'hover:border-red-400' : 'hover:border-blue-500' ?>">
                <div class="p-3 <?= $orphanSupervisorsCount > 0 ? 'bg-red-500/20 text-red-400' : 'bg-green-500/20 text-green-400' ?> rounded-lg mr-4">
                    <i class="fas <?= $orphanSupervisorsCount > 0 ? 'fa-user-slash' : 'fa-check' ?> text-xl"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-wider">Inactive Sup</p>
                    <div class="flex items-baseline">
                        <p class="text-2xl font-bold <?= $orphanSupervisorsCount > 0 ? 'text-red-400' : 'text-gray-100' ?>"><?= number_format($orphanSupervisorsCount) ?></p>
                        <?php if($orphanSupervisorsCount > 0): ?><span class="text-[10px] ml-2 text-red-400 uppercase tracking-wider font-bold opacity-0 group-hover:opacity-100 transition-opacity">Click to Fix</span><?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="card-inactive_om" onclick="toggleQuickFilter('inactive_om')" class="insight-card cursor-pointer bg-gray-800 border <?= $orphanOmsCount > 0 ? 'border-red-500/50' : 'border-gray-700' ?> rounded-xl p-4 flex items-center shadow-md group transition-all duration-200 hover:-translate-y-1 <?= $orphanOmsCount > 0 ? 'hover:border-red-400' : 'hover:border-blue-500' ?>">
                <div class="p-3 <?= $orphanOmsCount > 0 ? 'bg-red-500/20 text-red-400' : 'bg-green-500/20 text-green-400' ?> rounded-lg mr-4">
                    <i class="fas <?= $orphanOmsCount > 0 ? 'fa-user-slash' : 'fa-check' ?> text-xl"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-wider">Inactive OM</p>
                    <div class="flex items-baseline">
                        <p class="text-2xl font-bold <?= $orphanOmsCount > 0 ? 'text-red-400' : 'text-gray-100' ?>"><?= number_format($orphanOmsCount) ?></p>
                        <?php if($orphanOmsCount > 0): ?><span class="text-[10px] ml-2 text-red-400 uppercase tracking-wider font-bold opacity-0 group-hover:opacity-100 transition-opacity">Click to Fix</span><?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="card-tba" onclick="toggleQuickFilter('tba')" class="insight-card cursor-pointer bg-gray-800 border <?= $tbaAgentsCount > 0 ? 'border-yellow-500/50' : 'border-gray-700' ?> rounded-xl p-4 flex items-center shadow-md group transition-all duration-200 hover:-translate-y-1 hover:border-yellow-500">
                <div class="p-3 <?= $tbaAgentsCount > 0 ? 'bg-yellow-500/20 text-yellow-400' : 'bg-green-500/20 text-green-400' ?> rounded-lg mr-4">
                    <i class="fas <?= $tbaAgentsCount > 0 ? 'fa-question-circle' : 'fa-check' ?> text-xl"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-wider">Unassigned</p>
                    <div class="flex items-baseline">
                        <p class="text-2xl font-bold <?= $tbaAgentsCount > 0 ? 'text-yellow-400' : 'text-gray-100' ?>"><?= number_format($tbaAgentsCount) ?></p>
                        <?php if($tbaAgentsCount > 0): ?><span class="text-[10px] ml-2 text-yellow-400 uppercase tracking-wider font-bold opacity-0 group-hover:opacity-100 transition-opacity">Click to Fix</span><?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="card-format_errors" onclick="toggleQuickFilter('format_errors')" class="insight-card cursor-pointer bg-gray-800 border <?= $invalidCxiCount > 0 ? 'border-yellow-500/60' : 'border-gray-700' ?> rounded-xl p-4 flex items-center shadow-md group transition-all duration-200 hover:-translate-y-1 hover:border-yellow-500">
                <div class="p-3 <?= $invalidCxiCount > 0 ? 'bg-yellow-500/20 text-yellow-400' : 'bg-green-500/20 text-green-400' ?> rounded-lg mr-4">
                    <i class="fas <?= $invalidCxiCount > 0 ? 'fa-exclamation-triangle' : 'fa-check' ?> text-xl"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium uppercase tracking-wider">Format Errors</p>
                    <div class="flex items-baseline">
                        <p class="text-2xl font-bold <?= $invalidCxiCount > 0 ? 'text-yellow-400' : 'text-gray-100' ?>"><?= number_format($invalidCxiCount) ?></p>
                        <?php if($invalidCxiCount > 0): ?>
                            <span class="text-[10px] ml-2 text-yellow-400 uppercase tracking-wider font-bold opacity-0 group-hover:opacity-100 transition-opacity">Click to Fix</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="bg-gray-800/80 backdrop-blur-md rounded-xl border border-gray-700 p-5 mb-6 shadow-md">
            <h3 class="text-sm font-semibold text-gray-300 uppercase tracking-wider mb-4 border-b border-gray-700 pb-3"><i class="fas fa-filter mr-2"></i> Master Filters</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="relative lg:col-span-1">
                    <input type="text" id="searchInput" class="w-full pl-10 pr-4 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 rounded-lg text-gray-200 transition-all placeholder-gray-500 text-sm" placeholder="Search by ID or Name...">
                    <div class="absolute left-3 top-3 text-gray-500"><i class="fas fa-search"></i></div>
                </div>
                
                <div class="relative">
                    <select id="departmentFilter" class="w-full pl-4 pr-8 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 appearance-none text-sm transition-all uppercase">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="absolute right-3 top-3 text-gray-500 pointer-events-none"><i class="fas fa-chevron-down text-xs"></i></div>
                </div>
                
                <div class="relative">
                    <select id="supervisorFilter" class="w-full pl-4 pr-8 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 appearance-none text-sm transition-all uppercase">
                        <option value="">All Supervisors</option>
                        <?php foreach ($supervisors as $supervisor): ?>
                            <option value="<?= htmlspecialchars($supervisor) ?>"><?= htmlspecialchars($supervisor) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="absolute right-3 top-3 text-gray-500 pointer-events-none"><i class="fas fa-chevron-down text-xs"></i></div>
                </div>
                
                <div class="relative">
                    <select id="operationManagerFilter" class="w-full pl-4 pr-8 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 appearance-none text-sm transition-all uppercase">
                        <option value="">All Operation Mgrs</option>
                        <?php foreach ($operationManagers as $opManager): ?>
                            <option value="<?= htmlspecialchars($opManager) ?>"><?= htmlspecialchars($opManager) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="absolute right-3 top-3 text-gray-500 pointer-events-none"><i class="fas fa-chevron-down text-xs"></i></div>
                </div>
                
                <div class="relative">
                    <select id="statusFilter" class="w-full pl-4 pr-8 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 appearance-none text-sm transition-all">
                        <option value="1">Active Only (Default)</option>
                        <option value="">All Statuses</option>
                        <option value="0">Inactive Only</option>
                    </select>
                    <div class="absolute right-3 top-3 text-gray-500 pointer-events-none"><i class="fas fa-chevron-down text-xs"></i></div>
                </div>
                
                <div>
                    <button type="button" id="clearFiltersBtn" class="w-full py-2.5 bg-gray-700 hover:bg-gray-600 text-gray-200 rounded-lg transition-all flex items-center justify-center font-medium shadow border border-gray-600 text-sm">
                        <i class="fas fa-times-circle mr-2 text-gray-400"></i> Clear Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Table Container -->
        <div id="employeesTableContainer" class="w-full block">
            <?php include 'partials/employees_table.php'; ?>
        </div>
    </main>
</div>

<div id="individualEditModal" class="hidden fixed inset-0 bg-gray-900/70 backdrop-blur-sm flex items-center justify-center z-[55] transition-opacity duration-300 p-4">
    <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-2xl w-full max-w-3xl overflow-hidden transform transition-all">
        <div class="p-6">
            <div class="flex justify-between items-center mb-5 border-b border-gray-700 pb-4">
                <h3 class="text-xl font-bold text-white"><i class="fas fa-user-edit mr-2 text-blue-500"></i> Edit Agent Details</h3>
                <button onclick="closeIndividualModal()" class="text-gray-400 hover:text-white transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="POST" action="employee.php" id="individualEditForm">
                <input type="hidden" name="employee_id" id="ind_id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">CXI / COM Number</label>
                        <input type="text" id="ind_cxi" name="cxi_id" required pattern="^(CXI|COM)[0-9]{5}$" oninput="this.value = this.value.toUpperCase()"
                               class="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 transition-all font-mono uppercase shadow-inner">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Full Name</label>
                        <input type="text" id="ind_name" name="full_name" required oninput="this.value = this.value.toUpperCase()"
                               class="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 transition-all uppercase shadow-inner">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Department</label>
                        <input type="text" id="ind_dept" name="department" oninput="this.value = this.value.toUpperCase()"
                               class="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 transition-all uppercase shadow-inner">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Email Address</label>
                        <input type="email" id="ind_email" name="email" 
                               class="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 transition-all shadow-inner lowercase">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Supervisor</label>
                        <input type="text" id="ind_sup" name="supervisor" oninput="this.value = this.value.toUpperCase()"
                               class="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 transition-all uppercase shadow-inner">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Operations Manager</label>
                        <input type="text" id="ind_om" name="operation_manager" oninput="this.value = this.value.toUpperCase()"
                               class="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 transition-all uppercase shadow-inner">
                    </div>
                    <div class="md:col-span-2 bg-gray-900/50 p-4 rounded-lg border border-gray-700 mt-2">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" id="ind_active" name="is_active" class="w-5 h-5 text-blue-600 bg-gray-800 border-gray-600 rounded focus:ring-blue-500 focus:ring-2">
                            <span class="ml-3 text-sm font-bold text-gray-200">Active Account Status</span>
                        </label>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3 pt-2">
                    <button type="button" onclick="closeIndividualModal()" 
                            class="bg-gray-700 hover:bg-gray-600 text-white px-5 py-2.5 rounded-lg transition-colors font-medium">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg flex items-center shadow-lg transition-colors font-medium">
                        <i class="fas fa-save mr-2"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Team (Bulk Update) Modal -->
<div id="editTeamModal" class="hidden fixed inset-0 bg-gray-900/70 backdrop-blur-sm flex items-center justify-center z-[50] transition-opacity duration-300 p-4">
    <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-2xl w-full max-w-2xl overflow-hidden">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4 border-b border-gray-700 pb-4">
                <h3 class="text-xl font-bold text-white"><i class="fas fa-layer-group text-green-500 mr-2"></i> Bulk Update Agents</h3>
                <button onclick="closeEditTeamModal()" class="text-gray-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            
            <form method="POST" id="editTeamForm">
                <div id="selectedEmployeesContainer"></div>
                
                <div class="bg-blue-900/20 border border-blue-800/50 rounded-lg p-3 mb-5">
                    <p class="text-sm text-blue-300 flex items-center"><i class="fas fa-info-circle mr-2 text-lg"></i>Only fill in the fields you want to change for all selected agents.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Department</label>
                        <select name="department" class="w-full px-4 py-2 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 uppercase text-sm">
                            <option value="">-- No Change --</option>
                            <?php foreach ($departments as $dept): ?><option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Status</label>
                        <select name="status_update" class="w-full px-4 py-2 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 text-sm">
                            <option value="no_change">-- No Change --</option>
                            <option value="active">Force Active</option>
                            <option value="inactive">Force Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Supervisor</label>
                        <select name="supervisor" class="w-full px-4 py-2 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 uppercase text-sm">
                            <option value="">-- No Change --</option>
                            <?php foreach ($supervisors as $supervisor): ?><option value="<?= htmlspecialchars($supervisor) ?>"><?= htmlspecialchars($supervisor) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Operation Manager</label>
                        <select name="operation_manager" class="w-full px-4 py-2 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-blue-500 rounded-lg text-gray-200 uppercase text-sm">
                            <option value="">-- No Change --</option>
                            <?php foreach ($operationManagers as $opManager): ?><option value="<?= htmlspecialchars($opManager) ?>"><?= htmlspecialchars($opManager) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="md:col-span-2 bg-yellow-900/10 border border-yellow-700/50 rounded-lg p-4 mt-2">
                        <h4 class="text-yellow-400 font-bold text-sm mb-3"><i class="fas fa-exclamation-triangle mr-1"></i> Override Unique Identifiers</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-400 mb-1">ID Number (CXI/COM)</label>
                                <input type="text" name="cxi_id" placeholder="CXI00000" pattern="^(CXI|COM)[0-9]{5}$" oninput="this.value = this.value.toUpperCase()" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-yellow-500 rounded-md text-gray-200 font-mono uppercase text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-400 mb-1">Email Address</label>
                                <input type="email" name="email" placeholder="Leave blank to keep" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-yellow-500 rounded-md text-gray-200 text-sm">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3 pt-3 border-t border-gray-700">
                    <button type="button" onclick="closeEditTeamModal()" class="bg-gray-700 hover:bg-gray-600 text-gray-200 px-5 py-2.5 rounded-lg font-medium transition-colors">Cancel</button>
                    <button type="submit" name="bulk_update_team" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2.5 rounded-lg flex items-center shadow-lg font-medium transition-colors">
                        <i class="fas fa-save mr-2"></i> Apply Updates
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import CSV Modal -->
<div id="importModal" class="hidden fixed inset-0 bg-gray-900/70 backdrop-blur-sm flex items-center justify-center z-[60] transition-opacity duration-300 p-4">
    <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-2xl w-full max-w-md overflow-hidden transform transition-all">
        <div class="p-6">
            <div class="flex justify-between items-center mb-5 border-b border-gray-700 pb-4">
                <h3 class="text-xl font-bold text-white"><i class="fas fa-file-import mr-2 text-blue-500"></i> Import Agents</h3>
                <button onclick="closeImportModal()" class="text-gray-400 hover:text-white transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="importForm">
                <div class="mb-4">
                    <p class="text-sm text-gray-300 mb-2">Upload a CSV file containing agent details.</p>
                    <a href="download_employee_template.php" class="text-blue-400 hover:text-blue-300 text-sm font-medium inline-flex items-center"><i class="fas fa-download mr-1"></i> Download Template</a>
                </div>
                
                <div id="dropZone" class="border-2 border-dashed border-gray-600 rounded-xl p-8 text-center hover:border-blue-500 hover:bg-blue-500/10 transition-all cursor-pointer group mb-4">
                    <input type="file" name="csv_file" id="fileInput" class="hidden" accept=".csv">
                    <i class="fa-solid fa-cloud-arrow-up text-4xl text-gray-500 group-hover:text-blue-500 mb-3"></i>
                    <p id="fileName" class="text-gray-300 font-medium">Drag & drop or <span class="text-blue-500">browse CSV</span></p>
                </div>

                <div class="mb-6 bg-gray-900/50 p-4 rounded-lg border border-gray-700">
                    <label class="flex items-start cursor-pointer">
                        <div class="flex items-center h-5">
                            <input type="checkbox" id="overwrite_existing" name="overwrite" value="true" class="w-5 h-5 text-blue-600 bg-gray-800 border-gray-600 rounded focus:ring-blue-500 focus:ring-2 mt-0.5">
                        </div>
                        <div class="ml-3 text-sm">
                            <span class="font-bold text-gray-200 block">Overwrite Existing Details</span>
                            <span class="text-gray-400 text-xs">If an employee ID already exists, their details will be updated with the CSV data. If unchecked, existing records are skipped.</span>
                        </div>
                    </label>
                </div>
                
                <div class="flex justify-end space-x-3 pt-2">
                    <button type="button" onclick="closeImportModal()" 
                            class="bg-gray-700 hover:bg-gray-600 text-white px-5 py-2.5 rounded-lg transition-colors font-medium">
                        Cancel
                    </button>
                    <button type="submit" id="submitImport"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg flex items-center shadow-lg transition-colors font-medium">
                        <i class="fas fa-upload mr-2"></i> Upload CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Import Modal Functions
function openImportModal() { document.getElementById('importModal').classList.remove('hidden'); }
function closeImportModal() { document.getElementById('importModal').classList.add('hidden'); }

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileNameDisplay = document.getElementById('fileName');

dropZone.onclick = () => fileInput.click();
fileInput.onchange = () => { if (fileInput.files.length) fileNameDisplay.innerText = fileInput.files[0].name; };

['dragover', 'dragenter'].forEach(type => {
    dropZone.addEventListener(type, (e) => { e.preventDefault(); dropZone.classList.add('border-blue-500', 'bg-blue-500/10'); });
});
['dragleave', 'drop'].forEach(type => {
    dropZone.addEventListener(type, () => { dropZone.classList.remove('border-blue-500', 'bg-blue-500/10'); });
});
dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        fileNameDisplay.innerText = e.dataTransfer.files[0].name;
    }
});

document.getElementById('importForm').onsubmit = function(e) {
    e.preventDefault();
    if (!fileInput.files.length) {
        alert("Please select a CSV file.");
        return;
    }
    
    const btn = document.getElementById('submitImport');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
    
    const formData = new FormData();
    formData.append('csv_file', fileInput.files[0]);
    if (document.getElementById('overwrite_existing').checked) {
        formData.append('overwrite', 'true');
    }
    
    fetch('import_employees_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert("Error: " + data.message);
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        console.error(err);
        alert("An error occurred during import.");
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
};

let currentQuickFilter = '';

function toggleQuickFilter(type) {
    document.querySelectorAll('.insight-card').forEach(c => c.classList.remove('ring-4', 'ring-blue-500', 'border-blue-500'));
    
    if (currentQuickFilter === type) {
        currentQuickFilter = '';
        document.getElementById('statusFilter').value = '1'; 
    } else {
        currentQuickFilter = type;
        const activeCard = document.getElementById('card-' + type);
        if (activeCard) activeCard.classList.add('ring-4', 'ring-blue-500', 'border-blue-500');
        
        // Reset manual filters so they don't conflict
        document.getElementById('searchInput').value = '';
        document.getElementById('departmentFilter').value = '';
        document.getElementById('supervisorFilter').value = '';
        document.getElementById('operationManagerFilter').value = '';
        document.getElementById('statusFilter').value = ''; 
    }
    document.getElementById('searchInput').dispatchEvent(new Event('input')); // Trigger update
}

// Individual Edit Modal Handlers
function openIndividualModal(row) {
    document.getElementById('ind_id').value = row.getAttribute('data-id');
    document.getElementById('ind_cxi').value = row.getAttribute('data-cxi');
    document.getElementById('ind_name').value = row.getAttribute('data-name');
    document.getElementById('ind_dept').value = row.getAttribute('data-dept');
    document.getElementById('ind_sup').value = row.getAttribute('data-sup');
    document.getElementById('ind_om').value = row.getAttribute('data-om');
    document.getElementById('ind_email').value = row.getAttribute('data-email');
    document.getElementById('ind_active').checked = row.getAttribute('data-status') === '1';
    
    document.getElementById('individualEditModal').classList.remove('hidden');
}

function closeIndividualModal() {
    document.getElementById('individualEditModal').classList.add('hidden');
}

// Bulk Edit Modal Handlers
function showEditTeamModal() {
    const selectedCheckboxes = document.querySelectorAll('.employee-checkbox:checked');
    if (selectedCheckboxes.length === 0) return;
    
    const container = document.getElementById('selectedEmployeesContainer');
    container.innerHTML = '';
    
    selectedCheckboxes.forEach(checkbox => {
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'selected_employees[]';
        hiddenInput.value = checkbox.value;
        container.appendChild(hiddenInput);
    });
    
    document.getElementById('editTeamModal').classList.remove('hidden');
}

function closeEditTeamModal() {
    document.getElementById('editTeamModal').classList.add('hidden');
}

function toggleEditTeamButton() {
    const selectedCount = document.querySelectorAll('.employee-checkbox:checked').length;
    const editTeamBtn = document.getElementById('editTeamBtn');
    if (selectedCount > 0) {
        editTeamBtn.classList.remove('hidden');
        editTeamBtn.innerHTML = `<i class="fas fa-layer-group mr-2"></i> Bulk Update (${selectedCount})`;
    } else {
        editTeamBtn.classList.add('hidden');
    }
}

function selectAllEmployees(checkbox) {
    document.querySelectorAll('.employee-checkbox').forEach(cb => cb.checked = checkbox.checked);
    toggleEditTeamButton();
}

// Main Table Data Loading
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const filters = ['departmentFilter', 'supervisorFilter', 'operationManagerFilter', 'statusFilter'];
    let searchTimeout;

    function loadEmployees(search = '', department = '', supervisor = '', operationManager = '', status = '', quickFilter = '', page = 1) {
        const formData = new FormData();
        formData.append('search', search); formData.append('department', department);
        formData.append('supervisor', supervisor); formData.append('operation_manager', operationManager);
        formData.append('status', status); formData.append('quick_filter', quickFilter);
        formData.append('page', page);

        const selectedEmployees = Array.from(document.querySelectorAll('.employee-checkbox:checked')).map(cb => cb.value);
        formData.append('selected_employees_json', JSON.stringify(selectedEmployees));

        fetch('partials/employees_table.php', { method: 'POST', body: formData })
        .then(response => response.text())
        .then(data => {
            document.getElementById('employeesTableContainer').innerHTML = data;
            
            document.querySelectorAll('.employee-checkbox').forEach(cb => cb.addEventListener('change', toggleEditTeamButton));
            
            if (selectedEmployees.length > 0) {
                selectedEmployees.forEach(id => {
                    const cb = document.querySelector(`.employee-checkbox[value="${id}"]`);
                    if (cb) cb.checked = true;
                });
                toggleEditTeamButton();
            }
            
            document.querySelectorAll('.pagination-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const page = this.getAttribute('data-page');
                    loadEmployees(searchInput.value, document.getElementById('departmentFilter').value, 
                                  document.getElementById('supervisorFilter').value, document.getElementById('operationManagerFilter').value, 
                                  document.getElementById('statusFilter').value, currentQuickFilter, page);
                    updateUrl(searchInput.value, document.getElementById('departmentFilter').value, 
                              document.getElementById('supervisorFilter').value, document.getElementById('operationManagerFilter').value, 
                              document.getElementById('statusFilter').value, currentQuickFilter, page);
                });
            });
        });
    }

    function updateUrl(search, department, supervisor, operationManager, status, quickFilter, page) {
        const params = new URLSearchParams();
        if (search) params.append('search', search); if (department) params.append('department', department);
        if (supervisor) params.append('supervisor', supervisor); if (operationManager) params.append('operation_manager', operationManager);
        if (status) params.append('status', status); if (quickFilter) params.append('quick_filter', quickFilter);
        if (page > 1) params.append('page', page);
        history.pushState(null, '', `?${params.toString()}`);
    }

    function handleFilterChange() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            loadEmployees(searchInput.value, document.getElementById('departmentFilter').value, 
                          document.getElementById('supervisorFilter').value, document.getElementById('operationManagerFilter').value, 
                          document.getElementById('statusFilter').value, currentQuickFilter, 1);
            updateUrl(searchInput.value, document.getElementById('departmentFilter').value, 
                      document.getElementById('supervisorFilter').value, document.getElementById('operationManagerFilter').value, 
                      document.getElementById('statusFilter').value, currentQuickFilter, 1);
        }, 300);
    }

    searchInput.addEventListener('input', handleFilterChange);
    filters.forEach(id => document.getElementById(id).addEventListener('change', handleFilterChange));

    // Clear Filters Functionality
    document.getElementById('clearFiltersBtn').addEventListener('click', function() {
        searchInput.value = '';
        document.getElementById('departmentFilter').value = '';
        document.getElementById('supervisorFilter').value = '';
        document.getElementById('operationManagerFilter').value = '';
        document.getElementById('statusFilter').value = '1'; // Back to active
        currentQuickFilter = '';
        document.querySelectorAll('.insight-card').forEach(c => c.classList.remove('ring-4', 'ring-blue-500', 'border-blue-500'));
        handleFilterChange();
    });

    // Initialize with URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('search')) searchInput.value = urlParams.get('search');
    if (urlParams.get('department')) document.getElementById('departmentFilter').value = urlParams.get('department');
    if (urlParams.get('supervisor')) document.getElementById('supervisorFilter').value = urlParams.get('supervisor');
    if (urlParams.get('operation_manager')) document.getElementById('operationManagerFilter').value = urlParams.get('operation_manager');
    
    // Default to '1' if status is missing in URL
    document.getElementById('statusFilter').value = urlParams.get('status') !== null ? urlParams.get('status') : '1';
    
    currentQuickFilter = urlParams.get('quick_filter') || '';
    if (currentQuickFilter) {
        const activeCard = document.getElementById('card-' + currentQuickFilter);
        if (activeCard) activeCard.classList.add('ring-4', 'ring-blue-500', 'border-blue-500');
    }
    
    loadEmployees(searchInput.value, document.getElementById('departmentFilter').value, 
                  document.getElementById('supervisorFilter').value, document.getElementById('operationManagerFilter').value, 
                  document.getElementById('statusFilter').value, currentQuickFilter, urlParams.get('page') || 1);
});

// Helper for Deletions
function showDeleteModal(recordId) {
    const modal = `
        <div id="deleteModal" class="fixed inset-0 bg-gray-900/70 backdrop-blur-sm flex items-center justify-center z-[60] transition-opacity">
            <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-2xl w-full max-w-md p-6">
                <div class="flex items-center text-red-500 mb-4"><i class="fas fa-exclamation-triangle text-2xl mr-3"></i><h3 class="text-xl font-bold text-gray-100">Confirm Deletion</h3></div>
                <p class="text-gray-300 mb-6 bg-gray-900/50 p-3 rounded-lg border border-gray-700">Are you sure you want to delete this record?</p>
                <form method="post" action="${window.location.pathname}?delete=${recordId}" class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Admin KEY:</label>
                        <input type="password" name="delete_password" class="w-full px-4 py-2 bg-gray-900 border border-gray-600 focus:ring-2 focus:ring-red-500 rounded-lg text-gray-200" required>
                    </div>
                    <div class="flex justify-end space-x-3 pt-2">
                        <button type="button" onclick="closeDeleteModal()" class="px-5 py-2.5 bg-gray-700 text-gray-200 rounded-lg hover:bg-gray-600 font-medium">Cancel</button>
                        <button type="submit" class="px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 flex items-center shadow font-medium"><i class="fas fa-trash-alt mr-2"></i> Delete</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modal);
}
function closeDeleteModal() { const m = document.getElementById('deleteModal'); if(m) m.remove(); }

// Global click/key handlers for Modals
document.addEventListener('click', e => {
    if (e.target === document.getElementById('deleteModal')) closeDeleteModal();
    if (e.target === document.getElementById('editTeamModal')) closeEditTeamModal();
    if (e.target === document.getElementById('individualEditModal')) closeIndividualModal();
    if (e.target === document.getElementById('importModal')) closeImportModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (document.getElementById('deleteModal')) closeDeleteModal();
        if (!document.getElementById('editTeamModal').classList.contains('hidden')) closeEditTeamModal();
        if (!document.getElementById('individualEditModal').classList.contains('hidden')) closeIndividualModal();
        if (!document.getElementById('importModal').classList.contains('hidden')) closeImportModal();
    }
});
</script>
<?php renderFooter(); ?>