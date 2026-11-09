/* ════════════════════════════════════════════════════════════════
   REALTIME AUDIT LOG CONTROLLER (JS)
   Provides live, real-time updates for audit logs across all roles.
   ════════════════════════════════════════════════════════════════ */

(function () {
    'use strict';

    let pollTimer = null;
    const POLL_INTERVAL_MS = 3000;

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeJsString(str) {
        if (!str) return '';
        return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
    }

    function reapplyTableFilter(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;
        const searchInput = table.closest('.card, .content-section, body')?.querySelector('input[placeholder*="Search"]');
        if (searchInput && searchInput.value.trim() !== '') {
            const filter = searchInput.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
            });
        }
    }

    // Update System Admin Audit Log Table
    function updateAdminAuditTable(logs) {
        const table = document.getElementById('auditLogsTable');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        if (!logs || logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-tertiary);padding:24px;">No audit logs yet.</td></tr>';
            return;
        }

        let html = '';
        logs.forEach(log => {
            const logId = Number(log.id);

            html += `<tr>
                <td>${logId}</td>
                <td>${escapeHtml(log.created_at)}</td>
                <td>${escapeHtml(log.username)}</td>
                <td><span class="badge badge-violet">${escapeHtml(log.user_role)}</span></td>
                <td><span class="badge badge-orange">${escapeHtml(log.action_key)}</span></td>
                <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(log.details)}</td>
                <td>
                    <button type="button" class="btn-icon" title="Edit" onclick="editAuditLog(${logId}, '${escapeJsString(log.details)}')"><i class="bi bi-pencil"></i></button>
                    <form method="POST" style="display:inline;" onsubmit="return appConfirmSubmit(event, 'Delete this audit log entry?');">
                        <input type="hidden" name="audit_log_id" value="${logId}">
                        <button type="submit" name="delete_audit_log" class="btn-icon delete" title="Delete"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;
        reapplyTableFilter('auditLogsTable');
    }

    // Update Warehouse Head Audit Log Table
    function updateWarehouseAuditTable(logs) {
        const table = document.getElementById('warehouseAuditLogsTable');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        if (!logs || logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);padding:24px;">No warehouse audit records found.</td></tr>';
            return;
        }

        let html = '';
        logs.forEach(log => {
            const logId = Number(log.id);

            html += `<tr>
                <td>${logId}</td>
                <td>${escapeHtml(log.created_at)}</td>
                <td>${escapeHtml(log.username)}</td>
                <td><span class="badge badge-violet">${escapeHtml(log.user_role)}</span></td>
                <td><span class="badge badge-orange">${escapeHtml(log.action_key)}</span></td>
                <td style="max-width:360px;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(log.details || 'N/A')}</td>
            </tr>`;
        });

        tbody.innerHTML = html;
        reapplyTableFilter('warehouseAuditLogsTable');
    }

    // Update Purchaser Audit Log Table
    function updatePurchaserAuditTable(logs) {
        const table = document.getElementById('purchaserAuditLogsTable');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        if (!logs || logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-tertiary);padding:24px;">No purchaser audit records found.</td></tr>';
            return;
        }

        let html = '';
        logs.forEach(row => {
            let details = row.new_values || row.details || '';
            if (details !== '' && typeof details === 'string' && details.startsWith('{')) {
                try {
                    const parsed = JSON.parse(details);
                    if (parsed && typeof parsed === 'object') {
                        details = Object.entries(parsed).map(([k, v]) => `${k}: ${v}`).join(', ');
                    }
                } catch (e) {}
            }

            html += `<tr>
                <td>${escapeHtml(row.created_at)}</td>
                <td>${escapeHtml(row.user_name || row.username || 'N/A')}</td>
                <td><span class="badge badge-blue">${escapeHtml(row.action_type || row.action_key || 'N/A')}</span></td>
                <td>${escapeHtml(String(row.record_id || row.id || 'N/A'))}</td>
                <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(details || 'N/A')}</td>
            </tr>`;
        });

        tbody.innerHTML = html;
        reapplyTableFilter('purchaserAuditLogsTable');
    }

    // Update Recent Activity Feed
    function updateRecentActivityFeed(activities) {
        const feeds = document.querySelectorAll('.activity-feed');
        if (feeds.length === 0) return;

        if (!activities || activities.length === 0) {
            feeds.forEach(feed => {
                feed.innerHTML = `<li class="activity-item">
                    <div class="activity-icon product"><i class="bi bi-info-circle"></i></div>
                    <div class="activity-content">
                        <div class="activity-text">No recent activity recorded.</div>
                        <div class="activity-time">System</div>
                    </div>
                </li>`;
            });
            return;
        }

        let html = '';
        activities.slice(0, 10).forEach(act => {
            const actionKey = act.action_key || '';
            let iconClass = 'product';
            let iconName = 'box';

            if (actionKey.toLowerCase().includes('login')) {
                iconClass = 'login';
                iconName = 'box-arrow-in-right';
            } else if (actionKey.toLowerCase().includes('stock') || actionKey.toLowerCase().includes('movement')) {
                iconClass = 'stock-in';
                iconName = 'arrow-down-circle';
            } else if (actionKey.toLowerCase().includes('delete')) {
                iconClass = 'stock-out';
                iconName = 'arrow-up-circle';
            } else if (actionKey.toLowerCase().includes('user')) {
                iconClass = 'user';
                iconName = 'person';
            } else if (actionKey.toLowerCase().includes('adjust')) {
                iconClass = 'adjustment';
                iconName = 'sliders';
            }

            const rawDetails = act.details || actionKey;
            const snippet = rawDetails.length > 80 ? rawDetails.substring(0, 80) + '...' : rawDetails;

            html += `<li class="activity-item">
                <div class="activity-icon ${iconClass}">
                    <i class="bi bi-${iconName}"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-text"><strong>${escapeHtml(act.username)}</strong> — ${escapeHtml(snippet)}</div>
                    <div class="activity-time">${escapeHtml(act.created_at || '')}</div>
                </div>
            </li>`;
        });

        feeds.forEach(feed => {
            feed.innerHTML = html;
        });
    }

    // Fetch realtime audit logs from API
    async function fetchRealtimeAuditLogs() {
        if (document.hidden) return; // Skip polling if user switched tabs

        try {
            const response = await fetch('audit_api.php?action=get_logs', { cache: 'no-store' });
            if (!response.ok) return;

            const data = await response.json();
            if (!data.success) return;

            if (data.audit_logs && data.audit_logs.length > 0) {
                updateAdminAuditTable(data.audit_logs);
            }

            if (data.warehouse_audit_logs) {
                updateWarehouseAuditTable(data.warehouse_audit_logs);
            }

            if (data.purchaser_audit_logs) {
                updatePurchaserAuditTable(data.purchaser_audit_logs);
            }

            if (data.recent_activity) {
                updateRecentActivityFeed(data.recent_activity);
            }
        } catch (err) {
            console.error('Realtime audit poll error:', err);
        }
    }

    // Initialize module
    function initRealtimeAudit() {
        fetchRealtimeAuditLogs();
        if (!pollTimer) {
            pollTimer = setInterval(fetchRealtimeAuditLogs, POLL_INTERVAL_MS);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRealtimeAudit);
    } else {
        initRealtimeAudit();
    }

    // Expose global function to trigger manual refresh anytime
    window.refreshRealtimeAuditLogs = fetchRealtimeAuditLogs;
})();
