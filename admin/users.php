<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

// Handle user deletion
if (isset($_GET['delete'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $userId = (int)$_GET['delete'];
    $type = isset($_GET['type']) ? $_GET['type'] : 'users';
    
    $requiredPassword = "SLT@2025";
    $providedPassword = $_POST['delete_password'] ?? '';
    
    if (empty($providedPassword) || $providedPassword !== $requiredPassword) {
        $_SESSION['error'] = "Incorrect or missing password for deletion";
        redirect('users.php?tab=' . $type);
        exit();
    }
    
    try {
        $table = ($type === 'management') ? 'management' : (($type === 'operations') ? 'operations_managers' : 'users');
        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->execute([$userId]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success'] = "Record deleted successfully!";
        } else {
            $_SESSION['error'] = "Record not found";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Cannot delete record: Record of this user could not be deleted. It has a history record in the tracker. Kindly contact the developer.";
    }
    
    redirect('users.php?tab=' . $type);
}

// Handle status toggle
if (isset($_GET['toggle_status'])) {
    $userId = (int)$_GET['toggle_status'];
    $type = isset($_GET['type']) ? $_GET['type'] : 'users';
    
    try {
        $table = ($type === 'management') ? 'management' : (($type === 'operations') ? 'operations_managers' : 'users');
        $stmt = $pdo->prepare("SELECT is_active FROM $table WHERE id = ?");
        $stmt->execute([$userId]);
        $currentStatus = $stmt->fetchColumn();
        
        $newStatus = $currentStatus ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE $table SET is_active = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);
        
        $_SESSION['success'] = "Record status updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating record status: " . $e->getMessage();
    }
    
    redirect('users.php?tab=' . $type);
}

require_once '../components/layout.php';
renderHead('Manage SLT');
renderNavbar();
renderSidebar('users');

$currentTab = isset($_GET['tab']) ? $_GET['tab'] : 'users';
?>

<!-- Minimal Loading Screen - Only for initial page load -->
<div id="initialLoading" class="fixed inset-0 bg-gray-900 flex items-center justify-center z-50 transition-opacity duration-300">
    <div class="text-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500 mx-auto mb-4"></div>
        <p class="text-white text-lg">Loading Team Members...</p>
    </div>
</div>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-white">Manage Team Members</h1>
            <a href="profile.php?action=create&type=users" id="addNewButton" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors duration-200">
                <i class="fas fa-plus mr-2"></i> Add New
            </a>
        </div>

        <!-- Tabs -->
        <div class="border-b border-gray-700 mb-6">
            <nav class="-mb-px flex space-x-8">
                <a href="?tab=users" class="<?= $currentTab === 'users' ? 'border-primary-500 text-primary-400' : 'border-transparent text-gray-400 hover:text-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200">
                    SLT Members
                </a>
                <a href="?tab=operations" class="<?= $currentTab === 'operations' ? 'border-primary-500 text-primary-400' : 'border-transparent text-gray-400 hover:text-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200">
                    Operations Managers
                </a>
                <a href="?tab=management" class="<?= $currentTab === 'management' ? 'border-primary-500 text-primary-400' : 'border-transparent text-gray-400 hover:text-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200">
                    Team Leaders
                </a>
            </nav>
        </div>

        <?php renderAlert(); ?>
        
        <!-- Search Section -->
        <div class="mb-6">
            <div class="relative flex-grow max-w-md">
                <input type="text" id="searchInput" 
                       class="w-full pl-10 pr-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 placeholder-gray-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors duration-200" 
                       placeholder="Search by name, CXI ID, or email...">
                <div class="absolute left-3 top-2.5 text-gray-400">
                    <i class="fas fa-search"></i>
                </div>
            </div>
        </div>

        <!-- Users Table Container -->
        <div id="usersTableContainer" class="transition-opacity duration-200">
            <?php include 'partials/users_table.php'; ?>
        </div>
    </main>
</div>

<script>
// Simple Loading Manager - Only for initial load
class SimpleLoadingManager {
    constructor() {
        this.initialLoading = document.getElementById('initialLoading');
        this.init();
    }

    init() {
        // Hide loading when page is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.hideInitialLoading());
        } else {
            this.hideInitialLoading();
        }
    }

    hideInitialLoading() {
        setTimeout(() => {
            if (this.initialLoading) {
                this.initialLoading.style.opacity = '0';
                setTimeout(() => {
                    this.initialLoading.remove();
                }, 200);
            }
        }, 500);
    }
}

// Initialize simple loading manager
const simpleLoadingManager = new SimpleLoadingManager();

// Optimized Users Manager - No loading on search
class UsersManager {
    constructor() {
        this.searchTimeout = null;
        this.debounceDelay = 400; // Slightly longer delay for better UX
        this.currentPage = 1;
        this.currentTab = 'users';
        this.searchTerm = '';
        this.isLoading = false;
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadInitialData();
        this.startOnlineStatusPolling();
    }

    bindEvents() {
        const searchInput = document.getElementById('searchInput');

        searchInput.addEventListener('input', () => this.handleSearchChange(searchInput.value));

        // Tab change handling
        document.querySelectorAll('nav a[href^="?tab="]').forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                const url = new URL(tab.href);
                const tabParam = url.searchParams.get('tab');
                this.switchTab(tabParam);
            });
        });

        // Update Add New button URL
        this.updateAddNewButton();

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'f':
                        e.preventDefault();
                        searchInput.focus();
                        break;
                    case 'n':
                        e.preventDefault();
                        const addNewButton = document.getElementById('addNewButton');
                        if (addNewButton) {
                            window.location.href = addNewButton.href;
                        }
                        break;
                }
            }
        });
    }

    updateAddNewButton() {
        const addNewButton = document.getElementById('addNewButton');
        if (addNewButton) {
            addNewButton.href = `profile.php?action=create&type=${this.currentTab}`;
        }
    }

    handleSearchChange(value) {
        this.searchTerm = value;
        
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            this.loadUsers(1);
            this.updateUrl(1);
        }, this.debounceDelay);
    }

    switchTab(tab) {
        this.currentTab = tab;
        this.currentPage = 1;
        this.searchTerm = '';
        document.getElementById('searchInput').value = '';
        
        // Update active tab styling
        document.querySelectorAll('nav a').forEach(link => {
            link.classList.remove('border-primary-500', 'text-primary-400');
            link.classList.add('border-transparent', 'text-gray-400');
        });
        
        const activeTab = document.querySelector(`nav a[href="?tab=${tab}"]`);
        if (activeTab) {
            activeTab.classList.add('border-primary-500', 'text-primary-400');
            activeTab.classList.remove('border-transparent', 'text-gray-400');
        }
        
        this.loadUsers(1);
        this.updateUrl(1);
        history.pushState(null, '', `?tab=${tab}`);
        
        // Update the Add New button URL
        this.updateAddNewButton();
    }

    async loadUsers(page = 1) {
        if (this.isLoading) return; // Prevent multiple simultaneous requests
        
        this.currentPage = page;
        this.isLoading = true;

        try {
            const formData = new FormData();
            formData.append('search', this.searchTerm);
            formData.append('page', page);
            formData.append('type', this.currentTab);

            const response = await fetch('partials/users_table.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Network response was not ok');

            const data = await response.text();
            document.getElementById('usersTableContainer').innerHTML = data;
            
            this.rebindEvents();
            this.updateOnlineStatuses();

        } catch (error) {
            console.error('Error loading users:', error);
            this.showError('Error loading data. Please try again.');
        } finally {
            this.isLoading = false;
        }
    }

    rebindEvents() {
        // Rebind pagination events
        document.querySelectorAll('.pagination-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = parseInt(link.getAttribute('data-page'));
                this.loadUsers(page);
                this.updateUrl(page);
            });
        });
    }

    updateUrl(page) {
        const params = new URLSearchParams();
        params.append('tab', this.currentTab);
        
        if (this.searchTerm) params.append('search', this.searchTerm);
        if (page > 1) params.append('page', page);
        
        const queryString = params.toString();
        const newUrl = queryString ? `?${queryString}` : window.location.pathname;
        history.replaceState(null, '', newUrl);
    }

    loadInitialData() {
        const urlParams = new URLSearchParams(window.location.search);
        
        this.currentTab = urlParams.get('tab') || 'users';
        this.searchTerm = urlParams.get('search') || '';
        const initialPage = parseInt(urlParams.get('page')) || 1;
        
        // Set initial values
        document.getElementById('searchInput').value = this.searchTerm;
        
        // Set active tab
        document.querySelectorAll('nav a').forEach(link => {
            link.classList.remove('border-primary-500', 'text-primary-400');
            link.classList.add('border-transparent', 'text-gray-400');
        });
        
        const activeTab = document.querySelector(`nav a[href="?tab=${this.currentTab}"]`);
        if (activeTab) {
            activeTab.classList.add('border-primary-500', 'text-primary-400');
            activeTab.classList.remove('border-transparent', 'text-gray-400');
        }
        
        // Update Add New button
        this.updateAddNewButton();
        
        this.loadUsers(initialPage);
    }

    startOnlineStatusPolling() {
        // Update online status immediately and then every 5 seconds
        this.updateOnlineStatuses();
        setInterval(() => this.updateOnlineStatuses(), 5000);
    }

    async updateOnlineStatuses() {
        try {
            const response = await fetch('../api/online_status.php');
            const data = await response.json();
            
            if (data.success) {
                document.querySelectorAll('.online-status').forEach(indicator => {
                    const userId = indicator.getAttribute('data-user-id');
                    const isOnline = data.onlineUsers.includes(parseInt(userId));
                    
                    if (isOnline) {
                        indicator.classList.remove('bg-gray-400');
                        indicator.classList.add('bg-green-400');
                        // Show the ping animation
                        const pingElement = indicator.previousElementSibling;
                        if (pingElement && pingElement.classList.contains('online-indicator')) {
                            pingElement.style.display = 'inline-flex';
                        }
                    } else {
                        indicator.classList.remove('bg-green-400');
                        indicator.classList.add('bg-gray-400');
                        // Hide the ping animation
                        const pingElement = indicator.previousElementSibling;
                        if (pingElement && pingElement.classList.contains('online-indicator')) {
                            pingElement.style.display = 'none';
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Error checking online status:', error);
        }
    }

    showError(message) {
        document.getElementById('usersTableContainer').innerHTML = `
            <div class="bg-red-900/20 border border-red-700 rounded-lg p-6 text-center">
                <i class="fas fa-exclamation-triangle text-red-400 text-2xl mb-3"></i>
                <p class="text-red-300 mb-4">${message}</p>
                <button onclick="usersManager.loadUsers(1)" 
                        class="bg-red-600 hover:bg-red-500 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    <i class="fas fa-redo mr-2"></i>Try Again
                </button>
            </div>
        `;
    }
}

// Delete Modal Functions
function showDeleteModal(recordId, recordType = '') {
    const modal = document.createElement('div');
    modal.id = 'deleteModal';
    modal.className = 'fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50 transition-opacity duration-200';
    modal.innerHTML = `
        <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md transform transition-transform duration-200 scale-95">
            <div class="px-6 py-6">
                <h3 class="text-lg font-bold text-gray-100 mb-4">Confirm Deletion</h3>
                <p class="text-gray-300 mb-4">Are you sure you want to delete this record? This action cannot be undone.</p>
                <form method="post" action="${window.location.pathname}?delete=${recordId}${recordType ? '&type=' + recordType : ''}" class="space-y-4">
                    <div>
                        <label for="delete_password" class="block text-sm font-medium text-gray-300 mb-1">To confirm please enter the KEY:</label>
                        <input type="password" name="delete_password" id="delete_password" 
                               class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-gray-200 focus:border-red-500 focus:ring-1 focus:ring-red-500 transition-colors duration-200" 
                               required autocomplete="off" placeholder="Enter deletion key">
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeDeleteModal()" 
                                class="px-4 py-2 bg-gray-600 text-gray-100 rounded-md hover:bg-gray-500 transition-colors duration-200">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-500 transition-colors duration-200">
                            Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    setTimeout(() => {
        modal.querySelector('div').classList.remove('scale-95');
    }, 10);
    
    modal.querySelector('input').focus();
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    if (modal) {
        modal.querySelector('div').classList.add('scale-95');
        setTimeout(() => modal.remove(), 150);
    }
}

// Global event listeners
document.addEventListener('click', function(e) {
    if (e.target === document.getElementById('deleteModal')) {
        closeDeleteModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('deleteModal')) {
            closeDeleteModal();
        }
    }
});

// Form submission loading states
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        if (!form.action.includes('delete')) {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.classList.contains('bg-red-600')) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            });
        }
    });
});

// Initialize users manager
const usersManager = new UsersManager();
</script>

<?php renderFooter(); ?>