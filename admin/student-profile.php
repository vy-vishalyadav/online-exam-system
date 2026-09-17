<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) { header("Location: ../index.php"); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$student_id = (int)($_GET['id'] ?? 0);
if (!$student_id) { safe_redirect("manage-students.php"); }

$error = $success = "";

// ── Reset password ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $hashed_pw = password_hash('student', PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE students SET password=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "si", $hashed_pw, $student_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = "Password reset to 'student'.";
        safe_redirect("student-profile.php?id=$student_id");
    }
}

// Flash messages
if (!empty($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (!empty($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Fetch student
$stmt = mysqli_prepare($conn,
    "SELECT s.*, c.name AS class_name
     FROM students s LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.id = ?");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$student) { safe_redirect("manage-students.php"); }

// Fetch exam results
$stmt = mysqli_prepare($conn,
    "SELECT r.*, e.title AS exam_title, e.duration_minutes,
            es.time_taken_seconds,
            COALESCE(NULLIF((SELECT SUM(COALESCE(q.marks, 1)) FROM student_answers sa JOIN questions q ON sa.question_id = q.id WHERE sa.result_id = r.id), 0),
                     (SELECT COALESCE(SUM(q.marks), 0) FROM questions q WHERE q.exam_id = e.id)) AS exam_total_marks
     FROM results r
     JOIN exams e ON r.exam_id = e.id
     LEFT JOIN exam_sessions es ON es.student_id = r.student_id AND es.exam_id = r.exam_id
     WHERE r.student_id = ?
     ORDER BY r.attempted_at DESC");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$results_res = mysqli_stmt_get_result($stmt);
$results = [];
while ($row = mysqli_fetch_assoc($results_res)) $results[] = $row;
mysqli_stmt_close($stmt);

// Violations summary
$stmt = mysqli_prepare($conn,
    "SELECT violation_type, COUNT(*) AS cnt
     FROM exam_violations WHERE student_id = ?
     GROUP BY violation_type ORDER BY cnt DESC");
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$viol_res = mysqli_stmt_get_result($stmt);
$violations = [];
while ($row = mysqli_fetch_assoc($viol_res)) $violations[] = $row;
mysqli_stmt_close($stmt);
$total_violations = array_sum(array_column($violations, 'cnt'));

// Stats
$total_attempts  = count($results);
$published       = array_filter($results, fn($r) => $r['status'] === 'published');
$published_count = count($published);
$avg_score       = $published_count > 0 ? round(array_sum(array_column(array_values($published), 'score')) / $published_count, 1) : 0;
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="manage-students.php" class="text-decoration-none">Students</a></li>
        <li class="breadcrumb-item active"><?php echo htmlspecialchars($student['name']); ?></li>
    </ol>
</nav>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 mb-4">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show border-0 rounded-3 mb-4 shadow-sm">
    <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Profile Card -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap gap-4 align-items-start justify-content-between">
            <div class="d-flex gap-3 align-items-center">
                <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:72px;height:72px;">
                    <i class="bi bi-person-fill fs-2 text-primary"></i>
                </div>
                <div>
                    <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($student['name']); ?></h4>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-semibold">
                            <i class="bi bi-diagram-3 me-1"></i>
                            <?php echo $student['class_name'] ? htmlspecialchars($student['class_name']) : 'No Class'; ?>
                        </span>
                        <span class="badge bg-light text-dark border font-monospace px-3 py-1">
                            <i class="bi bi-person-badge me-1 text-primary"></i>
                            <?php echo htmlspecialchars($student['email']); ?>
                        </span>
                    </div>
                </div>
            </div>
            <!-- Reset password -->
            <form method="POST" onsubmit="return confirm('Reset password to \'student\' for <?php echo htmlspecialchars($student['name'], ENT_QUOTES); ?>?')">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="reset_password">
                <button type="submit" class="btn btn-outline-warning fw-semibold">
                    <i class="bi bi-key me-1"></i> Reset Password
                </button>
            </form>
        </div>

        <!-- Stats row -->
        <div class="row g-3 mt-3">
            <div class="col-6 col-md-3">
                <div class="text-center p-3 bg-light rounded-3">
                    <div class="fw-extrabold fs-3 text-primary"><?php echo $total_attempts; ?></div>
                    <div class="text-muted small">Total Attempts</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-center p-3 bg-light rounded-3">
                    <div class="fw-extrabold fs-3 text-success"><?php echo $published_count; ?></div>
                    <div class="text-muted small">Published Results</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-center p-3 bg-light rounded-3">
                    <div class="fw-extrabold fs-3 text-dark"><?php echo $avg_score; ?> marks</div>
                    <div class="text-muted small">Avg Marks</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-center p-3 bg-<?php echo $total_violations > 0 ? 'danger' : 'success'; ?>-subtle rounded-3">
                    <div class="fw-extrabold fs-3 text-<?php echo $total_violations > 0 ? 'danger' : 'success'; ?>"><?php echo $total_violations; ?></div>
                    <div class="text-muted small">Violations</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Exam History -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 py-3 rounded-top-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-clock-history text-primary me-2"></i>Exam History</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table custom-table align-middle mb-0 small">
                        <thead>
                            <tr>
                                <th class="ps-4">#</th>
                                <th>Exam Title</th>
                                <th>Score</th>
                                <th>Time Taken</th>
                                <th>Status</th>
                                <th class="pe-4 text-end">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($results)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-journal-x fs-2 d-block mb-2"></i>No exam attempts yet.
                            </td></tr>
                            <?php else: $i = 1; foreach ($results as $r):
                                $pending = ($r['status'] ?? 'published') === 'pending';
                                $tt = (int)($r['time_taken_seconds'] ?? 0);
                            ?>
                            <tr>
                                <td class="ps-4 text-muted"><?php echo $i++; ?></td>
                                <td class="fw-semibold text-dark"><?php echo htmlspecialchars($r['exam_title']); ?></td>
                                <td>
                                    <?php 
                                    $out_of = (float)($r['exam_total_marks'] ?? 0);
                                    $display_total = ($out_of > 0) ? rtrim(rtrim(number_format($out_of, 2), '0'), '.') : '';
                                    ?>
                                    <?php if ($pending): ?>
                                        <span class="text-muted fst-italic">Draft: <?php echo $r['score']; ?><?php echo $display_total ? " / $display_total" : ''; ?> marks</span>
                                    <?php else: ?>
                                        <span class="fw-bold text-primary">
                                            <?php echo $r['score']; ?><?php echo $display_total ? " / $display_total" : ''; ?> marks
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted">
                                    <?php if ($tt > 0): echo floor($tt/60).'m '.($tt%60).'s'; else: echo '—'; endif; ?>
                                </td>
                                <td>
                                    <?php if ($pending): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2.5 py-1 fw-bold">
                                            <i class="bi bi-hourglass-split me-1"></i> Under Review
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 fw-bold">
                                            <i class="bi bi-check-circle me-1"></i> Completed
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 text-end text-muted"><?php echo date('d M Y, h:i A', strtotime($r['attempted_at'])); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Violations Sidebar -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 py-3 rounded-top-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-shield-exclamation text-danger me-2"></i>Integrity Violations</h6>
            </div>
            <div class="card-body">
                <?php if (empty($violations)): ?>
                <div class="text-center py-3">
                    <i class="bi bi-shield-check fs-2 text-success d-block mb-2"></i>
                    <p class="text-muted small mb-0">No violations recorded.<br>Clean exam history!</p>
                </div>
                <?php else:
                    $vmap = [
                        'tab_switch'      => ['danger',    'bi-box-arrow-up-right', 'Tab Switch'],
                        'fullscreen_exit' => ['warning',   'bi-fullscreen-exit',    'Fullscreen Exit'],
                        'exit_exam'       => ['danger',    'bi-door-open-fill',     'Exited Exam'],
                        'blocked_key'     => ['secondary', 'bi-slash-circle',       'Blocked Action'],
                    ];
                    foreach ($violations as $v):
                        $b = $vmap[$v['violation_type']] ?? ['dark', 'bi-question', $v['violation_type']];
                ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="badge bg-<?php echo $b[0]; ?>-subtle text-<?php echo $b[0]; ?> border border-<?php echo $b[0]; ?>-subtle rounded-pill px-3 py-2">
                        <i class="bi <?php echo $b[1]; ?> me-1"></i><?php echo $b[2]; ?>
                    </span>
                    <span class="fw-bold fs-5 text-<?php echo $b[0]; ?>"><?php echo $v['cnt']; ?></span>
                </div>
                <?php endforeach; ?>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-muted small">Total</span>
                    <span class="fw-bold text-danger"><?php echo $total_violations; ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-3">
            <a href="manage-students.php" class="btn btn-outline-secondary w-100 fw-semibold">
                <i class="bi bi-arrow-left me-1"></i> Back to Students
            </a>
            <a href="view-results.php?student_id=<?php echo $student_id; ?>" class="btn btn-outline-primary w-100 fw-semibold mt-2">
                <i class="bi bi-bar-chart me-1"></i> View in Results
            </a>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
