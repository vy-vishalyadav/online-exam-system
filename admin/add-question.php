<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$error = "";
$success = "";
$pre_selected_exam = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_id = (int)($_POST['exam_id'] ?? 0);
    $question_text = trim(mysqli_real_escape_string($conn, $_POST['question_text'] ?? ''));
    $option_a = trim(mysqli_real_escape_string($conn, $_POST['option_a'] ?? ''));
    $option_b = trim(mysqli_real_escape_string($conn, $_POST['option_b'] ?? ''));
    $option_c = trim(mysqli_real_escape_string($conn, $_POST['option_c'] ?? ''));
    $option_d = trim(mysqli_real_escape_string($conn, $_POST['option_d'] ?? ''));
    $correct_option = strtoupper(trim(mysqli_real_escape_string($conn, $_POST['correct_option'] ?? '')));

    if (empty($exam_id) || empty($question_text) || empty($option_a) || empty($option_b) || empty($option_c) || empty($option_d) || empty($correct_option)) {
        $error = "Please fill in all fields including correct answer choice.";
    } else {
        $insert_query = "INSERT INTO questions (exam_id, question_text, option_a, option_b, option_c, option_d, correct_option) 
                        VALUES ($exam_id, '$question_text', '$option_a', '$option_b', '$option_c', '$option_d', '$correct_option')";

        if (mysqli_query($conn, $insert_query)) {
            $success = "Question added successfully!";
            $pre_selected_exam = $exam_id;
        } else {
            $error = "Error adding question: " . mysqli_error($conn);
        }
    }
}

$exams = mysqli_query($conn, "SELECT id, title FROM exams ORDER BY title ASC");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-plus-circle text-primary"></i> Add New Question</h4>
        <small class="text-muted">Fill in the question, options, and select the correct answer.</small>
    </div>
    <div class="d-flex gap-2">
        <a href="manage-questions.php<?php echo $pre_selected_exam ? '?exam_id='.$pre_selected_exam : ''; ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Manage Questions
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 d-flex align-items-center justify-content-between" role="alert">
        <div>
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success); ?>
        </div>
        <div>
            <a href="manage-questions.php?exam_id=<?php echo $pre_selected_exam; ?>" class="btn btn-sm btn-success text-white fw-bold me-2">View Questions</a>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-4">
        <form method="POST" action="">
            <div class="mb-4">
                <label class="form-label fw-bold text-dark">Select Target Exam</label>
                <select name="exam_id" class="form-select form-select-lg" required>
                    <option value="">-- Choose an exam --</option>
                    <?php if ($exams): ?>
                        <?php while ($e = mysqli_fetch_assoc($exams)): ?>
                            <option value="<?php echo $e['id']; ?>" <?php echo ($pre_selected_exam == $e['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($e['title']); ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold text-dark">Question Text</label>
                <textarea name="question_text" class="form-control" rows="3" placeholder="Type your question here..." required></textarea>
            </div>

            <div class="card bg-light border-0 p-3 mb-4 rounded-3">
                <h6 class="fw-bold mb-3 text-secondary"><i class="bi bi-ui-checks me-1"></i> Multiple Choice Options</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Option A</label>
                        <input type="text" name="option_a" class="form-control bg-white" placeholder="Option A text" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Option B</label>
                        <input type="text" name="option_b" class="form-control bg-white" placeholder="Option B text" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Option C</label>
                        <input type="text" name="option_c" class="form-control bg-white" placeholder="Option C text" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Option D</label>
                        <input type="text" name="option_d" class="form-control bg-white" placeholder="Option D text" required>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold text-primary">Correct Option</label>
                <select name="correct_option" class="form-select form-select-lg" required>
                    <option value="">-- Select which option is correct --</option>
                    <option value="A">Option A</option>
                    <option value="B">Option B</option>
                    <option value="C">Option C</option>
                    <option value="D">Option D</option>
                </select>
            </div>

            <div class="d-flex gap-2 pt-2">
                <button type="submit" class="btn btn-primary px-4 shadow-sm fw-bold">
                    <i class="bi bi-save me-1"></i> Save Question
                </button>
                <button type="reset" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle me-1"></i> Clear Form
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
