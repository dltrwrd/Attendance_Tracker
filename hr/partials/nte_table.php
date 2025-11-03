<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Get filter parameters
$status = $_POST['status'] ?? 'draft';
$page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$search = isset($_POST['search']) ? trim($_POST['search']) : '';
$dateFrom = isset($_POST['date_from']) ? $_POST['date_from'] : '';
$dateTo = isset($_POST['date_to']) ? $_POST['date_to'] : '';
$department = isset($_POST['department']) ? $_POST['department'] : '';

$perPage = 15;
$whereClauses = [];
$params = [];

// Base where clause for status
$whereClauses[] = "nte.nte_status = :status";
$params[':status'] = $status;

// Build additional where clauses
if (!empty($search)) {
    $whereClauses[] = "(nte.employee_id LIKE :search OR nte.full_name LIKE :search OR nte.nature_of_offense LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($dateFrom)) {
    $whereClauses[] = "nte.date_of_incident >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereClauses[] = "nte.date_of_incident <= :date_to";
    $params[':date_to'] = $dateTo;
}

if (!empty($department)) {
    $whereClauses[] = "nte.department = :department";
    $params[':department'] = $department;
}

$searchQuery = 'WHERE ' . implode(' AND ', $whereClauses);

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notice_to_explain nte $searchQuery");
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
    $query = "SELECT nte.*, ir.infraction as ir_infraction 
              FROM notice_to_explain nte 
              LEFT JOIN incident_report ir ON nte.ir_id = ir.id 
              $searchQuery 
              ORDER BY nte.created_at DESC 
              LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($query);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $ntes = $stmt->fetchAll();
} catch (PDOException $e) {
    $ntes = [];
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
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Date of Incident</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Violation</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-24">Instance</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-40">Sanction</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Status</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Attachment</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Created At</th>
                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider w-32">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
                <?php if (empty($ntes)): ?>
                    <tr>
                        <td colspan="10" class="px-6 py-8 text-center text-gray-400">
                            <i class="fas fa-inbox text-3xl mb-3 opacity-50"></i>
                            <p class="text-lg">No NTE records found</p>
                            <?php if ($status === 'draft'): ?>
                                <p class="text-sm text-gray-500 mt-2">New incident reports will automatically generate NTE drafts</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ntes as $nte): ?>
                    <tr class="hover:bg-gray-700/50">
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm font-medium text-gray-100" style="text-transform: uppercase;" title="<?= htmlspecialchars($nte['employee_id']) ?>">
                                <?= htmlspecialchars($nte['employee_id']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($nte['full_name']) ?>">
                                <?= htmlspecialchars($nte['full_name']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm text-gray-300" style="text-transform: uppercase;" title="<?= htmlspecialchars($nte['department']) ?>">
                                <?= htmlspecialchars($nte['department']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300">
                                <?= date('M d, Y', strtotime($nte['date_of_incident'])) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm text-gray-300" title="<?= htmlspecialchars($nte['nature_of_offense']) ?>">
                                <?= htmlspecialchars(substr($nte['nature_of_offense'], 0, 30)) ?><?= strlen($nte['nature_of_offense']) > 30 ? '...' : '' ?>
                            </div>
                            <div class="text-xs text-gray-500" title="<?= htmlspecialchars($nte['rule_section']) ?>">
                                <?= htmlspecialchars(substr($nte['rule_section'], 0, 25)) ?><?= strlen($nte['rule_section']) > 25 ? '...' : '' ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                <?= $nte['violation_instance'] == '1st' ? 'bg-green-500/20 text-green-300 border border-green-500/30' :
                                   ($nte['violation_instance'] == '2nd' ? 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30' :
                                   ($nte['violation_instance'] == '3rd' ? 'bg-orange-500/20 text-orange-300 border border-orange-500/30' : 
                                   'bg-red-500/20 text-red-300 border border-red-500/30')) ?>">
                                <?= $nte['violation_instance'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap overflow-hidden text-ellipsis max-w-xs">
                            <div class="text-sm text-gray-300" title="<?= htmlspecialchars($nte['sanction_proposed']) ?>">
                                <?= htmlspecialchars(substr($nte['sanction_proposed'], 0, 30)) ?><?= strlen($nte['sanction_proposed']) > 30 ? '...' : '' ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                <?= $nte['nte_status'] == 'draft' ? 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30' :
                                   ($nte['nte_status'] == 'issued' ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30' :
                                   ($nte['nte_status'] == 'answered' ? 'bg-green-500/20 text-green-300 border border-green-500/30' :
                                   ($nte['nte_status'] == 'for_decision' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' :
                                   'bg-gray-500/20 text-gray-300 border border-gray-500/30'))) ?>">
                                <?= strtoupper($nte['nte_status']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-300">
                                <?= date('M d, Y', strtotime($nte['created_at'])) ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                <?= date('g:i A', strtotime($nte['created_at'])) ?>
                            </div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="nte_review.php?id=<?= $nte['id'] ?>" title="Review NTE" class="text-blue-500 hover:text-blue-400 mr-3">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="employee_history.php?employee_id=<?= $nte['employee_id'] ?>" title="View History" class="text-green-500 hover:text-green-400">
                                <i class="fas fa-history"></i>
                            </a>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <?php if (!empty($nte['uploaded_file'])): ?>
                                <a href="../uploads/nte/<?= $nte['uploaded_file'] ?>" 
                                target="_blank" 
                                class="text-green-500 hover:text-green-400"
                                title="View Uploaded File">
                                    <i class="fas fa-file-pdf"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-gray-500">-</span>
                            <?php endif; ?>
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