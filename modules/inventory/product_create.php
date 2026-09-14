<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Add Material';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '') ?: generateProductCode();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        redirect('product_create.php', 'Material name is required', 'error');
    }
    // Check unique code
    $chk = $pdo->prepare("SELECT id FROM products WHERE code = ?");
    $chk->execute([$code]);
    if ($chk->fetch()) {
        $code = generateProductCode();
    }

    insert('products', [
        'code' => $code,
        'name' => $name,
        'description' => trim($_POST['description'] ?? ''),
        'category_id' => $_POST['category_id'] ?: null,
        'unit' => $_POST['unit'] ?: 'kg',
        'purchase_price' => 0,
        'sale_price' => 0,
        'stock_quantity' => $_POST['stock_quantity'] ?: 0,
        'min_stock_level' => $_POST['min_stock_level'] ?: 0,
        'status' => 1,
        'created_at' => date('Y-m-d'),
    ]);
    logActivity($pdo, 'create', 'product', null, 'Created product: ' . $name . ' (code: ' . $code . ')');
    redirect('products.php', 'Material added successfully');
}

$auto_code = generateProductCode();
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="fas fa-box"></i> Add New Material</h6>
    <a href="products.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-left"></i> Back to Materials</a>
  </div>
  <div class="card-body">
    <form method="post" autocomplete="off">
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label font-weight-bold">Item Code <span class="badge badge-info">Auto</span></label>
          <input type="text" name="code" class="form-control bg-light font-weight-bold text-primary" value="<?= htmlspecialchars($auto_code) ?>" readonly>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label font-weight-bold">Material Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. Iron Scrap, Copper Wire, Paper Waste" autofocus>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label font-weight-bold">Unit</label>
          <select name="unit" class="form-control">
            <option value="kg" selected>Kg</option>
            <option value="ton">Ton</option>
            <option value="pcs">Piece (pcs)</option>
            <option value="gadda">Gadda</option>
            <option value="pack">Pack</option>
            <option value="dozen">Dozen</option>
            <option value="meter">Meter</option>
            <option value="liter">Liter</option>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-control">
            <option value="">-- Select --</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Description</label>
          <input type="text" name="description" class="form-control" placeholder="Optional">
        </div>
      </div>

      <hr>
      <h6 class="mb-3 text-secondary">Pricing &amp; Stock</h6>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Opening Stock</label>
          <input type="number" min="0" step="any" name="stock_quantity" class="form-control" value="0">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Min Stock Alert Level</label>
          <input type="number" min="0" step="any" name="min_stock_level" class="form-control" value="0">
        </div>
      </div>

      <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save"></i> Save Material</button>
    </form>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>