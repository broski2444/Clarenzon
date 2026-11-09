<?php

function normalizeRoleKey(string $role): string {
    return strtolower(trim($role));
}

function isWarehouseHeadRole(string $role): bool {
    $key = normalizeRoleKey($role);
    return in_array($key, ['warehouse head', 'warehouse_head'], true);
}

function isWarehouseAssistantRole(string $role): bool {
    $key = normalizeRoleKey($role);
    return in_array($key, ['warehouse assistant', 'warehouse_assistant', 'warehouse asssistant', 'warehouse_asssistant'], true);
}

function isAdminWarehouseRole(string $role): bool {
    $key = normalizeRoleKey($role);
    return in_array($key, ['administrator', 'admin', 'warehouse head', 'warehouse_head', 'system administrator'], true);
}

/**
 * Canonical role key for scanner / inventory gates.
 * Aligns with route_protection::normalize_user_role when that helper is loaded.
 */
function scannerRoleKey(string $role): string {
    if (function_exists('normalize_user_role')) {
        return normalize_user_role($role);
    }
    $key = str_replace([' ', '-'], '_', normalizeRoleKey($role));
    $aliases = [
        'admin' => 'admin',
        'administrator' => 'admin',
        'system_administrator' => 'admin',
        'warehouse_head' => 'warehouse_head',
        'warehousehead' => 'warehouse_head',
        'warehouse' => 'warehouse_head',
        'purchaser' => 'purchaser',
        'technician' => 'technician',
        'techinician' => 'technician',
    ];
    return $aliases[$key] ?? $key;
}

/** Admin and Warehouse Head may use the web barcode scanner. */
function canUseMobileBarcodeScanner(string $role): bool {
    $key = scannerRoleKey($role);
    return $key === 'admin' || $key === 'warehouse_head';
}

/** Same roles may add/update stock from the scanner (shared inventory tables). */
function canScannerModifyStock(string $role): bool {
    return canUseMobileBarcodeScanner($role);
}

function ensureWarehouseAssistantPermissionsTable(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS warehouse_assistant_permissions (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
            can_manage_records TINYINT(1) NOT NULL DEFAULT 0,
            can_print_receipts TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $conn->query(
        "INSERT INTO warehouse_assistant_permissions (id, can_manage_records, can_print_receipts)
         VALUES (1, 0, 0)
         ON DUPLICATE KEY UPDATE id = id"
    );
}

function getWarehouseAssistantPermissions(mysqli $conn): array {
    ensureWarehouseAssistantPermissionsTable($conn);
    $res = $conn->query("SELECT can_manage_records, can_print_receipts FROM warehouse_assistant_permissions WHERE id = 1 LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        return [
            'can_manage_records' => (bool)$row['can_manage_records'],
            'can_print_receipts' => (bool)$row['can_print_receipts'],
        ];
    }

    return [
        'can_manage_records' => false,
        'can_print_receipts' => false,
    ];
}

function setWarehouseAssistantPermissions(mysqli $conn, bool $canManageRecords, bool $canPrintReceipts, ?int $updatedBy = null): bool {
    ensureWarehouseAssistantPermissionsTable($conn);
    $stmt = $conn->prepare(
        "UPDATE warehouse_assistant_permissions
         SET can_manage_records = ?, can_print_receipts = ?, updated_by = ?
         WHERE id = 1"
    );
    if ($stmt === false) {
        return false;
    }

    $manage = $canManageRecords ? 1 : 0;
    $print = $canPrintReceipts ? 1 : 0;
    $stmt->bind_param("iii", $manage, $print, $updatedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
