<?php
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session_bootstrap.php';
require_once __DIR__ . '/billing_print_lib.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access.');
}

$paymentNumber = trim($_GET['payment_number'] ?? '');
$invoiceNumber = trim($_GET['invoice_number'] ?? '');
if ($paymentNumber === '' && $invoiceNumber === '') {
    die('Payment number or invoice number is required.');
}

$conn = billingPrintConnect();
$bundle = billingFetchPaymentBundle($conn, $paymentNumber, $invoiceNumber);
if (!$bundle && $invoiceNumber !== '') {
    $bundle = billingFetchInvoiceBundle($conn, $invoiceNumber);
}
$conn->close();
if (!$bundle) {
    die('Customer receipt not found.');
}

$invoice = $bundle['invoice'];
$payment = $bundle['payment'] ?? ($bundle['payments'][count($bundle['payments']) - 1] ?? null);
$receiptNo = $payment['payment_number'] ?? 'Pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Receipt <?= htmlspecialchars($receiptNo) ?> | Clarenzon Medical</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(billingPrintWebBase()) ?>images/clarenzon-removebg.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style><?= billingPrintCss() ?></style>
</head>
<body>
<?php
billingPrintToolbar('Customer Official Receipt ' . $receiptNo, [
    'print_invoice.php?invoice_number=' . urlencode($invoice['invoice_number']) => 'Customer Invoice',
    'print_invoice.php?invoice_number=' . urlencode($invoice['invoice_number']) . '&with_receipt=1' => 'Invoice + Receipt',
]);
billingRenderCustomerReceipt($invoice, $bundle['items'], $payment);
?>
<script>
window.addEventListener('DOMContentLoaded', () => { setTimeout(() => window.print(), 400); });
</script>
</body>
</html>
