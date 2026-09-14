<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker','loader']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Material not found'); }

$st = $pdo->prepare("SELECT p.*, c.name AS cat_name
                     FROM products p
                     LEFT JOIN categories c ON p.category_id = c.id
                     WHERE p.id = ?");
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { http_response_code(404); exit('Material not found'); }

$st = $pdo->prepare("SELECT pi.quantity, pi.purchase_price, pi.subtotal,
                            pu.invoice_no, pu.purchase_date
                     FROM purchase_items pi
                     JOIN purchases pu ON pi.purchase_id = pu.id
                     WHERE pi.product_id = ? AND pu.status <> 'cancelled'
                     ORDER BY pu.id DESC LIMIT 10");
$st->execute([$id]);
$purchases = $st->fetchAll();

$st = $pdo->prepare("SELECT si.quantity, si.price, si.subtotal,
                            sa.invoice_no, sa.sale_date, cu.full_name AS customer_name
                     FROM sale_items si
                     JOIN sales sa ON si.sale_id = sa.id
                     LEFT JOIN customers cu ON sa.customer_id = cu.id
                     WHERE si.product_id = ? AND sa.status <> 'cancelled'
                     ORDER BY sa.id DESC LIMIT 10");
$st->execute([$id]);
$sales = $st->fetchAll();

$stock = (float)$p['stock_quantity'];
?>
<div class="row">
  <div class="col-md-6">
    <h6 class="text-primary"><?=htmlspecialchars($p['name'])?>
      <span class="badge badge-secondary ml-1"><?=htmlspecialchars($p['code'])?></span>
      <?= $p['status'] ? '<span class="badge badge-success ml-1">Active</span>' : '<span class="badge badge-secondary ml-1">Inactive</span>' ?>
    </h6>
  </div>
  <div class="col-md-6 text-md-right">
    <span class="font-weight-bold <?=$stock<=0?'text-danger':($stock<=max(1,(float)$p['min_stock_level'])?'text-warning':'text-success')?>">
      <?=(float)$stock?> <?=htmlspecialchars($p['unit'])?>
    </span>
  </div>
</div>
<h6 class="text-muted border-bottom pb-2 mb-2">Material Details</h6>
<table class="table table-sm table-bordered">
  <tbody>
    <tr><th class="w-25">Category</th><td><?=htmlspecialchars($p['cat_name'] ?? '-')?></td><th class="w-25">Unit</th><td><?=htmlspecialchars($p['unit'])?></td></tr>
    <tr><th>Stock</th><td><?=(float)$stock?> <?=htmlspecialchars($p['unit'])?></td><th>Min Stock Level</th><td><?=(float)$p['min_stock_level']?> <?=htmlspecialchars($p['unit'])?></td></tr>
    <tr><th>Description</th><td colspan="3"><?=htmlspecialchars($p['description'] ?? '-')?></td></tr>
  </tbody>
</table>

<?php if (count($purchases)): ?>
<h6 class="text-muted border-bottom pb-2 mb-2"><i class="fas fa-cart-arrow-down"></i> Recent Purchases</h6>
<div class="table-responsive mb-3">
  <table class="table table-sm table-bordered">
    <thead class="thead-light"><tr><th>Invoice</th><th>Date</th><th>Qty (Units)</th></tr></thead>
    <tbody>
      <?php foreach ($purchases as $pi): ?>
      <tr>
        <td><?=htmlspecialchars($pi['invoice_no'])?></td>
        <td><?=htmlspecialchars($pi['purchase_date'])?></td>
        <td><?=(float)$pi['quantity']?> <?=htmlspecialchars($p['unit'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<p class="text-muted small"><i class="fas fa-cart-arrow-down"></i> No purchases yet.</p>
<?php endif; ?>

<?php if (count($sales)): ?>
<h6 class="text-muted border-bottom pb-2 mb-2"><i class="fas fa-shopping-cart"></i> Recent Sales</h6>
<div class="table-responsive mb-2">
  <table class="table table-sm table-bordered">
    <thead class="thead-light"><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Qty (Units)</th></tr></thead>
    <tbody>
      <?php foreach ($sales as $si): ?>
      <tr>
        <td><?=htmlspecialchars($si['invoice_no'])?></td>
        <td><?=htmlspecialchars($si['sale_date'])?></td>
        <td><?=htmlspecialchars($si['customer_name'] ?? '-')?></td>
        <td><?=(float)$si['quantity']?> <?=htmlspecialchars($p['unit'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<p class="text-muted small"><i class="fas fa-shopping-cart"></i> No sales yet.</p>
<?php endif; ?>