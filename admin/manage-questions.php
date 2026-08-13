<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$error = "";
$success = "";

// Handle Delete Question
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = (int)$_GET['id'];
    $exam_filter = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
    if (mysqli_query($conn, "DELETE FROM questions WHERE id = $delete_id")) {
        $success = "Question deleted successfully!";
    } else {
        $error = "Failed to delete question: " . mysqli_error($conn);
    }
}

// Handle Edit Question POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_question'])) {
    $question_id = (int)$_POST['question_id'];
    $exam_id = (int)$_POST['exam_id'];
    $question_text = trim(mysqli_real_escape_string($conn, $_POST['question_text'] ?? ''));
    $option_a = trim(mysqli_real_escape_string($conn, $_POST['option_a'] ?? ''));
    $option_b = trim(mysqli_real_escape_string($conn, $_POST['option_b'] ?? ''));
    $option_c = trim(mysqli_real_escape_string($conn, $_POST['option_c'] ?? ''));
    $option_d = trim(mysqli_real_escape_string($conn, $_POST['option_d'] ?? ''));
    $correct_option = strtoupper(trim(mysqli_real_escape_string($conn, $_POST['correct_option'] ?? '')));

    if (empty($question_text) || empty($option_a) || empty($option_b) || empty($option_c) || empty($option_d) || empty($correct_option) || !$exam_id) {
        $error = "All fields are required.";
    } else {
        $update_query = "UPDATE questions SET 
                            exam_id = $exam_id,
                            question_text = '$question_text',
                            option_a = '$option_a',
                            option_b = '$option_b',
                            option_c = '$option_c',
                            option_d = '$option_d',
                            correct_option = '$correct_option'
                         WHERE id = $question_id";

        if (mysqli_query($conn, $update_query)) {
            $success = "Question updated successfully!";
        } else {
            $error = "Error updating question: " . mysqli_error($conn);
        }
    }
}

// Selected Exam Filter
$selected_exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

// Fetch all exams for dropdown filter
$exams_res = mysqli_query($conn, "SELECT id, title FROM exams ORDER BY title ASC");
$all_exams = [];
if ($exams_res) {
    while ($e = mysqli_fetch_assoc($exams_res)) {
        $all_exams[] = $e;
    }
}

// Build Question Query
$where_sql = $selected_exam_id ? "WHERE q.exam_id = $selected_exam_id" : "";
$questions_query = "SELECT q.*, e.title AS exam_title 
                   FROM questions q 
                   JOIN exams e ON q.exam_id = e.id 
                   $where_sql 
                   ORDER BY q.exam_id ASC, q.id ASC";
$questions_res = mysqli_query($conn, $questions_query);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-patch-question text-primary"></i> Manage Questions</h4>
        <small class="text-muted">View, edit, and delete questions for your exams.</small>
    </div>
    <div class="d-flex gap-2">
        <a href="manage-exam.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Manage Exams
        </a>
        <a href="add-question.php<?php echo $selected_exam_id ? '?exam_id='.$selected_exam_id : ''; ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Add New Question
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
    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="card shadow-sm mb-4 border-0 rounded-3">
    <div class="card-body py-3">
        <form method="GET" action="manage-questions.php" class="row align-items-center g-2">
            <div class="col-auto">
                <label class="fw-semibold text-secondary mb-0"><i class="bi bi-funnel me-1"></i> Filter by Exam:</label>
            </div>
            <div class="col-md-4">
                <select name="exam_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">-- All Exams --</option>
                    <?php foreach ($all_exams as $ex): ?>
                        <option value="<?php echo $ex['id']; ?>" <?php echo ($selected_exam_id == $ex['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ex['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($selected_exam_id): ?>
                <div class="col-auto">
                    <a href="manage-questions.php" class="btn btn-sm btn-link text-decoration-none">Clear Filter</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Questions List -->
<?php if ($questions_res && mysqli_num_rows($questions_res) > 0): ?>
    <div class="row g-3">
        <?php 
        $count = 1;
        while ($q = mysqli_fetch_assoc($questions_res)): 
        ?>
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-primary rounded-pill me-2">Question #<?php echo $count++; ?></span>
                            <span class="badge bg-light text-dark border"><i class="bi bi-journal-bookmark me-1"></i><?php echo htmlspecialchars($q['exam_title']); ?></span>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editQuestionModal<?php echo $q['id']; ?>">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <a href="manage-questions.php?action=delete&id=<?php echo $q['id']; ?><?php echo $selected_exam_id ? '&exam_id='.$selected_exam_id : ''; ?>" 
                               class="btn btn-outline-danger"
                               onclick="return confirm('Are you sure you want to delete this question?');">
                                <i class="bi bi-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <h6 class="fw-bold text-dark mb-3"><?php echo htmlspecialchars($q['question_text']); ?></h6>
                        
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="p-2 border rounded-3 <?php echo ($q['correct_option'] === 'A') ? 'bg-success-subtle border-success text-success fw-bold' : 'bg-light'; ?>">
                                    A. <?php echo htmlspecialchars($q['option_a']); ?>
                                    <?php if ($q['correct_option'] === 'A'): ?>
                                        <i class="bi bi-check-circle-fill ms-1"></i> (Correct Answer)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-2 border rounded-3 <?php echo ($q['correct_option'] === 'B') ? 'bg-success-subtle border-success text-success fw-bold' : 'bg-light'; ?>">
                                    B. <?php echo htmlspecialchars($q['option_b']); ?>
                                    <?php if ($q['correct_option'] === 'B'): ?>
                                        <i class="bi bi-check-circle-fill ms-1"></i> (Correct Answer)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-2 border rounded-3 <?php echo ($q['correct_option'] === 'C') ? 'bg-success-subtle border-success text-success fw-bold' : 'bg-light'; ?>">
                                    C. <?php echo htmlspecialchars($q['option_c']); ?>
                                    <?php if ($q['correct_option'] === 'C'): ?>
                                        <i class="bi bi-check-circle-fill ms-1"></i> (Correct Answer)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-2 border rounded-3 <?php echo ($q['correct_option'] === 'D') ? 'bg-success-subtle border-success text-success fw-bold' : 'bg-light'; ?>">
                                    D. <?php echo htmlspecialchars($q['option_d']); ?>
                                    <?php if ($q['correct_option'] === 'D'): ?>
                                        <i class="bi bi-check-circle-fill ms-1"></i> (Correct Answer)
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Question Modal -->
            <div class="modal fade" id="editQuestionModal<?php echo $q['id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <form method="POST" action="manage-questions.php<?php echo $selected_exam_id ? '?exam_id='.$selected_exam_id : ''; ?>">
                            <div class="modal-header bg-light">
                                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Question</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4">
                                <input type="hidden" name="edit_question" value="1">
                                <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Target Exam</label>
                                    <select name="exam_id" class="form-select" required>
                                        <?php foreach ($all_exams as $ex): ?>
                                            <option value="<?php echo $ex['id']; ?>" <?php echo ($q['exam_id'] == $ex['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($ex['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Question Text</label>
                                    <textarea name="question_text" class="form-control" rows="3" required><?php echo htmlspecialchars($q['question_text']); ?></textarea>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Option A</label>
                                        <input type="text" name="option_a" class="form-control" value="<?php echo htmlspecialchars($q['option_a']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Option B</label>
                                        <input type="text" name="option_b" class="form-control" value="<?php echo htmlspecialchars($q['option_b']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Option C</label>
                                        <input type="text" name="option_c" class="form-control" value="<?php echo htmlspecialchars($q['option_c']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Option D</label>
                                        <input type="text" name="option_d" class="form-control" value="<?php echo htmlspecialchars($q['option_d']); ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold text-primary">Correct Option</label>
                                    <select name="correct_option" class="form-select" required>
                                        <option value="A" <?php echo ($q['correct_option'] === 'A') ? 'selected' : ''; ?>>Option A</option>
                                        <option value="B" <?php echo ($q['correct_option'] === 'B') ? 'selected' : ''; ?>>Option B</option>
                                        <option value="C" <?php echo ($q['correct_option'] === 'C') ? 'selected' : ''; ?>>Option C</option>
                                        <option value="D" <?php echo ($q['correct_option'] === 'D') ? 'selected' : ''; ?>>Option D</option>
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer bg-light">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Update Question</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php endwhile; ?>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0 rounded-4 p-5 text-center">
        <div class="py-4">
            <i class="bi bi-journal-x fs-1 text-muted d-block mb-3"></i>
            <h5 class="fw-bold text-dark">No Questions Found</h5>
            <p class="text-muted mb-3">There are no questions added for the selected exam yet.</p>
            <a href="add-question.php<?php echo $selected_exam_id ? '?exam_id='.$selected_exam_id : ''; ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Add Question Now
            </a>
        </div>
    </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
