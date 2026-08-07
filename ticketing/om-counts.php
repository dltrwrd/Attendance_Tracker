<?php
include('../connection.php');

$currentYear = date('Y');
$targetYear = isset($_POST['target_year']) ? (int)$_POST['target_year'] : $currentYear;

$pdo = null;
$db_error = '';

try {
    $dsn = "mysql:host=$host;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $db_error = "Database connection failed: " . $e->getMessage();
}

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$weekdays = [
    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
    4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'
];

$omCounts = [];
$names = [];

if (empty($db_error)) {
    try {
        $stmt = $pdo->query("SELECT name FROM oms");
        while ($row = $stmt->fetch()) {
            $firstName = explode(' ', $row['name'])[0];
            $names[$row['name']] = $firstName;
        }
    } catch (PDOException $e) {
        $db_error = "Error fetching names from oms table: " . $e->getMessage();
    }

    foreach ($names as $fullName => $firstName) {
        $omCounts[$firstName] = [
            'monthly' => array_fill_keys($months, 0),
            'weekly' => array_fill_keys($weekdays, 0)
        ];
    }

    if (!empty($names)) {
        $inClause = implode(',', array_map(function($n) { return "'$n'"; }, array_keys($names)));
        $stmt = $pdo->prepare("
            SELECT OM, Timestamp
            FROM ticket
            WHERE YEAR(STR_TO_DATE(Timestamp, '%m/%d/%Y %H:%i:%s')) = :target_year
            AND OM IN ({$inClause})
        ");

        try {
            $stmt->execute([':target_year' => $targetYear]);

            while ($row = $stmt->fetch()) {
                $omName = $row['OM'];
                $timestamp = $row['Timestamp'];
                $firstNameFromOM = explode(' ', $omName)[0];

                if (isset($omCounts[$firstNameFromOM])) {
                    $date = DateTime::createFromFormat('n/j/Y H:i:s', $timestamp);

                    if ($date) {
                        $month = (int)$date->format('n');
                        $dayOfWeek = (int)$date->format('N');

                        if (isset($months[$month])) {
                            $omCounts[$firstNameFromOM]['monthly'][$months[$month]]++;
                        }

                        if (isset($weekdays[$dayOfWeek])) {
                            $omCounts[$firstNameFromOM]['weekly'][$weekdays[$dayOfWeek]]++;
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $db_error = "Error fetching data from ticket table: " . $e->getMessage();
        }
    } else {
        $db_error = "No names found in the 'oms' table. Please ensure the 'oms' table is populated with names.";
    }
}
?>
