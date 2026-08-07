<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- BULK UPDATE LOGIC ---
    if (isset($_POST['bulk_update']) && $_POST['bulk_update'] == '1') {
        $selectedIds = isset($_POST['selected_ids']) ? explode(',', $_POST['selected_ids']) : [];
        $selectedIds = array_filter(array_map('intval', $selectedIds));
        
        if (empty($selectedIds)) {
            $_SESSION['error'] = "No agents selected for update.";
            redirect('employees.php');
            exit;
        }

        $positionalParams = [];
        $setClauses = [];

        // Allowed fields mapping: Form Input Name => Database Column Name
        $fields = [
            'department' => 'department',
            'supervisor' => 'supervisor',
            'operation_manager' => 'operation_manager',
            'cxi_id' => 'employee_id',
            'email' => 'email'
        ];

        foreach ($fields as $postKey => $dbColumn) {
            if (!empty(trim($_POST[$postKey]))) {
                $setClauses[] = "$dbColumn = ?";
                $positionalParams[] = sanitizeInput($_POST[$postKey]);
            }
        }

        if (isset($_POST['status_update']) && $_POST['status_update'] !== 'no_change') {
            $setClauses[] = "is_active = ?";
            $positionalParams[] = ($_POST['status_update'] === 'active') ? 1 : 0;
        }

        if (!empty($setClauses)) {
            try {
                $pdo->beginTransaction();
                
                // Create placeholders for the IN clause (?, ?, ?)
                $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
                
                // Append IDs to parameters array for the WHERE IN clause
                $positionalParams = array_merge($positionalParams, $selectedIds);
                
                $sql = "UPDATE employees SET " . implode(', ', $setClauses) . 
                       " WHERE id IN ($placeholders)";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($positionalParams);
                
                $pdo->commit();
                $_SESSION['success'] = "Successfully updated " . count($selectedIds) . " agents!";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['error'] = "Error during bulk update. If you updated CXI or Email, ensure they remain unique to avoid conflicts.";
            }
        } else {
            $_SESSION['error'] = "No fields were filled out for the bulk update.";
        }
        
        redirect('employees.php');
        exit;
    }
    
    // --- SINGLE RECORD LOGIC (Existing) ---
    $employeeId = (int)$_POST['employee_id'];
    $employeeData = [
        'employee_id' => sanitizeInput($_POST['cxi_id']),
        'full_name' => sanitizeInput($_POST['full_name']),
        'department' => sanitizeInput($_POST['department']),
        'supervisor' => sanitizeInput($_POST['supervisor']),
        'operation_manager' => sanitizeInput($_POST['operation_manager']),
        'email' => sanitizeInput($_POST['email']),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    try {
        $pdo->beginTransaction();

        if ($employeeId > 0) {
            // Update existing employee
            $stmt = $pdo->prepare("UPDATE employees SET 
                employee_id = :employee_id,
                full_name = :full_name,
                department = :department,
                supervisor = :supervisor,
                operation_manager = :operation_manager,
                email = :email,
                is_active = :is_active
                WHERE id = :id");
                
            $employeeData['id'] = $employeeId;
            $stmt->execute($employeeData);
            
            $_SESSION['success'] = "Agent updated successfully!";
        } else {
            // Add new employee
            $stmt = $pdo->prepare("INSERT INTO employees 
                (employee_id, full_name, department, supervisor, operation_manager, email, is_active) 
                VALUES 
                (:employee_id, :full_name, :department, :supervisor, :operation_manager, :email, :is_active)");
                
            $stmt->execute($employeeData);
            
            $_SESSION['success'] = "Agent added successfully!";
        }
        
        $pdo->commit();
        redirect('employees.php');
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Agent already exists!";
        redirect($employeeId ? 'employee.php?id=' . $employeeId : 'employee.php?action=create');
        exit;
    }
}

// Get employee data
$employee = null;
if ($employeeId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        
        if (!$employee) {
            $_SESSION['error'] = "Employee not found";
            redirect('employees.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching employee data: " . $e->getMessage();
        redirect('employees.php');
        exit;
    }
} elseif ($action !== 'create') {
    redirect('employees.php');
    exit;
}

require_once '../components/layout.php';
renderHead($employeeId ? 'Edit Employee' : 'Add Employee');
renderNavbar();
renderSidebar('employees');
?>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold"><?= $employeeId ? 'Edit Agent' : 'Add New Agent' ?></h1>
            <a href="employees.php" class="text-gray-400 hover:text-white transition-colors">
                <i class="fas fa-times fa-lg"></i>
            </a>
        </div>

        <?php renderAlert(); ?>

        <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 shadow">
            <form action="employee.php<?= $employeeId ? '?id='.$employeeId : '?action=create' ?>" method="POST">
                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="cxi_id" class="block text-sm font-medium text-gray-300 mb-2">CXI Number</label>
                        <input type="text" id="cxi_id" name="cxi_id"
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 focus:ring-primary-500 focus:border-primary-500" 
                               value="<?= $employee ? htmlspecialchars($employee['employee_id']) : '' ?>" required>
                    </div>
                    
                    <div>
                        <label for="full_name" class="block text-sm font-medium text-gray-300 mb-2">Full Name</label>
                        <input type="text" id="full_name" name="full_name" style="text-transform: uppercase;"
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 focus:ring-primary-500 focus:border-primary-500" 
                               value="<?= $employee ? htmlspecialchars($employee['full_name']) : '' ?>" required>
                    </div>
                    
                    <div>
                        <label for="department" class="block text-sm font-medium text-gray-300 mb-2">Department</label>
                        <input type="text" id="department" name="department" style="text-transform: uppercase;"
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 focus:ring-primary-500 focus:border-primary-500" 
                               value="<?= $employee ? htmlspecialchars($employee['department']) : '' ?>" required>
                    </div>
                    
                    <div>
                        <label for="supervisor" class="block text-sm font-medium text-gray-300 mb-2">Supervisor</label>
                        <input type="text" id="supervisor" name="supervisor" style="text-transform: uppercase;"
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 focus:ring-primary-500 focus:border-primary-500" 
                               value="<?= $employee ? htmlspecialchars($employee['supervisor']) : '' ?>" required>
                    </div>
                    
                    <div>
                        <label for="operation_manager" class="block text-sm font-medium text-gray-300 mb-2">Operations Manager</label>
                        <input type="text" id="operation_manager" name="operation_manager" style="text-transform: uppercase;"
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 focus:ring-primary-500 focus:border-primary-500" 
                               value="<?= $employee ? htmlspecialchars($employee['operation_manager']) : '' ?>" required>
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-300 mb-2">Email</label>
                        <input type="email" id="email" name="email"
                               class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 focus:ring-primary-500 focus:border-primary-500" 
                               value="<?= $employee ? htmlspecialchars($employee['email']) : '' ?>">
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" id="is_active" name="is_active" 
                               class="w-4 h-4 text-primary-600 bg-gray-700 border-gray-600 rounded focus:ring-primary-500 focus:ring-2"
                               <?= ($employee && $employee['is_active']) || !$employee ? 'checked' : '' ?>>
                        <label for="is_active" class="ml-2 text-sm font-medium text-gray-300">Active</label>
                    </div>
                </div>
                
                <div class="flex space-x-3 pt-6 mt-6 border-t border-gray-700">
                    <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg flex items-center transition-colors">
                        <i class="fas fa-save mr-2"></i> Save
                    </button>
                    <a href="employees.php" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg flex items-center transition-colors">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </main>
</div>

<?php renderFooter(); ?>