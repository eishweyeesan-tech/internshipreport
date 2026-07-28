<?php
/**
 * Global announcement modal + scripts.
 *
 * Optional before include:
 *   $announcement_api_url  (default: ../api/get_announcement.php)
 *   $notifications_api_url (default: ../api/notifications.php)
 */
if (!empty($GLOBALS['announcement_modal_bundle_loaded'])) {
    return;
}
$GLOBALS['announcement_modal_bundle_loaded'] = true;

$announcement_api_url = $announcement_api_url ?? '../api/get_announcement.php';
$notifications_api_url = $notifications_api_url ?? '../api/notifications.php';

// Include the modal HTML if available (avoid warnings when file is missing)
if (file_exists(__DIR__ . '/announcement-detail-modal.php')) {
    include __DIR__ . '/announcement-detail-modal.php';
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaede2tHjc05r/x/aXs4VQRRz/hkUiJd1o2q+T" crossorigin="anonymous">
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
(function() {
    var ANNOUNCEMENT_API = <?= json_encode($announcement_api_url, JSON_UNESCAPED_SLASHES) ?>;
    var NOTIFICATIONS_API = <?= json_encode($notifications_api_url, JSON_UNESCAPED_SLASHES) ?>;

    function escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatBodyHtml(body) {
        return escapeHtml(body).replace(/\n/g, '<br>');
    }

    function formatTimestamp(iso) {
        try {
            return new Date(iso).toLocaleDateString('en-GB', {
                day: 'numeric', month: 'long', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
        } catch (e) {
            return iso || '';
        }
    }

    window.updateNotifBadge = window.updateNotifBadge || function(count) {
        var badge = document.getElementById('notif-badge');
        var markAllBtn = document.getElementById('notif-mark-all-btn');
        if (count > 0) {
            if (badge) {
                badge.textContent = count > 9 ? '9+' : count;
                badge.classList.remove('hidden');
                badge.classList.add('flex');
            } else {
                var bell = document.querySelector('#notif-bell-wrapper button');
                if (bell) {
                    var span = document.createElement('span');
                    span.id = 'notif-badge';
                    span.className = 'absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center border-2 border-white shadow-sm';
                    span.textContent = count > 9 ? '9+' : count;
                    bell.appendChild(span);
                }
            }
            if (markAllBtn) markAllBtn.classList.remove('hidden');
        } else {
            if (badge) {
                badge.classList.add('hidden');
                badge.classList.remove('flex');
            }
            if (markAllBtn) markAllBtn.classList.add('hidden');
        }
    };

    function markNotificationRead(notifId) {
        if (!notifId) return Promise.resolve({ unread_count: null });
        var fd = new FormData();
        fd.append('action', 'mark_read');
        fd.append('notification_id', notifId);
        return fetch(NOTIFICATIONS_API + '?action=mark_read', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .catch(function() { return { unread_count: null }; });
    }

    function fetchAnnouncement(announcementId) {
        return fetch(ANNOUNCEMENT_API + '?id=' + encodeURIComponent(announcementId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); });
    }

    function renderAttachments(attachments) {
        var wrap = document.getElementById('announcementDetailAttachments');
        var list = document.getElementById('announcementDetailAttachmentsList');
        if (!wrap || !list) return;
        list.innerHTML = '';
        if (!attachments || !attachments.length) {
            wrap.classList.add('hidden');
            return;
        }
        attachments.forEach(function(att) {
            var li = document.createElement('li');
            var name = escapeHtml(att.name || att.filename || 'File');
            var url = att.url || att.path || '#';
            li.innerHTML = '<a href="' + escapeHtml(url) + '" class="text-blue-600 hover:underline text-sm font-medium" target="_blank" rel="noopener">' + name + '</a>';
            list.appendChild(li);
        });
        wrap.classList.remove('hidden');
    }

    function setModalLoading() {
        document.getElementById('announcementDetailModalLabel').textContent = 'Loading…';
        document.getElementById('announcementDetailSender').textContent = '';
        document.getElementById('announcementDetailTimestamp').textContent = '';
        document.getElementById('announcementDetailBody').classList.add('hidden');
        document.getElementById('announcementDetailError').classList.add('hidden');
        document.getElementById('announcementDetailLoading').classList.remove('hidden');
        renderAttachments([]);
    }

    function showModalError(msg) {
        document.getElementById('announcementDetailLoading').classList.add('hidden');
        document.getElementById('announcementDetailBody').classList.add('hidden');
        var err = document.getElementById('announcementDetailError');
        err.textContent = msg;
        err.classList.remove('hidden');
    }

    window.openAnnouncementDetailModal = function(announcementId, notificationId) {
        if (!announcementId) return;
        setModalLoading();
        $('#announcementDetailModal').modal('show');

        var markPromise = markNotificationRead(notificationId);
        var detailPromise = fetchAnnouncement(announcementId);

        Promise.all([markPromise, detailPromise]).then(function(results) {
            var markData = results[0];
            var detailData = results[1];

            if (markData && markData.unread_count != null) {
                updateNotifBadge(markData.unread_count);
            }

            if (detailData.error) {
                showModalError(detailData.error);
                return;
            }

            var a = detailData.announcement;
            if (!a) {
                showModalError('Failed to load announcement.');
                return;
            }

            document.getElementById('announcementDetailLoading').classList.add('hidden');
            document.getElementById('announcementDetailModalLabel').textContent = a.title || '';
            document.getElementById('announcementDetailSender').textContent = 'From: ' + (a.sender_name || 'Admin');
            document.getElementById('announcementDetailTimestamp').textContent = formatTimestamp(a.created_at);

            var bodyEl = document.getElementById('announcementDetailBody');
            bodyEl.innerHTML = formatBodyHtml(a.body);
            bodyEl.classList.remove('hidden');

            renderAttachments(detailData.attachments || []);

            if (notificationId) {
                var row = document.querySelector('[data-notif-id="' + notificationId + '"]');
                if (row) {
                    row.classList.remove('bg-blue-50/40', 'bg-[#e7f3ff]', 'bg-indigo-50/40');
                    var dot = row.querySelector('.bg-blue-500.rounded-full');
                    if (dot && dot.classList.contains('w-2')) dot.remove();
                }
            }
        }).catch(function() {
            showModalError('Failed to load announcement.');
        });
    };

    function closeNotifDropdown() {
        var dd = document.getElementById('notif-dropdown');
        if (!dd) return;
        dd.classList.add('hidden');
        dd.classList.remove('show');
        dd.style.opacity = '0';
        dd.style.visibility = 'hidden';
        dd.style.transform = 'translateY(-8px) scale(0.95)';
    }

    window.onNotificationItemClick = function(event, el) {
        if (!el) return true;
        var notifId = parseInt(el.getAttribute('data-notif-id') || '0', 10);
        var announcementId = parseInt(el.getAttribute('data-announcement-id') || '0', 10);
        var fallbackHref = el.getAttribute('data-fallback-href')
            || el.getAttribute('data-redirect-url')
            || el.getAttribute('href')
            || '';

        if (announcementId > 0) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            openAnnouncementDetailModal(announcementId, notifId || null);
            closeNotifDropdown();
            return false;
        }

        var isAnchor = el.tagName === 'A';
        if (notifId) {
            markNotificationRead(notifId).then(function(data) {
                if (data && data.unread_count != null) updateNotifBadge(data.unread_count);
                if (!isAnchor && fallbackHref && fallbackHref !== '#') {
                    window.location.href = fallbackHref;
                }
            });
        } else if (!isAnchor && fallbackHref && fallbackHref !== '#') {
            window.location.href = fallbackHref;
        }

        if (isAnchor) {
            return true;
        }
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        return false;
    };

    window.markNotifRead = function(arg) {
        if (typeof arg === 'object' && arg && arg.getAttribute) {
            return onNotificationItemClick(null, arg);
        }
        var notifId = parseInt(arg, 10);
        if (notifId) {
            markNotificationRead(notifId).then(function(data) {
                if (data && data.unread_count != null) updateNotifBadge(data.unread_count);
            });
        }
    };

    window.markAllNotificationsRead = window.markAllNotificationsRead || function() {
        fetch(NOTIFICATIONS_API + '?action=mark_all_read', { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var count = data.unread_count != null ? data.unread_count : 0;
                updateNotifBadge(count);
                document.querySelectorAll('#notif-list [data-notif-id]').forEach(function(row) {
                    row.classList.remove('bg-blue-50/40', 'bg-[#e7f3ff]', 'bg-indigo-50/40');
                });
                document.querySelectorAll('#notif-list .bg-blue-500').forEach(function(dot) {
                    if (dot.classList.contains('rounded-full')) {
                        dot.classList.remove('bg-blue-500');
                    }
                });
                document.querySelectorAll('#notif-list p.font-semibold').forEach(function(t) {
                    t.classList.remove('font-semibold', 'text-gray-900', 'font-bold', 'text-slate-800');
                    t.classList.add('text-gray-600', 'text-slate-600', 'font-medium');
                });
            });
    };

    window.markAllNotifsRead = window.markAllNotificationsRead;
})();
</script>
