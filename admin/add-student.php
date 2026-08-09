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
    
    // Auto-generate Student ID and Password
    $generated_roll = rand(10000, 99999);
    $email = "roll" . $generated_roll . "@institute.com";
    $password = "student123"; // Default password for all students

    if (empty($name)) {
        $error = "Full Name is required.";
    } else {
        // Check if Student ID (email) already exists (rare with rand, but good to check)
        $check_query = "SELECT id FROM students WHERE email = '$email' LIMIT 1";
        $check_result = mysqli_query($conn, $check_query);

        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $error = "A generation error occurred (duplicate ID). Please try again.";
        } else {
            // Insert student into database
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
            <i class="bi bi-arrow-left"></i> Back to Dashboard
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

<div class="card shadow-sm">
    <div class="card-body p-4">
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label fw-semibold">Full Name</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Rahul Sharma" required>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="bi bi-info-circle-fill me-2"></i> <strong>Note:</strong> Student ID and Password will be automatically generated. The default password is <code>student123</code>.
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-circle me-1"></i> Add Student
                </button>
                <a href="manage-students.php" class="btn btn-outline-secondary">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
