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
    SELECT COUNT(*) FROM weekly_reports wr
    WHERE wr.status = 'approved_by_instructor'
      AND wr.supervisor_grade IS NULL
      AND wr.student_id IN (
          SELECT u.id FROM users u
          JOIN student_profiles sp ON sp.user_id = u.id
          WHERE u.role = 'student' AND sp.supervisor_id = ?
      )
");
$pending_reviews_q->bind_param("i", $sup_id);
$pending_reviews_q->execute();
$res = $pending_reviews_q->get_result();
$row = $res ? $res->fetch_row() : null;
$pending_reviews = (int) ($row[0] ?? 0);

$company_count_q = $db->prepare("
    SELECT COUNT(DISTINCT sp.company_id) FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?
      AND sp.company_id IS NOT NULL
");
$company_count_q->bind_param("i", $sup_id);
$company_count_q->execute();
$res = $company_count_q->get_result();
$row = $res ? $res->fetch_row() : null;
$company_count = (int) ($row[0] ?? 0);

// ── Students grouped by company (assigned students scope) ──────
$sql = "
    SELECT u.id AS uid, u.username,
       u.username AS full_name, sp.student_roll, u.position AS job_role,
       COALESCE(c.company_name, '') AS company_name,
       c.id AS company_id, c.address, c.contact_person, c.contact_email, c.contact_phone, c.website
    FROM student_profiles sp
    JOIN users u ON u.id = sp.user_id
    JOIN companies c ON c.id = sp.company_id
    WHERE u.role = 'student' AND sp.supervisor_id = ?
      AND sp.company_id IS NOT NULL
";
$types = "i";
$params = [$sup_id];

if ($search) {
    $sql .= " AND (c.company_name LIKE ? OR u.username LIKE ? OR u.position LIKE ? OR c.contact_person LIKE ? OR c.contact_email LIKE ?)";
    $like = '%' . $search . '%';
    $types .= "sssss";
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY c.company_name ASC, u.username ASC";

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
                        <h2 class="text-2xl font-bold text-slate-800 tracking-tight flex items-center gap-2">
                            <span>🏢</span> Companies
                        </h2>
                        <p class="text-xs text-slate-400 font-medium mt-1">Placement companies of your assigned students</p>
                    </div>
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <span class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-white border border-slate-200/80 rounded-xl text-xs font-semibold text-slate-700 shadow-xs">
                            🏢 <?= $company_count ?> active
                        </span>
                        <?php if ($search): ?>
                        <a href="supervisor-companies.php" class="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-semibold rounded-xl transition-all duration-200 ease-in-out shadow-xs">✕ Clear search</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ COMPANIES GRID ═══ -->
                <?php if (!empty($companies)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    <?php foreach ($companies as $company):
                        $student_count = count($company['students']);
                    ?>
                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden flex flex-col hover:shadow-md transition-all duration-200 ease-in-out">
                        <div class="p-6 border-b border-slate-100 flex items-center gap-3.5">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center text-sm font-bold shrink-0 shadow-xs">
                                <?= strtoupper(substr($company['company_name'], 0, 2)) ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-lg font-semibold text-slate-800 truncate"><?= htmlspecialchars($company['company_name']) ?></h3>
                                <?php if (!empty($company['website'])): ?>
                                <a href="<?= htmlspecialchars($company['website']) ?>" target="_blank" rel="noopener" class="text-xs text-blue-600 hover:underline font-medium truncate block"><?= htmlspecialchars($company['website']) ?></a>
                                <?php else: ?>
                                <p class="text-xs text-slate-400 font-medium truncate">Company placement</p>
                                <?php endif; ?>
                            </div>
                            <span class="text-xs font-semibold text-blue-700 bg-blue-50 px-2.5 py-1 rounded-full ring-1 ring-blue-600/20 shrink-0"><?= $student_count ?> student<?= $student_count !== 1 ? 's' : '' ?></span>
                        </div>

                        <div class="p-6 flex-1 space-y-4">
                            <?php if (!empty($company['address']) || !empty($company['contact_person']) || !empty($company['contact_email']) || !empty($company['contact_phone'])): ?>
                            <div class="space-y-2 text-sm text-slate-600 font-normal leading-relaxed">
                                <?php if (!empty($company['address'])): ?>
                                <p class="text-sm text-slate-600 font-normal flex items-start gap-2"><span class="shrink-0">📍</span><span><?= htmlspecialchars($company['address']) ?></span></p>
                                <?php endif; ?>
                                <?php if (!empty($company['contact_person'])): ?>
                                <p class="text-sm text-slate-600 font-normal flex items-center gap-2"><span class="shrink-0">👤</span><span><?= htmlspecialchars($company['contact_person']) ?></span></p>
                                <?php endif; ?>
                                <?php if (!empty($company['contact_email'])): ?>
                                <p class="text-sm text-slate-600 font-normal flex items-center gap-2"><span class="shrink-0">✉️</span><a href="mailto:<?= htmlspecialchars($company['contact_email']) ?>" class="text-teal-600 hover:underline truncate"><?= htmlspecialchars($company['contact_email']) ?></a></p>
                                <?php endif; ?>
                                <?php if (!empty($company['contact_phone'])): ?>
                                <p class="text-sm text-slate-600 font-normal flex items-center gap-2"><span class="shrink-0">📞</span><span><?= htmlspecialchars($company['contact_phone']) ?></span></p>
                                <?php endif; ?>
                            </div>
                            <div class="border-t border-slate-100 pt-3"></div>
                            <?php else: ?>
                            <p class="text-xs text-slate-400 font-medium italic">No contact details on file.</p>
                            <div class="border-t border-slate-100 pt-3"></div>
                            <?php endif; ?>

                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Assigned Students</p>
                            <div class="space-y-2.5">
                                <?php foreach ($company['students'] as $stu): ?>
                                <a href="view-student-dashboard.php?id=<?= (int)$stu['uid'] ?>" class="flex items-center gap-3 p-3 bg-gradient-to-r from-slate-50 to-white border border-slate-200/60 rounded-2xl hover:border-teal-300 hover:shadow-xs transition-all duration-200 ease-in-out group">
                                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-indigo-600 text-white flex items-center justify-center text-xs font-bold shrink-0 shadow-xs">
                                        <?= strtoupper(substr($stu['full_name'] ?: $stu['username'], 0, 1)) ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-slate-800 truncate group-hover:text-teal-700 transition-colors duration-150"><?= htmlspecialchars($stu['full_name'] ?: $stu['username']) ?></p>
                                        <p class="text-xs text-slate-400 font-medium truncate"><?= htmlspecialchars($stu['student_roll'] ?: $stu['username']) ?><?= !empty($stu['job_role']) ? ' · ' . htmlspecialchars($stu['job_role']) : '' ?></p>
                                    </div>
                                    <span class="text-xs font-semibold text-teal-700 opacity-0 group-hover:opacity-100 transition-opacity duration-150">View →</span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-16 text-center">
                    <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center text-4xl mx-auto mb-5">🏢</div>
                    <p class="text-lg font-bold text-slate-700">No companies yet</p>
                    <p class="text-sm text-slate-400 font-normal mt-1.5"><?= $search ? 'No companies match your search.' : 'Placement companies will appear here once students are assigned to you.' ?></p>
                    <?php if ($search): ?>
                    <a href="supervisor-companies.php" class="mt-5 inline-block text-xs font-semibold text-teal-700 hover:underline">✕ Clear search</a>
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
