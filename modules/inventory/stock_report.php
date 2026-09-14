<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Stock Report';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin', 'order_booker', 'loader']);

// Filter parameters
$from_date     = trim($_GET['from'] ?? '');
$to_date       = trim($_GET['to'] ?? '');
$cat_filter    = $_GET['category_id'] ?? '';
$status_filter = $_GET['stock_status'] ?? '';
$q             = trim($_GET['q'] ?? '');

$purchased_date_clause = '';
$sold_date_clause = '';

if ($from_date !== '') {
    $purchased_date_clause .= " AND pu.purchase_date >= " . $pdo->quote($from_date);
    $sold_date_clause .= " AND s.sale_date >= " . $pdo->quote($from_date);
}
if ($to_date !== '') {
    $purchased_date_clause .= " AND pu.purchase_date <= " . $pdo->quote($to_date);
    $sold_date_clause .= " AND s.sale_date <= " . $pdo->quote($to_date);
}

$sql = "SELECT p.*, c.name AS cat_name,
        (SELECT COALESCE(SUM(pi.quantity), 0) 
         FROM purchase_items pi 
         JOIN purchases pu ON pi.purchase_id = pu.id 
         WHERE pi.product_id = p.id AND pu.status != 'cancelled' $purchased_date_clause) AS total_purchased,
        (SELECT COALESCE(SUM(si.quantity), 0) 
         FROM sale_items si 
         JOIN sales s ON si.sale_id = s.id 
         WHERE si.product_id = p.id AND s.status != 'cancelled' $sold_date_clause) AS total_sold
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE 1=1";
$params = [];

if ($cat_filter !== '') {
    $sql .= " AND p.category_id = ?";
    $params[] = $cat_filter;
}
if ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.code LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if ($status_filter === 'out') {
    $sql .= " AND p.stock_quantity <= 0";
} elseif ($status_filter === 'low') {
    $sql .= " AND p.stock_quantity > 0 AND p.stock_quantity <= p.min_stock_level";
} elseif ($status_filter === 'ok') {
    $sql .= " AND p.stock_quantity > p.min_stock_level";
}

$sql .= " ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Calculate overall summary metrics
$total_qty        = 0;
$total_cost_val   = 0;
$total_sale_val   = 0;
$out_of_stock_cnt = 0;
$low_stock_cnt    = 0;

foreach ($items as $it) {
    $stock = (float)$it['stock_quantity'];
    $total_qty += $stock;
    $total_cost_val += ($stock * (float)$it['purchase_price']);
    $total_sale_val += ($stock * (float)$it['sale_price']);

    if ($stock <= 0) {
        $out_of_stock_cnt++;
    } elseif ($stock <= (float)$it['min_stock_level']) {
        $low_stock_cnt++;
    }
}

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$cat_name_display = 'All Categories';
if ($cat_filter) {
    foreach ($categories as $c) {
        if ($c['id'] == $cat_filter) {
            $cat_name_display = $c['name'];
            break;
        }
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
@media print {
  @page {
    size: A4 landscape;
    margin: 8mm 8mm 8mm 8mm;
  }
  body, .content, #content-wrapper {
    background: #fff !important;
    padding: 0 !important;
    margin: 0 !important;
    font-size: 15px !important;
    color: #000 !important;
  }
  .sidebar, .topbar, .d-print-none, .no-print, .scroll-to-top, .sidebar-overlay, .sticky-footer {
    display: none !important;
  }
  .card {
    border: none !important;
    box-shadow: none !important;
  }
  .card-header, .card-body {
    padding: 0 !important;
  }
  .table {
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 14.5px !important;
    color: #000 !important;
  }
  .table th {
    background-color: #f1f5f9 !important;
    color: #000 !important;
    border: 1.5px solid #334155 !important;
    padding: 8px 10px !important;
    font-weight: 800 !important;
    -webkit-print-color-adjust: exact;
  }
  .table td {
    border: 1px solid #475569 !important;
    padding: 7px 10px !important;
    color: #000 !important;
    font-weight: 600 !important;
  }
  .table tfoot td {
    background-color: #f8fafc !important;
    font-weight: 800 !important;
    border-top: 2px solid #000 !important;
    font-size: 15px !important;
  }
  .badge {
    border: 1.5px solid #334155 !important;
    color: #000 !important;
    background: transparent !important;
    font-size: 13px !important;
    font-weight: 700 !important;
  }
}
</style>

<!-- Action Toolbar (Screen only) -->
<div class="d-print-none mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-warehouse text-primary"></i> Inventory Stock Report</h5>
    <small class="text-muted">Live scrap stock levels, purchased/sold weights, and valuation report.</small>
  </div>
  <div class="d-flex align-items-center" style="gap: 8px;">
    <button type="button" class="btn btn-danger btn-sm" onclick="downloadPDF('stockReportContent', 'ARAB_KHEL_Stock_Report_<?= date('Y-m-d') ?>', 'landscape')">
      <i class="fas fa-file-pdf"></i> Download PDF
    </button>
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
    <?php if (isAdmin()): ?>
    <a href="<?= $base_url ?>modules/inventory/product_create.php" class="btn btn-success btn-sm">
      <i class="fas fa-plus-circle"></i> Add Material
    </a>
    <?php endif; ?>
  </div>
</div>

<div id="stockReportContent">

  <!-- Printable Professional Header (A4 Layout) -->
  <div class="d-none d-print-block mb-3 border-bottom pb-2">
    <div class="d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center">
        <img src="<?= $base_url ?>assets/img/logo.png" alt="ARAB KHEL" style="width: 70px; height: 70px; border-radius: 50%; object-fit: cover; margin-right: 15px; border: 2px solid #10b981;">
        <div>
          <h2 class="font-weight-bold mb-0" style="color:#0f172a; letter-spacing:0.5px;">ARAB KHEL</h2>
          <div class="font-weight-bold text-secondary" style="font-size: 12px;">Scrap &amp; Metal Merchants &middot; Wholesale &amp; Retail</div>
          <div class="text-muted" style="font-size: 11px;">
            <i class="fas fa-map-marker-alt"></i> Near Itifaq Kanta Misrishah Lahore &nbsp;|&nbsp; 
            <i class="fas fa-phone"></i> Phone: 0300-1234567 / 0321-7654321
          </div>
        </div>
      </div>
      <div class="text-right">
        <h4 class="font-weight-bold text-primary mb-1">INVENTORY STOCK REPORT</h4>
        <div style="font-size: 11px;"><strong>Report Date:</strong> <?= date('d-M-Y h:i A') ?></div>
        <div style="font-size: 11px;"><strong>Period:</strong> <?= ($from_date || $to_date) ? (($from_date ? formatDate($from_date) : 'Start') . ' to ' . ($to_date ? formatDate($to_date) : 'Present')) : 'All Time / Live Stock' ?></div>
        <div style="font-size: 11px;"><strong>Category:</strong> <?= htmlspecialchars($cat_name_display) ?></div>
        <div style="font-size: 11px;"><strong>Printed By:</strong> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></div>
      </div>
    </div>

    <!-- Print Summary Metrics Box -->
    <div class="row mt-2 text-center" style="font-size: 11px;">
      <div class="col-3 border py-1 bg-light">
        <span class="text-muted text-uppercase d-block" style="font-size:9px;">Total Materials</span>
        <strong><?= count($items) ?> Items</strong>
      </div>
      <div class="col-3 border py-1 bg-light">
        <span class="text-muted text-uppercase d-block" style="font-size:9px;">Available Stock Weight</span>
        <strong><?= number_format($total_qty, 2) ?> kg</strong> (<?= number_format($total_qty / 1000, 2) ?> MT)
      </div>
      <div class="col-3 border py-1 bg-light">
        <span class="text-muted text-uppercase d-block" style="font-size:9px;">Stock Purchase Valuation</span>
        <strong>PKR <?= formatCurrency($total_cost_val) ?></strong>
      </div>
      <div class="col-3 border py-1 bg-light">
        <span class="text-muted text-uppercase d-block" style="font-size:9px;">Stock Market Valuation</span>
        <strong>PKR <?= formatCurrency($total_sale_val) ?></strong>
      </div>
    </div>
  </div>

  <!-- Summary Stat Cards (Screen Only) -->
  <div class="row mb-3 d-print-none">
    <div class="col-xl-3 col-md-6 mb-2">
      <div class="card border-left-primary shadow-sm h-100 py-2">
        <div class="card-body py-2">
          <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Available Stock</div>
          <div class="h5 mb-0 font-weight-bold text-gray-800">
            <?= number_format($total_qty, 2) ?> <small class="text-muted">kg</small>
          </div>
          <small class="text-muted"><?= number_format($total_qty / 1000, 2) ?> Metric Tons</small>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-2">
      <div class="card border-left-success shadow-sm h-100 py-2">
        <div class="card-body py-2">
          <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Stock Purchase Value</div>
          <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?= formatCurrency($total_cost_val) ?></div>
          <small class="text-muted">Investment at Purchase Cost</small>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-2">
      <div class="card border-left-info shadow-sm h-100 py-2">
        <div class="card-body py-2">
          <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Stock Sale Value</div>
          <div class="h5 mb-0 font-weight-bold text-gray-800">PKR <?= formatCurrency($total_sale_val) ?></div>
          <small class="text-muted">Estimated Market Value</small>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-2">
      <div class="card border-left-warning shadow-sm h-100 py-2">
        <div class="card-body py-2">
          <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Stock Health / Alerts</div>
          <div class="h5 mb-0 font-weight-bold">
            <span class="text-danger"><?= $out_of_stock_cnt ?> Out</span> &nbsp;|&nbsp;
            <span class="text-warning"><?= $low_stock_cnt ?> Low</span>
          </div>
          <small class="text-muted"><?= count($items) ?> Total Materials</small>
        </div>
      </div>
    </div>
  </div>

  <!-- Filters Form (Screen Only) -->
  <div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
      <form method="get" id="stockFilterForm" class="row g-2 align-items-center">
        <div class="col-md-3">
          <input type="text" name="q" class="form-control form-control-sm"
                 placeholder="Search material or code..." value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="col-md-2">
          <select name="category_id" class="form-control form-control-sm">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $cat_filter == $c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <select name="stock_status" class="form-control form-control-sm">
            <option value="">All Stock Levels</option>
            <option value="ok" <?= $status_filter === 'ok' ? 'selected' : '' ?>>In Stock (Healthy)</option>
            <option value="low" <?= $status_filter === 'low' ? 'selected' : '' ?>>Low Stock</option>
            <option value="out" <?= $status_filter === 'out' ? 'selected' : '' ?>>Out of Stock (Zero)</option>
          </select>
        </div>
        <div class="col-md-2">
          <div class="input-group input-group-sm">
            <div class="input-group-prepend"><span class="input-group-text font-weight-bold" style="font-size:11px;">From</span></div>
            <input type="date" name="from" id="stockFromDate" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date) ?>" title="From date">
          </div>
        </div>
        <div class="col-md-2">
          <div class="input-group input-group-sm">
            <div class="input-group-prepend"><span class="input-group-text font-weight-bold" style="font-size:11px;">To</span></div>
            <input type="date" name="to" id="stockToDate" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date) ?>" title="To date">
          </div>
        </div>
        <div class="col-md-1 d-flex gap-1">
          <button type="submit" class="btn btn-primary btn-sm flex-fill" title="Apply Filter">
            <i class="fas fa-filter"></i>
          </button>
          <?php if ($q !== '' || $cat_filter !== '' || $status_filter !== '' || $from_date !== '' || $to_date !== ''): ?>
          <a href="stock_report.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
            <i class="fas fa-times"></i>
          </a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Stock Details Table -->
  <div class="card shadow">
    <div class="card-header d-flex justify-content-between align-items-center d-print-none">
      <h6 class="mb-0 font-weight-bold text-primary">
        <i class="fas fa-list"></i> Materials Inventory Register (<?= count($items) ?> records)
      </h6>
      <span class="badge badge-secondary"><?= htmlspecialchars($cat_name_display) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0 align-middle">
          <thead class="thead-light">
            <tr>
              <th style="width: 35px;">#</th>
              <th style="width: 80px;">Item Code</th>
              <th>Material Name</th>
              <th>Category</th>
              <th class="text-right"><?= ($from_date || $to_date) ? 'Period Pur.' : 'Total Pur.' ?></th>
              <th class="text-right"><?= ($from_date || $to_date) ? 'Period Sold' : 'Total Sold' ?></th>
              <th class="text-right font-weight-bold" style="background:#f8fafc;">Current Stock</th>
              <th class="text-center" style="width: 90px;">Status</th>
              <th class="text-center d-print-none" style="width: 95px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $i => $it): ?>
            <?php
              $stock = (float)$it['stock_quantity'];
              $cost_val = $stock * (float)$it['purchase_price'];
              $is_out = ($stock <= 0);
              $is_low = ($stock > 0 && $stock <= (float)$it['min_stock_level']);
            ?>
            <tr class="<?= $is_out ? 'table-danger' : ($is_low ? 'table-warning' : '') ?>">
              <td><?= $i + 1 ?></td>
              <td><code><?= htmlspecialchars($it['code']) ?></code></td>
              <td class="font-weight-bold text-dark">
                <?= htmlspecialchars($it['name']) ?>
              </td>
              <td><?= htmlspecialchars($it['cat_name'] ?? '—') ?></td>
              <td class="text-right text-muted">
                <?= number_format((float)$it['total_purchased'], 2) ?> <?= htmlspecialchars($it['unit']) ?>
              </td>
              <td class="text-right text-muted">
                <?= number_format((float)$it['total_sold'], 2) ?> <?= htmlspecialchars($it['unit']) ?>
              </td>
              <td class="text-right font-weight-bold" style="background:#f8fafc; font-size:13px;">
                <?= number_format($stock, 2) ?> <?= htmlspecialchars($it['unit']) ?>
              </td>
              <td class="text-center" nowrap>
                <?php if ($is_out): ?>
                  <span class="badge badge-danger">Out of Stock</span>
                <?php elseif ($is_low): ?>
                  <span class="badge badge-warning">Low Stock</span>
                <?php else: ?>
                  <span class="badge badge-success">In Stock</span>
                <?php endif; ?>
              </td>
              <td class="text-center d-print-none" nowrap>
                <a href="product_edit.php?id=<?= $it['id'] ?>&return=stock_report"
                   class="btn btn-sm btn-outline-warning" title="Edit Material">
                  <i class="fas fa-edit"></i>
                </a>
                <?php if (isAdmin()): ?>
                <form method="post" action="product_delete.php" class="d-inline"
                      onsubmit="return confirm('Are you sure you want to delete \'<?= htmlspecialchars(addslashes($it['name'])) ?>\'?');">
                  <input type="hidden" name="id" value="<?= $it['id'] ?>">
                  <input type="hidden" name="redirect" value="stock_report.php">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Material">
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>

            <?php if (!count($items)): ?>
            <tr>
              <td colspan="12" class="text-center text-muted py-4">
                No materials match the selected filters.
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
          <tfoot class="font-weight-bold bg-light">
            <tr>
              <td colspan="4" class="text-right">TOTALS (<?= count($items) ?> Materials):</td>
              <td colspan="2"></td>
              <td class="text-right text-dark" style="font-size:13px;">
                <?= number_format($total_qty, 2) ?> kg
              </td>
              <td></td>
              <td class="d-print-none"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- Signatures Footer (Print Only) -->
  <div class="d-none d-print-block mt-4 pt-3 border-top">
    <div class="row text-center">
      <div class="col-4">
        <div style="border-top: 1px dashed #000; width: 80%; margin: 30px auto 5px;"></div>
        <strong>Prepared By</strong>
        <div class="small text-muted"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></div>
      </div>
      <div class="col-4">
        <div style="border-top: 1px dashed #000; width: 80%; margin: 30px auto 5px;"></div>
        <strong>Store In-Charge</strong>
        <div class="small text-muted">Signature &amp; Stamp</div>
      </div>
      <div class="col-4">
        <div style="border-top: 1px dashed #000; width: 80%; margin: 30px auto 5px;"></div>
        <strong>Authorized Signatory</strong>
        <div class="small text-muted">ARAB KHEL</div>
      </div>
    </div>
    <div class="text-center text-muted small mt-3">
      Report Generated on <?= date('d-m-Y h:i A') ?> &middot; System Software by ARAB KHEL &middot; Misrishah Lahore
    </div>
  </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
