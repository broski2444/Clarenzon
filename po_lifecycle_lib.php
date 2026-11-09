<?php
/**
 * Single-PO lifecycle helpers. Status changes UPDATE the same purchase_orders row.
 */

function poLifecycleEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done || !$conn) {
        return;
    }
    $done = true;
    $col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'");
    if ($col && ($row = $col->fetch_assoc())) {
        $type = strtolower((string)($row['Type'] ?? ''));
        if (strpos($type, 'rejected') === false) {
            @$conn->query("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('Draft','Submitted','Pending Approval','Approved','Sent','Supplier Confirmed','Waiting for Delivery','Partially Received','Received','Cancelled','Rejected') NOT NULL DEFAULT 'Draft'");
        }
    }
    $rej = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'rejection_reason'");
    if (!($rej && $rej->num_rows > 0)) {
        @$conn->query("ALTER TABLE purchase_orders ADD COLUMN rejection_reason TEXT NULL AFTER notes");
    }
}

function poLifecycleAllowedDbStatuses(): array {
    return ['Draft', 'Submitted', 'Pending Approval', 'Approved', 'Sent', 'Supplier Confirmed', 'Waiting for Delivery', 'Partially Received', 'Received', 'Cancelled', 'Rejected'];
}

function poLifecycleLabel(string $status, array $po = []): string {
    $s = trim($status);
    if (in_array($s, ['Rejected', 'Cancelled'], true)) {
        return 'Rejected';
    }
    if ($s === 'Draft') {
        return 'Draft';
    }
    if (in_array($s, ['Submitted', 'Pending Approval'], true)) {
        return 'For Approval';
    }
    $received = (int)($po['qty_received'] ?? $po['received_boxes'] ?? 0);
    $remaining = (int)($po['qty_remaining'] ?? $po['remaining_boxes'] ?? -1);
    $recvStatus = trim((string)($po['receiving_status'] ?? $po['delivery_status'] ?? ''));
    if ($s === 'Received' || strcasecmp($recvStatus, 'Fully Received') === 0 || ($received > 0 && $remaining === 0)) {
        return 'Completed';
    }
    if ($s === 'Partially Received' || strcasecmp($recvStatus, 'Partially Received') === 0 || ($received > 0 && $remaining > 0)) {
        return 'Receiving';
    }
    if (in_array($s, ['Sent', 'Supplier Confirmed', 'Waiting for Delivery'], true)) {
        return 'Sent to Supplier';
    }
    if ($s === 'Approved') {
        return 'Approved';
    }
    return $s !== '' ? $s : 'Draft';
}

function poLifecycleFilterStatuses(string $filter): ?array {
    $filter = trim($filter);
    $map = [
        'For Approval' => ['Submitted', 'Pending Approval'],
        'Approved' => ['Approved'],
        'Sent to Supplier' => ['Sent', 'Supplier Confirmed', 'Waiting for Delivery'],
        'Receiving' => ['Partially Received'],
        'Completed' => ['Received'],
        'Draft' => ['Draft'],
        'Rejected' => ['Rejected', 'Cancelled'],
        'all' => null,
        'All' => null,
    ];
    if ($filter === '' || strcasecmp($filter, 'all') === 0) {
        return null;
    }
    if (isset($map[$filter])) {
        return $map[$filter];
    }
    if (in_array($filter, poLifecycleAllowedDbStatuses(), true)) {
        return [$filter];
    }
    return null;
}

function poLifecycleAttach(array &$row): void {
    $row['lifecycle_status'] = poLifecycleLabel((string)($row['status'] ?? ''), $row);
}

function poLifecycleMatches(array $row, string $filter): bool {
    $filter = trim($filter);
    if ($filter === '' || strcasecmp($filter, 'all') === 0) {
        return true;
    }
    $label = (string)($row['lifecycle_status'] ?? poLifecycleLabel((string)($row['status'] ?? ''), $row));
    return strcasecmp($label, $filter) === 0;
}
