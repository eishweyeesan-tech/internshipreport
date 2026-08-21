/**
 * Notifications Module JavaScript for InternReport System
 */

function toggleNotifDropdown(e) {
    if (e && typeof e.stopPropagation === 'function') {
        e.stopPropagation();
    }
    var dd = document.getElementById('notif-dropdown');
    if (!dd) return;

    var isHidden = dd.classList.contains('hidden') || dd.style.opacity === '0' || dd.style.visibility === 'hidden';

    if (!isHidden) {
        dd.style.opacity    = '0';
        dd.style.visibility = 'hidden';
        dd.style.transform  = 'translateY(-8px) scale(0.95)';
        setTimeout(function() {
            if (dd.style.opacity === '0') dd.classList.add('hidden');
        }, 200);
    } else {
        dd.classList.remove('hidden');
        dd.style.visibility = 'visible';
        dd.style.opacity    = '1';
        dd.style.transform  = 'translateY(0) scale(1)';
    }

    var pm = document.getElementById('profile-dropdown-menu') || document.getElementById('profileDropdownMenu');
    if (pm) pm.classList.add('hidden');
}

document.addEventListener('click', function(e) {
    var wrapper = document.getElementById('notif-bell-wrapper');
    var dd = document.getElementById('notif-dropdown');
    if (wrapper && dd && !wrapper.contains(e.target)) {
        dd.style.opacity = '0';
        dd.style.visibility = 'hidden';
        dd.style.transform = 'translateY(-8px) scale(0.95)';
        setTimeout(function() {
            if (dd.style.opacity === '0') dd.classList.add('hidden');
        }, 200);
    }
});

function openNotifFromSidebar() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(function() { toggleNotifDropdown(); }, 300);
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    var date = new Date(dateStr.replace(/-/g, '/'));
    if (isNaN(date.getTime())) date = new Date(dateStr);
    if (isNaN(date.getTime())) return dateStr;

    var now = new Date();
    var seconds = Math.floor((now - date) / 1000);
    if (seconds < 0 || seconds < 60) return 'Just now';
    var minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + 'm ago';
    var hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + 'h ago';
    var days = Math.floor(hours / 24);
    if (days === 1) return 'Yesterday';
    if (days < 7) return days + 'd ago';
    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

function updateNotifTimestamps() {
    document.querySelectorAll('[data-notif-time]').forEach(function(el) {
        var rawTime = el.getAttribute('data-notif-time');
        if (rawTime) {
            el.textContent = timeAgo(rawTime);
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    updateNotifTimestamps();
    setInterval(updateNotifTimestamps, 60000);
});

function updateNotifBadge(count) {
    var badge = document.getElementById('notif-badge');
    var pageBadge = document.getElementById('page-unread-count');
    var markAllBtn = document.getElementById('notif-mark-all-btn') || document.getElementById('mark-all-btn');

    if (pageBadge) {
        pageBadge.textContent = count;
    }

    if (badge) {
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : count;
            badge.classList.remove('hidden');
            badge.classList.add('flex');
            badge.style.display = '';
        } else {
            badge.textContent = '0';
            badge.classList.add('hidden');
            badge.classList.remove('flex');
            badge.style.display = 'none';
        }
    }

    if (markAllBtn) {
        if (count > 0) {
            markAllBtn.classList.remove('hidden');
            markAllBtn.style.display = '';
        } else {
            markAllBtn.classList.add('hidden');
            markAllBtn.style.display = 'none';
        }
    }
}

function toggleNotifOptions(btn) {
    var menu = btn.nextElementSibling;
    document.querySelectorAll('.notif-options-menu').forEach(function(m) {
        if (m !== menu) m.classList.add('hidden');
    });
    if (menu) menu.classList.toggle('hidden');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('[onclick*="toggleNotifOptions"]')) {
        document.querySelectorAll('.notif-options-menu').forEach(function(m) {
            m.classList.add('hidden');
        });
    }
});

function onNotificationItemClick(e, el) {
    if (e && typeof e.preventDefault === 'function') {
        e.preventDefault();
    }

    var id = el.getAttribute('data-notif-id');
    var redirectUrl = el.getAttribute('data-redirect-url') || el.getAttribute('data-fallback-href') || el.getAttribute('href') || '';

    // If no notification ID, immediately redirect
    if (!id || parseInt(id, 10) <= 0) {
        if (redirectUrl && redirectUrl !== '#') {
            window.location.href = redirectUrl;
        }
        return false;
    }

    // Visually mark as read immediately
    el.classList.remove('bg-teal-50/40', 'bg-teal-50/50', 'bg-blue-50/40', 'bg-blue-50/60', 'bg-[#e7f3ff]', 'bg-indigo-50/40');
    var unreadDot = el.querySelector('.unread-dot') || el.querySelector('span.bg-teal-500') || el.querySelector('span.bg-blue-500');
    if (unreadDot) {
        unreadDot.classList.remove('bg-teal-500', 'bg-blue-500');
        unreadDot.style.opacity = '0';
    }

    var fd = new FormData();
    fd.append('notification_id', id);
    fd.append('mark_notification_read', '1');

    var targetUrl = window.location.href;

    fetch(targetUrl, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        keepalive: true
    }).then(function(r) {
        return r.json().catch(function() { return null; });
    }).then(function(data) {
        if (data && data.unread_count !== undefined) {
            updateNotifBadge(data.unread_count);
        }
    }).catch(function() {
        // Ignore fetch errors during navigation
    }).finally(function() {
        if (redirectUrl && redirectUrl !== '#') {
            window.location.href = redirectUrl;
        }
    });

    return false;
}

function markAllNotifsRead() {
    var fd = new FormData();
    fd.append('mark_all_notifications_read', '1');

    fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) {
        return r.json().catch(function() { return null; });
    }).then(function(data) {
        updateNotifBadge(0);
        document.querySelectorAll('[data-notif-id]').forEach(function(item) {
            item.classList.remove('bg-teal-50/40', 'bg-teal-50/50', 'bg-blue-50/40', 'bg-blue-50/60', 'bg-[#e7f3ff]', 'bg-indigo-50/40');
            var dot = item.querySelector('.unread-dot') || item.querySelector('span.bg-teal-500') || item.querySelector('span.bg-blue-500');
            if (dot) {
                dot.classList.remove('bg-teal-500', 'bg-blue-500');
                dot.style.opacity = '0';
            }
        });
    }).catch(function() {});
}

function markAllNotificationsRead() {
    markAllNotifsRead();
}
