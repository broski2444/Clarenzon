<?php
session_start();
require_once 'config.php';
require_once 'route_protection.php';
require_once 'procurement_pr.php';

route_require_login(['admin']);

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo '<meta name="csrf-token" content="'.htmlspecialchars(csrf_get_token()).'">'; ?>
    <title>Purchase Requests | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <script src="java/app_dialog.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="container">
    <h2>Purchase Request Management</h2>
    <div>
        <label>Filter:</label>
        <select id="filterStatus">
            <option value="">All</option>
            <option value="Pending Approval">Pending</option>
            <option value="Approved">Approved</option>
            <option value="Rejected">Rejected</option>
        </select>
        <button id="applyFilter">Apply</button>
    </div>
    <div id="list"></div>
    <script>
    async function loadList() {
        const status = document.getElementById('filterStatus').value;
        const url = 'api/pr_list.php' + (status ? '?status=' + encodeURIComponent(status) : '');
        const r = await fetch(url); const j = await r.json();
        const list = document.getElementById('list');
        if (!j.success) { list.innerHTML = '<div>Error loading</div>'; return; }
        const rows = j.data;
        if (rows.length === 0) { list.innerHTML = '<div>No PRs found.</div>'; return; }
        let html = '<div class="system-table-scroll"><table class="table"><thead><tr><th>PR#</th><th>Requested By</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        for (const r2 of rows) {
            html += `<tr><td>${r2.pr_number}</td><td>${r2.requested_by}</td><td>${r2.request_date}</td><td>${r2.status}</td><td><button onclick="view(${r2.id})">View</button>`;
            if (r2.status === 'Pending Approval') html += ` <button onclick="decide(${r2.id},true)">Approve</button> <button onclick="decide(${r2.id},false)">Reject</button>`;
            html += '</td></tr>';
        }
        html += '</tbody></table></div>';
        list.innerHTML = html;
    }
    async function decide(id, approve) {
        const remarks = await appPrompt('Approval remarks (optional)');
        const token = document.querySelector('meta[name="csrf-token"]').content;
        const res = await fetch('api/pr_approve.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token': token},body:JSON.stringify({pr_id:id,approve:approve,remarks:remarks})});
        const j = await res.json(); if (j.success) { alert('OK'); loadList(); } else alert('Failed');
    }
    async function view(id) {
        // open a simple detail view
        const res = await fetch('api/pr_list.php'); const j = await res.json();
        const pr = j.data.find(x=>Number(x.id)===Number(id));
        if (!pr) { alert('Not found'); return; }
        // fetch items
        const r = await fetch('api/pr_list.php'); const jj = await r.json();
        alert('PR: ' + pr.pr_number + '\nStatus: ' + pr.status + '\nRequested By: ' + pr.requested_by);
    }
    document.getElementById('applyFilter').addEventListener('click', loadList);
    loadList();
    </script>
</div>
</body>
</html>
