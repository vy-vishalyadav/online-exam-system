<?php
include '../includes/header.php';
include '../config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}

// Filters
$filter_exam    = isset($_GET['exam_id'])    ? (int)$_GET['exam_id']    : 0;
$filter_student = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$filter_type    = isset($_GET['type'])       ? trim($_GET['type'])       : '';

$filtered_student_name = '';
if ($filter_student > 0) {
    $s_stmt = mysqli_prepare($conn, "SELECT name FROM students WHERE id=? LIMIT 1");
    if ($s_stmt) {
        mysqli_stmt_bind_param($s_stmt, "i", $filter_student);
        mysqli_stmt_execute($s_stmt);
        $s_res = mysqli_stmt_get_result($s_stmt);
        if ($s_row = mysqli_fetch_assoc($s_res)) {
            $filtered_student_name = $s_row['name'];
        }
        mysqli_stmt_close($s_stmt);
    }
}

// Build query
$where   = [];
$params  = [];
$types   = '';

if ($filter_exam)    { $where[] = 'v.exam_id=?';    $params[] = $filter_exam;    $types .= 'i'; }
if ($filter_student) { $where[] = 'v.student_id=?'; $params[] = $filter_student; $types .= 'i'; }
if ($filter_type)    { $where[] = 'v.violation_type=?'; $params[] = $filter_type; $types .= 's'; }

$sql = "SELECT v.*, s.name AS student_name, s.email AS student_email, c.name AS class_name, e.title AS exam_title
        FROM exam_violations v
        JOIN students s ON v.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        JOIN exams e    ON v.exam_id    = e.id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY v.occurred_at DESC LIMIT 500";

$stmt = mysqli_prepare($conn, $sql);
$violations = [];
if ($stmt) {
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $violations[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Summary: top offenders
$summary_sql = "SELECT v.student_id, s.name, s.email, e.title AS exam_title, v.exam_id,
                       COUNT(*) AS total,
                       SUM(v.violation_type IN ('tab_switch','fullscreen_exit','exit_exam')) AS serious
                FROM exam_violations v
                JOIN students s ON v.student_id=s.id
                JOIN exams e ON v.exam_id=e.id
                GROUP BY v.student_id, v.exam_id
                ORDER BY serious DESC, total DESC LIMIT 20";
$summary_res  = mysqli_query($conn, $summary_sql);
$summaries    = [];
if ($summary_res) {
    while ($r = mysqli_fetch_assoc($summary_res)) $summaries[] = $r;
}

// Exams list for filter
$exams_res = mysqli_query($conn, "SELECT id, title FROM exams ORDER BY id DESC");
$exams_list = [];
if ($exams_res) while ($r = mysqli_fetch_assoc($exams_res)) $exams_list[] = $r;
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-shield-exclamation text-danger me-2"></i>Anti-Cheat Violations Log</h4>
        <small class="text-muted">Audit trail of suspicious activity during exams.</small>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary fw-semibold">
        <i class="bi bi-arrow-left me-1"></i> Dashboard
    </a>
</div>

<?php if (!empty($summaries)): ?>
<!-- Top Offenders Summary -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-danger-subtle border-0 rounded-top-4 py-3">
        <h6 class="fw-bold text-danger mb-0"><i class="bi bi-flag-fill me-2"></i>Flagged Students (Top Offenders)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Student</th>
                        <th>Exam</th>
                        <th>Serious Violations<br><small class="text-muted fw-normal">(tab-switch / fullscreen exit)</small></th>
                        <th>Total Events</th>
                        <th class="pe-4 text-end">Filter</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summaries as $s):
                        $risk = $s['serious'] >= 3 ? 'danger' : ($s['serious'] >= 1 ? 'warning' : 'secondary');
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($s['name']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($s['email']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($s['exam_title']); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $risk; ?> rounded-pill px-3 py-2 fs-6">
                                <?php echo $s['serious']; ?>
                            </span>
                        </td>
                        <td><?php echo $s['total']; ?></td>
                        <td class="pe-4 text-end">
                            <a href="?student_id=<?php echo $s['student_id']; ?>&exam_id=<?php echo $s['exam_id']; ?>"
                               class="btn btn-sm btn-outline-danger fw-semibold">
                                <i class="bi bi-search me-1"></i> View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <?php if ($filter_student > 0): ?>
                <input type="hidden" name="student_id" value="<?php echo $filter_student; ?>">
                <div class="col-12 mb-1">
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 rounded-pill">
                        <i class="bi bi-person-fill-exclamation me-1"></i> Filtering for Student: <strong><?php echo htmlspecialchars($filtered_student_name ?: "ID #$filter_student"); ?></strong>
                        <a href="violations.php<?php echo $filter_exam ? '?exam_id='.$filter_exam : ''; ?>" class="text-danger ms-2 text-decoration-none fw-bold" title="Clear student filter">&times; Clear Student</a>
                    </span>
                </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-muted mb-1">Filter by Exam</label>
                <select name="exam_id" class="form-select form-select-sm">
                    <option value="">All Exams</option>
                    <?php foreach ($exams_list as $ex): ?>
                        <option value="<?php echo $ex['id']; ?>" <?php echo ($filter_exam == $ex['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ex['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1">Filter by Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="tab_switch"      <?php echo ($filter_type==='tab_switch')      ? 'selected':''; ?>>Tab Switch</option>
                    <option value="fullscreen_exit" <?php echo ($filter_type==='fullscreen_exit') ? 'selected':''; ?>>Fullscreen Exit</option>
                    <option value="exit_exam"       <?php echo ($filter_type==='exit_exam')       ? 'selected':''; ?>>Exited Exam</option>
                    <option value="blocked_key"     <?php echo ($filter_type==='blocked_key')     ? 'selected':''; ?>>Blocked Action</option>
                </select>
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary btn-sm fw-semibold px-4">
                    <i class="bi bi-funnel me-1"></i> Apply
                </button>
                <?php if ($filter_exam || $filter_student || !empty($filter_type)): ?>
                    <a href="violations.php" class="btn btn-outline-secondary btn-sm fw-semibold px-3 ms-2">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Full Log -->
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-header bg-white border-0 rounded-top-4 py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0"><i class="bi bi-list-ul me-2 text-muted"></i>All Events
            <span class="badge bg-secondary rounded-pill ms-2"><?php echo count($violations); ?></span>
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table custom-table align-middle mb-0 small">
                <thead>
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Student</th>
                        <th>Exam</th>
                        <th>Type</th>
                        <th>Detail</th>
                        <th>IP Address</th>
                        <th class="pe-4 text-end">Occurred At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($violations)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-shield-check fs-2 d-block mb-2 text-success"></i>
                                No violations recorded. All students are behaving!
                            </td>
                        </tr>
                    <?php else:
                        $i = 1;
                        foreach ($violations as $v):
                            $badge = match($v['violation_type']) {
                                'tab_switch'      => ['bg-danger',  'bi-box-arrow-up-right', 'Tab Switch'],
                                'fullscreen_exit' => ['bg-warning text-dark', 'bi-fullscreen-exit', 'Fullscreen Exit'],
                                'exit_exam'       => ['bg-danger',  'bi-door-open-fill',     'Exited Exam'],
                                'blocked_key'     => ['bg-secondary', 'bi-slash-circle',     'Blocked Action'],
                                default           => ['bg-dark', 'bi-question', $v['violation_type']],
                            };
                    ?>
                        <tr>
                            <td class="ps-4 text-muted"><?php echo $i++; ?></td>
                            <td>
                                <a href="student-profile.php?id=<?php echo $v['student_id']; ?>" class="fw-semibold text-dark text-decoration-none">
                                    <?php echo htmlspecialchars($v['student_name']); ?>
                                    <i class="bi bi-box-arrow-up-right ms-1 small text-muted"></i>
                                </a>
                                <?php if (!empty($v['class_name'])): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-0 ms-1 small">
                                        <?php echo htmlspecialchars($v['class_name']); ?>
                                    </span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($v['student_email']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($v['exam_title']); ?></td>
                            <td>
                                <span class="badge <?php echo $badge[0]; ?> rounded-pill px-2 py-1">
                                    <i class="bi <?php echo $badge[1]; ?> me-1"></i><?php echo $badge[2]; ?>
                                </span>
                            </td>
                            <td class="text-muted"><?php echo htmlspecialchars($v['detail'] ?? '—'); ?></td>
                            <td class="font-monospace text-muted"><?php echo htmlspecialchars($v['ip_address'] ?? '—'); ?></td>
                            <td class="pe-4 text-end text-muted font-monospace"><?php echo date('d M Y, h:i:s A', strtotime($v['occurred_at'])); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
