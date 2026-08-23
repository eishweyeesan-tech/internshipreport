<?php
require_once __DIR__ . '/../auth.php';
require_role('admin');
header('Location: admin-dashboard.php');
exit;
