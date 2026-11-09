/* Sidebar counts for unread notifications. Menu labels and routes stay as they are. */
(function () {
  const SECTION_RE = /showSection\(\s*['"]([^'"]+)['"]/;
  const moduleCounts = {};
  let lastSections = {};
  let lastTotal = 0;

  function formatCount(n) {
    const count = Number(n) || 0;
    if (count <= 0) return '';
    return count > 99 ? '99+' : String(count);
  }

  function sectionKey(el) {
    const onclick = el.getAttribute('onclick') || '';
    const match = onclick.match(SECTION_RE);
    return match ? match[1] : '';
  }

  function setBadge(el, count) {
    let badge = el.querySelector(':scope > .sidebar-notif-badge');
    const label = formatCount(count);
    if (!label) {
      if (badge) badge.remove();
      el.classList.remove('has-sidebar-notif');
      return;
    }
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'sidebar-notif-badge';
      const chevron = el.querySelector(':scope > .nav-chevron');
      if (chevron) el.insertBefore(badge, chevron);
      else el.appendChild(badge);
    }
    badge.textContent = label;
    el.classList.add('has-sidebar-notif');
  }

  function existingModulePendingCount(key) {
    if (key !== 'pr-approval') return null;
    const el = document.getElementById('prAppBadge');
    if (!el) return null;
    const raw = String(el.textContent || '').replace(/[^0-9]/g, '');
    return raw === '' ? 0 : (parseInt(raw, 10) || 0);
  }

  function countForSection(key, sections, total) {
    if (key === 'notifications') return Number(total) || 0;
    const pending = existingModulePendingCount(key);
    if (pending !== null) return pending;
    return Number(sections[key]) || 0;
  }

  function paint(sections, total) {
    lastSections = sections || {};
    lastTotal = Number(total) || 0;
    const nav = document.querySelector('.sidebar-nav');
    if (!nav) return;
    const items = nav.querySelectorAll('.nav-subitem, .nav-item');
    items.forEach((el) => {
      const key = sectionKey(el);
      if (!key) return;
      setBadge(el, countForSection(key, lastSections, lastTotal));
    });
    nav.querySelectorAll('.nav-group').forEach((group) => {
      const toggle = group.querySelector(':scope > .nav-group-toggle');
      if (!toggle) return;
      let sum = 0;
      group.querySelectorAll('.nav-group-items .nav-subitem').forEach((el) => {
        const key = sectionKey(el);
        if (!key || key === 'notifications') return;
        sum += countForSection(key, lastSections, lastTotal);
      });
      setBadge(toggle, sum);
    });
  }

  window.repaintSidebarNotificationBadges = function () {
    paint(lastSections, lastTotal);
  };

  window.refreshSidebarNotificationBadges = async function () {
    if (!document.querySelector('.sidebar-nav')) return;
    try {
      const res = await fetch('notifications_api.php?action=get-sidebar-badges', {
        cache: 'no-store',
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (!data || !data.success) return;
      paint(data.sections || {}, Number(data.total) || 0);
    } catch (_) {}
  };

  document.addEventListener('DOMContentLoaded', function () {
    window.refreshSidebarNotificationBadges();
  });
})();
