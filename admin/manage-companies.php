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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
        <?php $pageTitle = '🏢 Placement Companies Directory'; require_once __DIR__ . '/../includes/topbar.php'; ?>

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

                <!-- ════ ADD / EDIT COMPANY FORM ════ -->
                <div id="companyForm" class="bg-white rounded-2xl border <?= $edit_company ? 'border-teal-400 ring-2 ring-teal-500/20 shadow-md' : 'border-slate-200 shadow-sm' ?> overflow-hidden transition-all duration-200">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between <?= $edit_company ? 'bg-teal-50/50' : '' ?>">
                        <div class="flex items-center gap-2.5">
                            <span class="p-1.5 <?= $edit_company ? 'bg-teal-100 text-teal-800' : 'bg-teal-50 text-teal-700' ?> rounded-lg text-sm">🏢</span>
                            <div>
                                <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider">
                                    <?= $edit_company ? 'Edit Company Details' : 'Register New Partner Company' ?>
                                </h2>
                                <?php if ($edit_company): ?>
                                <p class="text-xs text-teal-700 font-medium mt-0.5">Editing: <strong class="font-bold text-slate-800"><?= htmlspecialchars($edit_company['company_name']) ?></strong></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($edit_company): ?>
                        <a href="manage-companies.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition cursor-pointer">
                            <span>✕ Cancel Edit</span>
                        </a>
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
                                <input type="text" name="company_name" required value="<?= htmlspecialchars($edit_company['company_name'] ?? '') ?>" placeholder="e.g. Tech Corp Myanmar" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-teal-600 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Website</label>
                                <input type="url" name="website" value="<?= htmlspecialchars($edit_company['website'] ?? '') ?>" placeholder="https://example.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-teal-600 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Contact Person</label>
                                <input type="text" name="contact_person" value="<?= htmlspecialchars($edit_company['contact_person'] ?? '') ?>" placeholder="Full name" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-teal-600 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Contact Email</label>
                                <input type="email" name="contact_email" value="<?= htmlspecialchars($edit_company['contact_email'] ?? '') ?>" placeholder="contact@company.com" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-teal-600 transition">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Contact Phone</label>
                                <input type="text" name="contact_phone" value="<?= htmlspecialchars($edit_company['contact_phone'] ?? '') ?>" placeholder="+959 123 456 789" pattern="[0-9+ .()\/-]{6,30}" maxlength="30" title="Enter a valid Myanmar phone number, e.g. 09-123-456-789 or +959 123 456 789" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-teal-600 transition">
                            </div>
                            <div class="md:col-span-2 lg:col-span-3">
                                <label class="block text-sm font-bold text-slate-500 mb-1.5">Address</label>
                                <textarea name="address" rows="2" placeholder="Street, city, region" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-teal-600 transition"><?= htmlspecialchars($edit_company['address'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center gap-3">
                            <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-700 hover:from-teal-700 hover:to-emerald-800 text-white font-bold text-sm rounded-xl shadow-lg shadow-teal-600/30 transition-all duration-200 cursor-pointer">
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
                    <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider">All Registered Companies</h2>
                            <span id="companyCountBadge" class="text-xs font-semibold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-full"><?= count($companies) ?> total</span>
                        </div>
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
                                <?php foreach ($companies as $i => $c): 
                                    $is_current_editing = $edit_company && ((int)$edit_company['id'] === (int)$c['id']);
                                ?>
                                <tr class="company-row hover:bg-slate-50/80 transition-colors <?= $is_current_editing ? 'bg-amber-50/60 ring-1 ring-amber-300' : '' ?>" data-name="<?= htmlspecialchars($c['company_name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <td class="row-number px-4 py-3.5 text-center text-slate-400 font-mono text-xs"><?= $i + 1 ?></td>
                                    <td class="px-4 py-3.5">
                                        <div class="flex flex-col">
                                            <div class="flex items-center gap-2">
                                                <span class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($c['company_name']) ?></span>
                                                <?php if ($is_current_editing): ?>
                                                <span class="text-[10px] font-extrabold uppercase px-1.5 py-0.5 bg-amber-200 text-amber-900 rounded">Editing</span>
                                                <?php endif; ?>
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
                                            <a href="?edit=<?= (int)$c['id'] ?>#companyForm" class="px-2.5 py-1.5 <?= $is_current_editing ? 'bg-amber-500 text-white shadow-xs' : 'bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200/60' ?> text-xs font-bold rounded-lg shadow-xs transition inline-flex items-center gap-1" title="Edit company">
                                                <i class="fa-regular fa-pen-to-square"></i>
                                                <span>Edit</span>
                                            </a>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('companySearchInput');
    const clearBtn = document.getElementById('clearCompanySearch');
    const rows = document.querySelectorAll('.company-row');
    const badge = document.getElementById('companyCountBadge');
    const footerCount = document.getElementById('companyFooterCount');
    const noResultsRow = document.getElementById('noCompanyResultsRow');
    const searchQueryDisplay = document.getElementById('searchQueryDisplay');
    const totalCount = <?= count($companies) ?>;

    if (!searchInput) return;

    function filterCompanies() {
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

    searchInput.addEventListener('input', filterCompanies);

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            filterCompanies();
            searchInput.focus();
        });
    }

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            searchInput.value = '';
            filterCompanies();
        }
    });
});
</script>

</body>
</html>
