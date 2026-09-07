<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

// CSRF token generation (used in exam form)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$exam_id) {
    header("Location: dashboard.php");
    exit;
}

// Fetch exam using prepared statement
$stmt = mysqli_prepare($conn, "SELECT * FROM exams WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $exam_id);
mysqli_stmt_execute($stmt);
$res_exam = mysqli_stmt_get_result($stmt);
$exam = ($res_exam ? mysqli_fetch_assoc($res_exam) : null);
mysqli_stmt_close($stmt);

if (!$exam) {
    echo '<div class="alert alert-danger">Exam not found. <a href="dashboard.php">Return to Dashboard</a></div>';
    include '../includes/footer.php';
    exit;
}

$questions_res = mysqli_query($conn, "SELECT * FROM questions WHERE exam_id = " . (int)$exam_id . " ORDER BY id ASC");
$questions     = [];
if ($questions_res) {
    while ($q = mysqli_fetch_assoc($questions_res)) {
        $questions[] = $q;
    }
}

$duration        = (int)($exam['duration_minutes'] ?? 30);
$total_questions = count($questions);

// Generate a one-time exam submission token to prevent double-submit on refresh
// Store it in session keyed by exam_id
$submit_token_key = 'exam_submit_token_' . $exam_id;
$_SESSION[$submit_token_key] = bin2hex(random_bytes(32));
$exam_submit_token = $_SESSION[$submit_token_key];
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 sticky-top bg-body py-2 z-3 border-bottom">
    <div>
        <h4 class="fw-extrabold mb-0 text-dark"><?php echo htmlspecialchars($exam['title']); ?></h4>
        <small class="text-muted"><i class="bi bi-question-circle me-1"></i> Total Questions: <strong><?php echo $total_questions; ?></strong></small>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="timer-box" id="timerContainer">
            <i class="bi bi-stopwatch-fill text-warning fs-5"></i> 
            <span id="timerText"><?php echo sprintf('%02d:00', $duration); ?></span>
        </div>
    </div>
</div>

<?php if ($total_questions === 0): ?>
    <div class="card shadow-sm border-0 rounded-4 p-5 text-center my-4">
        <i class="bi bi-exclamation-circle fs-1 text-warning d-block mb-3"></i>
        <h5 class="fw-bold">No Questions in This Exam</h5>
        <p class="text-muted">Questions have not been uploaded for this exam yet. Please contact your instructor.</p>
        <div>
            <a href="dashboard.php" class="btn btn-primary fw-bold px-4">Back to Dashboard</a>
        </div>
    </div>
<?php else: ?>

    <form method="POST" action="result.php" id="examForm" onsubmit="return confirmSubmission();">
        <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
        <input type="hidden" name="submit_exam" value="1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="exam_submit_token" value="<?php echo htmlspecialchars($exam_submit_token); ?>">

        <?php foreach ($questions as $index => $q): 
            $q_num  = $index + 1;
            $is_desc = (($q['question_type'] ?? 'mcq') === 'descriptive');
        ?>
            <div class="question-card shadow-sm mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="badge bg-primary rounded-pill px-3 py-2 fs-6">Q<?php echo $q_num; ?> of <?php echo $total_questions; ?></span>
                    <?php if ($is_desc): ?>
                        <span class="badge bg-warning text-dark border"><i class="bi bi-pencil-square me-1"></i> Descriptive Question</span>
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
                        <textarea name="descriptive_answer[<?php echo $q['id']; ?>]" 
                                  class="form-control descriptive-input p-3 shadow-sm rounded-3" 
                                  rows="5" 
                                  placeholder="Type your answer here..."
                                  maxlength="5000"></textarea>
                    </div>
                <?php else: ?>
                    <div class="options-container">
                        <label class="option-wrapper w-100" id="wrapper_<?php echo $q['id']; ?>_A">
                            <input type="radio" name="answer[<?php echo $q['id']; ?>]" value="A" onchange="selectOption(<?php echo $q['id']; ?>, 'A')">
                            <span class="fw-bold me-2 text-primary">A.</span>
                            <span class="text-dark"><?php echo htmlspecialchars($q['option_a']); ?></span>
                        </label>

                        <label class="option-wrapper w-100" id="wrapper_<?php echo $q['id']; ?>_B">
                            <input type="radio" name="answer[<?php echo $q['id']; ?>]" value="B" onchange="selectOption(<?php echo $q['id']; ?>, 'B')">
                            <span class="fw-bold me-2 text-primary">B.</span>
                            <span class="text-dark"><?php echo htmlspecialchars($q['option_b']); ?></span>
                        </label>

                        <label class="option-wrapper w-100" id="wrapper_<?php echo $q['id']; ?>_C">
                            <input type="radio" name="answer[<?php echo $q['id']; ?>]" value="C" onchange="selectOption(<?php echo $q['id']; ?>, 'C')">
                            <span class="fw-bold me-2 text-primary">C.</span>
                            <span class="text-dark"><?php echo htmlspecialchars($q['option_c']); ?></span>
                        </label>

                        <label class="option-wrapper w-100" id="wrapper_<?php echo $q['id']; ?>_D">
                            <input type="radio" name="answer[<?php echo $q['id']; ?>]" value="D" onchange="selectOption(<?php echo $q['id']; ?>, 'D')">
                            <span class="fw-bold me-2 text-primary">D.</span>
                            <span class="text-dark"><?php echo htmlspecialchars($q['option_d']); ?></span>
                        </label>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="card shadow-sm border-0 rounded-4 p-4 mb-5">
            <div class="d-flex justify-content-between align-items-center">
                <a href="dashboard.php" class="btn btn-outline-secondary px-4 fw-semibold" onclick="return confirm('Are you sure you want to exit? Your exam progress will not be saved.');">
                    <i class="bi bi-arrow-left me-1"></i> Exit Exam
                </a>
                <button type="submit" class="btn btn-success px-5 py-2.5 fw-bold shadow">
                    <i class="bi bi-check-circle-fill me-1"></i> Submit Exam
                </button>
            </div>
        </div>
    </form>

    <script>
    // Interactive Option Selection Highlight
    function selectOption(qId, choice) {
        ['A', 'B', 'C', 'D'].forEach(opt => {
            const el = document.getElementById(`wrapper_${qId}_${opt}`);
            if (el) el.classList.remove('selected');
        });
        const selectedEl = document.getElementById(`wrapper_${qId}_${choice}`);
        if (selectedEl) selectedEl.classList.add('selected');
    }

    // Confirmation before manual submission
    let isAutoSubmitting = false;
    function confirmSubmission() {
        if (isAutoSubmitting) return true;
        const total = <?php echo $total_questions; ?>;
        const answeredMcq = document.querySelectorAll('input[type="radio"]:checked').length;
        let answeredDesc = 0;
        document.querySelectorAll('textarea.descriptive-input').forEach(t => {
            if (t.value.trim().length > 0) answeredDesc++;
        });
        const answered = answeredMcq + answeredDesc;
        if (answered < total) {
            return confirm(`You have answered ${answered} out of ${total} questions. Are you sure you want to submit?`);
        }
        return confirm('Are you sure you want to submit your exam?');
    }

    // Timer Countdown Logic
    let totalSeconds = <?php echo $duration; ?> * 60;
    const timerText = document.getElementById('timerText');
    const timerContainer = document.getElementById('timerContainer');

    const interval = setInterval(() => {
        if (totalSeconds <= 0) {
            clearInterval(interval);
            timerText.textContent = "00:00 - Time's Up!";
            timerContainer.classList.add('warning');
            isAutoSubmitting = true;
            alert("Time is up! Your exam is being automatically submitted.");
            document.getElementById('examForm').submit();
            return;
        }
        totalSeconds--;
        
        if (totalSeconds <= 60) {
            timerContainer.classList.add('warning');
        }

        const m = Math.floor(totalSeconds / 60);
        const s = totalSeconds % 60;
        timerText.textContent = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }, 1000);
    </script>

<?php endif; ?>

<?php include '../includes/footer.php'; ?>
