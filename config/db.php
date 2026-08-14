<?php
/**
 * Single Canonical MySQLi Database Connection (Object-Oriented Style)
 * InternReport Management System
 */

$host     = 'localhost';
$port     = 3306;
$dbname   = 'intern_report_db';
$username = 'root';
$password = 'root';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli($host, $username, $password, $dbname, (int)$port);
    $mysqli->set_charset('utf8mb4');
} catch (Exception $e) {
    die("Database Connection Error: " . $e->getMessage());
}

$conn = $mysqli;

