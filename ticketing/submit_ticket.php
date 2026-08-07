<?php
function submit_ticket(PDO $pdo, array $ticketData) {
    try {

        $query = "INSERT INTO ticket (
            Timestamp, Email_Address, Site, Affected_employee, EID, Issues_Concerning, 
            Issue_Details, Station_Number, TIME_RECEIVED, TIME_RESOLVED, SLT_on_DUTY, 
            Week_Beginning, LOB, OM, Employee_name, Work_Number, Status, sub_cat, Urgency, issue_img
        ) VALUES (
            :Timestamp, :Email_Address, :Site, :Affected_employee, :EID, :Issues_Concerning, 
            :Issue_Details, :Station_Number, :TIME_RECEIVED, :TIME_RESOLVED, :SLT_on_DUTY, 
            :Week_Beginning, :LOB, :OM, :Employee_name, :Work_Number, :Status, :sub_cat, :Urgency, :issue_img
        )";

        $stmt = $pdo->prepare($query);

        $workNumberQuery = "SELECT Work_Number FROM ticket ORDER BY Work_Number DESC LIMIT 1";
        $workNumberStmt = $pdo->query($workNumberQuery);
        $row = $workNumberStmt->fetch();
        
        if ($row) {
            $lastNumber = intval(substr($row['Work_Number'], 3));
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 0;
        }
        $workNumber = 'SLT' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        $stmt->execute([
            ':Timestamp' => $ticketData['Timestamp'],
            ':Email_Address' => $ticketData['Email_Address'],
            ':Site' => $ticketData['Site'],
            ':Affected_employee' => $ticketData['Affected_employee'],
            ':EID' => $ticketData['EID'],
            ':Issues_Concerning' => $ticketData['Issues_Concerning'],
            ':Issue_Details' => $ticketData['Issue_Details'],
            ':Station_Number' => $ticketData['Station_Number'],
            ':TIME_RECEIVED' => $ticketData['TIME_RECEIVED'],
            ':TIME_RESOLVED' => $ticketData['TIME_RESOLVED'],
            ':SLT_on_DUTY' => $ticketData['SLT_on_DUTY'],
            ':Week_Beginning' => $ticketData['Week_Beginning'],
            ':LOB' => $ticketData['LOB'],
            ':OM' => $ticketData['OM'],
            ':Employee_name' => $ticketData['Employee_name'],
            ':Work_Number' => $workNumber,
            ':Status' => $ticketData['Status'],
            ':sub_cat' => $ticketData['sub_cat'],
            ':Urgency' => $ticketData['urgency'],
            ':issue_img' => $ticketData['issue_img'] ?? null,
        ]);

        return true;
    } catch (PDOException $e) {
        error_log("Ticket submission failed (PDO): " . $e->getMessage());
        return "Database error during ticket submission. Please contact support.";
    }
}
?>