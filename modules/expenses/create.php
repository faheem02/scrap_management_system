<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'New Expense';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$categories = $pdo->query("SELECT id, name FROM expense_categories WHERE status = 1 ORDER BY name")->fetchAll();
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount       = (float)($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?: date('Y-m-d');
    $category_id  = $_POST['category_id'] ?: null;
    $method       = $_POST['payment_method'] ?: 'cash';
    $bank_id      = $method == 'bank' ? ($_POST['bank_account_id'] ?: null) : null;
    $description  = trim($_POST['description'] ?? '');
    $vendor       = trim($_POST['vendor_name'] ?? '');
    $bill_no      = trim($_POST['bill_no'] ?? '');

    if ($amount <= 0) {
        redirect('create.php', 'Please enter a valid expense amount', 'error');
    }

    $pdo->beginTransaction();
    try {
        $eid = insert('expenses', [
            'category_id'     => $category_id,
            'expense_date'    => $expense_date,
            'amount'          => $amount,
            'description'     => $description,
            'vendor_name'     => $vendor,
            'bill_no'         => $bill_no,
            'payment_method'  => $method,
            'bank_account_id' => $bank_id,
            'branch_id'       => $_SESSION['branch_id'] ?? null,
            'created_by'      => $_SESSION['user_id'],
            'created_at'      => date('Y-m-d'),
        ]);

        $desc = 'Expense: ' . ($description ?: ($bill_no ? "Bill #{$bill_no}" : 'Expense'));
        if ($method == 'bank') {
            recordBankOutflow($pdo, $expense_date, $amount, $desc, 'expense', $eid, $_SESSION['user_id'], $bank_id);
        } else {
            recordCashOutflow($pdo, $expense_date, $amount, $desc, 'expense', $eid, $_SESSION['user_id']);
        }

        $pdo->commit();
        logActivity($pdo, 'create', 'expense', $eid, 'Recorded expense ' . ($bill_no ? "Bill #{$bill_no} " : '') . 'PKR ' . $amount);
        redirect('index.php', 'Expense saved successfully! ' . ($bill_no ? "Bill No: {$bill_no} | " : '') . 'Amount: PKR ' . formatCurrency($amount));
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('create.php', 'Error saving expense: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8 col-md-10">
    <div class="card shadow">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 text-danger font-weight-bold"><i class="fas fa-plus-circle mr-1"></i> New Expense</h6>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-list"></i> All Expenses</a>
      </div>
      <div class="card-body">
        <form method="post" id="expenseForm" autocomplete="off">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Amount (PKR) <span class="text-danger">*</span></label>
              <input type="number" name="amount" id="amount" step="0.01" min="0" class="form-control font-weight-bold" required placeholder="0.00" autofocus>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Expense Date <span class="text-danger">*</span></label>
              <input type="date" name="expense_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Bill / Invoice No</label>
              <input type="text" name="bill_no" id="bill_no" class="form-control" placeholder="e.g. 00235">
              <small class="text-muted">Vendor bill number ya receipt reference</small>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Category</label>
              <select name="category_id" class="form-control">
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Payment Method</label>
              <select name="payment_method" id="payMethod" class="form-control">
                <option value="cash" selected>Cash</option>
                <option value="bank">Bank</option>
              </select>
            </div>
            <div class="col-md-6 mb-3" id="bankDiv" style="display:none;">
              <label class="form-label font-weight-bold">Bank Account</label>
              <select name="bank_account_id" class="form-control">
                <?php foreach ($bank_accounts as $ba): ?>
                <option value="<?=$ba['id']?>"><?=htmlspecialchars($ba['account_name'])?> — <?=htmlspecialchars($ba['bank_name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Vendor / Payee</label>
              <input type="text" name="vendor_name" class="form-control" placeholder="e.g. Electric Company / Petrol Pump / Ali">
            </div>
            <div class="col-md-12 mb-3">
              <label class="form-label font-weight-bold">Description</label>
              <textarea name="description" class="form-control" rows="2" placeholder="e.g. Shop rent for the month / Generator fuel"></textarea>
            </div>
          </div>
          <div class="d-flex justify-content-between align-items-center mt-3 border-top pt-3">
            <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to List</a>
            <button type="submit" class="btn btn-danger px-4 py-2 font-weight-bold"><i class="fas fa-save mr-1"></i> Save Expense</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  $('#payMethod').change(function(){
    $('#bankDiv').toggle(this.value === 'bank');
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
