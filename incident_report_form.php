<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$record = null;

if ($id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM incident_report WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        
        if (!$record) {
            $error = "Incident report not found";
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
                'email_address' => $_POST['email_address'] ?? '',
                'employee_id' => $_POST['employee_id'],
                'full_name' => $_POST['full_name'],
                'department' => $_POST['department'],
                'operation_manager' => $_POST['operation_manager'],
                'infraction' => $_POST['infraction'],
                'reported_by' => $_POST['reported_by'],
                'position' => $_POST['position'],
                'date_of_incident' => $_POST['date_of_incident'],
                'shift' => $_POST['shift'],
                'incident_details' => $_POST['incident_details'],
                'evidence' => $_POST['evidence'],
                'created_at' => date('Y-m-d H:i:s')
            ];

            if ($id > 0) {
                // Update existing record
                $sql = "UPDATE incident_report SET 
                        email_address = :email_address,
                        employee_id = :employee_id,
                        full_name = :full_name,
                        department = :department,
                        operation_manager = :operation_manager,
                        infraction = :infraction,
                        reported_by = :reported_by,
                        position = :position,
                        date_of_incident = :date_of_incident,
                        shift = :shift,
                        incident_details = :incident_details,
                        evidence = :evidence,
                        WHERE id = :id";
                
                $data['id'] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($data);
                
                $success = "Incident report updated successfully!";
            } else {
                // Insert new record
                $sql = "INSERT INTO incident_report 
                        (email_address, employee_id, full_name, department, operation_manager, 
                         infraction, reported_by, position, date_of_incident, shift, 
                         incident_details, evidence, created_at) 
                        VALUES 
                        (:email_address, :employee_id, :full_name, :department, :operation_manager,
                         :infraction, :reported_by, :position, :date_of_incident, :shift,
                         :incident_details, :evidence, :created_at)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($data);
                
                $success = "Incident report created successfully!";
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
    $success = $id > 0 ? "Incident report updated successfully!" : "Incident report created successfully!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id > 0 ? 'Edit Incident Report' : 'Create Incident Report' ?></title>
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
                <h1 class="text-2xl font-bold text-white">
                    <?= $id > 0 ? 'Edit Incident Report' : 'Create Incident Report' ?>
                </h1>
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
            <form method="POST" id="incidentForm">
                <div class="space-y-6">
                    <!-- Employee Information -->
                    <div class="space-y-4">
                        <div>
                            <label for="email_address" class="block text-sm font-medium text-gray-300 mb-1">Email Address</label>
                            <input type="email" id="email_address" name="email_address"
                                value="<?= htmlspecialchars($record['email_address'] ?? ($_POST['email_address'] ?? '')) ?>"
                                class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                placeholder="your.email@company.com">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            
                            <div>
                                <label for="employee_id" class="block text-sm font-medium text-gray-300 mb-1">Employee ID * (Person you are reporting)</label>
                                <input type="text" id="employee_id" name="employee_id" required style="text-transform: uppercase;"
                                    value="<?= htmlspecialchars($record['employee_id'] ?? ($_POST['employee_id'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500" 
                                    onchange="fetchEmployeeDetails(this.value)"
                                    autocomplete="off">
                                <div id="employeeSearchResults" class="hidden absolute z-10 mt-1 w-full max-w-md bg-gray-800 border border-gray-700 rounded-lg shadow-lg"></div>
                            </div>

                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-300 mb-1">Full Name * (Person you are reporting)</label>
                                <input type="text" id="full_name" name="full_name" required readonly
                                    value="<?= htmlspecialchars($record['full_name'] ?? ($_POST['full_name'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="department" class="block text-sm font-medium text-gray-300 mb-1">Department *</label>
                                <input type="text" id="department" name="department" required readonly
                                    value="<?= htmlspecialchars($record['department'] ?? ($_POST['department'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>

                            <div>
                                <label for="operation_manager" class="block text-sm font-medium text-gray-300 mb-1">Operations Manager *</label>
                                <input type="text" id="operation_manager" name="operation_manager" required readonly
                                    value="<?= htmlspecialchars($record['operation_manager'] ?? ($_POST['operation_manager'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-400">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="infraction" class="block text-sm font-medium text-gray-300 mb-1">Infraction *</label>
                                <select id="infraction" name="infraction" required
                                        class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                    <option value="">Select Infractions</option>
                                    <option value="ATTENDANCE - Tardiness" <?= ($record['infraction'] ?? ($_POST['infraction'] ?? '')) === 'ATTENDANCE - Tardiness' ? 'selected' : '' ?>>ATTENDANCE - Tardiness</option>
                                    <option value="ATTENDANCE - Absences WITH Notification" <?= ($record['infraction'] ?? ($_POST['infraction'] ?? '')) === 'ATTENDANCE - Absences WITH Notification' ? 'selected' : '' ?>>ATTENDANCE - Absences WITH Notification</option>
                                    <option value="ATTENDANCE - Absences WITHOUT Notification" <?= ($record['infraction'] ?? ($_POST['infraction'] ?? '')) === 'ATTENDANCE - Absences WITHOUT Notification' ? 'selected' : '' ?>>ATTENDANCE - Absences WITHOUT Notification</option>
                                    <option value="OTHER HANDBOOK VIOLATION" <?= ($record['infraction'] ?? ($_POST['infraction'] ?? '')) === 'OTHER HANDBOOK VIOLATION' ? 'selected' : '' ?>>OTHER HANDBOOK VIOLATION</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="date_of_incident" class="block text-sm font-medium text-gray-300 mb-1">Date of Incident *</label>
                                <input type="date" id="date_of_incident" name="date_of_incident" required
                                    value="<?= htmlspecialchars($record['date_of_incident'] ?? ($_POST['date_of_incident'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="shift" class="block text-sm font-medium text-gray-300 mb-1">Shift *</label>
                                <input type="text" id="shift" name="shift" required
                                    value="<?= htmlspecialchars($record['shift'] ?? ($_POST['shift'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    placeholder="e.g., Schedule">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="reported_by" class="block text-sm font-medium text-gray-300 mb-1">Reported By *</label>
                                <input type="text" id="reported_by" name="reported_by" required
                                    value="<?= htmlspecialchars($record['reported_by'] ?? ($_POST['reported_by'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    placeholder="Your name">
                            </div>

                            <div>
                                <label for="position" class="block text-sm font-medium text-gray-300 mb-1">Position *</label>
                                <input type="text" id="position" name="position" required
                                    value="<?= htmlspecialchars($record['position'] ?? ($_POST['position'] ?? '')) ?>"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    placeholder="Your position">
                            </div>
                        </div>
                    </div>

                    <!-- Full Width Fields -->
                    <div class="space-y-4">
                        <div>
                            <label for="incident_details" class="block text-sm font-medium text-gray-300 mb-1">Incident Details *</label>
                            <textarea id="incident_details" name="incident_details" required rows="5"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    placeholder="Provide detailed description of the incident..."><?= htmlspecialchars($record['incident_details'] ?? ($_POST['incident_details'] ?? '')) ?></textarea>
                        </div>

                        <div>
                            <label for="evidence" class="block text-sm font-medium text-gray-300 mb-1">Evidence (URLs, file paths, or descriptions) note: Make sure to give an access to the files or links provided e.g., Send anyone with the link.</label>
                            <textarea id="evidence" name="evidence" rows="3"
                                    class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-md text-gray-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    placeholder="Any supporting evidence..."><?= htmlspecialchars($record['evidence'] ?? ($_POST['evidence'] ?? '')) ?></textarea>
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
                        <?= $id > 0 ? 'Update Report' : 'Submit Report' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Prevent double form submission
    document.getElementById('incidentForm').addEventListener('submit', function(e) {
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
                    document.getElementById('operation_manager').value = data.employee.operation_manager;
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
    }
    </script>
</body>
</html>