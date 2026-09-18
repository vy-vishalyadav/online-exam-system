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

$student_id = (int)$_SESSION['student_id'];
$exam_id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$exam_id) {
    header("Location: dashboard.php");
    exit;
}

// Fetch exam
$stmt = mysqli_prepare($conn, "SELECT * FROM exams WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $exam_id);
mysqli_stmt_execute($stmt);
$res_exam = mysqli_stmt_get_result($stmt);
$exam     = ($res_exam ? mysqli_fetch_assoc($res_exam) : null);
mysqli_stmt_close($stmt);

if (!$exam) {
    echo '<div class="alert alert-danger">Exam not found. <a href="dashboard.php">Return to Dashboard</a></div>';
    include '../includes/footer.php';
    exit;
}

// ── Assigned Class Enforcement ──────────────────────────────────────────────
$cls_chk = mysqli_prepare($conn, "SELECT 1 FROM exam_class_assignments WHERE exam_id = ? LIMIT 1");
if ($cls_chk) {
    mysqli_stmt_bind_param($cls_chk, "i", $exam_id);
    mysqli_stmt_execute($cls_chk);
    $has_class_restrictions = mysqli_fetch_assoc(mysqli_stmt_get_result($cls_chk));
    mysqli_stmt_close($cls_chk);

    if ($has_class_restrictions) {
        $stu_cls = mysqli_prepare($conn, "SELECT s.class_id, c.name as class_name FROM students s LEFT JOIN classes c ON s.class_id = c.id WHERE s.id = ? LIMIT 1");
        mysqli_stmt_bind_param($stu_cls, "i", $student_id);
        mysqli_stmt_execute($stu_cls);
        $stu_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stu_cls));
        mysqli_stmt_close($stu_cls);
        $s_cid = (int)($stu_row['class_id'] ?? 0);

        $elig_chk = mysqli_prepare($conn, "SELECT 1 FROM exam_class_assignments WHERE exam_id = ? AND class_id = ? LIMIT 1");
        mysqli_stmt_bind_param($elig_chk, "ii", $exam_id, $s_cid);
        mysqli_stmt_execute($elig_chk);
        $is_eligible = mysqli_fetch_assoc(mysqli_stmt_get_result($elig_chk));
        mysqli_stmt_close($elig_chk);

        if (!$is_eligible) {
            echo '<div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4">
                <i class="bi bi-shield-x fs-1 text-danger d-block mb-3"></i>
                <h5 class="fw-bold">Access Restricted</h5>
                <p class="text-muted">This exam is assigned to specific classes and is not available for your enrolled class.</p>
                <a href="dashboard.php" class="btn btn-outline-primary fw-bold px-4">Back to Dashboard</a>
            </div>';
            include '../includes/footer.php';
            exit;
        }
    }
}

// ── Schedule enforcement ─────────────────────────────────────────────────────
$now      = time();
$start_at = !empty($exam['start_at']) ? strtotime($exam['start_at']) : null;
$end_at   = !empty($exam['end_at'])   ? strtotime($exam['end_at'])   : null;

if ($start_at && ($now + 5) < $start_at) {
    // Exam hasn't opened yet
    echo '<div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4">
        <i class="bi bi-calendar-event fs-1 text-info d-block mb-3"></i>
        <h5 class="fw-bold">Exam Not Open Yet</h5>
        <p class="text-muted">This exam opens on <strong>' . date('d M Y \a\t h:i A', $start_at) . '</strong>.</p>
        <a href="dashboard.php" class="btn btn-outline-primary fw-bold px-4">Back to Dashboard</a>
    </div>';
    include '../includes/footer.php';
    exit;
}

if ($end_at && $now > $end_at) {
    // Exam window has closed
    echo '<div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4">
        <i class="bi bi-lock-fill fs-1 text-danger d-block mb-3"></i>
        <h5 class="fw-bold">Exam Window Closed</h5>
        <p class="text-muted">This exam closed on <strong>' . date('d M Y \a\t h:i A', $end_at) . '</strong>. No further submissions are accepted.</p>
        <a href="dashboard.php" class="btn btn-outline-secondary fw-bold px-4">Back to Dashboard</a>
    </div>';
    include '../includes/footer.php';
    exit;
}

$duration = (int)($exam['duration_minutes'] ?? 30);

// ── Phase 1: Server-side timer & question-order seed ─────────────────────────
$seed_string = md5($student_id . '_' . $exam_id . '_' . date('Ymd') . '_' . time());

// Check if a session already exists for this student+exam
$sess_check = mysqli_prepare($conn,
    "SELECT id, submitted, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed_seconds
     FROM exam_sessions WHERE student_id=? AND exam_id=? LIMIT 1");
mysqli_stmt_bind_param($sess_check, "ii", $student_id, $exam_id);
mysqli_stmt_execute($sess_check);
$sess_row = mysqli_fetch_assoc(mysqli_stmt_get_result($sess_check));
mysqli_stmt_close($sess_check);

$is_fresh_session = false;

// ── Single Attempt Enforcement (College Rule: No Retakes) ────────────────────
$chk_res = mysqli_prepare($conn, "SELECT id FROM results WHERE student_id=? AND exam_id=? LIMIT 1");
mysqli_stmt_bind_param($chk_res, "ii", $student_id, $exam_id);
mysqli_stmt_execute($chk_res);
$has_result = mysqli_fetch_assoc(mysqli_stmt_get_result($chk_res));
mysqli_stmt_close($chk_res);

if ($has_result || ($sess_row && (int)$sess_row['submitted'] === 1)) {
    // Student already submitted — redirect to scorecard
    header("Location: result.php?view_exam_id={$exam_id}&already_submitted=1#resultReviewCard");
    exit;
}

if (!$sess_row) {
    // First entry — create session record
    $ins = mysqli_prepare($conn,
        "INSERT INTO exam_sessions (student_id, exam_id, started_at, duration_minutes, question_seed, submitted)
         VALUES (?, ?, NOW(), ?, ?, 0)");
    mysqli_stmt_bind_param($ins, "iiis", $student_id, $exam_id, $duration, $seed_string);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
    $is_fresh_session = true;
} else {
    // Resuming active in-progress attempt
    $is_fresh_session = false;
}

// Fetch session using TIMESTAMPDIFF on MySQL's internal clock
$sess_stmt = mysqli_prepare($conn,
    "SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed_seconds, duration_minutes, question_seed, assigned_questions, submitted
     FROM exam_sessions WHERE student_id=? AND exam_id=? LIMIT 1");
mysqli_stmt_bind_param($sess_stmt, "ii", $student_id, $exam_id);
mysqli_stmt_execute($sess_stmt);
$session = mysqli_fetch_assoc(mysqli_stmt_get_result($sess_stmt));
mysqli_stmt_close($sess_stmt);

// Calculate remaining seconds (server-authoritative)
$dur_mins      = (int)($session['duration_minutes'] ?? 0);
if ($dur_mins <= 0) $dur_mins = (int)$duration;
$personal_total_sec = $dur_mins * 60;
$elapsed_sec        = max(0, (int)($session['elapsed_seconds'] ?? 0));
$personal_rem_sec   = max(0, $personal_total_sec - $elapsed_sec);

// Synchronous Schedule Window Calculation:
// If exam has end_at, EVERY student's timer is strictly bounded by end_at
$window_rem_sec = null;
if (!empty($exam['end_at'])) {
    $end_stmt = mysqli_prepare($conn, "SELECT TIMESTAMPDIFF(SECOND, NOW(), end_at) AS rem_sec FROM exams WHERE id=?");
    mysqli_stmt_bind_param($end_stmt, "i", $exam_id);
    mysqli_stmt_execute($end_stmt);
    $end_res = mysqli_fetch_assoc(mysqli_stmt_get_result($end_stmt));
    mysqli_stmt_close($end_stmt);
    $window_rem_sec = (int)($end_res['rem_sec'] ?? 0);
}

if ($window_rem_sec !== null) {
    // Strict college schedule: timer counts down to end_at
    $remaining_sec = max(0, min($personal_rem_sec, $window_rem_sec));
} else {
    $remaining_sec = $personal_rem_sec;
}

// If time is expired (with 5-second grace), auto-submit immediately
if ($remaining_sec <= 0 && ($elapsed_sec >= ($personal_total_sec + 5) || ($window_rem_sec !== null && $window_rem_sec <= -5))) {
    header("Location: result.php?timeout=1&exam_id={$exam_id}");
    exit;
}
if ($remaining_sec <= 0) {
    $remaining_sec = 1;
}

// ── Check existing violations for this student + exam ─────────────────────────
if ($is_fresh_session) {
    $del_v = mysqli_prepare($conn, "DELETE FROM exam_violations WHERE student_id=? AND exam_id=?");
    if ($del_v) {
        mysqli_stmt_bind_param($del_v, "ii", $student_id, $exam_id);
        mysqli_stmt_execute($del_v);
        mysqli_stmt_close($del_v);
    }
    $initial_violations = 0;
} else {
    // Deduplicate any rapid double-logged records for this session (e.g. from network retries or parallel sendBeacon/fetch)
    $dedup_v = mysqli_prepare($conn,
        "DELETE v1 FROM exam_violations v1
         INNER JOIN exam_violations v2
         WHERE v1.id > v2.id
           AND v1.student_id = ?
           AND v1.exam_id = ?
           AND v1.violation_type = v2.violation_type
           AND TIMESTAMPDIFF(SECOND, v2.occurred_at, v1.occurred_at) <= 3");
    if ($dedup_v) {
        mysqli_stmt_bind_param($dedup_v, "ii", $student_id, $exam_id);
        mysqli_stmt_execute($dedup_v);
        mysqli_stmt_close($dedup_v);
    }

    $v_stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) AS v_count FROM exam_violations 
         WHERE student_id=? AND exam_id=? AND violation_type IN ('tab_switch','fullscreen_exit','exit_exam')");
    mysqli_stmt_bind_param($v_stmt, "ii", $student_id, $exam_id);
    mysqli_stmt_execute($v_stmt);
    $v_row = mysqli_fetch_assoc(mysqli_stmt_get_result($v_stmt));
    $initial_violations = (int)($v_row['v_count'] ?? 0);
    mysqli_stmt_close($v_stmt);

    if ($initial_violations >= 3) {
        header("Location: result.php?timeout=1&exam_id={$exam_id}");
        exit;
    }
}


// ── Fetch questions (Anti-Cheat Question Pool & Locked Ordering) ─────────────
$questions = [];
$pool_limit = (int)($exam['questions_to_display'] ?? 0);

if (!empty($session['assigned_questions'])) {
    // Resuming session with already locked assigned questions
    $assigned_ids = array_filter(array_map('intval', explode(',', $session['assigned_questions'])));
    if (!empty($assigned_ids)) {
        $in_clause = implode(',', $assigned_ids);
        $q_res = mysqli_query($conn, "SELECT * FROM questions WHERE id IN ($in_clause) AND exam_id = " . (int)$exam_id);
        $q_map = [];
        if ($q_res) {
            while ($q = mysqli_fetch_assoc($q_res)) {
                $q_map[(int)$q['id']] = $q;
            }
        }
        foreach ($assigned_ids as $aid) {
            if (isset($q_map[$aid])) {
                $questions[] = $q_map[$aid];
            }
        }
    }
}

// If fresh session or assigned_questions wasn't set yet:
if (empty($questions)) {
    $q_stmt = mysqli_prepare($conn, "SELECT * FROM questions WHERE exam_id = ? ORDER BY id ASC");
    $all_pool = [];
    if ($q_stmt) {
        mysqli_stmt_bind_param($q_stmt, "i", $exam_id);
        mysqli_stmt_execute($q_stmt);
        $questions_res = mysqli_stmt_get_result($q_stmt);
        if ($questions_res) {
            while ($q = mysqli_fetch_assoc($questions_res)) {
                $all_pool[] = $q;
            }
        }
        mysqli_stmt_close($q_stmt);
    }
    $total_in_pool = count($all_pool);
    if ($total_in_pool > 0) {
        // Seed-based shuffle per student attempt
        $seed_int = hexdec(substr(md5($session['question_seed']), 0, 8));
        mt_srand($seed_int);
        $indices = range(0, $total_in_pool - 1);
        for ($i = $total_in_pool - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
        }
        $shuffled = [];
        foreach ($indices as $idx) {
            $shuffled[] = $all_pool[$idx];
        }

        // Apply Question Pool limit if configured
        if ($pool_limit > 0 && $pool_limit < count($shuffled)) {
            $questions = array_slice($shuffled, 0, $pool_limit);
        } else {
            $questions = $shuffled;
        }

        // Lock assigned questions into session for consistency across reloads
        $assigned_ids = array_map(function($q) { return (int)$q['id']; }, $questions);
        $assigned_str = implode(',', $assigned_ids);
        $upd_as = mysqli_prepare($conn, "UPDATE exam_sessions SET assigned_questions=? WHERE student_id=? AND exam_id=?");
        if ($upd_as) {
            mysqli_stmt_bind_param($upd_as, "sii", $assigned_str, $student_id, $exam_id);
            mysqli_stmt_execute($upd_as);
            mysqli_stmt_close($upd_as);
        }
        $session['assigned_questions'] = $assigned_str;
    }
}
$total_questions = count($questions);

// ── Phase 1: Shuffle MCQ options per question (seeded) ──────────────────────
$option_maps = []; // [q_id => ['A'=>'origA','B'=>'origC', ...]]
foreach ($questions as &$q) {
    if (($q['question_type'] ?? 'mcq') !== 'mcq') continue;

    $opts = [
        'A' => $q['option_a'],
        'B' => $q['option_b'],
        'C' => $q['option_c'],
        'D' => $q['option_d'],
    ];
    $correct_text = '';
    switch (strtoupper($q['correct_option'] ?? 'A')) {
        case 'A': $correct_text = $q['option_a']; break;
        case 'B': $correct_text = $q['option_b']; break;
        case 'C': $correct_text = $q['option_c']; break;
        case 'D': $correct_text = $q['option_d']; break;
    }

    // Seed based on student + question_id for consistent shuffle per student
    $opt_seed = hexdec(substr(md5($session['question_seed'] . '_opt_' . $q['id']), 0, 8));
    mt_srand($opt_seed);
    $labels  = ['A', 'B', 'C', 'D'];
    $values  = array_values($opts);
    for ($i = 3; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
    }

    // Rebuild shuffled options
    $new_opts    = array_combine($labels, $values);
    $new_correct = '';
    foreach ($new_opts as $lbl => $val) {
        if ($val === $correct_text) { $new_correct = $lbl; break; }
    }

    // Store map so result.php can decode correctly
    $option_maps[$q['id']] = [
        'map'     => $new_opts,              // label => text
        'correct' => $new_correct,           // new correct label
    ];

    $q['option_a']      = $new_opts['A'];
    $q['option_b']      = $new_opts['B'];
    $q['option_c']      = $new_opts['C'];
    $q['option_d']      = $new_opts['D'];
    $q['correct_option'] = $new_correct;
}
unset($q);

// Store option maps in session so result.php can score correctly
$_SESSION['option_maps_' . $exam_id] = $option_maps;

// ── Phase 1: Load existing draft answers (restore on page reload) ─────────────
$draft_answers = [];
$d_stmt = mysqli_prepare($conn,
    "SELECT question_id, answer FROM draft_answers WHERE student_id = ? AND exam_id = ?");
mysqli_stmt_bind_param($d_stmt, "ii", $student_id, $exam_id);
mysqli_stmt_execute($d_stmt);
$d_res = mysqli_stmt_get_result($d_stmt);
while ($row = mysqli_fetch_assoc($d_res)) {
    $draft_answers[(int)$row['question_id']] = $row['answer'];
}
mysqli_stmt_close($d_stmt);

// One-time submission token
$submit_token_key              = 'exam_submit_token_' . $exam_id;
$_SESSION[$submit_token_key]   = bin2hex(random_bytes(32));
$exam_submit_token             = $_SESSION[$submit_token_key];
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 sticky-top bg-body py-2 z-3 border-bottom">
    <div>
        <h4 class="fw-extrabold mb-0 text-dark"><?php echo htmlspecialchars($exam['title']); ?></h4>
        <small class="text-muted"><i class="bi bi-question-circle me-1"></i> Total Questions: <strong><?php echo $total_questions; ?></strong></small>
    </div>
    <div class="d-flex align-items-center gap-3">
        <!-- Save indicator -->
        <span id="saveIndicator" class="text-muted small" style="min-width:120px;text-align:right;"></span>
        <div class="timer-box" id="timerContainer">
            <i class="bi bi-stopwatch-fill text-warning fs-5"></i>
            <span id="timerText"><?php echo sprintf('%02d:%02d', floor($remaining_sec/60), $remaining_sec%60); ?></span>
        </div>
    </div>
</div>

<?php if ($total_questions === 0): ?>
    <div class="card shadow-sm border-0 rounded-4 p-5 text-center my-4">
        <i class="bi bi-exclamation-circle fs-1 text-warning d-block mb-3"></i>
        <h5 class="fw-bold">No Questions in This Exam</h5>
        <p class="text-muted">Questions have not been uploaded for this exam yet. Please contact your instructor.</p>
        <div><a href="dashboard.php" class="btn btn-primary fw-bold px-4">Back to Dashboard</a></div>
    </div>
<?php else: ?>

    <!-- Fullscreen Enforcement Overlay (guarantees user gesture for fullscreen) -->
    <div id="fullscreenOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.95);z-index:99999;display:none;align-items:center;justify-content:center;backdrop-filter:blur(8px);">
        <div class="card border-0 shadow-lg rounded-4 p-4 p-md-5 text-center text-white" style="max-width:480px;background:#1e293b;">
            <div class="mb-3">
                <i class="bi bi-shield-lock-fill text-warning" style="font-size:3.5rem;"></i>
            </div>
            <h4 class="fw-bold mb-2">Fullscreen Mode Required</h4>
            <p class="text-white-50 mb-4 small">
                For academic integrity, this exam must be taken in Fullscreen Mode.
                Switching tabs, exiting fullscreen, or minimizing the window will trigger a <strong>violation warning</strong>.
            </p>
            <button type="button" class="btn btn-primary btn-lg fw-bold px-4 py-2.5 rounded-pill shadow-lg" id="enterFullscreenBtn">
                <i class="bi bi-arrows-fullscreen me-2"></i> Enter Fullscreen &amp; Begin
            </button>
        </div>
    </div>

    <!-- Phase 2: Anti-Cheat Warning Modal -->
    <div class="modal fade" id="warningModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-danger text-white border-0 py-3">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        Academic Integrity Warning
                    </h5>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="mb-3">
                        <i class="bi bi-eye-slash-fill text-danger" style="font-size:3rem;"></i>
                    </div>
                    <p class="fw-bold text-dark fs-5 mb-2" id="warningMessage">
                        Suspicious activity detected.
                    </p>
                    <p class="text-muted mb-3" id="warningDetail"></p>
                    <div class="alert alert-danger border-0 rounded-3 py-2 px-3">
                        <strong>Warning <span id="warnCount">1</span> of <?php echo 3; ?></strong>
                        — Exam will be auto-submitted on <strong>3rd violation</strong>.
                    </div>
                </div>
                <div class="modal-footer border-0 justify-content-center pb-4">
                    <button type="button" class="btn btn-danger px-5 fw-bold" id="returnToExamBtn">
                        <i class="bi bi-arrow-return-left me-1"></i> Return to Exam
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- UI In-Page Exit Confirmation Modal (keeps fullscreen active, 0 violations if cancelled) -->
    <div class="modal fade" id="exitConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-danger text-white border-0 py-3">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> Warning: Exit Examination?
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-dark fw-bold mb-3">
                        Exiting this exam before final submission has the following consequences:
                    </p>
                    
                    <div class="list-group list-group-flush border rounded-3 mb-3 small">
                        <div class="list-group-item d-flex align-items-start gap-2.5 py-2.5">
                            <i class="bi bi-shield-exclamation text-danger fs-5 mt-n1 flex-shrink-0"></i>
                            <div>
                                <strong class="text-danger d-block">Integrity Strike Will Be Recorded</strong>
                                Leaving the exam mid-session is logged as an integrity violation (<span id="exitWarnNext" class="fw-bold">1</span> of 3 warnings). Reaching 3 violations auto-submits your exam.
                            </div>
                        </div>
                        <div class="list-group-item d-flex align-items-start gap-2.5 py-2.5">
                            <i class="bi bi-stopwatch text-warning fs-5 mt-n1 flex-shrink-0"></i>
                            <div>
                                <strong class="text-dark d-block">The Timer Will NOT Pause</strong>
                                The exam timer continues counting down on the server. If time runs out while you are away, your exam will be automatically closed and submitted.
                            </div>
                        </div>
                        <div class="list-group-item d-flex align-items-start gap-2.5 py-2.5">
                            <i class="bi bi-cloud-check text-success fs-5 mt-n1 flex-shrink-0"></i>
                            <div>
                                <strong class="text-dark d-block">Draft Answers Are Preserved</strong>
                                All answers you have selected so far are saved in drafts and will be loaded if you re-enter before time expires.
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-danger-subtle text-danger border border-danger-subtle rounded-3 py-2 px-3 small text-center mb-0 fw-semibold">
                        Are you sure you want to exit to the dashboard?
                    </div>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2 pb-4">
                    <button type="button" class="btn btn-outline-secondary px-4 fw-semibold rounded-pill" data-bs-dismiss="modal">
                        <i class="bi bi-arrow-return-left me-1"></i> Stay in Exam
                    </button>
                    <button type="button" class="btn btn-danger px-4 fw-bold rounded-pill shadow-sm" id="confirmExitBtn">
                        <i class="bi bi-box-arrow-right me-1"></i> Yes, Exit Exam
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- UI In-Page Submit Confirmation Modal (keeps fullscreen active, 0 violations) -->
    <div class="modal fade" id="submitConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-send-check-fill me-2"></i> Ready to Submit?
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted text-center mb-3">Please review your question status before submitting:</p>
                    <div class="row g-3 mb-3 text-center">
                        <div class="col-6">
                            <div class="bg-success-subtle p-3 rounded-4 border border-success-subtle">
                                <small class="text-muted fw-semibold d-block">Answered</small>
                                <h3 class="fw-extrabold text-success mb-0" id="modalAnsweredCount">0</h3>
                                <small class="text-muted">of <?php echo $total_questions; ?></small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="bg-warning-subtle p-3 rounded-4 border border-warning-subtle">
                                <small class="text-muted fw-semibold d-block">Unanswered</small>
                                <h3 class="fw-extrabold text-warning mb-0" id="modalUnansweredCount">0</h3>
                                <small class="text-muted">remaining</small>
                            </div>
                        </div>
                    </div>

                    <div id="modalUnansweredWarning" class="alert alert-warning border-0 rounded-3 py-2 px-3 small d-flex align-items-center gap-2 mb-0" style="display:none !important;">
                        <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 text-warning"></i>
                        <div>You still have unanswered questions. Once submitted, answers cannot be modified.</div>
                    </div>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2 pb-4">
                    <button type="button" class="btn btn-outline-secondary px-4 fw-semibold rounded-pill" data-bs-dismiss="modal">
                        <i class="bi bi-pencil me-1"></i> Continue Answering
                    </button>
                    <button type="button" class="btn btn-success px-4 fw-bold rounded-pill shadow-sm" id="confirmFinalSubmitBtn">
                        <i class="bi bi-check-circle-fill me-1"></i> Confirm &amp; Submit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Violation counter badge (top-left of sticky bar) -->
    <div id="violationBadge" style="display:none;position:fixed;top:70px;right:16px;z-index:9999;">
        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-2 shadow-sm small fw-semibold">
            <i class="bi bi-shield-exclamation me-1"></i>
            Warnings: <span id="violationCount">0</span>/3
        </span>
    </div>

    <!-- CBT Question Palette & Progress Tracker -->
    <div class="question-palette-card shadow-sm mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary rounded-pill px-3 py-1.5 fs-6">
                    Question <span id="currentQDisplay">1</span> of <?php echo $total_questions; ?>
                </span>
                <span class="text-muted small d-none d-sm-inline">
                    • <span id="answeredCountDisplay">0</span>/<?php echo $total_questions; ?> Answered (<span id="progressPercentDisplay">0%</span>)
                </span>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Font Size Readability Controls -->
                <div class="d-flex align-items-center gap-1 bg-light border rounded-pill px-2 py-1">
                    <small class="text-muted fw-semibold me-1"><i class="bi bi-type"></i> Font:</small>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 rounded-circle" onclick="changeFontSize(-1)" title="Decrease font size">A-</button>
                    <span class="small fw-bold px-1 font-size-label text-dark">Default</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 rounded-circle" onclick="changeFontSize(1)" title="Increase font size">A+</button>
                    <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 ms-1 text-muted" onclick="changeFontSize(0)" title="Reset font size"><i class="bi bi-arrow-counterclockwise"></i></button>
                </div>

                <div class="d-none d-md-flex align-items-center gap-3 small text-muted">
                    <span><span class="palette-legend-dot dot-answered"></span> Answered</span>
                    <span><span class="palette-legend-dot dot-unanswered"></span> Unanswered</span>
                    <span><span class="palette-legend-dot dot-current"></span> Current</span>
                </div>
            </div>
        </div>
        
        <div class="progress mb-3" style="height: 6px; border-radius: 10px; background-color: #f1f5f9;">
            <div id="examProgressBar" class="progress-bar bg-success rounded-pill" role="progressbar" style="width: 0%; transition: width 0.3s ease;"></div>
        </div>

        <div class="palette-grid" id="paletteGrid">
            <?php foreach ($questions as $idx => $quest): ?>
                <button type="button"
                        class="palette-btn unanswered-q <?php echo $idx === 0 ? 'active-q' : ''; ?>"
                        id="paletteBtn_<?php echo $idx; ?>"
                        onclick="goToQuestion(<?php echo $idx; ?>)"
                        title="Question <?php echo $idx + 1; ?>">
                    <?php echo $idx + 1; ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <form method="POST" action="result.php" id="examForm" onsubmit="return isAutoSubmitting;">
        <input type="hidden" name="exam_id"          value="<?php echo $exam_id; ?>">
        <input type="hidden" name="submit_exam"      value="1">
        <input type="hidden" name="csrf_token"       value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="exam_submit_token" value="<?php echo htmlspecialchars($exam_submit_token); ?>">


        <?php foreach ($questions as $index => $q):
            $q_num   = $index + 1;
            $is_desc = (($q['question_type'] ?? 'mcq') === 'descriptive');
            $q_id    = $q['id'];
            $draft   = $draft_answers[$q_id] ?? '';
        ?>
            <div class="question-card shadow-sm mb-4 <?php echo $index === 0 ? '' : 'd-none'; ?>"
                 id="questionCard_<?php echo $index; ?>"
                 data-qid="<?php echo $q_id; ?>"
                 data-type="<?php echo $is_desc ? 'descriptive' : 'mcq'; ?>"
                 data-index="<?php echo $index; ?>">
                <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                    <span class="badge bg-primary rounded-pill px-3 py-2 fs-6">Q<?php echo $q_num; ?> of <?php echo $total_questions; ?></span>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($is_desc): ?>
                            <span class="badge bg-warning text-dark border"><i class="bi bi-pencil-square me-1"></i> Descriptive</span>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border"><i class="bi bi-check2-circle me-1"></i> Multiple Choice</span>
                        <?php endif; ?>
                        <?php if (!empty($q['marks'])): ?>
                            <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-2.5 py-1.5"><?php echo (int)$q['marks']; ?> Marks</span>
                        <?php endif; ?>
                    </div>
                </div>

                <h5 class="fw-bold text-dark mb-4 lh-base question-text"><?php echo htmlspecialchars($q['question_text']); ?></h5>

                <?php if ($is_desc): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <label class="form-label fw-semibold text-muted mb-0">
                                <i class="bi bi-pencil text-primary me-1"></i> Write your detailed response:
                            </label>
                            <div class="d-flex align-items-center gap-1">
                                <span class="small text-muted me-1 d-none d-sm-inline"><i class="bi bi-arrows-vertical"></i> Height:</span>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Input box height">
                                    <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0.5"
                                            onclick="adjustInputHeight(<?php echo $q_id; ?>, -80)" title="Decrease box height">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0.5"
                                            onclick="resetInputHeight(<?php echo $q_id; ?>)" title="Reset to standard height">
                                        Default
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0.5"
                                            onclick="adjustInputHeight(<?php echo $q_id; ?>, 120)" title="Increase box height">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-sm px-2 py-0.5 fw-semibold"
                                            onclick="toggleMaxInputHeight(<?php echo $q_id; ?>)" id="btnMax_<?php echo $q_id; ?>" title="Large view for desktop">
                                        <i class="bi bi-arrows-angle-expand me-1"></i> Large View
                                    </button>
                                </div>
                            </div>
                        </div>
                        <textarea name="descriptive_answer[<?php echo $q_id; ?>]"
                                  id="desc_<?php echo $q_id; ?>"
                                  class="form-control descriptive-input p-3 shadow-sm rounded-3"
                                  rows="8"
                                  placeholder="Type your answer here manually..."
                                  maxlength="5000"
                                  data-qid="<?php echo $q_id; ?>"
                                  oncopy="return false;"
                                  oncut="return false;"
                                  onpaste="return false;"
                                  autocomplete="off"
                                  spellcheck="false"
                                  oninput="scheduleAutoSave(<?php echo $q_id; ?>, this.value)"><?php echo htmlspecialchars($draft); ?></textarea>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <span class="small text-muted"><i class="bi bi-shield-lock me-1 text-danger"></i> Copy &amp; paste strictly disabled • Drag bottom-right corner to expand vertically</span>
                            <span class="small text-muted"><span id="chars_<?php echo $q_id; ?>"><?php echo strlen($draft); ?></span>/5000</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="options-container mb-3">
                        <?php foreach (['A','B','C','D'] as $lbl):
                            $opt_key = 'option_' . strtolower($lbl);
                            $checked = ($draft === $lbl) ? 'checked' : '';
                        ?>
                        <label class="option-wrapper w-100 <?php echo $checked ? 'selected' : ''; ?>"
                               id="wrapper_<?php echo $q_id; ?>_<?php echo $lbl; ?>">
                            <input type="radio" name="answer[<?php echo $q_id; ?>]" value="<?php echo $lbl; ?>"
                                   <?php echo $checked; ?>
                                   onchange="selectOption(<?php echo $q_id; ?>, '<?php echo $lbl; ?>')">
                            <span class="fw-bold me-2 text-primary"><?php echo $lbl; ?>.</span>
                            <span class="text-dark"><?php echo htmlspecialchars($q[$opt_key]); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Per-Question Bottom Navigation & Controls -->
                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-secondary px-3 py-2 fw-semibold"
                                onclick="prevQuestion()" <?php echo $index === 0 ? 'disabled' : ''; ?>>
                            <i class="bi bi-arrow-left me-1"></i> Previous
                        </button>
                        <button type="button" class="btn btn-primary px-4 py-2 fw-bold"
                                onclick="nextQuestion()" <?php echo $index === $total_questions - 1 ? 'disabled' : ''; ?>>
                            Next <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>

                    <div>
                        <?php if (!$is_desc): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm px-3 py-2 fw-semibold"
                                    onclick="clearAnswer(<?php echo $q_id; ?>, 'mcq')" title="Deselect chosen option">
                                <i class="bi bi-eraser me-1"></i> Clear Choice
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card shadow-sm border-0 rounded-4 p-3 mb-5">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary px-4 fw-semibold" id="btnOpenExitModal">
                    <i class="bi bi-box-arrow-left me-1"></i> Exit Exam
                </button>
                <button type="button" class="btn btn-success px-4 py-2 fw-bold shadow-sm" id="btnOpenSubmitModal">
                    <i class="bi bi-check-circle-fill me-1"></i> Finish &amp; Submit Exam
                </button>
            </div>
        </div>
    </form>

    <script>
    // ── Config ────────────────────────────────────────────────────────────────
    const STUDENT_ID  = <?php echo (int)$student_id; ?>;
    const EXAM_ID     = <?php echo $exam_id; ?>;
    const CSRF_TOKEN  = <?php echo json_encode($_SESSION['csrf_token']); ?>;
    const LS_KEY      = `exam_draft_s${STUDENT_ID}_e${EXAM_ID}`;
    const SAVE_URL    = 'ajax_save_answer.php';
    const TIMER_URL   = `ajax_timer.php?exam_id=${EXAM_ID}`;
    const TOTAL_Q     = <?php echo $total_questions; ?>;

    // ── localStorage: load any cached answers (strictly scoped per student & exam) ──
    function lsLoad() {
        try { return JSON.parse(localStorage.getItem(LS_KEY) || '{}'); } catch(e) { return {}; }
    }
    function lsSave(qid, val) {
        try {
            const d = lsLoad();
            d[qid]  = val;
            localStorage.setItem(LS_KEY, JSON.stringify(d));
        } catch(e) {}
    }
    function lsClear() {
        try {
            localStorage.removeItem(LS_KEY);
            localStorage.removeItem(`exam_draft_${EXAM_ID}`);
        } catch(e) {}
    }

    // On page load: purge foreign student drafts & restore only this student's offline cache if empty in DB
    document.addEventListener('DOMContentLoaded', function() {
        try {
            Object.keys(localStorage).forEach(k => {
                if (k.startsWith('exam_draft_') && k !== LS_KEY) {
                    localStorage.removeItem(k);
                }
            });
        } catch(e) {}

        const cache = lsLoad();
        Object.entries(cache).forEach(([qid, val]) => {
            // MCQ
            const radio = document.querySelector(`input[name="answer[${qid}]"][value="${val}"]`);
            if (radio && !radio.checked) {
                const anyChecked = document.querySelector(`input[name="answer[${qid}]"]:checked`);
                if (!anyChecked) {
                    radio.checked = true;
                    selectOption(parseInt(qid), val, false); // false = don't re-save to server
                }
            }
            // Descriptive
            const ta = document.getElementById(`desc_${qid}`);
            if (ta && ta.value.trim() === '' && val) {
                ta.value = val;
                updateCharCount(parseInt(qid), val.length);
            }
        });

        // Initialize question palette and status counters
        updatePaletteStatus();

        // Restore preferred font size & textarea height
        try {
            const savedFont = localStorage.getItem('exam_font_level');
            if (savedFont !== null) {
                applyFontSize(parseInt(savedFont));
            }
            const savedH = localStorage.getItem('exam_desc_height');
            if (savedH) {
                document.querySelectorAll('textarea.descriptive-input').forEach(ta => {
                    ta.style.height = `${savedH}px`;
                });
            }
        } catch(e) {}

        // ── Wire up Exit confirmation modal (keeps fullscreen active, 0 violations) ──
        const btnExit = document.getElementById('btnOpenExitModal');
        if (btnExit) {
            btnExit.addEventListener('click', (e) => {
                e.preventDefault();
                openExitModal('dashboard.php');
            });
        }

        // Intercept all navbar links (logo, My Exams, My Results, dropdown items) during exam
        document.querySelectorAll('nav.navbar a').forEach(link => {
            link.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (this.classList.contains('dropdown-toggle') || this.getAttribute('data-bs-toggle') === 'dropdown') {
                    return; // Allow dropdown menu to toggle
                }
                if (href && href !== '#' && !href.startsWith('javascript:')) {
                    e.preventDefault();
                    openExitModal(this.href);
                }
            });
        });

        // Confirm Exit button inside the UI modal: logs violation and exits
        const confirmExitBtn = document.getElementById('confirmExitBtn');
        if (confirmExitBtn) {
            confirmExitBtn.addEventListener('click', () => {
                isExitingConfirmed = true;
                confirmExitBtn.disabled = true;
                confirmExitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Exiting...';

                // Log the voluntary exit as an integrity violation strike
                const fd = new FormData();
                fd.append('exam_id',    EXAM_ID);
                fd.append('type',       'exit_exam');
                fd.append('detail',     'Voluntarily exited exam to dashboard before submitting');
                fd.append('csrf_token', CSRF_TOKEN);

                let beaconQueued = false;
                if (navigator.sendBeacon) {
                    beaconQueued = navigator.sendBeacon(VIOLATION_URL, fd);
                }

                if (beaconQueued) {
                    // Queued successfully by browser background beacon
                    setTimeout(() => {
                        window.location.href = pendingExitHref;
                    }, 150);
                } else {
                    // Fallback to fetch if sendBeacon is unsupported or declined
                    fetch(VIOLATION_URL, { method: 'POST', body: fd, keepalive: true }).finally(() => {
                        window.location.href = pendingExitHref;
                    });
                    setTimeout(() => {
                        window.location.href = pendingExitHref;
                    }, 350);
                }
            });
        }

        // ── Wire up Submit confirmation modal (keeps fullscreen active, 0 violations) ──
        const btnSubmit = document.getElementById('btnOpenSubmitModal');
        if (btnSubmit) {
            btnSubmit.addEventListener('click', (e) => {
                e.preventDefault();
                openSubmitModal();
            });
        }

        // Confirm Final Submit button inside the UI modal
        const confirmFinalSubmitBtn = document.getElementById('confirmFinalSubmitBtn');
        if (confirmFinalSubmitBtn) {
            confirmFinalSubmitBtn.addEventListener('click', () => {
                isAutoSubmitting = true;
                lsClear();
                const m = getSubmitModal();
                if (m) m.hide();
                document.getElementById('examForm').submit();
            });
        }
    });

    // ── Font Size & Readability Scale ─────────────────────────────────────────
    const FONT_LEVELS = [
        { q: '1.05rem', body: '0.92rem', label: 'Compact' },
        { q: '1.2rem',  body: '1.05rem', label: 'Default' },
        { q: '1.4rem',  body: '1.18rem', label: 'Large' },
        { q: '1.65rem', body: '1.35rem', label: 'X-Large' },
        { q: '1.9rem',  body: '1.5rem',  label: 'Huge' }
    ];
    let currentFontLevel = 1;

    function applyFontSize(level) {
        currentFontLevel = Math.max(0, Math.min(FONT_LEVELS.length - 1, level));
        const cfg = FONT_LEVELS[currentFontLevel];
        document.documentElement.style.setProperty('--exam-q-font-size', cfg.q);
        document.documentElement.style.setProperty('--exam-body-font-size', cfg.body);

        document.querySelectorAll('.font-size-label').forEach(el => {
            el.textContent = cfg.label;
        });

        try { localStorage.setItem('exam_font_level', currentFontLevel); } catch(e) {}
    }

    function changeFontSize(delta) {
        if (delta === 0) {
            applyFontSize(1); // reset to default
        } else {
            applyFontSize(currentFontLevel + delta);
        }
    }

    // ── Descriptive Textarea Height Adjustments (Desktop Focused) ────────────
    function adjustInputHeight(qId, delta) {
        const ta = document.getElementById(`desc_${qId}`);
        if (!ta) return;
        const currentH = ta.offsetHeight || 280;
        const newH = Math.max(180, Math.min(900, currentH + delta));
        ta.style.height = `${newH}px`;
        try { localStorage.setItem('exam_desc_height', newH); } catch(e) {}
    }

    function resetInputHeight(qId) {
        const ta = document.getElementById(`desc_${qId}`);
        if (!ta) return;
        const defH = window.innerWidth >= 992 ? '300px' : '240px';
        ta.style.height = defH;
        try { localStorage.removeItem('exam_desc_height'); } catch(e) {}
        const btn = document.getElementById(`btnMax_${qId}`);
        if (btn) btn.innerHTML = '<i class="bi bi-arrows-angle-expand me-1"></i> Large View';
    }

    function toggleMaxInputHeight(qId) {
        const ta = document.getElementById(`desc_${qId}`);
        const btn = document.getElementById(`btnMax_${qId}`);
        if (!ta) return;
        if (ta.offsetHeight >= 460) {
            const defH = window.innerWidth >= 992 ? '300px' : '240px';
            ta.style.height = defH;
            if (btn) btn.innerHTML = '<i class="bi bi-arrows-angle-expand me-1"></i> Large View';
            try { localStorage.removeItem('exam_desc_height'); } catch(e) {}
        } else {
            ta.style.height = '520px';
            if (btn) btn.innerHTML = '<i class="bi bi-arrows-angle-contract me-1"></i> Compact View';
            try { localStorage.setItem('exam_desc_height', 520); } catch(e) {}
        }
    }

    // ── CBT Single Question Navigation & Palette State ────────────────────────
    let currentQuestionIndex = 0;

    function isQuestionAnswered(index) {
        const card = document.getElementById(`questionCard_${index}`);
        if (!card) return false;
        const qId  = card.getAttribute('data-qid');
        const type = card.getAttribute('data-type');

        if (type === 'descriptive') {
            const ta = document.getElementById(`desc_${qId}`);
            return !!(ta && ta.value.trim().length > 0);
        } else {
            const radio = document.querySelector(`input[type="radio"][name="answer[${qId}]"]:checked`);
            return !!radio;
        }
    }

    function updatePaletteStatus() {
        let answeredCount = 0;

        for (let i = 0; i < TOTAL_Q; i++) {
            const btn = document.getElementById(`paletteBtn_${i}`);
            const answered = isQuestionAnswered(i);

            if (answered) answeredCount++;

            if (btn) {
                if (answered) {
                    btn.classList.add('answered-q');
                    btn.classList.remove('unanswered-q');
                } else {
                    btn.classList.remove('answered-q');
                    btn.classList.add('unanswered-q');
                }

                if (i === currentQuestionIndex) {
                    btn.classList.add('active-q');
                    btn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
                } else {
                    btn.classList.remove('active-q');
                }
            }
        }

        const curQDisp = document.getElementById('currentQDisplay');
        if (curQDisp) curQDisp.textContent = currentQuestionIndex + 1;

        const ansDisp = document.getElementById('answeredCountDisplay');
        if (ansDisp) ansDisp.textContent = answeredCount;

        const pct = TOTAL_Q > 0 ? Math.round((answeredCount / TOTAL_Q) * 100) : 0;
        const pctDisp = document.getElementById('progressPercentDisplay');
        if (pctDisp) pctDisp.textContent = `${pct}%`;

        const bar = document.getElementById('examProgressBar');
        if (bar) bar.style.width = `${pct}%`;
    }

    function goToQuestion(index) {
        if (index < 0 || index >= TOTAL_Q) return;

        // Flush pending autosave on current active card before switching
        const curCard = document.getElementById(`questionCard_${currentQuestionIndex}`);
        if (curCard) {
            const curQid = curCard.getAttribute('data-qid');
            if (curQid && typeof flushAutosave === 'function') {
                flushAutosave(curQid);
            }
            curCard.classList.add('d-none');
        }

        // Show requested card
        currentQuestionIndex = index;
        const targetCard = document.getElementById(`questionCard_${currentQuestionIndex}`);
        if (targetCard) {
            targetCard.classList.remove('d-none');

            // Apply preserved desktop textarea height if available
            try {
                const savedH = localStorage.getItem('exam_desc_height');
                if (savedH) {
                    const descTa = targetCard.querySelector('textarea.descriptive-input');
                    if (descTa) descTa.style.height = `${savedH}px`;
                }
            } catch(e) {}

            // Re-run math typesetting if KaTeX is present
            if (typeof renderMathInElement === 'function') {
                try {
                    renderMathInElement(targetCard, {
                        delimiters: [
                            {left: '$$', right: '$$', display: true},
                            {left: '$',  right: '$',  display: false},
                            {left: '\\(', right: '\\)', display: false},
                            {left: '\\[', right: '\\]', display: true}
                        ],
                        throwOnError: false,
                        trust: false
                    });
                } catch(e) {}
            }
        }

        // Scroll into view if page has scrolled down
        const palCard = document.querySelector('.question-palette-card');
        if (palCard && window.scrollY > palCard.offsetTop) {
            palCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        updatePaletteStatus();
    }

    function nextQuestion() {
        if (currentQuestionIndex < TOTAL_Q - 1) {
            goToQuestion(currentQuestionIndex + 1);
        }
    }

    function prevQuestion() {
        if (currentQuestionIndex > 0) {
            goToQuestion(currentQuestionIndex - 1);
        }
    }

    function clearAnswer(qId, type) {
        if (type === 'descriptive') {
            const ta = document.getElementById(`desc_${qId}`);
            if (ta) {
                ta.value = '';
                updateCharCount(qId, 0);
            }
        } else {
            const checkedRadio = document.querySelector(`input[type="radio"][name="answer[${qId}]"]:checked`);
            if (checkedRadio) checkedRadio.checked = false;

            ['A','B','C','D'].forEach(opt => {
                const el = document.getElementById(`wrapper_${qId}_${opt}`);
                if (el) el.classList.remove('selected');
            });
        }

        lsSave(qId, '');
        autoSaveNow(qId, '');
        updatePaletteStatus();
    }

    // Keyboard shortcuts for CBT navigation: Left / Right arrows
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') return;
        if (document.querySelector('.modal.show')) return;
        const fsOverlay = document.getElementById('fullscreenOverlay');
        if (fsOverlay && fsOverlay.style.display !== 'none') return;

        if (e.key === 'ArrowRight' || e.key === 'PageDown') {
            e.preventDefault();
            nextQuestion();
        } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
            e.preventDefault();
            prevQuestion();
        }
    });

    // ── Option highlight ──────────────────────────────────────────────────────
    function selectOption(qId, choice, doSave = true) {
        ['A','B','C','D'].forEach(opt => {
            const el = document.getElementById(`wrapper_${qId}_${opt}`);
            if (el) el.classList.remove('selected');
        });
        const sel = document.getElementById(`wrapper_${qId}_${choice}`);
        if (sel) sel.classList.add('selected');

        if (doSave) {
            lsSave(qId, choice);
            autoSaveNow(qId, choice);
        }
        updatePaletteStatus();
    }

    // ── Descriptive char counter ──────────────────────────────────────────────
    function updateCharCount(qId, len) {
        const el = document.getElementById(`chars_${qId}`);
        if (el) el.textContent = len;
    }

    // ── Debounced auto-save for descriptive (Optimized with dirty-check & flush) ─
    const lastServerSaved = <?php echo json_encode(array_map('strval', $draft_answers)); ?> || {};
    const saveTimers = {};
    const saveAbortControllers = {};
    const saveSequence = {};

    function autoSaveNow(qId, val) {
        // Skip redundant AJAX write if value hasn't changed since last server save
        if (lastServerSaved[qId] !== undefined && lastServerSaved[qId] === val) {
            return;
        }

        // Abort any prior in-flight request for this question so older saves cannot arrive after this one
        if (saveAbortControllers[qId]) {
            try { saveAbortControllers[qId].abort(); } catch(e) {}
        }
        const controller = new AbortController();
        saveAbortControllers[qId] = controller;

        const currentSeq = (saveSequence[qId] = (saveSequence[qId] || 0) + 1);

        setSaveStatus('Saving…', '#888');
        const fd = new FormData();
        fd.append('exam_id',     EXAM_ID);
        fd.append('question_id', qId);
        fd.append('answer',      val);
        fd.append('csrf_token',  CSRF_TOKEN);

        fetch(SAVE_URL, { method: 'POST', body: fd, signal: controller.signal })
            .then(r => r.json())
            .then(d => {
                // Ensure no newer save was dispatched while this request was traveling
                if (currentSeq !== saveSequence[qId]) {
                    return;
                }
                if (d.ok) {
                    lastServerSaved[qId] = val;
                    setSaveStatus(`✓ Saved ${d.saved_at}`, '#198754');
                } else if (d.error === 'time_expired') {
                    setSaveStatus('⚠ Time expired!', '#dc3545');
                } else {
                    setSaveStatus('⚠ Save failed', '#dc3545');
                }
            })
            .catch(err => {
                if (err && err.name === 'AbortError') {
                    return; // Normal cancellation of superseded request
                }
                if (currentSeq === saveSequence[qId]) {
                    setSaveStatus('⚠ Offline — draft in browser', '#e67e22');
                }
            });
    }

    function flushAutosave(qId) {
        if (saveTimers[qId]) {
            clearTimeout(saveTimers[qId]);
            delete saveTimers[qId];
        }
        const ta = document.getElementById(`desc_${qId}`);
        if (ta) {
            autoSaveNow(qId, ta.value);
        }
    }

    function scheduleAutoSave(qId, val) {
        lsSave(qId, val);
        updateCharCount(qId, val.length);
        updatePaletteStatus();
        clearTimeout(saveTimers[qId]);
        saveTimers[qId] = setTimeout(() => autoSaveNow(qId, val), 3000); // 3.0s debounce
    }

    // Attach blur listeners to descriptive textareas so clicking away flushes immediately
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('textarea.descriptive-input').forEach(ta => {
            ta.addEventListener('blur', function() {
                const qId = this.getAttribute('data-qid');
                if (qId) flushAutosave(qId);
            });
        });
    });

    // ── AJAX save indicator ───────────────────────────────────────────────────
    const indicator = document.getElementById('saveIndicator');
    function setSaveStatus(msg, color) {
        if (!indicator) return;
        indicator.textContent = msg;
        indicator.style.color = color;
    }

    // ── State flags & UI Modals (keeps fullscreen intact, 0 violations) ──────
    let isAutoSubmitting    = false;
    let isExitingConfirmed  = false;
    let pendingExitHref     = 'dashboard.php';
    let exitModalInstance   = null;
    let submitModalInstance = null;

    function getExitModal() {
        if (!exitModalInstance && typeof bootstrap !== 'undefined') {
            const el = document.getElementById('exitConfirmModal');
            if (el) exitModalInstance = new bootstrap.Modal(el, {backdrop:'static', keyboard:false});
        }
        return exitModalInstance;
    }

    function getSubmitModal() {
        if (!submitModalInstance && typeof bootstrap !== 'undefined') {
            const el = document.getElementById('submitConfirmModal');
            if (el) submitModalInstance = new bootstrap.Modal(el, {backdrop:'static', keyboard:false});
        }
        return submitModalInstance;
    }

    function openExitModal(targetHref) {
        pendingExitHref = targetHref || 'dashboard.php';
        const exitWarnSpan = document.getElementById('exitWarnNext');
        if (exitWarnSpan) {
            exitWarnSpan.textContent = Math.min(3, warningCount + 1);
        }
        const m = getExitModal();
        if (m) m.show();
    }

    function openSubmitModal() {
        // Flush all pending descriptive autosaves before opening submission review modal
        document.querySelectorAll('textarea.descriptive-input').forEach(t => {
            const qId = t.getAttribute('data-qid');
            if (qId && typeof flushAutosave === 'function') flushAutosave(qId);
        });

        const answeredMcq  = document.querySelectorAll('input[type="radio"]:checked').length;
        let   answeredDesc = 0;
        document.querySelectorAll('textarea.descriptive-input').forEach(t => {
            if (t.value.trim().length > 0) answeredDesc++;
        });
        const answered   = answeredMcq + answeredDesc;
        const unanswered = Math.max(0, TOTAL_Q - answered);

        const ansEl   = document.getElementById('modalAnsweredCount');
        const unansEl = document.getElementById('modalUnansweredCount');
        const warnEl  = document.getElementById('modalUnansweredWarning');

        if (ansEl)   ansEl.textContent   = answered;
        if (unansEl) unansEl.textContent = unanswered;
        if (warnEl) {
            if (unanswered > 0) {
                warnEl.style.setProperty('display', 'flex', 'important');
            } else {
                warnEl.style.setProperty('display', 'none', 'important');
            }
        }

        const m = getSubmitModal();
        if (m) m.show();
    }

    // ── Server-authoritative timer ────────────────────────────────────────────
    let totalSeconds  = <?php echo $remaining_sec; ?>;
    const timerText   = document.getElementById('timerText');
    const timerBox    = document.getElementById('timerContainer');

    function formatTime(s) {
        const m = Math.floor(s / 60);
        const sec = s % 60;
        return `${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
    }

    function triggerAutoSubmit(reason) {
        if (isAutoSubmitting) return;
        isAutoSubmitting = true;
        lsClear();
        timerText.textContent = "00:00 — Time's Up!";
        timerBox.classList.add('warning');
        // Submit directly without native alert() which freezes JS & drops fullscreen
        document.getElementById('examForm').submit();
    }

    // Tick every second
    const interval = setInterval(() => {
        if (totalSeconds <= 0) {
            clearInterval(interval);
            triggerAutoSubmit("Time is up!");
            return;
        }
        totalSeconds--;
        if (totalSeconds <= 60) timerBox.classList.add('warning');
        timerText.textContent = formatTime(totalSeconds);
    }, 1000);

    // Heartbeat: reconcile with server every 30 seconds
    setInterval(() => {
        fetch(TIMER_URL)
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                if (d.submitted) {
                    clearInterval(interval);
                    triggerAutoSubmit("Your exam was already submitted.");
                    return;
                }
                // If client and server differ by more than 5s, correct client
                if (Math.abs(totalSeconds - d.remaining) > 5) {
                    totalSeconds = d.remaining;
                }
                if (d.remaining <= 0) {
                    clearInterval(interval);
                    triggerAutoSubmit("Time is up! (server confirmed)");
                }
            })
            .catch(() => {}); // silently fail on network issues
    }, 60000);

    // ══════════════════════════════════════════════════════════════════════════
    // PHASE 2 — Anti-Cheat & Integrity Controls
    // ══════════════════════════════════════════════════════════════════════════
    const MAX_WARNINGS   = 3;
    const VIOLATION_URL  = 'ajax_log_violation.php';
    let   warningCount   = <?php echo (int)$initial_violations; ?>;
    let   warningModalInstance = null;

    function getWarningModal() {
        if (!warningModalInstance && typeof bootstrap !== 'undefined') {
            const modalEl = document.getElementById('warningModal');
            if (modalEl) {
                warningModalInstance = new bootstrap.Modal(modalEl, {backdrop:'static', keyboard:false});
            }
        }
        return warningModalInstance;
    }

    const warnCountEl    = document.getElementById('warnCount');
    const warnMsgEl      = document.getElementById('warningMessage');
    const warnDetailEl   = document.getElementById('warningDetail');
    const violBadge      = document.getElementById('violationBadge');
    const violCountEl    = document.getElementById('violationCount');

    function updateViolationUI() {
        if (warningCount > 0 && violBadge) {
            violBadge.style.display = 'block';
        }
        if (violCountEl) violCountEl.textContent = warningCount;
        if (warnCountEl) warnCountEl.textContent = warningCount;
    }

    // Initialize UI if continuing an attempt with prior warnings
    if (warningCount > 0) {
        updateViolationUI();
    }

    // ── Log violation to server ───────────────────────────────────────────────
    function logViolation(type, detail) {
        const fd = new FormData();
        fd.append('exam_id',    EXAM_ID);
        fd.append('type',       type);
        fd.append('detail',     detail);
        fd.append('csrf_token', CSRF_TOKEN);

        if (typeof fetch === 'function') {
            fetch(VIOLATION_URL, { method: 'POST', body: fd, keepalive: true })
                .then(r => r.json())
                .then(d => {
                    if (d.ok && d.violation_count !== undefined) {
                        warningCount = Math.max(warningCount, d.violation_count);
                        updateViolationUI();
                        if (warningCount >= MAX_WARNINGS && !isAutoSubmitting) {
                            triggerAutoSubmit(`You have received ${MAX_WARNINGS} integrity violations.`);
                        }
                    }
                })
                .catch(() => {});
        } else if (navigator.sendBeacon) {
            navigator.sendBeacon(VIOLATION_URL, fd);
        }
    }

    // ── Show warning modal ────────────────────────────────────────────────────
    function showWarning(type, message, detail) {
        if (isAutoSubmitting) return;
        warningCount++;
        updateViolationUI();
        logViolation(type, detail);

        if (warnMsgEl)    warnMsgEl.textContent    = message;
        if (warnDetailEl) warnDetailEl.textContent = detail;

        if (warningCount >= MAX_WARNINGS) {
            const m = getWarningModal();
            if (m) m.hide();
            triggerAutoSubmit(`You have received ${MAX_WARNINGS} integrity violations. Exam auto-submitted.`);
            return;
        }

        const m = getWarningModal();
        if (m) m.show();
    }

    // ── Fullscreen API (Robust with User Gesture & Transition Guard) ─────────
    let isInExamFullscreen = false;
    let isFsTransitioning  = false;
    let blurReady          = false;
    let blurCooldown       = false;

    function isFullscreen() {
        return !!(document.fullscreenElement
            || document.webkitFullscreenElement
            || document.mozFullScreenElement
            || document.msFullscreenElement);
    }

    function beginFullscreenTransition() {
        isFsTransitioning = true;
        blurReady = false;
        // Suppress blur/focus events for 2.5s while browser switches fullscreen mode and shows banner
        setTimeout(() => {
            isFsTransitioning = false;
            if (isFullscreen()) {
                isInExamFullscreen = true;
                setTimeout(() => {
                    if (isInExamFullscreen) blurReady = true;
                }, 1000);
            }
        }, 2500);
    }

    function requestFullscreen() {
        const el = document.documentElement;
        beginFullscreenTransition();
        try {
            if      (el.requestFullscreen)       el.requestFullscreen().catch(() => {});
            else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
            else if (el.mozRequestFullScreen)    el.mozRequestFullScreen();
            else if (el.msRequestFullscreen)     el.msRequestFullscreen();
        } catch(e) {}
    }

    // Return to exam button — close modal & re-enter fullscreen
    document.getElementById('returnToExamBtn').addEventListener('click', () => {
        const m = getWarningModal();
        if (m) m.hide();
        requestFullscreen();
    });

    const fsOverlay  = document.getElementById('fullscreenOverlay');
    const enterFsBtn = document.getElementById('enterFullscreenBtn');

    if (enterFsBtn && fsOverlay) {
        enterFsBtn.addEventListener('click', () => {
            requestFullscreen();
            fsOverlay.style.display = 'none';
        });
    }

    // Show fullscreen prompt if not already in fullscreen on load
    if (!isFullscreen() && fsOverlay) {
        fsOverlay.style.display = 'flex';
    } else if (isFullscreen()) {
        isInExamFullscreen = true;
        setTimeout(() => { blurReady = true; }, 2000);
    }

    // Detect fullscreen exit
    ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange'].forEach(evt => {
        document.addEventListener(evt, () => {
            if (isFullscreen()) {
                isInExamFullscreen = true;
                if (fsOverlay) fsOverlay.style.display = 'none';
            } else {
                // Only treat as violation if exam was actively in fullscreen,
                // not during entry transition, not auto-submitting, and not confirmed exiting
                if (isInExamFullscreen && !isFsTransitioning && !isAutoSubmitting && !isExitingConfirmed) {
                    isInExamFullscreen = false;
                    blurReady = false; // Disarm blur while warning modal is shown
                    showWarning(
                        'fullscreen_exit',
                        'You exited fullscreen mode!',
                        'Fullscreen exited during exam. Please return to fullscreen.'
                    );
                }
            }
        });
    });

    // ── Tab-switch & Window blur detection ───────────────────────────────────
    function onFocusLost(source) {
        if (!isInExamFullscreen || isFsTransitioning || !blurReady || isAutoSubmitting || isExitingConfirmed || blurCooldown) return;
        blurCooldown = true;
        setTimeout(() => { blurCooldown = false; }, 2000); // 2s cooldown
        showWarning(
            'tab_switch',
            'You switched tabs or windows!',
            `Focus lost (${source}) — switching away from the exam is recorded.`
        );
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            onFocusLost('tab switched or minimized');
        }
    });

    window.addEventListener('blur', () => {
        onFocusLost('window lost focus');
    });

    // ── Prevent accidental tab closing (beforeunload & pagehide) ─────────────
    window.addEventListener('beforeunload', (e) => {
        if (isAutoSubmitting || isExitingConfirmed) return;
        // Do NOT log violation inside beforeunload! If student clicks 'Cancel' to stay,
        // logging here would unfairly penalize them.
        e.preventDefault();
        e.returnValue = 'Are you sure you want to leave? Your exam progress will be affected.';
        return e.returnValue;
    });

    window.addEventListener('pagehide', () => {
        if (isAutoSubmitting || isExitingConfirmed) return;
        // Only log violation when the page is actually unloaded
        logViolation('tab_switch', 'Closed exam tab or navigated away');
    });

    // ── Block right-click ─────────────────────────────────────────────────────
    document.addEventListener('contextmenu', e => {
        e.preventDefault();
        logViolation('blocked_key', 'right-click context menu');
    });

    // ── Block dangerous keyboard shortcuts & Copy/Paste/Cut ───────────────────
    const BLOCKED_KEYS = new Set([
        'F12',                           // DevTools
        'F5',                            // Refresh
        'PrintScreen',                   // Screenshot
    ]);

    // Toast notification for blocked actions (guaranteed visible in fullscreen)
    let blockToastTimer = null;
    function notifyBlockedAction(msg) {
        const container = document.fullscreenElement || document.body;
        let toastEl = document.getElementById('blockedActionToast');
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.id = 'blockedActionToast';
            toastEl.style.cssText = 'position:fixed;bottom:35px;left:50%;transform:translateX(-50%);background:#dc2626;color:#ffffff;padding:12px 28px;border-radius:50px;font-size:15px;font-weight:700;z-index:2147483647;box-shadow:0 8px 30px rgba(0,0,0,0.5);border:2px solid rgba(255,255,255,0.4);pointer-events:none;transition:all 0.3s ease;display:flex;align-items:center;gap:10px;';
            container.appendChild(toastEl);
        } else if (toastEl.parentElement !== container) {
            container.appendChild(toastEl);
        }
        toastEl.innerHTML = `<i class="bi bi-shield-x" style="font-size:1.25rem;"></i> <span>${msg}</span>`;
        toastEl.style.opacity = '1';
        toastEl.style.transform = 'translateX(-50%) translateY(0)';
        clearTimeout(blockToastTimer);
        blockToastTimer = setTimeout(() => {
            if (toastEl) {
                toastEl.style.opacity = '0';
                toastEl.style.transform = 'translateX(-50%) translateY(15px)';
            }
        }, 2800);
    }

    // ── Input Level 2 (beforeinput): Catch Win+V, Mobile Keyboard Clips, IME Pastes ──
    document.addEventListener('beforeinput', e => {
        const inputType = e.inputType || '';
        // Intercept any paste or drop at the DOM input pipeline before value changes
        if (inputType === 'insertFromPaste' || inputType === 'insertFromDrop' || inputType === 'insertFromPasteAsQuotation') {
            e.preventDefault();
            e.stopPropagation();
            notifyBlockedAction('Pasting from clipboard is strictly prohibited. Please type manually.');
            logViolation('blocked_key', `Clipboard insertion (${inputType}) blocked`);
        }
    }, true);

    document.addEventListener('keydown', e => {
        const isCtrlOrMeta = e.ctrlKey || e.metaKey;
        const keyUpper     = (e.key || '').toUpperCase();

        // Single blocked keys
        if (BLOCKED_KEYS.has(e.key)) {
            e.preventDefault();
            e.stopPropagation();
            notifyBlockedAction(`${e.key} key blocked`);
            logViolation('blocked_key', `${e.key} key blocked`);
            return;
        }

        // Windows alternative copy/paste shortcuts: Shift+Insert (paste), Ctrl+Insert (copy)
        if ((e.shiftKey && e.key === 'Insert') || (e.ctrlKey && e.key === 'Insert')) {
            e.preventDefault();
            e.stopPropagation();
            notifyBlockedAction('Copy / Paste is strictly prohibited during the exam');
            logViolation('blocked_key', 'Insert-based copy/paste blocked');
            return;
        }

        // Ctrl / Cmd + key shortcuts
        if (isCtrlOrMeta) {
            // Strict Copy / Paste / Cut / Select-All blocking
            if (['C', 'V', 'X', 'A'].includes(keyUpper)) {
                // Permit Ctrl+A strictly within editable textareas/inputs so students can edit typed text
                if (keyUpper === 'A' && (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type === 'text'))) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                const actionName = (keyUpper === 'C') ? 'Copy' : (keyUpper === 'V') ? 'Paste' : (keyUpper === 'X') ? 'Cut' : 'Select All';
                notifyBlockedAction(`${actionName} is strictly prohibited during the exam`);
                logViolation('blocked_key', `Ctrl+${keyUpper} (${actionName}) blocked`);
                return;
            }

            // Developer tools, print, save, view-source
            if (['I', 'J', 'U', 'S', 'P'].includes(keyUpper)) {
                e.preventDefault();
                e.stopPropagation();
                notifyBlockedAction('Shortcut disabled during the exam');
                logViolation('blocked_key', `Ctrl+${e.shiftKey?'Shift+':''}${keyUpper} blocked`);
                return;
            }
        }
    }, true);

    // ── Strictly Block Copy, Cut, and Paste events (Capture Phase) ────────────
    ['copy', 'cut', 'paste'].forEach(action => {
        document.addEventListener(action, e => {
            e.preventDefault();
            e.stopPropagation();
            const actionCap = action.charAt(0).toUpperCase() + action.slice(1);
            notifyBlockedAction(`${actionCap} is strictly disabled. Please type answers manually.`);
            logViolation('blocked_key', `${actionCap} event blocked`);
        }, true);
    });

    // ── Block Drag & Drop into any element ────────────────────────────────────
    document.addEventListener('dragover', e => {
        e.preventDefault();
    }, true);

    document.addEventListener('drop', e => {
        e.preventDefault();
        e.stopPropagation();
        notifyBlockedAction('Dragging and dropping text is disabled');
        logViolation('blocked_key', 'Drag-and-drop paste attempt blocked');
    }, true);

    </script>


<?php endif; ?>

<?php include '../includes/footer.php'; ?>
