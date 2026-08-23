<?php
require_once __DIR__ . '/../auth.php';
require_role('instructor');
header('Location: instructor-dashboard.php');
exit;
