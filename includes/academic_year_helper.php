<?php
/**
 * Academic Year Helper
 * Canonical helper functions to query, manage, and render Academic Years.
 */

if (!function_exists('ensure_academic_years_table')) {
    function ensure_academic_years_table($db) {
        if (!$db) return;
        
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
