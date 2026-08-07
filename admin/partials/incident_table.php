<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Get filter parameters
$search = isset($_POST['search']) ? trim($_POST['search']) : '';
$page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$dateFrom = isset($_POST['date_from']) ? $_POST['date_from'] : '';
$dateTo = isset($_POST['date_to']) ? $_POST['date_to'] : '';
$department = isset($_POST['department']) ? $_POST['department'] : '';

$perPage = 15;
$whereClauses = [];
$params = [];

// Build where clauses
if (!empty($search)) {
    $whereClauses[] = "(employee_id LIKE :search OR full_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($dateFrom)) {
    $whereClauses[] = "date_of_incident >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereClauses[] = "date_of_incident <= :date_to";
    $params[':date_to'] = $dateTo;
}

if (!empty($department)) {
    $whereClauses[] = "department = :department";
    $params[':department'] = $department;
}

$searchQuery = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM incident_report $searchQuery");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetchColumn();
} catch (PDOException $e) {
    $totalRecords = 0;
}

$totalPages = ceil($totalRecords / $perPage);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

try {
    $query = "SELECT * FROM incident_report $searchQuery ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($query);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();
} catch (PDOException $e) {
    $records = [];
}
?>

<div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden shadow">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 w-full" style="zoom:85%">
            <thead class="bg-gray-700">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Employee ID</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-48">Full Name</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Department</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Operations Manager</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Date of Incident</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24">Shift</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Infraction</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Reported By</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Created At</th>
                </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="11" class="px-6 py-8 text-center text-gray-400">
                            <i class="fas fa-users-slash text-3xl mb-3 opacity-50"></i>
                            <p class="text-lg">No incident reports found</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                    <tr class="hover:bg-gray-700/50">
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm font-medium text-gray-100" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['employee_id']) ?>">
                                <?= htmlspecialchars($record['employee_id']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['full_name']) ?>">
                                <?= htmlspecialchars($record['full_name']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['department']) ?>">
                                <?= htmlspecialchars($record['department']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($record['operation_manager']) ?>">
                                <?= htmlspecialchars($record['operation_manager']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300">
                                <?= date('M d, Y', strtotime($record['date_of_incident'])) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300">
                                <?= htmlspecialchars($record['shift']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm text-gray-300" title="<?= htmlspecialchars($record['infraction']) ?>">
                                <?= htmlspecialchars(substr($record['infraction'], 0, 50)) ?><?= strlen($record['infraction']) > 50 ? '...' : '' ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm text-gray-300" title="<?= htmlspecialchars($record['reported_by']) ?>">
                                <?= htmlspecialchars($record['reported_by']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300">
                                <?= date('M d, Y g:i A', strtotime($record['created_at'])) ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="mt-6 flex items-center justify-between">
    <div class="text-sm text-gray-400">
        Showing <?= ($offset + 1) ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> records
    </div>
    <div class="flex gap-1">
        <?php if ($page > 1): ?>
            <a href="#" data-page="1" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <a href="#" data-page="<?= $page - 1 ?>" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700">
                <i class="fas fa-angle-left"></i>
            </a>
        <?php endif; ?>

        <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="#" data-page="<?= $i ?>" class="pagination-link px-3 py-1 rounded-lg border <?= $i == $page ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-600 text-gray-300 hover:bg-gray-700' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="#" data-page="<?= $page + 1 ?>" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700">
                <i class="fas fa-angle-right"></i>
            </a>
            <a href="#" data-page="<?= $totalPages ?>" class="pagination-link px-3 py-1 rounded-lg border border-gray-600 text-gray-300 hover:bg-gray-700">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>