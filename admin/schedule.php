<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

// Handle AJAX request for getting departments by operation manager
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_departments_by_manager'])) {
    header('Content-Type: application/json');
    
    $operationManager = $_POST['operation_manager'] ?? '';
    
    try {
        if (empty($operationManager)) {
            // Return all departments
            $stmt = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
            $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Return departments for specific operation manager
            $stmt = $pdo->prepare("SELECT DISTINCT department FROM employees WHERE operation_manager = ? AND department IS NOT NULL AND department != '' ORDER BY department");
            $stmt->execute([$operationManager]);
            $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        echo json_encode(['success' => true, 'departments' => $departments]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for getting supervisors by operation manager
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_supervisors_by_manager'])) {
    header('Content-Type: application/json');
    
    $operationManager = $_POST['operation_manager'] ?? '';
    
    try {
        if (empty($operationManager)) {
            // Return all supervisors
            $stmt = $pdo->query("SELECT DISTINCT supervisor FROM employees WHERE supervisor IS NOT NULL AND supervisor != '' ORDER BY supervisor");
            $supervisors = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Return supervisors for specific operation manager
            $stmt = $pdo->prepare("SELECT DISTINCT supervisor FROM employees WHERE operation_manager = ? AND supervisor IS NOT NULL AND supervisor != '' ORDER BY supervisor");
            $stmt->execute([$operationManager]);
            $supervisors = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        echo json_encode(['success' => true, 'supervisors' => $supervisors]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for getting departments by operation manager (for main filters)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_filter_departments_by_manager'])) {
    header('Content-Type: application/json');
    
    $operationManager = $_POST['operation_manager'] ?? '';
    
    try {
        if (empty($operationManager)) {
            // Return all departments
            $stmt = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
            $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Return departments for specific operation manager
            $stmt = $pdo->prepare("SELECT DISTINCT department FROM employees WHERE operation_manager = ? AND department IS NOT NULL AND department != '' ORDER BY department");
            $stmt->execute([$operationManager]);
            $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        echo json_encode(['success' => true, 'departments' => $departments]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle inline updates via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    header('Content-Type: application/json');
    
    $scheduleId = (int)$_POST['schedule_id'];
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';
    $style = $_POST['style'] ?? '';
    
    // Validate field to prevent SQL injection
    $allowedFields = ['site', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    if (!in_array($field, $allowedFields)) {
        echo json_encode(['success' => false, 'message' => 'Invalid field']);
        exit;
    }
    
    try {
        // Update the specific field and style
        $styleField = $field . '_style';
        $stmt = $pdo->prepare("UPDATE schedule SET $field = ?, $styleField = ? WHERE id = ?");
        $stmt->execute([$value, $style, $scheduleId]);
        
        // Recalculate total hours
        $stmt = $pdo->prepare("SELECT monday, tuesday, wednesday, thursday, friday, saturday, sunday FROM schedule WHERE id = ?");
        $stmt->execute([$scheduleId]);
        $schedule = $stmt->fetch();
        
        $totalHours = 0;
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            if (!empty($schedule[$day])) {
                if (strpos($schedule[$day], '-') !== false) {
                    $totalHours += 8;
                } elseif (is_numeric($schedule[$day])) {
                    $totalHours += (float)$schedule[$day];
                } else {
                    $totalHours += 8;
                }
            }
        }
        
        // Update total hours
        $stmt = $pdo->prepare("UPDATE schedule SET total_sched = ? WHERE id = ?");
        $stmt->execute([$totalHours, $scheduleId]);
        
        echo json_encode([
            'success' => true, 
            'total_hours' => $totalHours
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle style updates via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_style'])) {
    header('Content-Type: application/json');
    
    $scheduleId = (int)$_POST['schedule_id'];
    $field = $_POST['field'] ?? '';
    $style = $_POST['style'] ?? '';
    
    // Validate field to prevent SQL injection
    $allowedFields = ['site', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    if (!in_array($field, $allowedFields)) {
        echo json_encode(['success' => false, 'message' => 'Invalid field']);
        exit;
    }
    
    try {
        // Update the style
        $styleField = $field . '_style';
        $stmt = $pdo->prepare("UPDATE schedule SET $styleField = ? WHERE id = ?");
        $stmt->execute([$style, $scheduleId]);
        
        echo json_encode(['success' => true, 'message' => 'Style updated']);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle notes updates via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notes'])) {
    header('Content-Type: application/json');
    
    $scheduleId = (int)$_POST['schedule_id'];
    $field = $_POST['field'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    // Validate field to prevent SQL injection
    $allowedFields = ['site', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    if (!in_array($field, $allowedFields)) {
        echo json_encode(['success' => false, 'message' => 'Invalid field']);
        exit;
    }
    
    try {
        // Update the notes
        $notesField = $field . '_notes';
        $stmt = $pdo->prepare("UPDATE schedule SET $notesField = ? WHERE id = ?");
        $stmt->execute([$notes, $scheduleId]);
        
        echo json_encode(['success' => true, 'message' => 'Notes updated']);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

require_once '../components/layout.php';
renderHead('Manage Schedules');
renderNavbar();
renderSidebar('schedules');

// Handle bulk schedule creation for department
if (isset($_POST['create_department_schedule'])) {
    $selectedDepartments = $_POST['departments'] ?? [];
    $weekBeginning = $_POST['week_beginning'] ?? '';
    
    if (empty($selectedDepartments) || empty($weekBeginning)) {
        $_SESSION['error'] = "Please select at least one department and week beginning date.";
        redirect('schedule.php');
    }
    
    try {
        $pdo->beginTransaction();
        
        $createdCount = 0;
        $scheduleStmt = $pdo->prepare("INSERT INTO schedule 
            (employee_id, fullname, department, supervisor, operation_manager, site, total_sched, 
             monday, tuesday, wednesday, thursday, friday, saturday, sunday, week_beginning) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($selectedDepartments) - 1) . '?';
        
        // Get active employees from selected departments
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE department IN ($placeholders) AND is_active = 1");
        $stmt->execute($selectedDepartments);
        $employees = $stmt->fetchAll();
        
        if (empty($employees)) {
            $_SESSION['error'] = "No active employees found in the selected departments.";
            redirect('schedule.php');
        }
        
        foreach ($employees as $employee) {
            try {
                // Create blank schedule with only basic info
                $scheduleData = [
                    $employee['employee_id'],
                    $employee['full_name'],
                    $employee['department'],
                    $employee['supervisor'],
                    $employee['operation_manager'],
                    '', // Blank site
                    0,  // Zero total hours initially
                    '', // Monday - blank
                    '', // Tuesday - blank
                    '', // Wednesday - blank
                    '', // Thursday - blank
                    '', // Friday - blank
                    '', // Saturday - blank
                    '', // Sunday - blank
                    $weekBeginning
                ];
                
                $scheduleStmt->execute($scheduleData);
                $createdCount++;
            } catch (PDOException $e) {
                // Skip if schedule already exists for this week
                if ($e->errorInfo[1] !== 1062) {
                    throw $e;
                }
            }
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Successfully created blank schedules for $createdCount employees across " . count($selectedDepartments) . " departments! You can now edit them below.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error creating schedules: " . $e->getMessage();
    }
    
    redirect('schedule.php');
}

// Handle schedule deletion
if (isset($_GET['delete'])) {
    $scheduleId = (int)$_GET['delete'];
    $requiredPassword = "SLT@2025";
    $providedPassword = $_POST['delete_password'] ?? '';
    
    if (empty($providedPassword) || $providedPassword !== $requiredPassword) {
        $_SESSION['error'] = "Incorrect or missing password for deletion";
        redirect('schedule.php');
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM schedule WHERE id = ?");
        $stmt->execute([$scheduleId]);
        $_SESSION['success'] = "Schedule deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting schedule: " . $e->getMessage();
    }
    
    redirect('schedule.php');
}

// Get unique values for filter dropdowns
try {
    $deptStmt = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
    $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $supervisorStmt = $pdo->query("SELECT DISTINCT supervisor FROM employees WHERE supervisor IS NOT NULL AND supervisor != '' ORDER BY supervisor");
    $supervisors = $supervisorStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $opManagerStmt = $pdo->query("SELECT DISTINCT operation_manager FROM employees WHERE operation_manager IS NOT NULL AND operation_manager != '' ORDER BY operation_manager");
    $operationManagers = $opManagerStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $departments = [];
    $supervisors = [];
    $operationManagers = [];
}
?>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Manage Schedules</h1>
            <button type="button" onclick="showCreateScheduleModal()" 
                    class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i> Create Department Schedule
            </button>
        </div>

        <?php renderAlert(); ?>
        
        <!-- Create Schedule Modal -->
        <div id="createScheduleModal" class="hidden fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50">
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold">Create Department Schedule</h3>
                        <button type="button" onclick="closeCreateScheduleModal()" class="text-gray-400 hover:text-white">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <form method="POST" id="createScheduleForm">
                        <div class="space-y-4">
                            <!-- Operation Manager Filter -->
                            <div>
                                <label for="operation_manager_filter" class="block text-sm font-medium text-gray-300 mb-1">Filter by Operation Manager</label>
                                <select id="operation_manager_filter" name="operation_manager_filter"
                                        class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-gray-200" style="text-transform: uppercase;">
                                    <option value="">All Operation Managers</option>
                                    <?php foreach ($operationManagers as $opManager): ?>
                                        <option value="<?= htmlspecialchars($opManager) ?>"><?= htmlspecialchars($opManager) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Multiple Department Selection -->
                            <div>
                                <label for="departments" class="block text-sm font-medium text-gray-300 mb-1">Departments *</label>
                                <select id="departments" name="departments[]" multiple required
                                        class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-gray-200 min-h-[120px]" style="text-transform: uppercase;">
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-400 mt-1">Hold Ctrl/Cmd to select multiple departments</p>
                                <p class="text-xs text-gray-400" id="selectedCountText">0 departments selected</p>
                            </div>
                            
                            <div>
                                <label for="week_beginning" class="block text-sm font-medium text-gray-300 mb-1">Week Beginning *</label>
                                <input type="week" id="week_beginning" name="week_beginning" required
                                       class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-gray-200">
                            </div>
                        </div>
                        
                        <div class="bg-blue-900/20 border border-blue-700 rounded-lg p-3 mt-4">
                            <p class="text-sm text-blue-300">
                                <i class="fas fa-info-circle mr-2"></i>
                                This will create blank schedules for all active employees in the selected departments.
                            </p>
                        </div>
                        
                        <div class="flex justify-end space-x-3 mt-6">
                            <button type="button" onclick="closeCreateScheduleModal()" 
                                    class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                                Cancel
                            </button>
                            <button type="submit" name="create_department_schedule" 
                                    class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg flex items-center">
                                <i class="fas fa-users mr-2"></i> Create Schedules
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Color Picker Modal -->
        <div id="colorPickerModal" class="hidden fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50">
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold">Cell Styling</h3>
                        <button type="button" onclick="closeColorPickerModal()" class="text-gray-400 hover:text-white">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Background Color</label>
                            <div class="grid grid-cols-6 gap-2" id="bgColorPalette">
                                <!-- Background colors will be populated by JavaScript -->
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Text Color</label>
                            <div class="grid grid-cols-6 gap-2" id="textColorPalette">
                                <!-- Text colors will be populated by JavaScript -->
                            </div>
                        </div>
                        
                        <div class="flex space-x-2">
                            <button type="button" onclick="applyCellStyle()" 
                                    class="flex-1 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg">
                                Apply
                            </button>
                            <button type="button" onclick="clearCellStyle()" 
                                    class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                                Clear Style
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes Modal -->
        <div id="notesModal" class="hidden fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50">
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold">Cell Notes</h3>
                        <button type="button" onclick="closeNotesModal()" class="text-gray-400 hover:text-white">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Notes</label>
                            <textarea id="notesTextarea" 
                                      class="w-full h-32 px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-gray-200 resize-none"
                                      placeholder="Add notes for this cell..."></textarea>
                        </div>
                        
                        <div class="flex justify-between items-center text-sm text-gray-400">
                            <span id="notesCellInfo"></span>
                            <span id="notesCharCount">0/500</span>
                        </div>
                    </div>
                    
                    <div class="flex space-x-2 mt-6">
                        <button type="button" onclick="saveNotes()" 
                                class="flex-1 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg">
                            Save Notes
                        </button>
                        <button type="button" onclick="clearNotes()" 
                                class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                            Clear Notes
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Multi-select Toolbar -->
        <div id="multiSelectToolbar" class="hidden fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-gray-800 border border-gray-700 rounded-lg shadow-lg p-3 z-40">
            <div class="flex items-center space-x-3">
                <span id="selectedCount" class="text-sm text-gray-300 font-medium">0 cells selected</span>
                <button onclick="copySelectedCells()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm flex items-center">
                    <i class="fas fa-copy mr-1"></i> Copy
                </button>
                <button onclick="pasteToSelectedCells()" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm flex items-center">
                    <i class="fas fa-paste mr-1"></i> Paste
                </button>
                <button onclick="showColorPickerForSelection()" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-1 rounded text-sm flex items-center">
                    <i class="fas fa-palette mr-1"></i> Style
                </button>
                <button onclick="showNotesForSelection()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-sm flex items-center">
                    <i class="fas fa-sticky-note mr-1"></i> Notes
                </button>
                <button onclick="clearSelection()" class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded text-sm flex items-center">
                    <i class="fas fa-times mr-1"></i> Clear
                </button>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="mb-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="relative flex-grow">
                <input type="text" id="searchInput" 
                       class="w-full pl-10 pr-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200" 
                       placeholder="Search by CXI number or name...">
                <div class="absolute left-3 top-2.5 text-gray-400">
                    <i class="fas fa-search"></i>
                </div>
            </div>
            <div class="relative">
                <select id="departmentFilter" 
                        class="w-full pl-4 pr-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 appearance-none" style="text-transform: uppercase;">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="absolute right-3 top-2.5 text-gray-400">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="relative">
                <select id="supervisorFilter" 
                        class="w-full pl-4 pr-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 appearance-none" style="text-transform: uppercase;">
                    <option value="">All Supervisors</option>
                    <?php foreach ($supervisors as $supervisor): ?>
                        <option value="<?= htmlspecialchars($supervisor) ?>"><?= htmlspecialchars($supervisor) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="absolute right-3 top-2.5 text-gray-400">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="relative">
                <select id="operationManagerFilter" 
                        class="w-full pl-4 pr-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 appearance-none" style="text-transform: uppercase;">
                    <option value="">All Operation Managers</option>
                    <?php foreach ($operationManagers as $opManager): ?>
                        <option value="<?= htmlspecialchars($opManager) ?>"><?= htmlspecialchars($opManager) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="absolute right-3 top-2.5 text-gray-400">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="relative">
                <input type="week" id="weekFilter" 
                       class="w-full pl-4 pr-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200">
            </div>
        </div>

        <div id="schedulesTableContainer">
            <?php include 'partials/schedules_table.php'; ?>
        </div>
    </main>
</div>

<style>
.editable-cell.selected {
    position: relative;
    z-index: 10;
}

.editable-cell.selected::after {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    border: 2px solid #3b82f6;
    border-radius: 4px;
    pointer-events: none;
}

.editable-cell.copy-source {
    position: relative;
}

.editable-cell.copy-source::before {
    content: '📋';
    position: absolute;
    top: -8px;
    right: -8px;
    font-size: 12px;
    background: #3b82f6;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 20;
}

.color-option {
    width: 30px;
    height: 30px;
    border-radius: 4px;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all 0.2s;
}

.color-option:hover {
    transform: scale(1.1);
    border-color: white;
}

.color-option.selected {
    border-color: #3b82f6;
    transform: scale(1.1);
}

/* Color styles for the palette */
.bg-red-500\/20 { background-color: rgba(239, 68, 68, 0.2); }
.bg-orange-500\/20 { background-color: rgba(249, 115, 22, 0.2); }
.bg-yellow-500\/20 { background-color: rgba(245, 158, 11, 0.2); }
.bg-green-500\/20 { background-color: rgba(16, 185, 129, 0.2); }
.bg-blue-500\/20 { background-color: rgba(59, 130, 246, 0.2); }
.bg-purple-500\/20 { background-color: rgba(139, 92, 246, 0.2); }
.bg-pink-500\/20 { background-color: rgba(236, 72, 153, 0.2); }
.bg-indigo-500\/20 { background-color: rgba(99, 102, 241, 0.2); }
.bg-teal-500\/20 { background-color: rgba(20, 184, 166, 0.2); }
.bg-cyan-500\/20 { background-color: rgba(6, 182, 212, 0.2); }
.bg-gray-500\/20 { background-color: rgba(107, 114, 128, 0.2); }

.border-red-500\/30 { border-color: rgba(239, 68, 68, 0.3); }
.border-orange-500\/30 { border-color: rgba(249, 115, 22, 0.3); }
.border-yellow-500\/30 { border-color: rgba(245, 158, 11, 0.3); }
.border-green-500\/30 { border-color: rgba(16, 185, 129, 0.3); }
.border-blue-500\/30 { border-color: rgba(59, 130, 246, 0.3); }
.border-purple-500\/30 { border-color: rgba(139, 92, 246, 0.3); }
.border-pink-500\/30 { border-color: rgba(236, 72, 153, 0.3); }
.border-indigo-500\/30 { border-color: rgba(99, 102, 241, 0.3); }
.border-teal-500\/30 { border-color: rgba(20, 184, 166, 0.3); }
.border-cyan-500\/30 { border-color: rgba(6, 182, 212, 0.3); }
.border-gray-500\/30 { border-color: rgba(107, 114, 128, 0.3); }

/* Notes styles */
.editable-cell.has-notes {
    position: relative;
}

.editable-cell.has-notes::after {
    content: '📝';
    position: absolute;
    top: -5px;
    right: -5px;
    font-size: 10px;
    background: #8b5cf6;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 5;
}

.notes-tooltip {
    position: absolute;
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 6px;
    padding: 8px 12px;
    color: #f3f4f6;
    font-size: 12px;
    max-width: 250px;
    z-index: 1000;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    white-space: pre-wrap;
    word-wrap: break-word;
}

.notes-tooltip::before {
    content: '';
    position: absolute;
    top: -5px;
    left: 10px;
    width: 10px;
    height: 10px;
    background: #1f2937;
    transform: rotate(45deg);
    border-left: 1px solid #374151;
    border-top: 1px solid #374151;
}

/* Multiple select styles */
select[multiple] {
    background-image: none;
    padding: 8px 12px;
}

select[multiple] option {
    padding: 8px 12px;
    border-bottom: 1px solid #374151;
}

select[multiple] option:checked {
    background-color: #3b82f6;
    color: white;
}

select[multiple] option:hover {
    background-color: #4b5563;
}

/* Add to your existing CSS */
select:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.loading-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #f3f4f6;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Add to your existing CSS */
.editable-cell {
    min-height: 40px; /* Adjust this value as needed */
}

.table-cell {
    height: 100%;
}

/* Ensure the table rows have consistent height */
tbody tr {
    height: auto;
}

/* Make sure the input field also takes full height when editing */
.editable-cell input {
    height: 100%;
    min-height: 40px;
}
</style>

<script>
// Global variables
let currentEditCell = null;
let currentInput = null;
let selectedCells = new Set();
let copiedValue = null;
let copiedStyle = null;
let currentNotesCell = null;
let notesTimeout = null;

// Color palettes
const backgroundColors = [
    { name: 'Red', value: 'bg-red-500/20', border: 'border-red-500/30' },
    { name: 'Orange', value: 'bg-orange-500/20', border: 'border-orange-500/30' },
    { name: 'Yellow', value: 'bg-yellow-500/20', border: 'border-yellow-500/30' },
    { name: 'Green', value: 'bg-green-500/20', border: 'border-green-500/30' },
    { name: 'Blue', value: 'bg-blue-500/20', border: 'border-blue-500/30' },
    { name: 'Purple', value: 'bg-purple-500/20', border: 'border-purple-500/30' },
    { name: 'Pink', value: 'bg-pink-500/20', border: 'border-pink-500/30' },
    { name: 'Indigo', value: 'bg-indigo-500/20', border: 'border-indigo-500/30' },
    { name: 'Teal', value: 'bg-teal-500/20', border: 'border-teal-500/30' },
    { name: 'Cyan', value: 'bg-cyan-500/20', border: 'border-cyan-500/30' },
    { name: 'Gray', value: 'bg-gray-500/20', border: 'border-gray-500/30' },
    { name: 'None', value: '', border: '' }
];

const textColors = [
    { name: 'Red', value: 'text-red-300' },
    { name: 'Orange', value: 'text-orange-300' },
    { name: 'Yellow', value: 'text-yellow-300' },
    { name: 'Green', value: 'text-green-300' },
    { name: 'Blue', value: 'text-blue-300' },
    { name: 'Purple', value: 'text-purple-300' },
    { name: 'Pink', value: 'text-pink-300' },
    { name: 'White', value: 'text-white' },
    { name: 'Gray', value: 'text-gray-300' },
    { name: 'Default', value: '' }
];

let selectedBgColor = null;
let selectedTextColor = null;

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeFilters();
    loadSchedules();
    initializeColorPalettes();
    initializeKeyboardShortcuts();
    initializeNotesTooltips();
    initializeDepartmentFiltering();
});

// Initialize color palettes
function initializeColorPalettes() {
    const bgPalette = document.getElementById('bgColorPalette');
    const textPalette = document.getElementById('textColorPalette');
    
    backgroundColors.forEach(color => {
        const colorDiv = document.createElement('div');
        colorDiv.className = `color-option ${color.value} ${color.border} ${color.value ? 'border' : ''}`;
        colorDiv.title = color.name;
        colorDiv.onclick = () => selectColor('bg', color);
        bgPalette.appendChild(colorDiv);
    });
    
    textColors.forEach(color => {
        const colorDiv = document.createElement('div');
        colorDiv.className = `color-option bg-gray-700 ${color.value}`;
        colorDiv.innerHTML = 'A';
        colorDiv.title = color.name;
        colorDiv.onclick = () => selectColor('text', color);
        textPalette.appendChild(colorDiv);
    });
}

function selectColor(type, color) {
    if (type === 'bg') {
        selectedBgColor = color;
        document.querySelectorAll('#bgColorPalette .color-option').forEach(opt => opt.classList.remove('selected'));
        event.target.classList.add('selected');
    } else {
        selectedTextColor = color;
        document.querySelectorAll('#textColorPalette .color-option').forEach(opt => opt.classList.remove('selected'));
        event.target.classList.add('selected');
    }
}

// Department filtering and multiple selection
function initializeDepartmentFiltering() {
    const operationManagerFilter = document.getElementById('operation_manager_filter');
    const departmentsSelect = document.getElementById('departments');
    
    if (operationManagerFilter) {
        operationManagerFilter.addEventListener('change', function() {
            filterDepartmentsByManager(this.value);
        });
    }
    
    if (departmentsSelect) {
        departmentsSelect.addEventListener('change', updateSelectedDepartmentsCount);
    }
    
    // Initialize department count
    updateSelectedDepartmentsCount();
}

// Enhanced department filtering for create modal
function filterDepartmentsByManager(operationManager) {
    const departmentsSelect = document.getElementById('departments');
    const selectedCountText = document.getElementById('selectedCountText');
    
    const formData = new FormData();
    formData.append('get_departments_by_manager', '1');
    formData.append('operation_manager', operationManager);

    fetch('schedule.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store current selections
            const currentSelections = Array.from(departmentsSelect.selectedOptions).map(opt => opt.value);
            
            // Clear current options
            departmentsSelect.innerHTML = '';
            
            // Populate departments
            data.departments.forEach(dept => {
                const option = document.createElement('option');
                option.value = dept;
                option.textContent = dept;
                if (currentSelections.includes(dept)) {
                    option.selected = true;
                }
                departmentsSelect.appendChild(option);
            });
            
            // Update selected count
            updateSelectedDepartmentsCount();
        } else {
            console.error('Error loading departments:', data.message);
        }
    })
    .catch(error => {
        console.error('Error loading departments:', error);
    });
}

// Update selected departments count
function updateSelectedDepartmentsCount() {
    const departmentsSelect = document.getElementById('departments');
    const selectedCountText = document.getElementById('selectedCountText');
    const selectedCount = Array.from(departmentsSelect.selectedOptions).length;
    
    selectedCountText.textContent = `${selectedCount} department${selectedCount !== 1 ? 's' : ''} selected`;
}

// Enhanced showCreateScheduleModal function
function showCreateScheduleModal() {
    document.getElementById('createScheduleModal').classList.remove('hidden');
    // Reset form when showing modal
    document.getElementById('operation_manager_filter').value = '';
    filterDepartmentsByManager(''); // Show all departments
}

// Enhanced filter functionality with operation manager dependency
function initializeFilters() {
    const searchInput = document.getElementById('searchInput');
    const departmentFilter = document.getElementById('departmentFilter');
    const supervisorFilter = document.getElementById('supervisorFilter');
    const operationManagerFilter = document.getElementById('operationManagerFilter');
    const weekFilter = document.getElementById('weekFilter');
    
    if (!searchInput) return;
    
    let searchTimeout;

    function handleFilterChange() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            loadSchedules(
                searchInput.value,
                departmentFilter ? departmentFilter.value : '',
                supervisorFilter ? supervisorFilter.value : '',
                operationManagerFilter ? operationManagerFilter.value : '',
                weekFilter ? weekFilter.value : ''
            );
        }, 300);
    }

    searchInput.addEventListener('input', handleFilterChange);
    if (departmentFilter) departmentFilter.addEventListener('change', handleFilterChange);
    if (supervisorFilter) supervisorFilter.addEventListener('change', handleFilterChange);
    if (operationManagerFilter) operationManagerFilter.addEventListener('change', handleFilterChange);
    if (weekFilter) weekFilter.addEventListener('change', handleFilterChange);
    
    // Add operation manager change listener to update dependent dropdowns
    if (operationManagerFilter) {
        operationManagerFilter.addEventListener('change', function() {
            updateDependentFilters(this.value);
        });
    }
}

// Update dependent filters based on operation manager selection
function updateDependentFilters(operationManager) {
    updateSupervisorsByManager(operationManager);
    updateDepartmentsByManager(operationManager);
}

// Update supervisors dropdown based on operation manager
function updateSupervisorsByManager(operationManager) {
    const supervisorFilter = document.getElementById('supervisorFilter');
    if (!supervisorFilter) return;
    
    const currentSupervisor = supervisorFilter.value;
    
    const formData = new FormData();
    formData.append('get_supervisors_by_manager', '1');
    formData.append('operation_manager', operationManager);

    fetch('schedule.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store current selection
            const currentSelection = supervisorFilter.value;
            
            // Clear and repopulate supervisors
            supervisorFilter.innerHTML = '<option value="">All Supervisors</option>';
            data.supervisors.forEach(supervisor => {
                const option = document.createElement('option');
                option.value = supervisor;
                option.textContent = supervisor;
                if (supervisor === currentSelection) {
                    option.selected = true;
                }
                supervisorFilter.appendChild(option);
            });
            
            // If current selection is no longer available, trigger filter change
            if (currentSelection && !data.supervisors.includes(currentSelection)) {
                supervisorFilter.value = '';
                triggerFilterChange();
            }
        } else {
            console.error('Error loading supervisors:', data.message);
        }
    })
    .catch(error => {
        console.error('Error loading supervisors:', error);
    });
}

// Update departments dropdown based on operation manager
function updateDepartmentsByManager(operationManager) {
    const departmentFilter = document.getElementById('departmentFilter');
    if (!departmentFilter) return;
    
    const currentDepartment = departmentFilter.value;
    
    const formData = new FormData();
    formData.append('get_filter_departments_by_manager', '1');
    formData.append('operation_manager', operationManager);

    fetch('schedule.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store current selection
            const currentSelection = departmentFilter.value;
            
            // Clear and repopulate departments
            departmentFilter.innerHTML = '<option value="">All Departments</option>';
            data.departments.forEach(department => {
                const option = document.createElement('option');
                option.value = department;
                option.textContent = department;
                if (department === currentSelection) {
                    option.selected = true;
                }
                departmentFilter.appendChild(option);
            });
            
            // If current selection is no longer available, trigger filter change
            if (currentSelection && !data.departments.includes(currentSelection)) {
                departmentFilter.value = '';
                triggerFilterChange();
            }
        } else {
            console.error('Error loading departments:', data.message);
        }
    })
    .catch(error => {
        console.error('Error loading departments:', error);
    });
}

// Trigger filter change programmatically
function triggerFilterChange() {
    const searchInput = document.getElementById('searchInput');
    const departmentFilter = document.getElementById('departmentFilter');
    const supervisorFilter = document.getElementById('supervisorFilter');
    const operationManagerFilter = document.getElementById('operationManagerFilter');
    const weekFilter = document.getElementById('weekFilter');
    
    loadSchedules(
        searchInput ? searchInput.value : '',
        departmentFilter ? departmentFilter.value : '',
        supervisorFilter ? supervisorFilter.value : '',
        operationManagerFilter ? operationManagerFilter.value : '',
        weekFilter ? weekFilter.value : ''
    );
}

// Load schedules via AJAX
function loadSchedules(search = '', department = '', supervisor = '', operationManager = '', week = '', page = 1) {
    const formData = new FormData();
    formData.append('search', search);
    formData.append('department', department);
    formData.append('supervisor', supervisor);
    formData.append('operation_manager', operationManager);
    formData.append('week', week);
    formData.append('page', page);

    fetch('partials/schedules_table.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        document.getElementById('schedulesTableContainer').innerHTML = data;
        initializeEditableCells();
        initializePagination();
        clearSelection();
    })
    .catch(error => {
        console.error('Error loading schedules:', error);
        showTemporaryMessage('Error loading schedules', 'error');
    });
}

// Initialize pagination
function initializePagination() {
    document.querySelectorAll('.pagination-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const page = this.getAttribute('data-page');
            const searchInput = document.getElementById('searchInput');
            const departmentFilter = document.getElementById('departmentFilter');
            const supervisorFilter = document.getElementById('supervisorFilter');
            const operationManagerFilter = document.getElementById('operationManagerFilter');
            const weekFilter = document.getElementById('weekFilter');
            
            loadSchedules(
                searchInput ? searchInput.value : '',
                departmentFilter ? departmentFilter.value : '',
                supervisorFilter ? supervisorFilter.value : '',
                operationManagerFilter ? operationManagerFilter.value : '',
                weekFilter ? weekFilter.value : '',
                page
            );
        });
    });
}

// Initialize editable cells
function initializeEditableCells() {
    // Use event delegation instead of replacing elements
    document.addEventListener('click', function(e) {
        const cell = e.target.closest('.editable-cell');
        if (!cell) return;
        
        // Handle multi-select with Ctrl/Cmd key
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            toggleCellSelection(cell);
            return;
        }
        
        if (e.target.tagName === 'INPUT') return;
        if (currentEditCell === cell) return;
        
        if (currentEditCell && currentEditCell !== cell) {
            saveCellEdit(currentEditCell);
            setTimeout(() => {
                makeCellEditable(cell);
            }, 100);
        } else {
            makeCellEditable(cell);
        }
    });

    // Double-click for selection
    document.addEventListener('dblclick', function(e) {
        const cell = e.target.closest('.editable-cell');
        if (!cell) return;
        
        if (!e.ctrlKey && !e.metaKey) {
            clearSelection();
            toggleCellSelection(cell);
        }
    });
}

// Multi-select functionality
function toggleCellSelection(cell) {
    if (selectedCells.has(cell)) {
        selectedCells.delete(cell);
        cell.classList.remove('selected');
    } else {
        selectedCells.add(cell);
        cell.classList.add('selected');
    }
    updateSelectionToolbar();
}

function clearSelection() {
    selectedCells.forEach(cell => {
        cell.classList.remove('selected', 'copy-source');
    });
    selectedCells.clear();
    updateSelectionToolbar();
}

function updateSelectionToolbar() {
    const toolbar = document.getElementById('multiSelectToolbar');
    const selectedCount = document.getElementById('selectedCount');
    
    if (selectedCells.size > 0) {
        toolbar.classList.remove('hidden');
        selectedCount.textContent = `${selectedCells.size} cells selected`;
    } else {
        toolbar.classList.add('hidden');
    }
}

function copySelectedCells() {
    if (selectedCells.size === 1) {
        const cell = Array.from(selectedCells)[0];
        copiedValue = cell.getAttribute('data-original-value') || cell.textContent.trim();
        
        // Get current styles
        copiedStyle = {
            bgColor: Array.from(cell.classList).find(cls => cls.startsWith('bg-') && cls.includes('/20')),
            border: Array.from(cell.classList).find(cls => cls.startsWith('border-') && cls.includes('/30')),
            textColor: Array.from(cell.classList).find(cls => cls.startsWith('text-') && !cls.includes('hover:'))
        };
        
        cell.classList.add('copy-source');
        showTemporaryMessage('Cell copied! Use Ctrl+V to paste', 'success');
        
        setTimeout(() => {
            cell.classList.remove('copy-source');
        }, 2000);
    } else {
        showTemporaryMessage('Please select only one cell to copy', 'warning');
    }
}

function pasteToSelectedCells() {
    if (!copiedValue && !copiedStyle) {
        showTemporaryMessage('No cell copied to paste', 'warning');
        return;
    }

    if (selectedCells.size === 0) {
        showTemporaryMessage('Please select cells to paste into', 'warning');
        return;
    }

    let pasteCount = 0;
    selectedCells.forEach(cell => {
        if (cell !== document.querySelector('.copy-source')) {
            if (copiedValue !== null) {
                const field = cell.getAttribute('data-field');
                const scheduleId = cell.getAttribute('data-schedule-id');
                
                // Capitalize the pasted value
                const capitalizedValue = copiedValue.toUpperCase();
                const displayValue = capitalizedValue || '-';
                cell.innerHTML = `<div class="px-2 py-1 text-center">${escapeHtml(displayValue)}</div>`;
                cell.setAttribute('data-original-value', capitalizedValue);
                
                saveCellValue(scheduleId, field, capitalizedValue);
            }
            
            if (copiedStyle) {
                applyStyleToCell(cell, copiedStyle, true);
            }
            
            pasteCount++;
        }
    });

    showTemporaryMessage(`Pasted to ${pasteCount} cells`, 'success');
    clearSelection();
}

function saveCellValue(scheduleId, field, value) {
    // Ensure value is capitalized before saving
    const capitalizedValue = value.toUpperCase();
    
    const formData = new FormData();
    formData.append('update_schedule', '1');
    formData.append('schedule_id', scheduleId);
    formData.append('field', field);
    formData.append('value', capitalizedValue);

    fetch('schedule.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.total_hours !== undefined && field !== 'total_sched') {
            const totalCell = document.querySelector(`[data-schedule-id="${scheduleId}"][data-field="total_sched"]`);
            if (totalCell) {
                totalCell.innerHTML = `<div class="text-center">${data.total_hours} hrs</div>`;
            }
        }
    })
    .catch(error => {
        console.error('Error saving pasted cell:', error);
    });
}

// Cell styling functions
function showColorPickerForSelection() {
    if (selectedCells.size === 0) {
        showTemporaryMessage('Please select cells to style', 'warning');
        return;
    }
    showColorPickerModal();
}

function applyCellStyle() {
    if (selectedCells.size === 0) {
        showTemporaryMessage('No cells selected', 'warning');
        return;
    }

    const style = {
        bgColor: selectedBgColor ? selectedBgColor.value : null,
        border: selectedBgColor ? selectedBgColor.border : null,
        textColor: selectedTextColor ? selectedTextColor.value : null
    };

    selectedCells.forEach(cell => {
        applyStyleToCell(cell, style, true);
    });

    showTemporaryMessage(`Style applied to ${selectedCells.size} cells`, 'success');
    closeColorPickerModal();
    clearSelection();
}

function applyStyleToCell(cell, style, saveToDB = false) {
    // Remove existing styling classes
    backgroundColors.forEach(color => {
        if (color.value) cell.classList.remove(color.value);
        if (color.border) cell.classList.remove(color.border);
    });
    textColors.forEach(color => {
        if (color.value) cell.classList.remove(color.value);
    });

    // Apply new styling
    if (style.bgColor) cell.classList.add(style.bgColor);
    if (style.border) cell.classList.add(style.border);
    if (style.textColor) cell.classList.add(style.textColor);

    // Save to database if requested
    if (saveToDB) {
        const field = cell.getAttribute('data-field');
        const scheduleId = cell.getAttribute('data-schedule-id');
        const styleString = JSON.stringify(style);
        
        saveCellStyle(scheduleId, field, styleString);
    }
}

function saveCellStyle(scheduleId, field, style) {
    const formData = new FormData();
    formData.append('update_style', '1');
    formData.append('schedule_id', scheduleId);
    formData.append('field', field);
    formData.append('style', style);

    fetch('schedule.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Error saving style:', data.message);
        }
    })
    .catch(error => {
        console.error('Error saving style:', error);
    });
}

function clearCellStyle() {
    if (selectedCells.size === 0) {
        showTemporaryMessage('No cells selected', 'warning');
        return;
    }

    selectedCells.forEach(cell => {
        // Remove all styling classes
        backgroundColors.forEach(color => {
            if (color.value) cell.classList.remove(color.value);
            if (color.border) cell.classList.remove(color.border);
        });
        textColors.forEach(color => {
            if (color.value) cell.classList.remove(color.value);
        });
        
        // Clear from database
        const field = cell.getAttribute('data-field');
        const scheduleId = cell.getAttribute('data-schedule-id');
        saveCellStyle(scheduleId, field, '');
    });

    showTemporaryMessage(`Style cleared from ${selectedCells.size} cells`, 'success');
    closeColorPickerModal();
    clearSelection();
}

// Notes functionality
function showNotesForSelection() {
    if (selectedCells.size === 0) {
        showTemporaryMessage('Please select cells to add notes', 'warning');
        return;
    }
    
    if (selectedCells.size === 1) {
        // Single cell - show notes modal
        const cell = Array.from(selectedCells)[0];
        showNotesModal(cell);
    } else {
        // Multiple cells - show bulk notes option
        showBulkNotesModal();
    }
}

function showNotesModal(cell) {
    currentNotesCell = cell;
    const field = cell.getAttribute('data-field');
    const scheduleId = cell.getAttribute('data-schedule-id');
    const cellValue = cell.getAttribute('data-original-value') || cell.textContent.trim();
    
    // Get existing notes from the data attribute (not from the database)
    const existingNotes = cell.getAttribute('data-notes') || '';
    
    document.getElementById('notesTextarea').value = existingNotes;
    document.getElementById('notesCellInfo').textContent = `${field.charAt(0).toUpperCase() + field.slice(1)}: ${cellValue || 'Empty'}`;
    updateCharCount();
    
    document.getElementById('notesModal').classList.remove('hidden');
}

function showBulkNotesModal() {
    currentNotesCell = Array.from(selectedCells)[0];
    document.getElementById('notesTextarea').value = '';
    document.getElementById('notesCellInfo').textContent = `${selectedCells.size} cells selected`;
    updateCharCount();
    
    document.getElementById('notesModal').classList.remove('hidden');
}

function closeNotesModal() {
    document.getElementById('notesModal').classList.add('hidden');
    currentNotesCell = null;
}

function updateCharCount() {
    const textarea = document.getElementById('notesTextarea');
    const charCount = document.getElementById('notesCharCount');
    const count = textarea.value.length;
    charCount.textContent = `${count}/500`;
    
    if (count > 500) {
        charCount.classList.add('text-red-400');
    } else {
        charCount.classList.remove('text-red-400');
    }
}

function saveNotes() {
    const notes = document.getElementById('notesTextarea').value.trim().substring(0, 500);
    
    if (selectedCells.size === 1 && currentNotesCell) {
        // Single cell notes
        saveCellNotes(currentNotesCell, notes);
    } else if (selectedCells.size > 1) {
        // Bulk notes for multiple cells
        selectedCells.forEach(cell => {
            // Update UI immediately for each cell
            cell.setAttribute('data-notes', notes);
            updateNotesIndicator(cell, notes);
            
            // Save to database
            const field = cell.getAttribute('data-field');
            const scheduleId = cell.getAttribute('data-schedule-id');
            
            const formData = new FormData();
            formData.append('update_notes', '1');
            formData.append('schedule_id', scheduleId);
            formData.append('field', field);
            formData.append('notes', notes);

            fetch('schedule.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error('Error saving notes:', data.message);
                }
            })
            .catch(error => {
                console.error('Error saving notes:', error);
            });
        });
        
        showTemporaryMessage(`Notes saved to ${selectedCells.size} cells!`, 'success');
    }
    
    closeNotesModal();
    clearSelection();
}

function saveCellNotes(cell, notes) {
    const field = cell.getAttribute('data-field');
    const scheduleId = cell.getAttribute('data-schedule-id');
    
    // Update cell attribute and visual indicator IN REAL-TIME
    cell.setAttribute('data-notes', notes);
    updateNotesIndicator(cell, notes);
    
    // Update the title attribute in real-time
    const hasNotes = notes && notes.trim() !== '';
    cell.setAttribute('title', `Click to edit - ${hasNotes ? 'Has notes (hover to view)' : 'No notes'}`);
    
    // Save to database
    const formData = new FormData();
    formData.append('update_notes', '1');
    formData.append('schedule_id', scheduleId);
    formData.append('field', field);
    formData.append('notes', notes);

    fetch('schedule.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showTemporaryMessage('Notes saved successfully!', 'success');
        } else {
            console.error('Error saving notes:', data.message);
        }
    })
    .catch(error => {
        console.error('Error saving notes:', error);
    });
}

function clearNotes() {
    const notes = '';
    
    if (selectedCells.size === 1 && currentNotesCell) {
        saveCellNotes(currentNotesCell, notes);
    } else if (selectedCells.size > 1) {
        selectedCells.forEach(cell => {
            saveCellNotes(cell, notes);
        });
    }
    
    closeNotesModal();
    clearSelection();
}

function updateNotesIndicator(cell, notes) {
    if (notes && notes.trim() !== '') {
        cell.classList.add('has-notes');
    } else {
        cell.classList.remove('has-notes');
    }
    
    // Update the title attribute
    const hasNotes = notes && notes.trim() !== '';
    cell.setAttribute('title', `Click to edit - ${hasNotes ? 'Has notes (hover to view)' : 'No notes'}`);
}

// Tooltip functionality
function initializeNotesTooltips() {
    document.addEventListener('mouseover', function(e) {
        const cell = e.target.closest('.editable-cell');
        if (!cell) return;
        
        const notes = cell.getAttribute('data-notes');
        if (!notes || notes.trim() === '') return;
        
        // Clear any existing timeout
        if (notesTimeout) {
            clearTimeout(notesTimeout);
        }
        
        // Show tooltip after short delay
        notesTimeout = setTimeout(() => {
            showNotesTooltip(cell, notes, e);
        }, 500);
    });
    
    document.addEventListener('mouseout', function(e) {
        const cell = e.target.closest('.editable-cell');
        if (!cell) return;
        
        if (notesTimeout) {
            clearTimeout(notesTimeout);
            notesTimeout = null;
        }
        
        hideNotesTooltip();
    });
    
    document.addEventListener('mousemove', function(e) {
        const tooltip = document.getElementById('notes-tooltip');
        if (tooltip) {
            tooltip.style.left = (e.pageX + 10) + 'px';
            tooltip.style.top = (e.pageY + 10) + 'px';
        }
    });
}

function showNotesTooltip(cell, notes, event) {
    hideNotesTooltip();
    
    const tooltip = document.createElement('div');
    tooltip.id = 'notes-tooltip';
    tooltip.className = 'notes-tooltip';
    tooltip.innerHTML = `
        <div class="font-semibold text-purple-300 mb-1">Notes:</div>
        <div class="text-gray-200">${escapeHtml(notes)}</div>
    `;
    
    document.body.appendChild(tooltip);
    
    // Position tooltip near cursor
    tooltip.style.left = (event.pageX + 10) + 'px';
    tooltip.style.top = (event.pageY + 10) + 'px';
}

function hideNotesTooltip() {
    const tooltip = document.getElementById('notes-tooltip');
    if (tooltip) {
        tooltip.remove();
    }
}

// Make cell editable
function makeCellEditable(cell) {
    const currentValue = cell.getAttribute('data-original-value') || cell.textContent.trim();
    
    // Store current styles to reapply after editing
    const currentStyles = {
        bgColor: Array.from(cell.classList).find(cls => cls.startsWith('bg-') && cls.includes('/20')),
        border: Array.from(cell.classList).find(cls => cls.startsWith('border-') && cls.includes('/30')),
        textColor: Array.from(cell.classList).find(cls => cls.startsWith('text-') && !cls.includes('hover:'))
    };
    
    cell.setAttribute('data-original-styles', JSON.stringify(currentStyles));
    
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue === '-' ? '' : currentValue;
    input.className = 'w-full h-full px-2 py-1 bg-gray-700 border-2 border-blue-500 rounded text-gray-200 text-sm focus:outline-none focus:border-blue-400 text-center uppercase';
    input.style.textTransform = 'uppercase';
    
    // Auto-capitalize input as user types
    input.addEventListener('input', function(e) {
        this.value = this.value.toUpperCase();
    });
    
    // Clear cell and add input
    cell.innerHTML = '';
    cell.appendChild(input);
    cell.classList.add('editing', 'bg-blue-500/10');
    
    // Remove other styling temporarily
    backgroundColors.forEach(color => {
        if (color.value) cell.classList.remove(color.value);
        if (color.border) cell.classList.remove(color.border);
    });
    textColors.forEach(color => {
        if (color.value) cell.classList.remove(color.value);
    });
    
    input.focus();
    input.select();
    
    currentInput = input;
    currentEditCell = cell;
    
    const handleKeyDown = function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveCellEdit(cell);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelCellEdit(cell);
        } else if (e.key === 'Tab') {
            e.preventDefault();
            saveCellEdit(cell, true);
        }
    };
    
    const handleBlur = function() {
        setTimeout(() => {
            if (currentEditCell === cell) {
                saveCellEdit(cell);
            }
        }, 150);
    };
    
    input.addEventListener('keydown', handleKeyDown);
    input.addEventListener('blur', handleBlur);
}

// Save cell edit
function saveCellEdit(cell, moveToNext = false) {
    if (!cell.classList.contains('editing')) return;
    
    const input = cell.querySelector('input');
    if (!input) return;
    
    const newValue = input.value.trim();
    const field = cell.getAttribute('data-field');
    const scheduleId = cell.getAttribute('data-schedule-id');
    const originalValue = cell.getAttribute('data-original-value') || '';
    
    // Get back the original styles
    const originalStyles = JSON.parse(cell.getAttribute('data-original-styles') || '{}');
    
    // Capitalize the new value before saving
    const capitalizedValue = newValue.toUpperCase();
    
    if (capitalizedValue === originalValue) {
        cancelCellEdit(cell);
        if (moveToNext) {
            moveToNextCell(cell);
        }
        return;
    }
    
    cell.innerHTML = '<i class="fas fa-spinner fa-spin text-blue-400 text-sm h-full flex items-center justify-center"></i>';
    cell.classList.remove('bg-blue-500/10', 'editing');
    
    const formData = new FormData();
    formData.append('update_schedule', '1');
    formData.append('schedule_id', scheduleId);
    formData.append('field', field);
    formData.append('value', capitalizedValue);

    fetch('schedule.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('Server returned non-JSON response: ' + text.substring(0, 100));
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // SUCCESS: Update the cell display
            const displayValue = capitalizedValue || '-';
            cell.innerHTML = `<div class="px-2 py-1 text-center h-full flex items-center justify-center">${escapeHtml(displayValue)}</div>`;
            cell.setAttribute('data-original-value', capitalizedValue);
            
            // Reapply the original styles
            if (originalStyles.bgColor) cell.classList.add(originalStyles.bgColor);
            if (originalStyles.border) cell.classList.add(originalStyles.border);
            if (originalStyles.textColor) cell.classList.add(originalStyles.textColor);
            
            // Update total hours if available
            if (data.total_hours !== undefined && field !== 'total_sched') {
                const totalCell = document.querySelector(`[data-schedule-id="${scheduleId}"][data-field="total_sched"]`);
                if (totalCell) {
                    totalCell.innerHTML = `<div class="text-center h-full flex items-center justify-center">${data.total_hours} hrs</div>`;
                }
            }
            
            showTemporaryMessage('Schedule updated successfully!', 'success');
            
            if (moveToNext) {
                setTimeout(() => {
                    moveToNextCell(cell);
                }, 50);
            }
        } else {
            throw new Error(data.message || 'Update failed');
        }
    })
    .catch(error => {
        console.error('Error saving cell:', error);
        // ERROR: Revert to original value and styles
        const displayValue = originalValue || '-';
        cell.innerHTML = `<div class="px-2 py-1 text-center h-full flex items-center justify-center">${escapeHtml(displayValue)}</div>`;
        cell.setAttribute('data-original-value', originalValue);
        
        // Reapply the original styles
        if (originalStyles.bgColor) cell.classList.add(originalStyles.bgColor);
        if (originalStyles.border) cell.classList.add(originalStyles.border);
        if (originalStyles.textColor) cell.classList.add(originalStyles.textColor);
        
        showTemporaryMessage('Error: ' + error.message, 'error');
    });
    
    currentEditCell = null;
    currentInput = null;
}

// Cancel cell edit
function cancelCellEdit(cell) {
    const originalValue = cell.getAttribute('data-original-value') || '';
    const displayValue = originalValue || '-';
    const originalStyles = JSON.parse(cell.getAttribute('data-original-styles') || '{}');
    
    cell.innerHTML = `<div class="px-2 py-1 text-center h-full flex items-center justify-center">${escapeHtml(displayValue)}</div>`;
    cell.classList.remove('editing', 'bg-blue-500/10');
    
    // Reapply the original styles
    if (originalStyles.bgColor) cell.classList.add(originalStyles.bgColor);
    if (originalStyles.border) cell.classList.add(originalStyles.border);
    if (originalStyles.textColor) cell.classList.add(originalStyles.textColor);
    
    currentEditCell = null;
    currentInput = null;
}

// Move to next cell
function moveToNextCell(currentCell) {
    const allEditableCells = Array.from(document.querySelectorAll('.editable-cell'));
    const currentIndex = allEditableCells.indexOf(currentCell);
    
    if (currentIndex !== -1 && currentIndex < allEditableCells.length - 1) {
        const nextCell = allEditableCells[currentIndex + 1];
        if (currentEditCell) {
            cancelCellEdit(currentEditCell);
        }
        setTimeout(() => {
            makeCellEditable(nextCell);
        }, 50);
    }
}

// Keyboard shortcuts
function initializeKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
            if (selectedCells.size > 0) {
                e.preventDefault();
                copySelectedCells();
            }
        }
        
        if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
            if (selectedCells.size > 0) {
                e.preventDefault();
                pasteToSelectedCells();
            }
        }
        
        if (e.key === 'Escape' && selectedCells.size > 0) {
            e.preventDefault();
            clearSelection();
        }
    });
}

// Show temporary message
function showTemporaryMessage(message, type = 'info') {
    const existingMessage = document.getElementById('temp-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.id = 'temp-message';
    messageDiv.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg border ${
        type === 'success' ? 'bg-green-500/20 border-green-500/30 text-green-300' :
        type === 'error' ? 'bg-red-500/20 border-red-500/30 text-red-300' :
        type === 'warning' ? 'bg-yellow-500/20 border-yellow-500/30 text-yellow-300' :
        'bg-blue-500/20 border-blue-500/30 text-blue-300'
    }`;
    messageDiv.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${
                type === 'success' ? 'fa-check-circle' :
                type === 'error' ? 'fa-exclamation-circle' :
                type === 'warning' ? 'fa-exclamation-triangle' :
                'fa-info-circle'
            } mr-2"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(messageDiv);
    
    setTimeout(() => {
        if (messageDiv.parentNode) {
            messageDiv.remove();
        }
    }, 3000);
}

// Escape HTML
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Modal functions
function showCreateScheduleModal() {
    document.getElementById('createScheduleModal').classList.remove('hidden');
}

function closeCreateScheduleModal() {
    document.getElementById('createScheduleModal').classList.add('hidden');
}

function showColorPickerModal() {
    document.getElementById('colorPickerModal').classList.remove('hidden');
}

function closeColorPickerModal() {
    document.getElementById('colorPickerModal').classList.add('hidden');
}

function closeNotesModal() {
    document.getElementById('notesModal').classList.add('hidden');
    currentNotesCell = null;
}

function showDeleteModal(recordId) {
    const modal = `
        <div id="deleteModal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50">
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md">
                <div class="px-6 py-6">
                    <h3 class="text-lg font-bold text-gray-100 mb-4">Confirm Deletion</h3>
                    <p class="text-gray-300 mb-4">Are you sure you want to delete this schedule?</p>
                    <form method="post" action="schedule.php?delete=${recordId}" class="space-y-4">
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

// Global event listeners
document.addEventListener('click', function(e) {
    if (e.target === document.getElementById('createScheduleModal')) {
        closeCreateScheduleModal();
    }
    if (e.target === document.getElementById('colorPickerModal')) {
        closeColorPickerModal();
    }
    if (e.target === document.getElementById('notesModal')) {
        closeNotesModal();
    }
    if (e.target === document.getElementById('deleteModal')) {
        closeDeleteModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('createScheduleModal')) {
            closeCreateScheduleModal();
        }
        if (document.getElementById('colorPickerModal')) {
            closeColorPickerModal();
        }
        if (document.getElementById('notesModal')) {
            closeNotesModal();
        }
        if (document.getElementById('deleteModal')) {
            closeDeleteModal();
        }
        if (currentEditCell) {
            cancelCellEdit(currentEditCell);
        }
    }
});

// Add notes-related event listeners
document.getElementById('notesTextarea').addEventListener('input', updateCharCount);

// Make functions globally available
window.loadSchedules = loadSchedules;
window.showCreateScheduleModal = showCreateScheduleModal;
window.closeCreateScheduleModal = closeCreateScheduleModal;
window.showDeleteModal = showDeleteModal;
window.closeDeleteModal = closeDeleteModal;
window.toggleCellSelection = toggleCellSelection;
window.copySelectedCells = copySelectedCells;
window.pasteToSelectedCells = pasteToSelectedCells;
window.clearSelection = clearSelection;
window.showColorPickerForSelection = showColorPickerForSelection;
window.applyCellStyle = applyCellStyle;
window.clearCellStyle = clearCellStyle;
window.closeColorPickerModal = closeColorPickerModal;
window.showNotesForSelection = showNotesForSelection;
window.closeNotesModal = closeNotesModal;
</script>

<?php renderFooter(); ?>