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

        // Fetch students list for this year
        $st_list_stmt = $db->prepare("
            SELECT u.id, u.username, u.email, u.phone, u.status, u.is_first_login,
                   sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
                   sup_u.username AS supervisor_name
            FROM users u
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
            WHERE u.role = 'student'
              AND (u.academic_year_id = ? OR u.academic_year = (SELECT year_label FROM academic_years WHERE id = ?))
            ORDER BY sp.student_roll ASC, u.username ASC
        ");
        $st_list_stmt->bind_param("ii", $academic_year_id, $academic_year_id);
        $st_list_stmt->execute();
        $st_list_res = $st_list_stmt->get_result();
        $students_list = $st_list_res ? $st_list_res->fetch_all(MYSQLI_ASSOC) : [];
        $st_list_stmt->close();

        // Natural sort students by roll number (e.g. 5CS-1, 5CS-2, 5CS-10)
        usort($students_list, function($a, $b) {
            $rA = trim($a['student_roll'] ?: $a['username']);
            $rB = trim($b['student_roll'] ?: $b['username']);
            $cmp = strnatcasecmp($rA, $rB);
            if ($cmp !== 0) return $cmp;
            return strcasecmp($a['full_name'] ?: $a['username'], $b['full_name'] ?: $b['username']);
        });

        echo json_encode([
            'success' => true,
            'supervisors' => $supervisors,
            'supervisor_count' => $total_supervisors,
            'student_count' => count($students_list),
            'students' => $students_list,
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

        // Check if supervisor currently has assigned students in this academic year
        $stu_chk = $db->prepare("
            SELECT COUNT(*) FROM student_profiles sp
            JOIN users stu ON stu.id = sp.user_id
            WHERE sp.supervisor_id = ?
              AND (stu.academic_year_id = ? OR stu.academic_year = (SELECT year_label FROM academic_years WHERE id = ?))
        ");
        $stu_chk->bind_param("iii", $supervisor_id, $academic_year_id, $academic_year_id);
        $stu_chk->execute();
        $stu_cnt = (int)($stu_chk->get_result()->fetch_row()[0] ?? 0);
        $stu_chk->close();

        if ($stu_cnt > 0) {
            echo json_encode([
                'success' => false,
                'error' => "Cannot unassign supervisor: {$stu_cnt} student(s) are currently assigned to this supervisor in {$label2}. Please reassign the students first."
            ]);
            exit;
        }

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
            'status' => $history['supervisor']['status'] ?? 'Active',
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
