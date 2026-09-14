<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── GET: load invoice info for the modal ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'load') {
    $id = (int)($_GET['sale_id'] ?? 0);
    if (!$id) { echo json_encode(['error' => 'Invalid ID']); exit; }

    $st = $pdo->prepare(
        "SELECT s.*, c.full_name AS customer_name, c.phone AS customer_phone
         FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = ?"
    );
    $st->execute([$id]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale) { echo json_encode(['error' => 'Sale not found']); exit; }

    $bank_accounts = $pdo->query(
        "SELECT id, account_name, bank_name FROM bank_accounts WHERE status = 1 ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'sale'          => $sale,
        'bank_accounts' => $bank_accounts,
    ]);
    exit;
}

// ── POST: save payment ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pay') {
    $sale_id        = (int)($_POST['sale_id'] ?? 0);
    $amount         = (float)($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $bank_account_id= (int)($_POST['bank_account_id'] ?? 0) ?: null;
    $receipt_date   = $_POST['receipt_date'] ?: date('Y-m-d');

    if (!$sale_id || $amount <= 0) {
        echo json_encode(['error' => 'Invalid amount']); exit;
    }

    $sale = getById('sales', $sale_id);
    if (!$sale) { echo json_encode(['error' => 'Sale not found']); exit; }

    if ($amount > (float)$sale['due_amount']) {
        $amount = (float)$sale['due_amount'];
    }

    $pdo->beginTransaction();
    try {
        // Insert customer receipt
        insert('customer_receipts', [
            'customer_id'     => $sale['customer_id'],
            'amount'          => $amount,
            'payment_method'  => $payment_method,
            'bank_account_id' => $payment_method == 'bank' ? $bank_account_id : null,
            'description'     => 'Payment against Invoice #' . $sale['invoice_no'],
            'receipt_date'    => $receipt_date,
            'created_by'      => $_SESSION['user_id'],
            'created_at'      => date('Y-m-d'),
        ]);

        // Update sale paid/due directly
        $new_paid = (float)$sale['paid_amount'] + $amount;
        $new_due  = max(0, (float)$sale['total_amount'] - (float)$sale['discount_amount'] - $new_paid);
        $status   = $new_due > 0 ? 'active' : 'completed';
        $pdo->prepare(
            "UPDATE sales SET paid_amount = ?, due_amount = ?, status = ? WHERE id = ?"
        )->execute([$new_paid, $new_due, $status, $sale_id]);

        // Cash / bank ledger
        $desc = 'Receipt against Sale #' . $sale['invoice_no'];
        if ($payment_method == 'bank') {
            recordBankInflow($pdo, $receipt_date, $amount, $desc, 'sale', $sale_id, $_SESSION['user_id'], $bank_account_id);
        } else {
            recordCashInflow($pdo, $receipt_date, $amount, $desc, 'sale', $sale_id, $_SESSION['user_id']);
        }

        updateCustomerBalance($pdo, $sale['customer_id']);

        $pdo->commit();
        logActivity($pdo, 'payment', 'sale', $sale_id, 'Received PKR ' . $amount . ' against ' . $sale['invoice_no']);
        echo json_encode(['success' => true, 'new_paid' => $new_paid, 'new_due' => $new_due]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Invalid request']);
