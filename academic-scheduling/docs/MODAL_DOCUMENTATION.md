# SlotSmart Timetable Modals Documentation
**Understanding the View Slot & Edit Entry Modals**

---

## 📋 Overview

There are **TWO modals** in the timetable system:

1. **View Slot Modal** - Shows all classes in a time slot (click "+ X more")
2. **Edit Entry Modal** - Edit room/time for a specific class (click a class card)

**User Flow:**
```
Grid Cell (shows 1 class + button)
    ↓ Click "+ 12 more, click to view"
View Slot Modal (shows all 12 classes)
    ↓ Click a specific class card
Edit Entry Modal (change room/time)
```

---

## 🎯 Modal 1: View Slot Classes Modal

### **Purpose**
Display **all classes** scheduled at a specific day/time slot in a nice 2-column grid.

### **When It Opens**
When user clicks the blue button: **"+ 12 more, click to view"**

### **Location in Code**

#### **HTML Structure** (`dashboard.php`)
**File:** `D:\wamp123\www\Slotsmart-1\dashboard.php`  
**Lines:** 184-199

```html
<!-- View Slot Classes Modal -->
<div class="modal fade" id="viewSlotModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="bi bi-calendar-event me-2"></i>
                    <span id="slot-modal-title">Classes at This Time</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3" id="slot-modal-body" style="max-height:70vh;overflow-y:auto;">
                <!-- Classes will be dynamically loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
```

#### **Key Elements**
| Element | ID | Purpose |
|---------|----|---------  |
| Modal Container | `viewSlotModal` | Bootstrap modal wrapper |
| Modal Title | `slot-modal-title` | Shows "X Classes on Monday at 08:00" |
| Modal Body | `slot-modal-body` | Container where class cards are rendered |

---

#### **JavaScript - Open Modal** (`main.js`)
**File:** `D:\wamp123\www\Slotsmart-1\assets\js\main.js`  
**Function:** `expandCell(cellId)`  
**Lines:** 1620-1710

```javascript
window.expandCell = function(cellId) {
    // Retrieve stored data
    const cellInfo = _cellData[cellId];
    if (!cellInfo) return;
    const { matches } = cellInfo;

    // Extract day and time from cellId (format: cell-Monday-0800)
    const parts = cellId.split('-');
    const day = parts[1] || '';
    const time = parts[2] || '';
    const formattedTime = time.substring(0,2) + ':' + time.substring(2);

    // Set modal title
    document.getElementById('slot-modal-title').textContent =
        `${matches.length} Class${matches.length > 1 ? 'es' : ''} on ${day} at ${formattedTime}`;

    // Build classes list
    const modalBody = document.getElementById('slot-modal-body');
    let html = '<div class="row g-3">';

    matches.forEach((m, idx) => {
        const isLab = m.component_type === 'Lab';
        const color = getDeptColor(m.department_id);
        const accent = isLab ? '#0d6efd' : '#198754';
        const bgCard = isLab ? '#e7f1ff' : '#f0fdf4';
        const typeTag = isLab
            ? `<span class="badge" style="background:#0d6efd;">LAB</span>`
            : `<span class="badge" style="background:#198754;">LECTURE</span>`;
        const creditHours = m.credit_hours || '--';
        const enrolled = m.enrolled_students || 0;
        const cap = m.capacity || 0;
        const pct = cap > 0 ? Math.round((enrolled / cap) * 100) : 0;
        const barColor = pct > 90 ? '#dc3545' : pct > 70 ? '#fd7e14' : '#198754';
        const cardId = `modal-class-${idx}`;

        // Store entry data for edit modal
        _cellData[cardId] = {
            timetable_id: m.timetable_id,
            course_code: m.course_code,
            course_name: m.course_name,
            component_type: m.component_type,
            room_id: m.room_id,
            faculty_id: m.faculty_id,
            slot_id: m.slot_id
        };

        html += `
        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-0" 
                 style="border-left:4px solid ${accent} !important;background:${bgCard};cursor:pointer;transition:all .2s;"
                 onclick="openClassEdit('${cardId}')"
                 onmouseover="this.style.boxShadow='0 6px 16px rgba(0,0,0,.15)';this.style.transform='translateY(-3px)'"
                 onmouseout="this.style.boxShadow='0 1px 3px rgba(0,0,0,.1)';this.style.transform='translateY(0)'">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <span class="badge fw-bold" style="background:${color};">${m.course_code}</span>
                            ${typeTag}
                        </div>
                        <span class="badge bg-light text-dark border">
                            <i class="bi bi-door-closed"></i> ${m.room_number}
                        </span>
                    </div>
                    <h6 class="card-title mb-2">${m.course_name}</h6>
                    <p class="card-text text-muted mb-2">
                        <i class="bi bi-person-fill"></i> ${m.faculty_name}<br>
                        <i class="bi bi-book"></i> Credit Hours: <strong>${creditHours}</strong>
                    </p>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-muted">Enrollment</small>
                            <small style="color:${barColor};">${enrolled}/${cap} (${pct}%)</small>
                        </div>
                        <div class="progress" style="height:5px;">
                            <div class="progress-bar" style="width:${pct}%;background:${barColor};"></div>
                        </div>
                    </div>
                    <div class="text-center pt-2 border-top">
                        <small class="text-primary fw-semibold">
                            <i class="bi bi-pencil"></i> Click to edit room/time
                        </small>
                    </div>
                </div>
            </div>
        </div>`;
    });

    html += '</div>';
    modalBody.innerHTML = html;

    // Show modal
    new bootstrap.Modal(document.getElementById('viewSlotModal')).show();
};
```

#### **How the Button is Created** (`main.js`)
**Lines:** 1547-1553 (in `renderTimetableGrid` function)

```javascript
// Add expand button if multiple classes
if (remaining > 0) {
    html += `
    <div class="screen-only-button" style="text-align:center;margin-top:5px;">
        <button onclick="expandCell('${cellId}')"
                style="background:#4361ee;color:#fff;border:none;padding:4px 10px;border-radius:4px;font-size:.7rem;cursor:pointer;font-weight:600;">
            + ${remaining} more, click to view
        </button>
    </div>`;
}
```

#### **Data Storage**
**Lines:** 1514 (stores all classes for each cell)

```javascript
// Store matches data globally for expand/collapse
_cellData[cellId] = { matches, rowH };
```

**Global Variable:**
```javascript
// Line 1306
const _cellData = {};
```

---

### **Visual Breakdown**

```
┌─────────────────────────────────────────────────┐
│  12 Classes on Monday at 08:00            ✕    │ ← Modal Header (slot-modal-title)
├─────────────────────────────────────────────────┤
│  ┌───────────────┐  ┌───────────────┐          │
│  │ CS201    LAB  │  │ EE301    LEC  │          │ ← 2-column grid
│  │ Programming   │  │ Circuits      │          │
│  │ 👤 Dr. Smith  │  │ 👤 Dr. Khan   │          │
│  │ 📚 0-1-0      │  │ 📚 3-0-3      │          │
│  │ 📊 25/30 83%  │  │ 📊 45/50 90%  │          │
│  │ ✏️ Click edit │  │ ✏️ Click edit │          │ ← Click handler
│  └───────────────┘  └───────────────┘          │
│  ... more cards ...                             │ ← Scrollable (max-height:70vh)
├─────────────────────────────────────────────────┤
│                         [Close]                 │ ← Modal Footer
└─────────────────────────────────────────────────┘
```

---

## 🎯 Modal 2: Edit Entry Modal

### **Purpose**
Edit the **room** and **time slot** for a specific class after it's been generated.

### **When It Opens**
When user clicks a **class card** in the View Slot Modal.

### **Location in Code**

#### **HTML Structure** (`dashboard.php`)
**File:** `D:\wamp123\www\Slotsmart-1\dashboard.php`  
**Lines:** 153-181

```html
<div class="modal fade" id="editEntryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>Edit Class Entry
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-timetable-id">
                <div class="alert alert-info py-2 small" id="edit-entry-info"></div>
                <div class="mb-3">
                    <label class="form-label fw-bold small">Room</label>
                    <select class="form-select form-select-sm" id="edit-room-id"></select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small">Time Slot</label>
                    <select class="form-select form-select-sm" id="edit-slot-id"></select>
                </div>
                <div id="edit-conflict-alert" class="alert alert-danger d-none py-2 small"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="btn-save-edit-entry">
                    <i class="bi bi-check-lg me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>
```

#### **Key Elements**
| Element | ID | Purpose |
|---------|----|---------  |
| Modal Container | `editEntryModal` | Bootstrap modal wrapper |
| Hidden Input | `edit-timetable-id` | Stores timetable_id for update |
| Info Alert | `edit-entry-info` | Shows course code & name |
| Room Dropdown | `edit-room-id` | Select new room |
| Slot Dropdown | `edit-slot-id` | Select new time slot |
| Conflict Alert | `edit-conflict-alert` | Shows error if conflict |
| Save Button | `btn-save-edit-entry` | Triggers update |

---

#### **JavaScript - Open Edit Modal** (`main.js`)
**File:** `D:\wamp123\www\Slotsmart-1\assets\js\main.js`  
**Function:** `openEditModal(entry)` (EXISTING - was already in code)  
**Lines:** 1094-1118

```javascript
window.openEditModal = function(entry) {
    document.getElementById('edit-timetable-id').value = entry.timetable_id;
    document.getElementById('edit-entry-info').innerHTML =
        `<strong>${entry.course_code}</strong> — ${entry.course_name}<br>
         <span class="badge bg-${entry.component_type==='Lab'?'primary':'success'}">${entry.component_type}</span>`;
    document.getElementById('edit-conflict-alert').classList.add('d-none');

    loadEditDropdowns(() => {
        // Fill room dropdown
        const roomSel = document.getElementById('edit-room-id');
        roomSel.innerHTML = allRooms.map(r =>
            `<option value="${r.room_id}" ${r.room_id == entry.room_id ? 'selected' : ''}>
                ${r.room_number} (${r.room_type}, cap: ${r.capacity})
            </option>`).join('');

        // Fill slot dropdown
        const slotSel = document.getElementById('edit-slot-id');
        slotSel.innerHTML = allSlots.map(s =>
            `<option value="${s.slot_id}" ${s.slot_id == entry.slot_id ? 'selected' : ''}>
                ${s.day} ${s.start_time.substring(0,5)}–${s.end_time.substring(0,5)}
            </option>`).join('');

        new bootstrap.Modal(document.getElementById('editEntryModal')).show();
    });
};
```

#### **JavaScript - Bridge Function** (`main.js`)
**Function:** `openClassEdit(cardId)` (NEW - added by me)  
**Lines:** 1606-1617

```javascript
window.openClassEdit = function(cardId) {
    const entry = _cellData[cardId];
    if (!entry) return;

    // Close view modal first
    const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewSlotModal'));
    if (viewModal) viewModal.hide();

    // Open edit modal
    openEditModal(entry);
};
```

**Why This Exists:**  
Because we can't pass complex JSON objects directly in `onclick` attributes, we:
1. Store class data in `_cellData[cardId]`
2. Pass simple `cardId` string to `openClassEdit()`
3. Retrieve data and call `openEditModal()`

---

#### **JavaScript - Save Changes** (`main.js`)
**Event Listener attached to save button**  
**Lines:** 1121-1149

```javascript
document.getElementById('btn-save-edit-entry')?.addEventListener('click', function() {
    const tid     = document.getElementById('edit-timetable-id').value;
    const room_id = document.getElementById('edit-room-id').value;
    const slot_id = document.getElementById('edit-slot-id').value;
    const alertEl = document.getElementById('edit-conflict-alert');
    alertEl.classList.add('d-none');
    this.disabled = true;

    const fd = new FormData();
    fd.append('action',       'update_timetable_entry');
    fd.append('timetable_id', tid);
    fd.append('room_id',      room_id);
    fd.append('slot_id',      slot_id);

    fetch('admin_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('editEntryModal'))?.hide();
                // Reload timetable to reflect changes
                const session = document.getElementById('filter-session')?.value;
                if (session) loadTimetable(session);
            } else {
                alertEl.textContent = data.message;
                alertEl.classList.remove('d-none');
            }
        });
});
```

---

### **Backend API** (`admin_handler.php`)
**Action:** `update_timetable_entry`  
**Lines:** 1005-1064

```php
case 'update_timetable_entry':
    // Get the entry being edited
    $stmt = $pdo->prepare("SELECT * FROM generated_timetable WHERE timetable_id = ?");
    $stmt->execute([$_POST['timetable_id']]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry) sendResponse(false, "Entry not found.");

    // Validate new room
    $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = ?");
    $roomStmt->execute([$_POST['room_id']]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) sendResponse(false, "Invalid room selected.");

    // Validate new slot
    $slotStmt = $pdo->prepare("SELECT * FROM time_slots WHERE slot_id = ?");
    $slotStmt->execute([$_POST['slot_id']]);
    $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot) sendResponse(false, "Invalid time slot selected.");

    // Check for conflicts (room clash, faculty clash)
    $conflictCheck = $pdo->prepare("
        SELECT * FROM generated_timetable 
        WHERE session_name = ? 
          AND slot_id = ? 
          AND (room_id = ? OR faculty_id = ?)
          AND timetable_id != ?
          AND (run_id = ? OR (run_id IS NULL AND ? IS NULL))
    ");
    $conflictCheck->execute([
        $entry['session_name'],
        $_POST['slot_id'],
        $_POST['room_id'],
        $entry['faculty_id'],
        $_POST['timetable_id'],
        $entry['run_id'],
        $entry['run_id']
    ]);
    
    if ($conflictCheck->rowCount() > 0) {
        $conflict = $conflictCheck->fetch(PDO::FETCH_ASSOC);
        $conflictType = ($conflict['room_id'] == $_POST['room_id']) ? "Room" : "Faculty";
        sendResponse(false, "$conflictType clash detected with another class!");
    }

    // Update the entry
    $updateStmt = $pdo->prepare("
        UPDATE generated_timetable 
        SET room_id = ?, slot_id = ? 
        WHERE timetable_id = ?
    ");
    $updateStmt->execute([
        $_POST['room_id'],
        $_POST['slot_id'],
        $_POST['timetable_id']
    ]);

    sendResponse(true, "Class updated successfully!");
    break;
```

---

### **Visual Breakdown**

```
┌─────────────────────────────────────┐
│  ✏️ Edit Class Entry           ✕   │ ← Modal Header
├─────────────────────────────────────┤
│  ┌─────────────────────────────┐   │
│  │ CS201 — Programming         │   │ ← Info Alert (edit-entry-info)
│  │ [LAB]                       │   │
│  └─────────────────────────────┘   │
│                                     │
│  Room                               │
│  ┌─────────────────────────────┐   │
│  │ Lab-305 (Lab, cap: 30)  ▼  │   │ ← Room Dropdown (edit-room-id)
│  └─────────────────────────────┘   │
│                                     │
│  Time Slot                          │
│  ┌─────────────────────────────┐   │
│  │ Monday 08:00–09:30      ▼  │   │ ← Slot Dropdown (edit-slot-id)
│  └─────────────────────────────┘   │
│                                     │
│  [❌ Room clash detected!]          │ ← Conflict Alert (hidden by default)
├─────────────────────────────────────┤
│  [Cancel]    [✓ Save Changes]      │ ← Footer Buttons
└─────────────────────────────────────┘
```

---

## 🔄 Complete Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│  TIMETABLE GRID (dashboard.php + main.js)              │
│                                                         │
│  Monday 08:00 Cell:                                    │
│  ┌───────────────────────────────┐                     │
│  │ CS201 LAB                     │ ← First class       │
│  │ Programming                   │   (always visible)  │
│  │ Room 305 • 0-1-0              │                     │
│  └───────────────────────────────┘                     │
│  [+ 11 more, click to view] ← Button onclick=          │
│                                 expandCell('cell-...')  │
└─────────────────────────────────────────────────────────┘
                    ↓ Click Button
┌─────────────────────────────────────────────────────────┐
│  VIEW SLOT MODAL (viewSlotModal)                       │
│  File: dashboard.php lines 184-199                     │
│  Function: expandCell() in main.js lines 1620-1710    │
│                                                         │
│  Shows ALL 12 classes in 2-column grid                │
│  Each card has onclick="openClassEdit('modal-class-0')"│
└─────────────────────────────────────────────────────────┘
                    ↓ Click a Class Card
┌─────────────────────────────────────────────────────────┐
│  BRIDGE FUNCTION                                        │
│  openClassEdit(cardId) - main.js lines 1606-1617      │
│  1. Retrieves class data from _cellData[cardId]       │
│  2. Closes viewSlotModal                               │
│  3. Calls openEditModal(entry)                         │
└─────────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────────┐
│  EDIT ENTRY MODAL (editEntryModal)                     │
│  File: dashboard.php lines 153-181                     │
│  Function: openEditModal() in main.js lines 1094-1118 │
│                                                         │
│  User selects new room & time slot                     │
│  Clicks [Save Changes]                                 │
└─────────────────────────────────────────────────────────┘
                    ↓ Click Save
┌─────────────────────────────────────────────────────────┐
│  SAVE HANDLER                                           │
│  Event listener on btn-save-edit-entry                 │
│  main.js lines 1121-1149                               │
│                                                         │
│  POST to admin_handler.php:                            │
│  {                                                      │
│    action: 'update_timetable_entry',                   │
│    timetable_id: 123,                                  │
│    room_id: 5,                                         │
│    slot_id: 8                                          │
│  }                                                      │
└─────────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────────┐
│  BACKEND API                                            │
│  admin_handler.php lines 1005-1064                     │
│                                                         │
│  1. Validates room & slot exist                        │
│  2. Checks for conflicts (room/faculty clash)          │
│  3. Updates database if no conflicts                   │
│  4. Returns success/error                              │
└─────────────────────────────────────────────────────────┘
                    ↓ Success
┌─────────────────────────────────────────────────────────┐
│  RELOAD TIMETABLE                                       │
│  Modal closes, grid refreshes with updated data        │
└─────────────────────────────────────────────────────────┘
```

---

## 📁 File Structure Summary

```
D:\wamp123\www\Slotsmart-1\
│
├── dashboard.php
│   ├── Line 153-181: Edit Entry Modal HTML
│   └── Line 184-199: View Slot Modal HTML
│
├── assets/js/main.js
│   ├── Line 1306: _cellData = {} (global storage)
│   ├── Line 1094-1118: openEditModal(entry) - Opens edit modal
│   ├── Line 1121-1149: Save button event listener
│   ├── Line 1514: Store cell data when rendering grid
│   ├── Line 1547-1553: Create "+ X more" button
│   ├── Line 1606-1617: openClassEdit(cardId) - Bridge function
│   └── Line 1620-1710: expandCell(cellId) - Opens view slot modal
│
└── admin_handler.php
    └── Line 1005-1064: update_timetable_entry API
```

---

## 🎨 CSS Classes Used

### **View Slot Modal**
```css
.modal-lg          /* Bootstrap - Large modal width */
.bg-dark           /* Bootstrap - Dark header background */
.text-white        /* Bootstrap - White text */
.btn-close-white   /* Bootstrap - White close button */
.card              /* Bootstrap - Card container */
.shadow-sm         /* Bootstrap - Subtle shadow */
.badge             /* Bootstrap - Badge component */
.progress          /* Bootstrap - Progress bar */
```

### **Edit Entry Modal**
```css
.modal-dialog      /* Bootstrap - Modal container */
.bg-primary        /* Bootstrap - Blue header */
.form-select-sm    /* Bootstrap - Small select dropdown */
.alert-info        /* Bootstrap - Blue info box */
.alert-danger      /* Bootstrap - Red error box */
.d-none            /* Bootstrap - Display none (hidden) */
```

### **Print Styles** (`style.css`)
```css
.print-only-class     /* Hidden on screen, visible in print */
.screen-only-button   /* Visible on screen, hidden in print */
```

---

## 🔧 JavaScript Global Variables

```javascript
// Line 1306 in main.js
const _cellData = {};
```

**Structure:**
```javascript
_cellData = {
  // Grid cells (stores all classes for a slot)
  'cell-Monday-0800': {
    matches: [classObj1, classObj2, ...],
    rowH: 148
  },
  
  // Modal class cards (stores single class for edit)
  'modal-class-0': {
    timetable_id: 123,
    course_code: 'CS201',
    course_name: 'Programming',
    component_type: 'Lab',
    room_id: 5,
    faculty_id: 12,
    slot_id: 8
  }
}
```

---

## 🚀 Testing the Modals

### **Test View Slot Modal:**
1. Go to: `http://localhost/Slotsmart-1/dashboard.php`
2. Generate a timetable (if not already loaded)
3. Find a cell with multiple classes (shows "+ X more" button)
4. Click the button
5. ✅ Modal should open showing all classes in 2-column grid

### **Test Edit Entry Modal:**
1. Open View Slot Modal (above)
2. Click any class card
3. ✅ View Slot Modal closes
4. ✅ Edit Entry Modal opens
5. Change room or time slot
6. Click "Save Changes"
7. ✅ Modal closes, timetable refreshes

### **Test Print (No Buttons in PDF):**
1. Load timetable
2. Click Print button
3. ✅ PDF preview shows all classes
4. ✅ No "+ X more" buttons visible
5. ✅ Only class cards shown

---

## 📝 Quick Reference

| What | Where |
|------|-------|
| View Slot Modal HTML | `dashboard.php` L184-199 |
| Edit Entry Modal HTML | `dashboard.php` L153-181 |
| expandCell() function | `main.js` L1620-1710 |
| openEditModal() function | `main.js` L1094-1118 |
| openClassEdit() bridge | `main.js` L1606-1617 |
| Save button handler | `main.js` L1121-1149 |
| Backend update API | `admin_handler.php` L1005-1064 |
| Data storage | `main.js` L1306 (_cellData) |
| Print styles | `style.css` L136-248 |

---

**Last Updated:** 2026-06-12  
**Author:** Claude (Sonnet 4.5)  
**Project:** SlotSmart Timetable System
