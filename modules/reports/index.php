<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Daily Summary Report';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$report_date = $_GET['report_date'] ?? date('Y-m-d');

// ── Sales for the day ──
$sales_st = $pdo->prepare("
    SELECT s.id, s.invoice_no, s.sale_date,
           s.total_amount, s.paid_amount, s.due_amount, s.payment_method,
           c.full_name AS customer_name, s.customer_id,
           COALESCE(SUM(si.quantity), 0) AS total_weight,
           COALESCE(SUM(si.subtotal) / NULLIF(SUM(si.quantity), 0), 0) AS avg_rate
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN sale_items si ON si.sale_id = s.id
    WHERE s.sale_date = ? AND s.status <> 'cancelled'
    GROUP BY s.id
    ORDER BY s.id ASC
");
$sales_st->execute([$report_date]);
$sales = $sales_st->fetchAll();

// ── Customer receipts on this day (cash vs bank) ──
$receipts_st = $pdo->prepare("
    SELECT customer_id, payment_method, amount
    FROM customer_receipts
    WHERE receipt_date = ? AND is_split = 0
");
$receipts_st->execute([$report_date]);
$receipts_rows = $receipts_st->fetchAll();
$receipts_cash = 0;
$receipts_bank = 0;
foreach ($receipts_rows as $r) {
    $amt = (float)$r['amount'];
    if (($r['payment_method'] ?? 'cash') === 'bank') {
        $receipts_bank += $amt;
    } else {
        $receipts_cash += $amt;
    }
}

// ── Expenses for day ──
$exp_st = $pdo->prepare("
    SELECT e.amount, e.payment_method, ec.name AS cat_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date = ?
");
$exp_st->execute([$report_date]);
$freight_total = 0;
$expense_total = 0;
$expense_cash  = 0;
$expense_bank  = 0;
foreach ($exp_st->fetchAll() as $ex) {
    $amt = (float)$ex['amount'];
    $expense_total += $amt;
    if (($ex['payment_method'] ?? 'cash') === 'bank') {
        $expense_bank += $amt;
    } else {
        $expense_cash += $amt;
    }
    $cat = strtolower($ex['cat_name'] ?? '');
    if (strpos($cat, 'transport') !== false || strpos($cat, 'freight') !== false) {
        $freight_total += $amt;
    }
}
$other_expense_total = $expense_total - $freight_total;

// ── Freight for the day (Vehicle Freight from Purchases + Transport Expenses) ──
$pur_freight_st = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date = ? AND status <> 'cancelled'");
$pur_freight_st->execute([$report_date]);
$purchase_freight = (float)$pur_freight_st->fetchColumn();
$total_freight = $freight_total + $purchase_freight;

// ── Sales Cash vs Bank breakdown ──
$sales_cash = 0;
$sales_bank = 0;
foreach ($sales as $s) {
    $p = (float)$s['paid_amount'];
    if (($s['payment_method'] ?? 'cash') === 'bank') {
        $sales_bank += $p;
    } else {
        $sales_cash += $p;
    }
}

// ── Total Received Breakdown (Cash vs Bank) ──
$cash_received  = $sales_cash + $receipts_cash;
$bank_received  = $sales_bank + $receipts_bank;
$total_received = $cash_received + $bank_received;

// ── Summaries ──
$total_weight   = array_sum(array_column($sales, 'total_weight'));
$total_amount   = array_sum(array_column($sales, 'total_amount'));
$total_due      = array_sum(array_column($sales, 'due_amount'));

// ── Total Stock at Yard (Remaining Stock) ──
$total_stock_yard = (float)$pdo->query("SELECT COALESCE(SUM(stock_quantity), 0) FROM products")->fetchColumn();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Toolbar -->
<div class="d-print-none mb-3 d-flex flex-wrap justify-content-between align-items-center">
  <div>
    <h5 class="mb-0 font-weight-bold text-dark">
      <i class="fas fa-chart-bar text-primary"></i> Daily Summary Report
    </h5>
    <small class="text-muted">Date: <strong><?= formatDate($report_date) ?></strong></small>
  </div>
  <div class="d-flex" style="gap:8px;">
    <button type="button" class="btn btn-sm btn-danger"
            onclick="downloadPDF('dailyReportArea', 'Daily_Report_<?= $report_date ?>')">
      <i class="fas fa-file-pdf"></i> Download PDF
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
  </div>
</div>

<!-- Date Filter -->
<div class="card shadow mb-3 d-print-none">
  <div class="card-body py-2">
    <form method="get" class="form-inline">
      <label class="mr-2 font-weight-bold small text-muted">Report Date:</label>
      <input type="date" name="report_date" class="form-control form-control-sm mr-2"
             value="<?= htmlspecialchars($report_date) ?>">
      <button type="submit" class="btn btn-sm btn-primary mr-2">
        <i class="fas fa-filter"></i> Generate
      </button>
      <?php if ($report_date !== date('Y-m-d')): ?>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-calendar-day"></i> Today
      </a>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- ===== PRINTABLE AREA ===== -->
<div id="dailyReportArea">

<!-- Printable Header -->
<div class="d-none d-print-block mb-3 text-center">
  <img src="<?= $base_url ?>assets/img/logo.png" alt="ARAB KHEL"
       style="width:58px;height:58px;border-radius:50%;object-fit:cover;margin-bottom:6px;border:2px solid #10b981;background:#fff;">
  <h3 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h3>
  <div style="font-size:13px;color:#475569;font-weight:600;">Near Itifaq Kanta Misrishah Lahore</div>
  <h4 class="font-weight-bold text-primary mt-2 mb-1">DAILY SUMMARY REPORT</h4>
  <div style="font-size:14px;font-weight:700;color:#0f172a;">Date: <?= formatDate($report_date) ?></div>
  <div style="font-size:12px;color:#475569;font-weight:600;margin-top:2px;">
    Printed on <?= date('d-m-Y H:i') ?> &nbsp;|&nbsp; By: <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>
  </div>
</div>

<!-- ===== SALES TABLE ===== -->
<div class="card shadow mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="mb-0 font-weight-bold">
      <i class="fas fa-shopping-cart text-primary mr-1"></i> Sales — <?= formatDate($report_date) ?>
    </h6>
    <span class="badge badge-primary d-print-none"><?= count($sales) ?> invoice(s)</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0">
        <thead class="thead-light">
          <tr>
            <th style="width:40px;">#</th>
            <th>Bill No</th>
            <th>Customer Name</th>
            <th class="text-right">Weight (kg)</th>
            <th class="text-right">Rate (PKR)</th>
            <th class="text-right">Total (PKR)</th>
            <th class="text-right">Received (PKR)</th>
            <th class="text-right">Balance (PKR)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($sales)): ?>
            <?php $i = 0; foreach ($sales as $s): $i++;
              $row_received = (float)$s['paid_amount'];
            ?>
            <tr>
              <td><?= $i ?></td>
              <td class="font-weight-bold"><?= htmlspecialchars($s['invoice_no']) ?></td>
              <td><?= htmlspecialchars($s['customer_name'] ?? '—') ?></td>
              <td class="text-right"><?= number_format((float)$s['total_weight'], 2) ?></td>
              <td class="text-right"><?= formatCurrency($s['avg_rate']) ?></td>
              <td class="text-right font-weight-bold">PKR <?= formatCurrency($s['total_amount']) ?></td>
              <td class="text-right font-weight-bold text-success">PKR <?= formatCurrency($row_received) ?></td>
              <td class="text-right <?= (float)$s['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>">
                PKR <?= formatCurrency($s['due_amount']) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-4">
                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                No sales recorded for <?= formatDate($report_date) ?>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
        <?php if (count($sales)): ?>
        <tfoot class="font-weight-bold table-active">
          <tr>
            <td colspan="3" class="text-right">TOTALS (<?= count($sales) ?> invoices)</td>
            <td class="text-right"><?= number_format($total_weight, 2) ?> kg</td>
            <td class="text-right">—</td>
            <td class="text-right">PKR <?= formatCurrency($total_amount) ?></td>
            <td class="text-right text-success">PKR <?= formatCurrency($total_received) ?></td>
            <td class="text-right <?= $total_due > 0 ? 'text-danger' : 'text-success' ?>">
              PKR <?= formatCurrency($total_due) ?>
            </td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- ===== DAY SUMMARY BOX ===== -->
<div class="card shadow mb-3">
  <div class="card-header bg-light">
    <h6 class="mb-0 font-weight-bold text-dark">
      <i class="fas fa-calculator text-success mr-1"></i> Day Summary — <?= formatDate($report_date) ?>
    </h6>
  </div>
  <div class="card-body p-0">
    <table class="table table-bordered mb-0">
      <tbody>
        <tr>
          <th style="width:50%;" class="pl-4">Total Weight Sold</th>
          <td class="text-right font-weight-bold text-success pr-4" style="font-size:15px;">
            <?= number_format($total_weight, 2) ?> kg
          </td>
        </tr>
        <tr>
          <th class="pl-4">Total Stock at Yard <small class="text-muted">(Remaining Stock)</small></th>
          <td class="text-right font-weight-bold text-primary pr-4" style="font-size:15px;">
            <?= number_format($total_stock_yard, 2) ?> kg
          </td>
        </tr>
        <tr>
          <th class="pl-4">Total Amount Received</th>
          <td class="text-right font-weight-bold text-info pr-4" style="font-size:15px;">
            PKR <?= formatCurrency($total_received) ?>
          </td>
        </tr>
        <tr>
          <th class="pl-4">Total Expense</th>
          <td class="text-right font-weight-bold text-danger pr-4" style="font-size:15px;">
            PKR <?= formatCurrency($expense_total) ?>
          </td>
        </tr>
        <tr>
          <th class="pl-4">
            Total Freight
            <?php if ($purchase_freight > 0 && $freight_total > 0): ?>
              <div class="small font-weight-normal text-muted">Vehicle Freight: PKR <?= formatCurrency($purchase_freight) ?> &middot; Transport: PKR <?= formatCurrency($freight_total) ?></div>
            <?php elseif ($purchase_freight > 0): ?>
              <div class="small font-weight-normal text-muted">Vehicle Freight (Purchases)</div>
            <?php elseif ($freight_total > 0): ?>
              <div class="small font-weight-normal text-muted">Transport / Freight Expense</div>
            <?php endif; ?>
          </th>
          <td class="text-right font-weight-bold pr-4" style="font-size:15px; color:#b45309;">
            PKR <?= formatCurrency($total_freight) ?>
          </td>
        </tr>
        <tr class="table-active" style="background:#f8fafc;">
          <th class="pl-4 font-weight-bold" style="font-size:15px;">
            Total Balance
            <div class="small font-weight-normal text-muted mt-1">
              Cash vs Bank Received
            </div>
          </th>
          <td class="text-right pr-4 font-weight-bold text-dark" style="font-size:15px;">
            <div>PKR <?= formatCurrency($total_received) ?></div>
            <div class="small font-weight-bold mt-1">
              <span class="text-success"><i class="fas fa-money-bill-wave"></i> Cash: PKR <?= formatCurrency($cash_received) ?></span>
              &nbsp;&middot;&nbsp;
              <span class="text-primary"><i class="fas fa-university"></i> Bank: PKR <?= formatCurrency($bank_received) ?></span>
            </div>
          </td>
        </tr>
        <tr style="background:#ecfdf5;">
          <th class="pl-4 font-weight-bold" style="font-size:15px;">Net Day Amount (Received − Expenses − Freight)</th>
          <td class="text-right pr-4 font-weight-bold <?= ($total_received - $expense_total - $purchase_freight) >= 0 ? 'text-success' : 'text-danger' ?>" style="font-size:16px;">
            <?php $net = $total_received - $expense_total - $purchase_freight; ?>
            PKR <?= formatCurrency($net) ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== SIGNATURE BLOCK (Print & Download PDF Only) ===== -->
<div class="report-signatures d-none d-print-block" style="margin-top: 55px; padding-top: 20px; page-break-inside: avoid;">
  <div style="display: flex; justify-content: space-between; text-align: center; width: 100%;">
    <div style="width: 28%;">
      <div style="border-top: 1.5px solid #000; padding-top: 6px; font-weight: 800; font-size: 13.5px; color: #000;">
        Account Officer
      </div>
      <div style="font-size: 11px; color: #555; margin-top: 2px;">Signature &amp; Date</div>
    </div>
    <div style="width: 28%;">
      <div style="border-top: 1.5px solid #000; padding-top: 6px; font-weight: 800; font-size: 13.5px; color: #000;">
        Manager
      </div>
      <div style="font-size: 11px; color: #555; margin-top: 2px;">Signature &amp; Date</div>
    </div>
    <div style="width: 28%;">
      <div style="border-top: 1.5px solid #000; padding-top: 6px; font-weight: 800; font-size: 13.5px; color: #000;">
        Owner
      </div>
      <div style="font-size: 11px; color: #555; margin-top: 2px;">Signature &amp; Date</div>
    </div>
  </div>
</div>

</div><!-- /dailyReportArea -->

<style>
@media print {
  #dailyReportArea { font-size: 14px !important; color: #000 !important; }
  #dailyReportArea table th { font-size: 14px !important; font-weight: 800 !important; color: #000 !important; border: 1.5px solid #334155 !important; padding: 7px 10px !important; }
  #dailyReportArea table td { font-size: 13.5px !important; font-weight: 600 !important; color: #000 !important; border: 1px solid #475569 !important; padding: 6px 10px !important; }
  #dailyReportArea table tfoot td, #dailyReportArea table tfoot th { font-size: 14.5px !important; font-weight: 800 !important; }
  .card { border: 1px solid #e2e8f0 !important; box-shadow: none !important; margin-bottom: 12px !important; }
  .card-header { padding: 6px 10px !important; }
  .report-signatures { display: block !important; margin-top: 45px !important; }
}
</style>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
