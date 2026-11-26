# Handoff: equipment-aware scheduling (SlotSmart / Remotify, phase 1)

Paste this into Claude Code (or let it read this file) and continue from "To do".

## Decisions already made (do not re-ask)
- No new DB tables. Equipment lives in existing columns, stored as JSON:
  - `rooms.equipment` = `{"AC 1":1,"AC 2":0,"PC 1":1}` (1 working, 0 faulty, one key per physical item; type = name without trailing number).
  - `courses.requires_equipment` = `["PC","AC","Light"]` (names only; 'None' = nothing). Old text formats ("Projector,Computers") are NOT supported any more.
- Needs are computed, never hardcoded per type (equipment.php + equipment_config.json):
  - ideal = ceil(N * (b + (1-b) * S/C)), N installed items, S enrolled students, C room capacity, b base share (default 0).
  - bare minimum = max(1, ceil(ideal * min_fraction)); whole items only. min_fraction: pc 0.75 (laptop allowance), ac 0.5, fan 0.6, light 0.6, default 0.6.
  - Room below the minimum for any required type = never assigned. A required type only applies to a room type that owns it somewhere (PCs are not demanded of lecture halls).
- Phase 1 of the genetic algorithm (times) is unchanged. Phase 2 (rooms, `assignRoomsGlobally` in ga_worker.php) builds a class x room cost table per period (forbidden / ideal 0 / minimum 1000 / wrong type +100000 / wasted seats) and solves it with the Hungarian algorithm.
- "Published" = `timetable_runs.published_at`. Publish is refused while a class sits in a room below its equipment minimum or a room is booked twice at once. Edits to a published run need a reason and are appended to `generated_timetable.change_log` (JSON).
- admin_handler.php now requires a logged-in session (it had no check).
- Sensors are NOT built. Later they only write 1/0 into the same per-item JSON.
- The "Energy plan" idea was dropped on purpose.

## To do / verify (nothing below was tested against MySQL or in a browser)
1. Import slotsmart.sql (DROPS the database; back up first), log in as admin@slotsmart.com, enter rooms with items, courses with equipment names, import the sample CSVs in assets/csvs.
2. Generate a timetable, check the job message (WARNING lists classes with no valid room), publish, edit a class on a published run (reason prompt), try a room below the minimum.
3. (Done) A class with no valid room is never given a wrong room: it goes to the unplaced list and publishing is blocked. The Slots tab shows a hint on how many slots are needed.
4. (Done) api.php is a read-only feed of the PUBLISHED timetable for the Remotify dashboard (no login, no mobile app).
5. Not built: automatic list of classes affected by a newly faulty item, with suggested replacement rooms/times.
