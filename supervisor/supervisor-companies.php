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
    WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?
      AND sp.company_name IS NOT NULL AND sp.company_name != ''
");
$company_count_q->bind_param("i", $sup_id);
$company_count_q->execute();
$res = $company_count_q->get_result();
$row = $res ? $res->fetch_row() : null;
$company_count = (int) ($row[0] ?? 0);

// ── Students grouped by company (assigned students scope) ──────
$sql = "
    SELECT u.id AS uid, u.username,
           sp.full_name, sp.student_roll, sp.job_role, sp.company_name,
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
    $sql .= " AND (sp.company_name LIKE ? OR sp.full_name LIKE ? OR sp.job_role LIKE ? OR c.contact_person LIKE ? OR c.contact_email LIKE ?)";
    $like = '%' . $search . '%';
    $types .= "sssss";
    array_push($params, $like, $like, $like, $like, $like);
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
            'company_name' => $row['company_name'],
            'company_id'   => $row['company_id'],
            'address'      => $row['address'],
            'contact_person' => $row['contact_person'],
            'contact_email'  => $row['contact_email'],
            'contact_phone'  => $row['contact_phone'],
            'website'        => $row['website'],
            'students'     => [],
        ];
    }
    $companies[$key]['students'][] = [
        'uid'         => (int) $row['uid'],
        'full_name'   => $row['full_name'],
        'username'    => $row['username'],
        'student_roll'=> $row['student_roll'],
        'job_role'    => $row['job_role'],
    ];
}

$filtered_count = count($companies);

function build_query_url($overrides = []) {
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
    <style>
        .scroll-margin { scroll-margin-top: 88px; }
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

    </script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/notifications.js"></script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <?php $active_page = 'companies'; include __DIR__ . '/includes/supervisor_sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div id="top" class="flex-1 flex flex-col min-h-0 min-w-0 overflow-hidden">

        <!-- Top Header -->
        <?php $pageTitle = 'University Supervisor Companies'; include __DIR__ . '/includes/supervisor_topbar.php'; ?>

        <!-- ════ COMPANIES CONTENT ════ -->
        <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6 lg:p-8">
            <div class="max-w-7xl w-full mx-auto space-y-6">


                <!-- ═══ PAGE HEADER ═══ -->
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h2 class="text-2xl font-black text-slate-800 tracking-tight">🏢 Companies</h2>
                        <p class="text-sm text-slate-400 mt-1 font-medium">Placement companies of your assigned students</p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-600">🏢 <?= $company_count ?> active</span>
                        <?php if ($search): ?>
                        <a href="supervisor-companies.php" class="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all duration-200">✕ Clear search</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ COMPANIES GRID ═══ -->
                <?php if (!empty($companies)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    <?php foreach ($companies as $company):
                        $student_count = count($company['students']);
                    ?>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden flex flex-col hover:shadow-md transition-shadow duration-300">
                        <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-xs font-black shrink-0 shadow-md shadow-blue-500/20">
                                <?= strtoupper(substr($company['company_name'], 0, 2)) ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($company['company_name']) ?></h3>
                                <?php if (!empty($company['website'])): ?>
                                <a href="<?= htmlspecialchars($company['website']) ?>" target="_blank" rel="noopener" class="text-xs text-blue-600 hover:underline font-medium truncate block"><?= htmlspecialchars($company['website']) ?></a>
                                <?php else: ?>
                                <p class="text-xs text-slate-400 font-medium truncate">Company placement</p>
                                <?php endif; ?>
                            </div>
                            <span class="text-sm font-bold text-blue-600 bg-blue-50 px-2.5 py-1 rounded-lg border border-blue-200/60 shrink-0"><?= $student_count ?> student<?= $student_count !== 1 ? 's' : '' ?></span>
                        </div>

                        <div class="px-5 py-4 flex-1 space-y-2.5">
                            <?php if (!empty($company['address']) || !empty($company['contact_person']) || !empty($company['contact_email']) || !empty($company['contact_phone'])): ?>
                            <div class="space-y-1.5">
                                <?php if (!empty($company['address'])): ?>
                                <p class="text-xs text-slate-500 font-medium flex items-start gap-2"><span class="shrink-0">📍</span><span><?= htmlspecialchars($company['address']) ?></span></p>
                                <?php endif; ?>
                                <?php if (!empty($company['contact_person'])): ?>
                                <p class="text-xs text-slate-500 font-medium flex items-center gap-2"><span class="shrink-0">👤</span><span><?= htmlspecialchars($company['contact_person']) ?></span></p>
                                <?php endif; ?>
                                <?php if (!empty($company['contact_email'])): ?>
                                <p class="text-xs text-slate-500 font-medium flex items-center gap-2"><span class="shrink-0">✉️</span><a href="mailto:<?= htmlspecialchars($company['contact_email']) ?>" class="text-blue-600 hover:underline truncate"><?= htmlspecialchars($company['contact_email']) ?></a></p>
                                <?php endif; ?>
                                <?php if (!empty($company['contact_phone'])): ?>
                                <p class="text-xs text-slate-500 font-medium flex items-center gap-2"><span class="shrink-0">📞</span><span><?= htmlspecialchars($company['contact_phone']) ?></span></p>
                                <?php endif; ?>
                            </div>
                            <div class="border-t border-slate-100 pt-2.5"></div>
                            <?php else: ?>
                            <p class="text-xs text-slate-400 font-medium italic">No contact details on file.</p>
                            <div class="border-t border-slate-100 pt-2.5"></div>
                            <?php endif; ?>

                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Assigned Students</p>
                            <div class="space-y-2">
                                <?php foreach ($company['students'] as $stu): ?>
                                <a href="view-student-dashboard.php?id=<?= (int)$stu['uid'] ?>" class="flex items-center gap-2.5 p-2.5 bg-gradient-to-r from-slate-50 to-white border border-slate-100 rounded-xl hover:border-blue-200 hover:shadow-sm transition-all duration-200 group">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-500 to-purple-600 text-white flex items-center justify-center text-xs font-black shrink-0 shadow-sm">
                                        <?= strtoupper(substr($stu['full_name'] ?: $stu['username'], 0, 1)) ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-bold text-slate-700 truncate group-hover:text-purple-600 transition-colors duration-150"><?= htmlspecialchars($stu['full_name'] ?: $stu['username']) ?></p>
                                        <p class="text-[11px] text-slate-400 font-medium truncate"><?= htmlspecialchars($stu['student_roll'] ?: $stu['username']) ?><?= !empty($stu['job_role']) ? ' · ' . htmlspecialchars($stu['job_role']) : '' ?></p>
                                    </div>
                                    <span class="text-[11px] font-bold text-blue-600 opacity-0 group-hover:opacity-100 transition-opacity duration-150">View →</span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-16 text-center">
                    <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center text-4xl mx-auto mb-5">🏢</div>
                    <p class="text-base font-bold text-slate-500">No companies yet</p>
                    <p class="text-sm text-slate-400 mt-1.5"><?= $search ? 'No companies match your search.' : 'Placement companies will appear here once students are assigned to you.' ?></p>
                    <?php if ($search): ?>
                    <a href="supervisor-companies.php" class="mt-5 inline-block text-xs font-bold text-indigo-600 hover:underline">✕ Clear search</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>
<?php include __DIR__ . '/includes/notification_delete.php'; ?>
</body>
</html>
