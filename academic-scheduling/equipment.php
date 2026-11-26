<?php
/**
 * equipment.php — shared equipment helpers (no DB, no output).
 *
 * rooms.equipment (JSON, one entry per physical item, 1 = working, 0 = faulty):
 *     {"AC 1":1,"AC 2":0,"Light 1":1,"PC 1":1,"Projector":1}
 *   Type = name minus trailing number ("AC 2" -> "ac"). Sensors can later overwrite single values.
 *
 * courses.requires_equipment (JSON list of NAMES only):  ["PC","AC","Light"]
 *   How many items are needed is computed from the room's installed quantity, its capacity and the
 *   number of enrolled students when rooms are assigned. 'None' or empty = nothing required.
 *
 * A required type only counts for a room type that has it somewhere: if no lecture hall owns any PC,
 * the PC requirement is not applied to theory classes in lecture halls (it still applies in labs).
 */

/** Tuning file equipment_config.json (same folder). Any equipment type works without an entry.
 *  base_share[type]  = part of the equipment needed whatever the class size (default 0)
 *  min_fraction[type]= bare minimum as a fraction of the ideal count (default default_min_fraction) */
function equipConfig(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = ['default_min_fraction' => 0.6, 'default_base_share' => 0.0,
          'min_fraction' => ['pc' => 0.75, 'ac' => 0.5, 'fan' => 0.6, 'light' => 0.6],
          'base_share'   => [],
          'aliases'      => ['computers' => 'pc', 'computer' => 'pc', 'pcs' => 'pc', 'acs' => 'ac']];
    $f = __DIR__ . '/equipment_config.json';
    if (is_file($f)) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j)) $c = array_replace_recursive($c, $j); }
    return $c;
}

function equipType(string $name): string {
    $t = strtolower(trim(preg_replace('/[\s_-]*\d+$/', '', trim($name))));
    $al = equipConfig()['aliases'];
    if (isset($al[$t])) return $al[$t];
    if (strlen($t) > 3 && substr($t, -1) === 's' && substr($t, -2) !== 'ss') $t = substr($t, 0, -1);   // fans -> fan
    return $al[$t] ?? $t;
}

/** Room JSON -> ['type' => ['w' => working, 'n' => installed]] (anything that is not JSON = no equipment) */
function equipRoomCounts(?string $s): array {
    static $cache = [];
    $s = $s ?? 'None';
    if (isset($cache[$s])) return $cache[$s];
    $out = [];
    $j = json_decode($s, true);
    if (is_array($j)) {
        foreach ($j as $name => $st) {
            $t = equipType((string)$name); if ($t === '') continue;
            $out[$t] = $out[$t] ?? ['w' => 0, 'n' => 0];
            $out[$t]['n']++; if ((int)$st === 1) $out[$t]['w']++;
        }
    }
    return $cache[$s] = $out;
}

/** Course JSON list -> required types, e.g. ['pc','ac','light'] */
function equipCourseReq(?string $s): array {
    static $cache = [];
    $s = $s ?? 'None';
    if (isset($cache[$s])) return $cache[$s];
    $out = [];
    $j = json_decode($s, true);
    if (is_array($j)) {
        $names = isset($j[0]) ? $j : array_keys($j);
        foreach ($names as $n) { $t = equipType((string)$n); if ($t !== '') $out[$t] = true; }
    }
    return $cache[$s] = array_keys($out);
}

/** Set of equipment types owned by at least one of these rooms (list of room equipment JSON strings). */
function equipTypesInRooms(array $roomEquipStrings): array {
    $set = [];
    foreach ($roomEquipStrings as $e) foreach (array_keys(equipRoomCounts($e)) as $t) $set[$t] = true;
    return $set;
}

/** Keep only the required types that this kind of room can have at all. */
function equipEffectiveReq(array $req, array $typesPresent): array {
    return array_values(array_filter($req, fn($t) => isset($typesPresent[$t])));
}

/** Design-share rule (same for every type): the room was equipped for its capacity C with N items.
 *  ideal = ceil(N * (b + (1-b) * S/C)), never more than N, at least 1.
 *  bare minimum = max(1, ceil(ideal * min_fraction)). Always whole items. */
function equipNeeds(string $type, int $students, int $installed, int $cap): array {
    $cfg = equipConfig();
    $f = (float)($cfg['min_fraction'][$type] ?? $cfg['default_min_fraction']);
    $b = (float)($cfg['base_share'][$type] ?? $cfg['default_base_share']);
    $share = min(1.0, $b + (1 - $b) * ($students / max($cap, 1)));
    $ideal = min($installed, max(1, (int)ceil(round($installed * $share, 6))));
    return [$ideal, max(1, (int)ceil(round($ideal * $f, 6)))];
}

/** 2 = ideal for every required type, 1 = bare minimum, 0 = below minimum (reject room). */
function equipLevel(array $req, array $have, int $students, int $cap): int {
    $students = max($students, 1);
    $level = 2;
    foreach ($req as $t) {
        $h = $have[$t] ?? null;
        if ($h === null || $h['n'] < 1) return 0;        // room lacks this equipment type entirely
        [$ideal, $min] = equipNeeds($t, $students, $h['n'], $cap);
        if ($h['w'] < $min) return 0;
        if ($h['w'] < $ideal) $level = 1;
    }
    return $level;
}

/** CSV text -> JSON. Rooms: "AC 1:1;AC 2:0;Projector" (name[:1|0|working|faulty]). Courses: "PC;AC;Light". */
function equipFromCsvRoom(string $s): string {
    $s = trim($s);
    if ($s === '' || strcasecmp($s, 'none') === 0) return 'None';
    if ($s[0] === '{') return json_decode($s, true) !== null ? $s : 'None';
    $o = [];
    foreach (explode(';', $s) as $p) {
        $b = explode(':', $p, 2); $n = trim($b[0]); if ($n === '') continue;
        $st = strtolower(trim($b[1] ?? '1'));
        $o[$n] = in_array($st, ['0', 'faulty', 'f'], true) ? 0 : 1;
    }
    return $o ? json_encode($o) : 'None';
}
function equipFromCsvCourse(string $s): string {
    $s = trim($s);
    if ($s === '' || strcasecmp($s, 'none') === 0) return 'None';
    if ($s[0] === '[') return json_decode($s, true) !== null ? $s : 'None';
    $o = array_values(array_filter(array_map('trim', explode(';', $s)), 'strlen'));
    return $o ? json_encode($o) : 'None';
}

/** Form post -> JSON. Rooms: eq_name[], eq_status[] ("1"/"0"). Courses: eq_name[] (names only). */
function equipRoomFromPost(array $p): string {
    $o = [];
    foreach (($p['eq_name'] ?? []) as $i => $n) {
        $n = trim((string)$n); if ($n === '') continue;
        $o[$n] = (($p['eq_status'][$i] ?? '1') === '0') ? 0 : 1;
    }
    return $o ? json_encode($o) : 'None';
}
function equipCourseFromPost(array $p): string {
    $o = [];
    foreach (($p['eq_name'] ?? []) as $n) { $n = trim((string)$n); if ($n !== '') $o[$n] = $n; }
    return $o ? json_encode(array_values($o)) : 'None';
}
