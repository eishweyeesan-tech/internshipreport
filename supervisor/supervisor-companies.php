<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/notification_actions.php';

$sup_id   = (int) $_SESSION['user_id'];
$sup_name = $_SESSION['username'];
$db       = $mysqli ?? $conn;

// ── Notification redirect URL helper ────────────────────────────
require_once __DIR__ . '/../config/notify.php';

// ── Centralized Notification Action Handler ────────────────────
handle_notification_ajax_actions($db, $sup_id);

// ── Fetch notifications ─────────────────────────────────────────
$unread_notif_q = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->bind_param("i", $sup_id);
$unread_notif_q->execute();
$res = $unread_notif_q->get_result();
$row = $res ? $res->fetch_row() : null;
$unread_notif_count = (int) ($row[0] ?? 0);

$recent_notifs_q = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->bind_param("i", $sup_id);
$recent_notifs_q->execute();
$res = $recent_notifs_q->get_result();
$recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ── Search filter ───────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');

// ── Summary counts (assigned students scope) ───────────────────
$pending_reviews_q = $db->prepare("
    SELECT COUNT(*) FROM report_evaluations re
    WHERE re.report_status = 'approved_by_instructor'
      AND re.student_id IN (
          SELECT u.id FROM users u
          JOIN student_profiles sp ON sp.user_id = u.id
          WHERE u.role = 'student' AND sp.supervisor_id = ?
      )
      AND NOT EXISTS (
          SELECT 1 FROM supervisor_weekly_evaluations swe
          WHERE swe.student_id = re.student_id AND swe.week_number = re.week_number
      )
");
$pending_reviews_q->bind_param("i", $sup_id);
$pending_reviews_q->execute();
$res = $pending_reviews_q->get_result();
$row = $res ? $res->fetch_row() : null;
$pending_reviews = (int) ($row[0] ?? 0);

$company_count_q = $db->prepare("
    SELECT COUNT(DISTINCT sp.company_name) FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.company_name IS NOT NULL AND sp.company_name != ''
");
$company_count_q->bind_param("i", $sup_id);
$company_count_q->execute();
$res = $company_count_q->get_result();
$row = $res ? $res->fetch_row() : null;
$company_count = (int) ($row[0] ?? 0);

// ── Students grouped by company (assigned students scope) ──────
$sql = "
    SELECT u.id AS uid, u.username, u.profile_pic,
           sp.full_name, sp.student_roll, sp.job_role, sp.company_name,
           sp.instructor_name, sp.instructor_email, sp.instructor_phone,
           c.id AS company_id, c.address, c.contact_person, c.contact_email, c.contact_phone, c.website
    FROM student_profiles sp
    JOIN users u ON u.id = sp.user_id
    LEFT JOIN companies c ON c.company_name = sp.company_name
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.company_name IS NOT NULL AND sp.company_name != ''
";
$types = "i";
$params = [$sup_id];

if ($search) {
    $sql .= " AND (sp.company_name LIKE ? OR sp.full_name LIKE ? OR sp.job_role LIKE ? OR sp.instructor_name LIKE ? OR c.contact_person LIKE ? OR c.contact_email LIKE ?)";
    $like = '%' . $search . '%';
    $types .= "ssssss";
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY sp.company_name ASC, sp.full_name ASC";

$companies_stmt = $db->prepare($sql);
$companies_stmt->bind_param($types, ...$params);
$companies_stmt->execute();
$res = $companies_stmt->get_result();
$company_rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Group rows by company name in PHP
$companies = [];
foreach ($company_rows as $row) {
    $key = $row['company_name'];
    if (!isset($companies[$key])) {
        $companies[$key] = [
            'company_name'   => $row['company_name'],
            'company_id'     => $row['company_id'],
            'address'        => $row['address'],
            'contact_person' => $row['contact_person'],
            'contact_email'  => $row['contact_email'],
            'contact_phone'  => $row['contact_phone'],
            'website'        => $row['website'],
            'students'       => [],
        ];
    }
    $companies[$key]['students'][] = [
        'uid'              => (int) $row['uid'],
        'full_name'        => $row['full_name'],
        'username'         => $row['username'],
        'profile_pic'      => $row['profile_pic'] ?? '',
        'student_roll'     => $row['student_roll'],
        'job_role'         => $row['job_role'],
        'instructor_name'  => $row['instructor_name'] ?? '',
        'instructor_email' => $row['instructor_email'] ?? '',
        'instructor_phone' => $row['instructor_phone'] ?? '',
    ];
}

$filtered_count = count($companies);

function build_query_url($overrides = [])
{
    $q = array_merge($_GET, $overrides);
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null) unset($q[$k]);
    }
    return $q;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Companies – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .scroll-margin {
            scroll-margin-top: 88px;
        }
    </style>
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
    <script>
        (function() {
            var theme = localStorage.getItem('theme');
            if (theme === 'dark') document.documentElement.classList.add('dark');
        })();

        function filterCompaniesLive(query) {
            const q = (query || '').toLowerCase().trim();
            const cards = document.querySelectorAll('.company-card');
            cards.forEach(card => {
                const searchData = (card.getAttribute('data-search') || '').toLowerCase();
                if (q === '' || searchData.includes(q)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/notifications.js"></script>
</head>

<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

    <div class="flex h-screen overflow-hidden">

        <!-- ─── SIDEBAR ─── -->
        <?php $active_page = 'companies';
        include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

        <!-- ─── MAIN ─── -->
        <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

            <!-- Top Header -->
            <?php $pageTitle = '🏢 Placement Companies';
            include __DIR__ . '/includes/supervisor_topbar.php'; ?>

            <!-- ════ COMPANIES CONTENT ════ -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl w-full mx-auto space-y-6">

                    <!-- Top Stats & Filter Bar -->
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <p class="text-sm text-slate-400 font-medium">Placement companies of your assigned students</p>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <div class="relative w-full sm:w-64">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400 text-xs">🔍</span>
                                <input type="text"
                                    id="companySearchInput"
                                    placeholder="Search company or student…"
                                    class="w-full bg-white hover:bg-slate-50 focus:bg-white border border-slate-200 focus:border-teal-500 rounded-xl pl-8 pr-8 py-1.5 text-xs text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all duration-200 shadow-2xs"
                                    oninput="filterCompaniesLive(this.value)">
                                <button type="button" onclick="document.getElementById('companySearchInput').value=''; filterCompaniesLive('');" class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-700 text-xs font-bold cursor-pointer">✕</button>
                            </div>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600 shadow-2xs">🏢 <?= $company_count ?> Active <?= $company_count === 1 ? 'Company' : 'Companies' ?></span>
                        </div>
                    </div>

                    <!-- ═══ COMPANIES GRID (OPTION 3: UNIFORM CARDS + AVATAR STACK + QUICK MODAL) ═══ -->
                    <?php if (!empty($companies)): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6" id="companiesGridContainer">
                            <?php foreach ($companies as $company):
                                $student_count = count($company['students']);
                                $student_search = implode(' ', array_map(function ($s) {
                                    return ($s['full_name'] ?: $s['username']) . ' ' . ($s['student_roll'] ?: '') . ' ' . ($s['job_role'] ?: '') . ' ' . ($s['instructor_name'] ?? '');
                                }, $company['students']));
                                $comp_search = strtolower($company['company_name'] . ' ' . ($company['address'] ?? '') . ' ' . ($company['contact_person'] ?? '') . ' ' . ($company['contact_email'] ?? '') . ' ' . ($company['contact_phone'] ?? '') . ' ' . $student_search);
                            ?>
                                <div class="company-card bg-white rounded-3xl border border-slate-200/80 shadow-xs hover:shadow-md transition-all duration-300 flex flex-col justify-between overflow-hidden group hover:border-teal-300" data-search="<?= htmlspecialchars($comp_search) ?>">
                                    <!-- Card Top Section -->
                                    <div class="p-5 sm:p-6 pb-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="flex items-center gap-3.5 min-w-0">
                                                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-teal-500 via-teal-600 to-indigo-600 text-white flex items-center justify-center text-sm font-black shrink-0 shadow-md shadow-teal-500/20 group-hover:scale-105 transition-transform">
                                                    <?= strtoupper(substr($company['company_name'], 0, 2)) ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <h3 class="text-sm font-bold text-slate-800 truncate group-hover:text-teal-700 transition-colors" title="<?= htmlspecialchars($company['company_name']) ?>"><?= htmlspecialchars($company['company_name']) ?></h3>
                                                    <?php if (!empty($company['website'])): ?>
                                                        <a href="<?= htmlspecialchars($company['website']) ?>" target="_blank" rel="noopener" class="text-xs text-teal-600 hover:underline font-medium truncate block mt-0.5" title="<?= htmlspecialchars($company['website']) ?>">🔗 <?= htmlspecialchars($company['website']) ?></a>
                                                    <?php else: ?>
                                                        <p class="text-xs text-slate-400 font-medium truncate mt-0.5">🏢 Placement Partner</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="text-xs font-bold text-teal-700 bg-teal-50 px-2.5 py-1 rounded-xl border border-teal-200/60 shrink-0">
                                                <?= $student_count ?> <?= $student_count === 1 ? 'Student' : 'Students' ?>
                                            </span>
                                        </div>

                                        <!-- Company Contact Info -->
                                        <div class="mt-4 pt-3.5 border-t border-slate-100/80 space-y-2 text-xs text-slate-600 min-h-[96px]">
                                            <?php if (!empty($company['address'])): ?>
                                                <p class="flex items-start gap-2 text-slate-500" title="<?= htmlspecialchars($company['address']) ?>">
                                                    <span class="shrink-0 text-slate-400">📍</span>
                                                    <span class="truncate"><?= htmlspecialchars($company['address']) ?></span>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($company['contact_person'])): ?>
                                                <p class="flex items-center gap-2 text-slate-700 font-semibold truncate" title="HR / Contact: <?= htmlspecialchars($company['contact_person']) ?>">
                                                    <span class="shrink-0 text-slate-400">👤</span>
                                                    <span class="text-slate-400 font-normal">Contact:</span>
                                                    <span class="truncate"><?= htmlspecialchars($company['contact_person']) ?></span>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($company['contact_email'])): ?>
                                                <p class="flex items-center gap-2 truncate" title="<?= htmlspecialchars($company['contact_email']) ?>">
                                                    <span class="shrink-0 text-slate-400">✉️</span>
                                                    <a href="mailto:<?= htmlspecialchars($company['contact_email']) ?>" class="text-teal-600 hover:underline truncate"><?= htmlspecialchars($company['contact_email']) ?></a>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($company['contact_phone'])): ?>
                                                <p class="flex items-center gap-2 text-slate-600 truncate font-mono" title="<?= htmlspecialchars($company['contact_phone']) ?>">
                                                    <span class="shrink-0 text-slate-400">📞</span>
                                                    <span><?= htmlspecialchars($company['contact_phone']) ?></span>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (empty($company['address']) && empty($company['contact_person']) && empty($company['contact_email']) && empty($company['contact_phone'])): ?>
                                                <p class="text-xs text-slate-400 font-medium italic">No contact details on file.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Card Footer: Mini Avatar Stack & Quick View Trigger -->
                                    <div class="p-3.5 sm:px-5 bg-slate-50/80 border-t border-slate-100 flex items-center justify-between gap-3">
                                        <!-- Avatar Stack (Clickable) -->
                                        <div class="flex items-center -space-x-2 py-0.5">
                                            <?php 
                                            $shown_avatars = array_slice($company['students'], 0, 4);
                                            $more_count = count($company['students']) - 4;
                                            foreach ($shown_avatars as $stu): 
                                                $s_name = $stu['full_name'] ?: $stu['username'];
                                                $s_roll = $stu['student_roll'] ?: '';
                                                $s_uid  = (int) $stu['uid'];
                                            ?>
                                                <a href="view-student-dashboard.php?id=<?= $s_uid ?>"
                                                   title="View <?= htmlspecialchars($s_name) ?> (<?= htmlspecialchars($s_roll) ?>)'s progress"
                                                   class="inline-block transition-transform duration-200 hover:scale-125 hover:z-20 relative cursor-pointer">
                                                    <?php if (!empty($stu['profile_pic'])): ?>
                                                        <img src="../uploads/avatars/<?= htmlspecialchars($stu['profile_pic']) ?>" alt="<?= htmlspecialchars($s_name) ?>" class="w-8 h-8 rounded-full object-cover ring-2 ring-white shadow-2xs hover:ring-teal-500 transition-all">
                                                    <?php else: ?>
                                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-[10px] font-black ring-2 ring-white shadow-2xs hover:ring-teal-500 transition-all">
                                                            <?= strtoupper(substr($s_name, 0, 1)) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </a>
                                            <?php endforeach; ?>
                                            <?php if ($more_count > 0): ?>
                                                <button type="button"
                                                        onclick="openCompanyModal(<?= htmlspecialchars(json_encode($company, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)"
                                                        title="View all <?= count($company['students']) ?> students"
                                                        class="w-8 h-8 rounded-full bg-slate-200 hover:bg-slate-300 text-slate-700 flex items-center justify-center text-[10px] font-bold ring-2 ring-white shadow-2xs transition-transform duration-200 hover:scale-110 hover:z-20 relative cursor-pointer">
                                                    +<?= $more_count ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>

                                        <!-- View Interns Action Button -->
                                        <button type="button" onclick="openCompanyModal(<?= htmlspecialchars(json_encode($company, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl shadow-xs transition-all hover:scale-105 cursor-pointer shrink-0">
                                            <span>👥 View Interns</span>
                                            <span>→</span>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm p-16 text-center">
                            <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center text-4xl mx-auto mb-5">🏢</div>
                            <p class="text-base font-bold text-slate-700">No companies yet</p>
                            <p class="text-sm text-slate-400 mt-1.5"><?= $search ? 'No companies match your search.' : 'Placement companies will appear here once students are assigned to you.' ?></p>
                            <?php if ($search): ?>
                                <a href="supervisor-companies.php" class="mt-5 inline-block text-xs font-bold text-teal-600 hover:underline">✕ Clear search</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </main>
        </div>
    </div>

    <!-- ═══════════ COMPANY STUDENTS QUICK VIEW MODAL ═══════════ -->
    <div id="companyStudentsModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-xs transition-opacity" onclick="closeCompanyModal()"></div>

        <!-- Modal Card -->
        <div class="relative w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-slate-100 flex flex-col max-h-[85vh] overflow-hidden z-10">
            <!-- Modal Header -->
            <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/60">
                <div class="flex items-center gap-3.5 min-w-0">
                    <div id="modalCompAvatar" class="w-12 h-12 rounded-2xl bg-gradient-to-br from-teal-500 via-teal-600 to-indigo-600 text-white flex items-center justify-center text-sm font-black shadow-md shrink-0">
                        🏢
                    </div>
                    <div class="min-w-0">
                        <h3 id="modalCompName" class="text-base font-black text-slate-800 truncate">Company Name</h3>
                        <p id="modalCompSubtitle" class="text-xs text-slate-500 font-medium">Assigned Interns</p>
                    </div>
                </div>
                <button type="button" onclick="closeCompanyModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center text-sm font-bold transition-colors cursor-pointer" title="Close">
                    ✕
                </button>
            </div>

            <!-- Modal Body (Students List) -->
            <div class="p-5 sm:p-6 overflow-y-auto space-y-3 flex-1" id="modalStudentsList">
                <!-- Rendered dynamically by JS -->
            </div>

            <!-- Modal Footer -->
            <div class="p-4 border-t border-slate-100 bg-slate-50/50 flex justify-end">
                <button type="button" onclick="closeCompanyModal()" class="px-5 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-xs rounded-xl transition cursor-pointer">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                })[m];
            });
        }

        function openCompanyModal(company) {
            if (!company) return;

            document.getElementById('modalCompName').textContent = company.company_name;
            document.getElementById('modalCompAvatar').textContent = company.company_name.substring(0, 2).toUpperCase();
            
            const totalStudents = company.students ? company.students.length : 0;
            let subtitle = `${totalStudents} Assigned Intern${totalStudents === 1 ? '' : 's'}`;
            if (company.address) {
                subtitle += ` • 📍 ${company.address}`;
            }
            document.getElementById('modalCompSubtitle').textContent = subtitle;

            const listEl = document.getElementById('modalStudentsList');
            listEl.innerHTML = '';

            if (company.students && company.students.length > 0) {
                company.students.forEach(stu => {
                    const name = stu.full_name || stu.username;
                    const roll = stu.student_roll || stu.username;
                    const role = stu.job_role || 'Intern';
                    const initial = name.charAt(0).toUpperCase();

                    let avatarHtml = '';
                    if (stu.profile_pic) {
                        avatarHtml = `<img src="../uploads/avatars/${escapeHtml(stu.profile_pic)}" alt="${escapeHtml(name)}" class="w-10 h-10 rounded-2xl object-cover ring-1 ring-slate-200 shrink-0">`;
                    } else {
                        avatarHtml = `<div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xs font-black shrink-0">${initial}</div>`;
                    }

                    let instructorHtml = '';
                    if (stu.instructor_name) {
                        instructorHtml = `
                            <div class="mt-2 text-[11px] text-teal-800 font-semibold bg-teal-50/90 px-2.5 py-1 rounded-xl border border-teal-100/80 flex items-center gap-1.5 flex-wrap">
                                <span>👨‍🏫 Instructor:</span>
                                <span class="font-bold text-teal-900">${escapeHtml(stu.instructor_name)}</span>
                                ${stu.instructor_email ? `<span class="text-slate-500 font-mono text-[10px]">(${escapeHtml(stu.instructor_email)})</span>` : ''}
                                ${stu.instructor_phone ? `<span class="text-slate-500 font-mono text-[10px]">📞 ${escapeHtml(stu.instructor_phone)}</span>` : ''}
                            </div>
                        `;
                    }

                    const itemHtml = `
                        <div class="p-4 bg-slate-50 hover:bg-slate-100/80 border border-slate-200/80 rounded-2xl transition flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0">
                                ${avatarHtml}
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h4 class="text-xs sm:text-sm font-bold text-slate-800">${escapeHtml(name)}</h4>
                                        <span class="px-2 py-0.5 rounded-lg text-[11px] font-mono font-bold bg-white text-slate-700 border border-slate-200 shadow-2xs">${escapeHtml(roll)}</span>
                                    </div>
                                    <p class="text-[11px] text-slate-500 font-medium mt-0.5">${escapeHtml(role)}</p>
                                    ${instructorHtml}
                                </div>
                            </div>
                            <a href="view-student-dashboard.php?id=${stu.uid}" class="inline-flex items-center gap-1 px-3.5 py-2 bg-white hover:bg-teal-50 text-teal-700 hover:text-teal-800 text-xs font-bold rounded-xl border border-slate-200 hover:border-teal-300 shadow-2xs transition shrink-0">
                                <span>View Progress</span>
                                <span>→</span>
                            </a>
                        </div>
                    `;
                    listEl.insertAdjacentHTML('beforeend', itemHtml);
                });
            } else {
                listEl.innerHTML = '<p class="text-center text-xs text-slate-400 py-6">No students assigned to this company.</p>';
            }

            const modal = document.getElementById('companyStudentsModal');
            modal.classList.remove('hidden');
        }

        function closeCompanyModal() {
            const modal = document.getElementById('companyStudentsModal');
            if (modal) modal.classList.add('hidden');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeCompanyModal();
        });
    </script>
    <?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>

</html>