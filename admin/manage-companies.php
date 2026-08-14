<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/phone_validation.php';

if ($_SESSION['role'] !== 'admin') {
    header('Location: ../dashboard.php');
    exit;
}

$admin_name = $_SESSION['username'];
$admin_id   = (int) $_SESSION['user_id'];
$db         = $mysqli ?? $conn;
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
    } elseif (($phone_err = phone_validation_error($contact_phone)) !== null) {
        $err = $phone_err;
    } else {
        $contact_phone = normalize_phone($contact_phone);
        $check = $db->prepare("SELECT id FROM companies WHERE company_name = ?");
        $check->bind_param("s", $company_name);
        $check->execute();
        $res = $check->get_result();
        if ($res && $res->fetch_row()) {
            $err = 'This company already exists.';
        } else {
            $stmt = $db->prepare("INSERT INTO companies (company_name, address, contact_person, contact_email, contact_phone, website) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $company_name, $address, $contact_person, $contact_email, $contact_phone, $website);
            $stmt->execute();
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

    if ($cid <= 0) {
        $err = 'Invalid company.';
    } elseif (empty($company_name)) {
        $err = 'Company name is required.';
    } elseif (($phone_err = phone_validation_error($contact_phone)) !== null) {
        $err = $phone_err;
    } else {
        $contact_phone = normalize_phone($contact_phone);
        $check = $db->prepare("SELECT id FROM companies WHERE company_name = ? AND id != ?");
        $check->bind_param("si", $company_name, $cid);
        $check->execute();
        $res = $check->get_result();
        if ($res && $res->fetch_row()) {
            $err = 'Another company with this name already exists.';
        } else {
            $stmt = $db->prepare("UPDATE companies SET company_name=?, address=?, contact_person=?, contact_email=?, contact_phone=?, website=? WHERE id=?");
            $stmt->bind_param("ssssssi", $company_name, $address, $contact_person, $contact_email, $contact_phone, $website, $cid);
            $stmt->execute();
            $msg = "Company updated successfully.";
        }
    }
}

// ── Delete Company ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_company'])) {
    $cid = (int) ($_POST['company_id'] ?? 0);
    if ($cid > 0) {
        $del = $db->prepare("DELETE FROM companies WHERE id = ?");
        $del->bind_param("i", $cid);
        $del->execute();
        $msg = 'Company deleted.';
    }
}

// ── Fetch companies ──────────────────────────────────────────────
$res = $db->query("SELECT * FROM companies ORDER BY company_name ASC");
$companies = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

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
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    'inter': ['Inter', 'sans-serif'],
                },
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
<body class="bg-slate-50 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <?php $activePage = 'companies'; ?>
    <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <?php $pageTitle = 'Companies'; require_once __DIR__ . '/../includes/topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6" style="scrollbar-gutter: stable;">
            <div class="max-w-6xl mx-auto space-y-6">

                <?php if ($msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>✅</span> <?= htmlspecialchars($msg) ?>
                </div>
                <?php endif; ?>
                <?php if ($err): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>❌</span> <?= htmlspecialchars($err) ?>
                </div>
                <?php endif; ?>

                <!-- ════ ADD / EDIT COMPANY FORM ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-blue-50 text-blue-600 rounded">🏢</span>
                            <?= $edit_company ? 'Edit Company' : 'Register New Partner Company' ?>
                        </h2>
                        <?php if ($edit_company): ?>
                        <a href="manage-companies.php" class="text-sm font-bold text-slate-400 hover:text-slate-600 transition">✕ Cancel</a>
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
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Company Name <span class="text-red-400">*</span></label>
                                <input type="text" name="company_name" required value="<?= htmlspecialchars($edit_company['company_name'] ?? '') ?>" placeholder="e.g. Tech Corp Myanmar" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Website</label>
                                <input type="url" name="website" value="<?= htmlspecialchars($edit_company['website'] ?? '') ?>" placeholder="https://example.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Contact Person</label>
                                <input type="text" name="contact_person" value="<?= htmlspecialchars($edit_company['contact_person'] ?? '') ?>" placeholder="Full name" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Contact Email</label>
                                <input type="email" name="contact_email" value="<?= htmlspecialchars($edit_company['contact_email'] ?? '') ?>" placeholder="contact@company.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Contact Phone</label>
                                <input type="text" name="contact_phone" value="<?= htmlspecialchars($edit_company['contact_phone'] ?? '') ?>" placeholder="+959 123 456 789" pattern="[0-9+ .()\/-]{6,30}" maxlength="30" title="Enter a valid Myanmar phone number, e.g. 09-123-456-789 or +959 123 456 789" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div class="md:col-span-2 lg:col-span-3">
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Address</label>
                                <textarea name="address" rows="2" placeholder="Street, city, region" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-blue-500 transition"><?= htmlspecialchars($edit_company['address'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center gap-3">
                            <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded-xl shadow-sm transition cursor-pointer">
                                <?= $edit_company ? '💾 Update Company' : '➕ Add Partner Company' ?>
                            </button>
                            <?php if ($edit_company): ?>
                            <a href="manage-companies.php" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-sm rounded-xl transition">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- ════ COMPANIES TABLE ════ -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider">All Registered Companies</h2>
                        <span class="text-sm text-slate-400"><?= count($companies) ?> total</span>
                    </div>
                    <?php if (!empty($companies)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-sm">
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
                                            <span class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($c['address']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($c['website'])): ?>
                                            <a href="<?= htmlspecialchars($c['website']) ?>" target="_blank" class="text-sm text-blue-500 hover:underline mt-0.5"><?= htmlspecialchars($c['website']) ?></a>
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
                                            <a href="?edit=<?= $c['id'] ?>" class="px-2.5 py-1.5 bg-amber-50 text-amber-600 text-sm font-bold rounded-lg hover:bg-amber-100 transition">✏️ Edit</a>
                                            <form method="POST" onsubmit="return confirm('Delete company &quot;<?= htmlspecialchars($c['company_name'], ENT_QUOTES) ?>&quot;?')">
                                                <input type="hidden" name="delete_company" value="1">
                                                <input type="hidden" name="company_id" value="<?= $c['id'] ?>">
                                                <button type="submit" class="px-2.5 py-1.5 bg-red-50 text-red-600 text-sm font-bold rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-6 py-3 border-t border-slate-100 bg-slate-50">
                        <p class="text-sm text-slate-400">Showing <?= count($companies) ?> company(ies).</p>
                    </div>
                    <?php else: ?>
                    <div class="p-10 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">🏢</div>
                        <p class="text-sm font-semibold text-slate-500 mb-1">No companies registered yet</p>
                        <p class="text-sm text-slate-400">Use the form above to add your first partner company.</p>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</div>

</body>
</html>
