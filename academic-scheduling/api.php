<?php
// ============================================================
// api.php — SlotSmart timetable feed (read-only, for the Remotify dashboard)
//
// Only the PUBLISHED timetable is ever returned. No login / tokens.
//
//   GET api.php?action=sessions
//        -> list of sessions that have a published timetable
//   GET api.php?action=timetable[&session=Fall 2025][&faculty_id=][&student_id=][&room_id=][&course_id=][&day=Monday]
//        -> every published class (optionally filtered), plus the same list grouped by day
//           (no session given = the most recently published one)
//
// Response format: { "success": true/false, "message": "...", "data": ... }
// ============================================================

require 'db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function apiResponse(bool $ok, string $msg = '', $data = null): void {
    echo json_encode(['success' => $ok, 'message' => $msg, 'data' => $data]);
    exit;
}

function getParam(string $key, string $default = ''): string {
    $val = $_GET[$key] ?? $_POST[$key] ?? $default;
    return trim((string) $val);
}

$action = getParam('action');

switch ($action) {

    case 'sessions':
        $rows = $pdo->query("
            SELECT DISTINCT base_session
            FROM timetable_runs
            WHERE published_at IS NOT NULL
            ORDER BY base_session DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        apiResponse(true, '', $rows);
        break;

    case 'timetable':
        $session = getParam('session');
        if (!$session) {
            $session = $pdo->query("
                SELECT base_session FROM timetable_runs
                WHERE published_at IS NOT NULL
                ORDER BY published_at DESC LIMIT 1
            ")->fetchColumn();
            if (!$session) apiResponse(false, 'No timetable has been published yet.');
        }

        $where  = ["tr.base_session = ?", "tr.published_at IS NOT NULL"];
        $params = [$session];
        foreach (['faculty_id' => 'gt.faculty_id', 'room_id' => 'gt.room_id', 'course_id' => 'gt.course_id'] as $k => $col) {
            if (getParam($k) !== '') { $where[] = "$col = ?"; $params[] = (int) getParam($k); }
        }
        if (getParam('day') !== '') { $where[] = "ts.day = ?"; $params[] = getParam('day'); }
        if (getParam('student_id') !== '') {
            $where[] = "EXISTS (SELECT 1 FROM enrollments e WHERE e.course_id = gt.course_id AND e.student_id = ?)";
            $params[] = (int) getParam('student_id');
        }

        $stmt = $pdo->prepare("
            SELECT gt.timetable_id, gt.session_name, gt.component_type,
                   c.course_id, c.course_name, c.course_code, c.credit_hours,
                   f.faculty_id, f.faculty_name,
                   r.room_id, r.room_number, r.room_type, r.capacity,
                   ts.day,
                   TIME_FORMAT(ts.start_time, '%H:%i') AS start_time,
                   TIME_FORMAT(ts.end_time,   '%H:%i') AS end_time
            FROM generated_timetable gt
            JOIN timetable_runs tr ON tr.run_id    = gt.run_id
            JOIN courses        c  ON c.course_id  = gt.course_id
            JOIN faculty        f  ON f.faculty_id = gt.faculty_id
            JOIN rooms          r  ON r.room_id    = gt.room_id
            JOIN time_slots     ts ON ts.slot_id   = gt.slot_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(ts.day,'Monday','Tuesday','Wednesday','Thursday','Friday'), ts.start_time, r.room_number
        ");
        $stmt->execute($params);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byDay = [];
        foreach ($classes as $cls) $byDay[$cls['day']][] = $cls;

        apiResponse(true, '', ['session' => $session, 'count' => count($classes), 'classes' => $classes, 'by_day' => $byDay]);
        break;

    default:
        apiResponse(false, "Unknown action: '$action'. Available: sessions, timetable.");
}
