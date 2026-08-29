<?php
/**
 * delete_year.php – Delete an erroneously created academic year.
 *
 * Called via POST from admin Academic Year Management page.
 * Conditions for deletion:
 * 1. Must not be the currently active academic year (is_current = 0).
 * 2. Must contain 0 registered students (student_count = 0).
 *
 * POST params:
 *   id          – Academic year ID OR
 *   year_label  – Year label string e.g. "2022-2023"
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

// Rule 1: Cannot delete currently active year
if (!empty($target['is_current'])) {
    echo json_encode(['success' => false, 'error' => 'Cannot delete the currently active academic year. Please set another year as active first.']);
    exit;
}

$year_label = $target['year_label'];

// Rule 2: Cannot delete a year with registered students
$chk_stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND (academic_year_id = ? OR academic_year = ?)");
$chk_stmt->bind_param("is", $id, $year_label);
$chk_stmt->execute();
$chk_res = $chk_stmt->get_result();
$student_count = $chk_res ? (int) ($chk_res->fetch_row()[0] ?? 0) : 0;

if ($student_count > 0) {
    echo json_encode(['success' => false, 'error' => "Cannot delete academic year '{$year_label}' because it contains {$student_count} registered student(s). Please archive it instead."]);
    exit;
}

try {
    $db->begin_transaction();

    // Delete the academic year
    $del_stmt = $db->prepare("DELETE FROM academic_years WHERE id = ?");
    $del_stmt->bind_param("i", $id);
    $del_stmt->execute();

    $db->commit();

    echo json_encode([
        'success'    => true,
        'id'         => $id,
        'year_label' => $year_label,
        'message'    => "Academic year '{$year_label}' has been deleted successfully.",
    ]);
} catch (Exception $e) {
    $db->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
