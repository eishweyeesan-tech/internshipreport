<?php
/**
 * init_year.php – Session-based academic year tracker.
 *
 * Include this AFTER database.php on every page that needs year context.
 *
 * Handles:
 *   1. POST year switching (admin form submission)
 *   2. GET year switching (?year_id=N query string)
 *   3. Session persistence across page loads
 *   4. Fallback to is_current = 1 if no session exists
 *   5. Synthetic default if the academic_years table is empty (pre-migration)
 *
 * Sets:
 *   $_SESSION['selected_academic_year_id']    (int)
 *   $_SESSION['selected_academic_year_label']  (string e.g. "2025-2026")
 *   $current_academic_year  (array|false) – full row from academic_years
 *   $all_academic_years     (array)       – all rows for dropdowns
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $pdo;

// ── Fetch all academic years (cached per request) ────────────────
$stmt = $pdo->query("
    SELECT id, year_label, start_date, end_date, status, is_current, created_at
    FROM academic_years
    ORDER BY start_date DESC
");
$all_academic_years = $stmt ? $stmt->fetchAll() : [];

// ── Build a lookup map for O(1) validation ──────────────────────
$ay_map = [];
foreach ($all_academic_years as $ay) {
    $ay_map[(int) $ay['id']] = $ay;
}

// ── Resolve which year is selected ──────────────────────────────
$selected_id = null;

// 1. Admin POST override (form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_academic_year'])) {
    $candidate = (int) ($_POST['switch_academic_year_id'] ?? 0);
    if ($candidate > 0 && isset($ay_map[$candidate])) {
        $selected_id = $candidate;
        $_SESSION['selected_academic_year_id'] = $selected_id;
    }
}

// 2. Admin GET override (?year_id=N, for dropdown clicks)
if (!$selected_id && isset($_GET['year_id'])) {
    $candidate = (int) $_GET['year_id'];
    if ($candidate > 0 && isset($ay_map[$candidate])) {
        $selected_id = $candidate;
        $_SESSION['selected_academic_year_id'] = $selected_id;
    }
}

// 3. Existing session value
if (!$selected_id && isset($_SESSION['selected_academic_year_id'])) {
    $sid = (int) $_SESSION['selected_academic_year_id'];
    if (isset($ay_map[$sid])) {
        $selected_id = $sid;
    } else {
        // Session references a deleted year; clear it
        unset($_SESSION['selected_academic_year_id'], $_SESSION['selected_academic_year_label']);
    }
}

// 4. Fallback: is_current = 1
if (!$selected_id) {
    foreach ($all_academic_years as $ay) {
        if ((int) $ay['is_current'] === 1) {
            $selected_id = (int) $ay['id'];
            break;
        }
    }
}

// ── Build the resolved year data ────────────────────────────────
if (!$selected_id && empty($all_academic_years)) {
    // Table is empty (pre-migration): create a synthetic default
    $yr = (int) date('Y');
    $label = $yr . '-' . ($yr + 1);
    $current_academic_year = [
        'id'         => 0,
        'year_label' => $label,
        'start_date' => $yr . '-09-01',
        'end_date'   => ($yr + 1) . '-08-31',
        'status'     => 'ACTIVE',
        'is_current' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $_SESSION['selected_academic_year_id']   = 0;
    $_SESSION['selected_academic_year_label'] = $label;
} elseif ($selected_id && isset($ay_map[$selected_id])) {
    $current_academic_year = $ay_map[$selected_id];
    $_SESSION['selected_academic_year_id']   = $selected_id;
    $_SESSION['selected_academic_year_label'] = $current_academic_year['year_label'];
} else {
    // Fallback: use the most recent row
    $current_academic_year = $all_academic_years[0] ?? false;
    if ($current_academic_year) {
        $selected_id = (int) $current_academic_year['id'];
        $_SESSION['selected_academic_year_id']   = $selected_id;
        $_SESSION['selected_academic_year_label'] = $current_academic_year['year_label'];
    } else {
        $current_academic_year = false;
        $_SESSION['selected_academic_year_id']   = null;
        $_SESSION['selected_academic_year_label'] = '';
    }
}
