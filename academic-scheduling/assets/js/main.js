// assets/js/main.js

const REGEX = {
    email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
    phone: /^\d{10,15}$/,
    text: /^[A-Za-z0-9\s\-\.]+$/
};

// DataTables storage to handle destroy/re-init
let dataTableInstances = {};

// ── Time-slot hint: shows how many slots the entered rooms/classes need at least ──
function refreshSlotHint() {
    const box = document.getElementById('slot-hint');
    if (!box) return;
    const fd = new FormData();
    fd.append('action', 'get_capacity_hint');
    fetch('admin_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { box.innerHTML = ''; return; }
            const h = res.data, e = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            if (!h.allocations) {
                box.innerHTML = '<div class="alert alert-secondary mb-0 py-2"><i class="bi bi-lightbulb me-1"></i>Slot hint appears once rooms, courses, allocations and enrollments are entered.</div>';
                return;
            }
            const row = (label, k) => {
                const have = h.have[k], min = h.min[k], rec = h.recommended[k];
                if (h.sessions[k] === 0) return '';
                let cls = 'text-success', note = 'enough';
                if (have < min) { cls = 'text-danger'; note = 'add at least ' + (min - have) + ' more'; }
                else if (have < rec) { cls = 'text-warning'; note = 'possible, but tight; aim for ' + rec; }
                else if (have > rec * 2.5) { note = 'more than needed; extra slots only slow generation'; cls = 'text-info'; }
                return '<li><b>' + label + ':</b> ' + h.sessions[k] + ' weekly sessions in ' + h.rooms[k] + ' room(s) &rarr; at least <b>' + min + '</b> slots/week (recommended ' + rec + '). You have <b>' + have + '</b> &mdash; <span class="' + cls + ' fw-semibold">' + note + '</span>' +
                    (h.why[k] ? '<br><small class="text-muted">Because: ' + e(h.why[k]) + '.</small>' : '') + '</li>';
            };
            const bad = (h.min.Theory > h.have.Theory) || (h.min.Lab > h.have.Lab) || h.noRoom.length;
            const tight = (h.recommended.Theory > h.have.Theory) || (h.recommended.Lab > h.have.Lab);
            let html = '<div class="alert alert-' + (bad ? 'danger' : tight ? 'warning' : 'success') + ' mb-0"><div class="fw-bold mb-1"><i class="bi bi-lightbulb me-1"></i>Time-slot hint (session ' + e(h.session) + ')</div><ul class="mb-1 ps-3">' +
                row('Theory (non-lab slots)', 'Theory') + row('Lab (160&ndash;210 min slots)', 'Lab') + '</ul>';
            if (h.noRoom.length) {
                html += '<div class="mt-1"><b>No room can ever host:</b> ' + h.noRoom.slice(0, 12).map(x => e(x.course) + ' (' + x.part.toLowerCase() + ', ' + x.students + ' students)').join(', ') +
                    (h.noRoom.length > 12 ? ' &hellip;' : '') + '. More slots will not fix this &mdash; add a bigger/better-equipped room or reduce enrolment.</div>';
            }
            if (h.overlapNote) html += '<div class="mt-1 text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>' + e(h.overlapNote) + '</div>';
            html += '<small class="text-muted">Minimums are exact lower bounds with theory kept in lecture halls (a free lab room can take a theory class only as an emergency fallback; rooms, teachers and student groups cannot be in two places at once); the recommended figure is 2&times; the minimum (tested: 1.5&times; still left a class without a room on a 720-class set, 2&times; placed all).</small></div>';
            box.innerHTML = html;
        })
        .catch(() => { box.innerHTML = ''; });
}
$(document).on('draw.dt', function (ev) { if (ev.target && ev.target.id === 'table-slots') refreshSlotHint(); });

document.addEventListener('DOMContentLoaded', function () {
    loadDropdowns();

    // ── Step 1: Set up tab event listeners FIRST ──────────────
    // IMPORTANT: listeners must exist before tab.show() fires shown.bs.tab
    const tabButtons = document.querySelectorAll('button[data-bs-toggle="pill"]');
    tabButtons.forEach(button => {
        button.addEventListener('shown.bs.tab', event => {
            localStorage.setItem('activeAdminTab', event.target.getAttribute('data-bs-target'));
            const target = event.target.getAttribute('data-bs-target');
            const pane = target.replace('#', '').replace('-pane', '');
            const tbl = 'table-' + pane;

            if (SERVER_SIDE_TABLES[tbl]) {
                if (!$.fn.DataTable.isDataTable('#' + tbl)) initServerSideTable(tbl);
                else $('#' + tbl).DataTable().ajax.reload();
            } else {
                const tbody = document.querySelector('#' + tbl + ' tbody');
                if (tbody && tbody.children.length === 0) loadTable(tbl);
            }

            if (target === '#slots-pane') refreshSlotHint();
            if (target === '#allocations-pane' || target === '#enrollments-pane') {
                loadCoursesDropdown();
            }
            if (target === '#enrollments-pane') {
                initStudentSearch();
            }
        });
    });

    // ── Step 2: Restore previously active tab (listeners are ready now) ──
    const savedTab = localStorage.getItem('activeAdminTab');
    if (savedTab) {
        const triggerEl = document.querySelector(`button[data-bs-target="${savedTab}"]`);
        if (triggerEl) {
            new bootstrap.Tab(triggerEl).show();
            return; // shown.bs.tab will handle loading the table
        }
    }

    // ── Step 3: No saved tab — load the default visible tab's table ──
    loadActiveTabTable();

    // Student search input listener
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'student-search') {
            // handled internally by initStudentSearch
        }
    });
});

// ALERT SYSTEM
function showSystemMessage(message, type = 'success') {
    const alertArea = document.getElementById('system-alert-area');
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show shadow-sm py-2 px-3 mb-2`;
    alertDiv.style.fontSize = "0.9rem";
    alertDiv.innerHTML = `
        <strong>${type === 'success' ? 'Success!' : 'Error!'}</strong> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="padding: 0.8rem"></button>
    `;
    alertArea.appendChild(alertDiv);
    setTimeout(() => {
        alertDiv.classList.remove('show');
        setTimeout(() => alertDiv.remove(), 150);
    }, 3000);
}

// CSV BULK UPLOAD FUNCTION
function uploadCSV(formId) {
    const form = document.getElementById(formId);
    if (!form) {
        showSystemMessage("Form not found: " + formId, "danger");
        return;
    }

    const fileInput = form.querySelector('input[type="file"]');
    if (!fileInput || !fileInput.files.length) {
        showSystemMessage("Please select a CSV file first.", "danger");
        return;
    }

    const file = fileInput.files[0];
    if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
        showSystemMessage("Please select a valid CSV file.", "danger");
        return;
    }

    const importType = form.querySelector('input[name="import_type"]');
    if (!importType) {
        showSystemMessage("Import type not specified.", "danger");
        return;
    }

    const formData = new FormData();
    formData.append('action', 'import_csv');
    formData.append('csv_file', file);
    formData.append('import_type', importType.value);

    // Show loading state
    const btn = form.querySelector('button[type="button"]');
    const originalText = btn ? btn.innerHTML : 'Import';
    if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Importing...';
    if (btn) btn.disabled = true;

    fetch('admin_handler.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showSystemMessage(data.message, "success");
                fileInput.value = '';
                // Only reload the table that was just imported
                const importTypeToTable = {
                    departments: 'table-departments', programs: 'table-programs',
                    courses: 'table-courses', faculty: 'table-faculty',
                    students: 'table-students', rooms: 'table-rooms',
                    time_slots: 'table-slots', allocations: 'table-allocations',
                    enrollments: 'table-enrollments'
                };
                const targetTable = importTypeToTable[importType.value];
                if (targetTable) loadTable(targetTable);
                loadDropdowns();
            } else {
                showSystemMessage(data.message, "danger");
            }
        })
        .catch(err => {
            console.error(err);
            showSystemMessage("Error uploading file.", "danger");
        })
        .finally(() => {
            if (btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        });
}

// ENROLLMENT CSV TEMPLATE DOWNLOAD
// FORM SUBMISSION HANDLER
function validateAndSubmit(actionName, formId, tableId) {
    const form = document.getElementById(formId);
    let isFormValid = true;

    // Required Checks
    const requiredInputs = form.querySelectorAll('[required]');
    requiredInputs.forEach(input => {
        if (!input.value.trim()) {
            input.classList.add('is-invalid');
            isFormValid = false;
        } else {
            input.classList.remove('is-invalid');
        }
    });

    if (!isFormValid) {
        showSystemMessage("Please fix the fields highlighted in red.", "danger");
        return;
    }

    // Submit Data
    const formData = new FormData(form);
    formData.append('action', actionName);

    fetch('admin_handler.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showSystemMessage(data.message, "success");

                // Reset form state to "Add" mode
                const btnId = form.querySelector('button[type="button"].btn-primary, button[type="button"].btn-success').id;
                const origAction = actionName.replace('update_', 'add_');

                // Reset button text
                let origText = 'Save';
                if (btnId.includes('enroll')) origText = 'Enroll';

                resetFormState(formId, btnId, origAction, origText);

                if (tableId) loadTable(tableId);
                loadDropdowns();

            } else {
                showSystemMessage(data.message, "danger");
            }
        })
        .catch(err => {
            console.error(err);
            showSystemMessage("Error processing request.", "danger");
        });
}

function deleteRecord(actionName, id, tableId) {
    if (!confirm("Are you sure you want to delete this record?")) return;

    const formData = new FormData();
    formData.append('action', actionName);
    formData.append('id', id);

    fetch('admin_handler.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showSystemMessage(data.message, "success");
                if (tableId) loadTable(tableId);
                loadDropdowns();
            } else {
                showSystemMessage(data.message, "danger");
            }
        })
        .catch(err => console.error(err));
}

// Tables too large to load all at once — use server-side DataTables pagination
const SERVER_SIDE_TABLES = {
    'table-students':    'get_students_paged',
    'table-enrollments': 'get_enrollments_paged',
    'table-courses':     'get_courses_paged',
};

function initServerSideTable(tableId) {
    const action = SERVER_SIDE_TABLES[tableId];
    if (!action) return;

    if ($.fn.DataTable.isDataTable('#' + tableId)) {
        $('#' + tableId).DataTable().destroy();
    }

    const colDefs = {
        'table-students': [
            { data: 'registration_number', defaultContent: '-' },
            { data: 'student_name', defaultContent: '-', render: (d, t, row) => {
                return d || '';
            }},
            { data: 'email', defaultContent: '-' },
            { data: 'program_name', defaultContent: '-' },
            { data: 'student_type', defaultContent: 'regular', render: d => d === 'transfer'
                ? '<span class="badge bg-warning text-dark">Transfer</span>'
                : '<span class="badge bg-success">Regular</span>' },
            { data: 'student_id', defaultContent: '', render: () => '' }
        ],
        'table-enrollments': [
            { data: 'batch_year', defaultContent: '-' },
            { data: 'semester', defaultContent: '-' },
            { data: 'student_name', defaultContent: '-' },
            { data: 'course_name', defaultContent: '-' },
            { data: 'enrollment_id', defaultContent: '', render: () => '' }
        ],
        'table-courses': [
            { data: 'course_code', defaultContent: '-' },
            { data: 'course_name', defaultContent: '-' },
            { data: 'credit_hours', defaultContent: '-' },
            { data: 'department_name', defaultContent: '-' },
            { data: 'program_name', defaultContent: '-' },
            { data: 'course_id', defaultContent: '', render: () => '' }
        ],
    };

    const editFns = {
        'table-students':    (row) => editStudent(row),
        'table-enrollments': (row) => editEnrollment(row),
        'table-courses':     (row) => editCourse(row),
    };
    const deleteFns = {
        'table-students':    { action: 'delete_student',    id: 'student_id'    },
        'table-enrollments': { action: 'delete_enrollment', id: 'enrollment_id' },
        'table-courses':     { action: 'delete_course',     id: 'course_id'     },
    };
    // The server must include the ID field in the response data for delete to work
    // students → student_id, enrollments → enrollment_id, courses → course_id

    $('#' + tableId).DataTable({
        serverSide: true,
        processing: true,
        pageLength: 25,
        ajax: {
            url: 'admin_handler.php',
            type: 'POST',
            data: d => { d.action = action; return d; },
            error: (xhr, err, thrown) => {
                showSystemMessage('Table load error: ' + (thrown || err), 'danger');
            }
        },
        columns: colDefs[tableId],
        createdRow: function(row, data) {
            const lastTd = row.lastElementChild;
            lastTd.innerHTML = '';
            const editBtn = document.createElement('button');
            editBtn.className = 'btn btn-sm btn-outline-warning py-0 px-2 me-1';
            editBtn.innerHTML = '<i class="bi bi-pencil"></i>';
            editBtn.onclick = () => editFns[tableId](data);
            const delBtn = document.createElement('button');
            delBtn.className = 'btn btn-sm btn-outline-danger py-0 px-2';
            delBtn.innerHTML = '<i class="bi bi-trash"></i>';
            delBtn.onclick = () => deleteRecord(deleteFns[tableId].action, data[deleteFns[tableId].id], tableId);
            lastTd.appendChild(editBtn);
            lastTd.appendChild(delBtn);
        },
        language: { search: '', searchPlaceholder: 'Search...', processing: '<span class="spinner-border spinner-border-sm me-2"></span>Loading...' }
    });
}

// === TABLE LOADER WITH DATATABLES ===
function loadTable(tableId) {
    // Route large tables to server-side pagination — never load all rows at once
    if (SERVER_SIDE_TABLES[tableId]) {
        if ($.fn.DataTable.isDataTable('#' + tableId))
            $('#' + tableId).DataTable().ajax.reload();
        else
            initServerSideTable(tableId);
        return;
    }

    let action = '';
    if (tableId === 'table-departments') action = 'get_departments';
    if (tableId === 'table-programs') action = 'get_programs';
    if (tableId === 'table-faculty') action = 'get_faculty';
    if (tableId === 'table-rooms') action = 'get_rooms';
    if (tableId === 'table-slots') action = 'get_slots';
    if (tableId === 'table-allocations') action = 'get_allocations';

    if (!action) return;

    // Destroy existing DataTable before replacing HTML content
    if ($.fn.DataTable.isDataTable(`#${tableId}`)) {
        $(`#${tableId}`).DataTable().destroy();
    }

    const formData = new FormData();
    formData.append('action', action);
    formData.append('nocache', new Date().getTime());

    fetch('admin_handler.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            const tbody = document.querySelector(`#${tableId} tbody`);
            if (!tbody) return;
            tbody.innerHTML = '';

            if (data.success && data.data.length > 0) {
                data.data.forEach(row => {
                    let tr = document.createElement('tr');

                    if (tableId === 'table-departments') {
                        tr.innerHTML = `<td>${row.department_id}</td><td>${row.department_name}</td>`;
                        tr.appendChild(createActionButtons(row, 'editDepartment', 'delete_department', row.department_id, tableId));
                    }
                    else if (tableId === 'table-programs') {
                        tr.innerHTML = `<td>${row.program_id}</td><td>${row.program_name}</td><td>${row.department_name}</td>`;
                        tr.appendChild(createActionButtons(row, 'editProgram', 'delete_program', row.program_id, tableId));
                    }
                    else if (tableId === 'table-faculty') {
                        tr.innerHTML = `<td>${row.faculty_name}</td><td>${row.email}</td><td>${row.phone_number}</td><td>${row.department_name}</td>`;
                        tr.appendChild(createActionButtons(row, 'editFaculty', 'delete_faculty', row.faculty_id, tableId));
                    }
                    else if (tableId === 'table-rooms') {
                        tr.innerHTML = `<td>${row.room_number}</td><td>${row.capacity}</td><td>${row.room_type}</td><td>${equipSummary(row.equipment)}</td>`;
                        tr.appendChild(createActionButtons(row, 'editRoom', 'delete_room', row.room_id, tableId));
                    }
                    else if (tableId === 'table-slots') {
                        tr.innerHTML = `<td>${row.day}</td><td>${row.start_time}</td><td>${row.end_time}</td>`;
                        tr.appendChild(createActionButtons(row, 'editSlot', 'delete_slot', row.slot_id, tableId));
                    }
                    else if (tableId === 'table-allocations') {
                        tr.innerHTML = `<td>${row.session_name}</td><td>${row.faculty_name}</td><td>${row.course_name}</td>`;
                        tr.appendChild(createActionButtons(row, 'editAllocation', 'delete_allocation', row.allocation_id, tableId));
                    }
                    tbody.appendChild(tr);
                });
            }

            // Re-initialize DataTable after DOM is updated
            initDataTable(tableId);
        });
}

function initDataTable(tableId) {
    // Basic DataTable configuration
    $(`#${tableId}`).DataTable({
        "destroy": true, // Ensure we can re-init
        "paging": true,
        "lengthChange": false, // Hide "Show 10 entries" dropdown to save space
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "pageLength": 10,
        "language": {
            "search": "",
            "searchPlaceholder": "Search..."
        }
    });
}

function createActionButtons(row, editFunc, deleteAction, id, tableId) {
    const td = document.createElement('td');

    // Very compact buttons
    const editBtn = document.createElement('button');
    editBtn.className = 'btn btn-sm btn-outline-warning py-0 px-2 me-1';
    editBtn.innerHTML = '<i class="bi bi-pencil"></i>';
    editBtn.onclick = () => window[editFunc](row);

    const delBtn = document.createElement('button');
    delBtn.className = 'btn btn-sm btn-outline-danger py-0 px-2';
    delBtn.innerHTML = '<i class="bi bi-trash"></i>';
    delBtn.onclick = () => deleteRecord(deleteAction, id, tableId);

    td.appendChild(editBtn);
    td.appendChild(delBtn);
    return td;
}

// === EDIT FUNCTIONS (unchanged logic, just layout triggers) ===

function editDepartment(row) {
    setupEditForm('form-department', 'btn-save-dept', 'update_department', 'Update', 'btn-cancel-dept');
    setVal('#form-department [name="id"]', row.department_id);
    setVal('#form-department [name="department_name"]', row.department_name);
}

function editProgram(row) {
    setupEditForm('form-program', 'btn-save-prog', 'update_program', 'Update', 'btn-cancel-prog');
    setVal('#form-program [name="id"]', row.program_id);
    setVal('#form-program [name="program_name"]', row.program_name);
    setVal('#form-program [name="department_id"]', row.department_id);
}

function editCourse(row) {
    // 1. Setup the form for UPDATE mode (Changes button text and action field)
    setupEditForm('form-course', 'btn-save-course', 'update_course', 'Update Course', 'btn-cancel-course');

    // 2. Map standard row data to form fields using your helper
    setVal('#form-course [name="id"]', row.course_id);
    setVal('#form-course [name="course_name"]', row.course_name);
    setVal('#form-course [name="course_code"]', row.course_code);
    setVal('#form-course [name="credit_hours"]', row.credit_hours);
    setVal('#form-course [name="is_lab"]', row.is_lab);
    setVal('#form-course [name="department_id"]', row.department_id);
    setVal('#form-course [name="program_id"]', row.program_id);

    // 3. Equipment rows
    fillEqRows('course-eq-rows', 'course', row.requires_equipment);
}

function editRoom(row) {
    // 1. Setup the form for UPDATE mode
    setupEditForm('form-room', 'btn-save-room', 'update_room', 'Update Room', 'btn-cancel-room');

    // 2. Map standard row data
    setVal('#form-room [name="id"]', row.room_id);
    setVal('#form-room [name="room_number"]', row.room_number);
    setVal('#form-room [name="floor_number"]', row.floor_number);
    setVal('#form-room [name="capacity"]', row.capacity);
    setVal('#form-room [name="type"]', row.type);

    // 3. Equipment rows
    fillEqRows('room-eq-rows', 'room', row.equipment);
}

function editFaculty(row) {
    setupEditForm('form-faculty', 'btn-save-fac', 'update_faculty', 'Update', 'btn-cancel-fac');
    setVal('#form-faculty [name="id"]', row.faculty_id);
    setVal('#form-faculty [name="faculty_name"]', row.faculty_name);
    setVal('#form-faculty [name="email"]', row.email);
    setVal('#form-faculty [name="phone_number"]', row.phone_number);
    setVal('#form-faculty [name="department_id"]', row.department_id);
}

function editStudent(row) {
    setupEditForm('form-student', 'btn-save-std', 'update_student', 'Update', 'btn-cancel-std');
    setVal('#form-student [name="id"]', row.student_id);
    setVal('#form-student [name="student_name"]', row.student_name);
    setVal('#form-student [name="email"]', row.email);
    setVal('#form-student [name="registration_number"]', row.registration_number);
    setVal('#form-student [name="phone_number"]', row.phone_number);
    setVal('#form-student [name="dob"]', row.dob);
    setVal('#form-student [name="gender"]', row.gender);
    setVal('#form-student [name="student_type"]', row.student_type || 'regular');
    setVal('#form-student [name="department_id"]', row.department_id);
    loadPrograms(row.department_id, 'student-program', row.program_id);
}

function editSlot(row) {
    setupEditForm('form-slot', 'btn-save-slot', 'update_slot', 'Update', 'btn-cancel-slot');
    setVal('#form-slot [name="id"]', row.slot_id);
    setVal('#form-slot [name="day"]', row.day);
    setVal('#form-slot [name="start_time"]', row.start_time);
    setVal('#form-slot [name="end_time"]', row.end_time);
}

function editAllocation(row) {
    setupEditForm('form-allocation', 'btn-save-alloc', 'update_allocation', 'Update', 'btn-cancel-alloc');
    setVal('#form-allocation [name="id"]', row.allocation_id);
    setVal('#form-allocation [name="session_name"]', row.session_name);
    setVal('#form-allocation [name="faculty_id"]', row.faculty_id);
    setVal('#form-allocation [name="course_id"]', row.course_id);
}

function editEnrollment(row) {
    setupEditForm('form-enrollment', 'btn-save-enroll', 'update_enrollment', 'Update', 'btn-cancel-enroll');
    setVal('#form-enrollment [name="id"]', row.enrollment_id);
    setVal('#form-enrollment [name="batch_year"]', row.batch_year || '');
    setVal('#form-enrollment [name="semester"]', row.semester);
    setVal('#form-enrollment [name="course_id"]', row.course_id);
    // Pre-fill student search with the student's name, then select them
    fetch('admin_handler.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'search_students', q: row.student_name || String(row.student_id) })
    })
    .then(r => r.json())
    .then(data => {
        const searchInput = document.getElementById('student-search');
        const sel = document.querySelector('#form-enrollment .student-select');
        if (!sel) return;
        sel.innerHTML = '';
        data.data.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.student_id;
            opt.textContent = `${s.student_name} (${s.registration_number || '-'})`;
            sel.appendChild(opt);
        });
        sel.value = row.student_id;
        if (searchInput) searchInput.value = row.student_name || '';
    });
}

// === HELPERS ===

function setVal(selector, value) {
    const el = document.querySelector(selector);
    if (el) el.value = value || '';
}

function setupEditForm(formId, btnId, action, btnText, cancelBtnId) {
    const btn = document.getElementById(btnId);
    btn.textContent = btnText;
    const oldOnClick = btn.getAttribute('onclick');
    let tableId = '';
    if (oldOnClick && oldOnClick.includes("'")) {
        tableId = oldOnClick.split("'")[5];
    }
    btn.setAttribute('onclick', `validateAndSubmit('${action}', '${formId}', '${tableId}')`);

    const cancelBtn = document.getElementById(cancelBtnId);
    if (cancelBtn) cancelBtn.classList.remove('d-none');

    // Smooth scroll to top of form
    document.getElementById(formId).scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function resetFormState(formId, btnId, originalAction, originalText) {
    document.getElementById(formId).reset();
    document.querySelector(`#${formId} [name="id"]`).value = "";

    const btn = document.getElementById(btnId);
    btn.textContent = originalText;

    const oldOnClick = btn.getAttribute('onclick');
    let tableId = '';
    if (oldOnClick && oldOnClick.includes("'")) {
        tableId = oldOnClick.split("'")[5];
    }
    btn.setAttribute('onclick', `validateAndSubmit('${originalAction}', '${formId}', '${tableId}')`);

    const cancelBtn = document.querySelector(`#${formId} .btn-secondary`);
    if (cancelBtn) cancelBtn.classList.add('d-none');
}

// Load only the table in the currently active tab
function loadActiveTabTable() {
    const paneMap = {
        'departments-pane': 'table-departments', 'programs-pane': 'table-programs',
        'courses-pane': 'table-courses',         'faculty-pane': 'table-faculty',
        'students-pane': 'table-students',        'rooms-pane': 'table-rooms',
        'slots-pane': 'table-slots',              'allocations-pane': 'table-allocations',
        'enrollments-pane': 'table-enrollments'
    };
    const activePane = document.querySelector('.tab-pane.active');
    if (activePane) {
        const tbl = paneMap[activePane.id];
        if (tbl) loadTable(tbl);
    }
}

// Only reload tables that are already initialized (visible/active ones)
function refreshAllTables() {
    const allTables = [
        'table-departments','table-programs','table-courses','table-faculty',
        'table-students','table-rooms','table-slots','table-allocations','table-enrollments'
    ];
    allTables.forEach(tbl => {
        // Server-side tables: reload only if already initialized
        if (SERVER_SIDE_TABLES[tbl]) {
            if ($.fn.DataTable.isDataTable('#' + tbl))
                $('#' + tbl).DataTable().ajax.reload();
        } else {
            // Client-side tables: only reload if the table is in an active/visible pane
            const el = document.getElementById(tbl);
            if (el && el.closest('.tab-pane.active')) loadTable(tbl);
        }
    });
}




function populateSelect(selectorClass, items, valKey, textKey) {
    document.querySelectorAll(selectorClass).forEach(select => {
        const savedVal = select.value;
        select.innerHTML = '<option value="" disabled selected>Select...</option>';
        if (items) {
            items.forEach(item => {
                let opt = document.createElement('option');
                opt.value = item[valKey];
                opt.textContent = item[textKey];
                select.appendChild(opt);
            });
        }
        if (savedVal) select.value = savedVal;
    });
}

function loadDropdowns() {
    // 1. Load Departments
    fetch('admin_handler.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'get_departments' })
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const selects = document.querySelectorAll('.dept-select');
                selects.forEach(select => {
                    // Preserve default option
                    const defaultOption = select.querySelector('option[disabled]')
                        ? select.querySelector('option[disabled]').outerHTML
                        : '<option value="" selected disabled>Select Department</option>';

                    let options = defaultOption;
                    data.data.forEach(dept => {
                        options += `<option value="${dept.department_id}">${dept.department_name}</option>`;
                    });
                    select.innerHTML = options;
                });
            }
        })
        .catch(err => console.error('Error loading departments:', err));

    // 2. Load Faculty
    if (document.querySelector('.faculty-select')) {
        fetch('admin_handler.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'get_faculty' })
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const selects = document.querySelectorAll('.faculty-select');
                    selects.forEach(select => {
                        let options = '<option value="" selected disabled>Select Faculty</option>';
                        data.data.forEach(fac => {
                            options += `<option value="${fac.faculty_id}">${fac.faculty_name}</option>`;
                        });
                        select.innerHTML = options;
                    });
                }
            })
            .catch(err => console.error('Error loading faculty:', err));
    }

    // Courses and Students are NOT pre-loaded here — too large.
    // Courses load lazily when the allocation/enrollment tab opens.
    // Students use AJAX search (type-to-search, max 20 results).

    // populate semester datalist hints
    const dl = document.getElementById('semester-hints');
    if (dl) {
        fetch('admin_handler.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'get_dropdowns' })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            dl.innerHTML = '';
            (data.data.semesters || []).forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.semester;
                dl.appendChild(opt);
            });
        });
    }
}

// === ENROLLMENT STUDENT SEARCH (AJAX, max 20 results) ===
let _pendingStudentId = null;
let _studentSearchTimer = null;

function initStudentSearch() {
    const searchInput = document.getElementById('student-search');
    const sel = document.querySelector('#form-enrollment .student-select');
    if (!searchInput || !sel) return;

    sel.innerHTML = '<option value="" disabled selected>Type above to search...</option>';

    searchInput.addEventListener('input', function () {
        clearTimeout(_studentSearchTimer);
        const q = this.value.trim();
        if (q.length < 2) {
            sel.innerHTML = '<option value="" disabled selected>Type at least 2 characters...</option>';
            return;
        }
        _studentSearchTimer = setTimeout(() => {
            fetch('admin_handler.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'search_students', q })
            })
            .then(r => r.json())
            .then(data => {
                sel.innerHTML = '<option value="" disabled selected>Select a student</option>';
                if (data.data.length === 0) {
                    sel.innerHTML = '<option value="" disabled>No matches found</option>';
                    return;
                }
                data.data.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.student_id;
                    opt.textContent = `${s.student_name} (${s.registration_number || '-'})`;
                    sel.appendChild(opt);
                });
                if (_pendingStudentId !== null) {
                    sel.value = _pendingStudentId;
                    _pendingStudentId = null;
                }
            });
        }, 300);
    });
}

// === COURSES LAZY LOADER (called once when tab opens) ===
let _coursesLoaded = false;
function loadCoursesDropdown() {
    if (_coursesLoaded) return;
    fetch('admin_handler.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'get_courses' })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        _coursesLoaded = true;
        document.querySelectorAll('.course-select').forEach(sel => {
            const current = sel.value;
            sel.innerHTML = '<option value="" disabled selected>Select Course</option>';
            data.data.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.course_id;
                opt.textContent = `${c.course_code} – ${c.course_name}`;
                sel.appendChild(opt);
            });
            if (current) sel.value = current;
        });
        // Add filter input above each course select if not already present
        document.querySelectorAll('.course-select').forEach(sel => {
            if (sel.previousElementSibling?.classList.contains('course-filter')) return;
            const fi = document.createElement('input');
            fi.type = 'text';
            fi.className = 'form-control form-control-sm mb-1 course-filter';
            fi.placeholder = 'Filter courses...';
            fi.addEventListener('input', function () {
                const q = this.value.toLowerCase();
                Array.from(sel.options).forEach(opt => {
                    opt.hidden = q && !opt.textContent.toLowerCase().includes(q);
                });
            });
            sel.parentNode.insertBefore(fi, sel);
        });
    });
}
// === LOAD PROGRAMS (Dependent Dropdown) ===
// This is triggered by onchange="loadPrograms(...)" in your HTML
function loadPrograms(deptId, targetSelectId, selectedProgramId = null) {
    const targetSelect = document.getElementById(targetSelectId);
    if (!targetSelect) return;

    targetSelect.innerHTML = '<option value="" disabled selected>Loading...</option>';

    const formData = new FormData();
    formData.append('action', 'get_programs');
    // We fetch all programs and filter JS-side, or you can add a 'get_programs_by_dept' action to PHP
    // Assuming standard get_programs returns all:

    fetch('admin_handler.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const programs = data.data.filter(p => p.department_id == deptId);

                let options = '<option value="" selected disabled>Select Program</option>';
                if (programs.length === 0) {
                    options = '<option value="" disabled>No programs found</option>';
                } else {
                    programs.forEach(prog => {
                        const isSelected = (selectedProgramId && selectedProgramId == prog.program_id) ? 'selected' : '';
                        options += `<option value="${prog.program_id}" ${isSelected}>${prog.program_name}</option>`;
                    });
                }
                targetSelect.innerHTML = options;
            } else {
                targetSelect.innerHTML = '<option value="" disabled>Error loading</option>';
            }
        })
        .catch(err => {
            console.error(err);
            targetSelect.innerHTML = '<option value="" disabled>Error</option>';
        });
}
// =============================================================
// SlotSmart — Dashboard Timetable Module v5
// Replace the second DOMContentLoaded block in main.js
// (from line 625 to end of file)
//
// Lab classes now sit in their own 3hr time column — no colspan
// merging needed. Each time column is rendered independently.
// =============================================================

document.addEventListener('DOMContentLoaded', function () {

    // Tracks the currently viewed run_id (null = auto-latest)
    let _activeRunId = null;
    window._activeRunId = null; // exposed for viewSavedRun

    // ── Session dropdown (dynamic, always fresh from DB) ──────
    function loadSessionDropdown(callback) {
        const sel = document.getElementById('filter-session');
        if (!sel) { if (callback) callback(); return; }

        const fd = new FormData();
        fd.append('action', 'get_sessions');
        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                sel.innerHTML = '';
                const sessions = (data.success && Array.isArray(data.data)) ? data.data : [];
                if (sessions.length === 0) {
                    sel.innerHTML = '<option value="">No sessions found</option>';
                } else {
                    sessions.forEach((s, i) => {
                        const opt = document.createElement('option');
                        opt.value = s;
                        opt.textContent = s;
                        if (i === 0) opt.selected = true;
                        sel.appendChild(opt);
                    });
                }
                if (callback) callback();
            })
            .catch(() => {
                sel.innerHTML = '<option value="">Error loading</option>';
                if (callback) callback();
            });
    }

    // ── Publish marker ────────────────────────────────────────
    let _runsCache = [];
    function _selectedRun() {
        const id = window._activeRunId || (_runsCache[0] && _runsCache[0].run_id);
        return _runsCache.find(r => +r.run_id === +id) || null;
    }
    function _refreshPublishUI() {
        const btn = document.getElementById('btn-publish-run'), badge = document.getElementById('publish-badge');
        if (!btn || !badge) return;
        const pub = _runsCache.find(r => r.published_at);
        badge.classList.toggle('d-none', !pub);
        if (pub) badge.textContent = 'Published: ' + (pub.generated_at || '').substring(0, 16).replace('T', ' ');
        const sel = _selectedRun();
        btn.classList.toggle('d-none', !sel);
        if (sel) { btn.innerHTML = sel.published_at ? '<i class="bi bi-x-circle"></i> Unpublish' : '<i class="bi bi-megaphone"></i> Publish'; }
    }
    document.getElementById('btn-publish-run')?.addEventListener('click', function () {
        const sel = _selectedRun(); if (!sel) return;
        const un = !!sel.published_at;
        if (!confirm(un ? 'Un-publish this timetable?' : 'Publish this timetable version? Later changes to it will require a reason and be logged.')) return;
        const fd = new FormData();
        fd.append('action', un ? 'unpublish_run' : 'publish_run');
        fd.append('run_id', sel.run_id);
        fetch('admin_handler.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            alert(d.message);
            const s = document.getElementById('filter-session')?.value;
            if (s) loadRunPicker(s);
        });
    });

    // ── Run picker — load versions for selected session ───────
    function loadRunPicker(baseSession) {
        const runSel = document.getElementById('filter-run');
        if (!runSel) return;
        const fd = new FormData();
        fd.append('action', 'get_timetable_runs');
        fd.append('base_session', baseSession);
        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                const runs = data.data || [];
                _runsCache = runs; _refreshPublishUI();
                if (runs.length <= 1) {
                    runSel.classList.add('d-none');
                    return;
                }
                runSel.classList.remove('d-none');
                runSel.innerHTML = '<option value="">📌 Current Official Timetable</option>';
                runs.forEach((r, i) => {
                    const opt = document.createElement('option');
                    opt.value = r.run_id;
                    const dt = r.generated_at ? r.generated_at.substring(0, 16).replace('T', ' ') : '';
                    const prefix = i === 0 ? '📌 Current: ' : '🕐 Previous: ';
                    opt.textContent = `${prefix}${dt} (${r.classes_count} classes)`;
                    if (i === 0) opt.selected = true;
                    runSel.appendChild(opt);
                });
                // Add help text below dropdown (only once)
                if (!runSel.nextElementSibling?.classList.contains('run-help-text')) {
                    const help = document.createElement('small');
                    help.className = 'text-muted ms-2 run-help-text';
                    help.textContent = 'Compare timetable versions';
                    runSel.parentNode.insertBefore(help, runSel.nextSibling);
                }
            })
            .catch(() => runSel.classList.add('d-none'));
    }

    // When session changes, reset run picker
    const sessionSel = document.getElementById('filter-session');
    if (sessionSel) {
        sessionSel.addEventListener('change', function () {
            _activeRunId = null;
            window._activeRunId = null;
            const runSel = document.getElementById('filter-run');
            if (runSel) { runSel.innerHTML = '<option value="">Latest Version</option>'; runSel.classList.add('d-none'); }
            loadRunPicker(this.value);
        });
    }

    const runSel = document.getElementById('filter-run');
    if (runSel) {
        runSel.addEventListener('change', function () {
            _activeRunId = this.value ? parseInt(this.value) : null;
            window._activeRunId = _activeRunId;
            _refreshPublishUI();
        });
    }

    // Load session first, THEN load the timetable so the correct session value is present
    loadSessionDropdown(function () {
        loadFilterDropdowns();
        const initSession = document.getElementById('filter-session')?.value;
        if (initSession) loadRunPicker(initSession);
        loadTimetable();
    });

    const genBtn = document.getElementById('btn-generate-tt');
    if (genBtn) genBtn.addEventListener('click', generateTimetableGA);

    const filterBtn = document.getElementById('btn-apply-filter');
    if (filterBtn) filterBtn.addEventListener('click', loadTimetable);

    window.printTimetable = () => window.print();

    // ── CSV Export ────────────────────────────────────────────
    const csvBtn = document.getElementById('btn-export-csv');
    if (csvBtn) {
        csvBtn.addEventListener('click', function () {
            const session = document.getElementById('filter-session')?.value || '';
            const runId   = window._activeRunId || '';
            if (!session) { alert('Please select a session first.'); return; }
            let url = `export_csv.php?session=${encodeURIComponent(session)}`;
            if (runId) url += `&run_id=${runId}`;
            window.location.href = url;
        });
    }

    // ── JSON Export ───────────────────────────────────────────
    const jsonBtn = document.getElementById('btn-export-json');
    if (jsonBtn) {
        jsonBtn.addEventListener('click', function () {
            const session = document.getElementById('filter-session')?.value || '';
            const runId   = window._activeRunId || '';
            if (!session) { alert('Please select a session first.'); return; }
            let url = `export_json.php?session=${encodeURIComponent(session)}`;
            if (runId) url += `&run_id=${runId}`;
            window.location.href = url;
        });
    }

    // ── Saved Timetables Modal ────────────────────────────────
    const savedModal = document.getElementById('savedTimetablesModal');
    if (savedModal) {
        savedModal.addEventListener('show.bs.modal', loadSavedTimetables);
    }

    function loadSavedTimetables() {
        const tbody = document.getElementById('saved-tt-body');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Loading...</td></tr>';
        const fd = new FormData();
        fd.append('action', 'get_timetable_sessions');
        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.data.length) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No saved timetables found.</td></tr>';
                    return;
                }
                tbody.innerHTML = data.data.map(row => {
                    const hasRunId = row.run_id != null;
                    const fitBadge = row.fitness_score == 0
                        ? '<span class="badge bg-success">Perfect</span>'
                        : `<span class="badge bg-warning text-dark">${row.fitness_score}</span>`;
                    return `<tr>
                        <td class="fw-bold">${row.session_name}</td>
                        <td class="small text-muted">${row.run_label || row.session_name}</td>
                        <td class="text-center"><span class="badge bg-primary">${row.total_classes}</span></td>
                        <td class="text-center">${fitBadge}</td>
                        <td class="small text-muted">${row.generated_at || '—'}</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-success me-1"
                                onclick="viewSavedRun(${JSON.stringify(row)})">
                                <i class="bi bi-eye"></i> View
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="${hasRunId ? `deleteTimetableRun(${row.run_id}, this)` : `deleteSavedSession('${row.session_name}', this)`}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>`;
                }).join('');
            });
    }

    window.viewSavedRun = function(row) {
        bootstrap.Modal.getInstance(document.getElementById('savedTimetablesModal'))?.hide();
        const sel = document.getElementById('filter-session');
        if (sel) sel.value = row.session_name || row.base_session || '';
        _activeRunId = row.run_id ? parseInt(row.run_id) : null;
        window._activeRunId = _activeRunId;
        setTimeout(() => loadTimetable(), 300);
    };

    window.deleteSavedSession = function(sessionName, btn) {
        if (!confirm(`Delete all legacy timetable data for "${sessionName}"? This cannot be undone.`)) return;
        btn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'delete_timetable_session');
        fd.append('session_name', sessionName);
        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) { loadSavedTimetables(); }
                else { alert(data.message); btn.disabled = false; }
            });
    };

    window.deleteTimetableRun = function(runId, btn) {
        if (!confirm('Delete this timetable run? This cannot be undone.')) return;
        btn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'delete_timetable_run');
        fd.append('run_id', runId);
        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) { loadSavedTimetables(); }
                else { alert(data.message); btn.disabled = false; }
            });
    };

    // ── Manual Edit: load rooms/slots/faculty once ────────────
    let editDropdownsLoaded = false;
    let allRooms = [], allSlots = [], allFaculty = [];

    function loadEditDropdowns(cb) {
        if (editDropdownsLoaded) { cb(); return; }
        const fd = new FormData();
        fd.append('action', 'get_rooms_and_slots');
        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    allRooms   = data.data.rooms   || [];
                    allSlots   = data.data.slots   || [];
                    allFaculty = data.data.faculty || [];
                    editDropdownsLoaded = true;
                }
                cb();
            });
    }

    // Open edit modal when clicking a class card
    window.openEditModal = function(entry) {
        document.getElementById('edit-timetable-id').value = entry.timetable_id;
        window._editVersion = entry.version;   // optimistic lock: server refuses if someone else edited it meanwhile
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

    // Save edit entry
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
        if (window._editReason) fd.append('reason', window._editReason);
        if (window._editVersion !== undefined && window._editVersion !== null) fd.append('expected_version', window._editVersion);

        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                this.disabled = false;
                if (!data.success && data.data && data.data.needs_reason) {
                    const why = prompt('This timetable is published. Reason for this change (required, saved in the change log):');
                    window._editReason = (why || '').trim();
                    if (window._editReason) this.click();
                    return;
                }
                window._editReason = '';
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

    // ── Colour palette (by department_id) ────────────────────
    const PALETTE = ['#0d6efd','#198754','#dc3545','#ffc107','#0dcaf0','#6610f2','#fd7e14','#20c997'];
    const _cc = {};
    function getDeptColor(id) {
        const k = String(id);
        if (!_cc[k]) _cc[k] = PALETTE[Object.keys(_cc).length % PALETTE.length];
        return _cc[k];
    }

    // ── Time helpers ──────────────────────────────────────────
    // Normalise 'HH:MM:SS' or 'HH:MM' → 'HH:MM'
    function norm(t) {
        if (!t) return '';
        const p = String(t).trim().split(':');
        return p.length >= 2 ? `${p[0].padStart(2,'0')}:${p[1].padStart(2,'0')}` : t;
    }

    // '08:30' → '8:30 AM'
    function fmt12(t) {
        const [hh, mm] = t.split(':').map(Number);
        return `${hh % 12 || 12}:${String(mm).padStart(2,'0')} ${hh < 12 ? 'AM' : 'PM'}`;
    }

    // Duration in minutes between two 'HH:MM' strings
    function durationMins(start, end) {
        const toMins = s => { const [h,m] = s.split(':').map(Number); return h*60+m; };
        return toMins(end) - toMins(start);
    }

    // ==========================================================
    // GENERATE (GA) — synchronous run with spinner feedback
    // ==========================================================
    function generateTimetableGA() {
        const session = document.getElementById('filter-session')?.value || '';
        if (!session) { alert('Please select a session first.'); return; }
        if (!confirm(`Generate optimised timetable for "${session}"?\n\nRuns in the background — you can watch progress live.\nA new version will be saved without overwriting previous ones.`)) return;

        const statusEl = document.getElementById('generation-status');
        const btn      = document.getElementById('btn-generate-tt');
        const startedAt = Date.now();

        if (btn)      { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Starting…'; }
        if (statusEl) { statusEl.innerHTML = 'Starting…'; statusEl.className = 'badge bg-warning text-dark border'; }

        // Step 1: dispatch job (returns immediately with job_id)
        const fd = new FormData();
        fd.append('session', session);

        fetch('ga_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.job_id) {
                    showSystemMessage(data.message || 'Could not start GA.', 'danger');
                    if (statusEl) { statusEl.innerHTML = 'Failed'; statusEl.className = 'badge bg-danger border'; }
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-cpu"></i> Generate Timetable (GA)'; }
                    return;
                }

                // Step 2: poll ga_status.php every 2 seconds
                const jobId = data.job_id;
                let pollInterval = null;

                function pollStatus() {
                    fetch(`ga_status.php?job_id=${jobId}`)
                        .then(r => r.json())
                        .then(st => {
                            const elapsed = Math.round((Date.now() - startedAt) / 1000);

                            // Update button and badge while running
                            if (!st.done) {
                                if (btn) btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> ${st.progress || 'Running…'} (${elapsed}s)`;
                                if (statusEl) {
                                    statusEl.innerHTML = `${st.progress || 'Running'} (${elapsed}s)`;
                                    statusEl.className = 'badge bg-warning text-dark border';
                                }
                                return; // keep polling
                            }

                            // Job finished
                            clearInterval(pollInterval);
                            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-cpu"></i> Generate Timetable (GA)'; }

                            if (st.failed) {
                                showSystemMessage(st.progress || 'GA failed.', 'danger');
                                if (statusEl) { statusEl.innerHTML = 'Failed'; statusEl.className = 'badge bg-danger border'; }
                            } else {
                                const cls = st.classes_count || 0;
                                showSystemMessage(`${st.message} | ${cls} classes scheduled | ${elapsed}s`, 'success');
                                if (statusEl) {
                                    statusEl.innerHTML = `Done — ${cls} classes (${elapsed}s)`;
                                    statusEl.className = 'badge bg-success border';
                                }
                                if (st.run_id) { _activeRunId = st.run_id; window._activeRunId = st.run_id; }
                                loadSessionDropdown(() => {
                                    const sel = document.getElementById('filter-session');
                                    if (sel) sel.value = session;
                                    loadRunPicker(session);
                                    loadTimetable();
                                });
                            }
                        })
                        .catch(() => {
                            // network blip — keep polling, don't abort
                        });
                }

                // Poll immediately, then every 2 seconds
                pollStatus();
                pollInterval = setInterval(pollStatus, 2000);
            })
            .catch(err => {
                showSystemMessage('Network error — check server logs.', 'danger');
                if (statusEl) { statusEl.innerHTML = 'Network error'; statusEl.className = 'badge bg-danger border'; }
                if (btn)      { btn.disabled = false; btn.innerHTML = '<i class="bi bi-cpu"></i> Generate Timetable (GA)'; }
                console.error(err);
            });
    }
    function loadFilterDropdowns() {
        const fd = new FormData();
        fd.append('action', 'get_dropdowns');
        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                fillSelect('#filter-dept',     data.data?.departments || [], 'department_id', 'department_name');
                fillSelect('#filter-faculty',  data.data?.faculty     || [], 'faculty_id',    'faculty_name');
                fillSelect('#filter-batch',    data.data?.batches     || [], 'batch_year',    'batch_year');
                fillSelect('#filter-room',     data.data?.rooms       || [], 'room_id',       'room_number');
                fillSelect('#filter-program',  data.data?.programs    || [], 'program_id',    'program_name');
                fillSelect('#filter-course',   data.data?.courses     || [], 'course_id',     'course_name');
                fillSelect('#filter-semester', data.data?.semesters   || [], 'semester',      'semester');
            })
            .catch(e => console.error('Dropdown error:', e));
    }

    function fillSelect(sel, items, vk, tk) {
        const el = document.querySelector(sel);
        if (!el) return;
        const first = el.options[0];
        el.innerHTML = '';
        el.appendChild(first);
        items.forEach(item => {
            const o = document.createElement('option');
            o.value = item[vk]; o.textContent = item[tk];
            el.appendChild(o);
        });
    }

    // Shared state for resolve modal
    let _unscheduledCourses = [];
    let _currentRunId = null;
    let _currentBaseSession = '';

    // Cell data storage for collapsible grid
    const _cellData = {};

    // ==========================================================
    // LOAD TIMETABLE DATA
    // ==========================================================
    function loadTimetable() {
        const container   = document.getElementById('timetable-container');
        const displayInfo = document.getElementById('display-filter-info');
        if (!container) return;

        const session  = document.getElementById('filter-session')?.value  || '';
        const dept     = document.getElementById('filter-dept')?.value     || 'all';
        const faculty  = document.getElementById('filter-faculty')?.value  || 'all';
        const batch    = document.getElementById('filter-batch')?.value    || '';
        const room     = document.getElementById('filter-room')?.value     || 'all';
        const program  = document.getElementById('filter-program')?.value  || 'all';
        const course   = document.getElementById('filter-course')?.value   || 'all';
        const semester = document.getElementById('filter-semester')?.value || '';
        const runId    = window._activeRunId || '';

        if (displayInfo) displayInfo.textContent = session + (runId ? ` (Run #${runId})` : '');

        container.innerHTML = `<div class="text-center p-5">
            <div class="spinner-border text-primary mb-3"></div>
            <div class="text-muted fw-semibold">Loading timetable…</div></div>`;

        const fd = new FormData();
        fd.append('action',        'get_generated_timetable');
        fd.append('session',       session);
        fd.append('department_id', dept);
        fd.append('faculty_id',    faculty);
        fd.append('batch_year',    batch);
        fd.append('room_id',       room);
        fd.append('program_id',    program);
        fd.append('course_id',     course);
        fd.append('semester',      semester);
        if (runId) fd.append('run_id', runId);

        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    container.innerHTML = `<div class="alert alert-danger m-3">${data.message || 'Error loading timetable.'}</div>`;
                    return;
                }

                const scheduled   = Array.isArray(data.data) ? data.data : (data.data?.scheduled   ?? []);
                const unscheduled = Array.isArray(data.data) ? []        : (data.data?.unscheduled ?? []);
                _unscheduledCourses  = unscheduled;
                _currentRunId        = data.data?.run_id       ?? null;
                _currentBaseSession  = data.data?.base_session ?? session;

                if (scheduled.length > 0) {
                    renderTimetableGrid(scheduled);
                    updateStatusSidebars(scheduled);
                } else {
                    container.innerHTML = `
                    <div class="alert alert-light text-center m-4 border rounded-3 p-5">
                        <i class="bi bi-calendar-x fs-1 text-muted d-block mb-3"></i>
                        <h5 class="fw-bold">No Classes Scheduled Yet</h5>
                        <p class="text-muted mb-0">Generate a timetable for <strong>${session}</strong> first.</p>
                    </div>`;
                }

                const alertArea = document.getElementById('system-alert-area');
                if (alertArea) {
                    alertArea.querySelectorAll('.unscheduled-alert').forEach(el => el.remove());
                    if (unscheduled.length > 0) {
                        const names = unscheduled.slice(0, 3).map(c => `<strong>${c.course_code}</strong>`).join(', ');
                        const more  = unscheduled.length > 3 ? ` +${unscheduled.length - 3} more` : '';
                        alertArea.insertAdjacentHTML('beforeend', `
                            <div class="alert alert-warning alert-dismissible fade show unscheduled-alert" role="alert">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>${unscheduled.length} unscheduled course(s):</strong>
                                ${names}${more}.
                                <button class="btn btn-sm btn-warning ms-2" type="button"
                                    data-bs-toggle="modal" data-bs-target="#resolveUnscheduledModal"
                                    onclick="openResolveModal()">
                                    <i class="bi bi-tools me-1"></i>Resolve
                                </button>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>`);
                    }
                }
            })
            .catch(err => {
                console.error(err);
                container.innerHTML = '<div class="alert alert-danger m-3">Error loading timetable data.</div>';
            });
    }

    // ==========================================================
    // RENDER TIMETABLE GRID
    //
    // Each time slot = one column. Lab columns are wider (3hr)
    // than theory columns (1.5hr) — width is proportional to
    // the slot duration so the grid visually reflects real time.
    //
    // No colspan merging needed — every row gets the same set
    // of <td> cells. Lab just appears in its 3hr time column.
    // ==========================================================
    function renderTimetableGrid(data) {
        const DAYS      = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
        const DAY_SHORT = ['Mon','Tue','Wed','Thu','Fri'];

        // Build time-slot map: key=norm(start_time), value={end_time, mins}
        const slotMap = {};
        data.forEach(r => {
            const st = norm(r.start_time);
            const et = norm(r.end_time);
            if (st && !slotMap[st]) {
                slotMap[st] = {
                    end_time: et,
                    mins: et ? durationMins(st, et) : 90
                };
            }
        });
        const times = Object.keys(slotMap).sort();

        // Layout: TIME column on left (sticky), one column per day
        const COL_TIME = 100;   // px - sticky time label
        const COL_DAY  = 175;   // px - each day column
        const totalW   = COL_TIME + DAYS.length * COL_DAY;

        let html = `<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table style="
            width:100%;
            min-width:${totalW}px;
            border-collapse:separate;
            border-spacing:0;
            font-family:inherit;
            table-layout:fixed;
        ">
        <colgroup>
            <col style="width:${COL_TIME}px;">`;

        DAYS.forEach(() => {
            html += `<col style="width:${COL_DAY}px;">`;
        });

        // Header row: TIME | Mon | Tue | Wed | Thu | Fri
        html += `</colgroup>
        <thead><tr>
            <th style="
                background:#1a1d2e;color:#9aa0b4;
                font-size:.65rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
                padding:12px 6px;text-align:center;vertical-align:middle;
                border-right:2px solid #2d3250;border-bottom:2px solid #4361ee;
                position:sticky;left:0;z-index:3;
            ">TIME</th>`;

        DAYS.forEach((day, i) => {
            html += `<th style="
                background:#1a1d2e;color:#e2e5f1;
                font-size:.78rem;font-weight:700;
                padding:12px 6px;text-align:center;vertical-align:middle;
                border-right:${i === DAYS.length - 1 ? 'none' : '1px solid #2d3250'};
                border-bottom:2px solid #4361ee;
            ">${DAY_SHORT[i]}<span style="display:block;font-size:.62rem;font-weight:400;opacity:.7;margin-top:1px;">${day.substring(3)}</span></th>`;
        });

        html += `</tr></thead><tbody>`;

        // One row per time slot
        times.forEach((st, ti) => {
            const et       = slotMap[st]?.end_time || '';
            const mins     = slotMap[st]?.mins || 90;
            const isLab3hr = mins >= 170;
            const isLast   = ti === times.length - 1;
            const rowH     = isLab3hr ? 165 : 148;

            // Sticky time label cell
            html += `<tr>
            <td style="
                background:${isLab3hr ? '#1c2645' : '#21263d'};color:#e2e5f1;
                font-size:.7rem;font-weight:700;
                padding:8px 5px;text-align:center;vertical-align:middle;
                border-right:2px solid #2d3250;
                border-bottom:${isLast ? 'none' : '1px solid #2d3250'};
                position:sticky;left:0;z-index:2;white-space:nowrap;line-height:1.4;
            ">
                ${fmt12(st)}<br>
                <span style="font-weight:400;opacity:.7;font-size:.62rem;">- ${et ? fmt12(et) : ''}</span>
                ${isLab3hr ? '<br><span style="font-size:.57rem;color:#6ea8fe;font-weight:600;">3hr Lab</span>' : ''}
            </td>`;

            // One data cell per day
            DAYS.forEach((day, di) => {
                const isLastCol = di === DAYS.length - 1;
                const matches   = data.filter(r => r.day === day && norm(r.start_time) === st);
                const isEmpty   = matches.length === 0;

                html += `<td style="
                    height:${rowH}px;
                    vertical-align:top;
                    padding:5px;
                    background:${isEmpty
                        ? (isLab3hr ? '#f5f8ff' : '#ffffff')
                        : (isLab3hr ? '#eff6ff' : '#fafbff')};
                    border-right:${isLastCol ? 'none' : '1px solid #e2e8f0'};
                    border-bottom:${isLast ? 'none' : '1px solid #e2e8f0'};
                ">`;

                if (!isEmpty) {
                    const cellId = `cell-${day}-${st.replace(/:/g,'')}`;
                    const remaining = matches.length - 1;

                    // Store matches data globally for expand/collapse
                    _cellData[cellId] = { matches, rowH };

                    html += `<div id="${cellId}-preview" style="padding:3px;">`;

                    // Render ALL classes (first one visible, rest hidden on screen but visible in print)
                    matches.forEach((m, idx) => {
                        const isLab = m.component_type === 'Lab';
                        const color = getDeptColor(m.department_id);
                        const accent = isLab ? '#0d6efd' : '#198754';
                        const bgCard = isLab ? '#dbeafe' : '#f0fdf4';
                        const typeTag = isLab
                            ? `<span style="background:#0d6efd;color:#fff;font-size:.58rem;padding:1px 5px;border-radius:3px;font-weight:700;">LAB</span>`
                            : `<span style="background:#198754;color:#fff;font-size:.58rem;padding:1px 5px;border-radius:3px;font-weight:700;">LEC</span>`;
                        const creditHours = m.credit_hours || '--';
                        const hideClass = idx > 0 ? ' class="print-only-class"' : '';

                        html += `
                        <div${hideClass} style="background:${bgCard};border-left:4px solid ${accent};border-radius:5px;padding:6px;font-size:.78rem;margin-bottom:4px;${idx > 0 ? '' : 'cursor:pointer;'}box-shadow:0 1px 3px rgba(0,0,0,.07);"
                             ${idx === 0 ? `onmouseover="this.style.boxShadow='0 3px 10px rgba(0,0,0,.18)'" onmouseout="this.style.boxShadow='0 1px 3px rgba(0,0,0,.07)'"` : ''}>
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">
                                <span style="background:${color};color:#fff;font-size:.6rem;font-weight:700;padding:1px 5px;border-radius:3px;">${m.course_code}</span>
                                ${typeTag}
                            </div>
                            <div style="font-weight:700;color:#1e293b;font-size:.72rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${m.course_name}">${m.course_name}</div>
                            <div style="color:#64748b;font-size:.65rem;margin-top:1px;">
                                ${m.room_number} • ${creditHours}
                            </div>
                        </div>`;
                    });

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

                    html += `</div>`;
                    html += `</div>`;
                } else {
                    html += `<div style="height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:.9rem;">--</div>`;
                }

                html += `</td>`;
            });

            html += `</tr>`;
        });

        html += `</tbody></table></div>`;
        document.getElementById('timetable-container').innerHTML = html;
    }

    // ==========================================================
    // ROOM AVAILABILITY SIDEBAR
    // ==========================================================
    function updateStatusSidebars(data) {
        const list = document.getElementById('room-status-list');
        if (!list) return;

        // Build a set of all rooms in the system from the timetable data
        // occupied = room is used at least once in this session
        const occupiedRooms = new Set(data.map(r => r.room_number).filter(Boolean));

        // Build per-room slot count so we can show how many slots are booked
        const roomSlotCount = {};
        data.forEach(r => {
            if (!r.room_number) return;
            roomSlotCount[r.room_number] = (roomSlotCount[r.room_number] || 0) + 1;
        });

        const rooms = [...occupiedRooms].sort();

        if (!rooms.length) {
            list.innerHTML = '<li class="list-group-item text-center text-muted small">No data</li>';
            return;
        }

        list.innerHTML = rooms.map(r => {
            const count = roomSlotCount[r] || 0;
            // Colour: many bookings = red, moderate = orange, few = green
            const badgeClass = count >= 5 ? 'bg-danger' : count >= 3 ? 'bg-warning text-dark' : 'bg-success';
            return `
                <li class="list-group-item d-flex justify-content-between align-items-center p-2">
                    <span class="small fw-semibold">${r}</span>
                    <span class="badge ${badgeClass}" style="font-size:.6rem;">${count} slot${count !== 1 ? 's' : ''}</span>
                </li>`;
        }).join('');
    }

    // ==========================================================
    // VIEW SLOT CLASSES MODAL
    // ==========================================================
    window.openClassEdit = function(cardId) {
        const entry = _cellData[cardId];
        if (!entry) return;

        // Close view modal first
        const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewSlotModal'));
        if (viewModal) viewModal.hide();

        // Open edit modal
        openEditModal(entry);
    };

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
                ? `<span class="badge" style="background:#0d6efd;font-size:.68rem;">LAB</span>`
                : `<span class="badge" style="background:#198754;font-size:.68rem;">LECTURE</span>`;
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
                <div class="card h-100 shadow-sm border-0" style="border-left:4px solid ${accent} !important;background:${bgCard};cursor:pointer;transition:all .2s;"
                     onclick="openClassEdit('${cardId}')"
                     onmouseover="this.style.boxShadow='0 6px 16px rgba(0,0,0,.15)';this.style.transform='translateY(-3px)'"
                     onmouseout="this.style.boxShadow='0 1px 3px rgba(0,0,0,.1)';this.style.transform='translateY(0)'">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <span class="badge fw-bold" style="background:${color};font-size:.7rem;">${m.course_code}</span>
                                ${typeTag}
                            </div>
                            <span class="badge bg-light text-dark border" style="font-size:.65rem;">
                                <i class="bi bi-door-closed"></i> ${m.room_number}
                            </span>
                        </div>
                        <h6 class="card-title mb-2" style="color:#1e293b;font-size:.9rem;font-weight:700;">${m.course_name}</h6>
                        <p class="card-text text-muted mb-2" style="font-size:.8rem;">
                            <i class="bi bi-person-fill"></i> ${m.faculty_name}<br>
                            <i class="bi bi-book"></i> Credit Hours: <strong>${creditHours}</strong>
                        </p>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small class="text-muted">Enrollment</small>
                                <small style="color:${barColor};font-weight:600;">${enrolled}/${cap} (${pct}%)</small>
                            </div>
                            <div class="progress" style="height:5px;">
                                <div class="progress-bar" style="width:${pct}%;background:${barColor};"></div>
                            </div>
                        </div>
                        <div class="text-center pt-2 border-top">
                            <small class="text-primary fw-semibold"><i class="bi bi-pencil"></i> Click to edit room/time</small>
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

    function getDeptColor(deptId) {
        const colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#14b8a6'];
        return colors[(deptId || 0) % colors.length];
    }

    // ==========================================================
    // RESOLVE UNSCHEDULED COURSES MODAL
    // ==========================================================
    window.openResolveModal = function() {
        const body = document.getElementById('resolve-modal-body');
        if (!body) return;

        if (_unscheduledCourses.length === 0) {
            body.innerHTML = '<div class="alert alert-success">All courses are scheduled!</div>';
            return;
        }

        // Load rooms and slots for the dropdowns
        const fd = new FormData();
        fd.append('action', 'get_rooms_and_slots');
        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(rsData => {
                const rooms = rsData.data?.rooms || [];
                const slots = rsData.data?.slots || [];

                const roomOptions = rooms.map(r =>
                    `<option value="${r.room_id}">${r.room_number} (${r.room_type}, cap:${r.capacity})</option>`).join('');
                const slotOptions = slots.map(s =>
                    `<option value="${s.slot_id}">${s.day} ${s.start_time.substring(0,5)}–${s.end_time.substring(0,5)}</option>`).join('');

                body.innerHTML = `
                    <p class="text-muted small mb-3">
                        <strong>${_unscheduledCourses.length}</strong> course(s) could not be auto-scheduled.
                        Manually assign a room and time slot for each, or re-run the GA.
                    </p>` +
                _unscheduledCourses.map((c, idx) => `
                    <div class="card mb-3 border-warning" id="unsch-card-${idx}">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <span class="badge bg-secondary me-1">${c.course_code}</span>
                                    <strong>${c.course_name}</strong>
                                    <span class="text-muted small ms-2">(${c.credit_hours})</span>
                                </div>
                                <small class="text-muted">${c.faculty_name} | ${c.enrolled_students} students</small>
                            </div>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label small mb-1">Type</label>
                                    <select class="form-select form-select-sm" id="unsch-type-${idx}">
                                        <option value="Theory">Theory</option>
                                        <option value="Lab">Lab</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small mb-1">Room</label>
                                    <select class="form-select form-select-sm" id="unsch-room-${idx}">
                                        ${roomOptions}
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small mb-1">Time Slot</label>
                                    <select class="form-select form-select-sm" id="unsch-slot-${idx}">
                                        ${slotOptions}
                                    </select>
                                </div>
                            </div>
                            <div class="mt-2">
                                <button class="btn btn-sm btn-primary" onclick="scheduleManually(${idx}, ${JSON.stringify(c)})">
                                    <i class="bi bi-calendar-plus me-1"></i>Schedule
                                </button>
                                <span class="ms-2 small text-muted" id="unsch-status-${idx}"></span>
                            </div>
                        </div>
                    </div>`).join('');
            });
    };

    window.scheduleManually = function(idx, course) {
        const room_id  = document.getElementById(`unsch-room-${idx}`)?.value;
        const slot_id  = document.getElementById(`unsch-slot-${idx}`)?.value;
        const compType = document.getElementById(`unsch-type-${idx}`)?.value || 'Theory';
        const statusEl = document.getElementById(`unsch-status-${idx}`);

        if (!room_id || !slot_id) { if (statusEl) statusEl.textContent = 'Select room and slot.'; return; }

        const fd = new FormData();
        fd.append('action',         'schedule_course_manually');
        fd.append('session',        _currentBaseSession);
        fd.append('run_id',         _currentRunId || '');
        fd.append('course_id',      course.course_id);
        fd.append('faculty_id',     course.faculty_id);
        fd.append('room_id',        room_id);
        fd.append('slot_id',        slot_id);
        fd.append('component_type', compType);
        if (window._schedReason) fd.append('reason', window._schedReason);

        if (statusEl) statusEl.textContent = 'Saving…';
        fetch('admin_handler.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success && data.data && data.data.needs_reason) {
                    const why = prompt('This timetable is published. Reason for adding this class (required, saved in the change log):');
                    window._schedReason = (why || '').trim();
                    if (window._schedReason) { window.scheduleManually(idx, course); return; }
                    if (statusEl) statusEl.innerHTML = '<span class="text-danger">Reason required.</span>';
                    return;
                }
                window._schedReason = '';
                if (data.success) {
                    if (statusEl) statusEl.innerHTML = '<span class="text-success fw-bold">Scheduled!</span>';
                    const card = document.getElementById(`unsch-card-${idx}`);
                    if (card) { card.classList.remove('border-warning'); card.classList.add('border-success'); }
                    _unscheduledCourses = _unscheduledCourses.filter(c2 => c2.course_id !== course.course_id);
                    loadTimetable();
                } else {
                    if (statusEl) statusEl.innerHTML = `<span class="text-danger">${data.message}</span>`;
                }
            });
    };

}); // end DOMContentLoaded

// ===== Equipment rows (key/value). Rooms: name + working/faulty. Courses: type + students-per-unit (ideal, min). =====
const _esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
let _eqUid = 0;
function addEqRow(containerId, kind, name = '', a = 1, b = 0) {
    const box = document.getElementById(containerId); if (!box) return;
    const div = document.createElement('div');
    div.className = 'd-flex align-items-center gap-2 mb-1 eq-row';
    const uid = 'eqr' + (++_eqUid);
    if (kind === 'room') {
        div.innerHTML = `<input type="text" name="eq_name[]" class="form-control form-control-sm" placeholder="e.g. AC 1" value="${_esc(name)}" style="max-width:180px">
            <input type="hidden" name="eq_status[]" value="${a ? 1 : 0}">
            <label class="small"><input type="radio" name="${uid}" value="1" ${a ? 'checked' : ''}> Working</label>
            <label class="small"><input type="radio" name="${uid}" value="0" ${a ? '' : 'checked'}> Faulty</label>
            <button type="button" class="btn btn-sm btn-outline-danger py-0">&times;</button>`;
        div.querySelectorAll('input[type=radio]').forEach(r => r.addEventListener('change', () => div.querySelector('input[name="eq_status[]"]').value = r.value));
    } else {
        div.innerHTML = `<input type="text" name="eq_name[]" class="form-control form-control-sm" placeholder="Type e.g. PC, AC, Light" value="${_esc(name)}" style="max-width:220px">
            <button type="button" class="btn btn-sm btn-outline-danger py-0">&times;</button>`;
    }
    div.querySelector('button').addEventListener('click', () => div.remove());
    box.appendChild(div);
}
function fillEqRows(containerId, kind, raw) {
    const box = document.getElementById(containerId); if (!box) return;
    box.innerHTML = '';
    let o = null;
    try { o = JSON.parse(raw); } catch (e) { }
    if (o && typeof o === 'object') {
        if (Array.isArray(o)) o.forEach(n => addEqRow(containerId, kind, n));
        else for (const [k, v] of Object.entries(o)) addEqRow(containerId, kind, k, +v);
    }
    if (!box.children.length) addEqRow(containerId, kind);
}
function equipSummary(raw) {
    let o = null; try { o = JSON.parse(raw); } catch (e) { }
    if (!o) return _esc(raw || '-');
    const t = {};
    for (const [k, v] of Object.entries(o)) { const ty = k.replace(/[\s_-]*\d+$/, '').trim(); t[ty] = t[ty] || [0, 0]; t[ty][1]++; if (+v === 1) t[ty][0]++; }
    return Object.entries(t).map(([k, [w, n]]) => `${_esc(k)} ${w}/${n}`).join(', ') || '-';
}
function _initEqRows() {
    [['form-course', 'course-eq-rows', 'course'], ['form-room', 'room-eq-rows', 'room']].forEach(([f, c, k]) => {
        const form = document.getElementById(f); if (!form) return;
        fillEqRows(c, k, null);
        form.addEventListener('reset', () => setTimeout(() => fillEqRows(c, k, null), 0));
    });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', _initEqRows); else _initEqRows();
