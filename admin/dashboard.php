<?php
// Quick exit for latency monitor to prevent heavy DB queries every second
if (isset($_GET['ping'])) {
    http_response_code(200);
    exit;
}

require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect(BASE_URL);
}

updateLastActivity();

// Get statistics
$stats = [
    'pending_emails' => 0,
    'pending_ir' => 0,
    'uncovered_shift' => 0, 
    'pending_coverage' => 0, 
    'absent_today' => 0,
    'absent_week' => 0,
    'absent_month' => 0,
    'absent_year' => 0,
    'late_today' => 0,
    'late_week' => 0,
    'late_month' => 0,
    'late_year' => 0
];

// Initialize array for Recent Activities feed
$recentActivities = [];

try {
    // Pending emails (not sent)
    $stmt = $pdo->query("SELECT COUNT(*) FROM absenteeism WHERE email_sent = 0");
    $stats['pending_emails'] += $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM tardiness WHERE email_sent = 0");
    $stats['pending_emails'] += $stmt->fetchColumn();
    
    // Pending IR forms
    $stmt = $pdo->query("SELECT COUNT(*) FROM absenteeism WHERE ir_form NOT REGEXP '^(YES|NO NEED)'");
    $stats['pending_ir'] += $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM tardiness WHERE ir_form NOT REGEXP '^(YES|FOR ACCUMULATION|NO NEED|EXPIRED)'");
    $stats['pending_ir'] += $stmt->fetchColumn();

    // Pending Coverage
    $stmt = $pdo->query("SELECT COUNT(*) FROM absenteeism WHERE coverage_1 = 'PENDING'");
    $stats['pending_coverage'] += $stmt->fetchColumn();

    $todayDate = date('Y-m-d');

    // Pending Uncovered Shift
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM absenteeism WHERE coverage_1 = 'UNCOVERED' AND date_of_absent = ?");
    $stmt->execute([$todayDate]);
    $stats['uncovered_shift'] += $stmt->fetchColumn();

    // Absenteeism stats
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $yearStart = date('Y-01-01');
    $yearEnd = date('Y-12-31');
    
    // Today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM absenteeism WHERE date_of_absent = ?");
    $stmt->execute([$todayDate]);
    $stats['absent_today'] = $stmt->fetchColumn();
    
    // This week
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM absenteeism WHERE date_of_absent BETWEEN ? AND ?");
    $stmt->execute([$weekStart, $todayDate]);
    $stats['absent_week'] = $stmt->fetchColumn();
    
    // This month
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM absenteeism WHERE date_of_absent BETWEEN ? AND ?");
    $stmt->execute([$monthStart, $monthEnd]);
    $stats['absent_month'] = $stmt->fetchColumn();
    
    // This year
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM absenteeism WHERE date_of_absent BETWEEN ? AND ?");
    $stmt->execute([$yearStart, $yearEnd]);
    $stats['absent_year'] = $stmt->fetchColumn();
    
    // Tardiness stats
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tardiness WHERE date_of_incident = ?");
    $stmt->execute([$todayDate]);
    $stats['late_today'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tardiness WHERE date_of_incident BETWEEN ? AND ?");
    $stmt->execute([$weekStart, $todayDate]);
    $stats['late_week'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tardiness WHERE date_of_incident BETWEEN ? AND ?");
    $stmt->execute([$monthStart, $todayDate]);
    $stats['late_month'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tardiness WHERE date_of_incident BETWEEN ? AND ?");
    $stmt->execute([$yearStart, $todayDate]);
    $stats['late_year'] = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Database error in dashboard stats: " . $e->getMessage());
}

// NEW ELEMENT LOGIC: Fetch from literal activity_history table
try {
    $stmt = $pdo->query("SELECT sub_name, activity_description, activity_time FROM activity_history ORDER BY activity_time DESC LIMIT 10");
    $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to fetch from activity_history table: " . $e->getMessage());
}

// FINAL FALLBACK: If ALL log tables fail or are completely empty, reconstruct the literal activities 
// directly from the core tables. This guarantees the feed is never blank.
if (empty($recentActivities)) {
    try {
        $queries = [
            "SELECT sub_name, CONCAT('tracked new absence for ', full_name) as activity_description, timestamp as activity_time FROM absenteeism WHERE timestamp IS NOT NULL",
            "SELECT sub_name, CONCAT('tracked new tardiness for ', full_name) as activity_description, timestamp as activity_time FROM tardiness WHERE timestamp IS NOT NULL",
            "SELECT sub_name, CONCAT('tracked new VTO for ', full_name) as activity_description, timestamp as activity_time FROM vto_tracker WHERE timestamp IS NOT NULL",
            "SELECT sub_name, CONCAT('sent absenteeism email for ', full_name) as activity_description, email_sent_at as activity_time FROM absenteeism WHERE email_sent = 1 AND email_sent_at IS NOT NULL",
            "SELECT sub_name, CONCAT('sent tardiness email for ', full_name) as activity_description, email_sent_at as activity_time FROM tardiness WHERE email_sent = 1 AND email_sent_at IS NOT NULL"
        ];
        
        $unionQuery = implode(" UNION ALL ", $queries);
        $stmt = $pdo->query($unionQuery);
        $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Fallback recent activities query failed: " . $e->getMessage());
    }
}

// Guarantee Strict Order and Limit (Descending, Max 10)
if (!empty($recentActivities)) {
    usort($recentActivities, function($a, $b) {
        return strtotime($b['activity_time']) - strtotime($a['activity_time']);
    });
    $recentActivities = array_slice($recentActivities, 0, 10);
}

// Get data for charts (last 12 months)
$chartData = [
    'months' => [],
    'absenteeism' => [],
    'tardiness' => [],
    'absenteeism_percentage' => [],
    'tardiness_percentage' => []
];

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE is_active = 1");
    $totalActiveAgents = $stmt->fetchColumn();
    $totalActiveAgents = max($totalActiveAgents, 1); // Prevent Division By Zero

    $currentDate = new DateTime('first day of this month');
    
    for ($i = 11; $i >= 0; $i--) {
        $monthDate = clone $currentDate;
        $monthDate->sub(new DateInterval("P{$i}M"));
        
        $month = $monthDate->format('Y-m');
        $startDate = $monthDate->format('Y-m-01');
        $endDate = $monthDate->format('Y-m-t'); 
        
        $chartData['months'][] = $monthDate->format('M Y');
        
        // Absenteeism
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM absenteeism WHERE date_of_absent BETWEEN ? AND ?");
        $stmt->execute([$startDate, $endDate]);
        $absentCount = $stmt->fetchColumn();
        $chartData['absenteeism'][] = $absentCount;
        $chartData['absenteeism_percentage'][] = round(($absentCount / $totalActiveAgents) * 100, 2);
        
        // Tardiness
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tardiness WHERE date_of_incident BETWEEN ? AND ?");
        $stmt->execute([$startDate, $endDate]);
        $tardyCount = $stmt->fetchColumn();
        $chartData['tardiness'][] = $tardyCount;
        $chartData['tardiness_percentage'][] = round(($tardyCount / $totalActiveAgents) * 100, 2);
    }
} catch (PDOException $e) {
    error_log("Database error in dashboard chart data: " . $e->getMessage());
}

// Determine System Health Status
$totalPending = $stats['pending_emails'] + $stats['pending_ir'] + $stats['pending_coverage'];
$criticalItems = $stats['uncovered_shift'];
$systemStatus = 'Operational';
$statusColor = 'text-emerald-400';
$statusBg = 'bg-emerald-400/10';
$statusIcon = 'fa-check-circle';
$statusBorder = 'border-emerald-500/20';

if ($criticalItems > 0) {
    $systemStatus = 'Critical Actions Required';
    $statusColor = 'text-rose-500';
    $statusBg = 'bg-rose-500/10';
    $statusIcon = 'fa-exclamation-triangle';
    $statusBorder = 'border-rose-500/30';
} elseif ($totalPending > 10) {
    $systemStatus = 'Attention Needed';
    $statusColor = 'text-amber-400';
    $statusBg = 'bg-amber-400/10';
    $statusIcon = 'fa-engine-warning';
    $statusBorder = 'border-amber-500/30';
}

require_once '../components/layout.php';
renderHead('Dashboard Overview');
renderNavbar();
renderSidebar('dashboard');
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    body {
        font-family: 'Inter', sans-serif;
        background-color: #0f172a; /* Deep Slate 900 */
        color: #e2e8f0;
    }

    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #0f172a; }
    ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: #475569; }
    
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #475569; }

    .animate-fade-in-up {
        animation: fadeInUp 0.5s ease-out forwards;
        opacity: 0;
        transform: translateY(15px);
    }
    
    .delay-100 { animation-delay: 0.1s; }
    .delay-200 { animation-delay: 0.2s; }
    .delay-300 { animation-delay: 0.3s; }
    .delay-400 { animation-delay: 0.4s; }

    @keyframes fadeInUp {
        to { opacity: 1; transform: translateY(0); }
    }

    .glass-card {
        background: rgba(30, 41, 59, 0.7);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.05);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
</style>

<div class="pt-2 min-h-screen relative overflow-hidden pb-12">
    <!-- Subtle Background Glows -->
    <div class="absolute top-0 left-1/4 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl -z-10 pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-rose-500/5 rounded-full blur-3xl -z-10 pointer-events-none"></div>

    <main class="p-4 md:p-8 max-w-[1400px] mx-auto z-10">
        
        <!-- Header Section with Data Controls -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4 animate-fade-in-up border-b border-slate-700/50 pb-6">
            <div>
                <h1 class="text-3xl font-bold text-white tracking-tight flex items-center gap-3">
                    Dashboard Overview
                </h1>
                <div class="mt-2 flex items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium <?= $statusBg ?> <?= $statusColor ?> border <?= $statusBorder ?>">
                        <i class="fas <?= $statusIcon ?>"></i>
                        System Status: <?= $systemStatus ?>
                    </span>
                    <span class="text-slate-400 text-sm hidden sm:inline-block">|</span>
                    <span class="text-slate-400 text-sm hidden sm:inline-block"><i class="fas fa-users mr-1"></i> <?= $totalActiveAgents ?> Active Agents</span>
                </div>
            </div>
            
            <div class="flex flex-col items-end gap-3">
                <div class="text-sm font-medium text-slate-300 flex items-center gap-2">
                    <i class="far fa-calendar-alt text-blue-400"></i>
                    <?= date('l, F j, Y') ?> &nbsp;&bull;&nbsp;
                    <div class="flex items-center gap-2">
                        <div class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></div>
                        <span id="realtime-clock" class="font-mono tracking-wider"></span>
                    </div>
                </div>
            </div>
        </div>

        <?php renderAlert(); ?>

        <!-- Priority KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <!-- Uncovered Shift (Moved to primary position based on severity) -->
            <div class="glass-card rounded-2xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-rose-900/20 group animate-fade-in-up <?php echo $stats['uncovered_shift'] > 0 ? 'border-rose-500/50 shadow-[0_0_15px_rgba(244,63,94,0.15)] bg-gradient-to-br from-slate-800/80 to-rose-900/20' : 'border-slate-700/50'; ?>">
                <div class="flex items-start justify-between relative z-10">
                    <div>
                        <p class="text-sm font-medium text-slate-400 mb-1">Uncovered Shifts (Today)</p>
                        <h3 class="text-4xl font-bold text-white tracking-tight"><?= $stats['uncovered_shift'] ?></h3>
                        <?php if($stats['uncovered_shift'] > 0): ?>
                            <p class="text-xs text-rose-400 mt-2 font-medium flex items-center gap-1"><i class="fas fa-exclamation-triangle"></i> Immediate action needed</p>
                        <?php else: ?>
                            <p class="text-xs text-emerald-400 mt-2 flex items-center gap-1"><i class="fas fa-check"></i> All covered today</p>
                        <?php endif; ?>
                    </div>
                    <div class="w-12 h-12 rounded-xl <?= $stats['uncovered_shift'] > 0 ? 'bg-rose-500/20 border-rose-500/30' : 'bg-slate-700/50 border-slate-600/50' ?> flex items-center justify-center border">
                        <i class="fas fa-shield-alt <?= $stats['uncovered_shift'] > 0 ? 'text-rose-400' : 'text-slate-400' ?> text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Pending Emails -->
            <div class="glass-card rounded-2xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-blue-900/20 group animate-fade-in-up delay-100 border-slate-700/50">
                <div class="flex items-start justify-between relative z-10">
                    <div>
                        <p class="text-sm font-medium text-slate-400 mb-1">Pending Emails</p>
                        <h3 class="text-4xl font-bold text-white tracking-tight"><?= $stats['pending_emails'] ?></h3>
                        <p class="text-xs text-slate-400 mt-2 flex items-center gap-1">
                            <?php if($stats['pending_emails'] > 0): ?> <i class="fas fa-arrow-right text-blue-400"></i> Awaiting dispatch <?php else: ?> <i class="fas fa-check text-emerald-400"></i> All caught up <?php endif; ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-blue-500/10 flex items-center justify-center border border-blue-500/20">
                        <i class="fas fa-paper-plane text-blue-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Pending IR Forms -->
            <div class="glass-card rounded-2xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-amber-900/20 group animate-fade-in-up delay-200 border-slate-700/50">
                <div class="flex items-start justify-between relative z-10">
                    <div>
                        <p class="text-sm font-medium text-slate-400 mb-1">Pending IR Forms</p>
                        <h3 class="text-4xl font-bold text-white tracking-tight"><?= $stats['pending_ir'] ?></h3>
                        <p class="text-xs text-slate-400 mt-2 flex items-center gap-1">
                            <?php if($stats['pending_ir'] > 0): ?> <i class="fas fa-clock text-amber-400"></i> Requires processing <?php else: ?> <i class="fas fa-check text-emerald-400"></i> All processed <?php endif; ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center border border-amber-500/20">
                        <i class="fas fa-file-signature text-amber-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Pending Coverage (Future) -->
            <div class="glass-card rounded-2xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-purple-900/20 group animate-fade-in-up delay-300 border-slate-700/50">
                <div class="flex items-start justify-between relative z-10">
                    <div>
                        <p class="text-sm font-medium text-slate-400 mb-1">Pending Coverage (All)</p>
                        <h3 class="text-4xl font-bold text-white tracking-tight"><?= $stats['pending_coverage'] ?></h3>
                        <p class="text-xs text-slate-400 mt-2 flex items-center gap-1">
                            <?php if($stats['pending_coverage'] > 0): ?> <i class="fas fa-calendar-day text-purple-400"></i> Shifts to organize <?php else: ?> <i class="fas fa-check text-emerald-400"></i> Schedule stable <?php endif; ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-purple-500/10 flex items-center justify-center border border-purple-500/20">
                        <i class="fas fa-user-clock text-purple-400 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Network Monitoring & Speedtest -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8 animate-fade-in-up delay-100">
            
            <!-- Latency Line Graph -->
            <div class="lg:col-span-2 glass-card rounded-2xl border border-slate-700/50 p-6 flex flex-col h-full overflow-hidden relative">
                <div class="flex justify-between items-center mb-1 relative z-10">
                    <h3 class="font-semibold text-white flex items-center gap-2">
                        <i class="fas fa-satellite-dish text-emerald-400"></i> Network Latency
                    </h3>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5 text-xs text-slate-400">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Live
                        </div>
                        <span id="current-ping" class="text-xs font-mono bg-emerald-500/10 text-emerald-400 px-3 py-1 rounded-md border border-emerald-500/20 shadow-[0_0_10px_rgba(16,185,129,0.2)]">-- ms</span>
                    </div>
                </div>
                <div class="text-[11px] text-slate-400 mb-3 relative z-10 font-medium" id="network-info">
                    <i class="fas fa-spinner fa-spin text-slate-500 mr-1"></i> Detecting connection...
                </div>
                
                <div class="relative h-48 w-full mt-auto -mx-2 -mb-4">
                    <canvas id="latencyChart"></canvas>
                </div>
            </div>

            <!-- Speedtest Module -->
            <div class="glass-card rounded-2xl border border-slate-700/50 p-6 flex flex-col h-full relative overflow-hidden group">
                <div class="flex justify-between items-center mb-4 relative z-10">
                    <h3 class="font-semibold text-white flex items-center gap-2">
                        <i class="fas fa-tachometer-fast text-blue-400"></i> Speed Test
                    </h3>
                </div>
                
                <div class="flex-1 flex flex-col items-center justify-center text-center z-10">
                    <!-- Gauge Container -->
                    <div class="relative w-40 h-40 flex items-center justify-center mb-4">
                        <svg class="absolute inset-0 w-full h-full transform -rotate-90" viewBox="0 0 100 100">
                            <!-- Background track -->
                            <circle cx="50" cy="50" r="44" fill="none" stroke="#1e293b" stroke-width="8"></circle>
                            <!-- Progress track -->
                            <circle id="speed-gauge-progress" cx="50" cy="50" r="44" fill="none" stroke="#3b82f6" stroke-width="8" stroke-dasharray="276" stroke-dashoffset="276" stroke-linecap="round" class="transition-all duration-300 ease-linear shadow-[0_0_15px_rgba(59,130,246,0.5)]"></circle>
                        </svg>
                        
                        <div class="flex flex-col items-center justify-center z-10 relative mt-2">
                            <span class="text-4xl font-bold text-white tracking-tighter" id="speed-value">--</span>
                            <span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">Mbps</span>
                        </div>
                    </div>
                    
                    <button id="run-speedtest" class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 rounded-xl text-sm font-bold text-white transition-all shadow-[0_0_15px_rgba(59,130,246,0.3)] hover:shadow-[0_0_25px_rgba(59,130,246,0.5)] active:scale-95 flex items-center gap-2 w-full justify-center">
                        <i class="fas fa-play text-xs"></i> RUN TEST
                    </button>
                    <p id="speed-status" class="text-[10px] text-slate-500 mt-3 font-medium h-3">Ready to measure download speed</p>
                </div>
                
                <!-- Background Decoration -->
                <div class="absolute -bottom-10 -right-8 text-slate-800/40 transform -rotate-12 transition-transform group-hover:scale-110 duration-500">
                    <i class="fas fa-wifi text-[140px]"></i>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            
            <!-- Action Center (To-Do List) -->
            <div class="glass-card rounded-2xl border border-slate-700/50 flex flex-col h-full animate-fade-in-up delay-200">
                <div class="p-5 border-b border-slate-700/50 flex justify-between items-center bg-slate-800/30 rounded-t-2xl">
                    <h3 class="font-semibold text-white flex items-center gap-2">
                        <i class="fas fa-tasks text-indigo-400"></i> Action Center
                    </h3>
                    <span class="bg-indigo-500/20 text-indigo-300 text-xs px-2 py-1 rounded-md font-medium border border-indigo-500/20">
                        <?= ($stats['pending_emails'] > 0 ? 1 : 0) + ($stats['uncovered_shift'] > 0 ? 1 : 0) + ($stats['pending_ir'] > 0 ? 1 : 0) ?> Tasks
                    </span>
                </div>
                
                <div class="p-5 flex-1 overflow-y-auto custom-scrollbar max-h-[600px]">
                    <?php if ($totalPending == 0 && $criticalItems == 0): ?>
                        <div class="h-full flex flex-col items-center justify-center text-center p-6 opacity-60">
                            <i class="fas fa-mug-hot text-4xl text-slate-500 mb-3"></i>
                            <p class="text-sm text-slate-400">No pending actions.</p>
                            <p class="text-xs text-slate-500">You're all caught up for now!</p>
                        </div>
                    <?php else: ?>
                        <ul class="space-y-4">
                            <?php if ($stats['uncovered_shift'] > 0): ?>
                            <li class="flex gap-4 p-3 rounded-xl bg-rose-500/5 border border-rose-500/20">
                                <div class="mt-0.5"><i class="fas fa-exclamation-circle text-rose-500"></i></div>
                                <div>
                                    <p class="text-sm font-medium text-white">Cover Uncovered Shifts</p>
                                    <p class="text-xs text-slate-400 mt-1">There are <?= $stats['uncovered_shift'] ?> uncovered shifts for today that require immediate assignment.</p>
                                    <a href="attendance.php?tab=absenteeism" class="inline-block mt-2 text-xs font-medium text-rose-400 hover:text-rose-300">Resolve now &rarr;</a>
                                </div>
                            </li>
                            <?php endif; ?>

                            <?php if ($stats['pending_emails'] > 0): ?>
                            <li class="flex gap-4 p-3 rounded-xl hover:bg-slate-800/50 transition-colors border border-transparent hover:border-slate-700">
                                <div class="mt-0.5"><i class="fas fa-envelope text-blue-400"></i></div>
                                <div>
                                    <p class="text-sm font-medium text-white">Dispatch Pending Emails</p>
                                    <p class="text-xs text-slate-400 mt-1">You have <?= $stats['pending_emails'] ?> system emails waiting in the queue to be sent to agents.</p>
                                    <a href="attendance.php" class="inline-block mt-2 text-xs font-medium text-blue-400 hover:text-blue-300">View Queue &rarr;</a>
                                </div>
                            </li>
                            <?php endif; ?>

                            <?php if ($stats['pending_ir'] > 0): ?>
                            <li class="flex gap-4 p-3 rounded-xl hover:bg-slate-800/50 transition-colors border border-transparent hover:border-slate-700">
                                <div class="mt-0.5"><i class="fas fa-file-alt text-amber-400"></i></div>
                                <div>
                                    <p class="text-sm font-medium text-white">Process Incident Reports</p>
                                    <p class="text-xs text-slate-400 mt-1"><?= $stats['pending_ir'] ?> incidents (absenteeism/tardiness) lack finalized IR forms.</p>
                                    <a href="attendance.php" class="inline-block mt-2 text-xs font-medium text-amber-400 hover:text-amber-300">Process Forms &rarr;</a>
                                </div>
                            </li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activities (Timeline View) & MTD Goals -->
            <div class="lg:col-span-2 glass-card rounded-2xl border border-slate-700/50 flex flex-col h-full animate-fade-in-up delay-300 overflow-hidden">
                <div class="p-5 border-b border-slate-700/50 flex justify-between items-center bg-slate-800/30">
                    <h3 class="font-semibold text-white flex items-center gap-2">
                        <i class="fas fa-history text-slate-400"></i> Recent Activities
                    </h3>
                    
                    <!-- MTD Quick Gauges -->
                    <div class="flex items-center gap-4 hidden sm:flex">
                        <div class="text-right">
                            <p class="text-[10px] text-slate-400 uppercase tracking-wide">MTD Absenteeism</p>
                            <div class="flex items-center gap-2 mt-0.5">
                                <div class="w-24 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                    <?php $absPct = end($chartData['absenteeism_percentage']) ?: 0; ?>
                                    <div class="h-full bg-rose-500" style="width: <?= min(100, $absPct) ?>%"></div>
                                </div>
                                <span class="text-xs font-bold text-white"><?= $absPct ?>%</span>
                            </div>
                        </div>
                        <div class="w-px h-6 bg-slate-700"></div>
                        <div class="text-right">
                            <p class="text-[10px] text-slate-400 uppercase tracking-wide">MTD Tardiness</p>
                            <div class="flex items-center gap-2 mt-0.5">
                                <div class="w-24 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                    <?php $tardyPct = end($chartData['tardiness_percentage']) ?: 0; ?>
                                    <div class="h-full bg-amber-500" style="width: <?= min(100, $tardyPct) ?>%"></div>
                                </div>
                                <span class="text-xs font-bold text-white"><?= $tardyPct ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex-1 overflow-y-auto p-5 custom-scrollbar max-h-[600px]">
                    <?php if(empty($recentActivities)): ?>
                        <div class="h-full flex flex-col items-center justify-center text-center p-6 opacity-60">
                            <i class="fas fa-history text-4xl text-slate-500 mb-3"></i>
                            <p class="text-sm text-slate-400">No recent activities found in logs.</p>
                        </div>
                    <?php else: ?>
                        <div class="relative border-l border-slate-700/50 ml-4 space-y-5 pb-2 mt-1">
                            <?php foreach($recentActivities as $activity): 
                                // Generate Initials safely
                                $words = explode(' ', trim($activity['sub_name'] ?? 'U K'));
                                $initials = '';
                                foreach($words as $w) {
                                    if(strlen($w) > 0) $initials .= strtoupper($w[0]);
                                }
                                $initials = substr($initials, 0, 2);
                                if(empty($initials)) $initials = '??';
                                
                                // Format time as "Today, 10:30 AM" or "Oct 24, 10:30 AM"
                                $actTime = strtotime($activity['activity_time']);
                                $timeStr = (date('Y-m-d') == date('Y-m-d', $actTime)) 
                                    ? 'Today, ' . date('h:i A', $actTime) 
                                    : date('M d, h:i A', $actTime);
                            ?>
                            <div class="relative pl-6 group">
                                <span class="absolute -left-[17px] top-1 h-8 w-8 rounded-full bg-slate-800 border border-slate-600 flex items-center justify-center shadow-lg group-hover:border-indigo-500 group-hover:shadow-[0_0_10px_rgba(99,102,241,0.3)] transition-all z-10">
                                    <span class="text-[10px] font-bold text-slate-400 group-hover:text-indigo-400 transition-colors"><?= htmlspecialchars($initials) ?></span>
                                </span>
                                <div class="bg-slate-800/30 p-3.5 rounded-xl border border-slate-700/50 transition-colors hover:bg-slate-800/60 flex flex-col justify-center group-hover:border-indigo-500/30">
                                    <div class="flex justify-between items-center gap-4">
                                        <p class="text-sm text-slate-300 leading-relaxed flex-1">
                                            <span class="font-bold text-indigo-400"><?= htmlspecialchars($activity['sub_name']) ?></span> 
                                            <?= htmlspecialchars($activity['activity_description']) ?>
                                        </p>
                                        <span class="text-[11px] font-medium text-slate-500 whitespace-nowrap">
                                            <i class="far fa-clock mr-1"></i><?= $timeStr ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Historical Trend Data (Charts) -->
        <div class="glass-card rounded-2xl border border-slate-700/50 p-6 mb-8 animate-fade-in-up delay-400">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-indigo-500/20 flex items-center justify-center border border-indigo-500/30">
                        <i class="fas fa-chart-area text-indigo-400 text-sm"></i>
                    </div>
                    Workforce Analytics Trend
                </h3>
                
                <div class="relative">
                    <select id="timeRange" class="appearance-none bg-slate-800 border border-slate-600 text-slate-200 text-sm rounded-lg pl-4 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all shadow-sm cursor-pointer hover:bg-slate-700">
                        <option value="12months">Last 12 Months Overview</option>
                        <option value="30days" selected>Last 30 Days (Daily)</option>
                        <option value="7days">Last 7 Days (Daily)</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-slate-400">
                        <i class="fas fa-chevron-down text-xs"></i>
                    </div>
                </div>
            </div>
            
            <div class="relative h-[22rem] w-full">
                <canvas id="combinedChart"></canvas>
            </div>
        </div>

        <!-- Metric Breakdowns (Mini charts) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="glass-card rounded-2xl border border-slate-700/50 p-6 flex flex-col h-full animate-fade-in-up delay-100">
                <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-4">Absenteeism Summary</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                    <div class="bg-slate-800/40 rounded-xl p-3 border border-slate-700/30 text-center">
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1">Today</p>
                        <p class="text-2xl font-bold text-white"><?= $stats['absent_today'] ?></p>
                    </div>
                    <div class="bg-slate-800/40 rounded-xl p-3 border border-slate-700/30 text-center">
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1">Week</p>
                        <p class="text-2xl font-bold text-white"><?= $stats['absent_week'] ?></p>
                    </div>
                    <div class="bg-slate-800/40 rounded-xl p-3 border border-slate-700/30 text-center">
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1">Month</p>
                        <p class="text-2xl font-bold text-white"><?= $stats['absent_month'] ?></p>
                    </div>
                    <div class="bg-slate-800/40 rounded-xl p-3 border border-slate-700/30 text-center">
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1">Year</p>
                        <p class="text-2xl font-bold text-white"><?= $stats['absent_year'] ?></p>
                    </div>
                </div>
                <div class="relative h-48 mt-auto w-full"><canvas id="absenteeismChart"></canvas></div>
            </div>

            <div class="glass-card rounded-2xl border border-slate-700/50 p-6 flex flex-col h-full animate-fade-in-up delay-200">
                <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-4">Tardiness Summary</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                    <div class="bg-slate-800/40 rounded-xl p-3 border border-slate-700/30 text-center">
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1">Today</p>
                        <p class="text-2xl font-bold text-white"><?= $stats['late_today'] ?></p>
                    </div>
                    <div class="bg-slate-800/40 rounded-xl p-3 border border-slate-700/30 text-center">
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1">Week</p>
                        <p class="text-2xl font-bold text-white"><?= $stats['late_week'] ?></p>
                    </div>
                    <div class="bg-slate-800/40 rounded-xl p-3 border border-slate-700/30 text-center">
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1">Month</p>
                        <p class="text-2xl font-bold text-white"><?= $stats['late_month'] ?></p>
                    </div>
                    <div class="bg-slate-800/40 rounded-xl p-3 border border-slate-700/30 text-center">
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1">Year</p>
                        <p class="text-2xl font-bold text-white"><?= $stats['late_year'] ?></p>
                    </div>
                </div>
                <div class="relative h-48 mt-auto w-full"><canvas id="tardinessChart"></canvas></div>
            </div>
        </div>

        <div class="animate-fade-in-up delay-400">
            <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-4 ml-1">Module Quick Access</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <a href="attendance.php?tab=absenteeism" class="group relative bg-slate-800/80 border border-slate-700/50 p-4 rounded-xl flex flex-col items-center justify-center overflow-hidden hover:bg-slate-700 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
                    <div class="w-10 h-10 rounded-full bg-slate-900 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-bed text-rose-400"></i>
                    </div>
                    <span class="text-xs font-medium text-slate-300 group-hover:text-white">Absenteeism DB</span>
                </a>
                
                <a href="attendance.php?tab=tardiness" class="group relative bg-slate-800/80 border border-slate-700/50 p-4 rounded-xl flex flex-col items-center justify-center overflow-hidden hover:bg-slate-700 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
                    <div class="w-10 h-10 rounded-full bg-slate-900 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-clock text-amber-400"></i>
                    </div>
                    <span class="text-xs font-medium text-slate-300 group-hover:text-white">Tardiness DB</span>
                </a>
                
                <a href="attendance.php?tab=vto" class="group relative bg-slate-800/80 border border-slate-700/50 p-4 rounded-xl flex flex-col items-center justify-center overflow-hidden hover:bg-slate-700 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
                    <div class="w-10 h-10 rounded-full bg-slate-900 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-user-check text-emerald-400"></i>
                    </div>
                    <span class="text-xs font-medium text-slate-300 group-hover:text-white">VTO Logs</span>
                </a>
                
                <a href="users.php" class="group relative bg-slate-800/80 border border-slate-700/50 p-4 rounded-xl flex flex-col items-center justify-center overflow-hidden hover:bg-slate-700 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
                    <div class="w-10 h-10 rounded-full bg-slate-900 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-users-cog text-blue-400"></i>
                    </div>
                    <span class="text-xs font-medium text-slate-300 group-hover:text-white">System Users</span>
                </a>
                
                <a href="employees.php" class="group relative bg-slate-800/80 border border-slate-700/50 p-4 rounded-xl flex flex-col items-center justify-center overflow-hidden hover:bg-slate-700 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
                    <div class="w-10 h-10 rounded-full bg-slate-900 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-id-badge text-indigo-400"></i>
                    </div>
                    <span class="text-xs font-medium text-slate-300 group-hover:text-white">Agent Directory</span>
                </a>
            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.scale.grid.color = 'rgba(51, 65, 85, 0.3)';
    Chart.defaults.scale.grid.borderColor = 'rgba(51, 65, 85, 0.3)';

    function updateManilaClock() {
        const now = new Date();
        document.getElementById('realtime-clock').textContent = now.toLocaleTimeString('en-US', {
            timeZone: 'Asia/Manila', hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
    }
    updateManilaClock(); setInterval(updateManilaClock, 1000);

    document.addEventListener('DOMContentLoaded', function() {
        
        function createGradient(ctx, colorStart, colorEnd) {
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, colorStart);
            gradient.addColorStop(1, colorEnd);
            return gradient;
        }

        // Mini Absenteeism Chart
        const absentCtx = document.getElementById('absenteeismChart').getContext('2d');
        new Chart(absentCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartData['months']) ?>,
                datasets: [{
                    label: 'Absences',
                    data: <?= json_encode($chartData['absenteeism']) ?>,
                    backgroundColor: createGradient(absentCtx, 'rgba(244, 63, 94, 0.8)', 'rgba(244, 63, 94, 0.1)'),
                    borderColor: '#f43f5e', borderWidth: 1, borderRadius: 3
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)' } },
                scales: {
                    y: { beginAtZero: true, border: { display: false }, ticks: { precision: 0, font: {size: 10} } },
                    x: { grid: { display: false }, border: { display: false }, ticks: { font: {size: 10} } }
                }
            }
        });

        // Mini Tardiness Chart
        const tardyCtx = document.getElementById('tardinessChart').getContext('2d');
        new Chart(tardyCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartData['months']) ?>,
                datasets: [{
                    label: 'Tardy Count',
                    data: <?= json_encode($chartData['tardiness']) ?>,
                    backgroundColor: createGradient(tardyCtx, 'rgba(245, 158, 11, 0.8)', 'rgba(245, 158, 11, 0.1)'),
                    borderColor: '#f59e0b', borderWidth: 1, borderRadius: 3
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)' } },
                scales: {
                    y: { beginAtZero: true, border: { display: false }, ticks: { precision: 0, font: {size: 10} } },
                    x: { grid: { display: false }, border: { display: false }, ticks: { font: {size: 10} } }
                }
            }
        });

        // Main Combined Analytics Chart
        const combinedCtx = document.getElementById('combinedChart').getContext('2d');
        let combinedChart = new Chart(combinedCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartData['months']) ?>,
                datasets: [
                    {
                        label: 'Absenteeism Count',
                        data: <?= json_encode($chartData['absenteeism']) ?>,
                        borderColor: '#f43f5e',
                        backgroundColor: createGradient(combinedCtx, 'rgba(244, 63, 94, 0.4)', 'rgba(244, 63, 94, 0.0)'),
                        borderWidth: 3, tension: 0.4, fill: true,
                        pointBackgroundColor: '#0f172a', pointBorderColor: '#f43f5e',
                        pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6,
                        yAxisID: 'y-count'
                    },
                    {
                        label: 'Tardiness Count',
                        data: <?= json_encode($chartData['tardiness']) ?>,
                        borderColor: '#f59e0b',
                        backgroundColor: createGradient(combinedCtx, 'rgba(245, 158, 11, 0.4)', 'rgba(245, 158, 11, 0.0)'),
                        borderWidth: 3, tension: 0.4, fill: true,
                        pointBackgroundColor: '#0f172a', pointBorderColor: '#f59e0b',
                        pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6,
                        yAxisID: 'y-count'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        labels: { color: '#e2e8f0', usePointStyle: true, padding: 20 }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleColor: '#f8fafc', bodyColor: '#cbd5e1',
                        padding: 12, cornerRadius: 8, usePointStyle: true
                    }
                },
                scales: {
                    'y-count': {
                        type: 'linear', display: true, position: 'left', beginAtZero: true,
                        border: { display: false }
                    },
                    x: { grid: { display: false }, border: { display: false } }
                }
            }
        });

        // Dynamic Fetcher
        fetchChartData('30days');
        document.getElementById('timeRange').addEventListener('change', function() {
            fetchChartData(this.value);
        });

        function fetchChartData(timeRange) {
            fetch(`../includes/fetch_chart_data.php?range=${timeRange}`)
                .then(response => response.ok ? response.json() : Promise.reject('Network error'))
                .then(data => { if (!data.error) updateChart(combinedChart, data); })
                .catch(error => console.error('Error fetching data:', error));
        }

        function updateChart(chart, newData) {
            chart.data.labels = newData.labels;
            chart.data.datasets[0].data = newData.absenteeism;
            chart.data.datasets[1].data = newData.tardiness;
            
            const isDaily = document.getElementById('timeRange').value !== '12months';
            chart.options.scales.x.ticks.maxRotation = isDaily ? 45 : 0;
            chart.options.scales.x.ticks.autoSkip = isDaily;
            chart.update();
        }

        // --- Network Latency Monitor Logic ---
        function updateNetworkInfo() {
            const infoEl = document.getElementById('network-info');
            if (navigator.connection) {
                const conn = navigator.connection;
                const type = (conn.effectiveType || 'unknown').toUpperCase();
                const dl = conn.downlink ? `${conn.downlink} Mbps estimated max` : '';
                const rtt = conn.rtt ? ` | ~${conn.rtt}ms RTT` : '';
                infoEl.innerHTML = `<i class="fas fa-wifi text-slate-500 mr-1"></i> Connection Details: <strong>${type}</strong> ${dl ? '(' + dl + ')' : ''} ${rtt}`;
            } else {
                infoEl.innerHTML = `<i class="fas fa-globe text-slate-500 mr-1"></i> Connection Details: Active (Advanced API unsupported by browser)`;
            }
        }
        
        updateNetworkInfo();
        if (navigator.connection) {
            navigator.connection.addEventListener('change', updateNetworkInfo);
        }

        const maxDataPoints = 60; // Increased for a higher resolution, smoother flow
        let pingData = Array(maxDataPoints).fill(0);
        let pingLabels = Array(maxDataPoints).fill('');

        const latencyCtx = document.getElementById('latencyChart').getContext('2d');
        const latencyChart = new Chart(latencyCtx, {
            type: 'line',
            data: {
                labels: pingLabels,
                datasets: [{
                    label: 'Latency (ms)',
                    data: pingData,
                    borderColor: '#10b981', // emerald-500
                    backgroundColor: createGradient(latencyCtx, 'rgba(16, 185, 129, 0.4)', 'rgba(16, 185, 129, 0.0)'),
                    borderWidth: 2,
                    tension: 0.3, // Smoothed tension for better scrolling aesthetics
                    fill: true,
                    pointRadius: 0, // hide dots for a smooth task-manager feel
                    pointHoverRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1000, // Matches ping interval exactly to create a continuous scroll effect
                    easing: 'linear'
                },
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        suggestedMax: 100, 
                        border: { display: false }, 
                        grid: { color: 'rgba(51, 65, 85, 0.2)' },
                        ticks: { color: '#64748b', maxTicksLimit: 5, font: {size: 10} } 
                    },
                    x: { display: false } // Hide x-axis completely
                }
            }
        });

        async function measureLatency() {
            const start = performance.now();
            try {
                // Fetch current page's headers to simulate a ping to the application server
                // Use URL object to correctly append query parameters without malforming the URL
                const pingUrl = new URL(window.location.href);
                pingUrl.searchParams.set('ping', Date.now());
                
                await fetch(pingUrl.toString(), { 
                    method: 'HEAD', 
                    cache: 'no-store' 
                });
                const latency = Math.round(performance.now() - start);
                updateLatencyUI(latency);
            } catch(e) {
                updateLatencyUI(0, true); // Error state
            }
        }

        function updateLatencyUI(latency, isError = false) {
            const pingLabel = document.getElementById('current-ping');
            
            if (isError) {
                pingLabel.innerText = 'ERR';
                pingLabel.className = 'text-xs font-mono bg-rose-500/10 text-rose-400 px-3 py-1 rounded-md border border-rose-500/20';
                pingData.push(0);
            } else {
                pingLabel.innerText = latency + ' ms';
                pingData.push(latency);
                
                // Color coding based on latency thresholds
                let color = '#10b981'; let bgStart = 'rgba(16, 185, 129, 0.4)'; let bgEnd = 'rgba(16, 185, 129, 0.0)'; // Good
                
                if (latency > 250) {
                    // Poor (Red)
                    color = '#f43f5e'; bgStart = 'rgba(244, 63, 94, 0.4)'; bgEnd = 'rgba(244, 63, 94, 0.0)';
                    pingLabel.className = 'text-xs font-mono bg-rose-500/10 text-rose-400 px-3 py-1 rounded-md border border-rose-500/20 shadow-[0_0_10px_rgba(244,63,94,0.2)]';
                } else if (latency > 100) {
                    // Warning (Amber)
                    color = '#f59e0b'; bgStart = 'rgba(245, 158, 11, 0.4)'; bgEnd = 'rgba(245, 158, 11, 0.0)';
                    pingLabel.className = 'text-xs font-mono bg-amber-500/10 text-amber-400 px-3 py-1 rounded-md border border-amber-500/20 shadow-[0_0_10px_rgba(245,158,11,0.2)]';
                } else {
                    pingLabel.className = 'text-xs font-mono bg-emerald-500/10 text-emerald-400 px-3 py-1 rounded-md border border-emerald-500/20 shadow-[0_0_10px_rgba(16,185,129,0.2)]';
                }

                latencyChart.data.datasets[0].borderColor = color;
                latencyChart.data.datasets[0].backgroundColor = createGradient(latencyCtx, bgStart, bgEnd);
            }

            pingData.shift(); // Remove oldest data point to scroll graph
            latencyChart.update('active'); // Use active mode for smooth transitioning
        }

        // Fire latency ping every 1 second (1000ms) for smoother continuous feed
        setInterval(measureLatency, 1000);
        measureLatency(); // Initial fire

        // --- Live Gauge Speedtest Logic ---
        const speedBtn = document.getElementById('run-speedtest');
        const speedVal = document.getElementById('speed-value');
        const speedGaugeProgress = document.getElementById('speed-gauge-progress');
        const speedStatus = document.getElementById('speed-status');

        function updateGaugeUI(speedMbps) {
            speedVal.innerText = speedMbps;
            // Cap visual gauge rotation at 100 Mbps for scaling purposes
            const maxGauge = 100; 
            const percent = Math.min(parseFloat(speedMbps) / maxGauge, 1);
            // 276 is the total stroke-dasharray (circumference of r=44 circle)
            const offset = 276 - (276 * percent); 
            speedGaugeProgress.style.strokeDashoffset = offset;
        }

        speedBtn.addEventListener('click', async () => {
            // UI Loading State
            speedBtn.disabled = true;
            speedBtn.innerHTML = '<i class="fas fa-spinner fa-spin text-xs"></i> MEASURING...';
            speedBtn.classList.replace('from-blue-600', 'from-slate-600');
            speedBtn.classList.replace('to-indigo-600', 'to-slate-700');
            speedStatus.innerText = 'Connecting to test server...';
            updateGaugeUI(0);
            
            const startTime = performance.now();
            let totalReceived = 0;
            let lastUpdate = startTime;
            
            // Set up a 5-second hard limit
            const TEST_DURATION = 5000; 
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), TEST_DURATION);

            try {
                speedStatus.innerText = 'Measuring download speed...';
                
                // Loop in case a fast connection downloads the payload before 5 seconds
                while (performance.now() - startTime < TEST_DURATION) {
                    const cacheBuster = "?bypass=" + Math.random();
                    // Using a dummy file from Wikimedia
                    const testUrl = "https://upload.wikimedia.org/wikipedia/commons/2/2d/Snake_River_%285mb%29.jpg" + cacheBuster;
                    
                    const response = await fetch(testUrl, { signal: controller.signal });
                    const reader = response.body.getReader();

                    while(true) {
                        const {done, value} = await reader.read();
                        if (done) break;
                        
                        totalReceived += value.length;
                        const now = performance.now();
                        
                        // Update UI gauge every 100ms
                        if (now - lastUpdate > 100) {
                            const durationSeconds = (now - startTime) / 1000;
                            const speedBps = (totalReceived * 8) / durationSeconds;
                            const speedMbps = (speedBps / (1024 * 1024)).toFixed(1);
                            updateGaugeUI(speedMbps);
                            lastUpdate = now;
                        }
                    }
                }
            } catch (error) {
                // Ignore AbortError as it is an expected part of the 5-second timer
                if (error.name !== 'AbortError') {
                    speedVal.innerText = 'ERR';
                    speedStatus.innerText = 'Connection blocked or failed.';
                    speedGaugeProgress.style.strokeDashoffset = 276;
                    resetSpeedtestBtn();
                    clearTimeout(timeoutId);
                    return;
                }
            }
            
            clearTimeout(timeoutId);
            
            // Final Calculation
            const endTime = performance.now();
            const actualDuration = (endTime - startTime) / 1000;
            // Cap duration at 5 seconds for calculation if it aborted, otherwise use actual
            const calcDuration = Math.min(actualDuration, TEST_DURATION / 1000); 
            
            const finalSpeedMbps = ((totalReceived * 8) / calcDuration / (1024 * 1024)).toFixed(1);
            
            updateGaugeUI(finalSpeedMbps);
            speedStatus.innerText = `Test completed in ${calcDuration.toFixed(1)}s`;
            resetSpeedtestBtn();
        });

        function resetSpeedtestBtn() {
            speedBtn.disabled = false;
            speedBtn.innerHTML = '<i class="fas fa-redo text-xs"></i> TEST AGAIN';
            speedBtn.classList.replace('from-slate-600', 'from-blue-600');
            speedBtn.classList.replace('to-slate-700', 'to-indigo-600');
        }

    });
</script>

<?php renderFooter(); ?>