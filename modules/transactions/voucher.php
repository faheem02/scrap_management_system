<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Transaction Voucher';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$v_no = trim($_GET['voucher_no'] ?? '');

$sql = "SELECT ft.*,
               fb.bank_name AS from_bank_name, fb.account_name AS from_account_name, fb.account_no AS from_account_no,
               tb.bank_name AS to_bank_name, tb.account_name AS to_account_name, tb.account_no AS to_account_no,
               c.full_name AS customer_name, c.customer_no, c.phone AS customer_phone, c.plant_area, c.plant_name AS customer_plant, c.city AS customer_city, c.address AS customer_address, c.current_balance AS customer_balance,
               u.full_name AS created_by_name
        FROM fund_transfers ft
        LEFT JOIN bank_accounts fb ON ft.from_bank_id = fb.id
        LEFT JOIN bank_accounts tb ON ft.to_bank_id = tb.id
        LEFT JOIN customers c ON ft.customer_id = c.id
        LEFT JOIN users u ON ft.created_by = u.id";

if ($id > 0) {
    $stmt = $pdo->prepare($sql . " WHERE ft.id = ?");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch();
} elseif ($v_no !== '') {
    $stmt = $pdo->prepare($sql . " WHERE ft.voucher_no = ?");
    $stmt->execute([$v_no]);
    $voucher = $stmt->fetch();
} else {
    $voucher = null;
}

if (!$voucher) {
    redirect('transfers.php', 'Voucher not found.', 'error');
}

// Classify Transaction Type
$type = $voucher['transfer_type'];
$is_party_payment = in_array($type, ['customer_payment', 'person_payment']);
$is_party_receipt = in_array($type, ['customer_receipt', 'person_receipt']);
$is_party_txn     = $is_party_payment || $is_party_receipt;

// Titles and Badges
$voucher_title = 'PAYMENT / TRANSFER VOUCHER';
$voucher_tag   = 'VOUCHER';
$theme_color   = '#0f172a'; // Slate dark
$theme_bg      = '#f8fafc';
$theme_border  = '#cbd5e1';
$badge_class   = 'badge-dark';

if ($type === 'bank_to_bank') {
    $voucher_title = 'BANK TO BANK TRANSFER VOUCHER';
    $voucher_tag   = 'BTV';
    $badge_class   = 'badge-primary';
} elseif ($type === 'bank_to_cash') {
    $voucher_title = 'CASH WITHDRAWAL VOUCHER';
    $voucher_tag   = 'CWV';
    $badge_class   = 'badge-warning text-dark';
} elseif ($type === 'cash_to_bank') {
    $voucher_title = 'CASH DEPOSIT VOUCHER';
    $voucher_tag   = 'CDV';
    $badge_class   = 'badge-info';
} elseif ($type === 'customer_payment') {
    $voucher_title = ($voucher['from_type'] === 'bank') ? 'BANK PAYMENT VOUCHER' : 'CASH PAYMENT VOUCHER';
    $voucher_tag   = ($voucher['from_type'] === 'bank') ? 'BPV' : 'CPV';
    $badge_class   = ($voucher['from_type'] === 'bank') ? 'badge-primary' : 'badge-success';
} elseif ($type === 'customer_receipt') {
    $voucher_title = ($voucher['to_type'] === 'bank') ? 'BANK RECEIPT VOUCHER' : 'CASH RECEIPT VOUCHER';
    $voucher_tag   = ($voucher['to_type'] === 'bank') ? 'BRV' : 'CRV';
    $badge_class   = ($voucher['to_type'] === 'bank') ? 'badge-info' : 'badge-success';
} elseif ($type === 'person_payment') {
    $voucher_title = ($voucher['from_type'] === 'bank') ? 'BANK PAYMENT VOUCHER (PARTY)' : 'CASH PAYMENT VOUCHER (PARTY)';
    $voucher_tag   = ($voucher['from_type'] === 'bank') ? 'BPV' : 'CPV';
    $badge_class   = ($voucher['from_type'] === 'bank') ? 'badge-primary' : 'badge-success';
} elseif ($type === 'person_receipt') {
    $voucher_title = ($voucher['to_type'] === 'bank') ? 'BANK RECEIPT VOUCHER (PARTY)' : 'CASH RECEIPT VOUCHER (PARTY)';
    $voucher_tag   = ($voucher['to_type'] === 'bank') ? 'BRV' : 'CRV';
    $badge_class   = ($voucher['to_type'] === 'bank') ? 'badge-info' : 'badge-success';
}

// Party Information
$party_name        = '';
$party_phone       = '';
$party_area        = '';
$party_code        = '';
$party_category    = '';
$party_balance_str = '';
$party_address     = '';

if (!empty($voucher['customer_id'])) {
    $party_name     = $voucher['customer_name'] ?: ('Customer #' . $voucher['customer_id']);
    $party_phone    = $voucher['customer_phone'] ?: '';
    $party_code     = $voucher['customer_no'] ?: ('CUS-' . str_pad($voucher['customer_id'], 4, '0', STR_PAD_LEFT));
    $party_area     = trim(($voucher['customer_plant'] ? ($voucher['customer_plant'] . ' - ') : '') . ($voucher['plant_area'] ?: $voucher['customer_city'] ?: ''));
    $party_address  = $voucher['customer_address'] ?: '';
    $party_category = 'Registered Customer / Client';
    if ($voucher['customer_balance'] !== null) {
        $bal = (float)$voucher['customer_balance'];
        if ($bal > 0) {
            $party_balance_str = 'Receivable / Due: PKR ' . formatCurrency($bal);
        } elseif ($bal < 0) {
            $party_balance_str = 'Advance Balance: PKR ' . formatCurrency(abs($bal));
        } else {
            $party_balance_str = 'Balance Clear (PKR 0.00)';
        }
    }
} elseif (!empty($voucher['person_name'])) {
    $party_name     = $voucher['person_name'];
    $party_phone    = $voucher['person_phone'] ?: '';
    $party_code     = 'PARTY-' . str_pad($voucher['id'], 4, '0', STR_PAD_LEFT);
    $party_category = 'Vendor / Contractor / External Party';
}

// Payment Channel Details
$source_title       = 'Main Cash Book';
$source_sub         = 'Physical Cash Counter';
$source_acc_no      = '';
$dest_title         = 'Main Cash Book';
$dest_sub           = 'Physical Cash Counter';
$dest_acc_no        = '';
$payment_mode_badge = 'Cash Payment';

if ($voucher['from_type'] === 'bank') {
    $source_title  = $voucher['from_bank_name'] . ' — ' . $voucher['from_account_name'];
    $source_sub    = 'Commercial Bank Account';
    $source_acc_no = $voucher['from_account_no'] ?: 'N/A';
    $payment_mode_badge = 'Bank Transfer / Cheque';
} elseif ($voucher['from_type'] === 'cash') {
    $source_title  = 'Main Cash Book';
    $source_sub    = 'Physical Cash in Hand Counter';
    $payment_mode_badge = 'Cash Payment';
}

if ($voucher['to_type'] === 'bank') {
    $dest_title  = $voucher['to_bank_name'] . ' — ' . $voucher['to_account_name'];
    $dest_sub    = 'Commercial Bank Account';
    $dest_acc_no = $voucher['to_account_no'] ?: 'N/A';
} elseif ($voucher['to_type'] === 'cash') {
    $dest_title  = 'Main Cash Book';
    $dest_sub    = 'Physical Cash in Hand Counter';
}

$logo_path = dirname(__DIR__, 2) . '/assets/img/logo.png';
$logo_data = file_exists($logo_path) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logo_path))) : (($base_url ?? '') . 'assets/img/logo.png');

$auto_download = isset($_GET['auto_download']) && $_GET['auto_download'] == '1';

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
/* Clean corporate accounting voucher styling */
.voucher-wrapper {
  background: #ffffff;
  color: #0f172a;
  font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}
.voucher-header-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 16px;
}
.voucher-party-card {
  background: #ffffff;
  border: 1.5px solid #cbd5e1;
  border-radius: 6px;
  padding: 14px 16px;
  height: 100%;
}
.voucher-party-card.highlight {
  border-color: #3b82f6;
  background: #f8fbff;
}
.voucher-table {
  border: 1.5px solid #cbd5e1 !important;
}
.voucher-table th, .voucher-table td {
  border: 1px solid #cbd5e1 !important;
  vertical-align: middle;
}
.signature-box {
  border-top: 1.5px solid #475569 !important;
  padding-top: 6px;
}
@media print {
  @page {
    size: A4 portrait;
    margin: 10mm 12mm;
  }
  body {
    background: #ffffff !important;
    color: #000000 !important;
    font-size: 13px !important;
  }
  .d-print-none, .navbar, .main-sidebar, .content-header, footer {
    display: none !important;
  }
  .content-wrapper {
    margin: 0 !important;
    padding: 0 !important;
    background: transparent !important;
  }
  .voucher-wrapper {
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
    width: 100% !important;
  }
  .voucher-table th, .voucher-table td {
    border: 1px solid #64748b !important;
    color: #000 !important;
  }
  .signature-box {
    border-top: 1.5px solid #000 !important;
  }
  * {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
}
</style>

<!-- Action Bar (Hidden in Print) -->
<div class="d-print-none mb-3 d-flex flex-wrap justify-content-between align-items-center">
  <div>
    <a href="transfers.php" class="btn btn-outline-secondary btn-sm shadow-sm mr-2">
      <i class="fas fa-arrow-left mr-1"></i> Back to Transfers
    </a>
  </div>
  <div>
    <button type="button" class="btn btn-danger btn-sm mr-2 shadow-sm" onclick="downloadPDF('voucherPrintArea', 'Voucher_<?= htmlspecialchars($voucher['voucher_no']) ?>')">
      <i class="fas fa-file-pdf mr-1"></i> Download PDF
    </button>
    <button type="button" class="btn btn-primary btn-sm shadow-sm" onclick="window.print()">
      <i class="fas fa-print mr-1"></i> Print Voucher
    </button>
  </div>
</div>

<!-- Voucher Main Card -->
<div class="row justify-content-center">
  <div class="col-lg-10 col-xl-9">
    <div class="voucher-wrapper shadow-sm p-4 p-md-5" id="voucherPrintArea">

      <!-- Top Organization & Voucher Details Header -->
      <div class="row pb-3 mb-3 border-bottom align-items-center" style="border-bottom: 2px solid #0f172a !important;">
        <div class="col-7">
          <div class="d-flex align-items-center">
            <img src="<?= $logo_data ?>" alt="ARAB KHEL" style="width: 65px; height: 65px; border-radius: 8px; object-fit: cover; margin-right: 15px; border: 2px solid #10b981; background: #fff; flex-shrink: 0;">
            <div>
              <h2 class="font-weight-bold mb-0 text-dark" style="letter-spacing: 0.5px; font-size: 24px; line-height: 1.1;">ARAB KHEL</h2>
              <div class="text-secondary font-weight-bold" style="font-size: 13px;">Scrap Business Management & Recycling</div>
              <div class="small text-muted"><i class="fas fa-map-marker-alt text-danger mr-1"></i> Near Ittefaq Kanta, Misrishah, Lahore</div>
              <div class="small text-muted"><i class="fas fa-file-invoice text-success mr-1"></i> Accounts & Finance Department</div>
            </div>
          </div>
        </div>
        <div class="col-5 text-right">
          <div class="d-inline-block text-right">
            <span class="badge <?= $badge_class ?> px-3 py-1 font-weight-bold" style="font-size: 12px; letter-spacing: 1px; text-transform: uppercase;">
              <?= $voucher_tag ?>
            </span>
            <h5 class="font-weight-bold text-dark mt-1 mb-1" style="font-size: 17px; letter-spacing: 0.3px;">
              <?= $voucher_title ?>
            </h5>
            <div class="voucher-header-box mt-2 text-left" style="min-width: 230px;">
              <div class="d-flex justify-content-between mb-1">
                <span class="text-muted small">Voucher No:</span>
                <span class="font-weight-bold text-dark font-monospace" style="font-size: 14px;"><?= htmlspecialchars($voucher['voucher_no']) ?></span>
              </div>
              <div class="d-flex justify-content-between mb-1">
                <span class="text-muted small">Issue Date:</span>
                <span class="font-weight-bold text-dark" style="font-size: 13px;"><?= formatDate($voucher['transfer_date']) ?></span>
              </div>
              <?php if (!empty($voucher['reference_no'])): ?>
                <div class="d-flex justify-content-between">
                  <span class="text-muted small">Cheque / Ref #:</span>
                  <span class="font-weight-bold text-primary" style="font-size: 13px;"><?= htmlspecialchars($voucher['reference_no']) ?></span>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Summary Banner -->
      <div class="row mb-3">
        <div class="col-12">
          <div class="p-2 px-3 rounded d-flex justify-content-between align-items-center" style="background: #f1f5f9; border: 1px solid #e2e8f0; font-size: 13px;">
            <div>
              <span class="text-muted mr-1 font-weight-bold">Payment Channel:</span>
              <span class="badge badge-light border text-dark font-weight-bold px-2 py-1"><?= $payment_mode_badge ?></span>
            </div>
            <div>
              <span class="text-muted mr-1 font-weight-bold">Transaction Type:</span>
              <span class="text-dark font-weight-bold"><?= ucwords(str_replace('_', ' ', $type)) ?></span>
            </div>
            <div>
              <span class="text-muted mr-1 font-weight-bold">Posting Status:</span>
              <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Completed & Posted</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Party and Payment Channel Details (Core Section) -->
      <div class="row mb-4">
        <?php if ($is_party_payment): ?>
          <!-- 1. Source Account: Disbursed From -->
          <div class="col-6">
            <div class="voucher-party-card">
              <div class="text-xs font-weight-bold text-uppercase text-muted mb-2 pb-1 border-bottom d-flex justify-content-between">
                <span><i class="fas fa-wallet mr-1 text-danger"></i> Payment Disbursed From (Source)</span>
                <span class="text-muted">Disbursal</span>
              </div>
              <h5 class="font-weight-bold text-dark mb-1" style="font-size: 16px;">
                <?= htmlspecialchars($source_title) ?>
              </h5>
              <div class="small text-muted mb-2"><?= htmlspecialchars($source_sub) ?></div>
              
              <div class="mt-2 pt-2 border-top small">
                <?php if ($voucher['from_type'] === 'bank'): ?>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Bank Name:</span>
                    <strong class="text-dark"><?= htmlspecialchars($voucher['from_bank_name']) ?></strong>
                  </div>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Account Title:</span>
                    <strong class="text-dark"><?= htmlspecialchars($voucher['from_account_name']) ?></strong>
                  </div>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Account Number:</span>
                    <span class="font-monospace text-dark font-weight-bold"><?= htmlspecialchars($source_acc_no) ?></span>
                  </div>
                <?php else: ?>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Source Account:</span>
                    <strong class="text-success"><i class="fas fa-money-bill-wave mr-1"></i> Cash Book</strong>
                  </div>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Disbursed Via:</span>
                    <span class="text-dark font-weight-bold">Physical Cash Drawer Counter</span>
                  </div>
                <?php endif; ?>
                <?php if (!empty($voucher['reference_no'])): ?>
                  <div class="d-flex justify-content-between mt-1 pt-1 border-top">
                    <span class="text-muted">Instrument / Cheque #:</span>
                    <span class="text-primary font-weight-bold"><?= htmlspecialchars($voucher['reference_no']) ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- 2. Destination: Beneficiary Party -->
          <div class="col-6">
            <div class="voucher-party-card highlight">
              <div class="text-xs font-weight-bold text-uppercase text-primary mb-2 pb-1 border-bottom d-flex justify-content-between">
                <span><i class="fas fa-user-check mr-1 text-primary"></i> Beneficiary / Paid To (Party)</span>
                <span class="badge badge-primary px-2"><?= $party_category ?: 'Beneficiary' ?></span>
              </div>
              <h4 class="font-weight-bold text-dark mb-1" style="font-size: 19px; color: #0f172a !important;">
                <?= htmlspecialchars($party_name) ?>
              </h4>
              <div class="small text-muted mb-2">Party ID: <strong class="text-dark"><?= htmlspecialchars($party_code) ?></strong></div>

              <div class="mt-2 pt-2 border-top small">
                <?php if ($party_phone): ?>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Contact Phone:</span>
                    <strong class="text-dark"><?= htmlspecialchars($party_phone) ?></strong>
                  </div>
                <?php endif; ?>
                <?php if ($party_area): ?>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Plant / Area / City:</span>
                    <strong class="text-dark"><?= htmlspecialchars($party_area) ?></strong>
                  </div>
                <?php endif; ?>
                <?php if ($party_address): ?>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Address:</span>
                    <span class="text-dark"><?= htmlspecialchars($party_address) ?></span>
                  </div>
                <?php endif; ?>
                <?php if ($party_balance_str): ?>
                  <div class="d-flex justify-content-between mt-1 pt-1 border-top font-weight-bold">
                    <span class="text-muted">Ledger Balance:</span>
                    <span class="text-dark"><?= htmlspecialchars($party_balance_str) ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

        <?php elseif ($is_party_receipt): ?>
          <!-- 1. Party: Received From -->
          <div class="col-6">
            <div class="voucher-party-card highlight">
              <div class="text-xs font-weight-bold text-uppercase text-primary mb-2 pb-1 border-bottom d-flex justify-content-between">
                <span><i class="fas fa-user-check mr-1 text-primary"></i> Received From (Party / Payer)</span>
                <span class="badge badge-primary px-2"><?= $party_category ?: 'Payer' ?></span>
              </div>
              <h4 class="font-weight-bold text-dark mb-1" style="font-size: 19px; color: #0f172a !important;">
                <?= htmlspecialchars($party_name) ?>
              </h4>
              <div class="small text-muted mb-2">Party ID: <strong class="text-dark"><?= htmlspecialchars($party_code) ?></strong></div>

              <div class="mt-2 pt-2 border-top small">
                <?php if ($party_phone): ?>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Contact Phone:</span>
                    <strong class="text-dark"><?= htmlspecialchars($party_phone) ?></strong>
                  </div>
                <?php endif; ?>
                <?php if ($party_area): ?>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Plant / Area / City:</span>
                    <strong class="text-dark"><?= htmlspecialchars($party_area) ?></strong>
                  </div>
                <?php endif; ?>
                <?php if ($party_balance_str): ?>
                  <div class="d-flex justify-content-between mt-1 pt-1 border-top font-weight-bold">
                    <span class="text-muted">Ledger Balance:</span>
                    <span class="text-dark"><?= htmlspecialchars($party_balance_str) ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- 2. Destination Account: Deposited To -->
          <div class="col-6">
            <div class="voucher-party-card">
              <div class="text-xs font-weight-bold text-uppercase text-muted mb-2 pb-1 border-bottom d-flex justify-content-between">
                <span><i class="fas fa-university mr-1 text-success"></i> Deposited Into (Destination)</span>
                <span class="text-muted">Receipt</span>
              </div>
              <h5 class="font-weight-bold text-dark mb-1" style="font-size: 16px;">
                <?= htmlspecialchars($dest_title) ?>
              </h5>
              <div class="small text-muted mb-2"><?= htmlspecialchars($dest_sub) ?></div>

              <div class="mt-2 pt-2 border-top small">
                <?php if ($voucher['to_type'] === 'bank'): ?>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Bank Name:</span>
                    <strong class="text-dark"><?= htmlspecialchars($voucher['to_bank_name']) ?></strong>
                  </div>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Account Title:</span>
                    <strong class="text-dark"><?= htmlspecialchars($voucher['to_account_name']) ?></strong>
                  </div>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Account Number:</span>
                    <span class="font-monospace text-dark font-weight-bold"><?= htmlspecialchars($dest_acc_no) ?></span>
                  </div>
                <?php else: ?>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Destination Account:</span>
                    <strong class="text-success"><i class="fas fa-money-bill-wave mr-1"></i> Cash Book</strong>
                  </div>
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Deposited Via:</span>
                    <span class="text-dark font-weight-bold">Physical Cash Drawer Counter</span>
                  </div>
                <?php endif; ?>
                <?php if (!empty($voucher['reference_no'])): ?>
                  <div class="d-flex justify-content-between mt-1 pt-1 border-top">
                    <span class="text-muted">Instrument / Cheque #:</span>
                    <span class="text-primary font-weight-bold"><?= htmlspecialchars($voucher['reference_no']) ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

        <?php else: ?>
          <!-- Account to Account Internal Transfer -->
          <div class="col-6">
            <div class="voucher-party-card">
              <div class="text-xs font-weight-bold text-uppercase text-muted mb-2 pb-1 border-bottom">
                <i class="fas fa-arrow-circle-up text-danger mr-1"></i> Transfer From (Source Account)
              </div>
              <h5 class="font-weight-bold text-dark mb-1"><?= htmlspecialchars($source_title) ?></h5>
              <div class="small text-muted"><?= htmlspecialchars($source_sub) ?></div>
              <?php if ($voucher['from_type'] === 'bank'): ?>
                <div class="mt-2 pt-2 border-top small">
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">A/C Number:</span>
                    <span class="font-monospace font-weight-bold text-dark"><?= htmlspecialchars($source_acc_no) ?></span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-6">
            <div class="voucher-party-card highlight">
              <div class="text-xs font-weight-bold text-uppercase text-primary mb-2 pb-1 border-bottom">
                <i class="fas fa-arrow-circle-down text-success mr-1"></i> Transfer To (Destination Account)
              </div>
              <h5 class="font-weight-bold text-dark mb-1"><?= htmlspecialchars($dest_title) ?></h5>
              <div class="small text-muted"><?= htmlspecialchars($dest_sub) ?></div>
              <?php if ($voucher['to_type'] === 'bank'): ?>
                <div class="mt-2 pt-2 border-top small">
                  <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">A/C Number:</span>
                    <span class="font-monospace font-weight-bold text-dark"><?= htmlspecialchars($dest_acc_no) ?></span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Transaction Particulars Table -->
      <table class="table table-bordered mb-3 voucher-table">
        <thead style="background: #f1f5f9; color: #0f172a;">
          <tr>
            <th style="width: 5%;" class="text-center font-weight-bold">Sr.</th>
            <th style="width: 52%;" class="font-weight-bold">Particulars / Transaction Narrative</th>
            <th style="width: 18%;" class="text-center font-weight-bold">Payment Channel</th>
            <th style="width: 25%;" class="text-right font-weight-bold">Amount (PKR)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="text-center font-weight-bold">1</td>
            <td>
              <div class="font-weight-bold text-dark" style="font-size: 14px;">
                <?php if ($is_party_payment): ?>
                  Payment disbursed to: <span class="text-primary"><?= htmlspecialchars($party_name) ?></span>
                <?php elseif ($is_party_receipt): ?>
                  Receipt collected from: <span class="text-primary"><?= htmlspecialchars($party_name) ?></span>
                <?php else: ?>
                  <?= htmlspecialchars($voucher_title) ?>
                <?php endif; ?>
              </div>

              <div class="text-muted small mt-1" style="line-height: 1.4;">
                <?php if ($voucher['description']): ?>
                  <strong>Description:</strong> <?= htmlspecialchars($voucher['description']) ?><br>
                <?php else: ?>
                  Settlement of transaction through official accounting ledger.<br>
                <?php endif; ?>
                <span class="text-secondary">
                  <strong>Channel Details:</strong> <?= htmlspecialchars($source_title) ?> &rarr; <?= htmlspecialchars($dest_title) ?>
                </span>
              </div>

              <?php if (!empty($voucher['reference_no'])): ?>
                <div class="mt-1">
                  <span class="badge badge-light border text-dark font-weight-bold small">
                    <i class="fas fa-tag mr-1 text-muted"></i> Ref / Instrument #: <?= htmlspecialchars($voucher['reference_no']) ?>
                  </span>
                </div>
              <?php endif; ?>
            </td>
            <td class="text-center align-middle">
              <span class="badge badge-light border text-dark font-weight-bold px-2 py-1">
                <?= $payment_mode_badge ?>
              </span>
            </td>
            <td class="text-right font-weight-bold text-dark align-middle" style="font-size: 16px; white-space: nowrap;">
              PKR <?= formatCurrency($voucher['amount']) ?>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr style="background: #f8fafc;">
            <th colspan="3" class="text-right font-weight-bold text-uppercase" style="font-size: 13px; letter-spacing: 0.5px;">
              Total Amount (PKR):
            </th>
            <th class="text-right font-weight-bold text-primary" style="font-size: 17px; white-space: nowrap; border-bottom: 3px double #0f172a !important;">
              PKR <?= formatCurrency($voucher['amount']) ?>
            </th>
          </tr>
        </tfoot>
      </table>

      <!-- Amount in Words & Posted Seal -->
      <div class="p-3 mb-4 rounded border d-flex justify-content-between align-items-center" style="background: #f8fafc; border-left: 4px solid #10b981 !important; border-color: #e2e8f0;">
        <div>
          <span class="text-muted font-weight-bold text-uppercase" style="font-size: 11px; letter-spacing: 0.5px;">Amount in Words:</span>
          <div class="font-weight-bold text-dark mt-1" style="font-size: 14px; font-style: italic;">
            Pak Rupees <?= numberToWords($voucher['amount']) ?>
          </div>
        </div>
        <div class="text-right">
          <span class="badge badge-success px-3 py-2 font-weight-bold" style="font-size: 12px; letter-spacing: 0.5px;">
            <i class="fas fa-shield-alt mr-1"></i> VERIFIED & POSTED
          </span>
        </div>
      </div>

      <!-- Signatures Section -->
      <div class="row pt-4 mt-4 text-center">
        <div class="col-3">
          <div class="signature-box">
            <div class="font-weight-bold text-dark mb-1" style="font-size: 13px;">
              <?= htmlspecialchars($voucher['created_by_name'] ?: 'Administrator') ?>
            </div>
            <small class="text-muted text-uppercase font-weight-bold" style="font-size: 10px; letter-spacing: 0.5px;">
              Prepared By (Cashier)
            </small>
          </div>
        </div>
        <div class="col-3">
          <div class="signature-box">
            <div class="font-weight-bold text-dark mb-1" style="font-size: 13px;">&nbsp;</div>
            <small class="text-muted text-uppercase font-weight-bold" style="font-size: 10px; letter-spacing: 0.5px;">
              Checked & Verified
            </small>
          </div>
        </div>
        <div class="col-3">
          <div class="signature-box">
            <div class="font-weight-bold text-dark mb-1" style="font-size: 13px;">&nbsp;</div>
            <small class="text-muted text-uppercase font-weight-bold" style="font-size: 10px; letter-spacing: 0.5px;">
              Authorized Signatory
            </small>
          </div>
        </div>
        <div class="col-3">
          <div class="signature-box">
            <div class="font-weight-bold text-dark mb-1" style="font-size: 13px;">&nbsp;</div>
            <small class="text-muted text-uppercase font-weight-bold" style="font-size: 10px; letter-spacing: 0.5px;">
              Receiver's Signature / Stamp
            </small>
          </div>
        </div>
      </div>

      <!-- Footer Note -->
      <div class="text-center text-muted small mt-4 pt-3 border-top d-flex justify-content-between align-items-center" style="font-size: 11px;">
        <span>Generated on <?= date('d-M-Y h:i A') ?> by <?= htmlspecialchars($voucher['created_by_name'] ?: 'Admin') ?></span>
        <span>Arab Khel Scrap Management System — Official Accounting Voucher</span>
        <span>Page 1 of 1</span>
      </div>

    </div>
  </div>
</div>

<?php if ($auto_download): ?>
<script>
window.addEventListener('DOMContentLoaded', function() {
  setTimeout(function() {
    downloadPDF('voucherPrintArea', 'Voucher_<?= htmlspecialchars($voucher['voucher_no']) ?>');
  }, 600);
});
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
