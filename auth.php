<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['is_first_login']) {
    header('Location: change-password.php');
    exit;
}

// Fetch profile data for header avatar dropdown (safe fallback if columns don't exist yet)
require_once __DIR__ . '/config/database.php';
$_SESSION['profile_pic'] = $_SESSION['profile_pic'] ?? null;
$_SESSION['last_login_at'] = $_SESSION['last_login_at'] ?? null;

try {
    $_auth_stmt = $pdo->prepare("SELECT profile_pic, github_link, linkedin_link, portfolio_link, last_login_at FROM users WHERE id = ?");
    $_auth_stmt->execute([$_SESSION['user_id']]);
    $_auth_user = $_auth_stmt->fetch();
    $_SESSION['profile_pic'] = $_auth_user['profile_pic'] ?? null;
    $_SESSION['last_login_at'] = $_auth_user['last_login_at'] ?? null;
} catch (PDOException $e) {
    // Columns not yet added — silently ignore until migration is run
}
