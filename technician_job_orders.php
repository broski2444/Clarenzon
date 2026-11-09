<?php

function ensureTechnicianJobOrderSchema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS job_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_order_no VARCHAR(50) NOT NULL UNIQUE,
        assigned_to INT NOT NULL,
        equipment_item VARCHAR(255) NULL,
        task_description TEXT NOT NULL,
        instructions TEXT NULL,
        status ENUM('Pending','Ongoing','Completed') NOT NULL DEFAULT 'Pending',
        assigned_date DATE NOT NULL,
        completed_at DATETIME NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_assigned_to (assigned_to),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS job_order_issued_supplies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        technician_id INT NOT NULL,
        reference_number VARCHAR(100) NOT NULL,
        remarks TEXT NULL,
        issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        stock_movement_id INT NULL,
        FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (stock_movement_id) REFERENCES stock_movements(id) ON DELETE SET NULL,
        INDEX idx_job_order (job_order_id),
        INDEX idx_technician (technician_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function technicianAddAuditLog(mysqli $conn, ?int $userId, string $username, string $role, string $action, string $details): void {
    $conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(100) NOT NULL,
        user_role VARCHAR(100) NOT NULL,
        action_key VARCHAR(100) NOT NULL,
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, username, user_role, action_key, details) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("issss", $userId, $username, $role, $action, $details);
        $stmt->execute();
        $stmt->close();
    }
}

function technicianNextJobOrderNo(mysqli $conn): string {
    $prefix = 'JO-' . date('Y') . '-';
    $res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE job_order_no LIKE '" . $conn->real_escape_string($prefix) . "%'");
    $count = $res ? (int)($res->fetch_assoc()['cnt'] ?? 0) : 0;
    return $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
}

function getTechnicianUsers(mysqli $conn): array {
    $rows = [];
    $res = $conn->query("SELECT id, username, full_name FROM users WHERE LOWER(role) IN ('technician','techinician') ORDER BY full_name, username");
    if ($res) {
        while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}

function getTechnicianJobOrders(mysqli $conn, ?int $technicianId = null): array {
    $rows = [];
    $sql = "SELECT jo.*, u.full_name AS technician_name, u.username AS technician_username
            FROM job_orders jo
            LEFT JOIN users u ON jo.assigned_to = u.id";
    if ($technicianId !== null) {
        $stmt = $conn->prepare($sql . " WHERE jo.assigned_to = ? ORDER BY jo.assigned_date DESC, jo.id DESC");
        if (!$stmt) return $rows;
        $stmt->bind_param("i", $technicianId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql . " ORDER BY jo.assigned_date DESC, jo.id DESC");
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    if (isset($stmt)) $stmt->close();
    return $rows;
}

function getTechnicianIssuedSupplies(mysqli $conn, ?int $technicianId = null): array {
    $rows = [];
    $sql = "SELECT jis.*, jo.job_order_no, p.sku, p.name AS product_name, u.full_name AS technician_name
            FROM job_order_issued_supplies jis
            JOIN job_orders jo ON jis.job_order_id = jo.id
            JOIN products p ON jis.product_id = p.id
            LEFT JOIN users u ON jis.technician_id = u.id";
    if ($technicianId !== null) {
        $stmt = $conn->prepare($sql . " WHERE jis.technician_id = ? ORDER BY jis.issued_at DESC, jis.id DESC");
        if (!$stmt) return $rows;
        $stmt->bind_param("i", $technicianId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql . " ORDER BY jis.issued_at DESC, jis.id DESC");
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    if (isset($stmt)) $stmt->close();
    return $rows;
}

function createTechnicianJobOrder(mysqli $conn, int $assignedTo, string $equipment, string $description, string $instructions, int $createdBy): array {
    if ($assignedTo <= 0 || trim($description) === '') {
        return ['success' => false, 'message' => 'Technician and task description are required.'];
    }
    $jobNo = technicianNextJobOrderNo($conn);
    $assignedDate = date('Y-m-d');
    $status = 'Pending';
    $stmt = $conn->prepare("INSERT INTO job_orders (job_order_no, assigned_to, equipment_item, task_description, instructions, status, assigned_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return ['success' => false, 'message' => $conn->error];
    $stmt->bind_param("sisssssi", $jobNo, $assignedTo, $equipment, $description, $instructions, $status, $assignedDate, $createdBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? ['success' => true, 'job_order_no' => $jobNo] : ['success' => false, 'message' => $conn->error];
}

function updateTechnicianJobStatus(mysqli $conn, int $jobOrderId, int $technicianId, string $status): array {
    if (!in_array($status, ['Pending', 'Ongoing', 'Completed'], true)) {
        return ['success' => false, 'message' => 'Invalid work status.'];
    }
    $completedSql = $status === 'Completed' ? ', completed_at = NOW()' : ', completed_at = NULL';
    $stmt = $conn->prepare("UPDATE job_orders SET status = ? {$completedSql} WHERE id = ? AND assigned_to = ?");
    if (!$stmt) return ['success' => false, 'message' => $conn->error];
    $stmt->bind_param("sii", $status, $jobOrderId, $technicianId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if (!$ok) return ['success' => false, 'message' => $conn->error];
    return $affected > 0 ? ['success' => true] : ['success' => false, 'message' => 'Job order not found or not assigned to you.'];
}

function issueSupplyForJobOrder(mysqli $conn, int $jobOrderId, int $productId, int $quantity, int $technicianId, string $remarks): array {
    if ($jobOrderId <= 0 || $productId <= 0 || $quantity <= 0) {
        return ['success' => false, 'message' => 'Job order, product, and quantity are required.'];
    }
    $jobStmt = $conn->prepare("SELECT id, job_order_no FROM job_orders WHERE id = ? AND assigned_to = ? LIMIT 1");
    if (!$jobStmt) return ['success' => false, 'message' => $conn->error];
    $jobStmt->bind_param("ii", $jobOrderId, $technicianId);
    $jobStmt->execute();
    $job = $jobStmt->get_result()->fetch_assoc();
    $jobStmt->close();
    if (!$job) return ['success' => false, 'message' => 'Job order not found or not assigned to you.'];

    $conn->begin_transaction();
    try {
        $productStmt = $conn->prepare("SELECT id, sku, name, quantity_in_stock, cost_price, unit_price, selling_price, reorder_level FROM products WHERE id = ? AND status != 'Discontinued' FOR UPDATE");
        if (!$productStmt) throw new RuntimeException($conn->error);
        $productStmt->bind_param("i", $productId);
        $productStmt->execute();
        $product = $productStmt->get_result()->fetch_assoc();
        $productStmt->close();
        if (!$product) throw new RuntimeException('Product not found.');
        $available = (int)$product['quantity_in_stock'];
        if ($quantity > $available) throw new RuntimeException('Issued quantity cannot exceed available stock.');

        $newStock = $available - $quantity;
        $newStatus = $newStock <= 0 ? 'Out of Stock' : ($newStock <= (int)$product['reorder_level'] ? 'Low Stock' : 'Active');
        $upd = $conn->prepare("UPDATE products SET quantity_in_stock = ?, status = ? WHERE id = ?");
        if (!$upd) throw new RuntimeException($conn->error);
        $upd->bind_param("isi", $newStock, $newStatus, $productId);
        $upd->execute();
        $upd->close();

        $reference = 'JO-ISS-' . date('Ymd-His') . '-' . $jobOrderId;
        $unitPrice = (float)($product['cost_price'] ?: ($product['unit_price'] ?: $product['selling_price']));
        $total = $unitPrice * $quantity;
        $movementType = 'Stock Out (Issue)';
        $movementNotes = 'Issued to job order ' . $job['job_order_no'] . ($remarks !== '' ? ': ' . $remarks : '');
        $mov = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, unit_price, total_value, reference_number, user_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$mov) throw new RuntimeException($conn->error);
        $mov->bind_param("isiddsis", $productId, $movementType, $quantity, $unitPrice, $total, $reference, $technicianId, $movementNotes);
        $mov->execute();
        $movementId = (int)$conn->insert_id;
        $mov->close();

        $iss = $conn->prepare("INSERT INTO job_order_issued_supplies (job_order_id, product_id, quantity, technician_id, reference_number, remarks, stock_movement_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$iss) throw new RuntimeException($conn->error);
        $iss->bind_param("iiiissi", $jobOrderId, $productId, $quantity, $technicianId, $reference, $remarks, $movementId);
        $iss->execute();
        $iss->close();

        $conn->commit();
        return ['success' => true, 'reference' => $reference, 'new_stock' => $newStock];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
