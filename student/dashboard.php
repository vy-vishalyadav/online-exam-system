<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

$exams = mysqli_query($conn, "SELECT * FROM exams ORDER BY id DESC");
?>

<div class="row mb-4">
    <div class="col">
        <h3 class="fw-bold">Welcome, <?php echo htmlspecialchars($_SESSION['student_name']); ?>!</h3>
        <p class="text-muted">Choose an exam below to begin. Best of luck!</p>
    </div>
    <div class="col-auto">
        <a href="result.php" class="btn btn-outline-primary">
            <i class="bi bi-clipboard-data"></i> View My Results
        </a>
    </div>
</div>

<h5 class="mb-3"><i class="bi bi-journal-text"></i> Available Exams</h5>

<div class="row g-3">
    <?php if ($exams && mysqli_num_rows($exams) > 0): ?>
        <?php while ($exam = mysqli_fetch_assoc($exams)): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card exam-card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo htmlspecialchars($exam['title']); ?></h5>
                        <p class="card-text text-muted mb-2">
                            <i class="bi bi-clock"></i> Duration: <?php echo (int)$exam['duration_minutes']; ?> minutes
                        </p>
                        <a href="exam.php?id=<?php echo $exam['id']; ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-play-fill"></i> Start Exam
                        </a>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No exams available right now. Please check back later.
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
