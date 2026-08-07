<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

// Pagination
$limit = 10; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Input Sanitization
$currentTab   = $_GET['tab'] ?? 'all';
$search       = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$typeFilter   = $_GET['type_filter'] ?? '';
$dateFrom     = $_GET['from'] ?? '';
$dateTo       = $_GET['to'] ?? '';
$isEmailOnly  = isset($_GET['is_email']) && $_GET['is_email'] === '1';

$stats = ['pending' => 0, 'closed_today' => 0, 'is_email' => 0, 'total' => 0];
$totalPages = 1;

// Handle Bulk Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $selectedIds = $_POST['selected_tickets'] ?? [];
    if (!empty($selectedIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        
        if ($_POST['bulk_action'] === 'bulk_delete') {
            foreach ($selectedIds as $id) { logActivity("Bulk deleted network ticket", $id, 'network'); }
            $stmt = $pdo->prepare("DELETE FROM network_tickets WHERE id IN ($placeholders)");
            $stmt->execute($selectedIds);
            $_SESSION['success'] = "Selected records deleted.";
        } elseif ($_POST['bulk_action'] === 'bulk_close') {
            foreach ($selectedIds as $id) { logActivity("Bulk marked ticket as CLOSED", $id, 'network'); }
            $stmt = $pdo->prepare("UPDATE network_tickets SET status = 'close' WHERE id IN ($placeholders)");
            $stmt->execute($selectedIds);
            $_SESSION['success'] = "Selected tickets marked as CLOSED.";
        } 
        // START NEW RETRACK LOGIC
        elseif ($_POST['bulk_action'] === 'bulk_retrack') {
            foreach ($selectedIds as $id) { logActivity("Reset email status (Retrack)", $id, 'network'); }
            // Sets email_sent back to 0 
            $stmt = $pdo->prepare("UPDATE network_tickets SET email_sent = 0 WHERE id IN ($placeholders)");
            $stmt->execute($selectedIds);
            $_SESSION['success'] = "Email status reset successfully.";
        }
        elseif ($_POST['bulk_action'] === 'bulk_check_email') {
            foreach ($selectedIds as $id) { 
                logActivity("Manually marked email icon as checked (Bulk)", $id, 'network'); 
            }
            // Update email_sent to 1 (checked)
            $stmt = $pdo->prepare("UPDATE network_tickets SET email_sent = 1 WHERE id IN ($placeholders)");
            $stmt->execute($selectedIds);
            $_SESSION['success'] = "Selected tickets marked as emailed.";
            header("Location: terabit_tracker.php");
            exit();
        }

    }
}

// Single Delete Logic
if (isset($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    logActivity("Deleted network ticket #$deleteId", $deleteId, 'network');
    $pdo->prepare("DELETE FROM network_tickets WHERE id = ?")->execute([$deleteId]);
    $_SESSION['success'] = "Record deleted successfully!";
    header("Location: terabit_tracker.php"); 
    exit();
}

try {
    // Get Statistics 
    $stats['pending'] = $pdo->query("SELECT COUNT(*) FROM network_tickets WHERE status = 'pending'")->fetchColumn();
    $stats['total'] = $pdo->query("SELECT COUNT(*) FROM network_tickets")->fetchColumn();
    $stats['is_email'] = $pdo->query("SELECT COUNT(*) FROM network_tickets WHERE is_email = 1")->fetchColumn();
    
    $today = date('Y-m-d');
    $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM network_tickets WHERE status = 'close' AND DATE(date_reported) = ?");
    $stmtToday->execute([$today]);
    $stats['closed_today'] = $stmtToday->fetchColumn();

    // Build Filter Query Logic
    $whereClauses = ["1=1"];
    $params = [];

    // Combine Tab and Type Filter 
    $activeType = !empty($typeFilter) ? $typeFilter : ($currentTab !== 'all' ? $currentTab : '');
    if (!empty($activeType)) {
        $whereClauses[] = "type = ?";
        $params[] = $activeType;
    }

    if ($isEmailOnly) {
        $whereClauses[] = "is_email = 1";
    }

    if (!empty($search)) { 
        $whereClauses[] = "(subject LIKE ? OR nickname LIKE ? OR cxi_id LIKE ? OR slt_on_duty LIKE ?)";
        $term = "%$search%"; 
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    }

    if (!empty($statusFilter)) { 
        $whereClauses[] = "status = ?"; 
        $params[] = $statusFilter; 
    }

    if (!empty($dateFrom)) { 
        $whereClauses[] = "DATE(date_received) >= ?"; 
        $params[] = $dateFrom; 
    }

    if (!empty($dateTo)) { 
        $whereClauses[] = "DATE(date_received) <= ?"; 
        $params[] = $dateTo; 
    }

    $whereSql = implode(" AND ", $whereClauses);

    // Total filtered records for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM network_tickets WHERE $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Fetch Page Results
    $query = "SELECT * FROM network_tickets WHERE $whereSql ORDER BY date_received DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();

} catch (PDOException $e) { 
    error_log($e->getMessage()); 
}

require_once '../components/layout.php';
renderHead('Terabit Tracker');
renderNavbar();
renderSidebar('network');
?>

<div class="pt-2 min-h-screen bg-gray-900 text-white font-sans">
    <main class="p-6">
        <form id="bulkForm" method="POST">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Terabit Tracker</h1>
            <div class="flex items-center gap-3">
                    <div id="bulkActions" class="hidden flex items-center gap-2">
                        <button type="submit" name="bulk_action" value="bulk_check_email" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition-all ">
                            <i class="fa-solid fa-envelope-circle-check mr-2"></i>No Need Email
                        </button>
                        <button type="submit" name="bulk_action" value="bulk_retrack" class="bg-purple-600 hover:bg-purple-700 px-4 py-2 rounded-lg transition-all "><i class="fa-solid fa-envelope mr-2"></i>Retrack Email</button>
                        <button type="submit" name="bulk_action" value="bulk_close" class="bg-green-600 hover:bg-green-700 px-4 py-2 rounded-lg transition-all"><i class="fa-solid fa-circle-check mr-2"></i>Mark as CLOSED</button>
                        <button type="submit" name="bulk_action" value="bulk_delete" onclick="return confirm('Delete selected entries?')" class="bg-red-600 hover:bg-red-700 px-4 py-2 rounded-lg transition-all"><i class="fa-solid fa-trash-can mr-2"></i>Delete Selected</button>
                    </div>
                <button type="button" onclick="openImportModal()" class="bg-emerald-600 hover:bg-emerald-700 px-4 py-2 rounded-lg transition-all">
                    <i class="fa-solid fa-file-import mr-2"></i> Import Excel
                </button>
                <a href="terabit_ticket_form.php?action=create" class="bg-primary-600 hover:bg-primary-700 px-4 py-2 rounded-lg transition-all"><i class="fas fa-plus mr-2"></i> Add Entry</a>
            </div>
        </div>

        <?php renderAlert(); ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <a href="terabit_tracker.php?status=pending" class="bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-md hover:border-yellow-500/50 transition-all group">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-gray-400 text-sm font-medium group-hover:text-yellow-500 transition-colors">Pending Tickets</h3>
                        <p class="text-3xl font-bold mt-2 text-yellow-500"><?= $stats['pending'] ?></p>
                    </div>
                    <div class="bg-yellow-500/10 p-3 rounded-full group-hover:bg-yellow-500/20 transition-all">
                        <i class="fas fa-clock text-yellow-500 text-xl"></i>
                    </div>
                </div>
            </a>
            
            <a href="terabit_tracker.php?status=close" class="bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-md hover:border-green-500/50 transition-all group">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-gray-400 text-sm font-medium group-hover:text-green-500 transition-colors">Closed Today</h3>
                        <p class="text-3xl font-bold mt-2 text-green-500"><?= $stats['closed_today'] ?></p>
                    </div>
                    <div class="bg-green-500/10 p-3 rounded-full group-hover:bg-green-500/20 transition-all">
                        <i class="fas fa-check-double text-green-500 text-xl"></i>
                    </div>
                </div>
            </a>

            <a href="terabit_tracker.php?is_email=1" class="bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-md hover:border-blue-500/50 transition-all group">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-gray-400 text-sm font-medium group-hover:text-blue-500 transition-colors">Email Source</h3>
                        <p class="text-3xl font-bold mt-2 text-blue-500"><?= $stats['is_email'] ?></p>
                    </div>
                    <div class="bg-blue-500/10 p-3 rounded-full group-hover:bg-blue-500/20 transition-all">
                        <i class="fas fa-envelope text-blue-500 text-xl"></i>
                    </div>
                </div>
            </a>

            <a href="terabit_tracker.php" class="bg-gray-800 rounded-xl border border-gray-700 p-6 shadow-md hover:border-primary-500/50 transition-all group">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-gray-400 text-sm font-medium group-hover:text-primary-500 transition-colors">Total Volume</h3>
                        <p class="text-3xl font-bold mt-2 text-white"><?= $stats['total'] ?></p>
                    </div>
                    <div class="bg-primary-500/10 p-3 rounded-full group-hover:bg-primary-500/20 transition-all">
                        <i class="fas fa-database text-primary-500 text-xl"></i>
                    </div>
                </div>
            </a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
            <div class="relative lg:col-span-1">
                <input type="text" id="searchInput" oninput="debounceFilter()" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-4 py-2 focus:ring-2 focus:ring-primary-500 outline-none" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <input type="date" id="dateFrom" onchange="autoFilter()" value="<?= $dateFrom ?>" class="bg-gray-800 border border-gray-700 rounded-lg text-white px-4 py-2 outline-none">
            <input type="date" id="dateTo" onchange="autoFilter()" value="<?= $dateTo ?>" class="bg-gray-800 border border-gray-700 rounded-lg text-white px-4 py-2 outline-none">
            
            <select id="typeFilter" onchange="autoFilter()" class="bg-gray-800 border border-gray-700 rounded-lg text-white px-4 py-2 outline-none">
                <option value="">ALL TYPES</option>
                <?php foreach(['Technical', 'Billing', 'Hardware', 'Software', 'Access', 'Other'] as $tOpt): ?>
                    <option value="<?= strtolower($tOpt) ?>" <?= strtolower($typeFilter) === strtolower($tOpt) ? 'selected' : '' ?>><?= $tOpt ?></option>
                <?php endforeach; ?>
            </select>

            <select id="statusFilter" onchange="autoFilter()" class="bg-gray-800 border border-gray-700 rounded-lg text-white px-4 py-2 outline-none">
                <option value="">ALL STATUS</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>PENDING</option>
                <option value="close" <?= $statusFilter === 'close' ? 'selected' : '' ?>>CLOSED</option>
            </select>
            
            <button type="button" onclick="window.location.href='terabit_tracker.php'" class="bg-gray-700 hover:bg-gray-600 rounded-lg px-4 py-2 font-medium transition-colors">Clear</button>
        </div>

        <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-900/50 text-gray-500 text-[10px] font-bold uppercase tracking-widest">
                        <tr>
                            <th class="px-6 py-4 text-left w-10"><input type="checkbox" id="selectAll" class="rounded bg-gray-700 border-gray-600 text-primary-600"></th>
                            <th class="px-6 py-4 text-left">Ticket ID</th>
                            <th class="px-6 py-4 text-left">Received Date</th>
                            <th class="px-6 py-4 text-left">Subject</th>
                            <th class="px-6 py-4 text-left">Type</th>
                            <th class="px-6 py-4 text-left">Status</th>
                            <th class="px-6 py-4 text-left">SLT on Duty</th>
                            <th class="px-6 py-4 text-left">Date Reported</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700 text-sm">
                        <?php if (empty($tickets)): ?>
                            <tr><td colspan="8" class="px-6 py-12 text-center text-gray-500 italic">No data found.</td></tr>
                        <?php else: foreach ($tickets as $row): ?>
                            <tr class="hover:bg-gray-700/40 transition-colors group">
                                <td class="px-6 py-4"><input type="checkbox" name="selected_tickets[]" value="<?= $row['id'] ?>" class="ticket-checkbox rounded bg-gray-700 border-gray-600 text-primary-600"></td>
                                <td class="px-6 py-4 whitespace-nowrapfont-mono">
                                    <?= !empty($row['SLT_TICKET_ID']) ? $row['SLT_TICKET_ID'] : 'SLT' . str_pad($row['id'], 4, '0', STR_PAD_LEFT) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-semibold text-gray-200"><?= date('M d, Y', strtotime($row['date_received'])) ?></div>
                                    <div class="text-[14px]"><?= date('h:i A', strtotime($row['date_received'])) ?></div>
                                </td>
                                <td class="px-6 py-4 uppercase font-medium text-gray-100 max-w-xs truncate"><?= htmlspecialchars($row['subject']) ?></td>
                                <td class="px-6 py-4 text-[11px] font-bold text-gray-400 uppercase"><?= htmlspecialchars($row['type']) ?></td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-black <?= $row['status'] === 'pending' ? 'bg-yellow-500/10 text-yellow-500 border border-yellow-500/20' : 'bg-green-500/10 text-green-500 border border-green-500/20' ?>">
                                        <?= strtoupper($row['status'] === 'close' ? 'CLOSED' : $row['status']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-bold text-gray-300"><?= htmlspecialchars($row['slt_on_duty']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-semibold"><?= date('M d, Y', strtotime($row['date_reported'])) ?></div>
                                    <div class="text-[14px]"><?= date('h:i A', strtotime($row['date_reported'])) ?></div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex justify-end gap-3 items-center">
                                        
                                        <div id="email-container-<?= $row['id'] ?>" class="flex items-center min-w-[24px] justify-end">
                                            <?php if (isset($row['email_sent']) && $row['email_sent'] == 1): ?>
                                                <i class="fas fa-check-circle text-green-500" title="Already Emailed"></i>
                                            <?php else: ?>
                                                <button type="button" 
                                                        onclick="sendNetworkEmail(<?= $row['id'] ?>)" 
                                                        class="text-blue-400 hover:text-blue-300 transition-colors" 
                                                        title="Send Notification">
                                                    <i class="fa-solid fa-envelope"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($row['email_link'])): ?>
                                            <a href="<?= htmlspecialchars($row['email_link']) ?>" target="_blank" class="text-red-500 hover:text-red-300 transition-colors" title="Reference Link">
                                                <i class="fas fa-link"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        
                                        <a href="terabit_ticket_form.php?action=edit&id=<?= $row['id'] ?>" class="text-blue-500 hover:text-blue-400" title="Edit"><i class="fas fa-edit"></i></a>
                                        <button type="button" onclick="showHistoryModal(<?= $row['id'] ?>, 'network')" class="text-purple-500 hover:text-purple-300 transition-colors" title="History"><i class="fas fa-history"></i></button>
                                        <button type="button" onclick="confirmDelete(<?= $row['id'] ?>)" class="text-red-500 hover:text-red-400" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

<?php if ($totalPages > 1): ?>
<div class="px-6 py-4 bg-gray-900/50 border-t border-gray-700 flex flex-col md:flex-row items-center justify-between gap-4 text-xs">
    <div class="text-gray-400">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?></div>
    
    <div class="flex gap-1 items-center">
        
        <button type="button" onclick="changePage(1)" 
                class="px-3 py-2 rounded border border-gray-700 bg-gray-800 text-gray-400 hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed" 
                <?= ($page <= 1) ? 'disabled' : '' ?>>
            <i class="fas fa-angle-double-left"></i>
        </button>

        <button type="button" onclick="changePage(<?= max(1, $page - 1) ?>)" 
                class="px-3 py-2 rounded border border-gray-700 bg-gray-800 text-gray-400 hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed" 
                <?= ($page <= 1) ? 'disabled' : '' ?>>
            <i class="fas fa-angle-left"></i>
        </button>

        <?php 
        $range = 2; 
        for ($i = 1; $i <= $totalPages; $i++): 
            if ($i == 1 || $i == $totalPages || ($i >= $page - $range && $i <= $page + $range)): ?>
                <button type="button" onclick="changePage(<?= $i ?>)" 
                        class="px-4 py-2 rounded border transition-all <?= $page == $i ? 'bg-primary-600 border-primary-500 text-white font-bold' : 'bg-gray-800 border-gray-700 text-gray-400 hover:bg-gray-700' ?>">
                    <?= $i ?>
                </button>
            <?php elseif ($i == $page - $range - 1 || $i == $page + $range + 1): ?>
                <span class="text-gray-600 px-1">...</span>
            <?php endif; 
        endfor; ?>

        <button type="button" onclick="changePage(<?= min($totalPages, $page + 1) ?>)" 
                class="px-3 py-2 rounded border border-gray-700 bg-gray-800 text-gray-400 hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed" 
                <?= ($page >= $totalPages) ? 'disabled' : '' ?>>
            <i class="fas fa-angle-right"></i>
        </button>

        <button type="button" onclick="changePage(<?= $totalPages ?>)" 
                class="px-3 py-2 rounded border border-gray-700 bg-gray-800 text-gray-400 hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed" 
                <?= ($page >= $totalPages) ? 'disabled' : '' ?>>
            <i class="fas fa-angle-double-right"></i>
        </button>

    </div>
</div>
<?php endif; ?>
        </div>
        </form>
    </main>
</div>

<div id="historyModal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center z-50">
    <div class="bg-gray-800 rounded-2xl w-full max-w-lg p-6 border border-gray-700 shadow-2xl">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-white flex items-center"><i class="fas fa-history mr-3 text-primary-500"></i> History </h3>
            <button onclick="closeHistoryModal()" class="text-gray-400 hover:text-white"><i class="fas fa-times fa-lg"></i></button>
        </div>
        <div id="historyContent" class="space-y-4 max-h-[450px] overflow-y-auto pr-3 text-left"></div>
    </div>
</div>

<!--- MODAL FOR IMPORT EXCEL BUTTON --->

<div id="importModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm flex items-center justify-center z-[100]">
    <div class="bg-gray-800 border border-gray-700 rounded-2xl w-full max-w-md p-8 shadow-2xl relative text-left">
        <button onclick="closeImportModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white"><i class="fas fa-times fa-lg"></i></button>
        
        <h3 class="text-xl font-bold mb-2 flex items-center"><i class="fa-solid fa-file-excel text-green-500 mr-3"></i> Bulk Import</h3>
        <p class="text-gray-400 text-sm mb-6">Select or drag your Excel (.xlsx) or CSV file here.</p>

        <form id="importForm">
            <div id="dropZone" class="border-2 border-dashed border-gray-600 rounded-xl p-10 text-center hover:border-emerald-500 hover:bg-emerald-500/5 transition-all cursor-pointer group">
                <input type="file" name="excel_file" id="fileInput" class="hidden" accept=".xlsx, .xls, .csv">
                <i class="fa-solid fa-cloud-arrow-up text-4xl text-gray-500 group-hover:text-emerald-500 mb-4"></i>
                <p id="fileName" class="text-gray-300 font-medium">Drag & drop or <span class="text-emerald-500">browse</span></p>
                <p class="text-gray-500 text-xs mt-2">Supports .xlsx and .csv</p>
            </div>
            
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeImportModal()" class="px-4 py-2 text-gray-400 hover:text-white font-bold">Cancel</button>
                <button type="submit" id="submitImport" class="bg-emerald-600 hover:bg-emerald-700 px-6 py-2 rounded-lg font-bold transition-all">Upload & Process</button>
            </div>
        </form>
    </div>
</div>

<script>
let timer;

function debounceFilter() {
    clearTimeout(timer);
    timer = setTimeout(autoFilter, 500); 
}

function autoFilter() {
    const s = document.getElementById('searchInput').value;
    const st = document.getElementById('statusFilter').value;
    const ty = document.getElementById('typeFilter').value;
    const f = document.getElementById('dateFrom').value;
    const t = document.getElementById('dateTo').value;
    
    // Check if the URL already has is_email to preserve that filter
    const urlParams = new URLSearchParams(window.location.search);
    const isEmail = urlParams.get('is_email') || '';

    window.location.href = `terabit_tracker.php?tab=<?= $currentTab ?>&search=${encodeURIComponent(s)}&status=${st}&type_filter=${ty}&from=${f}&to=${t}&is_email=${isEmail}&page=1`;
}

function showHistoryModal(id, type) {
    const modal = document.getElementById('historyModal');
    const content = document.getElementById('historyContent');
    modal.classList.remove('hidden');
    content.innerHTML = '<div class="text-center py-10"><i class="fas fa-circle-notch fa-spin fa-2x text-primary-500"></i></div>';
    
    fetch(`get_history.php?record_id=${id}&type=${type}`)
        .then(r => r.json())
        .then(data => {
            let html = '';
            if (data.length > 0) {
                data.forEach(h => {
                    html += `<div class="p-4 bg-gray-900/50 rounded-xl border border-gray-700/50 relative">
                        <div class="flex justify-between items-center mb-2 text-left">
                            <span class="text-sm font-bold text-primary-400">${h.sub_name}</span>
                            <span class="text-[10px] text-gray-500 font-mono">${h.activity_time}</span>
                        </div>
                        <p class="text-xs text-gray-300 text-left">${h.activity_description}</p>
                    </div>`;
                });
            } else { 
                html = '<p class="text-center text-gray-500 py-10 italic text-sm">No activity logs found.</p>'; 
            }
            content.innerHTML = html;
        });
}

function closeHistoryModal() { document.getElementById('historyModal').classList.add('hidden'); }

// Bulk Selection and Buttons
const selectAll = document.getElementById('selectAll');
const ticketCheckboxes = document.querySelectorAll('.ticket-checkbox');
const bulkActions = document.getElementById('bulkActions');

function toggleBulk() {
    const checkedCount = document.querySelectorAll('.ticket-checkbox:checked').length;
    bulkActions.classList.toggle('hidden', checkedCount === 0);
}

if(selectAll) {
    selectAll.addEventListener('change', function() {
        ticketCheckboxes.forEach(cb => cb.checked = this.checked);
        toggleBulk();
    });
}

ticketCheckboxes.forEach(cb => cb.addEventListener('change', toggleBulk));

function confirmDelete(id) { 
    if(confirm('Are you sure you want to delete this log?')) 
    window.location.href=`terabit_tracker.php?delete=${id}`; 
}

function changePage(p) { 
    const u = new URL(window.location.href); 
    u.searchParams.set('page', p); 
    window.location.href = u.href; 
}

function sendNetworkEmail(id) {
    const container = document.getElementById(`email-container-${id}`);
    const originalHTML = container.innerHTML;

    // Show loading spinner
    container.innerHTML = '<i class="fas fa-circle-notch fa-spin text-blue-400"></i>';

    const formData = new FormData();
    formData.append('id', id);

    // Just send the request and reload on success
    fetch('../includes/terabit_emailer.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                // Simply reload the page
                window.location.reload();
            } else {
                alert("Error sending email: " + data.message);
                container.innerHTML = originalHTML;
            }
        } catch (err) {
            window.location.reload();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert("System error occurred.");
        container.innerHTML = originalHTML;
    });
}


// Modal Functions
function openImportModal() { document.getElementById('importModal').classList.remove('hidden'); }
function closeImportModal() { document.getElementById('importModal').classList.add('hidden'); }

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileNameDisplay = document.getElementById('fileName');

// File Selection logic
dropZone.onclick = () => fileInput.click();
fileInput.onchange = () => { if (fileInput.files.length) fileNameDisplay.innerText = fileInput.files[0].name; };

// Drag and Drop Logic
['dragover', 'dragenter'].forEach(type => {
    dropZone.addEventListener(type, (e) => { e.preventDefault(); dropZone.classList.add('border-emerald-500', 'bg-emerald-500/10'); });
});
['dragleave', 'drop'].forEach(type => {
    dropZone.addEventListener(type, () => { dropZone.classList.remove('border-emerald-500', 'bg-emerald-500/10'); });
});
dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        fileNameDisplay.innerText = e.dataTransfer.files[0].name;
    }
});

// AJAX Submission
document.getElementById('importForm').onsubmit = function(e) {
    e.preventDefault();
    
    console.log('=== IMPORT FORM SUBMITTED ===');
    console.log('File input exists:', fileInput !== null);
    console.log('Files selected:', fileInput.files.length);
    
    if (!fileInput.files.length) {
        alert("Please select a file.");
        return;
    }

    const file = fileInput.files[0];
    console.log('File details:', {
        name: file.name,
        type: file.type,
        size: file.size + ' bytes (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)',
        lastModified: new Date(file.lastModified).toLocaleString()
    });

    // Check file size (10MB limit)
    const maxSize = 10 * 1024 * 1024; // 10MB
    if (file.size > maxSize) {
        alert(`File too large. Maximum size is 10MB. Your file: ${(file.size / 1024 / 1024).toFixed(2)}MB`);
        return;
    }

    // Check file type
    const allowedTypes = ['.xlsx', '.xls', '.csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv'];
    const fileExt = '.' + file.name.split('.').pop().toLowerCase();
    if (!allowedTypes.includes(fileExt) && !allowedTypes.includes(file.type)) {
        alert(`Invalid file type. Please select .xlsx, .xls, or .csv files only.`);
        return;
    }

    const btn = document.getElementById('submitImport');
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';

    const formData = new FormData();
    formData.append('excel_file', file);

    console.log('Sending fetch request to: ../includes/import_network_tickets.php');
    console.log('FormData entries:');
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + (pair[0] === 'excel_file' ? '[FILE]' : pair[1]));
    }

    // Add timeout to fetch
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout

    fetch('../includes/import_network_tickets.php', { 
        method: 'POST', 
        body: formData,
        signal: controller.signal
    })
    .then(async response => {
        clearTimeout(timeoutId);
        console.log('Response received:');
        console.log('- Status:', response.status, response.statusText);
        console.log('- Headers:', {
            'content-type': response.headers.get('content-type'),
            'content-length': response.headers.get('content-length')
        });
        
        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
        }
        
        // Try to get response text first for debugging
        const responseText = await response.text();
        console.log('Raw response text:', responseText.substring(0, 500) + (responseText.length > 500 ? '...' : ''));
        
        // Try to parse as JSON
        try {
            return JSON.parse(responseText);
        } catch (e) {
            console.error('Failed to parse JSON. First 500 chars:', responseText.substring(0, 500));
            throw new Error('Invalid JSON response from server. Check server error logs.');
        }
    })
    .then(data => {
        console.log('Parsed response data:', data);
        
        if (data.success) { 
            alert(`✅ Import successful! ${data.count || ''} records imported.`); 
            window.location.reload(); 
        } else { 
            alert(`❌ Error: ${data.message || 'Unknown error occurred'}`); 
            console.error('Import failed:', data);
            btn.disabled = false; 
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        clearTimeout(timeoutId);
        console.error('❌ Fetch/Processing error:', err);
        
        // Detailed error message
        let errorMessage = 'Server error. ';
        if (err.name === 'AbortError') {
            errorMessage += 'Request timed out after 30 seconds. The file might be too large or the server is slow.';
        } else if (err.message.includes('Failed to fetch')) {
            errorMessage += 'Network error - could not connect to server. Check if the server is accessible.';
        } else if (err.message.includes('HTTP error')) {
            errorMessage += err.message;
        } else {
            errorMessage += err.message;
        }
        
        alert(errorMessage);
        
        // Additional debugging info
        console.log('Debugging tips:');
        console.log('1. Check if ../includes/import_network_tickets.php exists');
        console.log('2. Check browser console for network errors (F12 → Network tab)');
        console.log('3. Check server error logs');
        console.log('4. Verify file permissions on the server');
        
        btn.disabled = false; 
        btn.innerHTML = originalText;
    });
};

// Add network error handling for failed requests
window.addEventListener('unhandledrejection', function(event) {
    console.error('Unhandled promise rejection:', event.reason);
    alert('An unexpected error occurred. Check console for details.');
});
</script>

<?php renderFooter(); ?>