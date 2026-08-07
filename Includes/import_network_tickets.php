<?php
// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// IMPORTANT: Put ALL use statements at the VERY TOP, right after the opening PHP tag
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// Create a debug log file in the same directory
$debug_log = __DIR__ . '/import_debug_' . date('Y-m-d') . '.log';

// Custom debug function
function debug_log($message, $data = null) {
    global $debug_log;
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message";
    if ($data !== null) {
        $log_message .= "\n" . print_r($data, true);
    }
    $log_message .= "\n------------------------\n";
    file_put_contents($debug_log, $log_message, FILE_APPEND);
}

// Start logging
debug_log("=== IMPORT SCRIPT STARTED ===");
debug_log("Script path: " . __FILE__);

ob_start(); 

try {
    // Check if config files exist
    debug_log("Checking config file paths...");
    
    $config_path = __DIR__ . '/../includes/config.php';
    if (!file_exists($config_path)) {
        throw new Exception("Config file not found at: $config_path");
    }
    debug_log("Config file exists");
    
    $functions_path = __DIR__ . '/../includes/functions.php';
    if (!file_exists($functions_path)) {
        throw new Exception("Functions file not found at: $functions_path");
    }
    debug_log("Functions file exists");
    
    require_once $config_path;
    require_once $functions_path;
    debug_log("Config and functions loaded successfully");

    // Check database connection
    if (!isset($pdo)) {
        throw new Exception("Database connection not established");
    }
    debug_log("Database connection OK");

    // Check vendor/autoload.php
    $vendor_path = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendor_path)) {
        throw new Exception("Vendor autoload not found at: $vendor_path. Run 'composer install' first.");
    }
    debug_log("Vendor autoload exists");
    
    require_once $vendor_path;
    debug_log("Vendor autoload loaded");

    // Check PhpSpreadsheet classes
    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        throw new Exception("PhpSpreadsheet classes not found. Check composer installation.");
    }
    debug_log("PhpSpreadsheet classes available");

    header('Content-Type: application/json');

    // Check if this is a POST request with file
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. Expected POST, got: " . $_SERVER['REQUEST_METHOD']);
    }
    debug_log("Request method check passed");

    if (!isset($_FILES['excel_file'])) {
        throw new Exception("No file uploaded - excel_file not found in \$_FILES");
    }
    debug_log("File upload check passed");

    $file = $_FILES['excel_file'];
    debug_log("File details:", [
        'name' => $file['name'],
        'type' => $file['type'],
        'tmp_name' => $file['tmp_name'],
        'error' => $file['error'],
        'size' => $file['size']
    ]);

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        $errorMsg = isset($uploadErrors[$file['error']]) ? $uploadErrors[$file['error']] : 'Unknown upload error';
        throw new Exception("Upload error: $errorMsg (Code: " . $file['error'] . ")");
    }
    debug_log("Upload error check passed");

    // Check if file was actually uploaded
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new Exception("File is not a valid uploaded file");
    }
    debug_log("Uploaded file validation passed");

    // Check if temp file exists and is readable
    if (!file_exists($file['tmp_name'])) {
        throw new Exception("Temporary file does not exist: " . $file['tmp_name']);
    }
    if (!is_readable($file['tmp_name'])) {
        throw new Exception("Temporary file is not readable: " . $file['tmp_name']);
    }
    debug_log("Temp file exists and is readable");
    debug_log("Temp file size: " . filesize($file['tmp_name']));

    // Get current user
    $userId = $_SESSION['user_id'] ?? 0;
    debug_log("Current user ID: " . $userId);
    
    $stmtUser = $pdo->prepare("SELECT sub_name FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $user = $stmtUser->fetch();
    
    $sltName = ($user && !empty($user['sub_name'])) ? $user['sub_name'] : "System Import";
    debug_log("SLT Name set to: " . $sltName);

    // Load and process the file
    debug_log("Attempting to load spreadsheet from: " . $file['tmp_name']);
    
    try {
        $spreadsheet = IOFactory::load($file['tmp_name']);
        debug_log("Spreadsheet loaded successfully");
    } catch (Exception $e) {
        throw new Exception("Failed to load spreadsheet: " . $e->getMessage());
    }

    $worksheet = $spreadsheet->getActiveSheet();
    debug_log("Worksheet loaded. Title: " . $worksheet->getTitle());
    debug_log("Highest row: " . $worksheet->getHighestRow());
    debug_log("Highest column: " . $worksheet->getHighestColumn());

    $dataRows = $worksheet->toArray();
    debug_log("Total rows from worksheet (including header): " . count($dataRows));

    // Show first few rows for debugging
    debug_log("First 3 rows of data:", array_slice($dataRows, 0, 3));

    // Remove headers
    array_shift($dataRows);
    $totalDataRows = count($dataRows);
    debug_log("Rows after removing header: " . $totalDataRows);

    if (empty($dataRows)) {
        throw new Exception("File contains no data rows after header.");
    }

    // Start transaction
    $pdo->beginTransaction();
    debug_log("Transaction started");

    $stmt = $pdo->prepare("INSERT INTO network_tickets 
        (date_received, subject, email_link, status, slt_on_duty, date_reported, type, is_email) 
        VALUES (?, ?, ?, ?, ?, NOW(), 'technical', 1)");
    
    if (!$stmt) {
        throw new Exception("Failed to prepare insert statement: " . implode(", ", $pdo->errorInfo()));
    }
    debug_log("Insert statement prepared successfully");

    $count = 0;
    $errors = [];

    foreach ($dataRows as $index => $data) {
        $rowNum = $index + 2; // +2 because row 1 was header and array is 0-based
        debug_log("Processing row $rowNum:", $data);

        // Check required fields
        if (empty($data[0]) || empty($data[2])) {
            debug_log("Skipping row $rowNum - missing required data (Date or Subject)");
            continue;
        }

        // Handle date parsing
        $rawDate = $data[0];
        $rawTime = $data[1] ?? '';
        
        try {
            if (is_numeric($rawDate)) {
                // Excel numeric date
                debug_log("Row $rowNum: Date is numeric: $rawDate");
                $timestamp = Date::excelToTimestamp($rawDate);
                $fullTimestamp = date('Y-m-d H:i:s', $timestamp);
                debug_log("Row $rowNum: Converted to: $fullTimestamp");
            } else {
                // String date
                debug_log("Row $rowNum: Date is string: $rawDate");
                $dateTimeStr = trim($rawDate . ' ' . $rawTime);
                $fullTimestamp = date('Y-m-d H:i:s', strtotime($dateTimeStr));
                debug_log("Row $rowNum: Parsed to: $fullTimestamp");
                
                if ($fullTimestamp === '1970-01-01 00:00:00' || $fullTimestamp === false) {
                    throw new Exception("Invalid date format");
                }
            }
        } catch (Exception $e) {
            $errors[] = "Row $rowNum: Failed to parse date - " . $e->getMessage();
            debug_log("Date parsing error on row $rowNum: " . $e->getMessage());
            continue;
        }

        $subject = sanitizeInput($data[2]);
        $link    = isset($data[3]) ? sanitizeInput($data[3]) : '';
        $resolvedDate = isset($data[4]) ? trim($data[4]) : '';

        // Status Logic
        $status = (!empty($resolvedDate) && $resolvedDate != '-' && strtolower($resolvedDate) != 'null' && strtolower($resolvedDate) != 'pending') ? 'close' : 'pending';
        debug_log("Row $rowNum: Status determined as: $status (resolvedDate: '$resolvedDate')");

        try {
            if ($stmt->execute([$fullTimestamp, $subject, $link, $status, $sltName])) {
                $newId = $pdo->lastInsertId();
                $generatedCustomId = "SLT" . str_pad($newId, 4, '0', STR_PAD_LEFT);
                
                $updateStmt = $pdo->prepare("UPDATE network_tickets SET SLT_TICKET_ID = ? WHERE id = ?");
                if ($updateStmt->execute([$generatedCustomId, $newId])) {
                    $count++;
                    debug_log("Row $rowNum: Inserted successfully. ID: $newId, Ticket ID: $generatedCustomId");
                } else {
                    $error = implode(", ", $updateStmt->errorInfo());
                    $errors[] = "Row $rowNum: Failed to update ticket ID - " . $error;
                    debug_log("Row $rowNum: Failed to update ticket ID: " . $error);
                }
            } else {
                $error = implode(", ", $stmt->errorInfo());
                $errors[] = "Row $rowNum: Failed to insert - " . $error;
                debug_log("Row $rowNum: Insert failed: " . $error);
            }
        } catch (Exception $e) {
            $errors[] = "Row $rowNum: Exception - " . $e->getMessage();
            debug_log("Row $rowNum: Exception during insert: " . $e->getMessage());
        }
    }

    debug_log("Processing complete. Successful inserts: $count, Errors: " . count($errors));
    if (!empty($errors)) {
        debug_log("Error details:", $errors);
    }

    $pdo->commit();
    debug_log("Transaction committed successfully");

    ob_end_clean();
    
    $response = ['success' => true, 'count' => $count];
    if (!empty($errors)) {
        $response['warnings'] = $errors;
    }
    
    debug_log("Sending response:", $response);
    echo json_encode($response);

} catch (Exception $e) {
    debug_log("EXCEPTION CAUGHT: " . $e->getMessage());
    debug_log("Stack trace: " . $e->getTraceAsString());
    
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
        debug_log("Transaction rolled back");
    }
    
    ob_end_clean();
    
    $response = ['success' => false, 'message' => $e->getMessage()];
    debug_log("Sending error response:", $response);
    echo json_encode($response);
}

debug_log("=== IMPORT SCRIPT ENDED ===\n\n");
?>