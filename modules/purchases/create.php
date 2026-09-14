<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'New Purchase';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_no      = trim($_POST['vehicle_no'] ?? '');
    $party_name      = trim($_POST['party_name'] ?? '');
    $purchase_date   = $_POST['purchase_date'] ?: date('Y-m-d');
    $plant_category  = trim($_POST['plant_category'] ?? '');
    $plant_area      = trim($_POST['plant_area'] ?? '');
    $plant_name      = trim($_POST['plant_name'] ?? '');
    $payment_method  = $_POST['payment_method'] ?: 'cash';
    $bank_account_id = (int)($_POST['bank_account_id'] ?? 0) ?: null;
    $notes           = trim($_POST['notes'] ?? '');

    if (!$vehicle_no) {
        redirect('create.php', 'Vehicle number is required', 'error');
    }

    $product_ids   = (array)($_POST['product_id'] ?? []);
    $product_names = (array)($_POST['product_name'] ?? []);
    $weight1s      = (array)($_POST['weight1'] ?? []);
    $weight2s      = (array)($_POST['weight2'] ?? []);

    $total_weight = 0;
    $items = [];
    $user_id = $_SESSION['user_id'] ?? 1;

    foreach ($product_ids as $i => $pid) {
        $pid   = (int)$pid;
        $pname = trim($product_names[$i] ?? '');
        $w1    = (float)($weight1s[$i] ?? 0);
        $w2    = (float)($weight2s[$i] ?? 0);
        $final = max($w1, $w2);

        if (!$pid && $pname !== '') {
            $fnd = $pdo->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?) LIMIT 1");
            $fnd->execute([$pname, $pname]);
            $pid = (int)$fnd->fetchColumn();

            if (!$pid) {
                $pcode = 'P-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $pname), 0, 4)) . rand(100, 999);
                $pid = insert('products', [
                    'code' => $pcode,
                    'name' => $pname,
                    'unit' => 'kg',
                    'purchase_price' => 0,
                    'sale_price' => 0,
                    'stock_quantity' => 0,
                    'status' => 1,
                    'created_at' => date('Y-m-d')
                ]);
            }
        }

        if (!$pid || $final <= 0) continue;
        $total_weight += $final;
        $items[] = [
            'product_id' => $pid,
            'weight1'    => $w1,
            'weight2'    => $w2,
            'final'      => $final,
        ];
    }

    if (!count($items)) {
        redirect('create.php', 'Please add at least one material with weight.', 'error');
    }

    // Vehicle/freight amount — entered manually in the payment section
    $total_amount = (float)($_POST['total_amount'] ?? 0);
    $paid_amount  = (float)($_POST['paid_amount'] ?? 0);
    if ($paid_amount > $total_amount) $paid_amount = $total_amount;
    $due_amount = $total_amount - $paid_amount;

    $invoice_no = trim($_POST['invoice_no'] ?? '') ?: generatePurchaseNo();
    $chk = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE invoice_no = ?");
    $chk->execute([$invoice_no]);
    if ($chk->fetchColumn() > 0) {
        $invoice_no = generatePurchaseNo();
    }

    $pdo->beginTransaction();
    try {
        $purchase_id = insert('purchases', [
            'vehicle_no'     => $vehicle_no,
            'party_name'     => $party_name,
            'invoice_no'     => $invoice_no,
            'purchase_date'  => $purchase_date,
            'total_amount'   => $total_amount,
            'discount_amount'=> 0,
            'paid_amount'    => $paid_amount,
            'due_amount'     => $due_amount,
            'payment_method' => $payment_method,
            'bank_account_id'=> $payment_method == 'bank' ? $bank_account_id : null,
            'status'         => 'received',
            'notes'          => $notes,
            'plant_area'     => $plant_area,
            'plant_name'     => $plant_name,
            'plant_category' => $plant_category,
            'created_by'     => $_SESSION['user_id'],
            'created_at'     => date('Y-m-d'),
        ]);

        foreach ($items as $it) {
            insert('purchase_items', [
                'purchase_id'    => $purchase_id,
                'product_id'     => $it['product_id'],
                'quantity'       => $it['final'],   // final weight stored as quantity
                'purchase_price' => 0,
                'subtotal'       => 0,
                'weight1'        => $it['weight1'],
                'weight2'        => $it['weight2'],
            ]);
            // Update stock (add final weight)
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")
                ->execute([$it['final'], $it['product_id']]);
        }

        // Payment handling (vehicle freight)
        if ($paid_amount > 0) {
            $desc = "Purchase #{$invoice_no} - Vehicle {$vehicle_no}";
            if ($payment_method == 'bank') {
                recordBankOutflow($pdo, $purchase_date, $paid_amount, $desc, 'purchase', $purchase_id, $_SESSION['user_id'], $bank_account_id);
            } else {
                recordCashOutflow($pdo, $purchase_date, $paid_amount, $desc, 'purchase', $purchase_id, $_SESSION['user_id']);
            }
        }

        $pdo->commit();
        logActivity($pdo, 'create', 'purchase', $purchase_id, 'Created purchase ' . $invoice_no . ' vehicle ' . $vehicle_no . ' (' . round($total_weight, 2) . ' kg)');
        redirect('index.php', 'Purchase saved: ' . $invoice_no . ' | Vehicle: ' . $vehicle_no . ' | Total weight: ' . round($total_weight, 2) . ' kg');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('create.php', 'Error saving purchase: ' . $e->getMessage(), 'error');
    }
}

$categories      = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$plant_areas     = $pdo->query("SELECT id, name FROM plant_areas WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts   = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();
$auto_invoice_no = generatePurchaseNo();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-cart-arrow-down"></i> New Purchase</h6>
    <a href="<?= $base_url ?>modules/purchases/index.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-list"></i> Purchase List</a>
  </div>
  <div class="card-body">
    <form method="post" id="purchaseForm" autocomplete="off">

      <!-- ── Section 1: Vehicle / Plant Info ── -->
      <div class="d-flex align-items-center mb-2">
        <h6 class="mb-0 text-secondary"><i class="fas fa-truck"></i> Vehicle &amp; Plant Info</h6>
      </div>
      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Invoice No <span class="badge badge-info">Auto</span></label>
          <input type="text" name="invoice_no" class="form-control bg-light font-weight-bold text-primary" value="<?= htmlspecialchars($auto_invoice_no) ?>" readonly>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Party Name</label>
          <input type="text" name="party_name" class="form-control" placeholder="e.g. Ali Steel Mill">
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Vehicle Number <span class="text-danger">*</span></label>
          <input type="text" name="vehicle_no" id="vehicleNo" class="form-control" placeholder="e.g. LEV-1234" required>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Purchase Date <span class="text-danger">*</span></label>
          <input type="date" name="purchase_date" class="form-control datepicker" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-md-6 mb-3">
          <div class="d-flex justify-content-between align-items-center">
            <label class="form-label mb-1">Plant Area</label>
            <a href="<?= $base_url ?>modules/purchases/plant_areas.php" target="_blank" class="small text-primary"><i class="fas fa-plus-circle"></i> Add Area</a>
          </div>
          <select name="plant_area" class="form-control">
            <option value="">-- Select Plant Area --</option>
            <?php foreach ($plant_areas as $pa): ?>
            <option value="<?= htmlspecialchars($pa['name']) ?>"><?= htmlspecialchars($pa['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Plant Category</label>
          <select name="plant_category" class="form-control">
            <option value="">-- Select Plant Category --</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <hr>

      <!-- ── Section 2: Materials ── -->
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
        <h6 class="mb-0 text-secondary"><i class="fas fa-boxes"></i> Materials</h6>
        <small class="text-muted">Final Weight = higher of Weight 1 (WIL) &amp; Weight 2</small>
      </div>

      <div id="productRows">
        <div class="product-row">
          <div class="row g-2">
            <div class="col-md-3 col-lg-3">
              <label class="form-label">Material</label>
              <div class="ac-wrap">
                <input type="text" class="form-control product-search" name="product_name[]" placeholder="Type material name or code..." autocomplete="off">
                <input type="hidden" name="product_id[]" class="product-id">
                <div class="ac-list"></div>
              </div>
            </div>
            <div class="col-6 col-md-2 col-lg-2">
              <label class="form-label">Unit</label>
              <input type="text" class="form-control unit-display bg-light" readonly value="">
            </div>
            <div class="col-6 col-md-2 col-lg-2">
              <label class="form-label">Weight 1 (WIL)</label>
              <input type="number" name="weight1[]" class="form-control weight1" step="0.01" min="0" placeholder="0.00">
            </div>
            <div class="col-6 col-md-2 col-lg-2">
              <label class="form-label">Weight 2</label>
              <input type="number" name="weight2[]" class="form-control weight2" step="0.01" min="0" placeholder="0.00">
            </div>
            <div class="col-6 col-md-2 col-lg-2">
              <label class="form-label">Final Weight</label>
              <input type="text" class="form-control final-weight font-weight-bold bg-light" readonly value="0.00">
            </div>
            <div class="col-6 col-md-1 col-lg-1 d-flex align-items-end">
              <button type="button" class="btn btn-outline-danger remove-row w-100" title="Remove"><i class="fas fa-trash-alt"></i></button>
            </div>
          </div>
        </div>
      </div>

      <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addRow"><i class="fas fa-plus"></i> Add Another Material</button>

      <div class="alert alert-info py-2 mb-3">
        <i class="fas fa-balance-scale"></i> <strong>Total Weight:</strong> <span id="sumWeight">0.00</span> kg
      </div>

      <hr>

      <!-- ── Section 3: Vehicle Amount / Payment ── -->
      <div class="d-flex align-items-center mb-2">
        <h6 class="mb-0 text-secondary"><i class="fas fa-money-bill-wave"></i> Vehicle Amount &amp; Payment</h6>
      </div>

      <div class="alert alert-secondary py-2 mb-3">
        <i class="fas fa-truck"></i> Payment against Vehicle No:
        <strong id="vehicleDisplay" class="ml-1 text-primary">—</strong>
      </div>

      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Total Amount (Freight) <span class="text-danger">*</span></label>
          <input type="number" name="total_amount" id="totalAmount" class="form-control font-weight-bold"
                 value="0" min="0" step="0.01" placeholder="0.00" required>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Paid Amount</label>
          <input type="number" name="paid_amount" id="paidAmount" class="form-control" value="0" min="0" step="0.01">
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Due Amount</label>
          <input type="text" class="form-control font-weight-bold text-danger" id="dueAmount" value="0.00" readonly>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" id="payMethod" class="form-control">
            <option value="cash" selected>Cash</option>
            <option value="bank">Bank</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div class="row" id="bankRow" style="display:none;">
        <div class="col-md-4 mb-3">
          <label class="form-label">Bank Account</label>
          <select name="bank_account_id" class="form-control">
            <?php foreach ($bank_accounts as $ba): ?>
            <option value="<?= $ba['id'] ?>"><?= htmlspecialchars($ba['account_name']) ?> — <?= htmlspecialchars($ba['bank_name']) ?></option>
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
          <button type="submit" class="btn btn-primary btn-block py-2"><i class="fas fa-save"></i> Save Purchase</button>
        </div>
      </div>

    </form>
  </div>
</div>

<script>
$(document).ready(function(){

  // ── Helpers ──
  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function hideList($list){ $list.empty().hide(); }

  // ── Vehicle number → display label ──
  $('#vehicleNo').on('input', function(){
    var v = $.trim(this.value);
    $('#vehicleDisplay').text(v || '—');
  });

  // ── Weight recalculator ──
  function recalcRow($row){
    var w1 = parseFloat($row.find('.weight1').val()) || 0;
    var w2 = parseFloat($row.find('.weight2').val()) || 0;
    var final = Math.max(w1, w2);
    $row.find('.final-weight').val(final.toFixed(2));
  }

  function recalcTotals(){
    var total = 0;
    $('#productRows .product-row').each(function(){
      total += parseFloat($(this).find('.final-weight').val()) || 0;
    });
    $('#sumWeight').text(total.toFixed(2));
  }

  function recalcPayment(){
    var total = parseFloat($('#totalAmount').val()) || 0;
    var paid  = parseFloat($('#paidAmount').val()) || 0;
    if (paid > total) { $('#paidAmount').val(total.toFixed(2)); paid = total; }
    $('#dueAmount').val(Math.max(total - paid, 0).toFixed(2));
  }

  // ── Product autocomplete ──
  function buildProductItem(item){
    var name = esc(item.name);
    var psub = [];
    if (item.code) psub.push('Code: ' + esc(item.code));
    if (item.unit) psub.push('Unit: ' + esc(item.unit));
    return $('<div class="ac-item" data-id="' + item.id +
      '" data-unit="' + esc(item.unit || '') +
      '" data-stock="' + item.stock_quantity + '">' +
      '<span class="ac-name">' + name + '</span>' +
      (psub.length ? '<small class="ac-sub">' + psub.join(' &middot; ') + '</small>' : '') +
      '</div>');
  }

  function renderList($list, items){
    $list.empty();
    if (!items || !items.length) {
      $list.append('<div class="ac-item ac-empty">No matching record found</div>');
    } else {
      $.each(items, function(i, it){
        $list.append(buildProductItem(it));
      });
    }
    $list.show();
  }

  $('#productRows').on('input', '.product-search', function(){
    var $row  = $(this).closest('.product-row');
    var $list = $row.find('.ac-list');
    var q     = $.trim(this.value);
    clearTimeout($(this).data('timer'));
    if (!q) {
      $row.find('.product-id').val('');
      $row.find('.unit-display').val('');
      hideList($list);
      return;
    }
    $(this).data('timer', setTimeout(function(){
      $.get('ajax_search.php', {type: 'product', q: q}, function(data){
        renderList($list, data);
      });
    }, 250));
  });

  function pickProduct($item){
    var $row = $item.closest('.product-row');
    $row.find('.product-id').val($item.data('id'));
    $row.find('.product-search').val($item.find('.ac-name').text());
    $row.find('.unit-display').val($item.data('unit') || '');
    hideList($row.find('.ac-list'));
  }

  $('#productRows').on('mousedown click', '.ac-item', function(e){
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickProduct($(this));
  });

  // Keyboard nav for product search
  $(document).on('keydown', '.product-search', function(e){
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

  // Weight inputs
  $('#productRows').on('input', '.weight1, .weight2', function(){
    var $row = $(this).closest('.product-row');
    recalcRow($row);
    recalcTotals();
  });

  // Add row
  $('#addRow').click(function(){
    var $first = $('#productRows .product-row').first().clone();
    $first.find('.product-search').val('');
    $first.find('.product-id').val('');
    $first.find('.ac-list').empty().hide();
    $first.find('.unit-display').val('');
    $first.find('.weight1, .weight2').val('');
    $first.find('.final-weight').val('0.00');
    $('#productRows').append($first);
  });

  // Remove row
  $('#productRows').on('click', '.remove-row', function(){
    if ($('#productRows .product-row').length > 1) {
      $(this).closest('.product-row').remove();
      recalcTotals();
    } else {
      alert('At least one material row is required.');
    }
  });

  // Payment recalc
  $('#totalAmount, #paidAmount').on('input', recalcPayment);

  // Bank toggle
  $('#payMethod').change(function(){
    $('#bankRow').toggle(this.value === 'bank');
  });

  // Form validation
  $('#purchaseForm').on('submit', function(e){
    var hasProduct = false;
    var hasWeight  = false;
    $('#productRows .product-row').each(function(){
      if ($(this).find('.product-id').val()) {
        hasProduct = true;
        if ((parseFloat($(this).find('.final-weight').val()) || 0) > 0) hasWeight = true;
      }
    });
    if (!hasProduct) {
      e.preventDefault();
      alert('Please select or type at least one material.');
      return;
    }
    if (!hasWeight) {
      e.preventDefault();
      alert('Please enter Weight 1 (WIL) or Weight 2 for at least one material.');
      return;
    }

    var $btn = $(this).find('button[type="submit"]');
    setTimeout(function(){
      $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
    }, 50);
  });

  // ── Prevent stale entries on browser back navigation ──
  function clearPurchaseForm() {
    $('#purchaseForm')[0].reset();
    var $rows = $('#productRows .product-row');
    if ($rows.length > 1) {
      $rows.slice(1).remove();
    }
    var $first = $('#productRows .product-row').first();
    $first.find('.product-search').val('');
    $first.find('.product-id').val('');
    $first.find('.ac-list').empty().hide();
    $first.find('.unit-display').val('');
    $first.find('.weight1, .weight2').val('');
    $first.find('.final-weight').val('0.00');
    recalcTotals();
  }

  window.addEventListener('pageshow', function(e) {
    var navEntries = (window.performance && window.performance.getEntriesByType) ? window.performance.getEntriesByType("navigation") : null;
    var isBack = e.persisted || (navEntries && navEntries.length > 0 && navEntries[0].type === 'back_forward') || (window.performance && window.performance.navigation && window.performance.navigation.type === 2);
    if (isBack && sessionStorage.getItem('purchase_completed') === '1') {
      sessionStorage.removeItem('purchase_completed');
      clearPurchaseForm();
    }
  });

});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
