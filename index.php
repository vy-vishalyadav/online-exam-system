<?php
// Start output buffering to allow safe redirects anytime
if (!ob_get_level()) {
    ob_start();
}

// 1. Secure Session Cookie Configuration
if (session_status() === PHP_SESSION_NONE) {
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
              (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    session_set_cookie_params([
        'lifetime' => 0, // Session cookie expires on browser close
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 2. Anti-Cache Headers (Prevent browser from caching login/session state in history)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// 3. Security Headers
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; "
     . "style-src 'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com; "
     . "font-src 'self' fonts.gstatic.com cdn.jsdelivr.net; "
     . "img-src 'self' data:; "
     . "connect-src 'self'");

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

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'logged_out') {
        $success_msg = "You have been logged out successfully.";
    } elseif ($_GET['msg'] === 'timeout') {
        $error = "Your session expired due to inactivity (30 mins). Please log in again.";
    } elseif ($_GET['msg'] === 'unauthorized') {
        $error = "Access denied. Please log in with an authorized account.";
    }
}

// ---------- Brute-force / rate-limit protection ----------
// Tracks failed attempts per IP in session. 5 failures = 15-min lockout.
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS',    900); // 15 minutes

$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$client_ip = trim(explode(',', $client_ip)[0]); // Use first IP if behind proxy

$lockout_key   = 'login_attempts_' . md5($client_ip);
$lockout_ts_key = 'login_lockout_until_' . md5($client_ip);

$is_locked_out = false;
if (!empty($_SESSION[$lockout_ts_key]) && time() < $_SESSION[$lockout_ts_key]) {
    $is_locked_out = true;
    $remaining     = ceil(($_SESSION[$lockout_ts_key] - time()) / 60);
    $error = "Too many failed login attempts. Please wait {$remaining} minute(s) before trying again.";
} elseif (!empty($_SESSION[$lockout_ts_key]) && time() >= $_SESSION[$lockout_ts_key]) {
    // Lockout expired — reset counters
    unset($_SESSION[$lockout_key], $_SESSION[$lockout_ts_key]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_locked_out) {

    // Clear any URL-based status message — it's a new login attempt, not a redirect notification
    $success_msg = "";
    $error = "";
    $role = $_POST['role'] ?? '';

    if ($role === 'student') {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($email) || empty($password)) {
            $error = "Please enter both Student ID and Password.";
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id, name, email, password FROM students WHERE email = ? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $email);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);

                if ($result && $row = mysqli_fetch_assoc($result)) {
                    if (password_verify($password, $row['password']) || $password === $row['password']) {
                        // SUCCESS — reset lockout counter
                        unset($_SESSION[$lockout_key], $_SESSION[$lockout_ts_key]);
                        session_regenerate_id(true);
                        $_SESSION['student_id']    = (int)$row['id'];
                        $_SESSION['student_name']  = $row['name'];
                        $_SESSION['student_email'] = $row['email'];
                        $_SESSION['last_activity'] = time();
                        header("Location: student/dashboard.php");
                        exit;
                    } else {
                        $error = "Invalid Student ID or password.";
                    }
                } else {
                    $error = "Invalid Student ID or password.";
                }
                mysqli_stmt_close($stmt);
            } else {
                $error = "Database query error. Please try again.";
            }
        }
    } elseif ($role === 'admin') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $error = "Please enter both Username and Password.";
        } else {
            $stmt = mysqli_prepare($conn, "SELECT id, username, password FROM admin WHERE username = ? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $username);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);

                if ($result && $row = mysqli_fetch_assoc($result)) {
                    if (password_verify($password, $row['password']) || $password === $row['password']) {
                        // SUCCESS — reset lockout counter
                        unset($_SESSION[$lockout_key], $_SESSION[$lockout_ts_key]);
                        session_regenerate_id(true);
                        $_SESSION['admin_id']       = (int)$row['id'];
                        $_SESSION['admin_username'] = $row['username'];
                        $_SESSION['last_activity']  = time();
                        header("Location: admin/dashboard.php");
                        exit;
                    } else {
                        $error = "Invalid admin username or password.";
                    }
                } else {
                    $error = "Invalid admin username or password.";
                }
                mysqli_stmt_close($stmt);
            } else {
                $error = "Database query error. Please try again.";
            }
        }
    }

    // FAILED attempt — increment counter & trigger lockout when threshold reached
    if (!empty($error) && $error !== "Please enter both Student ID and Password."
                       && $error !== "Please enter both Username and Password.") {
        $_SESSION[$lockout_key] = ($_SESSION[$lockout_key] ?? 0) + 1;
        $attempts_left = MAX_LOGIN_ATTEMPTS - (int)$_SESSION[$lockout_key];

        if ((int)$_SESSION[$lockout_key] >= MAX_LOGIN_ATTEMPTS) {
            $_SESSION[$lockout_ts_key] = time() + LOCKOUT_SECONDS;
            unset($_SESSION[$lockout_key]);
            $error = "Too many failed login attempts. Your account is locked for 15 minutes.";
        } elseif ($attempts_left <= 2) {
            $error .= " ({$attempts_left} attempt(s) remaining before lockout)";
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
                                <input type="text" name="email" class="form-control" placeholder="e.g. 1001@rclasses.com" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold text-secondary">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-key text-muted"></i></span>
                                <input type="password" name="password" id="studentPassword" class="form-control" placeholder="Enter your password" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('studentPassword', this)" tabindex="-1">
                                    <i class="bi bi-eye"></i>
                                </button>
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
                                <input type="password" name="password" id="adminPassword" class="form-control" placeholder="Enter admin password" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('adminPassword', this)" tabindex="-1">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-dark w-100 py-2.5 shadow-sm fw-bold">
                            <i class="bi bi-shield-lock-fill me-1"></i> Sign In as Admin
                        </button>
                    </form>
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
<?php
// Re-open the correct tab if a login attempt was made
$active_role = $_POST['role'] ?? '';
if ($active_role === 'admin'):
?>
<script>
    // Restore admin tab after failed admin login
    document.addEventListener('DOMContentLoaded', function () {
        var adminTab = document.getElementById('admin-tab');
        if (adminTab) {
            var tab = new bootstrap.Tab(adminTab);
            tab.show();
        }
    });
</script>
<?php endif; ?>
<script>
function togglePass(fieldId, btn) {
    var field = document.getElementById(fieldId);
    var icon  = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        field.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>
</body>

</html>
