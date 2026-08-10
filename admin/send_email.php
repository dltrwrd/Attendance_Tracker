<?php
session_start(); // Add session_start() at the beginning

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/infraction_email.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_GET['send_email']) && isset($_GET['type'])) {
    $id = (int)$_GET['send_email'];
    $type = $_GET['type'];
    // Prefer the exact filtered URL the admin was viewing (search/date/department) over a bare
    // tab redirect, so sending one email doesn't reset their filters. return_url comes from the
    // page's live URL (including client-side pushState filters); validated against open redirects
    // by requiring it start with 'attendance.php' — the only legitimate return target.
    $returnUrl = $_SESSION['attendance_return_url'] ?? ('attendance.php?tab=' . $type);
    if (!empty($_GET['return_url']) && str_starts_with($_GET['return_url'], 'attendance.php')) {
        $returnUrl = $_GET['return_url'];
    }

    // Check if currently processing (Prevent double execution)
    $session_key = 'email_processing_' . $type . '_' . $id;
    if (isset($_SESSION[$session_key]) && $_SESSION[$session_key] > time() - 30) {
        die('Email is already being sent. Please wait a moment before trying again.');
    }
    
    // Set processing flag (valid for 30 seconds)
    $_SESSION[$session_key] = time();
    
    // Validate type
    if (!in_array($type, ['tardiness', 'absenteeism'])) {
        unset($_SESSION[$session_key]);
        die('Invalid type');
    }
    
    try {
        // Check if email has already been sent
        $checkStmt = $pdo->prepare("SELECT email_sent, full_name FROM $type WHERE id = ?");
        $checkStmt->execute([$id]);
        $checkResult = $checkStmt->fetch();

        if ($checkResult && $checkResult['email_sent'] == 1) {
            unset($_SESSION[$session_key]);
            $_SESSION['error'] = "Email has already been sent for " . $checkResult['full_name'];
            redirect($returnUrl);
            exit();
        }
        
        // Log for debugging
        error_log("=== Email Sending Started ===");
        error_log("Type: $type, ID: $id");
        error_log("Time: " . date('Y-m-d H:i:s'));
        
        // Get the record from Employee for details
        $stmt = $pdo->prepare("SELECT * FROM $type WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();

        if (!$record) {
            unset($_SESSION[$session_key]);
            die('Record not found');
        }

        // Get current user's details
        $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$_SESSION['user_id']]);
        $record2 = $userStmt->fetch();
        
        // Build recipients + HTML content (shared with the bulk-queue action in attendance.php)
        $built = buildInfractionEmail($pdo, $type, $record, $record2 ?: []);
        if (!$built) {
            unset($_SESSION[$session_key]);
            die("No valid email addresses found. At least one recipient is required.");
        }

        // Create PHPMailer instance
        $mail = new PHPMailer(true);

        // SMTP Configuration for Gmail
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'cxi-slm@communixinc.com';
        $mail->Password = 'lvxi sqrd tpvq bpgh';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // SMTP Options for better reliability
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        $mail->Timeout = 30;

        // Sender
        $mail->setFrom('cxi-slm@communixinc.com', 'CXI Service Level Management');

        foreach ($built['to'] as $addr) {
            $mail->addAddress($addr);
        }
        foreach ($built['cc'] as $addr) {
            $mail->addCC($addr);
        }

        $mail->isHTML(true);
        $mail->Subject = $built['subject'];
        $mail->Body = $built['body'];
        
        // Log before sending
        error_log("Sending email with subject: {$built['subject']}");
        error_log("Total recipients: " . (count($built['to']) + count($built['cc'])));
        
        // Send email
        $mail->send();
        
        // Update record to mark email as sent
        $updateStmt = $pdo->prepare("SET time_zone = '+08:00'; UPDATE $type SET email_sent = 1, email_sent_at = NOW() WHERE id = ?");
        $updateStmt->execute([$id]);
        $updateStmt->closeCursor();
        
        // Add 10 points to the user for sending the email
        $userId = $_SESSION['user_id'];
        $today = date('Y-m-d');
        $checkPointsStmt = $pdo->prepare("SELECT user_id FROM games_points WHERE user_id = ?");
        $checkPointsStmt->execute([$userId]);
        if ($checkPointsStmt->fetch()) {
            $pdo->prepare("UPDATE games_points SET points = points + 10 WHERE user_id = ?")->execute([$userId]);
        } else {
            $pdo->prepare("INSERT INTO games_points (user_id, points, last_reset_date) VALUES (?, 10, ?)")->execute([$userId, $today]);
        }
        
        // Get the record again for logging
        $stmt = $pdo->prepare("SELECT full_name FROM $type WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        $stmt->closeCursor();
        
        // Log the email activity
        logActivity("Sent email: '{$built['subject']}' to {$record['full_name']}", $id, $type);
        
        // Log success
        error_log("=== Email Sent Successfully ===");
        error_log("Recipient: {$record['full_name']}");
        error_log("=================================");
        
        // Clear processing flag
        unset($_SESSION[$session_key]);
        
        // Redirect back with success message
        $_SESSION['success'] = "Email sent successfully to " . $record['full_name'];
        redirect($returnUrl);
        exit();
                
    } catch (Exception $e) {
        // Clear processing flag on error
        unset($_SESSION[$session_key]);
        
        // Log error
        error_log("=== Email Sending Failed ===");
        error_log("Error: " . $e->getMessage());
        error_log("Mail Error: " . ($mail->ErrorInfo ?? 'N/A'));
        error_log("===========================");
        
        // Redirect back with error message
        $_SESSION['error'] = "Failed to send email: " . ($mail->ErrorInfo ?? $e->getMessage());
        redirect($returnUrl);
        exit();
    }
    
} else {
    die('Invalid request');
}