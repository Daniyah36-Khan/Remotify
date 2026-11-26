<?php
/**
 * ga_worker.php — Optimized GA Worker (CLI)
 * Usage: php ga_worker.php <job_id>
 *
 * Optimizations applied:
 *  1. Group-token clash  — no per-student-ID loop; batch+semester group check only
 *  2. Background job     — runs detached from browser, polls via ga_status.php
 *  3. Penalty cache      — standalone penalties (room type, capacity, equip) cached per (course,room,slot)
 *  4. Dept partitioning  — one GA per department (~180 allocs each), rooms shared via global booking map
 *  7. Conflict graph     — pre-computed faculty+program conflicts used in greedy builder
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(0);          // no limit — browser is not waiting
ini_set('memory_limit', '1G');

require __DIR__ . '/db.php';
require __DIR__ . '/equipment.php';

$jobId = (int)($argv[1] ?? 0);
if (!$jobId) { fwrite(STDERR, "No job_id\n"); exit(1); }

// ── fatal error handler ────────────────────────────────────────────────
register_shutdown_function(function () use ($pdo, $jobId) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        try {
            $pdo->prepare("UPDATE ga_jobs SET status='failed', progress=? WHERE job_id=?")
                ->execute(['Fatal: ' . $e['message'], $jobId]);
        } catch (Exception $_) {}
    }
});

function jobProgress(PDO $pdo, int $jobId, string $msg, int $done, int $total): void {
    $pdo->prepare("UPDATE ga_jobs SET status='running', progress=?, dept_done=?, dept_total=?, updated_at=NOW() WHERE job_id=?")
        ->execute([$msg, $done, $total, $jobId]);
}

// ── load job ────────────────────────────────────────────────────────────
$job = $pdo->prepare("SELECT * FROM ga_jobs WHERE job_id=?");
$job->execute([$jobId]);
$job = $job->fetch(PDO::FETCH_ASSOC);
if (!$job) { fwrite(STDERR, "Job $jobId not found\n"); exit(1); }
$session = $job['session_name'];

$pdo->prepare("UPDATE ga_jobs SET status='running', progress='Loading data...', updated_at=NOW() WHERE job_id=?")
    ->execute([$jobId]);

// ═══════════════════════════════════════════════════════════════════
// CONSTANTS
// ═══════════════════════════════════════════════════════════════════
define('P_HARD',            100000);
define('P_ROOM_CLASH',      P_HARD);
define('P_FACULTY_CLASH',   P_HARD);
define('P_STUDENT_CLASH',   P_HARD);
define('P_TRANSFER_CLASH',  P_HARD);
define('P_LAB_THEORY_SAME_DAY', P_HARD);
define('P_LAB_WRONG_ROOM',  P_HARD);
define('P_THEORY_WRONG_ROOM', P_HARD);
define('P_CAPACITY',        P_HARD);
define('P_WRONG_SLOT_TYPE', P_HARD);
define('P_THEORY_SAME_DAY', P_HARD);
define('P_BACKTOBACK',      3000);
define('P_OVERLOAD',        1000);
define('P_EQUIP',           8000);
define('GA_MUT',   0.50);
define('GA_ELITE', 3);
define('GA_TOURN', 4);

// ═══════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════

function parseCH(string $ch): array {
    $p = explode('-', trim($ch));
    return ['T' => max(0,(int)($p[0]??0)), 'P' => max(0,(int)($p[1]??0))];
}
function theorySessions(int $T): int {
    if ($T<=0) return 0; if ($T<=2) return 1; if ($T<=3) return 2; if ($T<=5) return 3; return 4;
}
function slotMins(array $s): int {
    $b='2000-01-01 '; $st=strtotime($b.$s['start_time']); $en=strtotime($b.$s['end_time']);
    if ($en<=$st) $en+=86400; return (int)(($en-$st)/60);
}
function isLabSlot(array $s): bool { $m=(int)($s['duration_mins']??slotMins($s)); return $m>=160&&$m<=210; }
function buildConsecutivePairs(array $ts): array {
    $pairs=[];
    foreach($ts as $day=>$slots){
        usort($slots,fn($a,$b)=>strcmp($a['start_time'],$b['start_time']));
        for($i=0;$i<count($slots)-1;$i++){
            $endA=strtotime('2000-01-01 '.$slots[$i]['end_time']);
            $stB =strtotime('2000-01-01 '.$slots[$i+1]['start_time']);
            if(abs($stB-$endA)<=600) $pairs[$day][]= [$slots[$i],$slots[$i+1]];
        }
    }
    return $pairs;
}


// ── Hungarian algorithm (min-cost assignment, O(n^2 m)); rows <= columns. Returns row => column. ──
const HU_BIG = 1000000000;
function hungarianMin(array $a): array {
    $n=count($a); if($n===0) return [];
    $m=count($a[0]);
    $u=array_fill(0,$n+1,0); $v=array_fill(0,$m+1,0); $p=array_fill(0,$m+1,0); $way=array_fill(0,$m+1,0);
    for($i=1;$i<=$n;$i++){
        $p[0]=$i; $j0=0; $minv=array_fill(0,$m+1,PHP_INT_MAX); $used=array_fill(0,$m+1,false);
        do{
            $used[$j0]=true; $i0=$p[$j0]; $delta=PHP_INT_MAX; $j1=0;
            for($j=1;$j<=$m;$j++) if(!$used[$j]){
                $cur=$a[$i0-1][$j-1]-$u[$i0]-$v[$j];
                if($cur<$minv[$j]){ $minv[$j]=$cur; $way[$j]=$j0; }
                if($minv[$j]<$delta){ $delta=$minv[$j]; $j1=$j; }
            }
            for($j=0;$j<=$m;$j++){ if($used[$j]){ $u[$p[$j]]+=$delta; $v[$j]-=$delta; } else $minv[$j]-=$delta; }
            $j0=$j1;
        } while($p[$j0]!==0);
        do{ $j1=$way[$j0]; $p[$j0]=$p[$j1]; $j0=$j1; } while($j0);
    }
    $res=[]; for($j=1;$j<=$m;$j++) if($p[$j]) $res[$p[$j]-1]=$j-1;
    return $res;
}

/**
 * Slot-TYPE penalty only (a TIME concern the GA controls).
 * Room type / capacity / equipment are handled by Phase-2 global room
 * assignment (assignRoomsGlobally), so they are deliberately NOT penalised
 * here — that keeps the GA focused purely on time-slot constraints.
 */
function slotTypePenalty(array $g, bool $lf): int {
    if(!empty($g['is_lab_cont'])) return 0;
    if($lf && $g['is_lab']) return 0; // fallback mode: lab uses theory slots by design
    if($g['is_lab']  && !$g['slot_is_lab']) return P_WRONG_SLOT_TYPE; // lab in a theory slot
    if(!$g['is_lab'] &&  $g['slot_is_lab']) return P_WRONG_SLOT_TYPE; // theory in a lab slot
    return 0;
}

// $rooms MUST be pre-sorted by capacity ASC (caller loads it ORDER BY capacity ASC).
// Because of that, the first room that fits IS the closest-fit room — no per-call
// usort needed. This is the single hottest function in the GA, so it stays O(rooms).
function pickRoom(int $students, bool $isLab, string $ce, array $rooms, array $usedRoomIds=[]): array {
    $typeOk=fn($r)=>$isLab?$r['room_type']==='Lab':$r['room_type']!=='Lab';
    // Priority 1: free + type + fits + equipment ideal, then bare-minimum (below minimum never preferred).
    // Priorities 2-5 are only PLACEHOLDERS for the GA gene: final rooms are chosen by assignRoomsGlobally().
    $req=equipCourseReq($ce);
    foreach([2,1] as $lv) foreach($rooms as $r) if(!isset($usedRoomIds[(int)$r['room_id']])&&(int)$r['capacity']>=$students&&$typeOk($r)&&equipLevel($req,equipRoomCounts($r['equipment']??'None'),$students,(int)$r['capacity'])>=$lv) return $r;
    // Priority 2: free + type + fits
    foreach($rooms as $r) if(!isset($usedRoomIds[(int)$r['room_id']])&&(int)$r['capacity']>=$students&&$typeOk($r)) return $r;
    // Priority 3: free + fits (wrong type — fitness penalises)
    foreach($rooms as $r) if(!isset($usedRoomIds[(int)$r['room_id']])&&(int)$r['capacity']>=$students) return $r;
    // Priority 4: any (even used) + type + fits
    foreach($rooms as $r) if((int)$r['capacity']>=$students&&$typeOk($r)) return $r;
    // Priority 5: last resort — biggest room
    return $rooms[count($rooms)-1];
}

/** Build conflict graph — capped to avoid O(n²) blowup on large groups */
/**
 * TIME OVERLAP tables. Two classes clash in time when their slots overlap — not only when it is the same slot id.
 *   $OV[slot]  = ids of all slots overlapping it (itself included, same day)
 *   $PTS[slot] = "time points" inside it. A point is a start instant (day+start time). Two slots overlap exactly when they
 *                share a point, so "same teacher / same student group / same room at overlapping times" becomes
 *                "same key at one point".
 */
function buildOverlap(array $slots): array {
    $pid=[]; foreach($slots as $s){ $k=$s['day'].'|'.$s['start_time']; if(!isset($pid[$k])) $pid[$k]=count($pid); }
    $ov=[]; $pts=[];
    foreach($slots as $a){
        $sa=(int)$a['slot_id']; $ov[$sa]=[]; $pts[$sa]=[];
        foreach($slots as $b){
            if($a['day']!==$b['day']) continue;
            if($a['start_time']<$b['end_time'] && $b['start_time']<$a['end_time']) $ov[$sa][]=(int)$b['slot_id'];
            if($a['start_time']<=$b['start_time'] && $b['start_time']<$a['end_time']) $pts[$sa][$pid[$b['day'].'|'.$b['start_time']]]=true;
        }
        $pts[$sa]=array_keys($pts[$sa]);
    }
    return [$ov,$pts];
}
/** Mark a slot (and every slot overlapping it) as busy for $key in a booking map. */
function ovMark(array &$book, $key, string $day, int $sid): void {
    global $OV;
    foreach(($OV[$sid]??[$sid]) as $o) $book[$key]["$day|$o"]=true;
}

function buildConflictGraph(array $allocs, array $batchSemGroups): array {
    $g=[];
    // Group conflicts — skip groups larger than 30 (too many pairs, marginal benefit)
    foreach($batchSemGroups as $cids){
        $u=array_unique($cids); $n=count($u);
        if($n>30) continue; // large groups handled by BSG clash in fitness instead
        for($i=0;$i<$n;$i++) for($j=$i+1;$j<$n;$j++){
            $g[$u[$i]][$u[$j]]=true; $g[$u[$j]][$u[$i]]=true;
        }
    }
    // Faculty conflicts
    $fc=[];
    foreach($allocs as $a) $fc[(int)$a['faculty_id']][]=(int)$a['course_id'];
    foreach($fc as $courses){
        $n=count($courses);
        if($n>50) continue; // faculty teaching 50+ courses is data error — skip
        for($i=0;$i<$n;$i++) for($j=$i+1;$j<$n;$j++){
            $g[$courses[$i]][$courses[$j]]=true; $g[$courses[$j]][$courses[$i]]=true;
        }
    }
    return $g;
}

/**
 * Greedy chromosome builder.
 * Opt-1: no student-ID loops.
 * Opt-7: conflict graph avoids placing conflicting courses in same slot.
 * Hash-set booking (already done in ga_handler.php edits).
 */
function makeGreedyChromosome(
    array $allocs, array $rooms, array $theorySlots, array $labSlots,
    array $consecutivePairs, array $days, bool $labFallback,
    array $initFacBooked=[], array $initProgBooked=[], array $initRoomBooked=[],
    array $conflictGraph=[], array $initPlacedSlots=[]
): array {
    global $OV;
    $facBooked  = $initFacBooked;
    $progBooked = $initProgBooked;
    $roomBooked = $initRoomBooked;
    // course_id => [slot_id => true]. Seeded with already-placed courses so the
    // conflict graph can block group-mate / faculty-mate slots even when this
    // call only builds a single course (used by the repair pass).
    $placedSlots= $initPlacedSlots;
    $chr=[];

    foreach($allocs as $alloc){
        $fid=(int)$alloc['faculty_id']; $pid=(int)($alloc['program_id']??0);
        $cid=(int)$alloc['course_id'];
        $ch=parseCH($alloc['credit_hours']??'0-0-0');
        $ce=$alloc['requires_equipment']??'None';
        $students=max((int)($alloc['enrolled_students']??1),1);
        $genes=[]; $groupUsedDays=[];

        // Pre-build blocked slots from conflict graph
        $blockedSlots=[];
        foreach($conflictGraph[$cid]??[] as $cc=>$_)
            foreach($placedSlots[$cc]??[] as $sid=>$_) foreach(($OV[$sid]??[$sid]) as $o) $blockedSlots[$o]=true;

        $tc=theorySessions($ch['T']);
        for($i=0;$i<$tc;$i++){
            $candidates=[];
            foreach($theorySlots as $day=>$slots){
                if(in_array($day,$groupUsedDays,true)) continue;
                foreach($slots as $sl){
                    $ds="$day|{$sl['slot_id']}";
                    if(isset($blockedSlots[$sl['slot_id']])) continue;
                    if(isset($facBooked[$fid][$ds])) continue;
                    if($pid>0&&isset($progBooked[$pid][$ds])) continue;
                    $candidates[]=['day'=>$day,'slot'=>$sl,'ds'=>$ds];
                }
            }
            if(empty($candidates)){
                foreach($theorySlots as $day=>$slots){
                    if(in_array($day,$groupUsedDays,true)) continue;
                    foreach($slots as $sl) $candidates[]=['day'=>$day,'slot'=>$sl,'ds'=>"$day|{$sl['slot_id']}"];
                }
            }
            if(empty($candidates)) continue;

            $wMin=($ch['T']===2)?110:0; $wMax=($ch['T']===2)?130:109;
            $fc2=array_values(array_filter($candidates,function($c)use($wMin,$wMax){
                $m=(int)($c['slot']['duration_mins']??slotMins($c['slot'])); return $m>=$wMin&&$m<=$wMax;
            }));
            if(!empty($fc2)) $candidates=$fc2;

            $pick=$candidates[array_rand($candidates)];
            $day=$pick['day']; $sl=$pick['slot']; $ds=$pick['ds'];
            $usedRooms=$roomBooked[$ds]??[];
            $room=pickRoom($students,false,$ce,$rooms,$usedRooms);

            $genes[]=[
                'course_id'=>$cid,'faculty_id'=>$fid,'room_id'=>(int)$room['room_id'],
                'slot_id'=>(int)$sl['slot_id'],'session_name'=>$alloc['session_name'],
                'component_type'=>'Theory','program_id'=>$pid,
                'department_id'=>(int)($alloc['department_id']??0),
                'enrolled'=>$students,'is_lab'=>false,
                'room_cap'=>(int)$room['capacity'],'room_type'=>$room['room_type'],
                'room_equip'=>$room['equipment']??'None','course_equip'=>$ce,
                'day'=>$day,'slot_is_lab'=>false,'theory_idx'=>$i,
                'credit_hours'=>$alloc['credit_hours']??'0-0-0',
                'pair_slot'=>null,'is_lab_cont'=>false,'display_label'=>null,
            ];
            ovMark($facBooked,$fid,$day,(int)$sl['slot_id']);
            $roomBooked[$ds][(int)$room['room_id']]=true;
            if($pid>0) ovMark($progBooked,$pid,$day,(int)$sl['slot_id']);
            $groupUsedDays[]=$day;
            $placedSlots[$cid][(int)$sl['slot_id']]=true;
        }

        // Lab gene
        if($ch['P']>0){
            $labCandidates=[];
            foreach($labSlots as $day=>$slots){
                if(in_array($day,$groupUsedDays,true)) continue;
                foreach($slots as $sl){
                    $ds="$day|{$sl['slot_id']}";
                    if(isset($blockedSlots[$sl['slot_id']])) continue;
                    if(isset($facBooked[$fid][$ds])) continue;
                    if($pid>0&&isset($progBooked[$pid][$ds])) continue;
                    $labCandidates[]=['day'=>$day,'slot'=>$sl,'ds'=>$ds];
                }
            }
            if(empty($labCandidates)){
                foreach($labSlots as $day=>$slots){
                    if(in_array($day,$groupUsedDays,true)) continue;
                    foreach($slots as $sl) $labCandidates[]=['day'=>$day,'slot'=>$sl,'ds'=>"$day|{$sl['slot_id']}"];
                }
                if(empty($labCandidates)) foreach($labSlots as $day=>$slots) foreach($slots as $sl) $labCandidates[]=['day'=>$day,'slot'=>$sl,'ds'=>"$day|{$sl['slot_id']}"];
            }

            if(!empty($labCandidates)&&!empty($labSlots)){
                $pick=$labCandidates[array_rand($labCandidates)];
                $day=$pick['day']; $sl=$pick['slot']; $ds=$pick['ds'];
                $room=pickRoom($students,true,$ce,$rooms,$roomBooked[$ds]??[]);
                $genes[]=[
                    'course_id'=>$cid,'faculty_id'=>$fid,'room_id'=>(int)$room['room_id'],
                    'slot_id'=>(int)$sl['slot_id'],'session_name'=>$alloc['session_name'],
                    'component_type'=>'Lab','program_id'=>$pid,
                    'department_id'=>(int)($alloc['department_id']??0),
                    'enrolled'=>$students,'is_lab'=>true,
                    'room_cap'=>(int)$room['capacity'],'room_type'=>$room['room_type'],
                    'room_equip'=>$room['equipment']??'None','course_equip'=>$ce,
                    'day'=>$day,'slot_is_lab'=>true,'theory_idx'=>0,
                    'credit_hours'=>$alloc['credit_hours']??'0-0-0',
                    'pair_slot'=>null,'is_lab_cont'=>false,
                ];
                ovMark($facBooked,$fid,$day,(int)$sl['slot_id']);
                $roomBooked[$ds][(int)$room['room_id']]=true;
                if($pid>0) ovMark($progBooked,$pid,$day,(int)$sl['slot_id']);
            } elseif(!empty($consecutivePairs)) {
                // Fallback: two consecutive 1.5hr slots
                $pc=[];
                foreach($consecutivePairs as $day=>$pairs){
                    if(in_array($day,$groupUsedDays,true)) continue;
                    foreach($pairs as $pair){
                        $ds="$day|{$pair[0]['slot_id']}";
                        if(isset($facBooked[$fid][$ds])) continue;
                        if($pid>0&&isset($progBooked[$pid][$ds])) continue;
                        $pc[]=['day'=>$day,'pair'=>$pair,'ds'=>$ds];
                    }
                }
                if(empty($pc)) foreach($consecutivePairs as $day=>$pairs) foreach($pairs as $pair) $pc[]=['day'=>$day,'pair'=>$pair,'ds'=>"$day|{$pair[0]['slot_id']}"];
                if(!empty($pc)){
                    $pick=$pc[array_rand($pc)]; $day=$pick['day']; $pair=$pick['pair']; $ds=$pick['ds'];
                    $room=pickRoom($students,true,$ce,$rooms,$roomBooked[$ds]??[]);
                    $base=['course_id'=>$cid,'faculty_id'=>$fid,'room_id'=>(int)$room['room_id'],
                           'session_name'=>$alloc['session_name'],'component_type'=>'Lab','program_id'=>$pid,
                           'department_id'=>(int)($alloc['department_id']??0),
                           'enrolled'=>$students,'is_lab'=>true,
                           'room_cap'=>(int)$room['capacity'],'room_type'=>$room['room_type'],
                           'room_equip'=>$room['equipment']??'None','course_equip'=>$ce,
                           'day'=>$day,'slot_is_lab'=>false,'theory_idx'=>0,
                           'credit_hours'=>$alloc['credit_hours']??'0-0-0','pair_slot'=>null];
                    $genes[]=array_merge($base,['slot_id'=>(int)$pair[0]['slot_id'],'is_lab_cont'=>false]);
                    $genes[]=array_merge($base,['slot_id'=>(int)$pair[1]['slot_id'],'is_lab_cont'=>true]);
                    foreach([0,1] as $pi){ ovMark($facBooked,$fid,$day,(int)$pair[$pi]['slot_id']); if($pid>0) ovMark($progBooked,$pid,$day,(int)$pair[$pi]['slot_id']); }
                    $roomBooked[$ds][(int)$room['room_id']]=true;
                }
            }
        }
        $chr[]=['alloc_id'=>$cid,'genes'=>$genes];
    }
    return $chr;
}

function flattenChr(array $chr): array {
    $g=[];
    foreach($chr as $grp) foreach($grp['genes'] as $gene) $g[]=$gene;
    return $g;
}

/**
 * TIME-only fitness (Opt-1 + Opt-5).
 * No per-student-ID loop — clash detection via batchSemGroups group-token.
 * No room-clash / room-type / capacity penalties — those are resolved by
 * Phase-2 global room assignment, so the GA optimises purely on TIME:
 * faculty clashes, student-group clashes, day-spread, slot-type, back-to-back.
 */
function fitness(array $genes, array $fmh, bool $lf, array $bsg=[], array $stm=[]): int {
    global $PTS;
    $pen=0; $bFac=[]; $fLoad=[]; $courseDays=[]; $courseSlots=[]; $groupDaySched=[];
    $courseToGroups=[];
    foreach($bsg as $gk=>$gcids) foreach($gcids as $gcid) $courseToGroups[$gcid][]=$gk;

    foreach($genes as $g){
        $day=$g['day']; $sid=$g['slot_id']; $fid=$g['faculty_id'];
        $pid=$g['program_id']; $cid=$g['course_id']; $cont=!empty($g['is_lab_cont']);
        $type=$g['component_type'];

        // Slot-type mismatch (lab in theory slot etc.) — a TIME concern
        $pen+=slotTypePenalty($g,$lf);

        if(!$cont){
            // Faculty clash
            $fclash=false;
            foreach(($PTS[$sid]??[$sid]) as $pt){ $kf="$day|$pt|$fid"; if(isset($bFac[$kf])) $fclash=true; else $bFac[$kf]=1; }
            if($fclash) $pen+=P_FACULTY_CLASH;
            // Faculty load
            $fLoad[$fid]=($fLoad[$fid]??0)+1;
            // Course slot tracking (for group + same-day checks)
            $courseSlots[$cid][]="$day|$sid";
            if(!empty($stm[$sid])) foreach($courseToGroups[$cid]??[] as $gk) $groupDaySched[$gk][$day][]=$stm[$sid];
        }

        // Theory same-day spread
        if(!$cont&&$type==='Theory'){
            $courseDays[$cid]['T'][]="$day|$sid";
            $days=array_map(fn($ds)=>explode('|',$ds)[0],$courseDays[$cid]['T']??[]);
            $dc=array_count_values($days);
            if(($dc[$day]??0)>1) $pen+=P_THEORY_SAME_DAY;
        }
        // Lab+theory same day
        if(!$cont&&$type==='Lab'){
            $courseDays[$cid]['L'][]=$day;
            foreach($courseDays[$cid]['T']??[] as $tds) if(explode('|',$tds)[0]===$day){$pen+=P_LAB_THEORY_SAME_DAY;break;}
        }
        if(!$cont&&$type==='Theory'){
            foreach($courseDays[$cid]['L']??[] as $ld) if($ld===$day){$pen+=P_LAB_THEORY_SAME_DAY;break;}
        }
    }

    // Opt-1: batch+semester group clash (replaces per-student loop)
    foreach($bsg as $gcids){
        $seen=[];
        foreach($gcids as $gcid) foreach($courseSlots[$gcid]??[] as $ds){
            [$sd,$ss]=explode('|',$ds); $hit=false;
            foreach(($PTS[(int)$ss]??[(int)$ss]) as $pt){ $kk="$sd|$pt"; if(isset($seen[$kk])) $hit=true; else $seen[$kk]=$gcid; }
            if($hit) $pen+=P_HARD;
        }
    }

    // Back-to-back penalty
    foreach($groupDaySched as $dayMap) foreach($dayMap as $daySlots){
        if(count($daySlots)<3) continue;
        usort($daySlots,fn($a,$b)=>strcmp($a['start'],$b['start']));
        $chain=1;
        for($i=1;$i<count($daySlots);$i++){
            $gap=(strtotime('2000-01-01 '.$daySlots[$i]['start'])-strtotime('2000-01-01 '.$daySlots[$i-1]['end']))/60;
            if($gap<=10){$chain++;if($chain>=3)$pen+=P_BACKTOBACK;}else $chain=1;
        }
    }

    // Faculty overload
    foreach($fLoad as $fid=>$load) if($load>($fmh[$fid]??20)) $pen+=P_OVERLOAD*($load-($fmh[$fid]??20));

    return $pen;
}

// Group-level crossover. Rooms are NOT re-picked here — Phase-2 global room
// assignment reassigns every room afterward, so crossover only needs to splice
// the time-slot decisions. (This removes the old per-gene pickRoom bottleneck.)
function xover(array $pa, array $pb, array $rooms): array {
    $n=count($pa); if($n<=1) return $pa;
    $pt=mt_rand(1,$n-1);
    $child=array_merge(array_slice($pa,0,$pt),array_slice($pb,$pt));
    // Safety: a child must contain every course exactly once (same order as the parents), otherwise keep parent A.
    if(count($child)!==$n) return $pa;
    for($i=0;$i<$n;$i++) if($child[$i]['alloc_id']!==$pa[$i]['alloc_id']) return $pa;
    return $child;
}

function mutate(array $chr,array $allocs,array $rooms,array $ts,array $ls,array $cp,array $days,bool $lf,array $cg=[]): array {
    $idx=array_rand($chr); $cid=$chr[$idx]['alloc_id']; $alloc=null;
    foreach($allocs as $a) if((int)$a['course_id']===(int)$cid){$alloc=$a;break;}
    if(!$alloc) return $chr;
    $rebuilt=makeGreedyChromosome([$alloc],$rooms,$ts,$ls,$cp,$days,$lf,[],[],[],$cg);
    if(!empty($rebuilt)) $chr[$idx]=$rebuilt[0];
    return $chr;
}

function tournSel(array $pop,array $fc,int $k): array {
    $keys=array_rand($pop,min($k,count($pop)));
    if(!is_array($keys)) $keys=[$keys];
    $bk=$keys[0]; $bs=$fc[$keys[0]];
    foreach($keys as $k2) if($fc[$k2]<$bs){$bs=$fc[$k2];$bk=$k2;}
    return $pop[$bk];
}

function repairChromosome(array $chr,array $allocs,array $rooms,array $ts,array $ls,array $cp,array $days,bool $lf,array $cg=[],array $bsg=[], float $startTime=0.0, float $budget=5.0): array {
    global $PTS;
    $am=[];
    foreach($allocs as $a) $am[(int)$a['course_id']]=$a;
    // course_id → which groups it belongs to (for group-clash detection)
    $courseToGroups=[];
    foreach($bsg as $gk=>$gcids) foreach($gcids as $gcid) $courseToGroups[$gcid][]=$gk;

    for($pass=0;$pass<3;$pass++){
        if($startTime>0 && (microtime(true)-$startTime)>=$budget) break;
        $fu=[]; $gu=[]; $conflicts=[];
        foreach($chr as $gi=>$grp) foreach($grp['genes'] as $g){
            if(!empty($g['is_lab_cont'])) continue;
            $day=$g['day']; $sid=$g['slot_id'];
            // Faculty clash (room clashes are resolved by Phase-2, ignored here)
            foreach(($PTS[$sid]??[$sid]) as $pt){
                $kf="$day|$pt|f{$g['faculty_id']}";
                if(isset($fu[$kf]) && $fu[$kf]!==$gi){$conflicts[$gi]=true;$conflicts[$fu[$kf]]=true;}else $fu[$kf]=$gi;
            }
            // Student-group clash: two courses of the same batch+sem+program group
            // sharing the same day+slot.
            foreach($courseToGroups[(int)$g['course_id']]??[] as $gk){
                foreach(($PTS[$sid]??[$sid]) as $pt){
                    $kg="$gk|$day|$pt";
                    if(isset($gu[$kg]) && $gu[$kg]!==$gi){$conflicts[$gi]=true;$conflicts[$gu[$kg]]=true;}
                    else $gu[$kg]=$gi;
                }
            }
        }
        if(empty($conflicts)) break;
        // Booking maps from CLEAN (non-conflicting) groups:
        //   $fb    = faculty busy slots
        //   $placed= course_id → [slot_id => true]  (lets the conflict graph
        //            block group-mate slots when rebuilding a single course)
        $fb=[]; $placed=[];
        foreach($chr as $gi=>$grp){
            if(isset($conflicts[$gi])) continue;
            foreach($grp['genes'] as $g){
                if(!empty($g['is_lab_cont'])) continue;
                ovMark($fb,(int)$g['faculty_id'],$g['day'],(int)$g['slot_id']);
                $placed[(int)$g['course_id']][(int)$g['slot_id']]=true;
            }
        }
        foreach(array_keys($conflicts) as $gi){
            $cid=$chr[$gi]['alloc_id']; $alloc=$am[$cid]??null; if(!$alloc) continue;
            $fid=(int)$alloc['faculty_id'];
            $freeT=[]; foreach($ts as $day=>$slots) foreach($slots as $sl){
                $ds="$day|{$sl['slot_id']}";
                if(!isset($fb[$fid][$ds])) $freeT[$day][]=$sl;
            }
            foreach($ts as $day=>$slots) if(empty($freeT[$day]??[])) $freeT[$day]=$slots;
            $freeL=[]; foreach($ls as $day=>$slots) foreach($slots as $sl){
                $ds="$day|{$sl['slot_id']}";
                if(!isset($fb[$fid][$ds])) $freeL[$day][]=$sl;
            }
            foreach($ls as $day=>$slots) if(empty($freeL[$day]??[])) $freeL[$day]=$slots;
            $built=makeGreedyChromosome([$alloc],$rooms,!empty($freeT)?$freeT:$ts,!empty($freeL)?$freeL:$ls,
                buildConsecutivePairs(!empty($freeT)?$freeT:$ts),$days,$lf,$fb,[],[],$cg);
            if(!empty($built)){
                $chr[$gi]=$built[0];
                foreach($built[0]['genes'] as $rg){
                    if(!empty($rg['is_lab_cont'])) continue;
                    ovMark($fb,(int)$rg['faculty_id'],$rg['day'],(int)$rg['slot_id']);
                    $placed[(int)$rg['course_id']][(int)$rg['slot_id']]=true;
                }
            }
        }
    }
    return $chr;
}

/**
 * Phase 2 (Opt-5): GLOBAL room assignment.
 * After the GA fixes every class's TIME (day+slot), this reassigns ALL rooms
 * from scratch in one pass that sees the whole timetable at once. Because it
 * processes one period (day+slot) at a time and never reuses a room within a
 * period, it GUARANTEES zero room clashes and correct room types — something
 * per-department partitioning cannot do on its own.
 *
 * Strategy per period:
 *   - biggest classes claim rooms first (closest-fit by capacity)
 *   - labs → Lab rooms, theory → Lecture Halls
 *   - equipment matched when possible, relaxed only if unavoidable
 */
function assignRoomsGlobally(PDO $pdo, array $classes): array {
    // Rooms split by type, each sorted by capacity ASC (closest-fit first)
    $halls=[]; $labs=[];
    foreach($pdo->query("SELECT room_id,capacity,room_type,COALESCE(equipment,'None') eq FROM rooms ORDER BY capacity ASC") as $r){
        if($r['room_type']==='Lab') $labs[]=$r; else $halls[]=$r;
    }
    $allRooms=array_merge($halls,$labs); $m=count($allRooms);
    // A required type only applies to a room type that owns it somewhere (e.g. PCs are not demanded of lecture halls).
    $present=['Lab'=>equipTypesInRooms(array_column($labs,'eq')),'Lecture Hall'=>equipTypesInRooms(array_column($halls,'eq'))];

    // All time slots, with their kind (lab slot = 160-210 min) and day
    $slotKind=[]; $slotDay=[]; $slotsOfKind=['Lab'=>[],'Theory'=>[]];
    foreach($pdo->query("SELECT slot_id,day,start_time,end_time FROM time_slots") as $s){
        $k=isLabSlot($s)?'Lab':'Theory'; $slotKind[(int)$s['slot_id']]=$k; $slotDay[(int)$s['slot_id']]=$s['day']; $slotsOfKind[$k][]=(int)$s['slot_id'];
    }

    // $classes (in memory): each = [slot_id, course_id, faculty_id, component_type, day, ce, course_code, enrolled]
    foreach($classes as &$cl){ $cl['slot_id']=(int)$cl['slot_id']; $cl['course_id']=(int)$cl['course_id']; $cl['faculty_id']=(int)$cl['faculty_id']; } unset($cl);

    // Student groups (batch + semester + program): classes of one group may never share a slot
    $groupsOfCourse=[]; $coursesOfGroup=[];
    foreach($pdo->query("SELECT e.course_id, e.batch_year, e.semester, COALESCE(s.program_id,0) pid FROM enrollments e JOIN students s ON s.student_id=e.student_id WHERE e.batch_year!='' AND e.semester!='' GROUP BY e.course_id,e.batch_year,e.semester,s.program_id") as $g){
        $key=$g['batch_year'].'|'.$g['semester'].'|'.$g['pid'];
        $groupsOfCourse[(int)$g['course_id']][$key]=true; $coursesOfGroup[$key][(int)$g['course_id']]=true;
    }

    // Solve the rooms of ONE time slot: Hungarian assignment of its classes to rooms.
    //   forbidden (HU_BIG): a lab class in a hall OR capacity too small OR equipment below the course's bare minimum
    //   cost = (0 ideal | 1000 bare-minimum) + wasted seats; theory in a Lab room = +100000 (fallback only)
    // returns [classIndex => roomIndex|null]
    $solve=function(array $idxs,array $forbid=[]) use (&$classes,$allRooms,$m,$present){
        $n=count($idxs); if($n===0) return [];
        $cost=[];
        foreach($idxs as $ci=>$k){
            $cl=$classes[$k]; $isLab=$cl['component_type']==='Lab';
            $need=max((int)$cl['enrolled'],1); $req=equipCourseReq($cl['ce']); $row=[];
            foreach($allRooms as $rix=>$r){
                $cap=(int)$r['capacity']; $isLabRoom=$r['room_type']==='Lab';
                if(isset($forbid[$rix])){ $row[]=HU_BIG; continue; }          // room already busy at an overlapping time
                if($isLab&&!$isLabRoom){ $row[]=HU_BIG; continue; }
                $lv=($cap>=$need)?equipLevel(equipEffectiveReq($req,$present[$isLab?'Lab':'Lecture Hall']??[]),equipRoomCounts($r['eq']),$need,$cap):0;
                if($lv===0){ $row[]=HU_BIG; continue; }
                $row[]=($lv===2?0:1000)+((!$isLab&&$isLabRoom)?100000:0)+min(999,$cap-$need);
            }
            for($q=$m;$q<$n;$q++) $row[]=HU_BIG;
            $cost[$ci]=$row;
        }
        $assign=hungarianMin($cost); $out=[];
        foreach($idxs as $ci=>$k){
            $ri=$assign[$ci]??null;
            $out[$k]=($ri!==null && $ri<$m && $cost[$ci][$ri]<HU_BIG)?$ri:null;
        }
        return $out;
    };

    // Time info per slot. Everything below judges "same time" by real OVERLAP ($OV), not by identical slot id.
    global $OV;
    $slotInfo=[]; foreach($pdo->query("SELECT slot_id,day,start_time,end_time FROM time_slots") as $s2) $slotInfo[(int)$s2['slot_id']]=$s2;
    $facAt=[]; $courseAt=[]; $courseDay=[]; $classesAt=[];
    foreach($classes as $k=>$cl){
        $facAt[$cl['faculty_id']][$cl['slot_id']]=true;
        $courseAt[$cl['course_id']][$cl['slot_id']]=true; $courseDay[$cl['course_id']][$cl['day']]=true;
        $classesAt[$cl['slot_id']][]=$k;
    }
    // Rooms: sweep through each day in time order. At every start instant, the classes starting then are matched to rooms
    // (Hungarian) while the rooms still held by earlier, still-running classes are forbidden. So no room is ever used twice
    // at overlapping times, and every class sees the whole picture of its instant.
    $roomOf=[];   // class index => room index (null = none)
    $byInstant=[];
    foreach($classes as $k=>$cl){ $si=$slotInfo[$cl['slot_id']]; $byInstant[$si['day']][$si['start_time']][]=$k; }
    foreach($byInstant as $day=>$inst){
        ksort($inst); $done=[];
        foreach($inst as $t=>$idxs){
            $forbid=[];
            foreach($done as $k2){
                $s2=$slotInfo[$classes[$k2]['slot_id']];
                if($s2['start_time']<$t && $s2['end_time']>$t && ($roomOf[$k2]??null)!==null) $forbid[$roomOf[$k2]]=true;
            }
            foreach($solve($idxs,$forbid) as $k=>$ri) $roomOf[$k]=$ri;
            foreach($idxs as $k) $done[]=$k;
        }
    }

    // ── REPAIR: a class with no valid room is moved to another slot of the same kind ──
    // needs: teacher free and none of its student groups busy at any OVERLAPPING time, and a valid room free during that time.
    $moved=0;
    $unplacedIdx=array_keys(array_filter($roomOf,fn($v)=>$v===null));
    foreach($unplacedIdx as $u){
        $cl=$classes[$u]; $kind=$cl['component_type']==='Lab'?'Lab':'Theory';
        $cands=$slotsOfKind[$kind]; usort($cands,fn($x,$y)=>count($classesAt[$x]??[])<=>count($classesAt[$y]??[]));
        foreach([true,false] as $strictDay){                       // first try days where this course has no other class
            foreach($cands as $S){
                if($S===$cl['slot_id']) continue;
                $bad=false;
                foreach($OV[$S] as $o){
                    if(isset($facAt[$cl['faculty_id']][$o]) || isset($courseAt[$cl['course_id']][$o])){ $bad=true; break; }
                }
                if($bad) continue;
                if($strictDay && isset($courseDay[$cl['course_id']][$slotDay[$S]])) continue;
                foreach(array_keys($groupsOfCourse[$cl['course_id']]??[]) as $g){
                    foreach(array_keys($coursesOfGroup[$g]) as $c2){ if($c2===$cl['course_id']) continue; foreach($OV[$S] as $o) if(isset($courseAt[$c2][$o])){ $bad=true; break 3; } }
                }
                if($bad) continue;
                $forbid=[];
                foreach($OV[$S] as $o) foreach($classesAt[$o]??[] as $k2) if($k2!==$u && ($roomOf[$k2]??null)!==null) $forbid[$roomOf[$k2]]=true;
                $res=$solve([$u],$forbid);
                if(($res[$u]??null)===null) continue;
                // accept the move
                $old=$cl['slot_id'];
                $classesAt[$old]=array_values(array_diff($classesAt[$old]??[],[$u]));
                unset($facAt[$cl['faculty_id']][$old],$courseAt[$cl['course_id']][$old]);
                $classesAt[$S][]=$u; $facAt[$cl['faculty_id']][$S]=true; $courseAt[$cl['course_id']][$S]=true; $courseDay[$cl['course_id']][$slotDay[$S]]=true;
                $classes[$u]['slot_id']=$S; $classes[$u]['day']=$slotDay[$S];
                $roomOf[$u]=$res[$u];
                $moved++; break 2;
            }
        }
    }

    // ── result: nothing is written here; the caller inserts only the classes that got a valid room ──
    $unplaced=0; $unplacedList=[]; $fallback=0; $final=[];
    foreach($classes as $k=>$cl){
        $ri=$roomOf[$k]??null;
        if($ri!==null){
            $cl['room_id']=(int)$allRooms[$ri]['room_id'];
            if($cl['component_type']!=='Lab' && $allRooms[$ri]['room_type']==='Lab') $fallback++;
            $final[]=$cl;
        } else { $unplaced++; $unplacedList[]=$cl; }
    }
    return ['placed'=>$final,'unplaced'=>$unplaced,'list'=>$unplacedList,'labFallback'=>$fallback,'moved'=>$moved];
}

/**
 * Final safety net. Whatever the algorithm did, no class is stored if it overlaps in time with an already accepted class
 * that uses the same room, the same teacher, or shares even one enrolled student. Such a class is returned as "dropped"
 * (it then appears in the unplaced list instead of silently being double-booked).
 */
function auditOverlaps(PDO $pdo, array $placed): array {
    if(!$placed) return ['ok'=>[],'dropped'=>[]];
    $slot=[]; foreach($pdo->query("SELECT slot_id,day,start_time,end_time FROM time_slots") as $s) $slot[(int)$s['slot_id']]=$s;
    $cids=array_unique(array_map(fn($c)=>(int)$c['course_id'],$placed)); $stu=[];
    foreach($pdo->query("SELECT course_id,student_id FROM enrollments WHERE course_id IN (".implode(',',$cids).")") as $r) $stu[(int)$r['course_id']][(int)$r['student_id']]=true;
    $ok=[]; $dropped=[]; $byDay=[];
    foreach($placed as $cl){
        $si=$slot[(int)$cl['slot_id']]; $bad=false;
        foreach($byDay[$si['day']]??[] as $o){
            $so=$slot[(int)$o['slot_id']];
            if(!($si['start_time']<$so['end_time'] && $so['start_time']<$si['end_time'])) continue;
            if((int)$o['room_id']===(int)$cl['room_id'] || (int)$o['faculty_id']===(int)$cl['faculty_id']){ $bad=true; break; }
            $a=$stu[(int)$cl['course_id']]??[]; $b=$stu[(int)$o['course_id']]??[]; if(count($a)>count($b)) [$a,$b]=[$b,$a];
            foreach($a as $sid=>$_) if(isset($b[$sid])){ $bad=true; break 2; }
        }
        if($bad) $dropped[]=$cl; else { $ok[]=$cl; $byDay[$si['day']][]=$cl; }
    }
    return ['ok'=>$ok,'dropped'=>$dropped];
}

// ═══════════════════════════════════════════════════════════════════
// MAIN EXECUTION
// ═══════════════════════════════════════════════════════════════════
try {
    // ── Load shared resources ───────────────────────────────────────
    jobProgress($pdo, $jobId, 'Loading allocations...', 0, 0);

    // Opt-1: no student_ids loaded onto allocs — only enrolled_students count
    $st=$pdo->prepare("
        SELECT a.allocation_id,a.faculty_id,a.course_id,a.session_name,
               c.course_name,c.course_code,c.credit_hours,c.requires_equipment,
               c.department_id,c.program_id,
               d.department_name,
               f.faculty_name,f.max_hours_per_week,
               (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e WHERE e.course_id=c.course_id) AS enrolled_students
        FROM allocations a
        JOIN courses c     ON a.course_id=c.course_id
        JOIN faculty f     ON a.faculty_id=f.faculty_id
        JOIN departments d ON c.department_id=d.department_id
        WHERE a.session_name=?
          AND EXISTS (SELECT 1 FROM enrollments e2 WHERE e2.course_id=c.course_id)
        ORDER BY c.department_id ASC, c.course_id ASC");
    $st->execute([$session]);
    $allAllocs=$st->fetchAll(PDO::FETCH_ASSOC);

    if(empty($allAllocs)){
        $pdo->prepare("UPDATE ga_jobs SET status='failed',progress='No allocated courses with enrolled students found.' WHERE job_id=?")
            ->execute([$jobId]); exit(0);
    }

    // ── Batch+semester+PROGRAM group mapping (Opt-1: drives clash detection) ─
    // A scheduling "group" = one cohort = (batch_year, semester, program_id),
    // where program_id is the STUDENT'S program (a student's real schedule).
    //
    // We key on the student's program (not the course's): a shared course taken
    // by students of several programs belongs to EACH of those program groups,
    // so it must not clash with any of those cohorts' other courses. Grouping by
    // the course's program would miss these and leave real student clashes.
    //
    // The students JOIN runs once at startup (~100ms) — correctness > micro-opt.
    $courseIds=implode(',',array_map('intval',array_column($allAllocs,'course_id')));
    $bsg=[];
    $bsRows=$pdo->query("SELECT e.course_id, e.batch_year, e.semester, COALESCE(s.program_id,0) AS program_id
        FROM enrollments e
        JOIN students s ON e.student_id = s.student_id
        WHERE e.course_id IN ($courseIds) AND e.batch_year!='' AND e.semester!=''
        GROUP BY e.course_id, e.batch_year, e.semester, s.program_id");
    while($bs=$bsRows->fetch(PDO::FETCH_ASSOC)){
        $key=$bs['batch_year'].'|'.$bs['semester'].'|'.$bs['program_id'];
        $bsg[$key][]=(int)$bs['course_id'];
    }
    foreach($bsg as &$gc) $gc=array_values(array_unique($gc)); unset($gc);

    // ── Rooms ────────────────────────────────────────────────────────
    $rooms=$pdo->query("SELECT room_id,room_number,floor_number,capacity,room_type,COALESCE(equipment,'None') AS equipment FROM rooms ORDER BY capacity ASC")->fetchAll(PDO::FETCH_ASSOC);

    // ── Time slots ───────────────────────────────────────────────────
    $allSlots=$pdo->query("SELECT slot_id,day,start_time,end_time,TIMESTAMPDIFF(MINUTE,start_time,end_time) AS duration_mins FROM time_slots ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday'),start_time")->fetchAll(PDO::FETCH_ASSOC);
    $theorySlots=[]; $labSlots=[]; $allDays=[];
    foreach($allSlots as $sl){
        $day=$sl['day']; $m=(int)$sl['duration_mins']; $allDays[]=$day;
        if($m>=80&&$m<=130)  $theorySlots[$day][]=$sl;
        if($m>=150&&$m<=210) $labSlots[$day][]=$sl;
    }
    $allDays=array_values(array_unique($allDays));
    [$OV,$PTS]=buildOverlap($allSlots);          // time-overlap tables used by fitness, builder, repair and room assignment
    $consecutivePairs=buildConsecutivePairs($theorySlots);
    $labFallback=empty($labSlots);
    $slotTimeMap=[];
    foreach($allSlots as $sl) $slotTimeMap[(int)$sl['slot_id']]=['start'=>$sl['start_time'],'end'=>$sl['end_time']];

    // ── Faculty max hours ────────────────────────────────────────────
    $fmh=[];
    foreach($allAllocs as $a) $fmh[(int)$a['faculty_id']]=(int)($a['max_hours_per_week']??20);

    // ── Opt-7: conflict graph ────────────────────────────────────────
    jobProgress($pdo,$jobId,'Building conflict graph...',0,0);
    $conflictGraph=buildConflictGraph($allAllocs,$bsg);

    // ── Create timetable run ─────────────────────────────────────────
    $runLabel=$session.' #'.time();
    $pdo->prepare("INSERT INTO timetable_runs (base_session,run_label,fitness_score) VALUES (?,?,0)")
        ->execute([$session,$runLabel]);
    $runId=(int)$pdo->lastInsertId();

    $ins=$pdo->prepare("INSERT INTO generated_timetable (run_id,session_name,course_id,faculty_id,room_id,slot_id,component_type,display_label) VALUES (?,?,?,?,?,?,?,?)");

    // ── GLOBAL GA (no partitioning) ──────────────────────────────────
    // The whole session is scheduled in ONE GA over all allocations. This is
    // both faster (the greedy builder + hash-set bookings are cheap) and MORE
    // correct than per-department partitioning: cross-department shared courses
    // (a course taken by students of a program in another dept) keep their group
    // constraints intact instead of being split across independent dept runs.
    $totalAllocs = count($allAllocs);
    // Wall-clock budget for the whole GA. Extended to 120s to give the evolution
    // more time to converge on zero conflicts. The loop exits early if it hits
    // zero, so this is a ceiling, not a guarantee. Background execution means
    // the browser isn't blocked regardless of duration.
    $GA_BUDGET   = 120.0;
    $runStart    = microtime(true);

    jobProgress($pdo,$jobId,"Scheduling $totalAllocs courses...",0,1);

    // Initial population — sample a LARGER pool of greedy builds and keep the
    // best $dynPop. Greedy builds are cheap (~100ms) and the conflict-graph
    // guidance makes them quite good, so best-of-many gives a reliably strong
    // starting point (low variance) before evolution polishes it.
    $dynPop  = $totalAllocs>400 ? 8 : ($totalAllocs>150 ? 10 : 14);
    $sample  = max($dynPop, 24);
    $cand=[]; $candF=[];
    for($i=0;$i<$sample;$i++){
        $sh=$allAllocs; shuffle($sh);
        $c=makeGreedyChromosome($sh,$rooms,$theorySlots,$labSlots,$consecutivePairs,$allDays,$labFallback,[],[],[],$conflictGraph);
        // Every chromosome must list the courses in the SAME order, otherwise crossover would duplicate some courses and drop others.
        usort($c,fn($x,$y)=>((int)$x['alloc_id'])<=>((int)$y['alloc_id']));
        $cand[]=$c; $candF[]=fitness(flattenChr($c),$fmh,$labFallback,$bsg,$slotTimeMap);
    }
    array_multisort($candF,SORT_ASC,SORT_NUMERIC,$cand);
    $pop=array_slice($cand,0,$dynPop); $fc=array_slice($candF,0,$dynPop);
    $best=$pop[0]; $bestScore=$fc[0];
    $noImprove=0;

    // Evolution — runs until the time budget or zero conflicts. dynGens is huge;
    // the wall-clock budget is the real limiter (every generation is cheap).
    for($gen=0;$gen<100000;$gen++){
        if((microtime(true)-$runStart)>=$GA_BUDGET || $bestScore===0) break;
        $minScore=min($fc); $minIdx=(int)array_search($minScore,$fc);
        if($minScore<$bestScore){$bestScore=$minScore;$best=$pop[$minIdx];$noImprove=0;}else $noImprove++;
        $mutRate=GA_MUT+($noImprove>30?0.20:0);
        array_multisort($fc,SORT_ASC,SORT_NUMERIC,$pop); $fc=array_values($fc); $pop=array_values($pop);
        $np=array_slice($pop,0,GA_ELITE); $nf=array_slice($fc,0,GA_ELITE);
        while(count($np)<$dynPop){
            $pa=tournSel($pop,$fc,GA_TOURN); $pb=tournSel($pop,$fc,GA_TOURN);
            $ch=xover($pa,$pb,$rooms);
            if((mt_rand(1,100)/100)<=$mutRate) $ch=mutate($ch,$allAllocs,$rooms,$theorySlots,$labSlots,$consecutivePairs,$allDays,$labFallback,$conflictGraph);
            $np[]=$ch; $nf[]=fitness(flattenChr($ch),$fmh,$labFallback,$bsg,$slotTimeMap);
        }
        $pop=$np; $fc=$nf;
        if($gen%50===0) jobProgress($pdo,$jobId,"Optimising… (best score $bestScore)",0,1);
    }

    // Repair pass 1: general repair (may help or hurt, so we test it)
    jobProgress($pdo,$jobId,'Repairing residual conflicts...',0,1);
    $preFitness=fitness(flattenChr($best),$fmh,$labFallback,$bsg,$slotTimeMap);
    $repairStart=microtime(true);
    $repaired=repairChromosome($best,$allAllocs,$rooms,$theorySlots,$labSlots,$consecutivePairs,$allDays,$labFallback,$conflictGraph,$bsg,$repairStart,20.0);
    $repFitness=fitness(flattenChr($repaired),$fmh,$labFallback,$bsg,$slotTimeMap);
    if($repFitness<$preFitness){ $best=$repaired; $finalFitness=$repFitness; }
    else { $finalFitness=$preFitness; }

    // Repair pass 2: faculty-specific. A teacher may not have two classes at OVERLAPPING times: move the later one.
    $flat=flattenChr($best);
    $facDay=[];                                   // faculty => day => [slot ids already used]
    $moveDone=false;
    foreach($flat as $i=>$g){
        if(!empty($g['is_lab_cont'])||empty($g['slot_id'])) continue;
        $fid=(int)$g['faculty_id']; $day=$g['day']; $sid=(int)$g['slot_id'];
        $clash=false; foreach($OV[$sid]??[$sid] as $o) if(in_array($o,$facDay[$fid][$day]??[],true)){ $clash=true; break; }
        if($clash){
            $slots=!empty($g['is_lab'])?$labSlots:$theorySlots; $moved2=false;
            foreach($slots as $d2=>$slotList) foreach($slotList as $sl){
                $s2=(int)$sl['slot_id']; $free=true;
                foreach($OV[$s2] as $o) if(in_array($o,$facDay[$fid][$d2]??[],true)){ $free=false; break; }
                if(!$free) continue;
                $flat[$i]['day']=$d2; $flat[$i]['slot_id']=$s2; $day=$d2; $sid=$s2; $moved2=true; $moveDone=true; break 2;
            }
        }
        $facDay[$fid][$day][]=$sid;
    }
    if($moveDone){
        $newFit=fitness($flat,$fmh,$labFallback,$bsg,$slotTimeMap);
        if($newFit<$finalFitness){
            $seenKey=[];
            foreach($best as &$grp) foreach($grp['genes'] as &$gene){
                foreach($flat as $fi=>$fg){
                    if(isset($seenKey[$fi])) continue;
                    if($fg['course_id']===$gene['course_id']&&$fg['component_type']===$gene['component_type']&&($fg['theory_idx']??0)===($gene['theory_idx']??0)&&empty($fg['is_lab_cont'])==empty($gene['is_lab_cont'])){
                        $gene['day']=$fg['day']; $gene['slot_id']=$fg['slot_id']; $seenKey[$fi]=true; break;
                    }
                }
            }
            unset($grp,$gene);
            $finalFitness=$newFit;
        }
    }

    // Phase 2 first: global room assignment on the in-memory timetable (nothing is stored with a placeholder room)
    jobProgress($pdo, $jobId, 'Assigning rooms globally...', 1, 1);
    $allocMap=[]; foreach($allAllocs as $al) $allocMap[(int)$al['course_id']]=$al;
    $inMem=[];
    foreach(flattenChr($best) as $g){
        if(empty($g['slot_id'])||!empty($g['is_lab_cont'])) continue;
        $al=$allocMap[(int)$g['course_id']]??[];
        $inMem[]=['slot_id'=>$g['slot_id'],'course_id'=>$g['course_id'],'faculty_id'=>$g['faculty_id'],'component_type'=>$g['component_type'],
                  'day'=>$g['day'],'ce'=>$g['course_equip']??'None','course_code'=>$al['course_code']??'','enrolled'=>$g['enrolled']??1,'display_label'=>$g['display_label']??null];
    }
    $roomResult=assignRoomsGlobally($pdo,$inMem);
    $aud=auditOverlaps($pdo,$roomResult['placed']);                 // last safety net: no overlap clash is ever stored
    $roomResult['placed']=$aud['ok'];
    foreach($aud['dropped'] as $dr){ $roomResult['list'][]=$dr; $roomResult['unplaced']++; }
    $unplacedAll=$roomResult['list'];
    $pdo->beginTransaction();
    $totalSaved=0;
    foreach($roomResult['placed'] as $cl){
        try{
            $ins->execute([$runId,$runLabel,$cl['course_id'],$cl['faculty_id'],$cl['room_id'],$cl['slot_id'],$cl['component_type'],$cl['display_label']??null]);
            $totalSaved++;
        } catch(PDOException $e){ $unplacedAll[]=$cl; $roomResult['unplaced']++; }   // the database refused a clash
    }
    $pdo->commit();
    $roomResult['moved']=$roomResult['moved']??0;
    try{
        $hasCol=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='timetable_runs' AND COLUMN_NAME='unplaced_json'")->fetchColumn();
        if(!$hasCol) $pdo->exec("ALTER TABLE timetable_runs ADD COLUMN unplaced_json TEXT NULL");
        $uj=array_map(fn($u)=>['course_id'=>$u['course_id'],'course_code'=>$u['course_code'],'component'=>$u['component_type'],'slot_id'=>$u['slot_id'],'day'=>$u['day']],$unplacedAll);
        $pdo->prepare("UPDATE timetable_runs SET unplaced_json=? WHERE run_id=?")->execute([$uj?json_encode($uj):null,$runId]);
    } catch(Exception $e){}
    $roomResult['list']=array_map(fn($u)=>$u['course_code'].' '.$u['day'].' slot'.$u['slot_id'],$unplacedAll);

    // Update run record
    $pdo->prepare("UPDATE timetable_runs SET fitness_score=?,classes_count=? WHERE run_id=?")
        ->execute([$finalFitness,$totalSaved,$runId]);

    $status=$finalFitness===0?'PERFECT — Zero conflicts!':'Best solution found (some soft constraints remain)';
    $msg="Done — $totalSaved classes. $status";
    if(!empty($roomResult['unplaced'])) $msg="Done — $totalSaved classes, but NOT READY: ".$roomResult['unplaced']." class(es) have no room meeting type/capacity/equipment (do not publish). Add time slots (see Slots tab hint). First: ".implode('; ',array_slice($roomResult['list'],0,6));
    if(empty($roomResult['unplaced'])&&!empty($roomResult['labFallback'])) $msg.=" Note: ".$roomResult['labFallback']." theory class(es) use a lab room (fallback; add theory slots to avoid).";
    if(!empty($roomResult['moved'])) $msg.=" ".$roomResult['moved']." class(es) were moved to another slot to find a room.";
    $msg=mb_substr($msg,0,250);
    $pdo->prepare("UPDATE ga_jobs SET status='complete',progress=?,dept_done=1,dept_total=1,fitness_score=?,classes_count=?,run_id=?,run_label=?,updated_at=NOW() WHERE job_id=?")
        ->execute([$msg,$finalFitness,$totalSaved,$runId,$runLabel,$jobId]);

} catch(Exception $e){
    if(isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    $pdo->prepare("UPDATE ga_jobs SET status='failed',progress=?,updated_at=NOW() WHERE job_id=?")
        ->execute([mb_substr('Error: '.$e->getMessage(),0,250),$jobId]);
}