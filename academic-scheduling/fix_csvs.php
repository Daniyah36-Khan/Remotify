<?php
// fix_csvs.php — regenerate allocations + enrollments using REAL course codes
// keyed entirely off the current courses/students/faculty CSVs so they match.
$base = 'D:/wamp123/www/Slotsmart-1/assets/csvs/';

function readCsv($file) {
    $rows = []; $h = fopen($file, 'r'); if ($h === false) return $rows;
    $hdr = fgetcsv($h);
    while (($r = fgetcsv($h)) !== false) { if (count($r) === 1 && trim($r[0]) === '') continue; $rows[] = $r; }
    fclose($h); return $rows;
}

// ── Courses: program_name => semester => [course_code] ──
$courseRows = readCsv($base . 'courses (2).csv');
$progSemCourses = [];
foreach ($courseRows as $r) {
    if (empty($r[0])) continue;
    $code = trim($r[0]);
    $progName = trim($r[5] ?? '');
    preg_match('/(\d+)/', $code, $m);
    $sem = max(1, min(8, (int)floor(((int)($m[1] ?? 100)) / 100)));
    $progSemCourses[$progName][$sem][] = $code;
}

// ── Courses: department_name => [course_code] for active semesters ──
$deptActiveCourses = [];
$activeSems = [1, 3, 5, 7];
foreach ($courseRows as $r) {
    if (empty($r[0])) continue;
    $code = trim($r[0]);
    $deptName = trim($r[4] ?? '');
    preg_match('/(\d+)/', $code, $m);
    $sem = max(1, min(8, (int)floor(((int)($m[1] ?? 100)) / 100)));
    if (in_array($sem, $activeSems)) $deptActiveCourses[$deptName][] = $code;
}

// ── Faculty: department_name => [email] ──
$facRows = readCsv($base . 'faculty (1).csv');
$deptFaculty = [];
foreach ($facRows as $r) {
    $email = trim($r[1] ?? '');
    $deptName = trim($r[3] ?? '');
    if ($email && $deptName) $deptFaculty[$deptName][] = $email;
}

// ── Students: parse batch + program from reg_number / program_name ──
$studRows = readCsv($base . 'students (1).csv');
$batchToSem = ['2024'=>['s'=>1,'label'=>'Semester 1'], '2023'=>['s'=>3,'label'=>'Semester 3'],
               '2022'=>['s'=>5,'label'=>'Semester 5'], '2021'=>['s'=>7,'label'=>'Semester 7']];

// ── Build ALLOCATIONS: session, faculty_email, course_code ──
$session = 'Fall 2025';
$allocRows = ['session_name,faculty_email,course_code'];
$allocSeen = [];
foreach ($deptActiveCourses as $deptName => $codes) {
    $emails = $deptFaculty[$deptName] ?? [];
    if (empty($emails)) continue;
    $i = 0;
    foreach ($codes as $code) {
        if (isset($allocSeen[$code])) continue;     // one allocation per course/session (unique_alloc)
        $allocSeen[$code] = true;
        $email = $emails[$i % count($emails)];
        $allocRows[] = "$session,$email,$code";
        $i++;
    }
}
file_put_contents($base . 'allocations (1).csv', implode("\n", $allocRows) . "\n");
echo 'Allocations written: ' . (count($allocRows) - 1) . PHP_EOL;

// ── Build ENROLLMENTS: batch_year, semester, registration_number, course_code ──
$enrRows = ['batch_year,semester,registration_number,course_code'];
$enrCount = 0; $skipNoCourses = 0;
foreach ($studRows as $r) {
    $reg = trim($r[2] ?? '');
    $progName = trim($r[7] ?? '');
    if (!$reg || !$progName) continue;
    // reg format: BATCH-PROGID-NNNN
    $parts = explode('-', $reg);
    $batch = $parts[0] ?? '';
    if (!isset($batchToSem[$batch])) continue;
    $sem = $batchToSem[$batch]['s'];
    $semLabel = $batchToSem[$batch]['label'];
    $courses = $progSemCourses[$progName][$sem] ?? [];
    if (empty($courses)) { $skipNoCourses++; continue; }
    foreach ($courses as $code) {
        $enrRows[] = "$batch,$semLabel,$reg,$code";
        $enrCount++;
    }
}
file_put_contents($base . 'enrollments (1).csv', implode("\n", $enrRows) . "\n");
echo 'Enrollments written: ' . $enrCount . PHP_EOL;
echo 'Students skipped (no courses for their prog/sem): ' . $skipNoCourses . PHP_EOL;

// ── Self-verify: every code referenced exists in courses ──
$validCodes = [];
foreach ($courseRows as $r) if (!empty($r[0])) $validCodes[strtoupper(trim($r[0]))] = 1;
$badAlloc = 0;
foreach (array_slice($allocRows, 1) as $line) { $c = explode(',', $line); if (!isset($validCodes[strtoupper(trim($c[2]))])) $badAlloc++; }
$badEnr = 0;
foreach (array_slice($enrRows, 1) as $line) { $c = explode(',', $line); if (!isset($validCodes[strtoupper(trim($c[3]))])) $badEnr++; }
echo "VERIFY: bad allocation codes=$badAlloc, bad enrollment codes=$badEnr" . PHP_EOL;
