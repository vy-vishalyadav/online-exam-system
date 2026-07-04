<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Exam System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo (strpos($_SERVER['PHP_SELF'], '/student/') !== false || strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../css/style.css' : 'css/style.css'; ?>">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#">
            <i class="bi bi-mortarboard-fill"></i> Online Exam System
        </a>
        <div class="d-flex">
            <?php if (isset($_SESSION['student_name'])): ?>
                <span class="navbar-text text-white me-3">
                    <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['student_name']); ?>
                </span>
                <a href="../index.php?action=logout" class="btn btn-light btn-sm">Logout</a>
            <?php elseif (isset($_SESSION['admin_username'])): ?>
                <span class="navbar-text text-white me-3">
                    <i class="bi bi-shield-lock"></i> <?php echo htmlspecialchars($_SESSION['admin_username']); ?>
                </span>
                <a href="../index.php?action=logout" class="btn btn-light btn-sm">Logout</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<div class="container my-4">
