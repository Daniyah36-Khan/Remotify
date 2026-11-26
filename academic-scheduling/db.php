<?php
// db.php — Dynamic database connection
// Admin + API  → slotsmart_db
// Demo users   → slotsmart_demo_{user_id}  (their own isolated sandbox)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host    = '127.0.0.1';
$dbUser  = 'root';
$dbPass  = '';
$port    = '3308';
$charset = 'utf8mb4';

// Route to the correct database
if (!empty($_SESSION['is_demo']) && !empty($_SESSION['user_id'])) {
    $db = 'slotsmart_demo_' . (int)$_SESSION['user_id'];
} else {
    $db = 'slotsmart_db';
}

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (\PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'DB Connection Failed: ' . $e->getMessage()]));
}
?>
