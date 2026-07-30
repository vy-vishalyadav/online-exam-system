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
    $delete_query = "DELETE FROM students WHERE id = $delete_id";
    if (mysqli_query($conn, $delete_query)) {
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

    if (empty($name) || empty($email) || empty($password)) {
        $error = "All fields are required.";
    } else {
        // Check if Student ID (email) already exists
        $check_query = "SELECT id FROM students WHERE email = '$email' LIMIT 1";
        $check_result = mysqli_query($conn, $check_query);

        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $error = "Student ID '$email' already exists! Please use a unique Student ID.";
        } else {
            // Insert student into database
            $insert_query = "INSERT INTO students (name, email, password) VALUES ('$name', '$email', '$password')";
            if (mysqli_query($conn, $insert_query)) {
                $success = "Student '$name' ($email) added successfully!";
            } else {
                $error = "Error adding student: " . mysqli_error($conn);
            }
        }
    }
}

// Fetch all students
$students_query = "SELECT * FROM students ORDER BY id DESC";
$students_result = mysqli_query($conn, $students_query);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-people-fill text-primary"></i> Manage Students</h4>
        <small class="text-muted">Add, view, and manage all student accounts.</small>
    </div>
    <div>
        <a href="dashboard.php" class="btn btn-outline-secondary me-2">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
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

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-list-task me-1"></i> Student List</h6>
        <span class="badge bg-primary rounded-pill"><?php echo ($students_result ? mysqli_num_rows($students_result) : 0); ?> Total Students</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th>Student Name</th>
                        <th>Student ID (Email)</th>
                        <th>Password</th>
                        <th class="text-center pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($students_result && mysqli_num_rows($students_result) > 0):
                        $i = 1;
                        while ($student = mysqli_fetch_assoc($students_result)):
                    ?>
                        <tr>
                            <td class="ps-3 fw-bold"><?php echo $i++; ?></td>
                            <td>
                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($student['name']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace">
                                    <i class="bi bi-card-text me-1"></i><?php echo htmlspecialchars($student['email']); ?>
                                </span>
                            </td>
                            <td><code><?php echo htmlspecialchars($student['password']); ?></code></td>
                            <td class="text-center pe-3">
                                <a href="manage-students.php?action=delete&id=<?php echo $student['id']; ?>" 
                                   class="btn btn-sm btn-outline-danger" 
                                   onclick="return confirm('Are you sure you want to delete student &quot;<?php echo htmlspecialchars($student['name']); ?>&quot;?');">
                                    <i class="bi bi-trash-fill"></i> Delete
                                </a>
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
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="manage-students.php">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="addStudentModalLabel">
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
                        <label class="form-label fw-semibold">Student ID</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" class="form-control" placeholder="rollno@institute.com" required>
                        </div>
                        <div class="form-text">Example format: <code>rollno@institute.com</code></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="Enter student password" required>
                        </div>
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

<?php include '../includes/footer.php'; ?>
