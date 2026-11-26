<?php
// export_csv.php — timetable CSV download
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403); exit;
}
require 'db.php';

$run_id      = isset($_GET['run_id'])  ? (int)$_GET['run_id']  : 0;
$base_session = trim($_GET['session'] ?? '');

// Resolve which run to export
if (!$run_id && $base_session) {
    $r = $pdo->prepare("SELECT run_id FROM timetable_runs WHERE base_session = ? ORDER BY generated_at DESC LIMIT 1");
    $r->execute([$base_session]);
    $run_id = (int)($r->fetchColumn() ?: 0);
}

if (!$run_id && !$base_session) {
    http_response_code(400);
    echo 'No session or run specified.';
    exit;
}

// Build query
if ($run_id) {
    $sql = "
        SELECT
            tr.base_session AS session_name,
            gt.run_id,
            c.course_code,
            c.course_name,
            c.credit_hours,
            gt.component_type,
            f.faculty_name,
            r.room_number,
            r.room_type,
            r.capacity,
            ts.day,
            ts.start_time,
            ts.end_time,
            (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e WHERE e.course_id = c.course_id) AS enrolled_students,
            d.department_name,
            p.program_name
        FROM generated_timetable gt
        JOIN timetable_runs tr ON gt.run_id = tr.run_id
        JOIN courses    c  ON gt.course_id  = c.course_id
        JOIN faculty    f  ON gt.faculty_id = f.faculty_id
        JOIN rooms      r  ON gt.room_id    = r.room_id
        JOIN time_slots ts ON gt.slot_id    = ts.slot_id
        LEFT JOIN departments d ON c.department_id = d.department_id
        LEFT JOIN programs    p ON c.program_id    = p.program_id
        WHERE gt.run_id = ?
        ORDER BY CASE ts.day WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2
                             WHEN 'Wednesday' THEN 3 WHEN 'Thursday' THEN 4
                             WHEN 'Friday' THEN 5 ELSE 6 END,
                 ts.start_time, c.course_code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$run_id]);
} else {
    // Legacy: no run_id, filter by session_name directly
    $sql = "
        SELECT
            gt.session_name,
            NULL AS run_id,
            c.course_code,
            c.course_name,
            c.credit_hours,
            gt.component_type,
            f.faculty_name,
            r.room_number,
            r.room_type,
            r.capacity,
            ts.day,
            ts.start_time,
            ts.end_time,
            (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e WHERE e.course_id = c.course_id) AS enrolled_students,
            d.department_name,
            p.program_name
        FROM generated_timetable gt
        JOIN courses    c  ON gt.course_id  = c.course_id
        JOIN faculty    f  ON gt.faculty_id = f.faculty_id
        JOIN rooms      r  ON gt.room_id    = r.room_id
        JOIN time_slots ts ON gt.slot_id    = ts.slot_id
        LEFT JOIN departments d ON c.department_id = d.department_id
        LEFT JOIN programs    p ON c.program_id    = p.program_id
        WHERE gt.session_name = ?
        ORDER BY CASE ts.day WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2
                             WHEN 'Wednesday' THEN 3 WHEN 'Thursday' THEN 4
                             WHEN 'Friday' THEN 5 ELSE 6 END,
                 ts.start_time, c.course_code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$base_session]);
}

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'timetable_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $base_session ?: 'export') . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');
// BOM for Excel UTF-8
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Session', 'Day', 'Start Time', 'End Time',
    'Course Code', 'Course Name', 'Credit Hours', 'Type',
    'Faculty', 'Room', 'Room Type', 'Capacity',
    'Enrolled Students', 'Department', 'Program'
]);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['session_name'],
        $r['day'],
        $r['start_time'],
        $r['end_time'],
        $r['course_code'],
        $r['course_name'],
        $r['credit_hours'],
        $r['component_type'],
        $r['faculty_name'],
        $r['room_number'],
        $r['room_type'],
        $r['capacity'],
        $r['enrolled_students'],
        $r['department_name'] ?? '',
        $r['program_name'] ?? '',
    ]);
}

fclose($out);
exit;
