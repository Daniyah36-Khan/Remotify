# Remotify

**An intelligent, automated IT-resource management and scheduling system for academic institutions** — final-year project (BS Computer Science, Qarshi University, Lahore).

Remotify has two parts, built one after the other:

| Phase | Module | What it does | Status |
|---|---|---|---|
| 1 | [`academic-scheduling/`](academic-scheduling/) | Clash-free university timetables with a Genetic Algorithm, room and equipment aware, parallel (MPI) engine with PHP fallback | **Testing / finalizing** |
| 2 | [`it-management/`](it-management/) | Remote IT-resource management: PCs, labs, equipment status, endpoint control, security logs, sensors | Planned |

> **Project started:** November 2025 · **Phase 1 timeline:** see [`docs/TIMELINE.md`](docs/TIMELINE.md)

## How the two phases connect
Phase 1 produces a **published** timetable. Its read-only `api.php` feed is what the Remotify manage-resources dashboard reads. Rooms carry per-item equipment status (1 = working, 0 = faulty); in Phase 2 sensors and PC clients will update that status automatically, and the scheduler can react to faulty equipment.

## Repository layout
```
Remotify/
├── README.md               This overview
├── docs/                   Project-level docs (timeline, workflow)
├── academic-scheduling/    Phase 1 — timetable generation (PHP + Python/MPI + MySQL)
└── it-management/          Phase 2 — IT-resource management (planned)
```

## Working agreement (version control)
- `main` holds only tested, working code.
- Each change is made on a branch (`feature/…`, `fix/…`, `docs/…`) and merged through a pull request.
- Commit messages follow Conventional Commits: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`.
- Milestones are tagged: `v1.0-phase1-timetable`, `v2.0-phase2-it-management`.
- Every phase keeps a dated timeline in [`docs/TIMELINE.md`](docs/TIMELINE.md), updated with each push.
