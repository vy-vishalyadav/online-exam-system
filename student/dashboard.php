<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

require_once __DIR__ . '/../includes/exam_submission.php';
// Opportunistically finalize any expired student attempts (throttled to once per 60s globally)
runOpportunisticExpiredExamCleanup($conn);

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
            $pool_limit        = (int)($exam['questions_to_display'] ?? 0);
            if ($pool_limit > 0 && $pool_limit < $q_count) {
                if ($q_count > 0) {
                    $total_marks = round(($total_marks / $q_count) * $pool_limit, 1);
                }
                $q_count = $pool_limit;
            }
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
                $sched_badge  = '<span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-2.5 py-1 small live-sched-badge" id="schedBadge_' . (int)$exam['id'] . '" data-exam-id="' . (int)$exam['id'] . '" data-target="' . $s_at . '" data-type="opens" data-label="Opens ' . date('d M, h:i A', $s_at) . '"><i class="bi bi-calendar-event me-1"></i>Opens ' . date('d M, h:i A', $s_at) . '</span>';
            } elseif ($e_at && $now_ts > $e_at) {
                $sched_locked = true;
                $sched_badge  = '<span class="badge bg-secondary rounded-pill px-2.5 py-1 small" id="schedBadge_' . (int)$exam['id'] . '"><i class="bi bi-lock me-1"></i>Closed ' . date('d M, h:i A', $e_at) . '</span>';
            } elseif ($s_at && $e_at) {
                $sched_badge  = '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1 small live-sched-badge" id="schedBadge_' . (int)$exam['id'] . '" data-exam-id="' . (int)$exam['id'] . '" data-target="' . $e_at . '" data-type="closes" data-label="Live until ' . date('h:i A', $e_at) . '"><i class="bi bi-broadcast me-1"></i>Live until ' . date('h:i A', $e_at) . '</span>';
            }
        ?>
            <div class="col-md-6 col-lg-4 exam-card-wrapper"
                 id="examCardWrapper_<?php echo (int)$exam['id']; ?>"
                 data-status="<?php echo $filter_status; ?>"
                 data-exam-id="<?php echo (int)$exam['id']; ?>"
                 data-title="<?php echo htmlspecialchars($exam['title'], ENT_QUOTES, 'UTF-8'); ?>"
                 data-duration="<?php echo (int)$exam['duration_minutes']; ?>"
                 data-qcount="<?php echo $q_count; ?>"
                 data-total-marks="<?php echo htmlspecialchars((string)$total_marks_disp, ENT_QUOTES, 'UTF-8'); ?>"
                 data-start-ts="<?php echo $s_at ?: 0; ?>"
                 data-end-ts="<?php echo $e_at ?: 0; ?>"
                 data-in-progress="<?php echo $is_in_progress ? '1' : '0'; ?>"
                 data-submitted="<?php echo $is_submitted ? '1' : '0'; ?>">
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
                        <div class="mb-2" id="schedBadgeContainer_<?php echo (int)$exam['id']; ?>">
                            <?php echo $sched_badge; ?>
                        </div>

                        <h5 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($exam['title']); ?></h5>
                        
                        <div id="examMiddleStatus_<?php echo (int)$exam['id']; ?>">
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
                                    $marks_obtained = ($last_score !== null) ? rtrim(rtrim(number_format((float)$last_score, 2), '0'), '.') : '0'; 
                                    $score_pct = ($total_marks > 0) ? min(100, round(((float)$marks_obtained / $total_marks) * 100)) : 0;
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
                        <?php elseif ($e_at && $now_ts > $e_at): ?>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-lock-fill text-secondary me-1"></i><span class="fw-semibold text-secondary">Exam Closed</span> &bull; Scheduled window has ended.
                            </p>
                        <?php elseif ($s_at && $now_ts < $s_at): ?>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-calendar-event text-info me-1"></i><span class="fw-semibold text-info">Upcoming Exam</span> &bull; Opens <?php echo date('d M, h:i A', $s_at); ?>.
                            </p>
                        <?php elseif ($q_count === 0): ?>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-exclamation-circle text-warning me-1"></i>No questions available yet.
                            </p>
                        <?php else: ?>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-play-circle text-primary me-1"></i>Not started yet &bull; Click <strong>Start Exam</strong> below to begin.
                            </p>
                        <?php endif; ?>
                        </div>
                    </div>

                    <div id="examAction_<?php echo (int)$exam['id']; ?>">
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
                            <button class="btn btn-secondary w-100 fw-bold py-2 btn-sched-locked" disabled>
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

// Event delegation for Start Exam buttons (handles both static and dynamically unlocked buttons)
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.btn-start-exam');
    if (btn) {
        var d = btn.dataset;
        var rawTitle = d.title ? decodeURIComponent(d.title) : (d.title || '');
        confirmStartExam(d.examId, rawTitle, d.duration, d.qcount, d.totalMarks);
    }
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

// Dynamic real-time countdown updater: unlocks Start button immediately when opening timer reaches 0
function updateScheduleBadges() {
    var now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('.live-sched-badge').forEach(function(badge) {
        var examId = badge.getAttribute('data-exam-id');
        var target = parseInt(badge.getAttribute('data-target'), 10);
        var type = badge.getAttribute('data-type');
        var card = document.getElementById('examCardWrapper_' + examId);
        if (!target) return;
        var diff = target - now;

        if (diff <= 0) {
            if (type === 'opens') {
                // Scheduled start time reached! Transition to Live state without needing refresh
                var endTs = card ? parseInt(card.getAttribute('data-end-ts'), 10) : 0;
                if (endTs && endTs > now) {
                    badge.setAttribute('data-type', 'closes');
                    badge.setAttribute('data-target', endTs);
                    badge.className = 'badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1 small live-sched-badge';
                    var cDiff = endTs - now;
                    var ch = Math.floor(cDiff / 3600);
                    var cm = Math.floor((cDiff % 3600) / 60);
                    var cs = cDiff % 60;
                    badge.innerHTML = '<i class="bi bi-broadcast me-1"></i>Live &bull; ' + (ch > 0 ? ch + 'h ' : '') + cm + 'm ' + cs + 's left';
                } else {
                    badge.className = 'badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1 small';
                    badge.innerHTML = '<i class="bi bi-broadcast me-1"></i>Live Now';
                }

                // Dynamically unlock the card button immediately!
                if (card) {
                    var actionBox = document.getElementById('examAction_' + examId);
                    var isSubmitted = card.getAttribute('data-submitted') === '1';
                    var isInProgress = card.getAttribute('data-in-progress') === '1';
                    var qCount = parseInt(card.getAttribute('data-qcount'), 10) || 0;
                    var title = card.getAttribute('data-title') || '';
                    var duration = card.getAttribute('data-duration') || '0';
                    var totalMarks = card.getAttribute('data-total-marks') || '0';

                    if (!isSubmitted && actionBox && actionBox.querySelector('.btn-sched-locked')) {
                        var middleBox = document.getElementById('examMiddleStatus_' + examId);
                        if (middleBox && !isInProgress) {
                            middleBox.innerHTML = '<p class="text-muted small mb-3"><i class="bi bi-play-circle text-primary me-1"></i>Not started yet &bull; Click <strong>Start Exam</strong> below to begin.</p>';
                        }
                        if (qCount > 0) {
                            if (isInProgress) {
                                actionBox.innerHTML = '<a href="exam.php?id=' + examId + '" class="btn btn-warning text-dark w-100 fw-bold shadow-sm py-2"><i class="bi bi-play-circle-fill me-1"></i> Resume Exam</a>';
                            } else {
                                var safeTitle = title.replace(/"/g, '&quot;');
                                actionBox.innerHTML = '<button type="button" class="btn btn-primary w-100 fw-bold shadow-sm py-2 btn-start-exam" data-exam-id="' + examId + '" data-title="' + safeTitle + '" data-duration="' + duration + '" data-qcount="' + qCount + '" data-total-marks="' + totalMarks + '"><i class="bi bi-play-fill me-1"></i> Start Exam</button>';
                            }
                        } else {
                            actionBox.innerHTML = '<button class="btn btn-secondary w-100 fw-bold py-2" disabled><i class="bi bi-exclamation-circle me-1"></i> No Questions Available</button>';
                        }
                    }
                }
            } else if (type === 'closes') {
                // Closing window expired!
                badge.className = 'badge bg-secondary rounded-pill px-2.5 py-1 small';
                badge.innerHTML = '<i class="bi bi-lock me-1"></i>Closed';
                if (card) {
                    var actionBox = document.getElementById('examAction_' + examId);
                    var middleBox = document.getElementById('examMiddleStatus_' + examId);
                    var isSubmitted = card.getAttribute('data-submitted') === '1';
                    if (!isSubmitted) {
                        if (middleBox) {
                            middleBox.innerHTML = '<p class="text-muted small mb-3"><i class="bi bi-lock-fill text-secondary me-1"></i><span class="fw-semibold text-secondary">Exam Closed</span> &bull; Scheduled window has ended.</p>';
                        }
                        if (actionBox) {
                            actionBox.innerHTML = '<button class="btn btn-secondary w-100 fw-bold py-2" disabled><i class="bi bi-lock me-1"></i> Exam Closed</button>';
                        }
                    }
                }
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

document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        updateScheduleBadges();
    }
});
</script>

<?php include '../includes/footer.php'; ?>

