<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Bank Book';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// ── Action: Add Bank Account ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_account') {
    $account_name    = trim($_POST['account_name'] ?? '');
    $bank_name       = trim($_POST['bank_name'] ?? '');
    $account_no      = trim($_POST['account_no'] ?? '');
    $account_type    = in_array($_POST['account_type'] ?? '', ['current', 'savings', 'loan']) ? $_POST['account_type'] : 'current';
    $branch_code     = trim($_POST['branch_code'] ?? '');
    $opening_balance = (float)($_POST['opening_balance'] ?? 0);
    if ($opening_balance < 0) $opening_balance = 0;
    $opening_date    = $_POST['opening_date'] ?: date('Y-m-d');

    if ($account_name === '' || $bank_name === '') {
        redirect('index.php', 'Account Title and Bank Name are required', 'error');
    }

    $aid = insert('bank_accounts', [
        'account_name'    => $account_name,
        'bank_name'       => $bank_name,
        'account_no'      => $account_no,
        'account_type'    => $account_type,
        'branch_code'     => $branch_code,
        'opening_balance' => $opening_balance,
        'opening_date'    => $opening_date,
        'current_balance' => $opening_balance,
        'status'          => 1,
        'created_at'      => date('Y-m-d'),
        'updated_at'      => date('Y-m-d'),
    ]);

    logActivity($pdo, 'create', 'bank_account', $aid, 'Added bank account: ' . $account_name . ' (' . $bank_name . ')');
    redirect('index.php', 'Bank account added successfully: ' . $account_name);
}

// ── Action: Edit Bank Account ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_account') {
    $aid          = (int)($_POST['account_id'] ?? 0);
    $account_name = trim($_POST['account_name'] ?? '');
    $bank_name    = trim($_POST['bank_name'] ?? '');
    $account_no   = trim($_POST['account_no'] ?? '');
    $account_type = in_array($_POST['account_type'] ?? '', ['current', 'savings', 'loan']) ? $_POST['account_type'] : 'current';
    $branch_code  = trim($_POST['branch_code'] ?? '');
    $status       = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    if (!$aid || $account_name === '' || $bank_name === '') {
        redirect('index.php', 'Account Title and Bank Name are required', 'error');
    }

    update('bank_accounts', [
        'account_name' => $account_name,
        'bank_name'    => $bank_name,
        'account_no'   => $account_no,
        'account_type' => $account_type,
        'branch_code'  => $branch_code,
        'status'       => $status,
        'updated_at'   => date('Y-m-d'),
    ], $aid);

    logActivity($pdo, 'edit', 'bank_account', $aid, 'Updated bank account: ' . $account_name);
    redirect('index.php', 'Bank account updated successfully');
}

// ── Action: Delete Bank Account ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    $aid = (int)($_POST['account_id'] ?? 0);
    $txCount = $pdo->prepare("SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id = ?");
    $txCount->execute([$aid]);
    if ($txCount->fetchColumn() > 0) {
        redirect('index.php', 'Cannot delete account with existing transactions. You can set its status to Inactive instead.', 'error');
    }
    $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?")->execute([$aid]);
    logActivity($pdo, 'delete', 'bank_account', $aid, 'Deleted bank account id ' . $aid);
    redirect('index.php', 'Bank account deleted');
}

// ── Action: Update Opening Balance ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bank_opening'])) {
    $pdo->beginTransaction();
    try {
        $input = $_POST['opening'] ?? [];
        $opening_date = $_POST['opening_date'] ?: null;
        $accounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id")->fetchAll();
        foreach ($accounts as $a) {
            $val = array_key_exists($a['id'], $input) ? (float)$input[$a['id']] : (float)$a['opening_balance'];
            if ($val < 0) $val = 0;
            $q = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('deposit','transfer_in') THEN amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN transaction_type IN ('withdrawal','transfer_out') THEN amount ELSE 0 END),0) FROM bank_transactions WHERE bank_account_id = ?");
            $q->execute([$a['id']]);
            $net = (float)$q->fetchColumn();
            $up = $pdo->prepare("UPDATE bank_accounts SET opening_balance = ?, opening_date = ?, current_balance = ?, updated_at = ? WHERE id = ?");
            $up->execute([$val, $opening_date, $val + $net, date('Y-m-d'), $a['id']]);
        }
        logActivity($pdo, 'opening', 'bank', null, 'Updated bank opening balance');
        $pdo->commit();
        redirect('index.php', 'Bank opening balance updated');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

// Accounts with balances
$accounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id")->fetchAll();

// Date filter
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$conds = [];
$params = [];
if ($from !== '') { $conds[] = "bt.transaction_date >= ?"; $params[] = $from; }
if ($to !== '') { $conds[] = "bt.transaction_date <= ?"; $params[] = $to; }
$where = $conds ? (" WHERE " . implode(' AND ', $conds)) : '';

$today = date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$month_first = date('Y-m-01');
$month_last = date('Y-m-t');

// Transactions
$stmt = $pdo->prepare("SELECT bt.*, ba.account_name, ba.bank_name FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id" . $where . " ORDER BY bt.id DESC");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type = 'deposit' AND transaction_date >= ? AND transaction_date <= ?");
$stmt->execute([$from !== '' ? $from : '0000-01-01', $to !== '' ? $to : '9999-12-31']);
$total_deposits = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type = 'withdrawal' AND transaction_date >= ? AND transaction_date <= ?");
$stmt->execute([$from !== '' ? $from : '0000-01-01', $to !== '' ? $to : '9999-12-31']);
$total_withdrawals = $stmt->fetchColumn();

$total_bank = $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM bank_accounts WHERE status = 1")->fetchColumn();
$total_opening = $pdo->query("SELECT COALESCE(SUM(opening_balance),0) FROM bank_accounts WHERE status = 1")->fetchColumn();
$latest_opening_date = $pdo->query("SELECT MAX(opening_date) FROM bank_accounts WHERE opening_date IS NOT NULL")->fetchColumn();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 align-items-center d-print-none">
  <div class="col-md-7">
    <form method="get" class="form-inline mb-1">
      <label class="mr-1 small text-muted">From</label>
      <input type="date" name="from" value="<?=htmlspecialchars($from)?>" class="form-control form-control-sm mr-2">
      <label class="mr-1 small text-muted">To</label>
      <input type="date" name="to" value="<?=htmlspecialchars($to)?>" class="form-control form-control-sm mr-2">
      <button type="submit" class="btn btn-sm btn-primary mr-1"><i class="fas fa-filter"></i> Filter</button>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">All</a>
    </form>
  </div>
  <div class="col-md-5 text-md-right">
    <button type="button" class="btn btn-success shadow-sm mr-2" data-toggle="modal" data-target="#addAccountModal">
      <i class="fas fa-plus-circle"></i> Add Account
    </button>
    <button type="button" class="btn btn-primary shadow-sm mr-2" data-toggle="modal" data-target="#openingModal">
      <i class="fas fa-coins"></i> Opening Balance
    </button>
    <button type="button" class="btn btn-outline-secondary shadow-sm" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
  </div>
</div>

<!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <img src="<?= $base_url ?>assets/img/logo.png" alt="ARAB KHEL" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; margin-bottom: 6px; border: 1.5px solid #10b981; background: #fff;">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h4>
  <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">BANK BOOK</h5>
  <?php $b_meta = [];
  if ($from) $b_meta[] = 'From: ' . formatDate($from);
  if ($to) $b_meta[] = 'To: ' . formatDate($to);
  if ($b_meta): ?><div class="mt-1 font-weight-bold"><?=implode(' &nbsp;|&nbsp; ', $b_meta)?></div><?php endif; ?>
  <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
</div>

<!-- Stats Row -->
<div class="row mb-3">
  <div class="col-md-4">
    <div class="card border-left-success shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Deposits</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_deposits)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-left-danger shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Withdrawals</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_withdrawals)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-left-info shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Bank Balance</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_bank)?></div>
      </div>
    </div>
  </div>
</div>

<!-- Merged Single Table -->
<div class="card shadow mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <h6 class="mb-0 text-primary font-weight-bold"><i class="fas fa-university mr-1"></i> Bank Book</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0">
        <thead class="thead-light">
          <tr>
            <th>Date</th>
            <th>Account</th>
            <th>Bank</th>
            <th>Type</th>
            <th>Description</th>
            <th>Reference</th>
            <th class="text-right">Amount</th>
            <th class="text-right d-print-none">Balance</th>
            <th class="text-center d-print-none">Action</th>
          </tr>
        </thead>
        <tbody>
          <!-- Opening Balance row -->
          <tr class="table-light font-weight-bold">
            <td><?= $latest_opening_date ? formatDate($latest_opening_date) : '-' ?></td>
            <td colspan="2"><span class="text-muted">All Accounts</span></td>
            <td><span class="badge badge-secondary">Opening Balance</span></td>
            <td>Opening balance (total)</td>
            <td><span class="text-muted">—</span></td>
            <td class="text-right">PKR <?=formatCurrency($total_opening)?></td>
            <td class="text-right d-print-none">—</td>
            <td class="text-center d-print-none">
              <button type="button" class="btn btn-xs btn-outline-primary" data-toggle="modal" data-target="#openingModal" title="Edit Opening Balance">
                <i class="fas fa-edit"></i>
              </button>
            </td>
          </tr>
          <?php
            // Build per-account running balance map
            $acc_balance = [];
            foreach ($accounts as $a) {
              $acc_balance[$a['id']] = (float)$a['opening_balance'];
            }
            // Re-fetch transactions ordered ASC for running balance calc, display in DESC
            $all_txns_asc = $pdo->prepare("SELECT bt.*, ba.account_name, ba.bank_name, ba.current_balance FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id ORDER BY bt.transaction_date ASC, bt.id ASC");
            $all_txns_asc->execute();
            $txns_asc = $all_txns_asc->fetchAll();
            // Build running balance per transaction
            $txn_balance = [];
            $bal_map = $acc_balance;
            foreach ($txns_asc as $t) {
              $aid = $t['bank_account_id'];
              if (!isset($bal_map[$aid])) $bal_map[$aid] = 0;
              if (in_array($t['transaction_type'], ['deposit','transfer_in'])) {
                $bal_map[$aid] += (float)$t['amount'];
              } else {
                $bal_map[$aid] -= (float)$t['amount'];
              }
              $txn_balance[$t['id']] = $bal_map[$aid];
            }
          ?>
          <?php foreach ($transactions as $t): ?>
          <?php
            // Apply date filter display
            $in_range = true;
            if ($from && $t['transaction_date'] < $from) $in_range = false;
            if ($to   && $t['transaction_date'] > $to)   $in_range = false;
            if (!$in_range) continue;
          ?>
          <tr>
            <td><?=formatDate($t['transaction_date'])?></td>
            <td class="font-weight-bold"><?=htmlspecialchars($t['account_name'] ?? '-')?></td>
            <td><?=htmlspecialchars($t['bank_name'] ?? '-')?></td>
            <td>
              <?php
                $type = $t['transaction_type'];
                if (in_array($type, ['deposit','transfer_in']))
                  echo '<span class="badge badge-success">' . ucfirst(str_replace('_',' ',$type)) . '</span>';
                else
                  echo '<span class="badge badge-danger">' . ucfirst(str_replace('_',' ',$type)) . '</span>';
              ?>
            </td>
            <td><?=htmlspecialchars($t['description'] ?? '-')?></td>
            <td><small class="text-muted"><?=htmlspecialchars($t['reference_type'] ?? '—')?></small></td>
            <td class="text-right font-weight-bold <?= in_array($t['transaction_type'], ['deposit','transfer_in']) ? 'text-success' : 'text-danger' ?>">
              <?= in_array($t['transaction_type'], ['deposit','transfer_in']) ? '+' : '-' ?> PKR <?=formatCurrency($t['amount'])?>
            </td>
            <td class="text-right d-print-none text-primary font-weight-bold">
              PKR <?= formatCurrency($txn_balance[$t['id']] ?? 0) ?>
            </td>
            <td class="text-center d-print-none">—</td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($transactions)): ?>
          <tr><td colspan="9" class="text-center text-muted py-3">No bank transactions yet</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="font-weight-bold table-active">
          <tr>
            <td colspan="6" class="text-right">Current Total Bank Balance:</td>
            <td class="text-right text-primary">PKR <?=formatCurrency($total_bank)?></td>
            <td class="d-print-none"></td>
            <td class="d-print-none"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<!-- Add Bank Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1" role="dialog" aria-labelledby="addAccountModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="add_account">
        <div class="modal-header">
          <h5 class="modal-title font-weight-bold" id="addAccountModalLabel"><i class="fas fa-university text-success mr-2"></i> Add New Bank Account</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Account Title / Name <span class="text-danger">*</span></label>
              <input type="text" name="account_name" class="form-control" placeholder="e.g. Main Business Account" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Bank Name <span class="text-danger">*</span></label>
              <input type="text" name="bank_name" class="form-control" placeholder="e.g. Meezan Bank, HBL, MCB" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Account Number</label>
              <input type="text" name="account_no" class="form-control" placeholder="e.g. 0101-1234567890">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Account Type</label>
              <select name="account_type" class="form-control">
                <option value="current" selected>Current</option>
                <option value="savings">Savings</option>
                <option value="loan">Loan</option>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Branch Code / Name</label>
              <input type="text" name="branch_code" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Opening Balance (PKR)</label>
              <input type="number" step="0.01" min="0" name="opening_balance" class="form-control" value="0.00">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Opening Date</label>
              <input type="date" name="opening_date" class="form-control datepicker" value="<?=date('Y-m-d')?>">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
          <button type="submit" class="btn btn-success px-4"><i class="fas fa-save mr-1"></i> Save Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Bank Account Modal -->
<div class="modal fade" id="editAccountModal" tabindex="-1" role="dialog" aria-labelledby="editAccountModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="edit_account">
        <input type="hidden" name="account_id" id="edit_account_id">
        <div class="modal-header">
          <h5 class="modal-title font-weight-bold" id="editAccountModalLabel"><i class="fas fa-edit text-warning mr-2"></i> Edit Bank Account</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Account Title / Name <span class="text-danger">*</span></label>
              <input type="text" name="account_name" id="edit_account_name" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Bank Name <span class="text-danger">*</span></label>
              <input type="text" name="bank_name" id="edit_bank_name" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Account Number</label>
              <input type="text" name="account_no" id="edit_account_no" class="form-control">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Account Type</label>
              <select name="account_type" id="edit_account_type" class="form-control">
                <option value="current">Current</option>
                <option value="savings">Savings</option>
                <option value="loan">Loan</option>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Branch Code / Name</label>
              <input type="text" name="branch_code" id="edit_branch_code" class="form-control">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label font-weight-bold">Status</label>
              <select name="status" id="edit_status" class="form-control">
                <option value="1">Active</option>
                <option value="0">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save mr-1"></i> Update Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Opening Balance Modal -->
<div class="modal fade" id="openingModal" tabindex="-1" role="dialog" aria-labelledby="openingModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="save_bank_opening" value="1">
        <div class="modal-header">
          <h5 class="modal-title font-weight-bold" id="openingModalLabel"><i class="fas fa-coins text-primary mr-2"></i> Bank Opening Balance</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label font-weight-bold">Date *</label>
            <input type="date" name="opening_date" class="form-control datepicker" value="<?= $accounts ? (isset($accounts[0]['opening_date']) && $accounts[0]['opening_date'] ? $accounts[0]['opening_date'] : date('Y-m-d')) : date('Y-m-d') ?>" required>
          </div>
          <div class="table-responsive">
            <table class="table table-bordered">
              <thead class="thead-light"><tr><th>Account</th><th style="width:240px;">Opening Balance (PKR)</th></tr></thead>
              <tbody>
                <?php foreach ($accounts as $a): ?>
                <tr>
                  <td>
                    <strong><?=htmlspecialchars($a['account_name'])?></strong><br>
                    <small class="text-muted"><?=htmlspecialchars($a['bank_name'])?> · <?=htmlspecialchars($a['account_no'])?></small>
                  </td>
                  <td><input type="number" step="0.01" min="0" class="form-control" name="opening[<?=$a['id']?>]" value="<?=(float)$a['opening_balance']?>"></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!count($accounts)): ?>
                <tr><td colspan="2" class="text-center text-muted py-3">No bank accounts yet. Add accounts first.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <small class="text-muted">Current balance auto-recalculates = opening + deposits - withdrawals.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="fas fa-check mr-1"></i> Save Balances</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  $(document).on('click', '.btn-edit-account', function(){
    $('#edit_account_id').val($(this).data('id'));
    $('#edit_account_name').val($(this).data('name'));
    $('#edit_bank_name').val($(this).data('bank'));
    $('#edit_account_no').val($(this).data('no'));
    $('#edit_account_type').val($(this).data('type'));
    $('#edit_branch_code').val($(this).data('branch'));
    $('#edit_status').val($(this).data('status'));
    $('#editAccountModal').modal('show');
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>