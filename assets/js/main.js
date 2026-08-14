/**
 * Main Application JavaScript for InternReport System
 */

// Theme Initialization
(function() {
    var theme = localStorage.getItem('theme');
    if (theme === 'dark') {
        document.documentElement.classList.add('dark');
    }
})();

/**
 * Global Profile Dropdown Toggle
 */
function toggleProfileDropdown(e) {
    if (e) e.stopPropagation();
    var dd = document.getElementById('profile-dropdown-menu') || document.getElementById('profileDropdownMenu');
    if (dd) {
        dd.classList.toggle('hidden');
    }
    var nd = document.getElementById('notif-dropdown');
    if (nd) {
        nd.style.opacity = '0';
        nd.style.visibility = 'hidden';
        nd.style.transform = 'translateY(-8px) scale(0.95)';
    }
}

// Global click listener to close profile dropdown when clicking outside
document.addEventListener('click', function(e) {
    var dd = document.getElementById('profile-dropdown-menu') || document.getElementById('profileDropdownMenu');
    var btn = document.getElementById('profile-avatar-btn') || document.getElementById('profileDropdownContainer');
    if (dd && !dd.contains(e.target) && btn && !btn.contains(e.target)) {
        dd.classList.add('hidden');
    }
});

/**
 * Display a floating toast notification.
 *
 * @param {string} message
 * @param {string} type 'success' | 'error' | 'warning' | 'info'
 */
function showToast(message, type) {
    var toast = document.createElement('div');
    var bgColor, icon;
    switch (type) {
        case 'success': bgColor = 'bg-emerald-600'; icon = '✓'; break;
        case 'error': bgColor = 'bg-red-600'; icon = '✕'; break;
        case 'warning': bgColor = 'bg-amber-500'; icon = '⚠'; break;
        default: bgColor = 'bg-slate-700'; icon = 'ℹ';
    }
    toast.className = 'fixed bottom-6 right-6 z-[1000] ' + bgColor + ' text-white text-xs font-bold px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 transition-all duration-300';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(10px)';
    toast.innerHTML = '<span class="text-base">' + icon + '</span> ' + message;
    document.body.appendChild(toast);

    requestAnimationFrame(function() {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    });

    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}
