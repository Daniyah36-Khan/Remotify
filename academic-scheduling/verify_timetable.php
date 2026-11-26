<?php
// verify_timetable.php — independent checker for the newest generated timetable. Run from a terminal:
//     php verify_timetable.php            (uses the database in db.php)
//     php verify_timetable.php other_db   (another database)
// It judges "same time" by real TIME OVERLAP (not only identical slot ids) and checks, for the latest run:
//   room / teacher / student double-booking, seats vs enrolled students, lab class in a lab room,
//   theory sessions of a course on different days, lab not on a day that has the same course's theory.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }
require __DIR__ . '/db.php';
if (!empty($argv[1])) $pdo->exec("USE `" . str_replace('`', '', $argv[1]) . "`");
$rid = (int) $pdo->query("SELECT MAX(run_id) FROM timetable_runs")->fetchColumn();
$rows = $pdo->query("SELECT g.timetable_id,g.course_id,g.faculty_id,g.room_id,g.slot_id,g.component_type,r.capacity,r.room_type,s.day,TIME_TO_SEC(s.start_time) st,TIME_TO_SEC(s.end_time) en
  FROM generated_timetable g JOIN rooms r USING(room_id) JOIN time_slots s USING(slot_id) WHERE g.run_id=$rid")->fetchAll();
$stu = []; foreach ($pdo->query("SELECT course_id, student_id FROM enrollments") as $e) $stu[$e['course_id']][$e['student_id']] = 1;
$err = []; $add = function ($k) use (&$err) { $err[$k] = ($err[$k] ?? 0) + 1; };
foreach ($rows as $r) {
    if ($r['capacity'] < count($stu[$r['course_id']] ?? [])) $add('capacity');
    if ($r['component_type'] === 'Lab' && $r['room_type'] !== 'Lab') $add('lab_in_hall');
}
$byDay = []; foreach ($rows as $r) $byDay[$r['day']][] = $r;
$pairs = 0;
foreach ($byDay as $L) { $n = count($L);
    for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) { $a = $L[$i]; $b = $L[$j];
        if (!($a['st'] < $b['en'] && $b['st'] < $a['en'])) continue; $pairs++;
        if ($a['room_id'] == $b['room_id']) $add('ROOM_overlap');
        if ($a['faculty_id'] == $b['faculty_id']) $add('TEACHER_overlap');
        $sa = $stu[$a['course_id']] ?? []; $sb = $stu[$b['course_id']] ?? [];
        if (count($sa) > count($sb)) [$sa, $sb] = [$sb, $sa];
        foreach ($sa as $sid => $_) if (isset($sb[$sid])) { $add('STUDENT_overlap'); break; }
    } }
$bc = []; foreach ($rows as $r) $bc[$r['course_id']][] = $r;
foreach ($bc as $L) { $td = [];
    foreach ($L as $r) if ($r['component_type'] === 'Theory') { if (isset($td[$r['day']])) $add('theory_same_day'); $td[$r['day']] = 1; }
    foreach ($L as $r) if ($r['component_type'] === 'Lab' && isset($td[$r['day']])) $add('lab_on_theory_day'); }
$un = (string) $pdo->query("SELECT COALESCE(unplaced_json,'') FROM timetable_runs WHERE run_id=$rid")->fetchColumn();
echo "run=$rid classes=" . count($rows) . " overlapping-pairs-checked=$pairs unplaced=" . ($un === '' ? 0 : count(json_decode($un, true))) . "\n";
echo $err ? "PROBLEMS: " . json_encode($err) . "\n" : "ALL CHECKS PASSED\n";
exit($err ? 1 : 0);
