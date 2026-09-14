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
            header("Location: result.php");
            exit;
        }

        // Consume token
        unset($_SESSION[$submit_token_key]);

        // Phase 1: also check server-side session hasn't been submitted already
        $ss = mysqli_prepare($conn,
            "SELECT submitted FROM exam_sessions WHERE student_id=? AND exam_id=? LIMIT 1");
        if ($ss) {
            mysqli_stmt_bind_param($ss, "ii", $student_id, $exam_id);
            mysqli_stmt_execute($ss);
            $ss_res = mysqli_stmt_get_result($ss);
            $ss_row = $ss_res ? mysqli_fetch_assoc($ss_res) : null;
            mysqli_stmt_close($ss);
            if ($ss_row && $ss_row['submitted']) {
                $_SESSION['flash_already_submitted'] = "Your exam was already submitted.";
                header("Location: result.php");
                exit;
            }
        }

        // Fetch exam
        $stmt = mysqli_prepare($conn, "SELECT * FROM exams WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $exam_id);
        mysqli_stmt_execute($stmt);
        $res_exam = mysqli_stmt_get_result($stmt);
        $exam     = ($res_exam ? mysqli_fetch_assoc($res_exam) : null);
        mysqli_stmt_close($stmt);

        if ($exam) {
            $questions_res   = mysqli_query($conn, "SELECT * FROM questions WHERE exam_id = " . (int)$exam_id . " ORDER BY id ASC");
            $total_questions = 0;
            $mcq_count       = 0;
            $desc_count      = 0;
            $correct_count   = 0;
            $recorded_answers = [];

            // Phase 1: get option_maps from session (shuffled option labels)
            $option_maps = $_SESSION['option_maps_' . $exam_id] ?? [];

            $user_mcq_answers  = $_POST['answer'] ?? [];
            $user_desc_answers = $_POST['descriptive_answer'] ?? [];

            // Phase 1: fallback — load from draft_answers if POST is empty (e.g. auto-submit)
            if (empty($user_mcq_answers) && empty($user_desc_answers)) {
                $df = mysqli_prepare($conn,
                    "SELECT question_id, answer FROM draft_answers WHERE student_id=? AND exam_id=?");
                if ($df) {
                    mysqli_stmt_bind_param($df, "ii", $student_id, $exam_id);
                    mysqli_stmt_execute($df);
                    $df_res = mysqli_stmt_get_result($df);
                    while ($dr = mysqli_fetch_assoc($df_res)) {
                        $qid = (int)$dr['question_id'];
                        $ans = $dr['answer'];
                        // Determine if MCQ (single letter) or descriptive
                        if (in_array(strtoupper($ans), ['A','B','C','D']) && strlen($ans) <= 1) {
                            $user_mcq_answers[$qid]  = strtoupper($ans);
                        } else {
                            $user_desc_answers[$qid] = $ans;
                        }
                    }
                    mysqli_stmt_close($df);
                }
            }

            if ($questions_res) {
                while ($q = mysqli_fetch_assoc($questions_res)) {
                    $total_questions++;
                    $q_id   = $q['id'];
                    $q_type = $q['question_type'] ?? 'mcq';

                    if ($q_type === 'descriptive') {
                        $desc_count++;
                        $desc_text = trim($user_desc_answers[$q_id] ?? '');
                        if (strlen($desc_text) > 5000) $desc_text = substr($desc_text, 0, 5000);
                        $recorded_answers[] = [
                            'question_id'   => $q_id,
                            'question_type' => 'descriptive',
                            'question_text' => $q['question_text'],
                            'user_ans'      => $desc_text,
                            'is_correct'    => null,
                            'marks'         => 0
                        ];
                    } else {
                        $mcq_count++;
                        $raw_ans  = strtoupper(trim($user_mcq_answers[$q_id] ?? ''));
                        $user_ans = in_array($raw_ans, ['A','B','C','D']) ? $raw_ans : null;

                        // Phase 1: use original correct_option (pre-shuffle) OR option_map if available
                        $original_correct = strtoupper(trim($q['correct_option'] ?? ''));
                        if (!empty($option_maps[$q_id])) {
                            // The shuffled correct label is stored in option_maps
                            $shuffled_correct = $option_maps[$q_id]['correct'] ?? $original_correct;
                        } else {
                            $shuffled_correct = $original_correct;
                        }
                        $is_correct = ($user_ans !== null && $user_ans === $shuffled_correct);
                        if ($is_correct) $correct_count++;

                        $recorded_answers[] = [
                            'question_id'   => $q_id,
                            'question_type' => 'mcq',
                            'question_text' => $q['question_text'],
                            'option_a'      => $q['option_a'],
                            'option_b'      => $q['option_b'],
                            'option_c'      => $q['option_c'],
                            'option_d'      => $q['option_d'],
                            'user_ans'      => $user_ans,
                            'correct_ans'   => $original_correct,
                            'is_correct'    => $is_correct ? 1 : 0,
                            'marks'         => $is_correct ? 1 : 0
                        ];
                    }
                }
            }

            // Clear option maps from session after use
            unset($_SESSION['option_maps_' . $exam_id]);

            $exam_mode = $exam['result_mode'] ?? 'instant';
            if ($desc_count > 0 || $exam_mode === 'pending') {
                $status = 'pending';
            } else {
                $status = 'published';
            }

            $score_percentage = ($total_questions > 0) ? round(($correct_count / $total_questions) * 100) : 0;

            // Save result
            $result_id = 0;
            $stmt = mysqli_prepare($conn, "INSERT INTO results (student_id, exam_id, score, status, attempted_at) VALUES (?, ?, ?, ?, NOW())");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "iiis", $student_id, $exam_id, $score_percentage, $status);
                if (mysqli_stmt_execute($stmt)) {
                    $result_id = (int)mysqli_insert_id($conn);
                }
                mysqli_stmt_close($stmt);
            }

            if ($result_id <= 0) {
                $error_msg = "Failed to save your exam result. Please contact your instructor.";
            } else {
                // Save individual answers
                foreach ($recorded_answers as $ans) {
                    $qid     = (int)$ans['question_id'];
                    $u_ans   = $ans['user_ans'] ?? '';
                    $is_c    = $ans['is_correct'];
                    $m_award = (float)$ans['marks'];

                    $stmt = mysqli_prepare($conn, "INSERT INTO student_answers (result_id, student_id, exam_id, question_id, user_answer, is_correct, marks_awarded) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $is_c_bind = ($is_c === null) ? null : (int)$is_c;
                        mysqli_stmt_bind_param($stmt, "iiiisid", $result_id, $student_id, $exam_id, $qid, $u_ans, $is_c_bind, $m_award);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }

                // Phase 1: Mark exam session as submitted + record time taken
                $upd = mysqli_prepare($conn,
                    "UPDATE exam_sessions SET submitted=1,
                     time_taken_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
                     WHERE student_id=? AND exam_id=?");
                if ($upd) {
                    mysqli_stmt_bind_param($upd, "ii", $student_id, $exam_id);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                }

                // Phase 1: Clean up draft answers (no longer needed)
                $del = mysqli_prepare($conn,
                    "DELETE FROM draft_answers WHERE student_id=? AND exam_id=?");
                if ($del) {
                    mysqli_stmt_bind_param($del, "ii", $student_id, $exam_id);
                    mysqli_stmt_execute($del);
                    mysqli_stmt_close($del);
                }

                // PRG redirect
                $_SESSION['submission_review'] = [
                    'result_id'       => $result_id,
                    'exam_title'      => $exam['title'],
                    'status'          => $status,
                    'has_descriptive' => $desc_count > 0,
                    'desc_count'      => $desc_count,
                    'total'           => $total_questions,
                    'correct'         => $correct_count,
                    'wrong'           => $total_questions - $correct_count,
                    'score'           => $score_percentage,
                    'passed'          => $score_percentage >= 50,
                    'items'           => $recorded_answers
                ];
                header("Location: result.php");
                exit;
            }
        } else {
            $error_msg = "Invalid exam submission.";
        }
    }
}


// Retrieve and clear the submission review from session (after PRG redirect)
if (!empty($_SESSION['submission_review'])) {
    $submission_review = $_SESSION['submission_review'];
    unset($_SESSION['submission_review']);
}

// Flash message for already-submitted
$already_submitted_msg = "";
if (!empty($_SESSION['flash_already_submitted'])) {
    $already_submitted_msg = $_SESSION['flash_already_submitted'];
    unset($_SESSION['flash_already_submitted']);
}

// Fetch all past results for this student with time taken from exam_sessions
$stmt = mysqli_prepare($conn, "SELECT r.*, e.title AS exam_title,
                                es.time_taken_seconds
                                FROM results r
                                JOIN exams e ON r.exam_id = e.id
                                LEFT JOIN exam_sessions es
                                    ON es.student_id = r.student_id AND es.exam_id = r.exam_id
                                WHERE r.student_id = ?
                                ORDER BY r.attempted_at DESC");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$past_results_res = mysqli_stmt_get_result($stmt);
$past_results     = [];
if ($past_results_res) {
    while ($row = mysqli_fetch_assoc($past_results_res)) {
        $past_results[] = $row;
    }
}
mysqli_stmt_close($stmt);
?>

<?php if (!empty($already_submitted_msg)): ?>
    <div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-info-circle-fill me-2"></i> <?php echo htmlspecialchars($already_submitted_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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
        <div class="card shadow-lg border-0 rounded-4 mb-5 overflow-hidden">
            <div class="card-header p-4 text-center text-white bg-warning bg-gradient">
                <div class="mb-2">
                    <i class="bi bi-hourglass-split fs-1"></i>
                </div>
                <h2 class="fw-extrabold mb-1">Exam Submitted - Result Pending Review ⏳</h2>
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

                <div class="text-center">
                    <a href="dashboard.php" class="btn btn-primary btn-lg px-5 rounded-pill shadow-sm fw-bold">
                        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Instant Submission Scorecard Banner -->
        <div class="card shadow-lg border-0 rounded-4 mb-5 overflow-hidden">
            <div class="card-header p-4 text-center text-white <?php echo $submission_review['passed'] ? 'bg-success' : 'bg-danger'; ?>">
                <div class="mb-2">
                    <i class="bi <?php echo $submission_review['passed'] ? 'bi-trophy-fill' : 'bi-exclamation-octagon-fill'; ?> fs-1"></i>
                </div>
                <h2 class="fw-extrabold mb-1"><?php echo $submission_review['passed'] ? 'Congratulations! Exam Passed 🎉' : 'Exam Completed'; ?></h2>
                <p class="mb-0 text-white opacity-75">Result summary for <strong><?php echo htmlspecialchars($submission_review['exam_title']); ?></strong></p>
            </div>

            <div class="card-body p-4 p-md-5">
                <div class="row g-4 text-center justify-content-center mb-4">
                    <div class="col-6 col-md-3">
                        <div class="bg-light p-3 rounded-4 border">
                            <small class="text-muted fw-semibold">Your Score</small>
                            <h2 class="fw-extrabold <?php echo $submission_review['passed'] ? 'text-success' : 'text-danger'; ?> mb-0">
                                <?php echo $submission_review['score']; ?>%
                            </h2>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-light p-3 rounded-4 border">
                            <small class="text-muted fw-semibold">Correct Answers</small>
                            <h2 class="fw-extrabold text-success mb-0">
                                <?php echo $submission_review['correct']; ?> / <?php echo $submission_review['total']; ?>
                            </h2>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-light p-3 rounded-4 border">
                            <small class="text-muted fw-semibold">Wrong / Unanswered</small>
                            <h2 class="fw-extrabold text-danger mb-0">
                                <?php echo $submission_review['wrong']; ?>
                            </h2>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-light p-3 rounded-4 border">
                            <small class="text-muted fw-semibold">Result Status</small>
                            <div class="mt-1">
                                <?php if ($submission_review['passed']): ?>
                                    <span class="badge bg-success fs-6 px-3 py-2 rounded-pill">PASSED</span>
                                <?php else: ?>
                                    <span class="badge bg-danger fs-6 px-3 py-2 rounded-pill">FAILED</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Question Breakdown -->
                <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-list-check me-2 text-primary"></i> Answer Breakdown</h5>
                
                <div class="accordion mb-4" id="reviewAccordion">
                    <?php foreach ($submission_review['items'] as $idx => $item): 
                        $num     = $idx + 1;
                        $opt_map = [
                            'A' => $item['option_a'] ?? '', 
                            'B' => $item['option_b'] ?? '', 
                            'C' => $item['option_c'] ?? '', 
                            'D' => $item['option_d'] ?? ''
                        ];
                    ?>
                        <div class="accordion-item border rounded-3 mb-2 overflow-hidden">
                            <h2 class="accordion-header">
                                <button class="accordion-button <?php echo $item['is_correct'] ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $num; ?>">
                                    <div class="d-flex align-items-center gap-2 w-100 me-3">
                                        <span class="fw-bold">Q<?php echo $num; ?>:</span>
                                        <span class="text-truncate flex-grow-1 text-dark fw-semibold"><?php echo htmlspecialchars($item['question_text']); ?></span>
                                        <?php if ($item['is_correct']): ?>
                                            <span class="badge bg-success rounded-pill px-3 py-1">Correct</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger rounded-pill px-3 py-1">Incorrect</span>
                                        <?php endif; ?>
                                    </div>
                                </button>
                            </h2>
                            <div id="collapse<?php echo $num; ?>" class="accordion-collapse collapse show" data-bs-parent="#reviewAccordion">
                                <div class="accordion-body bg-white">
                                    <p class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($item['question_text']); ?></p>
                                    <div class="small mb-2">
                                        <strong>Your Answer:</strong> 
                                        <?php if ($item['user_ans']): ?>
                                            <span class="<?php echo $item['is_correct'] ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                                                Option <?php echo htmlspecialchars($item['user_ans']); ?>: <?php echo htmlspecialchars($opt_map[$item['user_ans']] ?? ''); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">Not answered</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!$item['is_correct']): ?>
                                        <div class="small text-success fw-bold">
                                            <i class="bi bi-check-circle-fill me-1"></i> Correct Answer: Option <?php echo htmlspecialchars($item['correct_ans']); ?>: <?php echo htmlspecialchars($opt_map[$item['correct_ans']] ?? ''); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-center">
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
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Exam Title</th>
                        <th>Score</th>
                        <th>Time Taken</th>
                        <th>Status</th>
                        <th>Instructor Feedback</th>
                        <th class="pe-4 text-end">Attempted On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!empty($past_results)):
                        $i = 1;
                        foreach ($past_results as $r):
                            $is_pending = (($r['status'] ?? 'published') === 'pending');
                            $passed     = $r['score'] >= 50;
                    ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                            <td><strong class="text-dark"><?php echo htmlspecialchars($r['exam_title']); ?></strong></td>
                            <td>
                                <?php if ($is_pending): ?>
                                    <span class="text-muted fst-italic"><i class="bi bi-hourglass me-1"></i>Pending</span>
                                <?php else: ?>
                                    <span class="fw-extrabold fs-6 <?php echo $passed ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $r['score']; ?>%
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
                                <?php elseif ($passed): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-check-circle-fill me-1"></i> Passed
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-x-circle-fill me-1"></i> Failed
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
                            <td class="pe-4 text-end text-muted small"><?php echo date('d M Y, h:i A', strtotime($r['attempted_at'])); ?></td>
                        </tr>
                    <?php
                        endforeach;
                    else:
                    ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i> You have not attempted any exam yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
