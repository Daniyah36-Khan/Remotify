#!/usr/bin/env python3
"""
ga_mpi.py - SlotSmart timetable engine with MPI (mpi4py).  Run:   mpiexec -n 4 python ga_mpi.py <job_id> [--db slotsmart_db] [--seconds 120]

What it does differently from ga_worker.php
  1. ROOM-AWARE: a time slot is only chosen for a class if every class in that slot can still get a valid room
     (bipartite matching of classes -> rooms per slot). The fitness counts classes that cannot get a room as a HARD
     violation, so the search removes them instead of leaving "placeholder" rooms behind.
  2. PARALLEL (message passing):
       * island model : every MPI rank evolves its own sub-population; the best individuals travel around a ring
                        (comm.sendrecv) every few generations; all ranks stop together (comm.allreduce / bcast)
       * room assignment: the finished timetable's slots are scattered over the ranks, each rank solves the Hungarian
                        assignment of its slots, rank 0 gathers the results (embarrassingly parallel: slots are independent)
  3. Only classes that really got a valid room are written to generated_timetable; the rest are listed in
     timetable_runs.unplaced_json and the job is flagged NOT READY.

Rules kept from ga_worker.php: no teacher clash, no student-group (batch+semester+program) clash, theory sessions of a
course on different days, lab never on a day that has its theory, lab only in lab slots, a lab class only in a Lab room,
theory in a lab room only as a last-resort fallback, capacity and equipment minimums (equipment.php rules, ported below).
"""
import sys, os, re, json, time, math, random
from mpi4py import MPI
import pymysql

P_HARD = 100000
P_BACKTOBACK = 3000
P_OVERLOAD = 1000
P_FALLBACK = 500          # theory class placed in a lab room (allowed, but discouraged)
HU_BIG = 10**9
HERE = os.path.dirname(os.path.abspath(__file__))

comm = MPI.COMM_WORLD
rank = comm.Get_rank()
size = comm.Get_size()

# ----------------------------------------------------------------------------- equipment rules (port of equipment.php)
CFG = {'default_min_fraction': 0.6, 'default_base_share': 0.0,
       'min_fraction': {'pc': 0.75, 'ac': 0.5, 'fan': 0.6, 'light': 0.6}, 'base_share': {},
       'aliases': {'computers': 'pc', 'computer': 'pc', 'pcs': 'pc', 'acs': 'ac'}}

def load_equipment_config():
    p = os.path.join(HERE, 'equipment_config.json')
    if os.path.isfile(p):
        try:
            j = json.load(open(p, encoding='utf-8'))
            for k, v in j.items():
                if isinstance(v, dict) and isinstance(CFG.get(k), dict): CFG[k].update(v)
                else: CFG[k] = v
        except Exception:
            pass

def etype(name):
    t = re.sub(r'[\s_-]*\d+$', '', name.strip()).strip().lower()
    al = CFG['aliases']
    if t in al: return al[t]
    if len(t) > 3 and t.endswith('s') and not t.endswith('ss'): t = t[:-1]
    return al.get(t, t)

def room_counts(s):
    out = {}
    try: j = json.loads(s or 'None')
    except Exception: return out
    if isinstance(j, dict):
        for name, st in j.items():
            t = etype(str(name))
            if not t: continue
            o = out.setdefault(t, [0, 0]); o[1] += 1
            if str(st) == '1': o[0] += 1
    return out

def course_req(s):
    try: j = json.loads(s or 'None')
    except Exception: return []
    names = []
    if isinstance(j, list): names = j
    elif isinstance(j, dict): names = list(j.keys())
    out = []
    for n in names:
        t = etype(str(n))
        if t and t not in out: out.append(t)
    return out

def equip_level(req, have, students, cap):
    students = max(students, 1); level = 2
    for t in req:
        h = have.get(t)
        if h is None or h[1] < 1: return 0
        b = float(CFG['base_share'].get(t, CFG['default_base_share']))
        f = float(CFG['min_fraction'].get(t, CFG['default_min_fraction']))
        share = min(1.0, b + (1 - b) * (students / max(cap, 1)))
        ideal = min(h[1], max(1, math.ceil(round(h[1] * share, 6))))
        mn = max(1, math.ceil(round(ideal * f, 6)))
        if h[0] < mn: return 0
        if h[0] < ideal: level = 1
    return level

# ----------------------------------------------------------------------------- database
def db_settings():
    s = open(os.path.join(HERE, 'db.php'), encoding='utf-8', errors='ignore').read()
    def g(name, default):
        m = re.search(r"\$%s\s*=\s*'([^']*)'" % name, s)
        return m.group(1) if m else default
    return dict(host=g('host', '127.0.0.1'), user=g('dbUser', 'root'), password=g('dbPass', ''), port=int(g('port', '3306')))

def connect(db):
    st = db_settings()
    return pymysql.connect(host=st['host'], port=st['port'], user=st['user'], password=st['password'], database=db,
                           charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor, autocommit=True)

def progress(conn, job_id, msg, status='running'):
    try:
        with conn.cursor() as c:
            c.execute("UPDATE ga_jobs SET status=%s, progress=%s, updated_at=NOW() WHERE job_id=%s", (status, msg[:250], job_id))
    except Exception:
        pass

def theory_sessions(T):
    return 0 if T <= 0 else 1 if T <= 2 else 2 if T <= 3 else 3 if T <= 5 else 4

def parse_ch(ch):
    p = (ch or '0-0-0').strip().split('-')
    g = lambda i: max(0, int(p[i])) if len(p) > i and p[i].strip().lstrip('-').isdigit() else 0
    return g(0), g(1)

def load_data(conn, job):
    d = {}
    with conn.cursor() as c:
        c.execute("""SELECT a.allocation_id,a.faculty_id,a.course_id,a.session_name,c.course_name,c.course_code,c.credit_hours,
                  COALESCE(c.requires_equipment,'None') AS requires_equipment,c.department_id,c.program_id,f.max_hours_per_week,
                  (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e WHERE e.course_id=c.course_id) AS enrolled_students
                  FROM allocations a JOIN courses c ON a.course_id=c.course_id JOIN faculty f ON a.faculty_id=f.faculty_id
                  WHERE a.session_name=%s AND EXISTS (SELECT 1 FROM enrollments e2 WHERE e2.course_id=c.course_id)
                  ORDER BY c.department_id, c.course_id""", (job['session_name'],))
        d['allocs'] = c.fetchall()
        c.execute("""SELECT e.course_id,e.batch_year,e.semester,COALESCE(s.program_id,0) AS program_id FROM enrollments e
                  JOIN students s ON e.student_id=s.student_id WHERE e.batch_year!='' AND e.semester!=''
                  GROUP BY e.course_id,e.batch_year,e.semester,s.program_id""")
        d['bsg_rows'] = c.fetchall()
        c.execute("SELECT room_id,room_number,capacity,room_type,COALESCE(equipment,'None') AS equipment FROM rooms ORDER BY capacity ASC")
        d['rooms'] = c.fetchall()
        c.execute("""SELECT slot_id,day,start_time,end_time,TIMESTAMPDIFF(MINUTE,start_time,end_time) AS dur FROM time_slots
                  ORDER BY FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday'),start_time""")
        d['slots'] = c.fetchall()
    for s in d['slots']:
        s['start_time'] = str(s['start_time']); s['end_time'] = str(s['end_time'])
    return d

def tmin(t):
    p = str(t).split(':'); return int(p[0]) * 60 + int(p[1])

# ----------------------------------------------------------------------------- problem model (built identically on every rank)
class Problem:
    def __init__(self, d):
        load_equipment_config()
        self.allocs = d['allocs']; self.rooms = d['rooms']; self.N = len(self.allocs)
        self.slot = {s['slot_id']: s for s in d['slots']}
        self.sday = {sid: s['day'] for sid, s in self.slot.items()}
        self.smin = {sid: tmin(s['start_time']) for sid, s in self.slot.items()}
        self.emin = {sid: tmin(s['end_time']) for sid, s in self.slot.items()}
        self.dur = {sid: int(s['dur']) for sid, s in self.slot.items()}
        # TIME POINTS: a point is a start instant (day + start minute). Two slots overlap exactly when they share a point, so
        # "same teacher / student group / room at overlapping times" is the same as "same key at one point".
        starts = {}
        for sid in self.slot: starts.setdefault(self.sday[sid], set()).add(self.smin[sid])
        pid = {}
        for day in sorted(starts):
            for t in sorted(starts[day]): pid[(day, t)] = len(pid)
        self.pts = {}; self.ptset = {}
        for sid in self.slot:
            dd = self.sday[sid]
            self.pts[sid] = tuple(pid[(dd, t)] for t in sorted(starts[dd]) if self.smin[sid] <= t < self.emin[sid])
            self.ptset[sid] = frozenset(self.pts[sid])
        self.theory_by_day = {}; self.lab_by_day = {}
        for s in d['slots']:
            m = int(s['dur'])
            if 80 <= m <= 130: self.theory_by_day.setdefault(s['day'], []).append(s['slot_id'])
            if 150 <= m <= 210: self.lab_by_day.setdefault(s['day'], []).append(s['slot_id'])
        self.theory_slots = [x for v in self.theory_by_day.values() for x in v]
        self.lab_slots = [x for v in self.lab_by_day.values() for x in v]
        # per-alloc facts
        self.A = []
        self.course_ais = {}
        for ai, a in enumerate(self.allocs):
            T, P = parse_ch(a['credit_hours'])
            nT = theory_sessions(T)
            self.A.append(dict(fid=int(a['faculty_id']), cid=int(a['course_id']), pid=int(a['program_id'] or 0), T=T, nT=nT,
                               lab=P > 0, students=max(int(a['enrolled_students'] or 1), 1), req=course_req(a['requires_equipment']),
                               code=a['course_code'], mh=int(a['max_hours_per_week'] or 20)))
            self.course_ais.setdefault(int(a['course_id']), []).append(ai)
        self.fmh = {a['fid']: a['mh'] for a in self.A}
        # groups (batch+semester+program) -> courses
        self.bsg = {}
        for r in d['bsg_rows']:
            key = '%s|%s|%s' % (r['batch_year'], r['semester'], r['program_id'])
            if int(r['course_id']) in self.course_ais: self.bsg.setdefault(key, set()).add(int(r['course_id']))
        self.bsg = {k: sorted(v) for k, v in self.bsg.items()}
        # conflict graph (same rule as ga_worker.php)
        cg = {}
        for cids in self.bsg.values():
            if len(cids) > 30: continue
            for i in range(len(cids)):
                for j in range(i + 1, len(cids)):
                    cg.setdefault(cids[i], set()).add(cids[j]); cg.setdefault(cids[j], set()).add(cids[i])
        fc = {}
        for a in self.A: fc.setdefault(a['fid'], []).append(a['cid'])
        for cs in fc.values():
            if len(cs) > 50: continue
            for i in range(len(cs)):
                for j in range(i + 1, len(cs)):
                    if cs[i] != cs[j]:
                        cg.setdefault(cs[i], set()).add(cs[j]); cg.setdefault(cs[j], set()).add(cs[i])
        self.cg = cg
        # room eligibility per (alloc, component): PRIM = proper rooms, FALL = lab rooms usable by theory (fallback)
        lab_eq = [r['equipment'] for r in self.rooms if r['room_type'] == 'Lab']
        hall_eq = [r['equipment'] for r in self.rooms if r['room_type'] != 'Lab']
        present = {'Lab': self.types_in(lab_eq), 'Hall': self.types_in(hall_eq)}
        self.rc = [room_counts(r['equipment']) for r in self.rooms]
        self.m = len(self.rooms)
        self.prim = {}; self.fall = {}; self.cost = {}
        for ai, a in enumerate(self.A):
            for comp in (0, 1):
                if (comp == 0 and a['nT'] == 0) or (comp == 1 and not a['lab']): continue
                kind = 'Lab' if comp == 1 else 'Hall'
                req = [t for t in a['req'] if t in present[kind]]
                p = []; f = []; cst = {}
                for ri, r in enumerate(self.rooms):
                    cap = int(r['capacity']); isl = r['room_type'] == 'Lab'
                    if comp == 1 and not isl: continue
                    if cap < a['students']: continue
                    lv = equip_level(req, self.rc[ri], a['students'], cap)
                    if lv == 0: continue
                    c = (0 if lv == 2 else 1000) + min(999, cap - a['students'])
                    if comp == 0 and isl:
                        f.append(ri); c += 100000
                    else:
                        p.append(ri)
                    cst[ri] = c
                self.prim[(ai, comp)] = tuple(p); self.fall[(ai, comp)] = tuple(f); self.cost[(ai, comp)] = cst
        self._state_cache = {}

    @staticmethod
    def types_in(eqs):
        s = set()
        for e in eqs: s.update(room_counts(e).keys())
        return s

    # class key = ai*8 + j  (j < nT: theory session j ; j == nT: the lab)
    def comp_of(self, k):
        ai, j = divmod(k, 8); return ai, (1 if j >= self.A[ai]['nT'] else 0)

    def slot_state(self, keys):
        """keys: sorted tuple of class keys in one slot -> (classes without any valid room, classes that need a lab-room fallback)"""
        st = self._state_cache.get(keys)
        if st is not None: return st
        n = len(keys)
        pr = [self.prim[self.comp_of(k)] for k in keys]; fb = [self.fall[self.comp_of(k)] for k in keys]
        match = {}
        def aug(i, seen, full):
            for r in (pr[i] + fb[i] if full else pr[i]):
                if r in seen: continue
                seen.add(r)
                if r not in match or aug(match[r], seen, full):
                    match[r] = i; return True
            return False
        order = sorted(range(n), key=lambda i: len(pr[i]) + len(fb[i]))
        pending = [i for i in order if not aug(i, set(), False)]
        un = 0
        for i in pending:
            if not aug(i, set(), True): un += 1
        fbc = sum(1 for r, i in match.items() if r not in pr[i])
        if len(self._state_cache) > 400000: self._state_cache.clear()
        self._state_cache[keys] = (un, fbc)
        return un, fbc

# ----------------------------------------------------------------------------- fitness
def evaluate(P, chrom, want_bad=False):
    pen = 0; bad = set()
    slot_cls = {}; fac_slot = {}; fload = {}
    sess_of_course = {}
    for ai, sl in enumerate(chrom):
        a = P.A[ai]; fid = a['fid']
        for j, sid in enumerate(sl):
            hit = False
            for pt in P.pts[sid]:
                slot_cls.setdefault(pt, []).append(ai * 8 + j)
                k = (pt, fid)
                if k in fac_slot: hit = True; bad.add(fac_slot[k])
                else: fac_slot[k] = ai
            if hit: pen += P_HARD; bad.add(ai)
            fload[fid] = fload.get(fid, 0) + 1
        sess_of_course.setdefault(a['cid'], []).extend(sl)
        nT = a['nT']
        days = [P.sday[s] for s in sl[:nT]]
        dup = len(days) - len(set(days))
        if dup: pen += P_HARD * dup; bad.add(ai)
        if a['lab'] and len(sl) > nT and P.sday[sl[nT]] in days: pen += P_HARD; bad.add(ai)
    # student groups
    for gk, cids in P.bsg.items():
        seen = {}; perday = {}
        for cid in cids:
            for s in sess_of_course.get(cid, ()):
                hit = None
                for pt in P.pts[s]:
                    if pt in seen: hit = seen[pt]
                    else: seen[pt] = cid
                if hit is not None:
                    pen += P_HARD
                    for x in P.course_ais[cid] + P.course_ais[hit]: bad.add(x)
                perday.setdefault(P.sday[s], []).append(s)
        for day, ss in perday.items():
            if len(ss) < 3: continue
            ss.sort(key=lambda x: P.smin[x]); chain = 1
            for i in range(1, len(ss)):
                if P.smin[ss[i]] - P.emin[ss[i - 1]] <= 10:
                    chain += 1
                    if chain >= 3: pen += P_BACKTOBACK
                else: chain = 1
    for fid, load in fload.items():
        mh = P.fmh.get(fid, 20)
        if load > mh: pen += P_OVERLOAD * (load - mh)
    # rooms: the classes running at every time point must be matchable to distinct valid rooms
    for sid, keys in slot_cls.items():
        un, fb = P.slot_state(tuple(sorted(keys)))
        if un: pen += P_HARD * un; bad.update(k // 8 for k in keys)
        if fb: pen += P_FALLBACK * fb
    return (pen, bad) if want_bad else pen

# ----------------------------------------------------------------------------- construction / mutation
class Book:
    """Bookings of everything already placed (used while (re)building one class)."""
    def __init__(self, P, chrom, skip=None):
        self.fac = {}; self.prog = {}; self.cls = {}; self.cslots = {}
        for ai, sl in enumerate(chrom):
            if ai == skip or not sl: continue
            for j, sid in enumerate(sl): self.add(P, ai, j, sid)
    def add(self, P, ai, j, sid):
        a = P.A[ai]; pts = P.pts[sid]
        self.fac.setdefault(a['fid'], set()).update(pts)
        if a['pid'] > 0: self.prog.setdefault(a['pid'], set()).update(pts)
        for pt in pts: self.cls.setdefault(pt, []).append(ai * 8 + j)
        self.cslots.setdefault(a['cid'], set()).update(pts)

def place_alloc(P, ai, B, rng):
    a = P.A[ai]; out = []; used_days = set()
    blocked = set()
    for c2 in P.cg.get(a['cid'], ()): blocked |= B.cslots.get(c2, set())
    facset = B.fac.get(a['fid'], set()); progset = B.prog.get(a['pid'], set()) if a['pid'] > 0 else set()
    def pick(by_day, theory):
        bad_pts = blocked | facset | progset
        strict = [s for d, sl in by_day.items() if d not in used_days for s in sl if not (P.ptset[s] & bad_pts)]
        c = strict
        if not c: c = [s for d, sl in by_day.items() if d not in used_days for s in sl]
        if not c: c = [s for sl in by_day.values() for s in sl]
        if theory:
            lo, hi = (110, 130) if a['T'] == 2 else (0, 109)
            f = [s for s in c if lo <= P.dur[s] <= hi]
            if f: c = f
        c = list(c); rng.shuffle(c)
        return c
    for j in range(a['nT'] + (1 if a['lab'] else 0)):
        is_lab = j >= a['nT']
        cands = pick(P.lab_by_day if is_lab else P.theory_by_day, not is_lab)
        if not cands: break
        key = ai * 8 + j; best = None; bscore = None
        for sid in cands[:14]:
            un = fb = load = 0
            for pt in P.pts[sid]:
                u1, f1 = P.slot_state(tuple(sorted(B.cls.get(pt, []) + [key]))); un += u1; fb += f1; load += len(B.cls.get(pt, []))
            score = (un, fb, load)
            if bscore is None or score < bscore: best, bscore = sid, score
            if un == 0 and fb == 0: break
        out.append(best); B.add(P, ai, j, best); used_days.add(P.sday[best])
        if not is_lab: facset = B.fac[a['fid']]
    return tuple(out)

def greedy_chromosome(P, rng):
    order = list(range(P.N)); rng.shuffle(order)
    B = Book(P, [()] * P.N)
    chrom = [()] * P.N
    for ai in order: chrom[ai] = place_alloc(P, ai, B, rng)
    return chrom

def mutate(P, chrom, rng, bad):
    chrom = list(chrom)
    ai = rng.choice(sorted(bad)) if bad and rng.random() < 0.75 else rng.randrange(P.N)
    B = Book(P, chrom, skip=ai)
    chrom[ai] = place_alloc(P, ai, B, rng)
    return chrom

def tournament(pop, fit, rng, k=3):
    idx = rng.sample(range(len(pop)), min(k, len(pop)))
    return pop[min(idx, key=lambda i: fit[i])]

# ----------------------------------------------------------------------------- Hungarian (min-cost assignment, rows <= columns)
def hungarian(a):
    n = len(a)
    if n == 0: return {}
    m = len(a[0]); INF = float('inf')
    u = [0] * (n + 1); v = [0] * (m + 1); p = [0] * (m + 1); way = [0] * (m + 1)
    for i in range(1, n + 1):
        p[0] = i; j0 = 0; minv = [INF] * (m + 1); used = [False] * (m + 1)
        while True:
            used[j0] = True; i0 = p[j0]; delta = INF; j1 = 0
            for j in range(1, m + 1):
                if not used[j]:
                    cur = a[i0 - 1][j - 1] - u[i0] - v[j]
                    if cur < minv[j]: minv[j] = cur; way[j] = j0
                    if minv[j] < delta: delta = minv[j]; j1 = j
            for j in range(m + 1):
                if used[j]: u[p[j]] += delta; v[j] -= delta
                else: minv[j] -= delta
            j0 = j1
            if p[j0] == 0: break
        while True:
            j1 = way[j0]; p[j0] = p[j1]; j0 = j1
            if j0 == 0: break
    return {p[j] - 1: j - 1 for j in range(1, m + 1) if p[j]}

def assign_rooms_for_slot(P, keys, forbid=()):
    """-> {class key: room index or None}. Rooms in `forbid` (still held by earlier, still-running classes) cannot be used."""
    n = len(keys); m = P.m
    cost = []
    for k in keys:
        cst = P.cost[P.comp_of(k)]
        row = [(HU_BIG if ri in forbid else cst.get(ri, HU_BIG)) for ri in range(m)] + [HU_BIG] * max(0, n - m)
        cost.append(row)
    asg = hungarian(cost); out = {}
    for i, k in enumerate(keys):
        ri = asg.get(i)
        out[k] = ri if (ri is not None and ri < m and cost[i][ri] < HU_BIG) else None
    return out

def assign_rooms_for_day(P, items):
    """items: [(class key, slot id)] of ONE day -> {key: room index or None}.
    Sweep in time order: classes starting at the same instant are matched together (Hungarian); rooms still held by
    earlier classes that are running at that instant are forbidden. So no room is ever used twice at overlapping times."""
    by_start = {}
    for k, sid in items: by_start.setdefault(P.smin[sid], []).append((k, sid))
    out = {}; done = []
    for t in sorted(by_start):
        forbid = set()
        for k2, sid2 in done:
            if P.smin[sid2] < t < P.emin[sid2] and out.get(k2) is not None: forbid.add(out[k2])
        group = by_start[t]
        out.update(assign_rooms_for_slot(P, tuple(sorted(k for k, _ in group)), forbid))
        done.extend(group)
    return out

def audit_overlaps(P, entries, stu):
    """entries: [(key, sid, room_index)] -> set of keys that must be dropped. Last safety net: a class is dropped when it overlaps
    in time with an already accepted class that uses the same room, the same teacher, or shares even one enrolled student."""
    drop = set(); accepted = {}
    for k, sid, ri in entries:
        a = P.A[k // 8]; bad = False
        for (k2, sid2, ri2) in accepted.get(P.sday[sid], ()):
            if not (P.smin[sid] < P.emin[sid2] and P.smin[sid2] < P.emin[sid]): continue
            a2 = P.A[k2 // 8]
            if ri == ri2 or a['fid'] == a2['fid']: bad = True; break
            s1 = stu.get(a['cid'], set()); s2 = stu.get(a2['cid'], set())
            if len(s1) > len(s2): s1, s2 = s2, s1
            if any(x in s2 for x in s1): bad = True; break
        if bad: drop.add(k)
        else: accepted.setdefault(P.sday[sid], []).append((k, sid, ri))
    return drop

# ----------------------------------------------------------------------------- main
def main():
    args = sys.argv[1:]
    if not args: sys.exit('usage: ga_mpi.py <job_id> [--db name] [--seconds N] [--pop N]')
    job_id = int(args[0]); db = 'slotsmart_db'; budget = 120.0; total_pop = 48; seed = None
    for i, x in enumerate(args):
        if x == '--db': db = args[i + 1]
        if x == '--seconds': budget = float(args[i + 1])
        if x == '--pop': total_pop = int(args[i + 1])
        if x == '--seed': seed = int(args[i + 1])
    t0 = MPI.Wtime()
    conn = None; data = None; err = None
    if rank == 0:
        try:
            conn = connect(db)
            with conn.cursor() as c:
                c.execute("SELECT * FROM ga_jobs WHERE job_id=%s", (job_id,)); job = c.fetchone()
            if not job: raise RuntimeError('job not found')
            progress(conn, job_id, 'Loading data (MPI x%d)...' % size)
            data = load_data(conn, job); data['job'] = job
            if not data['allocs']: raise RuntimeError('No allocated courses with enrolled students found.')
            if not any(80 <= int(s['dur']) <= 130 for s in data['slots']): raise RuntimeError('No theory slots (80-130 min) defined.')
            if any(parse_ch(a['credit_hours'])[1] > 0 for a in data['allocs']) and not any(150 <= int(s['dur']) <= 210 for s in data['slots']):
                raise RuntimeError('Lab courses exist but there are no lab slots (150-210 min).')
        except Exception as e:
            err = str(e)
    err = comm.bcast(err, root=0)
    if err:
        if rank == 0:
            progress(conn, job_id, 'Error: ' + err, 'failed')
        sys.exit(1)
    data = comm.bcast(data, root=0)
    P = Problem(data)
    rng = random.Random((seed if seed is not None else int(time.time())) * 1000 + rank)

    # ---- island GA ----
    local_pop = max(4, total_pop // size)
    sample = max(local_pop, 12)
    cand = []
    for _ in range(sample):
        c = greedy_chromosome(P, rng); cand.append((evaluate(P, c), c))
    cand.sort(key=lambda x: x[0])
    pop = [c for _, c in cand[:local_pop]]; fit = [f for f, _ in cand[:local_pop]]
    best_f, best_c = fit[0], pop[0]
    gens = 0; BLOCK = 8
    stop = False
    while not stop:
        for _ in range(BLOCK):
            if MPI.Wtime() - t0 >= budget: break          # keep to the time limit even when one generation is slow
            order = sorted(range(len(pop)), key=lambda i: fit[i])
            pop = [pop[i] for i in order]; fit = [fit[i] for i in order]
            if fit[0] < best_f: best_f, best_c = fit[0], pop[0]
            if best_f == 0: break
            np_, nf = pop[:2], fit[:2]
            while len(np_) < local_pop:
                pa = tournament(pop, fit, rng); pb = tournament(pop, fit, rng)
                cut = rng.randint(1, max(1, P.N - 1))
                child = list(pa[:cut]) + list(pb[cut:])
                if rng.random() < 0.8:
                    f0, bad = evaluate(P, child, True)
                    child = mutate(P, child, rng, bad)
                np_.append(child); nf.append(evaluate(P, child))
            pop, fit = np_, nf
            gens += 1
        # migration around the ring: send my best, receive my neighbour's best
        if size > 1:
            inc_f, inc_c = comm.sendrecv((best_f, best_c), dest=(rank + 1) % size, source=(rank - 1) % size)
            worst = max(range(len(fit)), key=lambda i: fit[i])
            if inc_f < fit[worst]: pop[worst], fit[worst] = inc_c, inc_f
            if inc_f < best_f: best_f, best_c = inc_f, inc_c
        gmin = comm.allreduce(best_f, op=MPI.MIN)
        elapsed = MPI.Wtime() - t0
        stop_flag = (gmin == 0) or (elapsed >= budget) if rank == 0 else None
        stop = comm.bcast(stop_flag, root=0)
        if rank == 0: progress(conn, job_id, 'Optimising (MPI x%d, gen %d, best penalty %d)' % (size, gens, gmin))

    results = comm.gather((best_f, best_c, gens), root=0)
    ga_time = MPI.Wtime() - t0
    best = None
    if rank == 0:
        best = min(results, key=lambda r: r[0])
    best = comm.bcast(best, root=0)
    best_f, best_c, _ = best
    total_gens = sum(r[2] for r in results) if rank == 0 else 0

    # ---- parallel room assignment: the days are scattered over the ranks, each rank sweeps its days in time order ----
    day_items = {}
    for ai, sl in enumerate(best_c):
        for j, sid in enumerate(sl): day_items.setdefault(P.sday[sid], []).append((ai * 8 + j, sid))
    dnames = sorted(day_items)
    mine = {d: assign_rooms_for_day(P, day_items[d]) for d in dnames[rank::size]}
    parts = comm.gather(mine, root=0)

    if rank == 0:
        try:
            room_of = {}
            for part in parts:
                for dname, mp in part.items(): room_of.update(mp)
            # last safety net (by real time overlap, using actual enrollments)
            cids = sorted({a['cid'] for a in P.A}); stu = {}
            with conn.cursor() as c:
                c.execute("SELECT course_id, student_id FROM enrollments WHERE course_id IN (%s)" % ','.join(str(x) for x in cids))
                for r in c.fetchall(): stu.setdefault(int(r['course_id']), set()).add(int(r['student_id']))
            entries = []
            for d in dnames:
                for k, sid in sorted(day_items[d], key=lambda x: (P.smin[x[1]], x[0])):
                    if room_of.get(k) is not None: entries.append((k, sid, room_of[k]))
            for k in audit_overlaps(P, entries, stu): room_of[k] = None
            run_label = '%s #%d' % (data['job']['session_name'], int(time.time()))
            with conn.cursor() as c:
                c.execute("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='timetable_runs' AND COLUMN_NAME='unplaced_json'")
                if not c.fetchone()['n']: c.execute("ALTER TABLE timetable_runs ADD COLUMN unplaced_json TEXT NULL")
                c.execute("INSERT INTO timetable_runs (base_session,run_label,fitness_score) VALUES (%s,%s,0)", (data['job']['session_name'], run_label))
                run_id = c.lastrowid
            placed = 0; unplaced = []
            sess = data['job']['session_name']
            conn.autocommit(False)
            with conn.cursor() as c:
                for ai, sl in enumerate(best_c):
                    a = P.A[ai]
                    for j, sid in enumerate(sl):
                        ri = room_of.get(ai * 8 + j)
                        comp = 'Lab' if j >= a['nT'] else 'Theory'
                        ok = False
                        if ri is not None:
                            try:
                                c.execute("INSERT INTO generated_timetable (run_id,session_name,course_id,faculty_id,room_id,slot_id,component_type) VALUES (%s,%s,%s,%s,%s,%s,%s)",
                                          (run_id, sess, a['cid'], a['fid'], P.rooms[ri]['room_id'], sid, comp)); ok = True
                            except pymysql.err.IntegrityError:
                                ok = False       # the database itself refused a clash
                        if ok: placed += 1
                        else: unplaced.append({'course_id': a['cid'], 'course_code': a['code'], 'component': comp, 'slot_id': sid, 'day': P.sday[sid]})
                    expected = a['nT'] + (1 if a['lab'] else 0)
                    for j in range(len(sl), expected):
                        unplaced.append({'course_id': a['cid'], 'course_code': a['code'], 'component': 'Lab' if j >= a['nT'] else 'Theory', 'slot_id': None, 'day': None})
                final_fit = best_f
                c.execute("UPDATE timetable_runs SET fitness_score=%s, classes_count=%s, unplaced_json=%s WHERE run_id=%s",
                          (final_fit, placed, json.dumps(unplaced) if unplaced else None, run_id))
            conn.commit(); conn.autocommit(True)
            fb_cnt = 0
            for k, ri in room_of.items():
                if ri is not None and P.comp_of(k)[1] == 0 and P.rooms[ri]['room_type'] == 'Lab': fb_cnt += 1
            msg = 'Done - %d classes. ' % placed
            if unplaced:
                msg += 'NOT READY: %d class(es) have no valid room/time (do not publish). Add time slots (see Slots tab hint). First: %s' % (
                    len(unplaced), '; '.join('%s %s' % (u['course_code'], u['day'] or '-') for u in unplaced[:5]))
            else:
                msg += ('PERFECT - zero conflicts, every class has a valid room.' if best_f < P_BACKTOBACK else 'Best solution found (soft constraints remain).')
                if fb_cnt: msg += ' Note: %d theory class(es) use a lab room (fallback).' % fb_cnt
            msg += ' [MPI x%d, %d gens, %.1fs]' % (size, total_gens, ga_time)
            with conn.cursor() as c:
                c.execute("UPDATE ga_jobs SET status='complete',progress=%s,dept_done=1,dept_total=1,fitness_score=%s,classes_count=%s,run_id=%s,run_label=%s,updated_at=NOW() WHERE job_id=%s",
                          (msg[:250], best_f, placed, run_id, run_label, job_id))
            print(json.dumps({'ranks': size, 'seconds': round(ga_time, 2), 'generations': total_gens, 'penalty': best_f, 'placed': placed, 'unplaced': len(unplaced)}))
        except Exception as e:
            try: conn.rollback()
            except Exception: pass
            progress(conn, job_id, 'Error: %s' % e, 'failed')
            raise

if __name__ == '__main__':
    main()
