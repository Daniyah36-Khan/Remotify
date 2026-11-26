# Slotsmart Genetic Algorithm Documentation

## Overview

Slotsmart uses a **genetic algorithm (GA)** to automatically generate conflict-free timetables for course scheduling. The system assigns courses to time slots and rooms while avoiding faculty clashes, student group clashes, room conflicts, and other scheduling constraints.

---

## Architecture

The GA system consists of three PHP files working together:

| File | Purpose |
|------|---------|
| `ga_handler.php` | Job dispatcher - creates GA job, launches background worker, returns immediately |
| `ga_worker.php` | Core GA implementation - runs the optimization algorithm |
| `ga_status.php` | Polling endpoint - frontend polls every 2 seconds for progress updates |

### Job Flow

```
1. User clicks "Generate Timetable"
      ↓
2. ga_handler.php creates job record in ga_jobs table
      ↓
3. ga_handler.php spawns ga_worker.php as detached CLI process
      ↓
4. Browser receives job_id, starts polling ga_status.php
      ↓
5. ga_worker.php runs GA optimization, updates progress
      ↓
6. Frontend displays results when job completes
```

---

## Genetic Algorithm Components

### 1. Chromosome Representation

A chromosome represents one complete timetable solution:

```php
$chromosome = [
    [
        'alloc_id' => 123,           // Course allocation ID
        'genes' => [
            // Theory session gene
            [
                'course_id' => 45,
                'faculty_id' => 7,
                'room_id' => 3,
                'slot_id' => 12,
                'component_type' => 'Theory',
                'day' => 'Monday',
                'is_lab' => false,
                // ... more fields
            ],
            // Lab session gene (if course has practical)
            [
                'course_id' => 45,
                'faculty_id' => 7,
                'room_id' => 8,
                'slot_id' => 15,
                'component_type' => 'Lab',
                'day' => 'Tuesday',
                'is_lab' => true,
                // ... more fields
            ]
        ]
    ],
    // ... more course allocations
];
```

### 2. Population Initialization

The GA starts with a **greedy chromosome builder** that creates intelligent initial solutions:

```php
function makeGreedyChromosome($allocs, $rooms, $theorySlots, $labSlots, ...)
```

**How it works:**
1. For each course allocation, try to place theory sessions first
2. Use **conflict graph** to avoid placing conflicting courses in the same slot
3. Check faculty availability, program availability, and room availability
4. Prefer slots that match credit hours (110-130 mins for 2-credit theory)
5. Then place lab sessions in lab slots (150-210 mins)
6. Fall back to consecutive theory slots if no lab slots available

**Conflict Graph** - Pre-computed conflicts:
- **Group conflicts**: Students in the same batch/semester/program cannot have overlapping courses
- **Faculty conflicts**: A faculty member cannot teach two courses simultaneously

### 3. Fitness Function

The fitness function calculates how "good" a chromosome is. **Lower is better** (0 = perfect solution).

```php
function fitness($genes, $facultyMaxHours, $labFallback, $batchSemGroups, $slotTimeMap)
```

#### Hard Constraints (Penalty: 100,000 each)

| Constraint | Description |
|------------|-------------|
| `P_ROOM_CLASH` | Two classes assigned to same room + time slot |
| `P_FACULTY_CLASH` | Faculty teaching two courses at same time |
| `P_STUDENT_CLASH` | Student in two courses at same time |
| `P_LAB_THEORY_SAME_DAY` | Lab and theory of same course on same day |
| `P_LAB_WRONG_ROOM` | Lab in non-lab room |
| `P_THEORY_WRONG_ROOM` | Theory in lab room |
| `P_CAPACITY` | Room too small for enrolled students |
| `P_WRONG_SLOT_TYPE` | Lab in theory slot or vice versa |

#### Soft Constraints (Lower penalties)

| Constraint | Penalty | Description |
|------------|---------|-------------|
| `P_BACKTOBACK` | 3,000 | More than 2 consecutive classes per day |
| `P_OVERLOAD` | 1,000 per hour | Faculty exceeds max hours per week |
| `P_EQUIP` | 8,000 | Room lacks required equipment |

### 4. Selection - Tournament Selection

```php
function tournSel($population, $fitnessScores, $k)
```

- Selects $k=4 random individuals
- Returns the one with the best (lowest) fitness
- Provides selection pressure while maintaining diversity

### 5. Crossover - Single Point

```php
function xover($parentA, $parentB, $rooms)
```

- Takes two parent chromosomes
- Splits at random point
- Combines first part of A with second part of B
- Rooms are NOT re-picked here (handled by Phase 2)

### 6. Mutation

```php
function mutate($chromosome, $allocs, $rooms, $theorySlots, $labSlots, ...)
```

- Randomly selects one course allocation
- Rebuilds that course's time slots using the greedy builder
- Mutation rate: 50% + additional 20% if no improvement for 30+ generations

### 7. Elitism

```php
define('GA_ELITE', 3);  // Keep top 3 unchanged
```

The top 3 best-performing chromosomes are copied unchanged to the next generation, ensuring the best solution is never lost.

---

## Two-Phase Optimization

The algorithm uses a **two-phase approach** for better performance:

### Phase 1: Time Slot Optimization (Genetic Algorithm)

The GA optimizes **only the time slots** (day + period). Room assignment is intentionally ignored here to reduce search space complexity.

### Phase 2: Global Room Assignment

After time slots are fixed, rooms are assigned globally:

```php
function assignRoomsGlobally($pdo, $runId)
```

**Strategy per period (day+slot):**
1. Sort classes by enrolled students (biggest first)
2. Labs → Lab rooms, Theory → Lecture halls
3. Match equipment when possible
4. Fall back to any available room if necessary

This guarantees **zero room clashes** because the global view sees all classes in each period.

---

## Repair Passes

After evolution completes, two repair passes fix residual conflicts:

### Repair Pass 1: General Repair
- Runs up to 3 iterations
- Rebuilds conflicting courses using greedy builder
- Accepts repair only if it improves fitness

### Repair Pass 2: Faculty-Specific
- Identifies remaining faculty clashes
- Tries to move conflicting classes to free slots
- Simple slot-shift approach

---

## Configuration Constants

```php
define('P_HARD',            100000);  // Hard constraint penalty
define('P_BACKTOBACK',      3000);    // Back-to-back penalty
define('P_OVERLOAD',        1000);    // Faculty overload penalty (per hour)
define('P_EQUIP',           8000);    // Equipment mismatch penalty
define('GA_MUT',   0.50);             // Base mutation rate (50%)
define('GA_ELITE', 3);                // Number of elite individuals
define('GA_TOURN', 4);                // Tournament size
define('GA_BUDGET', 120.0);           // Time budget in seconds
```

---

## Population Sizing

```php
$dynPop = $totalAllocs > 400 ? 8 : ($totalAllocs > 150 ? 10 : 14);
$sample = max($dynPop, 24);
```

- Small schedules (<150 courses): 14 individuals, sample 24
- Medium schedules (150-400): 10 individuals, sample 24  
- Large schedules (>400): 8 individuals, sample 24

---

## Data Structures

### Batch-Semester-Program Groups (BSG)

Instead of checking each individual student, courses are grouped:

```php
// Key: "2024|3|5" (batch_year|semester|program_id)
// Value: [course_id1, course_id2, ...]
$bsg = [
    '2024|3|5' => [45, 67, 89, ...],  // 3rd semester, Program 5
    '2024|5|5' => [12, 34, 56, ...],  // 5th semester, Program 5
];
```

This eliminates the need for per-student loops (major optimization).

### Time Slot Categories

```php
$theorySlots = [...];  // 80-130 minute slots
$labSlots = [...];     // 150-210 minute slots
```

---

## Database Schema

### ga_jobs table

```sql
CREATE TABLE ga_jobs (
    job_id        INT AUTO_INCREMENT PRIMARY KEY,
    session_name  VARCHAR(50) NOT NULL,
    status        ENUM('queued','running','complete','failed'),
    progress      VARCHAR(255),
    dept_done     INT DEFAULT 0,
    dept_total    INT DEFAULT 0,
    fitness_score INT DEFAULT 0,
    classes_count INT DEFAULT 0,
    run_id        INT DEFAULT NULL,
    run_label     VARCHAR(120) DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### timetable_runs table

```sql
CREATE TABLE timetable_runs (
    run_id        INT AUTO_INCREMENT PRIMARY KEY,
    base_session  VARCHAR(50),
    run_label     VARCHAR(120),
    fitness_score INT DEFAULT 0,
    classes_count INT DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### generated_timetable table

```sql
CREATE TABLE generated_timetable (
    timetable_id   INT AUTO_INCREMENT PRIMARY KEY,
    run_id         INT,
    session_name   VARCHAR(50),
    course_id      INT,
    faculty_id     INT,
    room_id        INT,
    slot_id        INT,
    component_type VARCHAR(20),
    display_label  VARCHAR(100)
);
```

---

## Key Optimizations

1. **No per-student loops** - Uses batch-semester-program group tokens instead
2. **Background execution** - GA runs detached from web server
3. **Conflict graph** - Pre-computed conflicts guide the greedy builder
4. **Closest-fit rooms** - Rooms sorted by capacity for O(1) room selection
5. **Two-phase approach** - Separates time and room optimization
6. **Greedy initialization** - Start with good solutions, not random
7. **Adaptive mutation** - Increases when stuck

---

## Output Interpretation

| Fitness Score | Meaning |
|---------------|---------|
| 0 | **Perfect** - Zero conflicts |
| 1-10,000 | Good - Minor soft constraint violations |
| 10,000+ | Some conflicts remain (may require manual fixing) |

The frontend displays the final fitness score and class count after completion.