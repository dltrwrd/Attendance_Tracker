<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Get parameters from POST (AJAX) or GET (initial load)
$search = isset($_POST['search']) ? trim($_POST['search']) : (isset($_GET['search']) ? trim($_GET['search']) : '');
$page = isset($_POST['page']) ? (int)$_POST['page'] : (isset($_GET['page']) ? (int)$_GET['page'] : 1);
$page = max($page, 1);

// Pagination configuration
$perPage = 12;
$searchQuery = '';
$params = [];

if (!empty($search)) {
    $searchConditions = [
        "rule_section LIKE :search",
        "nature_of_offense LIKE :search", 
        "stipulation LIKE :search",
        "specific_offenses LIKE :search"
    ];
    $searchQuery = "WHERE " . implode(' OR ', $searchConditions);
    $params[':search'] = "%$search%";
}

// Get total count for pagination
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM infractions $searchQuery");
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
    $query = "SELECT * FROM infractions $searchQuery ORDER BY date_created DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($query);
    
    if (!empty($search)) {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $infractions = $stmt->fetchAll();
} catch (PDOException $e) {
    $infractions = [];
}
?>

<!-- Infractions Grid -->
<?php if (empty($infractions)): ?>
    <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl p-12 text-center border border-gray-700/50">
        <div class="max-w-md mx-auto">
            <div class="w-20 h-20 bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-exclamation-triangle text-3xl text-gray-500"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-300 mb-3">No Infractions Found</h3>
            <p class="text-gray-400 mb-6">
                <?= !empty($search) ? 'No infractions match your search criteria.' : 'Start by adding your first infraction to the system.' ?>
            </p>
            <?php if (empty($search)): ?>
                <a href="infraction_form.php?action=create" class="inline-flex items-center px-6 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-all duration-200 transform hover:scale-105">
                    <i class="fas fa-plus mr-2"></i>
                    Add First Infraction
                </a>
            <?php else: ?>
                <button onclick="document.getElementById('searchInput').value = ''; infractionsManager.handleSearchChange('');" 
                        class="inline-flex items-center px-6 py-3 bg-gray-700 hover:bg-gray-600 text-white rounded-xl transition-all duration-200">
                    <i class="fas fa-times mr-2"></i>
                    Clear Search
                </button>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <?php foreach ($infractions as $infraction): ?>
        <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl p-6 border border-gray-700/50 hover:border-primary-500/30 transition-all duration-300 group hover:transform hover:scale-[1.02]">
            <!-- Header -->
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-12 h-12 bg-gradient-to-br from-primary-500/20 to-blue-500/20 rounded-xl flex items-center justify-center group-hover:from-primary-500/30 group-hover:to-blue-500/30 transition-all duration-300">
                        <i class="fas fa-gavel text-primary-400 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white"><?= htmlspecialchars($infraction['rule_section']) ?></h3>
                        <p class="text-sm text-gray-400"><?= date('M j, Y', strtotime($infraction['date_created'])) ?></p>
                    </div>
                </div>
                <div class="flex space-x-2 opacity-0 group-hover:opacity-100 transition-all duration-300">
                    <a href="infraction_form.php?id=<?= $infraction['id'] ?>" 
                       class="w-8 h-8 bg-primary-500/10 hover:bg-primary-500/20 text-primary-400 hover:text-primary-300 rounded-lg flex items-center justify-center transition-all duration-200"
                       title="Edit infraction">
                        <i class="fas fa-edit text-xs"></i>
                    </a>
                    <a href="#" 
                       onclick="event.preventDefault(); showDeleteModal(<?= $infraction['id'] ?>)" 
                       class="w-8 h-8 bg-red-500/10 hover:bg-red-500/20 text-red-400 hover:text-red-300 rounded-lg flex items-center justify-center transition-all duration-200"
                       title="Delete infraction">
                        <i class="fas fa-trash text-xs"></i>
                    </a>
                </div>
            </div>

            <!-- Content -->
            <div class="space-y-4">
                <!-- Nature of Offense -->
                <div>
                    <div class="flex items-center space-x-2 mb-2">
                        <i class="fas fa-exclamation-circle text-orange-400 text-sm"></i>
                        <span class="text-sm font-semibold text-gray-300">Nature of Offense</span>
                    </div>
                    <p class="text-gray-200 text-sm leading-relaxed"><?= htmlspecialchars($infraction['nature_of_offense']) ?></p>
                </div>

                <!-- Stipulation -->
                <div>
                    <div class="flex items-center space-x-2 mb-2">
                        <i class="fas fa-file-contract text-blue-400 text-sm"></i>
                        <span class="text-sm font-semibold text-gray-300">Stipulation</span>
                    </div>
                    <p class="text-gray-200 text-sm leading-relaxed"><?= htmlspecialchars($infraction['stipulation']) ?></p>
                </div>

                <!-- Specific Offenses -->
                <div>
                    <div class="flex items-center space-x-2 mb-2">
                        <i class="fas fa-list-ol text-green-400 text-sm"></i>
                        <span class="text-sm font-semibold text-gray-300">Specific Offenses</span>
                    </div>
                    <p class="text-gray-200 text-sm leading-relaxed"><?= htmlspecialchars($infraction['specific_offenses']) ?></p>
                </div>

                <!-- Instances Grid -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 pt-4 border-t border-gray-700/50">
                    <div class="text-center">
                        <div class="w-8 h-8 bg-yellow-500/20 rounded-lg flex items-center justify-center mx-auto mb-2">
                            <span class="text-yellow-400 text-sm font-bold">1</span>
                        </div>
                        <p class="text-xs text-yellow-300 font-medium">1st Instance</p>
                        <p class="text-white text-sm font-semibold mt-1"><?= htmlspecialchars($infraction['first_instance']) ?></p>
                    </div>
                    <div class="text-center">
                        <div class="w-8 h-8 bg-orange-500/20 rounded-lg flex items-center justify-center mx-auto mb-2">
                            <span class="text-orange-400 text-sm font-bold">2</span>
                        </div>
                        <p class="text-xs text-orange-300 font-medium">2nd Instance</p>
                        <p class="text-white text-sm font-semibold mt-1"><?= htmlspecialchars($infraction['second_instance']) ?></p>
                    </div>
                    <div class="text-center">
                        <div class="w-8 h-8 bg-red-500/20 rounded-lg flex items-center justify-center mx-auto mb-2">
                            <span class="text-red-400 text-sm font-bold">3</span>
                        </div>
                        <p class="text-xs text-red-300 font-medium">3rd Instance</p>
                        <p class="text-white text-sm font-semibold mt-1"><?= htmlspecialchars($infraction['third_instance']) ?></p>
                    </div>
                    <div class="text-center">
                        <div class="w-8 h-8 bg-purple-500/20 rounded-lg flex items-center justify-center mx-auto mb-2">
                            <span class="text-purple-400 text-sm font-bold">4</span>
                        </div>
                        <p class="text-xs text-purple-300 font-medium">4th Instance</p>
                        <p class="text-white text-sm font-semibold mt-1"><?= htmlspecialchars($infraction['fourth_instance']) ?></p>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-between pt-4 mt-4 border-t border-gray-700/50">
                <div class="flex items-center space-x-4 text-xs text-gray-400">
                    <div class="flex items-center space-x-1">
                        <i class="fas fa-clock"></i>
                        <span>Created: <?= date('M j, Y g:i A', strtotime($infraction['date_created'])) ?></span>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <span class="px-2 py-1 bg-primary-500/10 text-primary-400 rounded text-xs font-medium border border-primary-500/20">
                        ID: <?= $infraction['id'] ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="mt-8 flex flex-col sm:flex-row items-center justify-between space-y-4 sm:space-y-0">
    <div class="text-sm text-gray-400">
        Showing <span class="font-semibold text-white"><?= ($offset + 1) ?></span> to <span class="font-semibold text-white"><?= min($offset + $perPage, $totalRecords) ?></span> of <span class="font-semibold text-white"><?= $totalRecords ?></span> infractions
    </div>
    <div class="flex gap-2">
        <?php if ($page > 1): ?>
            <a href="#" data-page="1" class="inline-flex items-center justify-center w-10 h-10 bg-gray-700/50 hover:bg-primary-500/20 text-gray-300 hover:text-primary-300 rounded-xl border border-gray-600/50 hover:border-primary-500/30 transition-all duration-200 transform hover:scale-105">
                <i class="fas fa-angle-double-left text-xs"></i>
            </a>
            <a href="#" data-page="<?= $page - 1 ?>" class="inline-flex items-center justify-center w-10 h-10 bg-gray-700/50 hover:bg-primary-500/20 text-gray-300 hover:text-primary-300 rounded-xl border border-gray-600/50 hover:border-primary-500/30 transition-all duration-200 transform hover:scale-105">
                <i class="fas fa-angle-left text-xs"></i>
            </a>
        <?php endif; ?>

        <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="#" data-page="<?= $i ?>" class="inline-flex items-center justify-center w-10 h-10 rounded-xl border transition-all duration-200 transform hover:scale-105 <?= $i == $page ? 'bg-gradient-to-br from-primary-500 to-blue-500 border-primary-500 text-white shadow-lg shadow-primary-500/25' : 'bg-gray-700/50 hover:bg-primary-500/20 border-gray-600/50 hover:border-primary-500/30 text-gray-300 hover:text-primary-300' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="#" data-page="<?= $page + 1 ?>" class="inline-flex items-center justify-center w-10 h-10 bg-gray-700/50 hover:bg-primary-500/20 text-gray-300 hover:text-primary-300 rounded-xl border border-gray-600/50 hover:border-primary-500/30 transition-all duration-200 transform hover:scale-105">
                <i class="fas fa-angle-right text-xs"></i>
            </a>
            <a href="#" data-page="<?= $totalPages ?>" class="inline-flex items-center justify-center w-10 h-10 bg-gray-700/50 hover:bg-primary-500/20 text-gray-300 hover:text-primary-300 rounded-xl border border-gray-600/50 hover:border-primary-500/30 transition-all duration-200 transform hover:scale-105">
                <i class="fas fa-angle-double-right text-xs"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<style>
.hover\:scale-\[1\.02\] {
    transform: scale(1);
}

.hover\:scale-\[1\.02\]:hover {
    transform: scale(1.02);
}

.group:hover .group-hover\:from-primary-500\/30 {
    --tw-gradient-from: rgb(59 130 246 / 0.3);
    --tw-gradient-to: rgb(59 130 246 / 0);
    --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to);
}

.group:hover .group-hover\:to-blue-500\/30 {
    --tw-gradient-to: rgb(59 130 246 / 0.3);
}

.shadow-primary-500\/25 {
    --tw-shadow-color: rgb(59 130 246 / 0.25);
    --tw-shadow: var(--tw-shadow-colored);
}
</style>