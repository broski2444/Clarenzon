<?php
/**
 * Product selling-price / markup helpers for the Sales module.
 * Keeps cost_price separate from selling_price; freezes prices on sales lines elsewhere.
 */

function ensureProductPricingSchema(mysqli $conn): void
{
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $done = true;

    // Permissions flag
    $col = $conn->query("SHOW COLUMNS FROM user_permissions LIKE 'can_manage_pricing'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE user_permissions ADD COLUMN can_manage_pricing TINYINT(1) NOT NULL DEFAULT 0 AFTER can_delete_records");
    }

    // Product markup + last price update metadata
    $pCols = [
        'markup_percent' => "ALTER TABLE products ADD COLUMN markup_percent DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER selling_price",
        'selling_price_updated_at' => "ALTER TABLE products ADD COLUMN selling_price_updated_at DATETIME NULL AFTER markup_percent",
        'selling_price_updated_by' => "ALTER TABLE products ADD COLUMN selling_price_updated_by INT NULL AFTER selling_price_updated_at",
    ];
    foreach ($pCols as $name => $sql) {
        $c = $conn->query("SHOW COLUMNS FROM products LIKE '$name'");
        if ($c && $c->num_rows === 0) {
            @$conn->query($sql);
        }
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS product_price_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            sku VARCHAR(100) NULL,
            product_name VARCHAR(255) NULL,
            previous_cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            previous_markup_percent DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            previous_selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            new_cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            new_markup_percent DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            new_selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            changed_by INT NULL,
            changed_by_name VARCHAR(150) NULL,
            changed_by_role VARCHAR(100) NULL,
            change_source VARCHAR(50) NOT NULL DEFAULT 'sales_pricing',
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ppa_product (product_id),
            INDEX idx_ppa_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function userCanManageProductPricing(?mysqli $conn, int $userId, string $role): bool
{
    $roleKey = function_exists('normalize_user_role')
        ? normalize_user_role($role)
        : strtolower(str_replace([' ', '-'], '_', trim($role)));

    // Administrator always allowed
    if (in_array($roleKey, ['admin', 'administrator', 'system_administrator'], true)) {
        return true;
    }

    // Dedicated Sales roles can manage pricing by default
    if (in_array($roleKey, ['sales_representative', 'sales', 'sales_rep'], true)) {
        return true;
    }

    if (!$conn || $userId <= 0) {
        return false;
    }

    // Explicit permission flag (optional grant for other roles)
    $col = $conn->query("SHOW COLUMNS FROM user_permissions LIKE 'can_manage_pricing'");
    if ($col && $col->num_rows > 0) {
        $stmt = $conn->prepare("SELECT can_manage_pricing FROM user_permissions WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['can_manage_pricing'])) {
                return true;
            }
        }
    }

    return false;
}

function calcSellingPriceFromMarkup(float $cost, float $markupPercent): float
{
    return round($cost + ($cost * ($markupPercent / 100.0)), 2);
}

function calcMarkupPercentFromPrices(float $cost, float $selling): float
{
    if ($cost <= 0) {
        return 0.0;
    }
    return round((($selling - $cost) / $cost) * 100.0, 2);
}

function logProductPriceChange(
    mysqli $conn,
    int $productId,
    array $before,
    array $after,
    int $userId,
    string $username,
    string $role,
    string $source = 'sales_pricing',
    ?string $notes = null
): void {
    ensureProductPricingSchema($conn);
    $stmt = $conn->prepare(
        "INSERT INTO product_price_audit
        (product_id, sku, product_name, previous_cost_price, previous_markup_percent, previous_selling_price,
         new_cost_price, new_markup_percent, new_selling_price, changed_by, changed_by_name, changed_by_role, change_source, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    if (!$stmt) {
        return;
    }
    $sku = (string)($before['sku'] ?? $after['sku'] ?? '');
    $name = (string)($before['name'] ?? $after['name'] ?? '');
    $pc = (float)($before['cost_price'] ?? 0);
    $pm = (float)($before['markup_percent'] ?? 0);
    $ps = (float)($before['selling_price'] ?? 0);
    $nc = (float)($after['cost_price'] ?? $pc);
    $nm = (float)($after['markup_percent'] ?? 0);
    $ns = (float)($after['selling_price'] ?? 0);
    $notesStr = $notes !== null ? (string)$notes : '';
    $stmt->bind_param(
        'issddddddissss',
        $productId,
        $sku,
        $name,
        $pc,
        $pm,
        $ps,
        $nc,
        $nm,
        $ns,
        $userId,
        $username,
        $role,
        $source,
        $notesStr
    );
    $stmt->execute();
    $stmt->close();
}
