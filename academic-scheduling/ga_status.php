<?php
/**
 * ga_status.php — GA Job Status Polling Endpoint
 * Called every 2 seconds by the frontend to check progress.
 */
session_start();
$isSessionOk = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
if (!$isSessionOk) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
require 'db.php';
header('Content-Type: application/json');
error_reporting(0);

$jobId = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);
if (!$jobId) {
    echo json_encode(['success' => false, 'message' => 'No job_id provided.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM ga_jobs WHERE job_id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    echo json_encode(['success' => false, 'message' => 'Job not found.']);
    exit;
}

$resp = [
    'success'       => true,
    'status'        => $job['status'],          // queued | running | complete | failed
    'progress'      => $job['progress'],
    'dept_done'     => (int)$job['dept_done'],
    'dept_total'    => (int)$job['dept_total'],
    'fitness_score' => (int)$job['fitness_score'],
    'classes_count' => (int)$job['classes_count'],
    'run_id'        => $job['run_id'] ? (int)$job['run_id'] : null,
    'run_label'     => $job['run_label'],
    'message'       => $job['progress'],
];

// Convenience flag for the frontend
$resp['done']   = in_array($job['status'], ['complete', 'failed']);
$resp['failed'] = $job['status'] === 'failed';

echo json_encode($resp);
