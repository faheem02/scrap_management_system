<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Edit Purchase';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    redirect('index.php', 'Invalid purchase ID', 'error');
}
$purchase_id = (int)$_GET['id'];

$purchase = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
$purchase->execute([$purchase_id]);
$purchase = $purchase->fetch();
if (!$purchase) {
    redirect('index.php', 'Purchase not found', 'error');
}

$existing_items_stmt = $pdo->prepare(
    "SELECT pi.*, p.name AS product_name, p.unit AS product_unit
     FROM purchase_items pi
     JOIN products p ON p.id = pi.product_id
     WHERE pi.purchase_id = ?"
);
$existing_items_stmt->execute([$purchase_id]);
$existing_rows = $existing_items_stmt->fetchAll();

$existing_items = [];
foreach ($existing_rows as $er) {
    $existing_items[] = [
        'product_id'   => (int)$er['product_id'],
        'product_name' => $er['product_name'],
        'product_unit' => $er['product_unit'] ?? '',
        'weight1'      => (float)$er['weight1'],
        'weight2'      => (float)$er['weight2'],
        'final'        => (float)$er['quantity'],
    ];
}
$existing_items_json = json_encode($existing_items);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_purchase = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
    $old_purchase->execute([$purchase_id]);
    $old_purchase = $old_purchase->fetch();
    if (!$old_purchase) {
        redirect('index.php', 'Purchase not found', 'error');
    }

    $old_items_stmt = $pdo->prepare("SELECT * FROM purchase_items WHERE purchase_id = ?");
    $old_items_stmt->execute([$purchase_id]);
    $old_items = $old_items_stmt->fetchAll();

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
        redirect("purchase_edit.php?id=$purchase_id", 'Vehicle number is required', 'error');
    }

    $product_ids = (array)($_POST['product_id'] ?? []);
    $weight1s    = (array)($_POST['weight1'] ?? []);
    $weight2s    = (array)($_POST['weight2'] ?? []);

    if (!count($product_ids) || !$product_ids[0]) {
        redirect("purchase_edit.php?id=$purchase_id", 'Add at least one material', 'error');
    }

    $total_weight = 0;
    $items = [];
    foreach ($product_ids as $i => $pid) {
        if (!$pid) continue;
        $w1    = (float)($weight1s[$i] ?? 0);
        $w2    = (float)($weight2s[$i] ?? 0);
        $final = max($w1, $w2);
        if ($final <= 0) continue;
        $total_weight += $final;
        $items[] = ['product_id' => (int)$pid, 'weight1' => $w1, 'weight2' => $w2, 'final' => $final];
    }

    if (!count($items)) {
        redirect("purchase_edit.php?id=$purchase_id", 'Add at least one material with weight', 'error');
    }

    $total_amount = (float)($_POST['total_amount'] ?? 0);
    $paid_amount  = (float)($_POST['paid_amount'] ?? 0);
    if ($paid_amount > $total_amount) $paid_amount = $total_amount;
    $due_amount = $total_amount - $paid_amount;

    $pdo->beginTransaction();
    try {
        // 1. Reverse old stock
        foreach ($old_items as $oi) {
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                ->execute([$oi['quantity'], $oi['product_id']]);
        }

        // 2. Remove old payment ledger entries
        $old_affected_dates = [];
        if ($old_purchase['paid_amount'] > 0) {
            $old_cb = $pdo->prepare(
                "SELECT transaction_date FROM cash_book WHERE reference_type = 'purchase' AND reference_id = ? AND transaction_type = 'outflow'"
            );
            $old_cb->execute([$purchase_id]);
            foreach ($old_cb->fetchAll() as $ocb) {
                $old_affected_dates[] = $ocb['transaction_date'];
            }
            $pdo->prepare("DELETE FROM cash_book WHERE reference_type = 'purchase' AND reference_id = ?")
                ->execute([$purchase_id]);
            if ($old_purchase['payment_method'] == 'bank' && $old_purchase['bank_account_id']) {
                $btn = $pdo->prepare(
                    "SELECT id, amount, bank_account_id FROM bank_transactions WHERE reference_type = 'purchase' AND reference_id = ?"
                );
                $btn->execute([$purchase_id]);
                foreach ($btn->fetchAll() as $b) {
                    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                        ->execute([$b['amount'], $b['bank_account_id']]);
                }
                $pdo->prepare("DELETE FROM bank_transactions WHERE reference_type = 'purchase' AND reference_id = ?")
                    ->execute([$purchase_id]);
            }
        }

        // 3. Delete old items
        $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?")->execute([$purchase_id]);

        $invoice_no = $old_purchase['invoice_no'];

        // 4. Update purchase header
        $pdo->prepare(
            "UPDATE purchases SET vehicle_no = ?, party_name = ?, purchase_date = ?, total_amount = ?, discount_amount = 0,
             paid_amount = ?, due_amount = ?, payment_method = ?, bank_account_id = ?,
             plant_area = ?, plant_name = ?, plant_category = ?, notes = ?, updated_at = ?
             WHERE id = ?"
        )->execute([
            $vehicle_no, $party_name, $purchase_date, $total_amount,
            $paid_amount, $due_amount, $payment_method,
            $payment_method == 'bank' ? $bank_account_id : null,
            $plant_area, $plant_name, $plant_category,
            $notes, date('Y-m-d'), $purchase_id
        ]);

        // 5. Insert new items + update stock
        foreach ($items as $it) {
            insert('purchase_items', [
                'purchase_id'    => $purchase_id,
                'product_id'     => $it['product_id'],
                'quantity'       => $it['final'],
                'purchase_price' => 0,
                'subtotal'       => 0,
                'weight1'        => $it['weight1'],
                'weight2'        => $it['weight2'],
            ]);
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")
                ->execute([$it['final'], $it['product_id']]);
        }

        // 6. Record new payment
        if ($paid_amount > 0) {
            $desc = "Purchase #{$invoice_no} - Vehicle {$vehicle_no}";
            if ($payment_method == 'bank') {
                recordBankOutflow($pdo, $purchase_date, $paid_amount, $desc, 'purchase', $purchase_id, $_SESSION['user_id'], $bank_account_id);
            } else {
                recordCashOutflow($pdo, $purchase_date, $paid_amount, $desc, 'purchase', $purchase_id, $_SESSION['user_id']);
            }
        }

        // 7. Recompute cash daily balances
        foreach (array_unique(array_merge([$purchase_date], $old_affected_dates)) as $d) {
            $day = $pdo->prepare("SELECT id FROM cash_book_daily WHERE date = ?");
            $day->execute([$d]);
            $did = $day->fetchColumn();
            if ($did) recomputeCashDayTotals($pdo, (int)$did);
        }
        if ($old_affected_dates) recomputeCashDailyFrom($pdo, min($old_affected_dates));

        $pdo->commit();
        logActivity($pdo, 'update', 'purchase', $purchase_id, 'Updated purchase ' . $invoice_no . ' vehicle ' . $vehicle_no . ' (' . round($total_weight, 2) . ' kg)');
        redirect('index.php', 'Purchase updated: ' . $invoice_no . ' | Stock adjusted (' . round($total_weight, 2) . ' kg)');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect("purchase_edit.php?id=$purchase_id", 'Error updating purchase: ' . $e->getMessage(), 'error');
    }
}

$categories    = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$plant_areas   = $pdo->query("SELECT id, name FROM plant_areas WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6><i class="fas fa-cart-arrow-down"></i> Edit Purchase <?= htmlspecialchars($purchase['invoice_no']) ?></h6>
    <a href="<?= $base_url ?>modules/purchases/index.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-list"></i> Purchase List</a>
  </div>
  <div class="card-body">
    <form method="post" id="purchaseForm" action="<?= $base_url ?>modules/purchases/purchase_edit.php?id=<?= (int)$purchase_id ?>">
      <input type="hidden" name="id" value="<?= htmlspecialchars($purchase_id) ?>">

      <!-- ── Section 1: Vehicle / Plant Info ── -->
      <div class="d-flex align-items-center mb-2">
        <h6 class="mb-0 text-secondary"><i class="fas fa-truck"></i> Vehicle &amp; Plant Info</h6>
      </div>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Party Name</label>
          <input type="text" name="party_name" class="form-control"
                 placeholder="e.g. Ali Steel Mill"
                 value="<?= htmlspecialchars($purchase['party_name'] ?? '') ?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Vehicle Number <span class="text-danger">*</span></label>
          <input type="text" name="vehicle_no" id="vehicleNo" class="form-control"
                 placeholder="e.g. LEV-1234"
                 value="<?= htmlspecialchars($purchase['vehicle_no'] ?? '') ?>" required>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Purchase Date <span class="text-danger">*</span></label>
          <input type="date" name="purchase_date" class="form-control datepicker"
                 value="<?= htmlspecialchars($purchase['purchase_date']) ?>" required>
        </div>
        <div class="col-md-6 mb-3">
          <div class="d-flex justify-content-between align-items-center">
            <label class="form-label mb-1">Plant Area</label>
            <a href="<?= $base_url ?>modules/purchases/plant_areas.php" target="_blank" class="small text-primary"><i class="fas fa-plus-circle"></i> Add Area</a>
          </div>
          <select name="plant_area" class="form-control">
            <option value="">-- Select Plant Area --</option>
            <?php foreach ($plant_areas as $pa): ?>
            <option value="<?= htmlspecialchars($pa['name']) ?>"
              <?= ($purchase['plant_area'] ?? '') == $pa['name'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($pa['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Plant Category</label>
          <select name="plant_category" class="form-control">
            <option value="">-- Select Plant Category --</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['name']) ?>"
              <?= ($purchase['plant_category'] ?? '') == $cat['name'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat['name']) ?>
            </option>
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
                <input type="text" class="form-control product-search" placeholder="Type material name or code..." autocomplete="off">
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
        <strong id="vehicleDisplay" class="ml-1 text-primary"><?= htmlspecialchars($purchase['vehicle_no'] ?? '—') ?></strong>
      </div>

      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Total Amount (Freight) <span class="text-danger">*</span></label>
          <input type="number" name="total_amount" id="totalAmount" class="form-control font-weight-bold"
                 value="<?= htmlspecialchars($purchase['total_amount']) ?>" min="0" step="0.01" required>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Paid Amount</label>
          <input type="number" name="paid_amount" id="paidAmount" class="form-control"
                 value="<?= htmlspecialchars($purchase['paid_amount']) ?>" min="0" step="0.01">
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Due Amount</label>
          <input type="text" class="form-control font-weight-bold text-danger" id="dueAmount"
                 value="<?= htmlspecialchars($purchase['due_amount']) ?>" readonly>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" id="payMethod" class="form-control">
            <option value="other"<?= ($purchase['payment_method'] ?? 'other') == 'other' ? ' selected' : '' ?>>Other</option>
            <option value="cash"<?= $purchase['payment_method'] == 'cash' ? ' selected' : '' ?>>Cash</option>
            <option value="bank"<?= $purchase['payment_method'] == 'bank' ? ' selected' : '' ?>>Bank</option>
          </select>
        </div>
      </div>
      <div class="row" id="bankRow"<?= $purchase['payment_method'] != 'bank' ? ' style="display:none;"' : '' ?>>
        <div class="col-md-4 mb-3">
          <label class="form-label">Bank Account</label>
          <select name="bank_account_id" class="form-control">
            <?php foreach ($bank_accounts as $ba): ?>
            <option value="<?= $ba['id'] ?>"<?= (int)$purchase['bank_account_id'] === (int)$ba['id'] ? ' selected' : '' ?>>
              <?= htmlspecialchars($ba['account_name']) ?> — <?= htmlspecialchars($ba['bank_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row mt-2">
        <div class="col-md-8">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Optional notes"
                 value="<?= htmlspecialchars($purchase['notes'] ?? '') ?>">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button type="submit" class="btn btn-primary btn-block py-2"><i class="fas fa-save"></i> Update Purchase</button>
        </div>
      </div>

    </form>
  </div>
</div>

<script>
var existing_items = <?= $existing_items_json ?>;
$(document).ready(function(){

  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function hideList($list){ $list.empty().hide(); }

  // Vehicle display sync
  $('#vehicleNo').on('input', function(){
    var v = $.trim(this.value);
    $('#vehicleDisplay').text(v || '—');
  });

  function recalcRow($row){
    var w1 = parseFloat($row.find('.weight1').val()) || 0;
    var w2 = parseFloat($row.find('.weight2').val()) || 0;
    $row.find('.final-weight').val(Math.max(w1, w2).toFixed(2));
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

  function buildProductItem(item){
    var psub = [];
    if (item.code) psub.push('Code: ' + esc(item.code));
    if (item.unit) psub.push('Unit: ' + esc(item.unit));
    return $('<div class="ac-item" data-id="' + item.id +
      '" data-unit="' + esc(item.unit || '') + '">' +
      '<span class="ac-name">' + esc(item.name) + '</span>' +
      (psub.length ? '<small class="ac-sub">' + psub.join(' &middot; ') + '</small>' : '') +
      '</div>');
  }

  function renderList($list, items){
    $list.empty();
    if (!items || !items.length) {
      $list.append('<div class="ac-item ac-empty">No matching record found</div>');
    } else {
      $.each(items, function(i, it){ $list.append(buildProductItem(it)); });
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

  $('#productRows').on('input', '.weight1, .weight2', function(){
    var $row = $(this).closest('.product-row');
    recalcRow($row);
    recalcTotals();
  });

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

  $('#productRows').on('click', '.remove-row', function(){
    if ($('#productRows .product-row').length > 1) {
      $(this).closest('.product-row').remove();
      recalcTotals();
    } else {
      alert('At least one material row is required.');
    }
  });

  $('#totalAmount, #paidAmount').on('input', recalcPayment);

  $('#payMethod').change(function(){
    $('#bankRow').toggle(this.value === 'bank');
  });

  $('#purchaseForm').on('submit', function(e){
    var hasProduct = false, hasWeight = false;
    $('#productRows .product-row').each(function(){
      if ($(this).find('.product-id').val()) {
        hasProduct = true;
        if ((parseFloat($(this).find('.final-weight').val()) || 0) > 0) hasWeight = true;
      }
    });
    if (!hasProduct) { e.preventDefault(); alert('Please select at least one material.'); return; }
    if (!hasWeight)  { e.preventDefault(); alert('Please enter Weight 1 (WIL) or Weight 2 for at least one material.'); }
  });

  // Pre-fill existing items
  if (existing_items.length > 0) {
    var $firstRow = $('#productRows .product-row').first();
    $.each(existing_items, function(idx, item){
      var $row;
      if (idx === 0) {
        $row = $firstRow;
      } else {
        $row = $firstRow.clone();
        $row.find('.product-search, .product-id, .unit-display, .weight1, .weight2').val('');
        $row.find('.ac-list').empty().hide();
        $row.find('.final-weight').val('0.00');
        $('#productRows').append($row);
      }
      $row.find('.product-search').val(item.product_name);
      $row.find('.product-id').val(item.product_id);
      $row.find('.unit-display').val(item.product_unit || '');
      $row.find('.weight1').val(item.weight1 > 0 ? item.weight1 : '');
      $row.find('.weight2').val(item.weight2 > 0 ? item.weight2 : '');
      $row.find('.final-weight').val(item.final.toFixed(2));
    });
    recalcTotals();
    recalcPayment();
  }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
