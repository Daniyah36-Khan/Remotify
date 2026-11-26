<?php
// demo_handler.php — Demo user registration & login with isolated sandbox database

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$host   = '127.0.0.1';
$port   = '3308';
$dbUser = 'root';
$dbPass = '';

// Always connect to the master database for user management
try {
    $masterPdo = new PDO(
        "mysql:host=$host;port=$port;dbname=slotsmart_db;charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}

$action = $_POST['action'] ?? '';

// ── 1. CHECK EMAIL — is this a new or returning user? ──────────
if ($action === 'check_email') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }
    // Admin email → send to login page
    $adminCheck = $masterPdo->prepare("SELECT user_id FROM users WHERE email = ? AND is_demo = 0");
    $adminCheck->execute([$email]);
    if ($adminCheck->fetch()) {
        echo json_encode(['success' => true, 'is_admin' => true, 'redirect' => 'login.php']);
        exit;
    }

    $stmt = $masterPdo->prepare("SELECT user_id FROM users WHERE email = ? AND is_demo = 1");
    $stmt->execute([$email]);
    $exists = (bool)$stmt->fetch();
    echo json_encode(['success' => true, 'is_new' => !$exists]);
    exit;
}

// ── 2. ACCESS — register new or login returning user ────────────
if ($action === 'access') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit;
    }

    // Check if this email belongs to the fixed admin — redirect to login
    $adminCheck = $masterPdo->prepare("SELECT user_id FROM users WHERE email = ? AND is_demo = 0");
    $adminCheck->execute([$email]);
    if ($adminCheck->fetch()) {
        echo json_encode(['success' => true, 'is_admin' => true, 'redirect' => 'login.php']);
        exit;
    }

    // Look up existing demo user
    $stmt = $masterPdo->prepare("SELECT * FROM users WHERE email = ? AND is_demo = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // RETURNING USER — verify password
        if (!password_verify($password, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Incorrect password. Try again.']);
            exit;
        }
        $userId = (int)$user['user_id'];

        // Make sure their database still exists (re-create if somehow dropped)
        ensureDemoDatabase($masterPdo, $userId, $host, $port, $dbUser, $dbPass);

    } else {
        // NEW USER — create account and database
        $confirm = trim($_POST['confirm_password'] ?? '');
        if ($password !== $confirm) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit;
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $masterPdo->prepare("INSERT INTO users (name, role, email, password, is_demo) VALUES (?, 'admin', ?, ?, 1)")
            ->execute([explode('@', $email)[0], $email, $hashed]);
        $userId = (int)$masterPdo->lastInsertId();

        // Create their private sandbox database
        ensureDemoDatabase($masterPdo, $userId, $host, $port, $dbUser, $dbPass);
    }

    // Log the user in
    $_SESSION['loggedin'] = true;
    $_SESSION['user_id']  = $userId;
    $_SESSION['email']    = $email;
    $_SESSION['role']     = 'admin';
    $_SESSION['is_demo']  = 1;

    echo json_encode(['success' => true, 'redirect' => 'admin-forms.php']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);

// ── Helper: create demo database if it does not exist ──────────
function ensureDemoDatabase(PDO $masterPdo, int $userId, string $host, string $port, string $dbUser, string $dbPass): void {
    $dbName = 'slotsmart_demo_' . $userId;

    // Create database
    $masterPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Connect to it
    $demoPdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Check if tables already exist (avoid re-running schema on returning user)
    $tables = $demoPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables) > 0) return;

    // Execute schema template — run each statement individually
    $sql = file_get_contents(__DIR__ . '/schema_template.sql');
    // Strip comments
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt !== '') $demoPdo->exec($stmt);
    }
}
?>
