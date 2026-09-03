<?php
// includes/reset_fire_triggers.php

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Use absolute paths to avoid directory issues
$current_dir = __DIR__; // /home/.../public_html/includes
$root_dir = dirname(__DIR__); // /home/.../public_html

// Include files using the correct paths
require_once $current_dir . '/config.php';
require_once $current_dir . '/functions.php';

// Set timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');

// Log execution
error_log("=== Cron Job Started: reset_fire_triggers.php at " . date('Y-m-d H:i:s') . " ===");

try {
    // Must stay LONGER than one full read cycle, otherwise fire expires before anything reads it.
    // The only reader is fetchDataFromPHP's 1-minute time-based trigger (the PHP webhook path is
    // not in use), so the floor is 60s + fetch duration. 3 minutes gives roughly three chances to
    // catch a fire, which covers a slow fetch without overdoing it.
    //
    // Don't stretch this much further: fire re-fires on every trigger run while it is still set,
    // so a long window means the same row is reprocessed over and over. That is safe (addNoteTofile
    // and stackShiftLines both dedupe) but it burns Sheets calls, which is what causes the
    // "Service Spreadsheets timed out" failures in the first place.
    //
    // If a fetch ever legitimately runs longer than this, scope the absenteeism query in
    // api/mysql-to-sheets.php instead of widening this window.
    $manila_threshold = date('Y-m-d H:i:s', strtotime('-3 minutes'));

    error_log("Manila Threshold (3 min ago): " . $manila_threshold);

    // Reset fire_trigger for records older than 3 minutes
    $tables = ['absenteeism', 'tardiness', 'vto_tracker'];
    $total_reset = 0;
    
    foreach ($tables as $table) {
        // Check if table exists
        $check_table = $pdo->prepare("SHOW TABLES LIKE ?");
        $check_table->execute([$table]);
        
        if ($check_table->rowCount() > 0) {
            // First, count how many records should be reset
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE fire_trigger = 'fire' AND trigger_date <= ?");
            $count_stmt->execute([$manila_threshold]);
            $should_reset = $count_stmt->fetchColumn();
            
            error_log("Table {$table}: {$should_reset} records should be reset (threshold: $manila_threshold)");
            
            if ($should_reset > 0) {
                // Reset the records
                $stmt = $pdo->prepare("UPDATE $table SET fire_trigger = NULL WHERE fire_trigger = 'fire' AND trigger_date <= ?");
                $stmt->execute([$manila_threshold]);
                
                $reset_count = $stmt->rowCount();
                $total_reset += $reset_count;
                
                error_log("Reset {$reset_count} fire triggers in {$table}");
                
                // Log the specific records that were reset
                $detail_stmt = $pdo->prepare("SELECT id, employee_id, trigger_date FROM $table WHERE fire_trigger IS NULL AND trigger_date <= ?");
                $detail_stmt->execute([$manila_threshold]);
                $reset_records = $detail_stmt->fetchAll();
                
                foreach ($reset_records as $record) {
                    error_log("  - RESET: ID {$record['id']}, Employee {$record['employee_id']}, Date {$record['trigger_date']}");
                }
            }
        } else {
            error_log("Table {$table} not found");
        }
    }
    
    error_log("=== Cron Job Completed: Reset {$total_reset} total records ===");
    
    // Write to a log file for debugging
    file_put_contents($root_dir . '/cron_log.txt', date('Y-m-d H:i:s') . " - Reset {$total_reset} records\n", FILE_APPEND);
    
    echo "Cron job executed successfully. Reset {$total_reset} records.";
    
} catch (PDOException $e) {
    $error_msg = "ERROR in cron job: " . $e->getMessage();
    error_log($error_msg);
    file_put_contents($root_dir . '/cron_errors.txt', date('Y-m-d H:i:s') . " - " . $error_msg . "\n", FILE_APPEND);
    echo "Error: " . $e->getMessage();
}
?>