/* ════════════════════════════════════════════════════════════════
   MODULE 9: NOTIFICATIONS & ALERTS CLIENT CONTROLLER (JS)
   ════════════════════════════════════════════════════════════════ */

let notifPollInterval = null;

// Toggle notification dropdown visibility
function toggleNotifications() {
    const dropdown = document.getElementById('notifDropdown');
    if (!dropdown) return;
    
    dropdown.classList.toggle('show');
    
    // Close dropdown on click outside
    if (dropdown.classList.contains('show')) {
        setTimeout(() => {
            document.addEventListener('click', closeNotifDropdownOutside);
        }, 10);
        // Refresh dropdown items on click
        loadNotificationsDropdown();
    } else {
        document.removeEventListener('click', closeNotifDropdownOutside);
    }
}

function closeNotifDropdownOutside(e) {
    const dropdown = document.getElementById('notifDropdown');
    const btn = e.target.closest('.topbar-icon-btn');
    if (dropdown && !dropdown.contains(e.target) && !btn) {
        dropdown.classList.remove('show');
        document.removeEventListener('click', closeNotifDropdownOutside);
    }
}

// Fetch notifications count and dropdown content
async function loadNotificationsDropdown() {
    try {
        const res = await fetch('notifications_api.php?action=get-notifications');
        const data = await res.json();
        
        if (data.success) {
            if (typeof updateFaviconNotificationDot === 'function') {
                updateFaviconNotificationDot(Number(data.count) > 0);
            }

            // Update unread count badge
            const badge = document.getElementById('notifBadge');
            if (badge) {
                if (data.count > 0) {
                    badge.textContent = data.count;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }
            if (typeof window.refreshSidebarNotificationBadges === 'function') {
                window.refreshSidebarNotificationBadges();
            }
            
            // Populate dropdown menu list
            const list = document.getElementById('notifDropdownList');
            if (list) {
                list.innerHTML = '';
                if (data.notifications.length === 0) {
                    list.innerHTML = `<li style="padding: 12px; text-align:center; color:#9CA3AF;">No notifications found.</li>`;
                } else {
                    data.notifications.forEach(item => {
                        const isUnread = parseInt(item.is_read) === 0;
                        const isHigh = item.priority === 'high';
                        const iconClass = getNotifIconClass(item.type);
                        const iconInner = getNotifIconInner(item.type);
                        
                        const li = document.createElement('li');
                        li.className = `notification-list-item ${isUnread ? 'unread' : ''} ${isHigh ? 'high-priority' : ''}`;
                        li.onclick = () => handleNotificationClick(item);
                        
                        li.innerHTML = `
                            <div class="notification-icon-wrapper ${iconClass}">
                                <i class="${iconInner}"></i>
                            </div>
                            <div class="notification-item-content">
                                <div class="notification-item-title">${escapeHTML(item.title)}</div>
                                <div class="notification-item-msg">${escapeHTML(item.message)}</div>
                                <div class="notification-item-time">${formatTimeAgo(item.created_at)}</div>
                            </div>
                        `;
                        list.appendChild(li);
                    });
                }
            }
        }
    } catch (e) {
        console.error("Failed to load notifications", e);
    }
}

// Map notification type to icon styles
function getNotifIconClass(type) {
    const map = {
        low_stock: 'low_stock',
        near_expiry: 'near_expiry',
        order_status: 'order_status',
        pending_approval: 'pending_approval'
    };
    return map[type] || 'order_status';
}

function getNotifIconInner(type) {
    const map = {
        low_stock: 'bi bi-exclamation-triangle-fill',
        near_expiry: 'bi bi-calendar-range-fill',
        order_status: 'bi bi-info-circle-fill',
        pending_approval: 'bi bi-shield-fill-check'
    };
    return map[type] || 'bi bi-bell-fill';
}

// Handle notification item click
async function handleNotificationClick(item) {
    // 1. Mark as read
    if (parseInt(item.is_read) === 0) {
        const formData = new FormData();
        formData.append('id', item.id);
        try {
            await fetch('notifications_api.php?action=mark-read', { method: 'POST', body: formData });
        } catch (_) {}
    }
    
    // Close dropdown
    const dropdown = document.getElementById('notifDropdown');
    if (dropdown) dropdown.classList.remove('show');
    
    // 2. Refresh count
    loadNotificationsDropdown();
    
    // 3. Route to target section
    if (item.related_module) {
        const moduleKey = String(item.related_module).trim().toLowerCase().replace(/[_\s]+/g, '-');
        if (typeof clarSectionIsDenied === 'function' && clarSectionIsDenied(item.related_module)) {
            if (moduleKey === 'billing-invoices') {
                const msg = 'Account Receivable is not available for this account.';
                if (typeof pToast === 'function') pToast(msg, 'warn');
                else if (window.Swal) Swal.fire({ icon: 'info', title: 'Unavailable', text: msg });
                return;
            }
            if (typeof showSection === 'function') showSection('dashboard');
        } else if (typeof showSection === 'function') {
            showSection(item.related_module);
        } else {
            console.warn("showSection routing function not defined on this page.");
        }
    }
}

// Mark all matching notifications as read
async function markAllNotificationsAsRead() {
    try {
        const res = await fetch('notifications_api.php?action=mark-all-read', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            loadNotificationsDropdown();
            // If the history list is currently open on screen, refresh it as well
            if (document.getElementById('section-notifications')?.classList.contains('active')) {
                loadNotificationsHistory();
            }
        }
    } catch (_) {}
}

// Helper to escape HTML tags
function escapeHTML(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g, 
        tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
    );
}

// Render Time Elapsed format
function formatTimeAgo(dateStr) {
    const date = new Date(dateStr.replace(/-/g, "/"));
    const seconds = Math.floor((new Date() - date) / 1000);
    
    if (seconds < 60) return 'Just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

// Start AJAX Polling
function startNotificationsPolling() {
    loadNotificationsDropdown();
    if (notifPollInterval) clearInterval(notifPollInterval);
    notifPollInterval = setInterval(() => {
        if (document.hidden) return;
        loadNotificationsDropdown();
    }, 10000); // every 10 seconds
}

// ─── FULL NOTIFICATION HISTORY LOGS VIEWS (Section Panel) ───

async function loadNotificationsHistory() {
    const type = document.getElementById('histTypeFilter')?.value || '';
    const dateFrom = document.getElementById('histDateFrom')?.value || '';
    const dateTo = document.getElementById('histDateTo')?.value || '';
    const search = document.getElementById('histSearchInput')?.value || '';
    
    const tbody = document.getElementById('notifHistoryTbody');
    if (!tbody) return;
    
    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">Loading history logs...</td></tr>`;
    
    const url = `notifications_api.php?action=load-history&type=${encodeURIComponent(type)}&date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}&search=${encodeURIComponent(search)}`;
    
    try {
        const res = await fetch(url);
        const data = await res.json();
        if (data.success) {
            tbody.innerHTML = '';
            if (data.history.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">No audit notifications found matching filters.</td></tr>`;
                return;
            }
            
            data.history.forEach(item => {
                const isUnread = parseInt(item.is_read) === 0;
                const isHigh = item.priority === 'high';
                const row = document.createElement('tr');
                row.className = `${isUnread ? 'table-warning' : ''}`;
                
                row.innerHTML = `
                    <td><span class="badge ${isUnread ? 'badge-danger' : 'badge-green'}">${isUnread ? 'Unread' : 'Read'}</span></td>
                    <td><span class="badge badge-blue">${escapeHTML(item.type.replace('_', ' '))}</span></td>
                    <td>
                        <strong>${escapeHTML(item.title)}</strong><br>
                        <span class="text-muted" style="font-size:12px;">${escapeHTML(item.message)}</span>
                    </td>
                    <td>
                        ${item.priority === 'high' ? '<span class="badge badge-danger">High</span>' : '<span class="badge badge-secondary">Low</span>'}
                    </td>
                    <td>${item.created_at}</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="handleNotificationClick(${JSON.stringify(item).replace(/"/g, '&quot;')})">
                            <i class="bi bi-box-arrow-in-right"></i> Open Module
                        </button>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }
    } catch (_) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Failed to load history logs.</td></tr>`;
    }
}

// Load Expiry Days Configuration in Forms
async function loadExpiryAlertSettings() {
    const input = document.getElementById('expiryThresholdInput');
    if (!input) return;
    
    try {
        const res = await fetch('notifications_api.php?action=get-settings');
        const data = await res.json();
        if (data.success) {
            input.value = data.expiry_alert_days;
        }
    } catch (_) {}
}

async function saveExpiryAlertSettings() {
    const input = document.getElementById('expiryThresholdInput');
    if (!input) return;
    
    const days = input.value;
    const formData = new FormData();
    formData.append('expiry_days', days);
    
    try {
        const res = await fetch('notifications_api.php?action=update-settings', { method: 'POST', body: formData });
        const data = await res.json();
        alert(data.message || 'Updated successfully.');
        loadNotificationsDropdown();
        loadNotificationsHistory();
    } catch (e) {
        alert('Failed to update threshold settings.');
    }
}

// Auto-run on DOM Ready
document.addEventListener('DOMContentLoaded', () => {
    startNotificationsPolling();
    
    // Check if configuration elements exist, and pre-load them
    if (document.getElementById('expiryThresholdInput')) {
        loadExpiryAlertSettings();
    }
});
