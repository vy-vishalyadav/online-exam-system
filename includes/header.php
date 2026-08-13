<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_page = basename($_SERVER['PHP_SELF']);
$is_admin = isset($_SESSION['admin_id']);
$is_student = isset($_SESSION['student_id']);

$css_path = (strpos($_SERVER['PHP_SELF'], '/student/') !== false || strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../css/style.css' : 'css/style.css';
$css_ver = file_exists(dirname(__DIR__) . '/css/style.css') ? filemtime(dirname(__DIR__) . '/css/style.css') : time();
$logout_url = (strpos($_SERVER['PHP_SELF'], '/student/') !== false || strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../logout.php' : 'logout.php';
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
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS with Cache Busting -->
    <link rel="stylesheet" href="<?php echo $css_path; ?>?v=<?php echo $css_ver; ?>">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 text-white" href="<?php echo $is_admin ? '../admin/dashboard.php' : ($is_student ? '../student/dashboard.php' : '#'); ?>">
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
                            <a class="nav-link <?php echo ($current_page === 'dashboard.php') ? 'active' : ''; ?>" href="../admin/dashboard.php">
                                <i class="bi bi-speedometer2 me-1"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'manage-exam.php') ? 'active' : ''; ?>" href="../admin/manage-exam.php">
                                <i class="bi bi-journal-text me-1"></i> Exams
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'manage-questions.php' || $current_page === 'add-question.php') ? 'active' : ''; ?>" href="../admin/manage-questions.php">
                                <i class="bi bi-patch-question me-1"></i> Questions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'manage-students.php' || $current_page === 'add-student.php') ? 'active' : ''; ?>" href="../admin/manage-students.php">
                                <i class="bi bi-people me-1"></i> Students
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'view-results.php') ? 'active' : ''; ?>" href="../admin/view-results.php">
                                <i class="bi bi-bar-chart me-1"></i> Results
                            </a>
                        </li>
                    <?php elseif ($is_student): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'dashboard.php') ? 'active' : ''; ?>" href="../student/dashboard.php">
                                <i class="bi bi-journal-check me-1"></i> Available Exams
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($current_page === 'result.php') ? 'active' : ''; ?>" href="../student/result.php">
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
                        <div class="user-badge d-flex align-items-center gap-1">
                            <i class="bi bi-person-circle text-info me-1"></i> <strong><?php echo htmlspecialchars($_SESSION['student_name']); ?></strong>
                        </div>
                        <a href="<?php echo $logout_url; ?>" class="btn btn-logout btn-sm rounded-pill px-3 fw-bold">
                            <i class="bi bi-box-arrow-right me-1"></i> Logout
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</nav>

<div class="container main-content my-4">
