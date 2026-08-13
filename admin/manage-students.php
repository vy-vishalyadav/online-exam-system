<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$error = "";
$success = "";

// Handle Delete Student
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = (int)$_GET['id'];
    if (mysqli_query($conn, "DELETE FROM students WHERE id = $delete_id")) {
        $success = "Student deleted successfully!";
    } else {
        $error = "Failed to delete student: " . mysqli_error($conn);
    }
}

// Handle Add Student POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $name = trim(mysqli_real_escape_string($conn, $_POST['name'] ?? ''));
    $email = trim(mysqli_real_escape_string($conn, $_POST['email'] ?? ''));
    $password = trim(mysqli_real_escape_string($conn, $_POST['password'] ?? ''));

    // Auto generate Student ID if empty
    if (empty($email)) {
        $generated_roll = rand(10000, 99999);
        $email = (string)$generated_roll;
    }

    // Default password if empty
    if (empty($password)) {
        $password = "student123";
    }

    if (empty($name)) {
        $error = "Full Name is required.";
    } else {
        $check_query = "SELECT id FROM students WHERE email = '$email' LIMIT 1";
        $check_result = mysqli_query($conn, $check_query);

        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $error = "A student with Student ID '$email' already exists.";
        } else {
            $insert_query = "INSERT INTO students (name, email, password) VALUES ('$name', '$email', '$password')";
            if (mysqli_query($conn, $insert_query)) {
                $success = "Student '$name' added successfully! Student ID: $email, Password: $password";
            } else {
                $error = "Error adding student: " . mysqli_error($conn);
            }
        }
    }
}

// Handle Edit Student POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    $student_id = (int)$_POST['student_id'];
    $name = trim(mysqli_real_escape_string($conn, $_POST['name'] ?? ''));
    $email = trim(mysqli_real_escape_string($conn, $_POST['email'] ?? ''));
    $password = trim(mysqli_real_escape_string($conn, $_POST['password'] ?? ''));

    if (empty($name) || empty($email) || empty($password)) {
        $error = "All fields are required for editing.";
    } else {
        $check_query = "SELECT id FROM students WHERE email = '$email' AND id != $student_id LIMIT 1";
        $check_result = mysqli_query($conn, $check_query);

        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $error = "Student ID '$email' is already used by another student.";
        } else {
            $update_query = "UPDATE students SET name='$name', email='$email', password='$password' WHERE id=$student_id";
            if (mysqli_query($conn, $update_query)) {
                $success = "Student details updated successfully!";
            } else {
                $error = "Error updating student: " . mysqli_error($conn);
            }
        }
    }
}

// Fetch all students
$students_query = "SELECT s.*, (SELECT COUNT(*) FROM results r WHERE r.student_id = s.id) AS exam_count FROM students s ORDER BY s.id DESC";
$students_result = mysqli_query($conn, $students_query);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-people-fill text-primary"></i> Manage Students</h4>
        <small class="text-muted">Register, edit, delete, and manage student credentials.</small>
    </div>
    <div>
        <a href="dashboard.php" class="btn btn-outline-secondary me-2">
            <i class="bi bi-arrow-left"></i> Dashboard
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
    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success); ?>
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
                        <th>Password</th>
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
                            <td><code><?php echo htmlspecialchars($student['password']); ?></code></td>
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
                                    <a href="manage-students.php?action=delete&id=<?php echo $student['id']; ?>" 
                                       class="btn btn-outline-danger" 
                                       onclick="return confirm('Are you sure you want to delete student &quot;<?php echo htmlspecialchars($student['name']); ?>&quot;?');">
                                        <i class="bi bi-trash-fill"></i> Delete
                                    </a>
                                </div>

                                <!-- Edit Student Modal -->
                                <div class="modal fade text-start" id="editStudentModal<?php echo $student['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="manage-students.php">
                                                <div class="modal-header bg-light">
                                                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Student</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body p-4">
                                                    <input type="hidden" name="edit_student" value="1">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Full Name</label>
                                                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($student['name']); ?>" required>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Student ID</label>
                                                        <input type="text" name="email" class="form-control" value="<?php echo htmlspecialchars($student['email']); ?>" required>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Password</label>
                                                        <input type="text" name="password" class="form-control" value="<?php echo htmlspecialchars($student['password']); ?>" required>
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
                            <td colspan="6" class="text-center text-muted py-4">
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
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-person-plus-fill me-2"></i>Add New Student
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="add_student" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Rahul Sharma" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Student ID (Optional)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                            <input type="text" name="email" class="form-control" placeholder="Leave empty for auto-generated ID (e.g. 96579)">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password (Optional)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="text" name="password" class="form-control" placeholder="Default: student123">
                        </div>
                    </div>

                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-info-circle-fill me-1"></i> If Student ID or password is left blank, a numeric ID will be auto-generated with default password <code>student123</code>.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i> Save Student
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
