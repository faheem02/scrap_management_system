<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin','order_booker']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $customer = getById('customers', $id);
    if (!$customer) redirect('customers.php', 'Customer not found', 'error');

    $pdo->beginTransaction();
    try {
        // 1. Fetch all sales for this customer to restore product stock and reverse cash/bank
        $salesStmt = $pdo->prepare("SELECT id FROM sales WHERE customer_id = ?");
        $salesStmt->execute([$id]);
        $customerSales = $salesStmt->fetchAll(PDO::FETCH_COLUMN);

        $min_date = null;
        $daily_ids = [];

        foreach ($customerSales as $saleId) {
            // Restore inventory stock for each sale item
            $st = $pdo->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
            $st->execute([$saleId]);
            $items = $st->fetchAll();
            foreach ($items as $it) {
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")
                    ->execute([(float)$it['quantity'], (int)$it['product_id']]);
            }

            // Reverse cash_book entries for this sale
            $cb = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book WHERE reference_type = 'sale' AND reference_id = ?");
            $cb->execute([$saleId]);
            foreach ($cb->fetchAll() as $cr) {
                $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$cr['id']]);
                $daily_ids[] = $cr['daily_id'];
                if ($min_date === null || $cr['transaction_date'] < $min_date) $min_date = $cr['transaction_date'];
            }

            // Reverse bank transactions for this sale
            $bt = $pdo->prepare("SELECT id, amount, bank_account_id FROM bank_transactions WHERE reference_type = 'sale' AND reference_id = ?");
            $bt->execute([$saleId]);
            foreach ($bt->fetchAll() as $br) {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                    ->execute([(float)$br['amount'], (int)$br['bank_account_id']]);
                $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$br['id']]);
            }
        }

        // 2. Reverse cash_book and bank_transactions for customer_receipts
        $cbR = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book WHERE reference_type = 'customer_receipt' AND reference_id = ?");
        $cbR->execute([$id]);
        foreach ($cbR->fetchAll() as $cr) {
            $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$cr['id']]);
            $daily_ids[] = $cr['daily_id'];
            if ($min_date === null || $cr['transaction_date'] < $min_date) $min_date = $cr['transaction_date'];
        }

        $btR = $pdo->prepare("SELECT id, amount, bank_account_id FROM bank_transactions WHERE reference_type = 'customer_receipt' AND reference_id = ?");
        $btR->execute([$id]);
        foreach ($btR->fetchAll() as $br) {
            $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                ->execute([(float)$br['amount'], (int)$br['bank_account_id']]);
            $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$br['id']]);
        }

        // Recompute daily cash totals if any cash entries were removed
        foreach (array_unique($daily_ids) as $did) {
            recomputeCashDayTotals($pdo, (int)$did);
        }
        if ($min_date) {
            recomputeCashDailyFrom($pdo, $min_date);
        }

        // 3. Delete customer (MySQL foreign key ON DELETE CASCADE automatically removes customer_receipts, sales, and sale_items)
        delete('customers', $id);

        $pdo->commit();
        logActivity($pdo, 'delete', 'customer', $id, 'Deleted customer: ' . $customer['full_name'] . ' with associated sales/receipts');
        redirect('customers.php', 'Customer "' . $customer['full_name'] . '" deleted successfully');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect('customers.php', 'Error deleting customer: ' . $e->getMessage(), 'error');
    }
}
redirect('customers.php');