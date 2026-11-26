<?php
/**
 * import_test_data.php — CLI bulk importer for students + enrollments
 * Clears old students/enrollments, then imports the corrected CSVs directly.
 * Run:  php import_test_data.php
 *
 * Much faster + more reliable than the browser uploader for large files.
 * Safe to delete after use.
 */
require __DIR__ . '/db.php';

$csvDir = __DIR__ . '/assets/csvs/';
$t0 = microtime(true);

echo "=== SlotSmart Test Data Importer ===\n";

// ── 1. Clear old data (enrollments first — FK references students) ──────────
echo "Clearing old enrollments + students... ";
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo->exec("TRUNCATE TABLE enrollments");
$pdo->exec("TRUNCATE TABLE students");
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
echo "done.\n";

// ── 2. Lookup maps ──────────────────────────────────────────────────────────
$deptId = [];
foreach ($pdo->query("SELECT department_id, department_name FROM departments") as $r)
    $deptId[$r['department_name']] = (int)$r['department_id'];

$progId = [];
foreach ($pdo->query("SELECT program_id, program_name FROM programs") as $r)
    $progId[$r['program_name']] = (int)$r['program_id'];

$courseId = [];
foreach ($pdo->query("SELECT course_id, course_code FROM courses") as $r)
    $courseId[$r['course_code']] = (int)$r['course_id'];

// ── 3. Import students ───────────────────────────────────────────────────────
echo "Importing students... ";
$fh = fopen($csvDir . 'students (1).csv', 'r');
fgetcsv($fh); // header
$ins = $pdo->prepare("INSERT INTO students
    (student_name,email,registration_number,phone_number,dob,gender,student_type,department_id,program_id)
    VALUES (?,?,?,?,?,?,?,?,?)");

$studentNameId = [];   // name => student_id  (built as we insert, for enrollment step)
$nStu = 0;
$pdo->beginTransaction();
while (($row = fgetcsv($fh)) !== false) {
    $row = array_map('trim', $row);
    if (empty($row[0])) continue;
    // CSV cols: name,email,reg,phone,dob,gender,dept,program,type
    $dept = $deptId[$row[6]] ?? null;
    $prog = $progId[$row[7]] ?? null;
    $type = ($row[8] === 'transfer') ? 'transfer' : 'regular';

    $ins->execute([
        $row[0], $row[1] ?: null, $row[2] ?: null, $row[3] ?: null, $row[4] ?: null,
        $row[5] ?: 'Male', $type, $dept, $prog
    ]);
    $studentNameId[$row[0]] = (int)$pdo->lastInsertId(); // last writer wins (names are unique anyway)
    $nStu++;
    if ($nStu % 2000 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
}
$pdo->commit();
fclose($fh);
echo "$nStu students.\n";

// ── 4. Import enrollments ────────────────────────────────────────────────────
echo "Importing enrollments... ";
$fh = fopen($csvDir . 'enrollments (1).csv', 'r');
fgetcsv($fh); // header
$ins = $pdo->prepare("INSERT IGNORE INTO enrollments (batch_year,semester,student_id,course_id) VALUES (?,?,?,?)");

$nEnr = 0; $skipped = 0;
$pdo->beginTransaction();
while (($row = fgetcsv($fh)) !== false) {
    $row = array_map('trim', $row);
    // CSV cols: batch_year, semester, student_name, course_code
    if (empty($row[2]) || empty($row[3])) continue;
    $sid = $studentNameId[$row[2]] ?? null;
    $cid = $courseId[$row[3]] ?? null;
    if (!$sid || !$cid) { $skipped++; continue; }
    $ins->execute([$row[0], $row[1], $sid, $cid]);
    $nEnr++;
    if ($nEnr % 5000 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
}
$pdo->commit();
fclose($fh);
echo "$nEnr enrollments" . ($skipped ? " ($skipped skipped — name/code not found)" : "") . ".\n";

// ── 5. Verify coverage ───────────────────────────────────────────────────────
echo "\n=== Verification ===\n";
$q = $pdo->query("SELECT d.department_name, COUNT(DISTINCT c.course_id) AS enrolled_courses
    FROM courses c JOIN departments d ON c.department_id=d.department_id
    WHERE EXISTS (SELECT 1 FROM enrollments e WHERE e.course_id=c.course_id)
    GROUP BY d.department_name ORDER BY d.department_name");
foreach ($q as $r) echo "  {$r['department_name']}: {$r['enrolled_courses']} courses have students\n";

$max = $pdo->query("SELECT MAX(cnt) FROM (SELECT COUNT(*) cnt FROM enrollments GROUP BY course_id) t")->fetchColumn();
$min = $pdo->query("SELECT MIN(cnt) FROM (SELECT COUNT(*) cnt FROM enrollments GROUP BY course_id) t")->fetchColumn();
echo "  Students per course — min:$min max:$max\n";

echo "\nDone in " . round(microtime(true) - $t0, 1) . "s.\n";
