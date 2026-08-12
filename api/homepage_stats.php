<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

$students    = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$supervisors = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'supervisor'")->fetchColumn();
$companies   = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();

echo json_encode([
    'students'    => $students,
    'supervisors' => $supervisors,
    'companies'   => $companies,
]);
