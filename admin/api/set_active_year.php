<?php
/**
 * set_active_year.php – Set selected academic year as the current active year.
 *
 * Called via POST from admin Academic Year Management page.
 * Sets is_current = 1 and status = 'Active' for target year, resets others to is_current = 0.
 *
 * POST params:
 *   id          – Academic year ID OR
 *   year_label  – Year label string e.g. "2026-2027"
 */

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/academic_year_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST method required']);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = $mysqli ?? $conn;
ensure_academic_years_table($db);

$id = (int) ($_POST['id'] ?? 0);
$label = trim($_POST['year_label'] ?? '');

if ($id <= 0 && !empty($label)) {
    $stmt = $db->prepare("SELECT id FROM academic_years WHERE year_label = ? LIMIT 1");
    $stmt->bind_param("s", $label);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        $id = (int) $row['id'];
    }
}

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid academic year specified']);
    exit;
}

// Fetch target year
$target_stmt = $db->prepare("SELECT * FROM academic_years WHERE id = ? LIMIT 1");
$target_stmt->bind_param("i", $id);
$target_stmt->execute();
$res = $target_stmt->get_result();
$target = $res ? $res->fetch_assoc() : null;

if (!$target) {
    echo json_encode(['success' => false, 'error' => 'Academic year not found']);
    exit;
}

try {
    $db->begin_transaction();

    // Reset all is_current = 0
    $db->query("UPDATE academic_years SET is_current = 0");
    // Transition any previously 'Active' year to 'Archived'
    $db->query("UPDATE academic_years SET status = 'Archived' WHERE status = 'Active'");

    // Set target year active
    $active_stmt = $db->prepare("UPDATE academic_years SET is_current = 1, status = 'Active' WHERE id = ?");
    $active_stmt->bind_param("i", $id);
    $active_stmt->execute();

    $db->commit();

    echo json_encode([
        'success'    => true,
        'id'         => $id,
        'year_label' => $target['year_label'],
        'message'    => "Academic year '{$target['year_label']}' is now set as the active current year.",
    ]);
} catch (Exception $e) {
    $db->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
