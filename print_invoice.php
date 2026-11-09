<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . '/billing_print_lib.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access.');
}

$invoiceNumber = trim($_GET['invoice_number'] ?? '');
if ($invoiceNumber === '') {
    die('Invoice number is required.');
}

$conn = billingPrintConnect();
$bundle = billingFetchInvoiceBundle($conn, $invoiceNumber);
$warrantySoId = 0;
if ($bundle) {
    require_once __DIR__ . '/route_protection.php';
    require_once __DIR__ . '/warranty_lib.php';
    if (warrantyCanIssue(normalize_user_role((string)($_SESSION['role'] ?? '')))) {
        warrantyEnsureSchema($conn);
        $soIdForWarranty = (int)($bundle['invoice']['sales_order_id'] ?? 0);
        if (warrantySaleHasEligibleItems($conn, $soIdForWarranty)) {
            $warrantySoId = $soIdForWarranty;
        }
    }
}
$conn->close();
if (!$bundle) {
    die('Invoice not found.');
}

$withReceipt = isset($_GET['with_receipt']) && $_GET['with_receipt'] !== '0';
$latestPayment = $bundle['payments'] ? $bundle['payments'][count($bundle['payments']) - 1] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Invoice <?= htmlspecialchars($invoiceNumber) ?> | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(billingPrintWebBase()) ?>images/clarenzon-removebg.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style><?= billingPrintCss() ?></style>
</head>
<body>
<?php
$links = ['print_payment_receipt.php?invoice_number=' . urlencode($invoiceNumber) => 'Customer Receipt'];
if (!$withReceipt) {
    $links['print_invoice.php?invoice_number=' . urlencode($invoiceNumber) . '&with_receipt=1'] = 'Invoice + Receipt';
}
if ($warrantySoId > 0) {
    $links['print_warranty.php?so_id=' . $warrantySoId] = 'Warranty Certificates';
}
billingPrintToolbar('Customer Sales Invoice ' . $invoiceNumber, $links);
billingRenderCustomerInvoice($bundle['invoice'], $bundle['items'], $bundle['payments']);
if ($withReceipt) {
    billingRenderCustomerReceipt($bundle['invoice'], $bundle['items'], $latestPayment);
}
?>
<script>
window.addEventListener('DOMContentLoaded', () => { setTimeout(() => window.print(), 400); });
</script>
</body>
</html>
