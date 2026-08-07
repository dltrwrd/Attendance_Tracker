<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Unified parameter handling
$search = '';
$department = '';
$supervisor = '';
$operationManager = '';
$status = '';
$quickFilter = '';
$page = 1;
$selectedEmployees = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search = $_POST['search'] ?? '';
    $department = $_POST['department'] ?? '';
    $supervisor = $_POST['supervisor'] ?? '';
    $operationManager = $_POST['operation_manager'] ?? '';
    $status = $_POST['status'] ?? '';
    $quickFilter = $_POST['quick_filter'] ?? '';
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    
    if (!empty($_POST['selected_employees_json'])) {
        $selectedEmployees = json_decode($_POST['selected_employees_json'], true) ?? [];
    }
} else {
    $search = $_GET['search'] ?? '';
    $department = $_GET['department'] ?? '';
    $supervisor = $_GET['supervisor'] ?? '';
    $operationManager = $_GET['operation_manager'] ?? '';
    $status = $_GET['status'] ?? '';
    $quickFilter = $_GET['quick_filter'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
}

// Optimized query building
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(employee_id LIKE ? OR full_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($department)) {
    $whereConditions[] = "department = ?";
    $params[] = $department;
}

if (!empty($supervisor)) {
    $whereConditions[] = "supervisor = ?";
    $params[] = $supervisor;
}

if (!empty($operationManager)) {
    $whereConditions[] = "operation_manager = ?";
    $params[] = $operationManager;
}

if ($status !== '') {
    $whereConditions[] = "is_active = ?";
    $params[] = $status;
}

// Quick Filters Logic
if ($quickFilter === 'inactive_sup') {
    $whereConditions[] = "supervisor IN (SELECT full_name FROM employees WHERE is_active = 0)";
} elseif ($quickFilter === 'inactive_om') {
    $whereConditions[] = "operation_manager IN (SELECT full_name FROM employees WHERE is_active = 0)";
} elseif ($quickFilter === 'tba') {
    $whereConditions[] = "(supervisor = 'TBA' OR operation_manager = 'TBA' OR department = 'TBA')";
} elseif ($quickFilter === 'format_errors') {
    $whereConditions[] = "(employee_id NOT REGEXP '^(CXI|COM)[0-9]{5}$' AND employee_id != '')";
} elseif ($quickFilter === 'active') {
    $whereConditions[] = "is_active = 1";
}

$whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);
$page = max($page, 1);
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Optimized count query
try {
    $countSql = "SELECT COUNT(*) FROM employees $whereClause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalEmployees = $countStmt->fetchColumn();
} catch (PDOException $e) {
    $totalEmployees = 0;
}

$totalPages = ceil($totalEmployees / $perPage);
$page = min($page, max($totalPages, 1));

// Optimized data query with field selection
try {
    $sql = "SELECT id, employee_id, full_name, department, supervisor, operation_manager, email, created_at, is_active 
            FROM employees 
            $whereClause 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $pdo->prepare($sql);
    
    // Bind all parameters efficiently
    $paramIndex = 1;
    foreach ($params as $param) {
        $stmt->bindValue($paramIndex, $param);
        $paramIndex++;
    }
    
    $stmt->bindValue($paramIndex, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex + 1, $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $employees = $stmt->fetchAll();
} catch (PDOException $e) {
    $employees = [];
    error_log("Database error: " . $e->getMessage());
}
?>

<div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden shadow-lg">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 w-full" style="zoom:85%">
            <thead class="bg-gray-700">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                        <input type="checkbox" id="selectAll" onchange="selectAllEmployees(this)" class="rounded border-gray-600 bg-gray-700 text-primary-600 focus:ring-primary-500 focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 transition-colors duration-200 cursor-pointer">
                    </th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">ID Number</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Full Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Department</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Supervisor</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Operations Manager</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Email</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="9" class="px-6 py-10 text-center text-gray-400">
                            <i class="fas fa-users-slash text-4xl mb-3 opacity-50 block"></i>
                            <p class="text-lg font-semibold text-gray-300">No agents found</p>
                            <p class="text-sm mt-1">Try adjusting your search or clear your filters.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $employee): 
                        $isFormatError = !preg_match('/^(CXI|COM)[0-9]{5}$/', $employee['employee_id']);
                        $isTba = in_array('TBA', [strtoupper($employee['department']), strtoupper($employee['supervisor']), strtoupper($employee['operation_manager'])]);
                    ?>
                    <tr class="hover:bg-gray-700/60 transition-colors duration-150 cursor-pointer group"
                        onclick="openIndividualModal(this)"
                        data-id="<?= $employee['id'] ?>"
                        data-cxi="<?= htmlspecialchars($employee['employee_id']) ?>"
                        data-name="<?= htmlspecialchars($employee['full_name']) ?>"
                        data-dept="<?= htmlspecialchars($employee['department']) ?>"
                        data-sup="<?= htmlspecialchars($employee['supervisor']) ?>"
                        data-om="<?= htmlspecialchars($employee['operation_manager']) ?>"
                        data-email="<?= htmlspecialchars($employee['email'] ?? '') ?>"
                        data-status="<?= $employee['is_active'] ?>">
                        
                        <td class="px-4 py-4 whitespace-nowrap" onclick="event.stopPropagation()">
                            <input type="checkbox" name="selected_employees[]" value="<?= $employee['id'] ?>" 
                                   class="employee-checkbox rounded border-gray-600 bg-gray-700 text-primary-600 focus:ring-primary-500 focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 transition-colors duration-200 cursor-pointer"
                                   onchange="toggleEditTeamButton()"
                                   <?= in_array($employee['id'], $selectedEmployees) ? 'checked' : '' ?>>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-bold <?= $isFormatError ? 'text-yellow-400' : 'text-gray-100' ?> uppercase tracking-wide font-mono">
                                <?= htmlspecialchars($employee['employee_id']) ?>
                                <?php if($isFormatError): ?><i class="fas fa-exclamation-triangle ml-1 text-yellow-500" title="Invalid Format"></i><?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-200"><?= htmlspecialchars($employee['full_name']) ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm uppercase tracking-wide <?= strtoupper($employee['department']) === 'TBA' ? 'text-yellow-400/80 font-semibold' : 'text-gray-400' ?>"><?= htmlspecialchars($employee['department']) ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm uppercase tracking-wide <?= strtoupper($employee['supervisor']) === 'TBA' ? 'text-yellow-400/80 font-semibold' : 'text-gray-400' ?>"><?= htmlspecialchars($employee['supervisor']) ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm uppercase tracking-wide <?= strtoupper($employee['operation_manager']) === 'TBA' ? 'text-yellow-400/80 font-semibold' : 'text-gray-400' ?>"><?= htmlspecialchars($employee['operation_manager']) ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-400 lowercase"><?= htmlspecialchars($employee['email'] ?? '') ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-3 py-1 inline-flex text-xs leading-5 font-bold rounded-full shadow-sm <?= $employee['is_active'] ? 'bg-green-500/10 text-green-400 border border-green-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?> transition-colors duration-200">
                                <?= $employee['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium" onclick="event.stopPropagation()">
                            <a href="employees.php?toggle_status=<?= $employee['id'] ?>" class="text-gray-400 hover:text-yellow-400 p-2 rounded hover:bg-gray-700 transition-colors duration-200" title="Toggle Status" onclick="return confirm('Are you sure you want to <?= $employee['is_active'] ? 'deactivate' : 'activate' ?> this agent?')">
                                <i class="fas fa-<?= $employee['is_active'] ? 'power-off' : 'check' ?>"></i>
                            </a>
                            <a href="#" onclick="event.preventDefault(); showDeleteModal(<?= $employee['id'] ?>)" class="text-gray-400 hover:text-red-400 p-2 rounded hover:bg-gray-700 transition-colors duration-200 ml-1" title="Delete record">
                                <i class="fas fa-trash-alt"></i>
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
<div class="mt-4 px-2 flex items-center justify-between">
    <div class="text-sm text-gray-400 font-medium">
        Showing <span class="text-gray-200"><?= ($offset + 1) ?></span> to <span class="text-gray-200"><?= min($offset + $perPage, $totalEmployees) ?></span> of <span class="text-gray-200"><?= $totalEmployees ?></span> agents
    </div>
    <div class="flex gap-1">
        <?php if ($page > 1): ?>
            <a href="#" data-page="1" class="pagination-link px-3 py-1.5 rounded bg-gray-800 border border-gray-700 text-gray-400 hover:bg-gray-700 hover:text-white transition-colors duration-200">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <a href="#" data-page="<?= $page - 1 ?>" class="pagination-link px-3 py-1.5 rounded bg-gray-800 border border-gray-700 text-gray-400 hover:bg-gray-700 hover:text-white transition-colors duration-200">
                <i class="fas fa-angle-left"></i>
            </a>
        <?php endif; ?>

        <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="#" data-page="<?= $i ?>" class="pagination-link px-3.5 py-1.5 rounded font-medium transition-colors duration-200 <?= $i == $page ? 'bg-blue-600 text-white shadow-md' : 'bg-gray-800 border border-gray-700 text-gray-400 hover:bg-gray-700 hover:text-white' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="#" data-page="<?= $page + 1 ?>" class="pagination-link px-3 py-1.5 rounded bg-gray-800 border border-gray-700 text-gray-400 hover:bg-gray-700 hover:text-white transition-colors duration-200">
                <i class="fas fa-angle-right"></i>
            </a>
            <a href="#" data-page="<?= $totalPages ?>" class="pagination-link px-3 py-1.5 rounded bg-gray-800 border border-gray-700 text-gray-400 hover:bg-gray-700 hover:text-white transition-colors duration-200">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>