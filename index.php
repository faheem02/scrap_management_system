<?php
require_once 'includes/functions.php';
$page_title = 'Dashboard';
require_once 'includes/auth.php';

// ===== TODAY =====
$today_purchases = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
$today_purchase_count = $pdo->query("SELECT COUNT(*) FROM purchases WHERE purchase_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
$today_sales = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
$today_sale_count = $pdo->query("SELECT COUNT(*) FROM sales WHERE sale_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
$today_expenses = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = CURDATE()")->fetchColumn();
$today_cash_in = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_date = CURDATE() AND transaction_type = 'inflow'")->fetchColumn();
$today_cash_out = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE transaction_date = CURDATE() AND transaction_type = 'outflow'")->fetchColumn();

// ===== FREIGHT CHARGES (Kiraya) =====
$today_freight_paid_purchases = (float)$pdo->query("SELECT COALESCE(SUM(paid_amount),0) FROM purchases WHERE purchase_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
$today_freight_total_purchases = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
$today_freight_vehicles = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE purchase_date = CURDATE() AND status <> 'cancelled'")->fetchColumn();
$today_freight_expenses = (float)$pdo->query("
    SELECT COALESCE(SUM(e.amount),0)
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date = CURDATE()
      AND (LOWER(ec.name) LIKE '%transport%' OR LOWER(ec.name) LIKE '%freight%' OR LOWER(ec.name) LIKE '%kiraya%'
           OR LOWER(e.description) LIKE '%transport%' OR LOWER(e.description) LIKE '%freight%' OR LOWER(e.description) LIKE '%kiraya%')
")->fetchColumn();
$today_freight_paid = $today_freight_paid_purchases + $today_freight_expenses;
$today_freight_total = $today_freight_total_purchases + $today_freight_expenses;
$total_freight_due = (float)$pdo->query("SELECT COALESCE(SUM(due_amount),0) FROM purchases WHERE status <> 'cancelled'")->fetchColumn();
$total_vehicles_count = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE status <> 'cancelled'")->fetchColumn();

// ===== CASH / BANK =====
$cash_in_hand = $pdo->query("SELECT closing_balance FROM cash_book_daily ORDER BY date DESC LIMIT 1")->fetchColumn();
if (!$cash_in_hand) $cash_in_hand = 0;
$bank_total = $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM bank_accounts WHERE status = 1")->fetchColumn();

// ===== UDHARR / BALANCES =====
$cbal = $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM customers")->fetchColumn();
$customer_receivable = $cbal > 0 ? $cbal : 0;    // we are to receive
$customer_advance = $cbal < 0 ? abs($cbal) : 0;  // we owe customer

$sbal = $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM suppliers")->fetchColumn();
$supplier_payable = $sbal > 0 ? $sbal : 0;      // we owe supplier

// ===== COUNTS =====
$total_customers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$total_suppliers = $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
$total_products = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 1")->fetchColumn();
$total_employees = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 1")->fetchColumn();

// ===== STOCK =====
$low_stock_products = $pdo->query("
    SELECT p.id, p.code, p.name, p.stock_quantity, p.min_stock_level, p.sale_price,
           c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 1 AND p.min_stock_level > 0 AND p.stock_quantity <= p.min_stock_level
    ORDER BY (p.min_stock_level - p.stock_quantity) DESC
    LIMIT 8
")->fetchAll();
$low_stock_count = count($low_stock_products);
$out_of_stock = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 1 AND stock_quantity <= 0")->fetchColumn();

$stock_value = $pdo->query("SELECT COALESCE(SUM(stock_quantity * purchase_price),0) FROM products WHERE status = 1")->fetchColumn();

// ===== RECENT =====
$recent_purchases = $pdo->query("
    SELECT p.id, p.invoice_no, p.vehicle_no, p.party_name, p.total_amount, p.paid_amount, p.due_amount, p.purchase_date
    FROM purchases p
    WHERE p.status <> 'cancelled' ORDER BY p.id DESC LIMIT 5
")->fetchAll();

$recent_sales = $pdo->query("
    SELECT s.id, s.invoice_no, s.total_amount, s.paid_amount, s.due_amount, s.sale_date, c.full_name
    FROM sales s LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.status <> 'cancelled' ORDER BY s.id DESC LIMIT 5
")->fetchAll();

$recent_cash = $pdo->query("
    SELECT transaction_type, amount, description, transaction_date
    FROM cash_book ORDER BY id DESC LIMIT 6
")->fetchAll();


require_once 'includes/header.php';
$today_dt = date('l, d M Y');
$greeting = (int)date('H') < 12 ? 'Good Morning' : ((int)date('H') < 17 ? 'Good Afternoon' : 'Good Evening');
$user_name = $_SESSION['user_name'] ?? 'Admin';
?>

<!-- Greeting / Quick actions -->
<div class="greet-bar mb-4">
  <div class="row align-items-center">
    <div class="col-lg-7 mb-3 mb-lg-0">
      <h4 class="mb-1"><?= $greeting ?>, <?= htmlspecialchars($user_name) ?>!</h4>
      <p class="mb-0"><i class="far fa-calendar-alt"></i> <?= $today_dt ?>
        <span class="mx-2 d-none d-sm-inline">|</span>
        <span class="text-muted d-none d-sm-inline">ARAB KHEL &middot; Near Itifaq Kanta Misrishah Lahore</span>
      </p>
    </div>
    <div class="col-lg-5 text-lg-right">
      <?php if (isAdmin()): ?>
      <a href="<?= $base_url ?? '' ?>modules/sales/index.php" class="btn btn-light btn-sm mr-1 mb-1"><i class="fas fa-shopping-cart text-success"></i> New Sale</a>
      <a href="<?= $base_url ?? '' ?>modules/purchases/create.php" class="btn btn-light btn-sm mr-1 mb-1"><i class="fas fa-cart-arrow-down text-info"></i> Purchase</a>
      <a href="<?= $base_url ?? '' ?>modules/transactions/receive_customer.php" class="btn btn-light btn-sm mb-1"><i class="fas fa-hand-holding-usd text-success"></i> Receive</a>
      <?php elseif (isSalesTeam()): ?>
      <a href="<?= $base_url ?? '' ?>modules/sales/index.php" class="btn btn-light btn-sm mr-1 mb-1"><i class="fas fa-shopping-cart text-success"></i> New Sale</a>
      <a href="<?= $base_url ?? '' ?>modules/sales/invoices.php" class="btn btn-light btn-sm mb-1"><i class="fas fa-file-invoice text-info"></i> Invoices</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- KPI Cards Row 1: Daily Operations (3 Cards) -->
<div class="row">
  <?php if (isAdmin() || isSalesTeam()): ?>
  <div class="col-lg-4 col-md-6 mb-3">
    <div class="card stat-card border-left-primary h-100">
      <div class="card-body py-3 px-4">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Today's Sales</div>
            <div class="stat-value text-primary"><?= formatCurrency($today_sales) ?></div>
            <div class="stat-sub"><i class="fas fa-shopping-bag"></i> <?= (int)$today_sale_count ?> invoice(s)</div>
          </div>
          <div class="col-auto icon-circle icon-emerald"><i class="fas fa-shopping-cart"></i></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (isAdmin()): ?>
  <div class="col-lg-4 col-md-6 mb-3">
    <div class="card stat-card border-left-info h-100">
      <div class="card-body py-3 px-4">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Today's Purchases</div>
            <div class="stat-value text-info"><?= formatCurrency($today_purchases) ?></div>
            <div class="stat-sub"><i class="fas fa-box"></i> <?= (int)$today_purchase_count ?> purchase(s)</div>
          </div>
          <div class="col-auto icon-circle icon-info"><i class="fas fa-cart-arrow-down"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4 col-md-6 mb-3">
    <div class="card stat-card h-100" style="border-left: 4px solid #6366f1 !important;">
      <div class="card-body py-3 px-4">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label" style="color: #4f46e5;">Freight Paid (Today)</div>
            <div class="stat-value" style="color: #312e81;"><?= formatCurrency($today_freight_paid) ?></div>
            <div class="stat-sub">
              <span><i class="fas fa-truck text-muted"></i> <?= (int)$today_freight_vehicles ?> vehicle(s)</span>
              <?php if ($total_freight_due > 0): ?>
                <span class="mx-1">&middot;</span>
                <span class="text-danger font-weight-bold" title="Outstanding Freight Due">Due: <?= formatCurrency($total_freight_due) ?></span>
              <?php endif; ?>
              <a href="<?= $base_url ?? '' ?>modules/purchases/index.php" class="link-sub ml-1" style="color: #4f46e5;">View</a>
            </div>
          </div>
          <div class="col-auto icon-circle" style="background: linear-gradient(135deg, #6366f1, #4338ca); color: #fff;">
            <i class="fas fa-truck-moving"></i>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- KPI Cards Row 2: Cash & Funds (3 Cards) -->
<?php if (isAdmin()): ?>
<div class="row">
  <div class="col-lg-4 col-md-6 mb-3">
    <div class="card stat-card border-left-success h-100">
      <div class="card-body py-3 px-4">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Cash in Hand</div>
            <div class="stat-value text-success"><?= formatCurrency($cash_in_hand) ?></div>
            <div class="stat-sub">
              <span class="text-success font-weight-bold">+<?= formatCurrency($today_cash_in) ?></span>
              <span class="mx-1 text-muted">/</span>
              <span class="text-danger font-weight-bold">-<?= formatCurrency($today_cash_out) ?></span> today
            </div>
          </div>
          <div class="col-auto icon-circle icon-cash"><i class="fas fa-money-bill-wave"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4 col-md-6 mb-3">
    <div class="card stat-card border-left-secondary h-100">
      <div class="card-body py-3 px-4">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Bank Balance</div>
            <div class="stat-value" style="color: #475569;"><?= formatCurrency($bank_total) ?></div>
            <div class="stat-sub"><i class="fas fa-university"></i> <?= count($pdo->query("SELECT id FROM bank_accounts WHERE status = 1")->fetchAll()) ?> account(s)</div>
          </div>
          <div class="col-auto icon-circle icon-bank"><i class="fas fa-piggy-bank"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4 col-md-6 mb-3">
    <div class="card stat-card border-left-danger h-100">
      <div class="card-body py-3 px-4">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Today's Expenses</div>
            <div class="stat-value text-danger"><?= formatCurrency($today_expenses) ?></div>
            <div class="stat-sub"><i class="fas fa-receipt"></i> <a href="<?= $base_url ?? '' ?>modules/expenses/index.php" class="link-sub">View expenses</a></div>
          </div>
          <div class="col-auto icon-circle icon-rose"><i class="fas fa-file-invoice-dollar"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- KPI Cards Row 3: Balances & Inventory (3 Cards) -->
<div class="row">
  <div class="col-lg-4 col-md-6 mb-3">
    <div class="card stat-card border-left-warning h-100">
      <div class="card-body py-3 px-4">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Customer Receivable (Aap Lain)</div>
            <div class="stat-value text-warning"><?= formatCurrency($customer_receivable) ?></div>
            <div class="stat-sub"><?= (int)$total_customers ?> customer(s) <a href="<?= $base_url ?? '' ?>modules/transactions/receive_customer.php" class="link-sub">Receive</a></div>
          </div>
          <div class="col-auto icon-circle icon-amber"><i class="fas fa-hand-holding-usd"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4 col-md-6 mb-3">
    <div class="card stat-card border-left-danger h-100">
      <div class="card-body py-3 px-4">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Supplier Payable (Aap Dain)</div>
            <div class="stat-value text-danger"><?= formatCurrency($supplier_payable) ?></div>
            <div class="stat-sub"><?= (int)$total_suppliers ?> supplier(s) <a href="<?= $base_url ?? '' ?>modules/transactions/pay_supplier.php" class="link-sub text-danger">Pay</a></div>
          </div>
          <div class="col-auto icon-circle icon-red"><i class="fas fa-truck-loading"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4 col-md-6 mb-3">
    <div class="card stat-card border-left-warning h-100">
      <div class="card-body py-3 px-4">
        <div class="row no-gutters align-items-center">
          <div class="col mr-2">
            <div class="stat-label">Low / Out of Stock</div>
            <div class="stat-value text-warning"><?= (int)$low_stock_count ?> low / <?= (int)$out_of_stock ?> out</div>
            <div class="stat-sub"><?= formatCurrency($stock_value) ?> stock value</div>
          </div>
          <div class="col-auto icon-circle icon-orange"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>


<!-- Recent Activity -->
<div class="row">
  <?php if (isAdmin()): ?>
  <div class="col-lg-6 mb-3">
    <div class="card shadow h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="fas fa-truck text-info"></i> Recent Vehicle Freight (Purchases)</h6>
        <a href="<?= $base_url ?? '' ?>modules/purchases/index.php" class="btn btn-sm btn-outline-info">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Vehicle / Inv</th>
                <th>Party</th>
                <th class="text-right">Freight</th>
                <th class="text-right">Paid</th>
                <th class="text-right">Due</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($recent_purchases)): foreach ($recent_purchases as $p): ?>
                <tr>
                  <td>
                    <div class="font-weight-bold text-dark"><?= htmlspecialchars($p['vehicle_no'] ?: $p['invoice_no']) ?></div>
                    <?php if (!empty($p['vehicle_no'])): ?>
                      <small class="text-muted"><?= htmlspecialchars($p['invoice_no']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($p['party_name'] ?: 'N/A') ?></td>
                  <td class="text-right font-weight-bold"><?= formatCurrency($p['total_amount']) ?></td>
                  <td class="text-right text-success font-weight-bold"><?= formatCurrency($p['paid_amount']) ?></td>
                  <td class="text-right <?= $p['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-muted' ?>">
                    <?= $p['due_amount'] > 0 ? formatCurrency($p['due_amount']) : '—' ?>
                  </td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No vehicle freight records yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (isAdmin() || isSalesTeam()): ?>
  <div class="col-lg-6 mb-3">
    <div class="card shadow h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="fas fa-shopping-cart text-success"></i> Recent Sales</h6>
        <a href="<?= $base_url ?? '' ?>modules/sales/invoices.php" class="btn btn-sm btn-outline-success">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Invoice</th><th>Customer</th><th class="text-right">Total</th><th class="text-right">Due</th></tr></thead>
            <tbody>
              <?php if (count($recent_sales)): foreach ($recent_sales as $s): ?>
                <tr>
                  <td class="font-weight-bold"><?= htmlspecialchars($s['invoice_no']) ?></td>
                  <td><?= htmlspecialchars($s['full_name'] ?? 'N/A') ?></td>
                  <td class="text-right"><?= formatCurrency($s['total_amount']) ?></td>
                  <td class="text-right <?= $s['due_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-success' ?>"><?= formatCurrency($s['due_amount']) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No sales yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Recent Cash Activity -->
<?php if (isAdmin()): ?>
<div class="row">
  <div class="col-12 mb-3">
    <div class="card shadow">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="fas fa-money-bill-wave text-primary"></i> Recent Cash Book Activity</h6>
        <a href="<?= $base_url ?? '' ?>modules/cashbook/index.php" class="btn btn-sm btn-outline-primary">Open Cash Book</a>
      </div>
      <div class="card-body p-0">
        <ul class="activity-list list-unstyled mb-0">
          <?php if (count($recent_cash)): foreach ($recent_cash as $c): ?>
            <li class="activity-item">
              <span class="activity-dot <?= $c['transaction_type'] == 'inflow' ? 'dot-in' : 'dot-out' ?>">
                <i class="fas <?= $c['transaction_type'] == 'inflow' ? 'fa-arrow-down' : 'fa-arrow-up' ?>"></i>
              </span>
              <div class="flex-grow-1">
                <div class="activity-text"><?= htmlspecialchars($c['description']) ?></div>
                <div class="activity-date"><i class="far fa-clock"></i> <?= formatDate($c['transaction_date']) ?></div>
              </div>
              <span class="activity-amount <?= $c['transaction_type'] == 'inflow' ? 'text-success' : 'text-danger' ?>">
                <?= $c['transaction_type'] == 'inflow' ? '+' : '-' ?><?= formatCurrency(abs($c['amount'])) ?>
              </span>
            </li>
          <?php endforeach; else: ?>
            <li class="text-center text-muted py-4"><i class="far fa-list-alt"></i> No cash transactions yet</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Quick summary chips -->
<div class="row mt-1">
  <div class="col-md-3 col-6 mb-2"><a href="<?= $base_url ?? '' ?>modules/inventory/products.php" class="chip-chip"><i class="fas fa-box"></i><span class="chip-num"><?= (int)$total_products ?></span> Materials</a></div>
  <?php if (isAdmin() || isSalesTeam()): ?>
  <div class="col-md-3 col-6 mb-2"><a href="<?= $base_url ?? '' ?>modules/customers/customers.php" class="chip-chip"><i class="fas fa-users"></i><span class="chip-num"><?= (int)$total_customers ?></span> Customers</a></div>
  <?php endif; ?>
  <?php if (isAdmin()): ?>
  <div class="col-md-3 col-6 mb-2"><a href="<?= $base_url ?? '' ?>modules/employees/index.php" class="chip-chip"><i class="fas fa-user-tie"></i><span class="chip-num"><?= (int)$total_employees ?></span> Employees</a></div>
  <div class="col-md-3 col-6 mb-2"><a href="<?= $base_url ?? '' ?>modules/purchases/index.php" class="chip-chip"><i class="fas fa-truck"></i><span class="chip-num"><?= (int)$total_vehicles_count ?></span> Vehicles / Freight</a></div>
  <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>