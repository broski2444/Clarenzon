<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once 'config.php';
require_once 'route_protection.php';
require_once 'warehouse_permissions.php';

route_require_login(['admin', 'general_manager', 'warehouse_head', 'warehouse_assistant']);

mysqli_report(MYSQLI_REPORT_OFF);
$conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db']);

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role']     ?? '';
$full_name= $_SESSION['full_name'] ?? $username;
$isAssistantRole    = isWarehouseAssistantRole((string)$role);
$isAdminWarehouseRole = isAdminWarehouseRole((string)$role);
$assistantPermissions = $conn ? getWarehouseAssistantPermissions($conn) : [];
$canEdit  = $isAdminWarehouseRole || ($isAssistantRole && !empty($assistantPermissions['can_manage_records']));

// Dashboard stats
$stats = ['products'=>0,'stock'=>0,'value'=>0,'low_stock'=>0,'near_expiry'=>0,'categories'=>0,'movements_today'=>0];
if ($conn) {
    $r = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(quantity_in_stock),0) s, COALESCE(SUM(quantity_in_stock*cost_price),0) v FROM products WHERE status!='Discontinued'");
    if ($r && $row = $r->fetch_assoc()) { $stats['products']=$row['c']; $stats['stock']=$row['s']; $stats['value']=$row['v']; }
    $r = $conn->query("SELECT COUNT(*) c FROM products WHERE quantity_in_stock<=reorder_level AND status!='Discontinued'");
    if ($r && $row = $r->fetch_assoc()) $stats['low_stock']=$row['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM products WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY) AND status!='Discontinued'");
    if ($r && $row = $r->fetch_assoc()) $stats['near_expiry']=$row['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM categories WHERE status='Active'");
    if ($r && $row = $r->fetch_assoc()) $stats['categories']=$row['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM stock_movements WHERE DATE(transaction_date)=CURDATE()");
    if ($r && $row = $r->fetch_assoc()) $stats['movements_today']=$row['c'];
}
if ($conn) $conn->close();
if (isset($_GET['logout'])) { session_destroy(); header("Location: login.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= function_exists('clarenzon_csrf_meta_tag') ? clarenzon_csrf_meta_tag() : '' ?>
<title>Product Catalog | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
<meta name="description" content="Real-time inventory management for Clarenzon — product catalog, stock tracking, batch management, expiry alerts, and serial numbers.">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── DR Row Visual Styling ── */
.mvt-row-dr {
  opacity: 0.6;
  background-color: #f8fafc !important;
  pointer-events: none;
  cursor: default;
}
.mvt-row-dr td {
  color: #64748b !important;
  font-style: italic;
}
.badge-dr {
  background: #e0f2fe;
  color: #0369a1;
  border: 1px solid #bae6fd;
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 999px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --sidebar:#0f172a;--sidebar-border:#1e293b;--sidebar-hover:#1e293b;--sidebar-active:#2563eb;
  --bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--text:#0f172a;--muted:#64748b;
  --primary:#2563eb;--primary-dark:#1d4ed8;--success:#10b981;--warn:#f59e0b;--danger:#ef4444;
  --info:#06b6d4;--purple:#8b5cf6;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);overflow-x:hidden}
img,svg,canvas{max-width:100%;height:auto}
.main-content,.content-area,.tab-panel,.card,.card-header,.card-body,.toolbar,.form-grid,.stats-grid,.modal,.modal-body,.top-bar,.top-bar-left,.top-actions{min-width:0}
p,h1,h2,h3,h4,h5,h6,td,th,.card-title,.page-title,.stat-label,.nav-item,.badge{overflow-wrap:anywhere}
input,select,textarea,button{max-width:100%}

/* ── Sidebar ── */
.sidebar{position:fixed;left:0;top:0;width:240px;height:100vh;background:var(--sidebar);display:flex;flex-direction:column;z-index:1000;transition:transform .3s ease}
.sidebar-header{padding:22px 18px;border-bottom:1px solid var(--sidebar-border)}
.logo{display:flex;align-items:center;gap:11px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,#3b82f6,#1e40af);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:17px;flex-shrink:0}
.logo-text{font-size:14px;font-weight:700;color:#f1f5f9;line-height:1.2}
.logo-sub{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;font-weight:600}
.sidebar-nav{flex:1;padding:18px 10px;overflow-y:auto}
.nav-section{font-size:10px;color:#475569;text-transform:uppercase;letter-spacing:.6px;font-weight:600;padding:10px 12px 6px}
.nav-item{display:flex;align-items:center;gap:11px;padding:10px 12px;margin-bottom:2px;border-radius:8px;color:#94a3b8;text-decoration:none;font-size:13.5px;font-weight:500;transition:all .18s;cursor:pointer;border:none;background:transparent;width:100%}
.nav-item:hover{background:var(--sidebar-hover);color:#f1f5f9}
.nav-item.active{background:var(--sidebar-active);color:#fff}
.nav-item i{font-size:17px;width:20px;text-align:center}
.nav-badge{margin-left:auto;background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;min-width:18px;text-align:center}
.sidebar-footer{padding:16px;border-top:1px solid var(--sidebar-border)}
.user-profile{display:flex;align-items:center;gap:10px}
.user-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#1e40af);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.user-name{font-size:12px;font-weight:600;color:#f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:10px;color:#94a3b8}
.logout-btn{margin-left:auto;width:30px;height:30px;border-radius:6px;border:1px solid #334155;background:transparent;color:#ef4444;display:flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;transition:all .2s;flex-shrink:0}
.logout-btn:hover{background:#450a0a;border-color:#7f1d1d}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999}
.sidebar-overlay.active{display:block}

/* ── Main ── */
.main-content{margin-left:240px;min-height:100vh;display:flex;flex-direction:column}
.top-bar{background:var(--surface);border-bottom:1px solid var(--border);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:100;gap:12px}
.top-bar-left{display:flex;align-items:center;gap:12px}
.hamburger{display:none;background:none;border:none;font-size:22px;color:var(--text);cursor:pointer;padding:4px}
.page-title{font-size:19px;font-weight:700;color:var(--text)}
.top-actions{display:flex;align-items:center;gap:10px}
.top-search{position:relative}
.top-search input{padding:8px 14px 8px 36px;border:1px solid var(--border);border-radius:8px;font-size:13px;background:#f8fafc;width:220px;font-family:'Inter',sans-serif}
.top-search input:focus{outline:none;border-color:var(--primary);background:#fff}
.top-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px}

/* ── Tab Nav ── */
.tab-nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 24px;display:flex;gap:4px;overflow-x:auto}
.tab-nav::-webkit-scrollbar{height:3px}
.tab-btn{display:flex;align-items:center;gap:7px;padding:14px 16px;border:none;background:transparent;color:var(--muted);font-size:13.5px;font-weight:500;font-family:'Inter',sans-serif;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;transition:all .2s;position:relative}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary);font-weight:600}
.tab-btn i{font-size:16px}
.tab-badge{background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:8px;min-width:16px;text-align:center}

/* ── Content ── */
.content-area{padding:24px;flex:1}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* ── Stat Cards ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,.08)}
.stat-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%}
.stat-card.blue::before{background:var(--primary)}
.stat-card.green::before{background:var(--success)}
.stat-card.warn::before{background:var(--warn)}
.stat-card.danger::before{background:var(--danger)}
.stat-card.purple::before{background:var(--purple)}
.stat-card.info::before{background:var(--info)}
.stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:12px}
.stat-card.blue .stat-icon{background:#dbeafe;color:var(--primary)}
.stat-card.green .stat-icon{background:#d1fae5;color:var(--success)}
.stat-card.warn .stat-icon{background:#fef3c7;color:var(--warn)}
.stat-card.danger .stat-icon{background:#fee2e2;color:var(--danger)}
.stat-card.purple .stat-icon{background:#ede9fe;color:var(--purple)}
.stat-card.info .stat-icon{background:#cffafe;color:var(--info)}
.stat-value{font-size:28px;font-weight:800;color:var(--text);line-height:1}
.stat-label{font-size:12px;color:var(--muted);font-weight:500;margin-top:4px}

/* ── Cards ── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;margin-bottom:20px;overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.card-title{font-size:15px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:8px}
.card-body{padding:22px}

/* ── Toolbar ── */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.toolbar-search{position:relative}
.toolbar-search input{padding:8px 14px 8px 34px;border:1px solid var(--border);border-radius:8px;font-size:13px;width:200px;font-family:'Inter',sans-serif}
.toolbar-search input:focus{outline:none;border-color:var(--primary)}
.toolbar-search i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px}
.toolbar select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:'Inter',sans-serif;background:var(--surface)}
.toolbar select:focus{outline:none;border-color:var(--primary)}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:8px;border:none;font-size:13.5px;font-weight:500;font-family:'Inter',sans-serif;cursor:pointer;transition:all .18s;text-decoration:none}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-dark)}
.btn-success{background:var(--success);color:#fff}
.btn-success:hover{background:#059669}
.btn-warn{background:var(--warn);color:#fff}
.btn-warn:hover{background:#d97706}
.btn-danger{background:var(--danger);color:#fff}
.btn-danger:hover{background:#dc2626}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text)}
.btn-outline:hover{background:#f8fafc}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-icon{width:30px;height:30px;border-radius:6px;border:1px solid var(--border);background:var(--surface);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted);transition:all .2s;font-size:13px}
.btn-icon:hover{background:#f8fafc;color:var(--text)}
.btn-icon.primary:hover{background:#dbeafe;color:var(--primary);border-color:#bfdbfe}
.btn-icon.danger:hover{background:#fee2e2;color:var(--danger);border-color:#fecaca}

/* ── Table ── */
.table-wrap,.system-table-scroll{display:block;width:100%;max-width:100%;min-width:0;overflow-x:auto!important;overflow-y:auto;max-height:calc(100vh - 220px);-webkit-overflow-scrolling:touch;scrollbar-gutter:auto;scrollbar-width:auto;scrollbar-color:#64748b #e2e8f0}
.table-wrap::-webkit-scrollbar,.system-table-scroll::-webkit-scrollbar{height:14px;width:14px}
.table-wrap::-webkit-scrollbar-track,.system-table-scroll::-webkit-scrollbar-track{background:#e2e8f0;border-radius:8px}
.table-wrap::-webkit-scrollbar-thumb,.system-table-scroll::-webkit-scrollbar-thumb{background:#64748b;border-radius:8px;border:2px solid #e2e8f0}
.table-wrap>table,.system-table-scroll>table{width:max-content;min-width:100%}
table.tbl{width:100%;border-collapse:separate;border-spacing:0}
table.tbl thead th{background:#f8fafc;padding:11px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border);white-space:nowrap}
table.tbl tbody td{padding:13px 14px;border-bottom:1px solid #f1f5f9;font-size:13.5px;color:#374151;vertical-align:middle}
table.tbl tbody tr:hover{background:#fafbfc}
table.tbl tbody tr:last-child td{border-bottom:none}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-blue{background:#dbeafe;color:#1e40af}
.badge-green{background:#d1fae5;color:#065f46}
.badge-yellow{background:#fef3c7;color:#92400e}
.badge-red{background:#fee2e2;color:#991b1b}
.badge-purple{background:#ede9fe;color:#5b21b6}
.badge-gray{background:#f1f5f9;color:#475569}
.badge-orange{background:#ffedd5;color:#c2410c}
.badge-cyan{background:#cffafe;color:#155e75}
.stock-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px}
.dot-green{background:var(--success)}
.dot-yellow{background:var(--warn)}
.dot-red{background:var(--danger)}
.product-image-link{background:none;border:0;padding:0;color:inherit;font:inherit;font-weight:600;cursor:pointer;text-align:left}
.product-image-link:hover{color:var(--primary);text-decoration:underline}
.product-image-preview{max-width:100%;max-height:70vh;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#f8fafc}
.product-image-empty{padding:32px;text-align:center;color:var(--muted)}
.product-upload-preview{margin-top:10px;min-height:86px;border:1px dashed var(--border);border-radius:10px;background:#f8fafc;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px;overflow:hidden;padding:10px}
.product-upload-preview img{max-width:100%;max-height:160px;object-fit:contain;border-radius:8px}
.product-image-message{margin-top:6px;font-size:12px;color:var(--danger)}

/* ── Modals ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:5000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;width:100%;max-width:620px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;animation:slideUp .25s ease}
.modal.modal-lg{max-width:820px}
@keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.modal-title{font-size:16px;font-weight:700;color:var(--text)}
.modal-close{width:32px;height:32px;border:none;background:#f1f5f9;border-radius:8px;cursor:pointer;color:var(--muted);font-size:16px;display:flex;align-items:center;justify-content:center;transition:.15s}
.modal-close:hover{background:#fee2e2;color:var(--danger)}
.modal-body{padding:24px;overflow-x:hidden;overflow-y:auto;flex:1;min-width:0;min-height:0}
.modal-footer{padding:16px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;flex-shrink:0}

/* ── Form ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-label{font-size:12px;font-weight:600;color:#374151}
.form-label span{color:var(--danger)}
.form-control{padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:13.5px;font-family:'Inter',sans-serif;background:#fff;transition:.15s;width:100%}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.form-check{display:flex;align-items:center;gap:7px;font-size:13px;color:var(--muted);cursor:pointer;user-select:none}
.form-check input{width:15px;height:15px;cursor:pointer}

/* ── Alert boxes ── */
.alert-item{display:flex;align-items:flex-start;gap:12px;padding:14px;background:#fff;border:1px solid var(--border);border-radius:10px;margin-bottom:10px;transition:.2s}
.alert-item:hover{box-shadow:0 4px 12px rgba(0,0,0,.06)}
.alert-item.low-stock{border-left:3px solid var(--warn)}
.alert-item.near-expiry{border-left:3px solid var(--danger)}
.alert-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.alert-icon.warn{background:#fef3c7;color:var(--warn)}
.alert-icon.danger{background:#fee2e2;color:var(--danger)}
.alert-info h4{font-size:13.5px;font-weight:600;color:var(--text);margin-bottom:3px}
.alert-info p{font-size:12px;color:var(--muted)}
.alert-action{margin-left:auto;flex-shrink:0}

/* ── Scanner ── */
.scanner-box{background:#f8fbff;border:1px solid #bfdbfe;border-radius:10px;padding:18px}
#inventoryScanner{max-width:340px;border-radius:8px;overflow:hidden}

/* ── Empty state ── */
.empty-state{text-align:center;padding:48px 20px;color:var(--muted)}
.empty-state i{font-size:48px;opacity:.35;display:block;margin-bottom:12px}
.empty-state p{font-size:14px}

/* ── Loader ── */
.loader{display:inline-block;width:18px;height:18px;border:2px solid #e2e8f0;border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}
.loading-row td{text-align:center;padding:32px!important;color:var(--muted)}

/* ── Responsive ── */
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main-content{margin-left:0}
  .hamburger{display:inline-flex!important}
  .top-search{display:none}
  .stats-grid{grid-template-columns:1fr 1fr}
  .form-grid{grid-template-columns:1fr}
  .form-group.full{grid-column:1}
  #overviewPanels{grid-template-columns:1fr!important}
}

@media(max-width:768px){
  /* Top bar */
  .top-bar{padding:10px 14px;gap:8px}
  .page-title{font-size:15px}
  /* Stats */
  .stats-grid{grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px}
  .stat-card{padding:14px;border-radius:10px}
  .stat-value{font-size:22px}
  .stat-icon{width:36px;height:36px;font-size:17px;margin-bottom:8px}
  /* Content */
  .content-area{padding:14px 12px}
  .card-header{padding:12px 14px;flex-wrap:wrap;gap:8px}
  .card-body{padding:14px}
  /* Toolbar wraps */
  .toolbar{flex-wrap:wrap;gap:8px}
  .toolbar-search input{width:100%}
  .toolbar select{width:100%}
  /* Tab nav scrolls */
  .tab-nav{padding:0 12px;gap:0}
  .tab-btn{padding:11px 12px;font-size:12.5px}

  /* ── TABLE → MOBILE CARDS ── */
  /* Hide the regular table header on mobile */
  .table-wrap table.tbl thead{display:none}

  /* Each row becomes a card */
  .table-wrap table.tbl,
  .table-wrap table.tbl tbody,
  .table-wrap table.tbl tr{
    display:block;
    width:100%;
  }
  .table-wrap table.tbl tbody tr{
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:12px;
    margin-bottom:12px;
    padding:14px;
    box-shadow:0 1px 4px rgba(0,0,0,.05);
    position:relative;
  }
  .table-wrap table.tbl tbody tr:hover{background:#fafbff}

  /* Each cell becomes a labelled row */
  .table-wrap table.tbl tbody td{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:5px 0;
    border:none;
    font-size:13px;
    border-bottom:1px solid #f1f5f9;
  }
  .table-wrap table.tbl tbody td:last-child{border-bottom:none}

  /* Data-label injected via CSS attr() — labels shown before value */
  .table-wrap table.tbl tbody td::before{
    content:attr(data-label);
    font-size:11px;
    font-weight:600;
    color:#64748b;
    text-transform:uppercase;
    letter-spacing:.4px;
    flex-shrink:0;
    width:90px;
  }

  /* Modals stay centered on mobile */
  .modal-overlay{padding:16px;align-items:center;justify-content:center}
  .modal{
    border-radius:16px;
    max-width:100%;
    max-height:92vh;
    margin:auto;
  }
  [style*="width:140px"],[style*="width:160px"],[style*="width:90px"]{width:100%!important;max-width:100%!important}
}

@media(max-width:480px){
  .stats-grid{grid-template-columns:repeat(2,1fr);gap:8px}
  .stat-card{padding:12px}
  .stat-value{font-size:19px}
}

@media print{.sidebar,.top-bar,.tab-nav,.modal-overlay,.sidebar-overlay{display:none!important}.main-content{margin-left:0}}
</style>
<link rel="stylesheet" href="css/popup-center.css?v=<?= @filemtime(__DIR__ . '/css/popup-center.css') ?: time() ?>">
</head>
<body>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="logo">
      <div class="logo-icon"><i class="bi bi-box-seam-fill"></i></div>
      <div>
        <div class="logo-text">Clarenzon</div>
        <div class="logo-sub">Medical Supplies</div>
      </div>
    </div>
  </div>
  <div class="sidebar-nav">
    <div class="nav-section">Navigation</div>
    <a class="nav-item" onclick="location.href='admin_dashboard.php'"><i class="bi bi-grid-1x2"></i><span>Dashboard</span></a>
    <a class="nav-item active"><i class="bi bi-boxes"></i><span>Inventory</span></a>
    <a class="nav-item" onclick="location.href='scan_item.php'"><i class="bi bi-qr-code-scan"></i><span>Scan Item</span></a>
    <div class="nav-section">Management</div>
    <?php if(in_array($role,['Administrator','Manager','Purchaser'])): ?>
    <a class="nav-item" onclick="location.href='purchaser.php'"><i class="bi bi-cart-plus"></i><span>Procurement</span></a>
    <?php endif; ?>
    <a class="nav-item" onclick="location.href='admin_dashboard.php'"><i class="bi bi-people"></i><span>Users</span></a>
    <a class="nav-item" onclick="location.href='admin_dashboard.php'"><i class="bi bi-bar-chart-line"></i><span>Reports</span></a>
  </div>
  <div class="sidebar-footer">
    <div class="user-profile">
      <div class="user-avatar"><?= strtoupper(substr((string)$full_name,0,1)) ?></div>
      <div style="flex:1;min-width:0">
        <div class="user-name"><?= htmlspecialchars((string)$full_name) ?></div>
        <div class="user-role"><?= htmlspecialchars((string)$role) ?></div>
      </div>
      <a href="?logout=1" class="logout-btn" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Main -->
<div class="main-content">
  <div class="top-bar">
    <div class="top-bar-left">
      <button class="hamburger" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
      <div class="page-title"><i class="bi bi-boxes" style="color:var(--primary)"></i> Inventory Management</div>
    </div>
    <div class="top-actions">
      <div class="top-search"><i class="bi bi-search"></i><input type="text" id="globalSearch" placeholder="Search products…" oninput="applyGlobalSearch()"></div>
      <?php if($canEdit): ?>
      <button class="btn btn-outline" style="margin-right:4px;border-color:var(--primary);color:var(--primary)" onclick="openStockReturnModal()"><i class="bi bi-arrow-counterclockwise"></i> Stock Return</button>
      <button class="btn btn-primary" onclick="openModal('addProductModal')"><i class="bi bi-plus-lg"></i> Add Product</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tab Navigation -->
  <div class="tab-nav" id="tabNav">
    <button class="tab-btn active" data-tab="overview" onclick="switchTab('overview',this)"><i class="bi bi-grid-1x2"></i> Overview</button>
    <button class="tab-btn" data-tab="catalog" onclick="switchTab('catalog',this)"><i class="bi bi-archive"></i> Product Catalog</button>
    <button class="tab-btn" data-tab="movements" onclick="switchTab('movements',this)"><i class="bi bi-arrow-left-right"></i> Stock Movements</button>
    <button class="tab-btn" data-tab="batches" onclick="switchTab('batches',this)"><i class="bi bi-layers"></i> Batch / Expiry</button>
    <button class="tab-btn" data-tab="alerts" onclick="switchTab('alerts',this)"><i class="bi bi-bell"></i> Alerts <span class="tab-badge" id="alertBadge" style="display:none">0</span></button>
    <button class="tab-btn" data-tab="serials" onclick="switchTab('serials',this)"><i class="bi bi-upc-scan"></i> Serial Numbers</button>
    <button class="tab-btn" data-tab="scanner" onclick="switchTab('scanner',this)"><i class="bi bi-qr-code-scan"></i> Scanner</button>
  </div>

  <div class="content-area">

    <!-- ═══ OVERVIEW TAB ═══ -->
    <div class="tab-panel active" id="tab-overview">
      <div class="stats-grid">
        <div class="stat-card blue">
          <div class="stat-icon"><i class="bi bi-archive"></i></div>
          <div class="stat-value"><?= number_format($stats['products']) ?></div>
          <div class="stat-label">Active Products</div>
        </div>
        <div class="stat-card green">
          <div class="stat-icon"><i class="bi bi-boxes"></i></div>
          <div class="stat-value"><?= number_format($stats['stock']) ?></div>
          <div class="stat-label">Total Stock Units</div>
        </div>
        <div class="stat-card purple">
          <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
          <div class="stat-value" style="font-size:20px">₱<?= number_format($stats['value'],0) ?></div>
          <div class="stat-label">Inventory Value</div>
        </div>
        <div class="stat-card warn">
          <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
          <div class="stat-value"><?= $stats['low_stock'] ?></div>
          <div class="stat-label">Low / Reorder</div>
        </div>
        <div class="stat-card danger">
          <div class="stat-icon"><i class="bi bi-calendar-x"></i></div>
          <div class="stat-value"><?= $stats['near_expiry'] ?></div>
          <div class="stat-label">Near Expiry (90d)</div>
        </div>
        <div class="stat-card info">
          <div class="stat-icon"><i class="bi bi-arrow-left-right"></i></div>
          <div class="stat-value"><?= $stats['movements_today'] ?></div>
          <div class="stat-label">Movements Today</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" id="overviewPanels">
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="bi bi-exclamation-triangle" style="color:var(--warn)"></i> Low Stock Items</span><a class="btn btn-sm btn-outline" onclick="switchTab('alerts',document.querySelector('[data-tab=alerts]'))">View All</a></div>
          <div class="card-body" id="overviewLowStock"><div class="loader"></div> Loading…</div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="bi bi-calendar-x" style="color:var(--danger)"></i> Near Expiry</span></div>
          <div class="card-body" id="overviewExpiry"><div class="loader"></div> Loading…</div>
        </div>
      </div>
    </div>

    <!-- ═══ PRODUCT CATALOG TAB ═══ -->
    <div class="tab-panel" id="tab-catalog">
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="bi bi-archive"></i> Product Catalog</span>
          <div class="toolbar">
            <select id="catalogCatFilter" onchange="filterCatalog()"><option value="">All Categories</option></select>
            <select id="catalogStatusFilter" onchange="filterCatalog()">
              <option value="">All Status</option>
              <option value="Active">Active</option>
              <option value="Low Stock">Low Stock</option>
              <option value="Out of Stock">Out of Stock</option>
            </select>
            <div class="toolbar-search"><i class="bi bi-search"></i><input type="text" id="catalogSearch" placeholder="Search…" oninput="filterCatalog()"></div>
            <?php if($canEdit): ?>
            <button class="btn btn-primary btn-sm" onclick="openModal('addProductModal')"><i class="bi bi-plus-lg"></i> Add</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="table-wrap system-table-scroll">
          <table class="tbl" id="catalogTable">
            <thead>
              <tr><th>SKU</th><th>Product Name</th><th>Brand</th><th>Category</th><th>Stock</th><th>Sellable</th><th>Cost</th><th>Sell</th><th>Expiry</th><th>Location</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody id="catalogBody"><tr class="loading-row"><td colspan="12"><span class="loader"></span> Loading products…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card" style="margin-top:16px">
        <div class="card-header">
          <span class="card-title"><i class="bi bi-arrow-counterclockwise"></i> Returns &amp; Replacements</span>
          <button type="button" class="btn btn-outline btn-sm" onclick="loadReturnReplacementHistory()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
        <div class="table-wrap system-table-scroll">
          <table class="tbl">
            <thead>
              <tr><th>Return #</th><th>Party</th><th>Original Product</th><th>Qty</th><th>Condition</th><th>Replacement</th><th>Reason</th><th>Date</th></tr>
            </thead>
            <tbody id="returnHistoryBody"><tr><td colspan="8" class="text-center text-muted" style="padding:16px">Loading return history…</td></tr></tbody>
          </table>
        </div>
        <div class="table-wrap system-table-scroll">
          <table class="tbl">
            <thead>
              <tr><th>Replacement #</th><th>Original</th><th>Replaced With</th><th>Qty</th><th>Reason</th><th>Source</th><th>Date</th></tr>
            </thead>
            <tbody id="replacementHistoryBody"><tr><td colspan="7" class="text-center text-muted" style="padding:16px">Loading replacement history…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="tab-panel" id="tab-movements">
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="bi bi-arrow-left-right"></i> Stock Movements</span>
          <div class="toolbar">
            <select id="mvtTypeFilter" onchange="loadMovements()">
              <option value="all">All Types</option>
              <option value="Stock In (Receipt)">Stock In (Receipt)</option>
              <option value="Stock Out (Issue)">Stock Out (Issue)</option>
              <option value="Transfer">Transfer</option>
              <option value="Adjustment">Adjustment</option>
              <option value="Return">Return</option>
              <option value="Damage">Damage</option>
              <option value="Expiry">Expiry</option>
            </select>
            <input type="date" id="mvtDateFrom" class="form-control" style="width:140px;padding:8px 10px" onchange="loadMovements()">
            <input type="date" id="mvtDateTo"   class="form-control" style="width:140px;padding:8px 10px" onchange="loadMovements()">
            <?php if($canEdit): ?>
            <button class="btn btn-outline btn-sm" style="border-color:var(--primary);color:var(--primary)" onclick="openStockReturnModal()"><i class="bi bi-arrow-counterclockwise"></i> Stock Return</button>
            <button class="btn btn-primary btn-sm" onclick="openModal('addMovementModal')"><i class="bi bi-plus-lg"></i> Log Movement</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="table-wrap system-table-scroll">
          <table class="tbl">
            <thead><tr><th>Ref #</th><th>Product</th><th>Type</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>PO #</th><th>SI #</th><th>DR #</th><th>SLF Docs</th><th>From</th><th>To</th><th>By</th><th>Date</th></tr></thead>
            <tbody id="movementsBody"><tr class="loading-row"><td colspan="14"><span class="loader"></span> Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══ BATCHES / EXPIRY TAB ═══ -->
    <div class="tab-panel" id="tab-batches">
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="bi bi-layers"></i> Batch & Expiry Tracker</span>
          <div class="toolbar">
            <div class="toolbar-search"><i class="bi bi-search"></i><input type="text" id="batchSearch" placeholder="Search batches…" oninput="filterBatches()"></div>
            <select id="batchStatusFilter" onchange="filterBatches()">
              <option value="">All Status</option>
              <option value="Active">Active</option>
              <option value="Expired">Expired</option>
              <option value="Depleted">Depleted</option>
              <option value="Recalled">Recalled</option>
            </select>
            <?php if($canEdit): ?>
            <button class="btn btn-success btn-sm" onclick="openModal('addBatchModal')"><i class="bi bi-plus-lg"></i> Add Batch</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="table-wrap system-table-scroll">
          <table class="tbl">
            <thead><tr><th>Batch #</th><th>Product</th><th>Mfg Date</th><th>Expiry Date</th><th>Days Left</th><th>Qty</th><th>Remaining</th><th>Supplier</th><th>Received</th><th>Status</th></tr></thead>
            <tbody id="batchesBody"><tr class="loading-row"><td colspan="10"><span class="loader"></span> Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══ ALERTS TAB ═══ -->
    <div class="tab-panel" id="tab-alerts">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" id="alertsGrid">
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="bi bi-exclamation-triangle" style="color:var(--warn)"></i> Low Stock Items</span><span id="lowStockCount" class="badge badge-yellow"></span></div>
          <div class="card-body" id="lowStockList"><div class="loader"></div> Loading…</div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="bi bi-calendar-x" style="color:var(--danger)"></i> Near Expiry (≤90 days)</span><span id="expiryCount" class="badge badge-red"></span></div>
          <div class="card-body" id="nearExpiryList"><div class="loader"></div> Loading…</div>
        </div>
      </div>
    </div>

    <!-- ═══ SERIAL NUMBERS TAB ═══ -->
    <div class="tab-panel" id="tab-serials">
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="bi bi-upc-scan"></i> Serial Number Tracker</span>
          <div class="toolbar">
            <select id="serialProductFilter" onchange="loadSerials()"><option value="">All Products</option></select>
            <select id="serialStatusFilter" onchange="loadSerials()">
              <option value="">All Status</option>
              <option value="Active">Active</option>
              <option value="In Use">In Use</option>
              <option value="Returned">Returned</option>
              <option value="Retired">Retired</option>
              <option value="Lost">Lost</option>
              <option value="Damaged">Damaged</option>
            </select>
            <?php if($canEdit): ?>
            <button class="btn btn-primary btn-sm" onclick="openModal('addSerialModal')"><i class="bi bi-plus-lg"></i> Add Serial</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="table-wrap system-table-scroll">
          <table class="tbl">
            <thead><tr><th>Serial #</th><th>Product</th><th>SKU</th><th>Status</th><th>Purchase Date</th><th>Warranty Expiry</th><th>Location</th><th>Notes</th><th>Actions</th></tr></thead>
            <tbody id="serialsBody"><tr class="loading-row"><td colspan="9"><span class="loader"></span> Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══ SCANNER TAB ═══ -->
    <div class="tab-panel" id="tab-scanner">
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="bi bi-qr-code-scan"></i> QR / Barcode Scanner</span></div>
        <div class="card-body">
          <div class="scanner-box">
            <p style="font-size:13px;color:var(--muted);margin-bottom:10px">Scan a product barcode to locate it in inventory and log a movement.</p>
            <div id="inventoryScanner"></div>
            <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
              <button class="btn btn-primary btn-sm" onclick="startInventoryScanner()"><i class="bi bi-camera"></i> Start Camera</button>
              <button class="btn btn-outline btn-sm" onclick="stopInventoryScanner()"><i class="bi bi-stop-circle"></i> Stop</button>
            </div>
            <?php if($canEdit): ?>
            <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
              <div><label class="form-label">Movement Type</label>
                <select id="inventoryScanMovement" class="form-control" style="width:160px">
                  <option value="Stock In (Receipt)">Stock In</option>
                  <option value="Stock Out (Issue)">Stock Out</option>
                  <option value="Return">Stock Return</option>
                </select>
              </div>
              <div><label class="form-label">Qty</label><input type="number" id="inventoryScanQty" class="form-control" value="1" min="1" style="width:90px"></div>
              <div><label class="form-label">Reference</label><input type="text" id="inventoryScanRef" class="form-control" placeholder="Auto" style="width:160px"></div>
              <button class="btn btn-success btn-sm" onclick="processInventoryScan()"><i class="bi bi-check-lg"></i> Process</button>
            </div>
            <label class="form-check" style="margin-top:10px"><input type="checkbox" id="inventoryScanAuto" checked> Auto-process after scan</label>
            <?php endif; ?>
            <div id="inventoryScanResult" style="margin-top:10px;font-size:13px;color:var(--text)"></div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /content-area -->
</div><!-- /main-content -->

<!-- ══════════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════ -->

<!-- Add Product Modal -->
<div class="modal-overlay" id="addProductModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-archive"></i> Add New Product</span>
      <button class="modal-close" onclick="closeModal('addProductModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="addProductForm" enctype="multipart/form-data">
        <div class="form-grid">
          <div class="form-group"><label class="form-label">SKU <span>*</span></label><input class="form-control" name="sku" required placeholder="e.g. SKU-GLV-010"></div>
          <div class="form-group"><label class="form-label">Product Name <span>*</span></label><input class="form-control" name="name" required placeholder="Brand_Category_Size_Pkg"></div>
          <div class="form-group"><label class="form-label">Brand</label><input class="form-control" name="brand" placeholder="e.g. Indoplas"></div>
          <div class="form-group"><label class="form-label">Category</label><select class="form-control" name="category_id" id="prodCatSelect"><option value="">-- Select --</option></select></div>
          <div class="form-group"><label class="form-label">Size</label><input class="form-control" name="size" placeholder="e.g. Large, 10ml"></div>
          <div class="form-group"><label class="form-label">Packaging</label><input class="form-control" name="packaging" placeholder="e.g. 100s, 1pc"></div>
          <div class="form-group"><label class="form-label">Supplier</label><select class="form-control" name="supplier_id" id="prodSupplierSelect"><option value="">-- Select --</option></select></div>
          <div class="form-group"><label class="form-label">Storage Location</label><select class="form-control" name="location_id" id="prodLocationSelect"><option value="">-- Select --</option></select></div>
          <div class="form-group"><label class="form-label">Cost Price (₱)</label><input class="form-control" name="cost_price" type="number" step="0.01" min="0" value="0.00"></div>
          <div class="form-group"><label class="form-label">Selling Price (₱)</label><input class="form-control" name="selling_price" type="number" step="0.01" min="0" value="0.00"></div>
          <div class="form-group"><label class="form-label">Initial Stock Qty</label><input class="form-control" name="quantity_in_stock" type="number" min="0" value="0"></div>
          <div class="form-group"><label class="form-label">Reorder Level</label><input class="form-control" name="reorder_level" type="number" min="0" value="50"></div>
          <div class="form-group"><label class="form-label">Reorder Quantity</label><input class="form-control" name="reorder_quantity" type="number" min="1" value="100"></div>
          <div class="form-group"><label class="form-label">Unit of Measure</label><input class="form-control" name="unit_of_measure" value="piece"></div>
          <div class="form-group"><label class="form-label">Barcode / QR</label><input class="form-control" name="barcode" placeholder="Optional"></div>
          <div class="form-group"><label class="form-label">Expiry Date</label><input class="form-control" name="expiry_date" type="date"></div>
          <div class="form-group full"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2" placeholder="Product description…"></textarea></div>
          <div class="form-group full"><label class="form-label">Product Image</label><input class="form-control product-image-input" name="product_image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-preview="addProductImagePreview" data-message="addProductImageMessage"><div id="addProductImageMessage" class="product-image-message"></div><div id="addProductImagePreview" class="product-upload-preview"><span>No image selected.</span></div></div>
          <div class="form-group full" style="display:flex;gap:24px;flex-wrap:wrap">
            <label class="form-check"><input type="checkbox" name="batch_tracking" value="1"> Enable Batch Tracking</label>
            <label class="form-check"><input type="checkbox" name="expiry_tracking" value="1"> Enable Expiry Tracking</label>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addProductModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitAddProduct()"><i class="bi bi-save"></i> Save Product</button>
    </div>
  </div>
</div>

<!-- Edit Product Modal -->
<div class="modal-overlay" id="editProductModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-pencil-square"></i> Edit Product</span>
      <button class="modal-close" onclick="closeModal('editProductModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="editProductForm" enctype="multipart/form-data">
        <input type="hidden" name="id" id="editProdId">
        <div class="form-grid">
          <div class="form-group"><label class="form-label">Product Name <span>*</span></label><input class="form-control" name="name" id="editProdName" required></div>
          <div class="form-group"><label class="form-label">Brand</label><input class="form-control" name="brand" id="editProdBrand"></div>
          <div class="form-group"><label class="form-label">Category</label><select class="form-control" name="category_id" id="editProdCat"><option value="">-- Select --</option></select></div>
          <div class="form-group"><label class="form-label">Size</label><input class="form-control" name="size" id="editProdSize"></div>
          <div class="form-group"><label class="form-label">Packaging</label><input class="form-control" name="packaging" id="editProdPkg"></div>
          <div class="form-group"><label class="form-label">Supplier</label><select class="form-control" name="supplier_id" id="editProdSupplier"><option value="">-- Select --</option></select></div>
          <div class="form-group"><label class="form-label">Location</label><select class="form-control" name="location_id" id="editProdLocation"><option value="">-- Select --</option></select></div>
          <div class="form-group"><label class="form-label">Cost Price (₱)</label><input class="form-control" name="cost_price" type="number" step="0.01" id="editProdCost"></div>
          <div class="form-group"><label class="form-label">Selling Price (₱)</label><input class="form-control" name="selling_price" type="number" step="0.01" id="editProdSell"></div>
          <div class="form-group"><label class="form-label">Qty in Stock</label><input class="form-control" name="quantity_in_stock" type="number" id="editProdQty"></div>
          <div class="form-group"><label class="form-label">Reorder Level</label><input class="form-control" name="reorder_level" type="number" id="editProdRL"></div>
          <div class="form-group"><label class="form-label">Reorder Qty</label><input class="form-control" name="reorder_quantity" type="number" id="editProdRQ"></div>
          <div class="form-group"><label class="form-label">Unit of Measure</label><input class="form-control" name="unit_of_measure" id="editProdUOM"></div>
          <div class="form-group"><label class="form-label">Expiry Date</label><input class="form-control" name="expiry_date" type="date" id="editProdExpiry"></div>
          <div class="form-group"><label class="form-label">Status</label>
            <select class="form-control" name="status" id="editProdStatus">
              <option value="Active">Active</option>
              <option value="Low Stock">Low Stock</option>
              <option value="Out of Stock">Out of Stock</option>
              <option value="Discontinued">Discontinued</option>
            </select>
          </div>
          <div class="form-group full"><label class="form-label">Description</label><textarea class="form-control" name="description" id="editProdDesc" rows="2"></textarea></div>
          <div class="form-group full"><label class="form-label">Product Image</label><input class="form-control product-image-input" name="product_image" id="editProdImage" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-preview="editProductImagePreview" data-message="editProductImageMessage"><div id="editProductImageMessage" class="product-image-message">Current image is kept unless a replacement is selected.</div><div id="editProductImagePreview" class="product-upload-preview"><span>No image available.</span></div></div>
          <div class="form-group full" style="display:flex;gap:24px;flex-wrap:wrap">
            <label class="form-check"><input type="checkbox" name="batch_tracking" id="editProdBT" value="1"> Batch Tracking</label>
            <label class="form-check"><input type="checkbox" name="expiry_tracking" id="editProdET" value="1"> Expiry Tracking</label>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('editProductModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitEditProduct()"><i class="bi bi-save"></i> Update Product</button>
    </div>
  </div>
</div>

<!-- Product Image Modal -->
<div class="modal-overlay" id="productImageModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="productImageModalTitle"><i class="bi bi-image"></i> Product Image</span>
      <button class="modal-close" onclick="closeModal('productImageModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" id="productImageModalBody">
      <div class="product-image-empty">No image available.</div>
    </div>
  </div>
</div>

<!-- Add Movement Modal -->
<div class="modal-overlay" id="addMovementModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-arrow-left-right"></i> Log Stock Movement</span>
      <button class="modal-close" onclick="closeModal('addMovementModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="addMovementForm">
        <div class="form-grid">
          <div class="form-group full"><label class="form-label">Product <span>*</span></label><select class="form-control" name="product_id" id="mvtProductSelect" required><option value="">-- Select Product --</option></select></div>
          <div class="form-group"><label class="form-label">Movement Type <span>*</span></label>
            <select class="form-control" name="movement_type" required>
              <option value="Stock In (Receipt)">Stock In (Receipt)</option>
              <option value="Stock Out (Issue)">Stock Out (Issue)</option>
              <option value="Transfer">Transfer</option>
              <option value="Adjustment">Adjustment</option>
              <option value="Return">Return</option>
              <option value="Damage">Damage</option>
              <option value="Expiry">Expiry</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Quantity <span>*</span></label><input class="form-control" name="quantity" type="number" min="1" required placeholder="0"></div>
          <div class="form-group"><label class="form-label">Unit Price (₱)</label><input class="form-control" name="unit_price" type="number" step="0.01" min="0" value="0.00"></div>
          <div class="form-group"><label class="form-label">From Location</label><select class="form-control" name="from_location_id" id="mvtFromLoc"><option value="">-- None --</option></select></div>
          <div class="form-group"><label class="form-label">To Location</label><select class="form-control" name="to_location_id" id="mvtToLoc"><option value="">-- None --</option></select></div>
          <div class="form-group"><label class="form-label">Reference #</label><input class="form-control" name="reference_number" placeholder="Auto-generated if blank"></div>
          <div class="form-group full"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2" placeholder="Optional notes…"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addMovementModal')">Cancel</button>
      <button class="btn btn-success" onclick="submitAddMovement()"><i class="bi bi-check-lg"></i> Log Movement</button>
    </div>
  </div>
</div>

<!-- Add Batch Modal -->
<div class="modal-overlay" id="addBatchModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-layers"></i> Add Product Batch</span>
      <button class="modal-close" onclick="closeModal('addBatchModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="addBatchForm">
        <div class="form-grid">
          <div class="form-group full"><label class="form-label">Product <span>*</span></label><select class="form-control" name="product_id" id="batchProductSelect" required><option value="">-- Select Product --</option></select></div>
          <div class="form-group"><label class="form-label">Batch / Lot Number <span>*</span></label><input class="form-control" name="batch_number" required placeholder="e.g. LOT-2026-001"></div>
          <div class="form-group"><label class="form-label">Supplier</label><select class="form-control" name="supplier_id" id="batchSupplierSelect"><option value="">-- Select --</option></select></div>
          <div class="form-group"><label class="form-label">Manufacture Date</label><input class="form-control" name="manufacture_date" type="date"></div>
          <div class="form-group"><label class="form-label">Expiry Date</label><input class="form-control" name="expiry_date" type="date"></div>
          <div class="form-group"><label class="form-label">Quantity <span>*</span></label><input class="form-control" name="quantity" type="number" min="1" required placeholder="0"></div>
          <div class="form-group"><label class="form-label">Received Date</label><input class="form-control" name="received_date" type="date" value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group full"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2" placeholder="Optional…"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addBatchModal')">Cancel</button>
      <button class="btn btn-success" onclick="submitAddBatch()"><i class="bi bi-save"></i> Save Batch</button>
    </div>
  </div>
</div>

<!-- Add Serial Modal -->
<div class="modal-overlay" id="addSerialModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="bi bi-upc-scan"></i> Add Serial Number</span>
      <button class="modal-close" onclick="closeModal('addSerialModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="addSerialForm">
        <div class="form-grid">
          <div class="form-group full"><label class="form-label">Product <span>*</span></label><select class="form-control" name="product_id" id="serialProductSelect" required><option value="">-- Select Product --</option></select></div>
          <div class="form-group"><label class="form-label">Serial Number <span>*</span></label><input class="form-control" name="serial_number" required placeholder="e.g. MT-THR-2026-0005"></div>
          <div class="form-group"><label class="form-label">Status</label>
            <select class="form-control" name="status">
              <option value="Active">Active</option>
              <option value="In Use">In Use</option>
              <option value="Returned">Returned</option>
              <option value="Retired">Retired</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Purchase Date</label><input class="form-control" name="purchase_date" type="date"></div>
          <div class="form-group"><label class="form-label">Warranty Expiry</label><input class="form-control" name="warranty_expiry" type="date"></div>
          <div class="form-group"><label class="form-label">Location</label><select class="form-control" name="location_id" id="serialLocationSelect"><option value="">-- Select --</option></select></div>
          <div class="form-group full"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2" placeholder="Optional…"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addSerialModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitAddSerial()"><i class="bi bi-save"></i> Save Serial</button>
    </div>
  </div>
</div>

<!-- Stock Return Modal -->
<div class="modal-overlay" id="stockReturnModal">
  <div class="modal modal-lg">
    <div class="modal-header" style="background:#f8fafc;border-bottom:1px solid var(--border)">
      <span class="modal-title" style="color:var(--primary);display:flex;align-items:center;gap:8px">
        <i class="bi bi-arrow-counterclockwise" style="font-size:20px"></i> Process Stock Return
      </span>
      <button class="modal-close" onclick="closeModal('stockReturnModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="stockReturnForm">
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Select Product <span>*</span></label>
            <select class="form-control" name="product_id" id="returnProductSelect" required onchange="onReturnProductChange()">
              <option value="">-- Select Product --</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Return Action / Type <span>*</span></label>
            <select class="form-control" name="return_type" id="returnTypeSelect" required onchange="syncReturnReplacementVisibility()">
              <option value="Customer Return (Damaged - Replacement Exchange)">Customer Return (Damaged - Issue Replacement Item)</option>
              <option value="Customer Return (Damaged)">Customer Return (Damaged - Quarantine / Log Only)</option>
              <option value="Customer Return (Restockable)">Customer Return (Restockable - Add back to sellable stock)</option>
              <option value="Supplier Return (RMA)">Supplier Return (RMA - Return item to vendor)</option>
            </select>
          </div>
          <div class="form-group full" id="returnReplacementGroup" style="display:none">
            <label class="form-label">Replacement Product <span>*</span></label>
            <select class="form-control" name="replacement_product_id" id="returnReplacementSelect">
              <option value="">-- Select a valid sellable product --</option>
            </select>
            <small class="text-muted">Must be a different, non-expired product with available sellable stock.</small>
          </div>
          <div class="form-group">
            <label class="form-label">Product Batch / Lot Number</label>
            <select class="form-control" name="batch_id" id="returnBatchSelect">
              <option value="">-- Optional / Select Batch --</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Return Quantity <span>*</span></label>
            <input class="form-control" name="quantity" type="number" min="1" required placeholder="e.g. 5">
          </div>
          <div class="form-group">
            <label class="form-label">Unit Price / Value (₱)</label>
            <input class="form-control" name="unit_price" id="returnUnitPrice" type="number" step="0.01" min="0" placeholder="0.00">
          </div>
          <div class="form-group">
            <label class="form-label">Reference Number</label>
            <input class="form-control" name="reference_number" placeholder="Auto-generated if blank (RET-2026-XXXX)">
          </div>
          <div class="form-group">
            <label class="form-label">SLF / Invoice / DR #</label>
            <input class="form-control" name="return_slf" placeholder="e.g. SLF-8842 / SI-1042">
          </div>
          <div class="form-group">
            <label class="form-label">Reason for Return <span>*</span></label>
            <select class="form-control" name="reason" id="returnReasonSelect" required>
              <option value="Return">General Return</option>
              <option value="Wrong Item">Wrong Item Delivered</option>
              <option value="Damage">Damaged / Defective Item</option>
              <option value="Expiry">Expired / Near Expiry</option>
              <option value="Overstocked">Customer Overstocked</option>
              <option value="Other">Other / Special Circumstance</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">From Location</label>
            <select class="form-control" name="from_location_id" id="returnFromLoc">
              <option value="">-- None / Customer --</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">To Storage Location</label>
            <select class="form-control" name="to_location_id" id="returnToLoc">
              <option value="">-- None / Default Location --</option>
            </select>
          </div>
          <div class="form-group full">
            <label class="form-label">Remarks / Return Notes</label>
            <textarea class="form-control" name="notes" rows="2" placeholder="Provide additional details regarding the condition or reason for return..."></textarea>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('stockReturnModal')">Cancel</button>
      <button class="btn btn-primary" style="background:#2563eb" onclick="submitStockReturn()"><i class="bi bi-check-circle-fill"></i> Complete Return</button>
    </div>
  </div>
</div>

<!-- Product Replacement Modal -->
<div class="modal-overlay" id="productReplaceModal">
  <div class="modal">
    <div class="modal-header" style="background:#f8fafc;border-bottom:1px solid var(--border)">
      <span class="modal-title" style="color:var(--primary);display:flex;align-items:center;gap:8px">
        <i class="bi bi-arrow-repeat" style="font-size:20px"></i> Product Replacement
      </span>
      <button class="modal-close" onclick="closeModal('productReplaceModal')"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="productReplaceForm">
        <input type="hidden" name="original_product_id" id="replaceOriginalId">
        <div class="form-group">
          <label class="form-label">Original Product</label>
          <div id="replaceOriginalLabel" class="form-control" style="background:#f8fafc;min-height:38px"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Reason <span>*</span></label>
          <select class="form-control" name="reason" id="replaceReason" required>
            <option value="Damaged">Damaged</option>
            <option value="Defective">Defective</option>
            <option value="Unavailable">Unavailable</option>
            <option value="Returned">Returned</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Replacement Product <span>*</span></label>
          <select class="form-control" name="replacement_product_id" id="replaceProductSelect" required>
            <option value="">-- Select a valid sellable product --</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Quantity <span>*</span></label>
          <input class="form-control" name="quantity" id="replaceQty" type="number" min="1" value="1" required>
        </div>
        <div class="form-group">
          <label class="form-label">Reference / Sales Order</label>
          <input class="form-control" name="reference_number" placeholder="Optional SO / ticket number">
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" rows="2" placeholder="Traceability notes..."></textarea>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('productReplaceModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitProductReplacement()"><i class="bi bi-check-circle-fill"></i> Record Replacement</button>
    </div>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script src="java/auth_context.js"></script>
<script src="java/inventory.js"></script>
<script src="java/inventory_module.js"></script>
<script>
function toggleSidebar(){
  const sb=document.getElementById('sidebar'),ov=document.getElementById('sidebarOverlay');
  const open=sb.classList.toggle('open');
  if(ov)ov.classList.toggle('active',open);
  document.body.style.overflow=open?'hidden':'';
}
document.querySelectorAll('.sidebar .nav-item').forEach(el=>{
  el.addEventListener('click',()=>{if(window.innerWidth<=900)toggleSidebar()});
});
</script>
</body>
</html>
