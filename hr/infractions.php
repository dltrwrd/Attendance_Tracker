<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isHR()) {
    redirect(BASE_URL);
}

updateLastActivity();

// Handle infraction deletion
if (isset($_GET['delete'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $infractionId = (int)$_GET['delete'];
    
    $requiredPassword = "SLT@2025";
    $providedPassword = $_POST['delete_password'] ?? '';
    
    if (empty($providedPassword) || $providedPassword !== $requiredPassword) {
        $_SESSION['error'] = "Incorrect or missing password for deletion";
        redirect('infractions.php');
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM infractions WHERE id = ?");
        $stmt->execute([$infractionId]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success'] = "Infraction deleted successfully!";
        } else {
            $_SESSION['error'] = "Infraction not found";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Cannot delete infraction: " . $e->getMessage();
    }
    
    redirect('infractions.php');
}

require_once '../components/layout.php';
renderHead('Manage Infractions');
renderNavbar();
renderSidebar('infractions');
?>

<!-- Minimal Loading Screen - Only for initial page load -->
<div id="initialLoading" class="fixed inset-0 bg-gray-900 flex items-center justify-center z-50 transition-opacity duration-300">
    <div class="text-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500 mx-auto mb-4"></div>
        <p class="text-white text-lg">Loading Infractions...</p>
    </div>
</div>

<div class="pt-2 min-h-screen">
    <main class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-white">Manage Infractions</h1>
            <a href="infraction_form.php?action=create" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors duration-200">
                <i class="fas fa-plus mr-2"></i> Add New Infraction
            </a>
        </div>

        <?php renderAlert(); ?>
        
        <!-- Search Section -->
        <div class="mb-6">
            <div class="relative flex-grow max-w-md">
                <input type="text" id="searchInput" 
                       class="w-full pl-10 pr-4 py-2 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 placeholder-gray-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors duration-200" 
                       placeholder="Search by rule section, nature of offense, or stipulation...">
                <div class="absolute left-3 top-2.5 text-gray-400">
                    <i class="fas fa-search"></i>
                </div>
            </div>
        </div>

        <!-- Infractions Table Container -->
        <div id="infractionsTableContainer" class="transition-opacity duration-200">
            <?php include 'partials/infractions_table.php'; ?>
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

// Optimized Infractions Manager - No loading on search
class InfractionsManager {
    constructor() {
        this.searchTimeout = null;
        this.debounceDelay = 400;
        this.currentPage = 1;
        this.searchTerm = '';
        this.isLoading = false;
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadInitialData();
    }

    bindEvents() {
        const searchInput = document.getElementById('searchInput');

        searchInput.addEventListener('input', () => this.handleSearchChange(searchInput.value));

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
                        window.location.href = 'infraction_form.php?action=create';
                        break;
                }
            }
        });
    }

    handleSearchChange(value) {
        this.searchTerm = value;
        
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            this.loadInfractions(1);
            this.updateUrl(1);
        }, this.debounceDelay);
    }

    async loadInfractions(page = 1) {
        if (this.isLoading) return;
        
        this.currentPage = page;
        this.isLoading = true;

        try {
            const formData = new FormData();
            formData.append('search', this.searchTerm);
            formData.append('page', page);

            const response = await fetch('partials/infractions_table.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Network response was not ok');

            const data = await response.text();
            document.getElementById('infractionsTableContainer').innerHTML = data;
            
            this.rebindEvents();

        } catch (error) {
            console.error('Error loading infractions:', error);
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
                this.loadInfractions(page);
                this.updateUrl(page);
            });
        });
    }

    updateUrl(page) {
        const params = new URLSearchParams();
        
        if (this.searchTerm) params.append('search', this.searchTerm);
        if (page > 1) params.append('page', page);
        
        const queryString = params.toString();
        const newUrl = queryString ? `?${queryString}` : window.location.pathname;
        history.replaceState(null, '', newUrl);
    }

    loadInitialData() {
        const urlParams = new URLSearchParams(window.location.search);
        
        this.searchTerm = urlParams.get('search') || '';
        const initialPage = parseInt(urlParams.get('page')) || 1;
        
        // Set initial values
        document.getElementById('searchInput').value = this.searchTerm;
        
        this.loadInfractions(initialPage);
    }

    showError(message) {
        document.getElementById('infractionsTableContainer').innerHTML = `
            <div class="bg-red-900/20 border border-red-700 rounded-lg p-6 text-center">
                <i class="fas fa-exclamation-triangle text-red-400 text-2xl mb-3"></i>
                <p class="text-red-300 mb-4">${message}</p>
                <button onclick="infractionsManager.loadInfractions(1)" 
                        class="bg-red-600 hover:bg-red-500 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    <i class="fas fa-redo mr-2"></i>Try Again
                </button>
            </div>
        `;
    }
}

// Delete Modal Functions
function showDeleteModal(infractionId) {
    const modal = document.createElement('div');
    modal.id = 'deleteModal';
    modal.className = 'fixed inset-0 bg-gray-900/50 backdrop-blur-sm flex items-center justify-center z-50 transition-opacity duration-200';
    modal.innerHTML = `
        <div class="bg-gray-800 rounded-lg border border-gray-700 shadow-xl w-full max-w-md transform transition-transform duration-200 scale-95">
            <div class="px-6 py-6">
                <h3 class="text-lg font-bold text-gray-100 mb-4">Confirm Deletion</h3>
                <p class="text-gray-300 mb-4">Are you sure you want to delete this infraction? This action cannot be undone.</p>
                <form method="post" action="${window.location.pathname}?delete=${infractionId}" class="space-y-4">
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

// Initialize infractions manager
const infractionsManager = new InfractionsManager();
</script>

<?php renderFooter(); ?>