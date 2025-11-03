<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isHR()) {
    redirect(BASE_URL);
}

$nte_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($nte_id <= 0) {
    redirect('nte_list.php');
}

// Fetch NTE data
$stmt = $pdo->prepare("SELECT nte.*, ir.infraction as ir_infraction, ir.incident_details as original_incident
                      FROM notice_to_explain nte
                      JOIN incident_report ir ON nte.ir_id = ir.id
                      WHERE nte.id = ?");
$stmt->execute([$nte_id]);
$nte = $stmt->fetch();

if (!$nte) {
    $_SESSION['error'] = "NTE not found";
    redirect('nte_list.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notice to Explain - <?= htmlspecialchars($nte['employee_id']) ?></title>
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .print-break { page-break-after: always; }
        }
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px;
            line-height: 1.4;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 15px 0;
        }
        th, td { 
            border: 1px solid #333; 
            padding: 8px 12px; 
            text-align: left;
        }
        th { 
            background-color: #f5f5f5; 
            font-weight: bold;
        }
        .signature-section { 
            margin-top: 50px;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 60px;
            padding-top: 5px;
        }
        .footer {
            margin-top: 50px;
            font-size: 10px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">
            🖨️ Print NTE
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
            ❌ Close
        </button>
    </div>

    <div class="header">
        <h1>CXI HUMAN RESOURCES</h1>
        <h2>NOTICE TO EXPLAIN</h2>
    </div>

    <table>
        <tr>
            <th width="25%">EMP NAME:</th>
            <td width="25%"><?= htmlspecialchars($nte['full_name']) ?></td>
            <th width="25%">EMP ID:</th>
            <td width="25%"><?= htmlspecialchars($nte['employee_id']) ?></td>
        </tr>
        <tr>
            <th>SUPERVISOR NAME:</th>
            <td><?= htmlspecialchars($nte['supervisor_name'] ?? 'N/A') ?></td>
            <th>DATE CREATED:</th>
            <td><?= date('F d, Y', strtotime($nte['created_at'])) ?></td>
        </tr>
        <tr>
            <th>MANAGER'S NAME:</th>
            <td><?= htmlspecialchars($nte['manager_name'] ?? 'N/A') ?></td>
            <th>DEPARTMENT:</th>
            <td><?= htmlspecialchars($nte['department']) ?></td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>DATE/S OF VIOLATION</th>
                <th>SCHEDULE FOR THE DATE/S MENTIONED</th>
                <th>MIN/S FOR TARDINESS<br>DAY/S FOR ABSENCES</th>
                <th>WAS THE EMPLOYEE ABLE TO FOLLOW THE NOTIFICATION PROCEDURE?</th>
                <th>REASON/S PROVIDED</th>
                <th>DOCUMENT/S PRESENTED</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= date('F d, Y', strtotime($nte['date_of_incident'])) ?></td>
                <td><?= htmlspecialchars($nte['shift']) ?></td>
                <td><?= htmlspecialchars($nte['violation_duration'] ?? '1 Day') ?></td>
                <td><?= htmlspecialchars($nte['notification_procedure'] ?? 'YES') ?></td>
                <td><?= htmlspecialchars($nte['reason_provided'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($nte['documents_presented'] ?? 'N/A') ?></td>
            </tr>
        </tbody>
    </table>

    <table>
        <tr>
            <th width="30%">SANCTION BEING SERVED</th>
            <td width="70%"><?= htmlspecialchars($nte['sanction_proposed']) ?></td>
        </tr>
        <tr>
            <th>RULE SECTION</th>
            <td><?= htmlspecialchars($nte['rule_section']) ?> - <?= htmlspecialchars($nte['nature_of_offense']) ?></td>
        </tr>
    </table>

    <table>
        <tr>
            <th width="25%">1st INSTANCE</th>
            <th width="25%">2nd INSTANCE</th>
            <th width="25%">3rd INSTANCE</th>
            <th width="25%">4th INSTANCE</th>
        </tr>
        <tr>
            <td>VERBAL WARNING</td>
            <td>WRITTEN WARNING</td>
            <td>FINAL WRITTEN WARNING WITH 5 DAYS SUSPENSION</td>
            <td>DISMISSAL</td>
        </tr>
    </table>

    <p style="margin-top: 20px;">
        Meanwhile, we would like to hear your side regarding this incident Report and we will wait for your response within the next One hundred twenty (120) hours. Equivalent to 5 calendar days. Upon serving and acknowledging this document, failure to comply with this notice shall mean a waiver of your right to be heard and the corresponding correcting measure against you will be imposed.
    </p>

    <hr style="margin: 30px 0;">

    <h3>EXPLANATION</h3>
    <div style="min-height: 100px; border: 1px solid #333; padding: 10px; margin-bottom: 20px;">
        <?= nl2br(htmlspecialchars($nte['employee_explanation'] ?? 'To be filled by employee...')) ?>
    </div>

    <h3>ACTION PLAN AND COMMITMENT</h3>
    <div style="min-height: 100px; border: 1px solid #333; padding: 10px; margin-bottom: 20px;">
        <?= nl2br(htmlspecialchars($nte['action_plan'] ?? 'To be filled by employee...')) ?>
    </div>

    <div class="signature-section">
        <table style="border: none;">
            <tr>
                <td style="border: none; width: 33%; text-align: center;">
                    <div class="signature-line"></div>
                    <strong>PREPARED BY:</strong><br>
                    HR Representative<br>
                    Human Resources
                </td>
                <td style="border: none; width: 33%; text-align: center;">
                    <div class="signature-line"></div>
                    <strong>REVIEWED BY:</strong><br>
                    <?= htmlspecialchars($nte['manager_name'] ?? 'Manager') ?><br>
                    Manager
                </td>
                <td style="border: none; width: 33%; text-align: center;">
                    <div class="signature-line"></div>
                    <strong>ACKNOWLEDGED BY:</strong><br>
                    <?= htmlspecialchars($nte['full_name']) ?><br>
                    Employee
                </td>
            </tr>
            <tr>
                <td style="border: none; text-align: center;">
                    Date Issued: <?= date('M d, Y', strtotime($nte['date_issued'] ?? $nte['created_at'])) ?>
                </td>
                <td style="border: none; text-align: center;">
                    Date Signed: ________________
                </td>
                <td style="border: none; text-align: center;">
                    Date Signed: ________________
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <p><em>The content of this document is confidential and intended for the recipient specified herewith only. It is strictly forbidden for this document to be shared with any third party and be brought elsewhere.</em></p>
        <p>CXI-SLM-06747</p>
    </div>
</body>
</html>