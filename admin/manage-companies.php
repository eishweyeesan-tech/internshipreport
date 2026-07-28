<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';

if ($_SESSION['role'] !== 'admin') {
    header('Location: ../dashboard.php');
    exit;
}

$admin_name = $_SESSION['username'];
$admin_id   = $_SESSION['user_id'];
$msg = '';
$err = '';

// ── Add Company ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company'])) {
    $company_name  = trim($_POST['company_name'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $website       = trim($_POST['website'] ?? '');

    if (empty($company_name)) {
        $err = 'Company name is required.';
    } else {
        $check = $pdo->prepare("SELECT id FROM companies WHERE company_name = ?");
        $check->execute([$company_name]);
        if ($check->fetch()) {
            $err = 'This company already exists.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO companies (company_name, address, contact_person, contact_email, contact_phone, website) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$company_name, $address, $contact_person, $contact_email, $contact_phone, $website]);
            $msg = "Company \"{$company_name}\" added successfully.";
        }
    }
}

// ── Edit Company ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_company'])) {
    $cid           = (int) ($_POST['company_id'] ?? 0);
    $company_name  = trim($_POST['company_name'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $website       = trim($_POST['website'] ?? '');

    if ($cid > 0 && !empty($company_name)) {
        $check = $pdo->prepare("SELECT id FROM companies WHERE company_name = ? AND id != ?");
        $check->execute([$company_name, $cid]);
        if ($check->fetch()) {
            $err = 'Another company with this name already exists.';
        } else {
            $stmt = $pdo->prepare("UPDATE companies SET company_name=?, address=?, contact_person=?, contact_email=?, contact_phone=?, website=? WHERE id=?");
            $stmt->execute([$company_name, $address, $contact_person, $contact_email, $contact_phone, $website, $cid]);
            $msg = "Company updated successfully.";
        }
    }
}

// ── Delete Company ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_company'])) {
    $cid = (int) ($_POST['company_id'] ?? 0);
    if ($cid > 0) {
        $pdo->prepare("DELETE FROM companies WHERE id = ?")->execute([$cid]);
        $msg = 'Company deleted.';
    }
}

// ── Fetch companies ──────────────────────────────────────────────
$companies = $pdo->query("SELECT * FROM companies ORDER BY company_name ASC")->fetchAll();

// ── Check if editing a specific company ──────────────────────────
$edit_company = null;
if (isset($_GET['edit'])) {
    $eid = (int) $_GET['edit'];
    foreach ($companies as $c) {
        if ($c['id'] === $eid) {
            $edit_company = $c;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Companies – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 bg-white border-r border-slate-200 flex flex-col shrink-0">
        <div class="h-14 flex items-center px-5 border-b border-slate-100">
            <span class="text-sm font-black text-slate-800 tracking-tight">📋 InternReport</span>
            <span class="ml-2 text-[9px] font-bold text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded">ADMIN</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-2">
            <a href="admin-dashboard.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>📊</span> Dashboard
            </a>
            <a href="admin-dashboard.php?tab=students" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>🎓</span> Add Student
            </a>
            <a href="admin-dashboard.php?tab=supervisors" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>👨‍🏫</span> Add Supervisor
            </a>
            <a href="admin-dashboard.php?tab=manage" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>👥</span> Manage Users
            </a>
            <a href="manage-companies.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold bg-indigo-50 text-indigo-600 transition" style="border-right:3px solid #4f46e5">
                <span>🏢</span> Manage Companies
            </a>
            <a href="admin-dashboard.php?tab=announcements" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>📢</span> Announcements
            </a>
            <a href="admin-dashboard.php?tab=archive" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>📦</span> Batch Archive
            </a>
            <a href="admin-dashboard.php?tab=history" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>📜</span> Student History
            </a>
            <a href="admin-profile.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                <span>👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-slate-100">
            <a href="../logout.php" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-red-500 hover:bg-red-50 rounded-lg transition">🚪 Logout</a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <header class="h-14 bg-white border-b border-slate-200 flex items-center justify-between px-6 shrink-0">
            <h1 class="text-sm font-bold text-slate-700">Manage Companies</h1>
            <div class="flex items-center gap-2 text-xs text-slate-400">
                <span class="w-7 h-7 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xs font-bold">A</span>
                <?= htmlspecialchars($admin_name) ?>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6">
            <div class="max-w-6xl mx-auto space-y-6">

                <?php if ($msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>✅</span> <?= htmlspecialchars($msg) ?>
                </div>
                <?php endif; ?>
                <?php if ($err): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 text-xs font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>❌</span> <?= htmlspecialchars($err) ?>
                </div>
                <?php endif; ?>

                <!-- ════ ADD / EDIT COMPANY FORM ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-blue-50 text-blue-600 rounded">🏢</span>
                            <?= $edit_company ? 'Edit Company' : 'Register New Partner Company' ?>
                        </h2>
                        <?php if ($edit_company): ?>
                        <a href="manage-companies.php" class="text-[10px] font-bold text-slate-400 hover:text-slate-600 transition">✕ Cancel</a>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="p-6">
                        <?php if ($edit_company): ?>
                        <input type="hidden" name="edit_company" value="1">
                        <input type="hidden" name="company_id" value="<?= $edit_company['id'] ?>">
                        <?php else: ?>
                        <input type="hidden" name="add_company" value="1">
                        <?php endif; ?>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1.5">Company Name <span class="text-red-400">*</span></label>
                                <input type="text" name="company_name" required value="<?= htmlspecialchars($edit_company['company_name'] ?? '') ?>" placeholder="e.g. Tech Corp Myanmar" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1.5">Website</label>
                                <input type="url" name="website" value="<?= htmlspecialchars($edit_company['website'] ?? '') ?>" placeholder="https://example.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1.5">Contact Person</label>
                                <input type="text" name="contact_person" value="<?= htmlspecialchars($edit_company['contact_person'] ?? '') ?>" placeholder="Full name" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1.5">Contact Email</label>
                                <input type="email" name="contact_email" value="<?= htmlspecialchars($edit_company['contact_email'] ?? '') ?>" placeholder="contact@company.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1.5">Contact Phone</label>
                                <input type="text" name="contact_phone" value="<?= htmlspecialchars($edit_company['contact_phone'] ?? '') ?>" placeholder="+959 123 456 789" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div class="md:col-span-2 lg:col-span-3">
                                <label class="block text-[10px] font-bold text-slate-500 mb-1.5">Address</label>
                                <textarea name="address" rows="2" placeholder="Street, city, region" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition"><?= htmlspecialchars($edit_company['address'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center gap-3">
                            <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">
                                <?= $edit_company ? '💾 Update Company' : '➕ Add Partner Company' ?>
                            </button>
                            <?php if ($edit_company): ?>
                            <a href="manage-companies.php" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs rounded-xl transition">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- ════ COMPANIES TABLE ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider">All Registered Companies</h2>
                        <span class="text-[10px] text-slate-400"><?= count($companies) ?> total</span>
                    </div>
                    <?php if (!empty($companies)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-[10px]">
                                    <th class="px-5 py-3 text-left w-10">#</th>
                                    <th class="px-5 py-3 text-left">Company</th>
                                    <th class="px-5 py-3 text-left">Contact Person</th>
                                    <th class="px-5 py-3 text-left">Email</th>
                                    <th class="px-5 py-3 text-left">Phone</th>
                                    <th class="px-5 py-3 text-left w-28">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($companies as $i => $c): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-5 py-3 text-slate-400 font-mono"><?= $i + 1 ?></td>
                                    <td class="px-5 py-3">
                                        <div class="flex flex-col">
                                            <span class="font-semibold text-slate-700"><?= htmlspecialchars($c['company_name']) ?></span>
                                            <?php if (!empty($c['address'])): ?>
                                            <span class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($c['address']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($c['website'])): ?>
                                            <a href="<?= htmlspecialchars($c['website']) ?>" target="_blank" class="text-[10px] text-blue-500 hover:underline mt-0.5"><?= htmlspecialchars($c['website']) ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($c['contact_person'] ?: '—') ?></td>
                                    <td class="px-5 py-3">
                                        <?php if (!empty($c['contact_email'])): ?>
                                        <a href="mailto:<?= htmlspecialchars($c['contact_email']) ?>" class="text-blue-500 hover:underline"><?= htmlspecialchars($c['contact_email']) ?></a>
                                        <?php else: ?>
                                        <span class="text-slate-300">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($c['contact_phone'] ?: '—') ?></td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-1.5">
                                            <a href="?edit=<?= $c['id'] ?>" class="px-2.5 py-1.5 bg-amber-50 text-amber-600 text-[10px] font-bold rounded-lg hover:bg-amber-100 transition">✏️ Edit</a>
                                            <form method="POST" onsubmit="return confirm('Delete company &quot;<?= htmlspecialchars($c['company_name'], ENT_QUOTES) ?>&quot;?')">
                                                <input type="hidden" name="delete_company" value="1">
                                                <input type="hidden" name="company_id" value="<?= $c['id'] ?>">
                                                <button type="submit" class="px-2.5 py-1.5 bg-red-50 text-red-600 text-[10px] font-bold rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-6 py-3 border-t border-slate-100 bg-slate-50">
                        <p class="text-[10px] text-slate-400">Showing <?= count($companies) ?> company(ies).</p>
                    </div>
                    <?php else: ?>
                    <div class="p-10 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">🏢</div>
                        <p class="text-sm font-semibold text-slate-500 mb-1">No companies registered yet</p>
                        <p class="text-xs text-slate-400">Use the form above to add your first partner company.</p>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</div>

</body>
</html>
