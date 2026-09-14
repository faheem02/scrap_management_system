<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Bank Book';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// ── Action: Add Bank Account ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_account') {
    $account_name    = trim($_POST['account_name'] ?? '');
    $bank_name       = trim($_POST['bank_name'] ?? '');
    $account_no      = trim($_POST['account_no'] ?? '');
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
        'account_type'    => 'current',
        'branch_code'     => '',
        'opening_balance' => $opening_balance,
        'opening_date'    => $opening_date,
        'current_balance' => $opening_balance,
        'status'          => 1,
        'created_at'      => date('Y-m-d'),
        'updated_at'      => date('Y-m-d'),
    ]);

    logActivity($pdo, 'create', 'bank_account', $aid, 'Added bank account: ' . $account_name . ' (' . $bank_name . ')');
    redirect('index.php?account_id=' . $aid, 'Bank account added successfully: ' . $account_name);
}

// ── Action: Edit Bank Account ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_account') {
    $aid          = (int)($_POST['account_id'] ?? 0);
    $account_name = trim($_POST['account_name'] ?? '');
    $bank_name    = trim($_POST['bank_name'] ?? '');
    $account_no   = trim($_POST['account_no'] ?? '');
    $status       = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    if (!$aid || $account_name === '' || $bank_name === '') {
        redirect('index.php', 'Account Title and Bank Name are required', 'error');
    }

    update('bank_accounts', [
        'account_name' => $account_name,
        'bank_name'    => $bank_name,
        'account_no'   => $account_no,
        'status'       => $status,
        'updated_at'   => date('Y-m-d'),
    ], $aid);

    logActivity($pdo, 'edit', 'bank_account', $aid, 'Updated bank account: ' . $account_name);
    redirect('index.php?account_id=' . $aid, 'Bank account updated successfully');
}

// ── Action: Delete Bank Account ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    $aid = (int)($_POST['account_id'] ?? 0);
    $txCount = $pdo->prepare("SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id = ?");
    $txCount->execute([$aid]);
    if ($txCount->fetchColumn() > 0) {
        redirect('index.php' . ($aid ? '?account_id=' . $aid : ''), 'Cannot delete account with existing transactions. You can set its status to Inactive instead.', 'error');
    }
    $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?")->execute([$aid]);
    logActivity($pdo, 'delete', 'bank_account', $aid, 'Deleted bank account id ' . $aid);
    redirect('index.php', 'Bank account deleted');
}

// ── Action: Delete Bank Transaction ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_bank_transaction') {
    $tx_id = (int)($_POST['transaction_id'] ?? 0);
    $tx = getById('bank_transactions', $tx_id);
    if ($tx) {
        $aid = (int)$tx['bank_account_id'];
        $pdo->beginTransaction();
        try {
            if (in_array($tx['transaction_type'], ['deposit', 'transfer_in'])) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$tx['amount'], $aid]);
            } else {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$tx['amount'], $aid]);
            }
            $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$tx_id]);
            logActivity($pdo, 'delete', 'bank_transaction', $tx_id, 'Deleted bank transaction #' . $tx_id);
            $pdo->commit();
            redirect('index.php?account_id=' . $aid, 'Bank transaction deleted successfully');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect('index.php?account_id=' . $aid, 'Error deleting transaction: ' . $e->getMessage(), 'error');
        }
    }
    redirect('index.php', 'Transaction not found', 'error');
}

// ── Action: Edit Bank Transaction ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_bank_transaction') {
    $tx_id = (int)($_POST['transaction_id'] ?? 0);
    $tx = getById('bank_transactions', $tx_id);
    if ($tx) {
        $aid = (int)$tx['bank_account_id'];
        $new_date = $_POST['transaction_date'] ?: $tx['transaction_date'];
        $new_type = in_array($_POST['transaction_type'] ?? '', ['deposit','withdrawal','transfer_in','transfer_out']) ? $_POST['transaction_type'] : $tx['transaction_type'];
        $new_amount = (float)($_POST['amount'] ?? 0);
        $new_desc = trim($_POST['description'] ?? '');
        if ($new_amount <= 0) {
            redirect('index.php?account_id=' . $aid, 'Amount must be greater than 0', 'error');
        }

        $pdo->beginTransaction();
        try {
            if (in_array($tx['transaction_type'], ['deposit', 'transfer_in'])) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$tx['amount'], $aid]);
            } else {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$tx['amount'], $aid]);
            }
            if (in_array($new_type, ['deposit', 'transfer_in'])) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$new_amount, $aid]);
            } else {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$new_amount, $aid]);
            }
            $pdo->prepare("UPDATE bank_transactions SET transaction_date = ?, transaction_type = ?, amount = ?, description = ? WHERE id = ?")
                ->execute([$new_date, $new_type, $new_amount, $new_desc, $tx_id]);
            logActivity($pdo, 'edit', 'bank_transaction', $tx_id, 'Updated bank transaction #' . $tx_id);
            $pdo->commit();
            redirect('index.php?account_id=' . $aid, 'Bank transaction updated successfully');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect('index.php?account_id=' . $aid, 'Error updating transaction: ' . $e->getMessage(), 'error');
        }
    }
    redirect('index.php', 'Transaction not found', 'error');
}

// ── Action: Delete / Reset Opening Balance ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_opening') {
    $aid = (int)($_POST['account_id'] ?? 0);
    if ($aid > 0) {
        $acc = getById('bank_accounts', $aid);
        if ($acc) {
            $old_open = (float)$acc['opening_balance'];
            $pdo->prepare("UPDATE bank_accounts SET opening_balance = 0, current_balance = current_balance - ?, updated_at = ? WHERE id = ?")
                ->execute([$old_open, date('Y-m-d'), $aid]);
            logActivity($pdo, 'edit', 'bank_account', $aid, 'Reset opening balance to 0 for account ' . $acc['account_name']);
            redirect('index.php?account_id=' . $aid, 'Opening balance reset to 0');
        }
    }
    redirect('index.php', 'Account not found', 'error');
}

// ── Action: Update Opening Balance ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_bank_opening'])) {
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
        $ret_acc = (int)($_POST['return_account_id'] ?? 0);
        redirect($ret_acc > 0 ? 'index.php?account_id=' . $ret_acc : 'index.php', 'Bank opening balance updated');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

// ── Action: Quick Transfer from Bankbook ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_transfer') {
    $aid = (int)($_POST['from_bank_id'] ?? 0);
    try {
        $type = $_POST['transfer_type'] ?? 'bank_to_bank';
        if (!in_array($type, ['bank_to_bank', 'bank_to_cash'], true)) {
            $type = 'bank_to_bank';
        }
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
        $date = !empty($_POST['transfer_date']) ? $_POST['transfer_date'] : date('Y-m-d');
        $ref = trim($_POST['reference_no'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $user_id = $_SESSION['user_id'] ?? 1;

        $from_acc = getById('bank_accounts', $aid);
        if (!$from_acc) throw new Exception('Select a source bank account.');
        $from_label = $from_acc['bank_name'] . ' - ' . $from_acc['account_name'];

        $pdo->beginTransaction();

        if ($type === 'bank_to_bank') {
            $to_bank_id = (int)($_POST['to_bank_id'] ?? 0);
            if (!$to_bank_id) {
                throw new Exception("Please select a destination bank account.");
            }
            if ($aid === $to_bank_id) {
                throw new Exception("Source and destination bank accounts cannot be the same.");
            }
            $to_acc = getById('bank_accounts', $to_bank_id);
            $to_label = $to_acc ? ($to_acc['bank_name'] . ' - ' . $to_acc['account_name']) : 'Bank';
            recordBankOutflow($pdo, $date, $amount, 'Transfer to Bank: ' . $to_label . ($ref ? ' [Ref: ' . $ref . ']' : '') . ($desc ? ' - ' . $desc : ''), 'transfer', null, $user_id, $aid);
            recordBankInflow($pdo, $date, $amount, 'Transfer from Bank: ' . $from_label . ($ref ? ' [Ref: ' . $ref . ']' : '') . ($desc ? ' - ' . $desc : ''), 'transfer', null, $user_id, $to_bank_id);
            logActivity($pdo, 'transfer', 'bank_to_bank', null, 'Bank to Bank PKR ' . $amount . ' (' . $from_label . ' -> ' . $to_label . ')');
        } else {
            recordBankOutflow($pdo, $date, $amount, 'Cash withdrawal to Cash Book' . ($ref ? ' [Ref: ' . $ref . ']' : '') . ($desc ? ' - ' . $desc : ''), 'transfer', null, $user_id, $aid);
            recordCashInflow($pdo, $date, $amount, 'Cash received from Bank: ' . $from_label . ($ref ? ' [Ref: ' . $ref . ']' : '') . ($desc ? ' - ' . $desc : ''), 'transfer', null, $user_id);
            logActivity($pdo, 'transfer', 'bank_to_cash', null, 'Bank to Cash PKR ' . $amount . ' (' . $from_label . ')');
        }

        $pdo->commit();
        redirect('index.php' . ($aid ? '?account_id=' . $aid : ''), 'Transfer completed successfully!');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        redirect('index.php' . ($aid ? '?account_id=' . $aid : ''), 'Error: ' . $e->getMessage(), 'error');
    }
}

// Accounts list
$accounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id")->fetchAll();

// Bank account selection: default to first bank account if none specified
$selected_account_id = (int)($_GET['account_id'] ?? 0);
$current_account = null;
if ($selected_account_id > 0) {
    foreach ($accounts as $acc) {
        if ($acc['id'] == $selected_account_id) {
            $current_account = $acc;
            break;
        }
    }
}
if (!$current_account && count($accounts) > 0) {
    $current_account = $accounts[0];
    $selected_account_id = (int)$current_account['id'];
}

// Date filter
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$conds = [];
$params = [];
if ($selected_account_id > 0) {
    $conds[] = "bt.bank_account_id = ?";
    $params[] = $selected_account_id;
}
if ($from !== '') { $conds[] = "bt.transaction_date >= ?"; $params[] = $from; }
if ($to !== '') { $conds[] = "bt.transaction_date <= ?"; $params[] = $to; }
$where = $conds ? (" WHERE " . implode(' AND ', $conds)) : '';

$today = date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$month_first = date('Y-m-01');
$month_last = date('Y-m-t');

// Transactions for currently selected bank
$transactions = [];
if ($selected_account_id > 0) {
    $stmt = $pdo->prepare("SELECT bt.*, ba.account_name, ba.bank_name FROM bank_transactions bt LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id" . $where . " ORDER BY bt.transaction_date ASC, bt.id ASC");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
}

// Deposits and Withdrawals for selected account & range
$total_deposits = 0.0;
$total_withdrawals = 0.0;
if ($selected_account_id > 0) {
    $dep_sql = "SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type IN ('deposit','transfer_in') AND transaction_date >= ? AND transaction_date <= ? AND bank_account_id = ?";
    $dep_params = [$from !== '' ? $from : '0000-01-01', $to !== '' ? $to : '9999-12-31', $selected_account_id];
    $stmt = $pdo->prepare($dep_sql);
    $stmt->execute($dep_params);
    $total_deposits = (float)$stmt->fetchColumn();

    $wth_sql = "SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE transaction_type IN ('withdrawal','transfer_out') AND transaction_date >= ? AND transaction_date <= ? AND bank_account_id = ?";
    $wth_params = [$from !== '' ? $from : '0000-01-01', $to !== '' ? $to : '9999-12-31', $selected_account_id];
    $stmt = $pdo->prepare($wth_sql);
    $stmt->execute($wth_params);
    $total_withdrawals = (float)$stmt->fetchColumn();
}

// Per-account stats (opening, deposits, withdrawals, closing) for overview table
$acc_stats = [];
foreach ($accounts as $a) {
    $aid = $a['id'];
    $a_init_open = (float)$a['opening_balance'];

    if ($from !== '') {
        $p_dep = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE bank_account_id = ? AND transaction_type IN ('deposit','transfer_in') AND transaction_date < ?");
        $p_dep->execute([$aid, $from]);
        $prior_d = (float)$p_dep->fetchColumn();

        $p_wth = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE bank_account_id = ? AND transaction_type IN ('withdrawal','transfer_out') AND transaction_date < ?");
        $p_wth->execute([$aid, $from]);
        $prior_w = (float)$p_wth->fetchColumn();

        $a_open = $a_init_open + $prior_d - $prior_w;
    } else {
        $a_open = $a_init_open;
    }

    $d_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE bank_account_id = ? AND transaction_type IN ('deposit','transfer_in') AND transaction_date >= ? AND transaction_date <= ?");
    $d_stmt->execute([$aid, $from !== '' ? $from : '0000-01-01', $to !== '' ? $to : '9999-12-31']);
    $a_dep = (float)$d_stmt->fetchColumn();

    $w_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE bank_account_id = ? AND transaction_type IN ('withdrawal','transfer_out') AND transaction_date >= ? AND transaction_date <= ?");
    $w_stmt->execute([$aid, $from !== '' ? $from : '0000-01-01', $to !== '' ? $to : '9999-12-31']);
    $a_wth = (float)$w_stmt->fetchColumn();

    $a_close = $a_open + $a_dep - $a_wth;

    $acc_stats[$aid] = [
        'opening'     => $a_open,
        'deposits'    => $a_dep,
        'withdrawals' => $a_wth,
        'closing'     => $a_close,
    ];
}

// Calculate Opening Balance and Closing Balance for current selected bank view
if ($current_account) {
    $view_opening = $acc_stats[$selected_account_id]['opening'] ?? (float)$current_account['opening_balance'];
    $view_closing = $acc_stats[$selected_account_id]['closing'] ?? (float)$current_account['current_balance'];
    $view_opening_date = $current_account['opening_date'] ?: null;
} else {
    $view_opening = 0.0;
    $view_closing = 0.0;
    $view_opening_date = null;
}

// Running balance calculation for selected bank
$txn_balance = [];
if ($selected_account_id > 0 && $current_account) {
    $q_asc = $pdo->prepare("SELECT * FROM bank_transactions WHERE bank_account_id = ? ORDER BY transaction_date ASC, id ASC");
    $q_asc->execute([$selected_account_id]);
    $run = (float)$current_account['opening_balance'];
    foreach ($q_asc->fetchAll() as $tx) {
        if (in_array($tx['transaction_type'], ['deposit','transfer_in'])) {
            $run += (float)$tx['amount'];
        } else {
            $run -= (float)$tx['amount'];
        }
        $txn_balance[$tx['id']] = $run;
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Top Action Bar (Title on left, Actions on right) -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 d-print-none">
  <div>
    <h4 class="mb-0 font-weight-bold text-dark">
      <i class="fas fa-university text-primary mr-2"></i> Bank Book
    </h4>
    <small class="text-muted">Bank Ledger Transactions & Account Transfers</small>
  </div>
  <div class="mt-2 mt-md-0">
    <button type="button" class="btn btn-sm btn-success mr-1" data-toggle="modal" data-target="#addAccountModal">
      <i class="fas fa-plus-circle mr-1"></i> Add Account
    </button>
    <button type="button" class="btn btn-sm btn-info mr-1" data-toggle="modal" data-target="#bankTransferModal">
      <i class="fas fa-exchange-alt mr-1"></i> Transfer
    </button>
    <button type="button" class="btn btn-sm btn-primary mr-1" data-toggle="modal" data-target="#openingModal">
      <i class="fas fa-coins mr-1"></i> Opening Balance
    </button>
    <div class="btn-group">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="fas fa-print mr-1"></i> Print / PDF
      </button>
      <div class="dropdown-menu dropdown-menu-right shadow border-0 py-2">
        <a class="dropdown-item py-2" href="javascript:void(0)" onclick="window.print()">
          <i class="fas fa-print text-primary mr-2"></i> <strong>Print</strong>
          <small class="d-block text-muted">Direct printer / paper print</small>
        </a>
        <div class="dropdown-divider my-1"></div>
        <a class="dropdown-item py-2" href="javascript:void(0)" onclick="downloadPDF('bankbookPrintArea', 'Bank_Book_<?= preg_replace('/[^A-Za-z0-9_-]/', '_', ($current_account['bank_name'] ?? 'Bank') . '_' . ($current_account['account_name'] ?? '')) ?>_<?= date('Y-m-d') ?>')">
          <i class="fas fa-file-pdf text-danger mr-2"></i> <strong>Download PDF</strong>
          <small class="d-block text-muted">Save as PDF document</small>
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Date & Bank Filter Card (Guaranteed single-line) -->
<div class="card shadow-sm mb-3 d-print-none border-0">
  <div class="card-body py-2 px-3">
    <form method="get" class="form-inline flex-nowrap align-items-center" style="gap: 6px;">
      <label class="mr-1 small text-muted font-weight-bold text-nowrap">Bank:</label>
      <select name="account_id" class="form-control form-control-sm mr-3 font-weight-bold" style="max-width: 260px;" onchange="this.form.submit()">
        <?php foreach ($accounts as $a): ?>
        <option value="<?=$a['id']?>" <?= $selected_account_id == $a['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($a['bank_name']) ?> — <?= htmlspecialchars($a['account_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>

      <label class="mr-1 small text-muted font-weight-bold text-nowrap">From:</label>
      <input type="date" name="from" value="<?=htmlspecialchars($from)?>" class="form-control form-control-sm mr-3" style="width: 140px;">

      <label class="mr-1 small text-muted font-weight-bold text-nowrap">To:</label>
      <input type="date" name="to" value="<?=htmlspecialchars($to)?>" class="form-control form-control-sm mr-3" style="width: 140px;">

      <button type="submit" class="btn btn-sm btn-primary mr-1 text-nowrap"><i class="fas fa-filter mr-1"></i> Filter</button>
      <a href="index.php?account_id=<?= $selected_account_id ?>" class="btn btn-sm btn-outline-secondary text-nowrap">Reset</a>
    </form>
  </div>
</div>


<?php if ($current_account): ?>
<!-- Specific Bank Account Banner Card -->
<div class="card shadow-sm mb-3 border-left-primary">
  <div class="card-body py-2 px-3 d-flex flex-wrap justify-content-between align-items-center">
    <div>
      <h5 class="mb-0 font-weight-bold text-dark">
        <i class="fas fa-university text-primary mr-2"></i> <?= htmlspecialchars($current_account['bank_name']) ?>
        <span class="text-primary font-weight-bold ml-1">— <?= htmlspecialchars($current_account['account_name']) ?></span>
      </h5>
      <div class="small text-muted mt-1">
        <span class="mr-3"><i class="fas fa-hashtag mr-1"></i> A/C No: <strong><?= htmlspecialchars($current_account['account_no'] ?: 'N/A') ?></strong></span>
        <span class="badge badge-<?= $current_account['status'] ? 'success' : 'secondary' ?>"><?= $current_account['status'] ? 'Active' : 'Inactive' ?></span>
      </div>
    </div>
    <div class="d-print-none mt-2 mt-md-0">
      <button type="button" class="btn btn-sm btn-outline-warning btn-edit-account mr-1"
              data-id="<?= $current_account['id'] ?>"
              data-name="<?= htmlspecialchars($current_account['account_name'], ENT_QUOTES) ?>"
              data-bank="<?= htmlspecialchars($current_account['bank_name'], ENT_QUOTES) ?>"
              data-no="<?= htmlspecialchars($current_account['account_no'], ENT_QUOTES) ?>"
              data-status="<?= $current_account['status'] ?>"
              title="Edit Account Details">
        <i class="fas fa-edit mr-1"></i> Edit Account
      </button>
      <form method="post" action="index.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this bank account?');">
        <input type="hidden" name="action" value="delete_account">
        <input type="hidden" name="account_id" value="<?= $current_account['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Account">
          <i class="fas fa-trash mr-1"></i> Delete
        </button>
      </form>
    </div>
  </div>
</div>

<?php
$b_logo_path = dirname(__DIR__, 2) . '/assets/img/logo.png';
$b_logo_data = file_exists($b_logo_path) ? ('data:image/png;base64,' . base64_encode(file_get_contents($b_logo_path))) : (($base_url ?? '') . 'assets/img/logo.png');
?>
<div id="bankbookPrintArea">
<!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <img src="<?= $b_logo_data ?>" alt="ARAB KHEL" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; margin-bottom: 6px; border: 1.5px solid #10b981; background: #fff;">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h4>
  <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">
    BANK BOOK — <?= strtoupper(htmlspecialchars($current_account['bank_name'])) ?> (<?= htmlspecialchars($current_account['account_name']) ?>)
  </h5>
  <div class="small font-weight-bold">
    A/C No: <?= htmlspecialchars($current_account['account_no'] ?: 'N/A') ?>
  </div>
  <?php $b_meta = [];
  if ($from) $b_meta[] = '<span style="white-space:nowrap;">From: ' . formatDate($from) . '</span>';
  if ($to) $b_meta[] = '<span style="white-space:nowrap;">To: ' . formatDate($to) . '</span>';
  if ($b_meta): ?><div class="mt-1 font-weight-bold" style="white-space:nowrap;"><?=implode(' &nbsp;|&nbsp; ', $b_meta)?></div><?php endif; ?>
  <div class="mt-1 small text-muted font-weight-bold" style="white-space:nowrap;">
    Opening Balance: PKR <?=formatCurrency($view_opening)?> &nbsp;|&nbsp;
    Deposits: PKR <?=formatCurrency($total_deposits)?> &nbsp;|&nbsp;
    Withdrawals: PKR <?=formatCurrency($total_withdrawals)?> &nbsp;|&nbsp;
    Closing Balance: PKR <?=formatCurrency($view_closing)?>
  </div>
  <small style="white-space:nowrap;">Printed on <?=formatDate(date('Y-m-d'))?></small>
</div>


<!-- Dedicated Bank Ledger Transactions Table -->
<div class="card shadow mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center py-2">
    <h6 class="mb-0 text-primary font-weight-bold">
      <i class="fas fa-book-open mr-1"></i>
      <?= htmlspecialchars($current_account['bank_name']) ?> (<?= htmlspecialchars($current_account['account_name']) ?>) — Ledger Transactions
    </h6>
    <span class="badge badge-primary font-weight-normal px-2 py-1">Closing Balance: PKR <?=formatCurrency($view_closing)?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0">
        <thead class="thead-light">
          <tr>
            <th style="width: 125px; white-space: nowrap;" class="text-nowrap">Date</th>
            <th>Type</th>
            <th>Description</th>
            <th>Reference</th>
            <th class="text-right text-nowrap" style="width: 140px; white-space: nowrap;">Amount</th>
            <th class="text-right d-print-none text-nowrap" style="width: 140px; white-space: nowrap;">Balance</th>
            <th class="text-center d-print-none" style="width: 100px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <!-- Opening Balance row (Row 1) -->
          <tr class="table-light font-weight-bold">
            <td class="text-nowrap" style="white-space: nowrap;"><?= $view_opening_date ? formatDate($view_opening_date) : ($from ? formatDate($from) : '-') ?></td>
            <td><span class="badge badge-secondary">Opening Balance</span></td>
            <td>Opening balance (<?= htmlspecialchars($current_account['bank_name']) ?> - <?= htmlspecialchars($current_account['account_name']) ?>)</td>
            <td><span class="text-muted">—</span></td>
            <td class="text-right text-nowrap" style="white-space: nowrap;">PKR <?=formatCurrency($view_opening)?></td>
            <td class="text-right d-print-none text-primary font-weight-bold text-nowrap" style="white-space: nowrap;">PKR <?=formatCurrency($view_opening)?></td>
            <td class="text-center text-nowrap d-print-none">
              <button type="button" class="btn btn-xs btn-outline-primary" data-toggle="modal" data-target="#openingModal" title="Edit Opening Balance">
                <i class="fas fa-edit"></i>
              </button>
              <form method="post" action="index.php" class="d-inline" onsubmit="return confirm('Are you sure you want to reset the opening balance to 0?');">
                <input type="hidden" name="action" value="delete_opening">
                <input type="hidden" name="account_id" value="<?= $current_account['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger ml-1" title="Delete / Reset Opening Balance">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php foreach ($transactions as $t): ?>
          <tr>
            <td class="text-nowrap" style="white-space: nowrap;"><?=formatDate($t['transaction_date'])?></td>
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
            <td class="text-right font-weight-bold text-nowrap <?= in_array($t['transaction_type'], ['deposit','transfer_in']) ? 'text-success' : 'text-danger' ?>" style="white-space: nowrap;">
              <?= in_array($t['transaction_type'], ['deposit','transfer_in']) ? '+' : '-' ?> PKR <?=formatCurrency($t['amount'])?>
            </td>
            <td class="text-right d-print-none font-weight-bold text-nowrap <?= ($txn_balance[$t['id']] ?? 0) < 0 ? 'text-danger' : 'text-primary' ?>" style="white-space: nowrap;">
              PKR <?= formatCurrency($txn_balance[$t['id']] ?? 0) ?>
            </td>
            <td class="text-center text-nowrap d-print-none">
              <button type="button" class="btn btn-xs btn-outline-warning btn-edit-txn"
                      data-id="<?= $t['id'] ?>"
                      data-date="<?= $t['transaction_date'] ?>"
                      data-type="<?= $t['transaction_type'] ?>"
                      data-amount="<?= $t['amount'] ?>"
                      data-desc="<?= htmlspecialchars($t['description'] ?? '', ENT_QUOTES) ?>"
                      title="Edit Transaction">
                <i class="fas fa-edit"></i>
              </button>
              <form method="post" action="index.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this bank transaction?');">
                <input type="hidden" name="action" value="delete_bank_transaction">
                <input type="hidden" name="transaction_id" value="<?= $t['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger ml-1" title="Delete Transaction">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($transactions)): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">No transactions found for this bank in selected period</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="font-weight-bold table-light">
          <tr class="text-muted">
            <td colspan="4" class="text-right text-nowrap" style="white-space: nowrap;">Opening Balance (<?= $from ? formatDate($from) : 'Initial' ?>):</td>
            <td class="text-right text-nowrap" style="white-space: nowrap;">PKR <?= formatCurrency($view_opening) ?></td>
            <td colspan="2" class="d-print-none"></td>
          </tr>
          <tr>
            <td colspan="4" class="text-right text-success text-nowrap" style="white-space: nowrap;">Total Deposits (+):</td>
            <td class="text-right text-success text-nowrap" style="white-space: nowrap;">+ PKR <?= formatCurrency($total_deposits) ?></td>
            <td colspan="2" class="d-print-none"></td>
          </tr>
          <tr>
            <td colspan="4" class="text-right text-danger text-nowrap" style="white-space: nowrap;">Total Withdrawals (-):</td>
            <td class="text-right text-danger text-nowrap" style="white-space: nowrap;">- PKR <?= formatCurrency($total_withdrawals) ?></td>
            <td colspan="2" class="d-print-none"></td>
          </tr>
          <tr class="table-active">
            <td colspan="4" class="text-right text-primary h6 mb-0 font-weight-bold text-nowrap" style="white-space: nowrap;">
              <i class="fas fa-coins mr-1"></i> Closing Balance (<?= $to ? formatDate($to) : 'Present' ?>):
            </td>
            <td class="text-right font-weight-bold h6 mb-0 text-nowrap <?= $view_closing < 0 ? 'text-danger' : 'text-primary' ?>" style="white-space: nowrap;">
              PKR <?= formatCurrency($view_closing) ?>
            </td>
            <td class="text-right font-weight-bold h6 mb-0 d-print-none text-nowrap <?= $view_closing < 0 ? 'text-danger' : 'text-primary' ?>" style="white-space: nowrap;">
              PKR <?= formatCurrency($view_closing) ?>
            </td>
            <td class="d-print-none"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>
</div><!-- /#bankbookPrintArea -->
<?php else: ?>
<div class="card shadow text-center py-5">
  <div class="card-body">
    <i class="fas fa-university fa-3x text-muted mb-3"></i>
    <h5 class="font-weight-bold">No Bank Accounts Added Yet</h5>
    <p class="text-muted">Add your bank accounts to start tracking bank-wise opening balance, deposits, withdrawals, and closing balance.</p>
    <button type="button" class="btn btn-success px-4" data-toggle="modal" data-target="#addAccountModal">
      <i class="fas fa-plus-circle mr-1"></i> Add Bank Account
    </button>
  </div>
</div>
<?php endif; ?>

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
            <div class="col-md-12 mb-3">
              <label class="form-label font-weight-bold">Account Number</label>
              <input type="text" name="account_no" class="form-control" placeholder="e.g. 0101-1234567890">
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

<!-- Edit Bank Transaction Modal -->
<div class="modal fade" id="editTxnModal" tabindex="-1" role="dialog" aria-labelledby="editTxnModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="edit_bank_transaction">
        <input type="hidden" name="transaction_id" id="edit_txn_id">
        <div class="modal-header">
          <h5 class="modal-title font-weight-bold" id="editTxnModalLabel"><i class="fas fa-edit text-warning mr-2"></i> Edit Bank Transaction</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group mb-3">
            <label class="form-label font-weight-bold">Date <span class="text-danger">*</span></label>
            <input type="date" name="transaction_date" id="edit_txn_date" class="form-control datepicker" required>
          </div>
          <div class="form-group mb-3">
            <label class="form-label font-weight-bold">Transaction Type <span class="text-danger">*</span></label>
            <select name="transaction_type" id="edit_txn_type" class="form-control font-weight-bold">
              <option value="deposit">Deposit (+)</option>
              <option value="withdrawal">Withdrawal (-)</option>
              <option value="transfer_in">Transfer In (+)</option>
              <option value="transfer_out">Transfer Out (-)</option>
            </select>
          </div>
          <div class="form-group mb-3">
            <label class="form-label font-weight-bold">Amount (PKR) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0.01" name="amount" id="edit_txn_amount" class="form-control" required>
          </div>
          <div class="form-group mb-3">
            <label class="form-label font-weight-bold">Description</label>
            <textarea name="description" id="edit_txn_desc" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save mr-1"></i> Update Transaction</button>
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
        <input type="hidden" name="return_account_id" value="<?= $selected_account_id ?>">
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

<!-- Quick Transfer Modal -->
<div class="modal fade" id="bankTransferModal" tabindex="-1" role="dialog" aria-labelledby="bankTransferModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content border-0 shadow-lg">
      <form method="post" id="bbQuickTransferForm">
        <input type="hidden" name="action" value="quick_transfer">
        <input type="hidden" name="to_type" id="bbToTypeInput" value="bank">
        
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title font-weight-bold" id="bankTransferModalLabel">
            <i class="fas fa-exchange-alt mr-2"></i> Transfer from Bank
          </h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body p-4">
          <!-- Source Bank -->
          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark">Transfer From (Source Bank) <span class="text-danger">*</span></label>
            <select name="from_bank_id" id="bbFromBankSelect" class="form-control" required>
              <?php foreach ($accounts as $ba): ?>
                <option value="<?= $ba['id'] ?>" <?= ($selected_account_id == $ba['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($ba['bank_name'] . ' — ' . $ba['account_name']) ?> (Balance: PKR <?= formatCurrency($ba['current_balance']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Transfer Type Radio -->
          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark mb-1">Transfer Destination <span class="text-danger">*</span></label>
            <div class="row">
              <div class="col-md-6 mb-2">
                <div class="custom-control custom-radio p-2 border rounded bg-light">
                  <input type="radio" id="bbTypeB2B" name="transfer_type" value="bank_to_bank" class="custom-control-input bb-type-radio" checked>
                  <label class="custom-control-label font-weight-bold text-primary cursor-pointer" for="bbTypeB2B">
                    <i class="fas fa-university mr-1"></i> Another Bank Account
                  </label>
                </div>
              </div>
              <div class="col-md-6 mb-2">
                <div class="custom-control custom-radio p-2 border rounded bg-light">
                  <input type="radio" id="bbTypeB2C" name="transfer_type" value="bank_to_cash" class="custom-control-input bb-type-radio">
                  <label class="custom-control-label font-weight-bold text-success cursor-pointer" for="bbTypeB2C">
                    <i class="fas fa-hand-holding-usd mr-1"></i> Cash Book (Cash Inflow)
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- Destination Target Field -->
          <div class="form-group mb-3" id="bbDestBankDiv">
            <label class="font-weight-bold text-dark">Destination Bank Account <span class="text-danger">*</span></label>
            <select name="to_bank_id" id="bbToBankSelect" class="form-control">
              <option value="">-- Select Target Bank Account --</option>
              <?php foreach ($accounts as $ba): ?>
                <option value="<?= $ba['id'] ?>">
                  <?= htmlspecialchars($ba['bank_name'] . ' — ' . $ba['account_name']) ?> (Balance: PKR <?= formatCurrency($ba['current_balance']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group mb-3" id="bbDestCashDiv" style="display: none;">
            <label class="font-weight-bold text-dark">Destination Account</label>
            <div class="form-control bg-light text-success font-weight-bold">
              <i class="fas fa-money-bill-wave mr-1"></i> Main Cash Book (Physical Cash Inflow)
            </div>
          </div>

          <!-- Amount & Date -->
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Amount (PKR) <span class="text-danger">*</span></label>
              <div class="input-group">
                <div class="input-group-prepend">
                  <span class="input-group-text font-weight-bold bg-light">PKR</span>
                </div>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control font-weight-bold text-primary" placeholder="0.00" required>
              </div>
            </div>
            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Transfer Date <span class="text-danger">*</span></label>
              <input type="date" name="transfer_date" value="<?= date('Y-m-d') ?>" class="form-control font-weight-bold" required>
            </div>
          </div>

          <!-- Reference & Remarks -->
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Reference / Cheque #</label>
              <input type="text" name="reference_no" class="form-control" placeholder="Optional Cheque # or Online Ref">
            </div>
            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Description / Remarks</label>
              <input type="text" name="description" class="form-control" placeholder="Purpose or notes...">
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light d-flex justify-content-between">
          <a href="../transactions/transfers.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-list mr-1"></i> View All Transfers & Payments
          </a>
          <div>
            <button type="button" class="btn btn-secondary shadow-sm mr-1" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-info shadow-sm px-4">
              <i class="fas fa-check-circle mr-1"></i> Complete Transfer
            </button>
          </div>
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
    $('#edit_status').val($(this).data('status'));
    $('#editAccountModal').modal('show');
  });

  $(document).on('click', '.btn-edit-txn', function(){
    $('#edit_txn_id').val($(this).data('id'));
    $('#edit_txn_date').val($(this).data('date'));
    $('#edit_txn_type').val($(this).data('type'));
    $('#edit_txn_amount').val($(this).data('amount'));
    $('#edit_txn_desc').val($(this).data('desc'));
    $('#editTxnModal').modal('show');
  });

  // Quick Transfer Modal field switcher
  const bbRadios = document.querySelectorAll('.bb-type-radio');
  const bbDestBankDiv = document.getElementById('bbDestBankDiv');
  const bbDestCashDiv = document.getElementById('bbDestCashDiv');
  const bbToBankSelect = document.getElementById('bbToBankSelect');
  const bbToTypeInput = document.getElementById('bbToTypeInput');

  function updateBbTransferFields() {
    const checkedRadio = document.querySelector('input[name="transfer_type"].bb-type-radio:checked');
    if (!checkedRadio) return;
    const type = checkedRadio.value;

    if (type === 'bank_to_bank') {
      if (bbToTypeInput) bbToTypeInput.value = 'bank';
      if (bbDestBankDiv) bbDestBankDiv.style.display = 'block';
      if (bbDestCashDiv) bbDestCashDiv.style.display = 'none';
      if (bbToBankSelect) bbToBankSelect.required = true;
    } else if (type === 'bank_to_cash') {
      if (bbToTypeInput) bbToTypeInput.value = 'cash';
      if (bbDestBankDiv) bbDestBankDiv.style.display = 'none';
      if (bbDestCashDiv) bbDestCashDiv.style.display = 'block';
      if (bbToBankSelect) {
        bbToBankSelect.required = false;
        bbToBankSelect.value = '';
      }
    }
  }

  bbRadios.forEach(r => r.addEventListener('change', updateBbTransferFields));
  updateBbTransferFields();

  const bbForm = document.getElementById('bbQuickTransferForm');
  if (bbForm) {
    bbForm.addEventListener('submit', function(e) {
      const type = document.querySelector('input[name="transfer_type"].bb-type-radio:checked').value;
      const fromBank = document.getElementById('bbFromBankSelect').value;
      const toBank = bbToBankSelect.value;
      if (type === 'bank_to_bank' && fromBank === toBank) {
        e.preventDefault();
        alert('Source and destination bank cannot be the same account!');
        bbToBankSelect.focus();
        return false;
      }
    });
  }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>