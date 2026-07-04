<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 1;
$q_exam = mysqli_query($conn, "SELECT * FROM exams WHERE id=$exam_id");
$exam = ($q_exam ? mysqli_fetch_assoc($q_exam) : null);
$questions = mysqli_query($conn, "SELECT * FROM questions WHERE exam_id=$exam_id");
$duration = $exam['duration_minutes'] ?? 30;
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><?php echo htmlspecialchars($exam['title'] ?? 'Exam'); ?></h4>
        <small class="text-muted">Read each question carefully and select the best answer.</small>
    </div>
    <div class="timer-box">
        <i class="bi bi-stopwatch"></i> Time Left: <span id="timer"><?php echo $duration; ?>:00</span>
    </div>
</div>

<form method="POST" action="result.php">
    <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">

    <?php
    $q_num = 1;
    if ($questions && mysqli_num_rows($questions) > 0):
        while ($q = mysqli_fetch_assoc($questions)):
    ?>
        <div class="question-card">
            <h6 class="fw-bold mb-3">Q<?php echo $q_num; ?>. <?php echo htmlspecialchars($q['question_text']); ?></h6>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="answer[<?php echo $q['id']; ?>]" value="A" id="q<?php echo $q['id']; ?>a">
                <label class="form-check-label" for="q<?php echo $q['id']; ?>a">A. <?php echo htmlspecialchars($q['option_a']); ?></label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="answer[<?php echo $q['id']; ?>]" value="B" id="q<?php echo $q['id']; ?>b">
                <label class="form-check-label" for="q<?php echo $q['id']; ?>b">B. <?php echo htmlspecialchars($q['option_b']); ?></label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="answer[<?php echo $q['id']; ?>]" value="C" id="q<?php echo $q['id']; ?>c">
                <label class="form-check-label" for="q<?php echo $q['id']; ?>c">C. <?php echo htmlspecialchars($q['option_c']); ?></label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="answer[<?php echo $q['id']; ?>]" value="D" id="q<?php echo $q['id']; ?>d">
                <label class="form-check-label" for="q<?php echo $q['id']; ?>d">D. <?php echo htmlspecialchars($q['option_d']); ?></label>
            </div>
        </div>
    <?php
        $q_num++;
        endwhile;
    else:
    ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-circle"></i> No questions available for this exam yet.
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between">
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <button type="submit" class="btn btn-success">
            <i class="bi bi-check-circle"></i> Submit Exam
        </button>
    </div>
</form>

<script>
// Simple timer placeholder
let totalSeconds = <?php echo $duration; ?> * 60;
const timerEl = document.getElementById('timer');

const interval = setInterval(() => {
    if (totalSeconds <= 0) {
        clearInterval(interval);
        timerEl.textContent = "Time's Up!";
        return;
    }
    totalSeconds--;
    const m = Math.floor(totalSeconds / 60);
    const s = totalSeconds % 60;
    timerEl.textContent = `${m}:${s.toString().padStart(2, '0')}`;
}, 1000);
</script>

<?php include '../includes/footer.php'; ?>
