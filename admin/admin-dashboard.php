<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';

$admin_name = $_SESSION['username'];
$admin_id   = (int) $_SESSION['user_id'];
$db         = $mysqli ?? $conn;
$msg = '';
$err = '';

// Default password values should be available before request handlers run.
$def_student_pw = 'password123';
$def_supervisor_pw = 'password123';
$sys_settings = [];
$res_st = $db->query("SELECT setting_key, setting_value FROM system_settings");
if ($res_st) {
    while ($row = $res_st->fetch_assoc()) {
        $sys_settings[$row['setting_key']] = $row['setting_value'];
    }
}
$def_student_pw = $sys_settings['default_student_password'] ?? $def_student_pw;
$def_supervisor_pw = $sys_settings['default_supervisor_password'] ?? $def_supervisor_pw;

// ── Academic Years (for dynamic dropdowns and filtering) ────────
$res_ay = $db->query("SELECT DISTINCT academic_year FROM users WHERE academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
$academic_years = [];
if ($res_ay) {
    while ($row = $res_ay->fetch_assoc()) {
        if (!empty($row['academic_year'])) {
            $academic_years[] = $row['academic_year'];
        }
    }
}
if (!in_array('2025-2026', $academic_years, true)) {
    array_unshift($academic_years, '2025-2026');
}
$all_academic_years = array_map(function($y) { return ['year_label' => $y]; }, $academic_years);
$ay_label_to_id = [];

// Determine active tab first
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'students', 'supervisors', 'manage', 'archive', 'history'], true)) {
    $tab = 'overview';
}

// Determine selected academic year filter
// On Reports tab ('history'), default to '2025-2026'; on other tabs (Overview/Manage), default to 'all'
if (isset($_GET['year']) && $_GET['year'] !== '') {
    $selected_year = trim($_GET['year']);
} elseif ($tab === 'history') {
    $selected_year = '2025-2026';
} else {
    $selected_year = 'all';
}

// ══════════════════════════════════════════════════════════════════
// HANDLERS
// ══════════════════════════════════════════════════════════════════

// ── Add Student ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $s_name          = trim($_POST['s_name'] ?? '');
    $s_roll          = trim($_POST['s_roll'] ?? '');
    $s_major         = trim($_POST['s_major'] ?? '');
    $s_email         = trim($_POST['s_email'] ?? '');
    $s_company_id    = (int) ($_POST['s_company_id'] ?? 0);
    $s_supervisor_id = (int) ($_POST['s_supervisor_id'] ?? 0);
    $s_instructor    = trim($_POST['s_instructor'] ?? '');
    $s_start         = trim($_POST['s_start_date'] ?? '');
    $s_end           = trim($_POST['s_end_date'] ?? '');
    $s_academic      = trim($_POST['s_academic_year'] ?? '');
    $s_password      = $_POST['s_password'] ?? '';

    if (empty($s_name) || empty($s_roll) || empty($s_email) || empty($s_password)) {
        $err = 'Name, Roll No, Email, and Password are required.';
    } elseif (!filter_var($s_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email format.';
    } elseif (strlen($s_password) < 6) {
        $err = 'Password must be at least 6 characters.';
    } elseif ($s_academic && !preg_match('/^\d{4}-\d{4}$/', $s_academic)) {
        $err = 'Academic year must be in range format (e.g. 2024-2025).';
    } else {
        $check = $db->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $s_email);
        $check->execute();
        $res = $check->get_result();
        if ($res && $res->fetch_row()) {
            $err = 'A user with this email already exists.';
        } else {
            // Resolve academic_year_id from the string label
            $s_academic_id = ($s_academic && isset($ay_label_to_id[$s_academic])) ? $ay_label_to_id[$s_academic] : null;

            // Composite check: same roll number + same academic year = duplicate
            $check_dup = $db->prepare("SELECT id FROM users WHERE username = ? AND (academic_year_id = ? OR (academic_year_id IS NULL AND academic_year = ?))");
            $check_dup->bind_param("sis", $s_roll, $s_academic_id, $s_academic);
            $check_dup->execute();
            $res = $check_dup->get_result();
            if ($res && $res->fetch_row()) {
                $err = 'A student with roll number "' . htmlspecialchars($s_roll) . '" already exists in ' . htmlspecialchars($s_academic ?: 'this year') . '.';
            } else {
                // Look up company name from selected company_id
                $company_name = '';
                if ($s_company_id > 0) {
                    $cn = $db->prepare("SELECT company_name FROM companies WHERE id = ?");
                    $cn->bind_param("i", $s_company_id);
                    $cn->execute();
                    $res_c = $cn->get_result();
                    $row_c = $res_c ? $res_c->fetch_row() : null;
                    $company_name = $row_c[0] ?? '';
                }

                $hash = password_hash($s_password, PASSWORD_DEFAULT);
                $ins_u = $db->prepare("INSERT INTO users (username, email, password, role, is_first_login, academic_year, academic_year_id) VALUES (?, ?, ?, 'student', 1, ?, ?)");
                $ins_u->bind_param("ssssi", $s_roll, $s_email, $hash, $s_academic, $s_academic_id);
                $ins_u->execute();
                $uid = (int) $db->insert_id;

                $ins_sp = $db->prepare("INSERT INTO student_profiles (user_id, full_name, student_roll, major, company_id, company_name, supervisor_id, instructor_name, internship_start_date, internship_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins_sp->bind_param("isssisisss", $uid, $s_name, $s_roll, $s_major, $s_company_id, $company_name, $s_supervisor_id, $s_instructor, $s_start, $s_end);
                $ins_sp->execute();

                $msg = "Student \"{$s_name}\" created. Email: {$s_email}, Password: {$s_password}";
            }
        }
    }
}

// ── Add Supervisor ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supervisor'])) {
    $t_name     = trim($_POST['t_name'] ?? '');
    $t_dept     = trim($_POST['t_dept'] ?? '');
    $t_email    = trim($_POST['t_email'] ?? '');
    $t_academic = trim($_POST['t_academic_year'] ?? '');
    $t_password = $_POST['t_password'] ?? '';

    if (empty($t_name) || empty($t_email) || empty($t_password)) {
        $err = 'Name, Email, and Password are required.';
    } elseif (!filter_var($t_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email format.';
    } elseif (strlen($t_password) < 6) {
        $err = 'Password must be at least 6 characters.';
    } elseif ($t_academic && !preg_match('/^\d{4}-\d{4}$/', $t_academic)) {
        $err = 'Academic year must be in range format (e.g. 2024-2025).';
    } else {
        $check = $db->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $t_email);
        $check->execute();
        $res = $check->get_result();
        if ($res && $res->fetch_row()) {
            $err = 'A user with this email already exists.';
        } else {
            // Resolve academic_year_id from the string label
            $t_academic_id = ($t_academic && isset($ay_label_to_id[$t_academic])) ? $ay_label_to_id[$t_academic] : null;

            $hash = password_hash($t_password, PASSWORD_DEFAULT);
            $uname = 'sup_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $t_name));
            $ins_sup = $db->prepare("INSERT INTO users (username, email, password, role, is_first_login, academic_year, academic_year_id) VALUES (?, ?, ?, 'supervisor', 1, ?, ?)");
            $ins_sup->bind_param("ssssi", $uname, $t_email, $hash, $t_academic, $t_academic_id);
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

        if ($batch_year_id) {
            $cnt = $db->prepare("SELECT COUNT(*) FROM users WHERE academic_year_id = ? AND role = 'student'");
            $cnt->bind_param("i", $batch_year_id);
            $cnt->execute();
            $res = $cnt->get_result();
            $row = $res ? $res->fetch_row() : null;
            $count = (int) ($row[0] ?? 0);

            $up = $db->prepare("UPDATE users SET status = 'Archived' WHERE academic_year_id = ? AND role = 'student'");
            $up->bind_param("i", $batch_year_id);
            $up->execute();
        } else {
            $cnt = $db->prepare("SELECT COUNT(*) FROM users WHERE academic_year = ? AND role = 'student'");
            $cnt->bind_param("s", $batch_year);
            $cnt->execute();
            $res = $cnt->get_result();
            $row = $res ? $res->fetch_row() : null;
            $count = (int) ($row[0] ?? 0);

            $up = $db->prepare("UPDATE users SET status = 'Archived' WHERE academic_year = ? AND role = 'student'");
            $up->bind_param("s", $batch_year);
            $up->execute();
        }
        $msg = "Archived {$count} student(s) from batch {$batch_year}.";
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

        if ($restore_year_id) {
            $cnt = $db->prepare("SELECT COUNT(*) FROM users WHERE academic_year_id = ? AND role = 'student' AND status = 'Archived'");
            $cnt->bind_param("i", $restore_year_id);
            $cnt->execute();
            $res = $cnt->get_result();
            $row = $res ? $res->fetch_row() : null;
            $count = (int) ($row[0] ?? 0);

            $up1 = $db->prepare("UPDATE users SET status = 'Active' WHERE academic_year_id = ? AND role = 'student' AND status = 'Archived'");
            $up1->bind_param("i", $restore_year_id);
            $up1->execute();
        } else {
            $cnt = $db->prepare("SELECT COUNT(*) FROM users WHERE academic_year = ? AND role = 'student' AND status = 'Archived'");
            $cnt->bind_param("s", $restore_year);
            $cnt->execute();
            $res = $cnt->get_result();
            $row = $res ? $res->fetch_row() : null;
            $count = (int) ($row[0] ?? 0);

            $up = $db->prepare("UPDATE users SET status = 'Active' WHERE academic_year = ? AND role = 'student' AND status = 'Archived'");
            $up->bind_param("s", $restore_year);
            $up->execute();
        }
        $msg = "Restored {$count} student(s) from batch {$restore_year}. They can now log in again.";
    }
}

// ── Toggle Individual User Status (Active <-> Archived) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user_status'])) {
    $target_uid = (int) ($_POST['status_uid'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    if ($target_uid > 0 && in_array($new_status, ['Active', 'Archived'], true)) {
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
// 1. Active students
if ($selected_year !== '' && $selected_year !== 'all') {
    $st_cnt = $db->prepare("SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND u.status = 'Active' AND u.academic_year = ?");
    $st_cnt->bind_param("s", $selected_year);
    $st_cnt->execute();
    $res = $st_cnt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $student_count = (int) ($row[0] ?? 0);
    $st_cnt->close();
} else {
    $res = $db->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'Active'");
    $row = $res ? $res->fetch_row() : null;
    $student_count = (int) ($row[0] ?? 0);
}

// 2. Active supervisors
if ($selected_year !== '' && $selected_year !== 'all') {
    $sup_cnt = $db->prepare("SELECT COUNT(*) FROM users u WHERE u.role = 'supervisor' AND u.status = 'Active' AND (u.academic_year = ? OR u.academic_year IS NULL)");
    $sup_cnt->bind_param("s", $selected_year);
    $sup_cnt->execute();
    $res = $sup_cnt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $supervisor_count = (int) ($row[0] ?? 0);
    $sup_cnt->close();
} else {
    $res = $db->query("SELECT COUNT(*) FROM users WHERE role = 'supervisor' AND status = 'Active'");
    $row = $res ? $res->fetch_row() : null;
    $supervisor_count = (int) ($row[0] ?? 0);
}

// 3. Registered partner companies
$res = $db->query("SELECT COUNT(*) FROM companies");
$row = $res ? $res->fetch_row() : null;
$company_count = (int) ($row[0] ?? 0);

// 4. Pending first login requests
if ($selected_year !== '' && $selected_year !== 'all') {
    $pend_cnt = $db->prepare("SELECT COUNT(*) FROM users u WHERE u.is_first_login = 1 AND u.role != 'admin' AND u.academic_year = ?");
    $pend_cnt->bind_param("s", $selected_year);
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

// Supervisors list
if ($selected_year !== '' && $selected_year !== 'all') {
    $sup_list_stmt = $db->prepare("SELECT id, username, email FROM users WHERE role = 'supervisor' AND (academic_year = ? OR academic_year IS NULL) ORDER BY username");
    $sup_list_stmt->bind_param("s", $selected_year);
    $sup_list_stmt->execute();
    $res_sup = $sup_list_stmt->get_result();
    $supervisors = $res_sup ? $res_sup->fetch_all(MYSQLI_ASSOC) : [];
    $sup_list_stmt->close();
} else {
    $res_sup = $db->query("SELECT id, username, email FROM users WHERE role = 'supervisor' ORDER BY username");
    $supervisors = $res_sup ? $res_sup->fetch_all(MYSQLI_ASSOC) : [];
}

// Students list
if ($selected_year !== '' && $selected_year !== 'all') {
    $stu_list_stmt = $db->prepare("
        SELECT u.id AS uid, u.username, u.email, u.is_first_login, u.academic_year, u.status, u.created_at,
               sp.full_name, sp.student_roll, sp.major, sp.company_name,
               sp.instructor_name, sp.supervisor_id
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.role = 'student' AND u.academic_year = ?
        ORDER BY sp.full_name ASC, u.username ASC
    ");
    $stu_list_stmt->bind_param("s", $selected_year);
    $stu_list_stmt->execute();
    $res_stu = $stu_list_stmt->get_result();
    $students = $res_stu ? $res_stu->fetch_all(MYSQLI_ASSOC) : [];
    $stu_list_stmt->close();
} else {
    $res_stu = $db->query("
        SELECT u.id AS uid, u.username, u.email, u.is_first_login, u.academic_year, u.status, u.created_at,
               sp.full_name, sp.student_roll, sp.major, sp.company_name,
               sp.instructor_name, sp.supervisor_id
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.role = 'student'
        ORDER BY sp.full_name ASC, u.username ASC
    ");
    $students = $res_stu ? $res_stu->fetch_all(MYSQLI_ASSOC) : [];
}

// All users (with optional role filter, academic year filter, and search filter)
$search_term = trim($_GET['search'] ?? '');
$filter_role = $_GET['role'] ?? '';
$all_users_sql = "
    SELECT u.id, u.username, u.email, u.role, u.is_first_login, u.academic_year, u.status, u.created_at,
           sp.full_name, sp.student_roll, sp.company_name
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
";
$where_clauses = [];
$params = [];
$types = "";

if (in_array($filter_role, ['admin', 'supervisor', 'student'], true)) {
    $where_clauses[] = "u.role = ?";
    $params[] = $filter_role;
    $types .= "s";
}
if ($selected_year !== '' && $selected_year !== 'all') {
    $where_clauses[] = "u.academic_year = ?";
    $params[] = $selected_year;
    $types .= "s";
}
if ($search_term !== '') {
    $where_clauses[] = "(sp.full_name LIKE ? OR sp.student_roll LIKE ? OR u.username LIKE ? OR sp.company_name LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search_term . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "sssss";
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
    "SELECT 'student' AS type, 'New student added' AS title, COALESCE(sp.full_name, u.username) AS detail, u.created_at
     FROM users u
     LEFT JOIN student_profiles sp ON sp.user_id = u.id
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
    /* Old toggleProfileDropdown removed — handled by includes/topbar.php */
    </script>
</head>
<body class="bg-slate-50 font-sans antialiased">

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
    ?>
    <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <?php $pageTitle = 'Dashboard'; require_once __DIR__ . '/../includes/topbar.php'; ?>

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

            <!-- ════ ANALYTICS SUMMARY CARDS (always visible) ════ -->
            <div class="w-full grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Students Card -->
                <a href="?tab=manage&role=student" class="group block bg-white rounded-2xl border border-slate-200 shadow-sm p-5 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-teal-200 hover:bg-teal-50/60 cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center text-xl transition group-hover:bg-teal-100">🎓</div>
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Students</p>
                            <p class="text-2xl font-black text-slate-800"><?= $student_count ?></p>
                        </div>
                    </div>
                </a>

                <!-- Supervisors Card -->
                <a href="?tab=supervisors" class="group block bg-white rounded-2xl border border-slate-200 shadow-sm p-5 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-emerald-200 hover:bg-emerald-50/60 cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center text-xl transition group-hover:bg-emerald-100">👨‍🏫</div>
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Supervisors</p>
                            <p class="text-2xl font-black text-slate-800"><?= $supervisor_count ?></p>
                        </div>
                    </div>
                </a>

                <!-- Companies Card -->
                <a href="manage-companies.php" class="group block bg-white rounded-2xl border border-slate-200 shadow-sm p-5 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-teal-200 hover:bg-teal-50/60 cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center text-xl transition group-hover:bg-teal-100">🏢</div>
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Companies</p>
                            <p class="text-2xl font-black text-slate-800"><?= $company_count ?></p>
                        </div>
                    </div>
                </a>

                <!-- Pending Requests Card — dynamic alert style -->
                <a href="?tab=manage" class="group block rounded-2xl shadow-sm p-5 transition-all duration-200 hover:-translate-y-0.5 cursor-pointer <?= $pending_count > 0
                    ? 'bg-amber-50 border-2 border-amber-300 hover:shadow-md hover:border-amber-400 hover:bg-amber-100/60'
                    : 'bg-white border border-slate-200 hover:shadow-md hover:border-amber-200 hover:bg-amber-50/60'
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
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
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
                            <label class="block text-sm font-bold text-slate-500 mb-1">Company <span class="text-slate-300 font-normal">(ကုမ္ပဏီ)</span></label>
                            <select name="s_company_id" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                <option value="">— Select Company —</option>
                                <?php foreach ($companies as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Company Instructor</label>
                            <input type="text" name="s_instructor" placeholder="e.g. U Tin Aung" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
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
                            <label class="text-xs font-semibold text-slate-700 tracking-wide uppercase block mb-1.5">Academic Year *</label>
                            <select name="s_academic_year" class="w-full bg-slate-50 border border-slate-200 rounded-lg p-2.5 text-sm text-slate-800 focus:border-purple-500 focus:bg-white focus:outline-none transition-all duration-200">
                                <?php foreach ($academic_years as $ay): ?>
                                <option value="<?= htmlspecialchars($ay) ?>" <?= $ay === '2025-2026' ? 'selected' : '' ?>><?= htmlspecialchars($ay) ?></option>
                                <?php endforeach; ?>
                                <?php if (empty($academic_years)): ?>
                                <option value="">No academic years configured</option>
                                <?php endif; ?>
                            </select>
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
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Teacher Name *</label>
                            <input type="text" name="t_name" required placeholder="e.g. Dr. Myint Thein" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Department</label>
                            <input type="text" name="t_dept" placeholder="e.g. Computer Science" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Email *</label>
                            <input type="email" name="t_email" required placeholder="supervisor@example.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Academic Year</label>
                            <select name="t_academic_year" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 transition">
                                <option value="">— Select Year —</option>
                                <?php foreach ($academic_years as $ay): ?>
                                <option value="<?= htmlspecialchars($ay) ?>" <?= $ay === '2025-2026' ? 'selected' : '' ?>><?= htmlspecialchars($ay) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-500 mb-1">Default Password *</label>
                            <input type="text" name="t_password" required minlength="6" value="<?= htmlspecialchars($def_supervisor_pw) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 font-mono focus:outline-none focus:border-blue-500 transition">
                            <p class="text-sm text-slate-400 mt-0.5">Must change on first login.</p>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">👨‍🏫 Create Supervisor</button>
                    </div>
                </form>
            </div>

            <?php elseif ($tab === 'manage'): ?>
            <!-- ════ TAB: MANAGE USERS ════ -->

            <!-- All Users -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-slate-100 text-slate-600 rounded">👥</span> All Users
                    </h2>
                    <div class="flex items-center gap-3 flex-wrap">
                        <!-- Search Form -->
                        <form method="GET" class="flex items-center gap-2">
                            <input type="hidden" name="tab" value="manage">
                            <?php if ($filter_role): ?><input type="hidden" name="role" value="<?= htmlspecialchars($filter_role) ?>"><?php endif; ?>
                            <?php if (!empty($selected_year)): ?><input type="hidden" name="year" value="<?= htmlspecialchars($selected_year) ?>"><?php endif; ?>
                            <div class="relative flex-1 sm:w-56 max-w-xs">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                </span>
                                <input type="text" name="search" value="<?= htmlspecialchars($search_term ?? '') ?>" placeholder="Search user, roll, company..." class="w-full bg-slate-50 border border-teal-200 rounded-xl pl-9 pr-8 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 transition-all duration-200">
                                <?php if (!empty($search_term)): ?>
                                <a href="?<?= http_build_query(array_diff_key($_GET, ['search' => ''])) ?>" class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600 text-xs font-bold transition" title="Clear search">✕</a>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer">Search</button>
                        </form>
                        <div class="flex items-center gap-1.5">
                            <a href="?<?= http_build_query(array_merge($_GET, ['role' => ''])) ?>" class="px-2.5 py-1.5 text-xs font-bold rounded-lg transition <?= $filter_role === '' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">All</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['role' => 'admin'])) ?>" class="px-2.5 py-1.5 text-xs font-bold rounded-lg transition <?= $filter_role === 'admin' ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-600 hover:bg-amber-100' ?>">Admin</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['role' => 'supervisor'])) ?>" class="px-2.5 py-1.5 text-xs font-bold rounded-lg transition <?= $filter_role === 'supervisor' ? 'bg-emerald-500 text-white' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' ?>">Supervisor</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['role' => 'student'])) ?>" class="px-2.5 py-1.5 text-xs font-bold rounded-lg transition <?= $filter_role === 'student' ? 'bg-indigo-500 text-white' : 'bg-indigo-50 text-indigo-600 hover:bg-indigo-100' ?>">Student</a>
                            <span class="text-xs text-slate-400 ml-1 font-semibold"><?= count($all_users) ?> total</span>
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
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($all_users as $u): ?>
                            <tr class="hover:bg-slate-50 transition">
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
                                    $rs = ['admin'=>['Admin','text-amber-600','bg-amber-50'], 'supervisor'=>['Supervisor','text-emerald-600','bg-emerald-50'], 'student'=>['Student','text-indigo-600','bg-indigo-50']];
                                    $r = $rs[$u['role']] ?? ['Unknown','text-slate-600','bg-slate-100'];
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
                                    <?= $u['is_first_login'] ? '<span class="text-sm font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">⏳ Pending</span>' : '<span class="text-sm font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">✅ Active</span>' ?>
                                    <?php if (($u['status'] ?? 'Active') === 'Archived'): ?>
                                        <span class="text-sm font-bold text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded ml-1">📦</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2.5 text-slate-400 whitespace-nowrap"><?= (new DateTime($u['created_at']))->format('d M Y') ?></td>
                                <td class="px-3 py-2.5">
                                    <?php if ($u['role'] !== 'admin'): ?>
                                    <div class="flex items-center gap-1.5">
                                        <?php if ($u['role'] === 'student'): ?>
                                        <form method="POST" onsubmit="return confirm('Toggle status for <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?> to <?= ($u['status'] ?? 'Active') === 'Archived' ? 'Active' : 'Archived' ?>?')" class="inline">
                                            <input type="hidden" name="toggle_user_status" value="1">
                                            <input type="hidden" name="status_uid" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= ($u['status'] ?? 'Active') === 'Archived' ? 'Active' : 'Archived' ?>">
                                            <button type="submit" class="px-2 py-1 <?= ($u['status'] ?? 'Active') === 'Archived' ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?> text-sm font-bold rounded-lg transition cursor-pointer" title="<?= ($u['status'] ?? 'Active') === 'Archived' ? 'Restore to Active (Allow Login)' : 'Archive Student (Block Login)' ?>">
                                                <?= ($u['status'] ?? 'Active') === 'Archived' ? '♻️ Activate' : '📦 Archive' ?>
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
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
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
                SELECT u.academic_year AS year_label, COUNT(*) AS cnt
                FROM users u
                WHERE u.role = 'student' AND u.status = 'Archived' AND u.academic_year IS NOT NULL AND u.academic_year <> ''
                GROUP BY u.academic_year
                ORDER BY u.academic_year DESC
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
                SELECT u.id AS uid, u.username, u.email, u.academic_year, u.status, u.created_at,
                       sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
                       sp.instructor_name, sp.supervisor_id,
                       sup_u.username AS supervisor_name
                FROM users u
                LEFT JOIN student_profiles sp ON sp.user_id = u.id
                LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
                WHERE u.role = 'student'
            ";
            $hist_params = [];
            $hist_types = "";

            if ($selected_year !== '' && $selected_year !== 'all') {
                $hist_sql .= " AND u.academic_year = ?";
                $hist_params[] = $selected_year;
                $hist_types .= "s";
            }
            if ($search_term !== '') {
                $hist_sql .= " AND (sp.full_name LIKE ? OR sp.student_roll LIKE ? OR u.username LIKE ? OR sp.company_name LIKE ? OR sp.instructor_name LIKE ? OR sup_u.username LIKE ?)";
                $like = '%' . $search_term . '%';
                $hist_params[] = $like; $hist_params[] = $like; $hist_params[] = $like; $hist_params[] = $like; $hist_params[] = $like; $hist_params[] = $like;
                $hist_types .= "ssssss";
            }

            $hist_sql .= " ORDER BY sp.full_name ASC, u.username ASC";

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

            // Fetch latest grade for each student
            $hist_grades = [];
            foreach ($hist_students as $hs) {
                $gq = $db->prepare("SELECT grade FROM report_evaluations WHERE student_id = ? ORDER BY evaluated_at DESC LIMIT 1");
                $gq->bind_param("i", $hs['uid']);
                $gq->execute();
                $res = $gq->get_result();
                $row = $res ? $res->fetch_row() : null;
                $hist_grades[$hs['uid']] = $row[0] ?? null;
                $gq->close();
            }
            ?>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-lg font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-purple-50 text-purple-600 rounded">📜</span> Student History
                        <?php if ($selected_year && $selected_year !== 'all'): ?>
                            <span class="text-indigo-600 font-mono">— <?= htmlspecialchars($selected_year) ?></span>
                        <?php elseif ($selected_year === 'all'): ?>
                            <span class="text-indigo-600 font-mono">— All Years</span>
                        <?php endif; ?>
                    </h2>
                    <div class="flex items-center gap-3 flex-wrap">
                        <!-- Search Form -->
                        <form method="GET" class="flex items-center gap-2">
                            <input type="hidden" name="tab" value="history">
                            <?php if (!empty($selected_year)): ?><input type="hidden" name="year" value="<?= htmlspecialchars($selected_year) ?>"><?php endif; ?>
                            <div class="relative flex-1 sm:w-56 max-w-xs">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                </span>
                                <input type="text" name="search" value="<?= htmlspecialchars($search_term ?? '') ?>" placeholder="Search student, roll, company..." class="w-full bg-slate-50 border border-teal-200 rounded-xl pl-9 pr-8 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-500 transition-all duration-200">
                                <?php if (!empty($search_term)): ?>
                                <a href="?<?= http_build_query(array_diff_key($_GET, ['search' => ''])) ?>" class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600 text-xs font-bold transition" title="Clear search">✕</a>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer">Search</button>
                        </form>
                        <form method="GET" class="flex items-center gap-2">
                            <input type="hidden" name="tab" value="history">
                            <?php if (!empty($search_term)): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search_term) ?>"><?php endif; ?>
                            <select name="year" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-700 font-semibold focus:outline-none focus:border-teal-500 transition cursor-pointer">
                                <option value="all" <?= $selected_year === 'all' ? 'selected' : '' ?>>All Academic Years</option>
                                <?php foreach ($academic_years as $ay): ?>
                                <option value="<?= htmlspecialchars($ay) ?>" <?= $selected_year === $ay ? 'selected' : '' ?>><?= htmlspecialchars($ay) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>

                <?php if (!empty($hist_students)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-sm">
                                <th class="px-3 py-2.5 text-left">Roll No</th>
                                <th class="px-3 py-2.5 text-left">Student Name</th>
                                <th class="px-3 py-2.5 text-left">Job Role</th>
                                <th class="px-3 py-2.5 text-left">Company</th>
                                <th class="px-3 py-2.5 text-left">Supervisor</th>
                                <th class="px-3 py-2.5 text-left">Year</th>
                                <th class="px-3 py-2.5 text-left">Final Grade</th>
                                <th class="px-3 py-2.5 text-left">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($hist_students as $hs): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-3 py-2.5 font-mono font-semibold text-slate-700"><?= htmlspecialchars($hs['student_roll'] ?: '—') ?></td>
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm font-bold shrink-0">
                                            <?= strtoupper(($hs['full_name'] ?: $hs['username'])[0]) ?>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-slate-700"><?= htmlspecialchars($hs['full_name'] ?: $hs['username']) ?></p>
                                            <p class="text-sm text-slate-400"><?= htmlspecialchars($hs['email']) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 text-slate-600 max-w-[120px] truncate" title="<?= htmlspecialchars($hs['job_role'] ?? '') ?>"><?= htmlspecialchars($hs['job_role'] ?: '—') ?></td>
                                <td class="px-3 py-2.5 text-slate-600 max-w-[130px] truncate" title="<?= htmlspecialchars($hs['company_name'] ?? '') ?>"><?= htmlspecialchars($hs['company_name'] ?: '—') ?></td>
                                <td class="px-3 py-2.5 text-slate-500"><?= htmlspecialchars($hs['supervisor_name'] ?: 'Unassigned') ?></td>
                                <td class="px-3 py-2.5">
                                    <?php if ($hs['academic_year']): ?>
                                        <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($hs['academic_year']) ?></span>
                                    <?php else: ?>
                                        <span class="text-sm text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2.5">
                                    <?php
                                    $grade_map = [
                                        'excellent'         => ['Excellent',         'text-emerald-600', 'bg-emerald-50'],
                                        'good'              => ['Good',              'text-blue-600',    'bg-blue-50'],
                                        'average'           => ['Average',           'text-amber-600',   'bg-amber-50'],
                                        'needs_improvement' => ['Needs Improvement', 'text-red-600',     'bg-red-50'],
                                    ];
                                    $gv = $hist_grades[$hs['uid']] ?? null;
                                    $gs = $gv ? ($grade_map[$gv] ?? ['—', 'text-slate-400', 'bg-slate-50']) : ['—', 'text-slate-400', 'bg-slate-50'];
                                    ?>
                                    <span class="text-sm font-bold <?= $gs[1] ?> <?= $gs[2] ?> px-2 py-0.5 rounded"><?= $gs[0] ?></span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <a href="../view_student_history.php?uid=<?= $hs['uid'] ?>" class="inline-flex items-center gap-1 px-2.5 py-1 bg-purple-50 text-purple-600 text-sm font-bold rounded-lg hover:bg-purple-100 transition">
                                        👁️ View History
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-2.5 border-t border-slate-100 bg-slate-50">
                    <p class="text-sm text-slate-400">Showing <?= count($hist_students) ?> student(s) <?= ($selected_year && $selected_year !== 'all') ? 'for ' . htmlspecialchars($selected_year) : 'across all years' ?></p>
                </div>
                <?php else: ?>
                <div class="p-8 text-center text-sm text-slate-400">
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

</body>
</html>
