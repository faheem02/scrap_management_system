<?php
if (!isset($base_url)) {
    $script_file = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $app_root = str_replace('\\', '/', dirname(__DIR__));
    $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    $app_len = strlen($app_root);
    if ($script_file && $script_name && stripos($script_file, $app_root) === 0) {
        $rel = ltrim(substr($script_file, $app_len), '/');
        $base_url = substr($script_name, 0, strlen($script_name) - strlen($rel));
    } else {
        $parts = explode('/', trim($script_name, '/'));
        $base_url = !empty($parts[0]) ? '/' . $parts[0] . '/' : '/';
    }
    if (substr($base_url, -1) !== '/') {
        $base_url .= '/';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= $page_title ?? 'Dashboard' ?> | ARAB KHEL</title>
  <link rel="icon" type="image/png" href="<?= $base_url ?>assets/img/logo.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="<?= $base_url ?? '' ?>assets/css/style.css?v=10">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <script>
    function downloadPDF(elementId, filename, orientation) {
      var el = document.getElementById(elementId) || document.querySelector('.card.shadow');
      if (!el) { window.print(); return; }

      var btn = event && event.currentTarget ? event.currentTarget : null;
      var origBtnHtml = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
      }

      var isLandscape = (orientation === 'landscape');
      // Usable ratio (height / width) on A4 with 6mm margins
      var targetRatio = isLandscape ? (198 / 285) : (285 / 198);

      // Create a clean background container positioned off-screen so html2canvas can render it
      var container = document.createElement('div');
      container.style.position = 'absolute';
      container.style.left = '-9999px';
      container.style.top = '0';
      container.style.width = isLandscape ? '1120px' : '794px';
      container.style.background = '#ffffff';
      container.style.color = '#000000';
      container.style.zIndex = '9999';
      container.style.overflow = 'visible';

      var clone = el.cloneNode(true);
      // Remove screen-only headers, toolbars, buttons, links and forms from PDF
      clone.querySelectorAll('.d-print-none, .no-print, .action-toolbar, .card-header, button, a.btn, input[type="button"], input[type="submit"], form').forEach(function(n) {
        n.remove();
      });
      // Remove table action columns from PDF
      clone.querySelectorAll('th.d-print-none, td.d-print-none').forEach(function(n) {
        n.remove();
      });
      // Show printable headers in PDF
      clone.querySelectorAll('.d-none.d-print-block').forEach(function(n) {
        n.classList.remove('d-none');
      });
      // Ensure responsive tables expand fully without scroll clipping
      clone.querySelectorAll('.table-responsive').forEach(function(n) {
        n.style.overflow = 'visible';
      });
      // Ensure all date cells, amount cells, and text-nowrap elements stay strictly on 1 line
      clone.querySelectorAll('.text-nowrap, th:first-child, td:first-child, .text-right, th.text-right, td.text-right').forEach(function(n) {
        n.style.whiteSpace = 'nowrap';
        n.style.wordBreak = 'keep-all';
      });

      container.appendChild(clone);
      document.body.appendChild(container);

      // Measure rendered height vs width
      var cloneW = clone.offsetWidth || (isLandscape ? 1120 : 794);
      var cloneH = clone.scrollHeight;
      var renderedRatio = cloneH / cloneW;
      var pages = renderedRatio / targetRatio;

      var margin = [6, 6, 6, 6];
      // User rule: If next page has just 1 line (or up to ~26% overflow), adjust into 1 single page!
      // If there are many more lines, let it cleanly go into 2+ pages.
      if (pages > 1.00 && pages <= 1.28) {
        var scale = Math.max(0.78, Math.min(0.97, (0.97 / pages)));
        clone.style.transform = 'scale(' + scale + ')';
        clone.style.transformOrigin = 'top left';
        clone.style.width = (100 / scale) + '%';
        margin = [4, 4, 4, 4];
      } else if (pages > 2.00 && (pages - 2.00) <= 0.15) {
        // Just 1 line spilling onto page 3 -> pull back into 2 pages
        var scale = Math.max(0.88, (1.98 / pages));
        clone.style.transform = 'scale(' + scale + ')';
        clone.style.transformOrigin = 'top left';
        clone.style.width = (100 / scale) + '%';
        margin = [4, 4, 4, 4];
      }

      var opt = {
        margin: margin,
        filename: (filename || 'ARAB_KHEL_Document') + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 3, useCORS: true, scrollX: 0, scrollY: 0, logging: false },
        jsPDF: { unit: 'mm', format: 'a4', orientation: orientation || 'portrait' },
        pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
      };

      function cleanup() {
        if (document.body.contains(container)) {
          document.body.removeChild(container);
        }
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = origBtnHtml;
        }
      }

      html2pdf().set(opt).from(clone).save().then(cleanup).catch(function(err) {
        console.error('PDF generation error:', err);
        cleanup();
      });
    }

    function setDatePreset(fromId, toId, preset, formToSubmit) {
      var fromInput = document.getElementById(fromId);
      var toInput = document.getElementById(toId);
      if (!fromInput || !toInput) return;
      var now = new Date();
      var pad = function(n) { return (n < 10 ? '0' : '') + n; };
      var fmt = function(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };

      if (preset === 'today') {
        fromInput.value = fmt(now);
        toInput.value = fmt(now);
      } else if (preset === 'yesterday') {
        var y = new Date(now);
        y.setDate(y.getDate() - 1);
        fromInput.value = fmt(y);
        toInput.value = fmt(y);
      } else if (preset === 'this_month') {
        var firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
        fromInput.value = fmt(firstDay);
        toInput.value = fmt(now);
      } else if (preset === 'all') {
        fromInput.value = '';
        toInput.value = '';
      }
      if (formToSubmit) {
        if (typeof formToSubmit === 'string') {
          var f = document.getElementById(formToSubmit);
          if (f) f.submit();
        } else if (formToSubmit.submit) {
          formToSubmit.submit();
        }
      }
    }
  </script>
</head>
<body>

<!-- Sidebar Overlay (mobile drawer backdrop) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div id="wrapper">

  <!-- ===== SIDEBAR ===== -->
  <nav class="sidebar" id="sidebar">

    <a class="sidebar-brand" href="<?= $base_url ?? '' ?>index.php">
      <div class="sidebar-brand-icon">
        <img src="<?= $base_url ?? '' ?>assets/img/logo.png" alt="ARAB KHEL" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1.5px solid #10b981; background: #fff;">
      </div>
      <div>
        <span class="sidebar-brand-text">ARAB KHEL</span>
        <span class="sidebar-brand-sub" style="font-size:10px;">Near Itifaq Kanta Misrishah Lahore</span>
      </div>
    </a>

    <hr class="sidebar-divider">

    <!-- Dashboard -->
    <div class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
      <a class="nav-link" href="<?= $base_url ?? '' ?>index.php">
        <i class="fas fa-fw fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </a>
    </div>

    <hr class="sidebar-divider">

    <div class="sidebar-heading"><span>Management</span></div>

    <!-- Purchase (Admin only) -->
    <?php if (isAdmin()): ?>
    <div class="nav-item">
      <?php $on_purchase = str_contains($_SERVER['PHP_SELF'],'purchases/'); ?>
      <a class="nav-link <?= $on_purchase ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapsePurchase" role="button" aria-expanded="<?= $on_purchase ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-cart-arrow-down"></i>
        <span>Purchase</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_purchase ? 'show' : '' ?>" id="collapsePurchase">
        <div class="collapse-inner">
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'purchases/create') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/purchases/create.php"><i class="fas fa-plus-circle"></i> New Purchase</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'purchases/index') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/purchases/index.php"><i class="fas fa-list"></i> Purchase List</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'purchases/plant_areas') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/purchases/plant_areas.php"><i class="fas fa-map-marker-alt"></i> Plant Areas</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Materials (Admin: full | Sales team & Loader: view only) -->
    <div class="nav-item">
      <?php $on_product = str_contains($_SERVER['PHP_SELF'],'inventory/') && !str_contains($_SERVER['PHP_SELF'],'stock_report'); ?>
      <a class="nav-link <?= $on_product ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseProduct" role="button" aria-expanded="<?= $on_product ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-box"></i>
        <span>Materials</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_product ? 'show' : '' ?>" id="collapseProduct">
        <div class="collapse-inner">
          <?php if (isAdmin()): ?>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'inventory/product_create') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/product_create.php"><i class="fas fa-plus-circle"></i> Add Material</a>
          <?php endif; ?>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'inventory/products') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/products.php"><i class="fas fa-box"></i> Materials List</a>
          <?php if (isAdmin()): ?>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'inventory/categor') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/categories.php"><i class="fas fa-tags"></i> Categories</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Customers (Admin + Sales team) -->
    <?php if (isAdmin() || isSalesTeam()): ?>
    <div class="nav-item">
      <?php $on_customer = str_contains($_SERVER['PHP_SELF'],'customers/') || str_contains($_SERVER['PHP_SELF'],'receive_customer'); ?>
      <a class="nav-link <?= $on_customer ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseCustomer" role="button" aria-expanded="<?= $on_customer ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-users"></i>
        <span>Customers</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_customer ? 'show' : '' ?>" id="collapseCustomer">
        <div class="collapse-inner">
          <?php if (isAdmin() || isSalesTeam()): ?>
          <a class="collapse-item <?= (str_contains($_SERVER['PHP_SELF'],'customers/customers') && isset($_GET['add'])) ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/customers/customers.php?add=1"><i class="fas fa-user-plus"></i> Add Customer</a>
          <?php endif; ?>
          <a class="collapse-item <?= (str_contains($_SERVER['PHP_SELF'],'customers/customers') && !isset($_GET['add'])) ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/customers/customers.php"><i class="fas fa-address-card"></i> View Customers</a>
          <?php if (isAdmin()): ?>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'receive_customer') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/transactions/receive_customer.php"><i class="fas fa-hand-holding-usd"></i> Pay Amount</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>



    <hr class="sidebar-divider">

    <div class="sidebar-heading"><span>Finance</span></div>

    <!-- Sales (Admin + Sales team) -->
    <?php if (isAdmin() || isSalesTeam()): ?>
    <div class="nav-item">
      <?php $on_sales = str_contains($_SERVER['PHP_SELF'],'sales/'); ?>
      <a class="nav-link <?= $on_sales ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseSales" role="button" aria-expanded="<?= $on_sales ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-shopping-cart"></i>
        <span>Sales</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_sales ? 'show' : '' ?>" id="collapseSales">
        <div class="collapse-inner">
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'sales/index') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/sales/index.php"><i class="fas fa-plus-circle"></i> New Sale</a>
          <a class="collapse-item <?= (str_contains($_SERVER['PHP_SELF'],'sales/invoices') || str_contains($_SERVER['PHP_SELF'],'sales/invoice.')) ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/sales/invoices.php"><i class="fas fa-file-invoice"></i> Invoices</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (isAdmin()): ?>
    <!-- Cash Book -->
    <div class="nav-item">
      <a class="nav-link <?= str_contains($_SERVER['PHP_SELF'],'cashbook') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/cashbook/index.php">
        <i class="fas fa-fw fa-money-bill-wave"></i>
        <span>Cash Book</span>
      </a>
    </div>

    <!-- Bank Book -->
    <div class="nav-item">
      <a class="nav-link <?= str_contains($_SERVER['PHP_SELF'],'bankbook') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/bankbook/index.php">
        <i class="fas fa-fw fa-university"></i>
        <span>Bank Book</span>
      </a>
    </div>

    <!-- Transfers -->
    <div class="nav-item">
      <a class="nav-link <?= (str_contains($_SERVER['PHP_SELF'],'transfers.php') || str_contains($_SERVER['PHP_SELF'],'voucher.php')) ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/transactions/transfers.php">
        <i class="fas fa-fw fa-exchange-alt"></i>
        <span>Transfers</span>
      </a>
    </div>

    <!-- Expenses -->
    <div class="nav-item">
      <?php $on_expense = str_contains($_SERVER['PHP_SELF'],'expenses'); ?>
      <a class="nav-link <?= $on_expense ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseExpense" role="button" aria-expanded="<?= $on_expense ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-file-invoice-dollar"></i>
        <span>Expenses</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_expense ? 'show' : '' ?>" id="collapseExpense">
        <div class="collapse-inner">
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'expenses/create') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/expenses/create.php"><i class="fas fa-plus-circle"></i> New Expense</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'expenses/index') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/expenses/index.php"><i class="fas fa-file-invoice-dollar"></i> All Expenses</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'expenses/categories') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/expenses/categories.php"><i class="fas fa-tags"></i> Categories</a>
        </div>
      </div>
    </div>

    <hr class="sidebar-divider">

    <div class="sidebar-heading"><span>Others</span></div>

    <!-- Reports -->
    <div class="nav-item">
      <?php $on_report = str_contains($_SERVER['PHP_SELF'],'reports') || str_contains($_SERVER['PHP_SELF'],'stock_report'); ?>
      <a class="nav-link <?= $on_report ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseReports" role="button" aria-expanded="<?= $on_report ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-chart-bar"></i>
        <span>Reports</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_report ? 'show' : '' ?>" id="collapseReports">
        <div class="collapse-inner">
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'inventory/stock_report') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/stock_report.php"><i class="fas fa-warehouse"></i> Stock Report</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'reports/index') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/reports/index.php"><i class="fas fa-chart-line"></i> Financial Summary</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Sidebar Footer (gym-style) -->
    <div class="sidebar-footer">
      <a class="nav-link" href="<?= $base_url ?? '' ?>login.php?logout=1"><i class="fas fa-fw fa-sign-out-alt"></i> <span>Logout</span></a>
    </div>
  </nav>
  <!-- End Sidebar -->

  <!-- ===== CONTENT WRAPPER ===== -->
  <div id="content-wrapper">

    <!-- Topbar -->
    <header class="topbar">
      <button class="sidebar-toggle-btn" id="sidebarMobileToggle">
        <i class="fas fa-bars"></i>
      </button>
      <div class="page-title"><?= $page_title ?? 'Dashboard' ?></div>
      <div class="user-area">
        <span class="badge <?= isAdmin() ? 'badge-success' : 'badge-info' ?> d-none d-md-inline"><?= roleLabel($user_role) ?></span>
        <div class="dropdown">
          <button class="btn btn-link text-muted dropdown-toggle p-0" data-toggle="dropdown">
            <i class="fas fa-user-circle fa-lg"></i>
            <span class="ml-1 d-none d-sm-inline"><?= $_SESSION['user_name'] ?? 'Admin' ?></span>
          </button>
          <div class="dropdown-menu dropdown-menu-right">
            <a class="dropdown-item" href="<?= $base_url ?? '' ?>modules/profile/index.php">
              <i class="fas fa-user-circle fa-sm mr-2"></i> My Profile
            </a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="<?= $base_url ?? '' ?>login.php?logout=1">
              <i class="fas fa-sign-out-alt fa-sm mr-2 text-danger"></i> Logout
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- Page Content -->
    <div class="content">

      <!-- Page Heading -->
      <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#0f172a;"><?= $page_title ?? 'Dashboard' ?></h1>
      </div>

      <!-- Flash Messages -->
      <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= $_SESSION['success']; unset($_SESSION['success']); ?>
          <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
      <?php endif; ?>
      <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= $_SESSION['error']; unset($_SESSION['error']); ?>
          <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
      <?php endif; ?>