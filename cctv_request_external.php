<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$record = null;

if ($id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM cctv_request WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        
        if (!$record) {
            $error = "CCTV request not found";
        }
    } catch (PDOException $e) {
        $error = "Error loading record: " . $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check if this is a duplicate submission
        if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 5) {
            $error = "Please wait a few seconds before submitting again.";
        } else {

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
                
                $success = "CCTV request updated successfully!";
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
                
                $success = "CCTV request submitted successfully! Our team will review your request shortly.";
            }
            
            // Set last submission timestamp to prevent duplicates
            $_SESSION['last_submission'] = time();
            
            // Redirect to prevent form resubmission on refresh
            if ($id > 0) {
                header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $id . "&success=1");
            } else {
                header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
            }
            exit();
        }
        
    } catch (PDOException $e) {
        $error = "Error saving record: " . $e->getMessage();
    }
}

// Check for success parameter from redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = $id > 0 ? "CCTV request updated successfully!" : "CCTV request submitted successfully! Our team will review your request shortly.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id > 0 ? 'Edit CCTV Request' : 'CCTV Review Request Form' ?></title>
    <link rel="icon" href="assets/cxiico.png" type="image/png">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background-color: #1f2937;
            color: #f9fafb;
            font-family: 'Inter', sans-serif;
        }
        .form-submitted {
            pointer-events: none;
            opacity: 0.7;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-4xl">
        <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-lg">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-700">
                <div>
                    <h1 class="text-2xl font-bold text-white">
                        <?= $id > 0 ? 'Edit CCTV Request' : 'CCTV Review Request Form' ?>
                    </h1>
                    <p class="text-gray-400 mt-1">Submit a request for CCTV footage review</p>
                </div>
                <?php if(isset($_GET['embed']) && $_GET['embed'] === 'true'): ?>
                <button onclick="window.close()" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times fa-lg"></i>
                </button>
                <?php endif; ?>
            </div>

            <!-- Alert Messages -->
            <?php if(isset($success)): ?>
                <div class="mb-4 p-4 bg-green-900/50 border border-green-700 text-green-200 rounded-md">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($error)): ?>
                <div class="mb-4 p-4 bg-red-900/50 border border-red-700 text-red-200 rounded-md">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" id="cctvForm">
                <div class="space-y-6">
                    <!-- Employee Information -->
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="employee_id" class="block text-sm font-medium text-gray-300 mb-1">Your Employee ID *</label>
                                <input type="text" id="employee_id" name="employee_id" required style="text-transform: uppercase;"
                                    value="<?= htmlspecialchars($record['employee_id'] ?? ($_POST['employee_id'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500" 
                                    onchange="fetchEmployeeDetails(this.value)"
                                    autocomplete="off">
                                <div id="employeeSearchResults" class="hidden absolute z-10 mt-1 w-full max-w-md bg-gray-800 border border-gray-700 rounded-lg shadow-lg"></div>
                            </div>

                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-300 mb-1">Your Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required readonly
                                    value="<?= htmlspecialchars($record['full_name'] ?? ($_POST['full_name'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="department" class="block text-sm font-medium text-gray-300 mb-1">Your Department *</label>
                                <input type="text" id="department" name="department" required readonly
                                    value="<?= htmlspecialchars($record['department'] ?? ($_POST['department'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-300 mb-1">Your Email *</label>
                                <input type="email" id="email" name="email" required readonly
                                    value="<?= htmlspecialchars($record['email'] ?? ($_POST['email'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="supervisor" class="block text-sm font-medium text-gray-300 mb-1">Your Supervisor *</label>
                                <input type="text" id="supervisor" name="supervisor" required readonly
                                    value="<?= htmlspecialchars($record['supervisor'] ?? ($_POST['supervisor'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>

                            <div>
                                <label for="operation_manager" class="block text-sm font-medium text-gray-300 mb-1">Operations Manager *</label>
                                <input type="text" id="operation_manager" name="operation_manager" required readonly
                                    value="<?= htmlspecialchars($record['operation_manager'] ?? ($_POST['operation_manager'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>
                        </div>
                    </div>

                    <!-- Full Width Fields -->
                    <div class="space-y-4">
                        <div>
                            <label for="reason" class="block text-sm font-medium text-gray-300 mb-1">Reason for CCTV Review *</label>
                            <textarea id="reason" name="reason" required rows="5" placeholder="Please provide detailed reason for the CCTV review request. Include specific dates, times, locations, and the purpose of your request..."
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    ><?= htmlspecialchars($record['reason'] ?? ($_POST['reason'] ?? '')) ?></textarea>
                            <p class="text-gray-400 text-sm mt-1">Please be specific about what you need reviewed and why.</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3 pt-6 mt-6 border-t border-gray-700">
                    <?php if($id > 0): ?>
                        <a href="?id=<?= $id ?>" class="px-6 py-2 bg-gray-600 text-gray-100 rounded-md hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500 transition">
                            Reset
                        </a>
                    <?php else: ?>
                        <button type="reset" class="px-6 py-2 bg-gray-600 text-gray-100 rounded-md hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500 transition">
                            Clear Form
                        </button>
                    <?php endif; ?>
                    <button type="submit" id="submitBtn" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                        <?= $id > 0 ? 'Update Request' : 'Submit Request' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Prevent double form submission
    document.getElementById('cctvForm').addEventListener('submit', function(e) {
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<?= $id > 0 ? 'Updating...' : 'Submitting...' ?>';
        submitBtn.classList.add('form-submitted');
    });

    function searchEmployees(query) {
        if (query.length < 2) {
            document.getElementById('employeeSearchResults').classList.add('hidden');
            return;
        }

        fetch('api/search_employees.php?query=' + encodeURIComponent(query))
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
        
        fetch('api/get_employee.php?employee_id=' + encodeURIComponent(employeeId))
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
    </script>
</body>
</html>