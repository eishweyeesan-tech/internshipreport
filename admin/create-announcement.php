<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../auth.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: ../dashboard.php");
    exit;
}

$admin_name = $_SESSION["username"];
$admin_id = $_SESSION["user_id"];
$msg = "";
$err = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["send_announcement"])) {
    $a_title = trim($_POST["a_title"] ?? "");
    $a_body  = trim($_POST["a_body"] ?? "");
    $a_target = $_POST["a_target"] ?? "all";

    if (empty($a_title) || empty($a_body)) {
        $err = "Title and content are required.";
    } else {
        $pdo->prepare("INSERT INTO announcements (title, body, created_by) VALUES (?, ?, ?)")
            ->execute([$a_title, $a_body, $admin_id]);

        $roles = match($a_target) {
            "students" => ["student"],
            "supervisors" => ["supervisor"],
            default => ["student", "supervisor"],
        };

        $placeholders = implode(",", array_fill(0, count($roles), "?"));
        $recipients = $pdo->prepare("SELECT id FROM users WHERE role IN ($placeholders) AND status = 'Active'");
        $recipients->execute($roles);
        $count = 0;
        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'info')");
        foreach ($recipients->fetchAll() as $r) {
            $notifStmt->execute([$r["id"], "📢 " . $a_title, $a_body]);
            $count++;
        }

        $msg = "Announcement sent to " . $count . " user(s).";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Announcement – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    (function() {
        var theme = localStorage.getItem('theme');
        if (theme === 'dark') document.documentElement.classList.add('dark');
    })();
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
    /* Old toggleProfileDropdown removed — handled by includes/topbar.php */
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { font-family: 'Inter', sans-serif; }
        .drop-zone { transition: all 0.3s cubic-bezier(.4,0,.2,1); }
        .drop-zone.dragover {
            border-color: #6366f1;
            background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
            box-shadow: 0 0 0 4px rgba(99,102,241,0.1);
            transform: scale(1.005);
        }
        .card-glow:hover { box-shadow: 0 8px 30px -8px rgba(99,102,241,0.12), 0 2px 8px -2px rgba(0,0,0,0.04); }
        .field-focus:focus-within { box-shadow: 0 0 0 3px rgba(99,102,241,0.12); border-color: #6366f1; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .animate-slide-up { animation: slideUp 0.4s cubic-bezier(.4,0,.2,1) both; }
        .animate-fade-in { animation: fadeIn 0.3s ease both; }
        .btn-primary { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); box-shadow: 0 2px 8px -2px rgba(99,102,241,0.4); }
        .btn-primary:hover { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%); box-shadow: 0 4px 14px -2px rgba(99,102,241,0.5); transform: translateY(-1px); }
        .btn-primary:active { transform: translateY(0); }
        .sidebar-link { transition: all 0.15s ease; }
        .sidebar-link:hover { transform: translateX(2px); }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-slate-50 to-indigo-50/30 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <?php $activePage = 'announcements'; ?>
    <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <?php $pageTitle = 'Create Announcement'; require_once __DIR__ . '/../includes/topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6">

            <?php if ($msg): ?>
            <div class="bg-emerald-50/80 backdrop-blur-sm border border-emerald-200/60 text-emerald-700 text-sm font-semibold px-5 py-3.5 rounded-2xl flex items-center gap-2.5 mb-6 shadow-sm shadow-emerald-100/50 animate-slide-up">
                <span class="flex-shrink-0 w-7 h-7 bg-emerald-100 rounded-full flex items-center justify-center text-xs">✅</span>
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($err): ?>
            <div class="bg-red-50/80 backdrop-blur-sm border border-red-200/60 text-red-700 text-sm font-semibold px-5 py-3.5 rounded-2xl flex items-center gap-2.5 mb-6 shadow-sm shadow-red-100/50 animate-slide-up">
                <span class="flex-shrink-0 w-7 h-7 bg-red-100 rounded-full flex items-center justify-center text-xs">❌</span>
                <span><?= htmlspecialchars($err) ?></span>
            </div>
            <?php endif; ?>

            <div class="max-w-3xl mx-auto space-y-6">

                <!-- Page Header -->
                <div class="animate-slide-up">
                    <a href="admin-dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-400 hover:text-indigo-600 transition mb-4 group">
                        <svg class="w-4 h-4 transition-transform group-hover:-translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                        Back to Dashboard
                    </a>
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 bg-gradient-to-br from-amber-400 to-orange-500 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-amber-500/25 text-lg">
                            📢
                        </div>
                        <div>
                            <h1 class="text-xl font-extrabold text-slate-800 tracking-tight">Create Announcement</h1>
                            <p class="text-xs text-slate-400 font-medium mt-0.5">Broadcast an announcement to students and supervisors</p>
                        </div>
                    </div>
                </div>

                <!-- Announcement Form Card -->
                <div class="bg-white/90 backdrop-blur-sm rounded-3xl border border-slate-200/60 p-8 shadow-sm shadow-slate-200/40 card-glow transition-shadow duration-300 animate-slide-up" style="animation-delay: 0.1s;">

                    <form method="POST" enctype="multipart/form-data" class="space-y-6">
                        <input type="hidden" name="send_announcement" value="1">

                        <!-- Announcement Title -->
                        <div class="space-y-1.5">
                            <label for="a_title" class="block text-caption font-bold text-slate-600 uppercase tracking-wider">
                                Announcement Title <span class="text-rose-400">*</span>
                            </label>
                            <div class="field-focus rounded-xl border border-slate-200 transition-all duration-200">
                                <input
                                    type="text"
                                    id="a_title"
                                    name="a_title"
                                    required
                                    placeholder="e.g. Deadline for Week 4 Report Submission"
                                    class="w-full bg-slate-50/50 rounded-xl px-4 py-3.5 text-sm text-slate-800 placeholder:text-slate-300 focus:outline-none transition"
                                >
                            </div>
                        </div>

                        <!-- Target Audience -->
                        <div class="space-y-1.5">
                            <label for="a_target" class="block text-caption font-bold text-slate-600 uppercase tracking-wider">
                                Target Audience <span class="text-rose-400">*</span>
                            </label>
                            <div class="relative field-focus rounded-xl border border-slate-200 transition-all duration-200">
                                <select
                                    id="a_target"
                                    name="a_target"
                                    class="w-full appearance-none bg-slate-50/50 rounded-xl px-4 py-3.5 pr-10 text-sm text-slate-800 focus:outline-none transition cursor-pointer"
                                >
                                    <option value="all">📋 All Users (Students + Supervisors)</option>
                                    <option value="students">🎓 Students Only</option>
                                    <option value="supervisors">👨‍🏫 Supervisors Only</option>
                                </select>
                                <div class="absolute right-3.5 top-1/2 -translate-y-1/2 pointer-events-none">
                                    <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                </div>
                            </div>
                            <p class="text-label text-slate-400 font-medium mt-1">Choose who will receive this announcement notification</p>
                        </div>

                        <!-- Content Textarea -->
                        <div class="space-y-1.5">
                            <label for="a_body" class="block text-caption font-bold text-slate-600 uppercase tracking-wider">
                                Announcement Content <span class="text-rose-400">*</span>
                            </label>
                            <div class="field-focus rounded-xl border border-slate-200 overflow-hidden transition-all duration-200">
                                <div class="bg-slate-50/80 border-b border-slate-200/60 px-3.5 py-2.5 flex items-center gap-2">
                                    <span class="text-label font-bold text-slate-400 uppercase tracking-wider">Editor</span>
                                    <span class="w-px h-4 bg-slate-200/80"></span>
                                    <button type="button" class="w-7 h-7 rounded-lg hover:bg-slate-200/80 flex items-center justify-center text-slate-400 text-xs font-bold transition">B</button>
                                    <button type="button" class="w-7 h-7 rounded-lg hover:bg-slate-200/80 flex items-center justify-center text-slate-400 text-xs italic transition">I</button>
                                    <button type="button" class="w-7 h-7 rounded-lg hover:bg-slate-200/80 flex items-center justify-center text-slate-400 text-xs underline transition">U</button>
                                    <span class="w-px h-4 bg-slate-200/80"></span>
                                    <button type="button" class="w-7 h-7 rounded-lg hover:bg-slate-200/80 flex items-center justify-center text-slate-400 text-xs transition">•</button>
                                    <button type="button" class="w-7 h-7 rounded-lg hover:bg-slate-200/80 flex items-center justify-center text-slate-400 text-xs transition">≡</button>
                                </div>
                                <textarea
                                    id="a_body"
                                    name="a_body"
                                    rows="8"
                                    required
                                    placeholder="Write your announcement message here... Be clear about deadlines, requirements, and any action items for students."
                                    class="w-full bg-white px-4 py-3.5 text-sm text-slate-700 placeholder:text-slate-300 focus:outline-none transition resize-none"
                                ></textarea>
                            </div>
                        </div>

                        <!-- File Attachment Dropzone -->
                        <div class="space-y-1.5">
                            <label class="block text-caption font-bold text-slate-600 uppercase tracking-wider">
                                Attachments <span class="text-slate-400 font-normal normal-case">(optional)</span>
                            </label>
                            <div id="drop-zone" class="drop-zone border-2 border-dashed border-slate-200 hover:border-indigo-400 rounded-2xl p-10 text-center cursor-pointer transition-all duration-300 bg-slate-50/30 hover:bg-indigo-50/20">
                                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-slate-100 to-slate-50 flex items-center justify-center mx-auto mb-4 border border-slate-200/50">
                                    <svg class="w-7 h-7 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.23m1.999-1.999l.005-.005M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </div>
                                <p class="text-sm font-semibold text-slate-600 mb-1">Drag & drop files here or <span class="text-indigo-500 font-bold underline decoration-indigo-300 underline-offset-2">browse</span></p>
                                <p class="text-caption text-slate-400 font-medium">PDF, DOC, PNG, JPG up to 10MB each</p>
                                <input type="file" id="file-input" name="attachments[]" multiple class="hidden" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
                            </div>
                            <div id="file-list" class="mt-3 space-y-2"></div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center justify-between pt-5 border-t border-slate-100/80">
                            <p class="text-caption text-slate-400 font-medium">
                                <span class="text-rose-400">*</span> Required fields
                            </p>
                            <div class="flex items-center gap-3">
                                <a href="admin-dashboard.php" class="px-6 py-3 bg-slate-100/80 hover:bg-slate-200/80 text-slate-500 text-label font-bold rounded-xl transition-all duration-200 border border-slate-200/50">
                                    Cancel
                                </a>
                                <button type="submit" class="btn-primary px-7 py-3 text-white text-label font-bold rounded-xl transition-all duration-200 flex items-center gap-2.5 cursor-pointer">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                    Send Announcement
                                </button>
                            </div>
                        </div>

                    </form>
                </div>

            </div>
        </main>
    </div>
</div>

<script>
var dropZone = document.getElementById("drop-zone");
var fileInput = document.getElementById("file-input");
var fileList = document.getElementById("file-list");

dropZone.addEventListener("click", function() { fileInput.click(); });

dropZone.addEventListener("dragover", function(e) {
    e.preventDefault();
    dropZone.classList.add("dragover");
});

dropZone.addEventListener("dragleave", function() {
    dropZone.classList.remove("dragover");
});

dropZone.addEventListener("drop", function(e) {
    e.preventDefault();
    dropZone.classList.remove("dragover");
    handleFiles(e.dataTransfer.files);
});

fileInput.addEventListener("change", function() {
    handleFiles(this.files);
});

function handleFiles(files) {
    for (var i = 0; i < files.length; i++) {
        var file = files[i];
        var div = document.createElement("div");
        div.className = "flex items-center gap-2.5 px-3.5 py-2.5 bg-slate-50/80 border border-slate-200/60 rounded-xl animate-slide-up";
        var icon = file.type.includes("image") ? "🖼️" : "📄";
        div.innerHTML = "<span class='text-base'>" + icon + "</span>" +
            "<span class='text-xs font-semibold text-slate-700 truncate flex-1'>" + file.name + "</span>" +
            "<span class='text-label text-slate-400 font-medium bg-slate-100/80 px-2 py-0.5 rounded-full'>" + (file.size / 1024).toFixed(1) + " KB</span>" +
            "<button type='button' onclick='this.parentElement.remove()' class='w-6 h-6 rounded-lg hover:bg-red-50 text-slate-400 hover:text-rose-500 text-xs font-bold transition flex items-center justify-center'>✕</button>";
        fileList.appendChild(div);
    }
}
</script>
</body>
</html>
