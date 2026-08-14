<?php
/**
 * transition_year.php – Safely transition academic years.
 *
 * Archives the current ACTIVE year and promotes a selected UPCOMING
 * year to ACTIVE. Uses MySQLi transactions with row-level locking to
 * prevent race conditions during concurrent admin requests.
 *
 * POST params:
 *   upcoming_year_id – The id of the UPCOMING year to activate
 *
 * Response: JSON { success, archived, activated | error }
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth.php';

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

$upcoming_id = (int) ($_POST['upcoming_year_id'] ?? 0);
$db          = $mysqli ?? $conn;

if ($upcoming_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'No upcoming year selected.']);
    exit;
}

// ── Transition (single transaction with row-level locks) ────────
try {
    $db->begin_transaction();

    // 1. Lock and fetch the currently ACTIVE year
    $cur_stmt = $db->prepare("
        SELECT id, year_label
        FROM academic_years
        WHERE status = 'ACTIVE' AND is_current = 1
        LIMIT 1
        FOR UPDATE
    ");
    $cur_stmt->execute();
    $res = $cur_stmt->get_result();
    $cur = $res ? $res->fetch_assoc() : null;

    // 2. Lock and validate the target UPCOMING year
    $next_stmt = $db->prepare("
        SELECT id, year_label
        FROM academic_years
        WHERE id = ? AND status = 'UPCOMING'
        LIMIT 1
        FOR UPDATE
    ");
    $next_stmt->bind_param("i", $upcoming_id);
    $next_stmt->execute();
    $res = $next_stmt->get_result();
    $next = $res ? $res->fetch_assoc() : null;

    if (!$next) {
        $db->rollback();
        echo json_encode([
            'success' => false,
            'error'   => 'Selected year is not UPCOMING or does not exist.',
        ]);
        exit;
    }

    // Prevent transitioning a year onto itself
    if ($cur && (int) $cur['id'] === $upcoming_id) {
        $db->rollback();
        echo json_encode([
            'success' => false,
            'error'   => 'Cannot transition a year onto itself.',
        ]);
        exit;
    }

    // 3. Archive the current ACTIVE year
    if ($cur) {
        $cur_id = (int) $cur['id'];
        $stmt1 = $db->prepare("
            UPDATE academic_years
            SET status = 'ARCHIVED', is_current = 0
            WHERE id = ?
        ");
        $stmt1->bind_param("i", $cur_id);
        $stmt1->execute();

        // Batch-archive students linked to this year via FK
        $stmt2 = $db->prepare("
            UPDATE users SET status = 'Archived'
            WHERE academic_year_id = ?
              AND role = 'student'
              AND status = 'Active'
        ");
        $stmt2->bind_param("i", $cur_id);
        $stmt2->execute();

        // Fallback: archive by string label for rows where FK was never backfilled
        $stmt3 = $db->prepare("
            UPDATE users SET status = 'Archived'
            WHERE academic_year = ?
              AND academic_year_id IS NULL
              AND role = 'student'
              AND status = 'Active'
        ");
        $stmt3->bind_param("s", $cur['year_label']);
        $stmt3->execute();
    }

    // 4. Promote the selected UPCOMING year to ACTIVE
    $stmt4 = $db->prepare("
        UPDATE academic_years
        SET status = 'ACTIVE', is_current = 1
        WHERE id = ?
    ");
    $stmt4->bind_param("i", $upcoming_id);
    $stmt4->execute();

    // 5. Belt & suspenders: ensure no other rows have is_current = 1
    $stmt5 = $db->prepare("
        UPDATE academic_years
        SET is_current = 0
        WHERE id != ? AND is_current = 1
    ");
    $stmt5->bind_param("i", $upcoming_id);
    $stmt5->execute();

    // 6. Also sync is_current for any rows that are ARCHIVED but still marked current
    $stmt6 = $db->prepare("
        UPDATE academic_years
        SET is_current = 0
        WHERE status != 'ACTIVE' AND is_current = 1
    ");
    $stmt6->execute();

    $db->commit();

    // 7. Update session to reflect the new active year
    $_SESSION['selected_academic_year_id']   = $upcoming_id;
    $_SESSION['selected_academic_year_label'] = $next['year_label'];

    echo json_encode([
        'success'   => true,
        'archived'  => $cur ? $cur['year_label'] : null,
        'activated' => $next['year_label'],
        'message'   => "Year {$next['year_label']} is now ACTIVE."
                     . ($cur ? " Previous year {$cur['year_label']} archived." : ''),
    ]);

} catch (Throwable $e) {
    @$db->rollback();
    echo json_encode([
        'success' => false,
        'error'   => 'Transition failed: ' . $e->getMessage(),
    ]);
}
