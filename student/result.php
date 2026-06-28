<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

$student_id = (int)$_SESSION['student_id'];
$results = mysqli_query($conn, "SELECT r.*, e.title AS exam_title
                                FROM results r
                                JOIN exams e ON r.exam_id = e.id
                                WHERE r.student_id = $student_id
                                ORDER BY r.attempted_at DESC");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-data"></i> My Results</h4>
        <small class="text-muted">All exams you have attempted are listed below.</small>
    </div>
    <a href="dashboard.php" class="btn btn-outline-primary">
        <i class="bi bi-arrow-left"></i> Back to Dashboard
    </a>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Exam Title</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th>Attempted On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($results && mysqli_num_rows($results) > 0):
                        $i = 1;
                        while ($r = mysqli_fetch_assoc($results)):
                            $passed = $r['score'] >= 50;
                    ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($r['exam_title']); ?></td>
                            <td><strong><?php echo $r['score']; ?></strong></td>
                            <td>
                                <?php if ($passed): ?>
                                    <span class="badge bg-success">Passed</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Failed</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d M Y, h:i A', strtotime($r['attempted_at'])); ?></td>
                        </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="bi bi-inbox"></i> You have not attempted any exam yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
