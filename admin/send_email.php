<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

    // Validate type early, before doing anything else.
    if (!in_array($type, ['tardiness', 'absenteeism'])) {
        die('Invalid type');
    }

    // SAFETY GATE: never send on a bare GET. GET requests can be triggered by things that
    // aren't the user deliberately clicking "Send" in the dashboard — a link preview, a
    // browser prefetching a hovered link, or (as happened) simply clicking the URL text while
    // browsing the global_notifications table in phpMyAdmin. Only an explicit POST — submitted
    // from the confirmation form below, or from the dashboard's own confirm dialog — is allowed
    // to actually send. A GET just shows this confirmation page; nothing happens until someone
    // deliberately clicks the button on it.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Confirm Send Email</title>
            <style>
                body { font-family: system-ui, sans-serif; background:#0f172a; color:#e2e8f0; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
                .box { background:#1e293b; border:1px solid rgba(255,255,255,.1); border-radius:12px; padding:28px 32px; max-width:380px; text-align:center; }
                h2 { margin-top:0; font-size:18px; }
                p { color:#94a3b8; font-size:14px; }
                .actions { display:flex; gap:10px; margin-top:20px; justify-content:center; }
                button, a.cancel { padding:10px 18px; border-radius:8px; font-weight:600; font-size:14px; text-decoration:none; border:none; cursor:pointer; }
                button { background:#0ea5e9; color:#fff; }
                a.cancel { background:rgba(255,255,255,.08); color:#e2e8f0; display:inline-flex; align-items:center; }
            </style>
        </head>
        <body>
            <div class="box">
                <h2>Send infraction email?</h2>
                <p>This will immediately email the <?= htmlspecialchars($type) ?> record #<?= (int)$id ?>. This cannot be undone from here.</p>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="send_email" value="<?= (int)$id ?>">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($returnUrl) ?>">
                    <div class="actions">
                        <a class="cancel" href="<?= htmlspecialchars($returnUrl) ?>">Cancel</a>
                        <button type="submit">Yes, send it</button>
                    </div>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit();
    }

    // From this point on, we only ever reach here via a deliberate POST submission.

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