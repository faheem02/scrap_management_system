<?php
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireRole(['admin']);

header('Content-Type: application/json');

$customer_id = (int)($_GET['customer_id'] ?? 0);
if (!$customer_id) { echo json_encode([]); exit; }

$st = $pdo->prepare("SELECT id, invoice_no, total_amount, paid_amount, due_amount, sale_date
    FROM sales WHERE customer_id = ? AND status <> 'cancelled' AND due_amount > 0
    ORDER BY sale_date ASC, id ASC");
$st->execute([$customer_id]);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
