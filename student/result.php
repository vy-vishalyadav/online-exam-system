<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$student_id      = (int)$_SESSION['student_id'];
$submission_review = null;
$error_msg       = "";

require_once __DIR__ . '/../includes/exam_submission.php';


// ── Handle POST exam submission ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exam_id']) && isset($_POST['submit_exam'])) {
    $exam_id = (int)$_POST['exam_id'];

    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_msg = "Invalid request. Please return to the exam and try again.";
    } else {
        // One-time submission token check
        $submit_token_key = 'exam_submit_token_' . $exam_id;
        $expected_token   = $_SESSION[$submit_token_key] ?? null;
        $submitted_token  = $_POST['exam_submit_token'] ?? null;

        if (!$expected_token || !$submitted_token || !hash_equals($expected_token, $submitted_token)) {
            $_SESSION['flash_already_submitted'] = "Your exam was already submitted. Refreshing the page after submission has no effect.";
            safe_redirect("result.php");
        }

        // Consume token
        unset($_SESSION[$submit_token_key]);

        // Validate elapsed time against schedule & allowed duration
        $ss = mysqli_prepare($conn,
            "SELECT es.submitted, es.duration_minutes,
                    TIMESTAMPDIFF(SECOND, es.started_at, NOW()) AS elapsed_seconds,
                    e.end_at,
                    TIMESTAMPDIFF(SECOND, NOW(), e.end_at) AS window_rem_sec
             FROM exam_sessions es
             JOIN exams e ON e.id = es.exam_id
             WHERE es.student_id = ? AND es.exam_id = ? LIMIT 1");
        $ss_row = null;
        if ($ss) {
            mysqli_stmt_bind_param($ss, "ii", $student_id, $exam_id);
            mysqli_stmt_execute($ss);
            $ss_res = mysqli_stmt_get_result($ss);
            $ss_row = $ss_res ? mysqli_fetch_assoc($ss_res) : null;
            mysqli_stmt_close($ss);
            if ($ss_row && (int)$ss_row['submitted'] === 1) {
                $_SESSION['flash_already_submitted'] = "Your exam was already submitted.";
                safe_redirect("result.php");
            }
        }

        $allowed_sec          = ((int)($ss_row['duration_minutes'] ?? 30)) * 60;
        $elapsed_sec          = (int)($ss_row['elapsed_seconds'] ?? 0);
        $is_window_passed     = (!empty($ss_row['end_at']) && (int)($ss_row['window_rem_sec'] ?? 0) < -30);
        $is_duration_exceeded = ($elapsed_sec > ($allowed_sec + 30));

        if ($is_duration_exceeded || $is_window_passed) {
            // Reject late POST answers — load drafts auto-saved before timer expiration
            $user_mcq_answers  = [];
            $user_desc_answers = [];
        } else {
            $user_mcq_answers  = $_POST['answer'] ?? [];
            $user_desc_answers = $_POST['descriptive_answer'] ?? [];
        }

        $sub_res = finalizeExamSubmission($conn, $student_id, $exam_id, 'manual', $user_mcq_answers, $user_desc_answers);

        if (!empty($sub_res['already_submitted'])) {
            $_SESSION['flash_already_submitted'] = "Your exam was already submitted.";
            safe_redirect("result.php");
        } elseif (!empty($sub_res['success'])) {
            $_SESSION['submission_review'] = $sub_res['review'];
            safe_redirect("result.php");
        } else {
            $error_msg = "An error occurred while finalizing your submission. Please notify your instructor.";
        }
    }
}


// ── Handle timeout auto-submit (GET redirect from exam.php) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['timeout']) && isset($_GET['exam_id'])) {
    $to_exam_id = (int)$_GET['exam_id'];
    if ($to_exam_id > 0) {
        // 1. Verify session exists, is not already submitted, and fetch timing
        $ts = mysqli_prepare($conn,
            "SELECT es.submitted, es.duration_minutes,
                    TIMESTAMPDIFF(SECOND, es.started_at, NOW()) AS elapsed_seconds,
                    e.end_at,
                    TIMESTAMPDIFF(SECOND, NOW(), e.end_at) AS window_rem_sec
             FROM exam_sessions es
             JOIN exams e ON e.id = es.exam_id
             WHERE es.student_id = ? AND es.exam_id = ? LIMIT 1");
        $ts_row = null;
        if ($ts) {
            mysqli_stmt_bind_param($ts, "ii", $student_id, $to_exam_id);
            mysqli_stmt_execute($ts);
            $ts_res = mysqli_stmt_get_result($ts);
            $ts_row = $ts_res ? mysqli_fetch_assoc($ts_res) : null;
            mysqli_stmt_close($ts);
        }

        if ($ts_row && !(int)$ts_row['submitted']) {
            // 2. Check violation strikes count
            $v_chk = mysqli_prepare($conn,
                "SELECT COUNT(*) AS v_cnt FROM exam_violations 
                 WHERE student_id = ? AND exam_id = ? 
                    AND violation_type IN ('tab_switch','fullscreen_exit','exit_exam')");
            $v_count = 0;
            if ($v_chk) {
                mysqli_stmt_bind_param($v_chk, "ii", $student_id, $to_exam_id);
                mysqli_stmt_execute($v_chk);
                $v_res = mysqli_stmt_get_result($v_chk);
                $v_row = $v_res ? mysqli_fetch_assoc($v_res) : null;
                $v_count = (int)($v_row['v_cnt'] ?? 0);
                mysqli_stmt_close($v_chk);
            }

            // 3. Verify server-authoritative expiration: timer expired, schedule ended, or 3 strikes
            $elapsed_sec     = (int)($ts_row['elapsed_seconds'] ?? 0);
            $allowed_sec     = ((int)($ts_row['duration_minutes'] ?? 30)) * 60;
            $is_time_up      = ($elapsed_sec >= $allowed_sec);
            $is_window_up    = (!empty($ts_row['end_at']) && (int)($ts_row['window_rem_sec'] ?? 0) <= 0);
            $is_disqualified = ($v_count >= 3);

            if (!$is_time_up && !$is_window_up && !$is_disqualified) {
                // Premature trigger without valid expiry — redirect back to exam
                header("Location: exam.php?id={$to_exam_id}");
                exit;
            }

            $reason = $is_disqualified ? 'disqualified' : 'timeout';
            // Finalize submission atomically using the drafts
            $sub_res = finalizeExamSubmission($conn, $student_id, $to_exam_id, $reason, [], []);
            if (!empty($sub_res['success'])) {
                $_SESSION['submission_review'] = $sub_res['review'];
            }
        }
        safe_redirect("result.php");
    }
}

// Retrieve and clear the submission review from session (after PRG redirect)
if (!empty($_SESSION['submission_review'])) {
    $submission_review = $_SESSION['submission_review'];
    unset($_SESSION['submission_review']);
}

// ── Handle view specific past result by result_id OR exam_id ──
$view_result_id = isset($_GET['view_result_id']) ? (int)$_GET['view_result_id'] : 0;
$view_exam_id   = isset($_GET['view_exam_id']) ? (int)$_GET['view_exam_id'] : 0;

if (empty($submission_review) && $_SERVER['REQUEST_METHOD'] === 'GET' && ($view_result_id > 0 || $view_exam_id > 0)) {
    $vr_stmt = null;
    if ($view_result_id > 0) {
        $vr_stmt = mysqli_prepare($conn,
            "SELECT r.*, COALESCE(e.title, 'Examination (Archived)') AS exam_title,
                    COALESCE(NULLIF((SELECT SUM(COALESCE(q.marks, 1)) FROM student_answers sa JOIN questions q ON sa.question_id = q.id WHERE sa.result_id = r.id), 0),
                             (SELECT COALESCE(SUM(q.marks), 0) FROM questions q WHERE q.exam_id = e.id)) AS exam_total_marks
             FROM results r
             LEFT JOIN exams e ON r.exam_id = e.id
             WHERE r.student_id = ? AND r.id = ?
             LIMIT 1");
        if ($vr_stmt) {
            mysqli_stmt_bind_param($vr_stmt, "ii", $student_id, $view_result_id);
        }
    } else {
        $vr_stmt = mysqli_prepare($conn,
            "SELECT r.*, COALESCE(e.title, 'Examination (Archived)') AS exam_title,
                    COALESCE(NULLIF((SELECT SUM(COALESCE(q.marks, 1)) FROM student_answers sa JOIN questions q ON sa.question_id = q.id WHERE sa.result_id = r.id), 0),
                             (SELECT COALESCE(SUM(q.marks), 0) FROM questions q WHERE q.exam_id = e.id)) AS exam_total_marks
             FROM results r
             LEFT JOIN exams e ON r.exam_id = e.id
             WHERE r.student_id = ? AND r.exam_id = ?
             ORDER BY r.attempted_at DESC LIMIT 1");
        if ($vr_stmt) {
            mysqli_stmt_bind_param($vr_stmt, "ii", $student_id, $view_exam_id);
        }
    }

    if ($vr_stmt) {
        mysqli_stmt_execute($vr_stmt);
        $vr_res = mysqli_stmt_get_result($vr_stmt);
        $vr     = ($vr_res && $vr_res instanceof mysqli_result) ? mysqli_fetch_assoc($vr_res) : null;
        mysqli_stmt_close($vr_stmt);

        if ($vr) {
            $view_items   = [];
            $view_correct = 0;
            $view_total   = 0;
            $view_desc    = 0;
            $sa_has_rows  = false;

            $sa_stmt = mysqli_prepare($conn,
                "SELECT sa.*, COALESCE(q.question_text, CONCAT('Question #', sa.question_id)) AS question_text,
                        q.option_a, q.option_b, q.option_c, q.option_d,
                        q.correct_option, COALESCE(q.question_type, 'mcq') AS question_type
                 FROM student_answers sa
                 LEFT JOIN questions q ON sa.question_id = q.id
                 WHERE sa.result_id = ?
                 ORDER BY sa.id ASC");
            if ($sa_stmt) {
                $v_result_id = (int)$vr['id'];
                mysqli_stmt_bind_param($sa_stmt, "i", $v_result_id);
                mysqli_stmt_execute($sa_stmt);
                $sa_res = mysqli_stmt_get_result($sa_stmt);
                if ($sa_res && $sa_res instanceof mysqli_result) {
                    while ($sa_row = mysqli_fetch_assoc($sa_res)) {
                        $sa_has_rows = true;
                        $view_total++;
                        $q_type = $sa_row['question_type'] ?? 'mcq';
                        if ($q_type === 'descriptive') {
                            $view_desc++;
                            $view_items[] = [
                                'question_id'   => (int)$sa_row['question_id'],
                                'question_type' => 'descriptive',
                                'question_text' => (string)($sa_row['question_text'] ?? ''),
                                'user_ans'      => (string)($sa_row['user_answer'] ?? ''),
                                'is_correct'    => null,
                                'marks'         => $sa_row['marks_awarded'],
                            ];
                        } else {
                            $is_c = !empty($sa_row['is_correct']);
                            if ($is_c) $view_correct++;
                            $view_items[] = [
                                'question_id'   => (int)$sa_row['question_id'],
                                'question_type' => 'mcq',
                                'question_text' => (string)($sa_row['question_text'] ?? ''),
                                'option_a'      => (string)($sa_row['option_a'] ?? ''),
                                'option_b'      => (string)($sa_row['option_b'] ?? ''),
                                'option_c'      => (string)($sa_row['option_c'] ?? ''),
                                'option_d'      => (string)($sa_row['option_d'] ?? ''),
                                'user_ans'      => (string)($sa_row['user_answer'] ?? ''),
                                'correct_ans'   => (string)($sa_row['correct_option'] ?? ''),
                                'is_correct'    => $is_c,
                                'marks'         => $sa_row['marks_awarded'],
                            ];
                        }
                    }
                }
                mysqli_stmt_close($sa_stmt);
            }

            // Fallback if student_answers has no records (e.g. legacy/direct attempts)
            if (empty($view_items)) {
                $q_stmt = mysqli_prepare($conn,
                    "SELECT id, question_text, option_a, option_b, option_c, option_d, correct_option, question_type, marks
                     FROM questions
                     WHERE exam_id = ?
                     ORDER BY id ASC");
                if ($q_stmt) {
                    $v_exam_id = (int)$vr['exam_id'];
                    mysqli_stmt_bind_param($q_stmt, "i", $v_exam_id);
                    mysqli_stmt_execute($q_stmt);
                    $q_res = mysqli_stmt_get_result($q_stmt);
                    if ($q_res && $q_res instanceof mysqli_result) {
                        while ($q_row = mysqli_fetch_assoc($q_res)) {
                            $view_total++;
                            $q_type = $q_row['question_type'] ?? 'mcq';
                            if ($q_type === 'descriptive') {
                                $view_desc++;
                                $view_items[] = [
                                    'question_id'   => (int)$q_row['id'],
                                    'question_type' => 'descriptive',
                                    'question_text' => (string)($q_row['question_text'] ?? ''),
                                    'user_ans'      => '',
                                    'is_correct'    => null,
                                    'marks'         => null,
                                ];
                            } else {
                                $view_items[] = [
                                    'question_id'   => (int)$q_row['id'],
                                    'question_type' => 'mcq',
                                    'question_text' => (string)($q_row['question_text'] ?? ''),
                                    'option_a'      => (string)($q_row['option_a'] ?? ''),
                                    'option_b'      => (string)($q_row['option_b'] ?? ''),
                                    'option_c'      => (string)($q_row['option_c'] ?? ''),
                                    'option_d'      => (string)($q_row['option_d'] ?? ''),
                                    'user_ans'      => '',
                                    'correct_ans'   => (string)($q_row['correct_option'] ?? ''),
                                    'is_correct'    => false,
                                    'marks'         => 0,
                                ];
                            }
                        }
                    }
                    mysqli_stmt_close($q_stmt);
                }
            }

            $mcq_count = max(0, $view_total - $view_desc);
            if (!$sa_has_rows && $mcq_count > 0 && (int)$vr['score'] > 0) {
                $view_correct = (int)round(((int)$vr['score'] / 100) * $mcq_count);
            }
            $wrong_count = max(0, $mcq_count - $view_correct);

            $submission_review = [
                'result_id'       => (int)$vr['id'],
                'exam_title'      => (string)($vr['exam_title'] ?? 'Exam'),
                'status'          => (string)($vr['status'] ?? 'published'),
                'has_descriptive' => $view_desc > 0,
                'desc_count'      => $view_desc,
                'total'           => $view_total,
                'total_marks'     => (float)($vr['exam_total_marks'] ?? 0),
                'mcq_count'       => $mcq_count,
                'correct'         => $view_correct,
                'wrong'           => $wrong_count,
                'score'           => (float)$vr['score'],
                'items'           => $view_items,
                'is_historical'   => true,
            ];
        } else {
            $error_msg = "The requested examination result record could not be found, or you do not have permission to view it.";
        }
    }
}

// Flash message for already-submitted
$already_submitted_msg = "";
if (!empty($_SESSION['flash_already_submitted'])) {
    $already_submitted_msg = $_SESSION['flash_already_submitted'];
    unset($_SESSION['flash_already_submitted']);
} elseif (isset($_GET['already_submitted'])) {
    $already_submitted_msg = "You have already completed and submitted this examination. In accordance with collegiate academic policy, re-attempts are not permitted.";
}

// Fetch all past results for this student with time taken and question count
$stmt = mysqli_prepare($conn, "SELECT r.*, COALESCE(e.title, 'Examination (Archived)') AS exam_title,
                                es.time_taken_seconds,
                                COALESCE(NULLIF((SELECT COUNT(*) FROM student_answers sa WHERE sa.result_id = r.id), 0),
                                         (SELECT COUNT(*) FROM questions q WHERE q.exam_id = r.exam_id)) AS q_count,
                                COALESCE(NULLIF((SELECT SUM(COALESCE(q.marks, 1)) FROM student_answers sa JOIN questions q ON sa.question_id = q.id WHERE sa.result_id = r.id), 0),
                                         (SELECT COALESCE(SUM(q.marks), 0) FROM questions q WHERE q.exam_id = r.exam_id)) AS exam_total_marks
                                FROM results r
                                LEFT JOIN exams e ON r.exam_id = e.id
                                LEFT JOIN exam_sessions es
                                    ON es.student_id = r.student_id AND es.exam_id = r.exam_id
                                WHERE r.student_id = ?
                                ORDER BY r.attempted_at DESC");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$past_results_res = mysqli_stmt_get_result($stmt);
$past_results     = [];
if ($past_results_res) {
    while ($row = mysqli_fetch_assoc($past_results_res)) $past_results[] = $row;
}
mysqli_stmt_close($stmt);
?>

<?php if (!empty($already_submitted_msg)): ?>
    <div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-info-circle-fill me-2"></i> <?php echo htmlspecialchars($already_submitted_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($submission_review['timed_out'])): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-4">
        <i class="bi bi-stopwatch-fill me-2"></i>
        <strong>Time's Up!</strong> Your exam was automatically submitted when the timer ran out. Your answers up to that point were saved.
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($submission_review): ?>
    <?php if ($submission_review['status'] === 'pending'): ?>
        <!-- Pending Review Scorecard Banner -->
        <div class="card shadow-lg border-0 rounded-4 mb-5 overflow-hidden" id="resultReviewCard">
            <div class="card-header p-4 text-center text-white bg-warning bg-gradient">
                <div class="mb-2">
                    <i class="bi bi-hourglass-split fs-1"></i>
                </div>
                <h2 class="fw-extrabold mb-1">
                    <?php echo !empty($submission_review['is_historical']) ? 'Exam Review - Result Pending Review ⏳' : 'Exam Submitted - Result Pending Review ⏳'; ?>
                </h2>
                <p class="mb-0 text-white opacity-90">Your answers for <strong><?php echo htmlspecialchars($submission_review['exam_title']); ?></strong> have been recorded safely.</p>
            </div>

            <div class="card-body p-4 p-md-5">
                <div class="alert alert-warning border-0 bg-warning-subtle p-4 rounded-4 mb-4">
                    <div class="d-flex align-items-start gap-3">
                        <i class="bi bi-info-circle-fill text-warning fs-3 mt-1"></i>
                        <div>
                            <h5 class="fw-bold text-dark mb-1">Why is my result pending?</h5>
                            <p class="mb-0 text-muted">
                                <?php if ($submission_review['has_descriptive']): ?>
                                    This exam contains <strong><?php echo $submission_review['desc_count']; ?> written descriptive question(s)</strong> that require manual evaluation by your instructor.
                                <?php else: ?>
                                    This exam is configured for manual instructor evaluation and grade publishing.
                                <?php endif; ?>
                                Once your instructor evaluates and publishes your results, your final score and feedback will appear in your exam history below.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="row g-3 text-center justify-content-center mb-4">
                    <div class="col-6 col-md-4">
                        <div class="bg-light p-3 rounded-4 border">
                            <small class="text-muted fw-semibold">Total Questions</small>
                            <h3 class="fw-extrabold text-dark mb-0 mt-1"><?php echo $submission_review['total']; ?></h3>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="bg-light p-3 rounded-4 border">
                            <small class="text-muted fw-semibold">Current Status</small>
                            <div class="mt-1">
                                <span class="badge bg-warning text-dark fs-6 px-3 py-2 rounded-pill">
                                    <i class="bi bi-clock me-1"></i> Under Review
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-center d-flex justify-content-center gap-3 flex-wrap">
                    <a href="result.php" class="btn btn-outline-secondary btn-lg px-4 rounded-pill shadow-sm fw-bold">
                        <i class="bi bi-clock-history me-1"></i> All Results
                    </a>
                    <a href="dashboard.php" class="btn btn-primary btn-lg px-5 rounded-pill shadow-sm fw-bold">
                        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Scorecard Banner -->
        <div class="card shadow-lg border-0 rounded-4 mb-5 overflow-hidden" id="resultReviewCard">
            <div class="card-header p-4 text-center text-white" style="background: linear-gradient(135deg, #1e40af, #3b82f6);">
                <div class="mb-2">
                    <i class="bi bi-journal-check fs-1"></i>
                </div>
                <h2 class="fw-extrabold mb-1">
                    <?php echo !empty($submission_review['is_historical']) ? 'Exam Performance Review' : 'Exam Submitted ✓'; ?>
                </h2>
                <p class="mb-0 text-white opacity-75">Result for <strong><?php echo htmlspecialchars($submission_review['exam_title']); ?></strong></p>
            </div>

            <div class="card-body p-4 p-md-5">
                <div class="row g-4 text-center justify-content-center mb-4">
                    <div class="col-6 col-md-3">
                        <div class="bg-primary-subtle p-3 rounded-4 border border-primary-subtle">
                            <small class="text-muted fw-semibold">Marks Awarded</small>
                            <h2 class="fw-extrabold text-primary mb-0">
                                <?php echo rtrim(rtrim(number_format((float)$submission_review['score'], 2), '0'), '.'); ?>
                                <?php if (!empty($submission_review['total_marks']) && $submission_review['total_marks'] > 0): ?>
                                    <small class="fs-6 text-muted">/ <?php echo rtrim(rtrim(number_format($submission_review['total_marks'], 2), '0'), '.'); ?> marks</small>
                                <?php else: ?>
                                    <small class="fs-6 text-muted">marks</small>
                                <?php endif; ?>
                            </h2>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-success-subtle p-3 rounded-4 border border-success-subtle">
                            <small class="text-muted fw-semibold">Correct MCQs</small>
                            <h2 class="fw-extrabold text-success mb-0">
                                <?php echo $submission_review['correct']; ?>
                                <?php if (isset($submission_review['mcq_count'])): ?>
                                    <small class="fs-6 text-muted">/ <?php echo $submission_review['mcq_count']; ?></small>
                                <?php endif; ?>
                            </h2>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-danger-subtle p-3 rounded-4 border border-danger-subtle">
                            <small class="text-muted fw-semibold">Wrong / Unanswered</small>
                            <h2 class="fw-extrabold text-danger mb-0">
                                <?php echo $submission_review['wrong']; ?>
                            </h2>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-light p-3 rounded-4 border">
                            <small class="text-muted fw-semibold">Total Questions</small>
                            <h2 class="fw-extrabold text-dark mb-0">
                                <?php echo $submission_review['total']; ?>
                            </h2>
                        </div>
                    </div>
                </div>

                <!-- Detailed Question Breakdown -->
                <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-list-check me-2 text-primary"></i> Answer Breakdown</h5>
                
                <?php
                $rev_desc_items = [];
                $rev_mcq_items  = [];
                if (!empty($submission_review['items'])) {
                    foreach ($submission_review['items'] as $item) {
                        if (($item['question_type'] ?? 'mcq') === 'descriptive') {
                            $rev_desc_items[] = $item;
                        } else {
                            $rev_mcq_items[]  = $item;
                        }
                    }
                }
                ?>

                <!-- Fallback when no questions are available -->
                <?php if (empty($rev_desc_items) && empty($rev_mcq_items)): ?>
                    <div class="alert alert-light border rounded-4 text-center py-4 text-muted mb-4">
                        <i class="bi bi-journal-x fs-2 d-block mb-2 text-secondary"></i>
                        Detailed question responses are not available for this record.
                    </div>
                <?php endif; ?>

                <!-- Descriptive Section -->
                <?php if (!empty($rev_desc_items)): ?>
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="bi bi-file-earmark-text text-primary me-1"></i> Descriptive / Written Answers (<?php echo count($rev_desc_items); ?>)
                            </h6>
                            <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">Instructor Evaluated</span>
                        </div>
                        <?php foreach ($rev_desc_items as $d_idx => $d_item): 
                            $d_num   = $d_idx + 1;
                            $d_marks = $d_item['marks'] ?? null;
                        ?>
                            <div class="card mb-3 border rounded-3 p-3 bg-white shadow-sm">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="fw-bold text-dark">Q<?php echo $d_num; ?>: <?php echo htmlspecialchars($d_item['question_text'] ?? ''); ?></span>
                                    <span class="badge bg-light text-dark border">Descriptive</span>
                                </div>
                                <div class="p-3 bg-light rounded-3 border mb-2">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <small class="text-muted fw-bold"><i class="bi bi-pencil-square me-1"></i>Your Written Response:</small>
                                        <?php if (!empty($d_item['user_ans'])): 
                                            $stu_words = str_word_count(strip_tags((string)$d_item['user_ans']));
                                            $stu_chars = mb_strlen((string)$d_item['user_ans']);
                                        ?>
                                            <span class="badge bg-white text-muted border px-2 py-1 small">
                                                <?php echo $stu_words; ?> words &bull; <?php echo $stu_chars; ?> chars
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="p-3 bg-white rounded-2 border text-dark fs-6" style="white-space: pre-wrap; line-height: 1.65; min-height: 50px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"><?php echo !empty($d_item['user_ans']) ? htmlspecialchars($d_item['user_ans']) : '<em class="text-muted">No answer submitted.</em>'; ?></div>
                                </div>
                                <?php if ($d_marks !== null && $d_marks !== ''): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold">
                                            <i class="bi bi-award me-1"></i> Marks Awarded: <?php echo rtrim(rtrim(number_format((float)$d_marks, 2), '0'), '.'); ?> marks
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- MCQ Section -->
                <?php if (!empty($rev_mcq_items)): ?>
                    <div class="mb-4">
                        <?php if (!empty($rev_desc_items)): ?>
                            <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                <h6 class="fw-bold text-dark mb-0">
                                    <i class="bi bi-ui-checks text-primary me-1"></i> Multiple Choice Questions (<?php echo count($rev_mcq_items); ?>)
                                </h6>
                            </div>
                        <?php endif; ?>
                        <div class="accordion mb-4" id="reviewAccordion">
                            <?php foreach ($rev_mcq_items as $m_idx => $item): 
                                $num     = $m_idx + 1;
                                $opt_map = [
                                    'A' => (string)($item['option_a'] ?? ''), 
                                    'B' => (string)($item['option_b'] ?? ''), 
                                    'C' => (string)($item['option_c'] ?? ''), 
                                    'D' => (string)($item['option_d'] ?? '')
                                ];
                                $user_ans_val = (string)($item['user_ans'] ?? '');
                                $corr_ans_val = (string)($item['correct_ans'] ?? '');
                                $is_correct   = !empty($item['is_correct']);
                            ?>
                                <div class="accordion-item border rounded-3 mb-2 overflow-hidden">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button <?php echo $is_correct ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $num; ?>">
                                            <div class="d-flex align-items-center gap-2 w-100 me-3">
                                                <span class="fw-bold">Q<?php echo $num; ?>:</span>
                                                <span class="text-truncate flex-grow-1 text-dark fw-semibold"><?php echo htmlspecialchars($item['question_text'] ?? ''); ?></span>
                                                <?php if ($is_correct): ?>
                                                    <span class="badge bg-success rounded-pill px-3 py-1">Correct</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger rounded-pill px-3 py-1">Incorrect</span>
                                                <?php endif; ?>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="collapse<?php echo $num; ?>" class="accordion-collapse collapse show" data-bs-parent="#reviewAccordion">
                                        <div class="accordion-body bg-white">
                                            <p class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($item['question_text'] ?? ''); ?></p>
                                            <div class="small mb-2">
                                                <strong>Your Answer:</strong> 
                                                <?php if ($user_ans_val !== ''): 
                                                    $user_ans_text = $opt_map[$user_ans_val] ?? '';
                                                ?>
                                                    <span class="<?php echo $is_correct ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                                                        Option <?php echo htmlspecialchars($user_ans_val); ?><?php echo $user_ans_text !== '' ? ': ' . htmlspecialchars($user_ans_text) : ''; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted fst-italic">Not answered / Not recorded</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!$is_correct && $corr_ans_val !== ''): 
                                                $corr_ans_text = $opt_map[$corr_ans_val] ?? '';
                                            ?>
                                                <div class="small text-success fw-bold">
                                                    <i class="bi bi-check-circle-fill me-1"></i> Correct Answer: Option <?php echo htmlspecialchars($corr_ans_val); ?><?php echo $corr_ans_text !== '' ? ': ' . htmlspecialchars($corr_ans_text) : ''; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="text-center d-flex justify-content-center gap-3 flex-wrap">
                    <a href="result.php" class="btn btn-outline-secondary btn-lg px-4 rounded-pill shadow-sm fw-bold">
                        <i class="bi bi-clock-history me-1"></i> All Results
                    </a>
                    <a href="dashboard.php" class="btn btn-primary btn-lg px-5 rounded-pill shadow-sm fw-bold">
                        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Past Results History Table -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-clock-history text-primary me-2"></i> My Exam History</h4>
        <small class="text-muted">A record of all your exam attempts.</small>
    </div>
    <a href="dashboard.php" class="btn btn-outline-primary fw-semibold">
        <i class="bi bi-arrow-left"></i> Dashboard
    </a>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <!-- Top Horizontal Scrollbar Slider -->
    <div class="table-scroll-top-container d-none" id="studentResultsTableScrollTop">
        <div class="table-scroll-top-inner" id="studentResultsTableScrollTopInner"></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" id="studentResultsTableResponsive">
            <table class="table custom-table table-sticky-actions align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Exam Title</th>
                        <th>Score</th>
                        <th>Time Taken</th>
                        <th>Status</th>
                        <th>Instructor Feedback</th>
                        <th class="text-end">Attempted On</th>
                        <th class="pe-4 text-center">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!empty($past_results)):
                        $i = 1;
                        foreach ($past_results as $r):
                            $is_pending = (($r['status'] ?? 'published') === 'pending');
                            $r_q_count  = (int)($r['q_count'] ?? 0);
                    ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                            <td><strong class="text-dark"><?php echo htmlspecialchars($r['exam_title']); ?></strong></td>
                            <td>
                                <?php 
                                $out_of = (float)($r['exam_total_marks'] ?? 0);
                                $display_total = ($out_of > 0) ? rtrim(rtrim(number_format($out_of, 2), '0'), '.') : '';
                                ?>
                                <?php if ($is_pending): ?>
                                    <span class="badge bg-warning text-dark border rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-hourglass-split me-1"></i> Pending Review
                                    </span>
                                <?php else: ?>
                                    <span class="fw-bold fs-6 text-primary">
                                        <?php echo rtrim(rtrim(number_format((float)$r['score'], 2), '0'), '.'); ?><?php echo $display_total ? " / $display_total" : ''; ?> marks
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?php
                                $tt = (int)($r['time_taken_seconds'] ?? 0);
                                if ($tt > 0) {
                                    $mm = floor($tt / 60);
                                    $ss = $tt % 60;
                                    echo "<i class='bi bi-stopwatch me-1'></i>{$mm}m {$ss}s";
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($is_pending): ?>
                                    <span class="badge bg-warning text-dark border rounded-pill px-3 py-1">
                                        <i class="bi bi-hourglass-split me-1"></i> Under Review
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-check-circle me-1"></i> Completed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['admin_feedback'])): ?>
                                    <span class="badge bg-info-subtle text-dark border border-info-subtle text-wrap text-start p-2">
                                        <i class="bi bi-chat-left-text me-1 text-primary"></i> <?php echo htmlspecialchars($r['admin_feedback']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-muted small"><?php echo date('d M Y, h:i A', strtotime($r['attempted_at'])); ?></td>
                            <td class="pe-4 text-center">
                                <?php if (!$is_pending): ?>
                                    <a href="result.php?view_result_id=<?php echo (int)$r['id']; ?>#resultReviewCard"
                                       class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                                        <i class="bi bi-eye me-1"></i> View
                                    </a>
                                <?php else: ?>
                                    <a href="result.php?view_result_id=<?php echo (int)$r['id']; ?>#resultReviewCard"
                                       class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold" title="View Review Status">
                                        <i class="bi bi-hourglass-split me-1"></i> Status
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php
                        endforeach;
                    else:
                    ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i> You have not attempted any exam yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const topScroll = document.getElementById('studentResultsTableScrollTop');
    const tableCont = document.getElementById('studentResultsTableResponsive');
    if (topScroll && tableCont) {
        const topInner = document.getElementById('studentResultsTableScrollTopInner');
        const table = tableCont.querySelector('table');

        function updateScrollWidth() {
            if (!table) return;
            const scrollW = table.scrollWidth;
            const clientW = tableCont.clientWidth;
            if (scrollW > clientW + 5) {
                topScroll.classList.remove('d-none');
                if (topInner) topInner.style.width = scrollW + 'px';
            } else {
                topScroll.classList.add('d-none');
            }
        }

        let isSyncing = false;
        topScroll.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                tableCont.scrollLeft = topScroll.scrollLeft;
                requestAnimationFrame(function() { isSyncing = false; });
            }
        });
        tableCont.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                topScroll.scrollLeft = tableCont.scrollLeft;
                requestAnimationFrame(function() { isSyncing = false; });
            }
        });

        window.addEventListener('resize', updateScrollWidth);
        updateScrollWidth();
        setTimeout(updateScrollWidth, 300);
    }
});

// Data isolation: clear submitted exam drafts from localStorage
try {
    <?php if (!empty($student_id)): ?>
        <?php if (!empty($exam_id)): ?>
            localStorage.removeItem('exam_draft_s<?php echo (int)$student_id; ?>_e<?php echo (int)$exam_id; ?>');
            localStorage.removeItem('exam_draft_<?php echo (int)$exam_id; ?>');
        <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($submission_review)): ?>
        Object.keys(localStorage).forEach(function(k) {
            if (k.indexOf('exam_draft_') === 0) localStorage.removeItem(k);
        });
    <?php endif; ?>
} catch(e) {}
</script>

<?php include '../includes/footer.php'; ?>
