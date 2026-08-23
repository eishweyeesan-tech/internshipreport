/**
 * Notifications Module JavaScript for InternReport System
 */

function toggleNotifDropdown() {
    var dd = document.getElementById('notif-dropdown');
    if (!dd) return;

    var visible = dd.style.opacity === '1' || !dd.classList.contains('hidden');
    if (visible && dd.style.opacity === '1') {
        dd.style.opacity    = '0';
        dd.style.visibility = 'hidden';
        dd.style.transform  = 'translateY(-8px) scale(0.95)';
    } else {
        dd.classList.remove('hidden');
        dd.style.opacity    = '1';
        dd.style.visibility = 'visible';
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
    }
});

function openNotifFromSidebar() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(function() { toggleNotifDropdown(); }, 300);
}

function timeAgo(dateStr) {
    var date = new Date(dateStr);
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
        el.textContent = timeAgo(el.getAttribute('data-notif-time'));
    });
}
document.addEventListener('DOMContentLoaded', function() {
    updateNotifTimestamps();
    setInterval(updateNotifTimestamps, 60000);
});

function updateNotifBadge(count) {
    var badge = document.getElementById('notif-badge');
    var markAllBtn = document.getElementById('notif-mark-all-btn');
    if (!badge) return;
    if (count > 0) {
        badge.textContent = count > 9 ? '9+' : count;
        badge.classList.remove('hidden');
        badge.classList.add('flex');
        if (markAllBtn) markAllBtn.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
        badge.classList.remove('flex');
        if (markAllBtn) markAllBtn.classList.add('hidden');
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

function scrollToTargetHash(hash) {
    if (!hash) return;
    try {
        var target = document.querySelector(hash);
        if (target) {
            setTimeout(function() {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                target.classList.add('ring-4', 'ring-teal-400/70', 'transition-all', 'duration-500');
                setTimeout(function() {
                    target.classList.remove('ring-4', 'ring-teal-400/70');
                }, 2500);
            }, 100);
        }
    } catch (err) {}
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash) {
        setTimeout(function() {
            scrollToTargetHash(window.location.hash);
        }, 250);
    }
});

window.addEventListener('hashchange', function() {
    if (window.location.hash) {
        scrollToTargetHash(window.location.hash);
    }
});

function onNotificationItemClick(e, el) {
    e.preventDefault();
    var id = el.getAttribute('data-notif-id');
    var redirectUrl = el.getAttribute('data-redirect-url') || el.getAttribute('data-fallback-href') || window.location.pathname;

    var fd = new FormData();
    fd.append('notification_id', id);
    fd.append('mark_notification_read', '1');

    fetch(window.location.pathname, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) { return r.json(); })
      .then(function(data) {
          if (data && data.unread_count !== undefined) {
              updateNotifBadge(data.unread_count);
          }
      })
      .catch(function() {})
      .finally(function() {
          if (redirectUrl && redirectUrl !== '#') {
              var hashIdx = redirectUrl.indexOf('#');
              var isSamePage = (hashIdx !== -1) && (redirectUrl.substring(0, hashIdx) === window.location.pathname || redirectUrl.substring(0, hashIdx) === window.location.pathname + window.location.search || redirectUrl.startsWith('#'));
              if (isSamePage) {
                  var hash = hashIdx !== -1 ? redirectUrl.substring(hashIdx) : redirectUrl;
                  window.location.hash = hash;
                  scrollToTargetHash(hash);
              } else {
                  window.location.href = redirectUrl;
              }
          }
      });
}

function markAllNotifsRead() {
    var fd = new FormData();
    fd.append('mark_all_notifications_read', '1');
    fetch(window.location.pathname, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) { return r.json(); })
      .then(function(data) {
          updateNotifBadge(0);
          document.querySelectorAll('#notif-dropdown [data-notif-id]').forEach(function(item) {
              item.classList.remove('bg-[#e7f3ff]', 'bg-indigo-50/40', 'bg-blue-50/40');
              item.querySelector('.unread-dot')?.remove();
          });
      })
      .catch(function() {});
}

function markAllNotificationsRead() {
    markAllNotifsRead();
}
