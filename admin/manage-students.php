<?php
/**
 * Manage Students Entry Point
 * File: admin/manage-students.php
 */
if (!isset($_GET['tab'])) {
    $_GET['tab'] = 'students';
}
require_once __DIR__ . '/admin-dashboard.php';
