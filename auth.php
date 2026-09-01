<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Prevent caching of authenticated pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// 2. Redirect unauthenticated guests to login.php
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // Allow magic link token access on print_report.php
    if (strpos($script, 'print_report.php') !== false && !empty($_GET['token'])) {
        // Guest instructor viewing via magic link token
    } else {
        $in_sub = (strpos($script, '/admin/') !== false || strpos($script, '/instructor/') !== false || strpos($script, '/supervisor/') !== false || strpos($script, '/student/') !== false);
        $login_path = $in_sub ? '../login.php' : 'login.php';
        header('Location: ' . $login_path);
        exit;
    }
}

// 3. First-time login redirect to change password
if (!empty($_SESSION['is_first_login'])) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $in_sub = (strpos($script, '/admin/') !== false || strpos($script, '/instructor/') !== false || strpos($script, '/supervisor/') !== false || strpos($script, '/student/') !== false);
    $change_pw_path = $in_sub ? '../change-password.php' : 'change-password.php';
    header('Location: ' . $change_pw_path);
    exit;
}

// 4. Role-based redirect helper
if (!function_exists('get_user_dashboard_url')) {
    function get_user_dashboard_url($role) {
        switch ($role) {
            case 'admin':      return 'admin/admin-dashboard.php';
            case 'supervisor': return 'supervisor/supervisor-dashboard.php';
            case 'student':    return 'student/student-dashboard.php';
            default:           return 'login.php';
        }
    }
}

if (!function_exists('require_role')) {
    function require_role($allowed_roles) {
        if (!is_array($allowed_roles)) {
            $allowed_roles = [$allowed_roles];
        }
        $user_role = $_SESSION['role'] ?? '';
        if (!in_array($user_role, $allowed_roles, true)) {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $in_sub = (strpos($script, '/admin/') !== false || strpos($script, '/supervisor/') !== false || strpos($script, '/student/') !== false);
            $prefix = $in_sub ? '../' : '';
            $target = $prefix . get_user_dashboard_url($user_role);
            header('Location: ' . $target);
            exit;
        }
    }
}

// 5. Automatic Directory-Level RBAC Enforcement
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
if (strpos($script_name, '/admin/') !== false) {
    require_role('admin');
} elseif (strpos($script_name, '/supervisor/') !== false) {
    require_role('supervisor');
} elseif (strpos($script_name, '/student/') !== false) {
    if (strpos($script_name, 'print_report.php') !== false) {
        if (isset($_SESSION['user_id'])) {
            require_role(['student', 'supervisor', 'admin']);
        }
    } else {
        require_role('student');
    }
}

// Load DB connection & update session user data
require_once __DIR__ . '/config/db.php';

$_SESSION['profile_pic'] = $_SESSION['profile_pic'] ?? null;
$_SESSION['last_login_at'] = $_SESSION['last_login_at'] ?? null;

$db = $mysqli ?? $conn ?? null;
if (isset($_SESSION['user_id']) && $db) {
    try {
        $_auth_stmt = $db->prepare("SELECT profile_pic, last_login_at, status, role FROM users WHERE id = ? LIMIT 1");
        if ($_auth_stmt) {
            $_user_id_int = (int)$_SESSION['user_id'];
            $_auth_stmt->bind_param("i", $_user_id_int);
            $_auth_stmt->execute();
            $_auth_res = $_auth_stmt->get_result();
            $_auth_user = $_auth_res ? $_auth_res->fetch_assoc() : null;
            if ($_auth_user) {
                $_SESSION['profile_pic'] = $_auth_user['profile_pic'] ?? null;
                $_SESSION['last_login_at'] = $_auth_user['last_login_at'] ?? null;

                // Invalidate session immediately if supervisor account is set to Inactive
                if (($_auth_user['role'] ?? '') === 'supervisor' && strcasecmp(trim((string)($_auth_user['status'] ?? '')), 'Inactive') === 0) {
                    $_auth_stmt->close();
                    session_unset();
                    session_destroy();
                    $script = $_SERVER['SCRIPT_NAME'] ?? '';
                    $in_sub = (strpos($script, '/admin/') !== false || strpos($script, '/instructor/') !== false || strpos($script, '/supervisor/') !== false || strpos($script, '/student/') !== false);
                    $login_path = $in_sub ? '../login.php?error=inactive' : 'login.php?error=inactive';
                    header('Location: ' . $login_path);
                    exit;
                }
            }
            $_auth_stmt->close();
        }
    } catch (Throwable $e) {
        // Fallback gracefully
    }
}
