<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'New Sale';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id     = (int)($_POST['customer_id'] ?? 0) ?: null;
    $customer_name   = trim($_POST['customer_name'] ?? '');
    $customer_phone  = trim($_POST['customer_phone'] ?? '');
    $sale_date       = !empty($_POST['sale_date']) ? $_POST['sale_date'] : date('Y-m-d');
    $payment_method  = !empty($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
    
    $bank_account_id = null;
    if ($payment_method === 'bank' && !empty($_POST['bank_account_id'])) {
        $ba_id = (int)$_POST['bank_account_id'];
        $chkBa = $pdo->prepare("SELECT id FROM bank_accounts WHERE id = ?");
        $chkBa->execute([$ba_id]);
        if ($chkBa->fetchColumn()) {
            $bank_account_id = $ba_id;
        }
    }
    $notes           = trim($_POST['notes'] ?? '');

    $product_ids   = (array)($_POST['product_id'] ?? []);
    $product_names = (array)($_POST['product_name'] ?? []);
    $quantities    = (array)($_POST['quantity'] ?? []);   // weight stored as quantity
    $rates         = (array)($_POST['rate'] ?? []);

    if (!$customer_name) {
        redirect('index.php', 'Customer name is required', 'error');
    }

    $total      = 0;
    $total_qty  = 0;
    $items      = [];
    $user_id    = $_SESSION['user_id'] ?? 1;

    foreach ($product_ids as $i => $pid) {
        $pid   = (int)$pid;
        $pname = trim($product_names[$i] ?? '');
        $qty   = (float)($quantities[$i] ?? 0);
        $rate  = (float)($rates[$i] ?? 0);

        // Fallback: if product_id is not set but name was entered
        if (!$pid && $pname !== '') {
            $fnd = $pdo->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?) LIMIT 1");
            $fnd->execute([$pname, $pname]);
            $pid = (int)$fnd->fetchColumn();

            // Auto-create product if it doesn't exist yet
            if (!$pid) {
                $pcode = 'P-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $pname), 0, 4)) . rand(100, 999);
                $pid = insert('products', [
                    'code' => $pcode,
                    'name' => $pname,
                    'unit' => 'kg',
                    'purchase_price' => 0,
                    'sale_price' => $rate,
                    'stock_quantity' => 0,
                    'status' => 1,
                    'created_at' => date('Y-m-d')
                ]);
            }
        }

        if (!$pid || $qty <= 0) continue;
        $subtotal   = $qty * $rate;
        $total     += $subtotal;
        $total_qty += $qty;
        $items[]    = ['product_id' => $pid, 'qty' => $qty, 'rate' => $rate, 'subtotal' => $subtotal];
    }

    if (!count($items)) {
        redirect('index.php', 'Please add at least one material with weight and rate.', 'error');
    }

    $discount  = (float)($_POST['discount_amount'] ?? 0);
    if ($discount > $total) $discount = $total;
    $net_total = $total - $discount;

    $paid_amount = (float)($_POST['paid_amount'] ?? 0);
    if ($paid_amount > $net_total) $paid_amount = $net_total;
    $due_amount = $net_total - $paid_amount;

    $invoice_no = trim($_POST['invoice_no'] ?? '') ?: generateSaleNo();
    $chk = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE invoice_no = ?");
    $chk->execute([$invoice_no]);
    if ($chk->fetchColumn() > 0) {
        $invoice_no = generateSaleNo();
    }

    // Ensure branch_id is valid for FK constraint
    $branch_id = null;
    if (!empty($_SESSION['branch_id'])) {
        $chkB = $pdo->prepare("SELECT id FROM branches WHERE id = ?");
        $chkB->execute([(int)$_SESSION['branch_id']]);
        if ($chkB->fetchColumn()) {
            $branch_id = (int)$_SESSION['branch_id'];
        }
    }
    if (!$branch_id) {
        $firstBranch = $pdo->query("SELECT id FROM branches LIMIT 1")->fetchColumn();
        if ($firstBranch) $branch_id = (int)$firstBranch;
    }

    // Ensure customer_id exists if provided
    if ($customer_id) {
        $chkCust = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
        $chkCust->execute([$customer_id]);
        if (!$chkCust->fetchColumn()) {
            $customer_id = null;
        }
    }

    $pdo->beginTransaction();
    try {
        // Auto-create customer if not selected from existing
        if (!$customer_id) {
            if (!$customer_phone) $customer_phone = '—';
            $customer_no = generateCustomerNo();
            $customer_id = insert('customers', [
                'customer_no' => $customer_no,
                'full_name'   => $customer_name,
                'phone'       => $customer_phone,
                'branch_id'   => $branch_id,
                'opening_balance' => 0,
                'current_balance' => 0,
                'created_by'  => $user_id,
                'created_at'  => date('Y-m-d'),
            ]);
        }
        $sale_id = insert('sales', [
            'invoice_no'      => $invoice_no,
            'customer_id'     => $customer_id,
            'salesman_id'     => null,
            'sale_date'       => $sale_date,
            'total_amount'    => $net_total,
            'discount_amount' => $discount,
            'paid_amount'     => $paid_amount,
            'due_amount'      => $due_amount,
            'payment_method'  => $payment_method,
            'bank_account_id' => $bank_account_id,
            'status'          => $due_amount > 0 ? 'active' : 'completed',
            'notes'           => $notes,
            'branch_id'       => $branch_id,
            'created_by'      => $user_id,
            'created_at'      => date('Y-m-d'),
        ]);

        foreach ($items as $it) {
            insert('sale_items', [
                'sale_id'    => $sale_id,
                'product_id' => $it['product_id'],
                'quantity'   => $it['qty'],
                'price'      => $it['rate'],
                'subtotal'   => $it['subtotal'],
            ]);
            // Reduce stock
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                ->execute([$it['qty'], $it['product_id']]);
        }

        if ($paid_amount > 0) {
            $desc = "Sale #{$invoice_no}";
            if ($payment_method == 'bank' && $bank_account_id) {
                recordBankInflow($pdo, $sale_date, $paid_amount, $desc, 'sale', $sale_id, $user_id, $bank_account_id);
            } else {
                recordCashInflow($pdo, $sale_date, $paid_amount, $desc, 'sale', $sale_id, $user_id);
            }
        }

        updateCustomerBalance($pdo, $customer_id);

        $pdo->commit();
        logActivity($pdo, 'create', 'sale', $sale_id, 'Created sale ' . $invoice_no . ' total ' . $net_total . ' (weight ' . round($total_qty, 2) . ')');
        redirect('thermal_print.php?id=' . $sale_id, 'Sale saved: ' . $invoice_no . ', Stock updated.');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error saving sale: ' . $e->getMessage(), 'error');
    }
}

$auto_invoice_no = generateSaleNo();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="fas fa-shopping-cart"></i> New Sale</h6>
    <a href="invoices.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-invoice"></i> Invoices</a>
  </div>
  <div class="card-body">
    <form method="post" id="saleForm" autocomplete="off" novalidate>

      <!-- ── Section 1: Party / Sale Info ── -->
      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Invoice No <span class="badge badge-info">Auto</span></label>
          <input type="text" name="invoice_no" class="form-control bg-light font-weight-bold text-primary" value="<?= htmlspecialchars($auto_invoice_no) ?>" readonly>
        </div>

        <div class="col-md-3 mb-3">
          <label class="form-label">Party Name <span class="text-danger">*</span></label>
          <div class="ac-wrap" id="customerWrap">
            <input type="text" id="customerSearch" name="customer_name" class="form-control"
                   placeholder="Type name or search existing..." autocomplete="off" required>
            <input type="hidden" name="customer_id" id="customer_id">
            <div class="ac-list" id="customerList"></div>
          </div>
          <small class="text-muted d-block mt-1" id="customerBalance"></small>
        </div>

        <div class="col-md-3 mb-3">
          <label class="form-label">Phone</label>
          <input type="text" id="customerPhone" name="customer_phone" class="form-control"
                 placeholder="e.g. 03001234567">
          <small class="text-muted">Auto-fill hoga ya manually enter karo.</small>
        </div>

        <div class="col-md-3 mb-3">
          <label class="form-label">Sale Date <span class="text-danger">*</span></label>
          <input type="date" name="sale_date" class="form-control datepicker" value="<?= date('Y-m-d') ?>" required>
        </div>
      </div>

      <hr>

      <!-- ── Section 2: Materials ── -->
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
        <h6 class="mb-0 text-secondary"><i class="fas fa-boxes"></i> Materials</h6>
        <small class="text-muted">Weight × Rate = Subtotal</small>
      </div>

      <div id="productRows">
        <div class="product-row">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Material <span class="text-danger">*</span></label>
              <div class="ac-wrap">
                <input type="text" class="form-control product-search" name="product_name[]"
                       placeholder="Type material name or code..." autocomplete="off">
                <input type="hidden" name="product_id[]" class="product-id">
                <div class="ac-list"></div>
              </div>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Unit</label>
              <input type="text" class="form-control unit bg-light" readonly placeholder="-">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Weight</label>
              <input type="number" name="quantity[]" class="form-control qty" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Rate</label>
              <input type="number" name="rate[]" class="form-control rate" step="0.01" min="0" placeholder="0.00">
            </div>
            <div class="col-6 col-md-1">
              <label class="form-label">Subtotal</label>
              <input type="text" class="form-control subtotal bg-light" readonly value="0.00">
            </div>
            <div class="col-6 col-md-1 d-flex align-items-end">
              <button type="button" class="btn btn-outline-danger remove-row w-100" title="Remove">
                <i class="fas fa-times"></i>
              </button>
            </div>
          </div>
        </div>
      </div>

      <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addRow">
        <i class="fas fa-plus"></i> Add Another Material
      </button>

      <div class="alert alert-success py-2 mb-3">
        <i class="fas fa-balance-scale"></i>
        <strong>Total Weight:</strong> <span id="totalQty">0.00</span>
        &nbsp;|&nbsp;
        <i class="fas fa-coins"></i>
        <strong>Total Amount:</strong> PKR <span id="totalAmountBadge">0.00</span>
      </div>

      <hr>

      <!-- ── Section 3: Amount & Payment ── -->
      <div class="d-flex align-items-center mb-2">
        <h6 class="mb-0 text-secondary"><i class="fas fa-money-bill-wave"></i> Amount &amp; Payment</h6>
      </div>

      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Total Amount</label>
          <input type="text" class="form-control font-weight-bold" id="totalAmountInput" value="0.00" readonly>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Paid Amount</label>
          <input type="number" name="paid_amount" id="paidAmount" class="form-control" min="0" step="0.01" placeholder="0.00">
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Discount <small class="text-muted">(optional)</small></label>
          <input type="number" name="discount_amount" id="discountAmount" class="form-control" min="0" step="0.01" placeholder="0.00">
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Due Amount</label>
          <input type="text" class="form-control font-weight-bold text-danger" id="dueAmount" value="0.00" readonly>
        </div>
      </div>

      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" id="payMethod" class="form-control">
            <option value="cash" selected>Cash</option>
            <option value="bank">Bank</option>
            <option value="credit">Credit</option>
          </select>
        </div>
        <div class="col-md-4 mb-3" id="bankDiv" style="display:none;">
          <label class="form-label">Bank Account</label>
          <select name="bank_account_id" class="form-control">
            <?php foreach ($bank_accounts as $ba): ?>
            <option value="<?= $ba['id'] ?>">
              <?= htmlspecialchars($ba['account_name']) ?> — <?= htmlspecialchars($ba['bank_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row mt-2">
        <div class="col-md-8">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Optional notes">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button type="submit" class="btn btn-success btn-block py-2">
            <i class="fas fa-save"></i> Save Sale
          </button>
        </div>
      </div>

    </form>
  </div>
</div>

<script>
$(document).ready(function(){

  function fmt(n){ return parseFloat(n || 0).toFixed(2); }

  // ── Recalculate totals ──
  function recalc(){
    var total = 0, weight = 0;
    $('#productRows .product-row').each(function(){
      var q   = parseFloat($(this).find('.qty').val()) || 0;
      var r   = parseFloat($(this).find('.rate').val()) || 0;
      var sub = q * r;
      $(this).find('.subtotal').val(sub.toFixed(2));
      total  += sub;
      weight += q;
    });
    $('#totalAmountBadge').text(fmt(total));
    $('#totalAmountInput').val(fmt(total));
    $('#totalQty').text(fmt(weight));

    var disc = parseFloat($('#discountAmount').val()) || 0;
    if (disc > total) { disc = total; $('#discountAmount').val(fmt(total)); }
    var net  = Math.max(total - disc, 0);

    var method = $('#payMethod').val();
    var paid;
    if (method === 'credit') {
      paid = 0;
      $('#paidAmount').val('0.00').prop('readonly', true);
    } else {
      $('#paidAmount').prop('readonly', false);
      paid = parseFloat($('#paidAmount').val()) || 0;
      if (paid > net) { paid = net; $('#paidAmount').val(fmt(net)); }
    }
    $('#dueAmount').val(Math.max(net - paid, 0).toFixed(2));
  }

  $('#productRows').on('input', '.qty, .rate', recalc);
  $('#discountAmount, #paidAmount').on('input', recalc);

  // ── Add / remove row ──
  $('#addRow').click(function(){
    var $first = $('#productRows .product-row').first().clone();
    $first.find('.product-search').val('');
    $first.find('.product-id').val('');
    $first.find('.unit').val('');
    $first.find('.ac-list').empty().hide();
    $first.find('.qty, .rate').val('');
    $first.find('.subtotal').val('0.00');
    $('#productRows').append($first);
    recalc();
  });

  $('#productRows').on('click', '.remove-row', function(){
    if ($('#productRows .product-row').length > 1) {
      $(this).closest('.product-row').remove();
      recalc();
    } else {
      alert('At least one material row is required.');
    }
  });

  // ── Payment method toggle ──
  $('#payMethod').change(function(){
    var v = $(this).val();
    $('#bankDiv').toggle(v === 'bank');
    recalc();
  });

  // ── Helpers ──
  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function hideList($list){ $list.empty().hide(); }

  // ── Customer search ──
  var custTimer = null;
  $('#customerSearch').on('input', function(){
    var q = $.trim(this.value);
    clearTimeout(custTimer);

    // Clear existing selection whenever user types again
    $('#customer_id').val('');
    $('#customerBalance').text('');

    if (!q) {
      $('#customerPhone').val('');
      hideList($('#customerList'));
      return;
    }

    custTimer = setTimeout(function(){
      $.get('ajax_customer_search.php', {q: q}, function(data){
        var $list = $('#customerList');
        $list.empty();
        if (!data || !data.length) {
          // No match — show a "create new" prompt in dropdown
          $list.append(
            '<div class="ac-item ac-empty" style="color:#555;">' +
            '<i class="fas fa-user-plus text-success mr-1"></i>' +
            'Naya customer "<strong>' + esc(q) + '</strong>" save pe add ho jaye ga.' +
            '</div>'
          );
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.phone) sub.push('Phone: ' + esc(it.phone));
            if (it.city)  sub.push(esc(it.city));
            $list.append(
              '<div class="ac-item" data-id="' + it.id + '" data-phone="' + esc(it.phone || '') + '">' +
              '<span class="ac-name">' + esc(it.full_name) + '</span>' +
              (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
              '</div>'
            );
          });
        }
        $list.show();
      });
    }, 300);
  });

  function pickCustomer($item){
    $('#customer_id').val($item.data('id'));
    $('#customerSearch').val($item.find('.ac-name').text());
    $('#customerPhone').val($item.data('phone') || '');
    hideList($('#customerList'));
    $.get('ajax_customer_balance.php', {id: $item.data('id')}, function(data){
      $('#customerBalance').text(data);
    });
  }

  $('#customerList').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;  // naya customer — dismiss dropdown
    pickCustomer($(this));
  });

  // ── Product search ──
  $('#productRows').on('input', '.product-search', function(){
    var $row  = $(this).closest('.product-row');
    var $list = $row.find('.ac-list');
    var q     = $.trim(this.value);
    clearTimeout($(this).data('timer'));
    if (!q) {
      $row.find('.product-id').val('');
      $row.find('.unit').val('');
      hideList($list);
      return;
    }
    $(this).data('timer', setTimeout(function(){
      $.get('ajax_product_search.php', {q: q}, function(data){
        $list.empty();
        if (!data || !data.length) {
          $list.append('<div class="ac-item ac-empty">No matching product found</div>');
        } else {
          $.each(data, function(i, it){
            var sub = [];
            if (it.code) sub.push('Code: ' + esc(it.code));
            if (it.unit) sub.push('Unit: ' + esc(it.unit));
            $list.append(
              '<div class="ac-item" data-id="' + it.id + '" data-sale="' + it.sale_price + '" data-unit="' + esc(it.unit) + '">' +
              '<span class="ac-name">' + esc(it.name) + '</span>' +
              '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' +
              '<small class="ac-sub"><i class="fas fa-boxes"></i> In stock: ' + it.stock_quantity + '</small>' +
              '</div>'
            );
          });
        }
        $list.show();
      });
    }, 250));
  });

  function pickProduct($item){
    var $row = $item.closest('.product-row');
    $row.find('.product-id').val($item.data('id'));
    $row.find('.product-search').val($item.find('.ac-name').text());
    $row.find('.unit').val($item.data('unit') || '');
    $row.find('.rate').val(fmt($item.data('sale')));
    hideList($row.find('.ac-list'));
    recalc();
  }

  $('#productRows').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickProduct($(this));
  });

  // ── Keyboard navigation ──
  $(document).on('keydown', '#customerSearch, .product-search', function(e){
    var $list = $(this).closest('.ac-wrap').find('.ac-list');
    var items = $list.find('.ac-item:not(.ac-empty)');
    if (!$list.is(':visible') || !items.length) return;
    var idx = items.index(items.filter('.active'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var dir = e.key === 'ArrowDown' ? 1 : -1;
      idx = (idx + dir + items.length) % items.length;
      items.removeClass('active').eq(idx).addClass('active');
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var target = idx >= 0 ? items.eq(idx) : items.first();
      if (target.length) target.trigger('mousedown');
    } else if (e.key === 'Escape') {
      hideList($list);
    }
  });

  $(document).on('mouseover', '.ac-item', function(){
    $(this).addClass('active').siblings().removeClass('active');
  });

  $(document).on('mousedown', function(e){
    if (!$(e.target).closest('.ac-wrap').length) {
      $('.ac-list').empty().hide();
    }
  });

  // ── Form submit validation ──
  $('#saleForm').on('submit', function(e){
    var name = $.trim($('#customerSearch').val());
    if (!name) {
      e.preventDefault();
      $('#customerSearch').focus().addClass('is-invalid');
      alert('Customer name is required.');
      return;
    }
    $('#customerSearch').removeClass('is-invalid');

    var filled = false;
    $('#productRows .product-row').each(function(){
      var pid = $(this).find('.product-id').val();
      var pname = $.trim($(this).find('.product-search').val());
      var qty = parseFloat($(this).find('.qty').val()) || 0;
      if ((pid || pname) && qty > 0) filled = true;
    });

    if (!filled) {
      e.preventDefault();
      alert('Please enter at least one material with weight.');
      return;
    }

    // Prevent double clicking submit
    var $btn = $(this).find('button[type="submit"]');
    setTimeout(function(){
      $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
    }, 50);
  });

  // ── Prevent stale entries on browser back navigation ──
  function clearSaleForm() {
    $('#saleForm')[0].reset();
    $('#customer_id').val('');
    $('#customerBalance').text('');
    var $rows = $('#productRows .product-row');
    if ($rows.length > 1) {
      $rows.slice(1).remove();
    }
    var $first = $('#productRows .product-row').first();
    $first.find('.product-search').val('');
    $first.find('.product-id').val('');
    $first.find('.ac-list').empty().hide();
    $first.find('.qty, .rate').val('');
    $first.find('.unit').val('');
    $first.find('.subtotal').val('0.00');
    recalc();
  }

  window.addEventListener('pageshow', function(e) {
    var navEntries = (window.performance && window.performance.getEntriesByType) ? window.performance.getEntriesByType("navigation") : null;
    var isBack = e.persisted || (navEntries && navEntries.length > 0 && navEntries[0].type === 'back_forward') || (window.performance && window.performance.navigation && window.performance.navigation.type === 2);
    if (isBack && sessionStorage.getItem('sale_completed') === '1') {
      sessionStorage.removeItem('sale_completed');
      clearSaleForm();
    }
  });

  recalc();
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
