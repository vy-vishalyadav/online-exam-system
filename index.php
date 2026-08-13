<?php
session_start();
include 'config/db.php';

// FIRST: Handle logout action if requested via GET action=logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_unset();
    session_destroy();
    header("Location: index.php?msg=logged_out");
    exit;
}

// SECOND: Redirect if user is already logged in
if (isset($_SESSION['admin_id'])) {
    header("Location: admin/dashboard.php");
    exit;
} elseif (isset($_SESSION['student_id'])) {
    header("Location: student/dashboard.php");
    exit;
}

$error = "";
$success_msg = "";

if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success_msg = "You have been logged out successfully.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';

    if ($role === 'student') {
        $email = trim(mysqli_real_escape_string($conn, $_POST['email'] ?? ''));
        $password = trim(mysqli_real_escape_string($conn, $_POST['password'] ?? ''));

        if (empty($email) || empty($password)) {
            $error = "Please enter both Student ID and Password.";
        } else {
            $query = "SELECT * FROM students WHERE email='$email' AND password='$password' LIMIT 1";
            $result = mysqli_query($conn, $query);

            if ($result && mysqli_num_rows($result) === 1) {
                $row = mysqli_fetch_assoc($result);
                $_SESSION['student_id'] = $row['id'];
                $_SESSION['student_name'] = $row['name'];
                $_SESSION['student_email'] = $row['email'];
                header("Location: student/dashboard.php");
                exit;
            } else {
                $error = "Invalid Student ID or password.";
            }
        }
    } elseif ($role === 'admin') {
        $username = trim(mysqli_real_escape_string($conn, $_POST['username'] ?? ''));
        $password = trim(mysqli_real_escape_string($conn, $_POST['password'] ?? ''));

        if (empty($username) || empty($password)) {
            $error = "Please enter both Username and Password.";
        } else {
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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Online Exam System</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark navbar-custom">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="#">
            <i class="bi bi-mortarboard-fill text-primary fs-4"></i>
            <span>Online Exam System</span>
        </a>
    </div>
</nav>

<div class="login-wrapper container py-5">
    <div class="card login-card col-md-8 col-lg-5 mx-auto">
        <div class="login-header text-center">
            <div class="mb-2">
                <i class="bi bi-shield-lock-fill fs-1 text-white opacity-75"></i>
            </div>
            <h4 class="fw-bold mb-1">Portal Login</h4>
            <p class="text-white opacity-75 small mb-0">Sign in to access your dashboard</p>
        </div>

        <div class="card-body p-4">
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <div><?php echo htmlspecialchars($success_msg); ?></div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <ul class="nav nav-tabs nav-justified mb-4" id="loginTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active d-flex align-items-center justify-content-center gap-2" id="student-tab" data-bs-toggle="tab" data-bs-target="#student-pane" type="button" role="tab">
                        <i class="bi bi-person-badge fs-5"></i> Student Login
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link d-flex align-items-center justify-content-center gap-2" id="admin-tab" data-bs-toggle="tab" data-bs-target="#admin-pane" type="button" role="tab">
                        <i class="bi bi-shield-check fs-5"></i> Admin Login
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Student Login Form -->
                <div class="tab-pane fade show active" id="student-pane" role="tabpanel">
                    <form method="POST" action="">
                        <input type="hidden" name="role" value="student">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-secondary">Student ID</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person-badge text-muted"></i></span>
                                <input type="text" name="email" class="form-control" placeholder="e.g. 96579@rclasses.com" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold text-secondary">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-key text-muted"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2.5 shadow-sm fw-bold">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In as Student
                        </button>
                    </form>
                </div>

                <!-- Admin Login Form -->
                <div class="tab-pane fade" id="admin-pane" role="tabpanel">
                    <form method="POST" action="">
                        <input type="hidden" name="role" value="admin">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-secondary">Username</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person text-muted"></i></span>
                                <input type="text" name="username" class="form-control" placeholder="Admin username" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold text-secondary">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock text-muted"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="Enter admin password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-dark w-100 py-2.5 shadow-sm fw-bold">
                            <i class="bi bi-shield-lock-fill me-1"></i> Sign In as Admin
                        </button>
                    </form>
                </div>
            </div>

            <!-- Demo Credentials Helper -->
            <div class="mt-4 pt-3 border-top">
                <div class="bg-light p-3 rounded-3 small">
                    <div class="fw-bold text-dark mb-1"><i class="bi bi-info-circle-fill me-1 text-primary"></i> Demo Credentials:</div>
                    <div class="row g-2">
                        <div class="col-6">
                            <span class="text-muted">Admin:</span><br>
                            <code>admin</code> / <code>admin123</code>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Student:</span><br>
                            <code>96579@rclasses.com</code> / <code>student123</code>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<footer>
    <div class="container text-center py-3">
        <small class="text-muted">&copy; <?php echo date('Y'); ?> Online Exam System. All rights reserved.</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
