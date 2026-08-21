<?php
/**
 * Forwarding script for backward compatibility.
 * Redirects legacy supervisor-review.php links to the unified view-student-dashboard.php.
 */
require_once __DIR__ . '/../auth.php';

$student_id = (int) ($_GET['student_id'] ?? $_GET['id'] ?? $_GET['uid'] ?? 0);
$week       = (int) ($_GET['week'] ?? 0);

if ($student_id > 0) {
    $redirect_url = 'view-student-dashboard.php?id=' . $student_id . ($week > 0 ? '&week=' . $week : '');
    header('Location: ' . $redirect_url);
    exit;
}

header('Location: supervisor-dashboard.php');
exit;
