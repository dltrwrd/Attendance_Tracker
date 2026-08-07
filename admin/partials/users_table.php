<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Get parameters from POST (AJAX) or GET (initial load)
$search = isset($_POST['search']) ? trim($_POST['search']) : (isset($_GET['search']) ? trim($_GET['search']) : '');
$page = isset($_POST['page']) ? (int)$_POST['page'] : (isset($_GET['page']) ? (int)$_GET['page'] : 1);
$type = isset($_POST['type']) ? $_POST['type'] : (isset($_GET['tab']) ? $_GET['tab'] : 'users');
$page = max($page, 1);

// Determine which table to query
$table = 'users';
$columns = ['username', 'sub_name', 'fullname', 'slt_email', 'role', 'is_active', 'created_at'];
$idColumn = 'id';

if ($type === 'management') {
    $table = 'management';
    $columns = ['cxi_id', 'fullname', 'department', 'email', 'is_active', 'created_at'];
    $idColumn = 'id';
} elseif ($type === 'operations') {
    $table = 'operations_managers';
    $columns = ['cxi_id', 'fullname', 'department', 'email', 'is_active', 'created_at'];
    $idColumn = 'id';
}

// Pagination configuration
$perPage = 15;
$searchQuery = '';
$params = [];

if (!empty($search)) {
    $searchConditions = [];
    foreach ($columns as $column) {
        if ($column !== 'created_at' && $column !== 'is_active') {
            $searchConditions[] = "$column LIKE :search";
        }
    }
    $searchQuery = "WHERE " . implode(' OR ', $searchConditions);
    $params[':search'] = "%$search%";
}

// Get total count for pagination
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM $table $searchQuery");
    if (!empty($search)) {
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetchColumn();
} catch (PDOException $e) {
    $totalRecords = 0;
}

$totalPages = ceil($totalRecords / $perPage);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Get paginated records
try {
    $query = "SELECT * FROM $table $searchQuery ORDER BY is_active = 0, created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($query);
    
    if (!empty($search)) {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();
} catch (PDOException $e) {
    $records = [];
}
?>

<div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden shadow-lg">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 w-full" style="zoom:85%">
            <thead class="bg-gray-700">
                <tr>
                    <?php if ($type === 'users'): ?>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Username</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">SLT</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">User / Fullname</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Email</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider" style="display: none;">Role</th>
                    <?php else: ?>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">CXI ID</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">User / Fullname</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Department</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Email</th>
                    <?php endif; ?>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Created At</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="<?= $type === 'users' ? 8 : 7 ?>" class="px-6 py-8 text-center text-gray-400">
                            <i class="fas fa-users-slash text-3xl mb-3 opacity-50"></i>
                            <p class="text-lg">No records found</p>
                            <p class="text-sm mt-1"><?= !empty($search) ? 'Try adjusting your search' : 'No team members found' ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                    <tr class="hover:bg-gray-700/50 transition-colors duration-150">
                        <?php if ($type === 'users'): ?>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-100 uppercase tracking-wide"><?= htmlspecialchars($record['username']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300"><?= htmlspecialchars($record['sub_name']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center space-x-3">
                                    
                                    <!-- Mini Profile Photo (Clickable) -->
                                    <div class="relative group cursor-pointer transition-transform duration-200 hover:-translate-y-1 hover:z-10" 
                                         onclick="openProfileModal(<?= $record[$idColumn] ?>)" 
                                         title="View Profile">
                                        
                                        <div class="w-10 h-10 rounded-full border-2 border-gray-700 overflow-hidden bg-gray-800 shadow-sm flex-shrink-0">
                                            <?php 
                                            $photoPath = !empty($record['display_photo']) 
                                                ? '../components/profile/' . htmlspecialchars($record['display_photo']) 
                                                : '../components/profile/default.jpg'; 
                                            ?>
                                            <img src="<?= $photoPath ?>" alt="<?= htmlspecialchars($record['fullname']) ?>" class="w-full h-full object-cover">
                                        </div>
                                        
                                        <!-- Live Online Status Dot -->
                                        <span class="online-indicator animate-ping absolute bottom-0 right-0 w-3 h-3 rounded-full bg-green-400 opacity-75 pointer-events-none" style="display: none;"></span>
                                        <span class="online-status absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-gray-800 bg-gray-400" data-user-id="<?= $record[$idColumn] ?>"></span>
                                    
                                    </div>

                                    <!-- User Name & Info -->
                                    <div>
                                        <div class="text-sm font-medium text-gray-200 hover:text-white transition-colors cursor-pointer" onclick="openProfileModal(<?= $record[$idColumn] ?>)">
                                            <?= htmlspecialchars($record['fullname']) ?>
                                        </div>
                                        <div class="text-xs text-gray-500 uppercase tracking-wider mt-0.5">
                                            <?= htmlspecialchars($record['role'] ?? 'Member') ?>
                                        </div>
                                    </div>
                                    
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300 lowercase"><?= htmlspecialchars($record['slt_email']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap" style="display: none;">
                                <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $record['role'] === 'admin' ? 'bg-primary-500/20 text-primary-300 border border-primary-500/30' : 'bg-green-500/20 text-green-300 border border-green-500/30' ?> transition-colors duration-200 uppercase">
                                    <?= ucfirst($record['role']) ?>
                                </span>
                            </td>
                            
                        <?php else: ?>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-100 uppercase tracking-wide"><?= htmlspecialchars($record['cxi_id']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center space-x-3">
                                    
                                    <!-- Mini Profile Photo (Clickable) -->
                                    <div class="relative group cursor-pointer transition-transform duration-200 hover:-translate-y-1 hover:z-10" 
                                         onclick="openProfileModal(<?= $record[$idColumn] ?>)" 
                                         title="View Profile">
                                        
                                        <div class="w-10 h-10 rounded-full border-2 border-gray-700 overflow-hidden bg-gray-800 shadow-sm flex-shrink-0">
                                            <?php 
                                            $photoPath = !empty($record['display_photo']) 
                                                ? '../components/profile/' . htmlspecialchars($record['display_photo']) 
                                                : '../components/profile/default.jpg'; 
                                            ?>
                                            <img src="<?= $photoPath ?>" alt="<?= htmlspecialchars($record['fullname']) ?>" class="w-full h-full object-cover">
                                        </div>
                                        
                                        <!-- Live Online Status Dot -->
                                        <span class="online-indicator animate-ping absolute bottom-0 right-0 w-3 h-3 rounded-full bg-green-400 opacity-75 pointer-events-none" style="display: none;"></span>
                                        <span class="online-status absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-gray-800 bg-gray-400" data-user-id="<?= $record[$idColumn] ?>"></span>
                                    
                                    </div>

                                    <!-- User Name -->
                                    <div>
                                        <div class="text-sm font-medium text-gray-200 hover:text-white transition-colors cursor-pointer uppercase tracking-wide" onclick="openProfileModal(<?= $record[$idColumn] ?>)">
                                            <?= htmlspecialchars($record['fullname']) ?>
                                        </div>
                                    </div>
                                    
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300 uppercase tracking-wide"><?= htmlspecialchars($record['department']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-300 lowercase"><?= htmlspecialchars($record['email'] ?? '') ?></div>
                            </td>
                        <?php endif; ?>
                        
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300 uppercase tracking-wide">
                            <?= date('M j, Y g:i A', strtotime($record['created_at'])) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $record['is_active'] ? 'bg-green-500/20 text-green-300 border border-green-500/30' : 'bg-red-500/20 text-red-300 border border-red-500/30' ?> transition-colors duration-200">
                                <?= $record['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="profile.php?id=<?= $record[$idColumn] ?>&type=<?= $type ?>" title="Edit record" class="text-primary-500 hover:text-primary-400 mr-4 transition-colors duration-200">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="users.php?toggle_status=<?= $record[$idColumn] ?>&type=<?= $type ?>" class="text-yellow-500 hover:text-yellow-400 mr-4 transition-colors duration-200" title="Status update" onclick="return confirm('Are you sure you want to <?= $record['is_active'] ? 'deactivate' : 'activate' ?> this record?')">
                                <i class="fas fa-<?= $record['is_active'] ? 'times' : 'check' ?>"></i>
                            </a>
                            <a href="#" onclick="event.preventDefault(); showDeleteModal(<?= $record[$idColumn] ?>, '<?= $type ?>')" class="text-red-500 hover:text-red-400 transition-colors duration-200" title="Delete record">
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

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="mt-6 flex items-center justify-between">
    <div class="text-sm text-gray-400">
        Showing <?= ($offset + 1) ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> records
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