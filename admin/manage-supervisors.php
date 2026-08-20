<?php
/**
 * Manage Supervisors Entry Point
 * File: admin/manage-supervisors.php
 */
if (!isset($_GET['tab'])) {
    $_GET['tab'] = 'supervisors';
}
require_once __DIR__ . '/admin-dashboard.php';
