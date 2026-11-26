<?php
// index.php
session_start();

// CHECK: If user is NOT logged in, redirect to login page
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'University Timetable';
$currentPage = 'timetable';
include 'header.php';
?>

<div class="container-fluid">

    <div id="system-alert-area"></div>

    <div class="card shadow-sm mb-3 border-0 bg-light">
        <div class="card-body py-2 px-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="text-muted small fw-semibold text-nowrap"><i class="bi bi-funnel me-1"></i>Filters:</span>
                <select class="form-select form-select-sm flex-shrink-0" id="filter-session" style="width:130px;" title="Session"><option value="">Loading…</option></select>
                <select class="form-select form-select-sm flex-shrink-0 d-none" id="filter-run" style="width:170px;" title="Timetable Version"><option value="">Latest Version</option></select>
                <button type="button" class="btn btn-sm btn-outline-success flex-shrink-0 d-none" id="btn-publish-run" title="Mark the selected version as the published timetable"><i class="bi bi-megaphone"></i> Publish</button>
                <span class="badge bg-success d-none" id="publish-badge"></span>
                <select class="form-select form-select-sm flex-shrink-0" id="filter-dept" style="width:150px;" title="Department"><option value="">All Departments</option></select>
                <select class="form-select form-select-sm flex-shrink-0" id="filter-faculty" style="width:140px;" title="Faculty"><option value="">All Faculty</option></select>
                <select class="form-select form-select-sm flex-shrink-0" id="filter-batch" style="width:105px;" title="Batch"><option value="">All Batches</option></select>
                <select class="form-select form-select-sm flex-shrink-0" id="filter-program" style="width:135px;" title="Program"><option value="">All Programs</option></select>
                <select class="form-select form-select-sm flex-shrink-0" id="filter-course" style="width:140px;" title="Course"><option value="">All Courses</option></select>
                <select class="form-select form-select-sm flex-shrink-0" id="filter-semester" style="width:110px;" title="Semester"><option value="">All Semesters</option></select>
                <select class="form-select form-select-sm flex-shrink-0" id="filter-room" style="width:110px;" title="Room"><option value="">All Rooms</option></select>
                <button type="button" class="btn btn-primary btn-sm px-3 flex-shrink-0" id="btn-apply-filter"><i class="bi bi-funnel-fill me-1"></i>Apply</button>
            </div>
        </div>
    </div>

    <div class="row">

        <div class="col-md-10" id="printable-area">
            <div class="alert alert-info d-flex justify-content-between align-items-center py-2 mb-3">
                <span><i class="bi bi-info-circle me-2"></i> Viewing: <strong id="display-filter-info">Fall
                        2025</strong></span>
                <span class="badge bg-light text-dark border" id="generation-status">Status: Ready</span>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive" id="timetable-container">
                        <div class="p-5 text-center text-muted">
                            <div class="spinner-border text-secondary mb-2" role="status"></div>
                            <br>Loading Timetable...
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="sidebar-sticky d-flex flex-column gap-3">

                <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                    <div class="card shadow-sm border-0">
                        <div class="card-body d-grid gap-2">
                            <h6 class="fw-bold border-bottom pb-2">Admin Actions</h6>
                            <button type="button" id="btn-generate-tt"
                                class="btn btn-warning btn-sm w-100 text-dark fw-bold">
                                <i class="bi bi-cpu"></i> Generate Timetable (GA)
                            </button>

                            <button type="button" id="btn-saved-timetables"
                                class="btn btn-outline-primary btn-sm w-100"
                                data-bs-toggle="modal" data-bs-target="#savedTimetablesModal">
                                <i class="bi bi-archive me-1"></i> Saved Timetables
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm border-0">
                    <div class="card-body d-grid gap-2">
                        <h6 class="fw-bold border-bottom pb-2">Export / Print</h6>
                        <button class="btn btn-outline-dark btn-sm" onclick="printTimetable()">
                            <i class="bi bi-printer me-2"></i> Print / PDF
                        </button>
                        <button class="btn btn-outline-success btn-sm" id="btn-export-csv">
                            <i class="bi bi-filetype-csv me-2"></i> Export CSV
                        </button>
                        <button class="btn btn-outline-info btn-sm" id="btn-export-json">
                            <i class="bi bi-filetype-json me-2"></i> Export JSON
                        </button>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-bold small">
                        <i class="bi bi-door-open me-1"></i> Room Availability
                    </div>
                    <div class="card-body p-0 status-card" style="max-height: 200px; overflow-y:auto;">
                        <ul class="list-group list-group-flush small" id="room-status-list">
                            <li class="list-group-item text-center text-muted">Select a session to view status</li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<!-- ── Saved Timetables Modal ─────────────────────────── -->
<div class="modal fade" id="savedTimetablesModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-archive me-2"></i>Saved Timetable Runs</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <table class="table table-hover mb-0" id="saved-tt-table">
                    <thead class="table-dark">
                        <tr>
                            <th>Session</th>
                            <th>Run Label / Version</th>
                            <th class="text-center">Classes</th>
                            <th class="text-center">Fitness</th>
                            <th>Generated At</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="saved-tt-body">
                        <tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Resolve Unscheduled Courses Modal ──────────────── -->
<div class="modal fade" id="resolveUnscheduledModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Resolve Unscheduled Courses</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="resolve-modal-body">
                <p class="text-muted">Loading unscheduled courses...</p>
            </div>
        </div>
    </div>
</div>

<!-- ── Manual Edit Entry Modal ────────────────────────── -->
<div class="modal fade" id="editEntryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Class Entry</h5>
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

<!-- View Slot Classes Modal -->
<div class="modal fade" id="viewSlotModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-calendar-event me-2"></i><span id="slot-modal-title">Classes at This Time</span></h5>
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

<?php include 'footer.php'; ?>
