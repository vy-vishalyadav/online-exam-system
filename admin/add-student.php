<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim(mysqli_real_escape_string($conn, $_POST['name'] ?? ''));
    $email = trim(mysqli_real_escape_string($conn, $_POST['email'] ?? ''));
    $password = trim(mysqli_real_escape_string($conn, $_POST['password'] ?? ''));

    if (empty($email)) {
        $generated_roll = rand(10000, 99999);
        $email = $generated_roll . "@rclasses.com";
    }

    if (empty($password)) {
        $password = "student";
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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-person-plus-fill text-primary"></i> Add New Student</h4>
        <small class="text-muted">Enter student details below to create a new account.</small>
    </div>
    <div>
        <a href="manage-students.php" class="btn btn-outline-primary me-2">
            <i class="bi bi-people-fill me-1"></i> Manage Students
        </a>
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
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
    <div class="card-body p-4">
        <form method="POST" action="">
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
                    <input type="text" name="email" class="form-control" placeholder="Leave empty for auto-generated ID (e.g. 96579@rclasses.com)">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Password (Optional)</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-key"></i></span>
                    <input type="text" name="password" class="form-control" placeholder="Default: student">
                </div>
            </div>

            <div class="alert alert-info border-0 bg-info-subtle">
                <i class="bi bi-info-circle-fill me-2 text-info"></i> <strong>Note:</strong> If Student ID or Password is left empty, system auto-generates in format <code>numericid@rclasses.com</code>. Default password is <code>student</code>.
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                    <i class="bi bi-check-circle me-1"></i> Save Student
                </button>
                <a href="manage-students.php" class="btn btn-outline-secondary">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
