<?php
/**
 * Shared mobile chrome: header, bottom nav, toasts.
 */
declare(strict_types=1);

function mobile_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mobile_icon(string $name): string
{
    $icons = [
        'cross' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10.2 3.2h3.6v6.9h6.9v3.6h-6.9v6.9h-3.6v-6.9H3.3v-3.6h6.9z"/></svg>',
        'home' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 11.5 12 4l8 7.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z"/></svg>',
        'stock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg>',
        'bell' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 1 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
        'menu' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        'back' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M15 6 9 12l6 6"/></svg>',
        'scan' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V5a1 1 0 0 1 1-1h2M4 17v2a1 1 0 0 0 1 1h2M20 7V5a1 1 0 0 0-1-1h-2M20 17v2a1 1 0 0 1-1 1h-2M5 12h14"/></svg>',
        'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-4 14.5-4 16 0"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function mobile_pwa_head(string $title): void
{
    $icon192 = mobile_url('assets/icon-192.png');
    $apple = mobile_url('assets/apple-touch-icon.png');
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <meta name="theme-color" content="#0B4F9C">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Clarenzon WH">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="Clarenzon WH">
    <title><?= mobile_h($title) ?> | Clarenzon Warehouse</title>
    <link rel="manifest" href="<?= mobile_h(mobile_url('manifest.webmanifest')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= mobile_h($icon192) ?>">
    <link rel="apple-touch-icon" href="<?= mobile_h($apple) ?>">
    <link rel="stylesheet" href="<?= mobile_h(mobile_asset('app.css')) ?>">
    <?php
}

function mobile_role_label(string $key): string
{
    if ($key === 'admin') {
        return 'Admin';
    }
    if ($key === 'warehouse_head') {
        return 'Warehouse Head';
    }
    return ucwords(str_replace('_', ' ', $key));
}

function mobile_render_denied(?array $user = null): void
{
    $home = mobile_parent_url(function_exists('route_home_for_role') && $user
        ? route_home_for_role((string)($user['role'] ?? ''))
        : 'login.php');
    $logout = mobile_url('logout.php');
    header('X-Robots-Tag: noindex');
    ?>
<!DOCTYPE html>
<html lang="en" data-sw="<?= mobile_h(mobile_url('sw.js')) ?>">
<head>
    <?php mobile_pwa_head('Access Denied'); ?>
</head>
<body class="m-denied-body">
    <main class="m-denied">
        <div class="m-login-mark"><?= mobile_icon('cross') ?></div>
        <h1>Access Denied</h1>
        <p><?= mobile_h(mobile_denied_message()) ?></p>
        <div class="m-denied-actions">
            <a class="m-btn m-btn-primary" href="<?= mobile_h($home) ?>">Open desktop app</a>
            <a class="m-btn m-btn-ghost" href="<?= mobile_h($logout) ?>">Logout</a>
        </div>
    </main>
    <script src="<?= mobile_h(mobile_asset('pwa.js')) ?>"></script>
</body>
</html>
    <?php
    exit;
}

function mobile_render_start(string $title, string $nav = 'home', array $opts = []): void
{
    $user = mobile_user();
    $csrf = mobile_ensure_csrf();
    $page = $opts['page'] ?? $nav;
    $chrome = $opts['chrome'] ?? 'app';
    $hideNav = !empty($opts['hide_nav']) || in_array($chrome, ['scan', 'inner-nonav'], true);
    $back = (string)($opts['back'] ?? '');
    $bodyClass = 'm-app';
    if ($hideNav) {
        $bodyClass .= ' is-nonav';
    }
    if ($chrome === 'scan') {
        $bodyClass .= ' is-scan';
    }
    $GLOBALS['MOBILE_HIDE_NAV'] = $hideNav ? '1' : '';
    ?>
<!DOCTYPE html>
<html lang="en" data-sw="<?= mobile_h(mobile_url('sw.js')) ?>">
<head>
    <?php mobile_pwa_head($title); ?>
</head>
<body class="<?= mobile_h($bodyClass) ?>" data-page="<?= mobile_h($page) ?>" data-nav="<?= mobile_h($nav) ?>" data-role="<?= mobile_h($user['role_key']) ?>">
<div class="m-offline" id="mOffline" hidden>Connection lost. Reconnecting...</div>
<header class="m-top">
    <?php if ($back !== ''): ?>
        <a class="m-icon-btn" href="<?= mobile_h($back) ?>" aria-label="Back"><?= mobile_icon('back') ?></a>
        <div class="m-top-page"><?= mobile_h($title) ?></div>
    <?php elseif ($page === 'dashboard' || $chrome === 'brand'): ?>
        <a class="m-icon-btn" href="<?= mobile_h(mobile_url('profile.php')) ?>" aria-label="Menu"><?= mobile_icon('menu') ?></a>
        <div class="m-top-brand">
            <div class="m-top-mark"><?= mobile_icon('cross') ?></div>
            <div>
                <div class="m-top-title">CLARENZON</div>
                <div class="m-top-sub">Warehouse Mobile</div>
            </div>
        </div>
        <a class="m-icon-btn" href="<?= mobile_h(mobile_url('alerts.php')) ?>" aria-label="Alerts"><?= mobile_icon('bell') ?><span class="m-bell-count" id="mBellCount" hidden></span></a>
    <?php else: ?>
        <div class="m-top-page" style="text-align:center;width:100%"><?= mobile_h($title) ?></div>
    <?php endif; ?>
</header>
<main class="m-main" id="mMain">
    <?php
}

function mobile_render_end(string $nav = 'home'): void
{
    $user = mobile_user();
    $csrf = mobile_ensure_csrf();
    ?>
</main>
<?php if (empty($GLOBALS['MOBILE_HIDE_NAV'])): ?>
<nav class="m-tabbar" aria-label="Warehouse">
    <a class="m-tab <?= $nav === 'home' ? 'is-active' : '' ?>" href="<?= mobile_h(mobile_url('dashboard.php')) ?>">
        <?= mobile_icon('home') ?><span>Home</span>
    </a>
    <a class="m-tab <?= $nav === 'stock' ? 'is-active' : '' ?>" href="<?= mobile_h(mobile_url('inventory.php')) ?>">
        <?= mobile_icon('stock') ?><span>Stock</span>
    </a>
    <a class="m-tab <?= $nav === 'alerts' ? 'is-active' : '' ?>" href="<?= mobile_h(mobile_url('alerts.php')) ?>">
        <?= mobile_icon('bell') ?><span>Alerts</span>
    </a>
    <a class="m-tab <?= $nav === 'menu' ? 'is-active' : '' ?>" href="<?= mobile_h(mobile_url('profile.php')) ?>">
        <?= mobile_icon('menu') ?><span>Menu</span>
    </a>
</nav>
<?php endif; ?>
<div class="m-toast" id="mToast" hidden></div>
<script>
window.CLAR_AUTH_CONTEXT = <?= json_encode($user['role_key']) ?>;
window.CLAR_MOBILE = {
    csrf: <?= json_encode($csrf) ?>,
    role: <?= json_encode($user['role_key']) ?>,
    isAdmin: <?= $user['is_admin'] ? 'true' : 'false' ?>,
    userName: <?= json_encode($user['full_name']) ?>,
    firstName: <?= json_encode($user['first_name']) ?>,
    lastName: <?= json_encode($user['last_name']) ?>,
    api: <?= json_encode(mobile_url('api.php')) ?>,
    base: <?= json_encode(mobile_base_url()) ?>
};
</script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= mobile_h(mobile_asset('scanner.js')) ?>"></script>
<script src="../java/app_dialog.js"></script>
<script src="<?= mobile_h(mobile_asset('app.js')) ?>"></script>
<script src="<?= mobile_h(mobile_asset('pwa.js')) ?>"></script>
</body>
</html>
    <?php
}
