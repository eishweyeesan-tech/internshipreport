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

require_once __DIR__ . '/config/db.php';

$_SESSION['profile_pic'] = $_SESSION['profile_pic'] ?? null;
$_SESSION['last_login_at'] = $_SESSION['last_login_at'] ?? null;

$db = $mysqli ?? $conn ?? null;
if (isset($_SESSION['user_id']) && $db) {
    try {
        $_auth_stmt = $db->prepare("SELECT profile_pic, github_link, linkedin_link, portfolio_link, last_login_at FROM users WHERE id = ?");
        $_user_id_int = (int)$_SESSION['user_id'];
        $_auth_stmt->bind_param("i", $_user_id_int);
        $_auth_stmt->execute();
        $_auth_res = $_auth_stmt->get_result();
        $_auth_user = $_auth_res ? $_auth_res->fetch_assoc() : null;
        if ($_auth_user) {
            $_SESSION['profile_pic'] = $_auth_user['profile_pic'] ?? null;
            $_SESSION['last_login_at'] = $_auth_user['last_login_at'] ?? null;
        }
    } catch (Throwable $e) {
        // Fallback gracefully
    }
}
