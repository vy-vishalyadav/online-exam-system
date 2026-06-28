<?php
session_start();
include 'config/db.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';

    if ($role === 'student') {
        $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $password = mysqli_real_escape_string($conn, $_POST['password'] ?? '');

        $query = "SELECT * FROM students WHERE email='$email' AND password='$password' LIMIT 1";
        $result = mysqli_query($conn, $query);

        if ($result && mysqli_num_rows($result) === 1) {
            $row = mysqli_fetch_assoc($result);
            $_SESSION['student_id'] = $row['id'];
            $_SESSION['student_name'] = $row['name'];
            header("Location: student/dashboard.php");
            exit;
        } else {
            $error = "Invalid student email or password.";
        }
    } elseif ($role === 'admin') {
        $username = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
        $password = mysqli_real_escape_string($conn, $_POST['password'] ?? '');

        $query = "SELECT * FROM admin WHERE username='$username' AND password='$password' LIMIT 1";
        $result = mysqli_query($conn, $query);

        if ($result && mysqli_num_rows($result) === 1) {
            $row = mysqli_fetch_assoc($result);
            $_SESSION['admin_id'] = $row['id'];
            $_SESSION['admin_username'] = $row['username'];
            header("Location: admin/dashboard.php");
            exit;
        } else {
            $error = "Invalid admin username or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Online Exam System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<nav class="navbar navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#">
            <i class="bi bi-mortarboard-fill"></i> Online Exam System
        </a>
    </div>
</nav>

<div class="login-wrapper container">
    <div class="card login-card mt-5">
        <div class="card-body p-4">
            <h4 class="text-center mb-4 fw-bold">Welcome Back</h4>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <ul class="nav nav-tabs nav-justified mb-3" id="loginTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="student-tab" data-bs-toggle="tab"
                            data-bs-target="#student-pane" type="button" role="tab">
                        <i class="bi bi-person"></i> Student Login
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="admin-tab" data-bs-toggle="tab"
                            data-bs-target="#admin-pane" type="button" role="tab">
                        <i class="bi bi-shield-lock"></i> Admin Login
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Student Login -->
                <div class="tab-pane fade show active" id="student-pane" role="tabpanel">
                    <form method="POST" action="">
                        <input type="hidden" name="role" value="student">
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="student@example.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-box-arrow-in-right"></i> Login as Student
                        </button>
                    </form>
                </div>

                <!-- Admin Login -->
                <div class="tab-pane fade" id="admin-pane" role="tabpanel">
                    <form method="POST" action="">
                        <input type="hidden" name="role" value="admin">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="admin" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                        </div>
                        <button type="submit" class="btn btn-dark w-100">
                            <i class="bi bi-box-arrow-in-right"></i> Login as Admin
                        </button>
                    </form>
                </div>
            </div>

            <p class="text-center mt-3 text-muted small">
                Default admin: <strong>admin / admin123</strong>
            </p>
        </div>
    </div>
</div>

<footer class="text-center py-3 mt-5 bg-light border-top">
    <small class="text-muted">&copy; <?php echo date('Y'); ?> Online Exam System. All rights reserved.</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
