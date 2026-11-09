function showSection(section) {
    const titles = {
        dashboard: 'Dashboard',
        products: 'Product Catalog',
        inventory: 'Product Catalog',
        suppliers: 'Supplier Directory',
        reports: 'Inventory Reports'
    };
    const currentTitle = titles[section] || 'Dashboard';
    document.querySelectorAll('.content-section').forEach(s => s.style.display = 'none');
    document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
    document.getElementById('section-' + section).style.display = 'block';
    event.target.closest('.nav-link').classList.add('active');
    document.title = currentTitle + ' | Clarenzon Medical';
}

function filterProducts(searchTerm) {
    const term = searchTerm.toLowerCase();
    const products = document.querySelectorAll('.product-card');

    products.forEach(product => {
        const name = product.dataset.name;
        const category = product.dataset.category;

        if (name.includes(term) || category.includes(term)) {
            product.style.display = '';
        } else {
            product.style.display = 'none';
        }
    });
}
