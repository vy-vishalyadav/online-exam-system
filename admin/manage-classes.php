<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) { header("Location: ../index.php"); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$error = $success = "";

// ── Delete class ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_class') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $cid = (int)($_POST['id'] ?? 0);
        // Check if students are currently assigned to this class
        $chk = mysqli_prepare($conn, "SELECT COUNT(*) FROM students WHERE class_id=?");
        mysqli_stmt_bind_param($chk, "i", $cid);
        mysqli_stmt_execute($chk);
        mysqli_stmt_bind_result($chk, $cnt);
        mysqli_stmt_fetch($chk);
        mysqli_stmt_close($chk);

        // Safely unassign any students enrolled in this class
        if ($cnt > 0) {
            $u = mysqli_prepare($conn, "UPDATE students SET class_id = NULL WHERE class_id = ?");
            mysqli_stmt_bind_param($u, "i", $cid);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
        }

        // Remove any exam class assignments for this class
        $d = mysqli_prepare($conn, "DELETE FROM exam_class_assignments WHERE class_id = ?");
        mysqli_stmt_bind_param($d, "i", $cid);
        mysqli_stmt_execute($d);
        mysqli_stmt_close($d);

        // Delete the class record
        $stmt = mysqli_prepare($conn, "DELETE FROM classes WHERE id=?");
        mysqli_stmt_bind_param($stmt, "i", $cid);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['flash_success'] = "Class deleted successfully" . ($cnt > 0 ? " ($cnt student(s) unassigned)." : ".");
        } else {
            $_SESSION['flash_error'] = "Delete failed: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);

        safe_redirect("manage-classes.php");
    }
}

// ── Add class ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $name  = strtoupper(trim($_POST['name'] ?? ''));
        $desc  = trim($_POST['description'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        if (empty($name)) { $error = "Class name is required."; }
        elseif (strlen($name) > 50) { $error = "Name too long (max 50)."; }
        else {
            $stmt = mysqli_prepare($conn, "INSERT INTO classes (name, description, sort_order) VALUES (?,?,?)");
            mysqli_stmt_bind_param($stmt, "ssi", $name, $desc, $sort);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['flash_success'] = "Class '$name' added.";
                safe_redirect("manage-classes.php");
            } else {
                $error = mysqli_errno($conn) === 1062 ? "Class '$name' already exists." : "Error: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// ── Edit class ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_class'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $cid  = (int)($_POST['class_id'] ?? 0);
        $name = strtoupper(trim($_POST['name'] ?? ''));
        $desc = trim($_POST['description'] ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);
        if (empty($name)) { $error = "Class name is required."; }
        elseif (strlen($name) > 50) { $error = "Name too long (max 50)."; }
        else {
            $stmt = mysqli_prepare($conn, "UPDATE classes SET name=?, description=?, sort_order=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "ssii", $name, $desc, $sort, $cid);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['flash_success'] = "Class updated.";
                safe_redirect("manage-classes.php");
            } else {
                $error = mysqli_errno($conn) === 1062 ? "Class '$name' already exists." : "Update failed: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// ── Bulk promote ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'promote_class') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $from = (int)($_POST['from_class_id'] ?? 0);
        $to   = (int)($_POST['to_class_id']   ?? 0);
        if ($from && $to && $from !== $to) {
            $stmt = mysqli_prepare($conn, "UPDATE students SET class_id=? WHERE class_id=?");
            mysqli_stmt_bind_param($stmt, "ii", $to, $from);
            mysqli_stmt_execute($stmt);
            $moved = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['flash_success'] = "$moved student(s) promoted successfully.";
        } else {
            $_SESSION['flash_error'] = "Please select two different classes.";
        }
        safe_redirect("manage-classes.php");
    }
}

// ── Reset all passwords in class ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_class_passwords') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $cid = (int)($_POST['class_id'] ?? 0);
        $hashed_pw = password_hash('student', PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE students SET password=? WHERE class_id=?");
        mysqli_stmt_bind_param($stmt, "si", $hashed_pw, $cid);
        mysqli_stmt_execute($stmt);
        $done = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = "Reset passwords for $done student(s) to 'student'.";
        safe_redirect("manage-classes.php");
    }
}

// Flash messages
if (!empty($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (!empty($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Fetch classes with student count
$classes_res = mysqli_query($conn,
    "SELECT c.*, COUNT(s.id) AS student_count
     FROM classes c LEFT JOIN students s ON s.class_id = c.id
     GROUP BY c.id ORDER BY c.sort_order ASC, c.name ASC");
$classes = [];
while ($row = mysqli_fetch_assoc($classes_res)) $classes[] = $row;
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-diagram-3-fill text-primary me-2"></i>Manage Classes</h4>
        <small class="text-muted">Create and manage student class groups. Bulk-promote at year end.</small>
    </div>
    <div class="d-flex gap-2">
        <a href="dashboard.php" class="btn btn-outline-secondary fw-semibold"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
        <button class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#addClassModal">
            <i class="bi bi-plus-circle me-1"></i> Add Class
        </button>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4 border-0 rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show mb-4 border-0 rounded-3 shadow-sm">
    <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Bulk Promote card -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-warning-subtle border-0 rounded-top-4 py-3">
        <h6 class="fw-bold mb-0 text-warning-emphasis"><i class="bi bi-arrow-up-circle-fill me-2"></i>Bulk Promote — Year End</h6>
    </div>
    <div class="card-body py-3">
        <div class="alert alert-warning border-0 rounded-3 mb-3 py-2 px-3 small">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Promote in the correct order — highest class first:</strong>
            <span class="ms-1">TYIT → Graduated &nbsp;›&nbsp; SYIT → TYIT &nbsp;›&nbsp; FYIT → SYIT.</span>
            <br><span class="text-muted ms-4">Doing it in the wrong order will mix two batches together.</span>
        </div>
        <form method="POST" class="row g-2 align-items-end" onsubmit="return confirm('Move ALL students from selected class to another? This cannot be undone easily.')">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="promote_class">
            <div class="col-md-4">
                <label class="form-label fw-semibold small">From Class</label>
                <select name="from_class_id" class="form-select form-select-sm" required>
                    <option value="">Select class to promote from…</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['student_count']; ?> students)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 text-center pt-4"><i class="bi bi-arrow-right fs-5 text-muted"></i></div>
            <div class="col-md-4">
                <label class="form-label fw-semibold small">To Class</label>
                <select name="to_class_id" class="form-select form-select-sm" required>
                    <option value="">Select destination class…</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-warning btn-sm fw-bold px-4">
                    <i class="bi bi-arrow-up-circle me-1"></i> Promote All
                </button>
            </div>
        </form>
        <div class="form-text text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Student permanent IDs never change. Only their class assignment is updated.</div>
    </div>
</div>

<!-- Classes Table -->
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Class Name</th>
                        <th>Description</th>
                        <th>Students</th>
                        <th>Sort Order</th>
                        <th class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($classes)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>No classes yet.</td></tr>
                    <?php else: $i = 1; foreach ($classes as $c): ?>
                    <tr>
                        <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                        <td>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-2 fw-bold fs-6">
                                <?php echo htmlspecialchars($c['name']); ?>
                            </span>
                        </td>
                        <td class="text-muted"><?php echo htmlspecialchars($c['description'] ?? '—'); ?></td>
                        <td>
                            <a href="manage-students.php?class_id=<?php echo $c['id']; ?>" class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-3 py-1 text-decoration-none">
                                <i class="bi bi-people me-1"></i><?php echo $c['student_count']; ?> students
                            </a>
                        </td>
                        <td class="text-muted"><?php echo $c['sort_order']; ?></td>
                        <td class="pe-4 text-end">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editClassModal<?php echo $c['id']; ?>">
                                    <i class="bi bi-pencil me-1"></i>Edit
                                </button>
                                <!-- Reset passwords in class -->
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Reset ALL passwords in <?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?> to \'student\'?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="reset_class_passwords">
                                    <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>">
                                    <button type="submit" class="btn btn-outline-warning btn-sm" title="Reset all passwords in this class to 'student'">
                                        <i class="bi bi-key me-1"></i>Reset PW
                                    </button>
                                </form>
                                <!-- Delete -->
                                <form method="POST" style="display:inline;" onsubmit="return confirmDeleteClass('<?php echo htmlspecialchars(addslashes($c['name']), ENT_QUOTES); ?>', <?php echo (int)$c['student_count']; ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete_class">
                                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="<?php echo $c['student_count'] > 0 ? 'Delete class (' . $c['student_count'] . ' student(s) enrolled)' : 'Delete class'; ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>

                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Class Modals -->
<?php if (!empty($classes)): ?>
    <?php foreach ($classes as $c): ?>
    <div class="modal fade text-start" id="editClassModal<?php echo $c['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="edit_class" value="1">
                    <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Class</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Class Name</label>
                            <input type="text" name="name" class="form-control text-uppercase" value="<?php echo htmlspecialchars($c['name']); ?>" required maxlength="50">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" name="description" class="form-control" value="<?php echo htmlspecialchars($c['description'] ?? ''); ?>" maxlength="200">
                        </div>
                        <div class="mb-0">
                            <label class="form-label fw-semibold">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" value="<?php echo (int)$c['sort_order']; ?>" min="0">
                            <div class="form-text">Lower number = shown first (e.g. FYIT=1, SYIT=2, TYIT=3)</div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Add Class Modal -->
<div class="modal fade" id="addClassModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="add_class" value="1">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add New Class</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Class Name</label>
                    <input type="text" name="name" class="form-control text-uppercase" placeholder="e.g. TYIT, FYBSC, SYBCOM" required maxlength="50" autofocus>
                    <div class="form-text">Will be auto-converted to uppercase.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="description" class="form-control" placeholder="e.g. Third Year Information Technology" maxlength="200">
                </div>
                <div class="mb-0">
                    <label class="form-label fw-semibold">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?php echo count($classes) + 1; ?>" min="0">
                    <div class="form-text">Controls display order. Lower = first.</div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-plus-circle me-1"></i>Add Class</button>
            </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDeleteClass(className, studentCount) {
    if (studentCount > 0) {
        return confirm("Class '" + className + "' currently has " + studentCount + " student(s) enrolled.\n\nDeleting this class will unassign these " + studentCount + " student(s) (they will remain in the system with 'No Class').\n\nTo move students to another class instead, click Cancel and use the 'Promote / Move Students' tool.\n\nAre you sure you want to proceed with deleting this class?");
    }
    return confirm("Are you sure you want to delete class '" + className + "'?");
}
</script>

<?php include '../includes/footer.php'; ?>
