<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Customer Ledger';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$id = (int)($_GET['id'] ?? 0);
$customer = getById('customers', $id);
if (!$customer) { redirect('customers.php', 'Customer not found', 'error'); }

updateCustomerBalance($pdo, $id);
$customer = getById('customers', $id);

$opening = (float)($customer['opening_balance'] ?? 0);

// Filters
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

// Sales (increases receivable) - with payment_method info
$sales_st = $pdo->prepare("SELECT id, invoice_no, sale_date, total_amount, paid_amount, due_amount, payment_method FROM sales WHERE customer_id = ? AND status <> 'cancelled' ORDER BY sale_date ASC, id ASC");
$sales_st->execute([$id]);
$sales = $sales_st->fetchAll();

// Receipts from customer_receipts table (separate payment entries)
$receipts = $pdo->prepare("SELECT id, receipt_date, amount, payment_method, description FROM customer_receipts WHERE customer_id = ? ORDER BY receipt_date ASC, id ASC");
$receipts->execute([$id]);
$receipts = $receipts->fetchAll();

// Build chronological ledger rows
$all_rows = [];
$all_rows[] = ['date' => $customer['created_at'] ?? $customer['updated_at'] ?? date('Y-m-d'), 'sort' => 0, 'desc' => 'Opening Balance', 'debit' => $opening > 0 ? $opening : 0, 'credit' => $opening < 0 ? abs($opening) : 0, 'method' => '', 'type' => 'opening', 'link' => null];

foreach ($sales as $s) {
    // Full invoice amount as debit
    $all_rows[] = [
        'date'   => $s['sale_date'],
        'sort'   => 1,
        'desc'   => 'Sale Invoice #' . $s['invoice_no'],
        'debit'  => (float)$s['total_amount'],
        'credit' => 0,
        'method' => '',
        'type'   => 'sale',
        'link'   => 'invoice.php?id=' . $s['id'],
    ];
    // If paid at time of sale, record as credit on same date
    if ((float)$s['paid_amount'] > 0) {
        $method_label = $s['payment_method'] !== 'credit' ? $s['payment_method'] : '';
        $all_rows[] = [
            'date'   => $s['sale_date'],
            'sort'   => 2,
            'desc'   => 'Payment on Sale #' . $s['invoice_no'],
            'debit'  => 0,
            'credit' => (float)$s['paid_amount'],
            'method' => $method_label,
            'type'   => 'receipt',
            'link'   => null,
        ];
    }
}

foreach ($receipts as $r) {
    $all_rows[] = [
        'date'   => $r['receipt_date'],
        'sort'   => 3,
        'desc'   => $r['description'] ?: 'Payment Received',
        'debit'  => 0,
        'credit' => (float)$r['amount'],
        'method' => $r['payment_method'],
        'type'   => 'receipt',
        'link'   => null,
    ];
}
usort($all_rows, function($a, $b) {
    if ($a['date'] === $b['date']) return $a['sort'] <=> $b['sort'];
    return strcmp($a['date'], $b['date']);
});

// Running and period filtering
$period_opening = 0;
$rows = [];
if ($from) {
    foreach ($all_rows as $ar) {
        if ($ar['date'] < $from) {
            $period_opening += ($ar['debit'] - $ar['credit']);
        }
    }
    $rows[] = ['date' => $from, 'sort' => 0, 'desc' => 'Brought Forward / Opening Balance', 'debit' => $period_opening > 0 ? $period_opening : 0, 'credit' => $period_opening < 0 ? abs($period_opening) : 0, 'method' => '', 'type' => 'opening', 'link' => null, 'balance' => $period_opening];
}

$running = $from ? $period_opening : 0;
$total_debit = 0;
$total_credit = 0;

foreach ($all_rows as $ar) {
    if ($from && $ar['date'] < $from) continue;
    if ($to && $ar['date'] > $to) continue;

    if (!$from && $ar['type'] === 'opening') {
        $running = $opening;
        $ar['balance'] = $running;
        $rows[] = $ar;
        continue;
    }

    $running += ($ar['debit'] - $ar['credit']);
    $total_debit += $ar['debit'];
    $total_credit += $ar['credit'];
    $ar['balance'] = $running;
    $rows[] = $ar;
}

$current = (float)$customer['current_balance'];
$period_closing = $running;

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-print-none mb-2">
  <a href="customers.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to Customers</a>
</div>

<div class="card shadow mb-3 no-print">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h6 class="mb-0"><i class="fas fa-user"></i> <?=htmlspecialchars($customer['full_name'])?> <small class="text-muted">(<?=htmlspecialchars($customer['customer_no'])?>)</small></h6>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <form method="get" class="d-flex align-items-center bg-light border rounded py-1 px-2">
        <input type="hidden" name="id" value="<?=$id?>">
        <span class="small text-muted mr-1 font-weight-bold"><i class="fas fa-calendar-alt"></i> From</span>
        <input type="date" name="from" value="<?=htmlspecialchars($from)?>" class="form-control form-control-sm" style="max-width:130px;" title="From date">
        <span class="small text-muted mx-1 font-weight-bold">To</span>
        <input type="date" name="to" value="<?=htmlspecialchars($to)?>" class="form-control form-control-sm" style="max-width:130px;" title="To date">
        <button type="submit" class="btn btn-sm btn-primary ml-1" title="Filter ledger"><i class="fas fa-filter"></i></button>
        <?php if ($from || $to): ?>
        <a href="customer_view.php?id=<?=$id?>" class="btn btn-sm btn-outline-secondary ml-1" title="Reset date filter"><i class="fas fa-times"></i></a>
        <?php endif; ?>
      </form>
      <div class="border-right mr-1" style="height:28px;"></div>
      <button type="button" class="btn btn-sm btn-danger" onclick="downloadPDF('customerLedgerArea', 'Customer_Ledger_<?= htmlspecialchars($customer['customer_no']) ?>')">
        <i class="fas fa-file-pdf"></i> Download PDF
      </button>
      <a href="customer_edit.php?id=<?=$id?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-edit"></i> Edit</a>
      <?php if (isAdmin()): ?>
      <a href="<?=($base_url ?? '/scrap_management_system/')?>modules/transactions/receive_customer.php?customer_id=<?=$id?>" class="btn btn-sm btn-success"><i class="fas fa-hand-holding-usd"></i> Receive Payment</a>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>
  <div class="card-body py-2">
    <div class="row text-center">
      <div class="col-md-4"><div class="text-xs text-uppercase text-muted">Phone</div><div class="h6"><?=htmlspecialchars($customer['phone'] ?? '-')?></div></div>
      <?php $balClass = $current > 0 ? 'balance-negative' : ($current < 0 ? 'balance-positive' : 'balance-zero'); ?>
      <div class="col-md-4"><div class="text-xs text-uppercase text-muted">Overall Balance</div>
        <div class="h5 font-weight-bold <?=$balClass?>"><?=$current > 0 ? 'Receivable PKR '.formatCurrency($current) : ($current < 0 ? 'Advance PKR '.formatCurrency(abs($current)) : 'PKR 0.00')?></div>
      </div>
      <?php if ($from || $to): ?>
      <div class="col-md-4 border-left"><div class="text-xs text-uppercase text-muted">Period Closing Balance</div>
        <div class="h5 font-weight-bold <?= $period_closing > 0 ? 'text-danger' : ($period_closing < 0 ? 'text-success' : 'text-dark') ?>">PKR <?= formatCurrency($period_closing) ?></div>
      </div>
      <?php else: ?>
      <div class="col-md-4"><div class="text-xs text-uppercase text-muted">Account Status</div>
        <div class="h6 font-weight-bold text-success">Active</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div id="customerLedgerArea">
  <!-- Printable ledger header -->
  <div class="d-none d-print-block mb-3 text-center">
    <img src="<?= $base_url ?>assets/img/logo.png" alt="ARAB KHEL" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; margin-bottom: 6px; border: 1.5px solid #10b981; background: #fff;">
    <h4 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h4>
    <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
    <h5 class="font-weight-bold text-primary mt-2 mb-0">CUSTOMER ACCOUNT STATEMENT / LEDGER</h5>
    <div class="font-weight-bold"><?=htmlspecialchars($customer['full_name'])?> (<?=htmlspecialchars($customer['customer_no'])?>)</div>
    <small><?=htmlspecialchars($customer['phone'] ?? '')?></small>
    <div class="mt-1 font-weight-bold">
      <?= ($from || $to) ? ('Statement Period: ' . ($from ? formatDate($from) : 'Start') . ' to ' . ($to ? formatDate($to) : 'Present')) : ('As of: ' . formatDate(date('Y-m-d'))) ?>
    </div>
  </div>

  <div class="card shadow">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h6 class="mb-0"><i class="fas fa-book"></i> Ledger Transactions (<?= count($rows) ?> records)</h6>
      <span class="badge badge-primary">Current Balance: <?=$current > 0 ? 'Receivable' : ($current < 0 ? 'Advance' : 'Settled')?></span>
    </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="ledgerTable">
        <thead class="thead-light">
          <tr><th>Date</th><th>Description</th><th class="text-right">Debit (PKR)</th><th class="text-right">Credit (PKR)</th><th class="text-right">Balance (PKR)</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr class="<?=$r['type']==='opening' ? 'table-secondary font-weight-bold' : ''?>" data-date="<?=htmlspecialchars($r['date'])?>">
            <td><?=formatDate($r['date'])?></td>
            <td>
              <?=htmlspecialchars($r['desc'])?>
              <?php if ($r['method']): ?><span class="badge badge-secondary"><?=ucfirst($r['method'])?></span><?php endif; ?>
            </td>
            <td class="text-right"><?=$r['debit'] > 0 ? 'PKR '.formatCurrency($r['debit']) : '-'?></td>
            <td class="text-right"><?=$r['credit'] > 0 ? 'PKR '.formatCurrency($r['credit']) : '-'?></td>
            <td class="text-right <?= $r['balance'] > 0 ? 'balance-negative font-weight-bold' : ($r['balance'] < 0 ? 'balance-positive' : '')?>">PKR <?=formatCurrency($r['balance'])?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php if ($from || $to): ?>
          <tr class="table-info font-weight-bold">
            <td colspan="2" class="text-right">Period Totals</td>
            <td class="text-right">PKR <?= formatCurrency($total_debit) ?></td>
            <td class="text-right">PKR <?= formatCurrency($total_credit) ?></td>
            <td class="text-right <?= $period_closing > 0 ? 'balance-negative' : ($period_closing < 0 ? 'balance-positive' : '') ?>">
              PKR <?= formatCurrency($period_closing) ?>
            </td>
          </tr>
          <?php endif; ?>
          <tr class="table-active font-weight-bold">
            <td colspan="4" class="text-right">Closing / Overall Balance</td>
            <td class="text-right <?=$current > 0 ? 'balance-negative' : ($current < 0 ? 'balance-positive' : '')?>">PKR <?=formatCurrency($current)?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="mt-3 pt-2 border-top d-print-none">
      <div class="text-xs text-uppercase text-muted">Total Balance To Collect</div>
      <div class="h5 mb-0 <?=$current > 0 ? 'balance-negative' : ($current < 0 ? 'balance-positive' : '')?>">
        <?=$current > 0 ? 'PKR '.formatCurrency($current).' Receivable' : ($current < 0 ? 'PKR '.formatCurrency(abs($current)).' Advance' : 'PKR 0.00')?>
      </div>
    </div>
  </div>
</div>
</div>

<script>
function applyLedgerDateFilter() {
  var from = document.getElementById('ledgerFrom').value;
  var to = document.getElementById('ledgerTo').value;
  var clearBtn = document.getElementById('ledgerClearBtn');
  clearBtn.style.display = (from || to) ? '' : 'none';
  document.querySelectorAll('#ledgerTable tbody tr').forEach(function(tr) {
    var d = tr.getAttribute('data-date') || '';
    var show = true;
    if (from && d < from) show = false;
    if (to && d > to) show = false;
    tr.style.display = show ? '' : 'none';
  });
}
function clearLedgerFilter() {
  document.getElementById('ledgerFrom').value = '';
  document.getElementById('ledgerTo').value = '';
  applyLedgerDateFilter();
}
document.getElementById('ledgerFrom').addEventListener('change', applyLedgerDateFilter);
document.getElementById('ledgerTo').addEventListener('change', applyLedgerDateFilter);
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>