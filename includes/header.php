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

// 2. Anti-Cache Headers (Prevent browser from caching sensitive exams/dashboards in history)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// 3. Security Headers
header("X-Frame-Options: SAMEORIGIN");                         // Prevent clickjacking
header("X-Content-Type-Options: nosniff");                     // Prevent MIME sniffing
header("X-XSS-Protection: 1; mode=block");                    // Legacy XSS filter (older browsers)
header("Referrer-Policy: strict-origin-when-cross-origin");   // Don't leak URLs to 3rd parties
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; "
     . "style-src 'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com; "
     . "font-src 'self' fonts.gstatic.com cdn.jsdelivr.net; "
     . "img-src 'self' data:; "
     . "connect-src 'self'");

$current_page = basename($_SERVER['PHP_SELF']);
$is_admin_area = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false);
$is_student_area = (strpos($_SERVER['PHP_SELF'], '/student/') !== false);
$login_redirect = ($is_admin_area || $is_student_area) ? '../index.php' : 'index.php';

// 3. Inactivity Timeout (30 minutes of inactivity auto-logout)
$timeout_seconds = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_seconds)) {
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
    header("Location: " . $login_redirect . "?msg=timeout");
    exit;
}
$_SESSION['last_activity'] = time();

// 4. Role-Based Access Control (Access protection before rendering any HTML)
if ($is_admin_area && !isset($_SESSION['admin_id'])) {
    header("Location: ../index.php?msg=unauthorized");
    exit;
}

if ($is_student_area && !isset($_SESSION['student_id'])) {
    header("Location: ../index.php?msg=unauthorized");
    exit;
}

$is_admin = isset($_SESSION['admin_id']);
$is_student = isset($_SESSION['student_id']);

$css_path = ($is_student_area || $is_admin_area) ? '../css/style.css' : 'css/style.css';
$css_ver = file_exists(dirname(__DIR__) . '/css/style.css') ? filemtime(dirname(__DIR__) . '/css/style.css') : time();
$logout_url = ($is_student_area || $is_admin_area) ? '../logout.php' : 'logout.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Exam System</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS & JS Bundle -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS with Cache Busting -->
    <link rel="stylesheet" href="<?php echo $css_path; ?>?v=<?php echo $css_ver; ?>">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 text-white" href="<?php echo $is_admin ? 'dashboard.php' : ($is_student ? 'dashboard.php' : '#'); ?>">
            <i class="bi bi-mortarboard-fill text-indigo fs-4" style="color: #818cf8;"></i>
            <span class="fw-bold">Online Exam System</span>
        </a>
        
        <?php if ($is_admin || $is_student): ?>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto ms-lg-4 mb-2 mb-lg-0 gap-1">
                    <?php if ($is_admin): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">
                                <i class="bi bi-speedometer2 me-1"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo in_array($current_page, ['manage-exam.php','manage-questions.php','add-question.php']) ? 'active' : ''; ?>" href="manage-exam.php">
                                <i class="bi bi-journal-text me-1"></i> Exams
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo in_array($current_page, ['manage-students.php','add-student.php']) ? 'active' : ''; ?>" href="manage-students.php">
                                <i class="bi bi-people me-1"></i> Students
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'manage-classes.php') ? 'active' : ''; ?>" href="manage-classes.php">
                                <i class="bi bi-diagram-3 me-1"></i> Classes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'view-results.php') ? 'active' : ''; ?>" href="view-results.php">
                                <i class="bi bi-bar-chart me-1"></i> Results
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'violations.php') ? 'active' : ''; ?>" href="violations.php">
                                <i class="bi bi-shield-exclamation me-1"></i> Violations
                            </a>
                        </li>
                    <?php elseif ($is_student): ?>

                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">
                                <i class="bi bi-journal-check me-1"></i> My Exams
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'result.php') ? 'active' : ''; ?>" href="result.php">
                                <i class="bi bi-trophy me-1"></i> My Results
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <?php if ($is_admin): ?>
                        <div class="user-badge d-flex align-items-center gap-1">
                            <i class="bi bi-shield-lock-fill text-warning me-1"></i> Admin: <strong><?php echo htmlspecialchars($_SESSION['admin_username']); ?></strong>
                        </div>
                        <a href="<?php echo $logout_url; ?>" class="btn btn-logout btn-sm rounded-pill px-3 fw-bold">
                            <i class="bi bi-box-arrow-right me-1"></i> Logout
                        </a>
                    <?php elseif ($is_student): ?>
                        <!-- Student profile dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border rounded-pill px-3 d-flex align-items-center gap-2 fw-semibold" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle text-info"></i>
                                <?php echo htmlspecialchars($_SESSION['student_name']); ?>
                                <i class="bi bi-chevron-down small"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3 mt-1">
                                <li>
                                    <a class="dropdown-item <?php echo ($current_page === 'change-password.php') ? 'active' : ''; ?>" href="change-password.php">
                                        <i class="bi bi-key me-2 text-primary"></i> Change Password
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="<?php echo $logout_url; ?>">
                                        <i class="bi bi-box-arrow-right me-2"></i> Logout
                                    </a>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; ?>
    </div>
</nav>

<div class="container main-content my-4">
