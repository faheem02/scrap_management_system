<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Expenses';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$categories = $pdo->query("SELECT id, name FROM expense_categories WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();
$bank_map = [];
foreach ($bank_accounts as $ba) {
    $bank_map[$ba['id']] = $ba['account_name'] . ' (' . $ba['bank_name'] . ')';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_expense';

    if ($action === 'delete_expense') {
        $eid = (int)($_POST['expense_id'] ?? 0);
        $exp = getById('expenses', $eid);
        if (!$exp) { redirect('index.php', 'Expense not found', 'error'); }

        $pdo->beginTransaction();
        try {
            // Reverse cash_book entries
            $cb = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book WHERE reference_type = 'expense' AND reference_id = ?");
            $cb->execute([$eid]);
            $cbs = $cb->fetchAll();
            $min_date = null;
            $daily_ids = [];
            foreach ($cbs as $c) {
                $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$c['id']]);
                $daily_ids[] = $c['daily_id'];
                if ($min_date === null || $c['transaction_date'] < $min_date) $min_date = $c['transaction_date'];
            }
            foreach (array_unique($daily_ids) as $did) recomputeCashDayTotals($pdo, (int)$did);
            if ($min_date) recomputeCashDailyFrom($pdo, $min_date);

            // Reverse bank_transactions entries
            $bt = $pdo->prepare("SELECT id, amount, bank_account_id FROM bank_transactions WHERE reference_type = 'expense' AND reference_id = ?");
            $bt->execute([$eid]);
            foreach ($bt->fetchAll() as $b) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                    ->execute([(float)$b['amount'], (int)$b['bank_account_id']]);
                $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$b['id']]);
            }

            delete('expenses', $eid);
            logActivity($pdo, 'delete', 'expense', $eid, 'Deleted expense ' . (!empty($exp['bill_no']) ? "Bill #{$exp['bill_no']} " : '') . 'PKR ' . $exp['amount']);
            $pdo->commit();
            redirect('index.php', 'Expense deleted successfully');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect('index.php', 'Error deleting expense: ' . $e->getMessage(), 'error');
        }
    }

    if ($action === 'edit_expense') {
        $eid = (int)($_POST['expense_id'] ?? 0);
        $exp = getById('expenses', $eid);
        if (!$exp) { redirect('index.php', 'Expense not found', 'error'); }

        $amount       = (float)($_POST['amount'] ?? 0);
        $expense_date = $_POST['expense_date'] ?: date('Y-m-d');
        $category_id  = $_POST['category_id'] ?: null;
        $method       = $_POST['payment_method'] ?: 'cash';
        $bank_id      = $method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
        $description  = trim($_POST['description'] ?? '');
        $vendor       = trim($_POST['vendor_name'] ?? '');
        $bill_no      = trim($_POST['bill_no'] ?? '');

        if ($amount <= 0) {
            redirect('index.php', 'Enter a valid expense amount', 'error');
        }

        $pdo->beginTransaction();
        try {
            // 1. Reverse old cash_book entries
            $cb = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book WHERE reference_type = 'expense' AND reference_id = ?");
            $cb->execute([$eid]);
            $cbs = $cb->fetchAll();
            $min_date = null;
            $daily_ids = [];
            foreach ($cbs as $c) {
                $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$c['id']]);
                $daily_ids[] = $c['daily_id'];
                if ($min_date === null || $c['transaction_date'] < $min_date) $min_date = $c['transaction_date'];
            }
            foreach (array_unique($daily_ids) as $did) recomputeCashDayTotals($pdo, (int)$did);
            if ($min_date) recomputeCashDailyFrom($pdo, $min_date);

            // 2. Reverse old bank_transactions entries
            $bt = $pdo->prepare("SELECT id, amount, bank_account_id FROM bank_transactions WHERE reference_type = 'expense' AND reference_id = ?");
            $bt->execute([$eid]);
            foreach ($bt->fetchAll() as $b) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                    ->execute([(float)$b['amount'], (int)$b['bank_account_id']]);
                $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$b['id']]);
            }

            // 3. Update expense row
            update('expenses', $eid, [
                'category_id'     => $category_id,
                'expense_date'    => $expense_date,
                'amount'          => $amount,
                'description'     => $description,
                'vendor_name'     => $vendor,
                'bill_no'         => $bill_no,
                'payment_method'  => $method,
                'bank_account_id' => $bank_id,
                'updated_at'      => date('Y-m-d'),
            ]);

            // 4. Record new outflow
            $desc = 'Expense: ' . ($description ?: ($bill_no ? "Bill #{$bill_no}" : 'Expense'));
            if ($method == 'bank') {
                recordBankOutflow($pdo, $expense_date, $amount, $desc, 'expense', $eid, $_SESSION['user_id'], $bank_id);
            } else {
                recordCashOutflow($pdo, $expense_date, $amount, $desc, 'expense', $eid, $_SESSION['user_id']);
            }

            $pdo->commit();
            logActivity($pdo, 'edit', 'expense', $eid, 'Updated expense #' . $eid . ' (' . ($bill_no ? "Bill #{$bill_no} " : '') . 'PKR ' . $amount . ')');
            redirect('index.php', 'Expense updated successfully');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect('index.php', 'Error updating expense: ' . $e->getMessage(), 'error');
        }
    }

    $amount = (float)($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?: date('Y-m-d');
    $category_id = $_POST['category_id'] ?: null;
    $method = $_POST['payment_method'] ?: 'cash';
    $bank_id = $method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
    $description = trim($_POST['description'] ?? '');
    $vendor = trim($_POST['vendor_name'] ?? '');
    $bill_no = trim($_POST['bill_no'] ?? '');

    if ($amount <= 0) {
        redirect('index.php', 'Enter a valid expense amount', 'error');
    }

    $pdo->beginTransaction();
    try {
        $eid = insert('expenses', [
            'category_id' => $category_id,
            'expense_date' => $expense_date,
            'amount' => $amount,
            'description' => $description,
            'vendor_name' => $vendor,
            'bill_no' => $bill_no,
            'payment_method' => $method,
            'bank_account_id' => $bank_id,
            'branch_id' => $_SESSION['branch_id'] ?? null,
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d'),
        ]);
        $desc = 'Expense: ' . ($description ?: ($bill_no ? "Bill #{$bill_no}" : 'Expense'));
        if ($method == 'bank') {
            recordBankOutflow($pdo, $expense_date, $amount, $desc, 'expense', $eid, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashOutflow($pdo, $expense_date, $amount, $desc, 'expense', $eid, $_SESSION['user_id']);
        }
        $pdo->commit();
        logActivity($pdo, 'create', 'expense', $eid, 'Recorded expense ' . ($bill_no ? "Bill #{$bill_no} " : '') . 'PKR ' . $amount);
        redirect('index.php', 'Expense of PKR ' . formatCurrency($amount) . ' recorded');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

// Filters
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$cat = $_GET['category_id'] ?? '';
$sql = "SELECT e.*, ec.name AS cat_name FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id WHERE 1=1";
$params = [];
if ($from) { $sql .= " AND e.expense_date >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND e.expense_date <= ?"; $params[] = $to; }
if ($cat !== '') { $sql .= " AND e.category_id = ?"; $params[] = $cat; }
$sql .= " ORDER BY e.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$total_expense = 0;
foreach ($expenses as $e) $total_expense += $e['amount'];

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 d-print-none">
  <div class="col-md-8">
    <div class="alert alert-danger alert-dismissible fade show py-2 mb-0" role="alert">
      <i class="fas fa-wallet"></i> <strong>Expenses</strong> &nbsp;Record shop expenses (cash or bank) and track them here.
      <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span>&times;</span></button>
    </div>
  </div>
  <div class="col-md-4 text-md-right">
    <button type="button" class="btn btn-outline-secondary shadow-sm mr-2" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
    <button type="button" class="btn btn-danger shadow-sm" data-toggle="modal" data-target="#expenseModal">
      <i class="fas fa-plus-circle"></i> New Expense
    </button>
  </div>
</div>

<!-- New Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1" role="dialog" aria-labelledby="expenseModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="expenseModalLabel"><i class="fas fa-plus-circle text-danger"></i> New Expense</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Amount (PKR) *</label>
              <input type="number" name="amount" step="0.01" min="0" class="form-control" required placeholder="0.00">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Date *</label>
              <input type="date" name="expense_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-control">
                <option value="">-- Select --</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Payment Method</label>
              <select name="payment_method" id="payMethod" class="form-control">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-md-12 mb-3" id="bankDiv" style="display:none;">
              <label class="form-label">Bank Account</label>
              <select name="bank_account_id" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> - <?=htmlspecialchars($ba['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Vendor / Payee</label>
              <input type="text" name="vendor_name" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Bill No</label>
              <input type="text" name="bill_no" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-12 mb-3">
              <label class="form-label">Description</label>
              <input type="text" name="description" class="form-control" placeholder="e.g. Shop rent">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-check"></i> Save Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card shadow">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0"><i class="fas fa-file-invoice-dollar"></i> Expense List (<?=count($expenses)?>)</h6>
    <input type="text" id="expenseSearch" class="form-control form-control-sm d-print-none" placeholder="Search bill no / date / category / vendor / description" style="max-width:320px;">
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 mb-3 d-print-none">
      <div class="col-md-3"><input type="date" name="from" class="form-control datepicker" value="<?=htmlspecialchars($from)?>"></div>
      <div class="col-md-3"><input type="date" name="to" class="form-control datepicker" value="<?=htmlspecialchars($to)?>"></div>
      <div class="col-md-4">
        <select name="category_id" class="form-control">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?=$c['id']?>" <?= $cat == $c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-filter"></i> Go</button>
        <?php if ($from || $to || $cat !== ''): ?>
        <a href="index.php" class="btn btn-outline-secondary btn-block mt-1"><i class="fas fa-times"></i> All</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Printable header -->
    <div class="d-none d-print-block mb-3 text-center">
      <?php
      $logo_path = dirname(__DIR__, 2) . '/assets/img/logo.png';
      $logo_data = file_exists($logo_path) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logo_path))) : (($base_url ?? '') . 'assets/img/logo.png');
      ?>
      <img src="<?= $logo_data ?>" alt="ARAB KHEL" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; margin-bottom: 6px; border: 1.5px solid #10b981; background: #fff;">
      <h4 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h4>
      <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
      <h5 class="font-weight-bold text-danger mt-2 mb-0">EXPENSE LIST</h5>
      <?php $e_meta = [];
      if ($from) $e_meta[] = 'From: ' . formatDate($from);
      if ($to) $e_meta[] = 'To: ' . formatDate($to);
      $e_cat_name = '';
      foreach ($categories as $c) if ((string)$c['id'] === (string)$cat) { $e_cat_name = $c['name']; break; }
      if ($e_cat_name) $e_meta[] = 'Category: ' . $e_cat_name;
      if ($e_meta): ?><div class="mt-1 font-weight-bold"><?=implode(' &nbsp;|&nbsp; ', $e_meta)?></div><?php endif; ?>
      <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
    </div>

    <div class="alert alert-danger py-2 text-center d-print-none"><strong>Filtered Total: PKR <?=formatCurrency($total_expense)?></strong></div>
    <div class="d-none d-print-block mb-2"><h6 class="font-weight-bold text-danger">Total: PKR <?=formatCurrency($total_expense)?></h6></div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="expenseTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Bill No</th>
            <th>Category</th>
            <th>Description</th>
            <th>Vendor</th>
            <th>Method</th>
            <th>Amount</th>
            <th class="text-center d-print-none" style="width:105px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expenses as $e): ?>
          <tr>
            <td><?=formatDate($e['expense_date'])?></td>
            <td class="font-weight-bold">
              <?php if (!empty($e['bill_no'])): ?>
                <span class="badge badge-light border border-primary text-primary px-2 py-1" style="font-size:12px;"><?=htmlspecialchars($e['bill_no'])?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?=htmlspecialchars($e['cat_name'] ?? '-')?></td>
            <td><?=htmlspecialchars($e['description'] ?? '-')?></td>
            <td><?=htmlspecialchars($e['vendor_name'] ?? '-')?></td>
            <td>
              <?php if ($e['payment_method'] == 'bank'): ?>
                <span class="badge badge-info">Bank</span>
              <?php else: ?>
                <span class="badge badge-secondary">Cash</span>
              <?php endif; ?>
            </td>
            <td class="text-danger font-weight-bold">PKR <?=formatCurrency($e['amount'])?></td>
            <td class="text-center d-print-none text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-warning btn-edit-expense mr-1"
                      data-id="<?=$e['id']?>"
                      data-bill="<?=htmlspecialchars($e['bill_no'] ?? '', ENT_QUOTES)?>"
                      data-date="<?=$e['expense_date']?>"
                      data-cat="<?=$e['category_id'] ?? ''?>"
                      data-vendor="<?=htmlspecialchars($e['vendor_name'] ?? '', ENT_QUOTES)?>"
                      data-amount="<?=$e['amount']?>"
                      data-method="<?=$e['payment_method']?>"
                      data-bank="<?=$e['bank_account_id'] ?? ''?>"
                      data-desc="<?=htmlspecialchars($e['description'] ?? '', ENT_QUOTES)?>"
                      title="Edit Expense">
                <i class="fas fa-edit"></i>
              </button>
              <form method="post" action="index.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this expense?');">
                <input type="hidden" name="action" value="delete_expense">
                <input type="hidden" name="expense_id" value="<?=$e['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Expense">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($expenses)): ?><tr><td colspan="8" class="text-center text-muted py-3">No expenses yet</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Edit Expense Modal -->
<div class="modal fade" id="editExpenseModal" tabindex="-1" role="dialog" aria-labelledby="editExpenseModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content shadow">
      <form method="post" action="index.php">
        <input type="hidden" name="action" value="edit_expense">
        <input type="hidden" name="expense_id" id="edit_exp_id">
        <div class="modal-header bg-light">
          <h5 class="modal-title font-weight-bold" id="editExpenseModalLabel">
            <i class="fas fa-edit text-warning mr-2"></i> Edit Expense
          </h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 form-group">
              <label class="form-label font-weight-bold">Bill / Receipt No.</label>
              <input type="text" name="bill_no" id="edit_bill_no" class="form-control" placeholder="e.g. BILL-1024">
            </div>
            <div class="col-md-6 form-group">
              <label class="form-label font-weight-bold">Date *</label>
              <input type="date" name="expense_date" id="edit_expense_date" class="form-control" required>
            </div>
            <div class="col-md-6 form-group">
              <label class="form-label font-weight-bold">Expense Category</label>
              <select name="category_id" id="edit_category_id" class="form-control">
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?=$cat['id']?>"><?=htmlspecialchars($cat['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 form-group">
              <label class="form-label font-weight-bold">Vendor / Payee</label>
              <input type="text" name="vendor_name" id="edit_vendor_name" class="form-control" placeholder="Vendor or Person name">
            </div>
            <div class="col-md-6 form-group">
              <label class="form-label font-weight-bold">Amount (PKR) *</label>
              <input type="number" name="amount" id="edit_amount" step="0.01" min="0.01" class="form-control font-weight-bold" required>
            </div>
            <div class="col-md-6 form-group">
              <label class="form-label font-weight-bold">Payment Method *</label>
              <select name="payment_method" id="edit_pay_method" class="form-control" required>
                <option value="cash">Cash</option>
                <option value="bank">Bank Transfer / Cheque</option>
              </select>
            </div>
            <div class="col-md-12 form-group" id="edit_bank_div" style="display:none;">
              <label class="form-label font-weight-bold">Bank Account *</label>
              <select name="bank_account_id" id="edit_bank_account_id" class="form-control">
                <option value="">-- Select Bank Account --</option>
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'] . ' (' . $ba['bank_name'] . ')')?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-12 form-group">
              <label class="form-label font-weight-bold">Description / Notes</label>
              <textarea name="description" id="edit_description" class="form-control" rows="2" placeholder="Detail notes..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
$(document).ready(function(){
  $('#payMethod').change(function(){ $('#bankDiv').toggle(this.value === 'bank'); });
  $('#edit_pay_method').change(function(){ $('#edit_bank_div').toggle(this.value === 'bank'); });

  $('#expenseSearch').on('keyup', function(){
    var q = $(this).val().toLowerCase();
    $('#expenseTable tbody tr').each(function(){
      $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
    });
  });

  $(document).on('click', '.btn-edit-expense', function(){
    var id = $(this).data('id');
    var bill = $(this).data('bill');
    var date = $(this).data('date');
    var cat = $(this).data('cat');
    var vendor = $(this).data('vendor');
    var amount = $(this).data('amount');
    var method = $(this).data('method');
    var bank = $(this).data('bank');
    var desc = $(this).data('desc');

    $('#edit_exp_id').val(id);
    $('#edit_bill_no').val(bill);
    $('#edit_expense_date').val(date);
    $('#edit_category_id').val(cat);
    $('#edit_vendor_name').val(vendor);
    $('#edit_amount').val(amount);
    $('#edit_pay_method').val(method);
    $('#edit_bank_account_id').val(bank);
    $('#edit_description').val(desc);

    $('#edit_bank_div').toggle(method === 'bank');
    $('#editExpenseModal').modal('show');
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>