<?php
require_once __DIR__ . '/../auth.php';
require_role('student');
header('Location: student-dashboard.php');
exit;
