<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Unified parameter handling
$search = '';
$department = '';
$supervisor = '';
$operationManager = '';
$week = '';
$page = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search = $_POST['search'] ?? '';
    $department = $_POST['department'] ?? '';
    $supervisor = $_POST['supervisor'] ?? '';
    $operationManager = $_POST['operation_manager'] ?? '';
    $week = $_POST['week'] ?? '';
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
} else {
    $search = $_GET['search'] ?? '';
    $department = $_GET['department'] ?? '';
    $supervisor = $_GET['supervisor'] ?? '';
    $operationManager = $_GET['operation_manager'] ?? '';
    $week = $_GET['week'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
}

// Build query with filters
$whereConditions = [];
$params = [];

// Helper function to convert stored style to CSS classes
function getStyleClasses($styleJson) {
    if (empty($styleJson)) return '';
    
    $style = json_decode($styleJson, true);
    if (!$style) return '';
    
    $classes = [];
    if (!empty($style['bgColor'])) $classes[] = $style['bgColor'];
    if (!empty($style['border'])) $classes[] = $style['border'];
    if (!empty($style['textColor'])) $classes[] = $style['textColor'];
    
    return implode(' ', $classes);
}

if (!empty($search)) {
    $whereConditions[] = "(s.employee_id LIKE ? OR s.fullname LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($department)) {
    $whereConditions[] = "s.department = ?";
    $params[] = $department;
}

if (!empty($supervisor)) {
    $whereConditions[] = "s.supervisor = ?";
    $params[] = $supervisor;
}

if (!empty($operationManager)) {
    $whereConditions[] = "s.operation_manager = ?";
    $params[] = $operationManager;
}

if (!empty($week)) {
    $whereConditions[] = "s.week_beginning = ?";
    $params[] = $week;
}

$whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);
$page = max($page, 1);
$perPage = 30;
$offset = ($page - 1) * $perPage;

// Count total schedules
try {
    $countSql = "SELECT COUNT(*) FROM schedule s $whereClause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalSchedules = $countStmt->fetchColumn();
} catch (PDOException $e) {
    $totalSchedules = 0;
}

$totalPages = ceil($totalSchedules / $perPage);
$page = min($page, max($totalPages, 1));

// Get schedules data
try {
    $sql = "SELECT s.* FROM schedule s $whereClause 
            ORDER BY s.week_beginning DESC, s.department, s.fullname 
            LIMIT ? OFFSET ?";
    
    $stmt = $pdo->prepare($sql);
    
    $paramIndex = 1;
    foreach ($params as $param) {
        $stmt->bindValue($paramIndex, $param);
        $paramIndex++;
    }
    
    $stmt->bindValue($paramIndex, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex + 1, $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $schedules = $stmt->fetchAll();
} catch (PDOException $e) {
    $schedules = [];
    error_log("Database error: " . $e->getMessage());
}
?>

<div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden shadow-lg">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 w-full" style="zoom:85%">
            <thead class="bg-gray-700 sticky top-0">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">CXI Number</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Full Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Department</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Supervisor</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Operations Manager</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Week Beginning</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider text-center">Site</th>
                    
                    <!-- Dynamic day headers with dates -->
                    <?php
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    foreach ($days as $index => $day):
                        $date = '';
                        if (!empty($schedules[0]['week_beginning'])) {
                            $weekBeginning = new DateTime($schedules[0]['week_beginning']);
                            $weekBeginning->modify("+$index days");
                            $date = $weekBeginning->format('M-d-Y');
                        }
                    ?>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                        <div class="flex flex-col items-center">
                            <div class="text-[10px] font-normal text-gray-400 mb-1"><?= $date ?></div>
                            <div><?= $day ?></div>
                        </div>
                    </th>
                    <?php endforeach; ?>
                    
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Total Hours</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
                <?php if (empty($schedules)): ?>
                    <tr>
                        <td colspan="16" class="px-6 py-8 text-center text-gray-400">
                            <i class="fas fa-calendar-times text-3xl mb-3 opacity-50"></i>
                            <p class="text-lg">No schedules found</p>
                            <p class="text-sm mt-1">Create schedules for a department to get started</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($schedules as $schedule): ?>
                    <tr class="hover:bg-gray-700/50 transition-colors duration-150">
                        <!-- Read-only fields -->
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-100 uppercase tracking-wide"><?= htmlspecialchars($schedule['employee_id']) ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300"><?= htmlspecialchars($schedule['fullname']) ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300 uppercase tracking-wide"><?= htmlspecialchars($schedule['department']) ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300 uppercase tracking-wide"><?= htmlspecialchars($schedule['supervisor']) ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300 uppercase tracking-wide"><?= htmlspecialchars($schedule['operation_manager']) ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300 uppercase tracking-wide"><?= date('M j, Y', strtotime($schedule['week_beginning'])) ?></div>
                        </td>
                        
                        <!-- Editable fields -->
                        <td class="px-6 py-4 whitespace-nowrap h-full">
                            <div class="editable-cell min-w-[100px] cursor-pointer px-2 py-1 rounded hover:bg-gray-600/50 transition-colors duration-200 text-sm text-gray-200 border border-transparent text-center h-full flex items-center justify-center <?= !empty($schedule['site_style']) ? getStyleClasses($schedule['site_style']) : '' ?> <?= !empty($schedule['site_notes']) ? 'has-notes' : '' ?>"
                                data-schedule-id="<?= $schedule['id'] ?>"
                                data-field="site"
                                data-original-value="<?= htmlspecialchars($schedule['site'] ?? '') ?>"
                                data-notes="<?= !empty($schedule['site_notes']) ? htmlspecialchars($schedule['site_notes']) : '' ?>"
                                title="Click to edit - <?= !empty($schedule['site_notes']) ? 'Has notes (hover to view)' : 'No notes' ?>">
                                <?= !empty($schedule['site']) ? htmlspecialchars($schedule['site']) : '-' ?>
                            </div>
                        </td>

                        <!-- Daily schedule fields -->
                        <?php
                        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                        foreach ($days as $day):
                            $styleField = $day . '_style';
                            $notesField = $day . '_notes';
                        ?>
                        <td class="px-6 py-4 whitespace-nowrap h-full">
                            <div class="editable-cell min-w-[120px] cursor-pointer px-2 py-1 rounded hover:bg-gray-600/50 transition-colors duration-200 text-sm text-gray-200 border border-transparent text-center h-full flex items-center justify-center <?= !empty($schedule[$styleField]) ? getStyleClasses($schedule[$styleField]) : '' ?> <?= !empty($schedule[$notesField]) ? 'has-notes' : '' ?>"
                                data-schedule-id="<?= $schedule['id'] ?>"
                                data-field="<?= $day ?>"
                                data-original-value="<?= htmlspecialchars($schedule[$day] ?? '') ?>"
                                data-notes="<?= !empty($schedule[$notesField]) ? htmlspecialchars($schedule[$notesField]) : '' ?>"
                                title="Click to edit - <?= !empty($schedule[$notesField]) ? 'Has notes (hover to view)' : 'No notes' ?>">
                                <?= !empty($schedule[$day]) ? htmlspecialchars($schedule[$day]) : '-' ?>
                            </div>
                        </td>
                        <?php endforeach; ?>
                        
                        <!-- Total hours (read-only, auto-calculated) -->
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-100 text-center"
                                 data-schedule-id="<?= $schedule['id'] ?>"
                                 data-field="total_sched">
                                <?= $schedule['total_sched'] ?> hrs
                            </div>
                        </td>
                        
                        <!-- Actions -->
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="#" onclick="event.preventDefault(); showDeleteModal(<?= $schedule['id'] ?>)" 
                               class="text-red-500 hover:text-red-400 transition-colors duration-200" title="Delete schedule">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<div class="mt-6 flex items-center justify-between">
    <div class="text-sm text-gray-400">
        Showing <?= ($offset + 1) ?> to <?= min($offset + $perPage, $totalSchedules) ?> of <?= $totalSchedules ?> schedules
    </div>
    <div class="flex gap-1">
        <?php if ($page > 1): ?>
            <a href="#" data-page="1" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700 hover:border-gray-500 transition-colors duration-200">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <a href="#" data-page="<?= $page - 1 ?>" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700 hover:border-gray-500 transition-colors duration-200">
                <i class="fas fa-angle-left"></i>
            </a>
        <?php endif; ?>

        <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="#" data-page="<?= $i ?>" class="pagination-link px-3 py-1 rounded-lg border <?= $i == $page ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-600 text-gray-300 hover:bg-gray-700 hover:border-gray-500' ?> transition-colors duration-200">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="#" data-page="<?= $page + 1 ?>" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700 hover:border-gray-500 transition-colors duration-200">
                <i class="fas fa-angle-right"></i>
            </a>
            <a href="#" data-page="<?= $totalPages ?>" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700 hover:border-gray-500 transition-colors duration-200">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>