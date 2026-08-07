<?php
session_start(); // Add session_start() at the beginning

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_GET['send_email']) && isset($_GET['type'])) {
    $id = (int)$_GET['send_email'];
    $type = $_GET['type'];
    
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
            redirect('attendance.php?tab=' . $type);
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
        
        // Get the first name format
        $stmt = $pdo->prepare("
            SELECT 
                CONCAT(
                    UPPER(SUBSTRING(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ',', -1), ' ', 2)), 1, 1)),
                    LOWER(SUBSTRING(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ',', -1), ' ', 2)), 2))
                ) AS first_name 
            FROM $type 
            WHERE full_name = ?;
        ");
        $stmt->execute([$record['full_name']]);
        $record3 = $stmt->fetch();
        
        // Get the supervisor details
        $stmt = $pdo->prepare("SELECT * FROM management WHERE fullname = ?");
        $stmt->execute([$record['supervisor']]);
        $record4 = $stmt->fetch();
        
        // Get the operation manager details
        $stmt = $pdo->prepare("SELECT * FROM operations_managers WHERE fullname = ?");
        $stmt->execute([$record['operation_manager']]);
        $record5 = $stmt->fetch();
        
        // Validate and prepare email addresses
        $agentEmail = null;
        $supervisorEmail = null;
        $omEmail = null;
        
        // Agent Email (check both record email and supervisor email as fallback)
        if (!empty($record['email']) && filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
            $agentEmail = $record['email'];
        } elseif (!empty($record4['email']) && filter_var($record4['email'], FILTER_VALIDATE_EMAIL)) {
            $agentEmail = $record4['email'];
        }
        
        // Supervisor Email
        if (!empty($record4['email']) && filter_var($record4['email'], FILTER_VALIDATE_EMAIL)) {
            $supervisorEmail = $record4['email'];
        }
        
        // Operation Manager Email
        if (!empty($record5['email']) && filter_var($record5['email'], FILTER_VALIDATE_EMAIL)) {
            $omEmail = $record5['email'];
        }
        
        // Log email addresses for debugging
        error_log("Agent Email: " . ($agentEmail ?? 'NOT FOUND'));
        error_log("Supervisor Email: " . ($supervisorEmail ?? 'NOT FOUND'));
        error_log("Operation Manager Email: " . ($omEmail ?? 'NOT FOUND'));
        
        // Validate required emails
        $requiredEmails = [];
        if ($agentEmail) $requiredEmails['Agent/Supervisor'] = $agentEmail;
        if ($omEmail) $requiredEmails['Operation Manager'] = $omEmail;
        
        if (empty($requiredEmails)) {
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
        
        // Track added emails to avoid duplicates
        $addedEmails = [];
        
        // Add recipients - Avoid duplicates
        if ($agentEmail) {
            $mail->addAddress($agentEmail);
            $addedEmails[] = strtolower(trim($agentEmail));
            error_log("Added Agent: $agentEmail");
        }
        
        // Add Operation Manager (required)
        if ($omEmail) {
            $omEmailLower = strtolower(trim($omEmail));
            if (!in_array($omEmailLower, $addedEmails)) {
                $mail->addAddress($omEmail);
                $addedEmails[] = $omEmailLower;
                error_log("Added OM: $omEmail");
            } else {
                error_log("OM email already added: $omEmail");
            }
        }
        
        // Add Supervisor if different from agent
        if ($supervisorEmail && $supervisorEmail !== $agentEmail) {
            $supEmailLower = strtolower(trim($supervisorEmail));
            if (!in_array($supEmailLower, $addedEmails)) {
                $mail->addAddress($supervisorEmail);
                $addedEmails[] = $supEmailLower;
                error_log("Added Supervisor: $supervisorEmail");
            } else {
                error_log("Supervisor email already added: $supervisorEmail");
            }
        }
        
        // Default cc for the bosses
        $ccEmails = [
            'kiko.barrameda@communixinc.com',
            'phay.barrameda@communixinc.com',
            'cxi-slt@communixinc.com',
            'cxi-slm@communixinc.com',
            'ken.munoz@communixinc.com',
            'humanresources@communixinc.com',
            'cxi-hr@communixinc.com',
            'cxi.clinic@communixinc.com'
        ];

        // Remove cxi.clinic@communixinc.com if type is tardiness
        if ($type === 'tardiness') {
            $ccEmails = array_filter($ccEmails, function($email) {
                return $email !== 'cxi.clinic@communixinc.com';
            });
        }

        // Add CC emails - Avoid duplicates
        foreach ($ccEmails as $ccEmail) {
            if (filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                $ccEmailLower = strtolower(trim($ccEmail));
                if (!in_array($ccEmailLower, $addedEmails)) {
                    $mail->addCC($ccEmail);
                    $addedEmails[] = $ccEmailLower;
                }
            }
        }
        
        // Email content
        if ($type === 'absenteeism') {
            $subject = strtoupper($record['sanction']) . " - " . strtoupper($record['full_name']) . " - " . date('M d, Y', strtotime($record['date_of_absent']));
            
            $body = "
            <html>
            <body>
                <p>Dear " . htmlspecialchars($record3['first_name']) . ",</p>
                
                <p>I hope this email finds you well. This is to keep track of the attendance infractions incurred.
                Please find the details below:</p>
                
                <p><strong>Employee ID:</strong> " . htmlspecialchars($record['employee_id']) . "<br>
                <strong>Name of Employee:</strong> " . htmlspecialchars($record['full_name']) . "<br>
                <strong>DEPARTMENT:</strong> " . htmlspecialchars($record['department']) . "<br>
                <strong>SUPERVISOR:</strong> " . htmlspecialchars($record['supervisor']) . "<br>
                <strong>OM:</strong> " . htmlspecialchars($record['operation_manager']) . "<br>
                <strong>Date of Absenteeism:</strong> " . date('M d, Y', strtotime($record['date_of_absent'])) . "<br>
                <strong>Scheduled Shift:</strong> " . htmlspecialchars($record['shift']) . "<br>
                <strong>Reason for Absence:</strong> " . htmlspecialchars($record['reason']) . "<br>
                <strong>Received advise in SLT number:</strong> " . htmlspecialchars($record['follow_call_in_procedure']) . "</p>
                
                <p>We understand that unforeseen circumstances may arise occasionally, resulting in unavoidable absences/late arrivals.</p>
                
                <p>If any personal or professional challenges are affecting your attendance, please don't hesitate to discuss them with your supervisor.</p>
                
                <p>Remember that consistent punctuality and attendance are crucial for your professional development and overall success within our organization. It also demonstrates your commitment to your responsibilities and the team.</p>
                
                <p>If you have any questions or concerns, you may always reach out to our SLT email at <a href=\"mailto:cxi-slm@communixinc.com\">cxi-slm@communixinc.com</a> or the following hotlines:</p>
                
                <p>Mana #: 0931-107-2077</p>
                
                <p>Best regards,<br></p>
                
                <table border=\"0\" cellspacing=\"0\" cellpadding=\"0\" style=\"margin-top: 15px;\">
                    <tr>
                        <td valign=\"top\" style=\"padding-right: 15px;\">
                            <img src=\"https://lh7-us.googleusercontent.com/hKfBBQPswq1rr28KAdC4A3hrJxQw4kwPsT9_aIPTcLxO5GSreRobUkI6AnEfxbu2A5iircddGLupW7i5J-Ky7Avxq3Fg8rz1qDJWoDcsPBR_ui5hsE6sP09jDrZl7jvnOVonYOPz2ofYiDR4g62vhRY\" alt=\"CXI Logo\" width=\"200\" style=\"display: block;\">
                        </td>
                        <td valign=\"top\">
                            <p style=\"margin: 0; font-weight: bold; color: #333;\">" . htmlspecialchars($record2['fullname']) . "</p>
                            <p style=\"margin: 5px 0 0 0; color: #555;\">CXI Services Inc</p>
                            <p style=\"margin: 5px 0 0 0; color: #555;\">Service Level Technician</p>
                            <p style=\"margin: 5px 0 0 0;\">
                                <img src=\"https://lightpink-cormorant-243207.hostingersite.com/assets/email.png\" width=\"16\" height=\"16\" style=\"vertical-align: middle; margin-right: 5px;\">
                                <a href=\"mailto:" . htmlspecialchars($record2['slt_email']) . "\" style=\"color: #0066cc; text-decoration: none;\">" . htmlspecialchars($record2['slt_email']) . "</a>
                            </p>
                            <p style=\"margin: 5px 0 0 0;\">
                                <img src=\"https://lightpink-cormorant-243207.hostingersite.com/assets/globe.png\" width=\"16\" height=\"16\" style=\"vertical-align: middle; margin-right: 5px;\">
                                <a href=\"https://www.cxiph.com\" style=\"color: #0066cc; text-decoration: none;\">www.cxiph.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </body>
            </html>
            ";
        } else { // Tardiness
            $subject = "TARDINESS - " . strtoupper($record['full_name']) . " - " . date('M d, Y', strtotime($record['date_of_incident']));
            
            $body = "
            <html>
            <body>
                <p>Dear " . htmlspecialchars($record3['first_name']) . ",</p>
                
                <p>I hope this email finds you well. This is to keep track of the attendance infractions incurred. Please find the details below:</p>
                
                <p><strong>Employee ID:</strong> " . htmlspecialchars($record['employee_id']) . "<br>
                <strong>Name of Employee:</strong> " . htmlspecialchars($record['full_name']) . "<br>
                <strong>DEPARTMENT:</strong> " . htmlspecialchars($record['department']) . "<br>
                <strong>SUPERVISOR:</strong> " . htmlspecialchars($record['supervisor']) . "<br>
                <strong>OM:</strong> " . htmlspecialchars($record['operation_manager']) . "<br>
                <strong>Date of Tardiness:</strong> " . date('M d, Y', strtotime($record['date_of_incident'])) . "<br>
                <strong>Scheduled Shift:</strong> " . htmlspecialchars($record['shift']) . "<br>
                <strong>Time IN:</strong> " . htmlspecialchars($record['time_in']) . "<br>
                <strong>Minutes of Late:</strong> " . htmlspecialchars($record['minutes_late']) . " minutes</p>
                
                <p>We understand that unforeseen circumstances may arise occasionally, resulting in unavoidable absences/late arrivals.</p>
                
                <p>If any personal or professional challenges are affecting your attendance, please don't hesitate to discuss them with your supervisor.</p>
                
                <p>Remember that consistent punctuality and attendance are crucial for your professional development and overall success within our organization. It also demonstrates your commitment to your responsibilities and the team.</p>
                
                <p>If you have any questions or concerns, you may always reach out to our SLT email at <a href=\"mailto:cxi-slm@communixinc.com\">cxi-slm@communixinc.com</a> or the following hotlines:</p>
                
                <p>Mana #: 0931-107-2077</p>
                
                <p>Best regards,<br></p>
                
                <table border=\"0\" cellspacing=\"0\" cellpadding=\"0\" style=\"margin-top: 15px;\">
                    <tr>
                        <td valign=\"top\" style=\"padding-right: 15px;\">
                            <img src=\"https://lh7-us.googleusercontent.com/hKfBBQPswq1rr28KAdC4A3hrJxQw4kwPsT9_aIPTcLxO5GSreRobUkI6AnEfxbu2A5iircddGLupW7i5J-Ky7Avxq3Fg8rz1qDJWoDcsPBR_ui5hsE6sP09jDrZl7jvnOVonYOPz2ofYiDR4g62vhRY\" alt=\"CXI Logo\" width=\"200\" style=\"display: block;\">
                        </td>
                        <td valign=\"top\">
                            <p style=\"margin: 0; font-weight: bold; color: #333;\">" . htmlspecialchars($record2['fullname']) . "</p>
                            <p style=\"margin: 5px 0 0 0; color: #555;\">CXI Services Inc</p>
                            <p style=\"margin: 5px 0 0 0; color: #555;\">Service Level Technician</p>
                            <p style=\"margin: 5px 0 0 0;\">
                                <img src=\"https://lightpink-cormorant-243207.hostingersite.com/assets/email.png\" width=\"16\" height=\"16\" style=\"vertical-align: middle; margin-right: 5px;\">
                                <a href=\"mailto:" . htmlspecialchars($record2['slt_email']) . "\" style=\"color: #0066cc; text-decoration: none;\">" . htmlspecialchars($record2['slt_email']) . "</a>
                            </p>
                            <p style=\"margin: 5px 0 0 0;\">
                                <img src=\"https://lightpink-cormorant-243207.hostingersite.com/assets/globe.png\" width=\"16\" height=\"16\" style=\"vertical-align: middle; margin-right: 5px;\">
                                <a href=\"https://www.cxiph.com\" style=\"color: #0066cc; text-decoration: none;\">www.cxiph.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
                
            </body>
            </html>
            ";
        }
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        // Log before sending
        error_log("Sending email with subject: $subject");
        error_log("Total recipients: " . count($addedEmails));
        
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
        logActivity("Sent email: '{$subject}' to {$record['full_name']}", $id, $type);
        
        // Log success
        error_log("=== Email Sent Successfully ===");
        error_log("Recipient: {$record['full_name']}");
        error_log("=================================");
        
        // Clear processing flag
        unset($_SESSION[$session_key]);
        
        // Redirect back with success message
        $_SESSION['success'] = "Email sent successfully to " . $record['full_name'];
        redirect('attendance.php?tab=' . $type);
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
        redirect('attendance.php?tab=' . $type);
        exit();
    }
    
} else {
    die('Invalid request');
}