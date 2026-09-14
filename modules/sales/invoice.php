<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Sale Invoice';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$id = (int)($_GET['id'] ?? 0);
$sale = getById('sales', $id);
if (!$sale) { redirect('invoices.php', 'Invoice not found', 'error'); }

$customer = getById('customers', $sale['customer_id']);
$salesman = $sale['salesman_id'] ? getById('employees', $sale['salesman_id']) : null;
$items = $pdo->prepare("SELECT si.*, p.name, p.unit, p.purchase_price FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
$total_cost = 0;
foreach ($items as $it) { $total_cost += (float)$it['purchase_price'] * (float)$it['quantity']; }
$total_profit = (float)$sale['paid_amount'] - $total_cost;

$printed_by = '';
if (!empty($sale['created_by'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$sale['created_by']]);
    $printed_by = (string)$pu->fetchColumn();
}

$logo_path = dirname(__DIR__, 2) . '/assets/img/logo.png';
$logo_data = file_exists($logo_path) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logo_path))) : (($base_url ?? '') . 'assets/img/logo.png');

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-print-none mb-3 d-flex justify-content-between align-items-center">
  <a href="invoices.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Invoices</a>
  <div>
    <a href="thermal_print.php?id=<?= $id ?>" class="btn btn-dark btn-sm mr-1">
      <i class="fas fa-receipt"></i> Thermal Print
    </a>
    <button type="button" class="btn btn-danger btn-sm mr-1" onclick="downloadPDF('invoicePrintArea', 'Invoice_<?= htmlspecialchars($sale['invoice_no']) ?>')">
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

<div class="card shadow" id="invoicePrintArea">
  <div class="card-body">
    <div class="row border-bottom pb-3 mb-3 align-items-center">
      <div class="col-6">
        <div class="d-flex align-items-center">
          <img src="<?= $logo_data ?>" alt="ARAB KHEL" style="width: 58px; height: 58px; border-radius: 50%; object-fit: cover; margin-right: 14px; border: 2px solid #10b981; background: #fff; flex-shrink: 0;">
          <div>
            <h4 class="font-weight-bold mb-0" style="color:#0f172a; line-height: 1.2;">ARAB KHEL</h4>
            <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
          </div>
        </div>
      </div>
      <div class="col-6 text-right">
        <h5 class="font-weight-bold text-primary">SALE INVOICE</h5>
        <div><strong>Invoice #:</strong> <?= htmlspecialchars($sale['invoice_no']) ?></div>
        <div><strong>Date:</strong> <?= formatDate($sale['sale_date']) ?></div>
        <div><strong>Payment Method:</strong> <?= ucfirst($sale['payment_method']) ?></div>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-6">
        <h6 class="text-muted text-uppercase">Billed To (Customer)</h6>
        <div class="font-weight-bold"><?= htmlspecialchars($customer['full_name'] ?? 'N/A') ?></div>
        <div><?= htmlspecialchars($customer['phone'] ?? '') ?></div>
        <?php if (!empty($customer['plant_area'])): ?><div>Plant Area: <?= htmlspecialchars($customer['plant_area']) ?></div><?php endif; ?>
        <?php if (!empty($customer['plant_name'])): ?><div>Plant Name: <?= htmlspecialchars($customer['plant_name']) ?></div><?php endif; ?>
        <?php if ($salesman): ?>
        <div class="mt-2"><strong>Delivered By:</strong> <?= htmlspecialchars($salesman['full_name']) ?>
          <?php if (!empty($salesman['area'])): ?><small class="text-muted">(<?= htmlspecialchars($salesman['area']) ?>)</small><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="col-6 text-right">
        <h6 class="text-muted text-uppercase">Payment</h6>
        <div><strong>Paid:</strong> PKR <?= formatCurrency($sale['paid_amount']) ?></div>
        <div><strong>Due:</strong> PKR <?= formatCurrency($sale['due_amount']) ?></div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered">
        <thead>
          <tr><th>#</th><th>Material</th><th>Unit</th><th class="text-right">Weight</th><th class="text-right">Rate</th><th class="text-right">Subtotal</th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i => $it): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($it['name']) ?></td>
            <td><?= htmlspecialchars($it['unit'] ?? '—') ?></td>
            <td class="text-right"><?= (float)$it['quantity'] ?></td>
            <td class="text-right">PKR <?= formatCurrency($it['price']) ?></td>
            <td class="text-right">PKR <?= formatCurrency($it['subtotal']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><th colspan="5" class="text-right">Total:</th><th class="text-right">PKR <?= formatCurrency($sale['total_amount']) ?></th></tr>
          <?php if ($sale['discount_amount'] > 0): ?>
          <tr><th colspan="5" class="text-right text-muted">Discount:</th><th class="text-right">- PKR <?= formatCurrency($sale['discount_amount']) ?></th></tr>
          <?php endif; ?>
          <tr><th colspan="5" class="text-right text-success">Paid:</th><th class="text-right text-success">PKR <?= formatCurrency($sale['paid_amount']) ?></th></tr>
          <tr><th colspan="5" class="text-right text-danger">Due:</th><th class="text-right text-danger">PKR <?= formatCurrency($sale['due_amount']) ?></th></tr>
        </tfoot>
      </table>
    </div>

    <?php if ($sale['notes']): ?>
    <div class="mt-3"><strong>Notes:</strong> <?= htmlspecialchars($sale['notes']) ?></div>
    <?php endif; ?>

    <div class="mt-5">
      <strong>Prepared by:</strong> <?= htmlspecialchars($printed_by ?: '—') ?>
    </div>
  </div>
</div>

<style>
  .invoice-copy + .invoice-copy { margin-top: 30px; border-top: 2px dashed #aaa; padding-top: 20px; }
  @media print {
    .invoice-copy + .invoice-copy { margin-top: 20px; }
    .no-print { display: none !important; }
    #invoicePrintArea { font-size: 15px !important; color: #000 !important; }
    #invoicePrintArea table th { font-size: 15px !important; font-weight: 800 !important; color: #000 !important; border: 1.5px solid #334155 !important; }
    #invoicePrintArea table td { font-size: 14.5px !important; font-weight: 600 !important; color: #000 !important; border: 1px solid #475569 !important; }
    #invoicePrintArea table tfoot td { font-size: 15.5px !important; font-weight: 800 !important; }
  }
</style>

<script>
function printCopies(n) {
  // Clone the invoice card n times and print
  var $card = $('.card.shadow').first();
  var original = $card.clone();

  // Remove existing clones
  $('.print-copy-extra').remove();

  for (var i = 1; i < n; i++) {
    var $copy = original.clone().addClass('print-copy-extra mt-4').css('border-top','2px dashed #aaa');
    $copy.find('.d-print-none').remove();
    $card.after($copy);
  }
  window.print();
  // Remove clones after printing
  setTimeout(function(){ $('.print-copy-extra').remove(); }, 1000);
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>