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

// ── Handle Delete Student (POST only, with CSRF) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $delete_id = (int)($_POST['id'] ?? 0);
        if ($delete_id > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM students WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $delete_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                $_SESSION['flash_success'] = "Student deleted successfully!";
            } else {
                $_SESSION['flash_error'] = "Failed to delete student: " . mysqli_error($conn);
                mysqli_stmt_close($stmt);
            }
        }
        header("Location: manage-students.php");
        exit;
    }
}

// ── Handle Add Student POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            $error = "Student Name is required.";
        } elseif (strlen($name) > 150) {
            $error = "Student name is too long (max 150 characters).";
        } else {
            // Auto-generate next student ID using MAX() — single efficient DB query
            $res_max = mysqli_query($conn,
                "SELECT MAX(CAST(SUBSTRING_INDEX(email, '@', 1) AS UNSIGNED)) AS max_num
                 FROM students WHERE email REGEXP '^[0-9]+@rclasses\\.com$'"
            );
            $max_row    = ($res_max ? mysqli_fetch_assoc($res_max) : null);
            $next_num   = max(1001, (int)($max_row['max_num'] ?? 1000) + 1);
            $generated_id = $next_num . "@rclasses.com";

            $password = "student";

            $stmt = mysqli_prepare($conn, "INSERT INTO students (name, email, password) VALUES (?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "sss", $name, $generated_id, $password);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    $_SESSION['flash_success'] = "Student <strong>" . htmlspecialchars($name, ENT_QUOTES) . "</strong> registered! Login ID: <strong class='text-primary'>" . htmlspecialchars($generated_id, ENT_QUOTES) . "</strong> &nbsp;|&nbsp; Password: <strong>student</strong>";
                    header("Location: manage-students.php");
                    exit;
                } else {
                    // Handle duplicate email race condition gracefully
                    if (mysqli_errno($conn) === 1062) {
                        $error = "A student ID conflict occurred. Please try again.";
                    } else {
                        $error = "Error adding student: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                }
            } else {
                $error = "Database query error. Please try again.";
            }
        }
    }
}

// ── Handle Edit Student POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $email      = trim($_POST['email'] ?? '');

        if (empty($name) || empty($email)) {
            $error = "Name and Student ID are required.";
        } elseif (strlen($name) > 150) {
            $error = "Name is too long (max 150 characters).";
        } elseif (strlen($email) > 100) {
            $error = "Student ID is too long.";
        } else {
            // Check duplicate email (excluding this student)
            $stmt_chk = mysqli_prepare($conn, "SELECT id FROM students WHERE email = ? AND id != ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_chk, "si", $email, $student_id);
            mysqli_stmt_execute($stmt_chk);
            mysqli_stmt_store_result($stmt_chk);
            $dup = mysqli_stmt_num_rows($stmt_chk) > 0;
            mysqli_stmt_close($stmt_chk);

            if ($dup) {
                $error = "Student ID '" . htmlspecialchars($email) . "' is already used by another student.";
            } else {
                // Password always stays "student" — do not allow changing via edit
                $stmt = mysqli_prepare($conn, "UPDATE students SET name=?, email=? WHERE id=?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "ssi", $name, $email, $student_id);
                    if (mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);
                        $_SESSION['flash_success'] = "Student details updated successfully!";
                        header("Location: manage-students.php");
                        exit;
                    } else {
                        $error = "Error updating student: " . mysqli_error($conn);
                        mysqli_stmt_close($stmt);
                    }
                } else {
                    $error = "Database query error. Please try again.";
                }
            }
        }
    }
}

// Flash messages from redirects
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ── Fetch all students ────────────────────────────────────────────────────────
$students_query  = "SELECT s.*, (SELECT COUNT(*) FROM results r WHERE r.student_id = s.id) AS exam_count FROM students s ORDER BY s.id DESC";
$students_result = mysqli_query($conn, $students_query);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-people-fill text-primary"></i> Manage Students</h4>
        <small class="text-muted">Register, edit, delete, and manage student credentials.</small>
    </div>
    <div class="d-flex gap-2">
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
            <i class="bi bi-person-plus-fill me-1"></i> Add Student
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
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5"></i> <?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-list-task me-1 text-primary"></i> Registered Students</h6>
        <span class="badge bg-primary rounded-pill px-3 py-1"><?php echo ($students_result ? mysqli_num_rows($students_result) : 0); ?> Total Students</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Student Name</th>
                        <th>Student ID</th>
                        <th>Exams Attempted</th>
                        <th class="text-center pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($students_result && mysqli_num_rows($students_result) > 0):
                        $i = 1;
                        while ($student = mysqli_fetch_assoc($students_result)):
                    ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($student['name']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace fs-6">
                                    <i class="bi bi-person-badge me-1 text-primary"></i><?php echo htmlspecialchars($student['email']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">
                                    <?php echo $student['exam_count']; ?> attempt(s)
                                </span>
                            </td>
                            <td class="text-center pe-4">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editStudentModal<?php echo $student['id']; ?>">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <!-- Delete via POST form (prevents CSRF via simple link) -->
                                    <form method="POST" action="manage-students.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete student &quot;<?php echo htmlspecialchars($student['name'], ENT_QUOTES); ?>&quot;?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete_student">
                                        <input type="hidden" name="id" value="<?php echo $student['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-trash-fill"></i> Delete
                                        </button>
                                    </form>
                                </div>

                                <!-- Edit Student Modal -->
                                <div class="modal fade text-start" id="editStudentModal<?php echo $student['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="manage-students.php">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <div class="modal-header bg-light">
                                                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Student</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body p-4">
                                                    <input type="hidden" name="edit_student" value="1">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Full Name</label>
                                                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($student['name']); ?>" required maxlength="150">
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Student ID</label>
                                                        <input type="text" name="email" class="form-control" value="<?php echo htmlspecialchars($student['email']); ?>" required maxlength="100">
                                                    </div>

                                                    <div class="alert alert-info border-0 bg-info-subtle mb-0 py-2 px-3 small">
                                                        <i class="bi bi-info-circle me-1"></i> Password is always <strong>student</strong> and cannot be changed here.
                                                    </div>
                                                </div>
                                                <div class="modal-footer bg-light">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Update Student</button>
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
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i> No students registered yet. Click "Add Student" to create one.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="manage-students.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-person-plus-fill me-2"></i>Add New Student
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="add_student" value="1">
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold text-dark">Student Full Name</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light"><i class="bi bi-person text-muted"></i></span>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Rahul Sharma" required autofocus maxlength="150">
                        </div>
                        <div class="form-text text-muted mt-2">
                            <i class="bi bi-info-circle text-primary me-1"></i> The system automatically creates a unique numeric ID (e.g. <code>1001@rclasses.com</code>) with password <code>student</code>.
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-plus-circle me-1"></i> Add Student
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php 
if (isset($_GET['action']) && $_GET['action'] === 'new'): 
?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var modal = new bootstrap.Modal(document.getElementById('addStudentModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
