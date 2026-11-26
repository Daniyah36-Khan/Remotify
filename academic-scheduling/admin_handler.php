<?php
// admin_handler.php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require 'db.php';
require_once __DIR__ . '/equipment.php';
require_once __DIR__ . '/clash_check.php';

// Every action needs a logged-in session (this endpoint previously had no check).
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.', 'data' => []]);
    exit;
}

$action = $_POST['action'] ?? '';

/** Adds the two columns used for "published" and the per-class change history, if missing. */
function ensureEquipColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        foreach ([['timetable_runs', 'published_at', 'DATETIME NULL'], ['timetable_runs', 'unplaced_json', 'TEXT NULL'], ['generated_timetable', 'change_log', 'TEXT NULL'], ['generated_timetable', 'version', 'INT NOT NULL DEFAULT 0']] as [$t, $c, $d]) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $q->execute([$t, $c]);
            if (!$q->fetchColumn()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $d");
        }
    } catch (Exception $e) { /* needs ALTER permission; see README note */ }
}

/** Equipment types owned by at least one room of this room type (cached). */
function typesForRoomType(PDO $pdo, string $roomType): array
{
    static $cache = [];
    if (!isset($cache[$roomType])) {
        $q = $pdo->prepare("SELECT COALESCE(equipment,'None') FROM rooms WHERE room_type = ?");
        $q->execute([$roomType]);
        $cache[$roomType] = equipTypesInRooms($q->fetchAll(PDO::FETCH_COLUMN));
    }
    return $cache[$roomType];
}

/** Does this room fit the course (seats AND working equipment)? Level 0 = not allowed (too few seats / below bare minimum). */
function equipLevelFor(PDO $pdo, int $courseId, int $roomId, ?string $component = null): int
{
    $c = $pdo->prepare("SELECT COALESCE(requires_equipment,'None') FROM courses WHERE course_id=?");
    $c->execute([$courseId]);
    $r = $pdo->prepare("SELECT capacity, COALESCE(equipment,'None'), room_type FROM rooms WHERE room_id=?");
    $r->execute([$roomId]);
    $room = $r->fetch(PDO::FETCH_NUM);
    $en = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM enrollments WHERE course_id=?");
    $en->execute([$courseId]);
    if (!$room) return 0;
    // A lab class never goes into a lecture hall (it needs lab equipment). Theory in a lab room is allowed (fallback).
    if ($component === 'Lab' && $room[2] !== 'Lab') return 0;
    // Theory requirements are judged as for a lecture hall (so a theory class in a lab does not need working PCs).
    $req = equipEffectiveReq(equipCourseReq((string)$c->fetchColumn()), typesForRoomType($pdo, $component === 'Lab' ? 'Lab' : ($component === 'Theory' ? 'Lecture Hall' : $room[2])));
    $enrolled = (int)$en->fetchColumn();
    if ($enrolled > (int)$room[0]) return 0;   // not enough seats
    return equipLevel($req, equipRoomCounts($room[1]), max($enrolled, 1), (int)$room[0]);
}

/** Classes of a run whose current room is below the course's equipment minimum. */
function badEquipClasses(PDO $pdo, int $runId): array
{
    $q = $pdo->prepare("SELECT c.course_code, gt.component_type, r.room_number, r.room_type, r.capacity, COALESCE(r.equipment,'None') eq,
            COALESCE(c.requires_equipment,'None') ce,
            (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e WHERE e.course_id=gt.course_id) enrolled
        FROM generated_timetable gt JOIN courses c ON c.course_id=gt.course_id JOIN rooms r ON r.room_id=gt.room_id
        WHERE gt.run_id=?");
    $q->execute([$runId]);
    $bad = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x)
        if (($x['component_type'] === 'Lab' && $x['room_type'] !== 'Lab') || equipLevel(equipEffectiveReq(equipCourseReq($x['ce']), typesForRoomType($pdo, $x['component_type'] === 'Lab' ? 'Lab' : 'Lecture Hall')), equipRoomCounts($x['eq']), max((int)$x['enrolled'], 1), (int)$x['capacity']) === 0)
            $bad[] = $x['course_code'] . ' in ' . $x['room_number'];
    return array_values(array_unique($bad));
}

function sendResponse($success, $message, $data = [])
{
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

function validateCreditHours(string $ch): bool
{
    return (bool) preg_match('/^\d+-\d+-\d+$/', trim($ch));
}

// Look up department_id from department_name (cached)
function deptIdByName(PDO $pdo, string $name): ?int
{
    static $cache = [];
    $name = trim($name);
    if (!$name)
        return null;
    if (!array_key_exists($name, $cache)) {
        $s = $pdo->prepare("SELECT department_id FROM departments WHERE department_name = ? LIMIT 1");
        $s->execute([$name]);
        $cache[$name] = $s->fetchColumn() ?: null;
    }
    return $cache[$name];
}

// Look up program_id from program_name (cached)
function progIdByName(PDO $pdo, string $name): ?int
{
    static $cache = [];
    $name = trim($name);
    if (!$name)
        return null;
    if (!array_key_exists($name, $cache)) {
        $s = $pdo->prepare("SELECT program_id FROM programs WHERE program_name = ? LIMIT 1");
        $s->execute([$name]);
        $cache[$name] = $s->fetchColumn() ?: null;
    }
    return $cache[$name];
}

// Look up faculty_id from email (cached)
function facIdByEmail(PDO $pdo, string $email): ?int
{
    static $cache = [];
    $email = trim($email);
    if (!$email)
        return null;
    if (!array_key_exists($email, $cache)) {
        $s = $pdo->prepare("SELECT faculty_id FROM faculty WHERE email = ? LIMIT 1");
        $s->execute([$email]);
        $cache[$email] = $s->fetchColumn() ?: null;
    }
    return $cache[$email];
}

// Look up course_id from course_code (cached)
function courseIdByCode(PDO $pdo, string $code): ?int
{
    static $cache = [];
    $code = trim($code);
    if (!$code)
        return null;
    if (!array_key_exists($code, $cache)) {
        $s = $pdo->prepare("SELECT course_id FROM courses WHERE course_code = ? LIMIT 1");
        $s->execute([$code]);
        $cache[$code] = $s->fetchColumn() ?: null;
    }
    return $cache[$code];
}

// Look up student_id from registration_number (cached)
function studentIdByReg(PDO $pdo, string $reg): ?int
{
    static $cache = [];
    $reg = trim($reg);
    if (!$reg)
        return null;
    if (!array_key_exists($reg, $cache)) {
        $s = $pdo->prepare("SELECT student_id FROM students WHERE registration_number = ? LIMIT 1");
        $s->execute([$reg]);
        $cache[$reg] = $s->fetchColumn() ?: null;
    }
    return $cache[$reg];
}

function studentIdByName(PDO $pdo, string $name): ?int
{
    static $cache = [];
    $name = trim($name);
    if (!$name)
        return null;
    if (!array_key_exists($name, $cache)) {
        $s = $pdo->prepare("SELECT student_id FROM students WHERE student_name = ? LIMIT 1");
        $s->execute([$name]);
        $cache[$name] = $s->fetchColumn() ?: null;
    }
    return $cache[$name];
}

function courseIdByName(PDO $pdo, string $name): ?int
{
    static $cache = [];
    $name = trim($name);
    if (!$name)
        return null;
    if (!array_key_exists($name, $cache)) {
        $s = $pdo->prepare("SELECT course_id FROM courses WHERE course_name = ? LIMIT 1");
        $s->execute([$name]);
        $cache[$name] = $s->fetchColumn() ?: null;
    }
    return $cache[$name];
}

try {
    if (empty($action))
        sendResponse(false, "No action specified.");

    switch ($action) {

        // =======================================================
        // CSV IMPORT HANDLER
        // =======================================================
        case 'import_csv':
            $importType = $_POST['import_type'] ?? '';

            if (empty($importType)) {
                sendResponse(false, "Import type not specified.");
            }

            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit (upload_max_filesize). Ask your admin to increase it.',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds the form size limit.',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was selected.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
                    UPLOAD_ERR_CANT_WRITE => 'Server failed to save the file.',
                ];
                $code = $_FILES['csv_file']['error'] ?? -1;
                $msg = $uploadErrors[$code] ?? "Upload error (code $code).";
                sendResponse(false, $msg);
            }

            $file = $_FILES['csv_file'];
            $filename = $file['tmp_name'];

            $handle = fopen($filename, 'r');
            if ($handle === false) {
                sendResponse(false, "Could not read file.");
            }

            $header = fgetcsv($handle);
            if ($header === false) {
                fclose($handle);
                sendResponse(false, "Empty file or invalid format.");
            }

            $count = 0;
            $errors = [];
            $rowNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $rowData = array_map('trim', $row);

                try {
                    switch ($importType) {
                        case 'departments':
                            if (empty($rowData[0]))
                                continue 2;
                            $pdo->prepare("INSERT INTO departments (department_name) VALUES (?)")->execute([$rowData[0]]);
                            $count++;
                            break;

                        case 'programs':
                            // Format: program_name, department_name
                            if (empty($rowData[0]) || empty($rowData[1]))
                                continue 2;
                            $deptId = deptIdByName($pdo, $rowData[1]);
                            if (!$deptId) {
                                $errors[] = "Row $rowNumber: Department '{$rowData[1]}' not found.";
                                continue 2;
                            }
                            $pdo->prepare("INSERT INTO programs (program_name, department_id) VALUES (?, ?)")->execute([$rowData[0], $deptId]);
                            $count++;
                            break;

                        case 'courses':
                            // Format: course_code, course_name, credit_hours, equipment, department_name, program_name
                            if (empty($rowData[0]) || empty($rowData[1]))
                                continue 2;
                            $creditHours = $rowData[2] ?? '3-0-3';
                            if (!validateCreditHours($creditHours)) {
                                $errors[] = "Row $rowNumber: Invalid credit_hours '$creditHours'.";
                                continue 2;
                            }
                            $equipment = equipFromCsvCourse($rowData[3] ?? 'None');
                            $deptId = deptIdByName($pdo, $rowData[4] ?? '');
                            $progId = !empty($rowData[5]) ? progIdByName($pdo, $rowData[5]) : null;
                            $pdo->prepare("INSERT INTO courses (course_code, course_name, credit_hours, requires_equipment, department_id, program_id) VALUES (?, ?, ?, ?, ?, ?)")
                                ->execute([$rowData[0], $rowData[1], $creditHours, $equipment, $deptId, $progId]);
                            $count++;
                            break;

                        case 'faculty':
                            // Format: faculty_name, email, phone, department_name
                            if (empty($rowData[0]) || empty($rowData[3]))
                                continue 2;
                            $deptId = deptIdByName($pdo, $rowData[3]);
                            if (!$deptId) {
                                $errors[] = "Row $rowNumber: Department '{$rowData[3]}' not found.";
                                continue 2;
                            }
                            $pdo->prepare("INSERT INTO faculty (faculty_name, email, phone_number, department_id) VALUES (?, ?, ?, ?)")
                                ->execute([$rowData[0], $rowData[1] ?? null, $rowData[2] ?? null, $deptId]);
                            $count++;
                            break;

                        case 'students':
                            // Format: student_name, email, reg_no, phone, dob, gender, department_name, program_name, student_type
                            if (empty($rowData[0]) || empty($rowData[6]))
                                continue 2;
                            $deptId = deptIdByName($pdo, $rowData[6]);
                            $progId = !empty($rowData[7]) ? progIdByName($pdo, $rowData[7]) : null;
                            if (!$deptId) {
                                $errors[] = "Row $rowNumber: Department '{$rowData[6]}' not found.";
                                continue 2;
                            }
                            $stuType = (isset($rowData[8]) && $rowData[8] === 'transfer') ? 'transfer' : 'regular';
                            $pdo->prepare("INSERT INTO students (student_name, email, registration_number, phone_number, dob, gender, student_type, department_id, program_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([$rowData[0], $rowData[1] ?? null, $rowData[2] ?? null, $rowData[3] ?? null, $rowData[4] ?? null, $rowData[5] ?? 'Male', $stuType, $deptId, $progId]);
                            $count++;
                            break;

                        case 'rooms':
                            if (empty($rowData[0]) || empty($rowData[1]))
                                continue 2;
                            $pdo->prepare("INSERT INTO rooms (room_number, floor_number, capacity, room_type, equipment) VALUES (?, ?, ?, ?, ?)")
                                ->execute([$rowData[0], 1, $rowData[1], $rowData[2] ?? 'Lecture Hall', equipFromCsvRoom($rowData[3] ?? 'None')]);
                            $count++;
                            break;

                        case 'time_slots':
                            if (empty($rowData[0]) || empty($rowData[1]))
                                continue 2;
                            $pdo->prepare("INSERT INTO time_slots (day, start_time, end_time) VALUES (?, ?, ?)")->execute([$rowData[0], $rowData[1], $rowData[2] ?? '']);
                            $count++;
                            break;

                        case 'allocations':
                            // Format: session_name, faculty_email, course_code
                            if (empty($rowData[1]) || empty($rowData[2]))
                                continue 2;
                            $facId = facIdByEmail($pdo, $rowData[1]);
                            $courseId = courseIdByCode($pdo, $rowData[2]);
                            if (!$facId) {
                                $errors[] = "Row $rowNumber: Faculty email '{$rowData[1]}' not found.";
                                continue 2;
                            }
                            if (!$courseId) {
                                $errors[] = "Row $rowNumber: Course code '{$rowData[2]}' not found.";
                                continue 2;
                            }
                            $pdo->prepare("INSERT INTO allocations (session_name, faculty_id, course_id) VALUES (?, ?, ?)")->execute([$rowData[0] ?? 'Fall 2025', $facId, $courseId]);
                            $count++;
                            break;

                        case 'enrollments':
                            // Format: batch_year, semester, student_name, course
                            // Accepts: "CS101" OR "CS101 – Programming Fundamentals"
                            if (empty($rowData[2]) || empty($rowData[3]))
                                continue 2;
                            $stdId = studentIdByName($pdo, $rowData[2]);
                            if (!$stdId) {
                                $errors[] = "Row $rowNumber: Student '{$rowData[2]}' not found.";
                                continue 2;
                            }

                            // Smart parser: extract code from "CODE – Name" or use as-is
                            $courseInput = trim($rowData[3]);
                            $courseCode = $courseInput;
                            $providedName = null;

                            if (strpos($courseInput, ' – ') !== false) {
                                $parts = explode(' – ', $courseInput, 2);
                                $courseCode = trim($parts[0]);
                                $providedName = trim($parts[1]);
                            } elseif (strpos($courseInput, ' - ') !== false) {
                                $parts = explode(' - ', $courseInput, 2);
                                $courseCode = trim($parts[0]);
                                $providedName = trim($parts[1]);
                            }

                            // Lookup course
                            $courseId = courseIdByCode($pdo, $courseCode);
                            if (!$courseId) {
                                $errors[] = "Row $rowNumber: Course code '$courseCode' not found.";
                                continue 2;
                            }

                            // If name was provided, validate it matches
                            if ($providedName) {
                                $actual = $pdo->prepare("SELECT course_name FROM courses WHERE course_id = ?");
                                $actual->execute([$courseId]);
                                $actualName = $actual->fetchColumn();
                                if ($actualName && strcasecmp($actualName, $providedName) !== 0) {
                                    $errors[] = "Row $rowNumber: Course '$courseCode' name mismatch (expected: $actualName, got: $providedName)";
                                    continue 2;
                                }
                            }

                            $stmt = $pdo->prepare("INSERT IGNORE INTO enrollments (batch_year, semester, student_id, course_id) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$rowData[0] ?? '', $rowData[1] ?? '', $stdId, $courseId]);
                            if ($stmt->rowCount() > 0)
                                $count++;
                            break;

                        default:
                            $errors[] = "Unknown import type: $importType";
                    }
                } catch (PDOException $e) {
                    $errors[] = "Row $rowNumber: " . $e->getMessage();
                }
            }

            fclose($handle);

            $message = "Imported $count records successfully.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode("; ", array_slice($errors, 0, 5));
                if (count($errors) > 5) {
                    $message .= " (+" . (count($errors) - 5) . " more)";
                }
            }

            sendResponse($count > 0 || empty($errors), $message);
            break;

        // ==========================
        //   GENERATED TIMETABLE
        // ==========================
        case 'get_generated_timetable':
            $run_id = isset($_POST['run_id']) && $_POST['run_id'] !== '' ? (int) $_POST['run_id'] : null;
            $session = $_POST['session'] ?? '';
            $dept_id = $_POST['department_id'] ?? 'all';
            $faculty_id = $_POST['faculty_id'] ?? 'all';
            $batch = $_POST['batch_year'] ?? '';
            $room_id = $_POST['room_id'] ?? 'all';
            $program_id = $_POST['program_id'] ?? 'all';
            $course_id = $_POST['course_id'] ?? 'all';
            $semester = $_POST['semester'] ?? '';

            // Resolve base_session for unscheduled lookup
            $base_session = $session;
            if ($run_id) {
                $rsRow = $pdo->prepare("SELECT base_session FROM timetable_runs WHERE run_id = ?");
                $rsRow->execute([$run_id]);
                $base_session = $rsRow->fetchColumn() ?: $session;
            } elseif ($session) {
                // Auto-pick latest run for this base session
                try {
                    $latestRun = $pdo->prepare("SELECT run_id FROM timetable_runs WHERE base_session = ? ORDER BY generated_at DESC LIMIT 1");
                    $latestRun->execute([$session]);
                    $foundRun = $latestRun->fetchColumn();
                    if ($foundRun)
                        $run_id = (int) $foundRun;
                } catch (Exception $e) { /* timetable_runs may not exist yet */
                }
            }

            $sql = "SELECT
                        gt.timetable_id,
                        gt.run_id,
                        gt.session_name,
                        gt.component_type,
                        gt.version,
                        c.course_id,
                        c.course_name,
                        c.course_code,
                        c.credit_hours,
                        c.department_id,
                        c.program_id,
                        f.faculty_id,
                        f.faculty_name,
                        r.room_id,
                        r.room_number,
                        r.room_type,
                        r.capacity,
                        ts.slot_id,
                        ts.day,
                        ts.start_time,
                        ts.end_time,
                        (SELECT COUNT(DISTINCT e2.student_id)
                         FROM enrollments e2
                         WHERE e2.course_id = c.course_id) AS enrolled_students,
                        (SELECT COUNT(DISTINCT s2.student_id)
                         FROM enrollments e3
                         JOIN students s2 ON e3.student_id = s2.student_id
                         WHERE e3.course_id = c.course_id AND s2.student_type = 'transfer') AS transfer_students
                    FROM generated_timetable gt
                    JOIN courses      c  ON gt.course_id  = c.course_id
                    JOIN faculty      f  ON gt.faculty_id = f.faculty_id
                    JOIN rooms        r  ON gt.room_id    = r.room_id
                    JOIN time_slots   ts ON gt.slot_id    = ts.slot_id
                    WHERE EXISTS (
                        SELECT 1 FROM enrollments e
                        WHERE e.course_id = c.course_id
                    )";

            $params = [];
            if ($run_id) {
                $sql .= " AND gt.run_id = ?";
                $params[] = $run_id;
            } else {
                $sql .= " AND gt.session_name = ? AND gt.run_id IS NULL";
                $params[] = $session;
            }

            if ($dept_id !== 'all' && $dept_id !== '') {
                $sql .= " AND c.department_id = ?";
                $params[] = $dept_id;
            }
            if ($faculty_id !== 'all' && $faculty_id !== '') {
                $sql .= " AND f.faculty_id = ?";
                $params[] = $faculty_id;
            }
            if ($room_id !== 'all' && $room_id !== '') {
                $sql .= " AND r.room_id = ?";
                $params[] = $room_id;
            }
            if ($batch !== '' && $batch !== 'all') {
                $sql .= " AND EXISTS (
                    SELECT 1 FROM enrollments e3
                    WHERE e3.course_id = c.course_id AND e3.batch_year = ?
                )";
                $params[] = $batch;
            }
            if ($program_id !== 'all' && $program_id !== '') {
                $sql .= " AND c.program_id = ?";
                $params[] = $program_id;
            }
            if ($course_id !== 'all' && $course_id !== '') {
                $sql .= " AND gt.course_id = ?";
                $params[] = $course_id;
            }
            if ($semester !== '' && $semester !== 'all') {
                $sql .= " AND EXISTS (
                    SELECT 1 FROM enrollments e4
                    WHERE e4.course_id = c.course_id AND e4.semester = ?
                )";
                $params[] = $semester;
            }

            $sql .= " ORDER BY CASE ts.day
                               WHEN 'Monday'    THEN 1
                               WHEN 'Tuesday'   THEN 2
                               WHEN 'Wednesday' THEN 3
                               WHEN 'Thursday'  THEN 4
                               WHEN 'Friday'    THEN 5
                               ELSE 6 END,
                               ts.start_time, c.course_name";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Find unscheduled courses for this base session + run
            $unscheduledSql = "
                SELECT DISTINCT c.course_id, c.course_name, c.course_code,
                       c.credit_hours, c.department_id, c.program_id,
                       f.faculty_id, f.faculty_name,
                       (SELECT COUNT(DISTINCT e2.student_id) FROM enrollments e2
                        WHERE e2.course_id = c.course_id) AS enrolled_students
                FROM allocations a
                JOIN courses c ON a.course_id = c.course_id
                JOIN faculty f ON a.faculty_id = f.faculty_id
                WHERE a.session_name = ?
                  AND EXISTS (SELECT 1 FROM enrollments e WHERE e.course_id = c.course_id)
                  AND NOT EXISTS (
                      SELECT 1 FROM generated_timetable gt2
                      WHERE gt2.course_id = c.course_id
                      AND (gt2.run_id = ? OR (? IS NULL AND gt2.session_name = ? AND gt2.run_id IS NULL))
                  )";
            $unschParams = [$base_session, $run_id, $run_id, $session];
            if ($dept_id !== 'all' && $dept_id !== '') {
                $unscheduledSql .= " AND c.department_id = ?";
                $unschParams[] = $dept_id;
            }
            $unschStmt = $pdo->prepare($unscheduledSql);
            $unschStmt->execute($unschParams);
            $unscheduled = $unschStmt->fetchAll(PDO::FETCH_ASSOC);
            // Sessions the generator could not give a valid room/time (stored with the run) also count as unscheduled.
            try {
                if ($run_id) {
                    $uj = $pdo->prepare("SELECT unplaced_json FROM timetable_runs WHERE run_id = ?");
                    $uj->execute([$run_id]);
                    $ul = json_decode((string) $uj->fetchColumn(), true);
                    if (is_array($ul) && $ul) {
                        $have = array_flip(array_column($unscheduled, 'course_id'));
                        foreach (array_unique(array_column($ul, 'course_id')) as $ucid) {
                            if (isset($have[$ucid])) continue;
                            $q = $pdo->prepare("SELECT c.course_id, c.course_name, c.course_code, c.credit_hours, c.department_id, c.program_id, f.faculty_id, f.faculty_name,
                                    (SELECT COUNT(DISTINCT e2.student_id) FROM enrollments e2 WHERE e2.course_id = c.course_id) AS enrolled_students
                                FROM allocations a JOIN courses c ON a.course_id = c.course_id JOIN faculty f ON a.faculty_id = f.faculty_id
                                WHERE a.session_name = ? AND c.course_id = ? LIMIT 1");
                            $q->execute([$base_session, $ucid]);
                            if ($row = $q->fetch(PDO::FETCH_ASSOC)) { $row['partly_unplaced'] = 1; $unscheduled[] = $row; }
                        }
                    }
                }
            } catch (Exception $e) { /* column may not exist on old databases */ }

            sendResponse(true, "", [
                'scheduled' => $rows,
                'unscheduled' => $unscheduled,
                'run_id' => $run_id,
                'base_session' => $base_session,
            ]);
            break;

        case 'get_dropdowns':
            $depts = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
            $faculty = $pdo->query("SELECT faculty_id, faculty_name FROM faculty ORDER BY faculty_name")->fetchAll(PDO::FETCH_ASSOC);
            $rooms = $pdo->query("SELECT room_id, room_number FROM rooms ORDER BY room_number")->fetchAll(PDO::FETCH_ASSOC);
            $programs = $pdo->query("SELECT program_id, program_name FROM programs ORDER BY program_name")->fetchAll(PDO::FETCH_ASSOC);
            $courses = $pdo->query("SELECT course_id, course_name, course_code FROM courses ORDER BY course_name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $batches = $pdo->query("SELECT DISTINCT batch_year AS batch_year FROM enrollments WHERE batch_year IS NOT NULL AND batch_year != '' ORDER BY batch_year DESC")->fetchAll(PDO::FETCH_ASSOC);
            $semesters = $pdo->query("SELECT DISTINCT semester FROM enrollments WHERE semester IS NOT NULL AND semester != '' ORDER BY semester")->fetchAll(PDO::FETCH_ASSOC);
            sendResponse(true, "", ['departments' => $depts, 'faculty' => $faculty, 'batches' => $batches, 'rooms' => $rooms, 'programs' => $programs, 'courses' => $courses, 'semesters' => $semesters]);
            break;

        // ==========================
        //      DEPARTMENTS
        // ==========================
        case 'add_department':
            if (empty($_POST['department_name']))
                sendResponse(false, "Name empty");
            $stmt = $pdo->prepare("INSERT INTO departments (department_name) VALUES (?)");
            $stmt->execute([$_POST['department_name']]);
            sendResponse(true, "Department added!");
            break;

        case 'update_department':
            if (empty($_POST['id']) || empty($_POST['department_name']))
                sendResponse(false, "Data missing");
            $stmt = $pdo->prepare("UPDATE departments SET department_name = ? WHERE department_id = ?");
            $stmt->execute([$_POST['department_name'], $_POST['id']]);
            sendResponse(true, "Department updated!");
            break;

        case 'delete_department':
            $stmt = $pdo->prepare("DELETE FROM departments WHERE department_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "Department deleted!");
            break;

        case 'get_departments':
            $stmt = $pdo->query("SELECT * FROM departments ORDER BY department_id ASC");
            sendResponse(true, "", $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ==========================
        //      PROGRAMS
        // ==========================
        case 'add_program':
            $stmt = $pdo->prepare("INSERT INTO programs (program_name, department_id) VALUES (?, ?)");
            $stmt->execute([$_POST['program_name'], $_POST['department_id']]);
            sendResponse(true, "Program added!");
            break;

        case 'update_program':
            $stmt = $pdo->prepare("UPDATE programs SET program_name=?, department_id=? WHERE program_id=?");
            $stmt->execute([$_POST['program_name'], $_POST['department_id'], $_POST['id']]);
            sendResponse(true, "Program updated!");
            break;

        case 'delete_program':
            $stmt = $pdo->prepare("DELETE FROM programs WHERE program_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "Program deleted!");
            break;

        case 'get_programs':
            $sql = "SELECT p.*, d.department_name FROM programs p
                    JOIN departments d ON p.department_id = d.department_id
                    ORDER BY p.program_id ASC";
            sendResponse(true, "", $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ==========================
        //      COURSES
        // ==========================
        case 'add_course':
            // FIX: Validate credit_hours format before saving
            $creditHours = $_POST['credit_hours'] ?? '3-0-3';
            if (!validateCreditHours($creditHours)) {
                sendResponse(false, "Invalid credit hours format. Please use T-P-C format (e.g. 3-1-3 or 2-0-2).");
            }
            $equipment = equipCourseFromPost($_POST);
            $progId = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
            $stmt = $pdo->prepare("INSERT INTO courses (course_name, course_code, credit_hours, requires_equipment, department_id, program_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['course_name'], $_POST['course_code'], $creditHours, $equipment, $_POST['department_id'], $progId]);
            sendResponse(true, "Course added successfully!");
            break;

        case 'update_course':
            // FIX: Validate credit_hours format on update too
            $creditHours = $_POST['credit_hours'] ?? '3-0-3';
            if (!validateCreditHours($creditHours)) {
                sendResponse(false, "Invalid credit hours format. Please use T-P-C format (e.g. 3-1-3 or 2-0-2).");
            }
            $equipment = equipCourseFromPost($_POST);
            $progId = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
            $stmt = $pdo->prepare("UPDATE courses SET course_name=?, course_code=?, credit_hours=?, requires_equipment=?, department_id=?, program_id=? WHERE course_id=?");
            $stmt->execute([
                $_POST['course_name'],
                $_POST['course_code'],
                $creditHours,
                $equipment,
                $_POST['department_id'],
                $progId,
                $_POST['id']
            ]);
            sendResponse(true, "Course updated successfully!");
            break;

        case 'delete_course':
            $stmt = $pdo->prepare("DELETE FROM courses WHERE course_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "Course deleted!");
            break;

        case 'get_courses':
            $sql = "SELECT c.*, d.department_name, p.program_name
                    FROM courses c
                    JOIN departments d ON c.department_id = d.department_id
                    LEFT JOIN programs p ON c.program_id = p.program_id
                    ORDER BY c.course_id ASC";
            sendResponse(true, "", $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
            break;

        // Server-side paginated version for DataTables
        case 'get_courses_paged':
            $draw = (int) ($_POST['draw'] ?? 1);
            $start = (int) ($_POST['start'] ?? 0);
            $length = min((int) ($_POST['length'] ?? 25), 100);
            $search = trim($_POST['search']['value'] ?? '');
            $join = "FROM courses c JOIN departments d ON c.department_id=d.department_id LEFT JOIN programs p ON c.program_id=p.program_id";
            if ($search) {
                $like = "%$search%";
                $where = "WHERE (c.course_code LIKE ? OR c.course_name LIKE ? OR d.department_name LIKE ? OR p.program_name LIKE ?)";
                $params = [$like, $like, $like, $like];
            } else {
                $where = '';
                $params = [];
            }
            $total = (int) $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
            $fs = $pdo->prepare("SELECT COUNT(*) $join $where");
            $fs->execute($params);
            $filtered = (int) $fs->fetchColumn();
            $ds = $pdo->prepare("SELECT c.course_id,c.course_code,c.course_name,c.credit_hours,c.requires_equipment,d.department_name,p.program_name $join $where ORDER BY c.course_name ASC LIMIT $length OFFSET $start");
            $ds->execute($params);
            ob_clean();
            echo json_encode(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $ds->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        // ==========================
        //      FACULTY
        // ==========================
        case 'add_faculty':
            $stmt = $pdo->prepare("INSERT INTO faculty (faculty_name, email, phone_number, department_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['faculty_name'], $_POST['email'], $_POST['phone_number'], $_POST['department_id']]);
            sendResponse(true, "Faculty added!");
            break;

        case 'update_faculty':
            $stmt = $pdo->prepare("UPDATE faculty SET faculty_name=?, email=?, phone_number=?, department_id=? WHERE faculty_id=?");
            $stmt->execute([$_POST['faculty_name'], $_POST['email'], $_POST['phone_number'], $_POST['department_id'], $_POST['id']]);
            sendResponse(true, "Faculty updated!");
            break;

        case 'delete_faculty':
            $stmt = $pdo->prepare("DELETE FROM faculty WHERE faculty_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "Faculty deleted!");
            break;

        case 'get_faculty':
            $stmt = $pdo->query("SELECT f.faculty_id, f.faculty_name, f.email, f.phone_number, f.department_id, f.max_hours_per_week, d.department_name FROM faculty f LEFT JOIN departments d ON f.department_id = d.department_id ORDER BY f.faculty_id ASC");
            sendResponse(true, "", $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // Server-side paginated version for DataTables
        case 'get_faculty_paged':
            $draw = (int) ($_POST['draw'] ?? 1);
            $start = (int) ($_POST['start'] ?? 0);
            $length = min((int) ($_POST['length'] ?? 25), 100);
            $search = trim($_POST['search']['value'] ?? '');
            $join = "FROM faculty f LEFT JOIN departments d ON f.department_id=d.department_id";
            if ($search) {
                $like = "%$search%";
                $where = "WHERE (f.faculty_name LIKE ? OR f.email LIKE ? OR d.department_name LIKE ?)";
                $params = [$like, $like, $like];
            } else {
                $where = '';
                $params = [];
            }
            $total = (int) $pdo->query("SELECT COUNT(*) FROM faculty")->fetchColumn();
            $fs = $pdo->prepare("SELECT COUNT(*) $join $where");
            $fs->execute($params);
            $filtered = (int) $fs->fetchColumn();
            $ds = $pdo->prepare("SELECT f.faculty_id,f.faculty_name,f.email,f.phone_number,f.department_id,f.max_hours_per_week,d.department_name $join $where ORDER BY f.faculty_name ASC LIMIT $length OFFSET $start");
            $ds->execute($params);
            ob_clean();
            echo json_encode(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $ds->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        // Search faculty by name — returns max 20 matches
        case 'search_faculty':
            $q = trim($_POST['q'] ?? '');
            if (strlen($q) < 2) {
                sendResponse(true, '', []);
                break;
            }
            $like = "%$q%";
            $stmt = $pdo->prepare("SELECT f.faculty_id, f.faculty_name, f.email, f.department_id, d.department_name FROM faculty f LEFT JOIN departments d ON f.department_id = d.department_id WHERE f.faculty_name LIKE ? OR f.email LIKE ? ORDER BY f.faculty_name ASC LIMIT 20");
            $stmt->execute([$like, $like]);
            sendResponse(true, '', $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ==========================
        //      STUDENTS
        // ==========================
        case 'add_student':
            $stmt = $pdo->prepare("INSERT INTO students (student_name, email, registration_number, phone_number, dob, gender, student_type, department_id, program_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['student_name'], $_POST['email'], $_POST['registration_number'], $_POST['phone_number'], $_POST['dob'], $_POST['gender'], $_POST['student_type'] ?? 'regular', $_POST['department_id'], $_POST['program_id']]);
            sendResponse(true, "Student added!");
            break;

        case 'update_student':
            $stmt = $pdo->prepare("UPDATE students SET student_name=?, email=?, registration_number=?, phone_number=?, dob=?, gender=?, student_type=?, department_id=?, program_id=? WHERE student_id=?");
            $stmt->execute([$_POST['student_name'], $_POST['email'], $_POST['registration_number'], $_POST['phone_number'], $_POST['dob'], $_POST['gender'], $_POST['student_type'] ?? 'regular', $_POST['department_id'], $_POST['program_id'], $_POST['id']]);
            sendResponse(true, "Student updated!");
            break;

        case 'delete_student':
            $stmt = $pdo->prepare("DELETE FROM students WHERE student_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "Student deleted!");
            break;

        case 'get_students':
            $sql = "SELECT s.student_id, s.student_name, s.email, s.registration_number, s.phone_number, s.dob, s.gender, s.student_type, s.department_id, s.program_id, d.department_name, p.program_name FROM students s
                    LEFT JOIN departments d ON s.department_id = d.department_id
                    LEFT JOIN programs p ON s.program_id = p.program_id
                    ORDER BY s.student_name ASC";
            sendResponse(true, "", $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'get_students_paged':
            $draw = (int) ($_POST['draw'] ?? 1);
            $start = (int) ($_POST['start'] ?? 0);
            $length = min((int) ($_POST['length'] ?? 25), 100);
            $search = trim($_POST['search']['value'] ?? '');
            $join = "FROM students s LEFT JOIN departments d ON s.department_id=d.department_id LEFT JOIN programs p ON s.program_id=p.program_id";
            if ($search) {
                $like = "%$search%";
                $where = "WHERE (s.student_name LIKE ? OR s.email LIKE ? OR s.registration_number LIKE ? OR p.program_name LIKE ?)";
                $params = [$like, $like, $like, $like];
            } else {
                $where = '';
                $params = [];
            }
            $total = (int) $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
            $fs = $pdo->prepare("SELECT COUNT(*) $join $where");
            $fs->execute($params);
            $filtered = (int) $fs->fetchColumn();
            $ds = $pdo->prepare("SELECT s.student_id,s.student_name,s.email,s.registration_number,s.student_type,s.phone_number,s.dob,s.gender,s.department_id,s.program_id,d.department_name,p.program_name $join $where ORDER BY s.student_name ASC LIMIT $length OFFSET $start");
            $ds->execute($params);
            ob_clean();
            echo json_encode(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $ds->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        // Search students by name or reg number — returns max 20 matches
        case 'search_students':
            $q = trim($_POST['q'] ?? '');
            if (strlen($q) < 2) {
                sendResponse(true, '', []);
                break;
            }
            $like = "%$q%";
            $stmt = $pdo->prepare("SELECT student_id, student_name, registration_number FROM students WHERE student_name LIKE ? OR registration_number LIKE ? ORDER BY student_name ASC LIMIT 20");
            $stmt->execute([$like, $like]);
            sendResponse(true, '', $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ==========================
        //      SESSIONS
        // ==========================
        case 'get_sessions':
            $stmt = $pdo->query("SELECT DISTINCT session_name FROM allocations ORDER BY session_name DESC");
            $sessions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            sendResponse(true, "", $sessions);
            break;

        // ==========================
        //      ROOMS
        // ==========================
        case 'add_room':
            $room_equipment = equipRoomFromPost($_POST);
            $stmt = $pdo->prepare("INSERT INTO rooms (room_number, floor_number, capacity, room_type, equipment) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['room_number'], $_POST['floor_number'], $_POST['capacity'], $_POST['type'], $room_equipment]);
            sendResponse(true, "Room added!");
            break;

        case 'update_room':
            $room_equipment = equipRoomFromPost($_POST);
            $stmt = $pdo->prepare("UPDATE rooms SET room_number=?, floor_number=?, capacity=?, room_type=?, equipment=? WHERE room_id=?");
            $stmt->execute([$_POST['room_number'], $_POST['floor_number'], $_POST['capacity'], $_POST['type'], $room_equipment, $_POST['id']]);
            sendResponse(true, "Room updated!");
            break;

        case 'delete_room':
            $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "Room deleted!");
            break;

        case 'get_rooms':
            $stmt = $pdo->query("SELECT * FROM rooms ORDER BY room_id ASC");
            sendResponse(true, "", $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ==========================
        //      SLOTS
        // ==========================
        case 'add_slot':
            $stmt = $pdo->prepare("INSERT INTO time_slots (day, start_time, end_time) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['day'], $_POST['start_time'], $_POST['end_time']]);
            sendResponse(true, "Slot added!");
            break;

        case 'update_slot':
            $stmt = $pdo->prepare("UPDATE time_slots SET day=?, start_time=?, end_time=? WHERE slot_id=?");
            $stmt->execute([$_POST['day'], $_POST['start_time'], $_POST['end_time'], $_POST['id']]);
            sendResponse(true, "Slot updated!");
            break;

        case 'delete_slot':
            $stmt = $pdo->prepare("DELETE FROM time_slots WHERE slot_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "Slot deleted!");
            break;

        case 'get_slots':
            $stmt = $pdo->query("SELECT * FROM time_slots ORDER BY slot_id ASC");
            sendResponse(true, "", $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ==========================
        //      ALLOCATIONS
        // ==========================
        case 'add_allocation':
            // Duplicate check: same course already allocated this session?
            $dupCheck = $pdo->prepare("SELECT allocation_id FROM allocations WHERE course_id = ? AND session_name = ?");
            $dupCheck->execute([$_POST['course_id'], $_POST['session_name']]);
            if ($dupCheck->fetch()) {
                sendResponse(false, "This course is already allocated to a faculty for session '{$_POST['session_name']}'. Each course can only be allocated once per session.");
            }
            // Faculty double-booking warning (same faculty, different course, same session)
            $facCheck = $pdo->prepare("SELECT COUNT(*) FROM allocations WHERE faculty_id = ? AND session_name = ?");
            $facCheck->execute([$_POST['faculty_id'], $_POST['session_name']]);
            $facCount = (int) $facCheck->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO allocations (faculty_id, course_id, session_name) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['faculty_id'], $_POST['course_id'], $_POST['session_name']]);
            $msg = "Allocation added!";
            if ($facCount >= 3)
                $msg .= " Note: This faculty now has " . ($facCount + 1) . " courses this session — check workload.";
            sendResponse(true, $msg);
            break;

        case 'update_allocation':
            $stmt = $pdo->prepare("UPDATE allocations SET faculty_id=?, course_id=?, session_name=? WHERE allocation_id=?");
            $stmt->execute([$_POST['faculty_id'], $_POST['course_id'], $_POST['session_name'], $_POST['id']]);
            sendResponse(true, "Allocation updated!");
            break;

        case 'delete_allocation':
            $stmt = $pdo->prepare("DELETE FROM allocations WHERE allocation_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "Allocation deleted!");
            break;

        case 'get_allocations':
            $sql = "SELECT a.*, f.faculty_name, c.course_name FROM allocations a
                    JOIN faculty f ON a.faculty_id = f.faculty_id
                    JOIN courses c ON a.course_id = c.course_id
                    ORDER BY a.allocation_id ASC";
            sendResponse(true, "", $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ==========================
        //      ENROLLMENTS
        // ==========================
        case 'add_enrollment':
            $stmt = $pdo->prepare("INSERT INTO enrollments (batch_year, semester, student_id, course_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['batch_year'] ?? '', $_POST['semester'], $_POST['student_id'], $_POST['course_id']]);
            sendResponse(true, "Student Enrolled!");
            break;

        case 'update_enrollment':
            $stmt = $pdo->prepare("UPDATE enrollments SET batch_year=?, semester=?, student_id=?, course_id=? WHERE enrollment_id=?");
            $stmt->execute([$_POST['batch_year'] ?? '', $_POST['semester'], $_POST['student_id'], $_POST['course_id'], $_POST['id']]);
            sendResponse(true, "Enrollment updated!");
            break;

        case 'delete_enrollment':
            $stmt = $pdo->prepare("DELETE FROM enrollments WHERE enrollment_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "Enrollment deleted!");
            break;

        case 'get_enrollments':
            $sql = "SELECT e.*, s.student_name, c.course_name FROM enrollments e
                    JOIN students s ON e.student_id = s.student_id
                    JOIN courses c ON e.course_id = c.course_id
                    ORDER BY e.enrollment_id ASC";
            sendResponse(true, "", $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'get_enrollments_paged':
            $draw = (int) ($_POST['draw'] ?? 1);
            $start = (int) ($_POST['start'] ?? 0);
            $length = min((int) ($_POST['length'] ?? 25), 100);
            $search = trim($_POST['search']['value'] ?? '');
            $join = "FROM enrollments e JOIN students s ON e.student_id=s.student_id JOIN courses c ON e.course_id=c.course_id";
            if ($search) {
                $like = "%$search%";
                $where = "WHERE (s.student_name LIKE ? OR c.course_name LIKE ? OR e.batch_year LIKE ? OR e.semester LIKE ? OR s.registration_number LIKE ?)";
                $params = [$like, $like, $like, $like, $like];
            } else {
                $where = '';
                $params = [];
            }
            $total = (int) $pdo->query("SELECT COUNT(*) FROM enrollments")->fetchColumn();
            $fs = $pdo->prepare("SELECT COUNT(*) $join $where");
            $fs->execute($params);
            $filtered = (int) $fs->fetchColumn();
            $ds = $pdo->prepare("SELECT e.enrollment_id,e.batch_year,e.semester,e.student_id,e.course_id,s.student_name,c.course_name $join $where ORDER BY e.enrollment_id DESC LIMIT $length OFFSET $start");
            $ds->execute($params);
            ob_clean();
            echo json_encode(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $ds->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        // ==========================
        //  FIX: USER MANAGEMENT
        // ==========================
        case 'get_users':
            $stmt = $pdo->query("SELECT user_id, name, email, role, created_at FROM users ORDER BY user_id ASC");
            sendResponse(true, "", $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'add_user':
            if (empty($_POST['name']) || empty($_POST['email']) || empty($_POST['password'])) {
                sendResponse(false, "Name, email, and password are required.");
            }
            if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                sendResponse(false, "Invalid email address.");
            }
            if (strlen($_POST['password']) < 6) {
                sendResponse(false, "Password must be at least 6 characters.");
            }
            // Check for duplicate email
            $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $check->execute([$_POST['email']]);
            if ($check->fetch()) {
                sendResponse(false, "A user with this email already exists.");
            }
            $hashed = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $role = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['name'], $_POST['email'], $hashed, $role]);
            sendResponse(true, "User created successfully!");
            break;

        case 'update_user':
            if (empty($_POST['id']) || empty($_POST['name']) || empty($_POST['email'])) {
                sendResponse(false, "Missing required fields.");
            }
            $role = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
            // Only update password if a new one is provided
            if (!empty($_POST['password'])) {
                if (strlen($_POST['password']) < 6) {
                    sendResponse(false, "Password must be at least 6 characters.");
                }
                $hashed = password_hash($_POST['password'], PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET name=?, email=?, role=?, password=? WHERE user_id=?");
                $stmt->execute([$_POST['name'], $_POST['email'], $role, $hashed, $_POST['id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name=?, email=?, role=? WHERE user_id=?");
                $stmt->execute([$_POST['name'], $_POST['email'], $role, $_POST['id']]);
            }
            sendResponse(true, "User updated successfully!");
            break;

        case 'delete_user':
            // Prevent deleting yourself (optional safety check using session)
            session_start();
            if (isset($_SESSION['user_id']) && (int) $_POST['id'] === (int) $_SESSION['user_id']) {
                sendResponse(false, "You cannot delete your own account.");
            }
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id=?");
            $stmt->execute([$_POST['id']]);
            sendResponse(true, "User deleted!");
            break;

        // ── Timetable Runs ────────────────────────────────────────
        case 'get_timetable_sessions':
            // Try new timetable_runs table first
            try {
                $stmt = $pdo->query("
                    SELECT run_id, base_session AS session_name, run_label,
                           fitness_score, classes_count AS total_classes, generated_at
                    FROM timetable_runs
                    ORDER BY generated_at DESC
                ");
                $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                // Also check for legacy data (run_id IS NULL)
                $legacy = $pdo->query("
                    SELECT NULL AS run_id, session_name, session_name AS run_label,
                           0 AS fitness_score, COUNT(*) AS total_classes, MAX(created_at) AS generated_at
                    FROM generated_timetable
                    WHERE run_id IS NULL
                    GROUP BY session_name
                    ORDER BY generated_at DESC
                ")->fetchAll(PDO::FETCH_ASSOC);
                sendResponse(true, "", array_merge($runs, $legacy));
            } catch (Exception $e) {
                $stmt = $pdo->query("
                    SELECT gt.session_name, COUNT(*) AS total_classes,
                           MAX(gt.created_at) AS generated_at
                    FROM generated_timetable gt
                    GROUP BY gt.session_name
                    ORDER BY generated_at DESC
                ");
                sendResponse(true, "", $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;

        case 'get_capacity_hint':
            require_once __DIR__ . '/capacity_hint.php';
            $sess = trim($_POST['session'] ?? '');
            if ($sess === '')
                $sess = (string) $pdo->query("SELECT session_name FROM allocations GROUP BY session_name ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
            $rooms = $pdo->query("SELECT room_id, room_type, capacity, COALESCE(equipment,'None') AS equipment FROM rooms")->fetchAll(PDO::FETCH_ASSOC);
            $ast = $pdo->prepare("SELECT a.faculty_id, a.course_id, c.course_code, c.credit_hours, COALESCE(c.requires_equipment,'None') AS requires_equipment,
                    (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e WHERE e.course_id = c.course_id) AS enrolled
                FROM allocations a JOIN courses c ON c.course_id = a.course_id
                WHERE a.session_name = ? AND EXISTS (SELECT 1 FROM enrollments e2 WHERE e2.course_id = c.course_id)");
            $ast->execute([$sess]);
            $allocs = $ast->fetchAll(PDO::FETCH_ASSOC);
            // Slots of the same kind that overlap in time cannot all be used by one room/teacher/cohort, so count, per day,
            // the largest set of NON-overlapping slots of each kind (classic interval scheduling: sort by end time, take greedily).
            $kindDay = ['Theory' => [], 'Lab' => []]; $rawTh = 0; $rawLb = 0;
            foreach ($pdo->query("SELECT day, start_time, end_time FROM time_slots") as $s) {
                $st = strtotime('2000-01-01 ' . $s['start_time']); $en = strtotime('2000-01-01 ' . $s['end_time']);
                if ($en <= $st) $en += 86400;
                $m = (int) (($en - $st) / 60);
                $k = ($m >= 160 && $m <= 210) ? 'Lab' : 'Theory';
                if ($k === 'Lab') $rawLb++; else $rawTh++;
                $kindDay[$k][$s['day']][] = [$st, $en];
            }
            $eff = ['Theory' => 0, 'Lab' => 0];
            foreach ($kindDay as $k => $days) foreach ($days as $iv) {
                usort($iv, fn($a, $b) => $a[1] <=> $b[1]); $last = -1;
                foreach ($iv as [$a, $b2]) if ($a >= $last) { $eff[$k]++; $last = $b2; }
            }
            $th = $eff['Theory']; $lb = $eff['Lab'];
            $coh = [];
            foreach ($pdo->query("SELECT e.course_id, e.batch_year, e.semester, COALESCE(s.program_id,0) AS program_id
                    FROM enrollments e JOIN students s ON s.student_id = e.student_id
                    WHERE e.batch_year != '' AND e.semester != '' GROUP BY e.course_id, e.batch_year, e.semester, s.program_id") as $r)
                $coh[$r['batch_year'] . '|' . $r['semester'] . '|' . $r['program_id']][] = (int) $r['course_id'];
            $h = capacityHint($rooms, $allocs, $th, $lb, array_values($coh));
            $h['session'] = $sess;
            $h['allocations'] = count($allocs);
            $h['overlapNote'] = ($rawTh > $th || $rawLb > $lb)
                ? "Some slots of the same type overlap in time, so only " . $th . " theory / " . $lb . " lab slots can really be used (you have " . $rawTh . " / " . $rawLb . ")."
                : '';
            sendResponse(true, "", $h);
            break;

        case 'get_timetable_runs':
            $baseSession = $_POST['base_session'] ?? '';
            try {
                ensureEquipColumns($pdo);
                $stmt = $pdo->prepare("
                    SELECT run_id, base_session, run_label, fitness_score, classes_count, generated_at, published_at,
                           IF(unplaced_json IS NULL OR unplaced_json = '', 0, JSON_LENGTH(unplaced_json)) AS unplaced_count
                    FROM timetable_runs WHERE base_session = ?
                    ORDER BY generated_at DESC
                ");
                $stmt->execute([$baseSession]);
                sendResponse(true, "", $stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {
                sendResponse(true, "", []);
            }
            break;

        case 'publish_run':
        case 'unpublish_run':
            ensureEquipColumns($pdo);
            $rid = (int) ($_POST['run_id'] ?? 0);
            $rs = $pdo->prepare("SELECT base_session FROM timetable_runs WHERE run_id = ?");
            $rs->execute([$rid]);
            $base = $rs->fetchColumn();
            if (!$base)
                sendResponse(false, "Run not found.");
            if ($action === 'unpublish_run') {
                $pdo->prepare("UPDATE timetable_runs SET published_at = NULL WHERE run_id = ?")->execute([$rid]);
                sendResponse(true, "Timetable un-published.");
            }
            $pdo->beginTransaction();                      // lock the run so two admins cannot publish / edit it at the same moment
            $lk = $pdo->prepare("SELECT unplaced_json FROM timetable_runs WHERE run_id = ? FOR UPDATE");
            $lk->execute([$rid]);
            $ulist = json_decode((string) $lk->fetchColumn(), true);
            if (is_array($ulist) && $ulist) {
                $pdo->rollBack();
                sendResponse(false, "Cannot publish: " . count($ulist) . " class session(s) have no valid room/time (" . implode(', ', array_slice(array_unique(array_column($ulist, 'course_code')), 0, 8)) . "). Add time slots and regenerate, or place them manually.");
            }
            $bad = badEquipClasses($pdo, $rid);
            if ($bad)
                sendResponse(false, "Cannot publish: " . count($bad) . " class(es) are in a room below the equipment minimum: " . implode('; ', array_slice($bad, 0, 10)) . ". Fix them (edit the class room) or the room equipment, then publish.");
            // Two classes in one room at the same time = a class that found no valid room (kept a shared placeholder).
            $dbl = $pdo->prepare("SELECT r.room_number, COUNT(*) n FROM generated_timetable gt JOIN rooms r ON r.room_id = gt.room_id
                WHERE gt.run_id = ? GROUP BY gt.room_id, gt.slot_id, r.room_number HAVING COUNT(*) > 1");
            $dbl->execute([$rid]);
            $dblRooms = $dbl->fetchAll(PDO::FETCH_COLUMN);
            if ($dblRooms)
                sendResponse(false, "Cannot publish: a room is booked twice at the same time (" . implode(', ', array_slice(array_unique($dblRooms), 0, 10)) . "). A class found no valid room. Move one of them with the edit dialog, then publish.");
            $pdo->prepare("UPDATE timetable_runs SET published_at = NULL WHERE base_session = ?")->execute([$base]);
            $pdo->prepare("UPDATE timetable_runs SET published_at = NOW() WHERE run_id = ?")->execute([$rid]);
            $pdo->commit();
            sendResponse(true, "Timetable published. Later room/time changes to it need a reason and are logged.");
            break;

        case 'delete_timetable_session':
            // Legacy delete by session_name
            $stmt = $pdo->prepare("DELETE FROM generated_timetable WHERE session_name = ? AND run_id IS NULL");
            $stmt->execute([$_POST['session_name']]);
            sendResponse(true, "Timetable deleted.");
            break;

        case 'delete_timetable_run':
            $rid = (int) ($_POST['run_id'] ?? 0);
            if (!$rid)
                sendResponse(false, "Invalid run_id.");
            $pdo->prepare("DELETE FROM generated_timetable WHERE run_id = ?")->execute([$rid]);
            $pdo->prepare("DELETE FROM timetable_runs WHERE run_id = ?")->execute([$rid]);
            sendResponse(true, "Timetable run deleted.");
            break;

        // ── Manual edit: update room, faculty, or slot ────────────
        case 'update_timetable_entry':
            ensureEquipColumns($pdo);
            $tid = (int) $_POST['timetable_id'];
            $room_id = isset($_POST['room_id']) ? (int) $_POST['room_id'] : null;
            $fac_id = isset($_POST['faculty_id']) ? (int) $_POST['faculty_id'] : null;
            $slot_id = isset($_POST['slot_id']) ? (int) $_POST['slot_id'] : null;

            $pdo->beginTransaction();                      // check + change happen atomically; the row is locked meanwhile
            $cur = $pdo->prepare("SELECT * FROM generated_timetable WHERE timetable_id = ? FOR UPDATE");
            $cur->execute([$tid]);
            $entry = $cur->fetch();
            if (!$entry) {
                $pdo->rollBack();
                sendResponse(false, "Entry not found.");
                break;
            }
            // Optimistic concurrency: refuse when someone else changed this class after the screen was loaded.
            if (isset($_POST['expected_version']) && $_POST['expected_version'] !== '' && (int) $_POST['expected_version'] !== (int) ($entry['version'] ?? 0)) {
                $pdo->rollBack();
                sendResponse(false, "This class was changed by someone else since you opened it. Reload the timetable and try again.");
            }

            $newRoom = $room_id ?? $entry['room_id'];
            $newFac = $fac_id ?? $entry['faculty_id'];
            $newSlot = $slot_id ?? $entry['slot_id'];
            $session = $entry['session_name'];

            // Room / teacher / student clashes, judged by real time overlap (also catches different-but-overlapping slots).
            if ($room_id || $fac_id || $slot_id) {
                $why = timetableConflict($pdo, $entry['run_id'] !== null ? (int) $entry['run_id'] : null, (string) $session,
                    (int) $entry['course_id'], (int) $newFac, (int) $newRoom, (int) $newSlot, [$tid], $slot_id ? (string) ($entry['component_type'] ?? '') : null);
                if ($why) {
                    $pdo->rollBack();
                    sendResponse(false, $why);
                    break;
                }
            }

            ensureEquipColumns($pdo);
            if ((int) $newRoom !== (int) $entry['room_id'] && equipLevelFor($pdo, (int) $entry['course_id'], (int) $newRoom, $entry['component_type'] ?? null) === 0) {
                sendResponse(false, "That room cannot host this class: a lab class needs a lab room, and the room needs enough seats and the minimum working equipment the course requires. Choose another room.");
            }
            $changed = ((int) $newRoom !== (int) $entry['room_id']) || ((int) $newSlot !== (int) $entry['slot_id']);
            if ($changed) {
                $pub = $pdo->prepare("SELECT published_at FROM timetable_runs WHERE run_id = ?");
                $pub->execute([(int) $entry['run_id']]);
                $isPublished = (bool) $pub->fetchColumn();
                $reason = trim($_POST['reason'] ?? '');
                if ($isPublished && $reason === '')
                    sendResponse(false, "This timetable is published. A reason is required for the change.", ['needs_reason' => true]);
                $log = json_decode((string) ($entry['change_log'] ?? ''), true);
                if (!is_array($log)) $log = [];
                $log[] = ['at' => date('Y-m-d H:i:s'), 'by' => $_SESSION['email'] ?? 'admin', 'published' => $isPublished, 'reason' => $reason,
                    'from' => ['room_id' => (int) $entry['room_id'], 'slot_id' => (int) $entry['slot_id']],
                    'to' => ['room_id' => (int) $newRoom, 'slot_id' => (int) $newSlot]];
                $pdo->prepare("UPDATE generated_timetable SET change_log = ? WHERE timetable_id = ?")->execute([json_encode($log), $tid]);
            }
            $upd = $pdo->prepare("UPDATE generated_timetable SET room_id = ?, faculty_id = ?, slot_id = ?, version = version + 1 WHERE timetable_id = ?");
            $upd->execute([$newRoom, $newFac, $newSlot, $tid]);
            $pdo->commit();
            sendResponse(true, "Entry updated successfully.");
            break;

        case 'swap_timetable_entries':
            $tid1 = (int) $_POST['timetable_id_1'];
            $tid2 = (int) $_POST['timetable_id_2'];
            $field = $_POST['swap_field'];
            if (!in_array($field, ['room_id', 'slot_id'])) {
                sendResponse(false, "Invalid swap field.");
                break;
            }

            $e1 = $pdo->prepare("SELECT * FROM generated_timetable WHERE timetable_id = ?");
            $e1->execute([$tid1]);
            $entry1 = $e1->fetch();
            $e2 = $pdo->prepare("SELECT * FROM generated_timetable WHERE timetable_id = ?");
            $e2->execute([$tid2]);
            $entry2 = $e2->fetch();
            if (!$entry1 || !$entry2) {
                sendResponse(false, "Entry not found.");
                break;
            }

            $val1 = $entry1[$field];
            $val2 = $entry2[$field];
            if ($field === 'room_id' && (equipLevelFor($pdo, (int) $entry1['course_id'], (int) $val2, $entry1['component_type'] ?? null) === 0 || equipLevelFor($pdo, (int) $entry2['course_id'], (int) $val1, $entry2['component_type'] ?? null) === 0)) {
                sendResponse(false, "Swap refused: a room would have too few seats or be below the equipment minimum for its class.");
                break;
            }
            // Clash check on the AFTER-swap positions (against everything else, and the two against each other).
            $n1 = $entry1; $n1[$field] = $val2;
            $n2 = $entry2; $n2[$field] = $val1;
            foreach ([$n1, $n2] as $nn) {
                $why = timetableConflict($pdo, $nn['run_id'] !== null ? (int) $nn['run_id'] : null, (string) $nn['session_name'],
                    (int) $nn['course_id'], (int) $nn['faculty_id'], (int) $nn['room_id'], (int) $nn['slot_id'], [$tid1, $tid2], $field === 'slot_id' ? (string) ($nn['component_type'] ?? '') : null);
                if ($why) sendResponse(false, "Swap refused: " . $why . " Nothing was changed.");
            }
            $tm = $pdo->prepare("SELECT slot_id, day, start_time, end_time FROM time_slots WHERE slot_id IN (?, ?)");
            $tm->execute([(int) $n1['slot_id'], (int) $n2['slot_id']]);
            $tmr = []; foreach ($tm->fetchAll(PDO::FETCH_ASSOC) as $t) $tmr[(int) $t['slot_id']] = $t;
            $t1 = $tmr[(int) $n1['slot_id']] ?? null; $t2 = $tmr[(int) $n2['slot_id']] ?? null;
            if ($t1 && $t2 && $t1['day'] === $t2['day'] && $t1['start_time'] < $t2['end_time'] && $t2['start_time'] < $t1['end_time']) {
                if ((int) $n1['room_id'] === (int) $n2['room_id'] || (int) $n1['faculty_id'] === (int) $n2['faculty_id'])
                    sendResponse(false, "Swap refused: the two classes would use the same room or teacher at overlapping times. Nothing was changed.");
                $st = $pdo->prepare("SELECT 1 FROM enrollments a JOIN enrollments b ON a.student_id = b.student_id WHERE a.course_id = ? AND b.course_id = ? LIMIT 1");
                $st->execute([(int) $n1['course_id'], (int) $n2['course_id']]);
                if ($st->fetchColumn())
                    sendResponse(false, "Swap refused: the two classes share students and would overlap in time. Nothing was changed.");
            }
            // One transaction: remove both rows and put them back with the swapped value (the unique room/teacher keys are
            // checked per row, so swapping with two UPDATEs would fail halfway). Any clash rolls everything back.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM generated_timetable WHERE timetable_id IN (?, ?)")->execute([$tid1, $tid2]);
                $insSwap = $pdo->prepare("INSERT INTO generated_timetable (timetable_id, run_id, session_name, course_id, faculty_id, room_id, slot_id, component_type, display_label, change_log, version)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ([[$entry1, $val2], [$entry2, $val1]] as [$en, $nv]) {
                    $row = $en;
                    $row[$field] = $nv;
                    $insSwap->execute([$row['timetable_id'], $row['run_id'], $row['session_name'], $row['course_id'], $row['faculty_id'], $row['room_id'],
                        $row['slot_id'], $row['component_type'], $row['display_label'], $row['change_log'], ((int) ($row['version'] ?? 0)) + 1]);
                }
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                sendResponse(false, "Swap refused: it would double-book a room or a teacher. Nothing was changed.");
            }
            sendResponse(true, "Swapped successfully.");
            break;

        case 'get_rooms_and_slots':
            $rooms = $pdo->query("SELECT room_id, room_number, room_type, capacity FROM rooms ORDER BY room_number")->fetchAll(PDO::FETCH_ASSOC);
            $slots = $pdo->query("SELECT slot_id, day, start_time, end_time FROM time_slots ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday'), start_time")->fetchAll(PDO::FETCH_ASSOC);
            $facs = $pdo->query("SELECT faculty_id, faculty_name FROM faculty ORDER BY faculty_name")->fetchAll(PDO::FETCH_ASSOC);
            sendResponse(true, "", ['rooms' => $rooms, 'slots' => $slots, 'faculty' => $facs]);
            break;

        // ── Manual Schedule (place an unscheduled course) ────────
        case 'schedule_course_manually':
            $run_id = isset($_POST['run_id']) && $_POST['run_id'] !== '' ? (int) $_POST['run_id'] : null;
            $session = trim($_POST['session'] ?? '');
            $course_id = (int) ($_POST['course_id'] ?? 0);
            $faculty_id = (int) ($_POST['faculty_id'] ?? 0);
            $room_id = (int) ($_POST['room_id'] ?? 0);
            $slot_id = (int) ($_POST['slot_id'] ?? 0);
            $comp_type = ($_POST['component_type'] ?? 'Theory') === 'Lab' ? 'Lab' : 'Theory';

            if (!$session || !$course_id || !$faculty_id || !$room_id || !$slot_id)
                sendResponse(false, "All fields are required.");

            // Clash checks: room, teacher and students, by real time overlap
            $why = timetableConflict($pdo, $run_id, $session, $course_id, $faculty_id, $room_id, $slot_id, [], $comp_type);
            if ($why) sendResponse(false, $why);

            ensureEquipColumns($pdo);
            if (equipLevelFor($pdo, $course_id, $room_id, $comp_type) === 0)
                sendResponse(false, "That room cannot host this class: a lab class needs a lab room, and the room needs enough seats and the minimum working equipment the course requires. Choose another room.");
            $isPublished = false;
            if ($run_id) {
                $pubq = $pdo->prepare("SELECT published_at FROM timetable_runs WHERE run_id = ?");
                $pubq->execute([$run_id]);
                $isPublished = (bool) $pubq->fetchColumn();
            }
            $reason = trim($_POST['reason'] ?? '');
            if ($isPublished && $reason === '')
                sendResponse(false, "This timetable is published. A reason is required for adding a class.", ['needs_reason' => true]);
            $changeLog = json_encode([['at' => date('Y-m-d H:i:s'), 'by' => $_SESSION['email'] ?? 'admin', 'published' => $isPublished, 'reason' => $reason,
                'added' => ['room_id' => $room_id, 'slot_id' => $slot_id]]]);

            $runLabel = $run_id ? null : $session;
            if ($run_id) {
                $rl = $pdo->prepare("SELECT run_label FROM timetable_runs WHERE run_id = ?");
                $rl->execute([$run_id]);
                $runLabel = $rl->fetchColumn() ?: $session;
            }

            $ins = $pdo->prepare("INSERT INTO generated_timetable
                (run_id, session_name, course_id, faculty_id, room_id, slot_id, component_type, change_log)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$run_id, $runLabel, $course_id, $faculty_id, $room_id, $slot_id, $comp_type, $changeLog]);

            if ($run_id) {
                $pdo->prepare("UPDATE timetable_runs SET classes_count = classes_count + 1 WHERE run_id = ?")
                    ->execute([$run_id]);
            }
            sendResponse(true, "Course manually scheduled.");
            break;

        default:
            sendResponse(false, "Unknown action: $action");
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ((string) $e->getCode() === '23000' && stripos($e->getMessage(), 'Duplicate') !== false && stripos($e->getMessage(), 'uq_') !== false)
        sendResponse(false, "Clash: the database refused this because that room or teacher is already booked in that time slot of this timetable.");
    sendResponse(false, "DB Error: " . $e->getMessage());
}
?>
