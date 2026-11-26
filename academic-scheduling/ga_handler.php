<?php
/**
 * ga_handler.php — Job Dispatcher
 * Creates a background GA job and returns immediately with job_id.
 * The actual work is done by ga_worker.php (CLI process).
 * Frontend polls ga_status.php for progress.
 */
session_start();
$isSessionAdmin = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && $_SESSION['role'] === 'admin';
if (!$isSessionAdmin) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
require 'db.php';
header('Content-Type: application/json');
error_reporting(0);

// Auto-create ga_jobs table if it doesn't exist yet
$pdo->exec("CREATE TABLE IF NOT EXISTS ga_jobs (
    job_id        INT AUTO_INCREMENT PRIMARY KEY,
    session_name  VARCHAR(50)  NOT NULL,
    status        ENUM('queued','running','complete','failed') DEFAULT 'queued',
    progress      VARCHAR(255) DEFAULT 'Queued...',
    dept_done     INT DEFAULT 0,
    dept_total    INT DEFAULT 0,
    fitness_score INT DEFAULT 0,
    classes_count INT DEFAULT 0,
    run_id        INT DEFAULT NULL,
    run_label     VARCHAR(120) DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$session = trim($_POST['session'] ?? '');
if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Session name is required.']);
    exit;
}

// Insert the job record
$pdo->prepare("INSERT INTO ga_jobs (session_name, status, progress) VALUES (?, 'queued', 'Starting...')")
    ->execute([$session]);
$jobId = (int)$pdo->lastInsertId();

/**
 * Find the PHP CLI binary (php.exe).
 * IMPORTANT: under Apache mod_php, PHP_BINARY points to httpd.exe — NOT php.exe —
 * so we must locate the real CLI binary. On WAMP it lives next to the loaded
 * php.ini; we also glob common install paths as a fallback.
 */
function findPhpCli(): ?string {
    // 1. If we're already running under CLI, PHP_BINARY is correct.
    if (PHP_SAPI === 'cli' && is_file(PHP_BINARY)) return PHP_BINARY;

    $exe = (PHP_OS_FAMILY === 'Windows') ? 'php.exe' : 'php';

    // 2. Next to the loaded php.ini (most reliable on WAMP/XAMPP).
    $iniDir = dirname(php_ini_loaded_file() ?: '');
    if ($iniDir && is_file($iniDir . DIRECTORY_SEPARATOR . $exe))
        return $iniDir . DIRECTORY_SEPARATOR . $exe;

    // 3. PHP_BINDIR (works on many CGI/FastCGI setups).
    if (defined('PHP_BINDIR') && is_file(PHP_BINDIR . DIRECTORY_SEPARATOR . $exe))
        return PHP_BINDIR . DIRECTORY_SEPARATOR . $exe;

    // 4. Glob common WAMP/XAMPP locations as a last resort (Windows).
    if (PHP_OS_FAMILY === 'Windows') {
        foreach (['C:','D:','E:'] as $drive) {
            $hits = glob("$drive/wamp*/bin/php/php*/php.exe") ?: [];
            $hits = array_merge($hits, glob("$drive/xampp/php/php.exe") ?: []);
            if ($hits) { rsort($hits); return str_replace('/', DIRECTORY_SEPARATOR, $hits[0]); }
        }
    }
    return null;
}

/**
 * Pick the engine.  MPI engine (ga_mpi.py, mpi4py) when it is enabled in mpi_config.json and Python + mpi4py + pymysql +
 * mpiexec really work; otherwise the PHP engine (ga_worker.php).  The reason is stored in the job's progress text.
 */
function chooseEngine(PDO $pdo, string $dbName, ?string &$why): ?array {
    $cfg = ['enabled' => true, 'processes' => 4, 'seconds' => 120,
            'python' => (PHP_OS_FAMILY === 'Windows') ? 'python' : 'python3', 'mpiexec' => 'mpiexec'];
    $f = __DIR__ . DIRECTORY_SEPARATOR . 'mpi_config.json';
    if (is_file($f)) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j)) $cfg = array_merge($cfg, $j); }
    if (empty($cfg['enabled'])) { $why = 'MPI disabled in mpi_config.json'; return null; }
    if (!function_exists('exec')) { $why = 'exec() is disabled in PHP'; return null; }
    // the MPI engine needs theory slots, and lab slots when lab courses exist
    $slots = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE,start_time,end_time) m FROM time_slots")->fetchAll(PDO::FETCH_COLUMN);
    $th = 0; $lb = 0;
    foreach ($slots as $m) { if ($m >= 80 && $m <= 130) $th++; if ($m >= 150 && $m <= 210) $lb++; }
    if (!$th) { $why = 'no theory slots (80-130 min)'; return null; }
    $hasLab = (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE credit_hours REGEXP '^[0-9]+-[1-9][0-9]*-'")->fetchColumn();
    if ($hasLab && !$lb) { $why = 'lab courses but no lab slots (150-210 min)'; return null; }
    $null = (PHP_OS_FAMILY === 'Windows') ? '2>NUL' : '2>/dev/null';
    $out = []; $code = 1;
    @exec('"' . $cfg['python'] . '" -c "import mpi4py, pymysql" ' . $null, $out, $code);
    if ($code !== 0) { $why = 'Python with mpi4py + pymysql not found (pip install mpi4py pymysql)'; return null; }
    $out = []; $code = 1;
    @exec('"' . $cfg['mpiexec'] . '" -n 1 "' . $cfg['python'] . '" -c "print(1)" ' . $null, $out, $code);
    if ($code !== 0) { $why = 'mpiexec not found (install MS-MPI / OpenMPI)'; return null; }
    $why = 'MPI engine, ' . (int)$cfg['processes'] . ' processes';
    return $cfg;
}

$why = '';
$mpi = chooseEngine($pdo, $db, $why);
$phpBin = findPhpCli();
$script  = __DIR__ . DIRECTORY_SEPARATOR . 'ga_worker.php';

if ($mpi) {
    $mpiArgs = '-n ' . (int)$mpi['processes'] . ' "' . $mpi['python'] . '" "' . __DIR__ . DIRECTORY_SEPARATOR . 'ga_mpi.py" '
             . $jobId . ' --db ' . escapeshellarg($db) . ' --seconds ' . (int)$mpi['seconds'];
    $pdo->prepare("UPDATE ga_jobs SET progress=? WHERE job_id=?")->execute(['Starting ' . $why . '...', $jobId]);
    chdir(__DIR__);
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start "" /B "' . $mpi['mpiexec'] . '" ' . $mpiArgs;
        $h = @popen($cmd, 'r'); if ($h !== false) pclose($h); else @shell_exec($cmd);
    } else {
        @exec('"' . $mpi['mpiexec'] . '" ' . $mpiArgs . ' > /dev/null 2>&1 &');
    }
    echo json_encode(['success' => true, 'job_id' => $jobId, 'message' => 'Timetable generation started (' . $why . ').', 'session' => $session, 'engine' => 'mpi']);
    exit;
}
$pdo->prepare("UPDATE ga_jobs SET progress=? WHERE job_id=?")->execute(['Starting PHP engine (' . $why . ')...', $jobId]);

if (!$phpBin) {
    // No CLI binary — cannot background. Fail clearly instead of hanging forever.
    $pdo->prepare("UPDATE ga_jobs SET status='failed', progress=? WHERE job_id=?")
        ->execute(['Could not locate php.exe (CLI). Cannot run the timetable worker.', $jobId]);
    echo json_encode(['success' => false, 'message' => 'Server could not locate the PHP CLI binary to run the GA worker.']);
    exit;
}

// Launch ga_worker.php detached so the browser does NOT wait for it.
$launched = false;
if (PHP_OS_FAMILY === 'Windows') {
    // start "" /B runs the process in the background, detached from this request.
    $cmd = 'start "" /B "' . $phpBin . '" "' . $script . '" ' . $jobId;
    if (function_exists('popen')) {
        $h = @popen($cmd, 'r');
        if ($h !== false) { pclose($h); $launched = true; }
    }
    if (!$launched && function_exists('shell_exec')) {
        @shell_exec($cmd);
        $launched = true; // shell_exec returns null even on success — assume launched
    }
    // Last resort: COM WScript.Shell (truly detached, no window, no wait)
    if (!$launched && class_exists('COM')) {
        try {
            $wsh = new COM('WScript.Shell');
            $wsh->Run('"' . $phpBin . '" "' . $script . '" ' . $jobId, 0, false);
            $launched = true;
        } catch (Throwable $e) { /* ignore */ }
    }
} else {
    @exec('"' . $phpBin . '" "' . $script . '" ' . $jobId . ' > /dev/null 2>&1 &');
    $launched = true;
}

echo json_encode([
    'success'  => true,
    'job_id'   => $jobId,
    'message'  => 'Timetable generation started.',
    'session'  => $session,
]);