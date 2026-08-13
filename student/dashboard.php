<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

$student_id = (int)$_SESSION['student_id'];

// Fetch all exams with question count & student's highest score / latest attempt
$query = "SELECT e.*, 
            (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS q_count,
            (SELECT score FROM results r WHERE r.student_id = $student_id AND r.exam_id = e.id ORDER BY r.attempted_at DESC LIMIT 1) AS last_score,
            (SELECT COUNT(*) FROM results r WHERE r.student_id = $student_id AND r.exam_id = e.id) AS attempt_count
          FROM exams e 
          ORDER BY e.id DESC";
$exams = mysqli_query($conn, $query);
?>

<div class="row align-items-center mb-4 g-3">
    <div class="col">
        <h3 class="fw-extrabold mb-1">Welcome, <?php echo htmlspecialchars($_SESSION['student_name']); ?>! 👋</h3>
        <p class="text-muted mb-0">Select an exam below to begin. Read each question carefully. Good luck!</p>
    </div>
    <div class="col-auto">
        <a href="result.php" class="btn btn-outline-primary fw-bold shadow-sm rounded-pill px-3">
            <i class="bi bi-trophy me-1"></i> View My Results
        </a>
    </div>
</div>

<h5 class="fw-bold mb-3"><i class="bi bi-journal-text text-primary me-1"></i> Available Exams</h5>

<div class="row g-4 mb-5">
    <?php if ($exams && mysqli_num_rows($exams) > 0): ?>
        <?php while ($exam = mysqli_fetch_assoc($exams)): 
            $q_count = (int)$exam['q_count'];
            $attempt_count = (int)$exam['attempt_count'];
            $last_score = $exam['last_score'];
            $has_attempted = $attempt_count > 0;
            $passed = $has_attempted && $last_score >= 50;
        ?>
            <div class="col-md-6 col-lg-4">
                <div class="hover-card h-100 p-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold">
                                <i class="bi bi-clock me-1"></i><?php echo (int)$exam['duration_minutes']; ?> mins
                            </span>
                            <span class="badge bg-light text-dark border rounded-pill px-3 py-1">
                                <i class="bi bi-patch-question me-1"></i><?php echo $q_count; ?> Questions
                            </span>
                        </div>

                        <h5 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($exam['title']); ?></h5>
                        
                        <?php if ($has_attempted): ?>
                            <div class="bg-light p-3 rounded-3 mb-3 border">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted fw-semibold">Last Score:</small>
                                    <span class="fw-bold <?php echo $passed ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $last_score; ?>%
                                    </span>
                                </div>
                                <div class="mt-1">
                                    <?php if ($passed): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0.5 small">
                                            <i class="bi bi-check-circle me-1"></i> Passed
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-0.5 small">
                                            <i class="bi bi-x-circle me-1"></i> Failed
                                        </span>
                                    <?php endif; ?>
                                    <span class="text-muted small ms-1">(<?php echo $attempt_count; ?> attempt<?php echo $attempt_count > 1 ? 's' : ''; ?>)</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mb-3">
                                Not attempted yet. Minimum passing score is 50%.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if ($q_count > 0): ?>
                            <a href="exam.php?id=<?php echo $exam['id']; ?>" class="btn btn-primary w-100 fw-bold shadow-sm py-2">
                                <i class="bi bi-play-fill me-1"></i> <?php echo $has_attempted ? 'Re-take Exam' : 'Start Exam'; ?>
                            </a>
                        <?php else: ?>
                            <button class="btn btn-secondary w-100 fw-bold py-2" disabled>
                                <i class="bi bi-exclamation-circle me-1"></i> No Questions Available
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 rounded-4 p-5 text-center">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <h5 class="fw-bold text-dark">No Exams Available</h5>
                <p class="text-muted mb-0">Check back later or contact your administrator.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
