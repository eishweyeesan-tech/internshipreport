<?php
require_once 'auth.php';

// Sample notifications data - replace with actual database query
$notifications = [
    [
        'id' => 1,
        'type' => 'report_submitted',
        'icon' => '📄',
        'message' => '<strong>Juan Dela Cruz</strong> submitted a new weekly log report.',
        'timestamp' => '2 minutes ago',
        'is_read' => false,
        'redirect_url' => 'student/view_log.php?id=101'
    ],
    [
        'id' => 2,
        'type' => 'report_approved',
        'icon' => '✅',
        'message' => '<strong>Prof. Reyes</strong> approved your weekly log for Week 5.',
        'timestamp' => '1 hour ago',
        'is_read' => false,
        'redirect_url' => 'student/view_log.php?id=102'
    ],
    [
        'id' => 3,
        'type' => 'comment',
        'icon' => '💬',
        'message' => '<strong>Ms. Santos</strong> commented on your report.',
        'timestamp' => '3 hours ago',
        'is_read' => true,
        'redirect_url' => 'student/view_report.php?id=201'
    ]
];

$notifications_json = json_encode($notifications);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
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
<body class="bg-gray-100">
    <!-- Header with Notification Dropdown -->
    <header class="bg-white shadow-sm sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Logo / App Name -->
                <div class="flex items-center">
                    <h1 class="text-xl font-bold text-blue-600">InternReport</h1>
                </div>

                <!-- Right Side: User Info + Notifications -->
                <div class="flex items-center gap-4">
                    <!-- Notification Dropdown -->
                    <div class="relative" id="notificationWrapper">
                        <button id="notificationBell" class="relative p-2 bg-gray-100 rounded-full hover:bg-gray-200 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                            <span id="notificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center hidden">0</span>
                        </button>

                        <!-- Dropdown Panel -->
                        <div id="notificationDropdown" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 z-50 hidden">
                            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
                                <button id="markAllRead" class="text-sm text-blue-600 hover:text-blue-800 hover:underline">Mark all as read</button>
                            </div>
                            <div id="notificationList" class="max-h-96 overflow-y-auto"></div>
                            <div class="px-4 py-3 border-t border-gray-200 text-center">
                                <a href="notifications.php" class="text-blue-600 hover:text-blue-800 hover:underline text-sm font-medium">See All Notifications</a>
                            </div>
                        </div>
                    </div>

                    <!-- User Info -->
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-600"><?= htmlspecialchars($_SESSION['username']) ?></span>
                        <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full"><?= htmlspecialchars($_SESSION['role']) ?></span>
                    </div>

                    <!-- Logout -->
                    <a href="logout.php" class="text-sm text-red-600 hover:text-red-800 hover:underline">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h1 class="text-2xl font-bold text-gray-800">Dashboard</h1>
            <p class="text-gray-600 mt-2">Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</p>
            <p class="text-gray-500 text-sm">Role: <?= htmlspecialchars($_SESSION['role']) ?></p>
        </div>
    </main>

    <!-- Notification Dropdown Script -->
    <script>
        // Notification Data
        const notificationsData = <?= $notifications_json ?>;

        // DOM Elements
        const bell = document.getElementById('notificationBell');
        const dropdown = document.getElementById('notificationDropdown');
        const notificationList = document.getElementById('notificationList');
        const badge = document.getElementById('notificationBadge');
        const markAllReadBtn = document.getElementById('markAllRead');

        // Render Notifications
        function renderNotifications() {
            notificationList.innerHTML = '';

            if (notificationsData.length === 0) {
                notificationList.innerHTML = `
                    <div class="px-4 py-8 text-center text-gray-500">
                        <p>No notifications yet</p>
                    </div>
                `;
                return;
            }

            notificationsData.forEach(notification => {
                const item = createNotificationItem(notification);
                notificationList.appendChild(item);
            });

            updateBadge();
        }

        // Create Notification Item
        function createNotificationItem(notification) {
            const item = document.createElement('a');
            item.href = notification.redirect_url;
            item.className = `flex items-start px-4 py-3 border-b border-gray-100 cursor-pointer transition-colors ${
                notification.is_read
                    ? 'bg-white hover:bg-gray-50'
                    : 'bg-blue-50 hover:bg-blue-100'
            }`;
            item.dataset.id = notification.id;

            const unreadDot = notification.is_read
                ? ''
                : '<span class="w-2.5 h-2.5 bg-blue-500 rounded-full flex-shrink-0 mt-1.5"></span>';

            item.innerHTML = `
                <div class="flex items-center gap-3 w-full">
                    ${unreadDot}
                    <div class="flex-shrink-0 text-2xl">${notification.icon}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-800 leading-snug">${notification.message}</p>
                        <p class="text-xs text-gray-500 mt-1">${notification.timestamp}</p>
                    </div>
                </div>
            `;

            item.addEventListener('click', function(e) {
                e.preventDefault();
                markAsRead(notification.id);
                window.location.href = notification.redirect_url;
            });

            return item;
        }

        // Update Badge
        function updateBadge() {
            const unreadCount = notificationsData.filter(n => !n.is_read).length;

            if (unreadCount > 0) {
                badge.textContent = unreadCount;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }

        // Mark Single as Read
        function markAsRead(id) {
            const notification = notificationsData.find(n => n.id === id);
            if (notification) {
                notification.is_read = true;
                renderNotifications();
            }
        }

        // Mark All as Read
        function markAllAsRead() {
            notificationsData.forEach(n => n.is_read = true);
            renderNotifications();
        }

        // Toggle Dropdown
        function toggleDropdown() {
            dropdown.classList.toggle('hidden');
        }

        // Event Listeners
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleDropdown();
        });

        markAllReadBtn.addEventListener('click', function() {
            markAllAsRead();
        });

        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                dropdown.classList.add('hidden');
            }
        });

        // Initialize
        renderNotifications();
    </script>
</body>
</html>
