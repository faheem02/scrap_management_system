<?php
require_once __DIR__ . '/../config/db.php';

// Dynamic Base URL detection for root or subfolder installation
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
$GLOBALS['base_url'] = $base_url;
if (!defined('BASE_URL')) {
    define('BASE_URL', $base_url);
}

// Get all records from a table
function getAll($table, $order = 'id DESC') {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM $table ORDER BY $order");
    return $stmt->fetchAll();
}

// Get single record by ID
function getById($table, $id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Get records with a condition
function getWhere($table, $column, $value, $order = 'id DESC') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE $column = ? ORDER BY $order");
    $stmt->execute([$value]);
    return $stmt->fetchAll();
}

// Insert and return last ID
function insert($table, $data) {
    global $pdo;
    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $stmt = $pdo->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)");
    $stmt->execute(array_values($data));
    return $pdo->lastInsertId();
}

// Update record
function update($table, $data, $id) {
    global $pdo;
    $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
    $stmt = $pdo->prepare("UPDATE $table SET $sets WHERE id = ?");
    $stmt->execute([...array_values($data), $id]);
    return $stmt->rowCount();
}

// Delete record
function delete($table, $id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount();
}

// Count records
function countRows($table, $column = null, $value = null) {
    global $pdo;
    if ($column && $value !== null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
        $stmt->execute([$value]);
    } else {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
    }
    return $stmt->fetchColumn();
}

// Generate purchase invoice number
function generatePurchaseNo() {
    global $pdo;
    $prefix = 'PUR-' . date('ymd') . '-';
    $stmt = $pdo->query("SELECT COUNT(*) FROM purchases WHERE invoice_no LIKE '$prefix%'");
    $count = (int)$stmt->fetchColumn() + 1;
    do {
        $no = $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare("SELECT id FROM purchases WHERE invoice_no = ?");
        $chk->execute([$no]);
        if (!$chk->fetch()) break;
        $count++;
    } while (true);
    return $no;
}

// Generate sale invoice number
function generateSaleNo() {
    global $pdo;
    $prefix = 'INV-' . date('ymd') . '-';
    $stmt = $pdo->query("SELECT COUNT(*) FROM sales WHERE invoice_no LIKE '$prefix%'");
    $count = (int)$stmt->fetchColumn() + 1;
    do {
        $no = $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare("SELECT id FROM sales WHERE invoice_no = ?");
        $chk->execute([$no]);
        if (!$chk->fetch()) break;
        $count++;
    } while (true);
    return $no;
}

// Generate customer number
function generateCustomerNo() {
    global $pdo;
    $prefix = 'CUS-' . date('ym') . '-';
    $stmt = $pdo->query("SELECT COUNT(*) FROM customers WHERE customer_no LIKE '$prefix%'");
    $count = (int)$stmt->fetchColumn() + 1;
    do {
        $no = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare("SELECT id FROM customers WHERE customer_no = ?");
        $chk->execute([$no]);
        if (!$chk->fetch()) break;
        $count++;
    } while (true);
    return $no;
}

// Generate product/material code (0001, 0002, 0003...)
function generateProductCode() {
    global $pdo;
    $stmt = $pdo->query("SELECT MAX(CAST(code AS UNSIGNED)) AS max_code FROM products WHERE code REGEXP '^[0-9]+$'");
    $max = (int)$stmt->fetchColumn();
    if ($max > 0) {
        $next = $max + 1;
    } else {
        $next = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() + 1;
    }
    return str_pad($next, 4, '0', STR_PAD_LEFT);
}

// Generate salary slip number
function generateSalaryNo() {
    global $pdo;
    $prefix = 'SAL-' . date('ymd') . '-';
    $stmt = $pdo->query("SELECT COUNT(*) FROM employee_salaries WHERE slip_no LIKE '$prefix%'");
    $count = $stmt->fetchColumn() + 1;
    return $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);
}

// Format currency
function formatCurrency($amount) {
    return number_format((float)($amount ?? 0), 2);
}

// Format date
function formatDate($date) {
    return (!empty($date) && $date !== '-') ? date('d-m-Y', strtotime((string)$date)) : '-';
}

// Update customer live balance
// Sign: positive = customer owes us, negative = we owe customer
function updateCustomerBalance($pdo, $customer_id) {
    if (!$customer_id) return;
    $c = getById('customers', $customer_id);
    $opening = (float)($c['opening_balance'] ?? 0);
    // Total remaining due across all invoices (total - paid_amount per invoice)
    $due = $pdo->prepare("SELECT COALESCE(SUM(total_amount - paid_amount),0) FROM sales WHERE customer_id = ? AND status <> 'cancelled'");
    $due->execute([$customer_id]);
    $due = (float)$due->fetchColumn();
    // Only unallocated receipts (sales_id IS NULL) count as advance payments
    $adv = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_receipts WHERE customer_id = ? AND (sales_id IS NULL)");
    $adv->execute([$customer_id]);
    $advance = (float)$adv->fetchColumn();
    // Money paid to customer via fund_transfers (debit to customer)
    $cust_paid = 0;
    try {
        $cp = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fund_transfers WHERE customer_id = ? AND to_type = 'customer'");
        $cp->execute([$customer_id]);
        $cust_paid = (float)$cp->fetchColumn();
    } catch (Exception $e) {}
    $balance = $opening + $due - $advance + $cust_paid;
    $pdo->prepare("UPDATE customers SET current_balance = ? WHERE id = ?")->execute([$balance, $customer_id]);
    return $balance;
}

// Allocate a single receipt to invoices (incremental FIFO)
// Optional $receipt_id to tag the receipt with sales_id; optional $sales_id for targeted allocation
function allocateReceiptToSales($pdo, $customer_id, $receipt_id = null, $sales_id = null) {
    if (!$customer_id) return;

    $receipt_amount = 0;
    if ($receipt_id) {
        $r = $pdo->prepare("SELECT amount FROM customer_receipts WHERE id = ?");
        $r->execute([$receipt_id]);
        $rec = $r->fetch();
        if (!$rec) return;
        $receipt_amount = (float)$rec['amount'];
    } else {
        $r = $pdo->prepare("SELECT id, amount FROM customer_receipts WHERE customer_id = ? AND (sales_id IS NULL) ORDER BY id DESC LIMIT 1");
        $r->execute([$customer_id]);
        $rec = $r->fetch();
        if (!$rec) return;
        $receipt_amount = (float)$rec['amount'];
        $receipt_id = (int)$rec['id'];
    }

    $remaining = $receipt_amount;
    $tagged_sale_id = null;

    if ($sales_id) {
        // Targeted: allocate against a specific invoice first
        $s = $pdo->prepare("SELECT id, total_amount, paid_amount FROM sales WHERE id = ? AND customer_id = ? AND status <> 'cancelled'");
        $s->execute([$sales_id, $customer_id]);
        $sale = $s->fetch();
        if (!$sale) throw new Exception('Sale not found for this customer');
        $total = (float)$sale['total_amount'];
        $oldPaid = (float)$sale['paid_amount'];
        $available = max(0, $total - $oldPaid);
        $alloc = min($remaining, $available);
        $newPaid = $oldPaid + $alloc;
        $newDue = max(0, $total - $newPaid);
        $status = $newDue > 0 ? 'active' : 'completed';
        $pdo->prepare("UPDATE sales SET paid_amount = ?, due_amount = ?, status = ? WHERE id = ?")
            ->execute([$newPaid, $newDue, $status, $sales_id]);
        $remaining -= $alloc;
        if ($alloc > 0) $tagged_sale_id = $sales_id;

        // Continue FIFO for any excess
    }

    // FIFO: oldest unpaid invoice first (continues after targeted if excess)
    if ($remaining > 0) {
        $all_sales_q = $pdo->prepare("SELECT id, total_amount, paid_amount FROM sales WHERE customer_id = ? AND status <> 'cancelled' ORDER BY sale_date ASC, id ASC");
        $all_sales_q->execute([$customer_id]);
        $all_sales = $all_sales_q->fetchAll();

        foreach ($all_sales as $s) {
            // Skip the already-targeted invoice if excess is being distributed
            if ($tagged_sale_id && (int)$s['id'] === $tagged_sale_id) {
                // Still has available? No — we already applied alloc to it above, remaining > 0 means we exhausted it or it was fully due
                continue;
            }
            $total = (float)$s['total_amount'];
            $oldPaid = (float)$s['paid_amount'];
            $available = max(0, $total - $oldPaid);
            if ($available <= 0) continue;

            $alloc = min($remaining, $available);
            $newPaid = $oldPaid + $alloc;
            $newDue = max(0, $total - $newPaid);
            $status = $newDue > 0 ? 'active' : 'completed';
            $pdo->prepare("UPDATE sales SET paid_amount = ?, due_amount = ?, status = ? WHERE id = ?")
                ->execute([$newPaid, $newDue, $status, $s['id']]);

            if (!$tagged_sale_id && $receipt_id) {
                $tagged_sale_id = (int)$s['id'];
            }

            $remaining -= $alloc;
            if ($remaining <= 0) break;
        }
    }

    // Tag receipt
    if ($receipt_id && $tagged_sale_id) {
        $pdo->prepare("UPDATE customer_receipts SET sales_id = ? WHERE id = ?")->execute([$tagged_sale_id, $receipt_id]);
    }

    // Excess becomes advance: insert a split receipt (is_split=1, sales_id=NULL, no cash book entry)
    if ($remaining > 0.001 && $receipt_id) {
        $r = $pdo->prepare("SELECT payment_method, bank_account_id, receipt_date, created_by, created_at FROM customer_receipts WHERE id = ?");
        $r->execute([$receipt_id]);
        $orig = $r->fetch();
        insert('customer_receipts', [
            'customer_id'     => $customer_id,
            'sales_id'        => null,
            'amount'          => $remaining,
            'payment_method'  => $orig['payment_method'],
            'bank_account_id' => $orig['bank_account_id'],
            'description'     => 'Advance (excess of receipt #' . $receipt_id . ')',
            'receipt_date'    => $orig['receipt_date'],
            'created_by'      => $orig['created_by'],
            'created_at'      => $orig['created_at'],
            'is_split'        => 1,
        ]);
    }

    // Sweep any leftover advance into open invoices
    consumeCustomerAdvances($pdo, $customer_id);
}

// Apply a customer's outstanding advance (receipts with sales_id IS NULL) to open invoice dues (FIFO)
function consumeCustomerAdvances($pdo, $customer_id) {
    if (!$customer_id) return;

    $advs = $pdo->prepare("SELECT id, amount FROM customer_receipts WHERE customer_id = ? AND sales_id IS NULL ORDER BY receipt_date ASC, id ASC");
    $advs->execute([$customer_id]);
    $adv_rows = $advs->fetchAll();
    if (!$adv_rows) return;

    $invs = $pdo->prepare("SELECT id, total_amount, paid_amount FROM sales WHERE customer_id = ? AND status <> 'cancelled' AND due_amount > 0 ORDER BY sale_date ASC, id ASC");
    $invs->execute([$customer_id]);
    $inv_rows = $invs->fetchAll();
    if (!$inv_rows) return;

    foreach ($adv_rows as $adv) {
        $adv_remaining = (float)$adv['amount'];
        if ($adv_remaining <= 0.001) continue;
        foreach ($inv_rows as $inv) {
            $available = max(0, (float)$inv['total_amount'] - (float)$inv['paid_amount']);
            if ($available <= 0) continue;
            $used = min($adv_remaining, $available);
            $newPaid = (float)$inv['paid_amount'] + $used;
            $newDue = max(0, (float)$inv['total_amount'] - $newPaid);
            $status = $newDue > 0 ? 'active' : 'completed';
            $pdo->prepare("UPDATE sales SET paid_amount = ?, due_amount = ?, status = ? WHERE id = ?")
                ->execute([$newPaid, $newDue, $status, $inv['id']]);
            $adv_remaining -= $used;
            if ($adv_remaining <= 0.001) {
                $pdo->prepare("UPDATE customer_receipts SET sales_id = ? WHERE id = ?")->execute([(int)$inv['id'], $adv['id']]);
                break;
            }
            $pdo->prepare("UPDATE customer_receipts SET amount = ? WHERE id = ?")->execute([$adv_remaining, $adv['id']]);
        }
    }
    updateCustomerBalance($pdo, $customer_id);
}

// Log activity
function logActivity($pdo, $action, $module, $reference_id = null, $description = null) {
    $uid = $_SESSION['user_id'] ?? null;
    insert('activity_logs', [
        'user_id' => $uid,
        'action' => $action,
        'module' => $module,
        'reference_id' => $reference_id,
        'description' => $description,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'created_at' => date('Y-m-d'),
    ]);
}

// Record a cash inflow in cash book
function recordCashInflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null) {
    $today = $date ?: date('Y-m-d');

    // Ensure created_by exists in users table (prevents FK error if users table was altered)
    if ($created_by) {
        $chkU = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $chkU->execute([(int)$created_by]);
        if (!$chkU->fetchColumn()) {
            $created_by = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: null;
        }
    } else {
        $created_by = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: null;
    }

    $daily = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date = ?");
    $daily->execute([$today]);
    $daily_rec = $daily->fetch();

    if ($daily_rec) {
        $daily_id = $daily_rec['id'];
    } else {
        $prev = $pdo->query("SELECT closing_balance FROM cash_book_daily WHERE date < '$today' ORDER BY date DESC LIMIT 1")->fetch();
        $opening = $prev ? (float)$prev['closing_balance'] : 0;
        $daily_id = insert('cash_book_daily', [
            'date' => $today,
            'opening_balance' => $opening,
            'total_inflow' => 0,
            'total_outflow' => 0,
            'closing_balance' => $opening,
            'status' => 'open',
            'created_by' => $created_by,
            'created_at' => date('Y-m-d'),
        ]);
    }

    insert('cash_book', [
        'daily_id' => $daily_id,
        'transaction_date' => $today,
        'transaction_type' => 'inflow',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by,
        'created_at' => date('Y-m-d'),
    ]);

    recomputeCashDayTotals($pdo, (int)$daily_id);
    recomputeCashDailyFrom($pdo, $today);
}

// Record a cash outflow
function recordCashOutflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null) {
    $today = $date ?: date('Y-m-d');

    // Ensure created_by exists in users table (prevents FK error if users table was altered)
    if ($created_by) {
        $chkU = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $chkU->execute([(int)$created_by]);
        if (!$chkU->fetchColumn()) {
            $created_by = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: null;
        }
    } else {
        $created_by = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: null;
    }

    $daily = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date = ?");
    $daily->execute([$today]);
    $daily_rec = $daily->fetch();

    if ($daily_rec) {
        $daily_id = $daily_rec['id'];
    } else {
        $prev = $pdo->query("SELECT closing_balance FROM cash_book_daily WHERE date < '$today' ORDER BY date DESC LIMIT 1")->fetch();
        $opening = $prev ? (float)$prev['closing_balance'] : 0;
        $daily_id = insert('cash_book_daily', [
            'date' => $today,
            'opening_balance' => $opening,
            'total_inflow' => 0,
            'total_outflow' => 0,
            'closing_balance' => $opening,
            'status' => 'open',
            'created_by' => $created_by,
            'created_at' => date('Y-m-d'),
        ]);
    }

    insert('cash_book', [
        'daily_id' => $daily_id,
        'transaction_date' => $today,
        'transaction_type' => 'outflow',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by,
        'created_at' => date('Y-m-d'),
    ]);

    recomputeCashDayTotals($pdo, (int)$daily_id);
    recomputeCashDailyFrom($pdo, $today);
}

// Record bank inflow (deposit)
function recordBankInflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null, $bank_account_id = null) {
    $account = resolveBankAccount($pdo, $bank_account_id, $date);
    insert('bank_transactions', [
        'bank_account_id' => $account['id'],
        'transaction_date' => $date,
        'transaction_type' => 'deposit',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by ?: 1,
        'created_at' => date('Y-m-d'),
    ]);
    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $account['id']]);
    return $account['id'];
}

// Record bank outflow (withdrawal)
function recordBankOutflow($pdo, $date, $amount, $description, $reference_type = null, $reference_id = null, $created_by = null, $bank_account_id = null) {
    $account = resolveBankAccount($pdo, $bank_account_id, $date);
    insert('bank_transactions', [
        'bank_account_id' => $account['id'],
        'transaction_date' => $date,
        'transaction_type' => 'withdrawal',
        'amount' => $amount,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_by' => $created_by ?: 1,
        'created_at' => date('Y-m-d'),
    ]);
    $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $account['id']]);
    return $account['id'];
}

// Recompute a cash_book_daily row's inflow/outflow totals from its entries
function recomputeCashDayTotals($pdo, $daily_id) {
    $in = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE daily_id = ? AND transaction_type = 'inflow'");
    $in->execute([$daily_id]);
    $out = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_book WHERE daily_id = ? AND transaction_type = 'outflow'");
    $out->execute([$daily_id]);
    $pdo->prepare("UPDATE cash_book_daily SET total_inflow = ?, total_outflow = ?, updated_at = ? WHERE id = ?")
        ->execute([(float)$in->fetchColumn(), (float)$out->fetchColumn(), date('Y-m-d'), $daily_id]);
}

// Recompute cash_book_daily running balances (opening/closing) from a date onward
function recomputeCashDailyFrom($pdo, $from_date) {
    $rows = $pdo->prepare("SELECT * FROM cash_book_daily WHERE date >= ? ORDER BY date ASC, id ASC");
    $rows->execute([$from_date]);
    $carry = null;
    foreach ($rows->fetchAll() as $r) {
        $opening = ($carry === null) ? (float)$r['opening_balance'] : $carry;
        $closing = $opening + (float)$r['total_inflow'] - (float)$r['total_outflow'];
        $pdo->prepare("UPDATE cash_book_daily SET opening_balance = ?, closing_balance = ?, updated_at = ? WHERE id = ?")
            ->execute([$opening, $closing, date('Y-m-d'), $r['id']]);
        $carry = $closing;
    }
}

// Reverse a salary payment's ledger effect (cash_book + bank_transactions) prior to edit/delete
function removeSalaryLedger($pdo, $salary_id) {
    $stmt = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book WHERE reference_type = 'salary' AND reference_id = ?");
    $stmt->execute([$salary_id]);
    $rows = $stmt->fetchAll();
    $min_date = null;
    $daily_ids = [];
    foreach ($rows as $r) {
        $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$r['id']]);
        $daily_ids[] = $r['daily_id'];
        if ($min_date === null || $r['transaction_date'] < $min_date) $min_date = $r['transaction_date'];
    }
    foreach (array_unique($daily_ids) as $did) recomputeCashDayTotals($pdo, $did);
    if ($min_date) recomputeCashDailyFrom($pdo, $min_date);

    $btns = $pdo->prepare("SELECT * FROM bank_transactions WHERE reference_type = 'salary' AND reference_id = ?");
    $btns->execute([$salary_id]);
    foreach ($btns->fetchAll() as $b) {
        $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$b['amount'], $b['bank_account_id']]);
        $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$b['id']]);
    }
}

// Resolve bank account (use given or first active, create default if none)
function resolveBankAccount($pdo, $bank_account_id, $date) {
    if ($bank_account_id) {
        $stmt = $pdo->prepare("SELECT id, current_balance FROM bank_accounts WHERE id = ? AND status = 1");
        $stmt->execute([$bank_account_id]);
        $account = $stmt->fetch();
        if ($account) return $account;
    }
    $account = $pdo->query("SELECT id, current_balance FROM bank_accounts WHERE status = 1 ORDER BY id ASC LIMIT 1")->fetch();
    if ($account) return $account;
    $account_id = insert('bank_accounts', [
        'account_name' => 'Default Account',
        'bank_name' => 'Default Bank',
        'account_no' => 'AUTO-' . date('YmdHis'),
        'account_type' => 'current',
        'opening_balance' => 0,
        'current_balance' => 0,
        'status' => 1,
        'created_at' => $date,
    ]);
    return ['id' => $account_id, 'current_balance' => 0];
}

// Redirect with message
function redirect($url, $msg = null, $type = 'success') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($msg) {
        $_SESSION[$type] = $msg;
    }
    session_write_close();
    header("Location: $url");
    exit;
}

// ===== ROLE HELPERS =====
function roleLabel($role) {
    $labels = [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
        'salesperson' => 'Salesperson',
        'accountant' => 'Accountant',
        'salesman' => 'Salesman',
        'order_booker' => 'Order Booker',
        'loader' => 'Loader',
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// Redirect to dashboard if current role is not allowed
function requireRole($allowedRoles = ['admin']) {
    global $user_role, $base_url;
    if (!in_array($user_role, (array)$allowedRoles)) {
        redirect(($base_url ?? '/scrap_management_system/') . 'index.php', 'You do not have permission to access this page', 'error');
    }
}

function isAdmin() { global $user_role; return $user_role === 'admin'; }
function isEmployee() { global $user_role; return in_array($user_role, ['salesman', 'order_booker', 'loader'], true); }
function isSalesTeam() { global $user_role; return in_array($user_role, ['order_booker'], true); }

// Area of the currently logged-in employee (order booker / salesman / loader) from employees.area; null when no area assigned
function currentUserArea($pdo) {
    static $area = false;
    if ($area === false) {
        $st = $pdo->prepare("SELECT area FROM employees WHERE user_id = ? AND area IS NOT NULL AND area <> '' LIMIT 1");
        $st->execute([$_SESSION['user_id'] ?? 0]);
        $area = $st->fetchColumn() ?: null;
    }
    return $area;
}

// Convert amount number to words (Pakistani Rupees)
function numberToWords($number) {
    $number = round((float)$number, 2);
    if ($number < 0) return 'Minus ' . numberToWords(abs($number));
    if ($number == 0) return 'Zero Rupees Only';

    $ones = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
        11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
    ];
    $tens = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
    ];

    $convertGroup = function($n) use (&$convertGroup, $ones, $tens) {
        $n = (int)$n;
        $str = '';
        if ($n >= 100) {
            $h = (int)($n / 100);
            $str .= $ones[$h] . ' Hundred ';
            $n %= 100;
        }
        if ($n >= 20) {
            $t = (int)($n / 10);
            $str .= $tens[$t] . ' ';
            $n %= 10;
        }
        if ($n > 0) {
            $str .= $ones[$n] . ' ';
        }
        return trim($str);
    };

    $rupees = (int)floor($number);
    $paisa = (int)round(($number - $rupees) * 100);

    $parts = [];
    // Crores
    if ($rupees >= 10000000) {
        $cr = (int)($rupees / 10000000);
        $parts[] = $convertGroup($cr) . ' Crore';
        $rupees %= 10000000;
    }
    // Lakhs
    if ($rupees >= 100000) {
        $lakh = (int)($rupees / 100000);
        $parts[] = $convertGroup($lakh) . ' Lakh';
        $rupees %= 100000;
    }
    // Thousands
    if ($rupees >= 1000) {
        $th = (int)($rupees / 1000);
        $parts[] = $convertGroup($th) . ' Thousand';
        $rupees %= 1000;
    }
    // Hundreds & units
    if ($rupees > 0) {
        $parts[] = $convertGroup($rupees);
    }

    $words = implode(' ', array_filter($parts)) . ' Rupees';
    if ($paisa > 0) {
        $words .= ' and ' . $convertGroup($paisa) . ' Paisas';
    }
    return trim($words) . ' Only';
}

// Generate next voucher number for transfers / payments / receipts
function getNextTransferVoucherNo($pdo, $type = 'bank_to_bank', $date = null) {
    if (!$date) $date = date('Y-m-d');
    $prefix = 'TRF';
    if (in_array($type, ['customer_payment', 'person_payment'])) {
        $prefix = 'PAY';
    } elseif (in_array($type, ['customer_receipt', 'person_receipt'])) {
        $prefix = 'REC';
    } elseif ($type === 'cash_to_bank') {
        $prefix = 'DEP';
    } elseif ($type === 'bank_to_cash') {
        $prefix = 'WTH';
    }

    $day_str = date('Ymd', strtotime($date));
    $latest = $pdo->prepare("SELECT voucher_no FROM fund_transfers WHERE voucher_no LIKE ? ORDER BY id DESC LIMIT 1");
    $latest->execute([$prefix . '-' . $day_str . '-%']);
    $last_no = $latest->fetchColumn();
    $seq = 1;
    if ($last_no && preg_match('/-(\d{3,5})$/', $last_no, $m)) {
        $seq = (int)$m[1] + 1;
    }
    return sprintf('%s-%s-%03d', $prefix, $day_str, $seq);
}

// Create a new transfer / payment / receipt and record ledger movements
function createFundTransfer($pdo, $data) {
    $type = $data['transfer_type']; 
    // Types: bank_to_bank, bank_to_cash, cash_to_bank, customer_payment, customer_receipt, person_payment, person_receipt
    $amount = (float)($data['amount'] ?? 0);
    $date = !empty($data['transfer_date']) ? $data['transfer_date'] : date('Y-m-d');
    $ref = trim($data['reference_no'] ?? '');
    $desc = trim($data['description'] ?? '');
    $user_id = !empty($data['created_by']) ? (int)$data['created_by'] : ($_SESSION['user_id'] ?? 1);
    $person_name = trim($data['person_name'] ?? '');
    $person_phone = trim($data['person_phone'] ?? '');

    if ($amount <= 0) throw new Exception('Amount must be greater than zero.');

    $voucher_no = !empty($data['voucher_no']) ? trim($data['voucher_no']) : getNextTransferVoucherNo($pdo, $type, $date);

    $from_type = 'bank';
    $from_bank_id = null;
    $to_type = 'bank';
    $to_bank_id = null;
    $customer_id = null;

    if ($type === 'bank_to_bank') {
        $from_type = 'bank';
        $from_bank_id = (int)($data['from_bank_id'] ?? 0);
        $to_type = 'bank';
        $to_bank_id = (int)($data['to_bank_id'] ?? 0);
        if (!$from_bank_id || !$to_bank_id) throw new Exception('Select both source and destination banks.');
        if ($from_bank_id === $to_bank_id) throw new Exception('Source and destination bank accounts cannot be the same.');
    } elseif ($type === 'bank_to_cash') {
        $from_type = 'bank';
        $from_bank_id = (int)($data['from_bank_id'] ?? 0);
        $to_type = 'cash';
        if (!$from_bank_id) throw new Exception('Select source bank account.');
    } elseif ($type === 'cash_to_bank') {
        $from_type = 'cash';
        $to_type = 'bank';
        $to_bank_id = (int)($data['to_bank_id'] ?? 0);
        if (!$to_bank_id) throw new Exception('Select destination bank account.');
    } elseif ($type === 'customer_payment') {
        $from_type = (($data['from_type'] ?? '') === 'bank') ? 'bank' : 'cash';
        $from_bank_id = ($from_type === 'bank') ? (int)($data['from_bank_id'] ?? 0) : null;
        if ($from_type === 'bank' && !$from_bank_id) throw new Exception('Select bank account to pay customer.');
        $to_type = 'customer';
        $customer_id = (int)($data['customer_id'] ?? 0);
        if (!$customer_id) throw new Exception('Select a customer to send payment to.');
    } elseif ($type === 'customer_receipt') {
        $from_type = 'customer';
        $customer_id = (int)($data['customer_id'] ?? 0);
        if (!$customer_id) throw new Exception('Select a customer to receive money from.');
        $to_type = (($data['to_type'] ?? '') === 'bank') ? 'bank' : 'cash';
        $to_bank_id = ($to_type === 'bank') ? (int)($data['to_bank_id'] ?? 0) : null;
        if ($to_type === 'bank' && !$to_bank_id) throw new Exception('Select destination bank account to deposit customer payment.');
    } elseif ($type === 'person_payment') {
        $from_type = (($data['from_type'] ?? '') === 'bank') ? 'bank' : 'cash';
        $from_bank_id = ($from_type === 'bank') ? (int)($data['from_bank_id'] ?? 0) : null;
        if ($from_type === 'bank' && !$from_bank_id) throw new Exception('Select bank account for payment.');
        $to_type = 'person';
        if (!$person_name) throw new Exception('Enter person or party name.');
    } elseif ($type === 'person_receipt') {
        $from_type = 'person';
        if (!$person_name) throw new Exception('Enter person or party name.');
        $to_type = (($data['to_type'] ?? '') === 'bank') ? 'bank' : 'cash';
        $to_bank_id = ($to_type === 'bank') ? (int)($data['to_bank_id'] ?? 0) : null;
        if ($to_type === 'bank' && !$to_bank_id) throw new Exception('Select destination bank account.');
    } else {
        throw new Exception('Invalid transfer type: ' . $type);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO fund_transfers (voucher_no, transfer_type, from_type, from_bank_id, to_type, to_bank_id, customer_id, person_name, person_phone, amount, transfer_date, reference_no, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $voucher_no, $type, $from_type, $from_bank_id, $to_type, $to_bank_id, $customer_id,
            $person_name ?: null, $person_phone ?: null, $amount, $date, $ref, $desc, $user_id, date('Y-m-d H:i:s')
        ]);
        $transfer_id = (int)$pdo->lastInsertId();

        // 1. OUTFLOW / DEBIT FROM SOURCE
        if ($from_type === 'bank') {
            $wth_desc = 'Transfer Out';
            if ($type === 'bank_to_bank') {
                $to_acc = getById('bank_accounts', $to_bank_id);
                $wth_desc = 'Transfer to ' . ($to_acc ? ($to_acc['bank_name'] . ' - ' . $to_acc['account_name']) : 'Bank') . ' [' . $voucher_no . ']';
            } elseif ($type === 'bank_to_cash') {
                $wth_desc = 'Cash withdrawal to Cash Book [' . $voucher_no . ']';
            } elseif ($type === 'customer_payment') {
                $cust = getById('customers', $customer_id);
                $wth_desc = 'Payment to Customer: ' . ($cust ? $cust['full_name'] : '') . ' [' . $voucher_no . ']';
            } elseif ($type === 'person_payment') {
                $wth_desc = 'Payment to: ' . $person_name . ' [' . $voucher_no . ']';
            }
            if ($desc) $wth_desc .= ' - ' . $desc;

            $pdo->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, transaction_type, amount, description, reference_type, reference_id, created_by, created_at) VALUES (?, ?, 'withdrawal', ?, ?, 'transfer', ?, ?, ?)")
                ->execute([$from_bank_id, $date, $amount, $wth_desc, $transfer_id, $user_id, date('Y-m-d')]);
            $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                ->execute([$amount, $from_bank_id]);
        } elseif ($from_type === 'cash') {
            $cash_desc = 'Cash Outflow';
            if ($type === 'cash_to_bank') {
                $to_acc = getById('bank_accounts', $to_bank_id);
                $cash_desc = 'Cash deposited to Bank: ' . ($to_acc ? ($to_acc['bank_name'] . ' - ' . $to_acc['account_name']) : 'Bank') . ' [' . $voucher_no . ']';
            } elseif ($type === 'customer_payment') {
                $cust = getById('customers', $customer_id);
                $cash_desc = 'Payment to Customer: ' . ($cust ? $cust['full_name'] : '') . ' [' . $voucher_no . ']';
            } elseif ($type === 'person_payment') {
                $cash_desc = 'Payment to: ' . $person_name . ' [' . $voucher_no . ']';
            }
            if ($desc) $cash_desc .= ' - ' . $desc;
            recordCashOutflow($pdo, $date, $amount, $cash_desc, 'transfer', $transfer_id, $user_id);
        } elseif ($from_type === 'customer') {
            // Customer is paying us: Record in customer_receipts to update balances and allocate to sales
            $rc_id = insert('customer_receipts', [
                'customer_id'     => $customer_id,
                'sales_id'        => null,
                'amount'          => $amount,
                'payment_method'  => ($to_type === 'bank') ? 'bank' : 'cash',
                'bank_account_id' => ($to_type === 'bank') ? $to_bank_id : null,
                'description'     => 'Received from Customer [' . $voucher_no . ']' . ($desc ? ' - ' . $desc : ''),
                'receipt_date'    => $date,
                'created_by'      => $user_id,
                'created_at'      => date('Y-m-d'),
                'is_split'        => 0
            ]);
            allocateReceiptToSales($pdo, $customer_id, $rc_id);
            updateCustomerBalance($pdo, $customer_id);
        }

        // 2. INFLOW / CREDIT TO DESTINATION
        if ($to_type === 'bank') {
            $dep_desc = 'Transfer In';
            if ($type === 'bank_to_bank') {
                $from_acc = getById('bank_accounts', $from_bank_id);
                $dep_desc = 'Transfer from ' . ($from_acc ? ($from_acc['bank_name'] . ' - ' . $from_acc['account_name']) : 'Bank') . ' [' . $voucher_no . ']';
            } elseif ($type === 'cash_to_bank') {
                $dep_desc = 'Cash deposited from Cash Book [' . $voucher_no . ']';
            } elseif ($type === 'customer_receipt') {
                $cust = getById('customers', $customer_id);
                $dep_desc = 'Received from Customer: ' . ($cust ? $cust['full_name'] : '') . ' [' . $voucher_no . ']';
            } elseif ($type === 'person_receipt') {
                $dep_desc = 'Received from: ' . $person_name . ' [' . $voucher_no . ']';
            }
            if ($desc) $dep_desc .= ' - ' . $desc;

            $pdo->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, transaction_type, amount, description, reference_type, reference_id, created_by, created_at) VALUES (?, ?, 'deposit', ?, ?, 'transfer', ?, ?, ?)")
                ->execute([$to_bank_id, $date, $amount, $dep_desc, $transfer_id, $user_id, date('Y-m-d')]);
            $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                ->execute([$amount, $to_bank_id]);
        } elseif ($to_type === 'cash') {
            $cash_in_desc = 'Cash Inflow';
            if ($type === 'bank_to_cash') {
                $from_acc = getById('bank_accounts', $from_bank_id);
                $cash_in_desc = 'Cash received from Bank: ' . ($from_acc ? ($from_acc['bank_name'] . ' - ' . $from_acc['account_name']) : 'Bank') . ' [' . $voucher_no . ']';
            } elseif ($type === 'customer_receipt') {
                $cust = getById('customers', $customer_id);
                $cash_in_desc = 'Received from Customer: ' . ($cust ? $cust['full_name'] : '') . ' [' . $voucher_no . ']';
            } elseif ($type === 'person_receipt') {
                $cash_in_desc = 'Received from: ' . $person_name . ' [' . $voucher_no . ']';
            }
            if ($desc) $cash_in_desc .= ' - ' . $desc;
            recordCashInflow($pdo, $date, $amount, $cash_in_desc, 'transfer', $transfer_id, $user_id);
        } elseif ($to_type === 'customer') {
            // Customer received money from us: Debit to customer
            updateCustomerBalance($pdo, $customer_id);
        }

        logActivity($pdo, 'transfer', 'transfer', $transfer_id, 'Voucher #' . $voucher_no . ' (' . $type . ') Amount: ' . $amount);
        $pdo->commit();
        return ['success' => true, 'id' => $transfer_id, 'voucher_no' => $voucher_no];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Reverse/Delete a transfer record and restore balances
function deleteFundTransfer($pdo, $transfer_id) {
    $t = getById('fund_transfers', $transfer_id);
    if (!$t) throw new Exception('Transfer record not found.');

    $pdo->beginTransaction();
    try {
        $customer_id = $t['customer_id'];
        $v_no = $t['voucher_no'];

        // Reverse Bank Transactions
        $b_txns = $pdo->prepare("SELECT * FROM bank_transactions WHERE reference_type = 'transfer' AND reference_id = ?");
        $b_txns->execute([$transfer_id]);
        foreach ($b_txns->fetchAll() as $bt) {
            if ($bt['transaction_type'] === 'withdrawal' || $bt['transaction_type'] === 'transfer_out') {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([(float)$bt['amount'], $bt['bank_account_id']]);
            } elseif ($bt['transaction_type'] === 'deposit' || $bt['transaction_type'] === 'transfer_in') {
                $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([(float)$bt['amount'], $bt['bank_account_id']]);
            }
            $pdo->prepare("DELETE FROM bank_transactions WHERE id = ?")->execute([$bt['id']]);
        }

        // Reverse Cash Transactions
        $c_txns = $pdo->prepare("SELECT id, daily_id, transaction_date FROM cash_book WHERE reference_type = 'transfer' AND reference_id = ?");
        $c_txns->execute([$transfer_id]);
        $c_rows = $c_txns->fetchAll();
        $min_date = null;
        $daily_ids = [];
        foreach ($c_rows as $cr) {
            $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$cr['id']]);
            $daily_ids[] = $cr['daily_id'];
            if ($min_date === null || $cr['transaction_date'] < $min_date) $min_date = $cr['transaction_date'];
        }
        foreach (array_unique($daily_ids) as $did) {
            if ($did) recomputeCashDayTotals($pdo, $did);
        }
        if ($min_date) recomputeCashDailyFrom($pdo, $min_date);

        // If customer_receipt was linked, remove the customer_receipts entry
        if ($t['transfer_type'] === 'customer_receipt' && $customer_id) {
            $pdo->prepare("DELETE FROM customer_receipts WHERE customer_id = ? AND description LIKE ?")->execute([$customer_id, "%{$v_no}%"]);
        }

        // Delete transfer row
        $pdo->prepare("DELETE FROM fund_transfers WHERE id = ?")->execute([$transfer_id]);

        // Recalculate customer balance if customer was involved
        if ($customer_id) {
            updateCustomerBalance($pdo, $customer_id);
        }

        logActivity($pdo, 'delete', 'transfer', $transfer_id, 'Deleted voucher #' . $v_no);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Edit/Update an existing transfer record and adjust running ledger balances
function updateFundTransfer($pdo, $transfer_id, $data) {
    $t = getById('fund_transfers', $transfer_id);
    if (!$t) throw new Exception('Transfer record not found.');

    $old_amount = (float)$t['amount'];
    $new_amount = (float)($data['amount'] ?? 0);
    $new_date = !empty($data['transfer_date']) ? $data['transfer_date'] : $t['transfer_date'];
    $new_ref = trim($data['reference_no'] ?? '');
    $new_desc = trim($data['description'] ?? '');
    $new_person_name = trim($data['person_name'] ?? '');
    $new_person_phone = trim($data['person_phone'] ?? '');

    if ($new_amount <= 0) throw new Exception('Amount must be greater than zero.');

    $pdo->beginTransaction();
    try {
        $diff = $new_amount - $old_amount; // positive if increased, negative if decreased
        $from_type = $t['from_type'];
        $from_bank_id = $t['from_bank_id'];
        $to_type = $t['to_type'];
        $to_bank_id = $t['to_bank_id'];
        $customer_id = $t['customer_id'];
        $voucher_no = $t['voucher_no'];

        // 1. Adjust Source (Outflow)
        if ($from_type === 'bank') {
            $pdo->prepare("UPDATE bank_transactions SET amount = ?, transaction_date = ? WHERE reference_type = 'transfer' AND reference_id = ? AND transaction_type = 'withdrawal'")
                ->execute([$new_amount, $new_date, $transfer_id]);
            $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                ->execute([$diff, $from_bank_id]);
        } elseif ($from_type === 'cash') {
            $pdo->prepare("UPDATE cash_book SET amount = ?, transaction_date = ? WHERE reference_type = 'transfer' AND reference_id = ? AND transaction_type = 'outflow'")
                ->execute([$new_amount, $new_date, $transfer_id]);
            $c_st = $pdo->prepare("SELECT daily_id FROM cash_book WHERE reference_type = 'transfer' AND reference_id = ? LIMIT 1");
            $c_st->execute([$transfer_id]);
            $c_did = $c_st->fetchColumn();
            if ($c_did) {
                recomputeCashDayTotals($pdo, $c_did);
                recomputeCashDailyFrom($pdo, min($t['transfer_date'], $new_date));
            }
        } elseif ($from_type === 'customer') {
            $pdo->prepare("UPDATE customer_receipts SET amount = ?, receipt_date = ? WHERE customer_id = ? AND description LIKE ?")
                ->execute([$new_amount, $new_date, $customer_id, "%{$voucher_no}%"]);
            updateCustomerBalance($pdo, $customer_id);
        }

        // 2. Adjust Destination (Inflow)
        if ($to_type === 'bank') {
            $pdo->prepare("UPDATE bank_transactions SET amount = ?, transaction_date = ? WHERE reference_type = 'transfer' AND reference_id = ? AND transaction_type = 'deposit'")
                ->execute([$new_amount, $new_date, $transfer_id]);
            $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                ->execute([$diff, $to_bank_id]);
        } elseif ($to_type === 'cash') {
            $pdo->prepare("UPDATE cash_book SET amount = ?, transaction_date = ? WHERE reference_type = 'transfer' AND reference_id = ? AND transaction_type = 'inflow'")
                ->execute([$new_amount, $new_date, $transfer_id]);
            $c_st = $pdo->prepare("SELECT daily_id FROM cash_book WHERE reference_type = 'transfer' AND reference_id = ? LIMIT 1");
            $c_st->execute([$transfer_id]);
            $c_did = $c_st->fetchColumn();
            if ($c_did) {
                recomputeCashDayTotals($pdo, $c_did);
                recomputeCashDailyFrom($pdo, min($t['transfer_date'], $new_date));
            }
        } elseif ($to_type === 'customer') {
            // Customer payment (Debit) balance auto recalculates
        }

        // 3. Update fund_transfers record
        $up = $pdo->prepare("UPDATE fund_transfers SET amount = ?, transfer_date = ?, reference_no = ?, description = ?, person_name = ?, person_phone = ? WHERE id = ?");
        $up->execute([
            $new_amount,
            $new_date,
            $new_ref ?: null,
            $new_desc ?: null,
            $new_person_name ?: null,
            $new_person_phone ?: null,
            $transfer_id
        ]);

        if ($customer_id) {
            updateCustomerBalance($pdo, $customer_id);
        }

        logActivity($pdo, 'edit', 'transfer', $transfer_id, 'Updated voucher #' . $voucher_no . ' to amount PKR ' . $new_amount);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}