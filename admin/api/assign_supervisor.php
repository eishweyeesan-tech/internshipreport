<?php
/**
 * assign_supervisor.php – Manage Supervisor ↔ Academic Year assignments.
 *
 * Called via POST from admin Supervisor Management page.
 *
 * Actions:
 *   assign    – Assign a supervisor to an academic year
 *   unassign  – Remove a supervisor from an academic year
 *   get_history – Get full assignment history for a supervisor
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
ensure_supervisor_assignments_table($db);

$admin_id = (int) ($_SESSION['user_id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);

// get_year_details doesn't need supervisor_id
if ($action !== 'get_year_details') {
    if ($supervisor_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid supervisor ID']);
        exit;
    }

    // Verify supervisor exists
    $chk = $db->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'supervisor'");
    $chk->bind_param("i", $supervisor_id);
    $chk->execute();
    $res = $chk->get_result();
    $supervisor = $res ? $res->fetch_assoc() : null;
    if (!$supervisor) {
        echo json_encode(['success' => false, 'error' => 'Supervisor not found']);
        exit;
    }
}

switch ($action) {

    case 'get_year_details':
        $academic_year_id = (int) ($_POST['academic_year_id'] ?? 0);
        if ($academic_year_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid academic year ID']);
            exit;
        }
        $supervisors = get_supervisors_for_year($db, $academic_year_id);
        $total_supervisors = get_total_supervisors_for_year($db, $academic_year_id);

        // Student count for this year
        $st_cnt = $db->prepare("SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND u.academic_year_id = ?");
        $st_cnt->bind_param("i", $academic_year_id);
        $st_cnt->execute();
        $st_res = $st_cnt->get_result();
        $st_row = $st_res ? $st_res->fetch_row() : null;
        $student_count = (int) ($st_row[0] ?? 0);

        echo json_encode([
            'success' => true,
            'supervisors' => $supervisors,
            'supervisor_count' => $total_supervisors,
            'student_count' => $student_count,
        ]);
        break;

    case 'assign':
        $academic_year_id = (int) ($_POST['academic_year_id'] ?? 0);
        if ($academic_year_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid academic year ID']);
            exit;
        }

        // Verify year exists
        $yk = $db->prepare("SELECT id, year_label FROM academic_years WHERE id = ?");
        $yk->bind_param("i", $academic_year_id);
        $yk->execute();
        $yr = $yk->get_result();
        $year = $yr ? $yr->fetch_assoc() : null;
        if (!$year) {
            echo json_encode(['success' => false, 'error' => 'Academic year not found']);
            exit;
        }

        $ok = assign_supervisor_to_year($db, $supervisor_id, $academic_year_id, $admin_id);
        echo json_encode([
            'success' => true,
            'message' => $ok
                ? "Supervisor \"{$supervisor['username']}\" assigned to {$year['year_label']}."
                : "Supervisor is already assigned to {$year['year_label']}.",
        ]);
        break;

    case 'unassign':
        $academic_year_id = (int) ($_POST['academic_year_id'] ?? 0);
        if ($academic_year_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid academic year ID']);
            exit;
        }

        $yk2 = $db->prepare("SELECT year_label FROM academic_years WHERE id = ?");
        $yk2->bind_param("i", $academic_year_id);
        $yk2->execute();
        $yr2 = $yk2->get_result();
        $year2 = $yr2 ? $yr2->fetch_assoc() : null;
        $label2 = $year2 ? $year2['year_label'] : 'Unknown';

        $ok2 = unassign_supervisor_from_year($db, $supervisor_id, $academic_year_id);
        echo json_encode([
            'success' => true,
            'message' => $ok2
                ? "Supervisor \"{$supervisor['username']}\" removed from {$label2}."
                : "Supervisor was not assigned to {$label2}.",
        ]);
        break;

    case 'get_history':
        $history = get_supervisor_detailed_history($db, $supervisor_id);
        if (!$history) {
            echo json_encode(['success' => false, 'error' => 'Supervisor not found']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'supervisor' => $history['supervisor'],
            'assignments' => $history['assignments'],
            'total_students' => $history['total_students'],
            'total_assigned_years' => $history['total_assigned_years'],
            'total_evaluations' => $history['total_evaluations'],
        ]);
        break;

    case 'toggle_status':
        $target_status = trim($_POST['status'] ?? '');
        if (!in_array($target_status, ['Active', 'Inactive'], true)) {
            // Auto toggle if not specified
            $curr_status = trim((string)($supervisor['status'] ?? 'Active'));
            $target_status = ($curr_status === 'Active') ? 'Inactive' : 'Active';
        }

        $up_stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'supervisor'");
        $up_stmt->bind_param("si", $target_status, $supervisor_id);
        $up_stmt->execute();

        $action_word = ($target_status === 'Active') ? 'activated' : 'deactivated';
        echo json_encode([
            'success' => true,
            'status' => $target_status,
            'message' => "Supervisor \"{$supervisor['username']}\" has been {$action_word} successfully.",
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
