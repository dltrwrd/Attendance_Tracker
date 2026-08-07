<?php
ob_start();
session_start(); 
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php'; 
require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    // 1. Fetch ticket details
    $stmt = $pdo->prepare("SELECT * FROM network_tickets WHERE id = ?");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch();

    // 2. Fetch current user's details for the signature (from users table)
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $record2 = $userStmt->fetch();

    if (!$ticket || !$record2) {
        echo json_encode(['success' => false, 'message' => 'Required details not found']);
        exit;
    }

    $mail = new PHPMailer(true);

    try {
        // SMTP Server Settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'cxi-slm@communixinc.com'; 
        $mail->Password   = 'lvxi sqrd tpvq bpgh'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('cxi-slm@communixinc.com', 'CXI Service Level Management');
        $mail->addAddress('phay.barrameda@communixinc.com'); 
        $mail->addCC('cxi-slt@communixinc.com'); 

        // Content
        $ticketID = $ticket['SLT_TICKET_ID'] ?? $ticket['id'];
        $referenceLink = !empty($ticket['email_link']) ? $ticket['email_link'] : 'No link provided';

        $mail->isHTML(true);
        $mail->Subject = "Terabit Tracker - Ticket ID: " . $ticketID;
        
        // Email Body
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px;'>
                <p>Hi team,</p>
                <p>I hope this email finds you well.</p>
                <p>This is to provide a notification regarding a ticket entry in the Terabit Tracker. Please find the ticket details below:</p>
                
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 5px 0; color: #666; width: 120px;'><b>Ticket ID:</b></td>
                        <td style='padding: 5px 0; font-family: monospace; font-weight: bold;'>{$ticketID}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'><b>Subject:</b></td>
                        <td style='padding: 5px 0;'>" . htmlspecialchars($ticket['subject']) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'><b>Status:</b></td>
                        <td style='padding: 5px 0;'>" . strtoupper($ticket['status']) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'><b>Reference:</b></td>
                        <td style='padding: 5px 0;'>" . ($referenceLink !== 'No link provided' ? "<a href='{$referenceLink}' style='color: #3c78fa; text-decoration: underline;'>View Reference</a>" : "<i>No link provided</i>") . "</td>
                    </tr>
                </table>

                <p>Best regards,</p>
                
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
            </div>
        ";

        $mail->send();

        // Update Database
        $update = $pdo->prepare("UPDATE network_tickets SET email_sent = 1 WHERE id = ?");
        $update->execute([$id]);
        
        logActivity("Automated email sent for Ticket #$id", $id, 'terabit');
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $mail->ErrorInfo]);
    }
}