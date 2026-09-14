<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Purchase List';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// Filters
$from       = $_GET['from'] ?? '';
$to         = $_GET['to'] ?? '';
$vehicle_q  = trim($_GET['vehicle_q'] ?? '');

$sql = "SELECT * FROM purchases WHERE 1=1";
$params = [];
if ($from)      { $sql .= " AND purchase_date >= ?";      $params[] = $from; }
if ($to)        { $sql .= " AND purchase_date <= ?";      $params[] = $to; }
if ($vehicle_q) { $sql .= " AND vehicle_no LIKE ?";       $params[] = '%' . $vehicle_q . '%'; }
$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$total_purchases = 0; $total_paid = 0; $total_due = 0;
foreach ($purchases as $p) {
    if ($p['status'] != 'cancelled') {
        $total_purchases += $p['total_amount'];
        $total_paid      += $p['paid_amount'];
        $total_due       += $p['due_amount'];
    }
}
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Action Toolbar (Screen Only) -->
<div class="d-print-none mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-cart-arrow-down text-primary"></i> Purchases Register</h5>
    <small class="text-muted">Total Purchases: <?= count($purchases) ?> records</small>
  </div>
  <div class="d-flex flex-wrap gap-1">
    <button type="button" class="btn btn-sm btn-danger mr-1" onclick="downloadPDF('purchasesPrintArea', 'ARAB_KHEL_Purchases_List', 'landscape')">
      <i class="fas fa-file-pdf"></i> Download PDF
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary mr-1" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    <a href="<?= $base_url ?>modules/purchases/create.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> New Purchase</a>
  </div>
</div>

<div class="card shadow" id="purchasesPrintArea">
  <div class="card-body">
    <form method="get" id="purchaseFilterForm" class="row g-2 mb-3 d-print-none align-items-center">
      <div class="col-md-3">
        <div class="input-group">
          <div class="input-group-prepend"><span class="input-group-text font-weight-bold small">From</span></div>
          <input type="date" name="from" id="purchaseFromDate" class="form-control" value="<?= htmlspecialchars($from) ?>" title="From Date">
        </div>
      </div>
      <div class="col-md-3">
        <div class="input-group">
          <div class="input-group-prepend"><span class="input-group-text font-weight-bold small">To</span></div>
          <input type="date" name="to" id="purchaseToDate" class="form-control" value="<?= htmlspecialchars($to) ?>" title="To Date">
        </div>
      </div>
      <div class="col-md-3">
        <input type="text" name="vehicle_q" class="form-control" placeholder="Filter by vehicle no..." value="<?= htmlspecialchars($vehicle_q) ?>">
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-primary flex-fill"><i class="fas fa-filter"></i> Filter</button>
        <?php if ($from || $to || $vehicle_q): ?>
        <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i> Reset</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="row mb-3">
      <div class="col-md-4 text-center"><strong>Total Purchases:</strong> <span class="text-primary">PKR <?=formatCurrency($total_purchases)?></span></div>
      <div class="col-md-4 text-center"><strong>Total Paid:</strong> <span class="text-success">PKR <?=formatCurrency($total_paid)?></span></div>
      <div class="col-md-4 text-center"><strong>Total Due:</strong> <span class="text-danger">PKR <?=formatCurrency($total_due)?></span></div>
    </div>

    <!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <img src="<?= $base_url ?>assets/img/logo.png" alt="ARAB KHEL" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; margin-bottom: 6px; border: 1.5px solid #10b981; background: #fff;">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h4>
  <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">PURCHASE LIST</h5>
  <?php $p_meta = [];
  if ($from) $p_meta[] = 'From: ' . formatDate($from);
  if ($to) $p_meta[] = 'To: ' . formatDate($to);
  if ($vehicle_q) $p_meta[] = 'Vehicle: ' . htmlspecialchars($vehicle_q);
  if ($p_meta): ?><div class="mt-1 font-weight-bold"><?= implode(' &nbsp;|&nbsp; ', $p_meta) ?></div><?php endif; ?>
  <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
</div>

<div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead>
          <tr><th>Invoice</th><th>Date</th><th>Vehicle No</th><th>Plant</th><th>Total</th><th>Paid</th><th>Due</th><th>Method</th><th class="d-print-none">Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($purchases as $p): ?>
          <tr>
            <td class="font-weight-bold"><?= htmlspecialchars($p['invoice_no']) ?></td>
            <td><?= formatDate($p['purchase_date']) ?></td>
            <td><span class="badge badge-info"><?= htmlspecialchars($p['vehicle_no'] ?? '—') ?></span></td>
            <td>
              <?php if (!empty($p['plant_area']) || !empty($p['plant_category'])): ?>
                <small><?= htmlspecialchars($p['plant_area'] ?? '') ?><?= (!empty($p['plant_area']) && !empty($p['plant_category']) ? ' · ' : '') ?><?= htmlspecialchars($p['plant_category'] ?? '') ?></small>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>PKR <?= formatCurrency($p['total_amount']) ?></td>
            <td class="text-success">PKR <?= formatCurrency($p['paid_amount']) ?></td>
            <td class="<?= $p['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>">PKR <?= formatCurrency($p['due_amount']) ?></td>
            <td><span class="badge badge-secondary"><?= ucfirst($p['payment_method']) ?></span></td>
            <td class="text-center d-print-none" nowrap>
              <button type="button" class="btn btn-sm btn-outline-info view-purchase" data-id="<?=$p['id']?>" title="View Purchase"><i class="fas fa-eye"></i></button>
              <a href="<?= $base_url ?>modules/purchases/purchase_edit.php?id=<?=$p['id']?>" class="btn btn-sm btn-outline-warning" title="Edit Purchase"><i class="fas fa-edit"></i></a>
              <form method="post" action="<?= $base_url ?>modules/purchases/purchase_delete.php" class="d-inline" onsubmit="return confirm('Delete this purchase? This will reverse stock &amp; payments.');">
                <input type="hidden" name="id" value="<?=$p['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Purchase"><i class="fas fa-trash-alt"></i></button>
              </form>
              <a href="<?= $base_url ?>modules/purchases/purchase_print.php?id=<?=$p['id']?>" class="btn btn-sm btn-outline-primary" title="Print Purchase" target="_blank"><i class="fas fa-print"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($purchases)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No purchases found. <a href="<?= $base_url ?>modules/purchases/create.php">Make your first purchase</a></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Purchase View Modal -->
<div class="modal fade" id="purchaseViewModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="fas fa-cart-arrow-down"></i> Purchase Details</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" id="purchaseViewBody">
        <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  $(document).on('click', '.view-purchase', function(){
    var id = $(this).data('id');
    $('#purchaseViewBody').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $('#purchaseViewModal').modal('show');
    $.ajax({
      url: 'ajax_purchase_view.php',
      data: {id: id},
      dataType: 'html'
    }).done(function(html){
      $('#purchaseViewBody').html(html);
    }).fail(function(){
      $('#purchaseViewBody').html('<div class="alert alert-danger mb-0">Could not load purchase details. Please refresh and try again.</div>');
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>