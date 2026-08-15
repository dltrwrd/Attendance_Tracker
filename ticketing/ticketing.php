<?php

include('../connection.php');
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database Connection Error");
}

// --- AJAX HANDLER FOR FETCHING EMPLOYEE DETAILS ---
if (isset($_POST['action']) && $_POST['action'] === 'fetch_employee_details') {
    header('Content-Type: application/json');
    $eid = trim($_POST['eid'] ?? '');
    
    if (empty($eid)) {
        echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = :eid");
        $stmt->execute(['eid' => $eid]);
        $emp = $stmt->fetch();

        if ($emp) {
            echo json_encode(['success' => true, 'data' => $emp]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Employee not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

include_once('submit_ticket.php');

date_default_timezone_set('Asia/Manila');
$timenow = date('g:i A');
$datenow = date('F d, Y');
$timestamp = date('n/j/Y H:i:s');

$foundEmployees = [];
$notFoundIds = [];
$error = null;
$successMessage = null;
$showSelfTicketWarning = false; // Professional confirmation flag

$currentDate = new DateTime();
$currentDate->modify('this week monday');
$mondayDate = $currentDate->format('m/d/Y');

// Check for success parameter in URL
if (isset($_GET['success'])) {
    $msg = $_GET['success'];
    // Modified to handle alphanumeric Work_Numbers instead of just numeric IDs
    if (strpos($msg, 'tickets have been successfully submitted') !== false) {
        $successMessage = htmlspecialchars($msg);
    } else {
        $successMessage = "Ticket submitted successfully! Ticket ID #". htmlspecialchars($msg) . ".";
    }
}

// --- PHASE 1: SEARCH EMPLOYEES ---
if (isset($_POST['submitEID'])) {
    $eids = $_POST['eid'] ?? [];
    
    if (!is_array($eids)) {
        $eids = [$eids];
    }

    $eids = array_filter(array_map('trim', $eids));
    $eids = array_map('strtoupper', $eids);
    $eids = array_unique($eids);

    if (empty($eids)) {
        $error = "Please enter at least one Employee ID.";
    } else {
        if (count($eids) === 1) {
            $singleEid = reset($eids);
            $stmtSlt = $pdo->prepare("SELECT username FROM users WHERE username = :eid");
            $stmtSlt->execute(['eid' => $singleEid]);
            if ($stmtSlt->fetch()) {
                // Trigger the professional warning modal
                $showSelfTicketWarning = true;
            }
        }

        foreach ($eids as $eid) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = :eid");
                $stmt->execute(['eid' => $eid]);
                $emp = $stmt->fetch();

                if ($emp) {
                    $foundEmployees[] = $emp;
                } else {
                    $notFoundIds[] = $eid;
                }
            } catch (PDOException $e) {
                error_log("Error fetching EID $eid: " . $e->getMessage());
            }
        }

        if (empty($foundEmployees)) {
            $error = "No valid employees found. Please check the IDs.";
        } elseif (!empty($notFoundIds)) {
            $error = "Some IDs were not found: " . implode(', ', $notFoundIds);
        }
    }
}

// --- PHASE 2: SUBMIT TICKETS ---
if (isset($_POST['submitTix'])) {
    
    $targetEidsJson = $_POST['target_employee_ids'] ?? '[]';
    $targetEids = json_decode($targetEidsJson, true);
    
    if (is_array($targetEids)) {
        $targetEids = array_unique($targetEids);
    }

    if (empty($targetEids)) {
        $error = "Session expired or no employees selected. Please search again.";
    } else {
        $stationNumber = trim($_POST['Station_Number'] ?? '');
        $issue = trim($_POST['Issues_Concerning'] ?? '');
        $subCat = trim($_POST['sub_cat'] ?? '');
        $issueDetails = trim($_POST['Issue_Details'] ?? ''); 
        $site = trim($_POST['Site'] ?? '');
        $urgency = trim($_POST['urgency'] ?? '');

        $imgPath = null;
        if (isset($_FILES['issue_img']) && $_FILES['issue_img']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['issue_img']['tmp_name'];
            $fileName = $_FILES['issue_img']['name'];
            
            $check = getimagesize($fileTmpPath);
            if($check !== false) {
                $uploadDir = 'medias/'; 
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
                $newFileName = 'ticket_' . time() . '_' . uniqid() . '.' . $fileExtension;
                $destPath = $uploadDir . $newFileName;

                if(move_uploaded_file($fileTmpPath, $destPath)) {
                    $imgPath = $destPath; 
                } else {
                    $error = "There was an error moving the uploaded file.";
                }
            } else {
                $error = "File is not a valid image.";
            }
        }

        if (empty($stationNumber) || empty($issue) || empty($site) || empty($urgency) || empty($issueDetails)) {
            $error = "Please fill in all required ticket details.";
             foreach ($targetEids as $tid) {
                 $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = :eid");
                 $stmt->execute(['eid' => $tid]);
                 if($emp = $stmt->fetch()) $foundEmployees[] = $emp;
             }
        } elseif (!$error) { 
            $successCount = 0;
            $lastTicketId = 0;

            foreach ($targetEids as $targetId) {
                $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = :eid");
                $stmt->execute(['eid' => $targetId]);
                $empDetails = $stmt->fetch();

                if ($empDetails) {
                    $ticketData = [
                        'Station_Number' => $stationNumber,
                        'Issues_Concerning' => $issue,
                        'sub_cat' => $subCat,
                        'Issue_Details' => $issueDetails, 
                        'Site' => $site,
                        'urgency' => $urgency,
                        'Timestamp' => $timestamp,
                        'EID' => $empDetails['employee_id'] ?? 'N/A',
                        'Affected_employee' => 'Individual', 
                        'Employee_name' => $empDetails['full_name'] ?? 'N/A',
                        'Email_Address' => $empDetails['email'] ?? 'N/A',
                        'LOB' => $empDetails['department'] ?? 'N/A',
                        'OM' => $empDetails['operation_manager'] ?? 'N/A',
                        'TIME_RECEIVED' => $timenow,
                        'TIME_RESOLVED' => 'PENDING',
                        'SLT_on_DUTY' => 'PENDING',
                        'Week_Beginning' => $mondayDate,
                        'Status' => 'PENDING',
                        'issue_img' => $imgPath 
                    ];

                    if (function_exists('submit_ticket')) {
                        if (submit_ticket($pdo, $ticketData) === true) {
                            $successCount++;
                            $insertedId = $pdo->lastInsertId();
                            $lastTicketId = $insertedId; // Fallback to raw ID
                            
                            // Attempt to fetch the Work_Number field from the database based on inserted ID
                            try {
                                $stmtWN = $pdo->prepare("SELECT Work_Number FROM ticket WHERE id = :id");
                                $stmtWN->execute(['id' => $insertedId]);
                                if ($wn = $stmtWN->fetchColumn()) {
                                    $lastTicketId = $wn;
                                }
                            } catch (PDOException $e) {
                                // Silently fail and fallback to $insertedId if there is a database error
                            }
                        }
                    }
                }
            }

            if ($successCount > 0) {
                $redirectMsg = ($successCount > 1) 
                    ? "$successCount tickets have been successfully submitted." 
                    : $lastTicketId;
                
                header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($redirectMsg));
                exit();
            } else {
                $error = "Failed to submit tickets. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Ticket | CXI Services Inc.</title>
    <link rel="icon" href="assets/cxiico.png" type="image/png">
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
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.3s ease-out',
                        'slide-down': 'slideDown 0.3s ease-out forwards',
                        'pop-in': 'popIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0', transform: 'translateY(-10px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        slideDown: {
                            '0%': { opacity: '0', transform: 'translateY(-10px)', maxHeight: '0' },
                            '100%': { opacity: '1', transform: 'translateY(0)', maxHeight: '500px' }
                        },
                        popIn: {
                            '0%': { opacity: '0', transform: 'scale(0.8) translateY(20px)' },
                            '100%': { opacity: '1', transform: 'scale(1) translateY(0)' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .bg-auth {
            background-image: linear-gradient(to right, rgba(15, 23, 42, 0.95), rgba(15, 23, 42, 0.90)), 
                              url('https://source.unsplash.com/random/1920x1080/?technology,server');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
        .custom-scroll::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 3px; }
        .employee-card:hover .delete-btn { opacity: 1; }
        .drop-zone { transition: all 0.3s ease; }
        .drop-zone.dragover { border-color: #0ea5e9; background-color: rgba(14, 165, 233, 0.1); }
        [color-scheme="dark"]::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.6; cursor: pointer; }
        .glass-panel {
            background: rgba(31, 41, 55, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-auth min-h-screen flex flex-col items-center justify-center p-4 font-sans text-gray-200">
    
    <div class="w-full max-w-2xl animate-fade-in my-8">
        <div class="bg-gray-800/60 backdrop-blur-md rounded-2xl shadow-2xl border border-gray-700/50">
            
            <div class="p-8 border-b border-gray-700/50 relative overflow-hidden rounded-t-2xl">
                <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-primary-500 via-purple-500 to-primary-500"></div>
                <div class="flex justify-center mb-6">
                     <div class="bg-white/10 p-4 rounded-full shadow-lg shadow-primary-900/20 backdrop-blur-sm border border-white/5">
                        <img src="assets/cxiico.png" alt="CXI Logo" class="w-16 h-16 object-contain filter drop-shadow-md">
                     </div>
                </div>
                <h2 class="text-white text-center text-3xl font-bold tracking-tight mb-2">SLT Ticketing</h2>
                <p class="text-center text-gray-400 text-sm">Submit tickets for yourself or on behalf of your team.</p>
            </div>

            <div class="p-8">
                
                <!-- VISUAL WIZARD / STEPPER -->
                <div class="mb-10 w-full flex items-center justify-center relative">
                    <div class="flex items-center justify-between w-full max-w-sm relative z-10">
                        <!-- Step 1 -->
                        <div class="flex flex-col items-center gap-2">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold border-2 transition-all duration-300 <?= empty($foundEmployees) ? 'bg-primary-600 border-primary-500 text-white shadow-[0_0_15px_rgba(14,165,233,0.4)]' : 'bg-gray-800 border-primary-500 text-primary-400' ?>">
                                <?= empty($foundEmployees) ? '1' : '<i class="fas fa-check"></i>' ?>
                            </div>
                            <span class="text-xs font-bold uppercase tracking-wider <?= empty($foundEmployees) ? 'text-primary-400' : 'text-gray-400' ?>">Identify</span>
                        </div>
                        
                        <!-- Line -->
                        <div class="flex-1 h-1 mx-4 bg-gray-700 rounded-full relative overflow-hidden mt-[-20px]">
                            <div class="absolute top-0 left-0 h-full bg-primary-500 transition-all duration-500" style="width: <?= empty($foundEmployees) ? '0%' : '100%' ?>"></div>
                        </div>
                        
                        <!-- Step 2 -->
                        <div class="flex flex-col items-center gap-2">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold border-2 transition-all duration-300 <?= empty($foundEmployees) ? 'bg-gray-800 border-gray-700 text-gray-600' : 'bg-primary-600 border-primary-500 text-white shadow-[0_0_15px_rgba(14,165,233,0.4)]' ?>">
                                2
                            </div>
                            <span class="text-xs font-bold uppercase tracking-wider <?= empty($foundEmployees) ? 'text-gray-600' : 'text-primary-400' ?>">Details</span>
                        </div>
                    </div>
                </div>

                <!-- ALERTS -->
                <?php if ($successMessage): ?>
                    <div id="successAlert" class="bg-green-500/20 border border-green-500/40 text-green-200 px-4 py-4 rounded-xl mb-6 flex items-start gap-3 shadow-lg shadow-green-900/20 animate-fade-in">
                        <i class="fas fa-check-circle mt-1 text-green-400"></i>
                        <div class="flex-1">
                            <p class="font-semibold">Success</p>
                            <p class="text-sm opacity-90"><?= $successMessage ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div id="errorAlert" class="bg-red-500/20 border border-red-500/40 text-red-200 px-4 py-4 rounded-xl mb-6 flex items-start gap-3 shadow-lg shadow-red-900/20 animate-fade-in relative pr-10">
                        <i class="fas fa-exclamation-triangle mt-1 text-red-400"></i>
                        <div class="flex-1">
                            <p class="font-semibold">Attention Needed</p>
                            <p class="text-sm opacity-90"><?= htmlspecialchars($error) ?></p>
                        </div>
                        <button onclick="document.getElementById('errorAlert').style.display='none'" type="button" class="absolute top-4 right-4 text-red-400 hover:text-red-200 transition-colors p-1" title="Dismiss">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if (empty($foundEmployees)): ?>
                    <form method="POST" class="space-y-6" id="searchForm">
                        <input type="hidden" name="submitEID" value="1">
                        <div class="text-center mb-6">
                            <h3 class="text-lg font-medium text-white">Identify Employee(s)</h3>
                            <p class="text-xs text-gray-400">Search by Name or enter Employee ID.</p>
                        </div>

                        <div id="eid-container" class="space-y-3">
                            <div class="eid-row relative group">
                                <label class="text-xs font-semibold text-gray-400 uppercase tracking-wider ml-1 mb-1 block">Employee Search</label>
                                <div class="flex gap-2 relative">
                                    <div class="relative w-full">
                                        <input type="text" name="eid[]" class="employee-search-input w-full pl-10 pr-4 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-primary-500 outline-none transition-all uppercase" placeholder="e.g., JUAN DELA CRUZ or CXI0001" required autocomplete="off">
                                        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                            <i class="fas fa-search text-gray-500"></i>
                                        </div>
                                        
                                        <!-- Search Results Dropdown -->
                                        <div class="employee-search-results hidden absolute top-full left-0 z-50 mt-1 w-full glass-panel border border-gray-600/50 rounded-xl shadow-2xl overflow-hidden max-h-60 overflow-y-auto custom-scroll">
                                        </div>
                                    </div>
                                    <button type="button" onclick="addEidField()" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-primary-400 border border-gray-600 rounded-lg transition-colors" title="Add another Employee">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="group w-full py-3.5 px-4 bg-primary-600 hover:bg-primary-500 text-white font-bold rounded-xl shadow-lg shadow-primary-900/50 transition-all duration-200 flex items-center justify-center gap-2 transform hover:-translate-y-0.5">
                                <span class="btn-text">Continue to Ticket</span>
                                <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform btn-icon"></i>
                            </button>
                        </div>
                    </form>

                <?php else: ?>
                    
                    <div class="mb-8">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white font-semibold flex items-center gap-2">
                                <span class="bg-primary-500 text-xs px-2 py-0.5 rounded-full text-white" id="count-display"><?= count($foundEmployees) ?></span>
                                Selected Employee(s)
                            </h3>
                            <button type="button" onclick="openAddModal()" class="text-xs flex items-center gap-1 bg-primary-600/20 hover:bg-primary-600/40 text-primary-300 px-3 py-1.5 rounded-lg border border-primary-500/30 transition-all">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>
                        
                        <!-- Fixed Mobile Ergonomics: sm:max-h-60 but max-h-40 on mobile -->
                        <div id="selected-employees-grid" class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-40 sm:max-h-60 overflow-y-auto custom-scroll pr-1">
                            <?php foreach ($foundEmployees as $emp): ?>
                            <div class="employee-card bg-gray-700/40 border border-gray-600/50 rounded-lg flex items-stretch gap-0 relative group transition-all overflow-hidden" data-id="<?= htmlspecialchars($emp['employee_id']) ?>">
                                <div class="p-3 flex items-center gap-3 flex-1 min-w-0">
                                    <div class="h-10 w-10 rounded-full bg-gradient-to-br from-gray-600 to-gray-700 flex items-center justify-center text-gray-300 font-bold text-sm shrink-0">
                                        <?= substr($emp['full_name'] ?? 'U', 0, 1) ?>
                                    </div>
                                    <div class="overflow-hidden">
                                        <h4 class="text-sm font-semibold text-white truncate"><?= htmlspecialchars($emp['full_name']) ?></h4>
                                        <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($emp['employee_id']) ?> • <?= htmlspecialchars($emp['department']) ?></p>
                                    </div>
                                </div>
                                <button type="button" onclick="removeEmployee(this, '<?= htmlspecialchars($emp['employee_id']) ?>')" class="delete-btn w-10 bg-red-500/10 hover:bg-red-500 text-red-400 hover:text-white flex items-center justify-center border-l border-gray-600/50 transition-colors">
                                    <i class="fas fa-trash-alt text-xs"></i>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <hr class="border-gray-700/50 mb-8">

                    <form id="ticketForm" method="POST" enctype="multipart/form-data" class="space-y-5">
                        <input type="hidden" name="submitTix" value="1">
                        <input type="hidden" id="target_employee_ids" name="target_employee_ids" value='<?= json_encode(array_column($foundEmployees, 'employee_id')) ?>'>
                        <input type="hidden" name="urgency" id="urgency" value="">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label for="site" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Site Location</label>
                                <div class="relative">
                                    <select class="w-full pl-4 pr-10 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-gray-200 focus:ring-2 focus:ring-primary-500 outline-none appearance-none transition" id="site" name="Site" required>
                                        <option value="" selected disabled>Select Site...</option>
                                        <option value="KAWIT">KAWIT</option>
                                        <option value="BACOOR">BACOOR</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label for="stationNumber" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Station Number</label>
                                <input class="w-full px-4 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-gray-200 focus:ring-2 focus:ring-primary-500 outline-none transition uppercase" type="text" id="stationNumber" name="Station_Number" placeholder="e.g., STN-105" required>
                            </div>
                        </div>

                        <div>
                            <label for="issues" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Issue Category</label>
                            <div class="relative">
                                <select class="w-full pl-4 pr-10 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-gray-200 focus:ring-2 focus:ring-primary-500 outline-none appearance-none transition" id="issues" name="Issues_Concerning" required>
                                    <option value="" selected disabled>What seems to be the problem?</option>
                                    <optgroup label="Hardware">
                                        <option value="Keyboard">Keyboard Malfunction</option>
                                        <option value="Mouse">Mouse Malfunction</option>
                                        <option value="Headset">Headset / Audio Issue</option>
                                        <option value="Monitor">Monitor / Display Issue</option>
                                    </optgroup>
                                    <optgroup label="System & Network">
                                        <option value="Internet">Internet Connectivity</option>
                                        <option value="NT Login Issue">NT Login / Account Locked</option>
                                        <option value="Client Tool/System Issue">Client Tool / App Crash</option>
                                        <option value="Windows tools error">Windows OS Error</option>
                                        <option value="Full Storage">Full Disk Storage</option>
                                    </optgroup>
                                    <optgroup label="Other Concerns">
                                        <option value="HRIS Concern">HRIS Concern</option>
                                        <option value="Other">Other Inquiry</option>
                                    </optgroup>
                                </select>
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                            </div>
                            
                            <!-- Transparency in Priority Assignment -->
                            <div class="flex items-center justify-between mt-2 min-h-[24px]">
                                <div class="flex items-center gap-2">
                                    <div id="urgency-display" class="text-xs font-bold px-2 py-1 rounded bg-gray-700 text-gray-300 hidden"></div>
                                    <i id="urgency-info" class="fas fa-info-circle text-gray-500 hover:text-gray-300 text-xs cursor-help hidden" title="Priority is automatically assigned based on the issue category to ensure critical system down events are handled first."></i>
                                </div>
                            </div>
                            <div id="troubleshootingTip" class="mt-2 p-3 text-sm bg-blue-900/30 border border-blue-500/30 text-blue-200 rounded-lg hidden animate-fade-in"></div>

                            <!-- HRIS SPECIFIC WORKFLOW -->
                            <div id="hrisFlowContainer" class="hidden mt-3 p-4 bg-gray-800/80 border border-gray-600/80 rounded-xl shadow-inner space-y-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Specific HRIS Concern</label>
                                    <select id="hrisSubCategory" name="sub_cat" class="w-full px-3 py-2 bg-gray-900/50 border border-gray-600 rounded-lg text-gray-200 focus:ring-2 focus:ring-primary-500 outline-none appearance-none transition">
                                        <option value="" selected disabled>Select issue type...</option>
                                        <option value="Other">Other Concerns</option>
                                        <option value="Schedule">Schedule Concerns</option>
                                        <option value="Attendance">Attendance Concerns</option>
                                    </select>
                                </div>

                                <!-- Schedule Concerns Flow -->
                                <div id="hrisScheduleFlow" class="hidden space-y-4 animate-fade-in">
                                    <div class="space-y-2">
                                        <p class="text-sm font-medium text-gray-300">No Plotted Schedule?</p>
                                        <p class="text-xs text-yellow-400/90 italic mb-2 bg-yellow-900/20 border border-yellow-700/50 p-2 rounded">
                                            <i class="fas fa-exclamation-circle mr-1"></i> <strong>Note:</strong> Make sure to specify the date and the time of your OT/Shift/Rest Day in the details box below.
                                        </p>
                                        <div class="flex flex-col gap-2 bg-gray-900/30 p-3 rounded-lg border border-gray-700">
                                            <label class="flex items-center gap-2 cursor-pointer group">
                                                <input type="radio" name="sched_type" value="shift" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                                <span class="text-sm text-gray-300 group-hover:text-white transition">I have a shift for</span>
                                                <input type="date" id="date_shift" disabled class="px-2 py-1 bg-gray-900 border border-gray-600 rounded text-sm text-gray-200 disabled:opacity-50 disabled:cursor-not-allowed outline-none focus:border-primary-500 [color-scheme:dark]">
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer group">
                                                <input type="radio" name="sched_type" value="ot" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                                <span class="text-sm text-gray-300 group-hover:text-white transition">I have an OT for</span>
                                                <input type="date" id="date_ot" disabled class="px-2 py-1 bg-gray-900 border border-gray-600 rounded text-sm text-gray-200 disabled:opacity-50 disabled:cursor-not-allowed outline-none focus:border-primary-500 [color-scheme:dark]">
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer group">
                                                <input type="radio" name="sched_type" value="rest" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                                <span class="text-sm text-gray-300 group-hover:text-white transition">It's my Rest Day on</span>
                                                <input type="date" id="date_rest" disabled class="px-2 py-1 bg-gray-900 border border-gray-600 rounded text-sm text-gray-200 disabled:opacity-50 disabled:cursor-not-allowed outline-none focus:border-primary-500 [color-scheme:dark]">
                                            </label>
                                        </div>
                                    </div>

                                    <div id="hrisTLQuestion" class="hidden space-y-2 animate-fade-in">
                                        <p class="text-sm font-medium text-gray-300">Have you informed your Team Leader (TL)?</p>
                                        <div class="flex gap-4">
                                            <label class="flex items-center gap-2 cursor-pointer group">
                                                <input type="radio" name="tl_informed" value="yes" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                                <span class="text-sm text-gray-300 group-hover:text-white">Yes</span>
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer group">
                                                <input type="radio" name="tl_informed" value="no" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                                <span class="text-sm text-gray-300 group-hover:text-white">No</span>
                                            </label>
                                        </div>
                                        <div id="tlMessageContainer" class="mt-2 text-sm hidden"></div>
                                    </div>
                                </div>

                                <!-- Attendance Concerns Flow -->
                                <div id="hrisAttendanceFlow" class="hidden space-y-4 animate-fade-in">
                                    <p class="text-sm font-medium text-gray-300">Tell us what you see:</p>
                                    <div class="flex flex-col gap-2 bg-gray-900/30 p-3 rounded-lg border border-gray-700">
                                        <label class="flex items-center gap-2 cursor-pointer group">
                                            <input type="radio" name="att_type" value="no_sched" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                            <span class="text-sm text-gray-300 group-hover:text-white transition">I don't have a plotted schedule for today.</span>
                                        </label>
                                        <label class="flex items-center gap-2 cursor-pointer group">
                                            <input type="radio" name="att_type" value="bug" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                            <span class="text-sm text-gray-300 group-hover:text-white transition">I think it's a system bug.</span>
                                        </label>
                                    </div>

                                    <div id="hrisFloatQuestion" class="hidden space-y-2 animate-fade-in mt-4">
                                        <p class="text-sm font-medium text-gray-300">Have you timed in as float already?</p>
                                        <div class="flex gap-4">
                                            <label class="flex items-center gap-2 cursor-pointer group">
                                                <input type="radio" name="att_float" value="yes" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                                <span class="text-sm text-gray-300 group-hover:text-white">Yes</span>
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer group">
                                                <input type="radio" name="att_float" value="no" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                                <span class="text-sm text-gray-300 group-hover:text-white">No</span>
                                            </label>
                                        </div>
                                        <div id="floatMessageContainer" class="mt-2 text-sm hidden"></div>
                                    </div>

                                    <div id="hrisAttTLQuestion" class="hidden space-y-2 animate-fade-in mt-4">
                                        <p class="text-sm font-medium text-gray-300">Have you informed your Team Leader (TL) regarding this?</p>
                                        <div class="flex gap-4">
                                            <label class="flex items-center gap-2 cursor-pointer group">
                                                <input type="radio" name="att_tl_informed" value="yes" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                                <span class="text-sm text-gray-300 group-hover:text-white">Yes</span>
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer group">
                                                <input type="radio" name="att_tl_informed" value="no" class="form-radio text-primary-500 focus:ring-primary-500 bg-gray-800 border-gray-600">
                                                <span class="text-sm text-gray-300 group-hover:text-white">No</span>
                                            </label>
                                        </div>
                                        <div id="attTlMessageContainer" class="mt-2 text-sm hidden"></div>
                                    </div>
                                </div>
                            </div>
                            <!-- END HRIS WORKFLOW -->
                        </div>

                        <div>
                            <label for="issueDetails" class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Specific Details</label>
                            <textarea class="w-full px-4 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-gray-200 focus:ring-2 focus:ring-primary-500 outline-none transition" id="issueDetails" name="Issue_Details" rows="4" placeholder="Describe the error message, steps taken, or specific behavior..." required></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Attach Screenshot (Optional)</label>
                            <div class="drop-zone relative w-full h-32 rounded-lg border-2 border-dashed border-gray-600 bg-gray-900/50 flex flex-col items-center justify-center cursor-pointer hover:border-primary-500 transition-all group overflow-hidden" id="dropZone">
                                <input type="file" name="issue_img" id="fileInput" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" accept="image/*">
                                
                                <div id="dropZoneEmpty" class="flex flex-col items-center text-gray-500 group-hover:text-primary-400 pointer-events-none px-4 text-center">
                                    <i class="fas fa-cloud-upload-alt text-2xl mb-2"></i>
                                    <p class="text-xs">Tap, drag & drop, or <span class="underline">paste</span> image here</p>
                                </div>

                                <div id="dropZonePreview" class="hidden w-full h-full relative">
                                    <img id="imagePreview" src="" alt="Preview" class="w-full h-full object-contain">
                                    <button type="button" id="removeImageBtn" class="absolute top-2 right-2 bg-red-600 text-white rounded-full p-1.5 shadow-lg hover:bg-red-700 transition-colors z-10">
                                        <i class="fas fa-times text-xs"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 flex flex-col sm:flex-row gap-4">
                            <button id="submitBtn" class="flex-1 py-3.5 px-4 bg-primary-600 hover:bg-primary-500 text-white font-bold rounded-xl shadow-lg shadow-primary-900/50 transition-all duration-200 flex items-center justify-center gap-2 transform hover:-translate-y-0.5" type="submit">
                                <span class="btn-icon"><i class="fas fa-paper-plane"></i></span>
                                <span class="btn-text">Submit Ticket(s)</span>
                            </button>
                            <a class="sm:w-auto w-full py-3.5 px-6 border border-gray-600 hover:bg-gray-700 hover:text-white text-gray-400 font-medium rounded-xl transition duration-200 flex items-center justify-center" href="<?= $_SERVER['PHP_SELF'] ?>">
                                Cancel
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-center text-gray-500 text-xs mt-6">&copy; <?= date('Y') ?> CXI Services Inc. SLT Department</p>
    </div>

    <!-- ADD EMPLOYEE MODAL -->
    <div id="addEmployeeModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden flex items-center justify-center z-50 transition-opacity opacity-0 pointer-events-none">
        <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-2xl w-full max-w-md p-6 transform scale-95 transition-transform duration-200">
            <h3 class="text-lg font-bold text-white mb-4">Add Another Employee</h3>
            <div class="mb-4 relative">
                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Employee Search</label>
                <div class="relative">
                    <input type="text" id="newEidInput" class="w-full pl-10 pr-4 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-white focus:ring-2 focus:ring-primary-500 outline-none uppercase" placeholder="Name or CXI00002" autocomplete="off">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-500">
                        <i class="fas fa-search"></i>
                    </div>
                </div>
                <!-- Modal Search Results -->
                <div id="modalSearchResults" class="hidden absolute top-full left-0 z-50 mt-1 w-full glass-panel border border-gray-600/50 rounded-xl shadow-2xl overflow-hidden max-h-60 overflow-y-auto custom-scroll">
                </div>
                <p id="modalError" class="text-red-400 text-xs mt-2 hidden"></p>
            </div>
            <div class="flex justify-end gap-3 mt-12">
                <button onclick="closeAddModal()" class="px-4 py-2 border border-gray-600 rounded-lg text-gray-300 hover:bg-gray-700 transition">Cancel</button>
                <button onclick="fetchAndAddEmployee()" id="modalAddBtn" class="px-4 py-2 bg-primary-600 rounded-lg text-white hover:bg-primary-500 transition flex items-center gap-2">
                    <span>Add</span>
                    <i class="fas fa-spinner fa-spin hidden" id="modalLoader"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- SELF TICKETING CONFIRMATION MODAL -->
    <?php if ($showSelfTicketWarning): ?>
    <div id="selfTicketWarningModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center z-[100] animate-fade-in">
        <div class="bg-gray-800 rounded-2xl border border-gray-700 shadow-2xl w-full max-w-sm p-6 transform animate-pop-in relative text-center">
            <button onclick="window.location.href = window.location.pathname;" class="absolute top-4 right-4 text-gray-400 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
            
            <div class="w-16 h-16 rounded-full bg-yellow-500/10 flex items-center justify-center mx-auto mb-4 border border-yellow-500/20">
                <i class="fas fa-exclamation-triangle text-3xl text-yellow-500"></i>
            </div>
            
            <h3 class="text-xl font-bold text-gray-100 mb-2">Self-Ticketing Detected</h3>
            
            <p class="text-gray-400 text-sm mb-6">You are about to submit a ticket for your own account. Are you sure you want to proceed?</p>
            
            <div class="flex flex-col gap-3">
                <button onclick="document.getElementById('selfTicketWarningModal').remove()" class="w-full py-3 px-4 bg-primary-600 hover:bg-primary-500 text-white font-bold rounded-xl transition-colors shadow-lg flex items-center justify-center gap-2">
                    Yes, proceed
                </button>
                <button onclick="window.location.href = window.location.pathname;" class="w-full py-3 px-4 bg-gray-700 hover:bg-gray-600 text-gray-300 font-bold rounded-xl transition-colors border border-gray-600">
                    Cancel
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let currentEmployeeIds = <?= !empty($foundEmployees) ? json_encode(array_column($foundEmployees, 'employee_id')) : '[]' ?>;

        // Form Submit Loading States
        $('#searchForm').on('submit', function() {
            if (this.checkValidity()) {
                const btn = $(this).find('button[type="submit"]');
                btn.prop('disabled', true)
                   .removeClass('hover:-translate-y-0.5 hover:bg-primary-500')
                   .addClass('opacity-75 cursor-not-allowed')
                   .find('.btn-icon').removeClass('fa-arrow-right group-hover:translate-x-1').addClass('fa-spinner fa-spin');
                btn.find('.btn-text').text('Searching...');
            }
        });

        $('#ticketForm').on('submit', function() {
            if (this.checkValidity()) {
                const btn = $('#submitBtn');
                btn.prop('disabled', true)
                   .removeClass('hover:-translate-y-0.5 hover:bg-primary-500')
                   .addClass('opacity-75 cursor-not-allowed')
                   .find('.btn-icon').html('<i class="fas fa-spinner fa-spin"></i>');
                btn.find('.btn-text').text('Submitting...');
            }
        });

        // --- Autocomplete Logic ---
        function searchEmployeesAutocomplete(query, resultsContainer, inputElement) {
            if (query.length < 2) {
                resultsContainer.classList.add('hidden');
                return;
            }

            fetch('../api/search_employees.php?query=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    resultsContainer.innerHTML = '';
                    
                    if (data.success && data.employees.length > 0) {
                        data.employees.forEach(employee => {
                            const item = document.createElement('div');
                            item.className = 'px-4 py-3 hover:bg-gray-700/50 cursor-pointer border-b border-gray-700/50 transition-colors flex flex-col';
                            item.innerHTML = `
                                <span class="font-bold text-gray-200">${employee.employee_id}</span>
                                <span class="text-xs text-gray-400 uppercase">${employee.full_name}</span>
                            `;
                            item.addEventListener('click', () => {
                                inputElement.value = employee.employee_id;
                                resultsContainer.classList.add('hidden');
                                
                                // Auto-trigger search/add if it's the modal
                                if (inputElement.id === 'newEidInput') {
                                    fetchAndAddEmployee();
                                }
                            });
                            resultsContainer.appendChild(item);
                        });
                        resultsContainer.classList.remove('hidden');
                    } else {
                        const item = document.createElement('div');
                        item.className = 'px-4 py-4 text-sm text-gray-400 italic bg-gray-800/80';
                        item.textContent = 'No matching employees found.';
                        resultsContainer.appendChild(item);
                        resultsContainer.classList.remove('hidden');
                    }
                })
                .catch(error => console.error('Autocomplete Error:', error));
        }

        // Delegate event for dynamic main form inputs
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('employee-search-input')) {
                const resultsContainer = e.target.closest('.relative').querySelector('.employee-search-results');
                if(resultsContainer) {
                    searchEmployeesAutocomplete(e.target.value, resultsContainer, e.target);
                }
            }
        });

        // Event for Modal Input
        const modalInput = document.getElementById('newEidInput');
        if(modalInput) {
            modalInput.addEventListener('input', function() {
                const resultsContainer = document.getElementById('modalSearchResults');
                searchEmployeesAutocomplete(this.value, resultsContainer, this);
            });
        }

        // Hide results on outside click
        document.addEventListener('click', function(e) {
            const isSearchInput = e.target.classList.contains('employee-search-input') || e.target.id === 'newEidInput';
            const isResultsContainer = e.target.closest('.employee-search-results') || e.target.closest('#modalSearchResults');
            
            if (!isSearchInput && !isResultsContainer) {
                document.querySelectorAll('.employee-search-results, #modalSearchResults').forEach(el => {
                    if(el) el.classList.add('hidden');
                });
            }
        });

        function addEidField() {
            const container = document.getElementById('eid-container');
            const div = document.createElement('div');
            div.className = 'eid-row flex gap-2 animate-fade-in mt-3';
            div.innerHTML = `
                <div class="relative w-full">
                    <input type="text" name="eid[]" class="employee-search-input w-full pl-10 pr-4 py-3 bg-gray-900/50 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none transition-all uppercase" placeholder="Another Employee" autocomplete="off">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                        <i class="fas fa-search text-gray-500"></i>
                    </div>
                    <div class="employee-search-results hidden absolute top-full left-0 z-50 mt-1 w-full glass-panel border border-gray-600/50 rounded-xl shadow-2xl overflow-hidden max-h-60 overflow-y-auto custom-scroll"></div>
                </div>
                <button type="button" onclick="removeEidField(this)" class="px-4 py-2 bg-red-900/30 hover:bg-red-900/50 border border-red-800/50 text-red-400 rounded-lg transition-colors" title="Remove">
                    <i class="fas fa-trash-alt"></i>
                </button>
            `;
            container.appendChild(div);
        }

        function removeEidField(button) {
            $(button).closest('.eid-row').remove();
        }

        function openAddModal() {
            const modal = document.getElementById('addEmployeeModal');
            modal.classList.remove('hidden', 'pointer-events-none', 'opacity-0');
            modal.querySelector('div').classList.remove('scale-95');
            modal.querySelector('div').classList.add('scale-100');
            document.getElementById('newEidInput').value = '';
            document.getElementById('newEidInput').focus();
            document.getElementById('modalError').classList.add('hidden');
            document.getElementById('modalSearchResults').classList.add('hidden');
        }

        function closeAddModal() {
            const modal = document.getElementById('addEmployeeModal');
            modal.classList.add('opacity-0', 'pointer-events-none');
            modal.querySelector('div').classList.add('scale-95');
            modal.querySelector('div').classList.remove('scale-100');
            setTimeout(() => modal.classList.add('hidden'), 200);
        }

        function removeEmployee(btn, eid) {
            currentEmployeeIds = currentEmployeeIds.filter(id => id !== eid);
            updateHiddenInput();
            
            const card = btn.closest('.employee-card');
            card.style.opacity = '0';
            card.style.transform = 'scale(0.9)';
            setTimeout(() => card.remove(), 200);
            
            document.getElementById('count-display').textContent = currentEmployeeIds.length;
            
            if (currentEmployeeIds.length === 0) {
                alert('List is empty. Redirecting to search...');
                window.location.href = window.location.pathname;
            }
        }

        function fetchAndAddEmployee() {
            const eid = document.getElementById('newEidInput').value.trim().toUpperCase();
            const btn = document.getElementById('modalAddBtn');
            const loader = document.getElementById('modalLoader');
            const errorMsg = document.getElementById('modalError');

            if (!eid) {
                errorMsg.textContent = "Please select or enter an ID.";
                errorMsg.classList.remove('hidden');
                return;
            }

            if (currentEmployeeIds.includes(eid)) {
                errorMsg.textContent = "Employee '" + eid + "' is already in the list.";
                errorMsg.classList.remove('hidden');
                const input = document.getElementById('newEidInput');
                input.classList.add('border-red-500', 'animate-pulse');
                setTimeout(() => input.classList.remove('border-red-500', 'animate-pulse'), 1000);
                return;
            }

            btn.disabled = true;
            loader.classList.remove('hidden');
            errorMsg.classList.add('hidden');

            $.post('<?= $_SERVER['PHP_SELF'] ?>', {
                action: 'fetch_employee_details',
                eid: eid
            }, function(response) {
                btn.disabled = false;
                loader.classList.add('hidden');

                if (response.success) {
                    const emp = response.data;
                    if (!currentEmployeeIds.includes(emp.employee_id)) {
                        currentEmployeeIds.push(emp.employee_id);
                        updateHiddenInput();

                        const grid = document.getElementById('selected-employees-grid');
                        const initial = (emp.full_name || 'U').charAt(0);
                        
                        const div = document.createElement('div');
                        div.className = 'employee-card bg-gray-700/40 border border-gray-600/50 rounded-lg flex items-stretch gap-0 relative group transition-all overflow-hidden animate-fade-in';
                        div.setAttribute('data-id', emp.employee_id);
                        div.innerHTML = `
                            <div class="p-3 flex items-center gap-3 flex-1 min-w-0">
                                <div class="h-10 w-10 rounded-full bg-gradient-to-br from-gray-600 to-gray-700 flex items-center justify-center text-gray-300 font-bold text-sm shrink-0">
                                    ${initial}
                                </div>
                                <div class="overflow-hidden">
                                    <h4 class="text-sm font-semibold text-white truncate">${emp.full_name}</h4>
                                    <p class="text-xs text-gray-400 truncate">${emp.employee_id} • ${emp.department}</p>
                                </div>
                            </div>
                            <button type="button" onclick="removeEmployee(this, '${emp.employee_id}')" class="delete-btn w-10 bg-red-500/10 hover:bg-red-500 text-red-400 hover:text-white flex items-center justify-center border-l border-gray-600/50 transition-colors">
                                <i class="fas fa-trash-alt text-xs"></i>
                            </button>
                        `;
                        grid.appendChild(div);
                        document.getElementById('count-display').textContent = currentEmployeeIds.length;
                        closeAddModal();
                    } else {
                        errorMsg.textContent = "Employee '" + emp.employee_id + "' is already in the list.";
                        errorMsg.classList.remove('hidden');
                    }
                } else {
                    errorMsg.textContent = response.message || "Error fetching details.";
                    errorMsg.classList.remove('hidden');
                }
            }, 'json').fail(function() {
                btn.disabled = false;
                loader.classList.add('hidden');
                errorMsg.textContent = "Network error. Try again.";
                errorMsg.classList.remove('hidden');
            });
        }

        function updateHiddenInput() {
            document.getElementById('target_employee_ids').value = JSON.stringify(currentEmployeeIds);
            const btnText = currentEmployeeIds.length > 1 ? `Submit Multiple Tickets (${currentEmployeeIds.length})` : "Submit Ticket";
            
            const btnTextSpan = document.querySelector('#submitBtn .btn-text');
            if(btnTextSpan) {
                btnTextSpan.textContent = btnText;
            }
        }

        // --- Image Drop Zone Logic ---
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const dropZoneEmpty = document.getElementById('dropZoneEmpty');
        const dropZonePreview = document.getElementById('dropZonePreview');
        const imagePreview = document.getElementById('imagePreview');
        const removeImageBtn = document.getElementById('removeImageBtn');

        if(dropZone) {
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, highlight, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, unhighlight, false);
            });

            function highlight(e) { dropZone.classList.add('dragover'); }
            function unhighlight(e) { dropZone.classList.remove('dragover'); }

            dropZone.addEventListener('drop', handleDrop, false);

            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                handleFiles(files);
            }

            fileInput.addEventListener('change', function() { handleFiles(this.files); });

            window.addEventListener('paste', function(e) {
                const items = (e.clipboardData || e.originalEvent.clipboardData).items;
                for (let index in items) {
                    const item = items[index];
                    if (item.kind === 'file' && item.type.indexOf('image/') !== -1) {
                        const blob = item.getAsFile();
                        const file = new File([blob], "pasted-image.png", {type: blob.type});
                        const dt = new DataTransfer();
                        dt.items.add(file);
                        fileInput.files = dt.files;
                        handleFiles(fileInput.files);
                    }
                }
            });

            function handleFiles(files) {
                if (files.length > 0) {
                    const file = files[0];
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            imagePreview.src = e.target.result;
                            dropZoneEmpty.classList.add('hidden');
                            dropZonePreview.classList.remove('hidden');
                        }
                        reader.readAsDataURL(file);
                    }
                }
            }

            removeImageBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                e.preventDefault();
                fileInput.value = '';
                imagePreview.src = '';
                dropZonePreview.classList.add('hidden');
                dropZoneEmpty.classList.remove('hidden');
            });
        }

        // --- Common UI & HRIS Workflow Logic ---
        $(document).ready(function() {
            
            // Only auto-fade success alerts, not error alerts.
            setTimeout(function() {
                $('#successAlert').fadeOut('slow');
            }, 6000);

            function setUrgencyBasedOnIssue(issue) {
                const map = {
                    'Keyboard': 'LOW', 'HRIS Concern': 'LOW', 'Mouse': 'LOW', 'Headset': 'LOW', 'Monitor': 'LOW',
                    'Full Storage': 'MEDIUM', 'Windows tools error': 'MEDIUM', 'Other': 'MEDIUM',
                    'Internet': 'HIGH', 'NT Login Issue': 'HIGH', 'Client Tool/System Issue': 'HIGH'
                };
                return map[issue] || 'MEDIUM';
            }

            function toggleSubmitButton(enable) {
                const btn = $('#submitBtn');
                if (enable) {
                    btn.prop('disabled', false).removeClass('opacity-50 cursor-not-allowed bg-gray-600 hover:bg-gray-600').addClass('bg-primary-600 hover:bg-primary-500 hover:-translate-y-0.5');
                    btn.find('.btn-text').text(currentEmployeeIds.length > 1 ? `Submit Multiple Tickets (${currentEmployeeIds.length})` : "Submit Ticket");
                } else {
                    btn.prop('disabled', true).addClass('opacity-50 cursor-not-allowed bg-gray-600 hover:bg-gray-600').removeClass('bg-primary-600 hover:bg-primary-500 hover:-translate-y-0.5');
                    btn.find('.btn-text').text("Action Required First");
                }
            }
            
            // On Category Change
            $('#issues').on('change', function() {
                var selectedIssue = $(this).val();
                var urgency = setUrgencyBasedOnIssue(selectedIssue);
                
                $('#urgency').val(urgency);
                var urgencyClass = urgency === 'HIGH' ? 'text-red-400 bg-red-900/50' : (urgency === 'MEDIUM' ? 'text-yellow-400 bg-yellow-900/50' : 'text-green-400 bg-green-900/50');
                
                $('#urgency-display').removeClass().addClass('text-xs font-bold px-2 py-1 rounded inline-block ' + urgencyClass).text(urgency + ' PRIORITY').fadeIn();
                $('#urgency-info').fadeIn();
                
                var tipContainer = $('#troubleshootingTip');
                var tips = {
                    'Monitor': "Check power cables and HDMI/VGA connections first.",
                    'Keyboard': "Try replugging the USB connector to a different port.",
                    'Mouse': "Check for optical light or try a different USB port.",
                    'Headset': "Ensure it's set as default device in Sound Settings.",
                    'Internet': "Check LAN cable is securely connected until it clicks.",
                    'NT Login Issue': "If account is locked, we will reset it. Ensure Caps Lock is off.",
                    'Client Tool/System Issue': "Try restarting the specific application first.",
                    'Full Storage': "Delete temp files or Downloads folder content if possible.",
                    'Windows tools error': "Please include the Error Code in the details below.",
                    'HRIS Concern': "Please use the wizard below to specify your HRIS issue.",
                    'Other': "Provide as much context as possible."
                };

                if (tips[selectedIssue]) {
                    tipContainer.html('<i class="fas fa-lightbulb text-yellow-400 mr-2"></i>' + tips[selectedIssue]).removeClass('hidden');
                } else {
                    tipContainer.addClass('hidden');
                }

                // HRIS Flow Trigger
                if (selectedIssue === 'HRIS Concern') {
                    $('#hrisFlowContainer').slideDown();
                    $('#issueDetails').val(''); // Clear manual typing to encourage wizard
                    toggleSubmitButton(false); // Initially disabled until they finish flow
                } else {
                    $('#hrisFlowContainer').slideUp();
                    toggleSubmitButton(true);
                }
            });

            // HRIS Sub-Category Dropdown
            $('#hrisSubCategory').on('change', function() {
                const val = $(this).val();
                $('#issueDetails').val('');
                
                // Reset radios & dates
                $('input[name="sched_type"]').prop('checked', false);
                $('input[name="att_type"]').prop('checked', false);
                $('input[name="tl_informed"]').prop('checked', false);
                $('input[name="att_float"]').prop('checked', false);
                $('input[name="att_tl_informed"]').prop('checked', false);
                $('input[type="date"]').prop('disabled', true).val('');
                
                $('#hrisTLQuestion').hide();
                $('#tlMessageContainer').hide();
                
                $('#hrisFloatQuestion').hide();
                $('#hrisAttTLQuestion').hide();
                $('#floatMessageContainer').hide();
                $('#attTlMessageContainer').hide();
                
                $('#troubleshootingTip').hide();
                toggleSubmitButton(false);

                if (val === 'Schedule') {
                    $('#hrisAttendanceFlow').hide();
                    $('#hrisScheduleFlow').show();
                } else if (val === 'Attendance') {
                    $('#hrisScheduleFlow').hide();
                    $('#hrisAttendanceFlow').show();
                } else if (val === 'Other') {
                    $('#hrisScheduleFlow').hide();
                    $('#hrisAttendanceFlow').hide();
                    $('#issueDetails').focus();
                    $('#troubleshootingTip').html('<i class="fas fa-info-circle text-blue-400 mr-2"></i>Please specify your concern in the details box below.').show();
                    toggleSubmitButton(true);
                }
            });

            // Schedule Concern Radios
            $('input[name="sched_type"]').on('change', function() {
                $('input[type="date"]').prop('disabled', true);
                const selected = $(this).val();
                const targetInput = $('#date_' + selected);
                targetInput.prop('disabled', false).focus();
                
                $('#hrisTLQuestion').slideDown();
                
                // Clear TL question when changing schedule type
                $('input[name="tl_informed"]').prop('checked', false);
                $('#tlMessageContainer').hide();
                toggleSubmitButton(false);
                updateScheduleDescription();
            });

            // Date Selection
            $('input[type="date"]').on('change', updateScheduleDescription);

            // TL Informed Question (For Schedule)
            $('input[name="tl_informed"]').on('change', function() {
                const informed = $(this).val();
                const msgBox = $('#tlMessageContainer');
                
                if (informed === 'yes') {
                    msgBox.html('<div class="p-2 bg-green-900/30 border border-green-500/50 text-green-300 rounded"><i class="fas fa-check-circle mr-2"></i>Your TL will coordinate this matter with your Operations Manager. You may submit this ticket to log your concern.</div>').slideDown();
                    toggleSubmitButton(true);
                    updateScheduleDescription();
                } else {
                    msgBox.html('<div class="p-2 bg-red-900/30 border border-red-500/50 text-red-300 rounded"><i class="fas fa-exclamation-triangle mr-2"></i>You must inform your TL first regarding this matter so they can coordinate this issue.</div>').slideDown();
                    toggleSubmitButton(false);
                    $('#issueDetails').val('');
                }
            });

            function updateScheduleDescription() {
                if ($('input[name="tl_informed"]:checked').val() !== 'yes') return;

                const schedType = $('input[name="sched_type"]:checked').val();
                if (!schedType) return;
                
                const dateVal = $('#date_' + schedType).val();
                const formattedDate = dateVal ? new Date(dateVal).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : '[Select a Date]';
                
                let desc = '';
                if (schedType === 'shift') desc = `Employee has a shift for ${formattedDate} but there is no plotted schedule.\nTime: [Please specify time here]`;
                if (schedType === 'ot') desc = `Employee has an OT for ${formattedDate} but there is no plotted schedule.\nTime: [Please specify time here]`;
                if (schedType === 'rest') desc = `It is the employee's Rest Day on ${formattedDate} but there is no plotted schedule.\nTime: [Please specify time here]`;
                
                $('#issueDetails').val(desc);
            }

            // Attendance Concern Radios
            $('input[name="att_type"]').on('change', function() {
                const selected = $(this).val();
                
                // Reset float and TL questions
                $('input[name="att_float"]').prop('checked', false);
                $('input[name="att_tl_informed"]').prop('checked', false);
                $('#hrisFloatQuestion').hide();
                $('#hrisAttTLQuestion').hide();
                $('#floatMessageContainer').hide();
                $('#attTlMessageContainer').hide();

                if (selected === 'no_sched') {
                    $('#issueDetails').val("");
                    $('#troubleshootingTip').hide();
                    $('#hrisFloatQuestion').slideDown();
                    toggleSubmitButton(false);
                } else if (selected === 'bug') {
                    $('#issueDetails').val("Employee suspects a system bug regarding attendance. Specifics:\n\n");
                    $('#troubleshootingTip').html('<i class="fas fa-lightbulb text-yellow-400 mr-2"></i>Please make sure to attach a screenshot indicating the bug, and describe it below.').show();
                    $('#issueDetails').focus();
                    toggleSubmitButton(true);
                }
            });

            // Float Question (For Attendance)
            $('input[name="att_float"]').on('change', function() {
                const isFloat = $(this).val();
                
                // Reset TL question
                $('input[name="att_tl_informed"]').prop('checked', false);
                $('#hrisAttTLQuestion').hide();
                $('#attTlMessageContainer').hide();
                
                const msgBox = $('#floatMessageContainer');

                if (isFloat === 'yes') {
                    msgBox.hide();
                    $('#hrisAttTLQuestion').slideDown();
                    toggleSubmitButton(false);
                } else {
                    msgBox.html('<div class="p-2 bg-red-900/30 border border-red-500/50 text-red-300 rounded"><i class="fas fa-exclamation-triangle mr-2"></i>You must notify your Team Leader regarding this. There is no need to send a ticket.</div>').slideDown();
                    toggleSubmitButton(false);
                    $('#issueDetails').val('');
                }
            });

            // TL Informed Question (For Attendance Float)
            $('input[name="att_tl_informed"]').on('change', function() {
                const informed = $(this).val();
                const msgBox = $('#attTlMessageContainer');
                
                if (informed === 'yes') {
                    msgBox.html('<div class="p-2 bg-green-900/30 border border-green-500/50 text-green-300 rounded"><i class="fas fa-check-circle mr-2"></i>You may submit this ticket to log your concern.</div>').slideDown();
                    $('#issueDetails').val("Employee does not have a plotted schedule for today but has already timed in as a float. Team Leader has been informed.");
                    toggleSubmitButton(true);
                } else {
                    msgBox.html('<div class="p-2 bg-red-900/30 border border-red-500/50 text-red-300 rounded"><i class="fas fa-exclamation-triangle mr-2"></i>You must notify your Team Leader regarding this. There is no need to send a ticket.</div>').slideDown();
                    toggleSubmitButton(false);
                    $('#issueDetails').val('');
                }
            });

        });
    </script>
</body>
</html>