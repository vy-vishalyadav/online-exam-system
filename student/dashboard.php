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
            (SELECT status FROM results r WHERE r.student_id = $student_id AND r.exam_id = e.id ORDER BY r.attempted_at DESC LIMIT 1) AS last_status,
            (SELECT COUNT(*) FROM results r WHERE r.student_id = $student_id AND r.exam_id = e.id) AS attempt_count
          FROM exams e 
          ORDER BY e.id DESC";
$exams = mysqli_query($conn, $query);
?>

<div class="mb-4">
    <h3 class="fw-extrabold mb-1">Welcome, <?php echo htmlspecialchars($_SESSION['student_name']); ?>! 👋</h3>
    <p class="text-muted mb-0">Select an exam below to begin. Read each question carefully. Good luck!</p>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="fw-bold mb-0"><i class="bi bi-journal-text text-primary me-1"></i> Available Exams</h5>
    <!-- Filter Tabs -->
    <div class="btn-group btn-group-sm" role="group" id="examFilterGroup">
        <button type="button" class="btn btn-primary active" onclick="filterExams('all', this)">All</button>
        <button type="button" class="btn btn-outline-primary" onclick="filterExams('todo', this)">To Do</button>
        <button type="button" class="btn btn-outline-primary" onclick="filterExams('completed', this)">Completed</button>
    </div>
</div>

<div class="row g-4 mb-5" id="examCardContainer">
    <?php if ($exams && mysqli_num_rows($exams) > 0): ?>
        <?php while ($exam = mysqli_fetch_assoc($exams)): 
            $q_count = (int)$exam['q_count'];
            $attempt_count = (int)$exam['attempt_count'];
            $last_score = $exam['last_score'];
            $last_status = $exam['last_status'] ?? 'published';
            $has_attempted = $attempt_count > 0;
            $is_pending = ($has_attempted && $last_status === 'pending');
            $passed = ($has_attempted && !$is_pending && $last_score >= 50);
            $filter_status = (!$has_attempted || (!$passed && !$is_pending)) ? 'todo' : 'completed';
        ?>
            <div class="col-md-6 col-lg-4 exam-card-wrapper" data-status="<?php echo $filter_status; ?>">
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
                                <?php if ($is_pending): ?>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted fw-semibold">Status:</small>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1 fw-bold">
                                            <i class="bi bi-hourglass-split me-1"></i> Result Under Review
                                        </span>
                                    </div>
                                    <div class="mt-2 text-muted small">
                                        Your submission is being evaluated by your instructor.
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted fw-semibold">Last Score:</small>
                                        <span class="fw-bold <?php echo $passed ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo $last_score; ?>%
                                        </span>
                                    </div>
                                    <!-- Score progress bar -->
                                    <div class="progress mt-2 rounded-pill" style="height:6px;">
                                        <div class="progress-bar <?php echo $passed ? 'bg-success' : 'bg-danger'; ?>" style="width:<?php echo min(100, (int)$last_score); ?>%"></div>
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
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mb-3">
                                Not attempted yet. Minimum passing score is 50%.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if ($q_count > 0): ?>
                            <?php if ($passed): ?>
                                <!-- Passed: muted retake button -->
                                <a href="exam.php?id=<?php echo $exam['id']; ?>" class="btn btn-outline-secondary w-100 fw-bold py-2">
                                    <i class="bi bi-arrow-repeat me-1"></i> Re-take Exam
                                </a>
                            <?php else: ?>
                                <!-- Not passed / not attempted: prominent button + confirmation -->
                                <button type="button"
                                        class="btn btn-primary w-100 fw-bold shadow-sm py-2"
                                        onclick="confirmStartExam(<?php echo $exam['id']; ?>, '<?php echo htmlspecialchars(addslashes($exam['title'])); ?>', <?php echo (int)$exam['duration_minutes']; ?>, <?php echo $q_count; ?>)">
                                    <i class="bi bi-play-fill me-1"></i> <?php echo $has_attempted ? 'Retry Exam' : 'Start Exam'; ?>
                                </button>
                            <?php endif; ?>
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

<!-- Start Exam Confirmation Modal -->
<div class="modal fade" id="startExamModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-play-circle-fill text-primary me-2"></i> Ready to Start?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="mb-3 text-dark">You are about to start: <strong id="modalExamTitle"></strong></p>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="bg-primary-subtle rounded-3 p-3 text-center">
                            <i class="bi bi-stopwatch-fill text-primary fs-4 d-block mb-1"></i>
                            <div class="fw-bold text-primary" id="modalDuration"></div>
                            <small class="text-muted">Time Limit</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-success-subtle rounded-3 p-3 text-center">
                            <i class="bi bi-patch-question-fill text-success fs-4 d-block mb-1"></i>
                            <div class="fw-bold text-success" id="modalQCount"></div>
                            <small class="text-muted">Questions</small>
                        </div>
                    </div>
                </div>
                <div class="alert alert-warning d-flex gap-2 rounded-3 mb-0 small">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                    <div>The timer starts immediately when you click <strong>Begin Exam</strong>. Make sure you are ready and have a stable internet connection.</div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Not Yet</button>
                <a href="#" id="modalBeginBtn" class="btn btn-primary fw-bold px-4">
                    <i class="bi bi-play-fill me-1"></i> Begin Exam
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function confirmStartExam(examId, title, duration, qCount) {
    document.getElementById('modalExamTitle').textContent = title;
    document.getElementById('modalDuration').textContent = duration + ' minutes';
    document.getElementById('modalQCount').textContent = qCount + ' questions';
    document.getElementById('modalBeginBtn').href = 'exam.php?id=' + examId;
    new bootstrap.Modal(document.getElementById('startExamModal')).show();
}

function filterExams(status, btn) {
    // Update active button
    document.querySelectorAll('#examFilterGroup .btn').forEach(b => {
        b.classList.remove('btn-primary', 'active');
        b.classList.add('btn-outline-primary');
    });
    btn.classList.remove('btn-outline-primary');
    btn.classList.add('btn-primary', 'active');

    // Show/hide cards
    document.querySelectorAll('.exam-card-wrapper').forEach(card => {
        if (status === 'all' || card.dataset.status === status) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>

