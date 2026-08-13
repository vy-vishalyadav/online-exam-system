<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

$error = "";
$success = "";

// Handle Delete Result
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = (int)$_GET['id'];
    if (mysqli_query($conn, "DELETE FROM results WHERE id = $delete_id")) {
        $success = "Result record deleted successfully!";
    } else {
        $error = "Failed to delete result: " . mysqli_error($conn);
    }
}

// Search & Filter
$search = trim(mysqli_real_escape_string($conn, $_GET['search'] ?? ''));
$where_sql = "";
if (!empty($search)) {
    $where_sql = "WHERE s.name LIKE '%$search%' OR s.email LIKE '%$search%' OR e.title LIKE '%$search%'";
}

$results_query = "SELECT r.*, s.name AS student_name, s.email, e.title AS exam_title,
                 (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS total_q
                 FROM results r
                 JOIN students s ON r.student_id = s.id
                 JOIN exams e ON r.exam_id = e.id
                 $where_sql
                 ORDER BY r.attempted_at DESC";
$results = mysqli_query($conn, $results_query);

// Summary Stats
$q_stats = mysqli_query($conn, "SELECT 
    COUNT(*) AS total_attempts,
    SUM(CASE WHEN score >= 50 THEN 1 ELSE 0 END) AS passed_count,
    SUM(CASE WHEN score < 50 THEN 1 ELSE 0 END) AS failed_count,
    AVG(score) AS avg_score
    FROM results");
$stats = ($q_stats ? mysqli_fetch_assoc($q_stats) : null);
$total_attempts = $stats['total_attempts'] ?? 0;
$passed_count = $stats['passed_count'] ?? 0;
$failed_count = $stats['failed_count'] ?? 0;
$avg_score = round($stats['avg_score'] ?? 0, 1);
$pass_rate = $total_attempts > 0 ? round(($passed_count / $total_attempts) * 100, 1) : 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-data text-primary"></i> All Exam Results</h4>
        <small class="text-muted">Student performance metrics and result logs.</small>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Dashboard
    </a>
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

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card shadow-sm border-0 rounded-4 p-3 text-center bg-white">
            <small class="text-muted fw-semibold">Total Attempts</small>
            <h3 class="fw-extrabold text-dark mb-0 mt-1"><?php echo $total_attempts; ?></h3>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card shadow-sm border-0 rounded-4 p-3 text-center bg-white">
            <small class="text-muted fw-semibold">Pass Rate</small>
            <h3 class="fw-extrabold text-success mb-0 mt-1"><?php echo $pass_rate; ?>%</h3>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card shadow-sm border-0 rounded-4 p-3 text-center bg-white">
            <small class="text-muted fw-semibold">Passed Attempts</small>
            <h3 class="fw-extrabold text-primary mb-0 mt-1"><?php echo $passed_count; ?></h3>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card shadow-sm border-0 rounded-4 p-3 text-center bg-white">
            <small class="text-muted fw-semibold">Average Score</small>
            <h3 class="fw-extrabold text-warning mb-0 mt-1"><?php echo $avg_score; ?>%</h3>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card shadow-sm mb-4 border-0 rounded-3">
    <div class="card-body py-3">
        <form method="GET" action="view-results.php" class="row align-items-center g-2">
            <div class="col-md-6 col-lg-5">
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search by student name, email or exam title..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary fw-semibold">Search</button>
                </div>
            </div>
            <?php if (!empty($search)): ?>
                <div class="col-auto">
                    <a href="view-results.php" class="btn btn-sm btn-link text-decoration-none">Clear Search</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Results Table -->
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Student</th>
                        <th>Email / Student ID</th>
                        <th>Exam Title</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th>Attempted On</th>
                        <th class="text-center pe-4">Action</th>
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
                            <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                            <td><strong class="text-dark"><?php echo htmlspecialchars($r['student_name']); ?></strong></td>
                            <td><code class="text-muted"><?php echo htmlspecialchars($r['email']); ?></code></td>
                            <td><?php echo htmlspecialchars($r['exam_title']); ?></td>
                            <td>
                                <span class="fw-bold fs-6 <?php echo $passed ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $r['score']; ?>%
                                </span>
                            </td>
                            <td>
                                <?php if ($passed): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-check-circle-fill me-1"></i> Passed
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-x-circle-fill me-1"></i> Failed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?php echo date('d M Y, h:i A', strtotime($r['attempted_at'])); ?></td>
                            <td class="text-center pe-4">
                                <a href="view-results.php?action=delete&id=<?php echo $r['id']; ?>" 
                                   class="btn btn-sm btn-outline-danger" 
                                   onclick="return confirm('Are you sure you want to delete this result entry?');" 
                                   title="Delete Result">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i> No exam results found matching your criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
