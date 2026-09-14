<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) { header("Location: ../index.php"); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$error = $success = "";

// ── Delete student ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_student') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $error = "Invalid request."; }
    else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM students WHERE id=?");
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt) ? $_SESSION['flash_success'] = "Student deleted." : $_SESSION['flash_error'] = mysqli_error($conn);
            mysqli_stmt_close($stmt);
        }
        header("Location: manage-students.php"); exit;
    }
}

// ── Reset single student password ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $error = "Invalid request."; }
    else {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = mysqli_prepare($conn, "UPDATE students SET password='student' WHERE id=?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = "Password reset to 'student'.";
        header("Location: manage-students.php"); exit;
    }
}

// ── Add student ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $error = "Invalid request."; }
    else {
        $name     = trim($_POST['name'] ?? '');
        $class_id = (int)($_POST['class_id'] ?? 0) ?: null;

        if (empty($name))           { $error = "Student name is required."; }
        elseif (strlen($name) > 150){ $error = "Name too long (max 150 chars)."; }
        else {
            // Generate next 5-digit student ID
            $res = mysqli_query($conn,
                "SELECT MAX(CAST(SUBSTRING_INDEX(email, '@', 1) AS UNSIGNED)) AS max_num
                 FROM students WHERE email REGEXP '^[0-9]+@rclasses\\.com$'");
            $row      = $res ? mysqli_fetch_assoc($res) : null;
            $next_num = max(10001, (int)($row['max_num'] ?? 10000) + 1);
            $login_id = $next_num . "@rclasses.com";

            $stmt = mysqli_prepare($conn, "INSERT INTO students (name, class_id, email, password) VALUES (?,?,?,'student')");
            mysqli_stmt_bind_param($stmt, "sis", $name, $class_id, $login_id);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['flash_success'] = "Student <strong>" . htmlspecialchars($name, ENT_QUOTES) . "</strong> registered! &nbsp;|&nbsp; Login ID: <strong class='text-primary'>{$login_id}</strong> &nbsp;|&nbsp; Password: <strong>student</strong>";
                header("Location: manage-students.php"); exit;
            } else {
                $error = mysqli_errno($conn) === 1062 ? "ID conflict, please try again." : "Error: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// ── Edit student ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $error = "Invalid request."; }
    else {
        $id       = (int)($_POST['student_id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $class_id = (int)($_POST['class_id'] ?? 0) ?: null;

        if (empty($name) || empty($email)) { $error = "Name and Student ID are required."; }
        else {
            // Check duplicate email (excluding this student)
            $chk = mysqli_prepare($conn, "SELECT id FROM students WHERE email=? AND id!=? LIMIT 1");
            mysqli_stmt_bind_param($chk, "si", $email, $id);
            mysqli_stmt_execute($chk);
            mysqli_stmt_store_result($chk);
            $dup = mysqli_stmt_num_rows($chk) > 0;
            mysqli_stmt_close($chk);

            if ($dup) { $error = "Student ID '" . htmlspecialchars($email) . "' already used."; }
            else {
                $stmt = mysqli_prepare($conn, "UPDATE students SET name=?, class_id=?, email=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, "sisi", $name, $class_id, $email, $id);
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['flash_success'] = "Student updated.";
                    header("Location: manage-students.php"); exit;
                } else { $error = "Update failed: " . mysqli_error($conn); }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

// Flash
if (!empty($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (!empty($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Fetch all classes for dropdowns
$classes_res = mysqli_query($conn, "SELECT * FROM classes ORDER BY sort_order, name");
$classes = [];
while ($row = mysqli_fetch_assoc($classes_res)) $classes[] = $row;

// Active class filter from URL
$filter_class = (int)($_GET['class_id'] ?? 0);

// Fetch students with class name and attempt count
$students_res = mysqli_query($conn,
    "SELECT s.*, c.name AS class_name,
            (SELECT COUNT(*) FROM results r WHERE r.student_id = s.id) AS exam_count
     FROM students s
     LEFT JOIN classes c ON s.class_id = c.id
     ORDER BY c.sort_order ASC, c.name ASC, s.name ASC");
$students = [];
while ($row = mysqli_fetch_assoc($students_res)) $students[] = $row;
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-people-fill text-primary me-1"></i> Manage Students</h4>
        <small class="text-muted">Register, edit, delete and manage student credentials.</small>
    </div>
    <div class="d-flex gap-2">
        <a href="manage-classes.php" class="btn btn-outline-secondary fw-semibold"><i class="bi bi-diagram-3 me-1"></i> Classes</a>
        <a href="dashboard.php" class="btn btn-outline-secondary fw-semibold"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
        <button class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#addStudentModal">
            <i class="bi bi-person-plus-fill me-1"></i> Add Student
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
<div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm border-0 rounded-3">
    <i class="bi bi-check-circle-fill me-2 fs-5"></i><?php echo $success; ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filter tabs -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="btn-group btn-group-sm" id="classFilterGroup">
        <button type="button" class="btn btn-primary active" onclick="filterClass(0, this)">All</button>
        <?php foreach ($classes as $c): ?>
        <button type="button" class="btn btn-outline-primary" onclick="filterClass(<?php echo $c['id']; ?>, this)">
            <?php echo htmlspecialchars($c['name']); ?>
        </button>
        <?php endforeach; ?>
        <button type="button" class="btn btn-outline-secondary" onclick="filterClass(-1, this)">No Class</button>
    </div>
    <span class="badge bg-primary rounded-pill px-3 py-2" id="studentCountBadge"><?php echo count($students); ?> Total</span>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0" id="studentsTable">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Student Name</th>
                        <th>Student ID <small class="fw-normal text-muted">(permanent)</small></th>
                        <th>Class</th>
                        <th>Exams</th>
                        <th class="text-center pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No students registered yet.</td></tr>
                    <?php else: $i = 1; foreach ($students as $s): ?>
                    <tr class="student-row" data-class-id="<?php echo (int)($s['class_id'] ?? 0); ?>">
                        <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                        <td>
                            <a href="student-profile.php?id=<?php echo $s['id']; ?>" class="fw-bold text-dark text-decoration-none">
                                <?php echo htmlspecialchars($s['name']); ?>
                                <i class="bi bi-box-arrow-up-right ms-1 small text-muted"></i>
                            </a>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border font-monospace">
                                <i class="bi bi-person-badge me-1 text-primary"></i><?php echo htmlspecialchars($s['email']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($s['class_name']): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1">
                                    <?php echo htmlspecialchars($s['class_name']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small fst-italic">No class</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">
                                <?php echo $s['exam_count']; ?> attempt(s)
                            </span>
                        </td>
                        <td class="text-center pe-4">
                            <div class="btn-group btn-group-sm">
                                <a href="student-profile.php?id=<?php echo $s['id']; ?>" class="btn btn-outline-info" title="View Profile">
                                    <i class="bi bi-person-lines-fill"></i>
                                </a>
                                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editStudentModal<?php echo $s['id']; ?>" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Reset password of <?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?> to \'student\'?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                    <button type="submit" class="btn btn-outline-warning btn-sm" title="Reset Password"><i class="bi bi-key"></i></button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete student <?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete_student">
                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>

                            <!-- Edit Student Modal -->
                            <div class="modal fade text-start" id="editStudentModal<?php echo $s['id']; ?>" tabindex="-1">
                                <div class="modal-dialog"><div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="edit_student" value="1">
                                        <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">
                                        <div class="modal-header bg-light"><h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Student</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body p-4">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Full Name</label>
                                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($s['name']); ?>" required maxlength="150">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Class</label>
                                                <select name="class_id" class="form-select">
                                                    <option value="">No Class Assigned</option>
                                                    <?php foreach ($classes as $c): ?>
                                                    <option value="<?php echo $c['id']; ?>" <?php echo ($s['class_id'] == $c['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($c['name']); ?> — <?php echo htmlspecialchars($c['description'] ?? ''); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text"><i class="bi bi-info-circle me-1 text-primary"></i>Changing class does NOT change the student's permanent ID.</div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Student ID <span class="text-muted fw-normal">(permanent)</span></label>
                                                <input type="text" name="email" class="form-control font-monospace" value="<?php echo htmlspecialchars($s['email']); ?>" required maxlength="100">
                                                <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Rarely needs changing. Student uses this to log in.</div>
                                            </div>
                                            <div class="alert alert-info border-0 bg-info-subtle mb-0 py-2 px-3 small">
                                                <i class="bi bi-key me-1"></i> To reset password, use the <strong>Reset PW</strong> button in the table.
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light">
                                            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button class="btn btn-primary">Update Student</button>
                                        </div>
                                    </form>
                                </div></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="add_student" value="1">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Add New Student</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4">
                <div class="mb-4">
                    <label class="form-label fw-bold">Student Full Name</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-light"><i class="bi bi-person text-muted"></i></span>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Rahul Sharma" required autofocus maxlength="150">
                    </div>
                    <div class="form-text text-muted mt-2">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        A unique 5-digit permanent ID (e.g. <code>10001@rclasses.com</code>) is auto-created with password <code>student</code>.
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-bold">Class</label>
                    <select name="class_id" class="form-select">
                        <option value="">No Class Assigned</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> — <?php echo htmlspecialchars($c['description'] ?? ''); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary fw-bold"><i class="bi bi-plus-circle me-1"></i> Add Student</button>
            </div>
        </form>
    </div></div>
</div>

<script>
function filterClass(classId, btn) {
    document.querySelectorAll('#classFilterGroup .btn').forEach(b => {
        b.classList.remove('btn-primary','btn-secondary');
        b.classList.add(b.dataset.variant || 'btn-outline-primary');
        if (b.classList.contains('btn-outline-secondary') || b.textContent.trim() === 'No Class') {
            b.classList.remove('btn-primary'); b.classList.add('btn-outline-secondary');
        }
    });
    btn.classList.remove('btn-outline-primary','btn-outline-secondary');
    btn.classList.add(classId === -1 ? 'btn-secondary' : 'btn-primary');

    let visible = 0;
    document.querySelectorAll('.student-row').forEach(row => {
        const cid = parseInt(row.dataset.classId);
        let show = (classId === 0) || (classId === -1 && cid === 0) || (classId > 0 && cid === classId);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('studentCountBadge').textContent = visible + ' Shown';
}
// Auto-open add modal if requested via URL
<?php if (($_GET['action'] ?? '') === 'new'): ?>
document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('addStudentModal')).show());
<?php endif; ?>
// Auto-filter if class_id in URL
<?php if ($filter_class): ?>
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.querySelector(`#classFilterGroup button:nth-child(<?php
        $idx = 2;
        foreach ($classes as $idx2 => $c) { if ($c['id'] == $filter_class) { $idx = $idx2 + 2; break; } }
        echo $idx;
    ?>)`);
    if (btn) filterClass(<?php echo $filter_class; ?>, btn);
});
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>
