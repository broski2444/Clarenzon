<?php
/**
 * Product catalog information fields: category/subcategory tree,
 * packaging, unit, and storage temperature.
 * Does not change inventory quantities.
 */

function productCatalogEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $st = $conn->query("SHOW COLUMNS FROM products LIKE 'storage_temperature'");
    if (!($st && $st->num_rows > 0)) {
        @$conn->query("ALTER TABLE products ADD COLUMN storage_temperature VARCHAR(120) NULL AFTER packaging");
    }

    $pack = $conn->query("SHOW COLUMNS FROM products LIKE 'packaging'");
    if ($pack && ($row = $pack->fetch_assoc())) {
        $type = strtolower((string)($row['Type'] ?? ''));
        if (preg_match('/varchar\((\d+)\)/', $type, $m) && (int)$m[1] < 255) {
            @$conn->query("ALTER TABLE products MODIFY COLUMN packaging VARCHAR(255) NULL");
        }
    }

    $catTbl = $conn->query("SHOW TABLES LIKE 'categories'");
    if (!($catTbl && $catTbl->num_rows > 0)) {
        return;
    }
    $parentCol = $conn->query("SHOW COLUMNS FROM categories LIKE 'parent_id'");
    if (!($parentCol && $parentCol->num_rows > 0)) {
        @$conn->query("ALTER TABLE categories ADD COLUMN parent_id INT NULL AFTER description");
    }

    productCatalogSeedHierarchy($conn);
}

function productCatalogUnitOptions(): array {
    return ['pc', 'pcs', 'box', 'bottle', 'pack', 'kit', 'set', 'unit', 'pair', 'roll', 'kg', 'liter'];
}

function productCatalogStorageTempOptions(): array {
    return [
        'Room Temperature / 15–30°C',
        'Refrigerated / 2–8°C',
        'Frozen',
        'N/A',
        'Other',
    ];
}

function productCatalogPostedStorageTemp(string $presetKey, string $otherKey): ?string {
    $preset = trim((string)($_POST[$presetKey] ?? ''));
    $other = trim((string)($_POST[$otherKey] ?? ''));
    if ($preset === '' && $other === '') {
        return null;
    }
    if (strcasecmp($preset, 'Other') === 0) {
        return $other !== '' ? $other : 'Other';
    }
    return $preset !== '' ? $preset : ($other !== '' ? $other : null);
}

function productCatalogSaveInfoFields(mysqli $conn, string $sku, ?string $packaging, ?string $storageTemp): void {
    $sku = trim($sku);
    if ($sku === '') {
        return;
    }
    $hasPack = $conn->query("SHOW COLUMNS FROM products LIKE 'packaging'");
    $hasTemp = $conn->query("SHOW COLUMNS FROM products LIKE 'storage_temperature'");
    $sets = [];
    $types = '';
    $vals = [];
    if ($hasPack && $hasPack->num_rows > 0 && $packaging !== null) {
        $sets[] = 'packaging=?';
        $types .= 's';
        $vals[] = $packaging;
    }
    if ($hasTemp && $hasTemp->num_rows > 0) {
        $sets[] = 'storage_temperature=?';
        $types .= 's';
        $vals[] = $storageTemp;
    }
    if (!$sets) {
        return;
    }
    $types .= 's';
    $vals[] = $sku;
    $sql = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE sku=?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $stmt->close();
}

function productCatalogFindByName(mysqli $conn, string $name): ?int {
    $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function productCatalogEnsureNamed(mysqli $conn, string $name, ?string $description, ?int $parentId, ?int $defaultExpiry): int {
    $existing = productCatalogFindByName($conn, $name);
    if ($existing) {
        if ($parentId !== null) {
            $conn->query("UPDATE categories SET parent_id=$parentId WHERE id=$existing AND (parent_id IS NULL OR parent_id=0)");
        }
        if ($defaultExpiry !== null) {
            $conn->query("UPDATE categories SET default_expiry_tracking=$defaultExpiry WHERE id=$existing AND default_expiry_tracking IS NULL");
        }
        return $existing;
    }
    $status = 'Active';
    $stmt = $conn->prepare("INSERT INTO categories (name, description, status) VALUES (?, ?, ?)");
    if (!$stmt) {
        return 0;
    }
    $desc = (string)$description;
    $stmt->bind_param('sss', $name, $desc, $status);
    $ok = $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    if (!$ok || $id <= 0) {
        return 0;
    }
    if ($parentId !== null && $parentId > 0) {
        $conn->query("UPDATE categories SET parent_id=$parentId WHERE id=$id");
    }
    if ($defaultExpiry !== null) {
        $d = (int)$defaultExpiry;
        $conn->query("UPDATE categories SET default_expiry_tracking=$d WHERE id=$id");
    }
    return $id;
}

function productCatalogGuessParentName(string $name): string {
    $n = strtolower(trim($name));
    $equipment = [
        'laboratory equipment',
        'lab equipment',
        'computer & peripherals',
        'computers & peripherals',
        'computer and peripherals',
        'thermometers',
        'bp monitors',
        'equipment',
    ];
    foreach ($equipment as $needle) {
        if ($n === $needle || strpos($n, $needle) !== false) {
            return 'Equipment';
        }
    }
    $non = ['non-consumable', 'non consumable'];
    foreach ($non as $needle) {
        if ($n === $needle) {
            return 'Non-Consumable';
        }
    }
    return 'Consumable';
}

function productCatalogSeedHierarchy(mysqli $conn): void {
    $consumableId = productCatalogEnsureNamed($conn, 'Consumable', 'Consumable products', null, null);
    $equipmentId = productCatalogEnsureNamed($conn, 'Equipment', 'Equipment products', null, null);
    $nonId = productCatalogEnsureNamed($conn, 'Non-Consumable', 'Non-consumable products', null, null);
    $parents = [
        'Consumable' => $consumableId,
        'Equipment' => $equipmentId,
        'Non-Consumable' => $nonId,
    ];

    $subs = [
        ['Medical Supplies', 'Consumable', 1],
        ['POCT', 'Consumable', 1],
        ['Chemistry', 'Consumable', 1],
        ['Hematology', 'Consumable', 1],
        ['Laboratory Equipment', 'Equipment', 0],
        ['Computer & Peripherals', 'Equipment', 0],
    ];
    foreach ($subs as $sub) {
        [$name, $parentName, $def] = $sub;
        $pid = (int)($parents[$parentName] ?? 0);
        productCatalogEnsureNamed($conn, $name, $name, $pid > 0 ? $pid : null, $def);
    }

    $res = $conn->query("SELECT id, name, parent_id FROM categories");
    if (!$res) {
        return;
    }
    $topNames = ['consumable', 'equipment', 'non-consumable'];
    while ($row = $res->fetch_assoc()) {
        $id = (int)$row['id'];
        $name = (string)$row['name'];
        if (in_array(strtolower($name), $topNames, true)) {
            if ($row['parent_id'] !== null && (int)$row['parent_id'] > 0) {
                $conn->query("UPDATE categories SET parent_id=NULL WHERE id=$id");
            }
            continue;
        }
        if ($row['parent_id'] !== null && (int)$row['parent_id'] > 0) {
            continue;
        }
        $parentName = productCatalogGuessParentName($name);
        $pid = (int)($parents[$parentName] ?? 0);
        if ($pid > 0 && $pid !== $id) {
            $conn->query("UPDATE categories SET parent_id=$pid WHERE id=$id AND (parent_id IS NULL OR parent_id=0)");
        }
    }
}

/**
 * @return array{parents: array, children: array, by_parent: array}
 */
function productCatalogSplit(array $categories): array {
    $parents = [];
    $children = [];
    $byParent = [];
    foreach ($categories as $c) {
        $pid = isset($c['parent_id']) && $c['parent_id'] !== null && $c['parent_id'] !== '' ? (int)$c['parent_id'] : 0;
        if ($pid <= 0) {
            $parents[] = $c;
        } else {
            $children[] = $c;
            $byParent[$pid][] = $c;
        }
    }
    return ['parents' => $parents, 'children' => $children, 'by_parent' => $byParent];
}

function productCatalogMainGroupOrder(): array {
    return ['Consumable', 'Non-Consumable', 'Equipment'];
}

/**
 * Grouped Category filter markup: Consumable / Non-Consumable / Equipment optgroups.
 */
function productCatalogFilterSelectHtml(array $parents, array $byParent): string {
    $html = '<option value="">All Categories</option>';
    $parentsById = [];
    $parentsByName = [];
    foreach ($parents as $p) {
        $id = (int)($p['id'] ?? 0);
        $name = trim((string)($p['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $parentsById[$id] = $p;
        $parentsByName[strtolower($name)] = $p;
    }

    $usedIds = [];
    $emitGroup = static function (array $parent) use (&$html, &$usedIds, $byParent): void {
        $id = (int)($parent['id'] ?? 0);
        $name = trim((string)($parent['name'] ?? ''));
        if ($id <= 0 || $name === '' || isset($usedIds[$id])) {
            return;
        }
        $usedIds[$id] = true;
        $label = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars(strtolower($name), ENT_QUOTES, 'UTF-8');
        $html .= '<optgroup label="' . $label . '">';
        $html .= '<option value="' . $value . '">All ' . $label . '</option>';
        $kids = $byParent[$id] ?? [];
        usort($kids, static function ($a, $b) {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        foreach ($kids as $c) {
            $cname = trim((string)($c['name'] ?? ''));
            if ($cname === '') {
                continue;
            }
            $html .= '<option value="' . htmlspecialchars(strtolower($cname), ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($cname, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</optgroup>';
    };

    foreach (productCatalogMainGroupOrder() as $groupName) {
        $parent = $parentsByName[strtolower($groupName)] ?? null;
        if ($parent) {
            $emitGroup($parent);
        }
    }
    foreach ($parents as $parent) {
        $emitGroup($parent);
    }

    $orphans = [];
    foreach ($byParent as $pid => $kids) {
        if (isset($parentsById[(int)$pid])) {
            continue;
        }
        foreach ($kids as $c) {
            $orphans[] = $c;
        }
    }
    if ($orphans) {
        usort($orphans, static function ($a, $b) {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        $html .= '<optgroup label="Other">';
        foreach ($orphans as $c) {
            $cname = trim((string)($c['name'] ?? ''));
            if ($cname === '') {
                continue;
            }
            $html .= '<option value="' . htmlspecialchars(strtolower($cname), ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($cname, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</optgroup>';
    }

    return $html;
}

function productCatalogRenderUnitOptions(?string $selected = null): string {
    $opts = productCatalogUnitOptions();
    $selected = trim((string)$selected);
    if ($selected !== '' && !in_array($selected, $opts, true)) {
        array_unshift($opts, $selected);
    }
    $html = '';
    foreach ($opts as $opt) {
        $sel = ($selected !== '' && $opt === $selected) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
    }
    return $html;
}

function productCatalogRenderStorageTempOptions(?string $current = null): array {
    $options = productCatalogStorageTempOptions();
    $current = trim((string)$current);
    $preset = $current;
    $other = '';
    if ($current !== '' && !in_array($current, $options, true)) {
        $preset = 'Other';
        $other = $current;
    }
    return ['preset' => $preset, 'other' => $other, 'options' => $options];
}
