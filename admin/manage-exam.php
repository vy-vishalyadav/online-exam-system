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
        header("Location: manage-exam.php");
        exit;
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
        } elseif ($duration < 1 || $duration > 600) {
            $error = "Duration must be between 1 and 600 minutes.";
        } elseif ($start_at && $end_at && strtotime($end_at) <= strtotime($start_at)) {
            $error = "End time must be after start time.";
        } else {
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
                    header("Location: manage-exam.php"); exit;
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
        } elseif ($duration < 1 || $duration > 600) {
            $error = "Duration must be between 1 and 600 minutes.";
        } elseif ($start_at && $end_at && strtotime($end_at) <= strtotime($start_at)) {
            $error = "End time must be after start time.";
        } else {
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
                    header("Location: manage-exam.php"); exit;
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
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
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
                    if ($exams && mysqli_num_rows($exams) > 0):
                        $i = 1;
                        while ($e = mysqli_fetch_assoc($exams)):
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

                                <!-- Edit Exam Modal -->
                                <div class="modal fade text-start" id="editExamModal<?php echo $e['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
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
                                                        <label class="form-label fw-semibold">Exam Title</label>
                                                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($e['title']); ?>" required maxlength="200">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Duration (minutes)</label>
                                                        <input type="number" name="duration_minutes" class="form-control" min="1" max="600" value="<?php echo (int)$e['duration_minutes']; ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Result Release Mode</label>
                                                        <select name="result_mode" class="form-select">
                                                            <option value="instant" <?php echo ($mode === 'instant') ? 'selected' : ''; ?>>Instant Result (Auto-release score on submit)</option>
                                                            <option value="pending" <?php echo ($mode === 'pending') ? 'selected' : ''; ?>>Pending Review (Hold score for instructor review)</option>
                                                        </select>
                                                        <div class="form-text text-muted">
                                                            <i class="bi bi-info-circle me-1"></i> If this exam has descriptive questions, results will automatically be held for review.
                                                        </div>
                                                    </div>
                                                    <hr class="my-3">
                                                    <p class="fw-semibold mb-2 text-muted small"><i class="bi bi-calendar-range me-1"></i> EXAM SCHEDULE (optional)</p>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold">Opens At</label>
                                                            <input type="datetime-local" name="start_at" class="form-control"
                                                                value="<?php echo $e['start_at'] ? date('Y-m-d\TH:i', strtotime($e['start_at'])) : ''; ?>">
                                                            <div class="form-text">Leave blank = always accessible</div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-semibold">Closes At</label>
                                                            <input type="datetime-local" name="end_at" class="form-control"
                                                                value="<?php echo $e['end_at'] ? date('Y-m-d\TH:i', strtotime($e['end_at'])) : ''; ?>">
                                                            <div class="form-text">Students locked out after this</div>
                                                        </div>
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

                            </td>
                        </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i> No exams found. Click "Add New Exam" to create one.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Exam Modal -->
<div class="modal fade" id="addExamModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="manage-exam.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add New Exam</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="add_exam" value="1">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Exam Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Science &amp; Technology Quiz" required maxlength="200">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Duration (minutes)</label>
                        <input type="number" name="duration_minutes" class="form-control" min="1" max="600" value="30" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Result Release Mode</label>
                        <select name="result_mode" class="form-select">
                            <option value="instant" selected>Instant Result (Auto-release score on submit)</option>
                            <option value="pending">Pending Review (Hold score for instructor review)</option>
                        </select>
                        <div class="form-text text-muted">
                            <i class="bi bi-info-circle me-1"></i> If descriptive questions are added, results will automatically be set to Pending Review.
                        </div>
                    </div>
                    <hr class="my-3">
                    <p class="fw-semibold mb-2 text-muted small"><i class="bi bi-calendar-range me-1"></i> EXAM SCHEDULE (optional)</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Opens At</label>
                            <input type="datetime-local" name="start_at" class="form-control">
                            <div class="form-text">Leave blank = always accessible</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Closes At</label>
                            <input type="datetime-local" name="end_at" class="form-control">
                            <div class="form-text">Students locked out after this</div>
                        </div>
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

<?php 
// Open add modal automatically if requested via URL action=new
if (isset($_GET['action']) && $_GET['action'] === 'new'): 
?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var modal = new bootstrap.Modal(document.getElementById('addExamModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
