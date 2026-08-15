<?php
function renderHead($title) {
    ?>
    <!DOCTYPE html>
    <html lang="en" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> | CXI Admin</title>
        <link rel="icon" href="<?= BASE_URL ?>assets/cxiico.png" type="image/png" id="original-favicon">
        <link rel="icon" href="<?= BASE_URL ?>assets/cxiico.png" type="image/png" id="dynamic-favicon">
        <meta name="theme-color" content="#0ea5e9">
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://unpkg.com/peerjs@1.5.2/dist/peerjs.min.js"></script>
        
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
            
            async function updateNotificationBadge() {
                try {
                    const response = await fetch('ticket_dashboard.php?action=get_pending_count');
                    const data = await response.json();
                    const mainBadge = document.getElementById('pending-tickets-badge');
                    const submenuBadge = document.getElementById('pending-tickets-submenu-badge');
                    
                    if (data.pending_count > 0) {
                        if (!mainBadge) {
                            const iconContainer = document.querySelector('a[href="ticket_dashboard.php"] .relative');
                            if (iconContainer) {
                                const newBadge = document.createElement('span');
                                newBadge.id = 'pending-tickets-badge';
                                newBadge.className = 'absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs notification-dot shadow-lg shadow-red-500/50';
                                newBadge.textContent = data.pending_count > 9 ? '9+' : data.pending_count;
                                iconContainer.appendChild(newBadge);
                            }
                        } else {
                            mainBadge.textContent = data.pending_count > 9 ? '9+' : data.pending_count;
                            mainBadge.style.display = 'flex';
                            mainBadge.classList.add('animate-pulse');
                        }
                        
                        if (!submenuBadge) {
                            const submenuIconContainer = document.querySelector('a[href="ticket_dashboard.php"] .sidebar-icon-container.relative');
                            if (submenuIconContainer) {
                                const newSubmenuBadge = document.createElement('span');
                                newSubmenuBadge.id = 'pending-tickets-submenu-badge';
                                newSubmenuBadge.className = 'absolute -top-1 -right-1 bg-red-500 rounded-full w-2 h-2 flex items-center justify-center text-xs notification-dot shadow-lg shadow-red-500/50';
                                newSubmenuBadge.style.display = 'flex';
                                newSubmenuBadge.classList.add('animate-pulse');
                                submenuIconContainer.appendChild(newSubmenuBadge);
                            }
                        } else {
                            submenuBadge.style.display = 'flex';
                            submenuBadge.classList.add('animate-pulse');
                        }
                    } else {
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

            async function updateCCTVNotificationBadge() {
                try {
                    const response = await fetch('cctv_request.php?action=get_pending_count');
                    const data = await response.json();
                    const mainBadge = document.getElementById('pending-cctv-badge');
                    
                    if (data.pending_count > 0) {
                        if (!mainBadge) {
                            const iconContainer = document.querySelector('a[href="cctv_request.php"] .relative');
                            if (iconContainer) {
                                const newBadge = document.createElement('span');
                                newBadge.id = 'pending-cctv-badge';
                                newBadge.className = 'absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs notification-dot shadow-lg shadow-red-500/50';
                                newBadge.textContent = data.pending_count > 9 ? '9+' : data.pending_count;
                                newBadge.style.display = 'flex';
                                newBadge.classList.add('animate-pulse');
                                iconContainer.appendChild(newBadge);
                            }
                        } else {
                            mainBadge.textContent = data.pending_count > 9 ? '9+' : data.pending_count;
                            mainBadge.style.display = 'flex';
                            mainBadge.classList.add('animate-pulse');
                        }
                    } else {
                        if (mainBadge) {
                            mainBadge.style.display = 'none';
                            mainBadge.classList.remove('animate-pulse');
                        }
                    }
                } catch (error) {
                    console.error('Error updating CCTV notification badge:', error);
                }
            }

            function updateFaviconWithNotification(count) {
                return new Promise((resolve) => {
                    if (count === 0) {
                        document.getElementById('dynamic-favicon').href = document.getElementById('original-favicon').href;
                        updatePageTitle(count);
                        resolve();
                        return;
                    }
                    
                    const canvas = document.createElement('canvas');
                    canvas.width = 32;
                    canvas.height = 32;
                    const ctx = canvas.getContext('2d');
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    
                    img.onload = function() {
                        ctx.drawImage(img, 0, 0, 32, 32);
                        if (count > 0) {
                            ctx.beginPath();
                            ctx.arc(26, 6, 6, 0, Math.PI * 2);
                            ctx.fillStyle = '#ef4444';
                            ctx.fill();
                            ctx.fillStyle = '#ffffff';
                            ctx.font = 'bold 8px Arial';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';
                            const displayCount = count > 9 ? '9+' : count.toString();
                            ctx.fillText(displayCount, 26, 6);
                        }
                        document.getElementById('dynamic-favicon').href = canvas.toDataURL('image/png');
                        updatePageTitle(count);
                        resolve();
                    };
                    img.src = document.getElementById('original-favicon').href;
                });
            }

            function updatePageTitle(count) {
                const originalTitle = document.title.replace(/^\(\d+\)\s*/, '');
                const baseTitle = originalTitle.includes('| CXI Admin') ? originalTitle : originalTitle.replace(' | CXI Admin', '') + ' | CXI Admin';
                const newTitle = count > 0 ? `(${count > 9 ? '9+' : count}) ${baseTitle}` : baseTitle;
                document.title = newTitle;
            }

            async function updatePageTitleWithNotifications() {
                try {
                    const [ticketResponse, cctvResponse] = await Promise.all([
                        fetch('ticket_dashboard.php?action=get_pending_count'),
                        fetch('cctv_request.php?action=get_pending_count')
                    ]);
                    
                    const ticketData = await ticketResponse.json();
                    const cctvData = await cctvResponse.json();
                    
                    const totalPending = (ticketData.pending_count || 0) + (cctvData.pending_count || 0);
                    
                    await updateFaviconWithNotification(totalPending);
                } catch (error) {
                    console.error('Error updating page title:', error);
                }
            }

            function updateAllBadges() {
                updateNotificationBadge();
                updateCCTVNotificationBadge();
                updatePageTitleWithNotifications();
            }

            document.addEventListener('DOMContentLoaded', function() {
                updateAllBadges();
                setInterval(updateAllBadges, 30000);
            });
        </script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        
        <style>
            #sidebar {
                width: 5rem;
                transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                background: linear-gradient(to bottom, #111827, #0f172a);
                border-right: 1px solid rgba(255, 255, 255, 0.05);
                overflow: hidden;
            }
            
            #sidebar:hover {
                width: 16rem;
                box-shadow: 4px 0 24px rgba(0, 0, 0, 0.3);
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
            
            .sidebar-item {
                transition: all 0.2s ease;
                border-left: 3px solid transparent;
            }
            
            .sidebar-item.active {
                background: linear-gradient(90deg, rgba(14, 165, 233, 0.15) 0%, transparent 100%);
                border-left: 3px solid #0ea5e9;
                color: #ffffff;
            }
            
            .sidebar-item.active .sidebar-icon {
                color: #38bdf8;
                filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.4));
            }
            
            .sidebar-item:hover:not(.active) {
                background-color: rgba(255, 255, 255, 0.03);
                transform: translateX(4px);
            }
            
            .main-content {
                margin-left: 5rem;
                transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            #sidebar:hover ~ .main-content {
                margin-left: 16rem;
            }
            
            .sidebar-icon-container {
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: start;
                margin-right: 12px;
                flex-shrink: 0;
            }
            
            .sidebar-content {
                display: flex;
                flex-direction: column;
                align-items: center;
                width: 100%;
            }
            
            .hide-scrollbar::-webkit-scrollbar {
                display: none;
            }

            .hide-scrollbar {
                -ms-overflow-style: none;
                scrollbar-width: none;
            }
            
            @media (max-width: 768px) {
                #sidebar {
                    width: 16rem;
                    transform: translateX(-100%);
                }
                
                #sidebar.open {
                    transform: translateX(0) !important;
                }
                
                .main-content {
                    margin-left: 0 !important;
                }
                
                .sidebar-text {
                    opacity: 1;
                }
            }
            
            #original-favicon {
                display: none;
            }

            @keyframes borderPulse {
                0% { border-color: rgba(34, 197, 94, 0.5); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
                70% { border-color: rgba(34, 197, 94, 1); box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
                100% { border-color: rgba(34, 197, 94, 0.5); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
            }
            .speaking-pulse {
                animation: borderPulse 2s infinite;
            }
        </style>
    </head>
    <body class="bg-gray-900 text-gray-100 min-h-screen flex flex-col">
    <?php
}

function renderNavbar() {
    global $pdo;
    
    if (!isset($_SESSION['display_photo']) && isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT display_photo FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $_SESSION['display_photo'] = $userRow['display_photo'] ?? '';
        } catch (Exception $e) {
            error_log("Error fetching user photo: " . $e->getMessage());
        }
    }

    $displayPhoto = isset($_SESSION['display_photo']) && !empty($_SESSION['display_photo']) 
        ? '../components/profile/' . $_SESSION['display_photo'] 
        : '../components/profile/default.jpg';
    ?>
    <nav class="bg-gray-900/80 backdrop-blur-md border-b border-white/10 sticky top-0 z-30 transition-all shadow-sm">
        <div class="px-4 py-3 flex justify-between items-center">
            
            <div class="flex items-center space-x-4 md:opacity-0 md:w-20 pl-2 md:pl-0">            
                <button id="sidebarToggle" class="text-gray-400 hover:text-white focus:outline-none md:hidden p-2">
                    <i class="fas fa-bars fa-lg"></i>
                </button>
            </div>

            <!-- Right side: Voice UI, Notification Bell, Online Users, Profile -->
            <div class="flex items-center space-x-3 md:space-x-4">
                
                <div class="flex items-center bg-gray-800/50 hover:bg-gray-800/80 rounded-full border border-white/5 p-1 shadow-inner h-10 transition-all duration-300">
                    
                    <div class="flex items-center px-2 min-w-[40px] h-full justify-center">
                        <div id="voice-participants" class="flex items-center -space-x-2 hover:space-x-0 transition-all duration-300">
                        </div>
                    </div>
                    
                    <div id="voice-controls" class="hidden items-center space-x-1 px-2 border-l border-white/10 ml-1 h-full">
                        <button id="btn-mute" onclick="toggleMute()" class="text-gray-400 hover:text-white focus:outline-none w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition" title="Mute Microphone">
                            <i class="fas fa-microphone"></i>
                        </button>
                        <button id="btn-deafen" onclick="toggleDeafen()" class="text-gray-400 hover:text-white focus:outline-none w-8 h-8 flex items-center justify-center rounded-full hover:bg-white/10 transition" title="Deafen">
                            <i class="fas fa-headphones"></i>
                        </button>
                        <div class="group relative flex items-center justify-center w-8 h-8 text-gray-400 hover:text-white cursor-pointer rounded-full hover:bg-white/10 transition">
                            <i class="fas fa-volume-up"></i>
                            <div class="absolute top-full right-0 pt-2 hidden group-hover:block z-50">
                                <div class="bg-gray-800/95 backdrop-blur-md border border-white/10 rounded-xl p-3 shadow-2xl flex items-center space-x-3 w-40">
                                    <i class="fas fa-volume-down text-xs text-gray-400"></i>
                                    <input type="range" id="volume-slider" min="0" max="1" step="0.01" value="1" oninput="changeVolume(this.value)" class="flex-1 accent-emerald-500 cursor-pointer">
                                    <i class="fas fa-volume-up text-xs text-gray-400"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="h-4 w-[1px] bg-white/10 mx-2"></div>
                    
                    <button id="voice-toggle-btn" onclick="toggleVoiceCall()" class="bg-emerald-500/10 hover:bg-emerald-500 text-emerald-400 hover:text-white border border-emerald-500/20 text-xs font-bold h-full px-4 rounded-full shadow-sm transition-all duration-300 flex items-center justify-center space-x-2 min-w-[110px]">
                        <i class="fas fa-headset"></i>
                        <span>Join Call</span>
                    </button>
                </div>

                <div class="relative ml-2">
                    <button id="notificationBellBtn" onclick="toggleNotificationDropdown()" class="relative flex items-center justify-center w-10 h-10 rounded-full bg-gray-800/50 hover:bg-white/10 border border-white/5 transition-colors focus:outline-none">
                        <i class="fas fa-bell text-gray-400 hover:text-white transition-colors"></i>
                        <span id="global-notification-badge" class="absolute top-0 right-0 transform translate-x-1/4 -translate-y-1/4 bg-purple-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full border-2 border-gray-900 hidden shadow-lg shadow-purple-500/50 animate-pulse">0</span>
                    </button>

                    <!-- Notification Dropdown Menu -->
                    <div id="notificationDropdown" class="hidden absolute right-0 mt-3 w-80 sm:w-96 bg-gray-900/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl z-50 overflow-hidden transform origin-top-right transition-all">
                        <div class="flex justify-between items-center px-4 py-3 border-b border-white/10 bg-gray-800/50">
                            <h3 class="text-white font-bold tracking-wide">Notifications</h3>
                            <button onclick="clearAllNotifications()" class="text-xs text-red-400 hover:text-red-300 font-medium bg-red-500/10 hover:bg-red-500/20 px-2 py-1 rounded transition-colors" title="Clear all notifications from database">
                                Clear All
                            </button>
                        </div>
                        
                        <!-- Tabs -->
                        <div class="flex border-b border-white/5 bg-gray-800/30">
                            <button onclick="switchNotificationTab('unread')" id="tab-unread" class="flex-1 py-2 text-sm font-bold text-purple-400 border-b-2 border-purple-500 bg-white/5 transition-colors">Unread</button>
                            <button onclick="switchNotificationTab('read')" id="tab-read" class="flex-1 py-2 text-sm font-medium text-gray-400 border-b-2 border-transparent hover:text-gray-200 transition-colors">Read</button>
                        </div>

                        <!-- Notification Lists -->
                        <div class="max-h-[350px] overflow-y-auto custom-scrollbar relative">
                            <div id="unread-notifications-list" class="flex flex-col">
                                <!-- Unread items injected here -->
                                <div class="px-4 py-8 text-center text-gray-500 text-sm hidden" id="no-unread-msg">No unread notifications</div>
                            </div>
                            <div id="read-notifications-list" class="flex flex-col hidden">
                                <!-- Read items injected here -->
                                <div class="px-4 py-8 text-center text-gray-500 text-sm hidden" id="no-read-msg">No read notifications</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="onlineUsersContainer" class="hidden sm:flex items-center -space-x-2 hover:space-x-0 transition-all duration-300 border-l border-white/10 pl-4">
                </div>
                
                <div class="relative ml-2">
                    <button id="userMenuButton" onclick="openProfileModal()" class="flex items-center space-x-2 focus:outline-none hover:bg-white/10 p-1.5 rounded-lg transition-colors">
                        <div class="w-8 h-8 rounded-full bg-gray-700 flex items-center justify-center overflow-hidden border border-gray-600 shadow-sm">
                            <img id="navProfileImg" src="<?= htmlspecialchars($displayPhoto) ?>" alt="Profile" class="w-full h-full object-cover">
                        </div>
                        <span class="hidden md:inline font-medium"><?= htmlspecialchars($_SESSION['nickname'] ?? 'User'); ?></span>
                        <div class="relative flex h-3 w-3 ml-1">
                            <span class="online-indicator animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75" style="display: none;"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-gray-400 online-status" data-user-id="<?= htmlspecialchars($_SESSION['user_id'] ?? 0) ?>"></span>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </nav>
    <?php
}

function renderSidebar($activePage = 'dashboard', $pendingCount = 0, $pendingCCTVCount = 0) {
    ?>
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-30 hidden md:hidden"></div>
    
    <aside id="sidebar" class="fixed h-full z-40">
        <div class="p-4">
            <div class="flex items-center space-x-4">
                <div class="logo-container">
                    <img src="../assets/cxi.png" alt="CXI Logo" class="logo">
                </div>
            </div>
            <div class="border-t border-white/10 my-4"></div>
            
            <nav class="mt-6">
                <ul class="space-y-1">
                    <?= generateSidebarMenu($activePage, $pendingCount, $pendingCCTVCount) ?>
                </ul>
            </nav>
        </div>
    </aside>

    <div class="main-content flex-1 flex flex-col relative z-10">
    <?php
}

function generateSidebarMenu($activePage, $pendingCount, $pendingCCTVCount) {
    $userRole = getUserRole();
    $menuItems = '';

    switch($userRole) {
        case 'admin':
            $menuItems = generateAdminMenu($activePage, $pendingCount, $pendingCCTVCount);
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

function generateAdminMenu($activePage, $pendingCount, $pendingCCTVCount) {
    ob_start();
    ?>
    <li>
        <a href="dashboard.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'dashboard' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-tachometer-alt"></i>
            </div>
            <span class="sidebar-text font-medium">Dashboard</span>
        </a>
    </li>
    
    <li class="relative group">
        <a href="attendance.php" class="sidebar-item rounded-lg flex items-center justify-between px-4 py-3 text-gray-300 hover:text-white <?= in_array($activePage, ['attendance', 'attendance_statistics', 'incident_report']) ? 'active' : '' ?>">
            <div class="flex items-center">
                <div class="sidebar-icon-container">
                    <i class="sidebar-icon fas fa-chart-line"></i>
                </div>
                <span class="sidebar-text font-medium">SLT Tracker</span>
            </div>
            <i class="fas fa-chevron-down text-xs transition-transform duration-200 group-hover:rotate-180"></i>
        </a>
        <ul class="ml-4 mt-1 space-y-1 overflow-hidden max-h-0 group-hover:max-h-40 transition-all duration-300 ease-in-out border-l border-white/10">
            <li>
                <a href="attendance.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'attendance' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-list text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Tracker</span>
                </a>
            </li>
            <li>
                <a href="attendance_statistics.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'attendance_statistics' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-chart-pie text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Attendance Statistics</span>
                </a>
            </li>
            <li>
                <a href="incident_report.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'incident_report' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-exclamation-triangle text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Incident Report</span>
                </a>
            </li>
        </ul>
    </li>
    
    <li class="relative group">
        <a href="ticket_dashboard.php" class="sidebar-item rounded-lg flex items-center justify-between px-4 py-3 text-gray-300 hover:text-white <?= in_array($activePage, ['ticket_dashboard', 'statistics', 'hris_dashboard']) ? 'active' : '' ?>">
            <div class="flex items-center">
                <div class="sidebar-icon-container relative">
                    <i class="sidebar-icon fas fa-clipboard-list"></i>
                    <?php if ($pendingCount > 0): ?>
                        <span class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs shadow-lg shadow-red-500/50" id="pending-tickets-badge">
                            <?= $pendingCount > 9 ? '9+' : $pendingCount ?>
                        </span>
                    <?php else: ?>
                        <span class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs shadow-lg shadow-red-500/50" id="pending-tickets-badge" style="display: none;"></span>
                    <?php endif; ?>
                </div>
                <span class="sidebar-text font-medium">SLT Ticketing</span>
            </div>
            <i class="fas fa-chevron-down text-xs transition-transform duration-200 group-hover:rotate-180"></i>
        </a>
        <ul class="ml-4 mt-1 space-y-1 overflow-hidden max-h-0 group-hover:max-h-40 transition-all duration-300 ease-in-out border-l border-white/10">
            <li>
                <a href="ticket_dashboard.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'ticket_dashboard' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container relative">
                        <i class="sidebar-icon fas fa-ticket-alt text-xs"></i>
                        <?php if ($pendingCount > 0): ?>
                            <span class="absolute -top-1 -right-1 bg-red-500 rounded-full w-2 h-2 flex items-center justify-center text-xs shadow-lg shadow-red-500/50" id="pending-tickets-submenu-badge"></span>
                        <?php else: ?>
                            <span class="absolute -top-1 -right-1 bg-red-500 rounded-full w-2 h-2 flex items-center justify-center text-xs shadow-lg shadow-red-500/50" id="pending-tickets-submenu-badge" style="display: none;"></span>
                        <?php endif; ?>
                    </div>
                    <span class="sidebar-text text-sm">Ticketing</span>
                </a>
            </li>
            <li>
                <a href="statistics.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'statistics' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-chart-bar text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Ticket Statistics</span>
                </a>
            </li>
            <li>
                <a href="hris_dashboard.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'hris_dashboard' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-users-cog text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">HRIS Dashboard</span>
                </a>
            </li>
        </ul>
    </li>

    <li>
        <a href="cctv_request.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'cctv_request' ? 'active' : '' ?>">
            <div class="sidebar-icon-container relative">
                <i class="sidebar-icon fas fa-video"></i>
                <?php if ($pendingCCTVCount > 0): ?>
                    <span class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs shadow-lg shadow-red-500/50" id="pending-cctv-badge">
                        <?= $pendingCCTVCount > 9 ? '9+' : $pendingCCTVCount ?>
                    </span>
                <?php else: ?>
                    <span class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs shadow-lg shadow-red-500/50" id="pending-cctv-badge" style="display: none;"></span>
                <?php endif; ?>
            </div>
            <span class="sidebar-text font-medium">CCTV Request</span>
        </a>
    </li>
    
    <li>
        <a href="terabit_tracker.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'network' ? 'active' : '' ?>">
            <div class="sidebar-icon-container relative">
                <i class="sidebar-icon fas fa-network-wired"></i>
            </div>
            <span class="sidebar-text font-medium">Terabit Tracker</span>
        </a>
    </li>
    
    <li class="relative group">
        <a href="users.php" class="sidebar-item rounded-lg flex items-center justify-between px-4 py-3 text-gray-300 hover:text-white <?= in_array($activePage, ['users', 'inventory', 'shift_report', 'employees']) ? 'active' : '' ?>">
            <div class="flex items-center">
                <div class="sidebar-icon-container">
                    <i class="sidebar-icon fas fa-cog"></i>
                </div>
                <span class="sidebar-text font-medium">Settings</span>
            </div>
            <i class="fas fa-chevron-down text-xs transition-transform duration-200 group-hover:rotate-180"></i>
        </a>
        <ul class="ml-4 mt-1 space-y-1 overflow-hidden max-h-0 group-hover:max-h-48 transition-all duration-300 ease-in-out border-l border-white/10">
            <li>
                <a href="inventory_tracker.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'inventory' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-boxes text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">SLT Inventory</span>
                </a>
            </li>
            <li>
                <a href="shift_report.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'shift_report' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-list-alt text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Shift Report</span>
                </a>
            </li>
            <li>
                <a href="employees.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'employees' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-users text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Manage Agents</span>
                </a>
            </li>
            <li>
                <a href="users.php" class="sidebar-item rounded-lg flex items-center px-4 py-2 text-gray-300 hover:text-white <?= $activePage === 'users' ? 'active' : '' ?>">
                    <div class="sidebar-icon-container">
                        <i class="sidebar-icon fas fa-user-friends text-xs"></i>
                    </div>
                    <span class="sidebar-text text-sm">Team Members</span>
                </a>
            </li>
        </ul>
    </li>
    <?php
    return ob_get_clean();
}

function generateHRMenu($activePage) {
    ob_start();
    ?>
    <li>
        <a href="dashboard.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'dashboard' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-tachometer-alt"></i>
            </div>
            <span class="sidebar-text font-medium">Dashboard</span>
        </a>
    </li>
    <li>
        <a href="incident_report.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'incident_report' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-exclamation-triangle"></i>
            </div>
            <span class="sidebar-text font-medium">Incident Report</span>
        </a>
    </li>
    <li>
        <a href="nte_list.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'nte' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-file-alt"></i>
            </div>
            <span class="sidebar-text font-medium">Notice to Explain</span>
        </a>
    </li>
    <li>
        <a href="infractions.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'infractions' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-file-contract"></i>
            </div>
            <span class="sidebar-text font-medium">Infractions</span>
        </a>
    </li>
    <li>
        <a href="hris_dashboard.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'hris_dashboard' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-users-cog"></i>
            </div>
            <span class="sidebar-text font-medium">HRIS Dashboard</span>
        </a>
    </li>
    <li>
        <a href="users.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'users' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-user-friends"></i>
            </div>
            <span class="sidebar-text font-medium">Team Members</span>
        </a>
    </li>
    <?php
    return ob_get_clean();
}

function generateDefaultMenu($activePage) {
    ob_start();
    ?>
    <li>
        <a href="dashboard.php" class="sidebar-item rounded-lg flex items-center px-4 py-3 text-gray-300 hover:text-white <?= $activePage === 'dashboard' ? 'active' : '' ?>">
            <div class="sidebar-icon-container">
                <i class="sidebar-icon fas fa-tachometer-alt"></i>
            </div>
            <span class="sidebar-text font-medium">Dashboard</span>
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
    <footer class="bg-gray-900 border-t border-white/5 py-4 mt-auto">
        <div class="container mx-auto px-4 text-center text-gray-500 text-sm font-medium">
            &copy; <?= date('Y') ?> CXI Services Inc. All rights reserved.
        </div>
    </footer>

    <!-- PROFILE MODAL -->
    <div id="profileModal" class="hidden fixed inset-0 z-[100] bg-black/60 backdrop-blur-sm flex items-center justify-center transition-all duration-300 opacity-0">
        <div class="bg-gray-900/90 backdrop-blur-xl rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-all duration-300 border border-white/10">
            <div class="flex justify-between items-center px-6 py-4 border-b border-white/5 bg-white/5">
                <h2 class="text-lg font-semibold text-white tracking-wide">User Profile</h2>
                <button onclick="closeProfileModal()" class="text-gray-400 hover:text-white bg-white/5 hover:bg-white/10 rounded-full w-8 h-8 flex items-center justify-center transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="p-8 flex flex-col items-center relative overflow-hidden">
                <div class="absolute top-8 w-32 h-32 bg-primary-500/20 rounded-full blur-2xl animate-pulse pointer-events-none"></div>

                <div id="modalPhotoContainer" class="relative group cursor-pointer z-10" onclick="document.getElementById('profilePhotoInput').click()" title="Change Profile Photo">
                    <div class="w-32 h-32 rounded-full overflow-hidden border-[3px] border-gray-800 ring-4 ring-white/5 relative shadow-2xl transition-transform duration-300 group-hover:scale-105">
                        <img id="modalProfileImg" src="../components/profile/default.jpg" alt="Profile" class="w-full h-full object-cover">
                        <div id="modalCameraOverlay" class="absolute inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-300">
                            <i class="fas fa-camera text-white text-2xl transform translate-y-4 group-hover:translate-y-0 transition-transform duration-300"></i>
                        </div>
                    </div>
                    <span class="online-indicator animate-ping absolute bottom-1 right-2 w-6 h-6 rounded-full bg-green-400 opacity-75 pointer-events-none" style="display: none;"></span>
                    <div id="modalStatusDot" class="online-status absolute bottom-1 right-2 w-6 h-6 rounded-full border-4 border-gray-900 bg-gray-400" data-user-id="<?= htmlspecialchars($_SESSION['user_id'] ?? 0) ?>"></div>
                </div>
                <input type="file" id="profilePhotoInput" class="hidden" accept="image/jpeg, image/png, image/gif" onchange="uploadProfilePhoto(this)">
                
                <p id="uploadStatus" class="text-sm text-primary-400 mt-3 hidden animate-pulse font-medium">Uploading photo...</p>
                
                <h3 id="modalFullname" class="mt-5 text-2xl font-bold text-white tracking-wide text-center">Loading...</h3>
                <p class="text-xs text-gray-400 mt-1 uppercase tracking-[0.2em] font-semibold" id="modalRole">ROLE</p>
                
                <div class="mt-5 flex items-center z-10">
                    <span id="modalActiveText" class="px-5 py-1.5 bg-white/5 text-gray-400 text-sm font-medium rounded-full border border-white/10 shadow-inner">Status Unknown</span>
                </div>
            </div>
            
            <div id="modalFooterSignOut" class="bg-black/20 px-6 py-4 flex justify-end border-t border-white/5">
                <a href="../logout.php" class="inline-flex items-center space-x-2 bg-red-500/10 hover:bg-red-500 text-red-400 hover:text-white px-6 py-2.5 rounded-xl text-sm font-bold transition-all duration-300 border border-red-500/20 shadow-lg hover:shadow-red-500/20 hover:-translate-y-0.5">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Sign Out</span>
                </a>
            </div>
        </div>
    </div>

    <!-- AUDIO ASSETS -->
    <audio id="notificationSound" src="lalamove.mp3" preload="auto"></audio>
    <audio id="vtoNotificationSound" src="../assets/vto_alert.mp3" preload="auto"></audio>
    <audio id="joinSound" src="../assets/join.mp3" preload="auto"></audio>
    <audio id="disconnectSound" src="../assets/disconnect.mp3" preload="auto"></audio>

    <div id="ticket-popup" class="fixed bottom-6 right-6 z-50 transform transition-all duration-500 ease-[cubic-bezier(0.68,-0.55,0.26,1.55)] translate-x-[120%] max-w-sm w-full md:w-96">
        <div class="bg-gray-900/95 backdrop-blur-xl border-l-4 border-emerald-500 rounded-xl shadow-2xl shadow-black/50 p-5 relative border-y border-r border-white/10">
            <button onclick="closeTicketPopup()" class="absolute top-2 right-2 text-gray-400 hover:text-white bg-white/5 hover:bg-white/10 rounded-full w-7 h-7 flex items-center justify-center transition-colors">
                <i class="fas fa-times text-xs"></i>
            </button>
            
            <div class="flex items-start">
                <div class="flex-shrink-0 pt-1">
                    <div class="bg-emerald-500/20 rounded-full p-2.5 border border-emerald-500/20 shadow-inner">
                        <i class="fas fa-ticket-alt text-emerald-400 text-xl"></i>
                    </div>
                </div>
                <div class="ml-4 w-full pr-4">
                    <h3 class="text-white font-bold text-lg tracking-wide">Ticket Received!</h3>
                    <div class="mt-3 space-y-2">
                        <div class="flex items-center text-sm">
                            <i class="fas fa-user text-gray-500 w-5 text-center mr-2"></i>
                            <span id="popup-name" class="text-emerald-400 font-medium truncate"></span>
                        </div>
                        <div class="flex items-center text-sm">
                            <i class="fas fa-exclamation-circle text-gray-500 w-5 text-center mr-2"></i>
                            <span id="popup-issue" class="text-gray-300 truncate"></span>
                        </div>
                        <div class="flex items-center text-xs mt-3 text-gray-500 bg-white/5 rounded-md px-2 py-1 w-max">
                            <i class="far fa-clock mr-1.5"></i>
                            <span id="popup-time"></span>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-white/10">
                        <a href="ticket_dashboard.php" class="text-xs font-bold text-primary-400 hover:text-primary-300 uppercase tracking-widest flex items-center group">
                            View Dashboard <i class="fas fa-arrow-right ml-2 transform group-hover:translate-x-1 transition-transform"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="vto-popup" class="fixed bottom-24 right-6 z-50 transform transition-all duration-500 ease-[cubic-bezier(0.68,-0.55,0.26,1.55)] translate-x-[120%] max-w-sm w-full md:w-96">
        <div class="bg-gray-900/95 backdrop-blur-xl border-l-4 border-purple-500 rounded-xl shadow-2xl shadow-purple-500/20 p-5 relative border-y border-r border-white/10">
            <button onclick="closeVtoPopup()" class="absolute top-2 right-2 text-gray-400 hover:text-white bg-white/5 hover:bg-white/10 rounded-full w-7 h-7 flex items-center justify-center transition-colors">
                <i class="fas fa-times text-xs"></i>
            </button>
            
            <div class="flex items-start">
                <div class="flex-shrink-0 pt-1">
                    <div class="bg-purple-500/20 rounded-full p-2.5 border border-purple-500/20 shadow-inner">
                        <i class="fas fa-envelope-open-text text-purple-400 text-xl animate-pulse"></i>
                    </div>
                </div>
                <div class="ml-4 w-full pr-4">
                    <h3 class="text-white font-bold text-lg tracking-wide">VTO Request Alert</h3>
                    <div class="mt-2 space-y-1">
                        <p id="vto-popup-subject" class="text-gray-300 text-sm font-medium leading-snug"></p>
                        <div class="flex items-center justify-between mt-3">
                            <div class="flex items-center text-xs text-gray-500 bg-white/5 rounded-md px-2 py-1 w-max">
                                <i class="far fa-clock mr-1.5"></i>
                                <span>Just now</span>
                            </div>
                            <button onclick="closeVtoPopup(); document.getElementById('notificationDropdown').classList.remove('hidden');" class="text-xs font-bold text-purple-400 hover:text-purple-300 transition-colors">
                                View in Inbox
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="vtoPreviewModal" class="hidden fixed inset-0 z-[110] bg-black/60 backdrop-blur-sm flex items-center justify-center transition-all duration-300 opacity-0">
        <div class="bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden transform scale-95 transition-all duration-300 border border-white/10">
            <div class="flex justify-between items-center px-6 py-4 border-b border-white/5 bg-gray-800/50">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fas fa-envelope text-purple-500"></i>
                    VTO Request
                </h2>
                <button onclick="closeVtoPreview()" class="text-gray-400 hover:text-white bg-white/5 hover:bg-white/10 rounded-full w-8 h-8 flex items-center justify-center transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <label class="text-xs font-bold text-gray-500 uppercase tracking-wider">Subject / Details</label>
                    <div id="vtoPreviewSubject" class="mt-1 text-gray-200 text-sm bg-gray-800/50 p-4 rounded-xl border border-gray-700 leading-relaxed">
                        <!-- Content injected here -->
                    </div>
                </div>
                <div class="flex justify-between items-center">
                    <div>
                        <label class="text-xs font-bold text-gray-500 uppercase tracking-wider">Received At</label>
                        <p id="vtoPreviewTime" class="text-sm font-medium text-gray-400 mt-1"></p>
                    </div>
                    <span class="px-3 py-1 bg-purple-500/20 text-purple-400 rounded-full text-xs font-bold border border-purple-500/30">VTO Alert</span>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-white/5 bg-gray-800/30 flex justify-end">
                <button onclick="closeVtoPreview()" class="px-5 py-2 bg-gray-700 hover:bg-gray-600 text-white text-sm font-medium rounded-lg transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        const sidebarToggleBtn = document.getElementById('sidebarToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const sidebar = document.getElementById('sidebar');

        function toggleSidebar() {
            if (sidebar) sidebar.classList.toggle('open');
            if (sidebarOverlay) sidebarOverlay.classList.toggle('hidden');
        }

        if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', toggleSidebar);
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);

        let lastOnlineUsersList = [];
        
        async function checkOnlineStatus() {
            try {
                const response = await fetch('../api/online_status.php');
                const data = await response.json();
                
                if (data.success) {
                    const currentOnlineUsers = data.onlineUsers;
                    
                    document.querySelectorAll('.online-status').forEach(indicator => {
                        const userId = indicator.getAttribute('data-user-id');
                        const isOnline = currentOnlineUsers.includes(parseInt(userId));
                        
                        if (isOnline) {
                            indicator.classList.remove('bg-gray-400');
                            indicator.classList.add('bg-green-400');
                            indicator.setAttribute('title', 'Online'); 
                            
                            const pingElement = indicator.previousElementSibling;
                            if (pingElement && pingElement.classList.contains('online-indicator')) {
                                pingElement.style.display = 'inline-flex';
                            }
                        } else {
                            indicator.classList.remove('bg-green-400');
                            indicator.classList.add('bg-gray-400');
                            indicator.setAttribute('title', 'Offline'); 
                            
                            const pingElement = indicator.previousElementSibling;
                            if (pingElement && pingElement.classList.contains('online-indicator')) {
                                pingElement.style.display = 'none';
                            }
                        }
                    });
                    
                    const currentSorted = [...currentOnlineUsers].sort().join(',');
                    const lastSorted = [...lastOnlineUsersList].sort().join(',');
                    
                    if (currentSorted !== lastSorted) {
                        lastOnlineUsersList = currentOnlineUsers;
                        await updateOnlineUsersUI(currentOnlineUsers);
                    }
                }
            } catch (error) {
                console.error('Error checking online status:', error);
            }
        }

        async function updateOnlineUsersUI(userIds) {
            const container = document.getElementById('onlineUsersContainer');
            if (!container) return;
            
            if (!userIds || userIds.length === 0) {
                container.innerHTML = '';
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'get_users_batch');
            formData.append('user_ids', JSON.stringify(userIds));
            
            try {
                const response = await fetch('../components/profile_handler.php', {
                    method: 'POST',
                    body: formData
                });
                const rawText = await response.text();
                
                try {
                    const result = JSON.parse(rawText);
                    if (result.success && result.data.length > 0) {
                        let html = '';
                        result.data.forEach(user => {
                            const photoPath = '../components/profile/' + user.display_photo;
                            html += `
                                <div class="relative group cursor-pointer transition-transform duration-300 hover:-translate-y-1 hover:z-10" 
                                     onclick="openProfileModal(${user.id})"
                                     title="${user.fullname}">
                                    <div class="w-8 h-8 rounded-full border-2 border-gray-900 ring-2 ring-transparent group-hover:ring-white/20 overflow-hidden bg-gray-700 shadow-lg transition-all duration-300">
                                        <img src="${photoPath}" alt="${user.fullname}" class="w-full h-full object-cover">
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-green-400 border-[1.5px] border-gray-900 shadow-sm"></span>
                                </div>
                            `;
                        });
                        container.innerHTML = html;
                    } else if (result.success && result.data.length === 0) {
                        container.innerHTML = '';
                    }
                } catch (e) {
                    console.error("Failed to parse online users JSON", e);
                }
            } catch (error) {
                console.error("Error updating online users UI", error);
            }
        }
        
        async function sendHeartbeat() {
            try { await fetch('../api/heartbeat.php'); } 
            catch (error) { console.error("Error sending heartbeat:", error); }
        }
        
        sendHeartbeat();
        setInterval(sendHeartbeat, 30000);
        window.addEventListener('beforeunload', () => { navigator.sendBeacon('../api/heartbeat.php?action=offline'); });
        checkOnlineStatus();
        setInterval(checkOnlineStatus, 5000);

        async function openProfileModal(targetUserId = null) {
            const modal = document.getElementById('profileModal');
            if (!modal) return;
            const modalBox = modal.querySelector('div');
            
            modal.classList.remove('hidden');
            void modal.offsetWidth; 
            modal.classList.remove('opacity-0');
            modalBox.classList.remove('scale-95');
            
            document.getElementById('modalFullname').textContent = 'Loading...';
            document.getElementById('modalRole').textContent = 'ROLE';
            document.getElementById('modalActiveText').className = 'px-5 py-1.5 bg-white/5 text-gray-400 text-sm font-medium rounded-full border border-white/10 shadow-inner';
            document.getElementById('modalActiveText').innerHTML = 'Status Unknown';
            
            try {
                const url = targetUserId 
                    ? '../components/profile_handler.php?action=get_profile&user_id=' + targetUserId 
                    : '../components/profile_handler.php?action=get_profile';
                    
                const response = await fetch(url);
                const rawText = await response.text();
                
                let result;
                try {
                    result = JSON.parse(rawText);
                } catch (jsonError) {
                    console.error("CRITICAL: Backend did not return valid JSON:", rawText);
                    document.getElementById('modalFullname').textContent = 'Backend Error';
                    document.getElementById('modalRole').textContent = 'Press F12 to check console';
                    document.getElementById('modalActiveText').textContent = 'Failed';
                    return;
                }
                
                if (result.success) {
                    const data = result.data;
                    document.getElementById('modalFullname').textContent = data.fullname || 'Unknown User';
                    document.getElementById('modalRole').textContent = data.role || 'user';
                    
                    const photoPath = data.display_photo ? `../components/profile/${data.display_photo}` : '../components/profile/default.jpg';
                    document.getElementById('modalProfileImg').src = photoPath;
                    
                    const isActive = parseInt(data.is_active) === 1;
                    const statusText = document.getElementById('modalActiveText');
                    
                    if (isActive) {
                        statusText.innerHTML = '<i class="fas fa-check-circle mr-1 text-emerald-400"></i> Active';
                        statusText.className = 'px-5 py-1.5 bg-emerald-500/10 text-emerald-400 text-sm font-bold rounded-full border border-emerald-500/20 shadow-inner tracking-wide';
                    } else {
                        statusText.innerHTML = '<i class="fas fa-times-circle mr-1 text-red-400"></i> Inactive';
                        statusText.className = 'px-5 py-1.5 bg-red-500/10 text-red-400 text-sm font-bold rounded-full border border-red-500/20 shadow-inner tracking-wide';
                    }
                    
                    const isSelf = data.is_self !== undefined ? data.is_self : true;
                    const photoContainer = document.getElementById('modalPhotoContainer');
                    const cameraOverlay = document.getElementById('modalCameraOverlay');
                    const footerSignOut = document.getElementById('modalFooterSignOut');
                    const statusDot = document.getElementById('modalStatusDot');
                    
                    if(statusDot) statusDot.setAttribute('data-user-id', data.id);
                    
                    if (isSelf) {
                        photoContainer.setAttribute('onclick', "document.getElementById('profilePhotoInput').click()");
                        photoContainer.classList.add('cursor-pointer');
                        cameraOverlay.classList.remove('hidden');
                        footerSignOut.classList.remove('hidden');
                    } else {
                        photoContainer.removeAttribute('onclick');
                        photoContainer.classList.remove('cursor-pointer');
                        cameraOverlay.classList.add('hidden');
                        footerSignOut.classList.add('hidden');
                    }
                    
                    if (typeof checkOnlineStatus === 'function') checkOnlineStatus();
                } else {
                    document.getElementById('modalFullname').textContent = 'Error Loading Profile';
                    document.getElementById('modalRole').textContent = result.message || 'Error';
                }
            } catch (error) {
                console.error("Error fetching profile:", error);
                document.getElementById('modalFullname').textContent = 'Connection Error';
                document.getElementById('modalRole').textContent = 'Network failed';
            }
        } // FIX: Missing brace was restored here

        function closeProfileModal() {
            const modal = document.getElementById('profileModal');
            if (!modal) return;
            const modalBox = modal.querySelector('div');
            modal.classList.add('opacity-0');
            modalBox.classList.add('scale-95');
            setTimeout(() => { modal.classList.add('hidden'); }, 300);
        }

        const profileModalEl = document.getElementById('profileModal');
        if (profileModalEl) {
            profileModalEl.addEventListener('click', function(e) {
                if (e.target === this) closeProfileModal();
            });
        }

        async function uploadProfilePhoto(input) {
            if (!input.files || input.files.length === 0) return;
            
            const file = input.files[0];
            const formData = new FormData();
            formData.append('action', 'upload_photo');
            formData.append('photo', file);
            
            const uploadStatus = document.getElementById('uploadStatus');
            uploadStatus.classList.remove('hidden');
            
            try {
                const response = await fetch('../components/profile_handler.php', { method: 'POST', body: formData });
                const rawText = await response.text();
                
                let result;
                try { result = JSON.parse(rawText); } 
                catch (e) { alert("Backend error during upload."); return; }
                
                if (result.success) {
                    const newPhotoUrl = '../components/' + result.photo_url + '?t=' + new Date().getTime();
                    document.getElementById('modalProfileImg').src = newPhotoUrl;
                    const navImg = document.getElementById('navProfileImg');
                    if (navImg) navImg.src = newPhotoUrl;
                } else {
                    alert(result.message || "Failed to upload photo.");
                }
            } catch (error) {
                alert("An error occurred during upload.");
            } finally {
                uploadStatus.classList.add('hidden');
                input.value = '';
            }
        }

        let currentNotifications = [];

        function toggleNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.classList.toggle('hidden');
        }

        document.addEventListener('click', (e) => {
            const bellBtn = document.getElementById('notificationBellBtn');
            const dropdown = document.getElementById('notificationDropdown');
            if (bellBtn && dropdown && !bellBtn.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });

        function switchNotificationTab(tab) {
            const unreadList = document.getElementById('unread-notifications-list');
            const readList = document.getElementById('read-notifications-list');
            const unreadTabBtn = document.getElementById('tab-unread');
            const readTabBtn = document.getElementById('tab-read');

            if (tab === 'unread') {
                unreadList.classList.remove('hidden');
                readList.classList.add('hidden');
                unreadTabBtn.className = 'flex-1 py-2 text-sm font-bold text-purple-400 border-b-2 border-purple-500 bg-white/5 transition-colors';
                readTabBtn.className = 'flex-1 py-2 text-sm font-medium text-gray-400 border-b-2 border-transparent hover:text-gray-200 transition-colors';
            } else {
                readList.classList.remove('hidden');
                unreadList.classList.add('hidden');
                readTabBtn.className = 'flex-1 py-2 text-sm font-bold text-purple-400 border-b-2 border-purple-500 bg-white/5 transition-colors';
                unreadTabBtn.className = 'flex-1 py-2 text-sm font-medium text-gray-400 border-b-2 border-transparent hover:text-gray-200 transition-colors';
            }
        }

        function formatNotifTime(dateString) {
            const date = new Date(dateString);
            return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) + ' - ' + date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }

        function renderInbox() {
            const unreadList = document.getElementById('unread-notifications-list');
            const readList = document.getElementById('read-notifications-list');
            const unreadMsg = document.getElementById('no-unread-msg');
            const readMsg = document.getElementById('no-read-msg');
            const badge = document.getElementById('global-notification-badge');

            Array.from(unreadList.children).forEach(child => { if(child.id !== 'no-unread-msg') child.remove(); });
            Array.from(readList.children).forEach(child => { if(child.id !== 'no-read-msg') child.remove(); });

            const unreadItems = currentNotifications.filter(n => parseInt(n.is_read) === 0);
            const readItems = currentNotifications.filter(n => parseInt(n.is_read) === 1);

            if (unreadItems.length > 0) {
                badge.textContent = unreadItems.length > 9 ? '9+' : unreadItems.length;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }

            if (unreadItems.length === 0) unreadMsg.classList.remove('hidden');
            else {
                unreadMsg.classList.add('hidden');
                unreadItems.forEach(n => unreadList.insertAdjacentHTML('beforeend', createNotifHTML(n, true)));
            }

            if (readItems.length === 0) readMsg.classList.remove('hidden');
            else {
                readMsg.classList.add('hidden');
                readItems.forEach(n => readList.insertAdjacentHTML('beforeend', createNotifHTML(n, false)));
            }
        }

        function createNotifHTML(notif, isUnread) {
            const bgClass = isUnread ? 'bg-purple-500/5 hover:bg-purple-500/10' : 'hover:bg-gray-800 border-transparent';
            const borderClass = isUnread ? 'border-l-4 border-purple-500 border-y border-r border-white/5' : 'border-b border-gray-800';
            const iconColor = isUnread ? 'text-purple-400' : 'text-gray-500';
            const textColor = isUnread ? 'text-gray-200' : 'text-gray-400';
            
            return `
                <div class="p-3 cursor-pointer transition-colors ${bgClass} ${borderClass} group" onclick="openVtoPreview(${notif.id})">
                    <div class="flex gap-3 items-start">
                        <div class="mt-1">
                            <div class="${isUnread ? 'bg-purple-500/20' : 'bg-gray-800'} rounded-full p-2">
                                <i class="fas fa-envelope-open-text ${iconColor} text-sm"></i>
                            </div>
                        </div>
                        <div class="flex-1 overflow-hidden">
                            <p class="${textColor} text-sm font-medium leading-snug line-clamp-2">${notif.subject}</p>
                            <div class="flex items-center justify-between mt-2">
                                <p class="text-xs text-gray-500 font-mono">${formatNotifTime(notif.created_at)}</p>
                                <span class="text-[10px] uppercase font-bold text-gray-500 opacity-0 group-hover:opacity-100 transition-opacity">Click to view</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function openVtoPreview(id) {
            const notif = currentNotifications.find(n => parseInt(n.id) === parseInt(id));
            if (!notif) return;

            document.getElementById('vtoPreviewSubject').textContent = notif.subject;
            document.getElementById('vtoPreviewTime').textContent = formatNotifTime(notif.created_at);

            const modal = document.getElementById('vtoPreviewModal');
            const modalBox = modal.querySelector('div');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modalBox.classList.remove('scale-95');
            }, 10);

            if (parseInt(notif.is_read) === 0) markAsRead(id);
        }

        function closeVtoPreview() {
            const modal = document.getElementById('vtoPreviewModal');
            const modalBox = modal.querySelector('div');
            modal.classList.add('opacity-0');
            modalBox.classList.add('scale-95');
            setTimeout(() => { modal.classList.add('hidden'); }, 300);
        }

        async function markAsRead(id) {
            try {
                const notifIndex = currentNotifications.findIndex(n => parseInt(n.id) === parseInt(id));
                if (notifIndex > -1) {
                    currentNotifications[notifIndex].is_read = 1;
                    renderInbox();
                }
                await fetch(`<?= BASE_URL ?>components/fetch_notifications.php?action=mark_read&id=${id}`);
            } catch (error) { console.error('Failed to mark read:', error); }
        }

        async function clearAllNotifications() {
            if (!confirm("Are you sure you want to clear all VTO notifications? This will delete them from the database.")) return;
            try {
                const response = await fetch('<?= BASE_URL ?>components/fetch_notifications.php?action=clear_all');
                const data = await response.json();
                if (data.success) {
                    currentNotifications = [];
                    renderInbox();
                    document.getElementById('notificationDropdown').classList.add('hidden');
                }
            } catch (error) { alert('Failed to clear notifications.'); }
        }

        function showVtoPopup(subject) {
            document.getElementById('vto-popup-subject').textContent = subject || 'New VTO Email Received';
            const popup = document.getElementById('vto-popup');
            if(popup) {
                popup.classList.remove('translate-x-[120%]');
                popup.classList.add('translate-x-0');
                setTimeout(closeVtoPopup, 10000);
            }
        }

        function closeVtoPopup() {
            const popup = document.getElementById('vto-popup');
            if(popup) {
                popup.classList.remove('translate-x-0');
                popup.classList.add('translate-x-[120%]');
            }
        }

        let highestNotifId = localStorage.getItem('cxi_last_vto_notif_id') || 0;

        async function monitorVTONotifications() {
            try {
                const response = await fetch('<?= BASE_URL ?>components/fetch_notifications.php');
                if (!response.ok) return;
                
                const data = await response.json();
                
                if (data.notifications) {
                    currentNotifications = data.notifications;
                    renderInbox();

                    const newUnread = data.notifications.filter(n => parseInt(n.id) > highestNotifId && parseInt(n.is_read) === 0);
                    
                    if (newUnread.length > 0) {
                        const latest = newUnread[0];
                        const audio = document.getElementById('vtoNotificationSound');
                        if (audio) audio.play().catch(err => console.log("Sound autoplay blocked:", err));
                        
                        showVtoPopup(latest.subject);

                        const maxId = Math.max(...newUnread.map(n => parseInt(n.id)));
                        highestNotifId = maxId;
                        localStorage.setItem('cxi_last_vto_notif_id', highestNotifId);
                    }
                }
            } catch (error) { }
        }

        setInterval(monitorVTONotifications, 10000);
        document.addEventListener('DOMContentLoaded', monitorVTONotifications);

        function showTicketPopup(ticket) {
            document.getElementById('popup-name').textContent = ticket.Employee_name || 'Unknown Employee';
            document.getElementById('popup-issue').textContent = ticket.Issues_Concerning || ticket.Issue_Details || 'No details provided'; 
            document.getElementById('popup-time').textContent = ticket.TIME_RECEIVED || 'Just now';

            const popup = document.getElementById('ticket-popup');
            if(popup) {
                popup.classList.remove('translate-x-[120%]');
                popup.classList.add('translate-x-0');
            }
        }

        function closeTicketPopup() {
            const popup = document.getElementById('ticket-popup');
            if(popup) {
                popup.classList.remove('translate-x-0');
                popup.classList.add('translate-x-[120%]');
            }
        }

        let globalLastTicketId = 0;
        let globalIsFirstLoad = true;

        async function monitorGlobalTickets() {
            try {
                const response = await fetch('ticket_dashboard.php?action=get_tickets_data');
                if (!response.ok) return; 
                const data = await response.json();
                if (data.tickets && data.tickets.length > 0) {
                    const latestTicket = data.tickets[0];
                    const latestId = parseInt(latestTicket.id);
                    if (globalIsFirstLoad) {
                        globalLastTicketId = latestId;
                        globalIsFirstLoad = false;
                    } else {
                        if (latestId > globalLastTicketId) {
                            const audio = document.getElementById('notificationSound');
                            if (audio) audio.play().catch(err => console.log("Sound blocked:", err));
                            showTicketPopup(latestTicket);
                            if (typeof updateNotificationBadge === 'function') updateNotificationBadge();
                            if (typeof updatePageTitleWithNotifications === 'function') updatePageTitleWithNotifications();
                            globalLastTicketId = latestId;
                        }
                    }
                }
            } catch (error) { }
        }

        setInterval(monitorGlobalTickets, 15000);
        monitorGlobalTickets();

        let isConnectedToVoice = false;
        let isMuted = false;
        let isDeafened = false;
        let currentVolume = 1.0;
        let myPeerId = null;

        const voiceChannel = new BroadcastChannel('cxi_voice_channel');

        try {
            const cachedState = localStorage.getItem('cxi_voice_state');
            if (cachedState) {
                const state = JSON.parse(cachedState);
                if (state.isConnectedToVoice) {
                    isConnectedToVoice = true;
                    isMuted = state.isMuted;
                    isDeafened = state.isDeafened;
                    currentVolume = state.currentVolume;
                    myPeerId = state.myPeerId;
                    setTimeout(() => { updateVoiceControlsUI(state.participants || []); }, 50);
                }
            }
        } catch (e) { }

        function playGlobalSound(type) {
            try {
                const soundUrl = '<?= BASE_URL ?>assets/' + type + '.mp3';
                const audio = new Audio(soundUrl);
                audio.volume = 0.4;
                audio.play().catch(err => console.log('Audio playback blocked:', err));
            } catch (e) { }
        }

        voiceChannel.onmessage = (event) => {
            const data = event.data;
            if (!data) return;
            if (data.type === 'status_sync') {
                if (isConnectedToVoice && !data.isConnectedToVoice) playGlobalSound('disconnect');
                isConnectedToVoice = data.isConnectedToVoice;
                isMuted = data.isMuted;
                isDeafened = data.isDeafened;
                currentVolume = data.currentVolume;
                myPeerId = data.myPeerId;
                updateVoiceControlsUI(data.participants || []);
            }
        };

        function updateVoiceControlsUI(participants) {
            const btn = document.getElementById('voice-toggle-btn');
            const voiceControls = document.getElementById('voice-controls');
            const muteBtn = document.getElementById('btn-mute');
            const deafenBtn = document.getElementById('btn-deafen');
            const volumeSlider = document.getElementById('volume-slider');
            const participantsContainer = document.getElementById('voice-participants');

            if (!btn) return;
            const icon = btn.querySelector('i');
            const text = btn.querySelector('span');

            if (isConnectedToVoice) {
                btn.classList.remove('bg-emerald-500/10', 'hover:bg-emerald-500', 'text-emerald-400', 'border-emerald-500/20');
                btn.classList.add('bg-red-500/10', 'hover:bg-red-500', 'text-red-400', 'border-red-500/20');
                if (icon) { icon.classList.remove('fa-headset'); icon.classList.add('fa-phone-slash'); }
                if (text) text.innerText = "Leave";

                if (voiceControls) {
                    voiceControls.classList.remove('hidden');
                    voiceControls.classList.add('flex');
                }

                if (muteBtn) {
                    if (isMuted) { muteBtn.innerHTML = '<i class="fas fa-microphone-slash text-red-500"></i>'; muteBtn.setAttribute('title', 'Unmute Microphone'); } 
                    else { muteBtn.innerHTML = '<i class="fas fa-microphone"></i>'; muteBtn.setAttribute('title', 'Mute Microphone'); }
                }

                if (deafenBtn) {
                    if (isDeafened) { deafenBtn.innerHTML = '<i class="fas fa-headphones text-red-500"></i>'; deafenBtn.setAttribute('title', 'Undeafen'); } 
                    else { deafenBtn.innerHTML = '<i class="fas fa-headphones"></i>'; deafenBtn.setAttribute('title', 'Deafen'); }
                }

                if (volumeSlider) volumeSlider.value = currentVolume;
            } else {
                btn.classList.remove('bg-red-500/10', 'hover:bg-red-500', 'text-red-400', 'border-red-500/20');
                btn.classList.add('bg-emerald-500/10', 'hover:bg-emerald-500', 'text-emerald-400', 'border-emerald-500/20');
                if (icon) { icon.classList.remove('fa-phone-slash'); icon.classList.add('fa-headset'); }
                if (text) text.innerText = "Join Call";

                if (voiceControls) {
                    voiceControls.classList.add('hidden');
                    voiceControls.classList.remove('flex');
                }
            }

            if (participantsContainer && participants) {
                if (!isConnectedToVoice) fetchGlobalVoiceParticipants();
                else {
                    let html = '';
                    participants.forEach(p => {
                        const photoSrc = p.display_photo && p.display_photo !== '' ? '<?= BASE_URL ?>components/profile/' + p.display_photo : '<?= BASE_URL ?>components/profile/default.jpg';
                        const isMe = p.is_me;
                        const borderClass = isMe ? 'ring-blue-400' : 'ring-emerald-400';

                        html += `
                            <div class="relative group cursor-pointer transition-transform duration-300 hover:-translate-y-1 hover:z-10" title="${p.fullname}">
                                <div class="w-8 h-8 rounded-full border-2 border-gray-900 ring-2 ${borderClass} overflow-hidden bg-gray-800 shadow-lg">
                                    <img src="${photoSrc}" alt="${p.fullname}" class="w-full h-full object-cover">
                                </div>
                                <span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 rounded-full bg-emerald-500 flex items-center justify-center border-2 border-gray-900 shadow-sm">
                                    <i class="fas fa-microphone text-[8px] text-white"></i>
                                </span>
                            </div>
                        `;
                    });
                    participantsContainer.innerHTML = html;
                }
            }
        }

        function toggleMute() { voiceChannel.postMessage({ type: 'toggle_mute' }); }
        function toggleDeafen() { voiceChannel.postMessage({ type: 'toggle_deafen' }); }
        function changeVolume(val) { voiceChannel.postMessage({ type: 'change_volume', value: parseFloat(val) }); }

        async function toggleVoiceCall() {
            if (isConnectedToVoice) {
                voiceChannel.postMessage({ type: 'leave' });
                const formData = new FormData();
                formData.append('action', 'leave');
                try { await fetch('<?= BASE_URL ?>components/voice_status.php', { method: 'POST', body: formData }); } 
                catch (e) { }

                try { localStorage.removeItem('cxi_voice_state'); } catch (e) {}

                isConnectedToVoice = false; isMuted = false; isDeafened = false; currentVolume = 1.0; myPeerId = null;
                updateVoiceControlsUI([]);
            } else {
                const width = 410; const height = 600;
                const left = (screen.width / 2) - (width / 2); const top = (screen.height / 2) - (height / 2);
                window.open('<?= BASE_URL ?>components/voice_call.php', 'CXI_Voice_Call', `width=${width},height=${height},top=${top},left=${left},menubar=no,status=no,location=no,toolbar=no,scrollbars=no`);
            }
        }

        function queryVoiceStatus() { voiceChannel.postMessage({ type: 'query_status' }); }

        async function fetchGlobalVoiceParticipants() {
            if (isConnectedToVoice) return;
            try {
                const response = await fetch('<?= BASE_URL ?>components/voice_status.php?action=get_participants&t=' + new Date().getTime(), { cache: 'no-store' });
                const data = await response.json();
                if (data.success && data.participants) {
                    const participantsContainer = document.getElementById('voice-participants');
                    if (participantsContainer) {
                        let html = '';
                        data.participants.forEach(p => {
                            const photoSrc = p.display_photo && p.display_photo !== '' ? '<?= BASE_URL ?>components/profile/' + p.display_photo : '<?= BASE_URL ?>components/profile/default.jpg';
                            const isMe = p.id == <?= json_encode($_SESSION['user_id'] ?? null) ?>;
                            const borderClass = isMe ? 'ring-blue-400' : 'ring-emerald-400';

                            html += `
                                <div class="relative group cursor-pointer transition-transform duration-300 hover:-translate-y-1 hover:z-10" title="${p.fullname}">
                                    <div class="w-8 h-8 rounded-full border-2 border-gray-900 ring-2 ${borderClass} overflow-hidden bg-gray-800 shadow-lg">
                                        <img src="${photoSrc}" alt="${p.fullname}" class="w-full h-full object-cover">
                                    </div>
                                    <span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 rounded-full bg-emerald-500 flex items-center justify-center border-2 border-gray-900 shadow-sm">
                                        <i class="fas fa-microphone text-[8px] text-white"></i>
                                    </span>
                                </div>
                            `;
                        });
                        participantsContainer.innerHTML = html;
                    }
                }
            } catch (e) { }
        }

        setTimeout(queryVoiceStatus, 200); setInterval(queryVoiceStatus, 4000);
        setTimeout(fetchGlobalVoiceParticipants, 500); setInterval(fetchGlobalVoiceParticipants, 5000);

    </script>
</body>
</html>
<?php
}

function renderAlert() {
    if (isset($_SESSION['error'])) {
        echo '<div class="bg-red-500/10 backdrop-blur-md border border-red-500/20 text-red-400 px-5 py-4 rounded-xl mb-6 text-sm font-medium shadow-lg shadow-red-500/5 transition-all"><i class="fas fa-exclamation-circle mr-2"></i>'.$_SESSION['error'].'</div>';
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['success'])) {
        echo '<div class="bg-emerald-500/10 backdrop-blur-md border border-emerald-500/20 text-emerald-400 px-5 py-4 rounded-xl mb-6 text-sm font-medium shadow-lg shadow-emerald-500/5 transition-all"><i class="fas fa-check-circle mr-2"></i>'.$_SESSION['success'].'</div>';
        unset($_SESSION['success']);
    }
}
?>