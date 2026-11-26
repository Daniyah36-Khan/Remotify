<?php
// clash_check.php — one place that answers: "if this class sits in this room/slot, does it collide with anything else in the same timetable?"
// Checks by real TIME OVERLAP (not just the same slot id), so two different but overlapping slots cannot double-book anything.
//   room      – the same room used at overlapping times
//   teacher   – the same faculty member teaching at overlapping times
//   students  – any student enrolled in this course AND in another class at an overlapping time (same course counts too)
//   same-day rule (only when $component is given: 'Theory' or 'Lab') – a course may not have two theory classes on one day,
//             and its lab may not share a day with its theory (keeps the week balanced for students and teachers)
// Returns a human message, or null when everything is fine.

function timetableConflict(PDO $pdo, ?int $runId, string $session, int $courseId, int $facultyId, int $roomId, int $slotId, array $ignoreTids = [], ?string $component = null): ?string
{
    $m = $pdo->prepare("SELECT day, start_time, end_time FROM time_slots WHERE slot_id = ?");
    $m->execute([$slotId]);
    $me = $m->fetch(PDO::FETCH_ASSOC);
    if (!$me) return "That time slot does not exist.";

    $ign = array_values(array_filter(array_map('intval', $ignoreTids)));
    if (!$ign) $ign = [0];
    $in = implode(',', $ign);

    $q = $pdo->prepare("
        SELECT gt.timetable_id, gt.course_id, gt.room_id, gt.faculty_id,
               c.course_code, r.room_number, f.faculty_name, ts.day, ts.start_time, ts.end_time
        FROM generated_timetable gt
        JOIN time_slots ts ON ts.slot_id    = gt.slot_id
        JOIN courses    c  ON c.course_id   = gt.course_id
        JOIN rooms      r  ON r.room_id     = gt.room_id
        JOIN faculty    f  ON f.faculty_id  = gt.faculty_id
        WHERE (gt.run_id <=> ?) AND (? IS NOT NULL OR gt.session_name = ?)
          AND gt.timetable_id NOT IN ($in)
          AND ts.day = ? AND ts.start_time < ? AND ts.end_time > ?");
    $q->execute([$runId, $runId, $session, $me['day'], $me['end_time'], $me['start_time']]);
    $others = $q->fetchAll(PDO::FETCH_ASSOC);

    // Same-day rule: keeps the timetable non-burdening (no two theory classes of a course on one day, no lab on a theory day).
    if ($component === 'Theory' || $component === 'Lab') {
        $d = $pdo->prepare("
            SELECT gt.component_type
            FROM generated_timetable gt JOIN time_slots ts ON ts.slot_id = gt.slot_id
            WHERE (gt.run_id <=> ?) AND (? IS NOT NULL OR gt.session_name = ?)
              AND gt.course_id = ? AND ts.day = ? AND gt.timetable_id NOT IN ($in)");
        $d->execute([$runId, $runId, $session, $courseId, $me['day']]);
        foreach ($d->fetchAll(PDO::FETCH_COLUMN) as $ct) {
            if ($component === 'Theory' && $ct === 'Theory')
                return "Day rule: this course already has a theory class on {$me['day']}. Theory classes of one course must be on different days.";
            if ($component === 'Theory' && $ct === 'Lab')
                return "Day rule: this course has its lab on {$me['day']}. A theory class cannot share a day with the course's lab.";
            if ($component === 'Lab' && $ct === 'Theory')
                return "Day rule: this course already has a theory class on {$me['day']}. The lab must be on a different day.";
        }
    }

    $when = function ($o) { return substr($o['start_time'], 0, 5) . '-' . substr($o['end_time'], 0, 5); };
    $courses = [];
    foreach ($others as $o) {
        if ((int) $o['room_id'] === $roomId)
            return "Room clash: room {$o['room_number']} is already used by {$o['course_code']} at {$o['day']} " . $when($o) . " (overlaps this time).";
        if ((int) $o['faculty_id'] === $facultyId)
            return "Faculty clash: {$o['faculty_name']} already teaches {$o['course_code']} at {$o['day']} " . $when($o) . " (overlaps this time).";
        $courses[(int) $o['course_id']] = $o['course_code'];
    }
    if ($courses) {
        $ids = implode(',', array_map('intval', array_keys($courses)));
        $s = $pdo->prepare("SELECT b.course_id FROM enrollments a JOIN enrollments b ON a.student_id = b.student_id
                            WHERE a.course_id = ? AND b.course_id IN ($ids) LIMIT 1");
        $s->execute([$courseId]);
        $hit = $s->fetchColumn();
        if ($hit !== false)
            return "Student clash: some students of this course are also enrolled in {$courses[(int) $hit]}, which runs at an overlapping time on {$me['day']}.";
    }
    return null;
}
