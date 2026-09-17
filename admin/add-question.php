<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error             = "";
$success           = "";
$pre_selected_exam = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$selected_qtype    = 'descriptive';

// Flash messages
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    $pre_selected_exam = (int)($_SESSION['flash_exam_id'] ?? $pre_selected_exam);
    unset($_SESSION['flash_success'], $_SESSION['flash_exam_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $exam_id       = (int)($_POST['exam_id'] ?? 0);
        $question_text = trim($_POST['question_text'] ?? '');
        $question_type = ($_POST['question_type'] ?? 'descriptive') === 'mcq' ? 'mcq' : 'descriptive';
        $selected_qtype = $question_type;

        if (empty($exam_id) || empty($question_text)) {
            $error = "Please select an exam and provide the question text.";
        } elseif (strlen($question_text) > 2000) {
            $error = "Question text is too long (max 2000 characters).";
        } else {
            // Verify exam exists
            $stmt_chk = mysqli_prepare($conn, "SELECT id FROM exams WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_chk, "i", $exam_id);
            mysqli_stmt_execute($stmt_chk);
            mysqli_stmt_store_result($stmt_chk);
            $exam_exists = mysqli_stmt_num_rows($stmt_chk) > 0;
            mysqli_stmt_close($stmt_chk);

            if (!$exam_exists) {
                $error = "Invalid exam selected.";
            } elseif ($question_type === 'descriptive') {
                // Descriptive Question: read marks from form (default 5)
                $desc_marks = max(0.5, (float)($_POST['desc_marks'] ?? 5));

                $stmt = mysqli_prepare($conn, "INSERT INTO questions (exam_id, question_text, question_type, marks, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, 'descriptive', ?, NULL, NULL, NULL, NULL, NULL)");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "isd", $exam_id, $question_text, $desc_marks);
                    if (mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);
                        $_SESSION['flash_success'] = "Descriptive Question added successfully! (Exams with descriptive questions will hold results for review).";
                        $_SESSION['flash_exam_id'] = $exam_id;
                        safe_redirect("add-question.php?exam_id=$exam_id");
                    } else {
                        $error = "Error adding question: " . mysqli_error($conn);
                        mysqli_stmt_close($stmt);
                    }
                } else {
                    $error = "Database query error.";
                }
            } else {
                // MCQ Question — 1 mark each
                $option_a      = trim($_POST['option_a'] ?? '');
                $option_b      = trim($_POST['option_b'] ?? '');
                $option_c      = trim($_POST['option_c'] ?? '');
                $option_d      = trim($_POST['option_d'] ?? '');
                $correct_option = strtoupper(trim($_POST['correct_option'] ?? ''));

                // Validate option text lengths
                if (empty($option_a) || empty($option_b) || empty($option_c) || empty($option_d) || empty($correct_option)) {
                    $error = "Please fill in all 4 multiple choice options and specify the correct option.";
                } elseif (!in_array($correct_option, ['A','B','C','D'])) {
                    $error = "Correct option must be A, B, C, or D.";
                } elseif (strlen($option_a) > 500 || strlen($option_b) > 500 || strlen($option_c) > 500 || strlen($option_d) > 500) {
                    $error = "Option text is too long (max 500 characters each).";
                } else {
                    $mcq_marks = max(0.5, (float)($_POST['mcq_marks'] ?? 1));
                    $stmt = mysqli_prepare($conn, "INSERT INTO questions (exam_id, question_text, question_type, marks, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, 'mcq', ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "isdsssss", $exam_id, $question_text, $mcq_marks, $option_a, $option_b, $option_c, $option_d, $correct_option);
                        if (mysqli_stmt_execute($stmt)) {
                            mysqli_stmt_close($stmt);
                            $_SESSION['flash_success'] = "MCQ Question added successfully!";
                            $_SESSION['flash_exam_id'] = $exam_id;
                            safe_redirect("add-question.php?exam_id=$exam_id");
                        } else {
                            $error = "Error adding question: " . mysqli_error($conn);
                            mysqli_stmt_close($stmt);
                        }
                    } else {
                        $error = "Database query error.";
                    }
                }
            }

            if (empty($error)) {
                $pre_selected_exam = $exam_id;
            }
        }
    }
}

$exams = mysqli_query($conn, "SELECT id, title FROM exams ORDER BY title ASC");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-plus-circle text-primary"></i> Add New Question</h4>
        <small class="text-muted">Create multiple choice (MCQ) or descriptive (written) questions.</small>
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
        <form method="POST" action="" onreset="setTimeout(() => toggleQuestionType(document.getElementById('questionTypeSelect').value), 0)">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="row g-3 mb-4">
                <div class="col-md-7">
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
                <div class="col-md-5">
                    <label class="form-label fw-bold text-dark">Question Format</label>
                    <select name="question_type" id="questionTypeSelect" class="form-select form-select-lg" onchange="toggleQuestionType(this.value)">
                        <option value="descriptive" <?php echo ($selected_qtype === 'descriptive') ? 'selected' : ''; ?>>Descriptive (Written Answer)</option>
                        <option value="mcq" <?php echo ($selected_qtype === 'mcq') ? 'selected' : ''; ?>>Multiple Choice (MCQ)</option>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold text-dark">Question Text</label>
                <textarea name="question_text" class="form-control" rows="3" placeholder="Type your question prompt or essay topic here..." required maxlength="2000"></textarea>
            </div>

            <!-- MCQ Fields Container -->
            <div id="mcqContainer" style="<?php echo ($selected_qtype === 'descriptive') ? 'display: none;' : ''; ?>">
                <div class="card bg-light border-0 p-3 mb-4 rounded-3">
                    <h6 class="fw-bold mb-3 text-secondary"><i class="bi bi-ui-checks me-1"></i> Multiple Choice Options</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Option A</label>
                            <input type="text" name="option_a" id="opt_a" class="form-control bg-white" placeholder="Option A text" maxlength="500">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Option B</label>
                            <input type="text" name="option_b" id="opt_b" class="form-control bg-white" placeholder="Option B text" maxlength="500">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Option C</label>
                            <input type="text" name="option_c" id="opt_c" class="form-control bg-white" placeholder="Option C text" maxlength="500">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Option D</label>
                            <input type="text" name="option_d" id="opt_d" class="form-control bg-white" placeholder="Option D text" maxlength="500">
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold text-primary">Correct Option</label>
                    <select name="correct_option" id="correctOptSelect" class="form-select form-select-lg">
                        <option value="">-- Select which option is correct --</option>
                        <option value="A">Option A</option>
                        <option value="B">Option B</option>
                        <option value="C">Option C</option>
                        <option value="D">Option D</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label for="mcq_marks" class="form-label fw-semibold">
                        <i class="bi bi-award text-primary me-1"></i> Marks for this Question
                    </label>
                    <input type="number"
                           name="mcq_marks"
                           id="mcq_marks"
                           class="form-control"
                           min="0.5"
                           max="100"
                           step="0.5"
                           value="1"
                           style="max-width: 160px;">
                    <div class="form-text">Default is 1. You can set any value (e.g. 1, 2, 5).</div>
                </div>
            </div>

            <!-- Descriptive Notice Container -->
            <div id="descriptiveNotice" class="mb-4" style="<?php echo ($selected_qtype === 'descriptive') ? 'display: block;' : 'display: none;'; ?>">
                <div class="alert alert-info border-0 bg-info-subtle">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-info-circle-fill text-info fs-5 mt-0.5"></i>
                        <div>
                            <strong class="text-dark d-block mb-1">Descriptive / Subjective Question Format:</strong>
                            <p class="mb-0 text-muted small">
                                Students will receive an open text box to write their response. Any exam containing descriptive questions will automatically hold student results in <strong>Pending Review</strong> status until an instructor grades the answer in the <em>Results</em> section.
                            </p>
                        </div>
                    </div>
                </div>
                <!-- Max Marks for descriptive -->
                <div class="mb-3">
                    <label for="desc_marks" class="form-label fw-semibold">
                        <i class="bi bi-award text-primary me-1"></i> Max Marks for this Question
                    </label>
                    <input type="number"
                           name="desc_marks"
                           id="desc_marks"
                           class="form-control"
                           min="0.5"
                           max="100"
                           step="0.5"
                           value="5"
                           style="max-width: 160px;">
                    <div class="form-text">Default is 5. You can set any value (e.g. 2, 5, 10).</div>
                </div>
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

<script>
function toggleQuestionType(type) {
    const mcqBox = document.getElementById('mcqContainer');
    const descNotice = document.getElementById('descriptiveNotice');
    const optA = document.getElementById('opt_a');
    const optB = document.getElementById('opt_b');
    const optC = document.getElementById('opt_c');
    const optD = document.getElementById('opt_d');
    const correctOpt = document.getElementById('correctOptSelect');

    if (type === 'descriptive') {
        mcqBox.style.display = 'none';
        descNotice.style.display = 'block';
        optA.removeAttribute('required');
        optB.removeAttribute('required');
        optC.removeAttribute('required');
        optD.removeAttribute('required');
        correctOpt.removeAttribute('required');
    } else {
        mcqBox.style.display = 'block';
        descNotice.style.display = 'none';
        optA.setAttribute('required', 'required');
        optB.setAttribute('required', 'required');
        optC.setAttribute('required', 'required');
        optD.setAttribute('required', 'required');
        correctOpt.setAttribute('required', 'required');
    }
}
// Init on load
document.addEventListener('DOMContentLoaded', () => {
    toggleQuestionType(document.getElementById('questionTypeSelect').value);
});
</script>

<?php include '../includes/footer.php'; ?>
