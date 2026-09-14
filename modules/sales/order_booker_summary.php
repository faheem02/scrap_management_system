<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Order Booker Sales Summary';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$sql = "SELECT s.invoice_no, s.sale_date, s.total_amount, s.paid_amount, s.due_amount,
               c.full_name,
               u.id AS ob_id, u.full_name AS ob_name,
               (SELECT COALESCE(SUM(p.purchase_price * si.quantity), 0)
                FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = s.id) AS total_cost
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE s.status <> 'cancelled'";
$params = [];
if ($from) { $sql .= " AND s.sale_date >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND s.sale_date <= ?"; $params[] = $to; }
$sql .= " ORDER BY COALESCE(u.full_name, '') ASC, u.id ASC, s.sale_date ASC, s.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$groups = [];
$total_sales = 0; $total_paid = 0; $total_due = 0; $total_profit = 0; $inv_count = 0;
foreach ($sales as $s) {
    $key = $s['ob_id'] ? (int)$s['ob_id'] : 'unknown';
    if (!isset($groups[$key])) {
        $groups[$key] = ['name' => $s['ob_name'] ?: 'Unknown', 'rows' => []];
    }
    $groups[$key]['rows'][] = $s;
    $pr = (float)$s['paid_amount'] - (float)$s['total_cost'];
    $total_sales += (float)$s['total_amount'];
    $total_paid += (float)$s['paid_amount'];
    $total_due += (float)$s['due_amount'];
    $total_profit += $pr;
    $inv_count++;
}

$period_label = 'All invoices';
$period_desc = 'All dates';
if ($from && $to) { $period_label = 'Filtered invoices'; $period_desc = formatDate($from) . ' to ' . formatDate($to); }
elseif ($from) { $period_label = 'Filtered invoices'; $period_desc = 'From ' . formatDate($from); }
elseif ($to) { $period_label = 'Filtered invoices'; $period_desc = 'Until ' . formatDate($to); }

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center d-print-none">
    <h6><i class="fas fa-user-tie"></i> Order Booker Sales Summary (<?= count($groups) ?> bookers / <?= $inv_count ?> invoices)</h6>
    <div class="d-flex flex-wrap">
      <button type="button" class="btn btn-sm btn-primary mr-2" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <a href="invoices.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-invoice"></i> Invoices</a>
    </div>
  </div>
  <div class="card-body">

    <div class="report-sheet d-none d-print-block">
      <div class="report-head">
        <div class="report-brand-line">
          <div class="report-brand d-flex align-items-center">
            <img src="<?= $base_url ?>assets/img/logo.png" alt="ARAB KHEL" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; margin-right: 10px; border: 1.5px solid #10b981; background: #fff; flex-shrink: 0;">
            <div>
              <div class="report-brand-name">ARAB KHEL</div>
              <div class="report-brand-sub">Near Itifaq Kanta Misrishah Lahore</div>
            </div>
          </div>
          <div class="report-title-box">
            <div class="report-title">Order Booker Sales Summary</div>
            <div class="report-meta"><?= $period_label ?>: <?= $period_desc ?></div>
          </div>
        </div>
      </div>

      <table class="report-summary-table">
        <tr>
          <td class="rs-cell"><span class="rs-label">Order Bookers</span><span class="rs-val"><?= count($groups) ?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Invoices</span><span class="rs-val"><?= $inv_count ?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Sales</span><span class="rs-val">PKR <?= formatCurrency($total_sales) ?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Paid</span><span class="rs-val" style="color:#0f766e;">PKR <?= formatCurrency($total_paid) ?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Due</span><span class="rs-val" style="color:#b91c1c;">PKR <?= formatCurrency($total_due) ?></span></td>
          <td class="rs-cell"><span class="rs-label">Total Profit</span><span class="rs-val" style="color:<?= $total_profit >= 0 ? '#0f766e' : '#b91c1c' ?>;">PKR <?= formatCurrency($total_profit) ?></span></td>
        </tr>
      </table>
    </div>

    <form method="get" class="row g-2 mb-3 d-print-none">
      <div class="col-md-2">
        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>" placeholder="From">
      </div>
      <div class="col-md-2">
        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>" placeholder="To">
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-filter"></i> Filter</button>
      </div>
      <div class="col-md-2">
        <?php if ($from || $to): ?>
        <a href="order_booker_summary.php" class="btn btn-outline-secondary btn-block"><i class="fas fa-times"></i> Reset</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="row mb-3 d-print-none">
      <div class="col-md-3 text-center"><strong>Total Sales:</strong> <span class="text-primary">PKR <?= formatCurrency($total_sales) ?></span></div>
      <div class="col-md-3 text-center"><strong>Total Paid:</strong> <span class="text-success">PKR <?= formatCurrency($total_paid) ?></span></div>
      <div class="col-md-3 text-center"><strong>Total Due:</strong> <span class="text-danger">PKR <?= formatCurrency($total_due) ?></span></div>
      <div class="col-md-3 text-center"><strong>Total Profit:</strong> <span class="<?= $total_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?= formatCurrency($total_profit) ?></span></div>
    </div>

    <?php if (!$sales): ?>
      <p class="text-muted text-center py-4 mb-0">No invoices found for the selected period.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-bordered report-table">
        <thead>
          <tr><th>#</th><th>Order Booker</th><th>Invoice</th><th>Date</th><th>Customer</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th><th class="text-right">Profit</th></tr>
        </thead>
        <tbody>
          <?php
          $i = 0;
          foreach ($groups as $g):
            $g_sales = 0; $g_paid = 0; $g_due = 0; $g_profit = 0;
            $n = count($g['rows']);
            foreach ($g['rows'] as $r) {
              $r['profit'] = (float)$r['paid_amount'] - (float)$r['total_cost'];
              $g_sales += (float)$r['total_amount']; $g_paid += (float)$r['paid_amount']; $g_due += (float)$r['due_amount']; $g_profit += $r['profit'];
            }
            foreach ($g['rows'] as $j => $r):
              $row_profit = (float)$r['paid_amount'] - (float)$r['total_cost'];
              $i++;
          ?>
          <tr>
            <?php if ($j === 0): ?>
            <td rowspan="<?= $n + 1 ?>" class="font-weight-bold align-middle"><?= $i ?></td>
            <td rowspan="<?= $n + 1 ?>" class="font-weight-bold align-middle"><?= htmlspecialchars($g['name']) ?></td>
            <?php endif; ?>
            <td class="font-weight-bold"><?= htmlspecialchars($r['invoice_no']) ?></td>
            <td><?= formatDate($r['sale_date']) ?></td>
            <td><?= htmlspecialchars($r['full_name'] ?? 'N/A') ?></td>
            <td class="text-right">PKR <?= formatCurrency($r['total_amount']) ?></td>
            <td class="text-right text-success">PKR <?= formatCurrency($r['paid_amount']) ?></td>
            <td class="text-right <?= $r['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>">PKR <?= formatCurrency($r['due_amount']) ?></td>
            <td class="text-right <?= $row_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?= formatCurrency($row_profit) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="report-group-total">
            <td colspan="3" class="text-right">Subtotal (<?= $n ?> invoices)</td>
            <td class="text-right">PKR <?= formatCurrency($g_sales) ?></td>
            <td class="text-right">PKR <?= formatCurrency($g_paid) ?></td>
            <td class="text-right">PKR <?= formatCurrency($g_due) ?></td>
            <td class="text-right <?= $g_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?= formatCurrency($g_profit) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="report-tfoot">
          <tr>
            <td colspan="5">TOTAL (<?= count($groups) ?> bookers / <?= $inv_count ?> invoices)</td>
            <td class="text-right">PKR <?= formatCurrency($total_sales) ?></td>
            <td class="text-right">PKR <?= formatCurrency($total_paid) ?></td>
            <td class="text-right">PKR <?= formatCurrency($total_due) ?></td>
            <td class="text-right <?= $total_profit >= 0 ? 'text-success' : 'text-danger' ?>">PKR <?= formatCurrency($total_profit) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="report-foot d-none d-print-block">
      <div><strong>Prepared by:</strong> <?= htmlspecialchars($printed_by ?: '—') ?></div>
      <div><strong>Printed on:</strong> <?= date('d-m-Y H:i') ?></div>
      <div>ARAB KHEL &middot; Near Itifaq Kanta Misrishah Lahore</div>
    </div>
    <?php endif; ?>

  </div>
</div>

<style>
@media print {
  @page { size: A4 landscape; margin: 10mm; }
  .report-table th { font-size: 13.5px !important; padding: 8px 10px !important; }
  .report-table td { font-size: 13px !important; padding: 7px 10px !important; }
  .report-table tr.report-group-total td { background: #f1f5f9 !important; font-weight: 700; }
  tfoot.report-tfoot td { font-size: 13px !important; padding: 7px 10px !important; }
  .rs-label { font-size: 12px !important; letter-spacing: 0.6px !important; }
  .rs-val   { font-size: 18px !important; }
}
</style>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>