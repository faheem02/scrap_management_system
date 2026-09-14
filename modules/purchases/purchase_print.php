<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Purchase Invoice';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$purchase = getById('purchases', $id);
if (!$purchase) redirect('index.php', 'Purchase not found', 'error');

$st = $pdo->prepare(
    "SELECT pi.*, pr.name, pr.unit
     FROM purchase_items pi
     LEFT JOIN products pr ON pi.product_id = pr.id
     WHERE pi.purchase_id = ?
     ORDER BY pi.id"
);
$st->execute([$id]);
$items = $st->fetchAll();

$fmt_w = fn($w) => rtrim(rtrim(number_format((float)$w, 2), '0'), '.');

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-print-none mb-3 d-flex justify-content-between align-items-center">
  <a href="<?= $base_url ?>modules/purchases/index.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Purchases</a>
  <div>
    <button type="button" class="btn btn-danger btn-sm mr-1" onclick="downloadPDF('purchasePrintArea', 'Purchase_<?= htmlspecialchars($purchase['invoice_no']) ?>')">
      <i class="fas fa-file-pdf"></i> Download PDF
    </button>
    <button class="btn btn-secondary btn-sm mr-1" onclick="printCopies(2)">
      <i class="fas fa-copy"></i> Print 2 Copies
    </button>
    <button class="btn btn-primary btn-sm" onclick="window.print()">
      <i class="fas fa-print"></i> Print 1 Copy
    </button>
  </div>
</div>

<div class="card shadow" id="purchasePrintArea">
  <div class="card-body">

    <!-- Header -->
    <div class="row border-bottom pb-3 mb-3 align-items-center">
      <div class="col-6">
        <div class="d-flex align-items-center">
          <img src="<?= $base_url ?>assets/img/logo.png" alt="ARAB KHEL" style="width: 58px; height: 58px; border-radius: 50%; object-fit: cover; margin-right: 14px; border: 2px solid #10b981; background: #fff; flex-shrink: 0;">
          <div>
            <h4 class="font-weight-bold mb-0" style="color:#0f172a; line-height: 1.2;">ARAB KHEL</h4>
            <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
          </div>
        </div>
      </div>
      <div class="col-6 text-right">
        <h5 class="font-weight-bold text-primary">PURCHASE INVOICE</h5>
        <div><strong>Invoice #:</strong> <?= htmlspecialchars($purchase['invoice_no']) ?></div>
        <div><strong>Date:</strong> <?= formatDate($purchase['purchase_date']) ?></div>
      </div>
    </div>

    <!-- Vehicle / Plant & Payment info -->
    <div class="row mb-3">
      <div class="col-6">
        <h6 class="text-muted text-uppercase">Vehicle &amp; Plant</h6>
        <?php if (!empty($purchase['party_name'])): ?>
        <div><strong>Party:</strong> <?= htmlspecialchars($purchase['party_name']) ?></div>
        <?php endif; ?>
        <div><strong>Vehicle No:</strong>
          <span class="font-weight-bold text-primary"><?= htmlspecialchars($purchase['vehicle_no'] ?? '—') ?></span>
        </div>
        <?php if (!empty($purchase['plant_area'])): ?>
        <div><strong>Plant Area:</strong> <?= htmlspecialchars($purchase['plant_area']) ?></div>
        <?php endif; ?>
        <?php if (!empty($purchase['plant_category'])): ?>
        <div><strong>Plant Category:</strong> <?= htmlspecialchars($purchase['plant_category']) ?></div>
        <?php endif; ?>
      </div>
      <div class="col-6 text-right">
        <h6 class="text-muted text-uppercase">Payment</h6>
        <div><strong>Method:</strong> <?= ucfirst($purchase['payment_method']) ?></div>
        <div><strong>Total (Freight):</strong> PKR <?= formatCurrency($purchase['total_amount']) ?></div>
        <div><strong>Paid:</strong> PKR <?= formatCurrency($purchase['paid_amount']) ?></div>
        <div><strong>Due:</strong> PKR <?= formatCurrency($purchase['due_amount']) ?></div>
      </div>
    </div>

    <!-- Materials table -->
    <div class="table-responsive">
      <table class="table table-bordered">
        <thead>
          <tr>
            <th>#</th>
            <th>Material</th>
            <th>Unit</th>
            <th>Weight 1 (WIL)</th>
            <th>Weight 2</th>
            <th>Final Weight</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i => $it): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($it['name'] ?? 'Deleted') ?></td>
            <td><?= htmlspecialchars($it['unit'] ?? '-') ?></td>
            <td><?= $fmt_w($it['weight1'] ?? 0) ?></td>
            <td><?= $fmt_w($it['weight2'] ?? 0) ?></td>
            <td class="font-weight-bold"><?= $fmt_w($it['quantity']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php
          $total_weight = array_sum(array_column($items, 'quantity'));
          ?>
          <tr>
            <th colspan="5" class="text-right">Total Weight:</th>
            <th><?= $fmt_w($total_weight) ?></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Freight totals -->
    <div class="row justify-content-end mt-2">
      <div class="col-md-4">
        <table class="table table-sm table-bordered">
          <tr><th>Total (Freight)</th><td class="font-weight-bold">PKR <?= formatCurrency($purchase['total_amount']) ?></td></tr>
          <tr><th class="text-success">Paid</th><td class="text-success">PKR <?= formatCurrency($purchase['paid_amount']) ?></td></tr>
          <tr><th class="text-danger">Due</th><td class="<?= $purchase['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>">PKR <?= formatCurrency($purchase['due_amount']) ?></td></tr>
        </table>
      </div>
    </div>

    <?php if ($purchase['notes']): ?>
    <div class="mt-2"><strong>Notes:</strong> <?= htmlspecialchars($purchase['notes']) ?></div>
    <?php endif; ?>

  </div>
</div>

<style>
  @media print {
    .print-copy-extra { margin-top: 20px; }
    .no-print { display: none !important; }
    #purchasePrintArea { font-size: 15px !important; color: #000 !important; }
    #purchasePrintArea table th { font-size: 15px !important; font-weight: 800 !important; color: #000 !important; border: 1.5px solid #334155 !important; }
    #purchasePrintArea table td { font-size: 14.5px !important; font-weight: 600 !important; color: #000 !important; border: 1px solid #475569 !important; }
    #purchasePrintArea table tfoot td { font-size: 15.5px !important; font-weight: 800 !important; }
  }
</style>

<script>
function printCopies(n) {
  var $card = $('.card.shadow').first();
  var original = $card.clone();
  $('.print-copy-extra').remove();
  for (var i = 1; i < n; i++) {
    var $copy = original.clone().addClass('print-copy-extra mt-4').css('border-top','2px dashed #aaa');
    $copy.find('.d-print-none').remove();
    $card.after($copy);
  }
  window.print();
  setTimeout(function(){ $('.print-copy-extra').remove(); }, 1000);
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
