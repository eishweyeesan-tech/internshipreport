<?php
/**
 * create_year.php – Create a new academic year with UPCOMING status.
 *
 * Called via POST (JSON or form) from the admin Academic Year Management page.
 * Wrapped in a transaction for atomicity.
 *
 * POST params:
 *   year_label  – Format YYYY-YYYY (e.g. "2026-2027")
 *   start_date  – Format YYYY-MM-DD
 *   end_date    – Format YYYY-MM-DD
 *
 * Response: JSON { success, id, year_label, message | error }
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth.php';

header('Content-Type: application/json; charset=utf-8');

// ── Gate checks ─────────────────────────────────────────────────
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

// ── Input extraction ────────────────────────────────────────────
$label = trim($_POST['year_label'] ?? '');
$start = trim($_POST['start_date'] ?? '');
$end   = trim($_POST['end_date'] ?? '');

// ── Validation ──────────────────────────────────────────────────
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
    // Validate dates are real and logical
    $start_dt = DateTime::createFromFormat('Y-m-d', $start);
    $end_dt   = DateTime::createFromFormat('Y-m-d', $end);

    if (!$start_dt || $start_dt->format('Y-m-d') !== $start) {
        $errors[] = 'Start date is not a valid calendar date.';
    } elseif (!$end_dt || $end_dt->format('Y-m-d') !== $end) {
        $errors[] = 'End date is not a valid calendar date.';
    } elseif ($start_dt >= $end_dt) {
        $errors[] = 'End date must be after start date.';
    } else {
        // Validate year label matches the dates
        $label_start = (int) substr($label, 0, 4);
        $label_end   = (int) substr($label, 5, 4);
        if ($start_dt->format('Y') != $label_start || $end_dt->format('Y') != $label_end) {
            $errors[] = "Dates must fall within the year range {$label} ({$label_start}-{$label_end}).";
        }

        // Sanity: academic year should span ~8-14 months max
        $diff_months = ($end_dt->format('Y') - $start_dt->format('Y')) * 12
                     + ($end_dt->format('m') - $start_dt->format('m'));
        if ($diff_months < 6 || $diff_months > 18) {
            $errors[] = 'Academic year span seems unusual (' . $diff_months . ' months). Expected 6-18 months.';
        }
    }
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// ── Insert (in transaction) ────────────────────────────────────
try {
    $pdo->beginTransaction();

    // Check duplicate label (within transaction for consistency)
    $dup = $pdo->prepare("SELECT id FROM academic_years WHERE year_label = ? FOR UPDATE");
    $dup->execute([$label]);
    if ($dup->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => "Academic year \"{$label}\" already exists."]);
        exit;
    }

    $ins = $pdo->prepare("
        INSERT INTO academic_years (year_label, start_date, end_date, status, is_current)
        VALUES (?, ?, ?, 'UPCOMING', 0)
    ");
    $ins->execute([$label, $start, $end]);

    $new_id = (int) $pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        'success'    => true,
        'id'         => $new_id,
        'year_label' => $label,
        'start_date' => $start,
        'end_date'   => $end,
        'status'     => 'UPCOMING',
        'message'    => "Academic year {$label} created as UPCOMING.",
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Failed to create academic year: ' . $e->getMessage()]);
}
