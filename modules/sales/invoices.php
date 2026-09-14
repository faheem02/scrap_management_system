<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Invoices (Sales)';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$from        = $_GET['from'] ?? '';
$to          = $_GET['to'] ?? '';
$customer_id = (int)($_GET['customer_id'] ?? 0);
$cust_q      = trim($_GET['cust_q'] ?? '');

// If customer_id passed, resolve name
$customer_name = '';
if ($customer_id) {
    $cn = $pdo->prepare("SELECT full_name FROM customers WHERE id = ?");
    $cn->execute([$customer_id]);
    $customer_name = (string)$cn->fetchColumn();
    if (!$customer_name) $customer_id = 0;
}

$sql    = "SELECT s.*, c.full_name, c.phone
           FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE 1=1";
$params = [];
if ($from)        { $sql .= " AND s.sale_date >= ?";   $params[] = $from; }
if ($to)          { $sql .= " AND s.sale_date <= ?";   $params[] = $to; }
if ($customer_id) { $sql .= " AND s.customer_id = ?";  $params[] = $customer_id; }
$sql .= " ORDER BY s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$total_sales = 0; $total_paid = 0; $total_due = 0;
foreach ($sales as $s) {
    if ($s['status'] != 'cancelled') {
        $total_sales += (float)$s['total_amount'];
        $total_paid  += (float)$s['paid_amount'];
        $total_due   += (float)$s['due_amount'];
    }
}

$bank_accounts = $pdo->query(
    "SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id"
)->fetchAll();

$printed_by = '';
if (!empty($_SESSION['user_id'])) {
    $pu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $pu->execute([(int)$_SESSION['user_id']]);
    $printed_by = (string)$pu->fetchColumn();
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Action Toolbar (Screen Only) -->
<div class="d-print-none mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-file-invoice text-primary"></i> Sales Invoices Register</h5>
    <small class="text-muted">Total Invoices: <?= count($sales) ?> records</small>
  </div>
  <div class="d-flex flex-wrap gap-1">
    <button type="button" class="btn btn-sm btn-danger mr-1" onclick="downloadPDF('invoicesPrintArea', 'ARAB_KHEL_Sales_Invoices', 'landscape')">
      <i class="fas fa-file-pdf"></i> Download PDF
    </button>
    <button type="button" class="btn btn-sm btn-primary mr-1" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    <a href="index.php" class="btn btn-sm btn-success"><i class="fas fa-plus"></i> New Sale</a>
  </div>
</div>

<div class="card shadow" id="invoicesPrintArea">
  <div class="card-body">

    <!-- Print header -->
    <div class="d-none d-print-block mb-3 text-center">
      <img src="<?= $base_url ?>assets/img/logo.png" alt="ARAB KHEL" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; margin-bottom: 6px; border: 1.5px solid #10b981; background: #fff;">
      <h4 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h4>
      <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
      <h5 class="font-weight-bold text-primary mt-2 mb-0">SALE INVOICES</h5>
      <?php $pm = [];
        if ($from) $pm[] = 'From: ' . formatDate($from);
        if ($to)   $pm[] = 'To: '   . formatDate($to);
        if ($customer_name) $pm[] = 'Customer: ' . $customer_name;
        if ($pm): ?>
      <div class="mt-1 font-weight-bold"><?= implode(' &nbsp;|&nbsp; ', $pm) ?></div>
      <?php endif; ?>
      <small>Printed on <?= date('d-m-Y H:i') ?> by <?= htmlspecialchars($printed_by ?: '—') ?></small>
    </div>


    <!-- Filter form -->
    <form method="get" id="salesFilterForm" class="row g-2 mb-3 d-print-none align-items-center">
      <div class="col-md-3">
        <div class="input-group">
          <div class="input-group-prepend"><span class="input-group-text font-weight-bold small">From</span></div>
          <input type="date" name="from" id="salesFromDate" class="form-control" value="<?= htmlspecialchars($from) ?>" title="From Date">
        </div>
      </div>
      <div class="col-md-3">
        <div class="input-group">
          <div class="input-group-prepend"><span class="input-group-text font-weight-bold small">To</span></div>
          <input type="date" name="to" id="salesToDate" class="form-control" value="<?= htmlspecialchars($to) ?>" title="To Date">
        </div>
      </div>
      <div class="col-md-3">
        <div class="ac-wrap">
          <input type="text" id="customerFilterSearch" class="form-control"
                 placeholder="Search by customer name..." autocomplete="off"
                 value="<?= htmlspecialchars($customer_name) ?>">
          <input type="hidden" name="customer_id" id="filterCustomerId" value="<?= $customer_id ?>">
          <div class="ac-list" id="customerFilterList"></div>
        </div>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-filter"></i> Filter</button>
        <?php if ($from || $to || $customer_id): ?>
        <a href="invoices.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i> Reset</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Summary row -->
    <div class="row mb-3 d-print-none">
      <div class="col-md-4 text-center"><strong>Total Sales:</strong> <span class="text-primary">PKR <?= formatCurrency($total_sales) ?></span></div>
      <div class="col-md-4 text-center"><strong>Total Paid:</strong> <span class="text-success">PKR <?= formatCurrency($total_paid) ?></span></div>
      <div class="col-md-4 text-center"><strong>Total Due:</strong> <span class="text-danger">PKR <?= formatCurrency($total_due) ?></span></div>
    </div>

    <!-- Table -->
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead>
          <tr>
            <th>#</th>
            <th>Invoice</th>
            <th>Customer</th>
            <th>Date</th>
            <th class="text-right">Total</th>
            <th class="text-right">Paid</th>
            <th class="text-right">Due</th>
            <th class="d-print-none">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 0; foreach ($sales as $s): $i++; ?>
          <tr>
            <td><?= $i ?></td>
            <td class="font-weight-bold"><?= htmlspecialchars($s['invoice_no']) ?></td>
            <td><?= htmlspecialchars($s['full_name'] ?? 'N/A') ?></td>
            <td><?= formatDate($s['sale_date']) ?></td>
            <td class="text-right">PKR <?= formatCurrency($s['total_amount']) ?></td>
            <td class="text-right text-success">PKR <?= formatCurrency($s['paid_amount']) ?></td>
            <td class="text-right <?= $s['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>">
              PKR <?= formatCurrency($s['due_amount']) ?>
            </td>
            <td class="text-center d-print-none" nowrap>
              <?php if ($s['due_amount'] > 0): ?>
              <button type="button" class="btn btn-sm btn-success btn-receive"
                      data-id="<?= $s['id'] ?>" title="Receive Payment">
                <i class="fas fa-hand-holding-usd"></i>
              </button>
              <?php endif; ?>
              <a href="thermal_print.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-dark mr-1"
                 title="Thermal Print" target="_blank"><i class="fas fa-print"></i></a>
              <a href="sale_edit.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-warning"
                 title="Edit Sale"><i class="fas fa-edit"></i></a>
              <form method="post" action="sale_delete.php" class="d-inline"
                    onsubmit="return confirm('Delete this sale? This will reverse stock &amp; payments.');">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash-alt"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($sales)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">
            No sales found. <a href="index.php">Make your first sale</a>
          </td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr class="font-weight-bold">
            <td colspan="4">TOTAL (<?= count($sales) ?>)</td>
            <td class="text-right">PKR <?= formatCurrency($total_sales) ?></td>
            <td class="text-right">PKR <?= formatCurrency($total_paid) ?></td>
            <td class="text-right">PKR <?= formatCurrency($total_due) ?></td>
            <td class="d-print-none"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<!-- ── Receive Payment Modal ── -->
<div class="modal fade" id="receiveModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="fas fa-hand-holding-usd"></i> Receive Payment</h6>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="receiveInfo" class="alert alert-info py-2 mb-3" style="display:none;"></div>
        <form id="receiveForm">
          <input type="hidden" id="rcvSaleId">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Invoice</label>
              <input type="text" id="rcvInvoice" class="form-control bg-light" readonly>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Customer</label>
              <input type="text" id="rcvCustomer" class="form-control bg-light" readonly>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Total Amount</label>
              <input type="text" id="rcvTotal" class="form-control bg-light" readonly>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Due Amount</label>
              <input type="text" id="rcvDue" class="form-control bg-light font-weight-bold text-danger" readonly>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Receive Amount <span class="text-danger">*</span></label>
              <input type="number" id="rcvAmount" class="form-control" min="0.01" step="0.01" placeholder="0.00">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Receipt Date</label>
              <input type="date" id="rcvDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Payment Method</label>
              <select id="rcvMethod" class="form-control">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-md-6 mb-3" id="rcvBankDiv" style="display:none;">
              <label class="form-label">Bank Account</label>
              <select id="rcvBankAccount" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?= $ba['id'] ?>">
                  <?= htmlspecialchars($ba['account_name']) ?> — <?= htmlspecialchars($ba['bank_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="rcvSaveBtn">
          <i class="fas fa-save"></i> Save Payment
        </button>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){

  function esc(s){ return $('<div>').text(s||'').html(); }
  function fmtPKR(n){ return 'PKR ' + parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
  function hideList($l){ $l.empty().hide(); }

  // ── Customer filter autocomplete ──
  var filterTimer = null;
  $('#customerFilterSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(filterTimer);
    if (!q) { $('#filterCustomerId').val(''); hideList($('#customerFilterList')); return; }
    filterTimer = setTimeout(function(){
      $.get('ajax_customer_search.php', {q: q}, function(data){
        var $list = $('#customerFilterList');
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No customer found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = it.phone ? '<small class="ac-sub">Phone: ' + esc(it.phone) + '</small>' : '';
            $list.append('<div class="ac-item" data-id="' + it.id + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' + sub + '</div>');
          });
        }
        $list.show();
      });
    }, 250);
  });

  $('#customerFilterList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    $('#filterCustomerId').val($(this).data('id'));
    $('#customerFilterSearch').val($(this).find('.ac-name').text());
    hideList($('#customerFilterList'));
  });

  $(document).on('keydown', '#customerFilterSearch', function(e){
    var $list = $('#customerFilterList');
    var items = $list.find('.ac-item:not(.ac-empty)');
    if (!$list.is(':visible') || !items.length) return;
    var idx = items.index(items.filter('.active'));
    if (e.key==='ArrowDown'||e.key==='ArrowUp') {
      e.preventDefault();
      idx = (idx + (e.key==='ArrowDown'?1:-1) + items.length) % items.length;
      items.removeClass('active').eq(idx).addClass('active');
    } else if (e.key==='Enter') {
      e.preventDefault();
      (idx>=0?items.eq(idx):items.first()).trigger('mousedown');
    } else if (e.key==='Escape') { hideList($list); }
  });

  $(document).on('mouseover', '.ac-item', function(){ $(this).addClass('active').siblings().removeClass('active'); });
  $(document).on('mousedown', function(e){ if (!$(e.target).closest('.ac-wrap').length) $('.ac-list').empty().hide(); });

  // ── Receive Payment ──
  $('#rcvMethod').change(function(){
    $('#rcvBankDiv').toggle($(this).val() === 'bank');
  });

  $(document).on('click', '.btn-receive', function(){
    var sid = $(this).data('id');
    $('#receiveInfo').hide();
    $.get('ajax_receive_payment.php', {action:'load', sale_id: sid}, function(res){
      if (res.error) { alert(res.error); return; }
      var s = res.sale;
      $('#rcvSaleId').val(s.id);
      $('#rcvInvoice').val(s.invoice_no);
      $('#rcvCustomer').val(s.customer_name || '—');
      $('#rcvTotal').val(fmtPKR(s.total_amount));
      $('#rcvDue').val(fmtPKR(s.due_amount));
      $('#rcvAmount').val(parseFloat(s.due_amount).toFixed(2));
      $('#rcvDate').val('<?= date('Y-m-d') ?>');
      $('#rcvMethod').val('cash').trigger('change');
      $('#receiveModal').modal('show');
    });
  });

  $('#rcvSaveBtn').click(function(){
    var amount = parseFloat($('#rcvAmount').val()) || 0;
    if (amount <= 0) { alert('Please enter a valid amount.'); return; }
    var $btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
    $.post('ajax_receive_payment.php', {
      action:          'pay',
      sale_id:         $('#rcvSaleId').val(),
      amount:          amount,
      payment_method:  $('#rcvMethod').val(),
      bank_account_id: $('#rcvBankAccount').val(),
      receipt_date:    $('#rcvDate').val(),
    }, function(res){
      $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Payment');
      if (res.error) { alert('Error: ' + res.error); return; }
      $('#receiveModal').modal('hide');
      // Update row in table
      location.reload();
    });
  });

});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
