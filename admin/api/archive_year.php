<?php
/**
 * archive_year.php – Archive an academic year and batch update related students.
 *
 * Called via POST from admin Academic Year Management page.
 * Changes year status to 'Archived', sets is_current = 0.
 * Batch updates students with matching academic_year to status = 'Archived'.
 *
 * POST params:
 *   id          – Academic year ID OR
 *   year_label  – Year label string e.g. "2024-2025"
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

$year_label = $target['year_label'];

try {
    $db->begin_transaction();

    // 1. Mark year as Archived & not current
    $arch_stmt = $db->prepare("UPDATE academic_years SET status = 'Archived', is_current = 0 WHERE id = ?");
    $arch_stmt->bind_param("i", $id);
    $arch_stmt->execute();

    // 2. Batch update students for this academic year to Archived
    // NOTE: Supervisor accounts are intentionally NOT deactivated or deleted.
    // Only student records are archived. Supervisor accounts remain permanently active.
    // Historical supervisor assignments are preserved in supervisor_academic_assignments table.
    $stu_stmt = $db->prepare("UPDATE users SET status = 'Archived' WHERE role = 'student' AND (academic_year_id = ? OR academic_year = ?)");
    $stu_stmt->bind_param("is", $id, $year_label);
    $stu_stmt->execute();
    $archived_students_count = $stu_stmt->affected_rows;

    $db->commit();

    echo json_encode([
        'success'         => true,
        'id'              => $id,
        'year_label'      => $year_label,
        'archived_students' => $archived_students_count,
        'message'         => "Academic year '{$year_label}' has been archived. {$archived_students_count} student(s) updated to Archived status.",
    ]);
} catch (Exception $e) {
    $db->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
