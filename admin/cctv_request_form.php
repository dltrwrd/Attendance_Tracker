<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$record = null;

if ($id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM cctv_request WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        
        if (!$record) {
            $_SESSION['error'] = "CCTV request not found";
            redirect('cctv_request.php');
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error loading record: " . $e->getMessage();
        redirect('cctv_request.php');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Set timezone sa PHP
        date_default_timezone_set('Asia/Manila');

        $data = [
            'employee_id' => $_POST['employee_id'],
            'full_name' => $_POST['full_name'],
            'department' => $_POST['department'],
            'supervisor' => $_POST['supervisor'],
            'operation_manager' => $_POST['operation_manager'],
            'email' => $_POST['email'],
            'reason' => $_POST['reason'],
            'created_at' => date('Y-m-d H:i:s')
        ];

        if ($id > 0) {
            // Update existing record
            $sql = "UPDATE cctv_request SET 
                    employee_id = :employee_id,
                    full_name = :full_name,
                    department = :department,
                    supervisor = :supervisor,
                    operation_manager = :operation_manager,
                    email = :email,
                    reason = :reason
                    WHERE id = :id";
            
            $data['id'] = $id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);
            
            $_SESSION['success'] = "CCTV request updated successfully!";
        } else {
            // Insert new record
            $sql = "INSERT INTO cctv_request 
                    (employee_id, full_name, department, supervisor, operation_manager, 
                     email, reason, created_at) 
                    VALUES 
                    (:employee_id, :full_name, :department, :supervisor, :operation_manager,
                     :email, :reason, :created_at)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);
            
            $_SESSION['success'] = "CCTV request created successfully!";
        }
        
        redirect('cctv_request.php');
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error saving record: " . $e->getMessage();
        redirect('cctv_request.php');
    }
}

require_once '../components/layout.php';
renderHead('CCTV Request Form');
renderNavbar();
renderSidebar('cctv_request');
?>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">
                <?= $id > 0 ? 'Edit CCTV Request' : 'Create CCTV Request' ?>
            </h1>
            <a href="cctv_request.php" class="text-gray-400 hover:text-white">
                <i class="fas fa-times fa-lg"></i>
            </a>
        </div>

        <?php renderAlert(); ?>

        <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 shadow">
            <form method="POST">
                <div class="grid grid-cols-1 md:grid-cols-1 gap-6">
                    <!-- Employee Information -->
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 md:grid-cols-2 gap-6">
                            <div>
                                <label for="employee_id" class="block text-sm font-medium text-gray-300 mb-1">Employee ID</label>
                                <input type="text" id="employee_id" name="employee_id" required style="text-transform: uppercase;"
                                    value="<?= htmlspecialchars($record['employee_id'] ?? '') ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200" 
                                    onchange="fetchEmployeeDetails(this.value)"
                                    autocomplete="off">
                                <div id="employeeSearchResults" class="hidden absolute z-10 mt-1 w-full max-w-md bg-gray-800 border border-gray-700 rounded-lg shadow-lg"></div>
                            </div>

                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-300 mb-1">Full Name</label>
                                <input type="text" id="full_name" name="full_name" required readonly
                                    value="<?= htmlspecialchars($record['full_name'] ?? '') ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 md:grid-cols-2 gap-6">
                            <div>
                                <label for="department" class="block text-sm font-medium text-gray-300 mb-1">Department</label>
                                <input type="text" id="department" name="department" required readonly
                                    value="<?= htmlspecialchars($record['department'] ?? '') ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-300 mb-1">Email</label>
                                <input type="email" id="email" name="email" required readonly
                                    value="<?= htmlspecialchars($record['email'] ?? '') ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-2 gap-6">
                            <div>
                                <label for="supervisor" class="block text-sm font-medium text-gray-300 mb-1">Supervisor</label>
                                <input type="text" id="supervisor" name="supervisor" required readonly
                                    value="<?= htmlspecialchars($record['supervisor'] ?? '') ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>

                            <div>
                                <label for="operation_manager" class="block text-sm font-medium text-gray-300 mb-1">Operations Manager</label>
                                <input type="text" id="operation_manager" name="operation_manager" required readonly
                                    value="<?= htmlspecialchars($record['operation_manager'] ?? '') ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Full Width Fields -->
                <div class="space-y-4 mt-6">
                    <div>
                        <label for="reason" class="block text-sm font-medium text-gray-300 mb-1">Reason for CCTV Review</label>
                        <textarea id="reason" name="reason" required rows="5" placeholder="Please provide detailed reason for the CCTV review request..."
                                  class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200"><?= htmlspecialchars($record['reason'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="flex justify-end space-x-3 pt-4">
                    <a href="cctv_request.php" class="px-6 py-2 bg-gray-600 text-gray-100 rounded-md hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Cancel
                    </a>
                    <button type="submit" class="px-6 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <?= $id > 0 ? 'Update Request' : 'Create Request' ?>
                    </button>
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
</script>

<?php renderFooter(); ?>