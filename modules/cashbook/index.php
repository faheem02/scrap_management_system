<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Cash Book';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// Date filter
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$conds = [];
$params = [];
if ($from !== '') { $conds[] = "transaction_date >= ?"; $params[] = $from; }
if ($to !== '') { $conds[] = "transaction_date <= ?"; $params[] = $to; }
$where = $conds ? (" WHERE " . implode(' AND ', $conds)) : '';

$today = date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$month_first = date('Y-m-01');
$month_last = date('Y-m-t');

// All cash book entries
$stmt = $pdo->prepare("SELECT * FROM cash_book" . $where . " ORDER BY transaction_date ASC, (CASE WHEN transaction_type = 'opening_balance' THEN 0 ELSE 1 END) ASC, id ASC");
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Totals (respect selected range)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_type = 'inflow'" . ($where ? ' AND ' . substr($where, 7) : ''));
$stmt->execute($params);
$total_in = (float)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_type = 'outflow'" . ($where ? ' AND ' . substr($where, 7) : ''));
$stmt->execute($params);
$total_out = (float)$stmt->fetchColumn();

// Opening balance calculation for selected filter
if ($from !== '') {
    $st = $pdo->prepare("SELECT opening_balance FROM cash_book_daily WHERE date = ?");
    $st->execute([$from]);
    $ob = $st->fetchColumn();
    if ($ob !== false) {
        $period_opening = (float)$ob;
    } else {
        $st = $pdo->prepare("SELECT closing_balance FROM cash_book_daily WHERE date < ? ORDER BY date DESC LIMIT 1");
        $st->execute([$from]);
        $cb = $st->fetchColumn();
        $period_opening = ($cb !== false) ? (float)$cb : 0.0;
    }
} else {
    // When no date filter (All), get earliest recorded daily opening balance or 0
    $first_day = $pdo->query("SELECT opening_balance FROM cash_book_daily ORDER BY date ASC, id ASC LIMIT 1")->fetchColumn();
    $period_opening = ($first_day !== false) ? (float)$first_day : 0.0;
}

// Period Closing Balance = Opening + Inflow - Outflow
$period_closing = $period_opening + $total_in - $total_out;

// Latest overall cash in hand in the system
$cash_in_hand = $pdo->query("SELECT closing_balance FROM cash_book_daily ORDER BY date DESC LIMIT 1")->fetchColumn();
if ($cash_in_hand === false) {
    $cash_in_hand = $period_closing;
} else {
    $cash_in_hand = (float)$cash_in_hand;
}

// Running balance calculation for all entries chronologically
$all_asc = $pdo->query("SELECT * FROM cash_book ORDER BY transaction_date ASC, (CASE WHEN transaction_type = 'opening_balance' THEN 0 ELSE 1 END) ASC, id ASC")->fetchAll();
$first_daily_row = $pdo->query("SELECT id, date, opening_balance FROM cash_book_daily ORDER BY date ASC, id ASC LIMIT 1")->fetch();
$calc_run = $first_daily_row ? (float)$first_daily_row['opening_balance'] : 0.0;
$running_map = [];
$has_explicit_opening_in_entries = false;

foreach ($all_asc as $row) {
    $t = $row['transaction_type'];
    $a = (float)$row['amount'];
    if ($t === 'opening_balance') {
        $calc_run = $a;
    } elseif ($t === 'inflow') {
        $calc_run += $a;
    } elseif ($t === 'outflow') {
        $calc_run -= $a;
    }
    $running_map[$row['id']] = $calc_run;
}

foreach ($entries as $e) {
    if ($e['transaction_type'] === 'opening_balance') {
        $has_explicit_opening_in_entries = true;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_entry') {
        $entry_id = (int)($_POST['entry_id'] ?? 0);
        $entry = getById('cash_book', $entry_id);
        if (!$entry) { redirect('index.php', 'Cash entry not found', 'error'); }

        $pdo->beginTransaction();
        try {
            $daily_id = $entry['daily_id'];
            $tdate = $entry['transaction_date'];

            $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$entry_id]);

            if ($daily_id) {
                recomputeCashDayTotals($pdo, (int)$daily_id);
            }
            if ($tdate) {
                recomputeCashDailyFrom($pdo, $tdate);
            }

            logActivity($pdo, 'delete', 'cash_book', $entry_id, 'Deleted cash entry: ' . ($entry['description'] ?: 'Entry') . ' (PKR ' . $entry['amount'] . ')');
            $pdo->commit();
            redirect('index.php', 'Cash book entry deleted successfully');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect('index.php', 'Error deleting entry: ' . $e->getMessage(), 'error');
        }
    } elseif ($action === 'delete_opening') {
        $tdate = !empty($_POST['opening_date']) ? $_POST['opening_date'] : null;

        $pdo->beginTransaction();
        try {
            if ($tdate) {
                // 1. Delete manual opening_balance entry from cash_book for this date
                $pdo->prepare("DELETE FROM cash_book WHERE transaction_type = 'opening_balance' AND transaction_date = ?")->execute([$tdate]);

                // 2. Set opening_balance to 0 in cash_book_daily for this date
                $st = $pdo->prepare("SELECT id FROM cash_book_daily WHERE date = ?");
                $st->execute([$tdate]);
                $daily_id = $st->fetchColumn();
                if ($daily_id) {
                    $pdo->prepare("UPDATE cash_book_daily SET opening_balance = 0 WHERE id = ?")->execute([$daily_id]);
                    recomputeCashDayTotals($pdo, (int)$daily_id);
                }
                // 3. Cascade running balances from this date onward
                recomputeCashDailyFrom($pdo, $tdate);
            } else {
                // Delete all manual opening_balance entries
                $pdo->prepare("DELETE FROM cash_book WHERE transaction_type = 'opening_balance'")->execute();

                // Reset opening_balance on first daily record
                $first_d = $pdo->query("SELECT id, date FROM cash_book_daily ORDER BY date ASC, id ASC LIMIT 1")->fetch();
                if ($first_d) {
                    $pdo->prepare("UPDATE cash_book_daily SET opening_balance = 0 WHERE id = ?")->execute([$first_d['id']]);
                    recomputeCashDayTotals($pdo, (int)$first_d['id']);
                    recomputeCashDailyFrom($pdo, $first_d['date']);
                }
            }

            logActivity($pdo, 'delete', 'cash', null, 'Reset cash opening balance to 0 for ' . ($tdate ?: 'all'));
            $pdo->commit();
            redirect('index.php', 'Cash opening balance reset to 0 successfully');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect('index.php', 'Error resetting opening balance: ' . $e->getMessage(), 'error');
        }
    } elseif ($action === 'edit_entry') {
        $entry_id = (int)($_POST['entry_id'] ?? 0);
        $entry = getById('cash_book', $entry_id);
        if (!$entry) { redirect('index.php', 'Cash entry not found', 'error'); }

        $new_date = $_POST['transaction_date'] ?: $entry['transaction_date'];
        $new_type = $_POST['transaction_type'] ?: $entry['transaction_type'];
        $new_amount = (float)($_POST['amount'] ?? 0);
        $new_desc = trim($_POST['description'] ?? '');
        $new_ref = trim($_POST['reference_type'] ?? '');

        if ($new_amount < 0) { redirect('index.php', 'Enter a valid amount', 'error'); }

        $pdo->beginTransaction();
        try {
            $old_date = $entry['transaction_date'];
            $old_daily_id = $entry['daily_id'];

            // Get or create daily record for new date
            $st = $pdo->prepare("SELECT id FROM cash_book_daily WHERE date = ?");
            $st->execute([$new_date]);
            $new_daily_id = $st->fetchColumn();
            if (!$new_daily_id) {
                $new_daily_id = insert('cash_book_daily', [
                    'date' => $new_date,
                    'opening_balance' => 0,
                    'total_inflow' => 0,
                    'total_outflow' => 0,
                    'closing_balance' => 0,
                    'status' => 'open',
                    'created_by' => $_SESSION['user_id'],
                    'created_at' => date('Y-m-d'),
                ]);
            }

            $up = $pdo->prepare("UPDATE cash_book SET daily_id = ?, transaction_date = ?, transaction_type = ?, amount = ?, description = ?, reference_type = ? WHERE id = ?");
            $up->execute([$new_daily_id, $new_date, $new_type, $new_amount, $new_desc, $new_ref, $entry_id]);

            if ($old_daily_id) recomputeCashDayTotals($pdo, (int)$old_daily_id);
            if ($new_daily_id && $new_daily_id != $old_daily_id) recomputeCashDayTotals($pdo, (int)$new_daily_id);

            $min_d = min($old_date, $new_date);
            recomputeCashDailyFrom($pdo, $min_d);

            logActivity($pdo, 'edit', 'cash_book', $entry_id, 'Updated cash entry #' . $entry_id . ' (PKR ' . $new_amount . ')');
            $pdo->commit();
            redirect('index.php', 'Cash book entry updated successfully');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect('index.php', 'Error updating entry: ' . $e->getMessage(), 'error');
        }
    } elseif ($action === 'quick_cash_transfer') {
        try {
            $to_bank_id = (int)($_POST['to_bank_id'] ?? 0);
            if (!$to_bank_id) throw new Exception('Please select destination bank account.');
            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
            $date = !empty($_POST['transfer_date']) ? $_POST['transfer_date'] : date('Y-m-d');
            $ref = trim($_POST['reference_no'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $user_id = $_SESSION['user_id'] ?? 1;

            $to_acc = getById('bank_accounts', $to_bank_id);
            $bank_label = $to_acc ? ($to_acc['bank_name'] . ' - ' . $to_acc['account_name']) : 'Bank';

            $pdo->beginTransaction();
            recordCashOutflow($pdo, $date, $amount, 'Cash deposited to Bank: ' . $bank_label . ($ref ? ' [Ref: ' . $ref . ']' : '') . ($desc ? ' - ' . $desc : ''), 'transfer', null, $user_id);
            recordBankInflow($pdo, $date, $amount, 'Cash deposited from Cash Book' . ($ref ? ' [Ref: ' . $ref . ']' : ''), 'transfer', null, $user_id, $to_bank_id);
            logActivity($pdo, 'transfer', 'cash_to_bank', null, 'Cash to Bank PKR ' . $amount . ' -> ' . $bank_label);
            $pdo->commit();
            redirect('index.php', 'Cash transferred to Bank successfully!');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
        }
    } elseif ($action === 'opening' || isset($_POST['opening_amount'])) {
        $tdate = $_POST['opening_date'] ?: date('Y-m-d');
        $amount = (float)($_POST['opening_amount'] ?? 0);
        if ($amount < 0) { redirect('index.php', 'Enter a valid opening balance amount', 'error'); }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date = ?");
            $stmt->execute([$tdate]);
            $daily = $stmt->fetch();

            if ($daily) {
                $daily_id = $daily['id'];
                $up = $pdo->prepare("UPDATE cash_book_daily SET opening_balance = ?, closing_balance = ? + total_inflow - total_outflow, updated_at = ? WHERE id = ?");
                $up->execute([$amount, $amount, date('Y-m-d'), $daily_id]);
            } else {
                $daily_id = insert('cash_book_daily', [
                    'date' => $tdate,
                    'opening_balance' => $amount,
                    'total_inflow' => 0,
                    'total_outflow' => 0,
                    'closing_balance' => $amount,
                    'status' => 'open',
                    'created_by' => $_SESSION['user_id'],
                    'created_at' => date('Y-m-d'),
                ]);
            }

            $stmt = $pdo->prepare("SELECT closing_balance FROM cash_book_daily WHERE date = ?");
            $stmt->execute([$tdate]);
            $prev_closing = (float)$stmt->fetchColumn();

            $rows = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date > ? ORDER BY date ASC, id ASC");
            $rows->execute([$tdate]);
            foreach ($rows->fetchAll() as $r) {
                $closing = $prev_closing + (float)$r['total_inflow'] - (float)$r['total_outflow'];
                $up = $pdo->prepare("UPDATE cash_book_daily SET opening_balance = ?, closing_balance = ?, updated_at = ? WHERE id = ?");
                $up->execute([$prev_closing, $closing, date('Y-m-d'), $r['id']]);
                $prev_closing = $closing;
            }

            $exist_entry = $pdo->prepare("SELECT id FROM cash_book WHERE daily_id = ? AND transaction_type = 'opening_balance' LIMIT 1");
            $exist_entry->execute([$daily_id]);
            $exist_entry_id = $exist_entry->fetchColumn();

            if ($exist_entry_id) {
                $pdo->prepare("UPDATE cash_book SET amount = ?, transaction_date = ?, description = 'Opening balance (set manually)' WHERE id = ?")
                    ->execute([$amount, $tdate, $exist_entry_id]);
            } else {
                insert('cash_book', [
                    'daily_id' => $daily_id,
                    'transaction_date' => $tdate,
                    'transaction_type' => 'opening_balance',
                    'amount' => $amount,
                    'description' => 'Opening balance (set manually)',
                    'reference_type' => 'opening_balance',
                    'created_by' => $_SESSION['user_id'],
                    'created_at' => date('Y-m-d'),
                ]);
            }

            logActivity($pdo, 'opening', 'cash', null, 'Set cash opening balance PKR ' . $amount . ' for ' . $tdate);
            $pdo->commit();
            redirect('index.php', 'Opening balance saved');
        } catch (Exception $e) {
            $pdo->rollBack();
            redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
        }
    }
}

$cur_daily = $pdo->query("SELECT * FROM cash_book_daily WHERE date = CURDATE()")->fetch();
$active_banks = $pdo->query("SELECT id, bank_name, account_name, current_balance FROM bank_accounts WHERE status = 1 ORDER BY bank_name ASC")->fetchAll();
$all_customers = $pdo->query("SELECT id, customer_no, full_name, current_balance FROM customers ORDER BY full_name ASC")->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 align-items-center justify-content-between d-print-none">
  <div class="col-md-6 col-lg-5">
    <form method="get" class="form-inline">
      <label class="mr-1 small text-muted font-weight-bold">From:</label>
      <input type="date" name="from" value="<?=htmlspecialchars($from)?>" class="form-control form-control-sm mr-2">
      <label class="mr-1 small text-muted font-weight-bold">To:</label>
      <input type="date" name="to" value="<?=htmlspecialchars($to)?>" class="form-control form-control-sm mr-2">
      <button type="submit" class="btn btn-sm btn-primary mr-1"><i class="fas fa-filter mr-1"></i> Filter</button>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
    </form>
  </div>
  <div class="col-md-6 col-lg-7 text-right mt-2 mt-md-0">
    <button type="button" class="btn btn-sm btn-info mr-1" data-toggle="modal" data-target="#cashTransferModal">
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
        <a class="dropdown-item py-2" href="javascript:void(0)" onclick="downloadPDF('cashbookPrintArea', 'ARAB_KHEL_Cash_Book_<?= ($from ?: 'start') . '_to_' . ($to ?: date('Y-m-d')) ?>')">
          <i class="fas fa-file-pdf text-danger mr-2"></i> <strong>Download PDF</strong>
          <small class="d-block text-muted">Save as PDF document</small>
        </a>
      </div>
    </div>
  </div>
</div>

<?php
$logo_path = dirname(__DIR__, 2) . '/assets/img/logo.png';
$logo_data = file_exists($logo_path) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logo_path))) : (($base_url ?? '') . 'assets/img/logo.png');
?>
<div id="cashbookPrintArea">
<!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <img src="<?= $logo_data ?>" alt="ARAB KHEL" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; margin-bottom: 6px; border: 1.5px solid #10b981; background: #fff;">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h4>
  <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">CASH BOOK</h5>
  <?php $c_meta = [];
  if ($from) $c_meta[] = '<span style="white-space:nowrap;">From: ' . formatDate($from) . '</span>';
  if ($to) $c_meta[] = '<span style="white-space:nowrap;">To: ' . formatDate($to) . '</span>';
  if ($c_meta): ?><div class="mt-1 font-weight-bold" style="white-space:nowrap;"><?=implode(' &nbsp;|&nbsp; ', $c_meta)?></div><?php endif; ?>
  <div class="mt-1 small text-muted font-weight-bold" style="white-space:nowrap;">
    Opening: PKR <?=formatCurrency($period_opening)?> &nbsp;|&nbsp;
    Inflow: PKR <?=formatCurrency($total_in)?> &nbsp;|&nbsp;
    Outflow: PKR <?=formatCurrency($total_out)?> &nbsp;|&nbsp;
    Closing: PKR <?=formatCurrency($period_closing)?>
  </div>
  <small style="white-space:nowrap;">Printed on <?=formatDate(date('Y-m-d'))?></small>
</div>

<div class="row">
  <!-- Entries -->
  <div class="col-12 mb-3">
    <div class="card shadow">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 text-primary font-weight-bold"><i class="fas fa-money-bill-wave mr-1"></i> Cash Book Entries</h6>
        <span class="badge badge-primary font-weight-normal px-2 py-1">Closing: PKR <?=formatCurrency($period_closing)?></span>
      </div>
      <div class="card-body">
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
                <th class="text-center d-print-none" style="width: 110px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <!-- First Row: ALWAYS Opening Balance -->
              <tr class="table-light font-weight-bold">
                <td class="text-nowrap" style="white-space: nowrap;"><?= $from ? formatDate($from) : '-' ?></td>
                <td><span class="badge badge-secondary">Opening Balance</span></td>
                <td>Brought Forward / Opening Balance</td>
                <td><span class="text-muted">—</span></td>
                <td class="text-right text-muted text-nowrap" style="white-space: nowrap;">PKR <?=formatCurrency($period_opening)?></td>
                <td class="text-right text-primary font-weight-bold d-print-none text-nowrap" style="white-space: nowrap;">PKR <?=formatCurrency($period_opening)?></td>
                <td class="text-center text-nowrap d-print-none">
                  <button type="button" class="btn btn-xs btn-outline-primary" data-toggle="modal" data-target="#openingModal" title="Edit Opening Balance">
                    <i class="fas fa-edit"></i>
                  </button>
                  <form method="post" action="index.php" class="d-inline" onsubmit="return confirm('Are you sure you want to reset the cash opening balance to 0?');">
                    <input type="hidden" name="action" value="delete_opening">
                    <input type="hidden" name="opening_date" value="<?= htmlspecialchars($from ?: ($first_daily_row['date'] ?? date('Y-m-d'))) ?>">
                    <button type="submit" class="btn btn-xs btn-outline-danger ml-1" title="Delete / Reset Opening Balance">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php foreach ($entries as $e): ?>
              <?php if ($e['transaction_type'] === 'opening_balance') continue; // Handled in first row ?>
              <tr>
                <td class="text-nowrap" style="white-space: nowrap;"><?=formatDate($e['transaction_date'])?></td>
                <td>
                  <?php
                    $type = $e['transaction_type'];
                    if ($type == 'inflow') echo '<span class="badge badge-success">Inflow</span>';
                    elseif ($type == 'outflow') echo '<span class="badge badge-danger">Outflow</span>';
                    elseif ($type == 'opening_balance') echo '<span class="badge badge-secondary">Opening Balance</span>';
                    else echo '<span class="badge badge-secondary">' . ucfirst(str_replace('_',' ',$type)) . '</span>';
                  ?>
                </td>
                <td><?=htmlspecialchars($e['description'])?></td>
                <td><span class="text-muted"><?=htmlspecialchars($e['reference_type'] ?? '-')?></span></td>
                <td class="<?= $e['transaction_type'] == 'inflow' ? 'text-success' : ($e['transaction_type'] == 'outflow' ? 'text-danger' : 'text-muted') ?> font-weight-bold text-right text-nowrap" style="white-space: nowrap;">
                  <?= $e['transaction_type'] == 'inflow' ? '+' : ($e['transaction_type'] == 'outflow' ? '-' : '') ?> PKR <?=formatCurrency($e['amount'])?>
                </td>
                <td class="text-right d-print-none font-weight-bold text-nowrap <?= ($running_map[$e['id']] ?? 0) < 0 ? 'text-danger' : 'text-primary' ?>" style="white-space: nowrap;">
                  PKR <?= formatCurrency($running_map[$e['id']] ?? 0) ?>
                </td>
                <td class="text-center text-nowrap d-print-none">
                  <button type="button" class="btn btn-sm btn-outline-warning btn-edit-cash"
                          data-id="<?= $e['id'] ?>"
                          data-date="<?= $e['transaction_date'] ?>"
                          data-type="<?= $e['transaction_type'] ?>"
                          data-amount="<?= $e['amount'] ?>"
                          data-desc="<?= htmlspecialchars($e['description'] ?? '', ENT_QUOTES) ?>"
                          data-ref="<?= htmlspecialchars($e['reference_type'] ?? '', ENT_QUOTES) ?>"
                          title="Edit">
                    <i class="fas fa-edit"></i>
                  </button>
                  <form method="post" action="index.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this cash entry?');">
                    <input type="hidden" name="action" value="delete_entry">
                    <input type="hidden" name="entry_id" value="<?= $e['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!count($entries) && (!$period_opening || $has_explicit_opening_in_entries)): ?>
              <tr><td colspan="7" class="text-center text-muted py-3">No cash book entries yet</td></tr>
              <?php endif; ?>
            </tbody>
            <tfoot class="font-weight-bold table-light">
              <tr class="text-muted">
                <td colspan="4" class="text-right text-nowrap" style="white-space: nowrap;">Opening Balance (<?= $from ? formatDate($from) : 'Initial' ?>):</td>
                <td class="text-right text-nowrap" style="white-space: nowrap;">PKR <?= formatCurrency($period_opening) ?></td>
                <td colspan="2" class="d-print-none"></td>
              </tr>
              <tr>
                <td colspan="4" class="text-right text-success text-nowrap" style="white-space: nowrap;">Total Inflow (+):</td>
                <td class="text-right text-success text-nowrap" style="white-space: nowrap;">+ PKR <?= formatCurrency($total_in) ?></td>
                <td colspan="2" class="d-print-none"></td>
              </tr>
              <tr>
                <td colspan="4" class="text-right text-danger text-nowrap" style="white-space: nowrap;">Total Outflow (-):</td>
                <td class="text-right text-danger text-nowrap" style="white-space: nowrap;">- PKR <?= formatCurrency($total_out) ?></td>
                <td colspan="2" class="d-print-none"></td>
              </tr>
              <tr class="table-active">
                <td colspan="4" class="text-right text-primary h6 mb-0 font-weight-bold text-nowrap" style="white-space: nowrap;">
                  <i class="fas fa-coins mr-1"></i> Closing Balance (<?= $to ? formatDate($to) : 'Present' ?>):
                </td>
                <td class="text-right font-weight-bold h6 mb-0 text-nowrap <?= $period_closing < 0 ? 'text-danger' : 'text-primary' ?>" style="white-space: nowrap;">
                  PKR <?= formatCurrency($period_closing) ?>
                </td>
                <td class="text-right font-weight-bold h6 mb-0 d-print-none text-nowrap <?= $period_closing < 0 ? 'text-danger' : 'text-primary' ?>" style="white-space: nowrap;">
                  PKR <?= formatCurrency($period_closing) ?>
                </td>
                <td class="d-print-none"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
</div><!-- /#cashbookPrintArea -->

<div class="modal fade" id="openingModal" tabindex="-1" role="dialog" aria-labelledby="openingModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post" action="index.php">
        <input type="hidden" name="action" value="opening">
        <div class="modal-header">
          <h5 class="modal-title" id="openingModalLabel"><i class="fas fa-coins text-primary mr-1"></i> Cash Opening Balance</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label font-weight-bold">Date *</label>
            <input type="date" name="opening_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Opening Balance (PKR) *</label>
            <input type="number" name="opening_amount" step="0.01" min="0" class="form-control font-weight-bold" placeholder="0.00" value="<?=$cur_daily ? (float)$cur_daily['opening_balance'] : ''?>" required>
          </div>
          <?php if ($cur_daily): ?>
          <div class="card bg-light p-2 small border">
            <div class="d-flex justify-content-between mb-1">
              <span class="text-muted">Today's Opening Balance:</span>
              <strong>PKR <?=formatCurrency($cur_daily['opening_balance'])?></strong>
            </div>
            <div class="d-flex justify-content-between mb-1">
              <span class="text-success">Today's Total Inflow:</span>
              <strong class="text-success">+ PKR <?=formatCurrency($cur_daily['total_inflow'])?></strong>
            </div>
            <div class="d-flex justify-content-between mb-1">
              <span class="text-danger">Today's Total Outflow:</span>
              <strong class="text-danger">- PKR <?=formatCurrency($cur_daily['total_outflow'])?></strong>
            </div>
            <hr class="my-1">
            <div class="d-flex justify-content-between font-weight-bold">
              <span class="text-primary">Today's Closing Balance:</span>
              <strong class="<?= $cur_daily['closing_balance'] < 0 ? 'text-danger' : 'text-primary' ?>">PKR <?=formatCurrency($cur_daily['closing_balance'])?></strong>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Save Opening Balance</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Cash Entry Modal -->
<div class="modal fade" id="editCashModal" tabindex="-1" role="dialog" aria-labelledby="editCashModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post" action="index.php">
        <input type="hidden" name="action" value="edit_entry">
        <input type="hidden" name="entry_id" id="edit_entry_id">
        <div class="modal-header">
          <h5 class="modal-title font-weight-bold" id="editCashModalLabel"><i class="fas fa-edit text-warning mr-2"></i> Edit Cash Entry</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label font-weight-bold">Date *</label>
            <input type="date" name="transaction_date" id="edit_date" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Type *</label>
            <select name="transaction_type" id="edit_type" class="form-control" required>
              <option value="inflow">Inflow (Cash Received / +)</option>
              <option value="outflow">Outflow (Cash Paid / -)</option>
              <option value="opening_balance">Opening Balance</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Amount (PKR) *</label>
            <input type="number" name="amount" id="edit_amount" step="0.01" min="0" class="form-control font-weight-bold" placeholder="0.00" required>
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Description</label>
            <input type="text" name="description" id="edit_desc" class="form-control" placeholder="Description of transaction">
          </div>
          <div class="form-group">
            <label class="form-label font-weight-bold">Reference</label>
            <input type="text" name="reference_type" id="edit_ref" class="form-control" placeholder="e.g. sale, purchase, expense, general">
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

<!-- Cash Quick Transfer Modal -->
<!-- Quick Transfer: Cash to Bank Modal -->
<div class="modal fade" id="cashTransferModal" tabindex="-1" role="dialog" aria-labelledby="cashTransferModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content border-0 shadow-lg">
      <form method="post" id="cbQuickTransferForm">
        <input type="hidden" name="action" value="quick_cash_transfer">
        
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title font-weight-bold" id="cashTransferModalLabel">
            <i class="fas fa-exchange-alt mr-2"></i> Transfer from Cash to Bank
          </h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body p-4">
          <!-- Source Cash -->
          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark">Transfer From (Source)</label>
            <div class="form-control bg-light text-success font-weight-bold">
              <i class="fas fa-money-bill-wave mr-1"></i> Cash Book (Cash in Hand: PKR <?= formatCurrency($cash_in_hand) ?>)
            </div>
          </div>

          <!-- Destination Bank Account -->
          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark">Deposit Into Bank Account <span class="text-danger">*</span></label>
            <select name="to_bank_id" id="cbToBankSelect" class="form-control" required>
              <option value="">-- Select Destination Bank Account --</option>
              <?php foreach ($active_banks as $ba): ?>
                <option value="<?= $ba['id'] ?>">
                  <?= htmlspecialchars($ba['bank_name'] . ' — ' . $ba['account_name']) ?> (Bal: PKR <?= formatCurrency($ba['current_balance']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
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
              <label class="font-weight-bold text-dark">Date <span class="text-danger">*</span></label>
              <input type="date" name="transfer_date" value="<?= date('Y-m-d') ?>" class="form-control font-weight-bold" required>
            </div>
          </div>

          <!-- Reference & Remarks -->
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Reference #</label>
              <input type="text" name="reference_no" class="form-control" placeholder="Optional deposit slip #">
            </div>
            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Description / Remarks</label>
              <input type="text" name="description" class="form-control" placeholder="e.g. Cash deposited into bank">
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light d-flex justify-content-between">
          <a href="../transactions/transfers.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-list mr-1"></i> All Transfers
          </a>
          <div>
            <button type="button" class="btn btn-secondary shadow-sm mr-1" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-info shadow-sm px-4">
              <i class="fas fa-check-circle mr-1"></i> Deposit to Bank
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  $('.btn-edit-cash').on('click', function() {
    var id = $(this).data('id');
    var date = $(this).data('date');
    var type = $(this).data('type');
    var amount = $(this).data('amount');
    var desc = $(this).data('desc');
    var ref = $(this).data('ref');

    $('#edit_entry_id').val(id);
    $('#edit_date').val(date);
    $('#edit_type').val(type);
    $('#edit_amount').val(amount);
    $('#edit_desc').val(desc);
    $('#edit_ref').val(ref);

    $('#editCashModal').modal('show');
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>