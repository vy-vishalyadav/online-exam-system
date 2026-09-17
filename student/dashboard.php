<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

$student_id = (int)$_SESSION['student_id'];

// Fetch student's current class
$scr = mysqli_prepare($conn, "SELECT s.class_id, c.name AS class_name FROM students s LEFT JOIN classes c ON s.class_id=c.id WHERE s.id=?");
mysqli_stmt_bind_param($scr, "i", $student_id);
mysqli_stmt_execute($scr);
$scrow = mysqli_fetch_assoc(mysqli_stmt_get_result($scr));
mysqli_stmt_close($scr);
$student_class_id   = (int)($scrow['class_id'] ?? 0);
$student_class_name = $scrow['class_name'] ?? null;

// Fetch exams visible to this student using prepared statement:
// - Exams with NO class assignment (visible to everyone), OR
// - Exams assigned to this student's class
if ($student_class_id > 0) {
    $sql = "SELECT e.*,
                (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS q_count,
                (SELECT COALESCE(SUM(marks), COUNT(*)) FROM questions q WHERE q.exam_id = e.id) AS total_marks,
                (SELECT score  FROM results r WHERE r.student_id = ? AND r.exam_id = e.id ORDER BY r.attempted_at DESC LIMIT 1) AS last_score,
                (SELECT status FROM results r WHERE r.student_id = ? AND r.exam_id = e.id ORDER BY r.attempted_at DESC LIMIT 1) AS last_status,
                (SELECT COUNT(*) FROM results r WHERE r.student_id = ? AND r.exam_id = e.id) AS attempt_count,
                (SELECT es.submitted FROM exam_sessions es WHERE es.student_id = ? AND es.exam_id = e.id LIMIT 1) AS session_submitted,
                (SELECT es.started_at FROM exam_sessions es WHERE es.student_id = ? AND es.exam_id = e.id LIMIT 1) AS session_started_at
            FROM exams e
            WHERE (
                NOT EXISTS (SELECT 1 FROM exam_class_assignments eca WHERE eca.exam_id = e.id)
                OR EXISTS (SELECT 1 FROM exam_class_assignments eca WHERE eca.exam_id = e.id AND eca.class_id = ?)
            )
            ORDER BY e.id DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iiiiii", $student_id, $student_id, $student_id, $student_id, $student_id, $student_class_id);
} else {
    $sql = "SELECT e.*,
                (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS q_count,
                (SELECT COALESCE(SUM(marks), COUNT(*)) FROM questions q WHERE q.exam_id = e.id) AS total_marks,
                (SELECT score  FROM results r WHERE r.student_id = ? AND r.exam_id = e.id ORDER BY r.attempted_at DESC LIMIT 1) AS last_score,
                (SELECT status FROM results r WHERE r.student_id = ? AND r.exam_id = e.id ORDER BY r.attempted_at DESC LIMIT 1) AS last_status,
                (SELECT COUNT(*) FROM results r WHERE r.student_id = ? AND r.exam_id = e.id) AS attempt_count,
                (SELECT es.submitted FROM exam_sessions es WHERE es.student_id = ? AND es.exam_id = e.id LIMIT 1) AS session_submitted,
                (SELECT es.started_at FROM exam_sessions es WHERE es.student_id = ? AND es.exam_id = e.id LIMIT 1) AS session_started_at
            FROM exams e
            WHERE NOT EXISTS (SELECT 1 FROM exam_class_assignments eca WHERE eca.exam_id = e.id)
            ORDER BY e.id DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iiiii", $student_id, $student_id, $student_id, $student_id, $student_id);
}
mysqli_stmt_execute($stmt);
$exams = mysqli_stmt_get_result($stmt);
?>

<div class="mb-4">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h3 class="fw-extrabold mb-0">Welcome, <?php echo htmlspecialchars($_SESSION['student_name']); ?>! 👋</h3>
        <?php if ($student_class_name): ?>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-2 fw-bold fs-6">
                <i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($student_class_name); ?>
            </span>
        <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-2 small">
                <i class="bi bi-person-dash me-1"></i>No class assigned
            </span>
        <?php endif; ?>
    </div>
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
            $q_count           = (int)$exam['q_count'];
            $total_marks       = (float)($exam['total_marks'] ?? $q_count);
            $total_marks_disp  = (floor($total_marks) == $total_marks) ? (int)$total_marks : number_format($total_marks, 1);
            $attempt_count     = (int)($exam['attempt_count'] ?? 0);
            $has_attempted     = ($attempt_count > 0);
            $last_status       = $exam['last_status'] ?? null;
            $last_score        = $exam['last_score'] !== null ? (float)$exam['last_score'] : null;
            $session_submitted = (int)($exam['session_submitted'] ?? 0);
            $session_started   = !empty($exam['session_started_at']);
            $is_submitted      = ($has_attempted || $session_submitted === 1);
            $is_in_progress    = ($session_started && $session_submitted === 0);
            $is_pending        = ($is_submitted && $last_status === 'pending');
            $filter_status     = $is_submitted ? 'completed' : 'todo';

            // Schedule status
            $now_ts   = time();
            $s_at     = !empty($exam['start_at']) ? strtotime($exam['start_at']) : null;
            $e_at     = !empty($exam['end_at'])   ? strtotime($exam['end_at'])   : null;
            $sched_locked   = false;
            $sched_badge    = '';
            if ($s_at && $now_ts < $s_at) {
                $sched_locked = true;
                $sched_badge  = '<span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-2.5 py-1 small live-sched-badge" data-target="' . $s_at . '" data-type="opens" data-label="Opens ' . date('d M, h:i A', $s_at) . '"><i class="bi bi-calendar-event me-1"></i>Opens ' . date('d M, h:i A', $s_at) . '</span>';
            } elseif ($e_at && $now_ts > $e_at) {
                $sched_locked = true;
                $sched_badge  = '<span class="badge bg-secondary rounded-pill px-2.5 py-1 small"><i class="bi bi-lock me-1"></i>Closed ' . date('d M, h:i A', $e_at) . '</span>';
            } elseif ($s_at && $e_at) {
                $sched_badge  = '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1 small live-sched-badge" data-target="' . $e_at . '" data-type="closes" data-label="Live until ' . date('h:i A', $e_at) . '"><i class="bi bi-broadcast me-1"></i>Live until ' . date('h:i A', $e_at) . '</span>';
            }
        ?>
            <div class="col-md-6 col-lg-4 exam-card-wrapper" data-status="<?php echo $filter_status; ?>">
                <div class="hover-card h-100 p-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 fw-bold">
                                <i class="bi bi-clock me-1"></i><?php echo (int)$exam['duration_minutes']; ?> mins
                            </span>
                            <span class="badge bg-light text-dark border rounded-pill px-2.5 py-1">
                                <i class="bi bi-patch-question me-1"></i><?php echo $q_count; ?> Questions
                            </span>
                            <span class="badge rounded-pill px-2.5 py-1 fw-bold" style="background:#eef2ff; color:#4f46e5; border: 1px solid #c7d2fe;">
                                <i class="bi bi-award me-1"></i><?php echo $total_marks_disp; ?> Marks
                            </span>
                        </div>
                        <?php if ($sched_badge): ?>
                            <div class="mb-2"><?php echo $sched_badge; ?></div>
                        <?php endif; ?>

                        <h5 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($exam['title']); ?></h5>
                        
                        <?php if ($is_submitted): ?>
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
                                    <?php 
                                    $marks_obtained = ($last_score !== null) ? (int)$last_score : 0; 
                                    $score_pct = ($total_marks > 0) ? min(100, round(($marks_obtained / $total_marks) * 100)) : 0;
                                    ?>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted fw-semibold">Final Score:</small>
                                        <span class="fw-bold text-primary fs-6">
                                            <?php echo $marks_obtained; ?> / <?php echo $total_marks_disp; ?> marks
                                        </span>
                                    </div>
                                    <!-- Score bar -->
                                    <div class="progress mt-2 rounded-pill" style="height:6px;">
                                        <div class="progress-bar bg-primary" style="width:<?php echo $score_pct; ?>%"></div>
                                    </div>
                                    <div class="mt-2">
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1 small fw-bold">
                                            <i class="bi bi-check-circle-fill me-1"></i> Submitted
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($is_in_progress && !$sched_locked): ?>
                            <div class="bg-warning-subtle p-3 rounded-3 mb-3 border border-warning-subtle">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-warning text-dark border rounded-pill px-2.5 py-1 fw-bold">
                                        <i class="bi bi-hourglass-split me-1"></i> In Progress
                                    </span>
                                    <small class="text-muted fw-semibold">Session active</small>
                                </div>
                                <div class="mt-2 text-dark small">
                                    You have an ongoing attempt. Draft answers are saved.
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mb-3">
                                Not started yet.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if ($is_submitted): ?>
                            <?php if ($is_pending): ?>
                                <button class="btn btn-outline-warning w-100 fw-semibold py-2" disabled>
                                    <i class="bi bi-hourglass-split me-1"></i> Awaiting Result
                                </button>
                            <?php else: ?>
                                <a href="result.php?view_exam_id=<?php echo (int)$exam['id']; ?>#resultReviewCard"
                                   class="btn btn-primary w-100 fw-bold py-2">
                                    <i class="bi bi-eye me-1"></i> View Result
                                </a>
                            <?php endif; ?>
                        <?php elseif ($sched_locked): ?>
                            <button class="btn btn-secondary w-100 fw-bold py-2" disabled>
                                <i class="bi bi-lock me-1"></i>
                                <?php echo ($s_at && $now_ts < $s_at) ? 'Not Open Yet' : 'Exam Closed'; ?>
                            </button>
                        <?php elseif ($q_count > 0): ?>
                            <?php if ($is_in_progress): ?>
                                <!-- Active Session: Resume Exam -->
                                <a href="exam.php?id=<?php echo $exam['id']; ?>"
                                   class="btn btn-warning text-dark w-100 fw-bold shadow-sm py-2">
                                    <i class="bi bi-play-circle-fill me-1"></i> Resume Exam
                                </a>
                            <?php else: ?>
                                <!-- Not started: Start Exam -->
                                <button type="button"
                                        class="btn btn-primary w-100 fw-bold shadow-sm py-2 btn-start-exam"
                                        data-exam-id="<?php echo (int)$exam['id']; ?>"
                                        data-title="<?php echo htmlspecialchars($exam['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-duration="<?php echo (int)$exam['duration_minutes']; ?>"
                                        data-qcount="<?php echo $q_count; ?>"
                                        data-total-marks="<?php echo htmlspecialchars((string)$total_marks_disp, ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="bi bi-play-fill me-1"></i> Start Exam
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
                    <div class="col-4">
                        <div class="bg-primary-subtle rounded-3 p-2 text-center">
                            <i class="bi bi-stopwatch-fill text-primary fs-4 d-block mb-1"></i>
                            <div class="fw-bold text-primary small" id="modalDuration"></div>
                            <small class="text-muted" style="font-size:0.75rem;">Time Limit</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="bg-success-subtle rounded-3 p-2 text-center">
                            <i class="bi bi-patch-question-fill text-success fs-4 d-block mb-1"></i>
                            <div class="fw-bold text-success small" id="modalQCount"></div>
                            <small class="text-muted" style="font-size:0.75rem;">Questions</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rounded-3 p-2 text-center" style="background:#eef2ff; border:1px solid #c7d2fe;">
                            <i class="bi bi-award-fill fs-4 d-block mb-1" style="color:#4f46e5;"></i>
                            <div class="fw-bold small" style="color:#4f46e5;" id="modalTotalMarks"></div>
                            <small class="text-muted" style="font-size:0.75rem;">Total Marks</small>
                        </div>
                    </div>
                </div>
                <div class="alert alert-warning d-flex gap-2 rounded-3 mb-0 small">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1 text-warning"></i>
                    <div>This exam is strictly schedule-based. The countdown runs continuously until the scheduled closing time for all students. Ensure you have a stable connection before clicking <strong>Begin Exam</strong>.</div>
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
function confirmStartExam(examId, title, duration, qCount, totalMarks) {
    document.getElementById('modalExamTitle').textContent = title;
    document.getElementById('modalDuration').textContent = duration + ' mins';
    document.getElementById('modalQCount').textContent = qCount + ' questions';
    document.getElementById('modalTotalMarks').textContent = totalMarks + ' marks';
    document.getElementById('modalBeginBtn').href = 'exam.php?id=' + examId;
    new bootstrap.Modal(document.getElementById('startExamModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-start-exam').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            confirmStartExam(d.examId, d.title, d.duration, d.qcount, d.totalMarks);
        });
    });
});

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

// Dynamic real-time countdown updater for schedule badges
function updateScheduleBadges() {
    var now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('.live-sched-badge').forEach(function(badge) {
        var target = parseInt(badge.getAttribute('data-target'), 10);
        var type = badge.getAttribute('data-type');
        var defaultLabel = badge.getAttribute('data-label') || '';
        if (!target) return;
        var diff = target - now;
        if (diff <= 0) {
            if (type === 'opens') {
                badge.innerHTML = '<i class="bi bi-broadcast me-1"></i>Opening now...';
            } else {
                badge.className = 'badge bg-secondary rounded-pill px-2.5 py-1 small';
                badge.innerHTML = '<i class="bi bi-lock me-1"></i>Closed';
            }
            return;
        }
        var hours = Math.floor(diff / 3600);
        var mins = Math.floor((diff % 3600) / 60);
        var secs = diff % 60;
        var timeStr = (hours > 0 ? hours + 'h ' : '') + mins + 'm ' + secs + 's';
        if (type === 'closes') {
            badge.innerHTML = '<i class="bi bi-broadcast me-1"></i>Live &bull; ' + timeStr + ' left';
        } else if (type === 'opens') {
            badge.innerHTML = '<i class="bi bi-calendar-event me-1"></i>Opens in ' + timeStr;
        }
    });
}
setInterval(updateScheduleBadges, 1000);
updateScheduleBadges();
</script>

<?php include '../includes/footer.php'; ?>

