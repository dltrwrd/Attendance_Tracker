<?php
function renderHead($title) {
    ?>
    <!DOCTYPE html>
    <html lang="en" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> | CXI Admin</title>
        <link rel="icon" href="<?= BASE_URL ?>assets/cxiico.png" type="image/png">
        <meta name="theme-color" content="#0ea5e9">
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        colors: {
                            primary: {
                                50: '#f0f9ff',
                                100: '#e0f2fe',
                                200: '#bae6fd',
                                300: '#7dd3fc',
                                400: '#38bdf8',
                                500: '#0ea5e9',
                                600: '#0284c7',
                                700: '#0369a1',
                                800: '#075985',
                                900: '#0c4a6e',
                            }
                        }
                    }
                }
            }
            
            // Function to update the notification badge from all pages
            async function updateNotificationBadge() {
                try {
                    const response = await fetch('ticket_dashboard.php?action=get_pending_count');
                    const data = await response.json();
                    const mainBadge = document.getElementById('pending-tickets-badge');
                    const submenuBadge = document.getElementById('pending-tickets-submenu-badge');
                    
                    if (data.pending_count > 0) {
                        // Update main badge
                        if (!mainBadge) {
                            // Create badge if it doesn't exist
                            const iconContainer = document.querySelector('a[href="ticket_dashboard.php"] .relative');
                            if (iconContainer) {
                                const newBadge = document.createElement('span');
                                newBadge.id = 'pending-tickets-badge';
                                newBadge.className = 'absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs notification-dot';
                                newBadge.textContent = data.pending_count > 9 ? '9+' : data.pending_count;
                                iconContainer.appendChild(newBadge);
                            }
                        } else {
                            // Update existing badge
                            mainBadge.textContent = data.pending_count > 9 ? '9+' : data.pending_count;
                            mainBadge.style.display = 'flex';
                            mainBadge.classList.add('animate-pulse');
                        }
                        
                        // Update submenu badge (red dot only)
                        if (!submenuBadge) {
                            // Create badge if it doesn't exist
                            const submenuIconContainer = document.querySelector('a[href="ticket_dashboard.php"] .sidebar-icon-container.relative');
                            if (submenuIconContainer) {
                                const newSubmenuBadge = document.createElement('span');
                                newSubmenuBadge.id = 'pending-tickets-submenu-badge';
                                newSubmenuBadge.className = 'absolute -top-1 -right-1 bg-red-500 rounded-full w-2 h-2 flex items-center justify-center text-xs notification-dot';
                                newSubmenuBadge.style.display = 'flex';
                                newSubmenuBadge.classList.add('animate-pulse');
                                submenuIconContainer.appendChild(newSubmenuBadge);
                            }
                        } else {
                            // Update existing submenu badge
                            submenuBadge.style.display = 'flex';
                            submenuBadge.classList.add('animate-pulse');
                        }
                    } else {
                        // Hide badges if no pending tickets
                        if (mainBadge) {
                            mainBadge.style.display = 'none';
                            mainBadge.classList.remove('animate-pulse');
                        }
                        if (submenuBadge) {
                            submenuBadge.style.display = 'none';
                            submenuBadge.classList.remove('animate-pulse');
                        }
                    }
                } catch (error) {
                    console.error('Error updating notification badge:', error);
                }
            }

            // Update badge on all pages every 30 seconds
            if (window.location.pathname !== '/ticket_dashboard.php') {
                setInterval(updateNotificationBadge, 30000);
                updateNotificationBadge(); // Initial update
            }
        </script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            .sidebar-item.active {
                background-color: rgba(14, 165, 233, 0.2);
                border-left: 3px solid #0ea5e9;
            }
            .sidebar-item.active .sidebar-icon {
                color: #0ea5e9;
            }
            .sidebar-item:hover:not(.active) {
                background-color: rgba(255, 255, 255, 0.05);
            }
            
            /* Sidebar styles */
            #sidebar {
                width: 5rem;
                transition: all 0.3s ease;
                overflow: hidden;
            }
            
            #sidebar:hover {
                width: 16rem;
            }
            
            #sidebar:hover .sidebar-text {
                opacity: 1;
                transition: opacity 0.3s ease 0.2s;
            }
            
            .sidebar-text {
                opacity: 0;
                transition: opacity 0.1s ease;
                white-space: nowrap;
            }
            
            /* Adjust main content margin */
            .main-content {
                margin-left: 5rem;
                transition: margin-left 0.3s ease;
            }
            
            #sidebar:hover ~ .main-content {
                margin-left: 16rem;
            }
            
            /* Sidebar item styles */
            .sidebar-item.active {
                background-color: rgba(14, 165, 233, 0.2);
                border-left: 3px solid #0ea5e9;
            }
            
            .sidebar-item.active .sidebar-icon {
                color: #0ea5e9;
            }
            
            .sidebar-item:hover:not(.active) {
                background-color: rgba(255, 255, 255, 0.05);
            }
            
            /* Icon centering solution */
            .sidebar-icon-container {
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: start;
                margin-right: 12px;
                flex-shrink: 0;
            }
            
            /* Center the sidebar content */
            .sidebar-content {
                display: flex;
                flex-direction: column;
                align-items: center;
                width: 100%;
            }
            
            /* Adjust main content margin */
            .main-content {
                margin-left: 5rem;
                transition: margin-left 0.3s ease;
            }
            
            #sidebar:hover ~ .main-content {
                margin-left: 16rem;
            }
            /* Custom CSS to hide the scrollbar for the modal */
            .hide-scrollbar::-webkit-scrollbar {
                display: none; /* Para sa Chrome, Safari, at Opera */
            }

            .hide-scrollbar {
                -ms-overflow-style: none; /* Para sa IE at Edge */
                scrollbar-width: none; /* Para sa Firefox */
            }
            
            /* Responsive adjustments */
            @media (max-width: 768px) {
                #sidebar {
                    width: 16rem;
                    transform: translateX(-100%);
                }
                
                #sidebar:hover {
                    width: 16rem;
                }
                
                .main-content {
                    margin-left: 0;
                }
                
                #sidebarToggle:hover + #sidebar,
                #sidebar:hover {
                    transform: translateX(0);
                }
                
                .sidebar-text {
                    opacity: 1;
                }
            }
        </style>
    </head>
    <body class="bg-gray-900 text-gray-100 min-h-screen flex flex-col">
    <?php
}

function renderNavbar() {
    ?>
    <nav class="bg-gray-800 border-b border-gray-700">
        <div class="px-4 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-4" style="opacity:0;">            
                <button id="sidebarToggle" class="text-gray-400 hover:text-white focus:outline-none md:hidden">
                    <i class="fas fa-bars fa-lg"></i>
                </button>
                <div class="flex items-center">
                    <img src="../assets/cxi.png" alt="CXI Logo" class="w-10 h-10 mr-2">
                    <span class="text-xl font-semibold">CXI Admin</span>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="userMenuButton" class="flex items-center space-x-2 focus:outline-none">
                        <div class="w-8 h-8 rounded-full bg-primary-600 flex items-center justify-center">
                            <i class="fas fa-user text-white"></i>
                        </div>
                        <span class="hidden md:inline"><?= htmlspecialchars($_SESSION['nickname']); ?></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-gray-400 online-status" data-user-id="<?= $_SESSION['user_id'] ?>"></span>
                    </button>
                    <div id="userMenu" class="hidden absolute right-0 mt-2 w-48 bg-gray-800 rounded-md shadow-lg py-1 z-50 border border-gray-700">
                        <a href="../logout.php" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">Sign out</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <?php
}

function renderSidebar($activePage = 'dashboard', $pendingCount = 0) {
    ?>
    <aside id="sidebar" class="bg-gray-800 fixed h-full border-r border-gray-700 z-40">
        <div class="p-4">
            <div class="flex items-center space-x-4">
                <div class="logo-container">
                    <img src="../assets/cxi.png" alt="CXI Logo" class="logo">
                </div>
            </div>
            <div class="border-t border-gray-700 my-4"></div>
            
            <!-- Navigation Menu as Unordered List -->
            <nav class="mt-6">
                <ul class="space-y-2">
                    <?= generateSidebarMenu($activePage, $pendingCount) ?>
                </ul>
            </nav>
        </div>
    </aside>

    <div class="main-content flex-1 flex flex-col">
    <?php
}

function generateSidebarMenu($activePage, $pendingCount) {
    $userRole = getUserRole(); // Dapat meron kang function na ito para makuha ang user role
    $menuItems = '';

    switch($userRole) {
        case 'admin':
            $menuItems = generateAdminMenu($activePage, $pendingCount);
            break;
            
        case 'hr':
            $menuItems = generateHRMenu($activePage);
            break;
            
        default:
            $menuItems = generateDefaultMenu($activePage);
            break;
    }

    return $menuItems;
}

function generateAdminMenu($activePage, $pendingCount) {
    ob_start();
    ?>
    <!-- Dashboard -->
    <li>
        <a href="dashboard.php" class="sidebar-item flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'dashboard' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-tachometer-alt"></i>
            </div>
            <span class="sidebar-text">Dashboard</span>
        </a>
    </li>
    
    <!-- SLT Tracker with submenu -->
    <li class="relative group">
        <a href="attendance.php" class="sidebar-item flex items-center justify-between px-4 py-3 text-gray-300 hover:text-white <?= in_array($activePage, ['attendance', 'attendance_statistics']) ? 'active' : '' ?>">
            <div class="flex items-center">
                <div class="sidebar-icon-container">
                    <i class="sidebar-icon fas fa-chart-line"></i>
                </div>
                <span class="sidebar-text">SLT Tracker</span>
            </div>
            <i class="fas fa-chevron-down text-xs transition-transform duration-200 group-hover:rotate-180"></i>
        </a>
        <!-- Dropdown menu for SLT Tracker -->
        <ul class="ml-4 mt-1 space-y-1 overflow-hidden max-h-0 group-hover:max-h-32 transition-all duration-300 ease-in-out border-l border-gray-700">
            <li>
                <a href="attendance.php" class="sidebar-item flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'attendance' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-list text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Tracker</span>
                </a>
            </li>
            <li>
                <a href="attendance_statistics.php" class="sidebar-item flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'attendance_statistics' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-chart-pie text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Attendance Statistics</span>
                </a>
            </li>
            <li>
                <a href="incident_report.php" class="sidebar-item flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'incident_report' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-exclamation-triangle text-xs"></i>
                    </div>
                    <span class="sidebar-text">Incident Report</span>
                </a>
            </li>
        </ul>
    </li>
    
    <!-- SLT Ticketing with submenu -->
    <li class="relative group">
        <a href="ticket_dashboard.php" class="sidebar-item flex items-center justify-between px-4 py-3 text-gray-300 hover:text-white <?= in_array($activePage, ['ticket_dashboard', 'statistics']) ? 'active' : '' ?>">
            <div class="flex items-center">
                <div class="sidebar-icon-container relative">
                    <i class="sidebar-icon fas fa-clipboard-list"></i>
                    <?php if ($pendingCount > 0): ?>
                        <span class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs notification-dot" id="pending-tickets-badge">
                            <?= $pendingCount > 9 ? '9+' : $pendingCount ?>
                        </span>
                    <?php else: ?>
                        <span class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs notification-dot" id="pending-tickets-badge" style="display: none;"></span>
                    <?php endif; ?>
                </div>
                <span class="sidebar-text">SLT Ticketing</span>
            </div>
            <i class="fas fa-chevron-down text-xs transition-transform duration-200 group-hover:rotate-180"></i>
        </a>
        <!-- Dropdown menu for SLT Ticketing -->
        <ul class="ml-4 mt-1 space-y-1 overflow-hidden max-h-0 group-hover:max-h-32 transition-all duration-300 ease-in-out border-l border-gray-700">
            <li>
                <a href="ticket_dashboard.php" class="sidebar-item flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'ticket_dashboard' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container relative">
                        <i class="sidebar-icon fas fa-ticket-alt text-xs"></i>
                        <?php if ($pendingCount > 0): ?>
                            <span class="absolute -top-1 -right-1 bg-red-500 rounded-full w-2 h-2 flex items-center justify-center text-xs notification-dot" id="pending-tickets-submenu-badge"></span>
                        <?php else: ?>
                            <span class="absolute -top-1 -right-1 bg-red-500 rounded-full w-2 h-2 flex items-center justify-center text-xs notification-dot" id="pending-tickets-submenu-badge" style="display: none;"></span>
                        <?php endif; ?>
                    </div>
                    <span class="sidebar-text text-sm">Ticketing</span>
                </a>
            </li>
            <li>
                <a href="statistics.php" class="sidebar-item flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'statistics' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-chart-bar text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Ticket Statistics</span>
                </a>
            </li>
        </ul>
    </li>
    
    <!-- Management Settings with submenu -->
    <li class="relative group">
        <a href="users.php" class="sidebar-item flex items-center justify-between px-4 py-3 text-gray-300 hover:text-white <?= in_array($activePage, ['users', 'inventory', 'employees', 'team_members']) ? 'active' : '' ?>">
            <div class="flex items-center">
                <div class="sidebar-icon-container">
                    <i class="sidebar-icon fas fa-cog"></i>
                </div>
                <span class="sidebar-text">Settings</span>
            </div>
            <i class="fas fa-chevron-down text-xs transition-transform duration-200 group-hover:rotate-180"></i>
        </a>
        <!-- Dropdown menu for Management Settings -->
        <ul class="ml-4 mt-1 space-y-1 overflow-hidden max-h-0 group-hover:max-h-48 transition-all duration-300 ease-in-out border-l border-gray-700">
            <li>
                <a href="inventory_tracker.php" class="sidebar-item flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'inventory' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-boxes text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">SLT Inventory</span>
                </a>
            </li>
            <li>
                <a href="employees.php" class="sidebar-item flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'employees' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-users text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Manage Agents</span>
                </a>
            </li>
            <li>
                <a href="users.php" class="sidebar-item flex items-center px-4 py-2 text-white-300 hover:text-white <?= $activePage === 'users' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-user-friends text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Team Members</span>
                </a>
            </li>
        </ul>
    </li>
    
    <!-- Coming Soon Section -->
    <li>
        <br>
        <span class="sidebar-text">Coming soon!</span>
        <div class="relative group">
            <div class="sidebar-item flex items-center px-4 py-3 text-gray-500 cursor-not-allowed opacity-50">
                <div class="sidebar-icon-container">
                    <i class="sidebar-icon fas fa-clock"></i>
                </div>
                <span class="sidebar-text">Time keeping</span>
            </div>
        </div>
    </li>
    <?php
    return ob_get_clean();
}

function generateHRMenu($activePage) {
    ob_start();
    ?>
    <li>
        <a href="dashboard.php" class="sidebar-item flex items-center px-4 py-3 text-white <?= $activePage === 'dashboard' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-tachometer-alt"></i>
            </div>
            <span class="sidebar-text">Dashboard</span>
        </a>
    </li>
    <li>
        <a href="incident_report.php" class="sidebar-item flex items-center px-4 py-2 text-white <?= $activePage === 'incident_report' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-exclamation-triangle"></i>
            </div>
            <span class="sidebar-text">Incident Report</span>
        </a>
    </li>
    <li>
        <a href="infractions.php" class="sidebar-item flex items-center px-4 py-2 text-white <?= $activePage === 'infractions' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-file-contract"></i>
            </div>
            <span class="sidebar-text">Infractions</span>
        </a>
    </li>
    <li>
        <a href="users.php" class="sidebar-item flex items-center px-4 py-2 text-white <?= $activePage === 'users' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-user-friends"></i>
            </div>
            <span class="sidebar-text">Team Members</span>
        </a>
    </li>
    <?php
    return ob_get_clean();
}

function generateDefaultMenu($activePage) {
    ob_start();
    ?>
    <!-- Default menu for unrecognized roles -->
    <li>
        <a href="dashboard.php" class="sidebar-item flex items-center px-4 py-3 text-white <?= $activePage === 'dashboard' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-tachometer-alt"></i>
            </div>
            <span class="sidebar-text">Dashboard</span>
        </a>
    </li>
    <?php
    return ob_get_clean();
}

function getUserRole() {
    if (isAdmin()) {
        return 'admin';
    } elseif (isHR()) {
        return 'hr';
    } else {
        return 'default';
    }
}

function renderFooter() {
    ?>
    </div> <!-- Close main-content div -->
    <footer class="bg-gray-800 border-t border-gray-700 py-4 mt-auto">
        <div class="container mx-auto px-4 text-center text-gray-400 text-sm">
            &copy; <?= date('Y') ?> CXI Services Inc. All rights reserved.
        </div>
    </footer>
    <script src="../assets/js/script.js"></script>
    <script>
        // Toggle sidebar on mobile
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        });

        // Toggle user menu
        document.getElementById('userMenuButton').addEventListener('click', function() {
            document.getElementById('userMenu').classList.toggle('hidden');
        });

        // Close menus when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('#userMenuButton')) {
                document.getElementById('userMenu').classList.add('hidden');
            }
        });

        // Online status functionality
        function checkOnlineStatus() {
            fetch('../api/online_status.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.success) return;
                    
                    // Get all user indicators
                    const allUserIds = Array.from(document.querySelectorAll('.online-status'))
                        .map(el => parseInt(el.getAttribute('data-user-id')));
                    
                    // Update all indicators
                    document.querySelectorAll('.online-status').forEach(indicator => {
                        const userId = parseInt(indicator.getAttribute('data-user-id'));
                        const pingElement = indicator.previousElementSibling;
                        
                        // If user is in onlineUsers array, show as online
                        if (data.onlineUsers.includes(userId)) {
                            indicator.classList.remove('bg-gray-400');
                            indicator.classList.add('bg-green-400');
                            indicator.setAttribute('title', 'Online'); // Add title for online
                        } else {
                            // User is offline
                            indicator.classList.remove('bg-green-400');
                            indicator.classList.add('bg-gray-400');
                            indicator.setAttribute('title', 'Offline'); // Add title for idle
                        }
                    });
                })
                .catch(error => console.error('Error checking online status:', error));
        }

        // Check immediately and then every 15 seconds
        checkOnlineStatus();
        const statusCheckInterval = setInterval(checkOnlineStatus, 1800000);
    </script>
    </body>
    </html>
    <?php
}

function renderAlert() {
    if (isset($_SESSION['error'])) {
        echo '<div class="bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 rounded-lg mb-6 text-sm">'.$_SESSION['error'].'</div>';
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['success'])) {
        echo '<div class="bg-green-500/10 border border-green-500/30 text-green-300 px-4 py-3 rounded-lg mb-6 text-sm">'.$_SESSION['success'].'</div>';
        unset($_SESSION['success']);
    }
}
?>