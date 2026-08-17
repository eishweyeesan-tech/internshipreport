<?php
/**
 * create_year.php – Create a new academic year with UPCOMING status.
 *
 * Called via POST from the admin Academic Year Management page.
 *
 * POST params:
 *   year_label  – Format YYYY-YYYY (e.g. "2026-2027")
 *   start_date  – Format YYYY-MM-DD
 *   end_date    – Format YYYY-MM-DD
 *
 * Response: JSON { success, id, year_label, message | error }
 */

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/academic_year_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = $mysqli ?? $conn;
ensure_academic_years_table($db);

$label = trim($_POST['year_label'] ?? '');
$start = trim($_POST['start_date'] ?? '');
$end   = trim($_POST['end_date'] ?? '');

$errors = [];

if (empty($label) || !preg_match('/^\d{4}-\d{4}$/', $label)) {
    $errors[] = 'Year label must be in YYYY-YYYY format (e.g. 2026-2027).';
}

if (empty($start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $errors[] = 'Start date is required (YYYY-MM-DD).';
}

if (empty($end) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $errors[] = 'End date is required (YYYY-MM-DD).';
}

if (empty($errors)) {
    $start_dt = DateTime::createFromFormat('Y-m-d', $start);
    $end_dt   = DateTime::createFromFormat('Y-m-d', $end);

    if (!$start_dt || $start_dt->format('Y-m-d') !== $start) {
        $errors[] = 'Start date is not a valid calendar date.';
    } elseif (!$end_dt || $end_dt->format('Y-m-d') !== $end) {
        $errors[] = 'End date is not a valid calendar date.';
    } elseif ($start_dt >= $end_dt) {
        $errors[] = 'End date must be after start date.';
    } else {
        $label_start = (int) substr($label, 0, 4);
        $label_end   = (int) substr($label, 5, 4);
        if ($start_dt->format('Y') != $label_start || $end_dt->format('Y') != $label_end) {
            $errors[] = "Dates must fall within the year range {$label} ({$label_start}-{$label_end}).";
        }
    }
}

if (empty($errors)) {
    // Check duplicate
    $dup_stmt = $db->prepare("SELECT id FROM academic_years WHERE year_label = ?");
    $dup_stmt->bind_param("s", $label);
    $dup_stmt->execute();
    $dup_res = $dup_stmt->get_result();
    if ($dup_res && $dup_res->fetch_row()) {
        $errors[] = "Academic year '{$label}' already exists.";
    }
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

$set_as_current = !empty($_POST['set_as_current']) && ($_POST['set_as_current'] == '1' || $_POST['set_as_current'] === 'true' || $_POST['set_as_current'] === 'on');

try {
    $db->begin_transaction();

    if ($set_as_current) {
        // Reset existing active flags and set previously active years as Archived
        $db->query("UPDATE academic_years SET is_current = 0");
        $db->query("UPDATE academic_years SET status = 'Archived' WHERE status = 'Active'");

        $status = 'Active';
        $is_curr = 1;
    } else {
        $status = 'Upcoming';
        $is_curr = 0;
    }

    $ins = $db->prepare("INSERT INTO academic_years (year_label, start_date, end_date, is_current, status) VALUES (?, ?, ?, ?, ?)");
    $ins->bind_param("sssis", $label, $start, $end, $is_curr, $status);
    $ins->execute();
    $new_id = $ins->insert_id;

    if ($set_as_current) {
        $setting_stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('current_academic_year', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $setting_stmt->bind_param("s", $label);
        $setting_stmt->execute();
    }

    $db->commit();

    echo json_encode([
        'success'    => true,
        'id'         => $new_id,
        'year_label' => $label,
        'start_date' => $start,
        'end_date'   => $end,
        'status'     => $status,
        'is_current' => $is_curr,
        'message'    => "Academic year {$label} created successfully" . ($set_as_current ? " and set as current active year." : "."),
    ]);
} catch (Exception $e) {
    $db->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
