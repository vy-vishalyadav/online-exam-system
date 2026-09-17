<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = "";
$success = "";

// ── Handle Delete Exam (POST only, with CSRF) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_exam') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $delete_id = (int)($_POST['id'] ?? 0);
        if ($delete_id > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM exams WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $delete_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                $_SESSION['flash_success'] = "Exam deleted successfully!";
            } else {
                $_SESSION['flash_error'] = "Failed to delete exam: " . mysqli_error($conn);
                mysqli_stmt_close($stmt);
            }
        }
        safe_redirect("manage-exam.php");
    }
}

// ── Handle Add Exam POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exam'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $title       = trim($_POST['title'] ?? '');
        $duration    = (int)($_POST['duration_minutes'] ?? 30);
        $result_mode = ($_POST['result_mode'] ?? 'instant') === 'pending' ? 'pending' : 'instant';
        $start_at    = trim($_POST['start_at'] ?? '');
        $end_at      = trim($_POST['end_at']   ?? '');
        $start_at    = ($start_at !== '') ? date('Y-m-d H:i:s', strtotime($start_at)) : null;
        $end_at      = ($end_at   !== '') ? date('Y-m-d H:i:s', strtotime($end_at))   : null;
        $class_ids   = array_map('intval', $_POST['class_ids'] ?? []);

        if (empty($title)) {
            $error = "Exam Title is required.";
        } elseif (strlen($title) > 200) {
            $error = "Exam title is too long (max 200 characters).";
        } elseif (empty($start_at) || empty($end_at)) {
            $error = "Exam Schedule (Opens At and Closes At) is required for scheduled college exams.";
        } elseif (strtotime($end_at) <= strtotime($start_at)) {
            $error = "Closes At must be after Opens At.";
        } else {
            // Auto-calculate exact duration from schedule window
            $calc_dur = (int)round((strtotime($end_at) - strtotime($start_at)) / 60);
            if ($calc_dur >= 1) {
                $duration = $calc_dur;
            }
            $stmt = mysqli_prepare($conn, "INSERT INTO exams (title, duration_minutes, result_mode, start_at, end_at) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "sisss", $title, $duration, $result_mode, $start_at, $end_at);
                if (mysqli_stmt_execute($stmt)) {
                    $new_exam_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);
                    // Save class assignments
                    foreach ($class_ids as $cid) {
                        if ($cid > 0) {
                            $ca = mysqli_prepare($conn, "INSERT IGNORE INTO exam_class_assignments (exam_id, class_id) VALUES (?,?)");
                            mysqli_stmt_bind_param($ca, "ii", $new_exam_id, $cid);
                            mysqli_stmt_execute($ca);
                            mysqli_stmt_close($ca);
                        }
                    }
                    $_SESSION['flash_success'] = "Exam '" . htmlspecialchars($title) . "' added successfully!";
                    safe_redirect("manage-exam.php");
                } else {
                    $error = "Error adding exam: " . mysqli_error($conn);
                    mysqli_stmt_close($stmt);
                }
            } else {
                $error = "Database query error. Please try again.";
            }
        }
    }
}

// ── Handle Edit Exam POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_exam'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $exam_id     = (int)($_POST['exam_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $duration    = (int)($_POST['duration_minutes'] ?? 30);
        $result_mode = ($_POST['result_mode'] ?? 'instant') === 'pending' ? 'pending' : 'instant';
        $start_at    = trim($_POST['start_at'] ?? '');
        $end_at      = trim($_POST['end_at']   ?? '');
        $start_at    = ($start_at !== '') ? date('Y-m-d H:i:s', strtotime($start_at)) : null;
        $end_at      = ($end_at   !== '') ? date('Y-m-d H:i:s', strtotime($end_at))   : null;
        $class_ids   = array_map('intval', $_POST['class_ids'] ?? []);

        if (empty($title)) {
            $error = "Exam Title is required.";
        } elseif (strlen($title) > 200) {
            $error = "Exam title is too long (max 200 characters).";
        } elseif (empty($start_at) || empty($end_at)) {
            $error = "Exam Schedule (Opens At and Closes At) is required for scheduled college exams.";
        } elseif (strtotime($end_at) <= strtotime($start_at)) {
            $error = "Closes At must be after Opens At.";
        } else {
            // Auto-calculate exact duration from schedule window
            $calc_dur = (int)round((strtotime($end_at) - strtotime($start_at)) / 60);
            if ($calc_dur >= 1) {
                $duration = $calc_dur;
            }
            $stmt = mysqli_prepare($conn, "UPDATE exams SET title=?, duration_minutes=?, result_mode=?, start_at=?, end_at=? WHERE id=?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "sisssi", $title, $duration, $result_mode, $start_at, $end_at, $exam_id);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    // Replace class assignments: delete old, insert new
                    $del = mysqli_prepare($conn, "DELETE FROM exam_class_assignments WHERE exam_id=?");
                    mysqli_stmt_bind_param($del, "i", $exam_id);
                    mysqli_stmt_execute($del);
                    mysqli_stmt_close($del);
                    foreach ($class_ids as $cid) {
                        if ($cid > 0) {
                            $ca = mysqli_prepare($conn, "INSERT IGNORE INTO exam_class_assignments (exam_id, class_id) VALUES (?,?)");
                            mysqli_stmt_bind_param($ca, "ii", $exam_id, $cid);
                            mysqli_stmt_execute($ca);
                            mysqli_stmt_close($ca);
                        }
                    }
                    $_SESSION['flash_success'] = "Exam updated successfully!";
                    safe_redirect("manage-exam.php");
                } else {
                    $error = "Error updating exam: " . mysqli_error($conn);
                    mysqli_stmt_close($stmt);
                }
            } else {
                $error = "Database query error. Please try again.";
            }
        }
    }
}

// Flash messages
if (!empty($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (!empty($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Fetch all classes for checkboxes
$classes_res = mysqli_query($conn, "SELECT * FROM classes ORDER BY sort_order, name");
$all_classes = [];
while ($row = mysqli_fetch_assoc($classes_res)) $all_classes[] = $row;

// Fetch exams with question counts and assigned classes
$exams = mysqli_query($conn, "SELECT e.*,
    (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS q_count,
    (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id AND q.question_type = 'descriptive') AS desc_count,
    (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order SEPARATOR ', ')
     FROM exam_class_assignments eca JOIN classes c ON c.id=eca.class_id
     WHERE eca.exam_id = e.id) AS assigned_classes,
    (SELECT GROUP_CONCAT(eca2.class_id SEPARATOR ',')
     FROM exam_class_assignments eca2 WHERE eca2.exam_id = e.id) AS assigned_class_ids
    FROM exams e ORDER BY e.id DESC");
$exams_list = [];
if ($exams) {
    while ($row = mysqli_fetch_assoc($exams)) {
        $exams_list[] = $row;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-journal-text text-primary"></i> Manage Exams</h4>
        <small class="text-muted">Create, edit, delete, and view questions for each exam.</small>
    </div>
    <div class="d-flex gap-2">
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExamModal">
            <i class="bi bi-plus-circle me-1"></i> Add New Exam
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-4">
    <!-- Top Horizontal Scrollbar Slider -->
    <div class="table-scroll-top-container d-none" id="examTableScrollTop">
        <div class="table-scroll-top-inner" id="examTableScrollTopInner"></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" id="examTableResponsive">
            <table class="table custom-table table-sticky-actions align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Exam Title</th>
                        <th>Duration</th>
                        <th>Schedule</th>
                        <th>Assigned To</th>
                        <th>Questions</th>
                        <th>Result Mode</th>
                        <th class="text-center pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!empty($exams_list)):
                        $i = 1;
                        foreach ($exams_list as $e):
                            $has_desc = ((int)($e['desc_count'] ?? 0)) > 0;
                            $mode = $e['result_mode'] ?? 'instant';
                            $assigned_ids = $e['assigned_class_ids'] ? array_map('intval', explode(',', $e['assigned_class_ids'])) : [];
                    ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                            <td><strong class="text-dark"><?php echo htmlspecialchars($e['title']); ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><i class="bi bi-clock me-1"></i><?php echo (int)$e['duration_minutes']; ?> mins</span></td>
                            <td>
                                <?php
                                $now  = time();
                                $s_at = $e['start_at'] ? strtotime($e['start_at']) : null;
                                $e_at = $e['end_at']   ? strtotime($e['end_at'])   : null;
                                if ($s_at && $e_at) {
                                    if ($now < $s_at)
                                        echo '<span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-2 py-1"><i class="bi bi-calendar-event me-1"></i>Opens ' . date('d M, H:i', $s_at) . '</span>';
                                    elseif ($now >= $s_at && $now <= $e_at)
                                        echo '<span class="badge bg-success rounded-pill px-2 py-1"><i class="bi bi-broadcast me-1"></i>LIVE until ' . date('H:i', $e_at) . '</span>';
                                    else
                                        echo '<span class="badge bg-secondary rounded-pill px-2 py-1"><i class="bi bi-lock me-1"></i>Ended ' . date('d M', $e_at) . '</span>';
                                } else {
                                    echo '<span class="text-muted small">Always open</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($e['assigned_classes'])): ?>
                                    <?php foreach (explode(', ', $e['assigned_classes']) as $cn): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1 me-1">
                                            <?php echo htmlspecialchars(trim($cn)); ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted small"><i class="bi bi-globe me-1"></i>All students</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="manage-questions.php?exam_id=<?php echo $e['id']; ?>" class="text-decoration-none">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">
                                        <i class="bi bi-patch-question me-1"></i><?php echo $e['q_count']; ?> questions
                                    </span>
                                </a>
                                <?php if ($has_desc): ?>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-2 py-1 ms-1">
                                        <i class="bi bi-pencil-square me-1"></i><?php echo $e['desc_count']; ?> descriptive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($has_desc): ?>
                                    <span class="badge bg-warning text-dark border rounded-pill px-3 py-1" title="Exams with descriptive answers always require manual evaluation">
                                        <i class="bi bi-pencil-square me-1"></i> Pending (Descriptive)
                                    </span>
                                <?php elseif ($mode === 'pending'): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-3 py-1">
                                        <i class="bi bi-hourglass-split me-1"></i> Pending Review
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">
                                        <i class="bi bi-lightning-charge me-1"></i> Instant Result
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-4">
                                <div class="btn-group btn-group-sm">
                                    <a href="manage-questions.php?exam_id=<?php echo $e['id']; ?>" class="btn btn-outline-primary" title="Manage Questions">
                                        <i class="bi bi-list-check me-1"></i> Questions
                                    </a>
                                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editExamModal<?php echo $e['id']; ?>" title="Edit Exam">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <!-- Delete via POST form (prevents CSRF via simple link) -->
                                    <form method="POST" action="manage-exam.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete exam &quot;<?php echo htmlspecialchars($e['title'], ENT_QUOTES); ?>&quot;? All associated questions and results will be deleted.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete_exam">
                                        <input type="hidden" name="id" value="<?php echo $e['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Exam">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php
                        endforeach;
                    else:
                    ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i> No exams found. Click "Add New Exam" to create one.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Exam Modals -->
<?php if (!empty($exams_list)): ?>
    <?php foreach ($exams_list as $e): 
        $has_desc = ((int)($e['desc_count'] ?? 0)) > 0;
        $mode = $e['result_mode'] ?? 'instant';
        $assigned_ids = $e['assigned_class_ids'] ? array_map('intval', explode(',', $e['assigned_class_ids'])) : [];
    ?>
    <div class="modal fade text-start" id="editExamModal<?php echo $e['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <form method="POST" action="manage-exam.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Exam</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="edit_exam" value="1">
                        <input type="hidden" name="exam_id" value="<?php echo $e['id']; ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Exam Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($e['title']); ?>" required maxlength="200">
                        </div>
                        <input type="hidden" name="duration_minutes" id="edit_dur_<?php echo $e['id']; ?>" value="<?php echo (int)$e['duration_minutes']; ?>">
                        <input type="hidden" name="result_mode" value="<?php echo htmlspecialchars($mode); ?>">
                        <hr class="my-3">
                        <p class="fw-semibold mb-2 text-primary small"><i class="bi bi-calendar-check me-1"></i> EXAM SCHEDULE (Strict College Window)</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Opens At <span class="text-danger">*</span></label>
                                <input type="datetime-local" name="start_at" id="edit_start_<?php echo $e['id']; ?>" class="form-control edit-start-input" data-exam-id="<?php echo $e['id']; ?>"
                                    value="<?php echo $e['start_at'] ? date('Y-m-d\TH:i', strtotime($e['start_at'])) : ''; ?>" required>
                                <div class="form-text">When all students can begin</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Closes At <span class="text-danger">*</span></label>
                                <input type="datetime-local" name="end_at" id="edit_end_<?php echo $e['id']; ?>" class="form-control edit-end-input" data-exam-id="<?php echo $e['id']; ?>"
                                    value="<?php echo $e['end_at'] ? date('Y-m-d\TH:i', strtotime($e['end_at'])) : ''; ?>" required>
                                <div class="form-text">Strict synchronized deadline for all students</div>
                            </div>
                        </div>
                        <div class="mt-2 text-muted small">
                            <i class="bi bi-clock-history me-1 text-primary"></i> Calculated Duration: <span class="badge bg-primary-subtle text-primary fw-bold" id="edit_calc_badge_<?php echo $e['id']; ?>"><?php echo (int)$e['duration_minutes']; ?> mins</span>
                        </div>
                        <hr class="my-3">
                        <p class="fw-semibold mb-2 text-muted small"><i class="bi bi-people me-1"></i> ASSIGN TO CLASSES</p>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($all_classes as $cl): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="class_ids[]"
                                    id="ec<?php echo $e['id']; ?>_c<?php echo $cl['id']; ?>"
                                    value="<?php echo $cl['id']; ?>"
                                    <?php echo in_array($cl['id'], $assigned_ids) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="ec<?php echo $e['id']; ?>_c<?php echo $cl['id']; ?>">
                                    <?php echo htmlspecialchars($cl['name']); ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text mt-1"><i class="bi bi-globe me-1"></i>Leave all unchecked = visible to <strong>all students</strong>.</div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Exam</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Add Exam Modal -->
<div class="modal fade" id="addExamModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form method="POST" action="manage-exam.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add New Exam</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="add_exam" value="1">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Exam Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Science &amp; Technology Quiz" required maxlength="200">
                    </div>
                    <input type="hidden" name="duration_minutes" id="add_duration" value="30">
                    <input type="hidden" name="result_mode" value="instant">
                    <hr class="my-3">
                    <p class="fw-semibold mb-2 text-primary small"><i class="bi bi-calendar-check me-1"></i> EXAM SCHEDULE (Strict College Window)</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Opens At <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="start_at" id="add_start_at" class="form-control" required>
                            <div class="form-text">When all students can begin</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Closes At <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="end_at" id="add_end_at" class="form-control" required>
                            <div class="form-text">Strict synchronized deadline for all students</div>
                        </div>
                    </div>
                    <div class="mt-2 text-muted small">
                        <i class="bi bi-clock-history me-1 text-primary"></i> Calculated Duration: <span class="badge bg-primary-subtle text-primary fw-bold" id="add_calc_badge">-- mins</span>
                    </div>
                    <hr class="my-3">
                    <p class="fw-semibold mb-2 text-muted small"><i class="bi bi-people me-1"></i> ASSIGN TO CLASSES</p>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($all_classes as $cl): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="class_ids[]"
                                id="add_c<?php echo $cl['id']; ?>" value="<?php echo $cl['id']; ?>">
                            <label class="form-check-label fw-semibold" for="add_c<?php echo $cl['id']; ?>">
                                <?php echo htmlspecialchars($cl['name']); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text mt-1"><i class="bi bi-globe me-1"></i>Leave all unchecked = visible to <strong>all students</strong>.</div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Exam</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // ── Table Top Horizontal Scrollbar Sync ──
    const topScroll = document.getElementById('examTableScrollTop');
    const tableCont = document.getElementById('examTableResponsive');
    if (topScroll && tableCont) {
        const topInner = document.getElementById('examTableScrollTopInner');
        const table = tableCont.querySelector('table');

        function updateScrollWidth() {
            if (!table) return;
            const scrollW = table.scrollWidth;
            const clientW = tableCont.clientWidth;
            if (scrollW > clientW + 5) {
                topScroll.classList.remove('d-none');
                if (topInner) topInner.style.width = scrollW + 'px';
            } else {
                topScroll.classList.add('d-none');
            }
        }

        let isSyncing = false;
        topScroll.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                tableCont.scrollLeft = topScroll.scrollLeft;
                requestAnimationFrame(function() { isSyncing = false; });
            }
        });
        tableCont.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                topScroll.scrollLeft = tableCont.scrollLeft;
                requestAnimationFrame(function() { isSyncing = false; });
            }
        });

        window.addEventListener('resize', updateScrollWidth);
        updateScrollWidth();
        setTimeout(updateScrollWidth, 300);
    }

    // ── Add Exam Duration Auto-Calculation ──
    const startIn   = document.getElementById('add_start_at');
    const endIn     = document.getElementById('add_end_at');
    const durIn     = document.getElementById('add_duration');
    const addBadge  = document.getElementById('add_calc_badge');

    function updateAddDuration() {
        if (!startIn || !endIn) return;
        if (startIn.value && endIn.value) {
            const s = new Date(startIn.value);
            const e = new Date(endIn.value);
            if (!isNaN(s.getTime()) && !isNaN(e.getTime())) {
                const diffMins = Math.round((e - s) / 60000);
                if (diffMins > 0) {
                    if (durIn) durIn.value = diffMins;
                    if (addBadge) addBadge.textContent = diffMins + ' mins';
                } else {
                    if (addBadge) addBadge.textContent = 'Closes At must be after Opens At';
                }
            }
        } else if (durIn && addBadge) {
            addBadge.textContent = (durIn.value || 30) + ' mins';
        }
    }

    if (startIn) startIn.addEventListener('change', updateAddDuration);
    if (endIn)   endIn.addEventListener('change', updateAddDuration);
    updateAddDuration();

    // ── Edit Exam Duration Auto-Calculation ──
    document.querySelectorAll('.edit-start-input').forEach(function(startEl) {
        const id = startEl.dataset.examId;
        const endEl   = document.getElementById('edit_end_' + id);
        const durEl   = document.getElementById('edit_dur_' + id);
        const badgeEl = document.getElementById('edit_calc_badge_' + id);

        function updateEditDuration() {
            if (!startEl || !endEl) return;
            if (startEl.value && endEl.value) {
                const s = new Date(startEl.value);
                const e = new Date(endEl.value);
                if (!isNaN(s.getTime()) && !isNaN(e.getTime())) {
                    const diffMins = Math.round((e - s) / 60000);
                    if (diffMins > 0) {
                        if (durEl) durEl.value = diffMins;
                        if (badgeEl) badgeEl.textContent = diffMins + ' mins';
                    } else {
                        if (badgeEl) badgeEl.textContent = 'Invalid duration';
                    }
                }
            }
        }

        startEl.addEventListener('change', updateEditDuration);
        if (endEl) endEl.addEventListener('change', updateEditDuration);
    });

    <?php if (isset($_GET['action']) && $_GET['action'] === 'new'): ?>
    var addModalEl = document.getElementById('addExamModal');
    if (addModalEl) {
        var modal = new bootstrap.Modal(addModalEl);
        modal.show();
    }
    <?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>
