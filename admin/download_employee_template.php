<?php
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=employees_import_template.csv');

$output = fopen('php://output', 'w');
fputcsv($output, array('employee_id', 'full_name', 'department', 'supervisor', 'operation_manager', 'email', 'is_active'));
fputcsv($output, array('CXI12345', 'JUAN DELA CRUZ', 'IT', 'PEDRO PENDUKO', 'MARIA CLARA', 'juan@example.com', '1'));
fclose($output);
exit;
?>
