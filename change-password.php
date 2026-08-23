<?php
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/includes/security_helper.php';

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
    } elseif ($pw_err = validate_strong_password($new_password)) {
        $error = $pw_err;
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } else {
        $db = $mysqli ?? $conn;
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $uid = (int) $_SESSION['user_id'];
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;

        if (!$user || !password_verify($current_password, $user['password'])) {
            $error = 'Current password is incorrect.';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $db->prepare("UPDATE users SET password = ?, is_first_login = 0 WHERE id = ?");
            $update->bind_param("si", $hashed, $uid);
            $update->execute();

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
    <title>Change Password - InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 'inter': ['Inter', 'sans-serif'] },
                    fontSize: {
                    'micro': '0.5rem',
                    'caption': '0.6875rem',
                    'label': '0.8125rem',
                    'subtitle': '0.9375rem',
                    'body': '1rem',
                },
                }
            }
        }
    </script>
</head>
<body class="bg-slate-50 font-inter min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="bg-gradient-to-br from-teal-700 to-emerald-800 px-6 py-5 text-center">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <h2 class="text-lg font-bold text-white">Change Your Password</h2>
                <p class="text-teal-100 text-xs mt-1">You must set a secure password before continuing.</p>
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
                        <div class="relative">
                            <input type="password" id="cp_current_password" name="current_password" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-3 pr-9 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-teal-600 transition"
                                   placeholder="Enter current password">
                            <button type="button" onclick="togglePasswordVisibility('cp_current_password', this)" title="Show password" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                <svg class="eye-slash w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                                <svg class="eye-open w-4 h-4 text-teal-600 transition-colors hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-bold text-slate-500 mb-1">New Password</label>
                        <div class="relative">
                            <input type="password" id="cp_new_password" name="new_password" required minlength="6"
                                   class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-3 pr-9 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-teal-600 transition"
                                   placeholder="Enter new password">
                            <button type="button" onclick="togglePasswordVisibility('cp_new_password', this)" title="Show password" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                <svg class="eye-slash w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                                <svg class="eye-open w-4 h-4 text-teal-600 transition-colors hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </button>
                        </div>
                        <div class="mt-2 p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-[11px] text-slate-500 space-y-1 font-medium">
                            <p class="font-bold text-slate-700">Password Requirements:</p>
                            <p>• At least 6 characters</p>
                            <p>• Uppercase (A-Z) and Lowercase (a-z)</p>
                            <p>• Number (0-9) and Symbol (@, #, $, !, %, etc.)</p>
                        </div>
                    </div>
                    <div class="mb-5">
                        <label class="block text-sm font-bold text-slate-500 mb-1">Confirm New Password</label>
                        <div class="relative">
                            <input type="password" id="cp_confirm_password" name="confirm_password" required
                                   class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-3 pr-9 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-teal-600 transition"
                                   placeholder="Re-enter new password">
                            <button type="button" onclick="togglePasswordVisibility('cp_confirm_password', this)" title="Show password" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                <svg class="eye-slash w-4 h-4 text-slate-400 hover:text-slate-600 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                                <svg class="eye-open w-4 h-4 text-teal-600 transition-colors hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <button type="submit"
                            class="w-full bg-gradient-to-r from-teal-600 to-emerald-700 hover:from-teal-700 hover:to-emerald-800 text-white font-bold text-xs rounded-xl shadow-lg shadow-teal-600/30 transition-all duration-200 cursor-pointer py-2.5">
                        Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>
    <script>
        function togglePasswordVisibility(inputId, btn) {
            var input = document.getElementById(inputId);
            if (!input) return;
            var eyeSlash = btn.querySelector('.eye-slash');
            var eyeOpen = btn.querySelector('.eye-open');
            if (input.type === 'password') {
                input.type = 'text';
                if (eyeSlash) eyeSlash.classList.add('hidden');
                if (eyeOpen) eyeOpen.classList.remove('hidden');
                btn.setAttribute('title', 'Hide password');
            } else {
                input.type = 'password';
                if (eyeOpen) eyeOpen.classList.add('hidden');
                if (eyeSlash) eyeSlash.classList.remove('hidden');
                btn.setAttribute('title', 'Show password');
            }
        }
    </script>
</body>
</html>
