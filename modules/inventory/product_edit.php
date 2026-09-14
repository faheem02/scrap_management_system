<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Edit Material';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
$product = $id ? getById('products', $id) : null;
if (!$product) redirect('products.php', 'Material not found', 'error');

$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name")->fetchAll();

$return = $_POST['return'] ?? ($_GET['return'] ?? '');
$back_url = ($return === 'stock_report') ? 'stock_report.php' : 'products.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { redirect('product_edit.php?id=' . $id . ($return ? '&return=' . urlencode($return) : ''), 'Material name is required', 'error'); }
    $code = trim($_POST['code'] ?? '') ?: $product['code'];
    $chk = $pdo->prepare("SELECT id FROM products WHERE code = ? AND id <> ?");
    $chk->execute([$code, $id]);
    if ($chk->fetch()) { redirect('product_edit.php?id=' . $id . ($return ? '&return=' . urlencode($return) : ''), 'Material code already exists', 'error'); }

    update('products', [
        'code' => $code,
        'name' => $name,
        'description' => trim($_POST['description'] ?? ''),
        'category_id' => $_POST['category_id'] ?: null,
        'unit' => $_POST['unit'] ?: 'kg',
        'purchase_price' => (float)($product['purchase_price'] ?? 0),
        'sale_price' => (float)($product['sale_price'] ?? 0),
        'stock_quantity' => $_POST['stock_quantity'] ?: 0,
        'min_stock_level' => $_POST['min_stock_level'] ?: 0,
        'updated_at' => date('Y-m-d'),
    ], $id);
    logActivity($pdo, 'edit', 'product', $id, 'Edited product: ' . $name . ' (code: ' . $code . ')');
    redirect($back_url, 'Material "' . $name . '" updated successfully');
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="card shadow">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="fas fa-user-edit"></i> Edit Material</h6>
    <a href="<?= $back_url ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-left"></i> <?= $return === 'stock_report' ? 'Back to Stock Report' : 'Back to Materials' ?></a>
  </div>
  <div class="card-body">
    <form method="post">
      <?php if ($return): ?><input type="hidden" name="return" value="<?= htmlspecialchars($return) ?>"><?php endif; ?>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Material Name *</label>
          <input type="text" name="name" class="form-control" required value="<?=htmlspecialchars($product['name'])?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Item Code</label>
          <input type="text" name="code" class="form-control" value="<?=htmlspecialchars($product['code'])?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Unit</label>
          <select name="unit" class="form-control">
            <option value="kg" <?= $product['unit']=='kg' ? 'selected' : '' ?>>Kg</option>
            <option value="ton" <?= $product['unit']=='ton' ? 'selected' : '' ?>>Ton</option>
            <option value="pcs" <?= $product['unit']=='pcs' ? 'selected' : '' ?>>Piece (pcs)</option>
            <option value="gadda" <?= $product['unit']=='gadda' ? 'selected' : '' ?>>Gadda</option>
            <option value="pack" <?= $product['unit']=='pack' ? 'selected' : '' ?>>Pack</option>
            <option value="dozen" <?= $product['unit']=='dozen' ? 'selected' : '' ?>>Dozen</option>
            <option value="meter" <?= $product['unit']=='meter' ? 'selected' : '' ?>>Meter</option>
            <option value="liter" <?= $product['unit']=='liter' ? 'selected' : '' ?>>Liter</option>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-control">
            <option value="">-- Select --</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?=$c['id']?>" <?= (int)$product['category_id']==(int)$c['id'] ? 'selected' : '' ?>><?=htmlspecialchars($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Description</label>
          <input type="text" name="description" class="form-control" value="<?=htmlspecialchars($product['description'] ?? '')?>">
        </div>
      </div>

      <hr>
      <h6 class="mb-3 text-secondary">Stock &amp; Alert Level</h6>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Stock (Units) *</label>
          <input type="number" min="0" step="any" name="stock_quantity" class="form-control" required value="<?=$product['stock_quantity']?>">
          <small class="text-muted">Adjust after purchase/sale</small>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Min Stock Alert Level</label>
          <input type="number" min="0" step="any" name="min_stock_level" class="form-control" value="<?=$product['min_stock_level']?>">
        </div>
      </div>

      <div class="d-flex justify-content-between mt-2">
        <a href="products.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save"></i> Update Material</button>
      </div>
    </form>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>