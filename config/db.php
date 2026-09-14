<?php
// Ensure output buffering is active so header redirects work smoothly on PHP 8.2
if (!ob_get_level()) {
    ob_start();
}

// Silence PHP 8.1 / 8.2 deprecation warnings from breaking output or JSON AJAX responses
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

$host = 'localhost';
$username = 'root';
$password = '';

$dbname = 'scrap_management_system';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Disable strict group-by and zero-date checks on MySQL 8.0 / MariaDB
    $pdo->exec("SET SESSION sql_mode=''");
} catch (PDOException $e) {
    die("<div style='font-family:Arial,sans-serif;padding:30px;background:#fff1f2;border:1px solid #fda4af;border-radius:8px;max-width:600px;margin:50px auto;color:#9f1239;'>"
        . "<h3 style='margin-top:0;'>Database Connection Error</h3>"
        . "<p>" . htmlspecialchars($e->getMessage()) . "</p>"
        . "<hr style='border:0;border-top:1px solid #fecdd3;margin:15px 0;'>"
        . "<p style='font-size:14px;color:#4b5563;'><strong>Possible Solutions for XAMPP 8.2:</strong><br>"
        . "1. Make sure <strong>MySQL</strong> is running in XAMPP Control Panel.<br>"
        . "2. Ensure the database <strong>`$dbname`</strong> exists and is imported in phpMyAdmin.<br>"
        . "3. If MySQL root has a password, set it in <code>config/db.php</code>.</p>"
        . "</div>");
}
?>