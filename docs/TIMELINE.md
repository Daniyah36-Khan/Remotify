# Project Timeline

Dates are taken from the project's file history and are approximate (month level). Each phase section is updated whenever new work is pushed.

## Phase 1 — Academic scheduling (`academic-scheduling/`)

| When | Stage | What was done |
|---|---|---|
| Nov 2025 | Start | Project started: PHP/MySQL admin app foundation (login, pages, database) |
| Jan 2026 | Foundation | Shared header/footer, landing styles, basic admin pages |
| Jan – Jun 2026 | Core scheduler | Genetic Algorithm timetable generator, CSV import/export, dashboard, timetable grid, demo-user sandboxes, GA repair fixes (room clashes, slot duration) |
| Sep 2026 | Documentation | GA and methodology documentation written |
| Oct 2026 | Equipment-aware rooms | Per-item room equipment (1 working / 0 faulty), course equipment names, dynamic equipment quantities from enrolled students, Hungarian room assignment, "published" workflow with reasons and change log |
| Nov 2026 (early) | ADBS + parallel engine | Database safeguards (unique keys, transactions, optimistic version check), Python MPI island-model engine with automatic PHP fallback, unplaced-class list that blocks publishing, slot-capacity hint |
| Nov 2026 (late) | Hardening | Time-overlap clash model in both engines (room, teacher, student), independent checker `verify_timetable.php`, fix for a PHP crossover bug, same-day rule enforced in manual edits, agent code and mobile login removed, `api.php` reduced to a read-only published-timetable feed |
| Nov 2026 (late) | **Testing** | MPI vs PHP engine tests on the developer laptop (Windows, MS-MPI); sign-off when all checks pass |

**Sign-off checklist for Phase 1**
- [ ] MPI engine runs on Windows; PHP fallback verified
- [ ] `php verify_timetable.php` passes on small, medium and large datasets for both engines
- [ ] Tight-slot case reports unplaced classes honestly (no wrong rooms)
- [ ] Manual edit/swap refuses room, teacher, student and same-day violations
- [ ] Publish is blocked while any class is unplaced

## Phase 2 — IT-resource management (`it-management/`)

| When | Stage | Plan |
|---|---|---|
| After Phase 1 sign-off | Design | Firebase data model, PC client, admin dashboard |
| Then | Build | Resource monitoring, endpoint/energy control, security log collection with anomaly detection |
| Later | Sensors | Sensors update per-item equipment status (1/0); scheduler reacts to faulty equipment |
