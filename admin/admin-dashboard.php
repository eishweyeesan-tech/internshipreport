<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';

$admin_name = $_SESSION['username'];
$admin_id   = (int) $_SESSION['user_id'];
$db         = $mysqli ?? $conn;
$msg = '';
$err = '';

require_once __DIR__ . '/../includes/academic_year_helper.php';
ensure_academic_years_table($db);
ensure_supervisor_assignments_table($db);

// Default password values and active academic year from academic_years table
$default_pws = get_default_passwords($db);
$def_student_pw = $default_pws['student'];
$def_supervisor_pw = $default_pws['supervisor'];

// ── Academic Years (from academic_years table) ────────────────
$active_ay_rec = get_active_academic_year($db);
$current_active_year_label = $active_ay_rec ? $active_ay_rec['year_label'] : '2023-2024';

$all_ay_records = get_all_academic_years($db);
$academic_years = [];
$ay_label_to_id = [];
foreach ($all_ay_records as $rec) {
    $academic_years[] = $rec['year_label'];
    $ay_label_to_id[$rec['year_label']] = $rec['id'];
}
$all_academic_years = array_map(function ($y) {
    return ['year_label' => $y];
}, $academic_years);

// Determine active tab first
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'students', 'supervisors', 'manage', 'archive', 'history'], true)) {
    $tab = 'overview';
}

// Determine selected academic year filter
// On Reports tab ('history'), default to current active year; on other tabs (Overview/Manage), default to 'all'
if (isset($_GET['year']) && $_GET['year'] !== '') {
    $selected_year = trim($_GET['year']);
} elseif ($tab === 'history') {
    $selected_year = $current_active_year_label;
} else {
    $selected_year = 'all';
}
$selected_academic_id = ($selected_year !== 'all' && isset($ay_label_to_id[$selected_year])) ? (int) $ay_label_to_id[$selected_year] : null;

// ══════════════════════════════════════════════════════════════════
// HANDLERS
// ══════════════════════════════════════════════════════════════════

// ── Add Student ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $s_name          = trim($_POST['s_name'] ?? '');
    $s_roll          = trim($_POST['s_roll'] ?? '');
    $s_major         = trim($_POST['s_major'] ?? '');
    $s_email         = trim($_POST['s_email'] ?? '');
    $s_phone         = trim($_POST['s_phone'] ?? '');
    $s_company_id    = (int) ($_POST['s_company_id'] ?? 0);
    $s_supervisor_id = (int) ($_POST['s_supervisor_id'] ?? 0);
    $s_start         = trim($_POST['s_start_date'] ?? '');
    $s_end           = trim($_POST['s_end_date'] ?? '');
    $s_password      = $_POST['s_password'] ?? '';

    // Fetch active academic year
    $ay_active_res = $db->query("SELECT id, year_label FROM academic_years WHERE is_current = 1 LIMIT 1");
    $active_year_row = $ay_active_res ? $ay_active_res->fetch_assoc() : null;
    $s_academic_id = $active_year_row ? (int) $active_year_row['id'] : 0;
    $s_academic_label = $active_year_row ? $active_year_row['year_label'] : '';

    if (empty($s_academic_id)) {
        $err = 'No active academic year found in the system. Please activate an academic year first in Academic Year Management.';
    } elseif (empty($s_name) || empty($s_roll) || empty($s_email) || empty($s_password)) {
        $err = 'Name, Roll No, Email, and Password are required.';
    } elseif (!filter_var($s_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email format.';
    } elseif (strlen($s_password) < 6) {
        $err = 'Password must be at least 6 characters.';
    } else {
        $check = $db->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $s_email);
        $check->execute();
        $res = $check->get_result();
        if ($res && $res->fetch_row()) {
            $err = 'A user with this email already exists.';
        } else {
            // Composite check: same roll number + active academic year = duplicate
            $check_dup = $db->prepare("SELECT u.id FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE (u.username = ? OR sp.student_roll = ?) AND u.academic_year_id = ?");
            $check_dup->bind_param("ssi", $s_roll, $s_roll, $s_academic_id);
            $check_dup->execute();
            $res = $check_dup->get_result();
            if ($res && $res->fetch_row()) {
                $err = "This Roll Number ({$s_roll}) already exists for the current academic year ({$s_academic_label}).";
            } else {
                $hash = password_hash($s_password, PASSWORD_DEFAULT);
                $ins_u = $db->prepare("INSERT INTO users (username, email, phone, password, role, is_first_login, academic_year_id) VALUES (?, ?, ?, ?, 'student', 1, ?)");
                $ins_u->bind_param("ssssi", $s_name, $s_email, $s_phone, $hash, $s_academic_id);
                $ins_u->execute();
                $uid = (int) $db->insert_id;

                $comp_id = $s_company_id > 0 ? $s_company_id : null;
                $sup_id = $s_supervisor_id > 0 ? $s_supervisor_id : null;
                $istart = !empty($s_start) ? $s_start : null;
                $iend   = !empty($s_end) ? $s_end : null;

                $ins_sp = $db->prepare("INSERT INTO student_profiles (user_id, student_roll, major, company_id, supervisor_id, internship_start_date, internship_end_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins_sp->bind_param("ississs", $uid, $s_roll, $s_major, $comp_id, $sup_id, $istart, $iend);
                $ins_sp->execute();

                $msg = "Student \"{$s_name}\" ({$s_roll}) created successfully for {$s_academic_label}. Email: {$s_email}";
            }
        }
    }
}

// ── Add Supervisor ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supervisor'])) {
    $t_name     = trim($_POST['t_name'] ?? '');
    $t_dept     = trim($_POST['t_dept'] ?? '');
    $t_pos      = trim($_POST['t_position'] ?? $_POST['position'] ?? '');
    $t_email    = trim($_POST['t_email'] ?? '');
    $t_password = $_POST['t_password'] ?? '';

    if (empty($t_name) || empty($t_email) || empty($t_password)) {
        $err = 'Name, Email, and Password are required.';
    } elseif (!filter_var($t_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email format.';
    } elseif (strlen($t_password) < 6) {
        $err = 'Password must be at least 6 characters.';
    } else {
        $check = $db->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $t_email);
        $check->execute();
        $res = $check->get_result();
        if ($res && $res->fetch_row()) {
            $err = 'A user with this email already exists.';
        } else {
            $hash = password_hash($t_password, PASSWORD_DEFAULT);
            $uname = 'sup_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $t_name));
            $ins_sup = $db->prepare("INSERT INTO users (username, email, department, position, password, role, is_first_login) VALUES (?, ?, ?, ?, ?, 'supervisor', 1)");
            $ins_sup->bind_param("sssss", $uname, $t_email, $t_dept, $t_pos, $hash);
            $ins_sup->execute();

            $msg = "Supervisor \"{$t_name}\" created. Email: {$t_email}, Password: {$t_password}";
        }
    }
}

// ── Delete User ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $did = (int) ($_POST['delete_uid'] ?? 0);
    if ($did > 0 && $did !== $admin_id) {
        $del = $db->prepare("DELETE FROM users WHERE id = ?");
        $del->bind_param("i", $did);
        $del->execute();
        $msg = 'User deleted.';
    }
}

// ── Reset User Password ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $rid = (int) ($_POST['reset_uid'] ?? 0);
    if ($rid > 0 && $rid !== $admin_id) {
        // Determine which default password to use based on the user's role
        $r_role_q = $db->prepare("SELECT role FROM users WHERE id = ?");
        $r_role_q->bind_param("i", $rid);
        $r_role_q->execute();
        $res = $r_role_q->get_result();
        $row = $res ? $res->fetch_row() : null;
        $r_role = $row[0] ?? '';
        $default_pw = ($r_role === 'supervisor') ? $def_supervisor_pw : $def_student_pw;

        $hash = password_hash($default_pw, PASSWORD_DEFAULT);
        $up = $db->prepare("UPDATE users SET password = ?, is_first_login = 1 WHERE id = ? AND role IN ('student','supervisor')");
        $up->bind_param("si", $hash, $rid);
        $up->execute();

        $rname_q = $db->prepare("SELECT username FROM users WHERE id = ?");
        $rname_q->bind_param("i", $rid);
        $rname_q->execute();
        $res = $rname_q->get_result();
        $row = $res ? $res->fetch_row() : null;
        $rname = $row[0] ?? 'User';

        $msg = "Password reset for \"{$rname}\". Default password: {$default_pw}";
    }
}

// ── Batch Archive ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_batch'])) {
    $batch_year = trim($_POST['batch_year'] ?? '');
    if (empty($batch_year)) {
        $err = 'Please select an academic year to archive.';
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $batch_year)) {
        $err = 'Invalid academic year format.';
    } else {
        $batch_year_id = $ay_label_to_id[$batch_year] ?? null;
        if (!$batch_year_id) {
            $stmt_ay = $db->prepare("SELECT id FROM academic_years WHERE year_label = ? LIMIT 1");
            $stmt_ay->bind_param("s", $batch_year);
            $stmt_ay->execute();
            $r_ay = $stmt_ay->get_result();
            if ($r_ay && $row_ay = $r_ay->fetch_assoc()) {
                $batch_year_id = (int)$row_ay['id'];
            }
            $stmt_ay->close();
        }

        if ($batch_year_id) {
            $cnt = $db->prepare("SELECT COUNT(*) FROM users WHERE academic_year_id = ? AND role = 'student'");
            $cnt->bind_param("i", $batch_year_id);
            $cnt->execute();
            $res = $cnt->get_result();
            $row = $res ? $res->fetch_row() : null;
            $count = (int) ($row[0] ?? 0);
            $cnt->close();

            $up = $db->prepare("UPDATE users SET status = 'Archived' WHERE academic_year_id = ? AND role = 'student'");
            $up->bind_param("i", $batch_year_id);
            $up->execute();
            $up->close();
            $msg = "Archived {$count} student(s) from batch {$batch_year}.";
        } else {
            $err = "Academic year batch {$batch_year} not found.";
        }
    }
}

// ── Restore / Unarchive Batch ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_batch'])) {
    $restore_year = trim($_POST['restore_year'] ?? '');
    if (empty($restore_year)) {
        $err = 'Please select an academic year to restore.';
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $restore_year)) {
        $err = 'Invalid academic year format.';
    } else {
        $restore_year_id = $ay_label_to_id[$restore_year] ?? null;
        if (!$restore_year_id) {
            $stmt_ay = $db->prepare("SELECT id FROM academic_years WHERE year_label = ? LIMIT 1");
            $stmt_ay->bind_param("s", $restore_year);
            $stmt_ay->execute();
            $r_ay = $stmt_ay->get_result();
            if ($r_ay && $row_ay = $r_ay->fetch_assoc()) {
                $restore_year_id = (int)$row_ay['id'];
            }
            $stmt_ay->close();
        }

        if ($restore_year_id) {
            $cnt = $db->prepare("SELECT COUNT(*) FROM users WHERE academic_year_id = ? AND role = 'student' AND status = 'Archived'");
            $cnt->bind_param("i", $restore_year_id);
            $cnt->execute();
            $res = $cnt->get_result();
            $row = $res ? $res->fetch_row() : null;
            $count = (int) ($row[0] ?? 0);
            $cnt->close();

            $up1 = $db->prepare("UPDATE users SET status = 'Active' WHERE academic_year_id = ? AND role = 'student' AND status = 'Archived'");
            $up1->bind_param("i", $restore_year_id);
            $up1->execute();
            $up1->close();
            $msg = "Restored {$count} student(s) from batch {$restore_year}. They can now log in again.";
        } else {
            $err = "Academic year batch {$restore_year} not found.";
        }
    }
}

// ── Toggle Individual User Status (Active <-> Inactive <-> Archived) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user_status'])) {
    $target_uid = (int) ($_POST['status_uid'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    if ($target_uid > 0 && in_array($new_status, ['Active', 'Inactive', 'Archived'], true)) {
        $up = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
        $up->bind_param("si", $new_status, $target_uid);
        $up->execute();
        $msg = "User status updated to {$new_status} successfully.";
    }
}



// ── Mark notification as read ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notification_read'])) {
    $notif_id = (int)($_POST['notification_id'] ?? 0);
    if ($notif_id > 0) {
        $up = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $up->bind_param("ii", $notif_id, $admin_id);
        $up->execute();
    }
    header('Location: admin-dashboard.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    $up = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $up->bind_param("i", $admin_id);
    $up->execute();
    header('Location: admin-dashboard.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// ══════════════════════════════════════════════════════════════════
// DATA QUERIES
// ══════════════════════════════════════════════════════════════════

// Analytics counts
// 1. Students count
if ($selected_year !== '' && $selected_year !== 'all') {
    if ($selected_academic_id !== null) {
        $st_cnt = $db->prepare("SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND u.academic_year_id = ?");
        $st_cnt->bind_param("i", $selected_academic_id);
    } else {
        $st_cnt = $db->prepare("SELECT COUNT(*) FROM users u JOIN academic_years ay ON ay.id = u.academic_year_id WHERE u.role = 'student' AND ay.year_label = ?");
        $st_cnt->bind_param("s", $selected_year);
    }
    $st_cnt->execute();
    $res = $st_cnt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $student_count = (int) ($row[0] ?? 0);
    $st_cnt->close();
} else {
    $res = $db->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
    $row = $res ? $res->fetch_row() : null;
    $student_count = (int) ($row[0] ?? 0);
}

// 2. Supervisors count (using assignment table)
if ($selected_year !== '' && $selected_year !== 'all') {
    if ($selected_academic_id !== null) {
        $sup_cnt = $db->prepare("
            SELECT COUNT(DISTINCT u.id) FROM users u 
            WHERE u.role = 'supervisor' AND (
                u.id IN (SELECT saa.supervisor_id FROM supervisor_academic_assignments saa WHERE saa.academic_year_id = ?)
                OR u.id IN (
                    SELECT sp.supervisor_id FROM student_profiles sp 
                    JOIN users st ON st.id = sp.user_id 
                    WHERE st.academic_year_id = ? AND sp.supervisor_id IS NOT NULL
                )
            )
        ");
        $sup_cnt->bind_param("ii", $selected_academic_id, $selected_academic_id);
    } else {
        $sup_cnt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'supervisor'");
    }
    $sup_cnt->execute();
    $res = $sup_cnt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $supervisor_count = (int) ($row[0] ?? 0);
    $sup_cnt->close();
} else {
    $res = $db->query("SELECT COUNT(*) FROM users WHERE role = 'supervisor'");
    $row = $res ? $res->fetch_row() : null;
    $supervisor_count = (int) ($row[0] ?? 0);
}

// 3. Registered partner companies
if ($selected_year !== '' && $selected_year !== 'all') {
    if ($selected_academic_id !== null) {
        $comp_cnt = $db->prepare("
            SELECT COUNT(DISTINCT sp.company_id) 
            FROM student_profiles sp 
            JOIN users u ON u.id = sp.user_id 
            WHERE u.academic_year_id = ? 
              AND sp.company_id IS NOT NULL AND sp.company_id > 0
        ");
        $comp_cnt->bind_param("i", $selected_academic_id);
    } else {
        $comp_cnt = $db->prepare("
            SELECT COUNT(DISTINCT sp.company_id) 
            FROM student_profiles sp 
            JOIN users u ON u.id = sp.user_id 
            JOIN academic_years ay ON ay.id = u.academic_year_id
            WHERE ay.year_label = ? 
              AND sp.company_id IS NOT NULL AND sp.company_id > 0
        ");
        $comp_cnt->bind_param("s", $selected_year);
    }
    $comp_cnt->execute();
    $res = $comp_cnt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $company_count = (int) ($row[0] ?? 0);
    $comp_cnt->close();
} else {
    $res = $db->query("SELECT COUNT(*) FROM companies");
    $row = $res ? $res->fetch_row() : null;
    $company_count = (int) ($row[0] ?? 0);
}

// 4. Pending first login requests
if ($selected_year !== '' && $selected_year !== 'all') {
    if ($selected_academic_id !== null) {
        $pend_cnt = $db->prepare("SELECT COUNT(*) FROM users u WHERE u.is_first_login = 1 AND u.role != 'admin' AND u.academic_year_id = ?");
        $pend_cnt->bind_param("i", $selected_academic_id);
    } else {
        $pend_cnt = $db->prepare("SELECT COUNT(*) FROM users u JOIN academic_years ay ON ay.id = u.academic_year_id WHERE u.is_first_login = 1 AND u.role != 'admin' AND ay.year_label = ?");
        $pend_cnt->bind_param("s", $selected_year);
    }
    $pend_cnt->execute();
    $res = $pend_cnt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $pending_count = (int) ($row[0] ?? 0);
    $pend_cnt->close();
} else {
    $res = $db->query("SELECT COUNT(*) FROM users WHERE is_first_login = 1 AND role != 'admin'");
    $row = $res ? $res->fetch_row() : null;
    $pending_count = (int) ($row[0] ?? 0);
}

// Companies list
$res_c = $db->query("SELECT * FROM companies ORDER BY company_name ASC");
$companies = $res_c ? $res_c->fetch_all(MYSQLI_ASSOC) : [];

// Supervisors list (using assignment table)
if ($selected_year !== '' && $selected_year !== 'all') {
    if ($selected_academic_id !== null) {
        $sup_list_stmt = $db->prepare("
            SELECT DISTINCT u.id, u.username, u.email, u.phone, u.department, u.position, u.status, u.is_first_login, u.created_at
            FROM users u
            LEFT JOIN supervisor_academic_assignments saa ON saa.supervisor_id = u.id AND saa.academic_year_id = ?
            WHERE u.role = 'supervisor' AND (saa.id IS NOT NULL OR u.id IN (
                SELECT sp.supervisor_id FROM student_profiles sp
                JOIN users stu ON stu.id = sp.user_id
                WHERE stu.academic_year_id = ? AND sp.supervisor_id IS NOT NULL
            ))
            ORDER BY u.username
        ");
        $sup_list_stmt->bind_param("ii", $selected_academic_id, $selected_academic_id);
    } else {
        $sup_list_stmt = $db->prepare("SELECT id, username, email, phone, department, position, status, is_first_login, created_at FROM users WHERE role = 'supervisor' ORDER BY username");
    }
    $sup_list_stmt->execute();
    $res_sup = $sup_list_stmt->get_result();
    $supervisors = $res_sup ? $res_sup->fetch_all(MYSQLI_ASSOC) : [];
    $sup_list_stmt->close();
} else {
    $res_sup = $db->query("SELECT id, username, email, phone, department, position, status, is_first_login, created_at FROM users WHERE role = 'supervisor' ORDER BY username");
    $supervisors = $res_sup ? $res_sup->fetch_all(MYSQLI_ASSOC) : [];
}

// Students list with dynamic filters and roll number natural ordering
$search_student = trim($_GET['search_student'] ?? $_GET['student_search'] ?? '');
$filter_stu_year = trim($_GET['academic_year_filter'] ?? $_GET['student_year'] ?? $selected_year ?? '');

$filter_stu_year_id = ($filter_stu_year !== '' && $filter_stu_year !== 'all')
    ? ($ay_label_to_id[$filter_stu_year] ?? (is_numeric($filter_stu_year) ? (int)$filter_stu_year : null))
    : null;

if ($filter_stu_year_id !== null && is_numeric($filter_stu_year)) {
    foreach ($ay_label_to_id as $lbl => $id) {
        if ($id == $filter_stu_year) {
            $filter_stu_year = $lbl;
            break;
        }
    }
}

$stu_sql = "
    SELECT u.id AS uid, u.username, u.email, u.phone AS user_phone, u.is_first_login, 
           ay.year_label AS academic_year, u.academic_year_id, u.status, u.created_at, u.last_login_at,
           u.username AS full_name, sp.student_roll, sp.major, u.phone AS student_phone, 
           COALESCE(c.company_name, '') AS company_name, u.position AS job_role,
           '' AS instructor_name, '' AS instructor_email, '' AS instructor_phone,
           sp.internship_start_date, sp.internship_end_date,
           sp.supervisor_id,
           sup_u.username AS supervisor_name,
           sup_u.email AS supervisor_email,
           ay.id AS ay_id, ay.start_date AS ay_start_date
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN companies c ON c.id = sp.company_id
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    LEFT JOIN academic_years ay ON ay.id = u.academic_year_id
    WHERE u.role = 'student'
";
$stu_where = [];
$stu_params = [];
$stu_types = "";

if ($filter_stu_year !== '' && $filter_stu_year !== 'all') {
    if ($filter_stu_year_id !== null) {
        $stu_where[] = "(u.academic_year_id = ? OR ay.id = ? OR ay.year_label = ?)";
        $stu_params[] = $filter_stu_year_id;
        $stu_params[] = $filter_stu_year_id;
        $stu_params[] = $filter_stu_year;
        $stu_types .= "iis";
    } else {
        $stu_where[] = "ay.year_label = ?";
        $stu_params[] = $filter_stu_year;
        $stu_types .= "s";
    }
}

if ($search_student !== '') {
    $stu_where[] = "(sp.student_roll LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR c.company_name LIKE ?)";
    $like_stu = '%' . $search_student . '%';
    $stu_params[] = $like_stu;
    $stu_params[] = $like_stu;
    $stu_params[] = $like_stu;
    $stu_params[] = $like_stu;
    $stu_types .= "ssss";
}

if (!empty($stu_where)) {
    $stu_sql .= " AND " . implode(" AND ", $stu_where);
}

// Multi-level sorting: 1st Priority Academic Year DESC, 2nd Priority Roll Number natural ASC (LENGTH + roll)
$stu_sql .= " ORDER BY 
    COALESCE(ay.start_date, '1970-01-01') DESC,
    COALESCE(ay.id, u.academic_year_id, 0) DESC,
    LENGTH(COALESCE(NULLIF(sp.student_roll, ''), u.username)) ASC,
    COALESCE(NULLIF(sp.student_roll, ''), u.username) ASC";

if (!empty($stu_params)) {
    $stu_stmt = $db->prepare($stu_sql);
    $stu_stmt->bind_param($stu_types, ...$stu_params);
    $stu_stmt->execute();
    $res_stu = $stu_stmt->get_result();
    $students = $res_stu ? $res_stu->fetch_all(MYSQLI_ASSOC) : [];
    $stu_stmt->close();
} else {
    $res_stu = $db->query($stu_sql);
    $students = $res_stu ? $res_stu->fetch_all(MYSQLI_ASSOC) : [];
}

// Multi-level sorting: 1st Priority Academic Year DESC (latest year first), 2nd Priority Roll Number natural ASC (e.g. 5CS-1, 5CS-2, 5CS-10)
usort($students, function ($a, $b) {
    $ayA = trim($a['academic_year'] ?? '');
    $ayB = trim($b['academic_year'] ?? '');

    $yearA = preg_match('/^(\d{4})/', $ayA, $mA) ? (int)$mA[1] : 0;
    $yearB = preg_match('/^(\d{4})/', $ayB, $mB) ? (int)$mB[1] : 0;

    if ($yearA !== $yearB) {
        return $yearB <=> $yearA; // Latest academic year first (e.g. 2025 before 2024 before 2023)
    }

    if ($ayA !== $ayB) {
        if ($ayA === '') return 1;
        if ($ayB === '') return -1;
        $ayComp = strnatcasecmp($ayB, $ayA);
        if ($ayComp !== 0) return $ayComp;
    }

    $rollA = trim($a['student_roll'] ?: $a['username']);
    $rollB = trim($b['student_roll'] ?: $b['username']);

    return strnatcasecmp($rollA, $rollB);
});

// All users (with optional role filter, pending filter, academic year filter, and search filter)
$search_term = trim($_GET['search'] ?? '');
$filter_role = $_GET['role'] ?? '';
$filter_pending = (isset($_GET['filter']) && $_GET['filter'] === 'pending') || $filter_role === 'pending' || (isset($_GET['status']) && $_GET['status'] === 'pending');

$all_users_sql = "
    SELECT u.id, u.username, u.email, u.phone AS user_phone, u.department, u.position, u.role, u.is_first_login, 
           ay.year_label AS academic_year, u.academic_year_id, u.status, u.created_at, u.last_login_at,
           u.username AS full_name, sp.student_roll, sp.major, u.phone AS student_phone, 
           COALESCE(c.company_name, '') AS company_name, 
           u.position AS job_role,
           '' AS instructor_name, '' AS instructor_email, '' AS instructor_phone,
           sp.internship_start_date, sp.internship_end_date,
           sp.supervisor_id,
           sup_u.username AS supervisor_username,
           sup_u.email AS supervisor_email,
           c.contact_person AS company_contact_person,
           c.contact_email AS company_contact_email,
           c.contact_phone AS company_contact_phone
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    LEFT JOIN companies c ON c.id = sp.company_id
    LEFT JOIN academic_years ay ON ay.id = u.academic_year_id
";
$where_clauses = [];
$params = [];
$types = "";

if (in_array($filter_role, ['admin', 'supervisor', 'student'], true)) {
    $where_clauses[] = "u.role = ?";
    $params[] = $filter_role;
    $types .= "s";
}
if ($filter_pending) {
    $where_clauses[] = "u.is_first_login = 1 AND u.role != 'admin'";
}
if ($selected_year !== '' && $selected_year !== 'all') {
    if ($selected_academic_id !== null) {
        $where_clauses[] = "u.academic_year_id = ?";
        $params[] = $selected_academic_id;
        $types .= "i";
    } else {
        $where_clauses[] = "ay.year_label = ?";
        $params[] = $selected_year;
        $types .= "s";
    }
}
if ($search_term !== '') {
    $where_clauses[] = "(u.username LIKE ? OR sp.student_roll LIKE ? OR c.company_name LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search_term . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

if (!empty($where_clauses)) {
    $all_users_sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$all_users_sql .= " ORDER BY FIELD(u.role, 'admin', 'supervisor', 'student'), u.created_at DESC";

if (!empty($params)) {
    $all_users_stmt = $db->prepare($all_users_sql);
    $all_users_stmt->bind_param($types, ...$params);
    $all_users_stmt->execute();
    $res = $all_users_stmt->get_result();
    $all_users = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $all_users_stmt->close();
} else {
    $res = $db->query($all_users_sql);
    $all_users = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}



// Notifications
$unread_notif_q = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->bind_param("i", $admin_id);
$unread_notif_q->execute();
$res = $unread_notif_q->get_result();
$row = $res ? $res->fetch_row() : null;
$unread_notif_count = (int) ($row[0] ?? 0);

$recent_notifs_q = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->bind_param("i", $admin_id);
$recent_notifs_q->execute();
$res = $recent_notifs_q->get_result();
$recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Recent activity feed for the overview panel
$recent_activity_items = [];

$res = $db->query(
    "SELECT 'student' AS type, 'New student added' AS title, u.username AS detail, u.created_at
     FROM users u
     WHERE u.role = 'student'
     ORDER BY u.created_at DESC LIMIT 5"
);
$recent_student_activity = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
foreach ($recent_student_activity as $item) {
    $recent_activity_items[] = [
        'type' => 'student',
        'title' => 'New student added',
        'detail' => $item['detail'] ?? 'Student record created',
        'created_at' => $item['created_at'],
    ];
}

$res = $db->query(
    "SELECT 'supervisor' AS type, 'New supervisor added' AS title, u.username AS detail, u.created_at
     FROM users u
     WHERE u.role = 'supervisor'
     ORDER BY u.created_at DESC LIMIT 5"
);
$recent_supervisor_activity = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
foreach ($recent_supervisor_activity as $item) {
    $recent_activity_items[] = [
        'type' => 'supervisor',
        'title' => 'New supervisor added',
        'detail' => $item['detail'] ?? 'Supervisor record created',
        'created_at' => $item['created_at'],
    ];
}

$res = $db->query(
    "SELECT 'company' AS type, 'New company added' AS title, company_name AS detail, created_at
     FROM companies
     ORDER BY created_at DESC LIMIT 5"
);
$recent_company_activity = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
foreach ($recent_company_activity as $item) {
    $recent_activity_items[] = [
        'type' => 'company',
        'title' => 'New company added',
        'detail' => $item['detail'] ?? 'Company record created',
        'created_at' => $item['created_at'],
    ];
}




usort($recent_activity_items, function ($a, $b) {
    return strtotime($b['created_at'] ?? 'now') <=> strtotime($a['created_at'] ?? 'now');
});
$recent_activity_items = array_slice($recent_activity_items, 0, 8);

// ══════════════════════════════════════════════════════════════════
// ACTIVE TAB (Defined at top of file)
// ══════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/notifications.js"></script>
    <script>
        (function() {
            var theme = localStorage.getItem('theme');
            if (theme === 'dark') document.documentElement.classList.add('dark');
        })();
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        'inter': ['Inter', 'sans-serif'],
                    },
                    fontSize: {
                        'micro': '0.5rem',
                        'caption': '0.6875rem',
                        'label': '0.8125rem',
                        'subtitle': '0.9375rem',
                        'body': '1rem',
                    },
                }
            }
        }
    </script>
</head>

<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-sans antialiased">

    <div class="flex h-screen overflow-hidden">

        <?php
        $activePageMap = [
            'overview'     => 'dashboard',
            'students'     => 'students',
            'supervisors'  => 'supervisors',
            'manage'       => 'manage',
            'archive'      => 'archive',
            'history'      => 'history',
        ];
        $activePage = $activePageMap[$tab] ?? 'dashboard';

        $pageTitleMap = [
            'overview'     => 'Admin Overview',
            'students'     => 'Student Management',
            'supervisors'  => 'Supervisor Management',
            'manage'       => 'User Management',
            'archive'      => 'Archived Records',
            'history'      => 'Reports & Evaluation History',
        ];
        $pageTitle = $pageTitleMap[$tab] ?? 'Admin Dashboard';
        ?>
        <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

        <!-- ─── MAIN ─── -->
        <div class="flex-1 flex flex-col overflow-hidden">

            <!-- Top Bar -->
            <?php require_once __DIR__ . '/../includes/topbar.php'; ?>

            <!-- Content -->
            <main class="flex-1 overflow-y-auto p-4 lg:p-6" style="scrollbar-gutter: stable;">

                <?php if ($msg): ?>
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2 mb-6">
                        <span>✅</span> <?= htmlspecialchars($msg) ?>
                    </div>
                <?php endif; ?>
                <?php if ($err): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2 mb-6">
                        <span>❌</span> <?= htmlspecialchars($err) ?>
                    </div>
                <?php endif; ?>

                <div class="w-full space-y-6">

                    <!-- Success/Error Toast -->
                    <div id="toast" class="hidden fixed top-6 right-6 z-[100] max-w-sm"></div>

                    <!-- ════ ANALYTICS SUMMARY CARDS (always visible) ════ -->
                    <div class="w-full grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Students Card -->
                        <a href="admin-dashboard.php?tab=students<?= ($selected_year && $selected_year !== 'all') ? '&year=' . urlencode($selected_year) : '' ?>" class="group block bg-white rounded-2xl border border-slate-200 shadow-sm p-5 transition-all duration-200 hover:-translate-y-1 hover:shadow-md hover:border-teal-200 hover:bg-teal-50/60 cursor-pointer">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center text-xl transition group-hover:bg-teal-100">🎓</div>
                                <div>
                                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Students</p>
                                    <p class="text-2xl font-black text-slate-800"><?= $student_count ?></p>
                                </div>
                            </div>
                        </a>

                        <!-- Supervisors Card -->
                        <a href="admin-dashboard.php?tab=supervisors<?= ($selected_year && $selected_year !== 'all') ? '&year=' . urlencode($selected_year) : '' ?>" class="group block bg-white rounded-2xl border border-slate-200 shadow-sm p-5 transition-all duration-200 hover:-translate-y-1 hover:shadow-md hover:border-emerald-200 hover:bg-emerald-50/60 cursor-pointer">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center text-xl transition group-hover:bg-emerald-100">👨‍🏫</div>
                                <div>
                                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Supervisors</p>
                                    <p class="text-2xl font-black text-slate-800"><?= $supervisor_count ?></p>
                                </div>
                            </div>
                        </a>

                        <!-- Companies Card -->
                        <a href="manage-companies.php" class="group block bg-white rounded-2xl border border-slate-200 shadow-sm p-5 transition-all duration-200 hover:-translate-y-1 hover:shadow-md hover:border-teal-200 hover:bg-teal-50/60 cursor-pointer">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center text-xl transition group-hover:bg-teal-100">🏢</div>
                                <div>
                                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Companies</p>
                                    <p class="text-2xl font-black text-slate-800"><?= $company_count ?></p>
                                </div>
                            </div>
                        </a>

                        <!-- Pending Requests Card — dynamic alert style -->
                        <a href="admin-dashboard.php?tab=manage&filter=pending<?= ($selected_year && $selected_year !== 'all') ? '&year=' . urlencode($selected_year) : '' ?>" class="group block rounded-2xl shadow-sm p-5 transition-all duration-200 hover:-translate-y-1 hover:shadow-md cursor-pointer <?= $pending_count > 0
                                                                                                                                                                                                                                                                                                                ? 'bg-amber-50 border-2 border-amber-300 hover:border-amber-400 hover:bg-amber-100/60'
                                                                                                                                                                                                                                                                                                                : 'bg-white border border-slate-200 hover:border-amber-200 hover:bg-amber-50/60'
                                                                                                                                                                                                                                                                                                            ?>">
                            <div class="flex items-center gap-3">
                                <div class="relative w-11 h-11 rounded-xl flex items-center justify-center text-xl transition <?= $pending_count > 0
                                                                                                                                    ? 'bg-amber-100 text-amber-600 group-hover:bg-amber-200'
                                                                                                                                    : 'bg-amber-50 text-amber-600 group-hover:bg-amber-100'
                                                                                                                                ?>">
                                    ⏳
                                    <?php if ($pending_count > 0): ?>
                                        <span class="absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border-2 border-amber-50 animate-pulse"></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wider <?= $pending_count > 0 ? 'text-amber-600' : 'text-slate-400' ?>">Pending Requests</p>
                                    <div class="flex items-center gap-2">
                                        <p class="text-2xl font-black <?= $pending_count > 0 ? 'text-amber-700' : 'text-slate-800' ?>"><?= $pending_count ?></p>
                                        <?php if ($pending_count > 0): ?>
                                            <span class="text-xs font-bold text-amber-700 bg-amber-200 px-2 py-0.5 rounded-full animate-pulse">Action Needed</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php if ($tab === 'overview'): ?>
                        <!-- ════ TAB: OVERVIEW ════ -->

                        <div class="w-full grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6 items-start">

                            <!-- Recent Students -->
                            <div class="bg-white border border-slate-100 rounded-2xl p-6 shadow-sm h-full">
                                <div class="flex justify-between items-center mb-4">
                                    <h2 class="text-xs font-bold text-slate-400 tracking-wider uppercase">Recent Students</h2>
                                    <a href="?tab=manage" class="text-sm font-bold text-indigo-600 hover:underline">View All →</a>
                                </div>
                                <div class="divide-y divide-slate-100 max-h-64 overflow-y-auto">
                                    <?php foreach (array_slice($students, 0, 5) as $s): ?>
                                        <div class="py-3 flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm font-bold shrink-0">
                                                <?= strtoupper(($s['full_name'] ?: $s['username'])[0]) ?>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-xs font-semibold text-slate-700 truncate"><?= htmlspecialchars($s['full_name'] ?: $s['username']) ?></p>
                                                <p class="text-sm text-slate-400"><?= htmlspecialchars($s['company_name'] ?: 'No company') ?></p>
                                            </div>
                                            <span class="text-sm text-slate-400 shrink-0"><?= htmlspecialchars($s['student_roll'] ?: '') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($students)): ?>
                                        <div class="py-6 text-center text-xs text-slate-400">No students yet.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Recent Activities -->
                            <div class="bg-white border border-slate-100 rounded-2xl p-6 shadow-sm h-full">
                                <div class="flex justify-between items-center mb-4">
                                    <h2 class="text-xs font-bold text-slate-400 tracking-wider uppercase">Recent Activities</h2>
                                    <a href="?tab=history" class="text-sm font-bold text-indigo-600 hover:underline">View History &rarr;</a>
                                </div>
                                <?php if (!empty($recent_activity_items)): ?>
                                    <div class="space-y-3 max-h-72 overflow-y-auto pr-1">
                                        <?php foreach ($recent_activity_items as $activity): ?>
                                            <?php
                                            $activity_icon = [
                                                'student' => '🎓',
                                                'supervisor' => '👨‍🏫',
                                                'company' => '🏢',
                                                'holiday' => '🇲🇲',
                                                'announcement' => '📢',
                                            ][$activity['type']] ?? '📋';
                                            $activity_bg = [
                                                'student' => 'bg-indigo-50 text-indigo-600',
                                                'supervisor' => 'bg-emerald-50 text-emerald-600',
                                                'company' => 'bg-blue-50 text-blue-600',
                                                'holiday' => 'bg-red-50 text-red-600',
                                                'announcement' => 'bg-amber-50 text-amber-600',
                                            ][$activity['type']] ?? 'bg-slate-100 text-slate-500';
                                            $activity_time = $activity['created_at'] ? (new DateTime($activity['created_at']))->format('d M Y, H:i') : 'Recently added';
                                            ?>
                                            <div class="flex items-start gap-3 rounded-xl border border-slate-100 bg-slate-50/70 p-3">
                                                <div class="w-9 h-9 rounded-xl flex items-center justify-center text-lg shrink-0 <?= $activity_bg ?>">
                                                    <?= $activity_icon ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($activity['title']) ?></p>
                                                    <p class="text-sm text-slate-500 truncate"><?= htmlspecialchars($activity['detail']) ?></p>
                                                    <p class="text-xs text-slate-400 mt-1"><?= htmlspecialchars($activity_time) ?></p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="flex flex-col items-center justify-center py-10 text-center">
                                        <div class="w-14 h-14 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-2xl mb-4">📋</div>
                                        <p class="text-sm font-semibold text-slate-500">No recent activity yet</p>
                                        <p class="text-xs text-slate-400 mt-1 max-w-[240px]">New students, supervisors, and companies will appear here.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div>

                    <?php elseif ($tab === 'students'): ?>
                        <!-- ════ TAB: ADD STUDENT ════ -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-6">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-indigo-50 text-indigo-600 rounded">🎓</span> Register New Student
                                </h2>
                            </div>
                            <form method="POST" class="p-5 space-y-4">
                                <input type="hidden" name="add_student" value="1">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Full Name *</label>
                                        <input type="text" name="s_name" required placeholder="e.g. Aung Kyaw" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Roll Number *</label>
                                        <input type="text" name="s_roll" required placeholder="e.g. CS-2022-045" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Major / Department</label>
                                        <select name="s_major" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                            <option value="">— Select Major / Department —</option>
                                            <option value="Computer Science">Computer Science</option>
                                            <option value="Computer Technology">Computer Technology</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Email *</label>
                                        <input type="email" name="s_email" required placeholder="student@example.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Phone Number</label>
                                        <input type="text" name="s_phone" placeholder="e.g. 09-123-456-789" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Company <span class="text-slate-300 font-normal">(ကုမ္ပဏီ)</span></label>
                                        <select name="s_company_id" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                            <option value="">— Select Company —</option>
                                            <?php foreach ($companies as $c): ?>
                                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Supervisor <span class="text-slate-300 font-normal">(ကျောင်းကဆရာ/မ)</span></label>
                                        <select name="s_supervisor_id" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                            <option value="">— Select Supervisor —</option>
                                            <?php foreach ($supervisors as $sup): ?>
                                                <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['username']) ?> (<?= htmlspecialchars($sup['email']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Academic Year</label>
                                        <div class="relative">
                                            <input type="text"
                                                value="<?= htmlspecialchars($current_active_year_label ? ($current_active_year_label . ' (Current Academic Year)') : 'No Active Academic Year') ?>"
                                                readonly
                                                disabled
                                                class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-indigo-700 cursor-not-allowed select-none">
                                            <input type="hidden" name="academic_year_id" value="<?= (int)($active_ay_rec['id'] ?? 0) ?>">
                                        </div>
                                        <p class="text-xs text-slate-400 mt-1">Automatically bound to the active academic year.</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Internship Start Date</label>
                                        <input type="date" name="s_start_date" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Internship End Date</label>
                                        <input type="date" name="s_end_date" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Default Password *</label>
                                        <input type="text" name="s_password" required minlength="6" value="<?= htmlspecialchars($def_student_pw) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 font-mono focus:outline-none focus:border-blue-500 transition">
                                        <p class="text-sm text-slate-400 mt-0.5">Must change on first login.</p>
                                    </div>
                                </div>
                                <div class="flex justify-end pt-2">
                                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">🎓 Create Student</button>
                                </div>
                            </form>
                        </div>

                        <!-- ════ STUDENTS TABLE ════ -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
                                <div class="flex items-center gap-3">
                                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                        <span class="p-1 bg-indigo-50 text-indigo-600 rounded">🎓</span> All Registered Students
                                    </h2>
                                    <span id="stuCountDisplay" class="text-xs text-slate-400 font-semibold bg-slate-100 px-2.5 py-0.5 rounded-full"><?= count($students) ?> total</span>
                                </div>
                                <div class="flex items-center gap-3 flex-wrap">
                                    <!-- Academic Year Filter Dropdown -->
                                    <div class="flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                                        <label for="filter_student_academic_year" class="text-slate-500 whitespace-nowrap">Academic Year:</label>
                                        <select id="filter_student_academic_year" name="academic_year_filter" onchange="filterStudentByAcademicYear(this.value)" class="bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition cursor-pointer">
                                            <option value="all" <?= ($filter_stu_year === 'all' || empty($filter_stu_year)) ? 'selected' : '' ?>>All Academic Years</option>
                                            <?php foreach ($academic_years as $ay): ?>
                                                <option value="<?= htmlspecialchars($ay) ?>" <?= ($filter_stu_year === $ay) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($ay) ?><?= ($ay === $current_active_year_label) ? ' (Active)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Live / Instant Search Bar (No reload) -->
                                    <div class="relative flex-1 sm:w-64 max-w-xs">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                            </svg>
                                        </span>
                                        <input
                                            type="text"
                                            id="studentLiveSearchInput"
                                            value=""
                                            oninput="handleStudentLiveSearch(this.value)"
                                            onkeyup="handleStudentLiveSearch(this.value)"
                                            onkeydown="if(event.key === 'Enter'){ event.preventDefault(); return false; }"
                                            placeholder="Search roll no, name, email, company..."
                                            autocomplete="off"
                                            class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-8 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200">
                                        <button
                                            type="button"
                                            id="clearStudentSearchBtn"
                                            onclick="clearStudentLiveSearch()"
                                            class="hidden absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600 text-xs font-bold transition cursor-pointer"
                                            title="Clear search">✕</button>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($students)): ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-sm">
                                        <thead>
                                            <tr class="bg-slate-50 text-slate-500 font-semibold uppercase tracking-wider text-xs border-b border-slate-100">
                                                <th class="px-4 py-3 min-w-[120px]"># / Roll No</th>
                                                <th class="px-4 py-3 min-w-[220px]">Student Info</th>
                                                <th class="px-4 py-3 min-w-[170px]">Academic Details</th>
                                                <th class="px-4 py-3 min-w-[220px]">Internship Assignment</th>
                                                <th class="px-4 py-3 min-w-[180px]">Status & Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="studentTableBody" class="divide-y divide-slate-100">
                                            <?php
                                            $current_batch_year = null;
                                            foreach ($students as $i => $s):
                                                $s_ay = $s['academic_year'] ?: 'Unassigned Year';
                                                $is_new_batch = ($s_ay !== $current_batch_year);
                                                if ($is_new_batch) {
                                                    $current_batch_year = $s_ay;
                                                }
                                                $stu_search_str = strtolower(trim(($s['student_roll'] ?? '') . ' ' . ($s['username'] ?? '') . ' ' . ($s['full_name'] ?? '') . ' ' . ($s['email'] ?? '') . ' ' . ($s['company_name'] ?? '') . ' ' . ($s['job_role'] ?? '') . ' ' . ($s['supervisor_name'] ?? '') . ' ' . $s_ay));
                                            ?>
                                                <?php if ($is_new_batch && (empty($filter_stu_year) || $filter_stu_year === 'all')): ?>
                                                    <tr class="academic-year-header-row bg-slate-100/90 border-y border-slate-200" data-group-ay="<?= htmlspecialchars($s_ay) ?>">
                                                        <td colspan="5" class="px-4 py-2 text-xs font-bold text-slate-700">
                                                            <div class="flex items-center gap-2">
                                                                <span class="p-1 bg-indigo-100 text-indigo-700 rounded text-xs leading-none">🎓</span>
                                                                <span class="text-slate-500 uppercase tracking-wider text-[11px] font-bold">Academic Batch:</span>
                                                                <span class="font-mono font-bold text-indigo-700 bg-white px-2.5 py-0.5 rounded-md border border-indigo-200 shadow-xs"><?= htmlspecialchars($s_ay) ?></span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                                <tr class="student-row hover:bg-slate-50/80 transition-colors" data-search="<?= htmlspecialchars($stu_search_str) ?>" data-ay="<?= htmlspecialchars($s_ay) ?>">
                                                    <td class="px-4 py-3.5">
                                                        <div class="flex items-center gap-2">
                                                            <span class="text-slate-400 font-mono text-xs w-5"><?= $i + 1 ?></span>
                                                            <span class="font-mono font-bold text-slate-800 text-xs bg-slate-100 px-2 py-0.5 rounded border border-slate-200/60"><?= htmlspecialchars($s['student_roll'] ?: $s['username']) ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3.5">
                                                        <div class="flex items-start gap-2.5">
                                                            <div class="w-8 h-8 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">
                                                                <?= strtoupper(($s['full_name'] ?: $s['username'])[0]) ?>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($s['full_name'] ?: $s['username']) ?></span>
                                                                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3 text-xs text-slate-500 mt-0.5">
                                                                    <a href="mailto:<?= htmlspecialchars($s['email']) ?>" class="inline-flex items-center gap-1 text-slate-600 hover:text-indigo-600 transition">
                                                                        <i class="fa-regular fa-envelope text-[11px] text-slate-400"></i>
                                                                        <span><?= htmlspecialchars($s['email']) ?></span>
                                                                    </a>
                                                                    <?php $stu_phone = $s['student_phone'] ?: $s['user_phone']; ?>
                                                                    <?php if (!empty($stu_phone)): ?>
                                                                        <a href="tel:<?= htmlspecialchars($stu_phone) ?>" class="inline-flex items-center gap-1 text-slate-600 hover:text-indigo-600 transition">
                                                                            <i class="fa-solid fa-phone text-[11px] text-slate-400"></i>
                                                                            <span class="font-mono"><?= htmlspecialchars($stu_phone) ?></span>
                                                                        </a>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3.5">
                                                        <div class="flex flex-col gap-1 text-xs">
                                                            <span class="font-semibold text-slate-700"><?= htmlspecialchars($s['major'] ?: 'General') ?></span>
                                                            <?php if (!empty($s['academic_year'])): ?>
                                                                <span class="inline-block font-mono font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded w-fit text-[11px]"><?= htmlspecialchars($s['academic_year']) ?></span>
                                                            <?php else: ?>
                                                                <span class="text-slate-400">—</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3.5">
                                                        <div class="flex flex-col gap-1 text-xs">
                                                            <div class="flex items-center gap-1.5">
                                                                <i class="fa-regular fa-building text-[11px] text-slate-400"></i>
                                                                <span class="font-bold text-slate-800"><?= htmlspecialchars($s['company_name'] ?: 'No Company Assigned') ?></span>
                                                            </div>
                                                            <?php if (!empty($s['job_role'])): ?>
                                                                <div class="text-slate-500 flex items-center gap-1.5">
                                                                    <span class="text-slate-400">Role:</span>
                                                                    <span><?= htmlspecialchars($s['job_role']) ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div class="text-slate-500 flex items-center gap-1.5">
                                                                <i class="fa-solid fa-chalkboard-user text-[11px] text-slate-400"></i>
                                                                <span class="text-slate-400">Supervisor:</span>
                                                                <span class="font-medium text-slate-700"><?= htmlspecialchars($s['supervisor_name'] ?: 'Unassigned') ?></span>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3.5">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <div>
                                                                <?= $s['is_first_login']
                                                                    ? '<span class="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200/60 px-2 py-0.5 rounded-full whitespace-nowrap">⏳ Pending</span>'
                                                                    : '<span class="text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200/60 px-2 py-0.5 rounded-full whitespace-nowrap">✅ Active</span>'
                                                                ?>
                                                                <?php if (($s['status'] ?? 'Active') === 'Archived'): ?>
                                                                    <span class="text-xs font-bold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded ml-1">📦</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php
                                                            $stu_modal_data = [
                                                                'id' => (int) $s['uid'],
                                                                'role' => 'student',
                                                                'username' => $s['username'],
                                                                'full_name' => $s['full_name'] ?: $s['username'],
                                                                'student_roll' => $s['student_roll'] ?: '',
                                                                'email' => $s['email'] ?: '',
                                                                'phone' => ($s['student_phone'] ?: $s['user_phone']) ?: '',
                                                                'major' => $s['major'] ?: '',
                                                                'academic_year' => $s['academic_year'] ?: '',
                                                                'company_name' => $s['company_name'] ?: '',
                                                                'job_role' => $s['job_role'] ?: '',
                                                                'instructor_name' => $s['instructor_name'] ?? '',
                                                                'instructor_email' => $s['instructor_email'] ?? '',
                                                                'instructor_phone' => $s['instructor_phone'] ?? '',
                                                                'internship_start_date' => (!empty($s['internship_start_date']) && $s['internship_start_date'] !== '0000-00-00') ? (new DateTime($s['internship_start_date']))->format('d M Y') : '',
                                                                'internship_end_date' => (!empty($s['internship_end_date']) && $s['internship_end_date'] !== '0000-00-00') ? (new DateTime($s['internship_end_date']))->format('d M Y') : '',
                                                                'supervisor_name' => $s['supervisor_name'] ?: '',
                                                                'supervisor_email' => $s['supervisor_email'] ?? '',
                                                                'status' => ($s['status'] ?? 'Active') === 'Archived' ? 'Archived' : ($s['is_first_login'] ? 'Pending' : 'Active'),
                                                                'is_first_login' => (bool) ($s['is_first_login'] ?? 0),
                                                                'created_at' => !empty($s['created_at']) ? (new DateTime($s['created_at']))->format('d M Y') : '',
                                                                'last_login_at' => !empty($s['last_login_at']) ? (new DateTime($s['last_login_at']))->format('d M Y, h:i A') : '',
                                                            ];
                                                            $stu_modal_json = htmlspecialchars(json_encode($stu_modal_data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                                            ?>
                                                            <div class="flex items-center gap-1.5">
                                                                <button type="button"
                                                                    onclick="openUserDetailsModal(this)"
                                                                    data-user='<?= $stu_modal_json ?>'
                                                                    class="px-2.5 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold rounded-lg border border-indigo-200/60 shadow-xs transition flex items-center gap-1 cursor-pointer"
                                                                    title="View student details">
                                                                    <i class="fa-regular fa-eye text-xs"></i> Details
                                                                </button>
                                                                <form method="POST" onsubmit="return confirm('Reset password for <?= htmlspecialchars($s['full_name'] ?: $s['username'], ENT_QUOTES) ?>?\nDefault password: <?= htmlspecialchars($def_student_pw) ?>')" class="inline">
                                                                    <input type="hidden" name="reset_password" value="1">
                                                                    <input type="hidden" name="reset_uid" value="<?= $s['uid'] ?>">
                                                                    <button type="submit" class="px-2.5 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-bold rounded-lg border border-amber-200/60 shadow-xs transition cursor-pointer" title="Reset default password">🔑</button>
                                                                </form>
                                                                <form method="POST" onsubmit="return confirm('Delete student <?= htmlspecialchars($s['full_name'] ?: $s['username'], ENT_QUOTES) ?>?')" class="inline">
                                                                    <input type="hidden" name="delete_user" value="1">
                                                                    <input type="hidden" name="delete_uid" value="<?= $s['uid'] ?>">
                                                                    <button type="submit" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 text-xs font-bold rounded-lg border border-rose-200/60 shadow-xs transition cursor-pointer" title="Delete student">
                                                                        <i class="fa-regular fa-trash-can"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr id="noStudentsMatchRow" class="hidden">
                                                <td colspan="5" class="px-4 py-8 text-center text-xs text-slate-400">No student records found matching your search.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="px-6 py-3 border-t border-slate-100 bg-slate-50">
                                    <p id="stuFooterCountDisplay" class="text-sm text-slate-400">Showing <?= count($students) ?> student(s) <?= ($filter_stu_year && $filter_stu_year !== 'all') ? 'for ' . htmlspecialchars($filter_stu_year) : 'across all years' ?>.</p>
                                </div>
                            <?php else: ?>
                                <div class="p-10 text-center">
                                    <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">🎓</div>
                                    <p class="text-sm font-semibold text-slate-500 mb-1">No students found</p>
                                    <p class="text-sm text-slate-400">
                                        <?= ($filter_stu_year && $filter_stu_year !== 'all') || !empty($search_student)
                                            ? 'Try clearing the search or academic year filter.'
                                            : 'Use the form above to add your first student.'
                                        ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($tab === 'supervisors'): ?>
                        <!-- ════ TAB: ADD SUPERVISOR ════ -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-emerald-50 text-emerald-600 rounded">👨‍🏫</span> Register New Supervisor
                                </h2>
                            </div>
                            <form method="POST" class="p-5 space-y-4">
                                <input type="hidden" name="add_supervisor" value="1">
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Teacher Name *</label>
                                        <input type="text" name="t_name" required placeholder="e.g. Dr. Myint Thein" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-emerald-500 transition">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Email *</label>
                                        <input type="email" name="t_email" required placeholder="supervisor@example.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-emerald-500 transition">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Department</label>
                                        <select name="t_dept" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-emerald-500 transition cursor-pointer">
                                            <option value="">— Select Department —</option>
                                            <option value="Computer Science">Faculty of Computer Science(FCS)</option>
                                            <option value="Information Science">Faculty of Information Science(FIS)</option>
                                            <option value="Computer Systems and Technologies">Faculty of Computer Systems and Technologies(FCST)</option>
                                            <option value="Information Technology Supporting and Maintenance">Department of Information Technology Supporting and Maintenance</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Rank</label>
                                        <select name="t_position" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-emerald-500 transition cursor-pointer">
                                            <option value="">— Select Rank —</option>
                                            <option value="Professor">Professor</option>
                                            <option value="Associate Professor">Associate Professor</option>
                                            <option value="Lecturer">Lecturer</option>
                                            <option value="Assistant Lecturer">Assistant Lecturer</option>
                                            <option value="Tutor">Tutor</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Default Password *</label>
                                        <input type="text" name="t_password" required minlength="6" value="<?= htmlspecialchars($def_supervisor_pw) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 font-mono focus:outline-none focus:border-emerald-500 transition">
                                        <p class="text-sm text-slate-400 mt-0.5">Must change on first login.</p>
                                    </div>
                                </div>
                                <div class="flex justify-end pt-2">
                                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">👨‍🏫 Create Supervisor</button>
                                </div>
                            </form>
                        </div>

                        <!-- ════ SUPERVISORS TABLE ════ -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
                                <div class="flex items-center gap-2.5">
                                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                        <span class="p-1 bg-emerald-50 text-emerald-600 rounded">👨‍🏫</span> All Registered Supervisors
                                    </h2>
                                    <span id="supCountBadge" class="text-xs font-semibold text-slate-400 bg-slate-100 px-2.5 py-0.5 rounded-full"><?= count($supervisors) ?> total</span>
                                </div>
                                <div class="flex items-center gap-3 flex-wrap">
                                    <?php if ($selected_year && $selected_year !== 'all'): ?>
                                        <span class="text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200/60 font-mono">
                                            Session: <?= htmlspecialchars($selected_year) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($supervisors)): ?>
                                        <div class="relative w-full sm:w-64">
                                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                                <i class="fa-solid fa-magnifying-glass text-xs"></i>
                                            </span>
                                            <input type="text"
                                                id="supervisorLiveSearchInput"
                                                oninput="handleSupervisorLiveSearch(this.value)"
                                                placeholder="Search name, email..."
                                                class="w-full bg-slate-50/80 hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-emerald-500 rounded-xl pl-9 pr-8 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 shadow-2xs transition-all duration-200"
                                                autocomplete="off"
                                                spellcheck="false">
                                            <button type="button"
                                                id="clearSupSearchBtn"
                                                onclick="clearSupervisorLiveSearch()"
                                                class="hidden absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
                                                title="Clear search"
                                                aria-label="Clear search">
                                                <i class="fa-solid fa-xmark text-xs"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($supervisors)): ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-sm">
                                        <thead>
                                            <tr class="bg-slate-50 text-slate-500 font-semibold uppercase tracking-wider text-xs border-b border-slate-100">
                                                <th class="px-4 py-3 w-12 text-center">#</th>
                                                <th class="px-4 py-3 min-w-[220px]">Supervisor Info</th>
                                                <th class="px-4 py-3 min-w-[180px]">Rank</th>
                                                <th class="px-4 py-3 min-w-[150px]">Assigned Years</th>
                                                <th class="px-4 py-3 min-w-[180px]">Status & Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="supervisorTableBody" class="divide-y divide-slate-100">
                                            <?php foreach ($supervisors as $i => $sup): ?>
                                                <?php
                                                $sup_search_str = strtolower(trim(($sup['username'] ?? '') . ' ' . ($sup['email'] ?? '')));
                                                // Fetch assigned years for this supervisor
                                                $sup_years_q = $db->prepare("
                                                    SELECT ay.year_label FROM supervisor_academic_assignments saa
                                                    JOIN academic_years ay ON ay.id = saa.academic_year_id
                                                    WHERE saa.supervisor_id = ?
                                                    ORDER BY ay.start_date DESC
                                                ");
                                                $sup_years_q->bind_param("i", $sup['id']);
                                                $sup_years_q->execute();
                                                $sup_years_res = $sup_years_q->get_result();
                                                $sup_assigned_years = [];
                                                while ($sy = $sup_years_res ? $sup_years_res->fetch_assoc() : null) {
                                                    if ($sy) $sup_assigned_years[] = $sy['year_label'];
                                                }
                                                $sup_years_q->close();
                                                // Count total students supervised across all years
                                                $sup_total_students_q = $db->prepare("SELECT COUNT(*) FROM student_profiles WHERE supervisor_id = ?");
                                                $sup_total_students_q->bind_param("i", $sup['id']);
                                                $sup_total_students_q->execute();
                                                $sup_total_students_res = $sup_total_students_q->get_result();
                                                $sup_total_students_row = $sup_total_students_res ? $sup_total_students_res->fetch_row() : null;
                                                $sup_total_students = (int) ($sup_total_students_row[0] ?? 0);
                                                $sup_total_students_q->close();
                                                ?>
                                                <tr class="supervisor-row hover:bg-slate-50/80 transition-colors" data-search="<?= htmlspecialchars($sup_search_str, ENT_QUOTES, 'UTF-8') ?>">
                                                    <td class="sup-row-num px-4 py-3.5 text-center text-slate-400 font-mono text-xs"><?= $i + 1 ?></td>
                                                    <td class="px-4 py-3.5">
                                                        <div class="flex items-start gap-2.5">
                                                            <div class="w-8 h-8 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">
                                                                <?= strtoupper(($sup['username'])[0]) ?>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($sup['username']) ?></span>
                                                                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3 text-xs text-slate-500 mt-0.5">
                                                                    <a href="mailto:<?= htmlspecialchars($sup['email']) ?>" class="inline-flex items-center gap-1 text-slate-600 hover:text-emerald-600 transition">
                                                                        <i class="fa-regular fa-envelope text-[11px] text-slate-400"></i>
                                                                        <span><?= htmlspecialchars($sup['email']) ?></span>
                                                                    </a>
                                                                    <?php if (!empty($sup['phone'])): ?>
                                                                        <a href="tel:<?= htmlspecialchars($sup['phone']) ?>" class="inline-flex items-center gap-1 text-slate-600 hover:text-emerald-600 transition">
                                                                            <i class="fa-solid fa-phone text-[11px] text-slate-400"></i>
                                                                            <span class="font-mono"><?= htmlspecialchars($sup['phone']) ?></span>
                                                                        </a>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3.5">
                                                        <div class="flex flex-col gap-0.5 text-xs">
                                                            <span class="font-semibold text-slate-700"><?= htmlspecialchars($sup['department'] ?: 'Department Unset') ?></span>
                                                            <span class="text-slate-400 text-[11px]"><?= htmlspecialchars($sup['position'] ?: 'Supervisor') ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3.5">
                                                        <?php if (!empty($sup_assigned_years)): ?>
                                                            <div class="flex flex-wrap gap-1">
                                                                <?php foreach (array_slice($sup_assigned_years, 0, 3) as $ay_label): ?>
                                                                    <span class="inline-block font-mono font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded text-[11px] border border-emerald-200/60"><?= htmlspecialchars($ay_label) ?></span>
                                                                <?php endforeach; ?>
                                                                <?php if (count($sup_assigned_years) > 3): ?>
                                                                    <span class="inline-block text-[11px] text-slate-400 font-semibold">+<?= count($sup_assigned_years) - 3 ?> more</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-xs text-slate-400">Unassigned</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3.5">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <div>
                                                                <?php
                                                                $is_inactive = strcasecmp(trim((string)($sup['status'] ?? 'Active')), 'Inactive') === 0;
                                                                $is_pending = !empty($sup['is_first_login']);
                                                                if ($is_inactive):
                                                                ?>
                                                                    <span class="inline-flex items-center gap-1.5 text-xs font-bold text-red-700 bg-red-50 border border-red-200/70 px-2.5 py-0.5 rounded-full whitespace-nowrap">
                                                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Inactive
                                                                    </span>
                                                                <?php elseif ($is_pending): ?>
                                                                    <span class="inline-flex items-center gap-1.5 text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200/70 px-2.5 py-0.5 rounded-full whitespace-nowrap">
                                                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Pending
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200/70 px-2.5 py-0.5 rounded-full whitespace-nowrap">
                                                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="flex items-center gap-1.5">
                                                                <button type="button"
                                                                    onclick="openSupervisorHistoryModal(<?= (int)$sup['id'] ?>, '<?= htmlspecialchars($sup['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($sup['email'], ENT_QUOTES) ?>')"
                                                                    class="px-2.5 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold rounded-lg border border-indigo-200/60 shadow-xs transition cursor-pointer flex items-center gap-1"
                                                                    title="View assignment history and supervised students">
                                                                    📜 History
                                                                </button>
                                                                <form method="POST" onsubmit="return confirm('<?= $is_inactive ? 'Activate' : 'Deactivate' ?> supervisor <?= htmlspecialchars($sup['username'], ENT_QUOTES) ?>?\n<?= $is_inactive ? 'They will be allowed to log in.' : 'They will NOT be allowed to log in.' ?>')" class="inline">
                                                                    <input type="hidden" name="toggle_user_status" value="1">
                                                                    <input type="hidden" name="status_uid" value="<?= $sup['id'] ?>">
                                                                    <input type="hidden" name="new_status" value="<?= $is_inactive ? 'Active' : 'Inactive' ?>">
                                                                    <button type="submit" class="px-2.5 py-1.5 <?= $is_inactive ? 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border-emerald-200/60' : 'bg-slate-100 hover:bg-slate-200 text-slate-600 border-slate-200/60' ?> text-xs font-bold rounded-lg border shadow-xs transition cursor-pointer flex items-center gap-1" title="<?= $is_inactive ? 'Activate supervisor (Allow login)' : 'Deactivate supervisor (Block login)' ?>">
                                                                        <?= $is_inactive ? '🟢 Activate' : '🔴 Deactivate' ?>
                                                                    </button>
                                                                </form>
                                                                <form method="POST" onsubmit="return confirm('Reset password for <?= htmlspecialchars($sup['username'], ENT_QUOTES) ?>?\nDefault password: <?= htmlspecialchars($def_supervisor_pw) ?>')" class="inline">
                                                                    <input type="hidden" name="reset_password" value="1">
                                                                    <input type="hidden" name="reset_uid" value="<?= $sup['id'] ?>">
                                                                    <button type="submit" class="px-2 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-bold rounded-lg border border-amber-200/60 shadow-xs transition cursor-pointer" title="Reset default password">🔑</button>
                                                                </form>
                                                                <form method="POST" onsubmit="return confirm('Delete supervisor <?= htmlspecialchars($sup['username'], ENT_QUOTES) ?>?')" class="inline">
                                                                    <input type="hidden" name="delete_user" value="1">
                                                                    <input type="hidden" name="delete_uid" value="<?= $sup['id'] ?>">
                                                                    <button type="submit" class="px-2 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 text-xs font-bold rounded-lg border border-rose-200/60 shadow-xs transition cursor-pointer" title="Delete supervisor">
                                                                        <i class="fa-regular fa-trash-can"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr id="noSupervisorsMatchRow" class="hidden">
                                                <td colspan="5" class="px-4 py-8 text-center text-xs text-slate-400">
                                                    <div class="flex flex-col items-center justify-center">
                                                        <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-400 flex items-center justify-center text-lg mb-2">
                                                            <i class="fa-solid fa-user-slash text-slate-400"></i>
                                                        </div>
                                                        <p class="font-semibold text-slate-600">No matching supervisors found</p>
                                                        <p class="text-slate-400 text-[11px] mt-0.5">No supervisor matches "<span id="supSearchQueryDisplay" class="font-medium text-slate-600"></span>"</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="px-6 py-3 border-t border-slate-100 bg-slate-50">
                                    <p class="text-sm text-slate-400" id="supFooterCount">Showing <?= count($supervisors) ?> supervisor(s).</p>
                                </div>
                            <?php else: ?>
                                <div class="p-10 text-center">
                                    <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">👨‍🏫</div>
                                    <p class="text-sm font-semibold text-slate-500 mb-1">No supervisors registered yet</p>
                                    <p class="text-sm text-slate-400">Use the form above to add your first supervisor.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($tab === 'manage'): ?>
                        <!-- ════ TAB: MANAGE USERS ════ -->

                        <!-- All Users -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
                                <div class="flex items-center gap-3">
                                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                        <span class="p-1 bg-slate-100 text-slate-600 rounded"><?= $filter_pending ? '⏳' : '👥' ?></span> <?= $filter_pending ? 'Pending Users' : 'All Users' ?>
                                    </h2>
                                    <span id="userCountDisplay" class="text-xs text-slate-400 font-semibold bg-slate-100 px-2 py-0.5 rounded-full"><?= count($all_users) ?> total</span>
                                </div>
                                <div class="flex items-center gap-3 flex-wrap">
                                    <!-- Academic Year Filter Dropdown -->
                                    <div class="flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                                        <label for="filter_academic_year" class="text-slate-500 whitespace-nowrap">Academic Year:</label>
                                        <select id="filter_academic_year" onchange="filterByAcademicYear(this.value)" class="bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 transition cursor-pointer">
                                            <option value="all" <?= ($selected_year === 'all' || empty($selected_year)) ? 'selected' : '' ?>>All Years</option>
                                            <?php foreach ($academic_years as $ay): ?>
                                                <option value="<?= htmlspecialchars($ay) ?>" <?= ($selected_year === $ay) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($ay) ?><?= ($ay === $current_active_year_label) ? ' (Current)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Live Instant Search Bar -->
                                    <div class="relative flex-1 sm:w-56 max-w-xs">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                            </svg>
                                        </span>
                                        <input
                                            type="text"
                                            id="userLiveSearchInput"
                                            oninput="handleUserLiveSearch(this.value)"
                                            placeholder="Search user, roll, company..."
                                            autocomplete="off"
                                            class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-8 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 transition-all duration-200">
                                        <button
                                            type="button"
                                            id="clearSearchBtn"
                                            onclick="clearUserLiveSearch()"
                                            class="hidden absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600 text-xs font-bold transition cursor-pointer"
                                            title="Clear search">✕</button>
                                    </div>

                                    <!-- Role Filter Buttons -->
                                    <div class="flex items-center gap-1">
                                        <a href="?<?= http_build_query(array_merge($_GET, ['role' => '', 'filter' => ''])) ?>" class="px-2.5 py-1.5 text-xs font-bold rounded-lg transition <?= ($filter_role === '' && !$filter_pending) ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">All</a>
                                        <a href="?<?= http_build_query(array_merge($_GET, ['role' => 'admin', 'filter' => ''])) ?>" class="px-2.5 py-1.5 text-xs font-bold rounded-lg transition <?= ($filter_role === 'admin' && !$filter_pending) ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-600 hover:bg-amber-100' ?>">Admin</a>
                                        <a href="?<?= http_build_query(array_merge($_GET, ['role' => 'supervisor', 'filter' => ''])) ?>" class="px-2.5 py-1.5 text-xs font-bold rounded-lg transition <?= ($filter_role === 'supervisor' && !$filter_pending) ? 'bg-emerald-500 text-white' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' ?>">Supervisor</a>
                                        <a href="?<?= http_build_query(array_merge($_GET, ['role' => 'student', 'filter' => ''])) ?>" class="px-2.5 py-1.5 text-xs font-bold rounded-lg transition <?= ($filter_role === 'student' && !$filter_pending) ? 'bg-indigo-500 text-white' : 'bg-indigo-50 text-indigo-600 hover:bg-indigo-100' ?>">Student</a>
                                        <a href="?<?= http_build_query(array_merge($_GET, ['role' => '', 'filter' => 'pending'])) ?>" class="px-2.5 py-1.5 text-xs font-bold rounded-lg transition <?= $filter_pending ? 'bg-amber-500 text-white shadow-xs' : 'bg-amber-50 text-amber-600 hover:bg-amber-100' ?>">Pending</a>
                                    </div>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-sm">
                                            <th class="px-3 py-2.5 text-left">User</th>
                                            <th class="px-3 py-2.5 text-left">Role</th>
                                            <th class="px-3 py-2.5 text-left">Year</th>
                                            <th class="px-3 py-2.5 text-left">Status</th>
                                            <th class="px-3 py-2.5 text-left">Created</th>
                                            <th class="px-3 py-2.5 text-left">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="allUsersTableBody" class="divide-y divide-slate-100">
                                        <?php foreach ($all_users as $u): ?>
                                            <?php
                                            $search_str = strtolower(trim(($u['full_name'] ?? '') . ' ' . ($u['username'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['student_roll'] ?? '') . ' ' . ($u['company_name'] ?? '')));
                                            $user_detail_data = [
                                                'id' => (int) $u['id'],
                                                'role' => $u['role'],
                                                'username' => $u['username'],
                                                'full_name' => $u['full_name'] ?: $u['username'],
                                                'student_roll' => $u['student_roll'] ?: '',
                                                'email' => $u['email'] ?: '',
                                                'phone' => ($u['role'] === 'student' ? ($u['student_phone'] ?: $u['user_phone']) : $u['user_phone']) ?: '',
                                                'major' => $u['major'] ?: '',
                                                'department' => $u['department'] ?: '',
                                                'position' => $u['position'] ?: '',
                                                'academic_year' => $u['academic_year'] ?: '',
                                                'company_name' => $u['company_name'] ?: '',
                                                'job_role' => $u['job_role'] ?: '',
                                                'instructor_name' => $u['instructor_name'] ?: '',
                                                'instructor_email' => $u['instructor_email'] ?: '',
                                                'instructor_phone' => $u['instructor_phone'] ?: '',
                                                'internship_start_date' => (!empty($u['internship_start_date']) && $u['internship_start_date'] !== '0000-00-00') ? (new DateTime($u['internship_start_date']))->format('d M Y') : '',
                                                'internship_end_date' => (!empty($u['internship_end_date']) && $u['internship_end_date'] !== '0000-00-00') ? (new DateTime($u['internship_end_date']))->format('d M Y') : '',
                                                'supervisor_name' => $u['supervisor_username'] ?: '',
                                                'supervisor_email' => $u['supervisor_email'] ?: '',
                                                'status' => ($u['status'] ?? 'Active') === 'Archived' ? 'Archived' : ($u['is_first_login'] ? 'Pending' : 'Active'),
                                                'is_first_login' => (bool) ($u['is_first_login'] ?? 0),
                                                'created_at' => !empty($u['created_at']) ? (new DateTime($u['created_at']))->format('d M Y') : '',
                                                'last_login_at' => !empty($u['last_login_at']) ? (new DateTime($u['last_login_at']))->format('d M Y, h:i A') : '',
                                            ];
                                            $user_detail_json = htmlspecialchars(json_encode($user_detail_data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <tr class="user-row hover:bg-slate-50 transition" data-search="<?= htmlspecialchars($search_str) ?>">
                                                <td class="px-3 py-2.5">
                                                    <div class="flex items-center gap-2">
                                                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold shrink-0
                                            <?= $u['role'] === 'admin' ? 'bg-amber-100 text-amber-600' : ($u['role'] === 'supervisor' ? 'bg-emerald-100 text-emerald-600' : 'bg-indigo-100 text-indigo-600') ?>">
                                                            <?= strtoupper(($u['full_name'] ?? $u['username'])[0]) ?>
                                                        </div>
                                                        <div>
                                                            <p class="font-semibold text-slate-700"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></p>
                                                            <p class="text-sm text-slate-400"><?= htmlspecialchars($u['email']) ?></p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-3 py-2.5">
                                                    <?php
                                                    $rs = ['admin' => ['Admin', 'text-amber-600', 'bg-amber-50'], 'supervisor' => ['Supervisor', 'text-emerald-600', 'bg-emerald-50'], 'student' => ['Student', 'text-indigo-600', 'bg-indigo-50']];
                                                    $r = $rs[$u['role']] ?? ['Unknown', 'text-slate-600', 'bg-slate-100'];
                                                    ?>
                                                    <a href="?tab=manage&role=<?= $u['role'] ?>" class="inline-block text-sm font-bold <?= $r[1] ?> <?= $r[2] ?> px-2 py-0.5 rounded capitalize hover:opacity-80 transition"><?= $r[0] ?></a>
                                                </td>
                                                <td class="px-3 py-2.5">
                                                    <?php if (!empty($u['academic_year'])): ?>
                                                        <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($u['academic_year']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-sm text-slate-400">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2.5">
                                                    <?php
                                                    $u_status = trim((string)($u['status'] ?? 'Active'));
                                                    if (strcasecmp($u_status, 'Inactive') === 0): ?>
                                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded">
                                                            <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Inactive
                                                        </span>
                                                    <?php elseif (strcasecmp($u_status, 'Archived') === 0): ?>
                                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-slate-700 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded">
                                                            📦 Archived
                                                        </span>
                                                    <?php elseif (!empty($u['is_first_login'])): ?>
                                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded">
                                                            ⏳ Pending
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded">
                                                            ✅ Active
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-2.5 text-slate-400 whitespace-nowrap"><?= (new DateTime($u['created_at']))->format('d M Y') ?></td>
                                                <td class="px-3 py-2.5">
                                                    <div class="flex items-center gap-1.5">
                                                        <button type="button"
                                                            onclick="openUserDetailsModal(this)"
                                                            data-user='<?= $user_detail_json ?>'
                                                            class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold rounded-lg border border-indigo-200/60 shadow-xs transition flex items-center gap-1 cursor-pointer"
                                                            title="View full user details">
                                                            <i class="fa-regular fa-eye text-xs"></i> Details
                                                        </button>
                                                        <?php if ($u['role'] !== 'admin'): ?>
                                                            <?php if ($u['role'] === 'student'): ?>
                                                                <form method="POST" onsubmit="return confirm('Toggle status for <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?> to <?= ($u['status'] ?? 'Active') === 'Archived' ? 'Active' : 'Archived' ?>?')" class="inline">
                                                                    <input type="hidden" name="toggle_user_status" value="1">
                                                                    <input type="hidden" name="status_uid" value="<?= $u['id'] ?>">
                                                                    <input type="hidden" name="new_status" value="<?= ($u['status'] ?? 'Active') === 'Archived' ? 'Active' : 'Archived' ?>">
                                                                    <button type="submit" class="px-2 py-1 <?= ($u['status'] ?? 'Active') === 'Archived' ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?> text-xs font-bold rounded-lg transition cursor-pointer" title="<?= ($u['status'] ?? 'Active') === 'Archived' ? 'Restore to Active (Allow Login)' : 'Archive Student (Block Login)' ?>">
                                                                        <?= ($u['status'] ?? 'Active') === 'Archived' ? '♻️ Activate' : '📦 Archive' ?>
                                                                    </button>
                                                                </form>
                                                            <?php elseif ($u['role'] === 'supervisor'): ?>
                                                                <?php $is_sup_inact = strcasecmp($u_status, 'Inactive') === 0; ?>
                                                                <form method="POST" onsubmit="return confirm('<?= $is_sup_inact ? 'Activate' : 'Deactivate' ?> supervisor <?= htmlspecialchars($u['full_name'] ?: $u['username'], ENT_QUOTES) ?>?\n<?= $is_sup_inact ? 'They will be allowed to log in.' : 'They will NOT be allowed to log in.' ?>')" class="inline">
                                                                    <input type="hidden" name="toggle_user_status" value="1">
                                                                    <input type="hidden" name="status_uid" value="<?= $u['id'] ?>">
                                                                    <input type="hidden" name="new_status" value="<?= $is_sup_inact ? 'Active' : 'Inactive' ?>">
                                                                    <button type="submit" class="px-2 py-1 <?= $is_sup_inact ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?> text-xs font-bold rounded-lg transition cursor-pointer" title="<?= $is_sup_inact ? 'Activate supervisor (Allow login)' : 'Deactivate supervisor (Block login)' ?>">
                                                                        <?= $is_sup_inact ? '🟢 Activate' : '🔴 Deactivate' ?>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="POST" onsubmit="return confirm('Reset password for <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>?\nNew password will be: <?= $u['role'] === 'supervisor' ? htmlspecialchars($def_supervisor_pw) : htmlspecialchars($def_student_pw) ?>')" class="inline">
                                                                <input type="hidden" name="reset_password" value="1">
                                                                <input type="hidden" name="reset_uid" value="<?= $u['id'] ?>">
                                                                <button type="submit" class="px-2 py-1 bg-amber-50 text-amber-600 text-sm font-bold rounded-lg hover:bg-amber-100 transition cursor-pointer" title="Reset to default password">🔑</button>
                                                            </form>
                                                            <form method="POST" onsubmit="return confirm('Delete this user?')" class="inline">
                                                                <input type="hidden" name="delete_user" value="1">
                                                                <input type="hidden" name="delete_uid" value="<?= $u['id'] ?>">
                                                                <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 text-sm font-bold rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr id="noUsersMatchRow" class="hidden">
                                            <td colspan="6" class="px-3 py-8 text-center text-xs text-slate-400">No users found matching your search.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <?php elseif ($tab === 'archive'): ?>
                        <!-- ════ TAB: BATCH ARCHIVE ════ -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-lg font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-amber-50 text-amber-600 rounded">📦</span> Batch Archive
                                </h2>
                            </div>
                            <form method="POST" class="p-5 space-y-4">
                                <input type="hidden" name="archive_batch" value="1">
                                <p class="text-sm text-slate-400 leading-relaxed">
                                    Archive all students from a specific academic year. Archived students will no longer appear in active lists.
                                </p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Academic Year</label>
                                        <select name="batch_year" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                            <option value="">— Select Year —</option>
                                            <?php foreach ($academic_years as $ay): ?>
                                                <option value="<?= htmlspecialchars($ay) ?>"><?= htmlspecialchars($ay) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="flex items-end">
                                        <button type="submit" onclick="return confirm('Archive all students from this batch?')" class="px-5 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">📦 Archive Batch</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Archived Students Summary -->
                        <?php
                        $res_arch = $db->query("
                SELECT ay.year_label, COUNT(*) AS cnt
                FROM users u
                JOIN academic_years ay ON ay.id = u.academic_year_id
                WHERE u.role = 'student' AND u.status = 'Archived'
                GROUP BY ay.id, ay.year_label
                ORDER BY ay.start_date DESC, ay.year_label DESC
            ");
                        $archived = $res_arch ? $res_arch->fetch_all(MYSQLI_ASSOC) : [];
                        ?>
                        <?php if (!empty($archived)): ?>
                            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                                <div class="px-5 py-3 border-b border-slate-100">
                                    <h2 class="text-xl font-black text-slate-700 uppercase tracking-wider">Archived Batches</h2>
                                </div>
                                <div class="p-5">
                                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                                        <?php foreach ($archived as $ar): ?>
                                            <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-center">
                                                <p class="text-sm font-bold text-amber-600 uppercase tracking-wider mb-0.5">📦 <?= htmlspecialchars($ar['year_label']) ?></p>
                                                <p class="text-sm font-black text-amber-700"><?= $ar['cnt'] ?></p>
                                                <p class="text-sm text-amber-400">student(s) archived</p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- ════ RESTORE / UNARCHIVE BATCH ════ -->
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100">
                                <h2 class="text-lg font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-emerald-50 text-emerald-600 rounded">♻️</span> Restore / Unarchive Batch
                                </h2>
                            </div>
                            <form method="POST" class="p-5 space-y-4">
                                <input type="hidden" name="restore_batch" value="1">
                                <p class="text-sm text-slate-400 leading-relaxed">
                                    Restore all archived students from a specific academic year back to <strong>Active</strong> status so they can log in again.
                                </p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-bold text-slate-500 mb-1">Academic Year</label>
                                        <select name="restore_year" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-800 focus:outline-none focus:border-emerald-500 transition">
                                            <option value="">— Select Year —</option>
                                            <?php foreach ($academic_years as $ay): ?>
                                                <option value="<?= htmlspecialchars($ay) ?>"><?= htmlspecialchars($ay) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="flex items-end">
                                        <button type="submit" onclick="return confirm('Restore all archived students from this batch?\nThey will be set back to Active status and can log in again.')" class="px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">♻️ Restore Batch</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Per-Year Restore Buttons -->
                        <?php if (!empty($archived)): ?>
                            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                                <div class="px-5 py-3 border-b border-slate-100">
                                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                        <span class="p-1 bg-emerald-50 text-emerald-600 rounded">♻️</span> Quick Restore
                                    </h2>
                                </div>
                                <div class="p-5">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                        <?php foreach ($archived as $ar): ?>
                                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
                                                <div>
                                                    <p class="text-sm font-bold text-slate-700"><?= htmlspecialchars($ar['year_label']) ?></p>
                                                    <p class="text-xs text-slate-400"><?= $ar['cnt'] ?> archived student(s)</p>
                                                </div>
                                                <form method="POST" onsubmit="return confirm('Restore all <?= $ar['cnt'] ?> archived student(s) from <?= htmlspecialchars($ar['year_label']) ?>?\nThey will be set back to Active status.')" class="inline">
                                                    <input type="hidden" name="restore_batch" value="1">
                                                    <input type="hidden" name="restore_year" value="<?= htmlspecialchars($ar['year_label']) ?>">
                                                    <button type="submit" class="px-3 py-1.5 bg-emerald-50 text-emerald-600 text-xs font-bold rounded-lg hover:bg-emerald-100 transition cursor-pointer flex items-center gap-1">
                                                        ♻️ Restore
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($tab === 'history'): ?>
                        <!-- ════ TAB: STUDENT HISTORY ════ -->
                        <?php
                        $hist_sql = "
                SELECT u.id AS uid, u.username, u.email, ay.year_label AS academic_year, u.academic_year_id, u.status, u.created_at,
                       u.username AS full_name, sp.student_roll, sp.major, COALESCE(c.company_name, '') AS company_name, u.position AS job_role,
                       '' AS instructor_name, sp.supervisor_id,
                       sup_u.username AS supervisor_name
                FROM users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                LEFT JOIN companies c ON c.id = sp.company_id
                LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
                LEFT JOIN academic_years ay ON ay.id = u.academic_year_id
                WHERE u.role = 'student'
            ";
                        $hist_params = [];
                        $hist_types = "";

                        if ($selected_year !== '' && $selected_year !== 'all') {
                            if ($selected_academic_id !== null) {
                                $hist_sql .= " AND u.academic_year_id = ?";
                                $hist_params[] = $selected_academic_id;
                                $hist_types .= "i";
                            } else {
                                $hist_sql .= " AND ay.year_label = ?";
                                $hist_params[] = $selected_year;
                                $hist_types .= "s";
                            }
                        }
                        if ($search_term !== '') {
                            $hist_sql .= " AND (u.username LIKE ? OR sp.student_roll LIKE ? OR c.company_name LIKE ? OR sup_u.username LIKE ?)";
                            $like = '%' . $search_term . '%';
                            $hist_params[] = $like;
                            $hist_params[] = $like;
                            $hist_params[] = $like;
                            $hist_params[] = $like;
                            $hist_types .= "ssss";
                        }

                        $hist_sql .= " ORDER BY u.username ASC";

                        if (!empty($hist_params)) {
                            $hist_stmt = $db->prepare($hist_sql);
                            $hist_stmt->bind_param($hist_types, ...$hist_params);
                            $hist_stmt->execute();
                            $res = $hist_stmt->get_result();
                            $hist_students = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                            $hist_stmt->close();
                        } else {
                            $res = $db->query($hist_sql);
                            $hist_students = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                        }

                        // Natural sorting: Academic Year (DESC) and then Roll Number (ASC naturally: SCS-1, SCS-2, SCS-10, SCS-11)
                        usort($hist_students, function ($a, $b) {
                            $yearCmp = strcmp($b['academic_year'] ?? '', $a['academic_year'] ?? '');
                            if ($yearCmp !== 0) {
                                return $yearCmp;
                            }
                            $rollA = trim($a['student_roll'] ?: $a['username']);
                            $rollB = trim($b['student_roll'] ?: $b['username']);
                            $cmp = strnatcasecmp($rollA, $rollB);
                            if ($cmp !== 0) {
                                return $cmp;
                            }
                            return strcasecmp($a['full_name'] ?: $a['username'], $b['full_name'] ?: $b['username']);
                        });

                        // Fetch latest grade for each student
                        $hist_grades = [];
                        foreach ($hist_students as $hs) {
                            $gq = $db->prepare("SELECT instructor_grade FROM weekly_reports WHERE student_id = ? AND instructor_grade IS NOT NULL ORDER BY submitted_at DESC LIMIT 1");
                            $gq->bind_param("i", $hs['uid']);
                            $gq->execute();
                            $res = $gq->get_result();
                            $row = $res ? $res->fetch_row() : null;
                            $hist_grades[$hs['uid']] = $row[0] ?? null;
                            $gq->close();
                        }
                        ?>

                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-4">
                                <div class="flex items-center gap-3">
                                    <h2 class="text-lg font-semibold text-slate-700 flex items-center gap-2">
                                        <span>📜 Reports &amp; Log History</span>
                                        <?php if ($selected_year && $selected_year !== 'all'): ?>
                                            <span class="text-teal-700 font-mono text-sm font-semibold">— <?= htmlspecialchars($selected_year) ?></span>
                                        <?php elseif ($selected_year === 'all'): ?>
                                            <span class="text-teal-700 font-mono text-sm font-semibold">— All Years</span>
                                        <?php endif; ?>
                                    </h2>
                                    <span id="histCountDisplay" class="text-xs text-slate-500 font-semibold bg-slate-100 px-2.5 py-0.5 rounded-full border border-slate-200/60"><?= count($hist_students) ?> student(s)</span>
                                </div>
                                <div class="flex items-center gap-3 flex-wrap">
                                    <!-- Live Instant Search Bar -->
                                    <div class="relative flex-1 sm:w-64 max-w-xs">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                            </svg>
                                        </span>
                                        <input
                                            type="text"
                                            id="histLiveSearchInput"
                                            oninput="handleHistLiveSearch(this.value)"
                                            placeholder="Search student, roll, company..."
                                            autocomplete="off"
                                            class="w-full bg-slate-50 border border-slate-200/80 rounded-xl pl-9 pr-8 py-2 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 focus:bg-white transition-all duration-200 ease-in-out">
                                        <button
                                            type="button"
                                            id="clearHistSearchBtn"
                                            onclick="clearHistLiveSearch()"
                                            class="hidden absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600 text-xs font-bold transition-all duration-200 cursor-pointer"
                                            title="Clear search">✕</button>
                                    </div>

                                    <!-- Academic Year Dropdown -->
                                    <form method="GET" class="flex items-center gap-2">
                                        <input type="hidden" name="tab" value="history">
                                        <select name="year" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200/80 rounded-xl px-3 py-2 text-xs text-slate-700 font-semibold focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 focus:bg-white transition-all duration-200 ease-in-out cursor-pointer">
                                            <?= render_academic_year_options($db, $selected_year, true, 'All Academic Years') ?>
                                        </select>
                                    </form>
                                </div>
                            </div>

                            <?php if (!empty($hist_students)): ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-500 text-xs font-semibold uppercase tracking-wider">
                                                <th class="px-6 py-4 text-left">Roll No</th>
                                                <th class="px-6 py-4 text-left">Student Name</th>
                                                <th class="px-6 py-4 text-left">Company</th>
                                                <th class="px-6 py-4 text-left">Supervisor</th>
                                                <th class="px-6 py-4 text-left">Academic Year</th>
                                                <th class="px-6 py-4 text-left">Final Grade</th>
                                                <th class="px-6 py-4 text-left">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="histStudentsTableBody" class="divide-y divide-slate-100">
                                            <?php foreach ($hist_students as $hs): ?>
                                                <?php
                                                $h_search_str = strtolower(trim(($hs['full_name'] ?? '') . ' ' . ($hs['username'] ?? '') . ' ' . ($hs['student_roll'] ?? '') . ' ' . ($hs['company_name'] ?? '') . ' ' . ($hs['job_role'] ?? '') . ' ' . ($hs['supervisor_name'] ?? '') . ' ' . ($hs['email'] ?? '')));
                                                ?>
                                                <tr class="history-student-row hover:bg-slate-50/80 transition-all duration-200 ease-in-out" data-search="<?= htmlspecialchars($h_search_str) ?>">
                                                    <td class="px-6 py-4 font-mono font-semibold text-xs text-slate-700"><?= htmlspecialchars($hs['student_roll'] ?: '—') ?></td>
                                                    <td class="px-6 py-4">
                                                        <div class="flex items-center gap-3">
                                                            <div class="w-8 h-8 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center text-xs font-bold shrink-0 shadow-xs">
                                                                <?= strtoupper(($hs['full_name'] ?: $hs['username'])[0]) ?>
                                                            </div>
                                                            <div>
                                                                <p class="font-semibold text-sm text-slate-800 leading-snug"><?= htmlspecialchars($hs['full_name'] ?: $hs['username']) ?></p>
                                                                <p class="text-xs text-slate-400 font-medium"><?= htmlspecialchars($hs['email']) ?></p>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-slate-600 max-w-[170px] truncate" title="<?= htmlspecialchars($hs['company_name'] ?? '') ?>"><?= htmlspecialchars($hs['company_name'] ?: '—') ?></td>
                                                    <td class="px-6 py-4 text-sm text-slate-600"><?= htmlspecialchars($hs['supervisor_name'] ?: 'Unassigned') ?></td>
                                                    <td class="px-6 py-4">
                                                        <?php if ($hs['academic_year']): ?>
                                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-teal-50 text-teal-700 ring-1 ring-teal-600/20 font-mono"><?= htmlspecialchars($hs['academic_year']) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-xs text-slate-400 font-medium">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <?php
                                                        $grade_map = [
                                                            'excellent'         => ['Excellent',         'text-emerald-700 bg-emerald-50 ring-emerald-600/20'],
                                                            'good'              => ['Good',              'text-blue-700 bg-blue-50 ring-blue-600/20'],
                                                            'average'           => ['Average',           'text-amber-700 bg-amber-50 ring-amber-600/20'],
                                                            'needs_improvement' => ['Needs Improvement', 'text-rose-700 bg-rose-50 ring-rose-600/20'],
                                                        ];
                                                        $gv = $hist_grades[$hs['uid']] ?? null;
                                                        $gs = $gv ? ($grade_map[$gv] ?? ['—', 'text-slate-500 bg-slate-50 ring-slate-200']) : ['—', 'text-slate-400 bg-slate-50 ring-slate-200'];
                                                        ?>
                                                        <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full ring-1 <?= $gs[1] ?>"><?= $gs[0] ?></span>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <a href="../view_student_history.php?uid=<?= $hs['uid'] ?>" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-semibold rounded-xl text-teal-700 bg-teal-50 hover:bg-teal-100 hover:text-teal-800 ring-1 ring-teal-600/20 shadow-xs transition-all duration-200 ease-in-out">
                                                            <i class="fa-regular fa-eye text-xs"></i>
                                                            <span>View Reports</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr id="noHistMatchRow" class="hidden">
                                                <td colspan="7" class="px-6 py-8 text-center text-xs text-slate-400 font-medium">No student records found matching your search.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="px-6 py-3.5 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between text-xs text-slate-500 font-medium">
                                    <p id="histFooterCount">Showing <?= count($hist_students) ?> student(s) <?= ($selected_year && $selected_year !== 'all') ? 'for ' . htmlspecialchars($selected_year) : 'across all years' ?></p>
                                    <span class="text-slate-400">Internship Evaluation Records</span>
                                </div>
                            <?php else: ?>
                                <div class="p-12 text-center text-sm text-slate-400">
                                    <?php if ($selected_year && $selected_year !== 'all'): ?>
                                        No students found for academic year <?= htmlspecialchars($selected_year) ?>.
                                    <?php else: ?>
                                        No students registered yet.
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>


                </div>
            </main>
        </div>
    </div>

    <script>
        function filterByAcademicYear(year) {
            const url = new URL(window.location.href);
            if (!year || year === 'all') {
                url.searchParams.delete('year');
            } else {
                url.searchParams.set('year', year);
            }
            // Ensure tab is set
            if (!url.searchParams.get('tab')) {
                url.searchParams.set('tab', 'manage');
            }
            window.location.href = url.toString();
        }

        /**
         * Instant live search filtering for ALL USERS table
         */
        function handleUserLiveSearch(query) {
            query = (query || '').toLowerCase().trim();
            const clearBtn = document.getElementById('clearSearchBtn');
            if (clearBtn) {
                if (query.length > 0) {
                    clearBtn.classList.remove('hidden');
                } else {
                    clearBtn.classList.add('hidden');
                }
            }

            const rows = document.querySelectorAll('.user-row');
            let visibleCount = 0;

            rows.forEach(function(row) {
                const searchData = row.getAttribute('data-search') || row.innerText.toLowerCase();
                if (!query || searchData.includes(query)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const noMatch = document.getElementById('noUsersMatchRow');
            if (noMatch) {
                if (visibleCount === 0 && rows.length > 0) {
                    noMatch.classList.remove('hidden');
                } else {
                    noMatch.classList.add('hidden');
                }
            }

            const countDisplay = document.getElementById('userCountDisplay');
            if (countDisplay) {
                countDisplay.textContent = visibleCount + ' total';
            }
        }

        /**
         * Clear live search input and restore all table rows
         */
        function clearUserLiveSearch() {
            const input = document.getElementById('userLiveSearchInput');
            if (input) {
                input.value = '';
                input.focus();
                handleUserLiveSearch('');
            }
        }

        /**
         * Instant live search filtering for Student History table
         */
        function handleHistLiveSearch(query) {
            query = (query || '').toLowerCase().trim();
            const clearBtn = document.getElementById('clearHistSearchBtn');
            if (clearBtn) {
                if (query.length > 0) {
                    clearBtn.classList.remove('hidden');
                } else {
                    clearBtn.classList.add('hidden');
                }
            }

            const rows = document.querySelectorAll('.history-student-row');
            let visibleCount = 0;

            rows.forEach(function(row) {
                const searchData = row.getAttribute('data-search') || row.innerText.toLowerCase();
                if (!query || searchData.includes(query)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const noMatch = document.getElementById('noHistMatchRow');
            if (noMatch) {
                if (visibleCount === 0 && rows.length > 0) {
                    noMatch.classList.remove('hidden');
                } else {
                    noMatch.classList.add('hidden');
                }
            }

            const countDisplay = document.getElementById('histCountDisplay');
            if (countDisplay) {
                countDisplay.textContent = visibleCount + ' student(s)';
            }

            const footerCount = document.getElementById('histFooterCount');
            if (footerCount) {
                footerCount.textContent = 'Showing ' + visibleCount + ' student(s)';
            }
        }

        /**
         * Clear student history live search input and restore all table rows
         */
        function clearHistLiveSearch() {
            const input = document.getElementById('histLiveSearchInput');
            if (input) {
                input.value = '';
                input.focus();
                handleHistLiveSearch('');
            }
        }

        /**
         * Master client-side filter for All Registered Students table (tab=students)
         * Synchronizes real-time text search and Academic Year dropdown with ZERO page reload.
         */
        function applyStudentTableFilter() {
            const searchInput = document.getElementById('studentLiveSearchInput');
            const yearSelect = document.getElementById('filter_student_academic_year');
            const clearBtn = document.getElementById('clearStudentSearchBtn');

            const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
            const selectedYear = (yearSelect ? yearSelect.value : 'all').trim();

            // Toggle clear search button
            if (clearBtn) {
                if (query.length > 0) {
                    clearBtn.classList.remove('hidden');
                } else {
                    clearBtn.classList.add('hidden');
                }
            }

            const rows = document.querySelectorAll('.student-row');
            let visibleCount = 0;
            const visibleBatchYears = new Set();

            rows.forEach(function(row) {
                const searchData = row.getAttribute('data-search') || row.innerText.toLowerCase();
                const rowAy = (row.getAttribute('data-ay') || '').trim();

                const matchesYear = (selectedYear === 'all' || selectedYear === '' || rowAy === selectedYear);
                const matchesQuery = (!query || searchData.includes(query));

                if (matchesYear && matchesQuery) {
                    row.style.display = '';
                    visibleCount++;
                    if (rowAy) visibleBatchYears.add(rowAy);
                } else {
                    row.style.display = 'none';
                }
            });

            // Synchronize batch header divider rows
            const batchHeaders = document.querySelectorAll('.academic-year-header-row');
            batchHeaders.forEach(function(headerRow) {
                const groupAy = (headerRow.getAttribute('data-group-ay') || '').trim();
                const yearActive = (selectedYear === 'all' || selectedYear === '' || groupAy === selectedYear);
                if (yearActive && visibleBatchYears.has(groupAy)) {
                    headerRow.style.display = '';
                } else {
                    headerRow.style.display = 'none';
                }
            });

            // Toggle empty state message
            const noMatch = document.getElementById('noStudentsMatchRow');
            if (noMatch) {
                if (visibleCount === 0 && rows.length > 0) {
                    noMatch.classList.remove('hidden');
                } else {
                    noMatch.classList.add('hidden');
                }
            }

            // Update counter badges
            const countDisplay = document.getElementById('stuCountDisplay');
            if (countDisplay) {
                countDisplay.textContent = visibleCount + ' total';
            }

            const footerCount = document.getElementById('stuFooterCountDisplay');
            if (footerCount) {
                const yearSuffix = (selectedYear && selectedYear !== 'all') ? ' for ' + selectedYear : ' across all years';
                footerCount.textContent = 'Showing ' + visibleCount + ' student(s)' + yearSuffix + '.';
            }
        }

        /**
         * Instant live search filtering for All Registered Students table (tab=students)
         */
        function handleStudentLiveSearch(query) {
            applyStudentTableFilter();
        }

        /**
         * Clear student live search input and restore table rows
         */
        function clearStudentLiveSearch() {
            const input = document.getElementById('studentLiveSearchInput');
            if (input) {
                input.value = '';
                input.focus();
            }
            applyStudentTableFilter();
        }

        /**
         * Filter students by academic year dropdown with zero reload
         */
        function filterStudentByAcademicYear(year) {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', 'students');
                if (year === 'all' || !year) {
                    url.searchParams.delete('academic_year_filter');
                    url.searchParams.delete('student_year');
                } else {
                    url.searchParams.set('academic_year_filter', year);
                }
                window.history.replaceState({}, '', url.toString());
            } catch (e) {}

            applyStudentTableFilter();
        }

        /**
         * Instant live search filtering for All Registered Supervisors table (tab=supervisors)
         */
        function handleSupervisorLiveSearch(query) {
            query = (query || '').toLowerCase().trim();
            const clearBtn = document.getElementById('clearSupSearchBtn');
            if (clearBtn) {
                if (query.length > 0) {
                    clearBtn.classList.remove('hidden');
                } else {
                    clearBtn.classList.add('hidden');
                }
            }

            const rows = document.querySelectorAll('.supervisor-row');
            let visibleCount = 0;
            const totalCount = <?= count($supervisors) ?>;

            rows.forEach(function(row) {
                const searchData = (row.getAttribute('data-search') || '').toLowerCase();
                if (!query || searchData.includes(query)) {
                    row.style.display = '';
                    visibleCount++;
                    const numCell = row.querySelector('.sup-row-num');
                    if (numCell) {
                        numCell.textContent = visibleCount;
                    }
                } else {
                    row.style.display = 'none';
                }
            });

            const noMatch = document.getElementById('noSupervisorsMatchRow');
            const queryDisplay = document.getElementById('supSearchQueryDisplay');
            if (noMatch) {
                if (visibleCount === 0 && rows.length > 0) {
                    noMatch.classList.remove('hidden');
                    if (queryDisplay) queryDisplay.textContent = query;
                } else {
                    noMatch.classList.add('hidden');
                }
            }

            const badge = document.getElementById('supCountBadge');
            const footerCount = document.getElementById('supFooterCount');

            if (query) {
                if (badge) badge.textContent = `${visibleCount} of ${totalCount} found`;
                if (footerCount) footerCount.textContent = `Showing ${visibleCount} of ${totalCount} supervisor(s).`;
            } else {
                if (badge) badge.textContent = `${totalCount} total`;
                if (footerCount) footerCount.textContent = `Showing ${totalCount} supervisor(s).`;
            }
        }

        /**
         * Clear supervisor live search input and restore table rows
         */
        function clearSupervisorLiveSearch() {
            const input = document.getElementById('supervisorLiveSearchInput');
            if (input) {
                input.value = '';
                input.focus();
                handleSupervisorLiveSearch('');
            }
        }

        /**
         * Open User Details Modal
         */
        function openUserDetailsModal(button) {
            try {
                const raw = button.getAttribute('data-user');
                if (!raw) return;
                const u = JSON.parse(raw);

                const modal = document.getElementById('userDetailsModal');
                const avatar = document.getElementById('modalUserAvatar');
                const nameEl = document.getElementById('modalUserName');
                const subtitleEl = document.getElementById('modalUserSubtitle');
                const roleBadge = document.getElementById('modalRoleBadge');
                const statusBadge = document.getElementById('modalStatusBadge');

                const studentView = document.getElementById('studentDetailsView');
                const staffView = document.getElementById('staffDetailsView');
                const studentActions = document.getElementById('modalStudentActions');
                const historyBtn = document.getElementById('modalHistoryBtn');

                if (!modal) return;

                const displayName = u.full_name || u.username || 'User';
                const initial = (displayName.charAt(0) || 'U').toUpperCase();
                nameEl.textContent = displayName;
                subtitleEl.textContent = u.email || '—';

                // Avatar and Role Badge styling
                if (u.role === 'admin') {
                    avatar.className = 'w-12 h-12 rounded-2xl flex items-center justify-center text-lg font-black text-white shrink-0 shadow-sm bg-gradient-to-tr from-amber-500 to-amber-600';
                    avatar.textContent = initial;
                    roleBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full uppercase tracking-wider bg-amber-100 text-amber-800 border border-amber-200';
                    roleBadge.textContent = 'Admin';
                } else if (u.role === 'supervisor') {
                    avatar.className = 'w-12 h-12 rounded-2xl flex items-center justify-center text-lg font-black text-white shrink-0 shadow-sm bg-gradient-to-tr from-emerald-500 to-teal-600';
                    avatar.textContent = initial;
                    roleBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full uppercase tracking-wider bg-emerald-100 text-emerald-800 border border-emerald-200';
                    roleBadge.textContent = 'Supervisor';
                } else {
                    avatar.className = 'w-12 h-12 rounded-2xl flex items-center justify-center text-lg font-black text-white shrink-0 shadow-sm bg-gradient-to-tr from-indigo-500 to-purple-600';
                    avatar.textContent = initial;
                    roleBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full uppercase tracking-wider bg-indigo-100 text-indigo-800 border border-indigo-200';
                    roleBadge.textContent = 'Student';
                }

                // Status badge
                if (u.status === 'Archived') {
                    statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200';
                    statusBadge.textContent = '📦 Archived';
                } else if (u.status === 'Pending') {
                    statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200';
                    statusBadge.textContent = '⏳ Pending First Login';
                } else {
                    statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200';
                    statusBadge.textContent = '✅ Active';
                }

                if (u.role === 'student') {
                    studentView.classList.remove('hidden');
                    staffView.classList.add('hidden');
                    if (studentActions) studentActions.classList.remove('hidden');
                    if (historyBtn) historyBtn.href = '../view_student_history.php?uid=' + encodeURIComponent(u.id);

                    // 1. Academic & Personal Info
                    document.getElementById('detailStuName').textContent = displayName || '—';
                    document.getElementById('detailStuRoll').textContent = u.student_roll || '—';
                    document.getElementById('detailStuMajor').textContent = u.major || '—';

                    const emailLink = document.getElementById('detailStuEmail');
                    if (u.email) {
                        emailLink.textContent = u.email;
                        emailLink.href = 'mailto:' + u.email;
                        emailLink.className = 'font-medium text-indigo-600 hover:underline block truncate';
                    } else {
                        emailLink.textContent = '—';
                        emailLink.removeAttribute('href');
                        emailLink.className = 'text-slate-400 font-medium block truncate';
                    }

                    const phoneEl = document.getElementById('detailStuPhone');
                    const stuPhone = (u.phone || u.student_phone || u.user_phone || '').trim();
                    phoneEl.textContent = stuPhone || '—';

                    document.getElementById('detailStuYear').textContent = u.academic_year || '—';

                    // 2. Internship Placement
                    document.getElementById('detailStuCompany').textContent = u.company_name || '—';
                    document.getElementById('detailStuJobRole').textContent = u.job_role || '—';
                    document.getElementById('detailStuStartDate').textContent = u.internship_start_date || '—';
                    document.getElementById('detailStuEndDate').textContent = u.internship_end_date || '—';

                    // 3. Supervision
                    const supName = (u.supervisor_name || '').trim();
                    const supEmail = (u.supervisor_email || '').trim();
                    document.getElementById('detailStuSupervisor').textContent = supName || '—';
                    const supEmailEl = document.getElementById('detailStuSupervisorEmail');
                    if (supEmailEl) {
                        if (supEmail) {
                            supEmailEl.textContent = '(' + supEmail + ')';
                            supEmailEl.href = 'mailto:' + supEmail;
                            supEmailEl.classList.remove('hidden');
                        } else {
                            supEmailEl.classList.add('hidden');
                        }
                    }

                    const instName = (u.instructor_name || '').trim();
                    const instEmail = (u.instructor_email || '').trim();
                    const instPhone = (u.instructor_phone || '').trim();

                    document.getElementById('detailStuInstructor').textContent = instName || '—';

                    const instEmailLink = document.getElementById('detailStuInstEmail');
                    if (instEmail) {
                        instEmailLink.textContent = instEmail;
                        instEmailLink.href = 'mailto:' + instEmail;
                        instEmailLink.className = 'font-medium text-teal-600 hover:underline block truncate';
                    } else {
                        instEmailLink.textContent = '—';
                        instEmailLink.removeAttribute('href');
                        instEmailLink.className = 'text-slate-400 font-medium block truncate';
                    }

                    const instPhoneEl = document.getElementById('detailStuInstPhone');
                    instPhoneEl.textContent = instPhone || '—';

                    // 4. Account Information
                    const usernameEl = document.getElementById('detailStuUsername');
                    if (usernameEl) usernameEl.textContent = u.username || '—';

                    const statusEl = document.getElementById('detailStuAccountStatus');
                    if (statusEl) {
                        if (u.status === 'Archived') {
                            statusEl.innerHTML = '<span class="inline-flex items-center gap-1 text-slate-600 font-bold">📦 Archived</span>';
                        } else if (u.is_first_login || u.status === 'Pending') {
                            statusEl.innerHTML = '<span class="inline-flex items-center gap-1 text-amber-700 font-bold">⏳ Pending First Login</span>';
                        } else {
                            statusEl.innerHTML = '<span class="inline-flex items-center gap-1 text-emerald-700 font-bold">✅ Active</span>';
                        }
                    }

                    const createdEl = document.getElementById('detailStuCreatedAt');
                    if (createdEl) createdEl.textContent = u.created_at || '—';

                    const lastLoginEl = document.getElementById('detailStuLastLogin');
                    if (lastLoginEl) lastLoginEl.textContent = u.last_login_at || 'Never';

                } else {
                    studentView.classList.add('hidden');
                    staffView.classList.remove('hidden');
                    if (studentActions) studentActions.classList.add('hidden');

                    // Fill staff / admin fields
                    document.getElementById('detailStaffName').textContent = displayName;

                    const staffEmailLink = document.getElementById('detailStaffEmail');
                    staffEmailLink.textContent = u.email || '—';
                    staffEmailLink.href = u.email ? 'mailto:' + u.email : '#';

                    document.getElementById('detailStaffPhone').textContent = u.phone || '—';
                    document.getElementById('detailStaffDept').textContent = u.department || '—';
                    document.getElementById('detailStaffPos').textContent = u.position || (u.role === 'admin' ? 'Administrator' : 'Supervisor');
                    document.getElementById('detailStaffYear').textContent = u.academic_year || 'All Batches';
                    document.getElementById('detailStaffDate').textContent = u.created_at || '—';
                }

                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            } catch (err) {
                console.error('Error opening user details modal:', err);
            }
        }

        /**
         * Close User Details Modal
         */
        function closeUserDetailsModal() {
            const modal = document.getElementById('userDetailsModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('userDetailsModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeUserDetailsModal();
                    }
                });
            }
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeUserDetailsModal();
                }
            });
        });
    </script>

    <!-- ════ USER DETAILS MODAL ════ -->
    <div id="userDetailsModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 sm:p-6 overflow-y-auto hidden" role="dialog" aria-modal="true" aria-labelledby="modalUserName">
        <div class="relative w-full max-w-2xl bg-white rounded-2xl border border-slate-200 shadow-2xl overflow-hidden flex flex-col max-h-[90vh] my-auto">

            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 via-white to-slate-50 flex items-center justify-between">
                <div class="flex items-center gap-3.5 min-w-0">
                    <div id="modalUserAvatar" class="w-12 h-12 rounded-2xl flex items-center justify-center text-lg font-black text-white shrink-0 shadow-sm">
                        U
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 id="modalUserName" class="text-base sm:text-lg font-black text-slate-800 truncate">User Name</h3>
                            <span id="modalRoleBadge" class="text-xs font-bold px-2.5 py-0.5 rounded-full uppercase tracking-wider">Student</span>
                            <span id="modalStatusBadge" class="text-xs font-bold px-2.5 py-0.5 rounded-full">Active</span>
                        </div>
                        <p id="modalUserSubtitle" class="text-xs text-slate-500 font-medium truncate mt-0.5">student@example.com</p>
                    </div>
                </div>
                <button type="button" onclick="closeUserDetailsModal()" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition cursor-pointer shrink-0 ml-2" title="Close modal" aria-label="Close modal">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <!-- Modal Body (Scrollable) -->
            <div class="p-6 overflow-y-auto space-y-5 flex-1" style="scrollbar-gutter: stable;">

                <!-- STUDENT DETAILS VIEW -->
                <div id="studentDetailsView" class="space-y-4">

                    <!-- Section 1: Academic & Personal Info -->
                    <div class="bg-slate-50/70 border border-slate-200/80 rounded-2xl p-4">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                            <i class="fa-solid fa-graduation-cap text-indigo-500"></i> Academic & Personal Info
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-xs">
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Full Name</span>
                                <span id="detailStuName" class="font-bold text-slate-800 text-sm block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Roll Number</span>
                                <span id="detailStuRoll" class="font-mono font-bold text-indigo-700 text-sm block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Major / Department</span>
                                <span id="detailStuMajor" class="font-bold text-slate-800 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Email Address</span>
                                <a id="detailStuEmail" href="#" class="font-medium text-indigo-600 hover:underline block truncate">—</a>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Phone Number</span>
                                <span id="detailStuPhone" class="font-mono font-semibold text-slate-800 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Academic Year</span>
                                <span id="detailStuYear" class="font-mono font-bold text-indigo-600 block truncate">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Internship Placement -->
                    <div class="bg-slate-50/70 border border-slate-200/80 rounded-2xl p-4">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                            <i class="fa-solid fa-briefcase text-teal-600"></i> Internship Placement
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Partner Company</span>
                                <span id="detailStuCompany" class="font-bold text-slate-800 text-sm block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Job Role</span>
                                <span id="detailStuJobRole" class="font-semibold text-slate-800 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Internship Start Date</span>
                                <span id="detailStuStartDate" class="font-medium text-slate-700 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Internship End Date</span>
                                <span id="detailStuEndDate" class="font-medium text-slate-700 block truncate">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Supervision -->
                    <div class="bg-slate-50/70 border border-slate-200/80 rounded-2xl p-4">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                            <i class="fa-solid fa-chalkboard-user text-emerald-600"></i> Supervision
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs sm:col-span-2">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">University Supervisor (ကျောင်းကဆရာ/မ)</span>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span id="detailStuSupervisor" class="font-bold text-slate-800 text-sm block truncate">—</span>
                                    <a id="detailStuSupervisorEmail" href="#" class="font-medium text-indigo-600 hover:underline text-xs hidden">—</a>
                                </div>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs sm:col-span-2">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Company Instructor</span>
                                <span id="detailStuInstructor" class="font-bold text-slate-800 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Instructor Email</span>
                                <a id="detailStuInstEmail" href="#" class="font-medium text-teal-600 hover:underline block truncate">—</a>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Instructor Phone</span>
                                <span id="detailStuInstPhone" class="font-mono font-semibold text-slate-800 block truncate">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Section 4: Account Information -->
                    <div class="bg-slate-50/70 border border-slate-200/80 rounded-2xl p-4">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                            <i class="fa-solid fa-id-card text-slate-500"></i> Account Information
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Username</span>
                                <span id="detailStuUsername" class="font-mono font-semibold text-slate-800 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Account Status</span>
                                <span id="detailStuAccountStatus" class="font-semibold text-slate-800 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Registration Date</span>
                                <span id="detailStuCreatedAt" class="font-medium text-slate-700 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Last Login</span>
                                <span id="detailStuLastLogin" class="font-medium text-slate-700 block truncate">—</span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- SUPERVISOR / INSTRUCTOR / ADMIN DETAILS VIEW -->
                <div id="staffDetailsView" class="space-y-4 hidden">
                    <div class="bg-slate-50/70 border border-slate-200/80 rounded-2xl p-4">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                            <i class="fa-solid fa-id-badge text-emerald-600"></i> Account & Role Information
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Name / Username</span>
                                <span id="detailStaffName" class="font-bold text-slate-800 text-sm block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Email Address</span>
                                <a id="detailStaffEmail" href="#" class="font-medium text-emerald-600 hover:underline block truncate">—</a>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Phone Number</span>
                                <span id="detailStaffPhone" class="font-mono font-semibold text-slate-800 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Department</span>
                                <span id="detailStaffDept" class="font-bold text-slate-800 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Position / Designation</span>
                                <span id="detailStaffPos" class="font-semibold text-slate-800 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Academic Session</span>
                                <span id="detailStaffYear" class="font-mono font-bold text-slate-700 block truncate">—</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-100 shadow-2xs sm:col-span-2">
                                <span class="text-slate-400 font-medium block text-[11px] mb-0.5">Registration Date</span>
                                <span id="detailStaffDate" class="font-medium text-slate-600 block truncate">—</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-3.5 bg-slate-50 border-t border-slate-100 flex items-center justify-between gap-3">
                <div id="modalStudentActions">
                    <a id="modalHistoryBtn" href="#" class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200/60 rounded-xl transition">
                        <i class="fa-regular fa-file-lines text-xs"></i> View Student History & Reports
                    </a>
                </div>
                <div class="ml-auto">
                    <button type="button" onclick="closeUserDetailsModal()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">
                        Close
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- ════ SUPERVISOR HISTORY MODAL ════ -->
    <div id="supHistoryModal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeSupHistoryModal()"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl z-10 overflow-hidden flex flex-col max-h-[92vh]">
            <!-- Header -->
            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-emerald-50 via-white to-teal-50 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3.5 min-w-0">
                    <div class="w-11 h-11 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center text-lg font-black shrink-0 shadow-xs" id="supHistoryAvatar">S</div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 id="supHistoryTitle" class="text-base font-black text-slate-800 truncate">Supervisor History</h3>
                            <span id="supHistoryStatusBadge" class="text-xs font-bold px-2.5 py-0.5 rounded-full inline-flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                            </span>
                        </div>
                        <p id="supHistoryEmail" class="text-xs text-slate-500 font-medium truncate mt-0.5">—</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0 ml-3">
                    <button type="button" id="supHistoryToggleStatusBtn" onclick="toggleSupervisorStatusFromModal()" class="px-3 py-1.5 text-xs font-bold rounded-xl border transition cursor-pointer flex items-center gap-1 shadow-2xs">
                        🔴 Deactivate
                    </button>
                    <button type="button" onclick="closeSupHistoryModal()" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition cursor-pointer shrink-0" aria-label="Close modal">✕</button>
                </div>
            </div>

            <!-- Body (scrollable) -->
            <div class="p-6 overflow-y-auto flex-1 space-y-5" style="scrollbar-gutter: stable;">

                <!-- Assignment & Performance Summary Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="bg-gradient-to-br from-emerald-50/80 to-teal-50/80 border border-emerald-200/70 rounded-2xl p-4 flex items-center gap-3.5 shadow-2xs">
                        <div class="w-11 h-11 rounded-xl bg-emerald-100/90 text-emerald-700 flex items-center justify-center text-lg shrink-0">📅</div>
                        <div>
                            <p class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider">Years Assigned</p>
                            <p id="supHistoryYearCount" class="text-xl font-black text-emerald-900 mt-0.5">0</p>
                        </div>
                    </div>
                    <div class="bg-gradient-to-br from-indigo-50/80 to-blue-50/80 border border-indigo-200/70 rounded-2xl p-4 flex items-center gap-3.5 shadow-2xs">
                        <div class="w-11 h-11 rounded-xl bg-indigo-100/90 text-indigo-700 flex items-center justify-center text-lg shrink-0">🎓</div>
                        <div>
                            <p class="text-[11px] font-bold text-indigo-700 uppercase tracking-wider">Students Supervised</p>
                            <p id="supHistoryTotalStudents" class="text-xl font-black text-indigo-900 mt-0.5">0</p>
                        </div>
                    </div>
                </div>

                <!-- Assign Year Form -->
                <div class="bg-slate-50/70 border border-slate-200/80 rounded-2xl p-4">
                    <h4 class="text-xs font-black text-slate-600 uppercase tracking-wider mb-2.5 flex items-center gap-1.5">
                        <span class="p-1 bg-emerald-100 text-emerald-700 rounded text-[11px]">➕</span> Assign to Academic Year
                    </h4>
                    <form id="assignYearForm" class="flex flex-col sm:flex-row items-stretch sm:items-end gap-3" onsubmit="return assignSupervisorYear(event)">
                        <input type="hidden" id="assignSupId" value="">
                        <div class="flex-1">
                            <label class="block text-xs font-bold text-slate-500 mb-1">Academic Year Session</label>
                            <select id="assignYearSelect" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-400 transition" required>
                                <option value="">— Select Academic Year —</option>
                                <?php foreach ($all_ay_records as $ayr): ?>
                                    <option value="<?= (int)$ayr['id'] ?>"><?= htmlspecialchars($ayr['year_label']) ?><?= $ayr['status'] === 'Archived' ? ' (Archived)' : ($ayr['is_current'] ? ' (Current Active)' : '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer shrink-0">
                            ➕ Assign Year
                        </button>
                    </form>
                    <div id="assignYearError" class="hidden bg-red-50 border border-red-200 text-red-600 text-xs font-semibold px-4 py-2.5 rounded-xl mt-3"></div>
                </div>

                <!-- Year-by-Year History Section -->
                <div>
                    <h4 class="text-xs font-black text-slate-600 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                        <i class="fa-solid fa-clock-rotate-left text-indigo-500"></i> Year-by-Year Academic History & Supervised Students
                    </h4>
                    <div id="supHistoryList" class="space-y-4">
                        <!-- Populated dynamically by JS -->
                    </div>
                    <div id="supHistoryEmpty" class="hidden p-8 text-center bg-slate-50/50 rounded-2xl border border-dashed border-slate-200">
                        <div class="w-14 h-14 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-2xl mx-auto mb-3">📭</div>
                        <p class="text-sm font-semibold text-slate-600">No academic year history found</p>
                        <p class="text-xs text-slate-400 mt-1">This supervisor has not been assigned to any academic years and has no supervised student records yet.</p>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-6 py-3.5 border-t border-slate-100 bg-slate-50 flex items-center justify-between shrink-0">
                <p class="text-xs text-slate-400 font-medium">Historical records and student evaluations are permanently preserved.</p>
                <button type="button" onclick="closeSupHistoryModal()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">Close</button>
            </div>
        </div>
    </div>

    <script>
        // ═══ ENHANCED SUPERVISOR HISTORY MODAL ═════════════════════════════
        var _allAyRecords = <?= json_encode($all_ay_records, JSON_UNESCAPED_UNICODE) ?>;
        var _currentModalSupId = 0;
        var _currentModalSupStatus = 'Active';

        function openSupervisorHistoryModal(supId, supName, supEmail) {
            var modal = document.getElementById('supHistoryModal');
            if (!modal) return;
            _currentModalSupId = supId;
            document.getElementById('assignSupId').value = supId;
            document.getElementById('supHistoryTitle').textContent = supName || 'Supervisor History';
            document.getElementById('supHistoryEmail').textContent = supEmail || '—';
            document.getElementById('supHistoryAvatar').textContent = ((supName || 'S').charAt(0)).toUpperCase();
            document.getElementById('assignYearError').classList.add('hidden');

            // Fetch rich history via AJAX
            var fd = new FormData();
            fd.append('action', 'get_history');
            fd.append('supervisor_id', supId);

            fetch('api/assign_supervisor.php', {
                    method: 'POST',
                    body: fd
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        var sup = data.supervisor || {};
                        _currentModalSupStatus = sup.status || 'Active';
                        updateSupervisorModalStatusUI(_currentModalSupStatus);

                        if (sup.username) {
                            document.getElementById('supHistoryTitle').textContent = sup.username;
                        }
                        if (sup.email) {
                            var subtitle = sup.email;
                            if (sup.department) subtitle += ' · ' + sup.department;
                            if (sup.position) subtitle += ' (' + sup.position + ')';
                            document.getElementById('supHistoryEmail').textContent = subtitle;
                        }

                        renderSupHistory(data.assignments || [], data.total_students || 0, data.total_assigned_years || 0, data.total_evaluations || 0);
                    } else {
                        renderSupHistory([], 0, 0, 0);
                    }
                })
                .catch(function() {
                    renderSupHistory([], 0, 0, 0);
                });

            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function updateSupervisorModalStatusUI(status) {
            var badge = document.getElementById('supHistoryStatusBadge');
            var btn = document.getElementById('supHistoryToggleStatusBtn');
            var isInactive = (status.toLowerCase() === 'inactive');

            if (isInactive) {
                badge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full inline-flex items-center gap-1 bg-red-50 text-red-700 border border-red-200/80';
                badge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Inactive (Login Blocked)';
                btn.className = 'px-3 py-1.5 text-xs font-bold rounded-xl border bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border-emerald-200/80 transition cursor-pointer flex items-center gap-1 shadow-2xs';
                btn.innerHTML = '🟢 Activate Account';
                btn.title = 'Allow this supervisor to log in';
            } else {
                badge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200/80';
                badge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active (Can Login)';
                btn.className = 'px-3 py-1.5 text-xs font-bold rounded-xl border bg-red-50 hover:bg-red-100 text-red-700 border-red-200/80 transition cursor-pointer flex items-center gap-1 shadow-2xs';
                btn.innerHTML = '🔴 Deactivate Account';
                btn.title = 'Block this supervisor from logging in';
            }
        }

        function toggleSupervisorStatusFromModal() {
            if (!_currentModalSupId) return;
            var willDeactivate = (_currentModalSupStatus.toLowerCase() === 'active');
            var confirmMsg = willDeactivate ?
                'Deactivate this supervisor account?\nThey will NOT be able to log in. All historical student records and evaluations will be preserved.' :
                'Activate this supervisor account?\nThey will be able to log in normally.';
            if (!confirm(confirmMsg)) return;

            var targetStatus = willDeactivate ? 'Inactive' : 'Active';
            var fd = new FormData();
            fd.append('action', 'toggle_status');
            fd.append('supervisor_id', _currentModalSupId);
            fd.append('status', targetStatus);

            fetch('api/assign_supervisor.php', {
                    method: 'POST',
                    body: fd
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        _currentModalSupStatus = data.status || targetStatus;
                        updateSupervisorModalStatusUI(_currentModalSupStatus);
                        showToast('success', data.message || 'Status updated successfully.');
                    } else {
                        showToast('error', data.error || 'Failed to update status.');
                    }
                })
                .catch(function() {
                    showToast('error', 'Network error updating status.');
                });
        }

        function closeSupHistoryModal() {
            var modal = document.getElementById('supHistoryModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        }

        function renderSupHistory(assignments, totalStudents, totalYears, totalEvaluations) {
            var listEl = document.getElementById('supHistoryList');
            var emptyEl = document.getElementById('supHistoryEmpty');
            var yearCountEl = document.getElementById('supHistoryYearCount');
            var totalStuEl = document.getElementById('supHistoryTotalStudents');
            var totalEvalEl = document.getElementById('supHistoryTotalEvaluations');

            if (yearCountEl) yearCountEl.textContent = totalYears || (assignments ? assignments.length : 0);
            if (totalStuEl) totalStuEl.textContent = totalStudents || 0;
            if (totalEvalEl) totalEvalEl.textContent = totalEvaluations || 0;

            if (!assignments || assignments.length === 0) {
                listEl.innerHTML = '';
                emptyEl.classList.remove('hidden');
                return;
            }
            emptyEl.classList.add('hidden');

            var html = '';
            assignments.forEach(function(a) {
                var isArchived = (a.year_status === 'Archived');
                var statusColor = 'bg-slate-100 text-slate-600 border-slate-200';
                var statusIcon = '📦';
                if (a.year_status === 'Active') {
                    statusColor = 'bg-emerald-100 text-emerald-800 border-emerald-200';
                    statusIcon = '🟢';
                } else if (a.year_status === 'Upcoming') {
                    statusColor = 'bg-blue-100 text-blue-800 border-blue-200';
                    statusIcon = '🔵';
                }

                var assignBadge = a.is_assigned ?
                    '<span class="inline-flex items-center gap-1 text-[11px] font-bold text-teal-700 bg-teal-50 border border-teal-200/80 px-2 py-0.5 rounded-full"><i class="fa-solid fa-check text-[10px]"></i> Assigned</span>' :
                    '<span class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-500 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-full">Historical (Unassigned)</span>';

                html += '<div class="bg-white border border-slate-200/90 rounded-2xl overflow-hidden shadow-2xs transition hover:border-slate-300">';

                // Year Card Header
                html += '  <div class="px-4 py-3.5 bg-gradient-to-r from-slate-50 to-white border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">';
                html += '    <div class="flex items-center gap-2.5">';
                html += '      <div class="w-8 h-8 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center text-sm font-bold shrink-0">' + statusIcon + '</div>';
                html += '      <div>';
                html += '        <div class="flex items-center gap-2">';
                html += '          <span class="font-mono font-black text-sm text-slate-800">' + escHtml(a.year_label || '—') + '</span>';
                html += '          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border ' + statusColor + '">' + escHtml(a.year_status || '') + '</span>';
                html += '          ' + assignBadge;
                html += '        </div>';
                if (a.assigned_at_display) {
                    html += '        <p class="text-[11px] text-slate-400 font-medium mt-0.5">Assigned session: ' + escHtml(a.assigned_at_display) + '</p>';
                }
                html += '      </div>';
                html += '    </div>';

                html += '    <div class="flex items-center gap-2 shrink-0">';
                html += '      <span class="text-xs font-bold text-indigo-700 bg-indigo-50 border border-indigo-200/60 px-2.5 py-1 rounded-xl">🎓 ' + (a.student_count || 0) + ' student' + (a.student_count === 1 ? '' : 's') + '</span>';
                html += '      <span class="text-xs font-bold text-purple-700 bg-purple-50 border border-purple-200/60 px-2.5 py-1 rounded-xl">📝 ' + (a.evaluation_count || 0) + ' graded</span>';
                if (a.is_assigned) {
                    html += '      <button onclick="removeSupAssignment(' + _currentModalSupId + ', ' + (a.academic_year_id || 0) + ', \'' + escAttr(a.year_label || '') + '\')" ';
                    html += '        class="px-2.5 py-1 bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold rounded-xl border border-red-200/60 transition cursor-pointer shrink-0" title="Remove assignment from this year">✕ Unassign</button>';
                }
                html += '    </div>';
                html += '  </div>';

                // Students Table
                var students = a.students || [];
                if (students.length > 0) {
                    html += '  <div class="overflow-x-auto">';
                    html += '    <table class="w-full text-xs text-left">';
                    html += '      <thead>';
                    html += '        <tr class="bg-slate-50/70 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-100 text-[10px]">';
                    html += '          <th class="px-4 py-2.5">Roll No</th>';
                    html += '          <th class="px-4 py-2.5">Student Name & Major</th>';
                    html += '          <th class="px-4 py-2.5">Partner Company</th>';
                    html += '          <th class="px-4 py-2.5">Internship Duration</th>';
                    html += '          <th class="px-4 py-2.5 text-center">Student Status</th>';
                    html += '          <th class="px-4 py-2.5 text-right">Evaluations / Grade</th>';
                    html += '        </tr>';
                    html += '      </thead>';
                    html += '      <tbody class="divide-y divide-slate-100">';
                    students.forEach(function(st) {
                        var isStuActive = (st.student_status === 'Active');
                        var stuStatusPill = isStuActive ?
                            '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active</span>' :
                            '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-slate-600 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-full">📦 Archived</span>';

                        var gradeText = st.latest_weekly_grade ?
                            '<span class="inline-block px-1.5 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 font-bold text-[10px]">Grade: ' + escHtml(st.latest_weekly_grade) + '</span>' :
                            '';

                        html += '        <tr class="hover:bg-slate-50/50 transition">';
                        html += '          <td class="px-4 py-2.5 font-mono font-bold text-indigo-700">' + escHtml(st.student_roll || st.username || '—') + '</td>';
                        html += '          <td class="px-4 py-2.5">';
                        html += '            <span class="font-bold text-slate-800 block">' + escHtml(st.full_name || st.username) + '</span>';
                        if (st.major) {
                            html += '            <span class="text-[10px] text-slate-400">' + escHtml(st.major) + '</span>';
                        }
                        html += '          </td>';
                        html += '          <td class="px-4 py-2.5">';
                        html += '            <span class="font-semibold text-slate-700 block">' + escHtml(st.company_name || '—') + '</span>';
                        if (st.job_role) {
                            html += '            <span class="text-[10px] text-slate-400">' + escHtml(st.job_role) + '</span>';
                        }
                        html += '          </td>';
                        html += '          <td class="px-4 py-2.5 text-slate-600 whitespace-nowrap text-[11px]">' + escHtml(st.internship_dates_formatted || '—') + '</td>';
                        html += '          <td class="px-4 py-2.5 text-center">' + stuStatusPill + '</td>';
                        html += '          <td class="px-4 py-2.5 text-right whitespace-nowrap">';
                        html += '            <span class="font-semibold text-slate-700 block">' + (st.weekly_eval_count || 0) + ' weekly review' + (st.weekly_eval_count === 1 ? '' : 's') + '</span>';
                        if (gradeText) {
                            html += '            <div class="mt-0.5">' + gradeText + '</div>';
                        }
                        html += '          </td>';
                        html += '        </tr>';
                    });
                    html += '      </tbody>';
                    html += '    </table>';
                    html += '  </div>';
                } else {
                    html += '  <div class="p-4 text-center text-xs text-slate-400 bg-slate-50/40">';
                    html += '    No students supervised for this academic year.';
                    html += '  </div>';
                }

                html += '</div>';
            });

            listEl.innerHTML = html;
        }

        function assignSupervisorYear(e) {
            e.preventDefault();
            var supId = document.getElementById('assignSupId').value;
            var yearId = document.getElementById('assignYearSelect').value;
            var errDiv = document.getElementById('assignYearError');
            errDiv.classList.add('hidden');

            if (!supId || !yearId) {
                errDiv.textContent = 'Please select an academic year.';
                errDiv.classList.remove('hidden');
                return false;
            }

            var fd = new FormData();
            fd.append('action', 'assign');
            fd.append('supervisor_id', supId);
            fd.append('academic_year_id', yearId);

            fetch('api/assign_supervisor.php', {
                    method: 'POST',
                    body: fd
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        document.getElementById('assignYearSelect').value = '';
                        openSupervisorHistoryModal(supId, document.getElementById('supHistoryTitle').textContent, document.getElementById('supHistoryEmail').textContent);
                        showToast('success', data.message || 'Assigned to academic year.');
                    } else {
                        errDiv.textContent = data.error || 'Failed to assign.';
                        errDiv.classList.remove('hidden');
                    }
                })
                .catch(function() {
                    errDiv.textContent = 'Network error. Please try again.';
                    errDiv.classList.remove('hidden');
                });
            return false;
        }

        function removeSupAssignment(supId, yearId, yearLabel) {
            if (!confirm('Remove assignment for ' + yearLabel + '?\nThis will unassign the supervisor from this year, but all historical student and evaluation records will remain safely preserved.')) return;
            var fd = new FormData();
            fd.append('action', 'unassign');
            fd.append('supervisor_id', supId);
            fd.append('academic_year_id', yearId);

            fetch('api/assign_supervisor.php', {
                    method: 'POST',
                    body: fd
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        openSupervisorHistoryModal(supId, document.getElementById('supHistoryTitle').textContent, document.getElementById('supHistoryEmail').textContent);
                        showToast('success', data.message || 'Assignment removed.');
                    } else {
                        showToast('error', data.error || 'Failed to remove assignment.');
                    }
                })
                .catch(function() {
                    showToast('error', 'Network error.');
                });
        }

        function escHtml(s) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(s || ''));
            return d.innerHTML;
        }

        function escAttr(s) {
            return (s || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
        }

        function showToast(type, message) {
            var toast = document.getElementById('toast');
            if (!toast) return;
            var bg = type === 'success' ?
                'bg-emerald-50 border border-emerald-200 text-emerald-700' :
                'bg-red-50 border border-red-200 text-red-700';
            var icon = type === 'success' ? '✅' : '❌';
            toast.className = bg + ' px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 text-sm font-semibold transition-all duration-300 fixed top-6 right-6 z-[100] max-w-sm';
            toast.innerHTML = '<span>' + icon + '</span> ' + message;
            toast.classList.remove('hidden');
            setTimeout(function() {
                toast.classList.add('hidden');
            }, 3000);
        }
    </script>

</body>

</html>