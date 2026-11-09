<?php

/**
 * Role labels stored in users.role.
 * Existing rows use the title-case values already in the database.
 */
function clarenzonAssignableRoles(): array
{
    return [
        'Admin' => 'Admin',
        'Purchaser' => 'Purchaser',
        'Warehouse Head' => 'Warehouse Head',
        'Technician' => 'Technician',
        'Inventory Manager / Staff' => 'Inventory Manager / Staff',
        'Sales / Marketing Staff' => 'Sales / Marketing Staff',
        'Management / Executive' => 'Management / Executive',
        'Cashier' => 'Cashier',
    ];
}

function clarenzonRoleOptionsHtml(string $selected = ''): string
{
    $html = '';
    foreach (clarenzonAssignableRoles() as $value => $label) {
        if (in_array($value, ['Inventory Manager / Staff', 'Sales / Marketing Staff', 'Management / Executive'], true)) {
            continue;
        }
        $isSelected = strcasecmp($selected, $value) === 0 ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function clarenzonIsAssignableRole(string $role): bool
{
    foreach (array_keys(clarenzonAssignableRoles()) as $value) {
        if (strcasecmp($value, $role) === 0) {
            return true;
        }
    }
    return false;
}

function clarenzonCanonicalRole(string $role): string
{
    foreach (clarenzonAssignableRoles() as $value => $label) {
        if (strcasecmp($value, $role) === 0) {
            return $value;
        }
    }
    return $role;
}

function clarenzonEnsureUserRoleEnum(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $roles = array_keys(clarenzonAssignableRoles());
    $enum = "'" . implode("','", array_map(static function ($role) use ($conn) {
        return $conn->real_escape_string($role);
    }, $roles)) . "'";
    $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM($enum) NOT NULL DEFAULT 'Purchaser'");
}

function clarenzonUserStatusIsActive(?string $status): bool
{
    return strtolower(trim((string)$status)) === 'active';
}

function clarenzonDefaultSectionsForRole(string $role): string
{
    if (!function_exists('normalize_user_role')) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'route_protection.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
    $key = function_exists('normalize_user_role')
        ? normalize_user_role($role)
        : strtolower(str_replace([' ', '-', '/'], '_', $role));
    if ($key === 'technician') {
        return 'dashboard,job-orders,issued-supplies,audit-log,account';
    }
    if ($key === 'inventory_manager') {
        return 'dashboard,analytics,inventory,low-stock-view,expiry-view,reports,account';
    }
    if ($key === 'sales_marketing') {
        return 'dashboard,customers,sales-orders,billing-invoices,unpaid-invoices,sales-reports,account';
    }
    if ($key === 'management') {
        return 'dashboard,analytics,reports,sales-reports,account';
    }
    if ($key === 'cashier') {
        return 'dashboard,customers,sales-orders,billing-invoices,unpaid-invoices,sales-returns,account';
    }
    if ($key === 'warehouse_head') {
        return 'dashboard,analytics,inventory,barcode-scanner,low-stock-view,expiry-view,reports,account';
    }
    if ($key === 'admin') {
        return 'dashboard,analytics,inventory,users,audit-log,reports,account';
    }
    return 'dashboard,analytics,inventory,barcode-scanner,serials-view,low-stock-view,expiry-view,procurement,purchase-requests,purchase-orders,supplier-invoices,suppliers-view,purchase-history,delivery-schedule,purchaser-audit-log,markup-queue,reports,notifications,account';
}

function clarenzonAuditModuleLabel(string $actionKey): string
{
    $action = strtolower(trim($actionKey));
    $map = [
        'user_created' => 'User Management',
        'user_edited' => 'User Management',
        'role_changed' => 'User Management',
        'user_activated' => 'User Management',
        'user_deactivated' => 'User Management',
        'password_reset' => 'User Management',
        'login' => 'Authentication',
    ];
    if (isset($map[$action])) {
        return $map[$action];
    }
    if (strpos($action, 'user') !== false || strpos($action, 'password') !== false) {
        return 'User Management';
    }
    if (strpos($action, 'inventory') !== false || strpos($action, 'product') !== false || strpos($action, 'stock') !== false) {
        return 'Inventory';
    }
    if (strpos($action, 'purchase') !== false || strpos($action, 'supplier') !== false) {
        return 'Procurement';
    }
    if (strpos($action, 'sale') !== false || strpos($action, 'invoice') !== false || strpos($action, 'billing') !== false) {
        return 'Sales';
    }
    return 'System';
}
