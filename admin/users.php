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

<style>
    /* ---- Glassmorphism utility classes ---- */
    .glass-panel {
        background: rgba(255, 255, 255, 0.05);
        backdrop-filter: blur(20px) saturate(180%);
        -webkit-backdrop-filter: blur(20px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.37);
    }

    .glass-card {
        background: rgba(255, 255, 255, 0.055);
        backdrop-filter: blur(16px) saturate(160%);
        -webkit-backdrop-filter: blur(16px) saturate(160%);
        border: 1px solid rgba(255, 255, 255, 0.09);
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.28);
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .glass-card:hover {
        border-color: rgba(255, 255, 255, 0.18);
        background: rgba(255, 255, 255, 0.08);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
    }

    .glass-input {
        background: rgba(255, 255, 255, 0.05);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .glass-pill {
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.09);
        transition: all 0.2s ease;
    }

    .glass-pill:hover {
        background: rgba(255, 255, 255, 0.08);
    }

    .glass-pill.active-pill {
        background: rgba(59, 130, 246, 0.22);
        border-color: rgba(59, 130, 246, 0.45);
        color: #93c5fd;
    }

    .view-toggle-btn {
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.09);
        transition: all 0.2s ease;
    }

    .view-toggle-btn.active-view {
        background: rgba(59, 130, 246, 0.25);
        border-color: rgba(59, 130, 246, 0.5);
        color: #ffffff;
    }

    /* ---- Grid card entrance (used for genuinely new/first-seen cards) ---- */
    @keyframes gridCardFadeIn {
        0% { opacity: 0; transform: translateY(8px) scale(0.96); }
        100% { opacity: 1; transform: translateY(0) scale(1); }
    }

    .grid-card {
        will-change: transform, opacity;
    }

    .grid-card.enter-fade {
        animation: gridCardFadeIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        animation-delay: calc(var(--card-index, 0) * 25ms);
    }

    /* FLIP "swap places" transition — transform is driven from JS, this just
       defines how it eases when a card slides from its old spot to its new one */
    .grid-card.flip-move {
        transition: transform 480ms cubic-bezier(0.22, 1, 0.36, 1);
        z-index: 2;
    }

    #usersTableContainer.is-loading {
        opacity: 0.45;
        pointer-events: none;
        transition: opacity 0.15s ease;
    }
</style>

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

        <!-- Toolbar: Search, Filters, Sort, View Toggle -->
        <div class="glass-panel rounded-2xl p-4 mb-6">
            <div class="flex flex-col gap-4">

                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <!-- Search -->
                    <div class="relative flex-grow max-w-md">
                        <input type="text" id="searchInput"
                               class="w-full pl-10 pr-4 py-2 glass-input rounded-lg text-gray-200 placeholder-gray-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors duration-200"
                               placeholder="Search by name, CXI ID, or email...">
                        <div class="absolute left-3 top-2.5 text-gray-400">
                            <i class="fas fa-search"></i>
                        </div>
                    </div>

                    <!-- View Toggle -->
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="text-xs font-medium text-gray-400 uppercase tracking-wider mr-1">View</span>
                        <div class="flex rounded-lg overflow-hidden border border-white/10">
                            <button type="button" id="viewTableBtn" class="view-toggle-btn active-view px-3 py-2 text-sm text-gray-300" title="Table view">
                                <i class="fas fa-list"></i>
                            </button>
                            <button type="button" id="viewGridBtn" class="view-toggle-btn px-3 py-2 text-sm text-gray-300" title="Grid view">
                                <i class="fas fa-th-large"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pt-4 border-t border-white/10">

                    <!-- Status Filter -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-medium text-gray-400 uppercase tracking-wider mr-1">Status</span>
                        <div class="flex rounded-lg overflow-hidden border border-white/10" id="statusFilterGroup">
                            <button type="button" class="status-filter-btn glass-pill px-3 py-1.5 text-xs font-semibold text-gray-300" data-status="all">All</button>
                            <button type="button" class="status-filter-btn glass-pill active-pill px-3 py-1.5 text-xs font-semibold" data-status="active">Active</button>
                            <button type="button" class="status-filter-btn glass-pill px-3 py-1.5 text-xs font-semibold text-gray-300" data-status="inactive">Inactive</button>
                        </div>
                    </div>

                    <!-- Sort + Clear -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-medium text-gray-400 uppercase tracking-wider mr-1">Sort by</span>
                        <select id="sortField" class="glass-input rounded-lg text-gray-200 text-sm px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            <option value="">Default</option>
                            <option value="username" id="sortUsernameOption">Username</option>
                            <option value="fullname">Full Name</option>
                        </select>
                        <button type="button" id="sortDirToggle" data-dir="asc" class="glass-pill rounded-lg px-3 py-1.5 text-sm text-gray-300 opacity-40 pointer-events-none" title="Toggle sort direction">
                            <i class="fas fa-arrow-up-a-z"></i>
                        </button>

                        <button type="button" id="clearFiltersBtn" class="glass-pill rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-300 flex items-center gap-1.5">
                            <i class="fas fa-rotate-left"></i> Clear Filters
                        </button>
                    </div>

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

        // Filters / sort / view state (persist across tabs)
        this.view = 'table';
        this.status = 'active';
        this.sortField = '';
        this.sortDir = 'asc';

        // Whether the next render should play the grid "shuffle" animation
        this.pendingShuffle = false;

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

        // View toggle
        document.getElementById('viewTableBtn').addEventListener('click', () => this.setView('table'));
        document.getElementById('viewGridBtn').addEventListener('click', () => this.setView('grid'));

        // Status filter pills
        document.querySelectorAll('.status-filter-btn').forEach(btn => {
            btn.addEventListener('click', () => this.setStatus(btn.getAttribute('data-status')));
        });

        // Sort field + direction
        document.getElementById('sortField').addEventListener('change', (e) => this.setSortField(e.target.value));
        document.getElementById('sortDirToggle').addEventListener('click', () => this.toggleSortDir());

        // Clear filters
        document.getElementById('clearFiltersBtn').addEventListener('click', () => this.clearFilters());

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

    setView(view) {
        if (this.view === view) return;
        this.view = view;

        document.getElementById('viewTableBtn').classList.toggle('active-view', view === 'table');
        document.getElementById('viewGridBtn').classList.toggle('active-view', view === 'grid');

        this.loadUsers(1);
        this.updateUrl(1);
    }

    setStatus(status) {
        if (this.status === status) return;
        this.status = status;

        document.querySelectorAll('.status-filter-btn').forEach(btn => {
            btn.classList.toggle('active-pill', btn.getAttribute('data-status') === status);
        });

        this.pendingShuffle = true;
        this.loadUsers(1);
        this.updateUrl(1);
    }

    setSortField(field) {
        this.sortField = field;
        const dirToggle = document.getElementById('sortDirToggle');
        if (field) {
            dirToggle.classList.remove('opacity-40', 'pointer-events-none');
        } else {
            dirToggle.classList.add('opacity-40', 'pointer-events-none');
        }

        this.pendingShuffle = true;
        this.loadUsers(1);
        this.updateUrl(1);
    }

    toggleSortDir() {
        if (!this.sortField) return;
        this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';

        const dirToggle = document.getElementById('sortDirToggle');
        dirToggle.setAttribute('data-dir', this.sortDir);
        const icon = dirToggle.querySelector('i');
        icon.className = this.sortDir === 'asc' ? 'fas fa-arrow-up-a-z' : 'fas fa-arrow-down-a-z';

        this.pendingShuffle = true;
        this.loadUsers(1);
        this.updateUrl(1);
    }

    clearFilters() {
        this.searchTerm = '';
        this.status = 'active';
        this.sortField = '';
        this.sortDir = 'asc';

        document.getElementById('searchInput').value = '';

        document.querySelectorAll('.status-filter-btn').forEach(btn => {
            btn.classList.toggle('active-pill', btn.getAttribute('data-status') === 'active');
        });

        document.getElementById('sortField').value = '';
        const dirToggle = document.getElementById('sortDirToggle');
        dirToggle.setAttribute('data-dir', 'asc');
        dirToggle.classList.add('opacity-40', 'pointer-events-none');
        dirToggle.querySelector('i').className = 'fas fa-arrow-up-a-z';

        this.pendingShuffle = true;
        this.loadUsers(1);
        this.updateUrl(1);
    }

    switchTab(tab) {
        this.currentTab = tab;
        this.currentPage = 1;
        this.searchTerm = '';
        document.getElementById('searchInput').value = '';

        // Update sort field label to match the tab's identifier column
        const sortUsernameOption = document.getElementById('sortUsernameOption');
        if (sortUsernameOption) {
            sortUsernameOption.textContent = tab === 'users' ? 'Username' : 'CXI ID';
        }

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

        // Note: view / status / sort filters are intentionally NOT reset here,
        // so they stay applied across all tabs.
        this.loadUsers(1);
        this.updateUrl(1);
        history.pushState(null, '', this.buildUrl(1));

        // Update the Add New button URL
        this.updateAddNewButton();
    }

    async loadUsers(page = 1) {
        if (this.isLoading) return; // Prevent multiple simultaneous requests

        this.currentPage = page;
        this.isLoading = true;

        const container = document.getElementById('usersTableContainer');
        container.classList.add('is-loading');

        // Capture current grid card positions BEFORE the DOM is replaced, so
        // we can FLIP-animate them sliding to their new spot. Only relevant
        // when we're already in grid view and this reload was triggered by a
        // filter/sort change (not pagination, tab switches, plain search, etc).
        let previousRects = null;
        if (this.view === 'grid' && this.pendingShuffle) {
            previousRects = new Map();
            document.querySelectorAll('#gridContainer .grid-card').forEach(card => {
                previousRects.set(card.dataset.cardId, card.getBoundingClientRect());
            });
        }

        try {
            const formData = new FormData();
            formData.append('search', this.searchTerm);
            formData.append('page', page);
            formData.append('type', this.currentTab);
            formData.append('view', this.view);
            formData.append('status', this.status);
            formData.append('sort_field', this.sortField);
            formData.append('sort_dir', this.sortDir);

            const response = await fetch('partials/users_table.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Network response was not ok');

            const data = await response.text();
            container.innerHTML = data;

            this.rebindEvents();
            this.updateOnlineStatuses();

            if (this.view === 'grid') {
                if (previousRects && previousRects.size > 0) {
                    this.flipGridCards(previousRects);
                } else {
                    this.playGridEntrance();
                }
            }
            this.pendingShuffle = false;

        } catch (error) {
            console.error('Error loading users:', error);
            this.showError('Error loading data. Please try again.');
        } finally {
            this.isLoading = false;
            container.classList.remove('is-loading');
        }
    }

    // Simple staggered fade/slide-in for a fresh set of cards (initial load,
    // tab switch, pagination, search, or the first time grid view is opened).
    playGridEntrance() {
        const cards = document.querySelectorAll('#gridContainer .grid-card');
        cards.forEach((card, i) => {
            card.style.setProperty('--card-index', i);
            card.classList.add('enter-fade');
        });
    }

    // FLIP animation: cards that existed before AND still exist now slide
    // from their old position to their new one (a real "swap places" effect
    // when sort order or filters change positions). Cards that are newly
    // appearing get the regular fade-in instead.
    flipGridCards(previousRects) {
        const cards = document.querySelectorAll('#gridContainer .grid-card');
        let newCardIndex = 0;

        cards.forEach((card) => {
            const id = card.dataset.cardId;
            const oldRect = previousRects.get(id);

            if (!oldRect) {
                // Wasn't visible before under the old filter/sort — fade it in.
                card.style.setProperty('--card-index', newCardIndex++);
                card.classList.add('enter-fade');
                return;
            }

            const newRect = card.getBoundingClientRect();
            const dx = oldRect.left - newRect.left;
            const dy = oldRect.top - newRect.top;

            if (!dx && !dy) return; // didn't move, nothing to animate

            // Jump the card back to where it used to be (no transition), then
            // let it transition to its natural position on the next frame —
            // this is what produces the "sliding into its new slot" effect.
            card.style.transition = 'none';
            card.style.transform = `translate(${dx}px, ${dy}px)`;

            requestAnimationFrame(() => {
                card.classList.add('flip-move');
                card.style.transform = '';
            });

            card.addEventListener('transitionend', () => {
                card.classList.remove('flip-move');
                card.style.transition = '';
            }, { once: true });
        });
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

    buildUrl(page) {
        const params = new URLSearchParams();
        params.append('tab', this.currentTab);

        if (this.searchTerm) params.append('search', this.searchTerm);
        if (this.view !== 'table') params.append('view', this.view);
        if (this.status !== 'active') params.append('status', this.status);
        if (this.sortField) params.append('sort_field', this.sortField);
        if (this.sortField && this.sortDir !== 'asc') params.append('sort_dir', this.sortDir);
        if (page > 1) params.append('page', page);

        const queryString = params.toString();
        return queryString ? `?${queryString}` : window.location.pathname;
    }

    updateUrl(page) {
        history.replaceState(null, '', this.buildUrl(page));
    }

    loadInitialData() {
        const urlParams = new URLSearchParams(window.location.search);

        this.currentTab = urlParams.get('tab') || 'users';
        this.searchTerm = urlParams.get('search') || '';
        this.view = urlParams.get('view') === 'grid' ? 'grid' : 'table';
        const statusParam = urlParams.get('status');
        this.status = ['all', 'active', 'inactive'].includes(statusParam) ? statusParam : 'active';
        const sortFieldParam = urlParams.get('sort_field');
        this.sortField = ['username', 'fullname'].includes(sortFieldParam) ? sortFieldParam : '';
        this.sortDir = urlParams.get('sort_dir') === 'desc' ? 'desc' : 'asc';
        const initialPage = parseInt(urlParams.get('page')) || 1;

        // Set initial values
        document.getElementById('searchInput').value = this.searchTerm;

        document.getElementById('viewTableBtn').classList.toggle('active-view', this.view === 'table');
        document.getElementById('viewGridBtn').classList.toggle('active-view', this.view === 'grid');

        document.querySelectorAll('.status-filter-btn').forEach(btn => {
            btn.classList.toggle('active-pill', btn.getAttribute('data-status') === this.status);
        });

        document.getElementById('sortField').value = this.sortField;
        const dirToggle = document.getElementById('sortDirToggle');
        dirToggle.setAttribute('data-dir', this.sortDir);
        dirToggle.querySelector('i').className = this.sortDir === 'asc' ? 'fas fa-arrow-up-a-z' : 'fas fa-arrow-down-a-z';
        if (this.sortField) {
            dirToggle.classList.remove('opacity-40', 'pointer-events-none');
        } else {
            dirToggle.classList.add('opacity-40', 'pointer-events-none');
        }

        const sortUsernameOption = document.getElementById('sortUsernameOption');
        if (sortUsernameOption) {
            sortUsernameOption.textContent = this.currentTab === 'users' ? 'Username' : 'CXI ID';
        }

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