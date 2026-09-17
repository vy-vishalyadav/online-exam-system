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

// ── Handle Delete Result (POST only, with CSRF) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_result') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $delete_id = (int)($_POST['id'] ?? 0);
        if ($delete_id > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM results WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $delete_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                $_SESSION['flash_success'] = "Result record deleted successfully!";
            } else {
                $_SESSION['flash_error'] = "Failed to delete result: " . mysqli_error($conn);
                mysqli_stmt_close($stmt);
            }
        }
        safe_redirect("view-results.php");
    }
}

// ── Handle Evaluation / Grade / Publish ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_evaluation') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again.";
    } else {
        $result_id   = (int)($_POST['result_id'] ?? 0);
        $new_status  = 'published'; // Always published instantly when admin clicks Save & Publish
        $admin_feedback = trim($_POST['admin_feedback'] ?? '');
        // Limit feedback length
        if (strlen($admin_feedback) > 2000) $admin_feedback = substr($admin_feedback, 0, 2000);
        $final_score = isset($_POST['final_score']) ? (float)$_POST['final_score'] : 0;
        if ($final_score < 0) $final_score = 0;
        $final_score = round($final_score);

        // Update descriptive marks if submitted
        if (!empty($_POST['marks']) && is_array($_POST['marks'])) {
            foreach ($_POST['marks'] as $ans_id => $marks_val) {
                $ans_id = (int)$ans_id;

                // Fetch the max marks for this answer's question
                $stmt_max = mysqli_prepare($conn, "SELECT q.marks, q.question_type FROM student_answers sa JOIN questions q ON sa.question_id = q.id WHERE sa.id = ? AND sa.result_id = ? LIMIT 1");
                $q_max_marks = 5; // fallback
                if ($stmt_max) {
                    mysqli_stmt_bind_param($stmt_max, "ii", $ans_id, $result_id);
                    mysqli_stmt_execute($stmt_max);
                    $res_max = mysqli_stmt_get_result($stmt_max);
                    if ($row_max = mysqli_fetch_assoc($res_max)) {
                        $default_m = (($row_max['question_type'] ?? '') === 'descriptive') ? 5.0 : 1.0;
                        $q_max_marks = (isset($row_max['marks']) && (float)$row_max['marks'] > 0) ? (float)$row_max['marks'] : $default_m;
                    }
                    mysqli_stmt_close($stmt_max);
                }

                $m    = min($q_max_marks, max(0.0, (float)$marks_val));
                $is_c = ($m > 0) ? 1 : 0;
                $stmt = mysqli_prepare($conn, "UPDATE student_answers SET marks_awarded = ?, is_correct = ? WHERE id = ? AND result_id = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "diii", $m, $is_c, $ans_id, $result_id);
                    mysqli_stmt_execute($stmt);
                    $stmt = null;
                }
            }
        }

        // Update results record using prepared statement
        $stmt = mysqli_prepare($conn, "UPDATE results SET score=?, status=?, admin_feedback=?, evaluated_at=NOW() WHERE id=?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "issi", $final_score, $new_status, $admin_feedback, $result_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                $_SESSION['flash_success'] = "Result #$result_id evaluated and published successfully to the student!";
                safe_redirect("view-results.php");
            } else {
                $error = "Failed to update result: " . mysqli_error($conn);
                mysqli_stmt_close($stmt);
            }
        } else {
            $error = "Database query error.";
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

// ── Search & Filter ───────────────────────────────────────────────────────────
$search        = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$class_filter  = (int)($_GET['class_id'] ?? 0);
$student_filter = (int)($_GET['student_id'] ?? 0);
$exam_filter   = (int)($_GET['exam_id'] ?? 0);

// Fetch classes for dropdown
$classes_res = mysqli_query($conn, "SELECT * FROM classes ORDER BY sort_order, name");
$all_classes = [];
while ($row = mysqli_fetch_assoc($classes_res)) $all_classes[] = $row;

// Fetch exams for dropdown
$exams_res = mysqli_query($conn, "SELECT id, title FROM exams ORDER BY title ASC");
$all_exams = [];
if ($exams_res) {
    while ($row = mysqli_fetch_assoc($exams_res)) $all_exams[] = $row;
}

// Build WHERE using prepared-style binding via SQL
$where_clauses = [];
$bind_types    = "";
$bind_params   = [];

if (!empty($search)) {
    $escaped_search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
    $like_val = "%" . $escaped_search . "%";
    $where_clauses[] = "(s.name LIKE ? OR s.email LIKE ? OR e.title LIKE ?)";
    $bind_types .= "sss";
    $bind_params[] = $like_val;
    $bind_params[] = $like_val;
    $bind_params[] = $like_val;
}
if (!empty($status_filter) && in_array($status_filter, ['pending', 'published'])) {
    $where_clauses[] = "r.status = ?";
    $bind_types .= "s";
    $bind_params[] = $status_filter;
}
if ($class_filter > 0) {
    $where_clauses[] = "s.class_id = ?";
    $bind_types .= "i";
    $bind_params[] = $class_filter;
}
if ($exam_filter > 0) {
    $where_clauses[] = "r.exam_id = ?";
    $bind_types .= "i";
    $bind_params[] = $exam_filter;
}
if ($student_filter > 0) {
    $where_clauses[] = "r.student_id = ?";
    $bind_types .= "i";
    $bind_params[] = $student_filter;
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$sql = "SELECT r.*, COALESCE(s.name, 'Deleted Student') AS student_name, COALESCE(s.email, '—') AS email, c.name AS class_name,
         COALESCE(NULLIF((SELECT COUNT(*) FROM student_answers sa WHERE sa.result_id = r.id), 0),
                  (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id)) AS total_q,
         (SELECT COUNT(*) FROM student_answers sa JOIN questions q ON sa.question_id = q.id WHERE sa.result_id = r.id AND q.question_type = 'descriptive') AS desc_q_count,
         COALESCE(NULLIF((SELECT SUM(COALESCE(q.marks, 1)) FROM student_answers sa JOIN questions q ON sa.question_id = q.id WHERE sa.result_id = r.id), 0),
                  (SELECT COALESCE(SUM(q.marks), 0) FROM questions q WHERE q.exam_id = e.id)) AS exam_total_marks
         FROM results r
         LEFT JOIN students s ON r.student_id = s.id
         LEFT JOIN classes c ON s.class_id = c.id
         LEFT JOIN exams e ON r.exam_id = e.id
         $where_sql
         ORDER BY r.attempted_at DESC";

$stmt_results = mysqli_prepare($conn, $sql);
$results_list = [];
$result_ids   = [];

if ($stmt_results) {
    if (!empty($bind_types) && !empty($bind_params)) {
        mysqli_stmt_bind_param($stmt_results, $bind_types, ...$bind_params);
    }
    mysqli_stmt_execute($stmt_results);
    $res = mysqli_stmt_get_result($stmt_results);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $results_list[] = $row;
            $result_ids[]   = (int)$row['id'];
        }
    }
    mysqli_stmt_close($stmt_results);
}

// Preload student answers for displayed results
$answers_by_result = [];
if (!empty($result_ids)) {
    $ids_str  = implode(',', array_map('intval', $result_ids));
    $sa_query = mysqli_query($conn, "SELECT sa.*, 
                                      COALESCE(q.question_text, CONCAT('Question #', sa.question_id)) AS question_text, 
                                      COALESCE(q.question_type, 'mcq') AS question_type, 
                                      q.marks AS question_marks, 
                                      q.option_a, q.option_b, q.option_c, q.option_d, q.correct_option 
                                      FROM student_answers sa
                                      LEFT JOIN questions q ON sa.question_id = q.id
                                      WHERE sa.result_id IN ($ids_str)
                                      ORDER BY sa.id ASC");
    if ($sa_query) {
        while ($sa = mysqli_fetch_assoc($sa_query)) {
            $answers_by_result[$sa['result_id']][] = $sa;
        }
    }
}

// Summary Stats
$q_stats = mysqli_query($conn, "SELECT 
    COUNT(*) AS total_attempts,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_count
    FROM results");
$stats           = ($q_stats ? mysqli_fetch_assoc($q_stats) : null);
$total_attempts  = $stats['total_attempts'] ?? 0;
$pending_count   = $stats['pending_count'] ?? 0;
$published_count = $stats['published_count'] ?? 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-data text-primary"></i> All Exam Results</h4>
        <small class="text-muted">Student performance, descriptive answers grading, and result publishing.</small>
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
    <div class="col-md-4 col-12">
        <div class="card shadow-sm border-0 rounded-4 p-3 text-center bg-white">
            <small class="text-muted fw-semibold">Total Attempts</small>
            <h3 class="fw-extrabold text-dark mb-0 mt-1"><?php echo $total_attempts; ?></h3>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="card shadow-sm border-0 rounded-4 p-3 text-center bg-white">
            <small class="text-muted fw-semibold">Pending Review</small>
            <h3 class="fw-extrabold text-warning mb-0 mt-1"><?php echo $pending_count; ?></h3>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="card shadow-sm border-0 rounded-4 p-3 text-center bg-white">
            <small class="text-muted fw-semibold">Published Results</small>
            <h3 class="fw-extrabold text-success mb-0 mt-1"><?php echo $published_count; ?></h3>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card shadow-sm mb-4 border-0 rounded-3">
    <div class="card-body py-3">
        <form method="GET" action="view-results.php" class="row align-items-center g-2">
            <?php if ($student_filter > 0): ?>
                <input type="hidden" name="student_id" value="<?php echo $student_filter; ?>">
            <?php endif; ?>
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search student, ID or exam..." value="<?php echo htmlspecialchars($search); ?>" maxlength="100">
                </div>
            </div>
            <div class="col-md-3">
                <select name="exam_id" class="form-select">
                    <option value="">All Exams</option>
                    <?php foreach ($all_exams as $ex): ?>
                    <option value="<?php echo $ex['id']; ?>" <?php echo ($exam_filter == $ex['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ex['title']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="class_id" class="form-select">
                    <option value="">All Classes</option>
                    <?php foreach ($all_classes as $cl): ?>
                    <option value="<?php echo $cl['id']; ?>" <?php echo ($class_filter == $cl['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cl['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>⏳ Pending Review</option>
                    <option value="published" <?php echo ($status_filter === 'published') ? 'selected' : ''; ?>>✅ Published</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary fw-semibold">Filter</button>
                <?php if (!empty($search) || !empty($status_filter) || $class_filter || $exam_filter || $student_filter): ?>
                    <a href="view-results.php" class="btn btn-link text-decoration-none ms-2">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Results Table -->
<div class="card shadow-sm border-0 rounded-4 mb-5">
    <!-- Top Horizontal Scrollbar Slider -->
    <div class="table-scroll-top-container d-none" id="resultsTableScrollTop">
        <div class="table-scroll-top-inner" id="resultsTableScrollTopInner"></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" id="resultsTableResponsive">
            <table class="table custom-table table-sticky-actions align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Student ID</th>
                        <th>Exam Title</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th>Attempted On</th>
                        <th class="text-center pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!empty($results_list)):
                        $i = 1;
                        foreach ($results_list as $r):
                            $is_pending = (($r['status'] ?? 'published') === 'pending');
                            $has_desc   = ((int)($r['desc_q_count'] ?? 0)) > 0;
                    ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo $i++; ?></td>
                            <td>
                                <a href="student-profile.php?id=<?php echo $r['student_id']; ?>" class="fw-bold text-dark text-decoration-none">
                                    <?php echo htmlspecialchars($r['student_name']); ?>
                                    <i class="bi bi-box-arrow-up-right ms-1 small text-muted"></i>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($r['class_name'])): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1 small">
                                        <?php echo htmlspecialchars($r['class_name']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border font-monospace"><i class="bi bi-person-badge me-1 text-primary"></i><?php echo htmlspecialchars($r['email']); ?></span></td>
                            <td>
                                <?php echo htmlspecialchars($r['exam_title']); ?>
                                <?php if ($has_desc): ?>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill ms-1 small" title="Contains descriptive questions">
                                        <i class="bi bi-file-text me-1"></i>Descriptive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $out_of = (float)($r['exam_total_marks'] ?? 0);
                                $display_total = ($out_of > 0) ? rtrim(rtrim(number_format($out_of, 2), '0'), '.') : '';
                                ?>
                                <?php if ($is_pending): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle fw-bold">
                                        Draft: <?php echo $r['score']; ?><?php echo $display_total ? " / $display_total" : ''; ?> marks
                                    </span>
                                <?php else: ?>
                                    <span class="fw-bold fs-6 text-primary">
                                        <?php echo $r['score']; ?><?php echo $display_total ? " / $display_total" : ''; ?> marks
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($is_pending): ?>
                                    <span class="badge bg-warning text-dark border rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-hourglass-split me-1"></i> Pending Review
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold">
                                        <i class="bi bi-check-circle me-1"></i> Completed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?php echo date('d M Y, h:i A', strtotime($r['attempted_at'])); ?></td>
                            <td class="text-center pe-4">
                                <div class="btn-group gap-1">
                                    <button type="button" 
                                            class="btn btn-sm <?php echo $is_pending ? 'btn-warning text-dark fw-bold' : 'btn-outline-primary'; ?> rounded-pill px-3" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#reviewModal<?php echo $r['id']; ?>"
                                            title="Review Submission & Grade">
                                        <i class="bi bi-pencil-square me-1"></i> <?php echo $is_pending ? 'Review & Grade' : 'View / Edit'; ?>
                                    </button>
                                    <!-- Delete via POST form (CSRF protected) -->
                                    <form method="POST" action="view-results.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this result record?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete_result">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" title="Delete Result">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php
                        endforeach;
                    else:
                    ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i> No exam results found matching your criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modals for Submission Review & Grading -->
<?php if (!empty($results_list)): ?>
    <?php foreach ($results_list as $r): 
        $rid = $r['id'];
        $items = $answers_by_result[$rid] ?? [];
        $has_items = !empty($items);
        $total_questions   = count($items);
        $mcq_correct_count = 0;
        $desc_items        = [];
        $mcq_items         = [];
        $total_max_marks   = 0;
        $mcq_total_marks   = 0;
        $mcq_earned_marks  = 0;
        $desc_total_marks  = 0;
        $desc_earned_marks = 0;

        foreach ($items as $it) {
            $is_desc = (($it['question_type'] ?? 'mcq') === 'descriptive');
            $q_marks = isset($it['question_marks']) && (float)$it['question_marks'] > 0
                       ? (float)$it['question_marks']
                       : ($is_desc ? 5.0 : 1.0);
            $total_max_marks += $q_marks;

            if ($is_desc) {
                $desc_items[] = $it;
                $desc_total_marks += $q_marks;
                $desc_earned_marks += (float)($it['marks_awarded'] ?? 0);
            } else {
                $mcq_items[] = $it;
                $mcq_total_marks += $q_marks;
                if (!empty($it['is_correct'])) {
                    $mcq_correct_count++;
                    $mcq_earned_marks += (isset($it['marks_awarded']) && $it['marks_awarded'] !== null)
                                         ? (float)$it['marks_awarded'] : $q_marks;
                }
            }
        }
    ?>
    <div class="modal fade" id="reviewModal<?php echo $rid; ?>" tabindex="-1" aria-labelledby="reviewModalLabel<?php echo $rid; ?>" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-height: 95vh;">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST" action="view-results.php" style="display:flex; flex-direction:column; overflow:hidden; height:100%;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="save_evaluation">
                    <input type="hidden" name="result_id" value="<?php echo $rid; ?>">

                    <div class="modal-header bg-light border-bottom px-4 py-3">
                        <div>
                            <h5 class="modal-title fw-bold text-dark" id="reviewModalLabel<?php echo $rid; ?>">
                                <i class="bi bi-person-check-fill text-primary me-2"></i> Review Submission - <?php echo htmlspecialchars($r['student_name']); ?>
                            </h5>
                            <small class="text-muted">
                                <strong>Exam:</strong> <?php echo htmlspecialchars($r['exam_title']); ?> | 
                                <strong>Student ID:</strong> <?php echo htmlspecialchars($r['email']); ?> | 
                                <strong>Submitted:</strong> <?php echo date('d M Y, h:i A', strtotime($r['attempted_at'])); ?>
                                <?php if (!empty($r['evaluated_at'])): ?>
                                    | <strong>Evaluated:</strong> <?php echo date('d M Y, h:i A', strtotime($r['evaluated_at'])); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4" style="flex:1; overflow-y:auto;">
                        <?php if ($has_items): ?>
                            <!-- Descriptive Questions Section -->
                            <?php if (!empty($desc_items)): ?>
                                <div class="mb-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                        <h6 class="fw-bold text-dark mb-0">
                                            <i class="bi bi-file-earmark-text-fill text-warning me-1"></i> Descriptive / Written Answers (<?php echo count($desc_items); ?>)
                                        </h6>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Requires Instructor Grading</span>
                                    </div>

                                    <?php foreach ($desc_items as $d_idx => $d_item): 
                                        $aid          = $d_item['id'];
                                        $current_marks = $d_item['marks_awarded'] ?? 0;
                                        $max_marks    = isset($d_item['question_marks']) && (float)$d_item['question_marks'] > 0
                                                        ? (float)$d_item['question_marks'] : 5;
                                        $half_marks   = round($max_marks / 2, 2);
                                    ?>
                                        <div class="card mb-3 border rounded-3 p-3 bg-white shadow-sm">
                                             <div class="d-flex justify-content-between align-items-start mb-2">
                                                 <span class="fw-bold text-dark">Q: <?php echo htmlspecialchars($d_item['question_text']); ?></span>
                                                 <span class="badge bg-light text-dark border">Descriptive (Max <?php echo $max_marks; ?> marks)</span>
                                             </div>

                                             <div class="p-3 bg-light rounded-3 border mb-3">
                                                 <small class="text-muted fw-bold d-block mb-1">Student's Written Response:</small>
                                                 <div class="font-monospace text-dark" style="white-space: pre-wrap; font-size: 0.95rem;">
                                                     <?php echo !empty($d_item['user_answer']) ? htmlspecialchars($d_item['user_answer']) : '<em class="text-muted">No answer written by student.</em>'; ?>
                                                 </div>
                                             </div>

                                             <div class="row align-items-center g-2">
                                                 <div class="col-auto">
                                                     <label class="form-label fw-bold mb-0 text-primary small">Marks Awarded (0 to <?php echo $max_marks; ?>):</label>
                                                 </div>
                                                 <div class="col-auto">
                                                     <input type="number" 
                                                            name="marks[<?php echo $aid; ?>]" 
                                                            id="desc_mark_<?php echo $aid; ?>"
                                                            class="form-control form-control-sm desc-mark-input-<?php echo $rid; ?> fw-bold" 
                                                            min="0" 
                                                            max="<?php echo $max_marks; ?>" 
                                                            step="0.5" 
                                                            value="<?php echo htmlspecialchars($current_marks); ?>" 
                                                            oninput="calculateTotalScore(<?php echo $rid; ?>, <?php echo $mcq_earned_marks; ?>, <?php echo $total_max_marks; ?>);"
                                                            style="width: 100px;">
                                                 </div>
                                                 <div class="col-auto">
                                                     <button type="button" class="btn btn-sm btn-outline-success" onclick="document.getElementById('desc_mark_<?php echo $aid; ?>').value = '<?php echo $max_marks; ?>'; calculateTotalScore(<?php echo $rid; ?>, <?php echo $mcq_earned_marks; ?>, <?php echo $total_max_marks; ?>);">Full Mark (<?php echo $max_marks; ?>)</button>
                                                     <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('desc_mark_<?php echo $aid; ?>').value = '<?php echo $half_marks; ?>'; calculateTotalScore(<?php echo $rid; ?>, <?php echo $mcq_earned_marks; ?>, <?php echo $total_max_marks; ?>);">Half Mark (<?php echo $half_marks; ?>)</button>
                                                     <button type="button" class="btn btn-sm btn-outline-danger" onclick="document.getElementById('desc_mark_<?php echo $aid; ?>').value = '0'; calculateTotalScore(<?php echo $rid; ?>, <?php echo $mcq_earned_marks; ?>, <?php echo $total_max_marks; ?>);">Zero (0)</button>
                                                 </div>
                                             </div>
                                         </div>
                                     <?php endforeach; ?>
                                 </div>
                             <?php endif; ?>

                            <!-- MCQ Breakdown Section -->
                            <?php if (!empty($mcq_items)): ?>
                                <div class="mb-4">
                                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                        <h6 class="fw-bold text-dark mb-0">
                                            <i class="bi bi-ui-checks text-primary me-1"></i> Multiple Choice Questions (<?php echo count($mcq_items); ?>)
                                        </h6>
                                        <span class="badge bg-success-subtle text-success border">Auto-Graded: <?php echo $mcq_correct_count; ?> / <?php echo count($mcq_items); ?> Correct</span>
                                    </div>

                                    <div class="accordion" id="mcqAccordion<?php echo $rid; ?>">
                                        <?php foreach ($mcq_items as $m_idx => $m_item): 
                                            $m_num    = $m_idx + 1;
                                            $m_correct = (bool)$m_item['is_correct'];
                                            $user_opt  = $m_item['user_answer'] ?? '';
                                            $corr_opt  = $m_item['correct_option'] ?? '';
                                        ?>
                                            <div class="accordion-item border rounded-3 mb-2 overflow-hidden">
                                                <h2 class="accordion-header">
                                                    <button class="accordion-button collapsed py-2 <?php echo $m_correct ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#mcqCol<?php echo $rid . '_' . $m_num; ?>">
                                                        <div class="d-flex align-items-center justify-content-between w-100 me-2">
                                                            <span class="small fw-bold">Q<?php echo $m_num; ?>: <?php echo htmlspecialchars($m_item['question_text']); ?></span>
                                                            <span class="badge <?php echo $m_correct ? 'bg-success' : 'bg-danger'; ?> rounded-pill ms-2">
                                                                <?php echo $m_correct ? 'Correct (+1)' : 'Incorrect (0)'; ?>
                                                            </span>
                                                        </div>
                                                    </button>
                                                </h2>
                                                <div id="mcqCol<?php echo $rid . '_' . $m_num; ?>" class="accordion-collapse collapse" data-bs-parent="#mcqAccordion<?php echo $rid; ?>">
                                                    <div class="accordion-body bg-white small py-3">
                                                        <ul class="list-group list-group-flush mb-2">
                                                            <li class="list-group-item py-1"><strong>A:</strong> <?php echo htmlspecialchars($m_item['option_a']); ?></li>
                                                            <li class="list-group-item py-1"><strong>B:</strong> <?php echo htmlspecialchars($m_item['option_b']); ?></li>
                                                            <li class="list-group-item py-1"><strong>C:</strong> <?php echo htmlspecialchars($m_item['option_c']); ?></li>
                                                            <li class="list-group-item py-1"><strong>D:</strong> <?php echo htmlspecialchars($m_item['option_d']); ?></li>
                                                        </ul>
                                                        <div class="d-flex gap-4">
                                                            <div><strong>Student Answer:</strong> Option <?php echo htmlspecialchars($user_opt ?: 'None'); ?></div>
                                                            <div class="text-success"><strong>Correct Answer:</strong> Option <?php echo htmlspecialchars($corr_opt); ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-light border text-muted mb-4">
                                <i class="bi bi-info-circle me-1"></i> Individual question responses are not available for this record (taken before answer tracking was enabled). You can still evaluate and publish the final score and feedback below.
                            </div>
                        <?php endif; ?>

                        <!-- Final Scoring & Feedback Section -->
                        <div class="p-3 bg-light rounded-4 border">
                            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-award-fill text-warning me-1"></i> Final Evaluation & Publishing</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark mb-1">Final Marks Awarded</label>
                                    <div class="input-group">
                                        <input type="number" 
                                               name="final_score" 
                                               id="final_score_input_<?php echo $rid; ?>" 
                                               class="form-control form-control-lg fw-extrabold text-primary" 
                                               min="0" 
                                               max="<?php echo max(100, $total_max_marks); ?>" 
                                               value="<?php echo $r['score']; ?>" 
                                               required>
                                        <span class="input-group-text fw-bold">/ <?php echo $total_max_marks; ?> marks</span>
                                    </div>
                                    <?php if ($has_items && !empty($desc_items)): ?>
                                        <button type="button" 
                                                class="btn btn-sm btn-link text-decoration-none px-0 mt-1" 
                                                onclick="calculateTotalScore(<?php echo $rid; ?>, <?php echo $mcq_earned_marks; ?>)">
                                            <i class="bi bi-arrow-clockwise me-1"></i> Auto-Sum from Question Marks
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark mb-1">Grading Summary</label>
                                    <div class="border rounded-3 p-3 bg-white text-muted small">
                                        <div>Total Questions: <strong class="text-dark"><?php echo $r['total_q']; ?></strong> (<?php echo count($mcq_items); ?> MCQ, <?php echo count($desc_items); ?> Desc)</div>
                                        <div>Total Max Marks: <strong class="text-dark"><?php echo $total_max_marks; ?> marks</strong></div>
                                        <div>MCQ Marks Earned: <strong class="text-dark"><?php echo $mcq_earned_marks; ?> / <?php echo $mcq_total_marks; ?> marks</strong></div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold text-dark mb-1">Instructor Feedback &amp; Comments (Optional)</label>
                                    <textarea name="admin_feedback" 
                                              class="form-control" 
                                              rows="3" 
                                              placeholder="Write personalized feedback, comments on student answers, or rubric evaluation notes here..."
                                              maxlength="2000"><?php echo htmlspecialchars($r['admin_feedback'] ?? ''); ?></textarea>
                                    <small class="text-muted">This feedback will be prominently displayed on the student's exam scorecard.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer bg-light px-4 py-3">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success fw-bold px-4">
                            <i class="bi bi-check2-circle me-1"></i> Save &amp; Publish Result
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script>
    function calculateTotalScore(resultId, mcqEarnedMarks) {
        let descInputs = document.querySelectorAll('.desc-mark-input-' + resultId);
        let descEarned = 0;
        descInputs.forEach(function(input) {
            let val = parseFloat(input.value);
            if (!isNaN(val)) {
                descEarned += val;
            }
        });

        let totalEarned = (parseFloat(mcqEarnedMarks) || 0) + descEarned;
        if (totalEarned < 0) totalEarned = 0;

        let scoreInput = document.getElementById('final_score_input_' + resultId);
        if (scoreInput) {
            scoreInput.value = Math.round(totalEarned);
        }
    }
    </script>
<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const topScroll = document.getElementById('resultsTableScrollTop');
    const tableCont = document.getElementById('resultsTableResponsive');
    if (topScroll && tableCont) {
        const topInner = document.getElementById('resultsTableScrollTopInner');
        const table = tableCont.querySelector('table');

        function updateScrollWidth() {
            if (!table) return;
            const scrollW = table.scrollWidth;
            const clientW = tableCont.clientWidth;
            if (scrollW > clientW + 5) {
                topScroll.classList.remove('d-none');
                if (topInner) topInner.style.width = scrollW + 'px';
            } else {
                topScroll.classList.add('d-none');
            }
        }

        let isSyncing = false;
        topScroll.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                tableCont.scrollLeft = topScroll.scrollLeft;
                requestAnimationFrame(function() { isSyncing = false; });
            }
        });
        tableCont.addEventListener('scroll', function() {
            if (!isSyncing) {
                isSyncing = true;
                topScroll.scrollLeft = tableCont.scrollLeft;
                requestAnimationFrame(function() { isSyncing = false; });
            }
        });

        window.addEventListener('resize', updateScrollWidth);
        updateScrollWidth();
        setTimeout(updateScrollWidth, 300);
    }
});
</script>

<?php include '../includes/footer.php'; ?>
