# Remotify / SlotSmart – MPI (PDC) and ADBS notes

## 1. MPI engine (PDC)
`ga_mpi.py` = island-model genetic algorithm with mpi4py. Each MPI process (rank) evolves its own population; the best
timetables are passed around a ring (`sendrecv`) every few generations; `allreduce` finds the best score and `bcast` stops
everybody; room assignment (Hungarian per time slot) is split across ranks (`scatter` / `gather`).
Rooms are checked WHILE times are chosen (matching class -> room per slot), so a class can no longer end as a "placeholder".
`ga_handler.php` picks the engine: MPI if enabled in `mpi_config.json` and Python + mpi4py + pymysql + mpiexec work,
otherwise it silently uses the PHP engine (`ga_worker.php`), which has the same rules.

### Windows setup (once)
1. Install MS-MPI (runtime `msmpisetup.exe` AND SDK `msmpisdk.msi`) from Microsoft.
2. Install Python 3 (tick "Add to PATH"), then:  `pip install mpi4py pymysql`
3. Check in cmd:  `mpiexec -n 2 python -c "print(1)"`  (must print 1 twice)
4. If `python` / `mpiexec` are not on PATH, write their full paths in `mpi_config.json`.
5. Re-import `slotsmart.sql` (new columns/keys; this wipes the DB), then Generate as usual.

### Measured (large set, deliberately tight: 3 theory + 2 lab slots/day, 720 classes, 120 s cap, container with only 2 CPU cores)
| ranks | run 1 | run 2 | run 3 |
|---|---|---|---|
| 1 | 53.7 s | 59.8 s | 40.2 s |
| 2 | 21.6 s | 120.3 s (cap, one soft back-to-back left) | 25.9 s |
| 4 | 32.1 s | 42.3 s | 51.4 s |
All runs: 720 placed, 0 unplaced. The GA is random, so times vary; 4 ranks on 2 cores are oversubscribed (not a fair speed test).
Normal-size sets (small/medium/large with enough slots) finish in 0.2 - 2 s.

## 2. ADBS items
* DB-enforced clash keys on `generated_timetable`: `UNIQUE(run_id, slot_id, room_id)` and `UNIQUE(run_id, slot_id, faculty_id)`.
  Even a buggy page cannot double-book a room or teacher inside one timetable version.
* Index `enrollments(course_id, student_id)`; run `php adbs_explain.php` for EXPLAIN + timings before/after
  (large set: students-of-a-course 0.20 -> 0.10 ms, room/slot clash check 0.15 -> 0.07 ms; other queries I tried
  gained nothing, so I did not add indexes for them).
* Transactions: edit / swap / publish run in one transaction with `SELECT ... FOR UPDATE` row locks.
* Optimistic locking: `generated_timetable.version`; the edit dialog sends `expected_version`; if someone else changed the
  class meanwhile the save is refused.
* Swap = delete + re-insert in one transaction; any clash rolls everything back.
* `timetable_runs.unplaced_json`: classes the generator could not place (never given a wrong room). Publishing is refused
  while any exist; they show in the "unscheduled" list.
* Manual edits/swaps now also refuse a room with too few seats (before: only equipment was checked).

## 3. Round 2 fixes (time overlap, published-only API)
* **Time overlap.** Slots may overlap in time (a long lab slot overlaps theory slots). Before, both engines treated only the SAME
  slot id as "same time", so a teacher or student could be double-booked across overlapping slots while the result still said
  "PERFECT". Now both engines (MPI and PHP) judge by real overlap: a "time point" (= start instant) is shared by exactly the
  slots that overlap. Rooms are assigned by a time-ordered sweep. A final audit drops (and lists as unplaced) any class that would
  clash by room, teacher or shared student. Manual edit / swap / place-class use the same check (`clash_check.php`).
* **Slot hint** counts only slots of one type that can really be used at once (overlapping ones are merged) and says so.
* **api.php** is now a read-only feed for the Remotify dashboard: `?action=sessions` and `?action=timetable[&session=][&faculty_id=][&student_id=][&room_id=][&course_id=][&day=]`.
  It returns only the PUBLISHED timetable. The old mobile login / token code was removed (the `api_token` columns stay in the tables, unused).
* **Same-day rule in manual edits.** Moving or swapping a class to another slot (or placing one by hand) is refused if the course would get two theory
  classes on one day, or a lab on a theory day ("Day rule: ..."). It keeps the week non-burdening, like the generator does.
* `ga_handler.php` and `ga_status.php` accept only a logged-in admin session.
* **PHP crossover bug** fixed (it could duplicate one course and drop another once the GA really had to evolve).
* `verify_timetable.php` = independent overlap-aware checker: `php verify_timetable.php`.
