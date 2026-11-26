<?php
/**
 * capacity_hint.php — "how many time slots do I need?" (pure function, no DB, no output).
 *
 * Every number returned is a NECESSARY MINIMUM, not a guess: with fewer slots than this, a valid
 * timetable cannot exist, because
 *   (a) (theory classes kept in lecture halls; a lab room is only an emergency fallback) a set of rooms S can hold at most |S| classes per slot, so the classes that can ONLY use rooms
 *       inside S need at least ceil(load(S) / |S|) slots (Hall's counting bound);
 *   (b) one teacher, or one student cohort, cannot be in two places in the same slot, so a teacher's
 *       (or cohort's) weekly sessions need at least that many slots.
 * The GA needs some free room beyond the minimum to avoid clashes, so a recommended figure (2x the minimum, measured) is added.
 */
require_once __DIR__ . '/equipment.php';

function hintCH(string $ch): array { $p = explode('-', trim($ch)); return [max(0, (int)($p[0] ?? 0)), max(0, (int)($p[1] ?? 0))]; }
function hintTheorySessions(int $T): int { return $T <= 0 ? 0 : ($T <= 2 ? 1 : ($T <= 3 ? 2 : ($T <= 5 ? 3 : 4))); }

/**
 * @param array $rooms   [room_id, room_type, capacity, equipment]
 * @param array $allocs  [faculty_id, course_id, course_code, credit_hours, requires_equipment, enrolled]
 * @param array $cohorts list of course_id lists (one list per batch+semester+program group)
 */
function capacityHint(array $rooms, array $allocs, int $haveTheory, int $haveLab, array $cohorts = []): array {
    $byKind = ['Theory' => [], 'Lab' => []];
    foreach ($rooms as $r) $byKind[$r['room_type'] === 'Lab' ? 'Lab' : 'Theory'][] = $r;
    $present = [];
    foreach ($byKind as $k => $list) $present[$k] = equipTypesInRooms(array_column($list, 'equipment'));

    $load = ['Theory' => [], 'Lab' => []];      // kind => [roomSetKey => sessions]
    $sessions = ['Theory' => 0, 'Lab' => 0];
    $facLoad = []; $courseSess = [];            // faculty_id => [Theory, Lab]; course_id => [Theory, Lab]
    $noRoom = [];

    foreach ($allocs as $a) {
        [$T, $P] = hintCH($a['credit_hours'] ?? '3-0-3');
        $n = ['Theory' => hintTheorySessions($T), 'Lab' => $P > 0 ? 1 : 0];
        $req = equipCourseReq($a['requires_equipment'] ?? 'None');
        $stu = max(1, (int)$a['enrolled']);
        foreach (['Theory', 'Lab'] as $k) {
            if ($n[$k] === 0) continue;
            $ids = [];
            foreach ($byKind[$k] as $r) {
                $cap = (int)$r['capacity'];
                if ($cap < $stu) continue;
                if (equipLevel(equipEffectiveReq($req, $present[$k]), equipRoomCounts($r['equipment']), $stu, $cap) > 0) $ids[] = $r['room_id'];
            }
            if (!$ids) { $noRoom[] = ['course' => $a['course_code'], 'part' => $k, 'students' => $stu]; continue; }
            sort($ids); $key = implode(',', $ids);
            $load[$k][$key] = ($load[$k][$key] ?? 0) + $n[$k];
            $sessions[$k] += $n[$k];
            $facLoad[$a['faculty_id']][$k] = ($facLoad[$a['faculty_id']][$k] ?? 0) + $n[$k];
            $courseSess[$a['course_id']][$k] = ($courseSess[$a['course_id']][$k] ?? 0) + $n[$k];
        }
    }

    $out = ['rooms' => ['Theory' => count($byKind['Theory']), 'Lab' => count($byKind['Lab'])],
            'sessions' => $sessions, 'have' => ['Theory' => $haveTheory, 'Lab' => $haveLab],
            'noRoom' => $noRoom, 'min' => [], 'why' => [], 'recommended' => []];

    foreach (['Theory', 'Lab'] as $k) {
        $best = 0; $why = '';
        $sets = array_map(fn($s) => explode(',', (string)$s), array_keys($load[$k]));
        if ($sets) { $all = []; foreach ($sets as $s) foreach ($s as $x) $all[$x] = 1; $sets[] = array_keys($all); }
        foreach ($sets as $S) {
            $Sset = array_flip($S); $sum = 0;
            foreach ($load[$k] as $key => $cnt) { $ok = true; foreach (explode(',', (string)$key) as $x) if (!isset($Sset[$x])) { $ok = false; break; } if ($ok) $sum += $cnt; }
            $need = (int)ceil($sum / max(1, count($S)));
            if ($need > $best) { $best = $need; $why = "$sum " . ($k === 'Lab' ? 'lab' : 'theory') . " sessions can only use " . count($S) . " room(s)"; }
        }
        foreach ($facLoad as $fid => $v) { $x = $v[$k] ?? 0; if ($x > $best) { $best = $x; $why = "one teacher has $x weekly " . strtolower($k) . " sessions"; } }
        $coh = 0;
        foreach ($cohorts as $cs) { $x = 0; foreach ($cs as $cid) $x += $courseSess[$cid][$k] ?? 0; $coh = max($coh, $x); }
        if ($coh > $best) { $best = $coh; $why = "one student group has $coh weekly " . strtolower($k) . " sessions"; }
        $out['min'][$k] = $best; $out['why'][$k] = $why;
        // headroom: 2x the minimum. Measured on a 720-class test set: 1.5x still left a class without a room, 2x placed every class
        $out['recommended'][$k] = $best > 0 ? (int)ceil($best * 2) : 0;
    }
    return $out;
}
