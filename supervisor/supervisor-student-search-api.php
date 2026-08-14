<?php
/**
 * supervisor-student-search-api.php
 *
 * Live AJAX search endpoint for the Supervisor Dashboard search box.
 *
 * Returns ONLY students assigned to the currently logged-in supervisor
 * (scoped via student_profiles.supervisor_id). A supervisor can never see
 * another supervisor's students through this endpoint.
 *
 * Request:  GET ?q=<term>
 * Response: { results: [...], has_more: bool, total: int }
 */

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'supervisor') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$sup_id = (int) $_SESSION['user_id'];
$q      = trim((string) ($_GET['q'] ?? ''));
$db     = $mysqli ?? $conn;

if ($q === '') {
    echo json_encode(['results' => [], 'has_more' => false, 'total' => 0]);
    exit;
}

$limit       = 5;
$fetch_limit = $limit + 1;
$like        = '%' . $q . '%';

$sql = "
    SELECT u.id AS uid, u.username, u.email,
           sp.full_name, sp.student_roll, sp.major, sp.company_name, sp.job_role
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student'
      AND u.status = 'Active'
      AND sp.supervisor_id = ?
      AND (
          sp.full_name LIKE ?
          OR u.username LIKE ?
          OR sp.student_roll LIKE ?
          OR sp.company_name LIKE ?
          OR sp.job_role LIKE ?
          OR u.email LIKE ?
      )
    ORDER BY sp.full_name ASC
    LIMIT ?
";

$stmt = $db->prepare($sql);
$stmt->bind_param("issssssi", $sup_id, $like, $like, $like, $like, $like, $like, $fetch_limit);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$has_more = count($rows) > $limit;
$rows     = array_slice($rows, 0, $limit);

$results = array_map(function ($r) {
    $name = $r['full_name'] ?: $r['username'];
    return [
        'uid'          => (int) $r['uid'],
        'username'     => $r['username'],
        'email'        => $r['email'],
        'full_name'    => $name,
        'initials'     => strtoupper(substr($name, 0, 1)),
        'student_roll' => $r['student_roll'],
        'major'        => $r['major'],
        'company_name' => $r['company_name'],
        'job_role'     => $r['job_role'],
    ];
}, $rows);

echo json_encode([
    'results'  => $results,
    'has_more' => $has_more,
    'total'    => count($results) + ($has_more ? 1 : 0),
]);
