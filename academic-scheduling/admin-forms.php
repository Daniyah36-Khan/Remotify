<?php
// admin-forms.php
$pageTitle = 'Admin Dashboard';
$currentPage = 'admin';
include 'header.php';

// Check Admin Logic
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Location: dashboard.php');
    exit;
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 bg-light sidebar border-end sidebar-fixed d-flex flex-column p-3">
            <h5 class="fw-bold mb-3 ps-2 text-primary">Dashboard</h5>

            <div class="nav flex-column nav-pills me-3 flex-grow-1" id="adminTab" role="tablist"
                aria-orientation="vertical">
                <button class="nav-link active text-start mb-1" data-bs-toggle="pill" data-bs-target="#departments-pane"
                    id="tab-departments"><i class="bi bi-building me-2"></i>Departments</button>
                <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#programs-pane"
                    id="tab-programs"><i class="bi bi-mortarboard me-2"></i>Programs</button>
                <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#courses-pane"
                    id="tab-courses"><i class="bi bi-book me-2"></i>Courses</button>
                <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#faculty-pane"
                    id="tab-faculty"><i class="bi bi-person-badge me-2"></i>Faculty</button>
                <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#students-pane"
                    id="tab-students"><i class="bi bi-people me-2"></i>Students</button>
                <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#rooms-pane"
                    id="tab-rooms"><i class="bi bi-geo-alt me-2"></i>Rooms</button>
                <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#slots-pane"
                    id="tab-slots"><i class="bi bi-clock me-2"></i>Slots</button>
                <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#allocations-pane"
                    id="tab-allocations"><i class="bi bi-diagram-3 me-2"></i>Allocations</button>
                <button class="nav-link text-start mb-1" data-bs-toggle="pill" data-bs-target="#enrollments-pane"
                    id="tab-enrollments"><i class="bi bi-card-checklist me-2"></i>Enrollments</button>
            </div>

            <hr class="my-2">
            <div class="nav flex-column nav-pills me-3">
                <a href="dashboard.php" class="nav-link text-start text-dark mb-1">
                    <i class="bi bi-grid-3x3-gap-fill me-2"></i> View Timetable
                </a>
                <a href="logout.php" class="nav-link text-start text-danger">
                    <i class="bi bi-box-arrow-right me-2"></i> Logout
                </a>
            </div>
        </div>

        <div class="col-md-10 offset-md-2 py-3">
            <div id="system-alert-area" class="sticky-top" style="top: 70px; z-index: 1050;"></div>
        <script>const IS_DEMO = <?php echo (int)(!empty($_SESSION['is_demo'])); ?>;</script>

            <div class="tab-content" id="v-pills-tabContent">

                <div class="tab-pane fade show active" id="departments-pane">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body py-3">
                            <form id="form-department" class="row align-items-end g-2">
                                <input type="hidden" name="id" id="dept-id">
                                <div class="col-md-8">
                                    <label class="form-label small fw-bold text-secondary mb-1">Department Name</label>
                                    <input type="text" name="department_name" class="form-control form-control-sm"
                                        placeholder="Enter name" required>
                                </div>
                                <div class="col-md-4">
                                    <button type="button" class="btn btn-sm btn-primary w-100" id="btn-save-dept"
                                        onclick="validateAndSubmit('add_department', 'form-department', 'table-departments')">Save
                                        Department</button>
                                    <button type="button" class="btn btn-sm btn-secondary w-100 mt-1 d-none"
                                        id="btn-cancel-dept"
                                        onclick="resetFormState('form-department', 'btn-save-dept', 'add_department', 'Save Department')">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm mb-2 bg-light border-success border-start border-4">
                        <div class="card-body py-2">
                            <form id="form-csv-departments" enctype="multipart/form-data"
                                class="d-flex align-items-center gap-2 m-0">
                                <label class="form-label mb-0 fw-bold small text-success">Bulk Upload Departments
                                    (CSV):</label>
                                <input type="file" name="csv_file" class="form-control form-control-sm w-auto"
                                    accept=".csv" required>
                                <input type="hidden" name="import_type" value="departments">
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="uploadCSV('form-csv-departments')">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                <small class="text-muted ms-auto">Format: department_name</small>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover w-100" id="table-departments">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="programs-pane">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body py-3">
                            <form id="form-program" class="row align-items-end g-2">
                                <input type="hidden" name="id" id="prog-id">
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-secondary mb-1">Department</label>
                                    <select name="department_id" class="form-select form-select-sm dept-select"
                                        required></select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small fw-bold text-secondary mb-1">Program Name</label>
                                    <input type="text" name="program_name" class="form-control form-control-sm"
                                        required>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-sm btn-primary w-100" id="btn-save-prog"
                                        onclick="validateAndSubmit('add_program', 'form-program', 'table-programs')">Save
                                        Program</button>
                                    <button type="button" class="btn btn-sm btn-secondary w-100 mt-1 d-none"
                                        id="btn-cancel-prog"
                                        onclick="resetFormState('form-program', 'btn-save-prog', 'add_program', 'Save Program')">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm mb-2 bg-light border-success border-start border-4">
                        <div class="card-body py-2">
                            <form id="form-csv-programs" enctype="multipart/form-data"
                                class="d-flex align-items-center gap-2 m-0">
                                <label class="form-label mb-0 fw-bold small text-success">Bulk Upload Programs
                                    (CSV):</label>
                                <input type="file" name="csv_file" class="form-control form-control-sm w-auto"
                                    accept=".csv" required>
                                <input type="hidden" name="import_type" value="programs">
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="uploadCSV('form-csv-programs')">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                <small class="text-muted ms-auto">Format: program_name, department_name</small>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover w-100" id="table-programs">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Program Name</th>
                                        <th>Department</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="courses-pane">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body py-3">
                            <form id="form-course" class="row align-items-end g-2">
                                <input type="hidden" name="id" id="course-id">
                                <div class="col-md-2"><label
                                        class="form-label small fw-bold text-secondary mb-1">Code</label><input
                                        type="text" name="course_code" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-3"><label
                                        class="form-label small fw-bold text-secondary mb-1">Name</label><input
                                        type="text" name="course_name" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-1"><label
                                        class="form-label small fw-bold text-secondary mb-1">Cr.</label><input
                                        type="text" name="credit_hours" class="form-control form-control-sm" placeholder="e.g. 3-0-3" value="3-0-3"
                                        required></div>
                                <div class="col-md-2"><label
                                        class="form-label small fw-bold text-secondary mb-1">Dept</label><select
                                        name="department_id" class="form-select form-select-sm dept-select"
                                        onchange="loadPrograms(this.value, 'course-program')" required></select></div>
                                <div class="col-md-2"><label
                                        class="form-label small fw-bold text-secondary mb-1">Prog</label><select
                                        name="program_id" id="course-program" class="form-select form-select-sm"
                                        required>
                                        <option value="" disabled>Select Dept</option>
                                    </select></div>
                                <div class="mb-2">
                                    <label class="small fw-bold">Required Equipment</label>
                                    <div id="course-eq-rows" class="p-2 border rounded bg-light"></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addEqRow('course-eq-rows','course')"><i class="bi bi-plus-lg"></i> Add</button>
                                    <small class="text-muted ms-2">Names only (e.g. PC, AC, Light). How many are needed is worked out from enrolled students; rooms below the minimum are never assigned.</small>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-primary w-100" id="btn-save-course"
                                        onclick="validateAndSubmit('add_course', 'form-course', 'table-courses')">Save</button>
                                    <button type="button" class="btn btn-sm btn-secondary w-100 mt-1 d-none"
                                        id="btn-cancel-course"
                                        onclick="resetFormState('form-course', 'btn-save-course', 'add_course', 'Save')">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm mb-2 bg-light border-success border-start border-4">
                        <div class="card-body py-2">
                            <form id="form-csv-courses" enctype="multipart/form-data"
                                class="d-flex align-items-center gap-2 m-0">
                                <label class="form-label mb-0 fw-bold small text-success">Bulk Upload Courses
                                    (CSV):</label>
                                <input type="file" name="csv_file" class="form-control form-control-sm w-auto"
                                    accept=".csv" required>
                                <input type="hidden" name="import_type" value="courses">
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="uploadCSV('form-csv-courses')">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                <small class="text-muted ms-auto">Format: course_code, course_name, credit_hours, equipment (e.g. PC;AC;Light), department_name, program_name</small>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover w-100" id="table-courses">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Code</th>
                                        <th>Name</th>
                                        <th>Credits</th>
                                        <th>Dept</th>
                                        <th>Program</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="faculty-pane">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body py-3">
                            <form id="form-faculty" class="row align-items-end g-2">
                                <input type="hidden" name="id" id="fac-id">
                                <div class="col-md-3"><label
                                        class="form-label small fw-bold text-secondary mb-1">Name</label><input
                                        type="text" name="faculty_name" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-3"><label
                                        class="form-label small fw-bold text-secondary mb-1">Email</label><input
                                        type="text" name="email" class="form-control form-control-sm email-check"
                                        required></div>
                                <div class="col-md-2"><label
                                        class="form-label small fw-bold text-secondary mb-1">Phone</label><input
                                        type="text" name="phone_number"
                                        class="form-control form-control-sm phone-check"></div>
                                <div class="col-md-2"><label
                                        class="form-label small fw-bold text-secondary mb-1">Dept</label><select
                                        name="department_id" class="form-select form-select-sm dept-select"
                                        required></select></div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-primary w-100" id="btn-save-fac"
                                        onclick="validateAndSubmit('add_faculty', 'form-faculty', 'table-faculty')">Save</button>
                                    <button type="button" class="btn btn-sm btn-secondary w-100 mt-1 d-none"
                                        id="btn-cancel-fac"
                                        onclick="resetFormState('form-faculty', 'btn-save-fac', 'add_faculty', 'Save')">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
<div class="card shadow-sm mb-2 bg-light border-success border-start border-4">
                        <div class="card-body py-2">
                            <form id="form-csv-faculty" enctype="multipart/form-data"
                                class="d-flex align-items-center gap-2 m-0">
                                <label class="form-label mb-0 fw-bold small text-success">Bulk Upload Faculty
                                    (CSV):</label>
                                <input type="file" name="csv_file" class="form-control form-control-sm w-auto"
                                    accept=".csv" required>
                                <input type="hidden" name="import_type" value="faculty">
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="uploadCSV('form-csv-faculty')">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                <small class="text-muted ms-auto">Format: name, email, phone, department_name</small>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover w-100" id="table-faculty">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Dept</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="students-pane">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body py-3">
                            <form id="form-student" class="row align-items-end g-2">
                                <input type="hidden" name="id" id="std-id">
                                <div class="col-md-2"><label class="small fw-bold text-secondary mb-1">Reg
                                        Num</label><input type="text" name="registration_number"
                                        class="form-control form-control-sm"></div>
                                <div class="col-md-2"><label
                                        class="small fw-bold text-secondary mb-1">Name</label><input type="text"
                                        name="student_name" class="form-control form-control-sm" required></div>
                                <div class="col-md-2"><label
                                        class="small fw-bold text-secondary mb-1">Email</label><input type="text"
                                        name="email" class="form-control form-control-sm email-check" required></div>
                                <div class="col-md-2"><label
                                        class="small fw-bold text-secondary mb-1">Dept</label><select
                                        name="department_id" class="form-select form-select-sm dept-select"
                                        onchange="loadPrograms(this.value, 'student-program')" required></select></div>
                                <div class="col-md-2"><label
                                        class="small fw-bold text-secondary mb-1">Prog</label><select name="program_id"
                                        id="student-program" class="form-select form-select-sm" required>
                                        <option value="" disabled>Select Dept</option>
                                    </select></div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-primary w-100" id="btn-save-std"
                                        onclick="validateAndSubmit('add_student', 'form-student', 'table-students')">Save</button>
                                    <button type="button" class="btn btn-sm btn-secondary w-100 mt-1 d-none"
                                        id="btn-cancel-std"
                                        onclick="resetFormState('form-student', 'btn-save-std', 'add_student', 'Save')">Cancel</button>
                                </div>
                                <div class="col-12 mt-2 row g-2">
                                    <div class="col-md-2"><input type="text" name="phone_number"
                                            class="form-control form-control-sm phone-check" placeholder="Phone"></div>
                                    <div class="col-md-2"><input type="date" name="dob"
                                            class="form-control form-control-sm" placeholder="DOB"></div>
                                    <div class="col-md-2"><select name="gender" class="form-select form-select-sm">
                                            <option>Male</option>
                                            <option>Female</option>
                                            <option>Other</option>
                                        </select></div>
                                    <div class="col-md-4">
                                        <select name="student_type" class="form-select form-select-sm">
                                            <option value="regular" selected>Regular Student</option>
                                            <option value="transfer">Transfer Student</option>
                                        </select>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
<div class="card shadow-sm mb-2 bg-light border-success border-start border-4">
                        <div class="card-body py-2">
                            <form id="form-csv-students" enctype="multipart/form-data"
                                class="d-flex align-items-center gap-2 m-0">
                                <label class="form-label mb-0 fw-bold small text-success">Bulk Upload Students
                                    (CSV):</label>
                                <input type="file" name="csv_file" class="form-control form-control-sm w-auto"
                                    accept=".csv" required>
                                <input type="hidden" name="import_type" value="students">
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="uploadCSV('form-csv-students')">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                <small class="text-muted ms-auto">Format: name, email, reg_no, phone, dob, gender, department_name, program_name, student_type</small>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover w-100" id="table-students">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Reg No</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Program</th>
                                        <th>Type</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="rooms-pane">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body py-3">
                            <form id="form-room" class="row align-items-end g-2">
                                <input type="hidden" name="id" id="room-id">
                                <div class="col-md-2"><label class="small fw-bold text-secondary mb-1">Room
                                        No</label><input type="text" name="room_number"
                                        class="form-control form-control-sm" required></div>
                                <div class="col-md-2"><label
                                        class="small fw-bold text-secondary mb-1">Type</label><select name="type"
                                        class="form-select form-select-sm">
                                        <option>Lecture Hall</option>
                                        <option>Lab</option>
                                    </select></div>
                                <div class="col-md-2"><label
                                        class="small fw-bold text-secondary mb-1">Capacity</label><input type="number"
                                        name="capacity" class="form-control form-control-sm" required></div>
                                <div class="mb-2">
                                    <label class="small fw-bold">Available Equipment in Room</label>
                                    <div id="room-eq-rows" class="p-2 border rounded bg-light"></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addEqRow('room-eq-rows','room')"><i class="bi bi-plus-lg"></i> Add</button>
                                    <small class="text-muted ms-2">One row per physical item, e.g. "AC 1", "AC 2". Type = name without the number. Faulty items are not counted.</small>
                                </div>
                                <input type="hidden" name="floor_number" value="1">
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-primary w-100" id="btn-save-room"
                                        onclick="validateAndSubmit('add_room', 'form-room', 'table-rooms')">Save</button>
                                    <button type="button" class="btn btn-sm btn-secondary w-100 mt-1 d-none"
                                        id="btn-cancel-room"
                                        onclick="resetFormState('form-room', 'btn-save-room', 'add_room', 'Save')">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
<div class="card shadow-sm mb-2 bg-light border-success border-start border-4">
                        <div class="card-body py-2">
                            <form id="form-csv-rooms" enctype="multipart/form-data"
                                class="d-flex align-items-center gap-2 m-0">
                                <label class="form-label mb-0 fw-bold small text-success">Bulk Upload Rooms
                                    (CSV):</label>
                                <input type="file" name="csv_file" class="form-control form-control-sm w-auto"
                                    accept=".csv" required>
                                <input type="hidden" name="import_type" value="rooms">
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="uploadCSV('form-csv-rooms')">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                <small class="text-muted ms-auto">Format: room_no, capacity, type, equipment (e.g. AC 1:1;AC 2:0;Light 1:1  — 1 working, 0 faulty)</small>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover w-100" id="table-rooms">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Room</th>
                                        <th>Cap</th>
                                        <th>Type</th>
                                        <th>Equip</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="slots-pane">
                    <div id="slot-hint" class="mb-3"></div>
                    <div class="card shadow-sm mb-3">
                        <div class="card-body py-3">
                            <form id="form-slot" class="row align-items-end g-2">
                                <input type="hidden" name="id" id="slot-id">
                                <div class="col-md-3"><label class="small fw-bold text-secondary mb-1">Day</label>
                                    <select name="day" class="form-select form-select-sm">
                                        <option>Monday</option>
                                        <option>Tuesday</option>
                                        <option>Wednesday</option>
                                        <option>Thursday</option>
                                        <option>Friday</option>
                                        <option>Saturday</option>
                                    </select>
                                </div>
                                <div class="col-md-3"><label
                                        class="small fw-bold text-secondary mb-1">Start</label><input type="time"
                                        name="start_time" class="form-control form-control-sm" required></div>
                                <div class="col-md-3"><label class="small fw-bold text-secondary mb-1">End</label><input
                                        type="time" name="end_time" class="form-control form-control-sm" required></div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-sm btn-primary w-100" id="btn-save-slot"
                                        onclick="validateAndSubmit('add_slot', 'form-slot', 'table-slots')">Save</button>
                                    <button type="button" class="btn btn-sm btn-secondary w-100 mt-1 d-none"
                                        id="btn-cancel-slot"
                                        onclick="resetFormState('form-slot', 'btn-save-slot', 'add_slot', 'Save')">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
<div class="card shadow-sm mb-2 bg-light border-success border-start border-4">
                        <div class="card-body py-2">
                            <form id="form-csv-slots" enctype="multipart/form-data"
                                class="d-flex align-items-center gap-2 m-0">
                                <label class="form-label mb-0 fw-bold small text-success">Bulk Upload Time Slots
                                    (CSV):</label>
                                <input type="file" name="csv_file" class="form-control form-control-sm w-auto"
                                    accept=".csv" required>
                                <input type="hidden" name="import_type" value="time_slots">
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="uploadCSV('form-csv-slots')">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                <small class="text-muted ms-auto">Format: day, start_time, end_time</small>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover w-100" id="table-slots">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Day</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

<div class="tab-pane fade" id="allocations-pane">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body py-3">
                            <form id="form-allocation" class="row align-items-end g-2">
                                <input type="hidden" name="id" id="alloc-id">
                                <div class="col-md-3"><label
                                        class="small fw-bold text-secondary mb-1">Session</label><input type="text"
                                        name="session_name" class="form-control form-control-sm"
                                        placeholder="e.g. Fall 2025" required></div>
                                <div class="col-md-3"><label
                                        class="small fw-bold text-secondary mb-1">Faculty</label><select
                                        name="faculty_id" class="form-select form-select-sm faculty-select"
                                        required></select></div>
                                <div class="col-md-3"><label
                                        class="small fw-bold text-secondary mb-1">Course</label><select name="course_id"
                                        class="form-select form-select-sm course-select" required></select></div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-sm btn-primary w-100" id="btn-save-alloc"
                                        onclick="validateAndSubmit('add_allocation', 'form-allocation', 'table-allocations')">Save</button>
                                    <button type="button" class="btn btn-sm btn-secondary w-100 mt-1 d-none"
                                        id="btn-cancel-alloc"
                                        onclick="resetFormState('form-allocation', 'btn-save-alloc', 'add_allocation', 'Save')">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm mb-2 bg-light border-success border-start border-4">
                        <div class="card-body py-2">
                            <form id="form-csv-allocations" enctype="multipart/form-data"
                                class="d-flex align-items-center gap-2 m-0">
                                <label class="form-label mb-0 fw-bold small text-success">Bulk Upload Allocations
                                    (CSV):</label>
                                <input type="file" name="csv_file" class="form-control form-control-sm w-auto"
                                    accept=".csv" required>
                                <input type="hidden" name="import_type" value="allocations">
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="uploadCSV('form-csv-allocations')">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                <small class="text-muted ms-auto">Format: session_name, faculty_email, course_code</small>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover w-100" id="table-allocations">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Session</th>
                                        <th>Faculty</th>
                                        <th>Course</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

<div class="tab-pane fade" id="enrollments-pane">
                    <div class="card shadow-sm mb-3">
                        <div class="card-body py-3">
                            <form id="form-enrollment" class="row g-2">
                                <input type="hidden" name="id" id="enroll-id">
                                <div class="col-md-2">
                                    <label class="small fw-bold text-secondary mb-1">Batch Year</label>
                                    <select name="batch_year" class="form-select form-select-sm" required>
                                        <option value="">-- Batch --</option>
                                        <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 15; $y--): ?>
                                            <option value="<?= $y ?>"><?= $y ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="small fw-bold text-secondary mb-1">Semester</label>
                                    <input type="text" name="semester" class="form-control form-control-sm" placeholder="e.g. Semester 1" list="semester-hints" autocomplete="off" required>
                                    <datalist id="semester-hints"></datalist>
                                </div>
                                <div class="col-md-4">
                                    <label class="small fw-bold text-secondary mb-1">Student</label>
                                    <input type="text" id="student-search" class="form-control form-control-sm mb-1" placeholder="&#128269; Search by name or reg no...">
                                    <select name="student_id" class="form-select form-select-sm student-select" required></select>
                                </div>
                                <div class="col-md-2">
                                    <label class="small fw-bold text-secondary mb-1">Course</label>
                                    <select name="course_id" class="form-select form-select-sm course-select" required></select>
                                </div>
                                <div class="col-md-2 d-flex flex-column justify-content-end">
                                    <button type="button" class="btn btn-sm btn-success w-100" id="btn-save-enroll"
                                        onclick="validateAndSubmit('add_enrollment', 'form-enrollment', 'table-enrollments')">Enroll</button>
                                    <button type="button" class="btn btn-sm btn-secondary w-100 mt-1 d-none"
                                        id="btn-cancel-enroll"
                                        onclick="resetFormState('form-enrollment', 'btn-save-enroll', 'add_enrollment', 'Enroll')">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm mb-2 bg-light border-success border-start border-4">
                        <div class="card-body py-2">
                            <form id="form-csv-enrollments" enctype="multipart/form-data"
                                class="d-flex align-items-center gap-2 m-0">
                                <label class="form-label mb-0 fw-bold small text-success">Bulk Upload Enrollments
                                    (CSV):</label>
                                <input type="file" name="csv_file" class="form-control form-control-sm w-auto"
                                    accept=".csv" required>
                                <input type="hidden" name="import_type" value="enrollments">
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="uploadCSV('form-csv-enrollments')">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                <small class="text-muted ms-auto">Format: batch_year, semester, student_name, course (e.g., CS201 – Programming Fundamentals)</small>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <table class="table table-striped table-hover w-100" id="table-enrollments">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Batch</th>
                                        <th>Semester</th>
                                        <th>Student</th>
                                        <th>Course</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
