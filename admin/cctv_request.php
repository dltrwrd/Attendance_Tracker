<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

// Handle pending count request for notifications
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_count') {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as pending_count FROM cctv_request WHERE status = 'PENDING'");
        $stmt->execute();
        $result = $stmt->fetch();
        
        header('Content-Type: application/json');
        echo json_encode(['pending_count' => $result['pending_count']]);
        exit();
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['pending_count' => 0]);
        exit();
    }
}

// Get pending CCTV count for sidebar
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_count FROM cctv_request WHERE status = 'PENDING'");
    $stmt->execute();
    $pendingCCTVCount = $stmt->fetchColumn();
} catch (PDOException $e) {
    $pendingCCTVCount = 0;
}

// Handle deletion
if (isset($_GET['delete'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $id = (int)$_GET['delete'];
    $requiredPassword = "SLT@2025";
    $providedPassword = $_POST['delete_password'] ?? '';
    
    if (empty($providedPassword) || $providedPassword !== $requiredPassword) {
        $_SESSION['error'] = "Incorrect or missing password for deletion";
        redirect('cctv_request.php');
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM cctv_request WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success'] = "CCTV request deleted successfully!";
        } else {
            $_SESSION['error'] = "CCTV request not found";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting record: " . $e->getMessage();
    }
    
    redirect('cctv_request.php');
}

// Handle resolve action
if (isset($_POST['resolve_id'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $id = (int)$_POST['resolve_id'];
    $resolvedBy = $_SESSION['nickname'];
    
    try {
        $stmt = $pdo->prepare("UPDATE cctv_request SET status = 'REVIEWED', resolved_by = ?, resolved_at = NOW() WHERE id = ? AND status = 'PENDING'");
        $stmt->execute([$resolvedBy, $id]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success'] = "CCTV request marked as resolved successfully!";
        } else {
            $_SESSION['error'] = "CCTV request not found or already resolved";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating record: " . $e->getMessage();
    }
    
    redirect('cctv_request.php');
}

require_once '../components/layout.php';
renderHead('CCTV Review Requests');
renderNavbar();
renderSidebar('cctv_request', 0, $pendingCCTVCount);
?>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">CCTV Review Requests</h1>
            <div class="flex gap-3">
                <a href="../cctv_request_external.php" 
                   target="_blank" 
                   class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors duration-200">
                    <i class="fas fa-external-link-alt mr-2"></i> External Link
                </a>
                <a href="cctv_request_form.php?action=create" 
                   class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i> New CCTV Request
                </a>
            </div>
        </div>

        <?php renderAlert(); ?>

        <!-- Search and Filters -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <!-- Search Input -->
            <div class="relative sm:col-span-2 lg:col-span-1">
                <input type="text" id="searchInput" 
                    class="w-full pl-10 pr-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 shadow hover:border-blue-500 transition-colors duration-200 text-left"
                    placeholder="Search by employee ID or name..."
                    value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                <div class="absolute left-3 top-2.5 text-gray-400">
                    <i class="fas fa-search"></i>
                </div>
            </div>
            
            <!-- Date Range Filter -->
            <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
                <div class="relative flex-grow">
                    <input type="date" id="dateFrom" 
                        class="w-full px-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 shadow hover:border-blue-500 transition-colors duration-200 text-left"
                        value="<?= isset($_GET['from']) ? htmlspecialchars($_GET['from']) : '' ?>">
                </div>
                <div class="relative flex-grow">
                    <input type="date" id="dateTo" 
                        class="w-full px-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 shadow hover:border-blue-500 transition-colors duration-200 text-left"
                        value="<?= isset($_GET['to']) ? htmlspecialchars($_GET['to']) : '' ?>">
                </div>
            </div>
            
            <!-- Department Filter -->
            <div class="relative sm:col-span-1 lg:col-span-1">
                <select id="departmentFilter" 
                        class="w-full px-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 appearance-none shadow hover:border-blue-500 transition-colors duration-200 text-left">
                    <option value="">ALL DEPARTMENTS</option>
                    <?php
                    $stmt = $pdo->query("SELECT DISTINCT department FROM cctv_request ORDER BY department");
                    while ($row = $stmt->fetch()) {
                        $selected = (isset($_GET['dept']) && $_GET['dept'] === $row['department']) ? 'selected' : '';
                        echo '<option value="'.htmlspecialchars($row['department']).'" '.$selected.'>'.htmlspecialchars($row['department']).'</option>';
                    }
                    ?>
                </select>
                <div class="absolute right-3 top-2.5 text-gray-400 pointer-events-none">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
        </div>

        <!-- CCTV Requests Table -->
        <div id="cctvTableContainer">
            <?php include 'partials/cctv_table.php'; ?>
        </div>
    </main>
</div>

<script>
// Function to refresh CCTV notifications
function refreshCCTVNotifications() {
    if (typeof updateCCTVNotificationBadge === 'function') {
        updateCCTVNotificationBadge();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    const departmentFilter = document.getElementById('departmentFilter');
    let searchTimeout;

    // Function to load data with current filters
    function loadFilteredData(page = 1) {
        const urlParams = new URLSearchParams(window.location.search);
        const formData = new FormData();
        
        // Always include search
        formData.append('search', searchInput.value);
        
        // Add other filters
        formData.append('date_from', dateFrom.value);
        formData.append('date_to', dateTo.value);
        formData.append('department', departmentFilter.value);
        formData.append('page', page);
        
        fetch('partials/cctv_table.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            document.getElementById('cctvTableContainer').innerHTML = data;
            setupPaginationLinks();
            setupResolveButtons();
            // Refresh notifications after loading new data
            refreshCCTVNotifications();
        });
    }

    function setupPaginationLinks() {
        document.querySelectorAll('.pagination-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const page = this.getAttribute('data-page');
                loadFilteredData(page);
                
                // Update URL with new page parameter
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('page', page);
                history.pushState(null, '', '?' + urlParams.toString());
            });
        });
    }

    function setupResolveButtons() {
        document.querySelectorAll('.resolve-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const recordId = this.getAttribute('data-id');
                markAsResolved(recordId);
            });
        });
    }

    // Function to update URL with current filters
    function updateURL() {
        const urlParams = new URLSearchParams();
        
        if (searchInput.value) {
            urlParams.set('search', searchInput.value);
        }
        if (dateFrom.value) {
            urlParams.set('from', dateFrom.value);
        }
        if (dateTo.value) {
            urlParams.set('to', dateTo.value);
        }
        if (departmentFilter.value) {
            urlParams.set('dept', departmentFilter.value);
        }
        
        const newURL = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        history.pushState(null, '', newURL);
    }

    // Event listeners for filters
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            updateURL();
            loadFilteredData();
        }, 300);
    });

    dateFrom.addEventListener('change', () => {
        updateURL();
        loadFilteredData();
    });

    dateTo.addEventListener('change', () => {
        updateURL();
        loadFilteredData();
    });

    departmentFilter.addEventListener('change', () => {
        updateURL();
        loadFilteredData();
    });

    // Initial load - check for URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const initialPage = urlParams.get('page') || 1;
    loadFilteredData(initialPage);

    // Auto-refresh table every 60 seconds to show real-time updates
    setInterval(() => {
        const currentPage = document.querySelector('.pagination-link.bg-primary-600')?.getAttribute('data-page') || 1;
        loadFilteredData(currentPage);
    }, 60000);

    // Also refresh when window gains focus (user returns to tab)
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            const currentPage = document.querySelector('.pagination-link.bg-primary-600')?.getAttribute('data-page') || 1;
            loadFilteredData(currentPage);
            refreshCCTVNotifications();
        }
    });
});

// Mark as resolved function
function markAsResolved(recordId) {
    if (confirm('Are you sure you want to mark this request as resolved?')) {
        const formData = new FormData();
        formData.append('resolve_id', recordId);
        
        fetch('cctv_request.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.redirected) {
                window.location.href = response.url;
            } else {
                return response.text().then(html => {
                    // Refresh notifications after resolving
                    refreshCCTVNotifications();
                    // If not redirected, reload the page to show updated status
                    window.location.reload();
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while marking the request as resolved.');
        });
    }
}

// Delete confirmation modal
function showDeleteModal(recordId) {
    const modal = `
        <div id="deleteModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md">
                <div class="px-6 py-6">
                    <h3 class="text-lg font-bold text-gray-100 mb-4">Confirm Deletion</h3>
                    <p class="text-gray-300 mb-4">Are you sure you want to delete this CCTV request?</p>
                    <form method="post" action="cctv_request.php?delete=${recordId}" class="space-y-4">
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
</script>

<?php renderFooter(); ?>