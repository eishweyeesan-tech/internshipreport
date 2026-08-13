<?php
/**
 * Shared "Delete notification" confirmation modal + AJAX logic.
 * Include once per page, right before </body>.
 *
 * Expects each page that includes this to handle the POST:
 *   delete_notification=1&notification_id=<id>
 * by deleting only rows WHERE id = ? AND user_id = <session supervisor>
 * and responding with JSON { "success": bool, "unread_count": int } when the
 * request carries the X-Requested-With: XMLHttpRequest header.
 */
?>
<!-- ─── Delete Notification Confirmation Modal ─── -->
<div id="delete-notif-modal" class="fixed inset-0 z-[2000] hidden items-center justify-center">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl p-6 w-[90%] max-w-sm mx-4 border border-slate-100">
        <div class="flex items-start gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 text-red-600 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </div>
            <div>
                <h3 class="text-base font-extrabold text-slate-800">Delete notification?</h3>
                <p class="text-xs text-slate-500 mt-1">Are you sure you want to delete this notification? This action cannot be undone.</p>
            </div>
        </div>
        <div class="flex items-center justify-end gap-3 mt-2">
            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2.5 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition cursor-pointer">Cancel</button>
            <button type="button" onclick="confirmDelete()" class="px-4 py-2.5 text-xs font-bold text-white bg-red-600 hover:bg-red-700 rounded-xl shadow-md shadow-red-500/25 transition cursor-pointer">Delete</button>
        </div>
    </div>
</div>

<script>
(function () {
    var pendingDeleteId = null;

    function setModalVisible(visible) {
        var modal = document.getElementById('delete-notif-modal');
        if (!modal) return;
        if (visible) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        } else {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    function toast(message, type) {
        var bgColor = 'bg-slate-700', icon = 'ℹ';
        if (type === 'success') { bgColor = 'bg-emerald-600'; icon = '✓'; }
        if (type === 'error')   { bgColor = 'bg-red-600'; icon = '✕'; }
        var el = document.createElement('div');
        el.className = 'fixed bottom-6 right-6 z-[3000] ' + bgColor + ' text-white text-xs font-bold px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 transition-all duration-300';
        el.style.opacity = '0'; el.style.transform = 'translateY(10px)';
        el.innerHTML = '<span class="text-base">' + icon + '</span> ' + message;
        document.body.appendChild(el);
        requestAnimationFrame(function () { el.style.opacity = '1'; el.style.transform = 'translateY(0)'; });
        setTimeout(function () {
            el.style.opacity = '0'; el.style.transform = 'translateY(10px)';
            setTimeout(function () { el.remove(); }, 300);
        }, 3000);
    }

    window.requestDeleteNotification = function (id) {
        pendingDeleteId = id;
        setModalVisible(true);
    };

    window.closeDeleteModal = function () {
        pendingDeleteId = null;
        setModalVisible(false);
    };

    window.confirmDelete = function () {
        if (!pendingDeleteId) return;
        var id = pendingDeleteId;
        var fd = new FormData();
        fd.append('notification_id', id);
        fd.append('delete_notification', '1');
        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (!data || data.success !== true) {
                  toast('Could not delete this notification.', 'error');
                  return;
              }
              setModalVisible(false);
              pendingDeleteId = null;

              // Remove the notification everywhere on this page (dropdown + list)
              document.querySelectorAll('[data-notif-id="' + id + '"]').forEach(function (el) { el.remove(); });

              // Update unread badges / counts
              var badge = document.getElementById('notif-badge');
              var count = data.unread_count || 0;
              if (count > 0) {
                  if (badge) badge.textContent = count > 9 ? '9+' : count;
                  else {
                      var btn = document.querySelector('#notif-bell-wrapper button');
                      if (btn) {
                          var nb = document.createElement('span');
                          nb.id = 'notif-badge';
                          nb.className = 'absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-micro font-bold rounded-full flex items-center justify-center border border-white animate-pulse';
                          nb.textContent = count > 9 ? '9+' : count;
                          btn.appendChild(nb);
                      }
                  }
              } else if (badge) {
                  badge.remove();
              }
              var pageBadge = document.getElementById('page-unread-count');
              if (pageBadge) pageBadge.textContent = count;

              toast('Notification deleted.', 'success');

              // If the full Notifications list became empty, let the server render
              // the proper empty state (also fixes pagination counts).
              var list = document.getElementById('notif-list');
              if (list && list.querySelectorAll('[data-notif-id]').length === 0) {
                  window.location.reload();
              }
          })
          .catch(function () { toast('Something went wrong. Please try again.', 'error'); });
    };

    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDeleteModal();
    });
})();
</script>
