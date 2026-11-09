function showSection(section) {
    const titles = {
        dashboard: 'Procurement Overview',
        suppliers: 'Supplier Directory',
        requests: 'Purchase Requests',
        orders: 'Purchase Orders',
        receipts: 'Goods Received',
        invoices: 'Supplier Invoices',
        reports: 'Inventory Reports'
    };
    const currentTitle = titles[section] || 'Procurement Overview';
    document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(l => l.classList.remove('active'));
    document.getElementById('section-' + section).classList.add('active');
    event.target.closest('.nav-item').classList.add('active');
    document.title = currentTitle + ' | Clarenzon Medical';
}

function searchTable() {
    const input = document.getElementById('searchInput');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('productsTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    }
}
