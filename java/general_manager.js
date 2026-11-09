function showSection(section) {
    const titles = {
        dashboard: 'Dashboard',
        inventory: 'Product Catalog',
        suppliers: 'Supplier Directory',
        reports: 'Inventory Reports',
        settings: 'Settings'
    };
    const currentTitle = titles[section] || 'Dashboard';
    document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(l => l.classList.remove('active'));
    document.getElementById('section-' + section).classList.add('active');
    event.target.closest('.nav-item').classList.add('active');
    document.title = currentTitle + ' | Clarenzon Medical';
}

document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('overviewChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: (window.gmOverviewChartData && window.gmOverviewChartData.labels) || ['No inventory'],
                datasets: [{
                    label: 'Stock Value',
                    data: (window.gmOverviewChartData && window.gmOverviewChartData.values) || [0],
                    backgroundColor: 'rgba(79, 70, 229, 0.8)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#F3F4F6' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
