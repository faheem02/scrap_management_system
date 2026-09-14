<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Transfers';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// AJAX: Get Next Voucher No
if (isset($_GET['action']) && $_GET['action'] === 'get_voucher_no') {
    header('Content-Type: application/json');
    $vtype = $_GET['type'] ?? 'customer_payment';
    $vdate = $_GET['date'] ?? date('Y-m-d');
    $vno = getNextTransferVoucherNo($pdo, $vtype, $vdate);
    echo json_encode(['status' => 'success', 'voucher_no' => $vno]);
    exit;
}

$initial_voucher_no = getNextTransferVoucherNo($pdo, 'customer_payment', date('Y-m-d'));

// Fetch Active Bank Accounts
$bank_accounts = $pdo->query("SELECT id, account_name, bank_name, account_no, current_balance FROM bank_accounts WHERE status = 1 ORDER BY bank_name ASC, account_name ASC")->fetchAll();

// Fetch Active Customers
$customers = $pdo->query("SELECT id, customer_no, full_name, phone, current_balance FROM customers ORDER BY full_name ASC")->fetchAll();

// Handle Form Submission: Create Transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_transfer') {
    try {
        $mode = $_POST['transfer_mode'] ?? 'send'; // 'send' or 'receive'
        $method = $_POST['channel_method'] ?? 'cash'; // 'cash' or 'bank'
        $target = $_POST['target_type'] ?? 'customer'; // 'customer' or 'person'

        $person_name = trim($_POST['person_name'] ?? '');
        $person_phone = trim($_POST['person_phone'] ?? '');

        $type = 'customer_payment';
        $from_type = 'cash';
        $from_bank_id = null;
        $to_type = 'customer';
        $to_bank_id = null;
        $customer_id = null;

        if ($mode === 'send') {
            // Outflow from (Cash or Bank) to Customer / Person
            if ($method === 'bank') {
                $from_type = 'bank';
                $from_bank_id = (int)($_POST['channel_bank_id'] ?? 0);
            } else {
                $from_type = 'cash';
            }

            if ($target === 'customer') {
                $type = 'customer_payment';
                $to_type = 'customer';
                $customer_id = (int)($_POST['customer_id'] ?? 0);
            } else {
                $type = 'person_payment';
                $to_type = 'person';
            }
        } else {
            // Receive mode (Inflow into Cash or Bank from Customer / Person)
            if ($method === 'bank') {
                $to_type = 'bank';
                $to_bank_id = (int)($_POST['channel_bank_id'] ?? 0);
            } else {
                $to_type = 'cash';
            }

            if ($target === 'customer') {
                $type = 'customer_receipt';
                $from_type = 'customer';
                $customer_id = (int)($_POST['customer_id'] ?? 0);
            } else {
                $type = 'person_receipt';
                $from_type = 'person';
            }
        }

        $data = [
            'transfer_type' => $type,
            'from_type'     => $from_type,
            'from_bank_id'  => $from_bank_id,
            'to_type'       => $to_type,
            'to_bank_id'    => $to_bank_id,
            'customer_id'   => $customer_id,
            'person_name'   => $person_name,
            'person_phone'  => $person_phone,
            'amount'        => (float)($_POST['amount'] ?? 0),
            'transfer_date' => !empty($_POST['transfer_date']) ? $_POST['transfer_date'] : date('Y-m-d'),
            'voucher_no'    => trim($_POST['voucher_no'] ?? ''),
            'reference_no'  => trim($_POST['reference_no'] ?? ''),
            'description'   => trim($_POST['description'] ?? ''),
            'created_by'    => $_SESSION['user_id'] ?? 1
        ];

        $res = createFundTransfer($pdo, $data);
        $v_no = $res['voucher_no'];
        $t_id = $res['id'];
        redirect('transfers.php?new_id=' . $t_id, 'Transfer completed successfully! Voucher #' . $v_no . ' generated.', 'success');
    } catch (Exception $e) {
        redirect('transfers.php', 'Error: ' . $e->getMessage(), 'error');
    }
}

// Handle Delete/Reverse Transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_transfer') {
    $transfer_id = (int)($_POST['transfer_id'] ?? 0);
    try {
        deleteFundTransfer($pdo, $transfer_id);
        redirect('transfers.php', 'Transfer voucher and all associated ledger entries reversed successfully.', 'success');
    } catch (Exception $e) {
        redirect('transfers.php', 'Error deleting transfer: ' . $e->getMessage(), 'error');
    }
}

// Handle Edit Transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_transfer') {
    $transfer_id = (int)($_POST['transfer_id'] ?? 0);
    try {
        $data = [
            'amount'        => (float)($_POST['amount'] ?? 0),
            'transfer_date' => !empty($_POST['transfer_date']) ? $_POST['transfer_date'] : date('Y-m-d'),
            'reference_no'  => trim($_POST['reference_no'] ?? ''),
            'description'   => trim($_POST['description'] ?? ''),
            'person_name'   => trim($_POST['person_name'] ?? ''),
            'person_phone'  => trim($_POST['person_phone'] ?? '')
        ];
        updateFundTransfer($pdo, $transfer_id, $data);
        redirect('transfers.php', 'Transfer record updated and balances recalculated successfully.', 'success');
    } catch (Exception $e) {
        redirect('transfers.php', 'Error updating transfer: ' . $e->getMessage(), 'error');
    }
}

// Filters
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
$type_filter = trim($_GET['type_filter'] ?? '');
$search = trim($_GET['search'] ?? '');

$where_clauses = ["1=1"];
$params = [];

if ($from !== '') {
    $where_clauses[] = "ft.transfer_date >= ?";
    $params[] = $from;
}
if ($to !== '') {
    $where_clauses[] = "ft.transfer_date <= ?";
    $params[] = $to;
}
if ($type_filter !== '') {
    $where_clauses[] = "ft.transfer_type = ?";
    $params[] = $type_filter;
}
if ($search !== '') {
    $where_clauses[] = "(ft.voucher_no LIKE ? OR ft.reference_no LIKE ? OR ft.description LIKE ? OR c.full_name LIKE ? OR ft.person_name LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch Transfer Transactions
$sql = "SELECT ft.*,
               fb.bank_name AS from_bank_name, fb.account_name AS from_account_name, fb.account_no AS from_account_no,
               tb.bank_name AS to_bank_name, tb.account_name AS to_account_name, tb.account_no AS to_account_no,
               c.full_name AS customer_name, c.customer_no, c.phone AS customer_phone,
               u.full_name AS created_by_name
        FROM fund_transfers ft
        LEFT JOIN bank_accounts fb ON ft.from_bank_id = fb.id
        LEFT JOIN bank_accounts tb ON ft.to_bank_id = tb.id
        LEFT JOIN customers c ON ft.customer_id = c.id
        LEFT JOIN users u ON ft.created_by = u.id
        WHERE {$where_sql}
        ORDER BY ft.transfer_date DESC, ft.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transfers = $stmt->fetchAll();

// Summary Statistics
$total_volume = 0;
$b2b_total = 0;
$cash_bank_total = 0;
$customer_total = 0;
$person_total = 0;

$all_stats = $pdo->query("SELECT transfer_type, SUM(amount) AS sum_amount FROM fund_transfers GROUP BY transfer_type")->fetchAll();
foreach ($all_stats as $st) {
    $amt = (float)$st['sum_amount'];
    $total_volume += $amt;
    if ($st['transfer_type'] === 'bank_to_bank') $b2b_total += $amt;
    elseif (in_array($st['transfer_type'], ['bank_to_cash', 'cash_to_bank'])) $cash_bank_total += $amt;
    elseif (in_array($st['transfer_type'], ['customer_payment', 'customer_receipt'])) $customer_total += $amt;
    elseif (in_array($st['transfer_type'], ['person_payment', 'person_receipt'])) $person_total += $amt;
}

$today = date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$month_first = date('Y-m-01');
$month_last = date('Y-m-t');

// Check if newly created to prompt voucher
$newly_created_id = (int)($_GET['new_id'] ?? 0);

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- Header Actions -->
<div class="row mb-3 align-items-center d-print-none">
  <div class="col-md-7">
    <h4 class="font-weight-bold text-gray-800 mb-0">
      <i class="fas fa-exchange-alt text-primary mr-2"></i> Transfers & Payments
    </h4>
    <small class="text-muted">Cash, Bank, Customer & Person Transfers with Official Printable Vouchers</small>
  </div>
  <div class="col-md-5 text-md-right mt-2 mt-md-0">
    <button type="button" class="btn btn-sm btn-primary mr-1" data-toggle="modal" data-target="#newTransferModal">
      <i class="fas fa-plus-circle mr-1"></i> New Transfer
    </button>
    <div class="btn-group">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="fas fa-print mr-1"></i> Print / PDF
      </button>
      <div class="dropdown-menu dropdown-menu-right shadow border-0 py-2">
        <a class="dropdown-item py-2" href="javascript:void(0)" onclick="window.print()">
          <i class="fas fa-print text-primary mr-2"></i> <strong>Print List</strong>
          <small class="d-block text-muted">Direct printer print</small>
        </a>
        <div class="dropdown-divider my-1"></div>
        <a class="dropdown-item py-2" href="javascript:void(0)" onclick="downloadPDF('transfersPrintArea', 'Transfers_List_<?= date('Y-m-d') ?>', 'landscape')">
          <i class="fas fa-file-pdf text-danger mr-2"></i> <strong>Download PDF</strong>
          <small class="d-block text-muted">Save list as PDF</small>
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Newly Created Notification Banner -->
<?php if ($newly_created_id > 0): ?>
<div class="alert alert-success alert-dismissible fade show shadow-sm mb-3 d-print-none" role="alert">
  <div class="d-flex align-items-center justify-content-between">
    <div>
      <i class="fas fa-check-circle fa-lg mr-2"></i>
      <strong>Transaction Recorded!</strong> Official voucher has been generated.
    </div>
    <div>
      <a href="voucher.php?id=<?= $newly_created_id ?>" class="btn btn-sm btn-light font-weight-bold text-success shadow-sm mr-2" target="_blank">
        <i class="fas fa-print mr-1"></i> View / Print Voucher
      </a>
      <a href="voucher.php?id=<?= $newly_created_id ?>&auto_download=1" class="btn btn-sm btn-dark shadow-sm">
        <i class="fas fa-file-pdf mr-1"></i> Download PDF
      </a>
    </div>
  </div>
  <button type="button" class="close" data-dismiss="alert" aria-label="Close">
    <span aria-hidden="true">&times;</span>
  </button>
</div>
<?php endif; ?>

<!-- Summary Stat Cards -->
<div class="row mb-3 d-print-none">
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card border-left-primary shadow-sm h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Volume Transferred</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800 text-nowrap">PKR <?= formatCurrency($total_volume) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-money-check-alt fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card border-left-info shadow-sm h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Bank to Bank</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800 text-nowrap">PKR <?= formatCurrency($b2b_total) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-university fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card border-left-success shadow-sm h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Bank ⇄ Cashbook</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800 text-nowrap">PKR <?= formatCurrency($cash_bank_total) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-money-bill-wave fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-md-6 mb-3">
    <div class="card border-left-warning shadow-sm h-100 py-2">
      <div class="card-body py-2">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Customer & Person Txns</div>
            <div class="h5 mb-0 font-weight-bold text-gray-800 text-nowrap">PKR <?= formatCurrency($customer_total + $person_total) ?></div>
          </div>
          <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filters Card -->
<div class="card shadow-sm mb-3 d-print-none">
  <div class="card-body py-2 px-3">
    <form method="get" class="form-inline flex-wrap gap-2">
      <label class="mr-1 small text-muted font-weight-bold">Type:</label>
      <select name="type_filter" class="form-control form-control-sm mr-2 mb-1">
        <option value="">All Transfer Types</option>
        <option value="customer_payment" <?= $type_filter === 'customer_payment' ? 'selected' : '' ?>>Send to Customer</option>
        <option value="customer_receipt" <?= $type_filter === 'customer_receipt' ? 'selected' : '' ?>>Receive from Customer</option>
        <option value="person_payment" <?= $type_filter === 'person_payment' ? 'selected' : '' ?>>Send to Other Person</option>
        <option value="person_receipt" <?= $type_filter === 'person_receipt' ? 'selected' : '' ?>>Receive from Other Person</option>
      </select>

      <label class="mr-1 small text-muted font-weight-bold">From:</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control form-control-sm mr-2 mb-1">

      <label class="mr-1 small text-muted font-weight-bold">To:</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control form-control-sm mr-2 mb-1">

      <label class="mr-1 small text-muted font-weight-bold">Search:</label>
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Voucher #, Customer, Person, Ref..." class="form-control form-control-sm mr-2 mb-1">

      <button type="submit" class="btn btn-sm btn-primary mr-1 mb-1"><i class="fas fa-filter mr-1"></i> Filter</button>
      <a href="transfers.php" class="btn btn-sm btn-outline-secondary mb-1">Reset</a>
    </form>
  </div>
</div>

<!-- Print Styles for Transfers -->
<style>
@media print {
  @page {
    size: landscape;
    margin: 8mm 6mm;
  }
  body, #content-wrapper, .container-fluid {
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
  }
  .d-print-none, .sidebar, .topbar, .card-header {
    display: none !important;
  }
  .table-responsive {
    overflow: visible !important;
    overflow-x: visible !important;
    display: block !important;
  }
  .table-responsive::-webkit-scrollbar {
    display: none !important;
  }
  #transfersPrintArea {
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
  }
  #transfersPrintArea .card {
    border: none !important;
    box-shadow: none !important;
    margin: 0 !important;
  }
  #transfersPrintArea .card-body {
    padding: 0 !important;
  }
  #transfersPrintArea table {
    width: 100% !important;
    table-layout: auto !important;
    font-size: 11px !important;
    border-collapse: collapse !important;
  }
  #transfersPrintArea table th {
    font-size: 11.5px !important;
    padding: 6px 6px !important;
    background-color: #f1f5f9 !important;
    border: 1px solid #334155 !important;
    width: auto !important;
  }
  #transfersPrintArea table td {
    font-size: 11px !important;
    padding: 5px 6px !important;
    border: 1px solid #cbd5e1 !important;
  }
  #transfersPrintArea table tfoot th {
    font-size: 12px !important;
    padding: 6px 6px !important;
    border-top: 2px solid #000 !important;
  }
  #transfersPrintArea .badge {
    border: 1px solid #64748b !important;
    background: transparent !important;
    color: #0f172a !important;
    font-size: 10px !important;
    padding: 2px 4px !important;
    font-weight: 600 !important;
  }
}
</style>

<!-- Printable Area -->
<div id="transfersPrintArea">
  <?php
  $b_logo_path = dirname(__DIR__, 2) . '/assets/img/logo.png';
  $b_logo_data = file_exists($b_logo_path) ? ('data:image/png;base64,' . base64_encode(file_get_contents($b_logo_path))) : (($base_url ?? '') . 'assets/img/logo.png');
  ?>
  <div class="d-none d-print-block mb-3 text-center">
    <img src="<?= $b_logo_data ?>" alt="ARAB KHEL" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-bottom: 4px; border: 1.5px solid #10b981; background: #fff;">
    <h4 class="font-weight-bold mb-0" style="color:#0f172a; font-size: 18px;">ARAB KHEL</h4>
    <div style="font-size: 12px; color: #475569;">Near Itifaq Kanta Misrishah Lahore</div>
    <div style="font-size: 14px; font-weight: bold; margin-top: 5px; color: #1e293b;">TRANSFERS & PAYMENTS REPORT</div>
    <?php if ($from || $to): ?>
      <div style="font-size: 11px; color: #64748b;">Period: <?= $from ? formatDate($from) : 'Start' ?> to <?= $to ? formatDate($to) : 'Today' ?></div>
    <?php endif; ?>
    <hr style="margin: 8px 0; border-top: 1.5px solid #cbd5e1;">
  </div>

  <!-- Transactions Table -->
  <div class="card shadow-sm mb-4">
    <div class="card-header py-2 bg-white d-flex justify-content-between align-items-center">
      <h6 class="m-0 font-weight-bold text-dark">
        <i class="fas fa-list-alt text-primary mr-1"></i> Transactions History
        <span class="badge badge-secondary ml-1"><?= count($transfers) ?> Records</span>
      </h6>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-striped table-bordered mb-0 align-middle">
          <thead class="thead-light">
            <tr>
              <th style="width: 130px;" class="text-nowrap">Voucher #</th>
              <th style="width: 105px;" class="text-nowrap">Date</th>
              <th style="width: 140px;" class="text-nowrap">Type</th>
              <th>Transfer From (Source)</th>
              <th>Transfer To (Destination)</th>
              <th style="width: 130px;" class="text-right text-nowrap">Amount</th>
              <th>Description / Reference</th>
              <th style="width: 140px;" class="text-center d-print-none">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($transfers)): ?>
            <tr>
              <td colspan="8" class="text-center py-4 text-muted">
                <i class="fas fa-inbox fa-3x mb-2 d-block text-gray-300"></i>
                No transfer or payment transactions found matching the criteria.
              </td>
            </tr>
            <?php else: ?>
              <?php foreach ($transfers as $t): ?>
                <?php
                  $badge_class = 'badge-primary';
                  $type_label = 'Bank to Bank';
                  if ($t['transfer_type'] === 'bank_to_cash') {
                      $badge_class = 'badge-info';
                      $type_label = 'Bank ➔ Cash';
                  } elseif ($t['transfer_type'] === 'cash_to_bank') {
                      $badge_class = 'badge-success';
                      $type_label = 'Cash ➔ Bank';
                  } elseif ($t['transfer_type'] === 'customer_payment') {
                      $badge_class = 'badge-warning';
                      $type_label = 'Pay Customer';
                  } elseif ($t['transfer_type'] === 'customer_receipt') {
                      $badge_class = 'badge-success';
                      $type_label = 'Receive Customer';
                  } elseif ($t['transfer_type'] === 'person_payment') {
                      $badge_class = 'badge-secondary';
                      $type_label = 'Pay Person';
                  } elseif ($t['transfer_type'] === 'person_receipt') {
                      $badge_class = 'badge-info';
                      $type_label = 'Receive Person';
                  }

                  // From display
                  if ($t['from_type'] === 'bank') {
                      $from_display = htmlspecialchars(($t['from_bank_name'] ?? 'Bank') . ' — ' . ($t['from_account_name'] ?? ''));
                  } elseif ($t['from_type'] === 'cash') {
                      $from_display = '<span class="text-success font-weight-bold"><i class="fas fa-money-bill-wave mr-1"></i> Cash Book</span>';
                  } elseif ($t['from_type'] === 'customer') {
                      $from_display = '<span class="text-primary font-weight-bold"><i class="fas fa-user mr-1"></i> ' . htmlspecialchars($t['customer_name'] ?? 'Customer') . '</span>';
                  } elseif ($t['from_type'] === 'person') {
                      $from_display = '<span class="text-info font-weight-bold"><i class="fas fa-user-friends mr-1"></i> ' . htmlspecialchars($t['person_name'] ?? 'Other Person') . '</span>';
                  }

                  // To display
                  if ($t['to_type'] === 'bank') {
                      $to_display = htmlspecialchars(($t['to_bank_name'] ?? 'Bank') . ' — ' . ($t['to_account_name'] ?? ''));
                  } elseif ($t['to_type'] === 'cash') {
                      $to_display = '<span class="text-success font-weight-bold"><i class="fas fa-money-bill-wave mr-1"></i> Cash Book</span>';
                  } elseif ($t['to_type'] === 'customer') {
                      $to_display = '<span class="text-primary font-weight-bold"><i class="fas fa-user mr-1"></i> ' . htmlspecialchars($t['customer_name'] ?? 'Customer') . '</span>';
                      if (!empty($t['customer_phone'])) {
                          $to_display .= ' <small class="text-muted">(' . htmlspecialchars($t['customer_phone']) . ')</small>';
                      }
                  } elseif ($t['to_type'] === 'person') {
                      $to_display = '<span class="text-info font-weight-bold"><i class="fas fa-user-friends mr-1"></i> ' . htmlspecialchars($t['person_name'] ?? 'Other Person') . '</span>';
                      if (!empty($t['person_phone'])) {
                          $to_display .= ' <small class="text-muted">(' . htmlspecialchars($t['person_phone']) . ')</small>';
                      }
                  }
                ?>
                <tr>
                  <td class="text-nowrap font-weight-bold">
                    <a href="voucher.php?id=<?= $t['id'] ?>" class="badge badge-light border text-primary px-2 py-1" style="font-size: 13px;" title="Click to view voucher">
                      <i class="fas fa-receipt mr-1"></i> <?= htmlspecialchars($t['voucher_no']) ?>
                    </a>
                  </td>
                  <td class="text-nowrap" style="white-space: nowrap;"><?= formatDate($t['transfer_date']) ?></td>
                  <td class="text-nowrap"><span class="badge <?= $badge_class ?> px-2 py-1"><?= $type_label ?></span></td>
                  <td><?= $from_display ?></td>
                  <td><?= $to_display ?></td>
                  <td class="text-right text-nowrap font-weight-bold text-dark" style="white-space: nowrap; font-size: 14px;">
                    PKR <?= formatCurrency($t['amount']) ?>
                  </td>
                  <td>
                    <?php if (!empty($t['reference_no'])): ?>
                      <span class="badge badge-secondary mr-1">Ref: <?= htmlspecialchars($t['reference_no']) ?></span>
                    <?php endif; ?>
                    <span class="small"><?= htmlspecialchars($t['description'] ?: '—') ?></span>
                  </td>
                  <td class="text-center text-nowrap d-print-none">
                    <a href="voucher.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary mr-1" title="View & Print Voucher">
                      <i class="fas fa-receipt"></i> Voucher
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-warning mr-1 btn-edit-transfer"
                      data-id="<?= $t['id'] ?>"
                      data-voucher="<?= htmlspecialchars($t['voucher_no']) ?>"
                      data-amount="<?= $t['amount'] ?>"
                      data-date="<?= $t['transfer_date'] ?>"
                      data-ref="<?= htmlspecialchars($t['reference_no'] ?? '') ?>"
                      data-desc="<?= htmlspecialchars($t['description'] ?? '') ?>"
                      data-person="<?= htmlspecialchars($t['person_name'] ?? '') ?>"
                      data-phone="<?= htmlspecialchars($t['person_phone'] ?? '') ?>"
                      data-type="<?= $t['transfer_type'] ?>"
                      title="Edit Transfer">
                      <i class="fas fa-edit"></i> Edit
                    </button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete and reverse Voucher #<?= htmlspecialchars($t['voucher_no']) ?>? All ledger balances will be restored.');">
                      <input type="hidden" name="action" value="delete_transfer">
                      <input type="hidden" name="transfer_id" value="<?= $t['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete & Reverse Transfer">
                        <i class="fas fa-trash"></i>
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot class="thead-light font-weight-bold">
            <tr>
              <th colspan="5" class="text-right text-nowrap">Filtered Total:</th>
              <th class="text-right text-nowrap" style="white-space: nowrap;">
                PKR <?= formatCurrency(array_sum(array_column($transfers, 'amount'))) ?>
              </th>
              <th colspan="2"></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- All-in-One New Transfer Modal -->
<div class="modal fade" id="newTransferModal" tabindex="-1" role="dialog" aria-labelledby="newTransferModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content border-0 shadow-lg">
      <form method="post" action="transfers.php" id="transferForm">
        <input type="hidden" name="action" value="create_transfer">

        <div class="modal-header bg-primary text-white d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center">
            <h5 class="modal-title font-weight-bold mb-0 mr-3" id="newTransferModalLabel">
              <i class="fas fa-exchange-alt mr-2"></i> New Transfer
            </h5>
            <span class="badge badge-light text-primary font-weight-bold py-1 px-2" style="font-size: 13px;">
              <i class="fas fa-receipt mr-1"></i> <span id="headerVoucherNo"><?= $initial_voucher_no ?></span>
            </span>
          </div>
          <button type="button" class="close text-white ml-auto" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body p-4">
          <!-- Auto-Generated Voucher Info Bar -->
          <div class="alert alert-light border shadow-sm d-flex align-items-center justify-content-between py-2 px-3 mb-3" style="background-color: #f8fafc; border-left: 4px solid #2563eb !important;">
            <div class="d-flex align-items-center">
              <div class="mr-3 text-primary">
                <i class="fas fa-receipt fa-2x"></i>
              </div>
              <div>
                <span class="small text-muted font-weight-bold text-uppercase d-block" style="font-size: 11px; letter-spacing: 0.5px;">
                  Auto-Generated Voucher #
                </span>
                <span class="h5 mb-0 font-weight-bold text-primary" id="displayVoucherNo" style="letter-spacing: 0.5px;">
                  <?= $initial_voucher_no ?>
                </span>
              </div>
            </div>
            <div class="text-right">
              <span class="badge badge-success px-2 py-1"><i class="fas fa-magic mr-1"></i> Auto-Generated</span>
              <small class="d-block text-muted font-weight-bold mt-1" id="voucherTypeHint">Payment to Customer</small>
            </div>
            <input type="hidden" name="voucher_no" id="inputVoucherNo" value="<?= $initial_voucher_no ?>">
          </div>
          <!-- Step 1: Action Direction -->
          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark mb-1">1. Action Direction <span class="text-danger">*</span></label>
            <div class="row">
              <div class="col-md-6 mb-2">
                <div class="custom-control custom-radio p-2 border rounded bg-light">
                  <input type="radio" id="modeSend" name="transfer_mode" value="send" class="custom-control-input transfer-mode-radio" checked>
                  <label class="custom-control-label font-weight-bold text-danger cursor-pointer" for="modeSend">
                    <i class="fas fa-arrow-up mr-1"></i> Send / Transfer Money (Outflow)
                    <small class="d-block text-muted font-weight-normal">Pay to Customer or Person</small>
                  </label>
                </div>
              </div>
              <div class="col-md-6 mb-2">
                <div class="custom-control custom-radio p-2 border rounded bg-light">
                  <input type="radio" id="modeReceive" name="transfer_mode" value="receive" class="custom-control-input transfer-mode-radio">
                  <label class="custom-control-label font-weight-bold text-success cursor-pointer" for="modeReceive">
                    <i class="fas fa-arrow-down mr-1"></i> Receive Money (Inflow)
                    <small class="d-block text-muted font-weight-normal">Collect from Customer or Person</small>
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 2: Channel (Cash or Bank) -->
          <div class="row mb-3">
            <div class="col-md-6 mb-2">
              <label class="font-weight-bold text-dark" id="channelLabel">2. Our Account (Paid From) <span class="text-danger">*</span></label>
              <div class="d-flex mb-2">
                <div class="custom-control custom-radio mr-3">
                  <input type="radio" id="chCash" name="channel_method" value="cash" class="custom-control-input channel-radio" checked>
                  <label class="custom-control-label font-weight-bold text-success cursor-pointer" for="chCash">
                    <i class="fas fa-money-bill-wave mr-1"></i> Cash Book
                  </label>
                </div>
                <div class="custom-control custom-radio">
                  <input type="radio" id="chBank" name="channel_method" value="bank" class="custom-control-input channel-radio">
                  <label class="custom-control-label font-weight-bold text-primary cursor-pointer" for="chBank">
                    <i class="fas fa-university mr-1"></i> Bank Account
                  </label>
                </div>
              </div>
              <div id="channelBankSelectDiv" style="display: none;">
                <select name="channel_bank_id" id="channelBankSelect" class="form-control">
                  <option value="">-- Select Bank Account --</option>
                  <?php foreach ($bank_accounts as $ba): ?>
                    <option value="<?= $ba['id'] ?>">
                      <?= htmlspecialchars($ba['bank_name'] . ' — ' . $ba['account_name']) ?> (Bal: PKR <?= formatCurrency($ba['current_balance']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Step 3: Counter Party Type -->
            <div class="col-md-6 mb-2">
              <label class="font-weight-bold text-dark" id="targetLabel">3. Counter Party / Destination <span class="text-danger">*</span></label>
              <div class="d-flex flex-wrap gap-2 mb-2">
                <div class="custom-control custom-radio mr-2">
                  <input type="radio" id="tgCustomer" name="target_type" value="customer" class="custom-control-input target-radio" checked>
                  <label class="custom-control-label font-weight-bold text-warning cursor-pointer" for="tgCustomer">Customer</label>
                </div>
                <div class="custom-control custom-radio">
                  <input type="radio" id="tgPerson" name="target_type" value="person" class="custom-control-input target-radio">
                  <label class="custom-control-label font-weight-bold text-info cursor-pointer" for="tgPerson">Other Person</label>
                </div>
              </div>

              <!-- Dynamic Target Fields -->
              <div id="targetCustomerSelectDiv">
                <div class="ac-wrap" id="transferCustomerWrap">
                  <input type="text" id="transferCustomerSearch" class="form-control"
                         placeholder="Type name or phone to search..." autocomplete="off">
                  <input type="hidden" name="customer_id" id="transferCustomerId">
                  <div class="ac-list" id="transferCustomerList"></div>
                </div>
                <small class="text-muted d-block mt-1" id="transferCustomerBalance"></small>
              </div>

              <div id="targetPersonDiv" style="display: none;">
                <input type="text" name="person_name" id="targetPersonName" class="form-control mb-1" placeholder="Person / Party Name *">
                <input type="text" name="person_phone" class="form-control" placeholder="Phone Number (Optional)">
              </div>
            </div>
          </div>

          <hr class="my-3">

          <!-- Amount & Date -->
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Amount (PKR) <span class="text-danger">*</span></label>
              <div class="input-group">
                <div class="input-group-prepend">
                  <span class="input-group-text font-weight-bold bg-light">PKR</span>
                </div>
                <input type="number" step="0.01" min="0.01" name="amount" id="amountInput" class="form-control font-weight-bold text-primary" placeholder="0.00" required>
              </div>
            </div>

            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Date <span class="text-danger">*</span></label>
              <input type="date" name="transfer_date" id="transferDateInput" value="<?= date('Y-m-d') ?>" class="form-control font-weight-bold" required>
            </div>
          </div>

          <!-- Reference & Description -->
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Reference / Cheque / Slip #</label>
              <input type="text" name="reference_no" class="form-control" placeholder="Optional Cheque # or Online Ref">
            </div>
            <div class="col-md-6 mb-3">
              <label class="font-weight-bold text-dark">Description / Remarks</label>
              <input type="text" name="description" class="form-control" placeholder="Purpose or note...">
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary shadow-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary shadow-sm px-4">
            <i class="fas fa-check-circle mr-1"></i> Process Transfer & Generate Voucher
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Transfer Modal -->
<div class="modal fade" id="editTransferModal" tabindex="-1" role="dialog" aria-labelledby="editTransferModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content border-0 shadow-lg">
      <form method="post" action="transfers.php" id="editTransferForm">
        <input type="hidden" name="action" value="edit_transfer">
        <input type="hidden" name="transfer_id" id="editTransferId">

        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title font-weight-bold" id="editTransferModalLabel">
            <i class="fas fa-edit mr-2"></i> Edit Transfer (<span id="editVoucherBadge"></span>)
          </h5>
          <button type="button" class="close text-dark" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body p-4">
          <div class="alert alert-info py-2 px-3 small mb-3">
            <i class="fas fa-info-circle mr-1"></i> Changing the amount will automatically recalculate ledger balances for the involved accounts.
          </div>

          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark">Transfer Date <span class="text-danger">*</span></label>
            <input type="date" name="transfer_date" id="editTransferDate" class="form-control" required>
          </div>

          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark">Transfer Amount (PKR) <span class="text-danger">*</span></label>
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text font-weight-bold">PKR</span>
              </div>
              <input type="number" step="0.01" min="0.01" name="amount" id="editAmount" class="form-control font-weight-bold text-primary" style="font-size: 17px;" required>
            </div>
          </div>

          <div id="editPersonFields" style="display: none;">
            <div class="form-group mb-3">
              <label class="font-weight-bold text-dark">Person / Party Name</label>
              <input type="text" name="person_name" id="editPersonName" class="form-control" placeholder="Enter person or party name">
            </div>
            <div class="form-group mb-3">
              <label class="font-weight-bold text-dark">Person Phone</label>
              <input type="text" name="person_phone" id="editPersonPhone" class="form-control" placeholder="03xx-xxxxxxx">
            </div>
          </div>

          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark">Reference / Cheque / Slip #</label>
            <input type="text" name="reference_no" id="editReferenceNo" class="form-control" placeholder="Optional Cheque # or Online Ref">
          </div>

          <div class="form-group mb-3">
            <label class="font-weight-bold text-dark">Description / Remarks</label>
            <textarea name="description" id="editDescription" class="form-control" rows="2" placeholder="Purpose or note..."></textarea>
          </div>
        </div>

        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary shadow-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning shadow-sm font-weight-bold px-4">
            <i class="fas fa-save mr-1"></i> Update Transfer
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const modeRadios = document.querySelectorAll('.transfer-mode-radio');
  const channelRadios = document.querySelectorAll('.channel-radio');
  const targetRadios = document.querySelectorAll('.target-radio');

  const channelLabel = document.getElementById('channelLabel');
  const targetLabel = document.getElementById('targetLabel');
  const channelBankSelectDiv = document.getElementById('channelBankSelectDiv');
  const channelBankSelect = document.getElementById('channelBankSelect');

  const targetCustomerSelectDiv = document.getElementById('targetCustomerSelectDiv');
  const targetPersonDiv = document.getElementById('targetPersonDiv');

  const transferCustomerSearch = document.getElementById('transferCustomerSearch');
  const transferCustomerId = document.getElementById('transferCustomerId');
  const transferCustomerList = document.getElementById('transferCustomerList');
  const transferCustomerBalance = document.getElementById('transferCustomerBalance');
  const targetPersonName = document.getElementById('targetPersonName');

  function updateDynamicTransferModal() {
    const mode = document.querySelector('input[name="transfer_mode"]:checked').value;
    const channel = document.querySelector('input[name="channel_method"]:checked').value;
    const target = document.querySelector('input[name="target_type"]:checked').value;

    if (mode === 'send') {
      channelLabel.innerHTML = '2. Paid From (Source) <span class="text-danger">*</span>';
      targetLabel.innerHTML = '3. Paid To (Destination) <span class="text-danger">*</span>';
    } else {
      channelLabel.innerHTML = '2. Received Into (Destination) <span class="text-danger">*</span>';
      targetLabel.innerHTML = '3. Received From (Source) <span class="text-danger">*</span>';
    }

    if (channel === 'bank') {
      channelBankSelectDiv.style.display = 'block';
      channelBankSelect.required = true;
    } else {
      channelBankSelectDiv.style.display = 'none';
      channelBankSelect.required = false;
    }

    // Target fields toggle
    if (target === 'customer') {
      targetCustomerSelectDiv.style.display = 'block';
      targetPersonDiv.style.display = 'none';
      transferCustomerId.required = true;
      targetPersonName.required = false;
    } else if (target === 'person') {
      targetCustomerSelectDiv.style.display = 'none';
      targetPersonDiv.style.display = 'block';
      transferCustomerId.required = false;
      targetPersonName.required = true;
    }

    // Dynamic voucher number determination
    let computedType = 'customer_payment';
    let hintText = 'Payment to Customer';

    if (mode === 'send') {
      if (target === 'customer') { computedType = 'customer_payment'; hintText = 'Payment to Customer'; }
      else if (target === 'person') { computedType = 'person_payment'; hintText = 'Payment to Other Person'; }
    } else {
      if (target === 'customer') { computedType = 'customer_receipt'; hintText = 'Receipt from Customer'; }
      else if (target === 'person') { computedType = 'person_receipt'; hintText = 'Receipt from Other Person'; }
    }

    const hintEl = document.getElementById('voucherTypeHint');
    if (hintEl) hintEl.textContent = hintText;

    const dateInput = document.getElementById('transferDateInput');
    const dateVal = dateInput ? dateInput.value : '';

    fetch('transfers.php?action=get_voucher_no&type=' + encodeURIComponent(computedType) + '&date=' + encodeURIComponent(dateVal))
      .then(res => res.json())
      .then(data => {
        if (data && data.voucher_no) {
          const hb = document.getElementById('headerVoucherNo');
          const db = document.getElementById('displayVoucherNo');
          const ib = document.getElementById('inputVoucherNo');
          if (hb) hb.textContent = data.voucher_no;
          if (db) db.textContent = data.voucher_no;
          if (ib) ib.value = data.voucher_no;
        }
      })
      .catch(err => console.error(err));
  }

  modeRadios.forEach(r => r.addEventListener('change', updateDynamicTransferModal));
  channelRadios.forEach(r => r.addEventListener('change', updateDynamicTransferModal));
  targetRadios.forEach(r => r.addEventListener('change', updateDynamicTransferModal));
  const tDateInput = document.getElementById('transferDateInput');
  if (tDateInput) tDateInput.addEventListener('change', updateDynamicTransferModal);

  updateDynamicTransferModal();

  document.getElementById('transferForm').addEventListener('submit', function(e) {
    const channel = document.querySelector('input[name="channel_method"]:checked').value;
    const target = document.querySelector('input[name="target_type"]:checked').value;
    if (channel === 'bank' && !channelBankSelect.value) {
      e.preventDefault();
      alert('Please select the bank account to use!');
      channelBankSelect.focus();
      return false;
    }
    if (target === 'customer' && !transferCustomerId.value) {
      e.preventDefault();
      alert('Please search and select a customer!');
      transferCustomerSearch.focus();
      return false;
    }
  });

  // ── Customer Autocomplete for Transfer Modal ──
  var custTimer = null;
  function escT(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function renderTransferCustList(data) {
    transferCustomerList.empty();
    if (!data || !data.length) {
      transferCustomerList.append('<div class="ac-item ac-empty">No matching customer found</div>');
    } else {
      $.each(data, function(i, it) {
        var sub = [];
        if (it.phone) sub.push('Phone: ' + escT(it.phone));
        if (it.city)  sub.push(escT(it.city));
        if (it.plant_area) sub.push(escT(it.plant_area));
        transferCustomerList.append(
          '<div class="ac-item" data-id="' + it.id + '" data-phone="' + escT(it.phone || '') + '" data-balance="' + (it.current_balance || 0) + '">' +
          '<span class="ac-name">' + escT(it.name) + '</span>' +
          (sub.length ? '<small class="ac-sub">' + sub.join(' &middot; ') + '</small>' : '') +
          '</div>'
        );
      });
    }
    transferCustomerList.show();
  }

  function pickTransferCustomer($item) {
    transferCustomerId.value = $item.data('id');
    transferCustomerSearch.value = $item.find('.ac-name').text();
    transferCustomerList.empty().hide();
    var bal = Number($item.data('balance')) || 0;
    if (bal > 0)      transferCustomerBalance.textContent = 'Due: PKR ' + bal.toFixed(2);
    else if (bal < 0)  transferCustomerBalance.textContent = 'Advance: PKR ' + Math.abs(bal).toFixed(2);
    else               transferCustomerBalance.textContent = 'Clear';
  }

  $(document).on('input', '#transferCustomerSearch', function() {
    var q = $.trim(this.value);
    clearTimeout(custTimer);
    transferCustomerId.value = '';
    transferCustomerBalance.textContent = '';
    if (!q) { transferCustomerList.empty().hide(); return; }
    custTimer = setTimeout(function() {
      $.get('ajax_customer_search.php', {q: q}, function(data) { renderTransferCustList(data); });
    }, 300);
  });

  $(document).on('mousedown click', '#transferCustomerList .ac-item', function(e) {
    e.preventDefault();
    if ($(this).hasClass('ac-empty')) return;
    pickTransferCustomer($(this));
  });

  $(document).on('keydown', '#transferCustomerSearch', function(e) {
    var $list = transferCustomerList;
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
      if (target.length && !target.hasClass('ac-empty')) pickTransferCustomer(target);
    }
  });

  $(document).on('mouseover', '#transferCustomerList .ac-item', function() {
    $(this).addClass('active').siblings().removeClass('active');
  });

  $(document).on('mousedown', function(e) {
    if (!$(e.target).closest('#transferCustomerWrap').length) transferCustomerList.empty().hide();
  });

  // Clear customer search when opening new transfer modal
  $('#newTransferModal').on('show.bs.modal', function() {
    transferCustomerSearch.value = '';
    transferCustomerId.value = '';
    transferCustomerBalance.textContent = '';
    transferCustomerList.empty().hide();
  });

  // Handle Edit Transfer Modal
  document.querySelectorAll('.btn-edit-transfer').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const id = this.getAttribute('data-id');
      const voucher = this.getAttribute('data-voucher');
      const amount = this.getAttribute('data-amount');
      const date = this.getAttribute('data-date');
      const ref = this.getAttribute('data-ref') || '';
      const desc = this.getAttribute('data-desc') || '';
      const person = this.getAttribute('data-person') || '';
      const phone = this.getAttribute('data-phone') || '';
      const type = this.getAttribute('data-type') || '';

      document.getElementById('editTransferId').value = id;
      document.getElementById('editVoucherBadge').textContent = voucher;
      document.getElementById('editTransferDate').value = date;
      document.getElementById('editAmount').value = amount;
      document.getElementById('editReferenceNo').value = ref;
      document.getElementById('editDescription').value = desc;

      const personFields = document.getElementById('editPersonFields');
      if (type === 'person_payment' || type === 'person_receipt' || person.trim() !== '') {
        personFields.style.display = 'block';
        document.getElementById('editPersonName').value = person;
        document.getElementById('editPersonPhone').value = phone;
      } else {
        personFields.style.display = 'none';
        document.getElementById('editPersonName').value = '';
        document.getElementById('editPersonPhone').value = '';
      }

      $('#editTransferModal').modal('show');
    });
  });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
