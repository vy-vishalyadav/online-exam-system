<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$q_students = mysqli_query($conn, "SELECT COUNT(*) AS c FROM students");
$total_students = ($q_students ? mysqli_fetch_assoc($q_students)['c'] : 0);

$q_exams = mysqli_query($conn, "SELECT COUNT(*) AS c FROM exams");
$total_exams = ($q_exams ? mysqli_fetch_assoc($q_exams)['c'] : 0);

$q_results = mysqli_query($conn, "SELECT COUNT(*) AS c FROM results");
$total_results = ($q_results ? mysqli_fetch_assoc($q_results)['c'] : 0);
?>

<div class="mb-4">
    <h3 class="fw-bold">Admin Dashboard</h3>
    <p class="text-muted">Overview of the exam system at a glance.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a href="manage-students.php" class="text-decoration-none">
            <div class="card stat-card bg-gradient-blue">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1 text-white opacity-75">Total Students</h6>
                        <h2 class="fw-bold mb-0 text-white"><?php echo $total_students; ?></h2>
                    </div>
                    <i class="bi bi-people-fill stat-icon"></i>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="manage-exam.php" class="text-decoration-none">
            <div class="card stat-card bg-gradient-green">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1 text-white opacity-75">Total Exams</h6>
                        <h2 class="fw-bold mb-0 text-white"><?php echo $total_exams; ?></h2>
                    </div>
                    <i class="bi bi-journal-bookmark-fill stat-icon"></i>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="view-results.php" class="text-decoration-none">
            <div class="card stat-card bg-gradient-orange">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1 text-white opacity-75">Total Results</h6>
                        <h2 class="fw-bold mb-0 text-white"><?php echo $total_results; ?></h2>
                    </div>
                    <i class="bi bi-bar-chart-fill stat-icon"></i>
                </div>
            </div>
        </a>
    </div>
</div>

<h5 class="mb-3 fw-bold">Quick Actions</h5>
<div class="row g-3">
    <div class="col-md-3">
        <a href="add-student.php" class="text-decoration-none">
            <div class="card shadow-sm h-100 hover-lift">
                <div class="card-body text-center p-4">
                    <i class="bi bi-person-plus-fill text-primary" style="font-size: 2.2rem;"></i>
                    <h6 class="mt-2 mb-0 text-dark fw-semibold">Add Student</h6>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="manage-students.php" class="text-decoration-none">
            <div class="card shadow-sm h-100 hover-lift">
                <div class="card-body text-center p-4">
                    <i class="bi bi-people-fill text-info" style="font-size: 2.2rem;"></i>
                    <h6 class="mt-2 mb-0 text-dark fw-semibold">Manage Students</h6>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="add-question.php" class="text-decoration-none">
            <div class="card shadow-sm h-100 hover-lift">
                <div class="card-body text-center p-4">
                    <i class="bi bi-plus-circle-fill text-success" style="font-size: 2.2rem;"></i>
                    <h6 class="mt-2 mb-0 text-dark fw-semibold">Add Question</h6>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="manage-exam.php" class="text-decoration-none">
            <div class="card shadow-sm h-100 hover-lift">
                <div class="card-body text-center p-4">
                    <i class="bi bi-journal-text text-secondary" style="font-size: 2.2rem;"></i>
                    <h6 class="mt-2 mb-0 text-dark fw-semibold">Manage Exams</h6>
                </div>
            </div>
        </a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
