<?php
// Shared recipient + HTML content builder for tardiness/absenteeism infraction notices.
// Used by admin/send_email.php (single record) and the bulk-queue action in admin/attendance.php,
// so the email template only lives in one place.

function buildInfractionEmail(PDO $pdo, string $type, array $record, array $sender): ?array {
    $stmt = $pdo->prepare("
        SELECT
            CONCAT(
                UPPER(SUBSTRING(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ',', -1), ' ', 2)), 1, 1)),
                LOWER(SUBSTRING(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ',', -1), ' ', 2)), 2))
            ) AS first_name
        FROM $type
        WHERE full_name = ?
    ");
    $stmt->execute([$record['full_name']]);
    $nameRow = $stmt->fetch();
    $firstName = $nameRow['first_name'] ?? $record['full_name'];

    $stmt = $pdo->prepare("SELECT * FROM management WHERE fullname = ?");
    $stmt->execute([$record['supervisor']]);
    $supervisor = $stmt->fetch() ?: [];

    $stmt = $pdo->prepare("SELECT * FROM operations_managers WHERE fullname = ?");
    $stmt->execute([$record['operation_manager']]);
    $om = $stmt->fetch() ?: [];

    $agentEmail = null;
    if (!empty($record['email']) && filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
        $agentEmail = $record['email'];
    } elseif (!empty($supervisor['email']) && filter_var($supervisor['email'], FILTER_VALIDATE_EMAIL)) {
        $agentEmail = $supervisor['email'];
    }
    $supervisorEmail = (!empty($supervisor['email']) && filter_var($supervisor['email'], FILTER_VALIDATE_EMAIL)) ? $supervisor['email'] : null;
    $omEmail = (!empty($om['email']) && filter_var($om['email'], FILTER_VALIDATE_EMAIL)) ? $om['email'] : null;

    $to = [];
    if ($agentEmail) $to[strtolower(trim($agentEmail))] = $agentEmail;
    if ($omEmail) $to[strtolower(trim($omEmail))] = $omEmail;
    if ($supervisorEmail) $to[strtolower(trim($supervisorEmail))] = $supervisorEmail;

    if (empty($to)) {
        return null;
    }

    $ccPool = [
        'kiko.barrameda@communixinc.com',
        'phay.barrameda@communixinc.com',
        'cxi-slt@communixinc.com',
        'cxi-slm@communixinc.com',
        'ken.munoz@communixinc.com',
        'humanresources@communixinc.com',
        'cxi-hr@communixinc.com',
        'cxi.clinic@communixinc.com',
    ];
    if ($type === 'tardiness') {
        $ccPool = array_filter($ccPool, fn($e) => $e !== 'cxi.clinic@communixinc.com');
    }
    $cc = [];
    foreach ($ccPool as $addr) {
        if (!isset($to[strtolower($addr)])) {
            $cc[strtolower($addr)] = $addr;
        }
    }

    $senderName = htmlspecialchars($sender['fullname'] ?? 'CXI Services Inc');
    $senderEmail = htmlspecialchars($sender['slt_email'] ?? 'cxi-slm@communixinc.com');

    $signature = "
        <table border=\"0\" cellspacing=\"0\" cellpadding=\"0\" style=\"margin-top: 15px;\">
            <tr>
                <td valign=\"top\" style=\"padding-right: 15px;\">
                    <img src=\"https://lh7-us.googleusercontent.com/hKfBBQPswq1rr28KAdC4A3hrJxQw4kwPsT9_aIPTcLxO5GSreRobUkI6AnEfxbu2A5iircddGLupW7i5J-Ky7Avxq3Fg8rz1qDJWoDcsPBR_ui5hsE6sP09jDrZl7jvnOVonYOPz2ofYiDR4g62vhRY\" alt=\"CXI Logo\" width=\"200\" style=\"display: block;\">
                </td>
                <td valign=\"top\">
                    <p style=\"margin: 0; font-weight: bold; color: #333;\">{$senderName}</p>
                    <p style=\"margin: 5px 0 0 0; color: #555;\">CXI Services Inc</p>
                    <p style=\"margin: 5px 0 0 0; color: #555;\">Service Level Technician</p>
                    <p style=\"margin: 5px 0 0 0;\">
                        <img src=\"https://lightpink-cormorant-243207.hostingersite.com/assets/email.png\" width=\"16\" height=\"16\" style=\"vertical-align: middle; margin-right: 5px;\">
                        <a href=\"mailto:{$senderEmail}\" style=\"color: #0066cc; text-decoration: none;\">{$senderEmail}</a>
                    </p>
                    <p style=\"margin: 5px 0 0 0;\">
                        <img src=\"https://lightpink-cormorant-243207.hostingersite.com/assets/globe.png\" width=\"16\" height=\"16\" style=\"vertical-align: middle; margin-right: 5px;\">
                        <a href=\"https://www.cxiph.com\" style=\"color: #0066cc; text-decoration: none;\">www.cxiph.com</a>
                    </p>
                </td>
            </tr>
        </table>
    ";

    if ($type === 'absenteeism') {
        $subject = strtoupper($record['sanction']) . " - " . strtoupper($record['full_name']) . " - " . date('M d, Y', strtotime($record['date_of_absent']));
        $body = "
        <html><body>
            <p>Dear " . htmlspecialchars($firstName) . ",</p>
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
            {$signature}
        </body></html>";
    } else { // tardiness
        $subject = "TARDINESS - " . strtoupper($record['full_name']) . " - " . date('M d, Y', strtotime($record['date_of_incident']));
        $body = "
        <html><body>
            <p>Dear " . htmlspecialchars($firstName) . ",</p>
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
            {$signature}
        </body></html>";
    }

    return [
        'to' => array_values($to),
        'cc' => array_values($cc),
        'subject' => $subject,
        'body' => $body,
    ];
}
