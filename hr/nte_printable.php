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

// Determine which instance should be highlighted
$sanction_proposed = $nte['sanction_proposed'] ?? '';
$highlight_instance = '';

if (stripos($sanction_proposed, 'verbal') !== false) {
    $highlight_instance = '1st';
} elseif (stripos($sanction_proposed, 'written') !== false && stripos($sanction_proposed, 'final') === false) {
    $highlight_instance = '2nd';
} elseif (stripos($sanction_proposed, 'final') !== false || stripos($sanction_proposed, 'suspension') !== false) {
    $highlight_instance = '3rd';
} elseif (stripos($sanction_proposed, 'dismissal') !== false) {
    $highlight_instance = '4th';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notice to Explain - <?= htmlspecialchars($nte['employee_id']) ?></title>
    <style>
        /* Reset and base styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: Arial, sans-serif; 
            margin: 0;
            padding: 0;
            line-height: 1.3;
            font-size: 12px;
            background: white;
            width: 21cm;
            height: 29.7cm;
            margin: 0 auto;
        }
        
        .container {
            padding: 15px;
            border: 2px solid #000;
            border-top: 20px solid #000;
            width: 21cm;
            height: 29.7cm;
            position: relative;
        }
        
        .header { 
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #000;
        }
        
        .header > .header-text > h1, .header > .header-text > h2 {
            padding: 0;
            margin: 2px 0;
            font-family: "Arial Black", "Impact", sans-serif;
            line-height: 1.1;
        }
        
        .header > .header-text > h1 {
            font-size: 24px;
            font-weight: 900;
        }
        
        .header > .header-text > h2 {
            font-size: 18px;
            font-weight: bold;
        }
        
        .header > .header-image > img {
            width: 80px;
            height: auto;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 8px 0;
            font-size: 11px;
        }
        
        th, td { 
            border: 1px solid #333; 
            padding: 6px 8px; 
            text-align: left;
            line-height: 1.2;
            vertical-align: top;
        }
        
        th { 
            background-color: #d9d9d9 !important; 
            font-weight: bold;
            font-size: 10px;
        }
        
        .highlight-yellow {
            background-color: #ffff00 !important;
            font-weight: bold;
            color: #000000 !important;
        }
        
        .black-header {
            background-color: #000000 !important;
            color: white !important;
            padding: 2px;
            text-align: center;
            margin: 14px 0 6px 0;
            font-size: 12px;
            font-weight: bold;
        }
        
        .signature-section { 
            margin-top: 25px;
            position: absolute;
            bottom: 80px;
            width: calc(100% - 30px);
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 40px;
            width: 90%;
            margin-left: auto;
            margin-right: auto;
        }
        
        .footer {
            position: absolute;
            bottom: 20px;
            width: calc(100% - 30px);
            font-size: 9px;
            color: #666;
            text-align: center;
        }
        
        p {
            margin: 8px 0;
            font-size: 11px;
            line-height: 1.3;
            text-align: justify;
        }
        
        .explanation-box, .actionplan-box {
            min-height: 130px;
            border: 1px solid #333;
            padding: 10px;
            margin-bottom: 20px;
            font-size: 11px;
            line-height: 1.3;
        }

        /* Specific table adjustments */
        .violation-table th {
            font-size: 9px;
            padding: 4px 6px;
        }
        
        .violation-table td {
            font-size: 10px;
            padding: 4px 6px;
        }

        .instance-table th,
        .instance-table td {
            text-align: center;
            font-size: 10px;
            padding: 8px 4px;
        }

        /* Print-specific styles */
        @media print {
            @page {
                size: A4;
                margin: 0;
                padding: 20px;
            }
            
            body { 
                margin: 0 !important; 
                padding: 0 !important;
                width: 21cm !important;
                height: 29.7cm !important;
                font-size: 12px !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                background: white !important;
            }
            
            .no-print { 
                display: none !important; 
            }
            
            .container {
                width: 21cm !important;
                height: 29.7cm !important;
                margin: 0 !important;
                padding: 15px !important;
                border: 2px solid #000 !important;
                border-top: 20px solid #000 !important;
                page-break-after: avoid;
                page-break-inside: avoid;
            }
            
            .highlight-yellow {
                background-color: #ffff00 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .black-header {
                background-color: #000000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            th { 
                background-color: #d9d9d9 !important; 
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }

        /* Screen preview styles */
        @media screen {
            body {
                padding: 20px;
                background: #f0f0f0;
            }
            
            .container {
                background: white;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; margin: 5px;">
            🖨️ Print NTE
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; margin: 5px;">
            ❌ Close
        </button>
    </div>

    <div class="container">
        <div class="header">
            <div class="header-text">
                <h1>CXI HUMAN RESOURCES</h1>
                <h2>NOTICE TO EXPLAIN</h2>
            </div>
            <div class="header-image">
                <img src="../assets/cxilogo.png" alt="CXI Services Inc">
            </div>
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
                <td><?= htmlspecialchars($nte['supervisor'] ?? 'N/A') ?></td>
                <th>DATE CREATED:</th>
                <td><?= date('F d, Y', strtotime($nte['created_at'])) ?></td>
            </tr>
            <tr>
                <th>MANAGER'S NAME:</th>
                <td><?= htmlspecialchars($nte['operation_manager'] ?? 'N/A') ?></td>
                <th>DEPARTMENT:</th>
                <td><?= htmlspecialchars($nte['department']) ?></td>
            </tr>
        </table>

        <table class="violation-table">
            <thead>
                <tr>
                    <th width="14%">DATE/S OF VIOLATION</th>
                    <th width="16%">SCHEDULE FOR THE DATE/S MENTIONED</th>
                    <th width="12%">MIN/S FOR TARDINESS<br>DAY/S FOR ABSENCES</th>
                    <th width="20%">WAS THE EMPLOYEE ABLE TO FOLLOW THE NOTIFICATION PROCEDURE?</th>
                    <th width="23%">REASON/S PROVIDED</th>
                    <th width="15%">DOCUMENT/S PRESENTED</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= date('F d, Y', strtotime($nte['date_of_incident'])) ?></td>
                    <td><?= htmlspecialchars($nte['shift']) ?></td>
                    <td><?= htmlspecialchars($nte['violation_duration'] ?? '1 Day') ?></td>
                    <td><?= htmlspecialchars($nte['notification_procedure'] ?? 'YES') ?></td>
                    <td><?= htmlspecialchars($nte['incident_details'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($nte['documents_presented'] ?? 'N/A') ?></td>
                </tr>
            </tbody>
        </table>

        <table>
            <tr>
                <th width="20%">SANCTION BEING SERVED</th>
                <td width="20%"><?= htmlspecialchars($nte['rule_section']) ?></td>
                <td width="20%"><?= htmlspecialchars($nte['nature_of_offense']) ?></td>
                <td width="40%"><?= htmlspecialchars($nte['specific_offenses']) ?></td>
            </tr>
        </table>

        <table class="instance-table">
            <tr>
                <th width="25%" >1st INSTANCE</th>
                <th width="25%" >2nd INSTANCE</th>
                <th width="25%" >3rd INSTANCE</th>
                <th width="25%" >4th INSTANCE</th>
            </tr>
            <tr>
                <td class="<?= $highlight_instance === '1st' ? 'highlight-yellow' : '' ?>">VERBAL WARNING</td>
                <td class="<?= $highlight_instance === '2nd' ? 'highlight-yellow' : '' ?>">WRITTEN WARNING</td>
                <td class="<?= $highlight_instance === '3rd' ? 'highlight-yellow' : '' ?>">FINAL WRITTEN WARNING WITH 5 DAYS SUSPENSION</td>
                <td class="<?= $highlight_instance === '4th' ? 'highlight-yellow' : '' ?>">DISMISSAL</td>
            </tr>
        </table>

        <p style="margin-top: 15px;">
            Meanwhile, we would like to hear your side regarding this Incident Report and we will wait for your response within the next One hundred twenty (120) hours. Equivalent to 5 calendar days. Upon serving and acknowledging this document, failure to comply with this notice shall mean a waiver of your right to be heard and the corresponding correcting measure against you will be imposed.
        </p>

        <hr style="margin: 15px 0; border: 1px solid #333;">

        <div class="black-header">EXPLANATION</div>
        <div class="explanation-box">
            <?= nl2br(htmlspecialchars($nte['employee_explanation'] ?? '')) ?>
        </div>

        <div class="black-header">ACTION PLAN AND COMMITMENT</div>
        <div class="actionplan-box">
            <?= nl2br(htmlspecialchars($nte['action_plan'] ?? '')) ?>
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
                        <?= htmlspecialchars($nte['operation_manager'] ?? 'Manager') ?><br>
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
                    <td style="border: none; text-align: center; font-size: 9px;">
                        Date Issued: <?= date('M d, Y', strtotime($nte['date_issued'] ?? $nte['created_at'])) ?>
                    </td>
                    <td style="border: none; text-align: center; font-size: 9px;">
                        Date Signed: ________________
                    </td>
                    <td style="border: none; text-align: center; font-size: 9px;">
                        Date Signed: ________________
                    </td>
                </tr>
            </table>
        </div>

        <div class="footer" style="display: flex-1;">
            <p style="text-align: center; width: 100%;"><em>The content of this document is confidential and intended for the recipient specified herewith only. It is strictly forbidden for this document to be shared with any third party and be brought elsewhere.</em></p>
            <p style="text-align: center; width: 100%;">CXI-SLM-06747</p>
        </div>
    </div>
</body>
</html>