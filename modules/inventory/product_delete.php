<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $redirect_url = !empty($_POST['redirect']) ? $_POST['redirect'] : 'products.php';
    $product = getById('products', $id);
    if (!$product) redirect($redirect_url, 'Material not found', 'error');

    $pdo->beginTransaction();
    try {
        // Collect purchases and sales that have this product
        $purchases = $pdo->prepare("SELECT DISTINCT purchase_id FROM purchase_items WHERE product_id = ?");
        $purchases->execute([$id]);
        $purchase_ids = $purchases->fetchAll(PDO::FETCH_COLUMN);

        $sales = $pdo->prepare("SELECT DISTINCT sale_id FROM sale_items WHERE product_id = ?");
        $sales->execute([$id]);
        $sale_ids = $sales->fetchAll(PDO::FETCH_COLUMN);

        // Delete the product (MySQL cascades to purchase_items and sale_items)
        delete('products', $id);

        // Recompute totals for affected purchases
        foreach ($purchase_ids as $pid) {
            $sum = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM purchase_items WHERE purchase_id = ?");
            $sum->execute([$pid]);
            $new_tot = (float)$sum->fetchColumn();
            $pdo->prepare("UPDATE purchases SET total_amount = ?, due_amount = GREATEST(0, ? - paid_amount) WHERE id = ?")
                ->execute([$new_tot, $new_tot, $pid]);
        }

        // Recompute totals for affected sales
        foreach ($sale_ids as $sid) {
            $sum = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM sale_items WHERE sale_id = ?");
            $sum->execute([$sid]);
            $new_tot = (float)$sum->fetchColumn();
            $pdo->prepare("UPDATE sales SET total_amount = ?, due_amount = GREATEST(0, ? - paid_amount) WHERE id = ?")
                ->execute([$new_tot, $new_tot, $sid]);
        }

        $pdo->commit();
        logActivity($pdo, 'delete', 'product', $id, 'Deleted material: ' . $product['name'] . ' (code: ' . $product['code'] . ')');
        redirect($redirect_url, 'Material "' . $product['name'] . '" deleted successfully');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        redirect($redirect_url, 'Error deleting material: ' . $e->getMessage(), 'error');
    }
}
redirect('products.php');