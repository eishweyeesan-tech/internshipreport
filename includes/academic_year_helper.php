<?php
/**
 * Academic Year Helper
 * Canonical helper functions to query, manage, and render Academic Years.
 */

if (!function_exists('ensure_academic_years_table')) {
    function ensure_academic_years_table($db) {
        if (!$db) return;
        ensure_users_status_enum($db);
        
        $create_sql = "
            CREATE TABLE IF NOT EXISTS academic_years (
                id INT AUTO_INCREMENT PRIMARY KEY,
                year_label VARCHAR(50) NOT NULL UNIQUE,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('Active', 'Upcoming', 'Archived') NOT NULL DEFAULT 'Upcoming',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $db->query($create_sql);

        // Check if empty
        $check_res = $db->query("SELECT COUNT(*) FROM academic_years");
        $count = $check_res ? (int) ($check_res->fetch_row()[0] ?? 0) : 0;
        
        if ($count === 0) {
            // Seed from users table distinct values or defaults
            $users_ay_res = $db->query("SELECT DISTINCT academic_year FROM users WHERE academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
            $existing_years = [];
            if ($users_ay_res) {
                while ($row = $users_ay_res->fetch_assoc()) {
                    if (preg_match('/^\d{4}-\d{4}$/', $row['academic_year'])) {
                        $existing_years[] = $row['academic_year'];
                    }
                }
            }

            if (empty($existing_years)) {
                $existing_years = ['2023-2024', '2024-2025'];
            }

            // Make the first one active if not specified
            foreach ($existing_years as $idx => $label) {
                $start_year = substr($label, 0, 4);
                $end_year = substr($label, 5, 4);
                $start_date = $start_year . '-09-01';
                $end_date = $end_year . '-08-31';
                $is_current = ($idx === 0) ? 1 : 0;
                $status = ($idx === 0) ? 'Active' : 'Upcoming';

                $stmt = $db->prepare("INSERT IGNORE INTO academic_years (year_label, start_date, end_date, is_current, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssis", $label, $start_date, $end_date, $is_current, $status);
                $stmt->execute();
            }
        } else {
            // Ensure at least one current active academic year exists
            $curr_res = $db->query("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
            if (!$curr_res || !$curr_res->fetch_row()) {
                // Check if 2023-2024 exists
                $y23 = $db->query("SELECT id FROM academic_years WHERE year_label = '2023-2024' LIMIT 1");
                if ($y23 && $row23 = $y23->fetch_assoc()) {
                    $y_id = (int)$row23['id'];
                    $db->query("UPDATE academic_years SET is_current = 1, status = 'Active' WHERE id = {$y_id}");
                } else {
                    $db->query("UPDATE academic_years SET is_current = 1, status = 'Active' ORDER BY start_date ASC LIMIT 1");
                }
            }
        }
    }
}

if (!function_exists('get_all_academic_years')) {
    function get_all_academic_years($db) {
        ensure_academic_years_table($db);
        $res = $db->query("SELECT * FROM academic_years ORDER BY start_date DESC, year_label DESC");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('get_active_academic_year')) {
    function get_active_academic_year($db) {
        ensure_academic_years_table($db);
        $res = $db->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return $row;
        }
        $res = $db->query("SELECT * FROM academic_years WHERE status = 'Active' ORDER BY start_date DESC LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return $row;
        }
        $res = $db->query("SELECT * FROM academic_years ORDER BY start_date DESC LIMIT 1");
        return ($res && $row = $res->fetch_assoc()) ? $row : null;
    }
}

if (!function_exists('get_active_academic_year_label')) {
    function get_active_academic_year_label($db, $fallback = '2023-2024') {
        $active = get_active_academic_year($db);
        return $active ? $active['year_label'] : $fallback;
    }
}

if (!function_exists('get_academic_years_list')) {
    function get_academic_years_list($db) {
        $all = get_all_academic_years($db);
        $labels = [];
        foreach ($all as $y) {
            $labels[] = $y['year_label'];
        }
        return $labels;
    }
}

// ════════════════════════════════════════════════════════════════
// SUPERVISOR–ACADEMIC YEAR ASSIGNMENT FUNCTIONS
// ════════════════════════════════════════════════════════════════

if (!function_exists('ensure_supervisor_assignments_table')) {
    function ensure_supervisor_assignments_table($db) {
        if (!$db) return;
        $db->query("
            CREATE TABLE IF NOT EXISTS supervisor_academic_assignments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                supervisor_id INT NOT NULL,
                academic_year_id INT NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                assigned_by INT DEFAULT NULL,
                UNIQUE KEY unique_sup_year (supervisor_id, academic_year_id),
                FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Auto-migrate: seed from existing users.academic_year_id where role = supervisor
        $res = $db->query("
            SELECT u.id AS sup_id, u.academic_year_id, ay.id AS ay_id
            FROM users u
            LEFT JOIN academic_years ay ON ay.id = u.academic_year_id OR ay.year_label = u.academic_year
            WHERE u.role = 'supervisor'
              AND u.academic_year_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM supervisor_academic_assignments saa
                  WHERE saa.supervisor_id = u.id AND saa.academic_year_id = COALESCE(u.academic_year_id, ay.id)
              )
        ");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $sid = (int) $row['sup_id'];
                $ayid = (int) ($row['academic_year_id'] ?: $row['ay_id']);
                if ($sid > 0 && $ayid > 0) {
                    $ins = $db->prepare("INSERT IGNORE INTO supervisor_academic_assignments (supervisor_id, academic_year_id) VALUES (?, ?)");
                    $ins->bind_param("ii", $sid, $ayid);
                    $ins->execute();
                }
            }
        }

        // Also migrate supervisors linked via student_profiles in a given year
        $res2 = $db->query("
            SELECT DISTINCT sp.supervisor_id, ay.id AS ay_id
            FROM student_profiles sp
            JOIN users stu ON stu.id = sp.user_id
            JOIN academic_years ay ON (ay.id = stu.academic_year_id OR ay.year_label = stu.academic_year)
            WHERE sp.supervisor_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM supervisor_academic_assignments saa
                  WHERE saa.supervisor_id = sp.supervisor_id AND saa.academic_year_id = ay.id
              )
        ");
        if ($res2) {
            while ($row2 = $res2->fetch_assoc()) {
                $sid2 = (int) $row2['supervisor_id'];
                $ayid2 = (int) $row2['ay_id'];
                if ($sid2 > 0 && $ayid2 > 0) {
                    $ins2 = $db->prepare("INSERT IGNORE INTO supervisor_academic_assignments (supervisor_id, academic_year_id) VALUES (?, ?)");
                    $ins2->bind_param("ii", $sid2, $ayid2);
                    $ins2->execute();
                }
            }
        }
    }
}

if (!function_exists('assign_supervisor_to_year')) {
    function assign_supervisor_to_year($db, $supervisor_id, $academic_year_id, $assigned_by = null) {
        $stmt = $db->prepare("INSERT IGNORE INTO supervisor_academic_assignments (supervisor_id, academic_year_id, assigned_by) VALUES (?, ?, ?)");
        $stmt->bind_param("iii", $supervisor_id, $academic_year_id, $assigned_by);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }
}

if (!function_exists('unassign_supervisor_from_year')) {
    function unassign_supervisor_from_year($db, $supervisor_id, $academic_year_id) {
        $stmt = $db->prepare("DELETE FROM supervisor_academic_assignments WHERE supervisor_id = ? AND academic_year_id = ?");
        $stmt->bind_param("ii", $supervisor_id, $academic_year_id);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }
}

if (!function_exists('get_supervisor_assignments')) {
    function get_supervisor_assignments($db, $supervisor_id) {
        $stmt = $db->prepare("
            SELECT saa.*, ay.year_label, ay.start_date, ay.end_date, ay.status AS year_status
            FROM supervisor_academic_assignments saa
            JOIN academic_years ay ON ay.id = saa.academic_year_id
            WHERE saa.supervisor_id = ?
            ORDER BY ay.start_date DESC
        ");
        $stmt->bind_param("i", $supervisor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('get_supervisors_for_year')) {
    function get_supervisors_for_year($db, $academic_year_id) {
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.email, u.phone, u.department, u.position, u.status, u.is_first_login, u.created_at,
                   saa.assigned_at,
                   (SELECT COUNT(*) FROM student_profiles sp
                    JOIN users stu ON stu.id = sp.user_id
                    WHERE sp.supervisor_id = u.id
                      AND (stu.academic_year_id = ? OR stu.academic_year = ay.year_label)
                   ) AS student_count
            FROM supervisor_academic_assignments saa
            JOIN users u ON u.id = saa.supervisor_id
            JOIN academic_years ay ON ay.id = saa.academic_year_id
            WHERE saa.academic_year_id = ?
            ORDER BY u.username ASC
        ");
        $stmt->bind_param("ii", $academic_year_id, $academic_year_id);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('get_supervisor_year_student_count')) {
    function get_supervisor_year_student_count($db, $supervisor_id, $academic_year_id) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM student_profiles sp
            JOIN users stu ON stu.id = sp.user_id
            JOIN academic_years ay ON ay.id = ?
            WHERE sp.supervisor_id = ?
              AND (stu.academic_year_id = ? OR stu.academic_year = ay.year_label)
        ");
        $stmt->bind_param("iii", $academic_year_id, $supervisor_id, $academic_year_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_row() : null;
        return (int) ($row[0] ?? 0);
    }
}

if (!function_exists('get_total_supervisors_for_year')) {
    function get_total_supervisors_for_year($db, $academic_year_id) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM supervisor_academic_assignments WHERE academic_year_id = ?");
        $stmt->bind_param("i", $academic_year_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_row() : null;
        return (int) ($row[0] ?? 0);
    }
}

if (!function_exists('is_supervisor_assigned_to_year')) {
    function is_supervisor_assigned_to_year($db, $supervisor_id, $academic_year_id) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM supervisor_academic_assignments WHERE supervisor_id = ? AND academic_year_id = ?");
        $stmt->bind_param("ii", $supervisor_id, $academic_year_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_row() : null;
        return (int) ($row[0] ?? 0) > 0;
    }
}

if (!function_exists('ensure_users_status_enum')) {
    function ensure_users_status_enum($db) {
        if (!$db) return;
        try {
            // Check if status column supports 'Inactive'
            $col_res = $db->query("SHOW COLUMNS FROM users LIKE 'status'");
            if ($col_res && $row = $col_res->fetch_assoc()) {
                $type = strtolower((string)($row['Type'] ?? ''));
                if (strpos($type, 'inactive') === false) {
                    $db->query("ALTER TABLE users MODIFY COLUMN status ENUM('Active', 'Inactive', 'Archived') NOT NULL DEFAULT 'Active'");
                }
            }
        } catch (Throwable $e) {
            // Silently fallback
        }
    }
}

if (!function_exists('get_supervisor_detailed_history')) {
    function get_supervisor_detailed_history($db, $supervisor_id) {
        ensure_academic_years_table($db);
        ensure_supervisor_assignments_table($db);
        ensure_users_status_enum($db);

        $supervisor_id = (int) $supervisor_id;
        if ($supervisor_id <= 0) return null;

        // Fetch supervisor profile
        $sup_stmt = $db->prepare("SELECT id, username, email, phone, department, position, status, is_first_login, created_at, last_login_at FROM users WHERE id = ? AND role = 'supervisor' LIMIT 1");
        $sup_stmt->bind_param("i", $supervisor_id);
        $sup_stmt->execute();
        $sup_res = $sup_stmt->get_result();
        $supervisor = $sup_res ? $sup_res->fetch_assoc() : null;
        if (!$supervisor) return null;

        // Fetch all distinct academic years related to this supervisor (assigned or has supervised students)
        $years_stmt = $db->prepare("
            SELECT DISTINCT ay.id, ay.year_label, ay.start_date, ay.end_date, ay.status AS year_status, ay.is_current
            FROM academic_years ay
            WHERE ay.id IN (SELECT academic_year_id FROM supervisor_academic_assignments WHERE supervisor_id = ?)
               OR ay.id IN (SELECT stu.academic_year_id FROM student_profiles sp JOIN users stu ON stu.id = sp.user_id WHERE sp.supervisor_id = ? AND stu.academic_year_id IS NOT NULL)
               OR ay.year_label IN (SELECT stu.academic_year FROM student_profiles sp JOIN users stu ON stu.id = sp.user_id WHERE sp.supervisor_id = ? AND stu.academic_year IS NOT NULL AND stu.academic_year <> '')
            ORDER BY ay.start_date DESC, ay.year_label DESC
        ");
        $years_stmt->bind_param("iii", $supervisor_id, $supervisor_id, $supervisor_id);
        $years_stmt->execute();
        $years_res = $years_stmt->get_result();
        $years_list = $years_res ? $years_res->fetch_all(MYSQLI_ASSOC) : [];

        $history_by_year = [];
        $total_students_all_time = 0;
        $total_evaluations_all_time = 0;

        foreach ($years_list as $yr) {
            $year_id = (int) $yr['id'];
            $year_label = $yr['year_label'];

            // Check assignment record
            $assign_stmt = $db->prepare("SELECT assigned_at, assigned_by FROM supervisor_academic_assignments WHERE supervisor_id = ? AND academic_year_id = ? LIMIT 1");
            $assign_stmt->bind_param("ii", $supervisor_id, $year_id);
            $assign_stmt->execute();
            $assign_res = $assign_stmt->get_result();
            $assign_row = $assign_res ? $assign_res->fetch_assoc() : null;
            $is_assigned = ($assign_row !== null);
            $assigned_at = $assign_row ? $assign_row['assigned_at'] : null;

            // Fetch students supervised in this year
            $stu_stmt = $db->prepare("
                SELECT u.id, u.username, u.email, u.phone, u.status AS student_status,
                       sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role,
                       sp.internship_start_date, sp.internship_end_date,
                       (SELECT COUNT(*) FROM supervisor_weekly_evaluations swe WHERE swe.student_id = u.id AND swe.supervisor_id = ?) AS weekly_eval_count,
                       (SELECT swe2.weekly_grade FROM supervisor_weekly_evaluations swe2 WHERE swe2.student_id = u.id AND swe2.supervisor_id = ? ORDER BY swe2.week_number DESC, swe2.id DESC LIMIT 1) AS latest_weekly_grade,
                       (SELECT COUNT(*) FROM report_evaluations re WHERE re.student_id = u.id) AS report_eval_count
                FROM users u
                JOIN student_profiles sp ON sp.user_id = u.id
                WHERE sp.supervisor_id = ?
                  AND (u.academic_year_id = ? OR u.academic_year = ?)
                ORDER BY sp.student_roll ASC, sp.full_name ASC
            ");
            $stu_stmt->bind_param("iiiis", $supervisor_id, $supervisor_id, $supervisor_id, $year_id, $year_label);
            $stu_stmt->execute();
            $stu_res = $stu_stmt->get_result();
            $students = $stu_res ? $stu_res->fetch_all(MYSQLI_ASSOC) : [];

            $year_eval_count = 0;
            $grade_counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];

            foreach ($students as &$st) {
                $eval_cnt = (int)($st['weekly_eval_count'] ?? 0);
                $year_eval_count += $eval_cnt;
                if (!empty($st['latest_weekly_grade']) && isset($grade_counts[$st['latest_weekly_grade']])) {
                    $grade_counts[$st['latest_weekly_grade']]++;
                }
                $st['internship_dates_formatted'] = ($st['internship_start_date'] ? date('d M Y', strtotime($st['internship_start_date'])) : '—') .
                    ' to ' .
                    ($st['internship_end_date'] ? date('d M Y', strtotime($st['internship_end_date'])) : '—');
            }
            unset($st);

            $total_evaluations_all_time += $year_eval_count;

            $history_by_year[] = [
                'academic_year_id' => $year_id,
                'year_label' => $year_label,
                'start_date' => $yr['start_date'],
                'end_date' => $yr['end_date'],
                'year_status' => $yr['year_status'],
                'is_current' => (bool)$yr['is_current'],
                'is_assigned' => $is_assigned,
                'assigned_at' => $assigned_at,
                'assigned_at_display' => $assigned_at ? date('d M Y, H:i', strtotime($assigned_at)) : null,
                'student_count' => count($students),
                'students' => $students,
                'evaluation_count' => $year_eval_count,
                'grade_counts' => $grade_counts,
            ];
        }

        // Count distinct all-time students
        $tot_stu_stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM student_profiles WHERE supervisor_id = ?");
        $tot_stu_stmt->bind_param("i", $supervisor_id);
        $tot_stu_stmt->execute();
        $tot_stu_res = $tot_stu_stmt->get_result();
        $tot_stu_row = $tot_stu_res ? $tot_stu_res->fetch_row() : null;
        $total_students_all_time = (int) ($tot_stu_row[0] ?? 0);

        // Count total assignments
        $tot_assign_stmt = $db->prepare("SELECT COUNT(*) FROM supervisor_academic_assignments WHERE supervisor_id = ?");
        $tot_assign_stmt->bind_param("i", $supervisor_id);
        $tot_assign_stmt->execute();
        $tot_assign_res = $tot_assign_stmt->get_result();
        $tot_assign_row = $tot_assign_res ? $tot_assign_res->fetch_row() : null;
        $total_assigned_years = (int) ($tot_assign_row[0] ?? 0);

        return [
            'supervisor' => $supervisor,
            'total_assigned_years' => $total_assigned_years,
            'total_students' => $total_students_all_time,
            'total_evaluations' => $total_evaluations_all_time,
            'assignments' => $history_by_year,
        ];
    }
}

if (!function_exists('render_academic_year_options')) {
    function render_academic_year_options($db, $selected_value = '', $include_all_option = false, $all_label = 'All Academic Years') {
        $years = get_all_academic_years($db);
        $output = '';
        if ($include_all_option) {
            $sel = ($selected_value === 'all' || $selected_value === '') ? 'selected' : '';
            $output .= '<option value="all" ' . $sel . '>' . htmlspecialchars($all_label) . '</option>';
        }
        $active = get_active_academic_year($db);
        $active_label = $active ? $active['year_label'] : '';

        foreach ($years as $y) {
            $lbl = $y['year_label'];
            $is_sel = ($selected_value === $lbl) || (empty($selected_value) && !$include_all_option && $lbl === $active_label);
            $suffix = ($y['is_current'] == 1) ? ' (Current Active)' : (($y['status'] === 'Archived') ? ' (Archived)' : '');
            $output .= '<option value="' . htmlspecialchars($lbl) . '" ' . ($is_sel ? 'selected' : '') . '>';
            $output .= htmlspecialchars($lbl . $suffix);
            $output .= '</option>';
        }
        return $output;
    }
}
