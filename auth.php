<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['is_first_login']) {
    header('Location: change-password.php');
    exit;
}

// Fetch profile data for header avatar dropdown
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', 'root', 'intern_report_db');
    if (!$conn->connect_error) $conn->set_charset('utf8mb4');
}

$_SESSION['profile_pic'] = $_SESSION['profile_pic'] ?? null;
$_SESSION['last_login_at'] = $_SESSION['last_login_at'] ?? null;

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        $_auth_r = $conn->query("SELECT profile_pic, github_link, linkedin_link, portfolio_link, last_login_at FROM users WHERE id = " . (int)$_SESSION['user_id']);
        if ($_auth_r && $_auth_r->num_rows > 0) {
            $_auth_user = $_auth_r->fetch_assoc();
            $_SESSION['profile_pic'] = $_auth_user['profile_pic'] ?? null;
            $_SESSION['last_login_at'] = $_auth_user['last_login_at'] ?? null;
        }
    } catch (mysqli_sql_exception $e) {
        $_auth_r = $conn->query("SELECT profile_pic FROM users WHERE id = " . (int)$_SESSION['user_id']);
        if ($_auth_r && $_auth_r->num_rows > 0) {
            $_auth_user = $_auth_r->fetch_assoc();
            $_SESSION['profile_pic'] = $_auth_user['profile_pic'] ?? null;
        }
    }
}
