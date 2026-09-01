<?php

/**
 * assign_supervisor.php – Manage Supervisor ↔ Academic Year & Student Assignments.
 *
 * Called via POST from admin management pages.
 *
 * Actions:
 *   assign_student_to_supervisor  – Assign/unassign an individual student to a supervisor
 *   bulk_assign_students          – Bulk assign/unassign multiple students to a supervisor
 *   get_students_for_assignment   – Get students roster for supervisor assignment modal
 *   get_supervisors_list          – Get active supervisors list with supervised student count
 *   assign                        – Assign a supervisor to an academic year
 *   unassign                      – Remove a supervisor from an academic year
 *   get_history                   – Get full assignment history for a supervisor
 *   toggle_status                 – Activate/deactivate supervisor account
 *   get_year_details              – Get year details (supervisors & students)
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

switch ($action) {

    case 'assign_student_to_supervisor':
        $student_id = (int) ($_POST['student_id'] ?? 0);
        $supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);

        if ($student_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid student ID']);
            exit;
        }

        // Verify student exists
        $stu_chk = $db->prepare("SELECT u.id, u.username, u.academic_year, u.academic_year_id, sp.full_name, sp.student_roll FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE u.id = ? AND u.role = 'student'");
        $stu_chk->bind_param("i", $student_id);
        $stu_chk->execute();
        $stu_res = $stu_chk->get_result();
        $student = $stu_res ? $stu_res->fetch_assoc() : null;
        $stu_chk->close();

        if (!$student) {
            echo json_encode(['success' => false, 'error' => 'Student account not found']);
            exit;
        }

        $supervisor_name = null;
        if ($supervisor_id > 0) {
            // Verify supervisor exists and is active
            $sup_chk = $db->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'supervisor'");
            $sup_chk->bind_param("i", $supervisor_id);
            $sup_chk->execute();
            $sup_res = $sup_chk->get_result();
            $supervisor = $sup_res ? $sup_res->fetch_assoc() : null;
            $sup_chk->close();

            if (!$supervisor) {
                echo json_encode(['success' => false, 'error' => 'Supervisor not found']);
                exit;
            }
            $supervisor_name = $supervisor['username'];

            // Ensure profile exists then update
            $ensure_sp = $db->prepare("INSERT INTO student_profiles (user_id, supervisor_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE supervisor_id = VALUES(supervisor_id)");
            $ensure_sp->bind_param("ii", $student_id, $supervisor_id);
            $ensure_sp->execute();
            $ensure_sp->close();

            // Link supervisor to student's academic year
            $ay_id = (int)($student['academic_year_id'] ?? 0);
            if ($ay_id <= 0 && !empty($student['academic_year'])) {
                $y_stmt = $db->prepare("SELECT id FROM academic_years WHERE year_label = ?");
                $y_stmt->bind_param("s", $student['academic_year']);
                $y_stmt->execute();
                $y_res = $y_stmt->get_result();
                if ($y_row = $y_res->fetch_assoc()) {
                    $ay_id = (int)$y_row['id'];
                }
                $y_stmt->close();
            }
            if ($ay_id > 0) {
                assign_supervisor_to_year($db, $supervisor_id, $ay_id, $admin_id);
            }

            $stu_display = $student['full_name'] ?: ($student['student_roll'] ?: $student['username']);
            echo json_encode([
                'success' => true,
                'supervisor_id' => $supervisor_id,
                'supervisor_name' => $supervisor_name,
                'message' => "Student \"{$stu_display}\" assigned to supervisor \"{$supervisor_name}\" successfully.",
            ]);
        } else {
            // Unassign supervisor
            $unassign_sp = $db->prepare("UPDATE student_profiles SET supervisor_id = NULL WHERE user_id = ?");
            $unassign_sp->bind_param("i", $student_id);
            $unassign_sp->execute();
            $unassign_sp->close();

            $stu_display = $student['full_name'] ?: ($student['student_roll'] ?: $student['username']);
            echo json_encode([
                'success' => true,
                'supervisor_id' => 0,
                'supervisor_name' => 'Unassigned',
                'message' => "Supervisor unassigned from student \"{$stu_display}\".",
            ]);
        }
        break;

    case 'bulk_assign_students':
        $supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);
        $raw_ids = $_POST['student_ids'] ?? '[]';
        $student_ids = is_array($raw_ids) ? $raw_ids : json_decode($raw_ids, true);

        if (!is_array($student_ids) || empty($student_ids)) {
            echo json_encode(['success' => false, 'error' => 'No students selected for assignment']);
            exit;
        }

        // Clean IDs
        $clean_ids = [];
        foreach ($student_ids as $sid) {
            $sid = (int) $sid;
            if ($sid > 0) $clean_ids[] = $sid;
        }

        if (empty($clean_ids)) {
            echo json_encode(['success' => false, 'error' => 'Invalid student IDs provided']);
            exit;
        }

        $supervisor_name = null;
        if ($supervisor_id > 0) {
            $sup_chk = $db->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'supervisor'");
            $sup_chk->bind_param("i", $supervisor_id);
            $sup_chk->execute();
            $sup_res = $sup_chk->get_result();
            $supervisor = $sup_res ? $sup_res->fetch_assoc() : null;
            $sup_chk->close();

            if (!$supervisor) {
                echo json_encode(['success' => false, 'error' => 'Supervisor not found']);
                exit;
            }
            $supervisor_name = $supervisor['username'];

            // Update student profiles in batch
            $up_stmt = $db->prepare("INSERT INTO student_profiles (user_id, supervisor_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE supervisor_id = VALUES(supervisor_id)");
            foreach ($clean_ids as $sid) {
                $up_stmt->bind_param("ii", $sid, $supervisor_id);
                $up_stmt->execute();
            }
            $up_stmt->close();

            // Link supervisor to all distinct academic years of these students
            $in_clause = implode(',', array_fill(0, count($clean_ids), '?'));
            $types = str_repeat('i', count($clean_ids));
            $ay_stmt = $db->prepare("SELECT DISTINCT COALESCE(u.academic_year_id, ay.id) AS ay_id FROM users u LEFT JOIN academic_years ay ON ay.year_label = u.academic_year WHERE u.id IN ($in_clause) AND u.role = 'student'");
            $ay_stmt->bind_param($types, ...$clean_ids);
            $ay_stmt->execute();
            $ay_res = $ay_stmt->get_result();
            while ($ay_row = $ay_res->fetch_assoc()) {
                $ay_id = (int) ($ay_row['ay_id'] ?? 0);
                if ($ay_id > 0) {
                    assign_supervisor_to_year($db, $supervisor_id, $ay_id, $admin_id);
                }
            }
            $ay_stmt->close();

            $cnt = count($clean_ids);
            echo json_encode([
                'success' => true,
                'count' => $cnt,
                'supervisor_id' => $supervisor_id,
                'supervisor_name' => $supervisor_name,
                'message' => "Successfully assigned {$cnt} student(s) to supervisor \"{$supervisor_name}\".",
            ]);
        } else {
            // Bulk unassign
            $in_clause = implode(',', array_fill(0, count($clean_ids), '?'));
            $types = str_repeat('i', count($clean_ids));
            $un_stmt = $db->prepare("UPDATE student_profiles SET supervisor_id = NULL WHERE user_id IN ($in_clause)");
            $un_stmt->bind_param($types, ...$clean_ids);
            $un_stmt->execute();
            $un_stmt->close();

            $cnt = count($clean_ids);
            echo json_encode([
                'success' => true,
                'count' => $cnt,
                'supervisor_id' => 0,
                'supervisor_name' => 'Unassigned',
                'message' => "Successfully unassigned supervisor from {$cnt} student(s).",
            ]);
        }
        break;

    case 'get_students_for_assignment':
        $academic_year = trim($_POST['academic_year'] ?? 'all');
        $sql = "
            SELECT u.id, u.username, u.email, u.phone, u.status,
                   COALESCE(u.academic_year, ay.year_label) AS academic_year,
                   sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
                   sp.supervisor_id,
                   sup_u.username AS supervisor_name
            FROM users u
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
            LEFT JOIN academic_years ay ON (ay.id = u.academic_year_id OR ay.year_label = u.academic_year)
            WHERE u.role = 'student'
        ";
        $params = [];
        $types = "";

        if ($academic_year !== '' && $academic_year !== 'all') {
            $sql .= " AND (u.academic_year = ? OR ay.year_label = ? OR u.academic_year_id = ?)";
            $params[] = $academic_year;
            $params[] = $academic_year;
            $params[] = is_numeric($academic_year) ? (int)$academic_year : 0;
            $types .= "ssi";
        }

        $sql .= " ORDER BY LENGTH(COALESCE(NULLIF(sp.student_roll, ''), u.username)) ASC, COALESCE(NULLIF(sp.student_roll, ''), u.username) ASC";

        if (!empty($params)) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $students = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
        } else {
            $res = $db->query($sql);
            $students = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }

        echo json_encode([
            'success' => true,
            'students' => $students,
            'count' => count($students),
        ]);
        break;

    case 'get_supervisors_list':
        $sql = "
            SELECT u.id, u.username, u.email, u.phone, u.department, u.position, u.status,
                   COUNT(sp.user_id) AS assigned_students_count
            FROM users u
            LEFT JOIN student_profiles sp ON sp.supervisor_id = u.id
            WHERE u.role = 'supervisor' AND u.status = 'Active'
            GROUP BY u.id
            ORDER BY u.username ASC
        ";
        $res = $db->query($sql);
        $supervisors = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

        echo json_encode([
            'success' => true,
            'supervisors' => $supervisors,
            'count' => count($supervisors),
        ]);
        break;

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
                   sp.supervisor_id,
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

        // Natural sort students by roll number
        usort($students_list, function ($a, $b) {
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
        $supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);
        $academic_year_id = (int) ($_POST['academic_year_id'] ?? 0);
        if ($supervisor_id <= 0 || $academic_year_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        $chk = $db->prepare("SELECT username FROM users WHERE id = ? AND role = 'supervisor'");
        $chk->bind_param("i", $supervisor_id);
        $chk->execute();
        $supervisor = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$supervisor) {
            echo json_encode(['success' => false, 'error' => 'Supervisor not found']);
            exit;
        }

        $yk = $db->prepare("SELECT id, year_label FROM academic_years WHERE id = ?");
        $yk->bind_param("i", $academic_year_id);
        $yk->execute();
        $year = $yk->get_result()->fetch_assoc();
        $yk->close();

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
        $supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);
        $academic_year_id = (int) ($_POST['academic_year_id'] ?? 0);
        if ($supervisor_id <= 0 || $academic_year_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        $chk = $db->prepare("SELECT username FROM users WHERE id = ? AND role = 'supervisor'");
        $chk->bind_param("i", $supervisor_id);
        $chk->execute();
        $supervisor = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$supervisor) {
            echo json_encode(['success' => false, 'error' => 'Supervisor not found']);
            exit;
        }

        $yk2 = $db->prepare("SELECT year_label FROM academic_years WHERE id = ?");
        $yk2->bind_param("i", $academic_year_id);
        $yk2->execute();
        $year2 = $yk2->get_result()->fetch_assoc();
        $yk2->close();
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
        $supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);
        if ($supervisor_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid supervisor ID']);
            exit;
        }
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
        $supervisor_id = (int) ($_POST['supervisor_id'] ?? 0);
        if ($supervisor_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid supervisor ID']);
            exit;
        }

        $chk = $db->prepare("SELECT username, status FROM users WHERE id = ? AND role = 'supervisor'");
        $chk->bind_param("i", $supervisor_id);
        $chk->execute();
        $supervisor = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$supervisor) {
            echo json_encode(['success' => false, 'error' => 'Supervisor not found']);
            exit;
        }

        $target_status = trim($_POST['status'] ?? '');
        if (!in_array($target_status, ['Active', 'Inactive'], true)) {
            $curr_status = trim((string)($supervisor['status'] ?? 'Active'));
            $target_status = ($curr_status === 'Active') ? 'Inactive' : 'Active';
        }

        $up_stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'supervisor'");
        $up_stmt->bind_param("si", $target_status, $supervisor_id);
        $up_stmt->execute();
        $up_stmt->close();

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
