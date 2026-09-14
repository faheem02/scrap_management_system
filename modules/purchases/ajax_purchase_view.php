<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Not found'; exit; }

$purchase = getById('purchases', $id);
if (!$purchase) { http_response_code(404); echo 'Not found'; exit; }

$st = $pdo->prepare(
    "SELECT pi.*, pr.name AS product_name, pr.unit
     FROM purchase_items pi
     LEFT JOIN products pr ON pi.product_id = pr.id
     WHERE pi.purchase_id = ?
     ORDER BY pi.id"
);
$st->execute([$id]);
$items = $st->fetchAll();

$status_badge = $purchase['status'] === 'cancelled'
    ? '<span class="badge badge-danger">Cancelled</span>'
    : ($purchase['due_amount'] > 0
        ? '<span class="badge badge-warning">Partial</span>'
        : '<span class="badge badge-success">Paid</span>');

$fmt_w = fn($w) => rtrim(rtrim(number_format((float)$w, 2), '0'), '.');
?>
<h6>
  <?= htmlspecialchars($purchase['invoice_no']) ?>
  <?= $status_badge ?>
</h6>

<table class="table table-sm table-bordered mb-3">
  <tbody>
    <tr>
      <th class="w-25">Vehicle No</th>
      <td class="font-weight-bold text-primary"><?= htmlspecialchars($purchase['vehicle_no'] ?? '—') ?></td>
      <th>Party</th>
      <td><?= htmlspecialchars($purchase['party_name'] ?? '—') ?></td>
    </tr>
    <tr>
      <th>Date</th>
      <td><?= formatDate($purchase['purchase_date']) ?></td>
      <th>Method</th>
      <td><span class="badge badge-secondary"><?= ucfirst($purchase['payment_method']) ?></span></td>
    </tr>
    <tr>
      <th>Plant Area</th>
      <td><?= htmlspecialchars($purchase['plant_area'] ?? '-') ?></td>
      <th>Plant Category</th>
      <td><?= htmlspecialchars($purchase['plant_category'] ?? '-') ?></td>
    </tr>
  </tbody>
</table>

<?php if (count($items)): ?>
<div class="table-responsive mb-3">
  <table class="table table-sm table-bordered">
    <thead class="thead-light">
      <tr><th>#</th><th>Material</th><th>Unit</th><th>Weight 1 (WIL)</th><th>Weight 2</th><th>Final Weight</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $it): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($it['product_name'] ?? 'Deleted') ?></td>
        <td><?= htmlspecialchars($it['unit'] ?? '-') ?></td>
        <td><?= $fmt_w($it['weight1'] ?? 0) ?></td>
        <td><?= $fmt_w($it['weight2'] ?? 0) ?></td>
        <td class="font-weight-bold"><?= $fmt_w($it['quantity']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="5" class="text-right">Total Weight:</th>
        <th><?= $fmt_w(array_sum(array_column($items, 'quantity'))) ?></th>
      </tr>
    </tfoot>
  </table>
</div>
<?php else: ?>
<p class="text-muted small">No items found.</p>
<?php endif; ?>

<table class="table table-sm w-50">
  <tr><th>Total (Freight)</th><td class="font-weight-bold">PKR <?= formatCurrency($purchase['total_amount']) ?></td></tr>
  <tr><th>Paid</th><td class="text-success">PKR <?= formatCurrency($purchase['paid_amount']) ?></td></tr>
  <tr>
    <th>Due</th>
    <td class="<?= $purchase['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>">
      PKR <?= formatCurrency($purchase['due_amount']) ?>
    </td>
  </tr>
</table>

<?php if ($purchase['notes']): ?>
<div class="mt-2"><strong>Notes:</strong> <?= htmlspecialchars($purchase['notes']) ?></div>
<?php endif; ?>
