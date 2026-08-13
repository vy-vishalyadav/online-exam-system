<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$q_students = mysqli_query($conn, "SELECT COUNT(*) AS c FROM students");
$total_students = ($q_students ? mysqli_fetch_assoc($q_students)['c'] : 0);

$q_exams = mysqli_query($conn, "SELECT COUNT(*) AS c FROM exams");
$total_exams = ($q_exams ? mysqli_fetch_assoc($q_exams)['c'] : 0);

$q_questions = mysqli_query($conn, "SELECT COUNT(*) AS c FROM questions");
$total_questions = ($q_questions ? mysqli_fetch_assoc($q_questions)['c'] : 0);

$q_results = mysqli_query($conn, "SELECT COUNT(*) AS c FROM results");
$total_results = ($q_results ? mysqli_fetch_assoc($q_results)['c'] : 0);

// Fetch latest 5 results for recent overview
$recent_results = mysqli_query($conn, "SELECT r.*, s.name AS student_name, e.title AS exam_title
                                      FROM results r
                                      JOIN students s ON r.student_id = s.id
                                      JOIN exams e ON r.exam_id = e.id
                                      ORDER BY r.attempted_at DESC LIMIT 5");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Admin Dashboard</h3>
        <p class="text-muted mb-0">Overview of students, exams, questions, and attempt analytics.</p>
    </div>
    <div>
        <a href="manage-exam.php" class="btn btn-primary shadow-sm fw-bold">
            <i class="bi bi-plus-circle me-1"></i> Manage Exams
        </a>
    </div>
</div>

<!-- Stat Cards with Guaranteed High Contrast -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <a href="manage-students.php" class="text-decoration-none">
            <div class="stat-card stat-card-blue bg-gradient-blue" style="background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%) !important; color: #ffffff !important;">
                <h6 class="mb-1 fw-bold text-white opacity-75">Total Students</h6>
                <h2 class="fw-extrabold mb-0 text-white"><?php echo $total_students; ?></h2>
                <i class="bi bi-people-fill stat-icon text-white"></i>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl-3">
        <a href="manage-exam.php" class="text-decoration-none">
            <div class="stat-card stat-card-green bg-gradient-green" style="background: linear-gradient(135deg, #059669 0%, #047857 100%) !important; color: #ffffff !important;">
                <h6 class="mb-1 fw-bold text-white opacity-75">Total Exams</h6>
                <h2 class="fw-extrabold mb-0 text-white"><?php echo $total_exams; ?></h2>
                <i class="bi bi-journal-bookmark-fill stat-icon text-white"></i>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl-3">
        <a href="manage-questions.php" class="text-decoration-none">
            <div class="stat-card stat-card-purple" style="background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%) !important; color: #ffffff !important;">
                <h6 class="mb-1 fw-bold text-white opacity-75">Total Questions</h6>
                <h2 class="fw-extrabold mb-0 text-white"><?php echo $total_questions; ?></h2>
                <i class="bi bi-patch-question-fill stat-icon text-white"></i>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl-3">
        <a href="view-results.php" class="text-decoration-none">
            <div class="stat-card stat-card-orange bg-gradient-orange" style="background: linear-gradient(135deg, #d97706 0%, #b45309 100%) !important; color: #ffffff !important;">
                <h6 class="mb-1 fw-bold text-white opacity-75">Total Attempts</h6>
                <h2 class="fw-extrabold mb-0 text-white"><?php echo $total_results; ?></h2>
                <i class="bi bi-bar-chart-fill stat-icon text-white"></i>
            </div>
        </a>
    </div>
</div>

<!-- Quick Actions -->
<h5 class="fw-bold mb-3 text-dark"><i class="bi bi-lightning-charge text-warning me-1"></i> Quick Actions</h5>
<div class="row g-3 mb-5">
    <div class="col-md-3 col-sm-6">
        <a href="manage-exam.php?action=new" class="text-decoration-none">
            <div class="hover-card p-3 text-center">
                <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center p-3 mb-2" style="width: 54px; height: 54px;">
                    <i class="bi bi-plus-square-fill fs-4"></i>
                </div>
                <h6 class="fw-bold text-dark mb-0">Create Exam</h6>
            </div>
        </a>
    </div>
    <div class="col-md-3 col-sm-6">
        <a href="add-question.php" class="text-decoration-none">
            <div class="hover-card p-3 text-center">
                <div class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center p-3 mb-2" style="width: 54px; height: 54px;">
                    <i class="bi bi-question-circle-fill fs-4"></i>
                </div>
                <h6 class="fw-bold text-dark mb-0">Add Question</h6>
            </div>
        </a>
    </div>
    <div class="col-md-3 col-sm-6">
        <a href="manage-students.php" class="text-decoration-none">
            <div class="hover-card p-3 text-center">
                <div class="rounded-circle bg-info bg-opacity-10 text-info d-inline-flex align-items-center justify-content-center p-3 mb-2" style="width: 54px; height: 54px;">
                    <i class="bi bi-person-plus-fill fs-4"></i>
                </div>
                <h6 class="fw-bold text-dark mb-0">Add Student</h6>
            </div>
        </a>
    </div>
    <div class="col-md-3 col-sm-6">
        <a href="view-results.php" class="text-decoration-none">
            <div class="hover-card p-3 text-center">
                <div class="rounded-circle bg-warning bg-opacity-10 text-warning d-inline-flex align-items-center justify-content-center p-3 mb-2" style="width: 54px; height: 54px;">
                    <i class="bi bi-clipboard-data-fill fs-4"></i>
                </div>
                <h6 class="fw-bold text-dark mb-0">View Reports</h6>
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
                            $passed = $r['score'] >= 50;
                        ?>
                            <tr>
                                <td class="ps-4 fw-semibold text-dark"><?php echo htmlspecialchars($r['student_name']); ?></td>
                                <td class="text-dark"><?php echo htmlspecialchars($r['exam_title']); ?></td>
                                <td><span class="fw-bold text-dark"><?php echo $r['score']; ?>%</span></td>
                                <td>
                                    <?php if ($passed): ?>
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
