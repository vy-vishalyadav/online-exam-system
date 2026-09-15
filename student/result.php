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

            // Score = correct MCQs ÷ total MCQs × 100
            // (descriptive questions are scored later by admin)
            $score_percentage = ($mcq_count > 0) ? round(($correct_count / $mcq_count) * 100) : 0;

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


// ── Handle timeout auto-submit (GET redirect from exam.php) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['timeout']) && isset($_GET['exam_id'])) {
    $to_exam_id = (int)$_GET['exam_id'];
    if ($to_exam_id > 0) {
        // Check not already submitted
        $ts = mysqli_prepare($conn, "SELECT submitted FROM exam_sessions WHERE student_id=? AND exam_id=? LIMIT 1");
        mysqli_stmt_bind_param($ts, "ii", $student_id, $to_exam_id);
        mysqli_stmt_execute($ts);
        $ts_row = mysqli_fetch_assoc(mysqli_stmt_get_result($ts));
        mysqli_stmt_close($ts);

        if ($ts_row && !$ts_row['submitted']) {
            // Load draft answers and score them
            $to_exam_stmt = mysqli_prepare($conn, "SELECT * FROM exams WHERE id=? LIMIT 1");
            mysqli_stmt_bind_param($to_exam_stmt, "i", $to_exam_id);
            mysqli_stmt_execute($to_exam_stmt);
            $to_exam = mysqli_fetch_assoc(mysqli_stmt_get_result($to_exam_stmt));
            mysqli_stmt_close($to_exam_stmt);

            if ($to_exam) {
                $df = mysqli_prepare($conn, "SELECT question_id, answer FROM draft_answers WHERE student_id=? AND exam_id=?");
                mysqli_stmt_bind_param($df, "ii", $student_id, $to_exam_id);
                mysqli_stmt_execute($df);
                $df_res = mysqli_stmt_get_result($df);
                $drafts = [];
                while ($dr = mysqli_fetch_assoc($df_res)) $drafts[(int)$dr['question_id']] = $dr['answer'];
                mysqli_stmt_close($df);

                $option_maps = $_SESSION['option_maps_' . $to_exam_id] ?? [];
                $qs_res = mysqli_query($conn, "SELECT * FROM questions WHERE exam_id=" . $to_exam_id . " ORDER BY id ASC");
                $total_q = $correct_c = $desc_c = $mcq_c = 0;
                $rec_answers = [];
                while ($q = mysqli_fetch_assoc($qs_res)) {
                    $total_q++;
                    $q_type = $q['question_type'] ?? 'mcq';
                    $ans    = $drafts[$q['id']] ?? '';
                    if ($q_type === 'descriptive') {
                        $desc_c++;
                        $rec_answers[] = ['question_id'=>$q['id'],'question_type'=>'descriptive','question_text'=>$q['question_text'],'user_ans'=>$ans,'is_correct'=>null,'marks'=>0];
                    } else {
                        $mcq_c++;
                        $raw = strtoupper(trim($ans));
                        $user_a = in_array($raw, ['A','B','C','D']) ? $raw : null;
                        $orig_correct = strtoupper($q['correct_option'] ?? 'A');
                        $shuffled_correct = !empty($option_maps[$q['id']]) ? ($option_maps[$q['id']]['correct'] ?? $orig_correct) : $orig_correct;
                        $is_c = ($user_a !== null && $user_a === $shuffled_correct);
                        if ($is_c) $correct_c++;
                        $rec_answers[] = ['question_id'=>$q['id'],'question_type'=>'mcq','question_text'=>$q['question_text'],'option_a'=>$q['option_a'],'option_b'=>$q['option_b'],'option_c'=>$q['option_c'],'option_d'=>$q['option_d'],'user_ans'=>$user_a,'correct_ans'=>$orig_correct,'is_correct'=>$is_c?1:0,'marks'=>$is_c?1:0];
                    }
                }
                unset($_SESSION['option_maps_' . $to_exam_id]);

                $status_to = ($desc_c > 0 || ($to_exam['result_mode'] ?? 'instant') === 'pending') ? 'pending' : 'published';
                // Score = correct MCQs ÷ total MCQs × 100 (matches POST handler formula at line 164)
                // Descriptive questions are graded later by admin and must not dilute the MCQ score
                $score_to  = $mcq_c > 0 ? round(($correct_c / $mcq_c) * 100) : 0;

                $ins_r = mysqli_prepare($conn, "INSERT INTO results (student_id, exam_id, score, status, attempted_at) VALUES (?,?,?,?,NOW())");
                mysqli_stmt_bind_param($ins_r, "iiis", $student_id, $to_exam_id, $score_to, $status_to);
                $new_rid = 0;
                if (mysqli_stmt_execute($ins_r)) { $new_rid = (int)mysqli_insert_id($conn); }
                mysqli_stmt_close($ins_r);

                if ($new_rid) {
                    foreach ($rec_answers as $ra) {
                        $sa = mysqli_prepare($conn, "INSERT INTO student_answers (result_id,student_id,exam_id,question_id,user_answer,is_correct,marks_awarded) VALUES (?,?,?,?,?,?,?)");
                        if ($sa) {
                            $ic = $ra['is_correct']; $ma = (float)$ra['marks'];
                            mysqli_stmt_bind_param($sa, "iiiisid", $new_rid, $student_id, $to_exam_id, $ra['question_id'], $ra['user_ans'], $ic, $ma);
                            mysqli_stmt_execute($sa); mysqli_stmt_close($sa);
                        }
                    }
                    $upd = mysqli_prepare($conn, "UPDATE exam_sessions SET submitted=1, time_taken_seconds=TIMESTAMPDIFF(SECOND,started_at,NOW()) WHERE student_id=? AND exam_id=?");
                    mysqli_stmt_bind_param($upd, "ii", $student_id, $to_exam_id);
                    mysqli_stmt_execute($upd); mysqli_stmt_close($upd);

                    $cdel = mysqli_prepare($conn, "DELETE FROM draft_answers WHERE student_id=? AND exam_id=?");
                    mysqli_stmt_bind_param($cdel, "ii", $student_id, $to_exam_id);
                    mysqli_stmt_execute($cdel); mysqli_stmt_close($cdel);

                    $_SESSION['submission_review'] = [
                        'result_id'=>$new_rid,'exam_title'=>$to_exam['title'],'status'=>$status_to,
                        'has_descriptive'=>$desc_c>0,'desc_count'=>$desc_c,'total'=>$total_q,
                        'correct'=>$correct_c,'wrong'=>$total_q-$correct_c,'score'=>$score_to,
                        'passed'=>$score_to>=50,'items'=>$rec_answers,'timed_out'=>true
                    ];
                }
            }
        }
        header("Location: result.php"); exit;
    }
}

// Retrieve and clear the submission review from session (after PRG redirect)
if (!empty($_SESSION['submission_review'])) {
    $submission_review = $_SESSION['submission_review'];
    unset($_SESSION['submission_review']);
}

// ── Handle view specific past result by exam_id (from dashboard "View Result" button) ──
if (empty($submission_review) && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['view_exam_id'])) {
    $view_exam_id = (int)$_GET['view_exam_id'];
    if ($view_exam_id > 0) {
        $vr_stmt = mysqli_prepare($conn,
            "SELECT r.*, e.title AS exam_title
             FROM results r
             JOIN exams e ON r.exam_id = e.id
             WHERE r.student_id = ? AND r.exam_id = ? AND r.status = 'published'
             ORDER BY r.attempted_at DESC LIMIT 1");
        if ($vr_stmt) {
            mysqli_stmt_bind_param($vr_stmt, "ii", $student_id, $view_exam_id);
            mysqli_stmt_execute($vr_stmt);
            $vr = mysqli_fetch_assoc(mysqli_stmt_get_result($vr_stmt));
            mysqli_stmt_close($vr_stmt);

            if ($vr) {
                $sa_stmt = mysqli_prepare($conn,
                    "SELECT sa.*, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d,
                            q.correct_option, q.question_type
                     FROM student_answers sa
                     JOIN questions q ON sa.question_id = q.id
                     WHERE sa.result_id = ?
                     ORDER BY sa.id ASC");
                if ($sa_stmt) {
                    mysqli_stmt_bind_param($sa_stmt, "i", (int)$vr['id']);
                    mysqli_stmt_execute($sa_stmt);
                    $sa_res     = mysqli_stmt_get_result($sa_stmt);
                    $view_items = []; $view_correct = 0; $view_total = 0; $view_desc = 0;
                    while ($sa_row = mysqli_fetch_assoc($sa_res)) {
                        $view_total++;
                        $q_type = $sa_row['question_type'] ?? 'mcq';
                        if ($q_type === 'descriptive') {
                            $view_desc++;
                            $view_items[] = [
                                'question_id'   => $sa_row['question_id'],
                                'question_type' => 'descriptive',
                                'question_text' => $sa_row['question_text'],
                                'user_ans'      => $sa_row['user_answer'],
                                'is_correct'    => null,
                                'marks'         => $sa_row['marks_awarded'],
                            ];
                        } else {
                            if ($sa_row['is_correct']) $view_correct++;
                            $view_items[] = [
                                'question_id'   => $sa_row['question_id'],
                                'question_type' => 'mcq',
                                'question_text' => $sa_row['question_text'],
                                'option_a'      => $sa_row['option_a'],
                                'option_b'      => $sa_row['option_b'],
                                'option_c'      => $sa_row['option_c'],
                                'option_d'      => $sa_row['option_d'],
                                'user_ans'      => $sa_row['user_answer'],
                                'correct_ans'   => $sa_row['correct_option'],
                                'is_correct'    => $sa_row['is_correct'],
                                'marks'         => $sa_row['marks_awarded'],
                            ];
                        }
                    }
                    mysqli_stmt_close($sa_stmt);
                    $submission_review = [
                        'result_id'       => $vr['id'],
                        'exam_title'      => $vr['exam_title'],
                        'status'          => 'published',
                        'has_descriptive' => $view_desc > 0,
                        'desc_count'      => $view_desc,
                        'total'           => $view_total,
                        'correct'         => $view_correct,
                        'wrong'           => $view_total - $view_correct,
                        'score'           => $vr['score'],
                        'passed'          => $vr['score'] >= 50,
                        'items'           => $view_items,
                        'is_historical'   => true,
                    ];
                }
            }
        }
    }
}

// Flash message for already-submitted
$already_submitted_msg = "";
if (!empty($_SESSION['flash_already_submitted'])) {
    $already_submitted_msg = $_SESSION['flash_already_submitted'];
    unset($_SESSION['flash_already_submitted']);
}

// Fetch all past results for this student with time taken and question count
$stmt = mysqli_prepare($conn, "SELECT r.*, e.title AS exam_title,
                                es.time_taken_seconds,
                                (SELECT COUNT(*) FROM questions q WHERE q.exam_id = r.exam_id) AS q_count
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
        <!-- Scorecard Banner -->
        <div class="card shadow-lg border-0 rounded-4 mb-5 overflow-hidden">
            <div class="card-header p-4 text-center text-white" style="background: linear-gradient(135deg, #1e40af, #3b82f6);">
                <div class="mb-2">
                    <i class="bi bi-journal-check fs-1"></i>
                </div>
                <h2 class="fw-extrabold mb-1">Exam Submitted ✓</h2>
                <p class="mb-0 text-white opacity-75">Result for <strong><?php echo htmlspecialchars($submission_review['exam_title']); ?></strong></p>
            </div>

            <div class="card-body p-4 p-md-5">
                <div class="row g-4 text-center justify-content-center mb-4">
                    <div class="col-6 col-md-3">
                        <div class="bg-primary-subtle p-3 rounded-4 border border-primary-subtle">
                            <small class="text-muted fw-semibold">Marks Obtained</small>
                            <h2 class="fw-extrabold text-primary mb-0">
                                <?php echo $submission_review['correct']; ?> / <?php echo $submission_review['total']; ?>
                            </h2>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="bg-success-subtle p-3 rounded-4 border border-success-subtle">
                            <small class="text-muted fw-semibold">Correct</small>
                            <h2 class="fw-extrabold text-success mb-0">
                                <?php echo $submission_review['correct']; ?>
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
                            $r_marks    = ($r_q_count > 0) ? round($r['score'] * $r_q_count / 100) : '—';
                    ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                            <td><strong class="text-dark"><?php echo htmlspecialchars($r['exam_title']); ?></strong></td>
                            <td>
                                <?php if ($is_pending): ?>
                                    <span class="text-muted fst-italic"><i class="bi bi-hourglass me-1"></i>Pending</span>
                                <?php else: ?>
                                    <span class="fw-extrabold fs-6 text-primary">
                                        <?php echo $r_marks; ?> / <?php echo $r_q_count ?: '—'; ?>
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
                                    <a href="result.php?view_exam_id=<?php echo (int)$r['exam_id']; ?>"
                                       class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                                        <i class="bi bi-eye me-1"></i> View
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
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

<?php include '../includes/footer.php'; ?>
