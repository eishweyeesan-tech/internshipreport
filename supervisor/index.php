<?php
require_once __DIR__ . '/../auth.php';
require_role('supervisor');
header('Location: supervisor-dashboard.php');
exit;
