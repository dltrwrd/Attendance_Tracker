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

// Handle style updates via AJAX - ENHANCED VERSION
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
        // Validate and sanitize style JSON
        if (!empty($style)) {
            $decodedStyle = json_decode($style, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid style JSON');
            }
            // Re-encode to ensure valid JSON
            $style = json_encode($decodedStyle);
        }
        
        // Update the style
        $styleField = $field . '_style';
        $stmt = $pdo->prepare("UPDATE schedule SET $styleField = ? WHERE id = ?");
        $stmt->execute([$style, $scheduleId]);
        
        // Verify the update
        $verifyStmt = $pdo->prepare("SELECT $styleField FROM schedule WHERE id = ?");
        $verifyStmt->execute([$scheduleId]);
        $verifiedStyle = $verifyStmt->fetchColumn();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Style updated',
            'verified_style' => $verifiedStyle
        ]);
        
    } catch (Exception $e) {
        error_log("Style update error: " . $e->getMessage());
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
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-2xl">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold">Cell Styling</h3>
                        <button type="button" onclick="closeColorPickerModal()" class="text-gray-400 hover:text-white">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Background Color</label>
                            <div class="grid grid-cols-8 gap-2" id="bgColorPalette">
                                <!-- Background colors will be populated by JavaScript -->
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Text Color</label>
                            <div class="grid grid-cols-8 gap-2" id="textColorPalette">
                                <!-- Text colors will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                    
                    <!-- Custom Color Picker Button -->
                    <div class="mt-6 pt-6 border-t border-gray-700">
                        <button type="button" onclick="showCustomColorModal()" 
                                class="w-full bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white px-4 py-3 rounded-lg flex items-center justify-center transition-all duration-200 transform hover:scale-105">
                            <i class="fas fa-palette mr-2"></i> Custom Color Picker
                        </button>
                    </div>
                    
                    <div class="flex space-x-2 mt-6">
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

        <!-- Custom Color Picker Modal -->
        <div id="customColorModal" class="hidden fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50">
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold">Custom Color</h3>
                        <button type="button" onclick="closeCustomColorModal()" class="text-gray-400 hover:text-white">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Select Custom Color</label>
                            <input type="color" id="customColorInput" 
                                   class="w-full h-12 cursor-pointer bg-gray-700 border border-gray-600 rounded-md"
                                   value="#3b82f6">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Preview</label>
                            <div id="customColorPreview" class="w-full h-20 rounded-md border border-gray-600 flex items-center justify-center text-white font-medium">
                                Preview Text
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-2">
                            <input type="checkbox" id="applyAsSolid" class="rounded border-gray-600 bg-gray-700 text-primary-600 focus:ring-primary-500">
                            <label for="applyAsSolid" class="text-sm text-gray-300">Apply as solid color (transparent by default)</label>
                        </div>
                    </div>
                    
                    <div class="flex space-x-2 mt-6">
                        <button type="button" onclick="closeCustomColorModal()" 
                                class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                            Cancel
                        </button>
                        <button type="button" onclick="applyCustomColor()" 
                                class="flex-1 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg">
                            Apply Custom Color
                        </button>
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

        <!-- Advanced Filter Modal -->
        <div id="advancedFilterModal" class="hidden fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50">
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-4xl max-h-[80vh] overflow-hidden">
                <div class="p-6 border-b border-gray-700">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-bold">Advanced Filters</h3>
                        <button type="button" onclick="closeAdvancedFilterModal()" class="text-gray-400 hover:text-white">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <div class="p-6 overflow-y-auto max-h-[60vh]">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="filterSections">
                        <!-- Filter sections will be populated by JavaScript -->
                    </div>
                </div>
                
                <div class="p-6 border-t border-gray-700 bg-gray-900/50">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center space-x-4">
                            <button type="button" onclick="clearAllFilters()" 
                                    class="text-gray-300 hover:text-white text-sm flex items-center">
                                <i class="fas fa-times-circle mr-2"></i> Clear All Filters
                            </button>
                            <span id="activeFilterCount" class="text-sm text-gray-400">0 filters active</span>
                        </div>
                        <div class="flex space-x-3">
                            <button type="button" onclick="closeAdvancedFilterModal()" 
                                    class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                                Cancel
                            </button>
                            <button type="button" onclick="applyAdvancedFilters()" 
                                    class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg flex items-center">
                                <i class="fas fa-check mr-2"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Individual Column Filter Modal -->
        <div id="columnFilterModal" class="hidden fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50">
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-sm">
                <div class="p-4 border-b border-gray-700">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-bold" id="columnFilterTitle">Filter Column</h3>
                        <button type="button" onclick="closeColumnFilterModal()" class="text-gray-400 hover:text-white">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <div class="p-4">
                    <div class="mb-4">
                        <input type="text" id="filterSearchInput" 
                            class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-gray-200" 
                            placeholder="Search values..."
                            onkeyup="filterColumnOptions()">
                    </div>
                    
                    <div class="max-h-64 overflow-y-auto border border-gray-600 rounded-lg bg-gray-900/50 mb-4">
                        <div class="p-2 space-y-1" id="columnFilterOptions">
                            <!-- Filter options will be populated by JavaScript -->
                        </div>
                    </div>
                    
                    <div class="flex justify-between text-sm text-gray-400 mb-4">
                        <button type="button" onclick="selectAllColumnOptions()" class="hover:text-white">
                            Select All
                        </button>
                        <button type="button" onclick="clearColumnOptions()" class="hover:text-white">
                            Clear
                        </button>
                    </div>
                </div>
                
                <div class="p-4 border-t border-gray-700 bg-gray-900/50">
                    <div class="flex justify-between items-center">
                        <span id="columnSelectedCount" class="text-sm text-gray-400">0 selected</span>
                        <div class="flex space-x-2">
                            <button type="button" onclick="closeColumnFilterModal()" 
                                    class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded text-sm">
                                Cancel
                            </button>
                            <button type="button" onclick="applyColumnFilter()" 
                                    class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-1 rounded text-sm">
                                Apply
                            </button>
                        </div>
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

        <?php
        // Get total schedules count for display
        try {
            $totalCountStmt = $pdo->query("SELECT COUNT(*) FROM schedule");
            $totalSchedulesCount = $totalCountStmt->fetchColumn();
        } catch (PDOException $e) {
            $totalSchedulesCount = 0;
        }
        ?>

        <!-- Filter Status Bar -->
        <div class="mb-4 flex justify-between items-center">
            <div class="text-sm text-gray-400">
                Displaying <span id="displayCount"><?= $totalSchedulesCount ?></span> schedules
                <span id="filterStatus" class="ml-2 hidden">
                    • <span id="activeFilterCountBadge" class="bg-primary-600 text-white px-2 py-1 rounded text-xs">0 filters</span>
                </span>
            </div>
            <div class="flex space-x-2">
                <button type="button" onclick="showAdvancedFilterModal()" 
                        class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-filter mr-2"></i> All Filters
                </button>
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
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
}

.color-option:hover {
    transform: scale(1.1);
    border-color: white;
}

.color-option.selected {
    border-color: #3b82f6;
    transform: scale(1.1);
}

/* Custom color picker styles */
input[type="color"] {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    background: transparent;
    border: none;
    cursor: pointer;
}

input[type="color"]::-webkit-color-swatch {
    border-radius: 4px;
    border: 2px solid #4b5563;
}

input[type="color"]::-webkit-color-swatch-wrapper {
    padding: 0;
}

input[type="color"]::-moz-color-swatch {
    border-radius: 4px;
    border: 2px solid #4b5563;
}

/* Enhanced color styles for the palette */
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
.bg-lime-500\/20 { background-color: rgba(132, 204, 22, 0.2); }
.bg-amber-500\/20 { background-color: rgba(245, 158, 11, 0.2); }
.bg-emerald-500\/20 { background-color: rgba(16, 185, 129, 0.2); }
.bg-violet-500\/20 { background-color: rgba(139, 92, 246, 0.2); }
.bg-fuchsia-500\/20 { background-color: rgba(217, 70, 239, 0.2); }

/* Solid background colors */
.bg-red-500 { background-color: rgb(239, 68, 68); }
.bg-orange-500 { background-color: rgb(249, 115, 22); }
.bg-yellow-500 { background-color: rgb(245, 158, 11); }
.bg-green-500 { background-color: rgb(16, 185, 129); }
.bg-blue-500 { background-color: rgb(59, 130, 246); }
.bg-purple-500 { background-color: rgb(139, 92, 246); }
.bg-pink-500 { background-color: rgb(236, 72, 153); }
.bg-indigo-500 { background-color: rgb(99, 102, 241); }
.bg-teal-500 { background-color: rgb(20, 184, 166); }
.bg-cyan-500 { background-color: rgb(6, 182, 212); }
.bg-gray-500 { background-color: rgb(107, 114, 128); }
.bg-lime-500 { background-color: rgb(132, 204, 22); }
.bg-amber-500 { background-color: rgb(245, 158, 11); }
.bg-emerald-500 { background-color: rgb(16, 185, 129); }
.bg-violet-500 { background-color: rgb(139, 92, 246); }
.bg-fuchsia-500 { background-color: rgb(217, 70, 239); }
.bg-rose-500 { background-color: rgb(244, 63, 94); }
.bg-sky-500 { background-color: rgb(14, 165, 233); }

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
.border-lime-500\/30 { border-color: rgba(132, 204, 22, 0.3); }
.border-amber-500\/30 { border-color: rgba(245, 158, 11, 0.3); }
.border-emerald-500\/30 { border-color: rgba(16, 185, 129, 0.3); }
.border-violet-500\/30 { border-color: rgba(139, 92, 246, 0.3); }
.border-fuchsia-500\/30 { border-color: rgba(217, 70, 239, 0.3); }

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

/* Drag selection styles */
.drag-selection-overlay {
    position: absolute;
    background-color: rgba(59, 130, 246, 0.2);
    border: 2px solid #3b82f6;
    pointer-events: none;
    z-index: 30;
}

.drag-selecting .editable-cell.selectable {
    pointer-events: none;
}

.editable-cell.selectable.drag-highlight {
    background-color: rgba(59, 130, 246, 0.3) !important;
    border-color: #3b82f6 !important;
}

/* Filter styles */
.filter-section {
    background: rgba(55, 65, 81, 0.3);
    border-radius: 8px;
    padding: 16px;
}

.filter-checkbox {
    border-radius: 4px;
}

.filter-option {
    transition: background-color 0.2s;
}

.filter-option:hover {
    background-color: rgba(55, 65, 81, 0.5);
}

/* Filter badge */
.filter-badge {
    background: #3b82f6;
    color: white;
    border-radius: 12px;
    padding: 2px 8px;
    font-size: 0.75rem;
    font-weight: 500;
}

/* Filter icon styles */
th button {
    opacity: 0;
    transition: opacity 0.2s;
}

th:hover button {
    opacity: 1;
}

th button.filter-active {
    opacity: 1;
    color: #3b82f6;
}

/* Column filter modal styles */
.column-filter-checkbox:checked {
    background-color: #3b82f6;
    border-color: #3b82f6;
}

.filter-section input[type="text"] {
    font-size: 0.875rem;
}

/* Table cell styling */
.table-cell.styled-cell {
    border: 2px solid;
    padding: 4px 8px;
}

/* Ensure text is visible on solid backgrounds */
.bg-red-500, .bg-orange-500, .bg-yellow-500, .bg-green-500, 
.bg-blue-500, .bg-purple-500, .bg-pink-500, .bg-indigo-500,
.bg-teal-500, .bg-cyan-500, .bg-gray-500, .bg-lime-500,
.bg-amber-500, .bg-emerald-500, .bg-violet-500, .bg-fuchsia-500,
.bg-rose-500, .bg-sky-500 {
    color: white !important;
    font-weight: 600;
}

/* Custom color cell styling */
.custom-color-cell {
    padding: 4px 8px;
}

/* Enhanced text color support */
.text-red-300 { color: rgb(252, 165, 165) !important; }
.text-orange-300 { color: rgb(253, 186, 116) !important; }
.text-yellow-300 { color: rgb(253, 224, 71) !important; }
.text-green-300 { color: rgb(134, 239, 172) !important; }
.text-blue-300 { color: rgb(147, 197, 253) !important; }
.text-purple-300 { color: rgb(196, 181, 253) !important; }
.text-pink-300 { color: rgb(249, 168, 212) !important; }
.text-indigo-300 { color: rgb(165, 180, 252) !important; }
.text-teal-300 { color: rgb(94, 234, 212) !important; }
.text-cyan-300 { color: rgb(103, 232, 249) !important; }
.text-white { color: rgb(255, 255, 255) !important; }
.text-gray-300 { color: rgb(209, 213, 219) !important; }
.text-gray-400 { color: rgb(156, 163, 175) !important; }

/* Ensure text colors work on all backgrounds */
.editable-cell,
.editable-cell div {
    transition: color 0.2s ease;
}

/* Force text color inheritance */
.editable-cell * {
    color: inherit !important;
}

/* Enhanced selection styles */
.editable-cell.selected {
    position: relative;
    z-index: 10;
    animation: pulseSelection 0.3s ease-in-out;
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
    animation: pulseBorder 1.5s ease-in-out infinite;
}

/* Enhanced drag highlight */
.editable-cell.selectable.drag-highlight {
    background-color: rgba(59, 130, 246, 0.3) !important;
    border-color: #3b82f6 !important;
    z-index: 20;
    transition: all 0.1s ease;
}

/* Force selection styles to override other styles */
.editable-cell.selected {
    border-color: #3b82f6 !important;
}

/* Ensure selection is visible on all background colors */
.bg-red-500\/20.selected,
.bg-orange-500\/20.selected,
.bg-yellow-500\/20.selected,
.bg-green-500\/20.selected,
.bg-blue-500\/20.selected,
.bg-purple-500\/20.selected,
.bg-pink-500\/20.selected,
.bg-indigo-500\/20.selected,
.bg-teal-500\/20.selected,
.bg-cyan-500\/20.selected,
.bg-gray-500\/20.selected,
.bg-lime-500\/20.selected,
.bg-amber-500\/20.selected,
.bg-emerald-500\/20.selected,
.bg-violet-500\/20.selected,
.bg-fuchsia-500\/20.selected,
.custom-color-cell.selected {
    position: relative;
    z-index: 30;
}

/* Solid background selection enhancement */
.bg-red-500.selected,
.bg-orange-500.selected,
.bg-yellow-500.selected,
.bg-green-500.selected,
.bg-blue-500.selected,
.bg-purple-500.selected,
.bg-pink-500.selected,
.bg-indigo-500.selected,
.bg-teal-500.selected,
.bg-cyan-500.selected,
.bg-gray-500.selected,
.bg-lime-500.selected,
.bg-amber-500.selected,
.bg-emerald-500.selected,
.bg-violet-500.selected,
.bg-fuchsia-500.selected,
.bg-rose-500.selected,
.bg-sky-500.selected {
    position: relative;
    z-index: 30;
}

/* Copy source indicator enhancement */
.editable-cell.copy-source {
    position: relative;
    animation: copyPulse 1s ease-in-out infinite;
}


.editable-cell.copy-source::before {
    content: '📋';
    position: absolute;
    top: -8px;
    right: -8px;
    font-size: 12px;
    background: #10b981;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 40;
    animation: bounce 0.5s ease-in-out;
}


/* Hide only the drag selection overlay (blue square) */
.drag-selection-overlay {
    display: none !important;
}

/* Keep the drag highlight styling on cells */
.editable-cell.selectable.drag-highlight {
    /* This will keep the blue background highlight on cells during drag */
    background-color: rgba(59, 130, 246, 0.3) !important;
    border-color: #3b82f6 !important;
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

// Custom color variables
let customColor = '#3b82f6';
let customColorIsSolid = false;

// Drag selection variables
let isDragging = false;
let dragStartCell = null;
let dragSelectionOverlay = null;
let dragStartX = 0;
let dragStartY = 0;

// Filter state management
let activeFilters = {
    site: [],
    monday: [],
    tuesday: [],
    wednesday: [],
    thursday: [],
    friday: [],
    saturday: [],
    sunday: []
};

// Enhanced color palettes with solid colors
const backgroundColors = [
    // Transparent colors
    { name: 'Red Transparent', value: 'bg-red-500/20', border: 'border-red-500/30', type: 'transparent' },
    { name: 'Orange Transparent', value: 'bg-orange-500/20', border: 'border-orange-500/30', type: 'transparent' },
    { name: 'Yellow Transparent', value: 'bg-yellow-500/20', border: 'border-yellow-500/30', type: 'transparent' },
    { name: 'Green Transparent', value: 'bg-green-500/20', border: 'border-green-500/30', type: 'transparent' },
    { name: 'Blue Transparent', value: 'bg-blue-500/20', border: 'border-blue-500/30', type: 'transparent' },
    { name: 'Purple Transparent', value: 'bg-purple-500/20', border: 'border-purple-500/30', type: 'transparent' },
    { name: 'Pink Transparent', value: 'bg-pink-500/20', border: 'border-pink-500/30', type: 'transparent' },
    { name: 'Indigo Transparent', value: 'bg-indigo-500/20', border: 'border-indigo-500/30', type: 'transparent' },
    { name: 'Teal Transparent', value: 'bg-teal-500/20', border: 'border-teal-500/30', type: 'transparent' },
    { name: 'Cyan Transparent', value: 'bg-cyan-500/20', border: 'border-cyan-500/30', type: 'transparent' },
    { name: 'Gray Transparent', value: 'bg-gray-500/20', border: 'border-gray-500/30', type: 'transparent' },
    { name: 'Lime Transparent', value: 'bg-lime-500/20', border: 'border-lime-500/30', type: 'transparent' },
    { name: 'Amber Transparent', value: 'bg-amber-500/20', border: 'border-amber-500/30', type: 'transparent' },
    { name: 'Emerald Transparent', value: 'bg-emerald-500/20', border: 'border-emerald-500/30', type: 'transparent' },
    { name: 'Violet Transparent', value: 'bg-violet-500/20', border: 'border-violet-500/30', type: 'transparent' },
    { name: 'Fuchsia Transparent', value: 'bg-fuchsia-500/20', border: 'border-fuchsia-500/30', type: 'transparent' },
    
    // Solid colors
    { name: 'Red Solid', value: 'bg-red-500', border: 'border-red-500', type: 'solid' },
    { name: 'Orange Solid', value: 'bg-orange-500', border: 'border-orange-500', type: 'solid' },
    { name: 'Yellow Solid', value: 'bg-yellow-500', border: 'border-yellow-500', type: 'solid' },
    { name: 'Green Solid', value: 'bg-green-500', border: 'border-green-500', type: 'solid' },
    { name: 'Blue Solid', value: 'bg-blue-500', border: 'border-blue-500', type: 'solid' },
    { name: 'Purple Solid', value: 'bg-purple-500', border: 'border-purple-500', type: 'solid' },
    { name: 'Pink Solid', value: 'bg-pink-500', border: 'border-pink-500', type: 'solid' },
    { name: 'Indigo Solid', value: 'bg-indigo-500', border: 'border-indigo-500', type: 'solid' },
    { name: 'Teal Solid', value: 'bg-teal-500', border: 'border-teal-500', type: 'solid' },
    { name: 'Cyan Solid', value: 'bg-cyan-500', border: 'border-cyan-500', type: 'solid' },
    { name: 'Gray Solid', value: 'bg-gray-500', border: 'border-gray-500', type: 'solid' },
    { name: 'Lime Solid', value: 'bg-lime-500', border: 'border-lime-500', type: 'solid' },
    { name: 'Amber Solid', value: 'bg-amber-500', border: 'border-amber-500', type: 'solid' },
    { name: 'Emerald Solid', value: 'bg-emerald-500', border: 'border-emerald-500', type: 'solid' },
    { name: 'Violet Solid', value: 'bg-violet-500', border: 'border-violet-500', type: 'solid' },
    { name: 'Fuchsia Solid', value: 'bg-fuchsia-500', border: 'border-fuchsia-500', type: 'solid' },
    { name: 'Rose Solid', value: 'bg-rose-500', border: 'border-rose-500', type: 'solid' },
    { name: 'Sky Solid', value: 'bg-sky-500', border: 'border-sky-500', type: 'solid' },
    
    { name: 'None', value: '', border: '', type: 'none' }
];

const textColors = [
    { name: 'Red', value: 'text-red-300' },
    { name: 'Orange', value: 'text-orange-300' },
    { name: 'Yellow', value: 'text-yellow-300' },
    { name: 'Green', value: 'text-green-300' },
    { name: 'Blue', value: 'text-blue-300' },
    { name: 'Purple', value: 'text-purple-300' },
    { name: 'Pink', value: 'text-pink-300' },
    { name: 'Indigo', value: 'text-indigo-300' },
    { name: 'Teal', value: 'text-teal-300' },
    { name: 'Cyan', value: 'text-cyan-300' },
    { name: 'White', value: 'text-white' },
    { name: 'Gray Light', value: 'text-gray-300' },
    { name: 'Gray Dark', value: 'text-gray-400' },
    { name: 'Default', value: '' }
];

let selectedBgColor = null;
let selectedTextColor = null;

// Column filter variables
let currentColumnFilter = null;
let columnFilterOptions = [];

// Custom color picker functions
function showCustomColorModal() {
    closeColorPickerModal();
    document.getElementById('customColorModal').classList.remove('hidden');
    updateCustomColorPreview();
}

function closeCustomColorModal() {
    document.getElementById('customColorModal').classList.add('hidden');
}

// Enhanced updateCustomColorPreview function
function updateCustomColorPreview() {
    const preview = document.getElementById('customColorPreview');
    const colorInput = document.getElementById('customColorInput');
    const isSolid = document.getElementById('applyAsSolid').checked;
    
    customColor = colorInput.value;
    customColorIsSolid = isSolid;
    
    // Get selected text color or use auto-contrast
    const textColor = selectedTextColor ? selectedTextColor.value : getContrastColor(customColor);
    
    if (isSolid) {
        preview.style.backgroundColor = customColor;
        preview.style.backgroundImage = 'none';
        preview.style.borderColor = customColor;
    } else {
        preview.style.backgroundColor = `${customColor}20`;
        preview.style.borderColor = `${customColor}30`;
    }
    
    // Apply text color
    if (textColor && textColor.startsWith('text-')) {
        // Remove any existing text color classes
        textColors.forEach(color => {
            if (color.value) preview.classList.remove(color.value);
        });
        preview.classList.add(textColor);
        preview.style.color = ''; // Clear inline color if using class
    } else if (textColor) {
        preview.style.color = textColor;
        // Remove any text color classes
        textColors.forEach(color => {
            if (color.value) preview.classList.remove(color.value);
        });
    }
}

function getContrastColor(hexcolor) {
    // Remove the # if present
    hexcolor = hexcolor.replace("#", "");
    
    // Convert to RGB
    const r = parseInt(hexcolor.substr(0, 2), 16);
    const g = parseInt(hexcolor.substr(2, 2), 16);
    const b = parseInt(hexcolor.substr(4, 2), 16);
    
    // Calculate luminance
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    
    // Return black or white based on luminance
    return luminance > 0.5 ? '#000000' : '#ffffff';
}

// Helper function to convert RGB to Hex
function rgbToHex(rgb) {
    // If it's already hex, return as is
    if (rgb.startsWith('#')) return rgb;
    
    // Convert rgb(r, g, b) to hex
    const result = rgb.match(/\d+/g);
    if (result) {
        const [r, g, b] = result;
        return "#" + ((1 << 24) + (+r << 16) + (+g << 8) + +b).toString(16).slice(1);
    }
    return rgb;
}

// ENHANCED: Extract cell styles more reliably
function extractCellStyles(cell) {
    const styles = {
        bgColor: null,
        border: null,
        textColor: null,
        isCustom: cell.classList.contains('custom-color-cell')
    };
    
    if (styles.isCustom) {
        // Extract custom styles from inline styles
        styles.customColor = cell.style.backgroundColor || 
                           cell.style.borderColor?.replace('30', '').replace('20', '') || 
                           '#3b82f6';
        
        // Detect if solid or transparent
        styles.isSolid = cell.style.backgroundColor && 
                        !cell.style.backgroundColor.includes('20') && 
                        !cell.style.backgroundColor.includes('0.2');
        
        // Get text color
        const computedStyle = getComputedStyle(cell);
        styles.textColor = cell.style.color || computedStyle.color;
        
        // Convert RGB to hex if needed
        if (styles.textColor && styles.textColor.startsWith('rgb')) {
            styles.textColor = rgbToHex(styles.textColor);
        }
    } else {
        // Extract predefined styles from classes
        backgroundColors.forEach(color => {
            if (cell.classList.contains(color.value)) {
                styles.bgColor = color.value;
                styles.border = color.border;
            }
        });
        
        textColors.forEach(color => {
            if (cell.classList.contains(color.value)) {
                styles.textColor = color.value;
            }
        });
    }
    
    return styles;
}

// Enhanced applyCustomColor function
function applyCustomColor() {
    if (selectedCells.size === 0) {
        showTemporaryMessage('No cells selected', 'warning');
        return;
    }

    const style = {
        isCustom: true,
        customColor: customColor,
        isSolid: customColorIsSolid,
        textColor: selectedTextColor ? selectedTextColor.value : getContrastColor(customColor)
    };

    selectedCells.forEach(cell => {
        applyStyleToCell(cell, style, true);
    });

    showTemporaryMessage(`Custom color applied to ${selectedCells.size} cells`, 'success');
    closeCustomColorModal();
    clearSelection();
}

// Show column filter modal
function showColumnFilter(field, label) {
    currentColumnFilter = field;
    document.getElementById('columnFilterTitle').textContent = `Filter ${label}`;
    
    // Get unique values for this column
    columnFilterOptions = getUniqueValuesForField(field);
    
    populateColumnFilterOptions();
    updateColumnSelectedCount();
    
    document.getElementById('columnFilterModal').classList.remove('hidden');
    document.getElementById('filterSearchInput').value = '';
    document.getElementById('filterSearchInput').focus();
}

// Close column filter modal
function closeColumnFilterModal() {
    document.getElementById('columnFilterModal').classList.add('hidden');
    currentColumnFilter = null;
}

// Populate column filter options
function populateColumnFilterOptions(searchTerm = '') {
    const container = document.getElementById('columnFilterOptions');
    container.innerHTML = '';
    
    const filteredOptions = columnFilterOptions.filter(option => 
        option.toLowerCase().includes(searchTerm.toLowerCase())
    );
    
    filteredOptions.forEach(value => {
        const isChecked = activeFilters[currentColumnFilter].includes(value);
        const optionDiv = document.createElement('label');
        optionDiv.className = 'flex items-center space-x-2 p-1 hover:bg-gray-700/50 rounded cursor-pointer filter-option';
        optionDiv.innerHTML = `
            <input type="checkbox" 
                   value="${value}" 
                   class="column-filter-checkbox rounded border-gray-600 bg-gray-700 text-primary-600 focus:ring-primary-500"
                   ${isChecked ? 'checked' : ''}
                   onchange="updateColumnSelectedCount()">
            <span class="text-sm text-gray-300">${escapeHtml(value)}</span>
        `;
        container.appendChild(optionDiv);
    });
    
    if (filteredOptions.length === 0) {
        container.innerHTML = '<div class="text-sm text-gray-400 text-center p-2">No matching values</div>';
    }
}

// Filter column options based on search
function filterColumnOptions() {
    const searchTerm = document.getElementById('filterSearchInput').value;
    populateColumnFilterOptions(searchTerm);
}

// Select all column options
function selectAllColumnOptions() {
    document.querySelectorAll('.column-filter-checkbox').forEach(checkbox => {
        checkbox.checked = true;
    });
    updateColumnSelectedCount();
}

// Clear column options
function clearColumnOptions() {
    document.querySelectorAll('.column-filter-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    updateColumnSelectedCount();
}

// Update column selected count
function updateColumnSelectedCount() {
    const selectedCount = document.querySelectorAll('.column-filter-checkbox:checked').length;
    document.getElementById('columnSelectedCount').textContent = `${selectedCount} selected`;
}

// Apply column filter
function applyColumnFilter() {
    const selectedValues = [];
    document.querySelectorAll('.column-filter-checkbox:checked').forEach(checkbox => {
        selectedValues.push(checkbox.value);
    });
    
    activeFilters[currentColumnFilter] = selectedValues;
    updateActiveFilterCount();
    applyFiltersToTable();
    closeColumnFilterModal();
}

// Update the populateFilterSections function to include search
function populateFilterSections() {
    const filterSections = document.getElementById('filterSections');
    filterSections.innerHTML = '';
    
    const fields = ['site', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    const fieldLabels = {
        site: 'Site',
        monday: 'Monday',
        tuesday: 'Tuesday',
        wednesday: 'Wednesday',
        thursday: 'Thursday',
        friday: 'Friday',
        saturday: 'Saturday',
        sunday: 'Sunday'
    };
    
    fields.forEach(field => {
        const uniqueValues = getUniqueValuesForField(field);
        const filterSection = createFilterSection(field, fieldLabels[field], uniqueValues);
        filterSections.appendChild(filterSection);
    });
}

// Update createFilterSection to include search
function createFilterSection(field, label, values) {
    const section = document.createElement('div');
    section.className = 'filter-section';
    section.innerHTML = `
        <div class="mb-3">
            <h4 class="font-semibold text-gray-200 mb-2">${label}</h4>
            
            <!-- Search input for filter section -->
            <div class="mb-3">
                <input type="text" 
                       id="search-${field}"
                       class="w-full px-3 py-1 bg-gray-700 border border-gray-600 rounded-md text-gray-200 text-sm"
                       placeholder="Search ${label}..."
                       onkeyup="filterSectionOptions('${field}')">
            </div>
            
            <div class="flex space-x-2 mb-3">
                <button type="button" onclick="selectAllFilterOptions('${field}')" 
                        class="text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 px-2 py-1 rounded">
                    Select All
                </button>
                <button type="button" onclick="clearFilterOptions('${field}')" 
                        class="text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 px-2 py-1 rounded">
                    Clear
                </button>
            </div>
            <div class="max-h-48 overflow-y-auto border border-gray-600 rounded-lg bg-gray-900/50">
                <div class="p-2 space-y-1" id="filter-options-${field}">
                    ${values.map(value => `
                        <label class="flex items-center space-x-2 p-1 hover:bg-gray-700/50 rounded cursor-pointer filter-option">
                            <input type="checkbox" 
                                   value="${value}" 
                                   data-field="${field}"
                                   class="filter-checkbox rounded border-gray-600 bg-gray-700 text-primary-600 focus:ring-primary-500"
                                   ${activeFilters[field].includes(value) ? 'checked' : ''}>
                            <span class="text-sm text-gray-300">${escapeHtml(value)}</span>
                        </label>
                    `).join('')}
                </div>
            </div>
        </div>
    `;
    
    return section;
}

// Filter options within a section
function filterSectionOptions(field) {
    const searchTerm = document.getElementById(`search-${field}`).value.toLowerCase();
    const options = document.querySelectorAll(`#filter-options-${field} .filter-option`);
    
    options.forEach(option => {
        const text = option.querySelector('span').textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            option.style.display = 'flex';
        } else {
            option.style.display = 'none';
        }
    });
}

// Update the initialize function to include column filter modal
function initializeAdvancedFilters() {
    document.getElementById('advancedFilterModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAdvancedFilterModal();
        }
    });
    
    document.getElementById('columnFilterModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeColumnFilterModal();
        }
    });
    
    document.getElementById('customColorModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeCustomColorModal();
        }
    });
}

// Add event listener for Escape key to close column filter modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('columnFilterModal')) {
            closeColumnFilterModal();
        }
        if (document.getElementById('customColorModal')) {
            closeCustomColorModal();
        }
    }
});

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeFilters();
    loadSchedules();
    initializeColorPalettes();
    initializeKeyboardShortcuts();
    initializeNotesTooltips();
    initializeDepartmentFiltering();
    initializeDragSelection();
    initializeAdvancedFilters();
    updateDisplayCount();
    
    // Custom color picker events
    const colorInput = document.getElementById('customColorInput');
    const solidCheckbox = document.getElementById('applyAsSolid');
    
    if (colorInput) {
        colorInput.addEventListener('input', updateCustomColorPreview);
    }
    
    if (solidCheckbox) {
        solidCheckbox.addEventListener('change', updateCustomColorPreview);
    }
});

// Enhanced drag selection functionality
function initializeDragSelection() {
    document.addEventListener('mousedown', handleDragStart);
    document.addEventListener('mousemove', handleDragMove);
    document.addEventListener('mouseup', handleDragEnd);
    
    // Create drag selection overlay
    dragSelectionOverlay = document.createElement('div');
    dragSelectionOverlay.className = 'drag-selection-overlay hidden';
    document.body.appendChild(dragSelectionOverlay);
}

// Enhanced handle drag start
function handleDragStart(e) {
    const cell = e.target.closest('.editable-cell.selectable');
    if (!cell) return;
    
    // Only start drag selection if Ctrl key is pressed
    if (!e.ctrlKey && !e.metaKey) return;
    
    // Prevent default to avoid text selection
    e.preventDefault();
    
    isDragging = true;
    dragStartCell = cell;
    dragStartX = e.clientX;
    dragStartY = e.clientY;
    
    // Add drag selecting class to table
    const table = document.querySelector('table');
    if (table) {
        table.classList.add('drag-selecting');
    }
    
    // Show drag selection overlay
    dragSelectionOverlay.classList.remove('hidden');
    dragSelectionOverlay.style.left = `${dragStartX}px`;
    dragSelectionOverlay.style.top = `${dragStartY}px`;
    dragSelectionOverlay.style.width = '0';
    dragSelectionOverlay.style.height = '0';
    
    // Clear current selection if not holding Shift
    if (!e.shiftKey) {
        clearSelection();
    }
    
    // Add the starting cell to selection
    toggleCellSelection(cell);
    
    // Force a re-render of the selection
    updateSelectionVisuals();
}

// Enhanced handle drag movement
function handleDragMove(e) {
    if (!isDragging) return;
    
    const currentX = e.clientX;
    const currentY = e.clientY;
    
    // Update drag selection overlay
    const left = Math.min(dragStartX, currentX);
    const top = Math.min(dragStartY, currentY);
    const width = Math.abs(currentX - dragStartX);
    const height = Math.abs(currentY - dragStartY);
    
    dragSelectionOverlay.style.left = `${left}px`;
    dragSelectionOverlay.style.top = `${top}px`;
    dragSelectionOverlay.style.width = `${width}px`;
    dragSelectionOverlay.style.height = `${height}px`;
    
    // Find and select cells within the drag area
    const selectableCells = document.querySelectorAll('.editable-cell.selectable');
    const newlySelectedCells = new Set();
    
    selectableCells.forEach(cell => {
        const rect = cell.getBoundingClientRect();
        const cellCenterX = rect.left + rect.width / 2;
        const cellCenterY = rect.top + rect.height / 2;
        
        const isInSelection = 
            cellCenterX >= left && 
            cellCenterX <= left + width &&
            cellCenterY >= top && 
            cellCenterY <= top + height;
        
        if (isInSelection) {
            newlySelectedCells.add(cell);
            if (!selectedCells.has(cell)) {
                selectedCells.add(cell);
                cell.classList.add('selected', 'drag-highlight');
            }
        } else {
            cell.classList.remove('drag-highlight');
            // Only remove if it wasn't in the initial selection and we're not holding shift
            if (cell !== dragStartCell && !e.shiftKey) {
                selectedCells.delete(cell);
                cell.classList.remove('selected');
            }
        }
    });
    
    // Ensure drag start cell stays selected
    if (dragStartCell && !selectedCells.has(dragStartCell)) {
        selectedCells.add(dragStartCell);
        dragStartCell.classList.add('selected');
    }
    
    updateSelectionToolbar();
    updateSelectionVisuals();
}

// Enhanced handle drag end
function handleDragEnd() {
    if (!isDragging) return;
    
    isDragging = false;
    
    // Remove drag selecting class from table
    const table = document.querySelector('table');
    if (table) {
        table.classList.remove('drag-selecting');
    }
    
    // Hide drag selection overlay
    dragSelectionOverlay.classList.add('hidden');
    
    // Remove drag highlight from all cells
    document.querySelectorAll('.editable-cell.drag-highlight').forEach(cell => {
        cell.classList.remove('drag-highlight');
    });
    
    // Force a final visual update
    updateSelectionVisuals();
    
    dragStartCell = null;
}

// Enhanced updateSelectionVisuals for multiple cells
function updateSelectionVisuals() {
    const selectedCellsArray = Array.from(selectedCells);
    
    // Batch update to improve performance
    selectedCellsArray.forEach((cell, index) => {
        // Use setTimeout to stagger updates and prevent UI blocking
        setTimeout(() => {
            // Force reflow and style update
            cell.classList.remove('selected');
            void cell.offsetWidth; // Trigger reflow
            cell.classList.add('selected');
        }, index * 5); // Small delay between each cell
    });
    
    updateSelectionToolbar();
}

// Initialize advanced filters
function initializeAdvancedFilters() {
    document.getElementById('advancedFilterModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAdvancedFilterModal();
        }
    });
}

// Show advanced filter modal
function showAdvancedFilterModal() {
    populateFilterSections();
    updateActiveFilterCount();
    document.getElementById('advancedFilterModal').classList.remove('hidden');
}

// Close advanced filter modal
function closeAdvancedFilterModal() {
    document.getElementById('advancedFilterModal').classList.add('hidden');
}

// Populate filter sections with unique values from data
function populateFilterSections() {
    const filterSections = document.getElementById('filterSections');
    filterSections.innerHTML = '';
    
    const fields = ['site', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    const fieldLabels = {
        site: 'Site',
        monday: 'Monday',
        tuesday: 'Tuesday',
        wednesday: 'Wednesday',
        thursday: 'Thursday',
        friday: 'Friday',
        saturday: 'Saturday',
        sunday: 'Sunday'
    };
    
    fields.forEach(field => {
        const uniqueValues = getUniqueValuesForField(field);
        const filterSection = createFilterSection(field, fieldLabels[field], uniqueValues);
        filterSections.appendChild(filterSection);
    });
}

// Get unique values for a specific field from the table
function getUniqueValuesForField(field) {
    const cells = document.querySelectorAll(`.editable-cell[data-field="${field}"]`);
    const values = new Set();
    
    cells.forEach(cell => {
        const value = cell.getAttribute('data-original-value') || cell.textContent.trim();
        if (value && value !== '-') {
            values.add(value);
        } else {
            values.add('(Blanks)');
        }
    });
    
    return Array.from(values).sort();
}

// Create filter section HTML
function createFilterSection(field, label, values) {
    const section = document.createElement('div');
    section.className = 'filter-section';
    section.innerHTML = `
        <div class="mb-3">
            <h4 class="font-semibold text-gray-200 mb-2">${label}</h4>
            <div class="flex space-x-2 mb-3">
                <button type="button" onclick="selectAllFilterOptions('${field}')" 
                        class="text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 px-2 py-1 rounded">
                    Select All
                </button>
                <button type="button" onclick="clearFilterOptions('${field}')" 
                        class="text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 px-2 py-1 rounded">
                    Clear
                </button>
            </div>
            <div class="max-h-48 overflow-y-auto border border-gray-600 rounded-lg bg-gray-900/50">
                <div class="p-2 space-y-1" id="filter-options-${field}">
                    ${values.map(value => `
                        <label class="flex items-center space-x-2 p-1 hover:bg-gray-700/50 rounded cursor-pointer filter-option">
                            <input type="checkbox" 
                                   value="${value}" 
                                   data-field="${field}"
                                   class="filter-checkbox rounded border-gray-600 bg-gray-700 text-primary-600 focus:ring-primary-500"
                                   ${activeFilters[field].includes(value) ? 'checked' : ''}>
                            <span class="text-sm text-gray-300">${escapeHtml(value)}</span>
                        </label>
                    `).join('')}
                </div>
            </div>
        </div>
    `;
    
    return section;
}

// Select all options in a filter
function selectAllFilterOptions(field) {
    const checkboxes = document.querySelectorAll(`#filter-options-${field} input[type="checkbox"]`);
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
}

// Clear all options in a filter
function clearFilterOptions(field) {
    const checkboxes = document.querySelectorAll(`#filter-options-${field} input[type="checkbox"]`);
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
}

// Clear all filters
function clearAllFilters() {
    const fields = ['site', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    fields.forEach(field => {
        activeFilters[field] = [];
        const checkboxes = document.querySelectorAll(`#filter-options-${field} input[type="checkbox"]`);
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
    });
    updateActiveFilterCount();
    applyAdvancedFilters();
}

// Update active filter count display
function updateActiveFilterCount() {
    let totalActive = 0;
    Object.values(activeFilters).forEach(filters => {
        totalActive += filters.length;
    });
    
    document.getElementById('activeFilterCount').textContent = `${totalActive} filter${totalActive !== 1 ? 's' : ''} active`;
    document.getElementById('activeFilterCountBadge').textContent = `${totalActive} filter${totalActive !== 1 ? 's' : ''}`;
    
    const filterStatus = document.getElementById('filterStatus');
    if (totalActive > 0) {
        filterStatus.classList.remove('hidden');
    } else {
        filterStatus.classList.add('hidden');
    }
}

// Apply advanced filters
function applyAdvancedFilters() {
    // Collect current filter states
    const newFilters = {
        site: [],
        monday: [],
        tuesday: [],
        wednesday: [],
        thursday: [],
        friday: [],
        saturday: [],
        sunday: []
    };
    
    // Get all checked filter options
    document.querySelectorAll('.filter-checkbox:checked').forEach(checkbox => {
        const field = checkbox.getAttribute('data-field');
        const value = checkbox.value;
        newFilters[field].push(value);
    });
    
    activeFilters = newFilters;
    updateActiveFilterCount();
    applyFiltersToTable();
    closeAdvancedFilterModal();
}

// Apply filters to the table
function applyFiltersToTable() {
    const rows = document.querySelectorAll('tbody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return; // Skip "no schedules" row
        
        let shouldShow = true;
        
        // Check each field's filters
        Object.keys(activeFilters).forEach(field => {
            if (activeFilters[field].length > 0) {
                const cell = row.querySelector(`.editable-cell[data-field="${field}"]`);
                if (cell) {
                    const cellValue = cell.getAttribute('data-original-value') || cell.textContent.trim();
                    const displayValue = (cellValue && cellValue !== '-') ? cellValue : '(Blanks)';
                    
                    if (!activeFilters[field].includes(displayValue)) {
                        shouldShow = false;
                    }
                }
            }
        });
        
        if (shouldShow) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Update display count
    document.getElementById('displayCount').textContent = visibleCount;
    
    // Show message if no rows are visible
    const noSchedulesRow = document.querySelector('tbody tr td[colspan]');
    if (noSchedulesRow) {
        if (visibleCount === 0 && Object.values(activeFilters).some(filters => filters.length > 0)) {
            noSchedulesRow.parentElement.style.display = '';
            noSchedulesRow.colSpan = 16;
            noSchedulesRow.innerHTML = `
                <td colspan="16" class="px-6 py-8 text-center text-gray-400">
                    <i class="fas fa-filter text-3xl mb-3 opacity-50"></i>
                    <p class="text-lg">No schedules match your filters</p>
                    <p class="text-sm mt-1">Try adjusting your filter criteria</p>
                </td>
            `;
        } else if (visibleCount === 0) {
            noSchedulesRow.parentElement.style.display = '';
        } else {
            noSchedulesRow.parentElement.style.display = 'none';
        }
    }
    
    // Update the display count
    updateDisplayCount();
}

// Enhanced text color selection
function initializeColorPalettes() {
    const bgPalette = document.getElementById('bgColorPalette');
    const textPalette = document.getElementById('textColorPalette');
    
    backgroundColors.forEach(color => {
        const colorDiv = document.createElement('div');
        colorDiv.className = `color-option ${color.value} ${color.border} ${color.value ? 'border' : ''}`;
        colorDiv.title = color.name;
        colorDiv.onclick = () => selectColor('bg', color);
        
        // Add indicator for solid colors
        if (color.type === 'solid') {
            colorDiv.innerHTML = 'S';
            colorDiv.style.color = 'white';
            colorDiv.style.fontWeight = 'bold';
        } else if (color.type === 'transparent') {
            colorDiv.innerHTML = 'T';
            colorDiv.style.color = 'rgba(255,255,255,0.8)';
        }
        
        bgPalette.appendChild(colorDiv);
    });
    
    textColors.forEach(color => {
        const colorDiv = document.createElement('div');
        colorDiv.className = `color-option bg-gray-700 ${color.value}`;
        colorDiv.innerHTML = 'A';
        colorDiv.title = color.name;
        colorDiv.onclick = () => {
            selectColor('text', color);
            updateCustomColorPreview(); // Update preview when text color changes
        };
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
        
        // Update display count based on visible rows
        updateDisplayCount();
        applyFiltersToTable(); // Apply any active filters after loading
    })
    .catch(error => {
        console.error('Error loading schedules:', error);
        showTemporaryMessage('Error loading schedules', 'error');
    });
}

// Update display count based on visible rows
function updateDisplayCount() {
    const rows = document.querySelectorAll('tbody tr:not([style*="display: none"])');
    const visibleCount = Array.from(rows).filter(row => !row.querySelector('td[colspan]')).length;
    document.getElementById('displayCount').textContent = visibleCount;
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
    
    // Add selectable class to site and day cells only
    document.querySelectorAll('.editable-cell').forEach(cell => {
        const field = cell.getAttribute('data-field');
        if (field === 'site' || ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].includes(field)) {
            cell.classList.add('selectable');
        }
    });
}

// Enhanced toggle cell selection
function toggleCellSelection(cell) {
    if (selectedCells.has(cell)) {
        selectedCells.delete(cell);
        cell.classList.remove('selected');
    } else {
        selectedCells.add(cell);
        cell.classList.add('selected');
    }
    updateSelectionVisuals();
}

// Enhanced clear selection
function clearSelection() {
    selectedCells.forEach(cell => {
        cell.classList.remove('selected', 'copy-source', 'drag-highlight');
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

// ENHANCED copy selected cells with better style handling
function copySelectedCells() {
    if (selectedCells.size === 0) {
        showTemporaryMessage('No cells selected to copy', 'warning');
        return;
    }

    if (selectedCells.size === 1) {
        // Single cell copy
        const cell = Array.from(selectedCells)[0];
        copiedValue = cell.getAttribute('data-original-value') || cell.textContent.trim();
        copiedStyle = extractCellStyles(cell);
        
        cell.classList.add('copy-source');
        showTemporaryMessage('Cell copied! Use Ctrl+V to paste', 'success');
        
        setTimeout(() => {
            cell.classList.remove('copy-source');
        }, 2000);
    } else {
        // Multiple cells selected - show warning
        showTemporaryMessage('Please select only one cell to copy from', 'warning');
    }
}

// Enhanced paste to selected cells for multiple selection
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
    let pastePromises = [];
    
    // Convert to array for proper iteration
    const selectedCellsArray = Array.from(selectedCells);
    const copySourceCell = document.querySelector('.copy-source');
    
    selectedCellsArray.forEach(cell => {
        // Skip the source cell if it's in the selection
        if (cell === copySourceCell) {
            return;
        }
        
        // Create a promise for each paste operation
        const pastePromise = new Promise((resolve) => {
            setTimeout(() => {
                if (copiedValue !== null) {
                    const field = cell.getAttribute('data-field');
                    const scheduleId = cell.getAttribute('data-schedule-id');
                    
                    // Capitalize the pasted value
                    const capitalizedValue = copiedValue.toUpperCase();
                    const displayValue = capitalizedValue || '-';
                    
                    // Update the cell display immediately
                    cell.innerHTML = `<div class="px-2 py-1 text-center">${escapeHtml(displayValue)}</div>`;
                    cell.setAttribute('data-original-value', capitalizedValue);
                    
                    // Save to database
                    saveCellValue(scheduleId, field, capitalizedValue)
                        .then(() => {
                            pasteCount++;
                            resolve();
                        })
                        .catch(() => {
                            // Even if save fails, count it as pasted for UI
                            pasteCount++;
                            resolve();
                        });
                } else {
                    // If only style is being pasted
                    pasteCount++;
                    resolve();
                }
                
                // ENHANCED: Handle style paste with proper database saving
                if (copiedStyle) {
                    const field = cell.getAttribute('data-field');
                    const scheduleId = cell.getAttribute('data-schedule-id');
                    const styleString = JSON.stringify(copiedStyle);
                    
                    // Apply style visually first
                    applyStyleToCell(cell, copiedStyle, false);
                    
                    // Then save to database
                    saveCellStyle(scheduleId, field, styleString)
                        .then(() => {
                            // Style saved successfully
                        })
                        .catch(() => {
                            console.error('Failed to save style for cell:', cell);
                        });
                }
            }, 10); // Small delay to prevent UI blocking
        });
        
        pastePromises.push(pastePromise);
    });

    // Wait for all paste operations to complete
    Promise.all(pastePromises).then(() => {
        showTemporaryMessage(`Pasted to ${pasteCount} cells`, 'success');
        
        // Force visual update after all pastes are complete
        setTimeout(() => {
            updateSelectionVisuals();
            clearSelection();
        }, 100);
    });
}

// Enhanced save cell value with promise
function saveCellValue(scheduleId, field, value) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('update_schedule', '1');
        formData.append('schedule_id', scheduleId);
        formData.append('field', field);
        formData.append('value', value);

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
            resolve(data);
        })
        .catch(error => {
            console.error('Error saving pasted cell:', error);
            reject(error);
        });
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

// Enhanced applyCellStyle function for predefined colors
function applyCellStyle() {
    if (selectedCells.size === 0) {
        showTemporaryMessage('No cells selected', 'warning');
        return;
    }

    const style = {
        bgColor: selectedBgColor ? selectedBgColor.value : null,
        border: selectedBgColor ? selectedBgColor.border : null,
        textColor: selectedTextColor ? selectedTextColor.value : null,
        isCustom: false
    };

    selectedCells.forEach(cell => {
        applyStyleToCell(cell, style, true);
    });

    showTemporaryMessage(`Style applied to ${selectedCells.size} cells`, 'success');
    closeColorPickerModal();
    clearSelection();
}

// Enhanced applyStyleToCell for multiple cells
function applyStyleToCell(cell, style, saveToDB = false) {
    // Remove existing styling classes
    backgroundColors.forEach(color => {
        if (color.value) cell.classList.remove(color.value);
        if (color.border) cell.classList.remove(color.border);
    });
    textColors.forEach(color => {
        if (color.value) cell.classList.remove(color.value);
    });
    
    // Remove any existing custom styles
    cell.classList.remove('custom-color-cell');
    cell.style.backgroundColor = '';
    cell.style.borderColor = '';
    cell.style.color = '';

    // Apply new styling
    if (style.isCustom) {
        // Apply custom color styles
        cell.classList.add('custom-color-cell');
        if (style.isSolid) {
            cell.style.backgroundColor = style.customColor;
            cell.style.borderColor = style.customColor;
        } else {
            cell.style.backgroundColor = `${style.customColor}20`;
            cell.style.borderColor = `${style.customColor}30`;
        }
        // Apply text color
        if (style.textColor) {
            if (style.textColor.startsWith('text-')) {
                cell.classList.add(style.textColor);
            } else {
                cell.style.color = style.textColor;
            }
        } else {
            // Auto-determine text color based on background
            cell.style.color = getContrastColor(style.customColor);
        }
    } else {
        // Apply predefined styles
        if (style.bgColor) cell.classList.add(style.bgColor);
        if (style.border) cell.classList.add(style.border);
        if (style.textColor) cell.classList.add(style.textColor);
    }

    // Add styled-cell class for border styling
    if (style.border || style.isCustom) {
        cell.classList.add('styled-cell');
    } else {
        cell.classList.remove('styled-cell');
    }

    // Save to database if requested
    if (saveToDB) {
        const field = cell.getAttribute('data-field');
        const scheduleId = cell.getAttribute('data-schedule-id');
        const styleString = JSON.stringify(style);
        
        saveCellStyle(scheduleId, field, styleString);
    }
    
    // Force immediate visual update
    void cell.offsetWidth; // Trigger reflow
}

// Enhanced save cell style with better error handling
function saveCellStyle(scheduleId, field, style) {
    return new Promise((resolve, reject) => {
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
            if (data.success) {
                resolve(data);
            } else {
                console.error('Error saving style:', data.message);
                reject(new Error(data.message));
            }
        })
        .catch(error => {
            console.error('Error saving style:', error);
            reject(error);
        });
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
        
        // Remove custom styles
        cell.classList.remove('custom-color-cell');
        cell.style.backgroundColor = '';
        cell.style.borderColor = '';
        cell.style.color = '';
        
        // Remove styled-cell class
        cell.classList.remove('styled-cell');
        
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
        bgColor: Array.from(cell.classList).find(cls => cls.startsWith('bg-') && (cls.includes('/20') || cls.includes('-500'))),
        border: Array.from(cell.classList).find(cls => cls.startsWith('border-') && (cls.includes('/30') || cls.includes('-500'))),
        textColor: Array.from(cell.classList).find(cls => cls.startsWith('text-') && !cls.includes('hover:')),
        isCustom: cell.classList.contains('custom-color-cell')
    };
    
    // If it's a custom color, store the inline styles
    if (currentStyles.isCustom) {
        currentStyles.customColor = cell.style.backgroundColor || cell.style.borderColor;
        currentStyles.isSolid = !cell.style.backgroundColor.includes('20');
    }
    
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
    
    // Remove custom styles temporarily
    cell.classList.remove('custom-color-cell');
    cell.style.backgroundColor = '';
    cell.style.borderColor = '';
    cell.style.color = '';
    
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
            if (originalStyles.isCustom) {
                cell.classList.add('custom-color-cell');
                if (originalStyles.isSolid) {
                    cell.style.backgroundColor = originalStyles.customColor;
                    cell.style.borderColor = originalStyles.customColor;
                } else {
                    cell.style.backgroundColor = `${originalStyles.customColor}20`;
                    cell.style.borderColor = `${originalStyles.customColor}30`;
                }
                cell.style.color = getContrastColor(originalStyles.customColor);
            } else {
                if (originalStyles.bgColor) cell.classList.add(originalStyles.bgColor);
                if (originalStyles.border) cell.classList.add(originalStyles.border);
                if (originalStyles.textColor) cell.classList.add(originalStyles.textColor);
            }
            
            // Add styled-cell class if border exists
            if (originalStyles.border || originalStyles.isCustom) {
                cell.classList.add('styled-cell');
            }
            
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
        if (originalStyles.isCustom) {
            cell.classList.add('custom-color-cell');
            if (originalStyles.isSolid) {
                cell.style.backgroundColor = originalStyles.customColor;
                cell.style.borderColor = originalStyles.customColor;
            } else {
                cell.style.backgroundColor = `${originalStyles.customColor}20`;
                cell.style.borderColor = `${originalStyles.customColor}30`;
            }
            cell.style.color = getContrastColor(originalStyles.customColor);
        } else {
            if (originalStyles.bgColor) cell.classList.add(originalStyles.bgColor);
            if (originalStyles.border) cell.classList.add(originalStyles.border);
            if (originalStyles.textColor) cell.classList.add(originalStyles.textColor);
        }
        
        // Add styled-cell class if border exists
        if (originalStyles.border || originalStyles.isCustom) {
            cell.classList.add('styled-cell');
        }
        
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
    if (originalStyles.isCustom) {
        cell.classList.add('custom-color-cell');
        if (originalStyles.isSolid) {
            cell.style.backgroundColor = originalStyles.customColor;
            cell.style.borderColor = originalStyles.customColor;
        } else {
            cell.style.backgroundColor = `${originalStyles.customColor}20`;
            cell.style.borderColor = `${originalStyles.customColor}30`;
        }
        cell.style.color = getContrastColor(originalStyles.customColor);
    } else {
        if (originalStyles.bgColor) cell.classList.add(originalStyles.bgColor);
        if (originalStyles.border) cell.classList.add(originalStyles.border);
        if (originalStyles.textColor) cell.classList.add(originalStyles.textColor);
    }
    
    // Add styled-cell class if border exists
    if (originalStyles.border || originalStyles.isCustom) {
        cell.classList.add('styled-cell');
    }
    
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
    if (e.target === document.getElementById('customColorModal')) {
        closeCustomColorModal();
    }
    if (e.target === document.getElementById('notesModal')) {
        closeNotesModal();
    }
    if (e.target === document.getElementById('deleteModal')) {
        closeDeleteModal();
    }
    if (e.target === document.getElementById('advancedFilterModal')) {
        closeAdvancedFilterModal();
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
        if (document.getElementById('customColorModal')) {
            closeCustomColorModal();
        }
        if (document.getElementById('notesModal')) {
            closeNotesModal();
        }
        if (document.getElementById('deleteModal')) {
            closeDeleteModal();
        }
        if (document.getElementById('advancedFilterModal')) {
            closeAdvancedFilterModal();
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
window.showAdvancedFilterModal = showAdvancedFilterModal;
window.closeAdvancedFilterModal = closeAdvancedFilterModal;
window.clearAllFilters = clearAllFilters;
window.applyAdvancedFilters = applyAdvancedFilters;
window.showCustomColorModal = showCustomColorModal;
window.closeCustomColorModal = closeCustomColorModal;
window.applyCustomColor = applyCustomColor;
window.updateCustomColorPreview = updateCustomColorPreview;
</script>

<?php renderFooter(); ?>