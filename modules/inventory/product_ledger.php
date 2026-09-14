<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Material Ledger';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker','loader']);

$id = (int)($_GET['id'] ?? 0);
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';

$st = $pdo->prepare("SELECT p.*, c.name AS cat_name
                     FROM products p
                     LEFT JOIN categories c ON p.category_id = c.id
                     WHERE p.id = ?");
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { redirect('products.php', 'Material not found', 'error'); }

// --- Purchases query with date filter ---
$pur_sql = "SELECT pi.quantity, pi.purchase_price, pi.subtotal,
                   pu.invoice_no, pu.purchase_date AS txn_date,
                   pu.party_name AS party_name
            FROM purchase_items pi
            JOIN purchases pu ON pi.purchase_id = pu.id
            WHERE pi.product_id = ? AND pu.status <> 'cancelled'";
$pur_params = [$id];
if ($from) { $pur_sql .= " AND pu.purchase_date >= ?"; $pur_params[] = $from; }
if ($to)   { $pur_sql .= " AND pu.purchase_date <= ?"; $pur_params[] = $to; }
$pur_sql .= " ORDER BY pu.purchase_date ASC, pu.id ASC";
$st = $pdo->prepare($pur_sql);
$st->execute($pur_params);
$purchases = $st->fetchAll();

// --- Sales query with date filter ---
$sal_sql = "SELECT si.quantity, si.price, si.subtotal,
                   sa.invoice_no, sa.sale_date AS txn_date,
                   cu.full_name AS party_name
            FROM sale_items si
            JOIN sales sa ON si.sale_id = sa.id
            LEFT JOIN customers cu ON sa.customer_id = cu.id
            WHERE si.product_id = ? AND sa.status <> 'cancelled'";
$sal_params = [$id];
if ($from) { $sal_sql .= " AND sa.sale_date >= ?"; $sal_params[] = $from; }
if ($to)   { $sal_sql .= " AND sa.sale_date <= ?"; $sal_params[] = $to; }
$sal_sql .= " ORDER BY sa.sale_date ASC, sa.id ASC";
$st = $pdo->prepare($sal_sql);
$st->execute($sal_params);
$sales = $st->fetchAll();

// --- Merge and sort chronologically ---
$rows = [];
foreach ($purchases as $r) {
    $rows[] = [
        'date'       => $r['txn_date'],
        'type'       => 'purchase',
        'invoice'    => $r['invoice_no'],
        'party'      => $r['party_name'] ?? '-',
        'qty_in'     => (float)$r['quantity'],
        'qty_out'    => 0,
        'rate'       => (float)$r['purchase_price'],
        'subtotal'   => (float)$r['subtotal'],
    ];
}
foreach ($sales as $r) {
    $rows[] = [
        'date'       => $r['txn_date'],
        'type'       => 'sale',
        'invoice'    => $r['invoice_no'],
        'party'      => $r['party_name'] ?? '-',
        'qty_in'     => 0,
        'qty_out'    => (float)$r['quantity'],
        'rate'       => (float)$r['price'],
        'subtotal'   => (float)$r['subtotal'],
    ];
}
usort($rows, fn($a,$b) => strcmp($a['date'], $b['date']));

// Running stock
$running_stock = 0;
foreach ($rows as &$r) {
    $running_stock += $r['qty_in'] - $r['qty_out'];
    $r['running_stock'] = $running_stock;
}
unset($r);

$total_in    = array_sum(array_column($rows, 'qty_in'));
$total_out   = array_sum(array_column($rows, 'qty_out'));
$stock       = (float)$p['stock_quantity'];

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Toolbar (Screen Only) -->
<div class="d-print-none mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <a href="products.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Materials</a>
  </div>
  <div class="d-flex flex-wrap gap-1">
    <button type="button" class="btn btn-sm btn-danger mr-1" onclick="downloadPDF('productLedgerPrintArea', 'Material_Ledger_<?= htmlspecialchars($p['code']) ?>')">
      <i class="fas fa-file-pdf"></i> Download PDF
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
  </div>
</div>

<!-- Material Info Card (Screen Only) -->
<div class="card shadow mb-3 d-print-none">
  <div class="card-body py-2">
    <div class="row align-items-center">
      <div class="col-md-6">
        <h5 class="font-weight-bold mb-1 text-dark">
          <i class="fas fa-box text-primary"></i>
          <?= htmlspecialchars($p['name']) ?>
          <span class="badge badge-secondary ml-1"><?= htmlspecialchars($p['code']) ?></span>
          <?= $p['status'] ? '<span class="badge badge-success ml-1">Active</span>' : '<span class="badge badge-secondary ml-1">Inactive</span>' ?>
        </h5>
        <div class="text-muted small">
          Category: <strong><?= htmlspecialchars($p['cat_name'] ?? '-') ?></strong>
          &nbsp;|&nbsp; Unit: <strong><?= htmlspecialchars($p['unit']) ?></strong>
          <?php if (!empty($p['description'])): ?>
          &nbsp;|&nbsp; <?= htmlspecialchars($p['description']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-6 text-md-right mt-2 mt-md-0">
        <?php
          $stockClass = $stock <= 0 ? 'text-danger' : ($stock <= (float)$p['min_stock_level'] && (float)$p['min_stock_level'] > 0 ? 'text-warning' : 'text-success');
        ?>
        <div class="text-xs text-uppercase text-muted">Current Stock</div>
        <div class="h4 font-weight-bold <?= $stockClass ?> mb-0">
          <?= (float)$stock ?> <?= htmlspecialchars($p['unit']) ?>
        </div>
        <?php if ($stock <= 0): ?>
          <small class="text-danger"><i class="fas fa-exclamation-circle"></i> Out of stock</small>
        <?php elseif ($stock <= (float)$p['min_stock_level'] && (float)$p['min_stock_level'] > 0): ?>
          <small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Low stock</small>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Date Filter -->
<div class="card shadow mb-3 d-print-none">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="col-md-4">
        <div class="input-group">
          <div class="input-group-prepend"><span class="input-group-text font-weight-bold small">From</span></div>
          <input type="date" name="from" id="ledgerFrom" class="form-control" value="<?= htmlspecialchars($from) ?>">
        </div>
      </div>
      <div class="col-md-4">
        <div class="input-group">
          <div class="input-group-prepend"><span class="input-group-text font-weight-bold small">To</span></div>
          <input type="date" name="to" id="ledgerTo" class="form-control" value="<?= htmlspecialchars($to) ?>">
        </div>
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-filter"></i> Filter</button>
        <?php if ($from || $to): ?>
        <a href="product_ledger.php?id=<?= $id ?>" class="btn btn-outline-secondary"><i class="fas fa-times"></i> Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- ===== PRINTABLE HEADER ===== -->
<div id="productLedgerPrintArea">
<div class="d-none d-print-block mb-3 text-center">
  <img src="<?= $base_url ?>assets/img/logo.png" alt="ARAB KHEL"
       style="width:60px;height:60px;border-radius:50%;object-fit:cover;margin-bottom:8px;border:2px solid #10b981;background:#fff;">
  <h3 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h3>
  <div style="font-size:14px;color:#475569;font-weight:600;">Near Itifaq Kanta Misrishah Lahore</div>
  <h4 class="font-weight-bold text-primary mt-2 mb-1">MATERIAL LEDGER</h4>
  <div style="font-size:16px;font-weight:700;color:#0f172a;">
    <?= htmlspecialchars($p['name']) ?>
    <span style="font-weight:500;font-size:14px;">(<?= htmlspecialchars($p['code']) ?>)</span>
  </div>
  <div style="font-size:13px;font-weight:600;color:#334155;margin-top:4px;">
    Category: <?= htmlspecialchars($p['cat_name'] ?? '-') ?>
    &nbsp;|&nbsp; Unit: <?= htmlspecialchars($p['unit']) ?>
    &nbsp;|&nbsp; Current Stock: <strong><?= (float)$stock ?> <?= htmlspecialchars($p['unit']) ?></strong>
  </div>
  <?php if ($from || $to): ?>
  <div style="font-size:13px;font-weight:700;color:#0f172a;margin-top:4px;">
    Period: <?= $from ? formatDate($from) : 'Beginning' ?> &mdash; <?= $to ? formatDate($to) : 'Present' ?>
  </div>
  <?php endif; ?>
  <div style="font-size:12px;color:#475569;font-weight:600;margin-top:2px;">
    Printed on <?= date('d-m-Y H:i') ?> by <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>
  </div>
</div>

<!-- ===== SUMMARY BOXES ===== -->
<div class="row mb-3">
  <div class="col-4">
    <div class="card text-center border-left-success">
      <div class="card-body py-2">
        <div class="text-xs text-uppercase text-muted">Total Purchased</div>
        <div class="h5 font-weight-bold text-success mb-0"><?= number_format($total_in, 2) ?> <small><?= htmlspecialchars($p['unit']) ?></small></div>
      </div>
    </div>
  </div>
  <div class="col-4">
    <div class="card text-center border-left-danger">
      <div class="card-body py-2">
        <div class="text-xs text-uppercase text-muted">Total Sold</div>
        <div class="h5 font-weight-bold text-danger mb-0"><?= number_format($total_out, 2) ?> <small><?= htmlspecialchars($p['unit']) ?></small></div>
      </div>
    </div>
  </div>
  <div class="col-4">
    <div class="card text-center border-left-primary">
      <div class="card-body py-2">
        <div class="text-xs text-uppercase text-muted">Net (Period)</div>
        <div class="h5 font-weight-bold text-primary mb-0"><?= number_format($total_in - $total_out, 2) ?> <small><?= htmlspecialchars($p['unit']) ?></small></div>
      </div>
    </div>
  </div>
</div>

<!-- ===== LEDGER TABLE ===== -->
<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center d-print-none">
    <h6 class="mb-0"><i class="fas fa-book"></i> Transaction History
      <?php if ($from || $to): ?>
        <span class="badge badge-info ml-1">
          <?= $from ? formatDate($from) : '...' ?> &mdash; <?= $to ? formatDate($to) : '...' ?>
        </span>
      <?php endif; ?>
    </h6>
    <span class="badge badge-secondary"><?= count($rows) ?> record(s)</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0">
        <thead class="thead-light">
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Type</th>
            <th>Invoice</th>
            <th>Party</th>
            <th class="text-right">Rate (PKR)</th>
            <th class="text-right">Qty In</th>
            <th class="text-right">Qty Out</th>
            <th class="text-right">Stock Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows)): ?>
            <?php $i = 0; foreach ($rows as $r): $i++; ?>
            <tr class="<?= $r['type'] === 'purchase' ? '' : 'table-warning' ?>">
              <td><?= $i ?></td>
              <td><?= formatDate($r['date']) ?></td>
              <td>
                <?php if ($r['type'] === 'purchase'): ?>
                  <span class="badge badge-success"><i class="fas fa-cart-arrow-down"></i> Purchase</span>
                <?php else: ?>
                  <span class="badge badge-danger"><i class="fas fa-shopping-cart"></i> Sale</span>
                <?php endif; ?>
              </td>
              <td class="font-weight-bold"><?= htmlspecialchars($r['invoice']) ?></td>
              <td><?= htmlspecialchars($r['party']) ?></td>
              <td class="text-right">PKR <?= formatCurrency($r['rate']) ?></td>
              <td class="text-right <?= $r['qty_in'] > 0 ? 'text-success font-weight-bold' : 'text-muted' ?>">
                <?= $r['qty_in'] > 0 ? '+'.number_format($r['qty_in'], 2).' '.htmlspecialchars($p['unit']) : '-' ?>
              </td>
              <td class="text-right <?= $r['qty_out'] > 0 ? 'text-danger font-weight-bold' : 'text-muted' ?>">
                <?= $r['qty_out'] > 0 ? '-'.number_format($r['qty_out'], 2).' '.htmlspecialchars($p['unit']) : '-' ?>
              </td>
              <td class="text-right font-weight-bold <?= $r['running_stock'] <= 0 ? 'text-danger' : 'text-primary' ?>">
                <?= number_format($r['running_stock'], 2) ?> <?= htmlspecialchars($p['unit']) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="9" class="text-center text-muted py-4">
                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                No transactions found<?= ($from || $to) ? ' for the selected period.' : '.' ?>
                <?php if ($from || $to): ?>
                  <a href="product_ledger.php?id=<?= $id ?>">View all transactions</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
        <?php if (count($rows)): ?>
        <tfoot>
          <tr class="font-weight-bold table-active">
            <td colspan="6" class="text-right">TOTALS</td>
            <td class="text-right text-success">+<?= number_format($total_in, 2) ?> <?= htmlspecialchars($p['unit']) ?></td>
            <td class="text-right text-danger">-<?= number_format($total_out, 2) ?> <?= htmlspecialchars($p['unit']) ?></td>
            <td class="text-right <?= $stock <= 0 ? 'text-danger' : 'text-primary' ?>">
              <?= number_format($stock, 2) ?> <?= htmlspecialchars($p['unit']) ?> <small>(Live)</small>
            </td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>


  </div>
</div>
</div><!-- /productLedgerPrintArea -->

<script>
// Auto-submit form when quick date preset changes dates
function setDatePreset(fromId, toId, preset) {
  var fromInput = document.getElementById(fromId);
  var toInput   = document.getElementById(toId);
  if (!fromInput || !toInput) return;
  var now = new Date();
  var pad = function(n) { return (n < 10 ? '0' : '') + n; };
  var fmt = function(d) { return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); };
  if (preset === 'today') {
    fromInput.value = fmt(now); toInput.value = fmt(now);
  } else if (preset === 'yesterday') {
    var y = new Date(now); y.setDate(y.getDate()-1);
    fromInput.value = fmt(y); toInput.value = fmt(y);
  } else if (preset === 'this_month') {
    fromInput.value = fmt(new Date(now.getFullYear(), now.getMonth(), 1));
    toInput.value   = fmt(now);
  } else if (preset === 'all') {
    fromInput.value = ''; toInput.value = '';
  }
  fromInput.closest('form').submit();
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
