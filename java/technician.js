function getSectionTitle(section) {
    const titles = {
        dashboard: 'Overview',
        'job-orders': 'My Job Orders',
        'issued-supplies': 'Issued Supplies',
        'work-status': 'Work Status / Report',
        'audit-log': 'Audit Log'
    };
    return titles[section] || 'Overview';
}

function showSection(section, element) {
    const target = document.getElementById('section-' + section);
    if (!target) return;

    const currentTitle = getSectionTitle(section);
    document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
    target.classList.add('active');

    document.querySelectorAll('.nav-item, .nav-subitem').forEach(item => item.classList.remove('active'));
    document.querySelectorAll('.nav-group-toggle').forEach(toggle => toggle.classList.remove('active-group'));

    if (element) {
        element.classList.add('active');
        const group = element.closest('.nav-group');
        if (group) {
            group.classList.add('open');
            const toggle = group.querySelector('.nav-group-toggle');
            if (toggle) toggle.classList.add('active-group');
        }
    }

    const breadcrumb = document.getElementById('breadcrumbCurrent');
    if (breadcrumb) breadcrumb.textContent = currentTitle;

    const globalSearch = document.getElementById('globalSearch');
    if (globalSearch) {
        globalSearch.placeholder = section === 'dashboard' ? 'Search products...' : 'Search products from overview...';
    }

    document.title = currentTitle + ' | Clarenzon Medical';
    closeMobileSidebar();
}

function toggleNavGroup(button) {
    const group = button.closest('.nav-group');
    if (!group) return;
    group.classList.toggle('open');
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;

    if (window.innerWidth <= 991) {
        sidebar.classList.toggle('mobile-open');
        if (overlay) overlay.classList.toggle('active', sidebar.classList.contains('mobile-open'));
    } else {
        sidebar.classList.toggle('collapsed');
    }
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
}

function toggleTheme() {
    const html = document.documentElement;
    const nextTheme = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', nextTheme);
    localStorage.setItem('technician-theme', nextTheme);

    const icon = document.querySelector('#themeToggle i');
    if (icon) {
        icon.className = nextTheme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    }
}

function searchTable() {
    const input = document.getElementById('globalSearch') || document.getElementById('searchInput');
    const table = document.getElementById('productsTable');
    if (!input || !table || !table.tBodies.length) return;

    const filter = input.value.toLowerCase();
    const rows = table.tBodies[0].getElementsByTagName('tr');
    for (const row of rows) {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('technician-theme');
    if (savedTheme === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        const icon = document.querySelector('#themeToggle i');
        if (icon) icon.className = 'bi bi-sun-fill';
    }
});

window.addEventListener('resize', () => {
    if (window.innerWidth > 991) closeMobileSidebar();
});
