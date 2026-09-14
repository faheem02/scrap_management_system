<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Thermal Receipt';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker']);

$id = (int)($_GET['id'] ?? 0);
$sale = getById('sales', $id);
if (!$sale) {
    redirect('invoices.php', 'Invoice not found', 'error');
}

$customer = $sale['customer_id'] ? getById('customers', $sale['customer_id']) : null;
$salesman = $sale['salesman_id'] ? getById('employees', $sale['salesman_id']) : null;

$st = $pdo->prepare(
    "SELECT si.*, p.name, p.unit 
     FROM sale_items si 
     LEFT JOIN products p ON si.product_id = p.id 
     WHERE si.sale_id = ? 
     ORDER BY si.id ASC"
);
$st->execute([$id]);
$items = $st->fetchAll();

$total_weight = 0;
foreach ($items as $it) {
    $total_weight += (float)$it['quantity'];
}

$biller_name = '';
if (!empty($sale['created_by'])) {
    $u = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $u->execute([(int)$sale['created_by']]);
    $biller_name = (string)$u->fetchColumn();
}

$logo_path = dirname(__DIR__, 2) . '/assets/img/logo.png';
$logo_data = file_exists($logo_path) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logo_path))) : (($base_url ?? '') . 'assets/img/logo.png');

$auto_print = !isset($_GET['noprint']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Receipt #<?= htmlspecialchars($sale['invoice_no']) ?> - Thermal Print</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      background-color: #f1f5f9;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, monospace;
      color: #000;
      padding: 20px 10px;
    }
    .action-toolbar {
      max-width: 360px;
      margin: 0 auto 15px;
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      justify-content: center;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 6px 12px;
      font-size: 13px;
      font-weight: 500;
      border-radius: 6px;
      cursor: pointer;
      text-decoration: none;
      border: 1px solid transparent;
      transition: all 0.2s ease;
    }
    .btn-primary { background: #0284c7; color: #fff; }
    .btn-primary:hover { background: #0369a1; }
    .btn-success { background: #16a34a; color: #fff; }
    .btn-success:hover { background: #15803d; }
    .btn-secondary { background: #475569; color: #fff; }
    .btn-secondary:hover { background: #334155; }
    .btn-danger { background: #dc2626; color: #fff; }
    .btn-danger:hover { background: #b91c1c; }
    .btn-outline { background: #fff; border-color: #cbd5e1; color: #334155; }
    .btn-outline:hover { background: #f8fafc; }

    /* Thermal Receipt Card */
    .receipt-container {
      width: 80mm;
      max-width: 100%;
      margin: 0 auto;
      background: #fff;
      padding: 14px 12px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      border-radius: 4px;
      font-size: 12px;
      line-height: 1.35;
    }

    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    .font-bold { font-weight: bold; }
    .uppercase { text-transform: uppercase; }

    .brand-title {
      font-size: 16px;
      font-weight: 900;
      letter-spacing: 0.5px;
      margin-bottom: 2px;
      text-transform: uppercase;
    }
    .brand-sub {
      font-size: 11px;
      color: #333;
      margin-bottom: 6px;
    }
    .receipt-badge {
      display: inline-block;
      border: 1px solid #000;
      padding: 2px 8px;
      font-size: 11px;
      font-weight: bold;
      margin: 4px 0 8px;
    }

    .divider {
      border: none;
      border-top: 1px dashed #000;
      margin: 6px 0;
    }
    .divider-double {
      border: none;
      border-top: 2px solid #000;
      margin: 6px 0;
    }

    .meta-table, .items-table, .totals-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 11.5px;
    }
    .meta-table td {
      padding: 1.5px 0;
      vertical-align: top;
    }

    .items-table th {
      border-top: 1px dashed #000;
      border-bottom: 1px dashed #000;
      padding: 4px 0;
      font-size: 11px;
    }
    .items-table td {
      padding: 3px 0;
      vertical-align: top;
    }
    .item-row td {
      padding-top: 4px;
    }
    .item-calc {
      font-size: 10.5px;
      color: #444;
    }

    .totals-table td {
      padding: 2px 0;
    }
    .grand-total {
      font-size: 13px;
      font-weight: bold;
    }

    .footer-note {
      font-size: 10px;
      text-align: center;
      margin-top: 10px;
      color: #444;
      line-height: 1.3;
    }

    /* Print Specific Rules */
    @media print {
      body {
        background: #fff !important;
        padding: 0 !important;
        margin: 0 !important;
      }
      .action-toolbar {
        display: none !important;
      }
      .receipt-container {
        width: 100% !important;
        max-width: 80mm !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 2mm 1mm !important;
        margin: 0 !important;
      }
      @page {
        size: 80mm auto;
        margin: 0;
      }
    }
  </style>
</head>
<body>

  <!-- Screen Navigation / Actions -->
  <div class="action-toolbar">
    <a href="index.php" class="btn btn-success">
      <i class="fas fa-plus"></i> New Sale
    </a>
    <button type="button" class="btn btn-primary" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
    <button type="button" class="btn btn-danger" onclick="downloadThermalPDF()">
      <i class="fas fa-file-pdf"></i> Download PDF
    </button>
    <a href="invoice.php?id=<?= $id ?>" class="btn btn-outline">
      <i class="fas fa-file-invoice"></i> A4 Invoice
    </a>
    <a href="invoices.php" class="btn btn-secondary">
      <i class="fas fa-list"></i> Invoices
    </a>
  </div>

  <!-- Receipt Output for Thermal Printer (80mm / 58mm compatible) -->
  <div class="receipt-container" id="receiptContainer">
    
    <div class="text-center">
      <?php if (!empty($logo_data)): ?>
        <img src="<?= $logo_data ?>" alt="ARAB KHEL" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover; margin: 0 auto 5px; display: block; border: 1.5px solid #111; background: #fff;">
      <?php endif; ?>
      <div class="brand-title">ARAB KHEL</div>
      <div class="brand-sub font-bold" style="font-size:11px; margin-bottom:2px;">Scrap &amp; Metal Merchants</div>
      <div class="brand-sub" style="font-size:10px; margin-bottom:4px;">Near Itifaq Kanta Misrishah Lahore</div>
      <div class="receipt-badge">SALE RECEIPT</div>
    </div>

    <table class="meta-table">
      <tr>
        <td style="width: 50%;"><strong>Inv #:</strong> <?= htmlspecialchars($sale['invoice_no']) ?></td>
        <td class="text-right" style="width: 50%;"><strong>Date:</strong> <?= date('d-m-Y', strtotime($sale['sale_date'])) ?></td>
      </tr>
      <tr>
        <td colspan="2"><strong>Customer:</strong> <?= htmlspecialchars($customer['full_name'] ?? 'Counter Sale') ?></td>
      </tr>
      <?php if (!empty($customer['phone']) && $customer['phone'] !== '—'): ?>
      <tr>
        <td colspan="2"><strong>Phone:</strong> <?= htmlspecialchars($customer['phone']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if (!empty($customer['plant_area'])): ?>
      <tr>
        <td colspan="2"><strong>Plant Area:</strong> <?= htmlspecialchars($customer['plant_area']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($biller_name): ?>
      <tr>
        <td colspan="2"><strong>Cashier:</strong> <?= htmlspecialchars($biller_name) ?></td>
      </tr>
      <?php endif; ?>
    </table>

    <table class="items-table" style="margin-top: 4px;">
      <thead>
        <tr>
          <th class="text-left" style="width: 48%;">Item</th>
          <th class="text-right" style="width: 22%;">Weight</th>
          <th class="text-right" style="width: 30%;">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $idx => $it): ?>
        <tr class="item-row">
          <td colspan="3" class="font-bold"><?= ($idx + 1) . '. ' . htmlspecialchars($it['name'] ?? 'Item') ?></td>
        </tr>
        <tr>
          <td class="item-calc">@ PKR <?= number_format((float)$it['price'], 2) ?></td>
          <td class="text-right"><?= number_format((float)$it['quantity'], 2) ?> <?= htmlspecialchars($it['unit'] ?: 'kg') ?></td>
          <td class="text-right font-bold"><?= number_format((float)$it['subtotal'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <hr class="divider">

    <table class="totals-table">
      <tr>
        <td>Total Weight:</td>
        <td class="text-right font-bold"><?= number_format($total_weight, 2) ?> kg</td>
      </tr>
      <tr>
        <td>Sub Total:</td>
        <td class="text-right">PKR <?= number_format((float)$sale['total_amount'] + (float)$sale['discount_amount'], 2) ?></td>
      </tr>
      <?php if ((float)$sale['discount_amount'] > 0): ?>
      <tr>
        <td>Discount:</td>
        <td class="text-right">- PKR <?= number_format((float)$sale['discount_amount'], 2) ?></td>
      </tr>
      <?php endif; ?>
      <tr class="grand-total">
        <td style="border-top: 1px solid #000; padding-top: 3px;">Net Total:</td>
        <td class="text-right" style="border-top: 1px solid #000; padding-top: 3px;">PKR <?= number_format((float)$sale['total_amount'], 2) ?></td>
      </tr>
      <tr>
        <td>Paid Amount:</td>
        <td class="text-right font-bold">PKR <?= number_format((float)$sale['paid_amount'], 2) ?></td>
      </tr>
      <?php if ((float)$sale['due_amount'] > 0): ?>
      <tr>
        <td class="font-bold">Remaining Due:</td>
        <td class="text-right font-bold" style="color:#000;">PKR <?= number_format((float)$sale['due_amount'], 2) ?></td>
      </tr>
      <?php endif; ?>
      <tr>
        <td>Method:</td>
        <td class="text-right"><?= ucfirst($sale['payment_method']) ?></td>
      </tr>
      <?php if ($customer && isset($customer['current_balance']) && (float)$customer['current_balance'] != 0): ?>
      <tr>
        <td style="border-top: 1px dashed #000; padding-top: 2px;">Customer Balance:</td>
        <td class="text-right font-bold" style="border-top: 1px dashed #000; padding-top: 2px;">
          PKR <?= number_format((float)$customer['current_balance'], 2) ?>
        </td>
      </tr>
      <?php endif; ?>
    </table>

    <?php if (!empty($sale['notes'])): ?>
    <hr class="divider">
    <div style="font-size: 11px;">
      <strong>Note:</strong> <?= htmlspecialchars($sale['notes']) ?>
    </div>
    <?php endif; ?>

    <hr class="divider">

    <div class="footer-note">
      Thank you for your business!<br>
      Printed: <?= date('d-m-Y h:i A') ?><br>
      ARAB KHEL &middot; Misrishah Lahore
    </div>

  </div>

  <script>
    function downloadThermalPDF() {
      var el = document.getElementById('receiptContainer');
      var opt = {
        margin: [4, 4, 4, 4],
        filename: 'Receipt_<?= htmlspecialchars($sale['invoice_no']) ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: [80, 240], orientation: 'portrait' }
      };
      html2pdf().set(opt).from(el).save();
    }
    // Mark sale completed so returning to sale form will reset cleanly
    try { sessionStorage.setItem('sale_completed', '1'); } catch(e){}
  </script>
  <?php if ($auto_print): ?>
  <script>
    window.addEventListener('DOMContentLoaded', function() {
      // Auto print immediately on page load
      setTimeout(function() {
        window.print();
      }, 350);
    });
  </script>
  <?php endif; ?>

</body>
</html>
