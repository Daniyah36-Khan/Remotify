<?php
// ADBS demo: EXPLAIN + timing of the hot queries WITHOUT and WITH the new indexes / clash keys.
// Run:  php adbs_explain.php [database]      (default: the database in db.php)
require __DIR__ . '/db.php';
if (!isset($pdo)) { fwrite(STDERR, "db.php did not give \$pdo\n"); exit(1); }
if (!empty($argv[1])) $pdo->exec("USE `" . str_replace('`', '', $argv[1]) . "`");
$rid = (int) $pdo->query("SELECT MAX(run_id) FROM timetable_runs")->fetchColumn();
$cid = (int) $pdo->query("SELECT course_id FROM enrollments GROUP BY course_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$g   = $pdo->query("SELECT batch_year, semester FROM enrollments WHERE batch_year IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_NUM) ?: ['2023', '1'];
$gt  = $pdo->query("SELECT room_id, slot_id FROM generated_timetable WHERE run_id = $rid LIMIT 1")->fetch(PDO::FETCH_NUM) ?: [1, 1];
$ses = (string) $pdo->query("SELECT session_name FROM allocations LIMIT 1")->fetchColumn();
$Q = [
 'students of one course'        => ["SELECT COUNT(DISTINCT student_id) FROM enrollments WHERE course_id = ?", [$cid]],
 'room/slot clash check'         => ["SELECT timetable_id FROM generated_timetable WHERE run_id = ? AND room_id = ? AND slot_id = ?", [$rid, $gt[0], $gt[1]]],
];
$IDX = [
 'idx_enroll_course_student' => 'enrollments',
 'uq_run_slot_room' => 'generated_timetable', 'uq_run_slot_faculty' => 'generated_timetable',
];
function ixExists($pdo, $t, $n) { $s = $pdo->prepare("SHOW INDEX FROM `$t` WHERE Key_name = ?"); $s->execute([$n]); return (bool) $s->fetch(); }
function measure($pdo, $sql, $p, $reps = 200) {
    $e = $pdo->prepare("EXPLAIN $sql"); $e->execute($p); $x = $e->fetch(PDO::FETCH_ASSOC);
    $st = $pdo->prepare($sql); $t = microtime(true);
    for ($i = 0; $i < $reps; $i++) { $st->execute($p); $st->fetchAll(); }
    return [round((microtime(true) - $t) * 1000 / $reps, 3), $x['key'] ?? 'NONE (full scan)', $x['type'] ?? '', $x['rows'] ?? '?'];
}
// keep definitions so we can put everything back
$defs = [
 'idx_enroll_course_student' => "CREATE INDEX idx_enroll_course_student ON enrollments (course_id, student_id)",
 'uq_run_slot_room'          => "ALTER TABLE generated_timetable ADD UNIQUE KEY uq_run_slot_room (run_id, slot_id, room_id)",
 'uq_run_slot_faculty'       => "ALTER TABLE generated_timetable ADD UNIQUE KEY uq_run_slot_faculty (run_id, slot_id, faculty_id)",
];
$had = [];
foreach ($IDX as $n => $t) { $had[$n] = ixExists($pdo, $t, $n); if ($had[$n]) try { $pdo->exec("DROP INDEX `$n` ON `$t`"); } catch (Exception $e) { echo "(cannot drop $n: " . $e->getMessage() . ")\n"; } }
foreach (['enrollments','allocations','generated_timetable'] as $t) $pdo->query("ANALYZE TABLE `$t`")->fetchAll();
$before = []; foreach ($Q as $k => [$s, $p]) $before[$k] = measure($pdo, $s, $p);
foreach ($IDX as $n => $t) if ($had[$n] && !ixExists($pdo, $t, $n)) $pdo->exec($defs[$n]);
foreach (['enrollments','allocations','generated_timetable'] as $t) $pdo->query("ANALYZE TABLE `$t`")->fetchAll();
$after = []; foreach ($Q as $k => [$s, $p]) $after[$k] = measure($pdo, $s, $p);
printf("%-26s | %-34s | %-34s | speed-up\n", 'query', 'BEFORE (ms, key, rows)', 'AFTER (ms, key, rows)');
foreach ($Q as $k => $_) {
  [$b, $a] = [$before[$k], $after[$k]];
  printf("%-26s | %7.3f %-18s %5s | %7.3f %-18s %5s | x%.1f\n", $k, $b[0], $b[1], $b[3], $a[0], $a[1], $a[3], $a[0] > 0 ? $b[0] / $a[0] : 0);
}
