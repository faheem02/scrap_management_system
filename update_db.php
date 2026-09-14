<?php
// update_db.php - Safe database update for client installations without deleting existing data
require_once __DIR__ . '/config/db.php';

$messages = [];
$errors = [];

function addColumnIfNotExists($pdo, $table, $column, $definition) {
    global $messages, $errors;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            $messages[] = "Added column `{$column}` to table `{$table}`.";
        } else {
            $messages[] = "Column `{$column}` already exists in table `{$table}`.";
        }
    } catch (Exception $e) {
        $errors[] = "Error on {$table}.{$column}: " . $e->getMessage();
    }
}

function createTableIfNotExists($pdo, $table, $sql) {
    global $messages, $errors;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
            $messages[] = "Created table `{$table}`.";
        } else {
            $messages[] = "Table `{$table}` already exists.";
        }
    } catch (Exception $e) {
        $errors[] = "Error creating table {$table}: " . $e->getMessage();
    }
}

// 1. Table: fund_transfers
createTableIfNotExists($pdo, 'fund_transfers', "
CREATE TABLE `fund_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_no` varchar(50) NOT NULL,
  `transfer_type` varchar(50) NOT NULL,
  `from_type` varchar(50) NOT NULL,
  `from_bank_id` int(11) DEFAULT NULL,
  `to_type` varchar(50) NOT NULL,
  `to_bank_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `person_name` varchar(150) DEFAULT NULL,
  `person_phone` varchar(50) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `transfer_date` date NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_no` (`voucher_no`),
  KEY `idx_voucher` (`voucher_no`),
  KEY `idx_transfer_date` (`transfer_date`),
  KEY `idx_transfer_type` (`transfer_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// 2. Table: plant_areas
createTableIfNotExists($pdo, 'plant_areas', "
CREATE TABLE `plant_areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// 3. Columns in customer_receipts
addColumnIfNotExists($pdo, 'customer_receipts', 'sales_id', 'INT DEFAULT NULL AFTER `customer_id`');
addColumnIfNotExists($pdo, 'customer_receipts', 'is_split', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `created_at`');

// 4. Columns in purchases
addColumnIfNotExists($pdo, 'purchases', 'vehicle_no', 'VARCHAR(50) DEFAULT NULL AFTER `supplier_id`');
addColumnIfNotExists($pdo, 'purchases', 'party_name', 'VARCHAR(100) DEFAULT NULL AFTER `vehicle_no`');
addColumnIfNotExists($pdo, 'purchases', 'plant_area', 'VARCHAR(100) DEFAULT NULL');
addColumnIfNotExists($pdo, 'purchases', 'plant_name', 'VARCHAR(100) DEFAULT NULL');
addColumnIfNotExists($pdo, 'purchases', 'plant_category', 'VARCHAR(100) DEFAULT NULL');

// 5. Columns in purchase_items
addColumnIfNotExists($pdo, 'purchase_items', 'weight1', 'DECIMAL(12,2) DEFAULT NULL');
addColumnIfNotExists($pdo, 'purchase_items', 'weight2', 'DECIMAL(12,2) DEFAULT NULL');

// 6. Columns in suppliers
addColumnIfNotExists($pdo, 'suppliers', 'adjustment', 'DECIMAL(12,2) DEFAULT 0.00 AFTER `opening_balance`');

// 7. Ensure default branch exists
try {
    $branchCount = (int)$pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn();
    if ($branchCount === 0) {
        $pdo->exec("INSERT INTO branches (id, name, address, phone, status, created_at) VALUES (1, 'Main Branch', 'Head Office', '—', 1, CURDATE())");
        $messages[] = "Inserted default 'Main Branch' (id=1) to prevent Foreign Key errors.";
    } else {
        $messages[] = "Branch table has existing record(s). OK.";
    }
} catch (Exception $e) {
    $errors[] = "Branch check error: " . $e->getMessage();
}

$is_cli = (php_sapi_name() === 'cli');
if ($is_cli) {
    echo "=== DATABASE UPDATE REPORT ===\n";
    foreach ($messages as $m) echo "[OK] $m\n";
    foreach ($errors as $err) echo "[ERROR] $err\n";
    echo "\nCompleted without deleting existing data.\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Database Safe Update</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <style>
    body { background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px 20px; }
    .card { border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: none; }
    .card-header { background: #1e293b; color: #fff; border-top-left-radius: 12px; border-top-right-radius: 12px; padding: 18px 24px; }
  </style>
</head>
<body>
<div class="container" style="max-width: 720px;">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0 font-weight-bold">System Database Safe Update</h5>
      <span class="badge badge-success px-3 py-2">Data Safe &bull; No Wipe</span>
    </div>
    <div class="card-body p-4">
      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
          <h6 class="font-weight-bold">Some warnings or errors occurred:</h6>
          <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
              <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php else: ?>
        <div class="alert alert-success d-flex align-items-center mb-4">
          <div class="mr-3" style="font-size: 2rem;">&#10004;</div>
          <div>
            <h6 class="mb-1 font-weight-bold">Database Update Completed Successfully!</h6>
            <p class="mb-0 small text-muted">All client entries, invoices, customers, and history have been preserved 100%.</p>
          </div>
        </div>
      <?php endif; ?>

      <h6 class="text-secondary font-weight-bold mb-3">Update Actions Performed:</h6>
      <ul class="list-group mb-4">
        <?php foreach ($messages as $m): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2">
            <span><?= htmlspecialchars($m) ?></span>
            <span class="badge badge-primary badge-pill">OK</span>
          </li>
        <?php endforeach; ?>
      </ul>

      <div class="text-center">
        <a href="index.php" class="btn btn-primary px-4 py-2 font-weight-bold">Go to Dashboard</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
