<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$exams = mysqli_query($conn, "SELECT id, title FROM exams ORDER BY title ASC");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-plus-circle"></i> Add New Question</h4>
        <small class="text-muted">Fill in the question details below.</small>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back
    </a>
</div>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label fw-semibold">Select Exam</label>
                <select name="exam_id" class="form-select" required>
                    <option value="">-- Choose an exam --</option>
                    <?php while ($e = mysqli_fetch_assoc($exams)): ?>
                        <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['title']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Question Text</label>
                <textarea name="question_text" class="form-control" rows="3" placeholder="Enter the question..." required></textarea>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Option A</label>
                    <input type="text" name="option_a" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Option B</label>
                    <input type="text" name="option_b" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Option C</label>
                    <input type="text" name="option_c" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Option D</label>
                    <input type="text" name="option_d" class="form-control" required>
                </div>
            </div>

            <div class="mt-3 mb-3">
                <label class="form-label fw-semibold">Correct Option</label>
                <select name="correct_option" class="form-select" required>
                    <option value="">-- Select correct answer --</option>
                    <option value="A">Option A</option>
                    <option value="B">Option B</option>
                    <option value="C">Option C</option>
                    <option value="D">Option D</option>
                </select>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Question
                </button>
                <button type="reset" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle"></i> Clear
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
