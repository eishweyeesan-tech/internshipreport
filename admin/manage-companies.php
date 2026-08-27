<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/phone_validation.php';

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
        if ((int) $c['id'] === $eid) {
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'sans-serif'],
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

<body class="bg-slate-50 font-inter antialiased">

    <div class="flex h-screen overflow-hidden">

        <?php $activePage = 'companies'; ?>
        <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

        <!-- ─── MAIN ─── -->
        <div class="flex-1 flex flex-col overflow-hidden">

            <!-- Top Bar -->
            <?php $pageTitle = '🏢 Internship Companies';
            require_once __DIR__ . '/../includes/topbar.php'; ?>

            <!-- Content -->
            <main class="flex-1 overflow-y-auto scroll-smooth p-4 lg:p-6" style="scrollbar-gutter: stable;">
                <div class="w-full space-y-6 pb-16">

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

                    <!-- ════ COMPANIES TABLE ════ -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                                    <span class="p-1 bg-teal-50 text-teal-700 rounded">🏢</span> All Registered Companies
                                </h2>
                                <span id="companyCountBadge" class="text-xs font-semibold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-full"><?= count($companies) ?> total</span>
                            </div>
                            <div class="flex items-center gap-3 flex-wrap">
                                <?php if (!empty($companies)): ?>
                                    <div class="relative w-full sm:w-64">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                            <i class="fa-solid fa-magnifying-glass text-xs"></i>
                                        </span>
                                        <input type="text"
                                            id="companySearchInput"
                                            placeholder="Search company name..."
                                            class="w-full bg-slate-50/80 hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl pl-9 pr-8 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200"
                                            autocomplete="off"
                                            spellcheck="false">
                                        <button type="button"
                                            id="clearCompanySearch"
                                            class="hidden absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
                                            title="Clear search"
                                            aria-label="Clear search">
                                            <i class="fa-solid fa-xmark text-xs"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <button type="button"
                                    onclick="openAddCompanyModal()"
                                    class="inline-flex items-center gap-2 px-3.5 py-2 bg-gradient-to-r from-teal-600 to-emerald-700 hover:from-teal-700 hover:to-emerald-800 text-white text-xs font-bold rounded-xl shadow-xs hover:shadow transition-all duration-200 cursor-pointer shrink-0">
                                    <i class="fa-solid fa-plus text-xs"></i>
                                    <span>Add New Company</span>
                                </button>
                            </div>
                        </div>
                        <?php if (!empty($companies)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm" id="companiesTable">
                                    <thead>
                                        <tr class="bg-slate-50 text-slate-500 font-semibold uppercase tracking-wider text-xs border-b border-slate-100">
                                            <th class="px-4 py-3 w-12 text-center">#</th>
                                            <th class="px-4 py-3 min-w-[200px]">Company Name</th>
                                            <th class="px-4 py-3 min-w-[160px]">Contact Person</th>
                                            <th class="px-4 py-3 min-w-[190px]">Email & Phone</th>
                                            <th class="px-4 py-3 min-w-[220px]">Address</th>
                                            <th class="px-4 py-3 w-28 text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($companies as $i => $c): ?>
                                            <tr class="company-row hover:bg-slate-50/80 transition-colors" data-name="<?= htmlspecialchars($c['company_name'], ENT_QUOTES, 'UTF-8') ?>">
                                                <td class="row-number px-4 py-3.5 text-center text-slate-400 font-mono text-xs"><?= $i + 1 ?></td>
                                                <td class="px-4 py-3.5">
                                                    <div class="flex flex-col">
                                                        <div class="flex items-center gap-2">
                                                            <span class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($c['company_name']) ?></span>
                                                        </div>
                                                        <?php if (!empty($c['website'])): ?>
                                                            <a href="<?= htmlspecialchars($c['website']) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-xs font-medium text-teal-600 hover:text-teal-700 hover:underline mt-0.5 w-fit">
                                                                <i class="fa-solid fa-globe text-[11px] text-teal-500"></i>
                                                                <span><?= htmlspecialchars($c['website']) ?></span>
                                                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-slate-400"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-xs text-slate-400 mt-0.5">—</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3.5">
                                                    <?php if (!empty($c['contact_person'])): ?>
                                                        <div class="flex items-center gap-2">
                                                            <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center text-xs font-bold shrink-0">
                                                                <?= strtoupper($c['contact_person'][0]) ?>
                                                            </div>
                                                            <span class="font-medium text-slate-700 text-xs sm:text-sm"><?= htmlspecialchars($c['contact_person']) ?></span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-xs text-slate-400">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3.5">
                                                    <div class="flex flex-col gap-1 text-xs">
                                                        <?php if (!empty($c['contact_email'])): ?>
                                                            <a href="mailto:<?= htmlspecialchars($c['contact_email']) ?>" class="inline-flex items-center gap-1.5 text-slate-600 hover:text-teal-600 transition w-fit">
                                                                <i class="fa-regular fa-envelope text-slate-400 text-[11px]"></i>
                                                                <span class="font-medium"><?= htmlspecialchars($c['contact_email']) ?></span>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if (!empty($c['contact_phone'])): ?>
                                                            <a href="tel:<?= htmlspecialchars($c['contact_phone']) ?>" class="inline-flex items-center gap-1.5 text-slate-600 hover:text-teal-600 transition w-fit">
                                                                <i class="fa-solid fa-phone text-slate-400 text-[11px]"></i>
                                                                <span class="font-mono"><?= htmlspecialchars($c['contact_phone']) ?></span>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if (empty($c['contact_email']) && empty($c['contact_phone'])): ?>
                                                            <span class="text-slate-400">—</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3.5">
                                                    <?php if (!empty($c['address'])): ?>
                                                        <span class="text-xs text-slate-600 leading-relaxed block max-w-xs sm:max-w-sm"><?= nl2br(htmlspecialchars($c['address'])) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-xs text-slate-400">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3.5 text-center">
                                                    <div class="flex items-center justify-center gap-1.5">
                                                        <button type="button"
                                                            onclick="openEditCompanyModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)"
                                                            class="px-2.5 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200/60 text-xs font-bold rounded-lg shadow-xs transition inline-flex items-center gap-1 cursor-pointer"
                                                            title="Edit company">
                                                            <i class="fa-regular fa-pen-to-square"></i>
                                                            <span>Edit</span>
                                                        </button>
                                                        <form method="POST" onsubmit="return confirm('Delete company &quot;<?= htmlspecialchars($c['company_name'], ENT_QUOTES) ?>&quot;?')" class="inline">
                                                            <input type="hidden" name="delete_company" value="1">
                                                            <input type="hidden" name="company_id" value="<?= $c['id'] ?>">
                                                            <button type="submit" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 text-xs font-bold rounded-lg border border-rose-200/60 shadow-xs transition cursor-pointer" title="Delete company">
                                                                <i class="fa-regular fa-trash-can"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr id="noCompanyResultsRow" class="hidden">
                                            <td colspan="6" class="px-6 py-12 text-center text-slate-400">
                                                <div class="flex flex-col items-center justify-center">
                                                    <div class="w-12 h-12 rounded-2xl bg-slate-50 text-slate-400 flex items-center justify-center text-xl mb-2">
                                                        <i class="fa-solid fa-building-circle-xmark text-slate-300"></i>
                                                    </div>
                                                    <p class="text-sm font-semibold text-slate-600 mb-1">No matching companies found</p>
                                                    <p class="text-xs text-slate-400">No company name matches "<span id="searchQueryDisplay" class="font-semibold text-slate-600"></span>"</p>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="px-6 py-3 border-t border-slate-100 bg-slate-50">
                                <p class="text-sm text-slate-400" id="companyFooterCount">Showing <?= count($companies) ?> company(ies).</p>
                            </div>
                        <?php else: ?>
                            <div class="p-12 text-center">
                                <div class="w-16 h-16 rounded-2xl bg-teal-50 text-teal-700 flex items-center justify-center text-3xl mx-auto mb-4 border border-teal-100 shadow-xs">🏢</div>
                                <h3 class="text-sm font-black text-slate-700 mb-1">No Companies Registered Yet</h3>
                                <p class="text-xs text-slate-400 max-w-sm mx-auto mb-5">Add host partner organizations to place students for their internships.</p>
                                <button type="button"
                                    onclick="openAddCompanyModal()"
                                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-700 hover:from-teal-700 hover:to-emerald-800 text-white font-bold text-xs rounded-xl shadow-sm hover:shadow transition-all cursor-pointer">
                                    <i class="fa-solid fa-plus text-xs"></i> <span>Add Partner Company</span>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <!-- ════ ADD / EDIT COMPANY MODAL ════ -->
    <div id="companyModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 sm:p-6 overflow-y-auto hidden" role="dialog" aria-modal="true" aria-labelledby="companyModalTitle">
        <div class="relative w-full max-w-2xl bg-white rounded-2xl border border-slate-200 shadow-2xl overflow-hidden flex flex-col my-auto max-h-[92vh] animate-in fade-in zoom-in-95 duration-150">
            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-teal-50/70 via-white to-emerald-50/70 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-2xl bg-teal-100 text-teal-700 flex items-center justify-center text-lg font-black shrink-0 shadow-2xs">
                        🏢
                    </div>
                    <div class="min-w-0">
                        <h3 id="companyModalTitle" class="text-base font-black text-slate-800">Register New Partner Company</h3>
                        <p id="companyModalSubtitle" class="text-xs text-slate-400 font-medium truncate mt-0.5">Add a host organization for student internship placement</p>
                    </div>
                </div>
                <button type="button" onclick="closeCompanyModal()" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition cursor-pointer shrink-0 ml-2" title="Close" aria-label="Close modal">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <!-- Modal Form Body (Scrollable) -->
            <form id="companyForm" method="POST" class="p-6 space-y-4 overflow-y-auto flex-1" style="scrollbar-gutter: stable;">
                <input type="hidden" name="add_company" id="form_add_company" value="1">
                <input type="hidden" name="edit_company" id="form_edit_company" value="0" disabled>
                <input type="hidden" name="company_id" id="form_company_id" value="0">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label for="modal_company_name" class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">
                            Company Name <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <i class="fa-regular fa-building text-xs"></i>
                            </span>
                            <input type="text"
                                id="modal_company_name"
                                name="company_name"
                                required
                                placeholder="e.g. Tech Corp Myanmar"
                                value="<?= (isset($_POST['add_company']) && !empty($err)) ? htmlspecialchars($_POST['company_name'] ?? '') : '' ?>"
                                class="w-full bg-slate-50/80 hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl pl-9 pr-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 shadow-2xs transition">
                        </div>
                    </div>

                    <div>
                        <label for="modal_website" class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">
                            Website
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <i class="fa-solid fa-globe text-xs"></i>
                            </span>
                            <input type="url"
                                id="modal_website"
                                name="website"
                                placeholder="https://example.com"
                                value="<?= (isset($_POST['add_company']) && !empty($err)) ? htmlspecialchars($_POST['website'] ?? '') : '' ?>"
                                class="w-full bg-slate-50/80 hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl pl-9 pr-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 shadow-2xs transition">
                        </div>
                    </div>

                    <div>
                        <label for="modal_contact_person" class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">
                            Contact Person
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <i class="fa-regular fa-user text-xs"></i>
                            </span>
                            <input type="text"
                                id="modal_contact_person"
                                name="contact_person"
                                placeholder="Full name"
                                value="<?= (isset($_POST['add_company']) && !empty($err)) ? htmlspecialchars($_POST['contact_person'] ?? '') : '' ?>"
                                class="w-full bg-slate-50/80 hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl pl-9 pr-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 shadow-2xs transition">
                        </div>
                    </div>

                    <div>
                        <label for="modal_contact_email" class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">
                            Contact Email
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <i class="fa-regular fa-envelope text-xs"></i>
                            </span>
                            <input type="email"
                                id="modal_contact_email"
                                name="contact_email"
                                placeholder="contact@gmail.com"
                                value="<?= (isset($_POST['add_company']) && !empty($err)) ? htmlspecialchars($_POST['contact_email'] ?? '') : '' ?>"
                                class="w-full bg-slate-50/80 hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl pl-9 pr-3.5 py-2.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 shadow-2xs transition">
                        </div>
                    </div>

                    <div>
                        <label for="modal_contact_phone" class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">
                            Contact Phone
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <i class="fa-solid fa-phone text-xs"></i>
                            </span>
                            <input type="text"
                                id="modal_contact_phone"
                                name="contact_phone"
                                placeholder="+959 454104282"
                                pattern="[0-9+ .()\/-]{6,30}"
                                maxlength="30"
                                title="Enter a valid Myanmar phone number, e.g. 09-123-456-789 or +959 123 456 789"
                                value="<?= (isset($_POST['add_company']) && !empty($err)) ? htmlspecialchars($_POST['contact_phone'] ?? '') : '' ?>"
                                class="w-full bg-slate-50/80 hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl pl-9 pr-3.5 py-2.5 text-xs font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 shadow-2xs transition">
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label for="modal_address" class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">
                            Company Address
                        </label>
                        <textarea
                            id="modal_address"
                            name="address"
                            rows="2"
                            placeholder="Street, township, city, region"
                            class="w-full bg-slate-50/80 hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl p-3 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 shadow-2xs transition"><?= (isset($_POST['add_company']) && !empty($err)) ? htmlspecialchars($_POST['address'] ?? '') : '' ?></textarea>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2.5 shrink-0">
                    <button type="button"
                        onclick="closeCompanyModal()"
                        class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit"
                        id="modalSubmitBtn"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-700 hover:from-teal-700 hover:to-emerald-800 text-white font-bold text-xs rounded-xl shadow-sm hover:shadow transition cursor-pointer">
                        <span>➕ Add Partner Company</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        /**
         * Open Company Modal in Add Mode
         */
        function openAddCompanyModal() {
            const modal = document.getElementById('companyModal');
            const form = document.getElementById('companyForm');
            if (!modal || !form) return;

            form.reset();
            document.getElementById('companyModalTitle').textContent = 'Register New Partner Company';
            document.getElementById('companyModalSubtitle').textContent = 'Add a host organization for student internship placement';
            document.getElementById('modalSubmitBtn').innerHTML = '<i class="fa-solid fa-plus text-xs"></i> <span>Add Partner Company</span>';

            document.getElementById('form_add_company').disabled = false;
            document.getElementById('form_add_company').value = '1';
            document.getElementById('form_edit_company').disabled = true;
            document.getElementById('form_edit_company').value = '0';
            document.getElementById('form_company_id').value = '0';

            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            setTimeout(function() {
                const input = document.getElementById('modal_company_name');
                if (input) input.focus();
            }, 60);
        }

        /**
         * Open Company Modal in Edit Mode
         */
        function openEditCompanyModal(data) {
            if (!data) return;
            const modal = document.getElementById('companyModal');
            if (!modal) return;

            document.getElementById('companyModalTitle').textContent = 'Edit Company Details';
            document.getElementById('companyModalSubtitle').textContent = 'Editing: ' + (data.company_name || 'Company');
            document.getElementById('modalSubmitBtn').innerHTML = '<i class="fa-regular fa-floppy-disk text-xs"></i> <span>Update Company</span>';

            document.getElementById('form_add_company').disabled = true;
            document.getElementById('form_add_company').value = '0';
            document.getElementById('form_edit_company').disabled = false;
            document.getElementById('form_edit_company').value = '1';
            document.getElementById('form_company_id').value = data.id || '0';

            document.getElementById('modal_company_name').value = data.company_name || '';
            document.getElementById('modal_website').value = data.website || '';
            document.getElementById('modal_contact_person').value = data.contact_person || '';
            document.getElementById('modal_contact_email').value = data.contact_email || '';
            document.getElementById('modal_contact_phone').value = data.contact_phone || '';
            document.getElementById('modal_address').value = data.address || '';

            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            setTimeout(function() {
                const input = document.getElementById('modal_company_name');
                if (input) input.focus();
            }, 60);
        }

        /**
         * Close Company Modal
         */
        function closeCompanyModal() {
            const modal = document.getElementById('companyModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('companySearchInput');
            const clearBtn = document.getElementById('clearCompanySearch');
            const rows = document.querySelectorAll('.company-row');
            const badge = document.getElementById('companyCountBadge');
            const footerCount = document.getElementById('companyFooterCount');
            const noResultsRow = document.getElementById('noCompanyResultsRow');
            const searchQueryDisplay = document.getElementById('searchQueryDisplay');
            const totalCount = <?= count($companies) ?>;

            function filterCompanies() {
                if (!searchInput) return;
                const query = searchInput.value.trim().toLowerCase();
                let visibleCount = 0;

                if (clearBtn) {
                    if (searchInput.value.length > 0) {
                        clearBtn.classList.remove('hidden');
                    } else {
                        clearBtn.classList.add('hidden');
                    }
                }

                rows.forEach(row => {
                    const name = (row.getAttribute('data-name') || '').toLowerCase();
                    if (!query || name.includes(query)) {
                        row.style.display = '';
                        visibleCount++;
                        const numCell = row.querySelector('.row-number');
                        if (numCell) {
                            numCell.textContent = visibleCount;
                        }
                    } else {
                        row.style.display = 'none';
                    }
                });

                if (noResultsRow) {
                    if (visibleCount === 0 && rows.length > 0) {
                        noResultsRow.classList.remove('hidden');
                        if (searchQueryDisplay) {
                            searchQueryDisplay.textContent = searchInput.value.trim();
                        }
                    } else {
                        noResultsRow.classList.add('hidden');
                    }
                }

                if (query) {
                    if (badge) badge.textContent = `${visibleCount} of ${totalCount} found`;
                    if (footerCount) footerCount.textContent = `Showing ${visibleCount} of ${totalCount} company(ies).`;
                } else {
                    if (badge) badge.textContent = `${totalCount} total`;
                    if (footerCount) footerCount.textContent = `Showing ${totalCount} company(ies).`;
                }
            }

            if (searchInput) {
                searchInput.addEventListener('input', filterCompanies);

                if (clearBtn) {
                    clearBtn.addEventListener('click', function() {
                        searchInput.value = '';
                        filterCompanies();
                        searchInput.focus();
                    });
                }

                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        searchInput.value = '';
                        filterCompanies();
                    }
                });
            }

            // Modal Backdrop and Escape handlers
            const companyModal = document.getElementById('companyModal');
            if (companyModal) {
                companyModal.addEventListener('click', function(e) {
                    if (e.target === companyModal) {
                        closeCompanyModal();
                    }
                });
            }

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeCompanyModal();
                }
            });

            <?php if ($edit_company): ?>
                openEditCompanyModal(<?= json_encode($edit_company) ?>);
            <?php elseif ((isset($_POST['add_company']) || isset($_POST['edit_company'])) && !empty($err)): ?>
                openAddCompanyModal();
            <?php endif; ?>
        });
    </script>

</body>

</html>