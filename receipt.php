<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/settings.php';

if (empty($_SESSION['user_id'])) { http_response_code(403); exit('Unauthorized'); }

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) { http_response_code(400); exit('Missing order_id'); }

$db    = Database::getInstance();
$order = $db->fetchOne(
    'SELECT o.*, t.table_number
       FROM orders o
       LEFT JOIN restaurant_tables t ON t.id = o.table_id
      WHERE o.id = ?',
    [$orderId]
);

if (!$order) { http_response_code(404); exit('Order not found'); }

$lbpRate = Settings::lbpRate();

$items = $db->fetchAll(
    'SELECT item_name, quantity, unit_price, subtotal
       FROM order_items WHERE order_id = ? ORDER BY id',
    [$orderId]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?= $orderId ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 13px;
            background: #fff;
            color: #000;
            width: 300px;
            margin: 0 auto;
            padding: 16px 12px;
        }

        .center { text-align: center; }
        .right  { text-align: right; }
        .bold   { font-weight: bold; }
        .name   { font-size: 18px; font-weight: bold; letter-spacing: .04em; }
        .sub    { font-size: 11px; color: #555; }
        .divider { border-top: 1px dashed #aaa; margin: 8px 0; }
        .divider-solid { border-top: 1px solid #000; margin: 8px 0; }

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 2px 0; }
        td.qty  { width: 24px; }
        td.price{ text-align: right; white-space: nowrap; }

        .total-row td { font-weight: bold; font-size: 14px; border-top: 1px solid #000; padding-top: 5px; margin-top: 5px; }

        .badge {
            display: inline-block;
            border: 1px solid #000;
            padding: 1px 6px;
            font-size: 11px;
            margin-top: 4px;
        }

        .footer-note { font-size: 11px; color: #555; margin-top: 4px; }
        .barcode-placeholder { font-size: 9px; letter-spacing: .08em; margin-top: 6px; color: #aaa; }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="center">
    <p class="name"><?= htmlspecialchars(RESTAURANT_NAME) ?></p>
    <p class="sub"><?= htmlspecialchars(RESTAURANT_SUBTITLE) ?></p>
</div>

<div class="divider"></div>

<div class="center">
    <span class="badge"><?= strtoupper($order['order_type'] === 'takeaway' ? 'TAKEAWAY' : 'DINE IN') ?></span>
</div>

<p style="margin-top:6px;">
    <span class="bold">Receipt #<?= $orderId ?></span><br>
    <?= htmlspecialchars($order['table_number'] ? 'Table ' . $order['table_number'] : ($order['customer_name'] ?: 'Takeout')) ?><br>
    <?= date('d M Y  H:i', strtotime($order['created_at'])) ?>
</p>

<div class="divider"></div>

<table>
    <thead>
        <tr>
            <td class="bold">Item</td>
            <td class="bold qty">Qty</td>
            <td class="bold price">Amt</td>
        </tr>
    </thead>
    <tbody>
        <tr><td colspan="3"><div class="divider" style="margin:3px 0;"></div></td></tr>
        <?php foreach ($items as $item): ?>
        <tr>
            <td><?= htmlspecialchars($item['item_name']) ?></td>
            <td class="qty"><?= $item['quantity'] ?></td>
            <td class="price"><?= CURRENCY_SYMBOL ?><?= number_format($item['subtotal'], 2) ?></td>
        </tr>
        <tr>
            <td colspan="3" style="font-size:11px; color:#555; padding-bottom:3px;">
                @ <?= CURRENCY_SYMBOL ?><?= number_format($item['unit_price'], 2) ?> each
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="divider"></div>

<table>
    <tr>
        <td>Subtotal</td>
        <td class="price"><?= CURRENCY_SYMBOL ?><?= number_format($order['subtotal'], 2) ?></td>
    </tr>
    <tr>
        <td>Tax (<?= rtrim(rtrim(number_format($order['tax_rate'], 2), '0'), '.') ?>%)</td>
        <td class="price"><?= CURRENCY_SYMBOL ?><?= number_format($order['tax_amount'], 2) ?></td>
    </tr>
    <?php if ($order['discount_amount'] > 0): ?>
    <tr>
        <td>Discount</td>
        <td class="price">−<?= CURRENCY_SYMBOL ?><?= number_format($order['discount_amount'], 2) ?></td>
    </tr>
    <?php endif; ?>
    <tr class="total-row">
        <td>TOTAL</td>
        <td class="price"><?= CURRENCY_SYMBOL ?><?= number_format($order['total'], 2) ?></td>
    </tr>
    <?php if ($lbpRate > 0): ?>
    <tr>
        <td colspan="2" style="font-size:11px;color:#555;padding-top:2px;">
            = LBP <?= number_format((int)round($order['total'] * $lbpRate)) ?>
        </td>
    </tr>
    <?php endif; ?>
</table>

<?php if ($order['status'] === 'paid'): ?>
<div class="divider" style="margin-top:8px;"></div>
<table>
    <tr>
        <td>Payment</td>
        <td class="price bold"><?= ucfirst($order['payment_method'] ?? '—') ?></td>
    </tr>
    <?php if ($order['payment_method'] === 'cash' && $order['amount_tendered'] > 0): ?>
    <?php $usedLBP = !empty($order['amount_tendered_lbp']) && $order['amount_tendered_lbp'] > 0; ?>
    <tr>
        <td>Tendered</td>
        <td class="price">
            <?php if ($usedLBP): ?>
                LBP <?= number_format((int)$order['amount_tendered_lbp']) ?>
            <?php else: ?>
                <?= CURRENCY_SYMBOL ?><?= number_format($order['amount_tendered'], 2) ?>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td>Change</td>
        <td class="price">
            <?php if ($usedLBP && !empty($order['change_due_lbp'])): ?>
                LBP <?= number_format((int)$order['change_due_lbp']) ?>
            <?php else: ?>
                <?= CURRENCY_SYMBOL ?><?= number_format($order['change_due'], 2) ?>
            <?php endif; ?>
        </td>
    </tr>
    <?php endif; ?>
</table>
<?php else: ?>
<div class="center" style="margin-top:8px;">
    <span class="badge" style="border-color:#e11d48; color:#e11d48;">UNPAID — <?= strtoupper($order['status']) ?></span>
</div>
<?php endif; ?>

<div class="divider-solid" style="margin-top:12px;"></div>

<div class="center">
    <p class="footer-note">Thank you for your visit!</p>
    <p class="barcode-placeholder">*** <?= str_pad($orderId, 8, '0', STR_PAD_LEFT) ?> ***</p>
</div>

<div class="no-print" style="margin-top:20px; text-align:center;">
    <button onclick="window.print()" style="padding:8px 20px; background:#76B900; color:#000; border:none; border-radius:6px; font-weight:bold; cursor:pointer;">
        Print
    </button>
</div>

<script>
// Auto-print when opened in popup
if (window.opener) {
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>
</body>
</html>
