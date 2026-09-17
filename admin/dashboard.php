<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$q_classes   = mysqli_query($conn, "SELECT COUNT(*) AS c FROM classes");
$total_classes = ($q_classes ? mysqli_fetch_assoc($q_classes)['c'] : 0);

$q_students  = mysqli_query($conn, "SELECT COUNT(*) AS c FROM students");
$total_students = ($q_students ? mysqli_fetch_assoc($q_students)['c'] : 0);

$q_exams     = mysqli_query($conn, "SELECT COUNT(*) AS c FROM exams");
$total_exams = ($q_exams ? mysqli_fetch_assoc($q_exams)['c'] : 0);

$q_pending   = mysqli_query($conn, "SELECT COUNT(*) AS c FROM results WHERE status = 'pending'");
$pending_count = ($q_pending ? mysqli_fetch_assoc($q_pending)['c'] : 0);

// Fetch latest 5 results for recent overview
$recent_results = mysqli_query($conn, "SELECT r.*, s.name AS student_name, e.title AS exam_title
                                      FROM results r
                                      JOIN students s ON r.student_id = s.id
                                      JOIN exams e ON r.exam_id = e.id
                                      ORDER BY r.attempted_at DESC LIMIT 5");
?>

<div class="mb-4">
    <h3 class="fw-bold text-dark mb-1">Admin Dashboard</h3>
    <p class="text-muted mb-0">Overview of classes, students, and active examination sessions.</p>
</div>

<?php if ($pending_count > 0): ?>
    <a href="view-results.php?status=pending" class="text-decoration-none">
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-4 rounded-3 shadow-sm border-0" role="alert">
            <i class="bi bi-hourglass-split fs-5 text-warning"></i>
            <div>
                <strong><?php echo $pending_count; ?> result<?php echo $pending_count > 1 ? 's' : ''; ?> waiting for your review.</strong>
                <span class="text-muted ms-1">Click to review and publish.</span>
            </div>
            <i class="bi bi-arrow-right ms-auto text-warning"></i>
        </div>
    </a>
<?php endif; ?>

<!-- Stat Cards (3 Key Metrics) -->
<div class="row g-4 mb-5">
    <div class="col-md-4">
        <a href="manage-classes.php" class="text-decoration-none">
            <div class="stat-card bg-white border-0 shadow-sm rounded-4 p-4 position-relative overflow-hidden" style="border-top: 4px solid #0284c7 !important;">
                <h6 class="mb-1 fw-semibold text-muted small text-uppercase ls-1">Total Classes</h6>
                <h2 class="fw-extrabold mb-0 text-dark"><?php echo $total_classes; ?></h2>
                <i class="bi bi-diagram-3-fill stat-icon" style="color:#0284c7; opacity:0.12;"></i>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="manage-students.php" class="text-decoration-none">
            <div class="stat-card bg-white border-0 shadow-sm rounded-4 p-4 position-relative overflow-hidden" style="border-top: 4px solid #4f46e5 !important;">
                <h6 class="mb-1 fw-semibold text-muted small text-uppercase ls-1">Total Students</h6>
                <h2 class="fw-extrabold mb-0 text-dark"><?php echo $total_students; ?></h2>
                <i class="bi bi-people-fill stat-icon" style="color:#4f46e5; opacity:0.12;"></i>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="manage-exam.php" class="text-decoration-none">
            <div class="stat-card bg-white border-0 shadow-sm rounded-4 p-4 position-relative overflow-hidden" style="border-top: 4px solid #059669 !important;">
                <h6 class="mb-1 fw-semibold text-muted small text-uppercase ls-1">Total Exams</h6>
                <h2 class="fw-extrabold mb-0 text-dark"><?php echo $total_exams; ?></h2>
                <i class="bi bi-journal-bookmark-fill stat-icon" style="color:#059669; opacity:0.12;"></i>
            </div>
        </a>
    </div>
</div>

<!-- Recent Attempts Table -->
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-primary me-2"></i> Recent Exam Attempts</h5>
        <a href="view-results.php" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill">View All Results</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Student</th>
                        <th>Exam Title</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th class="pe-4 text-end">Attempted At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recent_results && mysqli_num_rows($recent_results) > 0): ?>
                        <?php while ($r = mysqli_fetch_assoc($recent_results)): 
                            $is_pending = (($r['status'] ?? 'published') === 'pending');
                            $passed = $r['score'] >= 50;
                        ?>
                            <tr>
                                <td class="ps-4 fw-semibold text-dark"><?php echo htmlspecialchars($r['student_name']); ?></td>
                                <td class="text-dark"><?php echo htmlspecialchars($r['exam_title']); ?></td>
                                <td>
                                    <?php if ($is_pending): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Draft (<?php echo $r['score']; ?>%)</span>
                                    <?php else: ?>
                                        <span class="fw-bold text-dark"><?php echo $r['score']; ?>%</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_pending): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-3 py-1 fw-bold">
                                            <i class="bi bi-hourglass-split me-1"></i> Pending Review
                                        </span>
                                    <?php elseif ($passed): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold">Passed</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold">Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 text-end text-muted small"><?php echo date('d M Y, h:i A', strtotime($r['attempted_at'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i> No exam attempts recorded yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
