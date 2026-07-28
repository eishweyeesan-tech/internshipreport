<?php
// Compact profile widget for sidebar inclusion.
// Expects `$student_name`, `$student_roll`, `$profile_pic`, `$role`, and optionally `$profile_row` to be in scope.
if (!isset($student_name)) {
    // Fallback: try to load minimal profile
    require_once __DIR__ . '/../auth.php';
    $user_id = $_SESSION['user_id'];
    $conn = $conn ?? new mysqli('localhost', 'root', 'root', 'intern_report_db');
    $esc = $conn->real_escape_string($user_id);
    $pr = $conn->query("SELECT sp.full_name, sp.student_roll, u.profile_pic FROM student_profiles sp LEFT JOIN users u ON u.id = sp.user_id WHERE sp.user_id = {$esc}");
    $prow = $pr ? $pr->fetch_assoc() : null;
    $student_name = $prow['full_name'] ?? $_SESSION['username'];
    $student_roll = $prow['student_roll'] ?? '';
    $profile_pic = $prow['profile_pic'] ?? '';
    $role = $_SESSION['role'] ?? 'student';
}
?>
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-full overflow-hidden bg-indigo-100 flex items-center justify-center text-xl font-bold text-indigo-600">
            <?php if (!empty($profile_pic)): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-12 h-12 object-cover">
            <?php else: ?>
                <?= strtoupper(substr($student_name, 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div class="min-w-0">
            <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($student_name) ?></p>
            <?php if (!empty($student_roll)): ?>
                <p class="text-xs text-slate-400 font-mono"><?= htmlspecialchars($student_roll) ?></p>
            <?php endif; ?>
            <p class="text-xs text-slate-400 mt-1 capitalize"><?= htmlspecialchars($role) ?></p>
        </div>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-2">
        <a href="profile.php" class="block text-center text-xs font-bold px-2 py-2 bg-slate-50 border border-slate-100 rounded">View Profile</a>
        <a href="log-history.php" class="block text-center text-xs font-bold px-2 py-2 bg-slate-50 border border-slate-100 rounded">Logs</a>
    </div>
</div>
