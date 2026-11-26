# Methodologies Used in Slotsmart GA

This document summarizes all the algorithms, techniques, and optimization strategies implemented in your Slotsmart genetic algorithm for automatic timetable generation.

---

## 1. Genetic Algorithm (Core)

The foundation of your system — an evolutionary algorithm that evolves a population of timetable solutions over generations.

- **Population-based search**: Maintains multiple candidate solutions (chromosomes)
- **Fitness-driven selection**: Better solutions (lower penalties) are more likely to reproduce
- **Generational evolution**: Creates new populations through selection, crossover, and mutation
- **Termination**: Stops when time budget (120s) is reached or perfect solution (fitness = 0) is found

---

## 2. Greedy Chromosome Builder

Instead of random initialization, your GA starts with intelligent solutions built using a greedy approach.

**How it works:**
- Iterates through each course allocation
- For each course, finds the best available time slot (not used by faculty, program, or conflicting courses)
- Uses conflict graph to guide slot selection
- Prioritizes slots matching credit hour requirements (110-130 mins for 2-credit theory)
- Builds lab sessions in lab slots, falls back to consecutive theory slots if needed

**Why it matters**: Produces high-quality starting population, reducing evolution time significantly.

---

## 3. Conflict Graph Pre-computation

A directed graph that pre-calculates which courses cannot be placed in the same time slot.

**Two types of conflicts:**
- **Group conflicts**: Courses taken by students in the same batch/semester/program
- **Faculty conflicts**: Courses taught by the same faculty member

**How it works:**
```php
// For each group, mark all courses as conflicting
foreach($batchSemGroups as $cids) {
    for each pair (course_i, course_j) {
        $graph[$course_i][$course_j] = true;
        $graph[$course_j][$course_i] = true;
    }
}
```

**Why it matters**: The greedy builder checks this graph to avoid placing conflicting courses together — O(1) lookup instead of scanning all courses.

---

## 4. Batch-Semester-Program (BSP) Grouping

Instead of checking each individual student for conflicts, courses are grouped by (batch_year, semester, program_id).

**Instead of:**
```php
// OLD: Per-student loop (expensive!)
foreach ($students as $student) {
    foreach ($studentCourses as $course) {
        // Check for clashes...
    }
}
```

**You use:**
```php
// NEW: Group-based check (fast!)
$bsg['2024|3|5'] = [course1, course2, course3]; // 3rd semester, Program 5
foreach ($bsg as $group) {
    // Check if any two courses share a slot
}
```

**Why it matters**: Eliminates thousands of per-student iterations. A group of 500 students becomes 1 token.

---

## 5. Tournament Selection

Selection operator that picks the best from a random subset.

```php
function tournSel($population, $fitnessScores, $k=4) {
    $keys = array_rand($population, min($k, count($population)));
    // Return individual with lowest fitness (best)
}
```

**Parameters:**
- Tournament size (k) = 4
- Randomly selects 4 individuals
- Returns the best one

**Why it matters**: Provides selection pressure while maintaining diversity — better solutions win, but any individual can be selected.

---

## 6. Single-Point Crossover

Recombination operator that splices two parent chromosomes.

```php
function xover($parentA, $parentB) {
    $point = mt_rand(1, count($parentA) - 1);
    return array_merge(
        array_slice($parentA, 0, $point),
        array_slice($parentB, $point)
    );
}
```

**Why it matters**: Combines good traits from two solutions. Time slots are combined; rooms are handled separately in Phase 2.

---

## 7. Adaptive Mutation

Mutation operator that rebuilds a course's time slots using the greedy builder.

**Mutation rate:**
- Base: 50%
- Increases to 70% if no improvement for 30+ generations

```php
$mutRate = GA_MUT + ($noImprove > 30 ? 0.20 : 0);
if ((mt_rand(1, 100) / 100) <= $mutRate) {
    $chromosome = mutate($chromosome, ...);
}
```

**Why it matters**: When stuck, higher mutation introduces more diversity to escape local optima.

---

## 8. Elitism

Directly copies the best individuals to the next generation unchanged.

```php
define('GA_ELITE', 3); // Keep top 3
$nextGen = array_slice($pop, 0, GA_ELITE); // Copy unchanged
```

**Why it matters**: Guarantees the best solution is never lost during evolution.

---

## 9. Two-Phase Optimization

**Phase 1: Genetic Algorithm** — Optimizes time slots only (day + period)

**Phase 2: Global Room Assignment** — Assigns rooms after time slots are fixed

```php
function assignRoomsGlobally($pdo, $runId) {
    // Group all classes by period (day + slot)
    // Sort by enrolled students (biggest first)
    // Labs → Lab rooms, Theory → Lecture halls
    // Match equipment when possible
}
```

**Why it matters:**
- Reduces search space complexity (time × room → just time)
- Guarantees zero room clashes (global view sees all classes in each period)
- Closest-fit room selection (biggest classes claim rooms first)

---

## 10. Closest-Fit Room Selection

Rooms are pre-sorted by capacity, so the first room that fits is optimal.

```php
// Pre-sorted: $rooms = [...sorted by capacity ASC...]

function pickRoom($students, $isLab, $equipment, $rooms, $usedRoomIds) {
    // Priority 1: free + correct type + capacity + equipment
    foreach ($rooms as $r) {
        if (!isset($usedRoomIds[$r['room_id']]) &&
            $r['capacity'] >= $students &&
            $isLab == ($r['room_type'] === 'Lab') &&
            eqMatch($equipment, $r['equipment'])) {
            return $r; // First match = closest fit
        }
    }
    // ... fallback priorities
}
```

**Why it matters**: O(1) room selection instead of O(n) sorting on each call.

---

## 11. Repair Passes

Post-evolution conflict resolution to clean up remaining issues.

**Repair Pass 1: General Repair**
- Runs up to 3 iterations
- Identifies faculty clashes and group clashes
- Rebuilds conflicting courses using greedy builder
- Only accepts repair if it improves fitness

**Repair Pass 2: Faculty-Specific**
- Targets remaining faculty conflicts
- Tries simple slot shifts to move conflicting class to free slot

**Why it matters**: Catches edge cases that evolution might miss.

---

## 12. Background Job Execution

The GA runs as a detached CLI process, not blocking the web server.

```php
// ga_handler.php spawns:
$cmd = 'start "" /B "php.exe" "ga_worker.php" ' . $jobId;
// Runs detached from browser
```

**Why it matters:**
- Browser doesn't wait for long-running process
- No timeout issues
- User can close browser; job continues

---

## 13. Progress Polling

Frontend polls for status every 2 seconds.

```php
// ga_status.php returns:
[
    'status' => 'running',
    'progress' => 'Optimizing... (best score 45000)',
    'fitness_score' => 45000,
    'classes_count' => 156
]
```

**Why it matters**: User sees real-time progress without page refreshes.

---

## Summary Table

| # | Methodology | Purpose |
|---|-------------|---------|
| 1 | Genetic Algorithm | Core optimization framework |
| 2 | Greedy Initialization | Build high-quality starting solutions |
| 3 | Conflict Graph | O(1) conflict lookups |
| 4 | BSP Grouping | Eliminate per-student loops |
| 5 | Tournament Selection | Selection operator |
| 6 | Single-Point Crossover | Recombination operator |
| 7 | Adaptive Mutation | Escapes local optima |
| 8 | Elitism | Preserve best solutions |
| 9 | Two-Phase Optimization | Reduce search space |
| 10 | Closest-Fit Rooms | Efficient room selection |
| 11 | Repair Passes | Clean up residual conflicts |
| 12 | Background Jobs | Non-blocking execution |
| 13 | Progress Polling | Real-time user feedback |

---

## Key Performance Optimizations

1. **No student ID loops** — Uses group tokens instead
2. **Pre-sorted rooms** — O(1) closest-fit selection
3. **Greedy-first population** — Start strong, not random
4. **Wall-clock budget** — 120s time limit prevents runaway runs
5. **Conflict graph caching** — Built once, used throughout
6. **Hash-set bookings** — O(1) slot availability checks

---

## Penalty Weights

| Constraint | Penalty | Type |
|------------|---------|------|
| Room clash | 100,000 | Hard |
| Faculty clash | 100,000 | Hard |
| Student clash | 100,000 | Hard |
| Wrong slot type | 100,000 | Hard |
| Back-to-back (3+) | 3,000 | Soft |
| Faculty overload | 1,000/hour | Soft |
| Equipment mismatch | 8,000 | Soft |

A **perfect timetable** has fitness = 0 (zero hard constraints violated).