<?php
/**
 * transition_year.php – Safely transition academic years.
 *
 * Archives the current ACTIVE year and promotes a selected UPCOMING
 * year to ACTIVE. Uses PDO transactions with row-level locking to
 * prevent race conditions during concurrent admin requests.
 *
 * POST params:
 *   upcoming_year_id – The id of the UPCOMING year to activate
 *
 * Response: JSON { success, archived, activated | error }
 */

require_once __DIR__ . '/../config/database.php';
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

if ($upcoming_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'No upcoming year selected.']);
    exit;
}

// ── Transition (single transaction with row-level locks) ────────
try {
    $pdo->beginTransaction();

    // 1. Lock and fetch the currently ACTIVE year
    //    FOR UPDATE prevents two concurrent transitions from both
    //    seeing the same ACTIVE row and double-archiving.
    $cur_stmt = $pdo->prepare("
        SELECT id, year_label
        FROM academic_years
        WHERE status = 'ACTIVE' AND is_current = 1
        LIMIT 1
        FOR UPDATE
    ");
    $cur_stmt->execute();
    $cur = $cur_stmt->fetch();

    // 2. Lock and validate the target UPCOMING year
    $next_stmt = $pdo->prepare("
        SELECT id, year_label
        FROM academic_years
        WHERE id = ? AND status = 'UPCOMING'
        LIMIT 1
        FOR UPDATE
    ");
    $next_stmt->execute([$upcoming_id]);
    $next = $next_stmt->fetch();

    if (!$next) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error'   => 'Selected year is not UPCOMING or does not exist.',
        ]);
        exit;
    }

    // Prevent transitioning a year onto itself (shouldn't happen, but defensive)
    if ($cur && (int) $cur['id'] === $upcoming_id) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error'   => 'Cannot transition a year onto itself.',
        ]);
        exit;
    }

    // 3. Archive the current ACTIVE year
    if ($cur) {
        $pdo->prepare("
            UPDATE academic_years
            SET status = 'ARCHIVED', is_current = 0
            WHERE id = ?
        ")->execute([$cur['id']]);

        // Batch-archive students linked to this year via FK
        $pdo->prepare("
            UPDATE users SET status = 'Archived'
            WHERE academic_year_id = ?
              AND role = 'student'
              AND status = 'Active'
        ")->execute([$cur['id']]);

        // Fallback: archive by string label for rows where FK was never backfilled
        $pdo->prepare("
            UPDATE users SET status = 'Archived'
            WHERE academic_year = ?
              AND academic_year_id IS NULL
              AND role = 'student'
              AND status = 'Active'
        ")->execute([$cur['year_label']]);
    }

    // 4. Promote the selected UPCOMING year to ACTIVE
    $pdo->prepare("
        UPDATE academic_years
        SET status = 'ACTIVE', is_current = 1
        WHERE id = ?
    ")->execute([$upcoming_id]);

    // 5. Belt & suspenders: ensure no other rows have is_current = 1
    $pdo->prepare("
        UPDATE academic_years
        SET is_current = 0
        WHERE id != ? AND is_current = 1
    ")->execute([$upcoming_id]);

    // 6. Also sync is_current for any rows that are ARCHIVED but still marked current
    $pdo->prepare("
        UPDATE academic_years
        SET is_current = 0
        WHERE status != 'ACTIVE' AND is_current = 1
    ")->execute();

    $pdo->commit();

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

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error'   => 'Transition failed: ' . $e->getMessage(),
    ]);
}
