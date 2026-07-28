<?php
/**
 * supervisors-by-year.php – AJAX API: supervisors registered in a given academic year.
 *
 * GET params (one required):
 *   ?year=YYYY-YYYY   – academic year label (e.g. ?year=2025-2026)
 *   ?year_id=N        – numeric academic_years.id
 *
 * Response envelope:
 *   { success: true, data: [...], meta: { academic_year, total_supervisors, total_students } }
 *   { success: false, error: "..." }
 *
 * Each data object:
 *   { id, username, email, profile_pic, student_count, evaluation_count }
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth.php';

// ── Auth gate ────────────────────────────────────────────────────
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── Resolve academic year ────────────────────────────────────────
$year_label = trim($_GET['year'] ?? '');
$year_id    = isset($_GET['year_id']) ? (int) $_GET['year_id'] : 0;

try {
    if ($year_id > 0) {
        $ay_stmt = $pdo->prepare("SELECT id, year_label FROM academic_years WHERE id = ?");
        $ay_stmt->execute([$year_id]);
        $ay = $ay_stmt->fetch();
        if (!$ay) {
            echo json_encode(['success' => false, 'error' => 'Academic year not found.']);
            exit;
        }
        $resolved_id    = (int) $ay['id'];
        $resolved_label = $ay['year_label'];
    } elseif (!empty($year_label) && preg_match('/^\d{4}-\d{4}$/', $year_label)) {
        $ay_stmt = $pdo->prepare("SELECT id, year_label FROM academic_years WHERE year_label = ?");
        $ay_stmt->execute([$year_label]);
        $ay = $ay_stmt->fetch();
        if ($ay) {
            $resolved_id    = (int) $ay['id'];
            $resolved_label = $ay['year_label'];
        } else {
            $resolved_id    = null;
            $resolved_label = $year_label;
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Provide ?year=YYYY-YYYY or ?year_id=N.']);
        exit;
    }

    // ── Fetch supervisors from users table ─────────────────────────
    if ($resolved_id !== null) {
        $sup_stmt = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.profile_pic
            FROM users u
            WHERE u.role = 'supervisor'
              AND u.academic_year_id = ?
              AND u.status = 'Active'
            ORDER BY u.username ASC
        ");
        $sup_stmt->execute([$resolved_id]);
    } else {
        $sup_stmt = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.profile_pic
            FROM users u
            WHERE u.role = 'supervisor'
              AND u.academic_year = ?
              AND u.status = 'Active'
            ORDER BY u.username ASC
        ");
        $sup_stmt->execute([$resolved_label]);
    }

    $supervisors = $sup_stmt->fetchAll();

    // ── Enrich: student count & evaluation count per supervisor ────
    if (!empty($supervisors)) {
        $sup_ids = array_column($supervisors, 'id');
        $placeholders = implode(',', array_fill(0, count($sup_ids), '?'));

        // Students assigned to each supervisor (via student_profiles)
        $sc_stmt = $pdo->prepare("
            SELECT sp.supervisor_id, COUNT(*) AS cnt
            FROM student_profiles sp
            JOIN users u ON u.id = sp.user_id
            WHERE sp.supervisor_id IN ($placeholders)
              AND u.status = 'Active'
            GROUP BY sp.supervisor_id
        ");
        $sc_stmt->execute($sup_ids);
        $student_counts = $sc_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Weekly evaluations completed by each supervisor
        $ev_stmt = $pdo->prepare("
            SELECT supervisor_id, COUNT(*) AS cnt
            FROM supervisor_weekly_evaluations
            WHERE supervisor_id IN ($placeholders)
            GROUP BY supervisor_id
        ");
        $ev_stmt->execute($sup_ids);
        $eval_counts = $ev_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($supervisors as &$sup) {
            $sid = (int) $sup['id'];
            $sup['student_count']    = (int) ($student_counts[$sid] ?? 0);
            $sup['evaluation_count'] = (int) ($eval_counts[$sid] ?? 0);
        }
        unset($sup);
    }

    // ── Aggregate meta ─────────────────────────────────────────────
    $total_students = 0;
    foreach ($supervisors as $s) {
        $total_students += $s['student_count'];
    }

    echo json_encode([
        'success' => true,
        'data'    => $supervisors,
        'meta'    => [
            'academic_year'     => $resolved_label,
            'total_supervisors' => count($supervisors),
            'total_students'    => $total_students,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error: ' . $e->getMessage(),
    ]);
}
