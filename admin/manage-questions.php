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

$error   = "";
$success = "";

// ── Handle Delete Question (POST only, with CSRF) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_question') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $delete_id   = (int)($_POST['id'] ?? 0);
        $exam_filter = (int)($_POST['exam_id'] ?? 0);
        if ($delete_id > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM questions WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $delete_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                $_SESSION['flash_success'] = "Question deleted successfully!";
            } else {
                $_SESSION['flash_error'] = "Failed to delete question: " . mysqli_error($conn);
                mysqli_stmt_close($stmt);
            }
        }
        $redirect = "manage-questions.php" . ($exam_filter ? "?exam_id=$exam_filter" : "");
        header("Location: $redirect");
        exit;
    }
}

// ── Handle Edit Question POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_question'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $question_id   = (int)($_POST['question_id'] ?? 0);
        $exam_id       = (int)($_POST['exam_id'] ?? 0);
        $question_text = trim($_POST['question_text'] ?? '');
        $question_type = ($_POST['question_type'] ?? 'mcq') === 'descriptive' ? 'descriptive' : 'mcq';

        if (empty($question_text) || !$exam_id) {
            $error = "Exam and Question text are required.";
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
                $stmt = mysqli_prepare($conn, "UPDATE questions SET exam_id=?, question_text=? WHERE id=?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "isi", $exam_id, $question_text, $question_id);
                    if (mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);
                        $_SESSION['flash_success'] = "Descriptive question updated successfully!";
                        $selected_exam_id_redir = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
                        header("Location: manage-questions.php" . ($selected_exam_id_redir ? "?exam_id=$selected_exam_id_redir" : ""));
                        exit;
                    } else {
                        $error = "Error updating question: " . mysqli_error($conn);
                        mysqli_stmt_close($stmt);
                    }
                } else {
                    $error = "Database query error.";
                }
            } else {
                $option_a       = trim($_POST['option_a'] ?? '');
                $option_b       = trim($_POST['option_b'] ?? '');
                $option_c       = trim($_POST['option_c'] ?? '');
                $option_d       = trim($_POST['option_d'] ?? '');
                $correct_option = strtoupper(trim($_POST['correct_option'] ?? ''));

                if (empty($option_a) || empty($option_b) || empty($option_c) || empty($option_d) || empty($correct_option)) {
                    $error = "All options and correct choice are required for MCQ.";
                } elseif (!in_array($correct_option, ['A','B','C','D'])) {
                    $error = "Correct option must be A, B, C, or D.";
                } elseif (strlen($option_a) > 500 || strlen($option_b) > 500 || strlen($option_c) > 500 || strlen($option_d) > 500) {
                    $error = "Option text is too long (max 500 characters each).";
                } else {
                    $stmt = mysqli_prepare($conn, "UPDATE questions SET exam_id=?, question_text=?, option_a=?, option_b=?, option_c=?, option_d=?, correct_option=? WHERE id=?");
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "issssssi", $exam_id, $question_text, $option_a, $option_b, $option_c, $option_d, $correct_option, $question_id);
                        if (mysqli_stmt_execute($stmt)) {
                            mysqli_stmt_close($stmt);
                            $_SESSION['flash_success'] = "Question updated successfully!";
                            $selected_exam_id_redir = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
                            header("Location: manage-questions.php" . ($selected_exam_id_redir ? "?exam_id=$selected_exam_id_redir" : ""));
                            exit;
                        } else {
                            $error = "Error updating question: " . mysqli_error($conn);
                            mysqli_stmt_close($stmt);
                        }
                    } else {
                        $error = "Database query error.";
                    }
                }
            }
        }
    }
}

// Flash messages
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
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

// Build Question Query (no user-supplied raw string in WHERE — only cast int)
if ($selected_exam_id) {
    $questions_res = mysqli_query($conn, "SELECT q.*, e.title AS exam_title 
                       FROM questions q 
                       JOIN exams e ON q.exam_id = e.id 
                       WHERE q.exam_id = $selected_exam_id
                       ORDER BY q.exam_id ASC, q.id ASC");
} else {
    $questions_res = mysqli_query($conn, "SELECT q.*, e.title AS exam_title 
                       FROM questions q 
                       JOIN exams e ON q.exam_id = e.id 
                       ORDER BY q.exam_id ASC, q.id ASC");
}
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
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary rounded-pill">Question #<?php echo $count++; ?></span>
                            <span class="badge bg-light text-dark border"><i class="bi bi-journal-bookmark me-1"></i><?php echo htmlspecialchars($q['exam_title']); ?></span>
                            <?php if (($q['question_type'] ?? 'mcq') === 'descriptive'): ?>
                                <span class="badge bg-warning text-dark border rounded-pill"><i class="bi bi-pencil-square me-1"></i> Descriptive</span>
                            <?php else: ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">MCQ</span>
                            <?php endif; ?>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editQuestionModal<?php echo $q['id']; ?>">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <!-- Delete via POST form -->
                            <form method="POST" action="manage-questions.php<?php echo $selected_exam_id ? '?exam_id='.$selected_exam_id : ''; ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="delete_question">
                                <input type="hidden" name="id" value="<?php echo $q['id']; ?>">
                                <input type="hidden" name="exam_id" value="<?php echo $selected_exam_id; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <h6 class="fw-bold text-dark mb-3"><?php echo htmlspecialchars($q['question_text']); ?></h6>
                        
                        <?php if (($q['question_type'] ?? 'mcq') === 'descriptive'): ?>
                            <div class="p-3 bg-light rounded-3 border text-muted">
                                <i class="bi bi-card-text text-primary me-1"></i> <strong>Open Written Format:</strong> Students will write their detailed explanation in an open text area. Submissions will be held for manual evaluation.
                            </div>
                        <?php else: ?>
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
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Edit Question Modal -->
            <div class="modal fade" id="editQuestionModal<?php echo $q['id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <form method="POST" action="manage-questions.php<?php echo $selected_exam_id ? '?exam_id='.$selected_exam_id : ''; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="modal-header bg-light">
                                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Question</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4">
                                <input type="hidden" name="edit_question" value="1">
                                <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                <input type="hidden" name="question_type" value="<?php echo $q['question_type'] ?? 'mcq'; ?>">

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
                                    <textarea name="question_text" class="form-control" rows="3" required maxlength="2000"><?php echo htmlspecialchars($q['question_text']); ?></textarea>
                                </div>

                                <?php if (($q['question_type'] ?? 'mcq') === 'descriptive'): ?>
                                    <div class="alert alert-info border-0 bg-info-subtle">
                                        <i class="bi bi-info-circle me-1"></i> This is a descriptive question. Options and correct answer are not required.
                                    </div>
                                <?php else: ?>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Option A</label>
                                            <input type="text" name="option_a" class="form-control" value="<?php echo htmlspecialchars($q['option_a']); ?>" required maxlength="500">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Option B</label>
                                            <input type="text" name="option_b" class="form-control" value="<?php echo htmlspecialchars($q['option_b']); ?>" required maxlength="500">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Option C</label>
                                            <input type="text" name="option_c" class="form-control" value="<?php echo htmlspecialchars($q['option_c']); ?>" required maxlength="500">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Option D</label>
                                            <input type="text" name="option_d" class="form-control" value="<?php echo htmlspecialchars($q['option_d']); ?>" required maxlength="500">
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
                                <?php endif; ?>
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
