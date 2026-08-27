<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Get parameters from POST (AJAX) or GET (initial load)
$search    = isset($_POST['search']) ? trim($_POST['search']) : (isset($_GET['search']) ? trim($_GET['search']) : '');
$page      = isset($_POST['page']) ? (int)$_POST['page'] : (isset($_GET['page']) ? (int)$_GET['page'] : 1);
$type      = isset($_POST['type']) ? $_POST['type'] : (isset($_GET['tab']) ? $_GET['tab'] : 'users');
$view      = isset($_POST['view']) ? $_POST['view'] : (isset($_GET['view']) ? $_GET['view'] : 'table');
$status    = isset($_POST['status']) ? $_POST['status'] : (isset($_GET['status']) ? $_GET['status'] : 'active');
$sortField = isset($_POST['sort_field']) ? $_POST['sort_field'] : (isset($_GET['sort_field']) ? $_GET['sort_field'] : '');
$sortDir   = isset($_POST['sort_dir']) ? $_POST['sort_dir'] : (isset($_GET['sort_dir']) ? $_GET['sort_dir'] : 'asc');
$page      = max($page, 1);

// Whitelist incoming values
$view      = in_array($view, ['table', 'grid'], true) ? $view : 'table';
$status    = in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'active';
$sortField = in_array($sortField, ['', 'username', 'fullname'], true) ? $sortField : '';
$sortDir   = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';

// Determine which table to query
$table = 'users';
$columns = ['username', 'sub_name', 'fullname', 'slt_email', 'role', 'is_active', 'created_at'];
$idColumn = 'id';
$usernameColumn = 'username';
$emailColumn = 'slt_email';

if ($type === 'management') {
    $table = 'management';
    $columns = ['cxi_id', 'fullname', 'department', 'email', 'is_active', 'created_at'];
    $idColumn = 'id';
    $usernameColumn = 'cxi_id';
    $emailColumn = 'email';
} elseif ($type === 'operations') {
    $table = 'operations_managers';
    $columns = ['cxi_id', 'fullname', 'department', 'email', 'is_active', 'created_at'];
    $idColumn = 'id';
    $usernameColumn = 'cxi_id';
    $emailColumn = 'email';
}

// Pagination configuration
$perPage = ($view === 'grid') ? 20 : 15;
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $searchConditions = [];
    foreach ($columns as $column) {
        if ($column !== 'created_at' && $column !== 'is_active') {
            $searchConditions[] = "$column LIKE :search";
        }
    }
    $whereConditions[] = '(' . implode(' OR ', $searchConditions) . ')';
    $params[':search'] = "%$search%";
}

if ($status === 'active') {
    $whereConditions[] = 'is_active = 1';
} elseif ($status === 'inactive') {
    $whereConditions[] = 'is_active = 0';
}

$whereQuery = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Determine ORDER BY
if ($sortField === 'username') {
    $orderQuery = "ORDER BY $usernameColumn $sortDir";
} elseif ($sortField === 'fullname') {
    $orderQuery = "ORDER BY fullname $sortDir";
} else {
    $orderQuery = "ORDER BY is_active = 0, created_at DESC";
}

// Get total count for pagination
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM $table $whereQuery");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetchColumn();
} catch (PDOException $e) {
    $totalRecords = 0;
}

$totalPages = max(1, ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Get paginated records
try {
    $query = "SELECT * FROM $table $whereQuery $orderQuery LIMIT :limit OFFSET :offset";
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

function slt_photo_path($record) {
    return !empty($record['display_photo'])
        ? '../components/profile/' . htmlspecialchars($record['display_photo'])
        : '../components/profile/default.jpg';
}
?>

<?php if ($view === 'grid'): ?>

    <?php if (empty($records)): ?>
        <div class="glass-panel rounded-2xl px-6 py-16 text-center">
            <i class="fas fa-users-slash text-3xl mb-3 text-gray-400 opacity-50"></i>
            <p class="text-lg text-gray-300">No records found</p>
            <p class="text-sm mt-1 text-gray-500"><?= !empty($search) ? 'Try adjusting your search or filters' : 'No team members found' ?></p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-4" id="gridContainer">
            <?php foreach ($records as $i => $record): ?>
                <?php
                    $fullname = $record['fullname'] ?? '';
                    $email = $record[$emailColumn] ?? '';
                    $employeeNo = $record[$usernameColumn] ?? '';
                    $isActive = !empty($record['is_active']);
                ?>
                <div class="grid-card glass-card rounded-2xl p-5 flex flex-col items-center text-center group" data-card-id="<?= $record[$idColumn] ?>" style="--card-index: <?= $i ?>;">
                    <div class="relative mb-3">
                        <div class="w-20 h-20 md:w-24 md:h-24 rounded-full border-2 border-white/15 overflow-hidden bg-gray-800 shadow-lg cursor-pointer transition-transform duration-300 group-hover:scale-105"
                             onclick="openProfileModal(<?= $record[$idColumn] ?>)" title="View Profile">
                            <img src="<?= slt_photo_path($record) ?>" alt="<?= htmlspecialchars($fullname) ?>" class="w-full h-full object-cover">
                        </div>
                        <!-- Live Online Status Dot (kept on the photo) -->
                        <span class="online-indicator animate-ping absolute bottom-0.5 right-0.5 w-3.5 h-3.5 rounded-full bg-green-400 opacity-75 pointer-events-none" style="display: none;"></span>
                        <span class="online-status absolute bottom-0.5 right-0.5 w-3.5 h-3.5 rounded-full border-2 border-gray-900 bg-gray-400" data-user-id="<?= $record[$idColumn] ?>"></span>
                    </div>

                    <div class="text-[10px] font-mono tracking-wider text-gray-500 uppercase mb-1"><?= htmlspecialchars($employeeNo) ?></div>

                    <div class="text-sm font-bold text-white leading-snug cursor-pointer hover:text-primary-400 transition-colors" onclick="openProfileModal(<?= $record[$idColumn] ?>)">
                        <?= htmlspecialchars($fullname) ?>
                    </div>

                    <div class="text-xs text-gray-400 lowercase mt-1 truncate max-w-full"><?= htmlspecialchars($email) ?></div>

                    <span class="mt-3 px-3 py-1 inline-flex text-[11px] leading-5 font-semibold rounded-full <?= $isActive ? 'bg-green-500/20 text-green-300 border border-green-500/30' : 'bg-red-500/20 text-red-300 border border-red-500/30' ?>">
                        <?= $isActive ? 'Active' : 'Inactive' ?>
                    </span>

                    <div class="mt-4 pt-3 border-t border-white/10 w-full flex items-center justify-center gap-4">
                        <a href="profile.php?id=<?= $record[$idColumn] ?>&type=<?= $type ?>" title="Edit record" class="text-primary-500 hover:text-primary-400 transition-colors duration-200">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="users.php?toggle_status=<?= $record[$idColumn] ?>&type=<?= $type ?>" class="text-yellow-500 hover:text-yellow-400 transition-colors duration-200" title="Status update" onclick="return confirm('Are you sure you want to <?= $isActive ? 'deactivate' : 'activate' ?> this record?')">
                            <i class="fas fa-<?= $isActive ? 'times' : 'check' ?>"></i>
                        </a>
                        <a href="#" onclick="event.preventDefault(); showDeleteModal(<?= $record[$idColumn] ?>, '<?= $type ?>')" class="text-red-500 hover:text-red-400 transition-colors duration-200" title="Delete record">
                            <i class="fas fa-trash"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>

    <div class="glass-panel rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-white/10 w-full" style="zoom:85%">
                <thead class="bg-white/5">
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
                <tbody class="divide-y divide-white/10">
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="<?= $type === 'users' ? 8 : 7 ?>" class="px-6 py-8 text-center text-gray-400">
                                <i class="fas fa-users-slash text-3xl mb-3 opacity-50"></i>
                                <p class="text-lg">No records found</p>
                                <p class="text-sm mt-1"><?= !empty($search) ? 'Try adjusting your search or filters' : 'No team members found' ?></p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                        <tr class="hover:bg-white/5 transition-colors duration-150">
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
                                                <img src="<?= slt_photo_path($record) ?>" alt="<?= htmlspecialchars($record['fullname']) ?>" class="w-full h-full object-cover">
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
                                                <img src="<?= slt_photo_path($record) ?>" alt="<?= htmlspecialchars($record['fullname']) ?>" class="w-full h-full object-cover">
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

<?php endif; ?>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="mt-6 flex items-center justify-between">
    <div class="text-sm text-gray-400">
        Showing <?= ($offset + 1) ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> records
    </div>
    <div class="flex gap-1">
        <?php if ($page > 1): ?>
            <a href="#" data-page="1" class="pagination-link px-3 py-1 rounded-lg border border-white/10 text-gray-300 hover:bg-white/10 hover:border-white/20 transition-colors duration-200">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <a href="#" data-page="<?= $page - 1 ?>" class="pagination-link px-3 py-1 rounded-lg border border-white/10 text-gray-300 hover:bg-white/10 hover:border-white/20 transition-colors duration-200">
                <i class="fas fa-angle-left"></i>
            </a>
        <?php endif; ?>

        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);

        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="#" data-page="<?= $i ?>" class="pagination-link px-3 py-1 rounded-lg border <?= $i == $page ? 'bg-primary-600 border-primary-600 text-white' : 'border-white/10 text-gray-300 hover:bg-white/10 hover:border-white/20' ?> transition-colors duration-200">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="#" data-page="<?= $page + 1 ?>" class="pagination-link px-3 py-1 rounded-lg border border-white/10 text-gray-300 hover:bg-white/10 hover:border-white/20 transition-colors duration-200">
                <i class="fas fa-angle-right"></i>
            </a>
            <a href="#" data-page="<?= $totalPages ?>" class="pagination-link px-3 py-1 rounded-lg border border-white/10 text-gray-300 hover:bg-white/10 hover:border-white/20 transition-colors duration-200">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>