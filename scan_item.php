<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'warehouse_permissions.php';

clarenzon_session_start_any_authenticated();
route_require_login();

$role = (string)($_SESSION['role'] ?? '');
if (!canUseMobileBarcodeScanner($role)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Access Denied</title></head><body style="font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f1f5f9;"><div style="max-width:420px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;text-align:center;"><h1 style="font-size:22px;margin:0 0 8px;">Access Denied</h1><p style="color:#64748b;">You do not have permission to use the mobile barcode scanner.</p><p><a href="' . htmlspecialchars(route_home_for_role($role)) . '">Back to Dashboard</a></p></div></body></html>';
    exit();
}

require_once 'config.php';

function getScanConnection(): mysqli|false {
    if (!function_exists('clarenzon_db_connect_first')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysqli_connect_fast.php';
    }
    $conn = clarenzon_db_connect_first(['clarenzon_db', 'clarenzon_inventory', 'inventory_db']);
    return $conn instanceof mysqli ? $conn : false;
}

$conn = getScanConnection();
if ($conn === false) die('Database connection failed');

$role       = (string)($_SESSION['role'] ?? '');
$canManage  = isAdminWarehouseRole($role);
if (!$canManage && isWarehouseAssistantRole($role)) {
    $permissions = getWarehouseAssistantPermissions($conn);
    $canManage   = (bool)$permissions['can_manage_records'];
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="description" content="Hospital Inventory Barcode Scanner — scan to look up, stock, and register medical supply products.">
    <title>Barcode Scanner | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="images/clarenzon-removebg.png">
    <script src="java/favicon_notifications.js"></script>
    <link rel="stylesheet" href="css/admin-theme.css">
    <link rel="stylesheet" href="css/popup-center.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════
   RESET & BASE
══════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    width: 100%;
    overflow-x: hidden;
    font-family: 'Inter', 'Segoe UI', sans-serif;
    background: #0b1120;
    color: #e2e8f0;
    -webkit-text-size-adjust: 100%;
}

body { min-height: 100vh; min-height: 100dvh; }

/* ══════════════════════════════════════════════
   PAGE LAYOUT
══════════════════════════════════════════════ */
.scanner-page {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    min-height: 100dvh;
}

.top-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: rgba(11, 17, 32, 0.9);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(148, 163, 184, 0.1);
    position: sticky;
    top: 0;
    z-index: 100;
}

.top-header h1 {
    font-size: 17px;
    font-weight: 700;
    color: #f1f5f9;
    display: flex;
    align-items: center;
    gap: 10px;
}

.header-icon {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    flex-shrink: 0;
}

.header-right { display: flex; align-items: center; gap: 10px; }

.back-link {
    color: #94a3b8;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    padding: 7px 14px;
    border-radius: 9px;
    background: rgba(148, 163, 184, 0.08);
    border: 1px solid rgba(148, 163, 184, 0.12);
    transition: all 0.2s;
}
.back-link:hover { background: rgba(148, 163, 184, 0.15); color: #e2e8f0; }

<?php if ($canManage): ?>
.btn-register-header {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 9px;
    border: 1px solid rgba(34, 197, 94, 0.3);
    background: rgba(34, 197, 94, 0.1);
    color: #4ade80;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: 'Inter', sans-serif;
}
.btn-register-header:hover {
    background: rgba(34, 197, 94, 0.2);
    border-color: rgba(34, 197, 94, 0.5);
}
<?php endif; ?>

/* ── 2-column content ── */
.content-area {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 1fr;
    max-width: 1440px;
    width: 100%;
    margin: 0 auto;
}

/* ══════════════════════════════════════════════
   LEFT PANEL — CAMERA
══════════════════════════════════════════════ */
.camera-panel {
    display: flex;
    flex-direction: column;
    padding: 18px;
    gap: 14px;
}

.viewfinder-container {
    position: relative;
    width: 100%;
    aspect-ratio: 4/3;
    background: #111827;
    border-radius: 20px;
    overflow: hidden;
    border: 2px solid rgba(99, 102, 241, 0.3);
    box-shadow: 0 0 40px rgba(99, 102, 241, 0.08);
}

.viewfinder-container.scanning {
    border-color: rgba(99, 102, 241, 0.6);
    box-shadow: 0 0 60px rgba(99, 102, 241, 0.18);
}

.viewfinder-container.success-flash { animation: successFlash 0.55s ease; }
.viewfinder-container.register-flash { animation: registerFlash 0.55s ease; }

@keyframes successFlash {
    0%   { border-color: rgba(99,102,241,0.6); }
    35%  { border-color: #22c55e; box-shadow: 0 0 80px rgba(34,197,94,0.45); }
    100% { border-color: rgba(99,102,241,0.6); }
}
@keyframes registerFlash {
    0%   { border-color: rgba(99,102,241,0.6); }
    35%  { border-color: #8b5cf6; box-shadow: 0 0 80px rgba(139,92,246,0.45); }
    100% { border-color: rgba(99,102,241,0.6); }
}

#scanner-viewport { width: 100%; height: 100%; }
#scanner-viewport video,
#scanner-viewport canvas {
    width: 100%   !important;
    height: 100%  !important;
    object-fit: cover !important;
    position: absolute !important;
    top: 0  !important;
    left: 0 !important;
}
#scanner-viewport canvas.drawingBuffer { display: none !important; }

.scanner-overlay { position: absolute; inset: 0; pointer-events: none; z-index: 10; }

.scan-brackets {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 80%; height: 70%;
    max-width: 500px; max-height: 350px;
}

.bracket {
    position: absolute;
    width: 26px; height: 26px;
    border-color: #6366f1;
    border-style: solid;
    border-width: 0;
    transition: border-color 0.3s;
}
.scanning .bracket { border-color: #818cf8; }
.success-flash .bracket { border-color: #22c55e; }
.register-flash .bracket { border-color: #8b5cf6; }

.bracket.tl { top:0;    left:0;  border-top-width:3px;    border-left-width:3px;   border-top-left-radius:8px; }
.bracket.tr { top:0;    right:0; border-top-width:3px;    border-right-width:3px;  border-top-right-radius:8px; }
.bracket.bl { bottom:0; left:0;  border-bottom-width:3px; border-left-width:3px;   border-bottom-left-radius:8px; }
.bracket.br { bottom:0; right:0; border-bottom-width:3px; border-right-width:3px;  border-bottom-right-radius:8px; }

.scan-line {
    position: absolute;
    left: 8%; right: 8%;
    height: 2px;
    background: linear-gradient(90deg, transparent, #6366f1 20%, #a78bfa 50%, #6366f1 80%, transparent);
    box-shadow: 0 0 12px rgba(99,102,241,0.6);
    opacity: 0;
    top: 28%; z-index: 11;
}
.scanning .scan-line { opacity: 1; animation: scanMove 2.2s ease-in-out infinite; }
@keyframes scanMove { 0%,100%{top:26%} 50%{top:70%} }

/* Camera placeholder */
.camera-placeholder {
    position: absolute; inset: 0;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 14px; z-index: 5;
    background: linear-gradient(145deg, #111827 0%, #0b1120 100%);
}
.cam-icon {
    width: 72px; height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(99,102,241,0.15), rgba(139,92,246,0.15));
    border: 2px solid rgba(99,102,241,0.25);
    display: flex; align-items: center; justify-content: center;
    font-size: 32px;
    animation: camPulse 2.5s ease-in-out infinite;
}
@keyframes camPulse { 0%,100%{transform:scale(1);opacity:.9} 50%{transform:scale(1.06);opacity:1} }
.camera-placeholder p { color: #64748b; font-size: 13px; text-align: center; line-height: 1.6; }
.camera-placeholder p strong { color: #94a3b8; }

/* Scanner controls */
.scanner-status {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px;
    background: rgba(30,41,59,0.5);
    border: 1px solid rgba(148,163,184,0.08);
    border-radius: 12px;
    font-size: 13px; color: #94a3b8;
}
.status-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #475569;
    transition: background 0.3s;
    flex-shrink: 0;
}
.status-dot.active {
    background: #22c55e;
    box-shadow: 0 0 8px rgba(34,197,94,0.5);
    animation: dotPulse 1.5s ease-in-out infinite;
}
@keyframes dotPulse { 0%,100%{opacity:1} 50%{opacity:.5} }

.scanner-btn-row { display: flex; gap: 10px; }

/* Generic button base */
.btn {
    border: none;
    border-radius: 12px;
    padding: 12px 20px;
    font-family: 'Inter', sans-serif;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}

.btn-start {
    flex: 1;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    box-shadow: 0 4px 20px rgba(99,102,241,0.3);
}
.btn-start:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(99,102,241,0.4); }
.btn-start:active { transform: translateY(0); }
.btn-start:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.btn-stop {
    flex: 1;
    background: rgba(239,68,68,0.1);
    color: #f87171;
    border: 1px solid rgba(239,68,68,0.2);
}
.btn-stop:hover { background: rgba(239,68,68,0.18); }

/* Manual input */
.manual-section {
    background: rgba(30,41,59,0.5);
    border: 1px solid rgba(148,163,184,0.1);
    border-radius: 16px;
    padding: 14px;
}
.manual-section h3 {
    font-size: 12px; font-weight: 700;
    color: #64748b; text-transform: uppercase;
    letter-spacing: 0.06em; margin-bottom: 10px;
}
.input-row { display: flex; gap: 8px; }
.input-row input {
    flex: 1;
    padding: 11px 14px;
    background: rgba(11,17,32,0.8);
    border: 1px solid rgba(148,163,184,0.15);
    border-radius: 10px;
    color: #e2e8f0;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s;
}
.input-row input:focus { border-color: rgba(99,102,241,0.5); }
.input-row input::placeholder { color: #475569; }

.btn-lookup {
    background: rgba(99,102,241,0.1);
    color: #a5b4fc;
    border: 1px solid rgba(99,102,241,0.2);
    border-radius: 10px;
    padding: 11px 16px;
    font-family: 'Inter', sans-serif;
    font-size: 13px; font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    touch-action: manipulation;
}
.btn-lookup:hover { background: rgba(99,102,241,0.2); }

/* ══════════════════════════════════════════════
   RIGHT PANEL — DETAILS
══════════════════════════════════════════════ */
.details-panel {
    display: flex;
    flex-direction: column;
    padding: 18px;
    gap: 14px;
    border-left: 1px solid rgba(148,163,184,0.08);
    overflow-y: auto;
    max-height: calc(100vh - 62px);
}

.panel-card {
    background: rgba(30,41,59,0.5);
    border: 1px solid rgba(148,163,184,0.08);
    border-radius: 18px;
    padding: 18px;
    backdrop-filter: blur(8px);
}

.panel-card h3 {
    font-size: 13px; font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase; letter-spacing: 0.06em;
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 14px;
}

/* ── Product Details — 3 states ── */

/* IDLE */
.product-idle {
    text-align: center;
    padding: 28px 16px;
}
.product-idle .idle-icon {
    font-size: 42px;
    opacity: 0.35;
    display: block;
    margin-bottom: 12px;
    animation: idleFloat 3s ease-in-out infinite;
}
@keyframes idleFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
.product-idle p { color: #475569; font-size: 14px; line-height: 1.7; }
.product-idle p strong { color: #64748b; }

/* FOUND */
.product-found-header {
    display: flex; align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}
.product-name-block {}
.product-name-block .found-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px; font-weight: 700;
    background: rgba(34,197,94,0.12);
    color: #4ade80;
    border: 1px solid rgba(34,197,94,0.2);
    margin-bottom: 6px;
}
.product-name-block h4 {
    font-size: 17px; font-weight: 700;
    color: #f1f5f9; line-height: 1.3;
}
.product-name-block .product-brand {
    font-size: 13px; color: #94a3b8; margin-top: 2px;
}

.stock-display {
    text-align: right;
    flex-shrink: 0;
}
.stock-num {
    font-size: 28px; font-weight: 800; line-height: 1;
}
.stock-label { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; }

.product-meta-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.meta-item {
    background: rgba(11,17,32,0.5);
    border: 1px solid rgba(148,163,184,0.06);
    border-radius: 10px;
    padding: 9px 11px;
}
.meta-item.full { grid-column: 1/-1; }

.meta-label {
    display: block;
    font-size: 10px; font-weight: 700;
    color: #475569; text-transform: uppercase;
    letter-spacing: 0.07em; margin-bottom: 3px;
}
.meta-value {
    font-size: 13px; font-weight: 600;
    color: #cbd5e1;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.expiry-warning {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(245,158,11,0.12);
    color: #fbbf24;
    border: 1px solid rgba(245,158,11,0.2);
    border-radius: 6px;
    padding: 2px 7px;
    font-size: 11px; font-weight: 700;
}

.product-status-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px; font-weight: 700;
}
.status-active   { background:rgba(34,197,94,0.12);  color:#4ade80;  border:1px solid rgba(34,197,94,0.2); }
.status-low      { background:rgba(245,158,11,0.12); color:#fbbf24;  border:1px solid rgba(245,158,11,0.2); }
.status-out      { background:rgba(239,68,68,0.12);  color:#f87171;  border:1px solid rgba(239,68,68,0.2); }
.status-other    { background:rgba(148,163,184,0.1); color:#94a3b8;  border:1px solid rgba(148,163,184,0.15); }

/* NOT FOUND */
.product-not-found {
    text-align: center;
    padding: 20px 10px;
}
.not-found-icon {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: rgba(239,68,68,0.1);
    border: 2px solid rgba(239,68,68,0.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px;
    margin: 0 auto 12px;
    animation: notFoundPulse 2s ease-in-out infinite;
}
@keyframes notFoundPulse { 0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,0.15)} 50%{box-shadow:0 0 0 10px rgba(239,68,68,0)} }

.not-found-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px; font-weight: 700;
    background: rgba(239,68,68,0.1);
    color: #f87171;
    border: 1px solid rgba(239,68,68,0.2);
    margin-bottom: 8px;
}
.not-found-code {
    font-family: monospace;
    font-size: 14px; font-weight: 600;
    color: #94a3b8;
    background: rgba(11,17,32,0.5);
    border: 1px solid rgba(148,163,184,0.1);
    border-radius: 8px;
    padding: 6px 14px;
    display: inline-block;
    margin-bottom: 10px;
    word-break: break-all;
}
.not-found-msg { font-size: 13px; color: #64748b; line-height: 1.6; margin-bottom: 14px; }

.btn-register-now {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 13px 20px;
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: #fff;
    border: none; border-radius: 12px;
    font-family: 'Inter', sans-serif;
    font-size: 14px; font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 18px rgba(34,197,94,0.3);
}
.btn-register-now:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(34,197,94,0.4); }
.btn-register-now:active { transform: translateY(0); }

/* ══════════════════════════════════════════════
   QUICK ACTIONS — 3 buttons
══════════════════════════════════════════════ */
.quick-actions-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px;
    margin-bottom: 12px;
}

.qa-btn {
    padding: 10px 8px;
    border-radius: 12px;
    border: 1px solid rgba(148,163,184,0.12);
    background: rgba(30,41,59,0.6);
    color: #64748b;
    font-family: 'Inter', sans-serif;
    font-size: 12px; font-weight: 600;
    cursor: pointer;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 4px;
    transition: all 0.2s;
    touch-action: manipulation;
}
.qa-btn .qa-icon { font-size: 18px; }
.qa-btn:hover:not(:disabled) { background: rgba(51,65,85,0.7); color: #94a3b8; }
.qa-btn:disabled { opacity: 0.35; cursor: not-allowed; }

.qa-btn.qa-active-in {
    background: rgba(34,197,94,0.12);
    border-color: rgba(34,197,94,0.3);
    color: #4ade80;
    box-shadow: 0 0 14px rgba(34,197,94,0.1);
}
.qa-btn.qa-active-out {
    background: rgba(245,158,11,0.12);
    border-color: rgba(245,158,11,0.3);
    color: #fbbf24;
    box-shadow: 0 0 14px rgba(245,158,11,0.1);
}
.qa-btn.qa-active-reg {
    background: rgba(139,92,246,0.12);
    border-color: rgba(139,92,246,0.3);
    color: #c4b5fd;
    box-shadow: 0 0 14px rgba(139,92,246,0.1);
}
.qa-btn.qa-pulse-reg {
    animation: regPulse 1.8s ease-in-out infinite;
}
@keyframes regPulse {
    0%,100%{ box-shadow: 0 0 0 0 rgba(139,92,246,0.2); }
    50%    { box-shadow: 0 0 0 6px rgba(139,92,246,0); }
}

/* Stock action form (below buttons) */
.stock-action-form {
    display: none;
    gap: 8px;
    flex-direction: column;
    margin-top: 4px;
}
.stock-action-form.visible { display: flex; }

.stock-qty-row { display: flex; gap: 8px; align-items: center; }

.qty-input {
    width: 78px;
    padding: 10px 10px;
    background: rgba(11,17,32,0.7);
    border: 1px solid rgba(148,163,184,0.15);
    border-radius: 10px;
    color: #e2e8f0;
    font-family: 'Inter', sans-serif;
    font-size: 16px; font-weight: 700;
    text-align: center; outline: none;
}
.qty-input:focus { border-color: rgba(99,102,241,0.5); }

.notes-input {
    flex: 1;
    padding: 10px 12px;
    background: rgba(11,17,32,0.7);
    border: 1px solid rgba(148,163,184,0.15);
    border-radius: 10px;
    color: #e2e8f0;
    font-family: 'Inter', sans-serif;
    font-size: 13px; outline: none;
    transition: border-color 0.2s;
}
.notes-input:focus { border-color: rgba(99,102,241,0.4); }
.notes-input::placeholder { color: #475569; }

.btn-apply-stock {
    width: 100%;
    padding: 11px 16px;
    border-radius: 12px;
    border: none;
    font-family: 'Inter', sans-serif;
    font-size: 14px; font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.btn-apply-stock.apply-in {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: #fff;
    box-shadow: 0 4px 14px rgba(34,197,94,0.25);
}
.btn-apply-stock.apply-in:hover { box-shadow: 0 6px 22px rgba(34,197,94,0.35); }
.btn-apply-stock.apply-out {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    box-shadow: 0 4px 14px rgba(245,158,11,0.25);
}
.btn-apply-stock.apply-out:hover { box-shadow: 0 6px 22px rgba(245,158,11,0.35); }

.secondary-actions {
    display: flex; gap: 8px; flex-wrap: wrap;
    margin-top: 6px;
}
.btn-secondary {
    flex: 1;
    padding: 8px 10px;
    font-size: 12px; font-weight: 600;
    border-radius: 9px;
    cursor: pointer;
    border: 1px solid rgba(148,163,184,0.1);
    background: rgba(15,23,42,0.5);
    color: #64748b;
    transition: all 0.2s;
    font-family: 'Inter', sans-serif;
    touch-action: manipulation;
}
.btn-secondary:hover { color: #94a3b8; background: rgba(30,41,59,0.6); }
.btn-delete { color: #f87171 !important; border-color: rgba(239,68,68,0.15) !important; }
.btn-delete:hover { background: rgba(239,68,68,0.08) !important; }

/* ══════════════════════════════════════════════
   RECENT SCANS — with status badges
══════════════════════════════════════════════ */
.scan-list { list-style: none; display: flex; flex-direction: column; gap: 6px; }
.scan-list-empty { text-align: center; color: #475569; font-size: 13px; padding: 10px; }

.scan-item {
    padding: 9px 12px;
    background: rgba(11,17,32,0.4);
    border: 1px solid rgba(148,163,184,0.06);
    border-radius: 10px;
    font-size: 12px;
    display: flex; align-items: center; gap: 10px;
}
.scan-item .scan-code { font-weight: 700; color: #cbd5e1; font-size: 13px; }
.scan-item .scan-meta { color: #475569; font-size: 11px; }

.scan-badge {
    flex-shrink: 0;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 10px; font-weight: 700;
    letter-spacing: 0.03em;
    white-space: nowrap;
}
.badge-existing  { background:rgba(34,197,94,0.1);  color:#4ade80;  border:1px solid rgba(34,197,94,0.2); }
.badge-new       { background:rgba(139,92,246,0.1); color:#c4b5fd;  border:1px solid rgba(139,92,246,0.2); }
.badge-not-found { background:rgba(239,68,68,0.1);  color:#f87171;  border:1px solid rgba(239,68,68,0.2); }
.badge-action    { background:rgba(99,102,241,0.1); color:#a5b4fc;  border:1px solid rgba(99,102,241,0.2); }

/* ══════════════════════════════════════════════
   TOAST
══════════════════════════════════════════════ */
.toast {
    position: fixed;
    bottom: 28px; left: 50%;
    transform: translateX(-50%) translateY(16px);
    padding: 11px 20px;
    border-radius: 12px;
    font-family: 'Inter', sans-serif;
    font-size: 14px; font-weight: 600;
    color: #fff;
    background: rgba(30,41,59,0.97);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(148,163,184,0.12);
    box-shadow: 0 8px 32px rgba(0,0,0,0.35);
    opacity: 0; pointer-events: none;
    transition: all 0.3s;
    z-index: 9999;
    max-width: 92vw; text-align: center;
}
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast.t-success { border-left: 3px solid #22c55e; }
.toast.t-error   { border-left: 3px solid #ef4444; }
.toast.t-warn    { border-left: 3px solid #f59e0b; }
.toast.t-info    { border-left: 3px solid #6366f1; }

/* ══════════════════════════════════════════════
   EDIT PRODUCT MODAL
══════════════════════════════════════════════ */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.65);
    backdrop-filter: blur(6px);
    align-items: center; justify-content: center;
    z-index: 4000; padding: 20px;
    overflow-y: auto;
}
.modal-overlay.show { display: flex; }

.modal-box {
    width: min(480px, 100%);
    background: #1a2540;
    border: 1px solid rgba(148,163,184,0.12);
    border-radius: 20px;
    padding: 24px;
    box-shadow: 0 24px 64px rgba(0,0,0,0.5);
}
.modal-box h3 { font-size: 18px; font-weight: 700; color: #f1f5f9; margin-bottom: 16px; }
.modal-box form { display: grid; gap: 10px; }
.modal-box input, .modal-box select {
    padding: 11px 13px;
    border-radius: 10px;
    border: 1px solid rgba(148,163,184,0.15);
    background: rgba(11,17,32,0.8);
    color: #e2e8f0;
    font-family: 'Inter', sans-serif;
    font-size: 14px; outline: none;
    transition: border-color 0.2s;
    width: 100%;
}
.modal-box input:focus, .modal-box select:focus { border-color: rgba(99,102,241,0.5); }
.modal-box input::placeholder { color: #475569; }
.modal-btn-row { display: flex; gap: 10px; margin-top: 4px; }
.modal-btn-row .btn { flex: 1; }
.btn-cancel {
    background: rgba(148,163,184,0.08);
    color: #94a3b8;
    border: 1px solid rgba(148,163,184,0.12);
}
.btn-cancel:hover { background: rgba(148,163,184,0.15); }
.btn-save {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
}

/* ══════════════════════════════════════════════
   REGISTER NEW PRODUCT MODAL — 4-tab wizard
══════════════════════════════════════════════ */
.register-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.72);
    backdrop-filter: blur(8px);
    align-items: center; justify-content: center;
    z-index: 5000; padding: 16px;
}
.register-overlay.show { display: flex; }

.register-dialog {
    width: min(620px, 100%);
    max-height: min(90vh, 90dvh);
    background: #131d32;
    border: 1px solid rgba(148,163,184,0.12);
    border-radius: 22px;
    display: flex; flex-direction: column;
    box-shadow: 0 32px 80px rgba(0,0,0,0.6);
    overflow: hidden;
    position: relative;
}

/* Header */
.reg-header {
    padding: 20px 22px 0;
    flex-shrink: 0;
}
.reg-header-row {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 4px;
}
.reg-header h2 {
    font-size: 19px; font-weight: 800; color: #f1f5f9;
    display: flex; align-items: center; gap: 8px;
}
.reg-header .reg-subtitle { font-size: 13px; color: #64748b; margin-top: 2px; }

.btn-reg-close {
    width: 32px; height: 32px;
    border-radius: 50%;
    border: none;
    background: rgba(148,163,184,0.08);
    color: #94a3b8;
    font-size: 20px; line-height: 1;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s;
    flex-shrink: 0;
}
.btn-reg-close:hover { background: rgba(239,68,68,0.15); color: #f87171; }

/* Tabs */
.reg-tabs {
    display: flex;
    gap: 0;
    padding: 14px 22px 0;
    border-bottom: 1px solid rgba(148,163,184,0.08);
    flex-shrink: 0;
    overflow-x: auto;
    scrollbar-width: none;
}
.reg-tabs::-webkit-scrollbar { display: none; }

.reg-tab {
    padding: 9px 14px;
    border: none; border-bottom: 2px solid transparent;
    background: transparent;
    color: #475569;
    font-family: 'Inter', sans-serif;
    font-size: 13px; font-weight: 600;
    cursor: pointer;
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap;
    transition: all 0.2s;
    position: relative;
    bottom: -1px;
}
.reg-tab:hover { color: #94a3b8; }
.reg-tab.tab-active {
    color: #a5b4fc;
    border-bottom-color: #6366f1;
}
.reg-tab .tab-error-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #ef4444;
    display: none;
    flex-shrink: 0;
}
.reg-tab.tab-has-error .tab-error-dot { display: block; }

/* Body */
.reg-body {
    flex: 1;
    overflow-y: auto;
    padding: 18px 22px;
    scrollbar-width: thin;
    scrollbar-color: rgba(99,102,241,0.2) transparent;
}

.reg-panel { display: none; }
.reg-panel.panel-active { display: block; }

/* Form field styles inside register modal */
.form-group {
    margin-bottom: 14px;
}
.form-label {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 700;
    color: #64748b;
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 5px;
}
.form-label .req { color: #f87171; font-size: 14px; line-height: 1; }

.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 11px 13px;
    background: rgba(11,17,32,0.7);
    border: 1px solid rgba(148,163,184,0.15);
    border-radius: 10px;
    color: #e2e8f0;
    font-family: 'Inter', sans-serif;
    font-size: 14px; outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    border-color: rgba(99,102,241,0.5);
    box-shadow: 0 0 0 3px rgba(99,102,241,0.08);
}
.form-input::placeholder, .form-textarea::placeholder { color: #475569; }
.form-input.field-error { border-color: rgba(239,68,68,0.5); }

.form-select { cursor: pointer; }
.form-select option { background: #1a2540; }

.form-textarea {
    resize: vertical;
    min-height: 80px;
    line-height: 1.5;
}

.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

.form-hint { font-size: 11px; color: #475569; margin-top: 4px; }

.section-divider {
    border: none;
    border-top: 1px solid rgba(148,163,184,0.08);
    margin: 16px 0;
}

/* Footer */
.reg-footer {
    padding: 14px 22px;
    border-top: 1px solid rgba(148,163,184,0.08);
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
    background: rgba(11,17,32,0.3);
}

.reg-progress {
    display: flex; gap: 6px; align-items: center;
}
.prog-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: rgba(148,163,184,0.2);
    transition: all 0.3s;
}
.prog-dot.dot-active { background: #6366f1; box-shadow: 0 0 6px rgba(99,102,241,0.5); }
.prog-dot.dot-done { background: #22c55e; }

.reg-footer-btns { display: flex; gap: 8px; }

.btn-reg-prev {
    padding: 10px 18px;
    border-radius: 10px;
    border: 1px solid rgba(148,163,184,0.15);
    background: rgba(30,41,59,0.6);
    color: #94a3b8;
    font-family: 'Inter', sans-serif;
    font-size: 13px; font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-reg-prev:hover { color: #e2e8f0; border-color: rgba(148,163,184,0.3); }

.btn-reg-next {
    padding: 10px 22px;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: 13px; font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 3px 12px rgba(99,102,241,0.3);
}
.btn-reg-next:hover { box-shadow: 0 4px 18px rgba(99,102,241,0.45); }

.btn-reg-submit {
    padding: 10px 22px;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: 13px; font-weight: 700;
    cursor: pointer;
    display: none;
    align-items: center; gap: 6px;
    transition: all 0.2s;
    box-shadow: 0 3px 12px rgba(34,197,94,0.3);
}
.btn-reg-submit:hover { box-shadow: 0 4px 18px rgba(34,197,94,0.45); }
.btn-reg-submit:disabled { opacity: 0.55; cursor: not-allowed; }
.btn-reg-submit.show-submit { display: inline-flex; }

/* ══════════════════════════════════════════════
   MOBILE BOTTOM BAR
══════════════════════════════════════════════ */
.mobile-bar {
    display: none;
    position: fixed; bottom: 0; left: 0; right: 0;
    padding: 10px 14px;
    padding-bottom: max(10px, env(safe-area-inset-bottom));
    background: rgba(11,17,32,0.97);
    backdrop-filter: blur(16px);
    border-top: 1px solid rgba(148,163,184,0.1);
    gap: 8px;
    z-index: 2000;
    flex-direction: column;
}
.mobile-bar-row { display: flex; gap: 8px; }

/* ══════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════ */
@media (max-width: 960px) {
    .content-area { grid-template-columns: 1fr; }
    .details-panel { border-left: none; border-top: 1px solid rgba(148,163,184,0.08); max-height: none; padding-bottom: 160px; }
    .viewfinder-container { aspect-ratio: 16/10; }
}
@media (max-width: 640px) {
    .top-header { padding: 11px 14px; }
    .top-header h1 { font-size: 15px; }
    .camera-panel, .details-panel { padding: 12px; }
    .product-meta-grid { grid-template-columns: 1fr; }
    .quick-actions-row { grid-template-columns: 1fr 1fr 1fr; }
    .mobile-bar { display: flex; }
    .details-panel { padding-bottom: 160px; }
    .form-row-2 { grid-template-columns: 1fr; }
    .form-row-3 { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 420px) {
    .camera-panel { padding: 10px; gap: 10px; }
    .details-panel { padding: 10px; }
    .reg-footer { flex-direction: column; gap: 12px; align-items: stretch; }
    .reg-footer-btns { justify-content: flex-end; }
}
</style>
</head>
<body>
<div class="scanner-page">

    <!-- ── Top Header ── -->
    <header class="top-header">
        <h1>
            <div class="header-icon">🏥</div>
            Hospital Barcode Scanner
        </h1>
        <div class="header-right">
            <?php if ($canManage): ?>
            <button class="btn-register-header" id="headerRegisterBtn">
                ➕ Register Product
            </button>
            <?php endif; ?>
            <a class="back-link" href="admin_dashboard.php">← Dashboard</a>
        </div>
    </header>

    <div class="content-area">

        <!-- ══════════════════════════════════════
             LEFT: Camera Panel
        ══════════════════════════════════════ -->
        <section class="camera-panel">

            <!-- Viewfinder -->
            <div class="viewfinder-container" id="viewfinderContainer">
                <div id="scanner-viewport"></div>

                <div class="scanner-overlay">
                    <div class="scan-brackets">
                        <div class="bracket tl"></div>
                        <div class="bracket tr"></div>
                        <div class="bracket bl"></div>
                        <div class="bracket br"></div>
                    </div>
                    <div class="scan-line"></div>
                </div>

                <div class="camera-placeholder" id="cameraPlaceholder">
                    <div class="cam-icon" id="placeholderIcon">📷</div>
                    <p id="placeholderText"><strong>Start camera to scan</strong><br>or use the input below</p>
                    <p id="placeholderSub" style="font-size:11px;color:#475569;margin-top:2px;"></p>
                </div>
            </div>

            <!-- Status -->
            <div class="scanner-status">
                <span class="status-dot" id="statusDot"></span>
                <span id="statusText">Scanner idle — start camera or type barcode</span>
            </div>

            <!-- Start / Stop -->
            <div class="scanner-btn-row">
                <button class="btn btn-start" id="startScannerBtn">📷 Start Camera</button>
                <button class="btn btn-stop"  id="stopScannerBtn"  style="display:none;">⏹ Stop</button>
            </div>

            <!-- Manual / USB Input -->
            <div class="manual-section">
                <h3 id="manualInputTitle">Manual / USB Scanner Input</h3>
                <div class="input-row">
                    <input type="text" id="barcodeInput"
                           placeholder="Type or scan barcode here…"
                           autocomplete="off" inputmode="text"
                           autocapitalize="off" autocorrect="off" spellcheck="false">
                    <button class="btn-lookup" id="manualLookupBtn">🔍 Lookup</button>
                </div>
                <p id="inputHint" style="margin-top:8px;font-size:12px;color:#475569;">
                    USB scanners auto-submit on Enter. Camera scanning is also supported.
                </p>
            </div>
        </section>

        <!-- ══════════════════════════════════════
             RIGHT: Details Panel
        ══════════════════════════════════════ -->
        <section class="details-panel">

            <!-- Product Details card -->
            <div class="panel-card">
                <h3>📦 Product Details</h3>
                <div id="productInfo">
                    <!-- Rendered by JS: idle / found / not-found -->
                    <div class="product-idle">
                        <span class="idle-icon">🔍</span>
                        <p><strong>No item scanned yet.</strong><br>Point camera at a barcode,<br>or type it in the box below.</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions card -->
            <div class="panel-card">
                <h3>⚡ Quick Actions</h3>

                <!-- 3-button row -->
                <div class="quick-actions-row">
                    <button class="qa-btn" id="qaStockIn" data-action="stock_in" disabled>
                        <span class="qa-icon">✅</span>
                        Stock In
                    </button>
                    <button class="qa-btn" id="qaStockOut" data-action="stock_out" disabled>
                        <span class="qa-icon">⚠️</span>
                        Stock Out
                    </button>
                    <?php if ($canManage): ?>
                    <button class="qa-btn" id="qaRegister" data-action="register" disabled>
                        <span class="qa-icon">➕</span>
                        Register
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Stock In/Out form (shown when stock action selected) -->
                <div class="stock-action-form" id="stockActionForm">
                    <div class="stock-qty-row">
                        <input type="number" class="qty-input" id="quantityInput" min="1" value="1" placeholder="Qty">
                        <input type="text" class="notes-input" id="stockNotes" placeholder="Notes (optional)">
                    </div>
                    <button class="btn-apply-stock apply-in" id="applyStockBtn">
                        ✅ Apply Stock In
                    </button>
                </div>

                <!-- Secondary: Edit / Delete (shown when product found) -->
                <div class="secondary-actions" id="secondaryActions" style="display:none;">
                    <button class="btn-secondary" id="editProductBtn">✏️ Edit</button>
                    <?php if ($canManage): ?>
                    <button class="btn-secondary btn-delete" id="deleteProductBtn">🗑 Delete</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Scans card -->
            <div class="panel-card">
                <h3>🕐 Recent Scans</h3>
                <ul id="recentScansList" class="scan-list">
                    <li class="scan-list-empty">No scan history yet.</li>
                </ul>
            </div>

        </section>
    </div><!-- /content-area -->
</div><!-- /scanner-page -->

<!-- ══════════════════════════════════════
     Toast
══════════════════════════════════════ -->
<div class="toast" id="toast"></div>

<!-- ══════════════════════════════════════
     Mobile Bottom Bar
══════════════════════════════════════ -->
<div class="mobile-bar" id="mobileBar">
    <div class="mobile-bar-row">
        <button class="qa-btn" id="mobileQaIn"  style="flex:1;" disabled>✅ Stock In</button>
        <button class="qa-btn" id="mobileQaOut" style="flex:1;" disabled>⚠️ Stock Out</button>
        <?php if ($canManage): ?>
        <button class="qa-btn" id="mobileQaReg" style="flex:1;" disabled>➕ Register</button>
        <?php endif; ?>
    </div>
    <div class="mobile-bar-row">
        <input type="number" id="mobileQtyInput" min="1" value="1"
               style="width:60px;padding:10px;background:rgba(11,17,32,0.7);border:1px solid rgba(148,163,184,0.15);border-radius:10px;color:#e2e8f0;font-family:'Inter',sans-serif;font-size:15px;font-weight:700;text-align:center;outline:none;">
        <button class="btn-apply-stock apply-in" id="mobileApplyBtn" style="flex:1;" disabled>Apply</button>
    </div>
</div>

<!-- ══════════════════════════════════════
     Edit Product Modal
══════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ Edit Product</h3>
        <form id="editProductForm">
            <input type="hidden" id="editProductId" name="product_id">
            <input type="text" id="editName"    name="name"       placeholder="Product Name *" required>
            <input type="text" id="editSku"     name="sku"        placeholder="SKU *" required>
            <input type="text" id="editBarcode" name="barcode"    placeholder="Barcode">
            <input type="text" id="editLocation" name="location"  placeholder="Location">
            <input type="number" id="editPrice" name="unit_price" step="0.01" placeholder="Unit Price (₱)">
            <div class="modal-btn-row">
                <button class="btn btn-cancel" type="button" id="cancelEditBtn">Cancel</button>
                <button class="btn btn-save"   type="submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════
     Register New Product Modal — 4 tabs
══════════════════════════════════════ -->
<div class="register-overlay" id="registerModal">
    <div class="register-dialog">

        <!-- Header -->
        <div class="reg-header">
            <div class="reg-header-row">
                <div>
                    <h2>➕ Register New Product</h2>
                    <p class="reg-subtitle" id="regSubtitle">Fill in the product details to add it to inventory.</p>
                </div>
                <button class="btn-reg-close" id="closeRegisterBtn" type="button">×</button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="reg-tabs">
            <button class="reg-tab tab-active" data-reg-tab="0" id="regTab0">
                📋 Basic
                <span class="tab-error-dot"></span>
            </button>
            <button class="reg-tab" data-reg-tab="1" id="regTab1">
                📦 Inventory
                <span class="tab-error-dot"></span>
            </button>
            <button class="reg-tab" data-reg-tab="2" id="regTab2">
                🏢 Supplier
                <span class="tab-error-dot"></span>
            </button>
            <button class="reg-tab" data-reg-tab="3" id="regTab3">
                🏥 Hospital
                <span class="tab-error-dot"></span>
            </button>
        </div>

        <!-- Body -->
        <div class="reg-body">
            <form id="registerForm" autocomplete="off">

                <!-- ── Tab 0: Basic Information ── -->
                <div class="reg-panel panel-active" id="regPanel0">

                    <div class="form-group">
                        <label class="form-label">Barcode / SKU <span class="req">*</span></label>
                        <input class="form-input" type="text" id="regBarcode" name="barcode"
                               placeholder="Scan or type barcode / product code" required autocomplete="off">
                        <p class="form-hint">This will be the unique identifier for this product.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Product Name <span class="req">*</span></label>
                        <input class="form-input" type="text" id="regName" name="name"
                               placeholder="e.g. Gloves_Indoplas_Large_100s" required>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Brand</label>
                            <input class="form-input" type="text" id="regBrand" name="brand"
                                   placeholder="e.g. Indoplas">
                        </div>
                        <div class="form-group">
                            <label class="form-label">SKU <span style="font-weight:400;text-transform:none;color:#475569;">(auto if blank)</span></label>
                            <input class="form-input" type="text" id="regSku" name="sku"
                                   placeholder="Leave blank to auto-generate">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select class="form-select" id="regCategory" name="category_id">
                                <option value="">— Select Category —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unit of Measure</label>
                            <select class="form-select" id="regUom" name="unit_of_measure">
                                <option value="piece">Piece / pcs</option>
                                <option value="box">Box</option>
                                <option value="bottle">Bottle</option>
                                <option value="pack">Pack</option>
                                <option value="pair">Pair</option>
                                <option value="set">Set</option>
                                <option value="bag">Bag</option>
                                <option value="roll">Roll</option>
                                <option value="vial">Vial</option>
                                <option value="ampoule">Ampoule</option>
                                <option value="syringe">Syringe</option>
                                <option value="tube">Tube</option>
                                <option value="strip">Strip</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea class="form-textarea" id="regDescription" name="description"
                                  placeholder="Brief description of the product (optional)"></textarea>
                    </div>
                </div>

                <!-- ── Tab 1: Inventory Information ── -->
                <div class="reg-panel" id="regPanel1">

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Initial Stock Qty</label>
                            <input class="form-input" type="number" id="regQty" name="quantity"
                                   min="0" value="0" placeholder="0">
                            <p class="form-hint">Enter 0 if no stock yet.</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reorder Level</label>
                            <input class="form-input" type="number" id="regReorder" name="reorder_level"
                                   min="0" value="50" placeholder="50">
                            <p class="form-hint">Alert threshold for low stock.</p>
                        </div>
                    </div>

                    <hr class="section-divider">

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Purchase / Cost Price <span style="color:#94a3b8;">(₱)</span></label>
                            <input class="form-input" type="number" id="regCostPrice" name="cost_price"
                                   min="0" step="0.01" value="0.00" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Selling Price <span style="color:#94a3b8;">(₱)</span></label>
                            <input class="form-input" type="number" id="regSellingPrice" name="selling_price"
                                   min="0" step="0.01" value="0.00" placeholder="0.00">
                        </div>
                    </div>

                    <div style="background:rgba(99,102,241,0.06);border:1px solid rgba(99,102,241,0.15);border-radius:10px;padding:10px 12px;">
                        <p style="font-size:12px;color:#64748b;line-height:1.6;">
                            💡 If initial stock qty &gt; 0, a <strong style="color:#94a3b8;">Stock In (Receipt)</strong> movement will be automatically created.
                        </p>
                    </div>
                </div>

                <!-- ── Tab 2: Supplier Information ── -->
                <div class="reg-panel" id="regPanel2">

                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select class="form-select" id="regSupplier" name="supplier_id">
                            <option value="">— Select Supplier (optional) —</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Supplier Product Code</label>
                        <input class="form-input" type="text" id="regSupplierCode" name="supplier_product_code"
                               placeholder="Supplier's own product code (optional)">
                        <p class="form-hint">The code used by the supplier to identify this product.</p>
                    </div>

                    <div style="background:rgba(30,41,59,0.4);border:1px solid rgba(148,163,184,0.08);border-radius:10px;padding:12px;" id="supplierInfoBox" style="display:none;">
                        <p style="font-size:12px;color:#64748b;">Select a supplier above to link this product to their catalog.</p>
                    </div>
                </div>

                <!-- ── Tab 3: Hospital / Storage ── -->
                <div class="reg-panel" id="regPanel3">

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Batch / Lot Number</label>
                            <input class="form-input" type="text" id="regBatchNo" name="batch_number"
                                   placeholder="e.g. LOT-2026-001">
                            <p class="form-hint">Leave blank if not applicable.</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Expiry Date</label>
                            <input class="form-input" type="date" id="regExpiry" name="expiry_date">
                            <p class="form-hint">Leave blank for non-expiring items.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Storage Location</label>
                        <select class="form-select" id="regLocation" name="location_id">
                            <option value="">— Select Location —</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Additional Notes</label>
                        <textarea class="form-textarea" id="regNotes" name="notes"
                                  placeholder="Any additional notes about this product or its storage requirements…"></textarea>
                    </div>
                </div>

            </form><!-- /registerForm -->
        </div><!-- /reg-body -->

        <!-- Footer -->
        <div class="reg-footer">
            <div class="reg-progress">
                <div class="prog-dot dot-active" id="dot0"></div>
                <div class="prog-dot" id="dot1"></div>
                <div class="prog-dot" id="dot2"></div>
                <div class="prog-dot" id="dot3"></div>
            </div>
            <div class="reg-footer-btns">
                <button class="btn-reg-prev" id="regPrevBtn" style="display:none;">← Previous</button>
                <button class="btn-reg-next" id="regNextBtn">Next →</button>
                <button class="btn-reg-submit" id="regSubmitBtn" type="button">➕ Register Product</button>
            </div>
        </div>

    </div><!-- /register-dialog -->
</div><!-- /register-overlay -->

<!-- QuaggaJS -->
<script src="java/app_dialog.js?v=<?= time() ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>

<script>
(function () {
    'use strict';

    /* ══════════════════════════════════════════════
       DEVICE DETECTION
    ══════════════════════════════════════════════ */
    const isMobile = /Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
        || (navigator.maxTouchPoints > 1 && window.innerWidth < 1024);

    /* ══════════════════════════════════════════════
       STATE
    ══════════════════════════════════════════════ */
    const state = {
        scanCode:       '',          // last code that was scanned/entered
        scanState:      'idle',      // 'idle' | 'found' | 'not_found'
        currentProduct: null,        // full product object (if found)
        selectedAction: 'stock_in',  // 'stock_in' | 'stock_out' | 'register'
        scannerRunning: false,
        usbBuffer:      '',
        usbTimer:       null,
        lastScanTime:   0,
        lookupData:     null,        // { categories, suppliers, locations }
        lookupLoaded:   false,
        regCurrentTab:  0,           // 0-3
    };

    /* ══════════════════════════════════════════════
       DOM REFERENCES
    ══════════════════════════════════════════════ */
    const $  = id => document.getElementById(id);
    const $$ = sel => document.querySelectorAll(sel);

    const toastEl           = $('toast');
    const productInfo       = $('productInfo');
    const barcodeInput      = $('barcodeInput');
    const viewfinderCont    = $('viewfinderContainer');
    const cameraPlaceholder = $('cameraPlaceholder');
    const statusDot         = $('statusDot');
    const statusText        = $('statusText');
    const startBtn          = $('startScannerBtn');
    const stopBtn           = $('stopScannerBtn');
    const quantityInput     = $('quantityInput');
    const mobileQtyInput    = $('mobileQtyInput');
    const stockActionForm   = $('stockActionForm');
    const applyStockBtn     = $('applyStockBtn');
    const secondaryActions  = $('secondaryActions');
    const recentScansList   = $('recentScansList');
    const mobileApplyBtn    = $('mobileApplyBtn');

    /* ── Quick action buttons ── */
    const qaIn  = $('qaStockIn');
    const qaOut = $('qaStockOut');
    const qaReg = $('qaRegister');           // may be null (non-canManage)
    const mobIn  = $('mobileQaIn');
    const mobOut = $('mobileQaOut');
    const mobReg = $('mobileQaReg');         // may be null

    /* ── Register modal ── */
    const registerModal = $('registerModal');
    const regBarcode    = $('regBarcode');
    const regName       = $('regName');
    const regSubmitBtn  = $('regSubmitBtn');
    const regNextBtn    = $('regNextBtn');
    const regPrevBtn    = $('regPrevBtn');

    /* ══════════════════════════════════════════════
       UTILITIES
    ══════════════════════════════════════════════ */
    function esc(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    let toastTimer = null;
    function showToast(msg, type = 'success') {
        clearTimeout(toastTimer);
        toastEl.textContent = msg;
        toastEl.className   = `toast show t-${type}`;
        toastTimer = setTimeout(() => { toastEl.className = 'toast'; }, 3000);
    }

    function triggerFeedback() {
        if (navigator.vibrate) navigator.vibrate([80, 40, 80]);
        try {
            const ctx  = new (window.AudioContext || window.webkitAudioContext)();
            const osc  = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain); gain.connect(ctx.destination);
            osc.type = 'sine'; osc.frequency.value = 1400;
            gain.gain.value = 0.12;
            osc.start();
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.12);
            osc.stop(ctx.currentTime + 0.12);
        } catch (_) { /* no audio */ }
    }

    function flashSuccess()  { viewfinderCont.classList.add('success-flash');  setTimeout(() => viewfinderCont.classList.remove('success-flash'),  660); }
    function flashRegister() { viewfinderCont.classList.add('register-flash'); setTimeout(() => viewfinderCont.classList.remove('register-flash'), 660); }

    /* ══════════════════════════════════════════════
       API HELPER
    ══════════════════════════════════════════════ */
    async function fetchJSON(url, options = {}) {
        const resp = await fetch(url, options);
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) throw new Error(data.message || `HTTP ${resp.status}`);
        return data;
    }

    /* ══════════════════════════════════════════════
       RENDER: Product Panel
    ══════════════════════════════════════════════ */
    function renderProductPanel() {
        if (state.scanState === 'idle') {
            productInfo.innerHTML = `
            <div class="product-idle">
                <span class="idle-icon">🔍</span>
                <p><strong>No item scanned yet.</strong><br>
                Point camera at a barcode,<br>or type it in the input below.</p>
            </div>`;
            return;
        }

        if (state.scanState === 'not_found') {
            productInfo.innerHTML = `
            <div class="product-not-found">
                <div class="not-found-icon">🔴</div>
                <div class="not-found-badge">✕ Not Registered</div>
                <div class="not-found-code">${esc(state.scanCode)}</div>
                <p class="not-found-msg">This barcode is <strong>not registered</strong> in the inventory.<br>
                Would you like to register this product?</p>
                <?php if ($canManage): ?>
                <button class="btn-register-now" onclick="window.openRegisterModal('${esc(state.scanCode)}')">
                    ➕ Register New Product
                </button>
                <?php endif; ?>
            </div>`;
            return;
        }

        /* ── FOUND ── */
        const p = state.currentProduct;
        const qty = parseInt(p.quantity_in_stock) || 0;
        const reorder = parseInt(p.reorder_level) || 50;

        let qtyColor = '#4ade80';
        if (qty <= 0) qtyColor = '#f87171';
        else if (qty <= reorder) qtyColor = '#fbbf24';

        let statusBadge = '';
        const s = (p.status || 'Active').toLowerCase();
        if (s.includes('active'))      statusBadge = '<span class="product-status-badge status-active">● Active</span>';
        else if (s.includes('low'))    statusBadge = '<span class="product-status-badge status-low">⚠ Low Stock</span>';
        else if (s.includes('out'))    statusBadge = '<span class="product-status-badge status-out">✕ Out of Stock</span>';
        else                           statusBadge = `<span class="product-status-badge status-other">${esc(p.status)}</span>`;

        let expiryHtml = '—';
        if (p.expiry_date) {
            const expDate  = new Date(p.expiry_date);
            const today    = new Date();
            const daysLeft = Math.ceil((expDate - today) / 86400000);
            if (daysLeft < 0) {
                expiryHtml = `<span class="expiry-warning">⚠ EXPIRED</span>`;
            } else if (daysLeft <= 90) {
                expiryHtml = `${esc(p.expiry_date)} <span class="expiry-warning">⚠ ${daysLeft}d</span>`;
            } else {
                expiryHtml = esc(p.expiry_date);
            }
        }

        productInfo.innerHTML = `
        <div class="product-found-header">
            <div class="product-name-block">
                <div class="found-badge">✓ Product Found</div>
                <h4>${esc(p.name || '')}</h4>
                <div class="product-brand">${esc(p.brand || '—')}</div>
            </div>
            <div class="stock-display">
                <div class="stock-num" style="color:${qtyColor}">${qty}</div>
                <div class="stock-label">In Stock</div>
                <div style="margin-top:4px;">${statusBadge}</div>
            </div>
        </div>
        <div class="product-meta-grid">
            <div class="meta-item">
                <span class="meta-label">SKU</span>
                <span class="meta-value">${esc(p.sku || '—')}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Barcode</span>
                <span class="meta-value">${esc(p.barcode || '—')}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Category</span>
                <span class="meta-value">${esc(p.category_name || '—')}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Unit</span>
                <span class="meta-value">${esc(p.unit_of_measure || '—')}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Reorder Level</span>
                <span class="meta-value">${reorder}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Unit Price</span>
                <span class="meta-value">₱${Number(p.unit_price || 0).toFixed(2)}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Expiry Date</span>
                <span class="meta-value">${expiryHtml}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Supplier</span>
                <span class="meta-value">${esc(p.supplier_name || '—')}</span>
            </div>
            <div class="meta-item full">
                <span class="meta-label">Storage Location</span>
                <span class="meta-value">${esc(p.location || 'Not assigned')}</span>
            </div>
        </div>`;
    }

    /* ══════════════════════════════════════════════
       RENDER: Quick Actions
    ══════════════════════════════════════════════ */
    function renderQuickActions() {
        const isFound    = state.scanState === 'found';
        const isNotFound = state.scanState === 'not_found';

        /* Stock In/Out buttons */
        if (qaIn)  { qaIn.disabled  = !isFound; }
        if (qaOut) { qaOut.disabled = !isFound; }
        if (mobIn)  { mobIn.disabled  = !isFound; }
        if (mobOut) { mobOut.disabled = !isFound; }

        /* Register button */
        if (qaReg) {
            qaReg.disabled = !isNotFound;
            qaReg.classList.toggle('qa-pulse-reg', isNotFound);
        }
        if (mobReg) {
            mobReg.disabled = !isNotFound;
            mobReg.classList.toggle('qa-pulse-reg', isNotFound);
        }

        /* Mobile apply */
        if (mobileApplyBtn) mobileApplyBtn.disabled = !isFound;

        /* Show/hide stock form */
        const showForm = isFound && (state.selectedAction === 'stock_in' || state.selectedAction === 'stock_out');
        stockActionForm.classList.toggle('visible', showForm);

        /* Apply button color / label */
        if (state.selectedAction === 'stock_in') {
            applyStockBtn.className = 'btn-apply-stock apply-in';
            applyStockBtn.textContent = '✅ Apply Stock In';
            if (mobileApplyBtn) { mobileApplyBtn.className = 'btn-apply-stock apply-in'; mobileApplyBtn.textContent = 'Apply'; }
        } else {
            applyStockBtn.className = 'btn-apply-stock apply-out';
            applyStockBtn.textContent = '⚠️ Apply Stock Out';
            if (mobileApplyBtn) { mobileApplyBtn.className = 'btn-apply-stock apply-out'; mobileApplyBtn.textContent = 'Apply'; }
        }

        /* Secondary actions (Edit/Delete) */
        secondaryActions.style.display = isFound ? 'flex' : 'none';
    }

    /* ══════════════════════════════════════════════
       RENDER: Recent Scans
    ══════════════════════════════════════════════ */
    const STATUS_BADGE = {
        existing_product:     '<span class="scan-badge badge-existing">Existing</span>',
        new_product_registered:'<span class="scan-badge badge-new">New Registered</span>',
        product_not_found:    '<span class="scan-badge badge-not-found">Not Found</span>',
    };
    const ACTION_BADGE = {
        stock_in:             '<span class="scan-badge badge-action">Stock In</span>',
        stock_out:            '<span class="scan-badge badge-action">Stock Out</span>',
        product_updated:      '<span class="scan-badge badge-action">Updated</span>',
        product_deleted:      '<span class="scan-badge badge-action">Deleted</span>',
    };

    async function loadRecentScans() {
        try {
            const data = await fetchJSON('scanner_api.php/recent-scans');
            recentScansList.innerHTML = '';
            if (!data.data || data.data.length === 0) {
                recentScansList.innerHTML = '<li class="scan-list-empty">No scan history yet.</li>';
                return;
            }
            data.data.forEach(item => {
                const li    = document.createElement('li');
                li.className = 'scan-item';
                const badge = STATUS_BADGE[item.scan_status] || STATUS_BADGE['existing_product'];
                const acBadge = ACTION_BADGE[item.action_performed] || '';
                const name  = item.product_name ? ` <span style="color:#64748b;">— ${esc(item.product_name)}</span>` : '';
                const time  = item.created_at ? item.created_at.substring(0,16).replace('T',' ') : '';
                li.innerHTML = `
                    <div style="flex:1;min-width:0;">
                        <div class="scan-code">${esc(item.scanned_code)}${name}</div>
                        <div class="scan-meta">${time}</div>
                    </div>
                    <div style="display:flex;gap:4px;flex-direction:column;align-items:flex-end;">
                        ${badge}
                        ${acBadge}
                    </div>`;
                recentScansList.appendChild(li);
            });
        } catch (e) {
            console.warn('loadRecentScans error:', e);
        }
    }

    /* ══════════════════════════════════════════════
       LOOKUP BARCODE — core scanning logic
    ══════════════════════════════════════════════ */
    async function lookupBarcode(code, device = 'camera') {
        code = code.trim();
        if (!code) return;

        /* Debounce: skip if same code within 2.5s */
        const now = Date.now();
        if (code === state.scanCode && now - state.lastScanTime < 2500) return;
        state.scanCode    = code;
        state.lastScanTime = now;

        barcodeInput.value = code;

        try {
            const resp = await fetchJSON(`scanner_api.php/product/${encodeURIComponent(code)}`);
            state.scanState      = 'found';
            state.currentProduct = resp.data;
            state.selectedAction = 'stock_in';

            renderProductPanel();
            renderQuickActions();
            triggerFeedback();
            flashSuccess();
            showToast(`✓ ${resp.data.name || 'Product'} found`, 'success');
            await loadRecentScans();

        } catch (err) {
            state.scanState      = 'not_found';
            state.currentProduct = null;

            renderProductPanel();
            renderQuickActions();
            showToast('Not registered — click Register Product', 'warn');
            await loadRecentScans();
        }
    }

    /* ══════════════════════════════════════════════
       STOCK IN / STOCK OUT
    ══════════════════════════════════════════════ */
    async function applyStockAction(action) {
        if (!state.currentProduct) {
            showToast('Scan an item first', 'error');
            return;
        }
        const qtyEl  = (window.innerWidth <= 640 && mobileQtyInput) ? mobileQtyInput : quantityInput;
        const qty    = parseInt(qtyEl.value || '1', 10);
        const notes  = ($('stockNotes') ? $('stockNotes').value.trim() : '');
        if (!qty || qty < 1) { showToast('Enter a valid quantity', 'error'); return; }

        const route = action === 'stock_in' ? 'stock-in' : 'stock-out';
        const barcode = state.currentProduct.barcode || state.currentProduct.sku;

        const bodyParams = new URLSearchParams();
        bodyParams.append('barcode', barcode);
        bodyParams.append('quantity', qty);
        bodyParams.append('notes', notes);
        bodyParams.append('device_used', state.scannerRunning ? 'camera' : 'scanner');

        if (action === 'stock_in') {
            const poVal = ($('refPoNumber') ? $('refPoNumber').value.trim() : '');
            const siVal = ($('refSiNumber') ? $('refSiNumber').value.trim() : '');
            const drVal = ($('refDrNumber') ? $('refDrNumber').value.trim() : '');
            const retVal = ($('refReturnSlf') ? $('refReturnSlf').value.trim() : '');
            const chgVal = ($('refChargeSlf') ? $('refChargeSlf').value.trim() : '');
            const unitVal = state.currentProduct.unit_of_measure || 'piece';

            bodyParams.append('po_number', poVal);
            bodyParams.append('si_number', siVal);
            bodyParams.append('dr_number', drVal);
            bodyParams.append('return_slf', retVal);
            bodyParams.append('charge_slf', chgVal);
            bodyParams.append('quantity_unit', unitVal);
        }

        applyStockBtn.disabled = true;
        applyStockBtn.textContent = '⏳ Applying…';
        if (mobileApplyBtn) mobileApplyBtn.disabled = true;

        try {
            const data = await fetchJSON(`scanner_api.php/${route}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: bodyParams,
            });
            showToast(data.message || 'Stock updated ✓', 'success');
            triggerFeedback();

            /* Clear inputs */
            if ($('refPoNumber')) $('refPoNumber').value = '';
            if ($('refSiNumber')) $('refSiNumber').value = '';
            if ($('refDrNumber')) $('refDrNumber').value = '';
            if ($('refReturnSlf')) $('refReturnSlf').value = '';
            if ($('refChargeSlf')) $('refChargeSlf').value = '';
            if ($('stockNotes')) $('stockNotes').value = '';
            if (quantityInput) quantityInput.value = '1';
            if (mobileQtyInput) mobileQtyInput.value = '1';

            /* Re-lookup to refresh stock number */
            state.lastScanTime = 0; // force re-lookup
            await lookupBarcode(barcode, 'scanner');
        } catch (e) {
            showToast(e.message || 'Action failed', 'error');
        } finally {
            applyStockBtn.disabled = false;
            renderQuickActions();
            if (mobileApplyBtn) mobileApplyBtn.disabled = false;
        }
    }

    /* ══════════════════════════════════════════════
       ACTION SELECTION
    ══════════════════════════════════════════════ */
    function selectAction(action) {
        state.selectedAction = action;

        /* Reset all button active classes */
        [qaIn, qaOut, qaReg, mobIn, mobOut, mobReg].forEach(b => {
            if (b) b.classList.remove('qa-active-in','qa-active-out','qa-active-reg');
        });

        if (action === 'stock_in') {
            if (qaIn)  qaIn.classList.add('qa-active-in');
            if (mobIn) mobIn.classList.add('qa-active-in');
        } else if (action === 'stock_out') {
            if (qaOut)  qaOut.classList.add('qa-active-out');
            if (mobOut) mobOut.classList.add('qa-active-out');
        } else if (action === 'register') {
            if (qaReg)  qaReg.classList.add('qa-active-reg');
            if (mobReg) mobReg.classList.add('qa-active-reg');
            window.openRegisterModal(state.scanCode || '');
            return; // don't call renderQuickActions (modal handles it)
        }

        renderQuickActions();
    }

    /* ══════════════════════════════════════════════
       LOAD LOOKUP DATA (categories / suppliers / locations)
    ══════════════════════════════════════════════ */
    async function loadLookupData() {
        if (state.lookupLoaded) return;
        try {
            const resp = await fetchJSON('scanner_api.php/lookup-data');
            state.lookupData   = resp.data;
            state.lookupLoaded = true;
            populateDropdowns();
        } catch (e) {
            console.warn('loadLookupData error:', e);
        }
    }

    function populateDropdowns() {
        const d = state.lookupData;
        if (!d) return;

        const catSel  = $('regCategory');
        const supSel  = $('regSupplier');
        const locSel  = $('regLocation');

        if (catSel) {
            catSel.innerHTML = '<option value="">— Select Category (optional) —</option>';
            d.categories.forEach(c => {
                catSel.insertAdjacentHTML('beforeend', `<option value="${c.id}">${esc(c.name)}</option>`);
            });
        }
        if (supSel) {
            supSel.innerHTML = '<option value="">— Select Supplier (optional) —</option>';
            d.suppliers.forEach(s => {
                supSel.insertAdjacentHTML('beforeend', `<option value="${s.id}">${esc(s.name)}</option>`);
            });
        }
        if (locSel) {
            locSel.innerHTML = '<option value="">— Select Location (optional) —</option>';
            d.locations.forEach(l => {
                locSel.insertAdjacentHTML('beforeend', `<option value="${l.id}">[${esc(l.code)}] ${esc(l.label)}</option>`);
            });
        }
    }

    /* ══════════════════════════════════════════════
       REGISTER MODAL — open / close / tabs
    ══════════════════════════════════════════════ */
    window.openRegisterModal = function (barcode) {
        barcode = (barcode || '').trim();

        /* Reset form */
        const form = $('registerForm');
        if (form) form.reset();

        /* Pre-fill barcode */
        if (regBarcode) {
            regBarcode.value    = barcode;
            regBarcode.readOnly = !!barcode;
            regBarcode.style.background = barcode ? 'rgba(30,41,59,0.5)' : '';
            regBarcode.style.color      = barcode ? '#94a3b8' : '';
            regBarcode.style.cursor     = barcode ? 'not-allowed' : '';
        }

        /* Subtitle */
        const subtitle = $('regSubtitle');
        if (subtitle) {
            subtitle.textContent = barcode
                ? `Barcode "${barcode}" not found — register it now.`
                : 'Fill in the product details to add it to inventory.';
        }

        /* Reset tabs */
        goToRegTab(0);
        $$('.reg-tab').forEach(t => t.classList.remove('tab-has-error'));
        $$('.form-input').forEach(i => i.classList.remove('field-error'));

        /* Load dropdown data */
        loadLookupData();

        registerModal.classList.add('show');
        setTimeout(() => { if (regName) regName.focus(); }, 120);
    };

    function closeRegisterModal() {
        registerModal.classList.remove('show');
    }

    function goToRegTab(idx) {
        state.regCurrentTab = idx;

        /* Panels */
        for (let i = 0; i < 4; i++) {
            const panel = $(`regPanel${i}`);
            if (panel) panel.classList.toggle('panel-active', i === idx);
        }
        /* Tab buttons */
        $$('.reg-tab').forEach(btn => {
            btn.classList.toggle('tab-active', parseInt(btn.dataset.regTab) === idx);
        });
        /* Progress dots */
        for (let i = 0; i < 4; i++) {
            const dot = $(`dot${i}`);
            if (dot) {
                dot.classList.toggle('dot-active', i === idx);
                dot.classList.toggle('dot-done', i < idx);
            }
        }
        /* Prev / Next / Submit buttons */
        regPrevBtn.style.display = idx > 0 ? '' : 'none';
        if (idx < 3) {
            regNextBtn.style.display = '';
            regSubmitBtn.classList.remove('show-submit');
            regSubmitBtn.style.display = 'none';
        } else {
            regNextBtn.style.display = 'none';
            regSubmitBtn.classList.add('show-submit');
            regSubmitBtn.style.display = '';
        }
    }

    /* ── Tab click ── */
    $$('[data-reg-tab]').forEach(btn => {
        btn.addEventListener('click', () => goToRegTab(parseInt(btn.dataset.regTab)));
    });

    /* ── Next button ── */
    regNextBtn.addEventListener('click', () => {
        if (state.regCurrentTab < 3) goToRegTab(state.regCurrentTab + 1);
    });

    /* ── Prev button ── */
    regPrevBtn.addEventListener('click', () => {
        if (state.regCurrentTab > 0) goToRegTab(state.regCurrentTab - 1);
    });

    /* ── Barcode field → update hidden / readOnly logic ── */
    if (regBarcode) {
        regBarcode.addEventListener('input', () => {
            if (!regBarcode.readOnly) {
                state.scanCode = regBarcode.value.trim();
            }
        });
    }

    /* ── Close ── */
    $('closeRegisterBtn').addEventListener('click', closeRegisterModal);
    registerModal.addEventListener('click', e => { if (e.target === registerModal) closeRegisterModal(); });

    /* ══════════════════════════════════════════════
       REGISTER FORM SUBMIT
    ══════════════════════════════════════════════ */
    regSubmitBtn.addEventListener('click', async () => {
        /* Sync barcode if manual entry */
        const barcodeVal = regBarcode ? regBarcode.value.trim() : '';
        const nameVal    = regName    ? regName.value.trim()    : '';

        /* Clear previous errors */
        $$('.form-input').forEach(i => i.classList.remove('field-error'));
        $$('.reg-tab').forEach(t => t.classList.remove('tab-has-error'));

        /* Validate Tab 0 (Basic) */
        let errors = [];
        if (!barcodeVal) {
            errors.push({ field: regBarcode, tab: 0, msg: 'Barcode is required' });
        }
        if (!nameVal) {
            errors.push({ field: regName, tab: 0, msg: 'Product name is required' });
        }

        if (errors.length > 0) {
            errors.forEach(e => {
                if (e.field) e.field.classList.add('field-error');
                const tabBtn = document.querySelector(`[data-reg-tab="${e.tab}"]`);
                if (tabBtn) tabBtn.classList.add('tab-has-error');
            });
            /* Navigate to first error tab */
            goToRegTab(errors[0].tab);
            showToast(errors[0].msg, 'error');
            if (errors[0].field) errors[0].field.focus();
            return;
        }

        /* Serialize form */
        const form    = $('registerForm');
        const payload = new URLSearchParams();
        payload.append('barcode', barcodeVal);
        payload.append('name',    nameVal);

        const fields = ['brand','sku','description','unit_of_measure',
                        'quantity','reorder_level','cost_price','selling_price',
                        'category_id','supplier_id','supplier_product_code',
                        'batch_number','expiry_date','location_id','notes'];
        fields.forEach(f => {
            const el = form.elements[f];
            if (el) payload.append(f, el.value || '');
        });

        regSubmitBtn.disabled      = true;
        regSubmitBtn.textContent   = '⏳ Registering…';

        try {
            const data = await fetchJSON('scanner_api.php/register-product', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body:    payload,
            });

            showToast(`✓ ${data.data.name || 'Product'} registered!`, 'success');
            closeRegisterModal();
            triggerFeedback();
            flashRegister();

            /* Load the newly registered product into the details panel */
            state.lastScanTime = 0;
            await lookupBarcode(barcodeVal, 'scanner');
            await loadRecentScans();

        } catch (e) {
            showToast(e.message || 'Registration failed', 'error');
        } finally {
            regSubmitBtn.disabled    = false;
            regSubmitBtn.textContent = '';
            regSubmitBtn.classList.add('show-submit');
            regSubmitBtn.innerHTML   = '➕ Register Product';
        }
    });

    /* ══════════════════════════════════════════════
       EDIT PRODUCT MODAL
    ══════════════════════════════════════════════ */
    $('editProductBtn').addEventListener('click', () => {
        if (!state.currentProduct) { showToast('Scan an item first', 'error'); return; }
        const p = state.currentProduct;
        $('editProductId').value = p.id   || '';
        $('editName').value      = p.name || '';
        $('editSku').value       = p.sku  || '';
        $('editBarcode').value   = p.barcode || '';
        $('editLocation').value  = p.location_code || p.location || '';
        $('editPrice').value     = p.unit_price || '';
        $('editModal').classList.add('show');
    });

    $('cancelEditBtn').addEventListener('click', () => $('editModal').classList.remove('show'));
    $('editModal').addEventListener('click', e => { if (e.target === $('editModal')) $('editModal').classList.remove('show'); });

    $('editProductForm').addEventListener('submit', async e => {
        e.preventDefault();
        try {
            await fetchJSON('scanner_api.php/update-product', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body:    new URLSearchParams(new FormData(e.target)),
            });
            showToast('Product updated ✓', 'success');
            $('editModal').classList.remove('show');
            state.lastScanTime = 0;
            await lookupBarcode($('editBarcode').value || $('editSku').value, 'scanner');
        } catch (err) {
            showToast(err.message || 'Update failed', 'error');
        }
    });

    /* ══════════════════════════════════════════════
       DELETE PRODUCT
    ══════════════════════════════════════════════ */
    const delBtn = $('deleteProductBtn');
    if (delBtn) {
        delBtn.addEventListener('click', async () => {
            if (!state.currentProduct) { showToast('Scan an item first', 'error'); return; }
            if (!await appConfirm(`Delete "${state.currentProduct.name}"? This cannot be undone.`)) return;
            try {
                await fetchJSON('scanner_api.php/delete-product', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body:    new URLSearchParams({ product_id: state.currentProduct.id }),
                });
                showToast('Product deleted', 'success');
                state.scanState      = 'idle';
                state.currentProduct = null;
                state.scanCode       = '';
                renderProductPanel();
                renderQuickActions();
                await loadRecentScans();
            } catch (err) {
                showToast(err.message || 'Delete failed', 'error');
            }
        });
    }

    /* ══════════════════════════════════════════════
       QUICK ACTION BUTTON WIRING
    ══════════════════════════════════════════════ */
    if (qaIn)  qaIn.addEventListener('click',  () => selectAction('stock_in'));
    if (qaOut) qaOut.addEventListener('click', () => selectAction('stock_out'));
    if (qaReg) qaReg.addEventListener('click', () => selectAction('register'));

    applyStockBtn.addEventListener('click', () => applyStockAction(state.selectedAction));

    if (mobIn)  mobIn.addEventListener('click',  () => selectAction('stock_in'));
    if (mobOut) mobOut.addEventListener('click', () => selectAction('stock_out'));
    if (mobReg) mobReg.addEventListener('click', () => selectAction('register'));
    if (mobileApplyBtn) mobileApplyBtn.addEventListener('click', () => applyStockAction(state.selectedAction));

    /* Sync qty inputs */
    quantityInput.addEventListener('input', () => { if (mobileQtyInput) mobileQtyInput.value = quantityInput.value; });
    if (mobileQtyInput) mobileQtyInput.addEventListener('input', () => { quantityInput.value = mobileQtyInput.value; });

    /* Header register button */
    const headerRegBtn = $('headerRegisterBtn');
    if (headerRegBtn) headerRegBtn.addEventListener('click', () => window.openRegisterModal(''));

    /* ══════════════════════════════════════════════
       MANUAL / USB INPUT
    ══════════════════════════════════════════════ */
    barcodeInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const val = barcodeInput.value.trim();
            if (val) {
                lookupBarcode(val, 'manual_entry');
                setTimeout(() => { barcodeInput.value = ''; barcodeInput.focus(); }, 350);
            }
        }
    });

    /* USB scanner rapid-input detection */
    barcodeInput.addEventListener('input', () => {
        clearTimeout(state.usbTimer);
        const val = barcodeInput.value.trim();
        state.usbTimer = setTimeout(() => {
            if (val.length >= 4) {
                lookupBarcode(val, 'usb_scanner');
                setTimeout(() => { barcodeInput.value = ''; barcodeInput.focus(); }, 350);
            }
        }, 180);
    });

    $('manualLookupBtn').addEventListener('click', () => {
        const val = barcodeInput.value.trim();
        if (val) {
            lookupBarcode(val, 'manual_entry');
            setTimeout(() => { barcodeInput.value = ''; barcodeInput.focus(); }, 350);
        }
    });

    /* ══════════════════════════════════════════════
       QUAGGA CAMERA SCANNER
    ══════════════════════════════════════════════ */
    function startScanner() {
        if (state.scannerRunning) return;
        cameraPlaceholder.style.display = 'none';

        Quagga.init({
            inputStream: {
                name: 'Live', type: 'LiveStream',
                target: document.querySelector('#scanner-viewport'),
                constraints: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
                area: { top: '10%', right: '5%', left: '5%', bottom: '10%' },
            },
            decoder: {
                readers: ['ean_reader','ean_8_reader','upc_reader','upc_e_reader',
                          'code_128_reader','code_39_reader','code_93_reader','itf_reader','codabar_reader'],
                multiple: false,
            },
            locate: true,
            frequency: 10,
        }, err => {
            if (err) {
                cameraPlaceholder.style.display = 'flex';
                if (err.name === 'NotAllowedError')  showToast('Camera permission denied', 'error');
                else if (err.name === 'NotFoundError') showToast('No camera found', 'error');
                else showToast('Camera error: ' + (err.message || err.name), 'error');
                return;
            }
            Quagga.start();
            state.scannerRunning = true;
            viewfinderCont.classList.add('scanning');
            statusDot.classList.add('active');
            statusText.textContent = 'Scanning — point at a barcode';
            startBtn.style.display = 'none';
            stopBtn.style.display  = 'flex';
            showToast('Camera scanner started', 'success');
        });

        Quagga.onDetected(result => {
            if (!result?.codeResult?.code) return;
            const code   = result.codeResult.code.trim();
            if (!code) return;
            const errors = (result.codeResult.decodedCodes || [])
                .filter(d => d.error !== undefined).map(d => d.error);
            const avg    = errors.length ? errors.reduce((a,b) => a+b, 0) / errors.length : 0;
            if (avg > 0.18) return;
            lookupBarcode(code, 'camera');
        });
    }

    function stopScanner() {
        if (!state.scannerRunning) return;
        Quagga.stop(); Quagga.offDetected();
        state.scannerRunning = false;
        viewfinderCont.classList.remove('scanning');
        statusDot.classList.remove('active');
        statusText.textContent = 'Scanner stopped';
        startBtn.style.display = 'flex';
        stopBtn.style.display  = 'none';
        cameraPlaceholder.style.display = 'flex';
        document.querySelector('#scanner-viewport').innerHTML = '';
    }

    startBtn.addEventListener('click', startScanner);
    stopBtn.addEventListener('click',  stopScanner);

    /* ══════════════════════════════════════════════
       DEVICE ADAPTATION
    ══════════════════════════════════════════════ */
    function adaptForDevice() {
        const pIcon  = $('placeholderIcon');
        const pText  = $('placeholderText');
        const pSub   = $('placeholderSub');
        const mTitle = $('manualInputTitle');
        const mHint  = $('inputHint');

        if (!isMobile) {
            if (pIcon)  pIcon.textContent = '🖥️';
            if (pText)  pText.innerHTML   = '<strong>USB Scanner Ready</strong><br>Connect USB/Bluetooth scanner<br>or start webcam below.';
            if (pSub)   pSub.textContent  = 'Scanned codes appear in the input box automatically.';
            if (mTitle) mTitle.textContent = 'USB / Bluetooth Scanner Input';
            if (mHint)  mHint.textContent  = 'Just scan with your USB scanner — no Enter needed.';
            statusText.textContent = 'Ready — scan with USB scanner or start webcam';
        } else {
            if (pText)  pText.innerHTML   = '<strong>Tap "Start Camera"</strong><br>to scan barcodes with your phone.';
        }
    }

    /* ══════════════════════════════════════════════
       MOBILE BAR SHOW/HIDE
    ══════════════════════════════════════════════ */
    function refreshMobileBar() {
        const bar = $('mobileBar');
        if (bar) bar.style.display = window.innerWidth <= 640 ? 'flex' : 'none';
    }
    window.addEventListener('resize', refreshMobileBar);
    refreshMobileBar();

    /* ══════════════════════════════════════════════
       AUTO-FOCUS FOR DESKTOP USB SCANNERS
    ══════════════════════════════════════════════ */
    function autoFocusInput() {
        if (!isMobile && !state.scannerRunning) {
            setTimeout(() => { barcodeInput.focus(); barcodeInput.select(); }, 150);
        }
    }
    autoFocusInput();
    document.addEventListener('visibilitychange', () => { if (!document.hidden) autoFocusInput(); });
    window.addEventListener('focus', autoFocusInput);
    [registerModal, $('editModal')].forEach(m => {
        if (m) m.addEventListener('click', () => setTimeout(autoFocusInput, 200));
    });

    /* ══════════════════════════════════════════════
       INIT
    ══════════════════════════════════════════════ */
    adaptForDevice();
    renderProductPanel();
    renderQuickActions();
    loadRecentScans();

})();
</script>
</body>
</html>
