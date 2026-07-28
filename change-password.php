<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } elseif (strlen($new_password) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current_password, $user['password'])) {
            $error = 'Current password is incorrect.';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE users SET password = :password, is_first_login = 0 WHERE id = :id");
            $update->execute([':password' => $hashed, ':id' => $_SESSION['user_id']]);

            $_SESSION['is_first_login'] = false;

            switch ($_SESSION['role']) {
                case 'admin':
                    header('Location: admin/admin-dashboard.php');
                    break;
                case 'student':
                    header('Location: student/student-dashboard.php');
                    break;
                case 'supervisor':
                    header('Location: supervisor/supervisor-dashboard.php');
                    break;
                default:
                    header('Location: dashboard.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Internship Report System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 'inter': ['Inter', 'sans-serif'] },
                }
            }
        }
    </script>
</head>
<body class="bg-slate-50 font-inter min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="bg-gradient-to-br from-indigo-600 to-purple-600 px-6 py-5 text-center">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <h2 class="text-lg font-bold text-white">Change Your Password</h2>
                <p class="text-indigo-100 text-xs mt-1">You must change your password before continuing.</p>
            </div>

            <div class="px-6 py-5">
                <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 text-xs font-semibold px-4 py-3 rounded-xl mb-4">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <?php if ($message): ?>
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl mb-4">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="block text-sm font-bold text-slate-500 mb-1">Current Password</label>
                        <input type="password" name="current_password" required
                               class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-blue-500 transition"
                               placeholder="Enter current password">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-bold text-slate-500 mb-1">New Password</label>
                        <input type="password" name="new_password" required minlength="8"
                               class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-blue-500 transition"
                               placeholder="Enter new password">
                        <p class="text-sm text-slate-400 mt-1">At least 8 characters.</p>
                    </div>
                    <div class="mb-5">
                        <label class="block text-sm font-bold text-slate-500 mb-1">Confirm New Password</label>
                        <input type="password" name="confirm_password" required
                               class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-blue-500 transition"
                               placeholder="Re-enter new password">
                    </div>
                    <button type="submit"
                            class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer py-2.5">
                        Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
