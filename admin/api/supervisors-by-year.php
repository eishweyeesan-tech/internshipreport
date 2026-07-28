<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth.php';

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$year = trim($_GET['year'] ?? '');

if (empty($year) || !preg_match('/^\d{4}-\d{4}$/', $year)) {
    echo json_encode([]);
    exit;
}

// Resolve academic_year_id from the dimension table, fallback to string column
$ay_stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year_label = ?");
$ay_stmt->execute([$year]);
$ay_id = $ay_stmt->fetchColumn();

if ($ay_id) {
    $supervisors = $pdo->prepare("
        SELECT DISTINCT u.id, u.username, u.email
        FROM users u
        INNER JOIN supervisor_assignments sa ON sa.supervisor_id = u.id
        WHERE sa.academic_year_id = ?
          AND u.role = 'supervisor'
        ORDER BY u.username ASC
    ");
    $supervisors->execute([$ay_id]);
} else {
    $supervisors = $pdo->prepare("
        SELECT DISTINCT u.id, u.username, u.email
        FROM users u
        INNER JOIN supervisor_assignments sa ON sa.supervisor_id = u.id
        WHERE sa.academic_year = ?
          AND u.role = 'supervisor'
        ORDER BY u.username ASC
    ");
    $supervisors->execute([$year]);
}
$rows = $supervisors->fetchAll();

echo json_encode($rows);
