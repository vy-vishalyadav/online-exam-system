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
        safe_redirect("manage-students.php");
    }
}

// ── Reset single student password ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $error = "Invalid request."; }
    else {
        $id = (int)($_POST['id'] ?? 0);
        $hashed_pw = password_hash('student', PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE students SET password=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "si", $hashed_pw, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = "Password reset to 'student'.";
        safe_redirect("manage-students.php");
    }
}

// ── Add student ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $error = "Invalid request."; }
    else {
        $name     = trim($_POST['name'] ?? '');
        $class_id = (int)($_POST['class_id'] ?? 0);

        if (empty($name))            { $error = "Student name is required."; }
        elseif (strlen($name) > 150) { $error = "Name too long (max 150 chars)."; }
        elseif ($class_id <= 0)      { $error = "Class is required. Please select a class."; }
        else {
            // Fetch class details to format domain
            $cls_stmt = mysqli_prepare($conn, "SELECT name FROM classes WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($cls_stmt, "i", $class_id);
            mysqli_stmt_execute($cls_stmt);
            $cls_res = mysqli_stmt_get_result($cls_stmt);
            $cls_row = $cls_res ? mysqli_fetch_assoc($cls_res) : null;
            mysqli_stmt_close($cls_stmt);

            if (!$cls_row) {
                $error = "The selected class does not exist.";
            } else {
                // Class name to domain suffix: e.g. "TYIT" -> "tyit.com"
                $clean_class = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $cls_row['name']));
                if (empty($clean_class)) {
                    $clean_class = 'class' . $class_id;
                }
                $domain = $clean_class . '.com';

                // Find highest existing 5-digit ID for this class domain using prepared statement
                $domain_pattern = "%@" . $domain;
                $stmt_max = mysqli_prepare($conn,
                    "SELECT MAX(CAST(SUBSTRING_INDEX(email, '@', 1) AS UNSIGNED)) AS max_num
                     FROM students WHERE email LIKE ? AND email REGEXP '^[0-9]+@'");
                $row = null;
                if ($stmt_max) {
                    mysqli_stmt_bind_param($stmt_max, "s", $domain_pattern);
                    mysqli_stmt_execute($stmt_max);
                    $res = mysqli_stmt_get_result($stmt_max);
                    $row = $res ? mysqli_fetch_assoc($res) : null;
                    mysqli_stmt_close($stmt_max);
                }
                $next_num = max(10001, (int)($row['max_num'] ?? 10000) + 1);
                $login_id = $next_num . "@" . $domain;

                // Ensure unique ID across all students
                while (true) {
                    $chk = mysqli_prepare($conn, "SELECT id FROM students WHERE email = ? LIMIT 1");
                    mysqli_stmt_bind_param($chk, "s", $login_id);
                    mysqli_stmt_execute($chk);
                    mysqli_stmt_store_result($chk);
                    $exists = mysqli_stmt_num_rows($chk) > 0;
                    mysqli_stmt_close($chk);
                    if (!$exists) break;
                    $next_num++;
                    $login_id = $next_num . "@" . $domain;
                }

                $hashed_pw = password_hash('student', PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($conn, "INSERT INTO students (name, class_id, email, password) VALUES (?,?,?,?)");
                mysqli_stmt_bind_param($stmt, "siss", $name, $class_id, $login_id, $hashed_pw);
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['flash_success'] = "Student <strong>" . htmlspecialchars($name, ENT_QUOTES) . "</strong> registered! &nbsp;|&nbsp; Login ID: <strong class='text-primary'>{$login_id}</strong> &nbsp;|&nbsp; Password: <strong>student</strong>";
                    safe_redirect("manage-students.php");
                } else {
                    $error = mysqli_errno($conn) === 1062 ? "ID conflict, please try again." : "Error: " . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            }
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
        $class_id = (int)($_POST['class_id'] ?? 0);

        if (empty($name) || empty($email)) { 
            $error = "Name and Student ID are required."; 
        } elseif ($class_id <= 0) {
            $error = "Class is required. Please select a valid class.";
        } else {
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
                    safe_redirect("manage-students.php");
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

// Active class and search filter from URL
$filter_class = (int)($_GET['class_id'] ?? 0);
$search       = trim($_GET['search'] ?? '');

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

<!-- Search & Filters Card -->
<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body py-3">
        <div class="row g-3 align-items-center">
            <!-- Student Search Bar -->
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="studentSearchInput" class="form-control border-start-0" 
                           placeholder="Search by student name or ID..." 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           oninput="onSearchInput(this.value)">
                    <button class="btn btn-outline-secondary border-start-0" type="button" id="clearSearchBtn" 
                            style="<?php echo empty($search) ? 'display: none;' : ''; ?>" onclick="clearSearch()" title="Clear search">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
            <!-- Class Dropdown Filter -->
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-funnel text-muted"></i></span>
                    <select id="classFilterSelect" class="form-select" onchange="onClassSelectChange(this.value)">
                        <option value="0">All Classes</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($filter_class == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <!-- Stats & Quick Actions -->
            <div class="col-md-4 d-flex justify-content-md-end align-items-center gap-2">
                <span class="badge bg-primary rounded-pill px-3 py-2" id="studentCountBadge"><?php echo count($students); ?> Total</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="resetFiltersBtn" onclick="resetAllFilters()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                </button>
            </div>
        </div>

        <!-- Quick Class Pills -->
        <div class="d-flex align-items-center mt-3 pt-2 border-top flex-wrap gap-2">
            <small class="text-muted fw-semibold me-1"><i class="bi bi-tags me-1"></i>Quick Filter:</small>
            <div class="btn-group btn-group-sm flex-wrap" id="classFilterGroup">
                <button type="button" class="btn btn-primary active" data-class-id="0" onclick="filterClass(0)">All</button>
                <?php foreach ($classes as $c): ?>
                <button type="button" class="btn btn-outline-primary" data-class-id="<?php echo $c['id']; ?>" onclick="filterClass(<?php echo $c['id']; ?>)">
                    <?php echo htmlspecialchars($c['name']); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <!-- Top Horizontal Scrollbar Slider -->
    <div class="table-scroll-top-container d-none" id="studentsTableScrollTop">
        <div class="table-scroll-top-inner" id="studentsTableScrollTopInner"></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" id="studentsTableResponsive">
            <table class="table custom-table table-sticky-actions align-middle mb-0" id="studentsTable">
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
                    <tr class="student-row" 
                        data-class-id="<?php echo (int)($s['class_id'] ?? 0); ?>" 
                        data-name="<?php echo htmlspecialchars(strtolower($s['name'])); ?>" 
                        data-email="<?php echo htmlspecialchars(strtolower($s['email'])); ?>">
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
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    <!-- Empty Search State -->
                    <tr id="noMatchesRow" style="display: none;">
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-search fs-2 d-block mb-2 text-secondary"></i>
                            No students found matching your search criteria.
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="resetAllFilters()">
                                    Clear Search & Filters
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Student Modals (Placed outside table to prevent backdrop stacking trap) -->
<?php if (!empty($students)): foreach ($students as $s): ?>
<div class="modal fade text-start" id="editStudentModal<?php echo $s['id']; ?>" tabindex="-1" aria-labelledby="editStudentModalLabel<?php echo $s['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="edit_student" value="1">
                <input type="hidden" name="student_id" value="<?php echo $s['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold text-dark" id="editStudentModalLabel<?php echo $s['id']; ?>"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($s['name']); ?>" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Class <span class="text-danger">*</span></label>
                        <select name="class_id" class="form-select" required>
                            <option value="" disabled>-- Select a Class --</option>
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold">Update Student</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="add_student" value="1">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Add New Student</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Student Full Name <span class="text-danger">*</span></label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-light"><i class="bi bi-person text-muted"></i></span>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Rahul Sharma" required autofocus maxlength="150">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                    <select name="class_id" id="addStudentClassSelect" class="form-select form-select-lg" required onchange="updateIdPreview(this)">
                        <option value="" disabled selected>-- Select a Class (Required) --</option>
                        <?php foreach ($classes as $c): 
                            $class_domain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c['name'])) . '.com';
                        ?>
                        <option value="<?php echo $c['id']; ?>" data-domain="<?php echo htmlspecialchars($class_domain); ?>">
                            <?php echo htmlspecialchars($c['name']); ?> — <?php echo htmlspecialchars($c['description'] ?? ''); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="p-3 bg-light rounded-3 border">
                    <div class="d-flex align-items-center mb-1 text-dark fw-bold small">
                        <i class="bi bi-person-badge text-primary me-2 fs-6"></i>
                        Auto-Generated Login ID:
                    </div>
                    <div class="font-monospace text-primary fw-bold fs-6 ps-4">
                        <span>10001</span><span id="idPreviewSuffix" class="text-muted">@[class].com</span>
                    </div>
                    <small class="text-muted d-block ps-4 mt-1">
                        <i class="bi bi-info-circle me-1"></i> A unique ID formatted as <code>uniquid@[class].com</code> (e.g. <code>10001@tyit.com</code>) is created automatically with default password <code>student</code>.
                    </small>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-plus-circle me-1"></i> Add Student</button>
            </div>
        </form>
    </div></div>
</div>

<script>
let currentClassFilter = <?php echo (int)$filter_class; ?>;
let currentSearchTerm  = <?php echo json_encode($search); ?>.toLowerCase().trim();

function updateIdPreview(sel) {
    const opt = sel.options[sel.selectedIndex];
    const domain = opt ? opt.dataset.domain : '';
    const suffixEl = document.getElementById('idPreviewSuffix');
    if (suffixEl) {
        if (domain) {
            suffixEl.textContent = '@' + domain;
            suffixEl.className = 'text-primary fw-bold';
        } else {
            suffixEl.textContent = '@[class].com';
            suffixEl.className = 'text-muted';
        }
    }
}

function applyFilters() {
    const term = currentSearchTerm;
    const classId = currentClassFilter;
    let visible = 0;
    const rows = document.querySelectorAll('.student-row');

    rows.forEach(row => {
        const cid = parseInt(row.dataset.classId || '0');
        const name = (row.dataset.name || '').toLowerCase();
        const email = (row.dataset.email || '').toLowerCase();

        const matchesClass = (classId === 0) || (cid === classId);
        const matchesSearch = !term || name.includes(term) || email.includes(term);

        if (matchesClass && matchesSearch) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    // Update count badge
    const badge = document.getElementById('studentCountBadge');
    if (badge) {
        badge.textContent = visible + ' Shown';
    }

    // Toggle no matches row
    const noMatches = document.getElementById('noMatchesRow');
    if (noMatches) {
        noMatches.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
    }

    // Toggle clear search button
    const clearBtn = document.getElementById('clearSearchBtn');
    if (clearBtn) {
        clearBtn.style.display = term.length > 0 ? '' : 'none';
    }

    if (typeof updateStudentsScrollWidth === 'function') {
        updateStudentsScrollWidth();
    }
}

function filterClass(classId) {
    currentClassFilter = parseInt(classId);

    // Sync dropdown
    const select = document.getElementById('classFilterSelect');
    if (select) select.value = classId;

    // Sync button pills
    document.querySelectorAll('#classFilterGroup .btn').forEach(btn => {
        const cid = parseInt(btn.dataset.classId);
        if (cid === classId) {
            btn.classList.remove('btn-outline-primary', 'btn-outline-secondary');
            btn.classList.add(cid === -1 ? 'btn-secondary' : 'btn-primary', 'active');
        } else {
            btn.classList.remove('btn-primary', 'btn-secondary', 'active');
            btn.classList.add(cid === -1 ? 'btn-outline-secondary' : 'btn-outline-primary');
        }
    });

    applyFilters();
}

function onClassSelectChange(classId) {
    filterClass(parseInt(classId));
}

function onSearchInput(val) {
    currentSearchTerm = val.toLowerCase().trim();
    applyFilters();
}

function clearSearch() {
    const input = document.getElementById('studentSearchInput');
    if (input) {
        input.value = '';
        input.focus();
    }
    currentSearchTerm = '';
    applyFilters();
}

function resetAllFilters() {
    clearSearch();
    filterClass(0);
}

// Synchronize Top Horizontal Scrollbar with Students Table
let updateStudentsScrollWidth = function() {};
document.addEventListener('DOMContentLoaded', () => {
    const topScroll = document.getElementById('studentsTableScrollTop');
    const tableCont = document.getElementById('studentsTableResponsive');
    if (topScroll && tableCont) {
        const topInner = document.getElementById('studentsTableScrollTopInner');
        const table = tableCont.querySelector('table');

        updateStudentsScrollWidth = function() {
            if (!table) return;
            const scrollW = table.scrollWidth;
            const clientW = tableCont.clientWidth;
            if (scrollW > clientW + 5) {
                topScroll.classList.remove('d-none');
                if (topInner) topInner.style.width = scrollW + 'px';
            } else {
                topScroll.classList.add('d-none');
            }
        };

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

        window.addEventListener('resize', updateStudentsScrollWidth);
        updateStudentsScrollWidth();
        setTimeout(updateStudentsScrollWidth, 300);
    }

    // Initialize filters based on initial state
    if (currentClassFilter !== 0 || currentSearchTerm !== '') {
        filterClass(currentClassFilter);
    }

    <?php if (($_GET['action'] ?? '') === 'new'): ?>
    new bootstrap.Modal(document.getElementById('addStudentModal')).show();
    <?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>
