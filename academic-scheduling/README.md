# Academic Timetable Scheduling (Phase 1)

This module is Phase 1 of the final-year project **[Remotify](../README.md)**, an intelligent, automated IT-resource management and scheduling system. It builds clash-free university timetables with a Genetic Algorithm (GA) and gives every class a room that has enough seats and the equipment the course needs.

> **Started:** November 2025 (earliest project files).
> **Current stage:** Phase 1 (academic scheduling) — reliable, room- and equipment-aware timetable generation (testing).
> **Next:** Phase 2 — IT-resource management (Firebase, PC client, sensors).

---

## Table of Contents
1. [What it does](#what-it-does)
2. [Architecture](#architecture)
3. [Timetable rules](#timetable-rules)
4. [Requirements](#requirements)
5. [Setup](#setup)
6. [Generating and checking a timetable](#generating-and-checking-a-timetable)
7. [API for the Remotify dashboard](#api-for-the-remotify-dashboard)
8. [Project structure](#project-structure)
9. [Timeline](#timeline)
10. [Known limitations](#known-limitations)
11. [Roadmap](#roadmap)

---

## What it does
- Imports departments, programs, courses, faculty, students, rooms, time slots, allocations and enrollments (CSV).
- Generates a timetable in the background and shows live progress.
- Assigns rooms by capacity **and** equipment (lights, fans, ACs, PCs, LED). Quantities are computed from the number of enrolled students; nothing is hardcoded per room type.
- Never gives a class a wrong room. A class that cannot be placed goes to an **unplaced list**, and publishing is blocked until the list is empty.
- Admin can edit, swap or manually place classes. Every change is checked for clashes and recorded in a change log with a reason once a timetable is **published**.
- Hints how many extra time slots are needed when classes outnumber rooms.

## Architecture

| Layer | Technology | Job |
|---|---|---|
| Web app | PHP 7.4+, MySQL/MariaDB, Bootstrap, jQuery, DataTables | Admin pages, CRUD, import, edit/swap, publish, API |
| Main engine | Python + `mpi4py` (MPI) — `ga_mpi.py` | Parallel GA (island model) and room assignment |
| Fallback engine | PHP — `ga_worker.php` | Used automatically when Python/MPI is unavailable |

`ga_handler.php` starts a job, picks the engine from `mpi_config.json`, and the page polls `ga_status.php` for progress.

**MPI engine:** each process runs its own population (island). Islands pass their best timetable around a ring, share a stop signal, and split the final room assignment by day. Only rank 0 writes to the database.

**Database safety (ADBS):** unique keys on (run, slot, room) and (run, slot, teacher); transactions with row locks for edits and swaps; optimistic `version` check so two admins cannot overwrite each other. See [`docs/MPI_ADBS_NOTES.md`](docs/MPI_ADBS_NOTES.md).

## Timetable rules
- No room, teacher, student or program/department clash. Clashes are judged by **real time overlap**, not only identical slot ids.
- Room seats ≥ enrolled students; a lab class only in a lab room; a theory class may use a lab room only as a costed fallback.
- A room below the minimum equipment is never assigned. Equipment levels: ideal / minimum / below minimum.
- Non-burdening week: a course's theory classes are on different days, and its lab is not on a theory day. Manual edits enforce this too.
- Slot uniqueness is day + start time + end time.

## Requirements
- PHP 7.4+ with PDO MySQL, MySQL/MariaDB (default port **3308** in `db.php`), WAMP/XAMPP or similar.
- For the MPI engine (optional): Python 3, `pip install mpi4py pymysql`, and MS-MPI (Windows) or OpenMPI (Linux).

## Setup
1. Copy the project into your web root (for example `www/academic-scheduling`).
2. Edit `db.php` if your MySQL host, port or password differ.
3. Import `slotsmart.sql` (this **drops and recreates** the database — back up first).
4. Open the site and sign in with the default admin: `admin@slotsmart.com` / `admin123`. **Change this password after first login.**
5. Import CSVs from `assets/csvs/` (`*_small`, `*_medium`, `*_large` sets are provided for testing).
6. Optional MPI settings in `mpi_config.json`:
   ```json
   { "enabled": true, "processes": 4, "seconds": 120, "python": "python", "mpiexec": "mpiexec" }
   ```
   Set `"enabled": false` to use the PHP engine only.

## Generating and checking a timetable
1. Admin → generate a timetable for a session. The job message shows which engine ran.
2. Check the result from a terminal in the project folder:
   ```
   php verify_timetable.php
   ```
   It reports room, teacher and student overlaps, seats, lab-room type and same-day rules. Expect `ALL CHECKS PASSED` and `unplaced=0`.
3. Publish. Publishing is refused while any class is unplaced.

## API for the Remotify dashboard
`api.php` is a read-only feed of the **published** timetable (no login, no tokens).

| Request | Returns |
|---|---|
| `api.php?action=sessions` | Sessions that have a published timetable |
| `api.php?action=timetable` | All published classes of the latest published session |
| `…&session=Fall 2025` | A specific session |
| `…&faculty_id= / student_id= / room_id= / course_id= / day=` | Optional filters |

Response: `{ "success": true, "message": "", "data": { "session", "count", "classes", "by_day" } }`

> Add an access check before exposing this on a public server.

## Project structure
```
index.php, login.php, dashboard.php, admin-forms.php   Pages
admin_handler.php        Admin actions (CRUD, edit/swap, publish, import)
clash_check.php          Clash + same-day rule check used by manual edits
ga_handler.php           Starts a generation job, chooses the engine
ga_status.php            Job progress for the page
ga_worker.php            PHP engine (fallback)
ga_mpi.py                Python MPI engine (main)
mpi_config.json          Engine settings
equipment.php, equipment_config.json   Equipment rules and tunable defaults
capacity_hint.php        "How many slots do I need?" hint
api.php                  Read-only published-timetable feed
verify_timetable.php     Independent timetable checker (CLI)
slotsmart.sql, schema_template.sql     Database schema
assets/                  JS, CSS, Bootstrap, sample CSV data
docs/                    Design and methodology notes
```

## Timeline
The dated phase timeline is kept in [`../docs/TIMELINE.md`](../docs/TIMELINE.md).

## Known limitations
- The MPI engine is still being tested on Windows (MS-MPI); the PHP fallback covers failures.
- Tested on generated datasets; real university data is the next test.
- In a very tight timetable the generator may, as a last resort, put two theory classes of a course on one day.
- `api.php` has no access check yet.

## Roadmap
- **Phase 1 (now):** finish testing and sign off timetable generation.
- **Phase 2:** IT-resource management — Firebase, PC client, energy/endpoint control, sensors that update per-item equipment status (1 = working, 0 = faulty).
