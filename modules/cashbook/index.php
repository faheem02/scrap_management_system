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
$stmt = $pdo->prepare("SELECT * FROM cash_book" . $where . " ORDER BY id DESC");
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Totals (respect selected range)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_type = 'inflow'" . ($where ? ' AND ' . substr($where, 7) : ''));
$stmt->execute($params);
$total_in = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_type = 'outflow'" . ($where ? ' AND ' . substr($where, 7) : ''));
$stmt->execute($params);
$total_out = $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'opening';

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
    }

    if ($action === 'edit_entry') {
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
    }

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

        logActivity($pdo, 'opening', 'cash', null, 'Set cash opening balance PKR ' . $amount . ' for ' . $tdate);
        $pdo->commit();
        redirect('index.php', 'Opening balance saved');
    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row mb-3 align-items-center d-print-none">
  <div class="col-md-8">
    <form method="get" class="form-inline mb-1">
      <label class="mr-1 small text-muted">From</label>
      <input type="date" name="from" value="<?=htmlspecialchars($from)?>" class="form-control form-control-sm mr-2">
      <label class="mr-1 small text-muted">To</label>
      <input type="date" name="to" value="<?=htmlspecialchars($to)?>" class="form-control form-control-sm mr-2">
      <button type="submit" class="btn btn-sm btn-primary mr-1"><i class="fas fa-filter"></i> Filter</button>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">All</a>
    </form>
    <div class="small">
      <a href="index.php?from=<?=$today?>&to=<?=$today?>" class="mr-2">Today</a>
      <a href="index.php?from=<?=$monday?>&to=<?=$sunday?>" class="mr-2">This Week</a>
      <a href="index.php?from=<?=$month_first?>&to=<?=$month_last?>" class="mr-2">This Month</a>
    </div>
  </div>
  <div class="col-md-4 text-md-right">
    <button type="button" class="btn btn-outline-secondary shadow-sm mr-2" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
    <button type="button" class="btn btn-primary shadow-sm" data-toggle="modal" data-target="#openingModal">
      <i class="fas fa-coins"></i> Opening Balance
    </button>
  </div>
</div>

<?php
$logo_path = dirname(__DIR__, 2) . '/assets/img/logo.png';
$logo_data = file_exists($logo_path) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logo_path))) : (($base_url ?? '') . 'assets/img/logo.png');
?>
<!-- Printable header -->
<div class="d-none d-print-block mb-3 text-center">
  <img src="<?= $logo_data ?>" alt="ARAB KHEL" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; margin-bottom: 6px; border: 1.5px solid #10b981; background: #fff;">
  <h4 class="font-weight-bold mb-0" style="color:#0f172a;">ARAB KHEL</h4>
  <small class="text-muted">Near Itifaq Kanta Misrishah Lahore</small>
  <h5 class="font-weight-bold text-primary mt-2 mb-0">CASH BOOK</h5>
  <?php $c_meta = [];
  if ($from) $c_meta[] = 'From: ' . formatDate($from);
  if ($to) $c_meta[] = 'To: ' . formatDate($to);
  if ($c_meta): ?><div class="mt-1 font-weight-bold"><?=implode(' &nbsp;|&nbsp; ', $c_meta)?></div><?php endif; ?>
  <small>Printed on <?=formatDate(date('Y-m-d'))?></small>
</div>

<div class="row mb-3">
  <div class="col-md-4">
    <div class="card border-left-success shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Inflow</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_in)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-left-danger shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Outflow</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($total_out)?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <?php $cash_in_hand = $pdo->query("SELECT closing_balance FROM cash_book_daily ORDER BY date DESC LIMIT 1")->fetchColumn(); if (!$cash_in_hand) $cash_in_hand = 0; ?>
    <div class="card border-left-primary shadow stat-card">
      <div class="card-body py-2">
        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Cash in Hand</div>
        <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?=formatCurrency($cash_in_hand)?></div>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <!-- Entries -->
  <div class="col-12 mb-3">
    <div class="card shadow">
      <div class="card-header"><h6><i class="fas fa-money-bill-wave"></i> Cash Book Entries</h6></div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-hover">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th>Reference</th>
                <th class="text-right">Amount</th>
                <th class="text-center d-print-none" style="width: 110px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($entries as $e): ?>
              <tr>
                <td><?=formatDate($e['transaction_date'])?></td>
                <td>
                  <?php
                    $type = $e['transaction_type'];
                    if ($type == 'inflow') echo '<span class="badge badge-success">Inflow</span>';
                    elseif ($type == 'outflow') echo '<span class="badge badge-danger">Outflow</span>';
                    else echo '<span class="badge badge-secondary">' . ucfirst(str_replace('_',' ',$type)) . '</span>';
                  ?>
                </td>
                <td><?=htmlspecialchars($e['description'])?></td>
                <td><span class="text-muted"><?=htmlspecialchars($e['reference_type'] ?? '-')?></span></td>
                <td class="<?= $e['transaction_type'] == 'inflow' ? 'text-success' : ($e['transaction_type'] == 'outflow' ? 'text-danger' : '') ?> font-weight-bold text-right">
                  <?= $e['transaction_type'] == 'inflow' ? '+' : ($e['transaction_type'] == 'outflow' ? '-' : '') ?> PKR <?=formatCurrency($e['amount'])?>
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
              <?php if (!count($entries)): ?><tr><td colspan="6" class="text-center text-muted py-3">No cash book entries yet</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="openingModal" tabindex="-1" role="dialog" aria-labelledby="openingModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="openingModalLabel"><i class="fas fa-coins"></i> Cash Opening Balance</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">Date *</label>
            <input type="date" name="opening_date" class="form-control datepicker" value="<?=date('Y-m-d')?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Opening Balance (PKR) *</label>
            <input type="number" name="opening_amount" step="0.01" min="0" class="form-control" placeholder="0.00" required>
          </div>
          <?php $cur_opening = $pdo->query("SELECT opening_balance FROM cash_book_daily WHERE date = CURDATE()")->fetchColumn(); if ($cur_opening !== false): ?>
          <small class="text-muted">Current opening balance today: PKR <?=formatCurrency($cur_opening)?></small>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Save</button>
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