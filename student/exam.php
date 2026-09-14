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

$duration = (int)($exam['duration_minutes'] ?? 30);

// ── Phase 1: Server-side timer & question-order seed ─────────────────────────
// Create or resume exam session (UNIQUE on student_id+exam_id)
$seed_string = md5($student_id . '_' . $exam_id . '_' . date('Ymd'));

$ins = mysqli_prepare($conn,
    "INSERT IGNORE INTO exam_sessions (student_id, exam_id, started_at, duration_minutes, question_seed)
     VALUES (?, ?, NOW(), ?, ?)");
mysqli_stmt_bind_param($ins, "iiis", $student_id, $exam_id, $duration, $seed_string);
mysqli_stmt_execute($ins);
mysqli_stmt_close($ins);

// Fetch session (guaranteed to exist now)
$sess_stmt = mysqli_prepare($conn,
    "SELECT started_at, duration_minutes, question_seed, submitted
     FROM exam_sessions WHERE student_id = ? AND exam_id = ? LIMIT 1");
mysqli_stmt_bind_param($sess_stmt, "ii", $student_id, $exam_id);
mysqli_stmt_execute($sess_stmt);
$sess_res = mysqli_stmt_get_result($sess_stmt);
$session  = mysqli_fetch_assoc($sess_res);
mysqli_stmt_close($sess_stmt);

// If already submitted, send back to dashboard
if ($session['submitted']) {
    $_SESSION['flash_already_submitted'] = "You have already submitted this exam.";
    header("Location: result.php");
    exit;
}

// Calculate remaining seconds (server-authoritative)
$elapsed       = (int)(time() - strtotime($session['started_at']));
$total_seconds = (int)$session['duration_minutes'] * 60;
$remaining_sec = max(0, $total_seconds - $elapsed);

// If time already expired server-side, redirect
if ($remaining_sec === 0) {
    header("Location: result.php?timeout=1&exam_id={$exam_id}");
    exit;
}

// ── Fetch questions ──────────────────────────────────────────────────────────
$questions_res = mysqli_query($conn,
    "SELECT * FROM questions WHERE exam_id = " . (int)$exam_id . " ORDER BY id ASC");
$questions = [];
if ($questions_res) {
    while ($q = mysqli_fetch_assoc($questions_res)) {
        $questions[] = $q;
    }
}
$total_questions = count($questions);

// ── Phase 1: Randomize question order per student (seeded shuffle) ───────────
if ($total_questions > 0) {
    // Use seed derived from student+exam so same student always gets same order
    $seed_int = hexdec(substr(md5($session['question_seed']), 0, 8));
    mt_srand($seed_int);
    $indices = range(0, $total_questions - 1);
    for ($i = $total_questions - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
    }
    $shuffled = [];
    foreach ($indices as $idx) {
        $shuffled[] = $questions[$idx];
    }
    $questions = $shuffled;
}

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

    <form method="POST" action="result.php" id="examForm" onsubmit="return confirmSubmission();">
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
            <div class="question-card shadow-sm mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="badge bg-primary rounded-pill px-3 py-2 fs-6">Q<?php echo $q_num; ?> of <?php echo $total_questions; ?></span>
                    <?php if ($is_desc): ?>
                        <span class="badge bg-warning text-dark border"><i class="bi bi-pencil-square me-1"></i> Descriptive</span>
                    <?php else: ?>
                        <span class="badge bg-light text-muted border">Multiple Choice</span>
                    <?php endif; ?>
                </div>

                <h5 class="fw-bold text-dark mb-4"><?php echo htmlspecialchars($q['question_text']); ?></h5>

                <?php if ($is_desc): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted">
                            <i class="bi bi-pencil text-primary me-1"></i> Write your detailed response:
                        </label>
                        <textarea name="descriptive_answer[<?php echo $q_id; ?>]"
                                  id="desc_<?php echo $q_id; ?>"
                                  class="form-control descriptive-input p-3 shadow-sm rounded-3"
                                  rows="5"
                                  placeholder="Type your answer here..."
                                  maxlength="5000"
                                  data-qid="<?php echo $q_id; ?>"
                                  oninput="scheduleAutoSave(<?php echo $q_id; ?>, this.value)"><?php echo htmlspecialchars($draft); ?></textarea>
                        <div class="text-end small text-muted mt-1">
                            <span id="chars_<?php echo $q_id; ?>"><?php echo strlen($draft); ?></span>/5000
                        </div>
                    </div>
                <?php else: ?>
                    <div class="options-container">
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
            </div>
        <?php endforeach; ?>

        <div class="card shadow-sm border-0 rounded-4 p-4 mb-5">
            <div class="d-flex justify-content-between align-items-center">
                <a href="dashboard.php" class="btn btn-outline-secondary px-4 fw-semibold"
                   onclick="return confirm('Are you sure you want to exit? Your draft answers are auto-saved.');">
                    <i class="bi bi-arrow-left me-1"></i> Exit Exam
                </a>
                <button type="submit" class="btn btn-success px-5 py-2.5 fw-bold shadow">
                    <i class="bi bi-check-circle-fill me-1"></i> Submit Exam
                </button>
            </div>
        </div>
    </form>

    <script>
    // ── Config ────────────────────────────────────────────────────────────────
    const EXAM_ID     = <?php echo $exam_id; ?>;
    const CSRF_TOKEN  = <?php echo json_encode($_SESSION['csrf_token']); ?>;
    const LS_KEY      = `exam_draft_${EXAM_ID}`;
    const SAVE_URL    = 'ajax_save_answer.php';
    const TIMER_URL   = `ajax_timer.php?exam_id=${EXAM_ID}`;
    const TOTAL_Q     = <?php echo $total_questions; ?>;

    // ── localStorage: load any cached answers (offline fallback) ─────────────
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

    // On page load: restore any localStorage values not already pre-filled by PHP drafts
    document.addEventListener('DOMContentLoaded', function() {
        const cache = lsLoad();
        Object.entries(cache).forEach(([qid, val]) => {
            // MCQ
            const radio = document.querySelector(`input[name="answer[${qid}]"][value="${val}"]`);
            if (radio && !radio.checked) {
                radio.checked = true;
                selectOption(parseInt(qid), val, false); // false = don't re-save to server
            }
            // Descriptive
            const ta = document.getElementById(`desc_${qid}`);
            if (ta && ta.value.trim() === '' && val) {
                ta.value = val;
                updateCharCount(parseInt(qid), val.length);
            }
        });
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
    }

    // ── Descriptive char counter ──────────────────────────────────────────────
    function updateCharCount(qId, len) {
        const el = document.getElementById(`chars_${qId}`);
        if (el) el.textContent = len;
    }

    // ── Debounced auto-save for descriptive ───────────────────────────────────
    const saveTimers = {};
    function scheduleAutoSave(qId, val) {
        lsSave(qId, val);
        updateCharCount(qId, val.length);
        clearTimeout(saveTimers[qId]);
        saveTimers[qId] = setTimeout(() => autoSaveNow(qId, val), 1500); // 1.5s debounce
    }

    // ── AJAX save to server ───────────────────────────────────────────────────
    const indicator = document.getElementById('saveIndicator');
    function setSaveStatus(msg, color) {
        if (!indicator) return;
        indicator.textContent = msg;
        indicator.style.color = color;
    }

    function autoSaveNow(qId, val) {
        setSaveStatus('Saving…', '#888');
        const fd = new FormData();
        fd.append('exam_id',     EXAM_ID);
        fd.append('question_id', qId);
        fd.append('answer',      val);
        fd.append('csrf_token',  CSRF_TOKEN);

        fetch(SAVE_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    setSaveStatus(`✓ Saved ${d.saved_at}`, '#198754');
                } else if (d.error === 'time_expired') {
                    setSaveStatus('⚠ Time expired!', '#dc3545');
                } else {
                    setSaveStatus('⚠ Save failed', '#dc3545');
                }
            })
            .catch(() => setSaveStatus('⚠ Offline — draft in browser', '#e67e22'));
    }

    // ── Confirmation before manual submit ─────────────────────────────────────
    let isAutoSubmitting = false;
    function confirmSubmission() {
        if (isAutoSubmitting) return true;
        const answeredMcq  = document.querySelectorAll('input[type="radio"]:checked').length;
        let   answeredDesc = 0;
        document.querySelectorAll('textarea.descriptive-input').forEach(t => {
            if (t.value.trim().length > 0) answeredDesc++;
        });
        const answered = answeredMcq + answeredDesc;
        if (answered < TOTAL_Q) {
            return confirm(`You have answered ${answered} of ${TOTAL_Q} questions. Submit anyway?`);
        }
        return confirm('Are you sure you want to submit your exam?');
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
        timerText.textContent = "00:00 — Time's Up!";
        timerBox.classList.add('warning');
        alert(reason + "\nYour exam is being submitted automatically.");
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
    }, 30000);
    </script>

<?php endif; ?>

<?php include '../includes/footer.php'; ?>
