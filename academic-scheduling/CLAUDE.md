# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SlotSmart is a PHP-based AI-powered university timetable generation system. It uses a genetic algorithm (GA) engine to produce conflict-free schedules for courses, faculty, rooms, and students across semesters.

## Development Environment

- **Stack**: WAMP (Apache + MySQL on port 3308 + PHP 7.4+)
- **No build tools**: No `package.json`, `composer.json`, webpack, or CLI build step
- **All CDN assets**: Bootstrap 5.3, jQuery 3.7, DataTables 1.13.7, Font Awesome 6.4 loaded from CDNs
- **Local URL**: `http://localhost/Slotsmart-1/`

To set up locally:
1. Place the project under `D:\wamp123\www\Slotsmart-1` (or equivalent WAMP `www` root)
2. Import `slotsmart.sql` into MySQL
3. Adjust `db.php` if your MySQL password or port differs from the defaults (`port=3308`, empty password)

There are no lint, test, or build commands — development is done directly in the browser.

## Architecture

### Request Flow

```
index.php (landing, no auth)  →  login.php  →  admin-forms.php (CRUD)
                                                      ↓
                                              admin_handler.php (AJAX CRUD)
                                              ga_handler.php   (GA generation)
                                                      ↓
                                              dashboard.php (view/print timetable)
```

### Key Files

| File | Role |
|---|---|
| `db.php` | PDO connection (MySQL, port 3308) |
| `header.php` | Universal navbar; enforces session/role checks on every page |
| `admin_handler.php` | Single AJAX endpoint for all CRUD and CSV-import operations (switch on `action`) |
| `ga_handler.php` | Genetic algorithm engine — pop=30, gen=200, mutation=50%, 360s timeout, 1 GB memory |
| `admin-forms.php` | 9-tab Bootstrap interface for data entry |
| `dashboard.php` | Timetable viewer with cascading filters; print-optimized via CSS |
| `demo_handler.php` | Creates/resets a demo admin account |
| `slotsmart.sql` | Complete schema (11 InnoDB tables, FK cascades, sample data) |
| `assets/js/main.js` | Client-side form logic, DataTables init, fetch wrappers, CSV upload |
| `assets/css/style.css` | Admin/dashboard styles including print media queries |
| `assets/css/landing.css` | Landing page hero, pricing, animations |

### Database Schema (11 tables)

Core entities: `departments` → `programs`, `courses`, `faculty`, `rooms`, `time_slots`  
Assignment layer: `allocations` (faculty+course per session), `enrollments` (student+course)  
Output: `generated_timetable` (FK to all entities; cleared and repopulated on each GA run)

`courses.credit_hours` uses a `"T-P-C"` string format (Theory-Practical-Credit).

### Session & Auth

- PHP sessions only — no JWT or tokens
- Login sets `$_SESSION['loggedin']`, `['user_id']`, `['email']`, `['role']`
- Only role is `'admin'`; enforced in `header.php`, `admin-forms.php`, and `ga_handler.php`
- Non-authenticated requests redirect to `login.php`; non-admin redirects to `dashboard.php`

### GA Engine (`ga_handler.php`)

- Genes are grouped by allocation (theory slots + optional lab slot together to enforce same-day constraint)
- Fitness penalties: `P_HARD = 100,000` for room/faculty/student clashes, capacity violations, equipment mismatch, lab/theory on different days
- Output written directly to `generated_timetable`; previous timetable is deleted before insert
- Script sets `max_execution_time = 360` and `memory_limit = 1024M`

### AJAX Pattern

`admin_handler.php` responds to `POST` requests with JSON `{success, message, data}`. All admin form submissions and the GA trigger go through `fetch()` in `main.js`.
