<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$error = "";
$success = "";

// Handle Delete Exam
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = (int)$_GET['id'];
    if (mysqli_query($conn, "DELETE FROM exams WHERE id = $delete_id")) {
        $success = "Exam deleted successfully!";
    } else {
        $error = "Failed to delete exam: " . mysqli_error($conn);
    }
}

// Handle Add Exam POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exam'])) {
    $title = trim(mysqli_real_escape_string($conn, $_POST['title'] ?? ''));
    $duration = (int)($_POST['duration_minutes'] ?? 30);

    if (empty($title)) {
        $error = "Exam Title is required.";
    } elseif ($duration < 1) {
        $error = "Duration must be at least 1 minute.";
    } else {
        $insert_query = "INSERT INTO exams (title, duration_minutes) VALUES ('$title', $duration)";
        if (mysqli_query($conn, $insert_query)) {
            $success = "Exam '$title' added successfully!";
        } else {
            $error = "Error adding exam: " . mysqli_error($conn);
        }
    }
}

// Handle Edit Exam POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_exam'])) {
    $exam_id = (int)$_POST['exam_id'];
    $title = trim(mysqli_real_escape_string($conn, $_POST['title'] ?? ''));
    $duration = (int)($_POST['duration_minutes'] ?? 30);

    if (empty($title)) {
        $error = "Exam Title is required.";
    } elseif ($duration < 1) {
        $error = "Duration must be at least 1 minute.";
    } else {
        $update_query = "UPDATE exams SET title='$title', duration_minutes=$duration WHERE id=$exam_id";
        if (mysqli_query($conn, $update_query)) {
            $success = "Exam updated successfully!";
        } else {
            $error = "Error updating exam: " . mysqli_error($conn);
        }
    }
}

// Fetch all exams with question counts
$exams = mysqli_query($conn, "SELECT e.*, (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS q_count FROM exams e ORDER BY e.id DESC");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-journal-text text-primary"></i> Manage Exams</h4>
        <small class="text-muted">Create, edit, delete, and view questions for each exam.</small>
    </div>
    <div>
        <a href="dashboard.php" class="btn btn-outline-secondary me-2">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExamModal">
            <i class="bi bi-plus-circle me-1"></i> Add New Exam
        </button>
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

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Exam Title</th>
                        <th>Duration</th>
                        <th>Questions</th>
                        <th class="text-center pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($exams && mysqli_num_rows($exams) > 0):
                        $i = 1;
                        while ($e = mysqli_fetch_assoc($exams)):
                    ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                            <td><strong class="text-dark"><?php echo htmlspecialchars($e['title']); ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><i class="bi bi-clock me-1"></i><?php echo (int)$e['duration_minutes']; ?> mins</span></td>
                            <td>
                                <a href="manage-questions.php?exam_id=<?php echo $e['id']; ?>" class="text-decoration-none">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">
                                        <i class="bi bi-patch-question me-1"></i><?php echo $e['q_count']; ?> questions
                                    </span>
                                </a>
                            </td>
                            <td class="text-center pe-4">
                                <div class="btn-group btn-group-sm">
                                    <a href="manage-questions.php?exam_id=<?php echo $e['id']; ?>" class="btn btn-outline-primary" title="Manage Questions">
                                        <i class="bi bi-list-check me-1"></i> Questions
                                    </a>
                                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editExamModal<?php echo $e['id']; ?>" title="Edit Exam">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <a href="manage-exam.php?action=delete&id=<?php echo $e['id']; ?>" 
                                       class="btn btn-outline-danger" 
                                       onclick="return confirm('Are you sure you want to delete exam &quot;<?php echo htmlspecialchars($e['title']); ?>&quot;? All associated questions and results will be deleted.');" title="Delete Exam">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                </div>

                                <!-- Edit Exam Modal -->
                                <div class="modal fade text-start" id="editExamModal<?php echo $e['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="manage-exam.php">
                                                <div class="modal-header bg-light">
                                                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Exam</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body p-4">
                                                    <input type="hidden" name="edit_exam" value="1">
                                                    <input type="hidden" name="exam_id" value="<?php echo $e['id']; ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Exam Title</label>
                                                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($e['title']); ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Duration (minutes)</label>
                                                        <input type="number" name="duration_minutes" class="form-control" min="1" value="<?php echo (int)$e['duration_minutes']; ?>" required>
                                                    </div>
                                                </div>
                                                <div class="modal-footer bg-light">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Update Exam</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                            </td>
                        </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i> No exams found. Click "Add New Exam" to create one.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Exam Modal -->
<div class="modal fade" id="addExamModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="manage-exam.php">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add New Exam</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="add_exam" value="1">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Exam Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Science & Technology Quiz" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Duration (minutes)</label>
                        <input type="number" name="duration_minutes" class="form-control" min="1" value="30" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Exam</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php 
// Open add modal automatically if requested via URL action=new
if (isset($_GET['action']) && $_GET['action'] === 'new'): 
?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var modal = new bootstrap.Modal(document.getElementById('addExamModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
