<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
$page_title = 'Plant Areas';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

// Handle POST actions (Add, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            redirect('plant_areas.php', 'Plant area name is required', 'error');
        }

        // Check if duplicate
        $chk = $pdo->prepare("SELECT id FROM plant_areas WHERE name = ?");
        $chk->execute([$name]);
        if ($chk->fetch()) {
            redirect('plant_areas.php', 'This plant area already exists', 'error');
        }

        insert('plant_areas', [
            'name'       => $name,
            'status'     => 1,
            'created_at' => date('Y-m-d'),
        ]);
        logActivity($pdo, 'create', 'plant_area', null, 'Added plant area: ' . $name);
        redirect('plant_areas.php', 'Plant area added successfully');
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if (!$id || $name === '') {
            redirect('plant_areas.php', 'Valid plant area name is required', 'error');
        }

        $chk = $pdo->prepare("SELECT id FROM plant_areas WHERE name = ? AND id != ?");
        $chk->execute([$name, $id]);
        if ($chk->fetch()) {
            redirect('plant_areas.php?edit=' . $id, 'Another plant area with this name already exists', 'error');
        }

        $pdo->prepare("UPDATE plant_areas SET name = ?, status = ? WHERE id = ?")
            ->execute([$name, $status, $id]);

        logActivity($pdo, 'update', 'plant_area', $id, 'Updated plant area: ' . $name);
        redirect('plant_areas.php', 'Plant area updated successfully');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pa = getById('plant_areas', $id);
            if ($pa) {
                // Check if used in purchases
                $used = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE plant_area = ?");
                $used->execute([$pa['name']]);
                $count = (int)$used->fetchColumn();

                if ($count > 0) {
                    redirect('plant_areas.php', "Cannot delete: This plant area is used in {$count} purchase(s). You can mark it inactive instead.", 'error');
                } else {
                    $pdo->prepare("DELETE FROM plant_areas WHERE id = ?")->execute([$id]);
                    logActivity($pdo, 'delete', 'plant_area', $id, 'Deleted plant area: ' . $pa['name']);
                    redirect('plant_areas.php', 'Plant area deleted successfully');
                }
            }
        }
        redirect('plant_areas.php', 'Invalid request', 'error');
    }
}

// Edit mode
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_pa = $edit_id ? getById('plant_areas', $edit_id) : null;

// Fetch all plant areas with purchase usage count
$plant_areas = $pdo->query(
    "SELECT pa.*, 
     (SELECT COUNT(*) FROM purchases p WHERE p.plant_area = pa.name) AS purchase_count 
     FROM plant_areas pa 
     ORDER BY pa.name ASC"
)->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="row">
  <!-- Add / Edit Form -->
  <div class="col-lg-4 mb-4">
    <div class="card shadow">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6>
          <i class="fas <?= $edit_pa ? 'fa-edit text-warning' : 'fa-plus-circle text-primary' ?>"></i>
          <?= $edit_pa ? 'Edit Plant Area' : 'Add Plant Area' ?>
        </h6>
        <?php if ($edit_pa): ?>
        <a href="plant_areas.php" class="btn btn-sm btn-outline-secondary">
          <i class="fas fa-times"></i> Cancel
        </a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="post" action="plant_areas.php">
          <input type="hidden" name="action" value="<?= $edit_pa ? 'edit' : 'add' ?>">
          <?php if ($edit_pa): ?>
          <input type="hidden" name="id" value="<?= (int)$edit_pa['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Plant Area Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required
                   placeholder="e.g. Sundar Industrial Estate"
                   value="<?= htmlspecialchars($edit_pa['name'] ?? '') ?>" autofocus>
            <small class="text-muted">This area will appear in the New Purchase dropdown.</small>
          </div>

          <?php if ($edit_pa): ?>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
              <option value="1" <?= $edit_pa['status'] == 1 ? 'selected' : '' ?>>Active (Visible in dropdown)</option>
              <option value="0" <?= $edit_pa['status'] == 0 ? 'selected' : '' ?>>Inactive (Hidden)</option>
            </select>
          </div>
          <?php endif; ?>

          <div class="d-grid mt-4">
            <button type="submit" class="btn btn-<?= $edit_pa ? 'warning' : 'primary' ?> btn-block">
              <i class="fas <?= $edit_pa ? 'fa-check' : 'fa-plus' ?>"></i>
              <?= $edit_pa ? 'Update Plant Area' : 'Save Plant Area' ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Plant Areas Table -->
  <div class="col-lg-8 mb-4">
    <div class="card shadow">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="fas fa-map-marker-alt"></i> Plant Areas (<?= count($plant_areas) ?>)</h6>
        <a href="<?= $base_url ?>modules/purchases/create.php" class="btn btn-sm btn-outline-primary">
          <i class="fas fa-cart-arrow-down"></i> New Purchase
        </a>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="thead-light">
              <tr>
                <th style="width: 50px;">#</th>
                <th>Area Name</th>
                <th class="text-center" style="width: 100px;">Purchases</th>
                <th class="text-center" style="width: 100px;">Status</th>
                <th class="text-center" style="width: 120px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($plant_areas as $i => $pa): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td class="font-weight-bold">
                  <i class="fas fa-map-marker-alt text-danger mr-1"></i>
                  <?= htmlspecialchars($pa['name']) ?>
                </td>
                <td class="text-center">
                  <span class="badge badge-info"><?= (int)$pa['purchase_count'] ?></span>
                </td>
                <td class="text-center">
                  <?php if ($pa['status'] == 1): ?>
                    <span class="badge badge-success">Active</span>
                  <?php else: ?>
                    <span class="badge badge-secondary">Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="text-center" nowrap>
                  <a href="plant_areas.php?edit=<?= (int)$pa['id'] ?>"
                     class="btn btn-sm btn-outline-warning" title="Edit">
                    <i class="fas fa-edit"></i>
                  </a>
                  <form method="post" action="plant_areas.php" class="d-inline"
                        onsubmit="return confirm('Are you sure you want to delete \'<?= htmlspecialchars(addslashes($pa['name'])) ?>\'?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$pa['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!count($plant_areas)): ?>
              <tr>
                <td colspan="5" class="text-center text-muted py-4">
                  No plant areas added yet. Use the form on the left to add your first plant area.
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
