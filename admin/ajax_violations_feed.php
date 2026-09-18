<?php
// AJAX endpoint for real-time dynamic violation feed and top offender updates
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Release session lock immediately so polling does not block other admin tabs/actions
session_write_close();

include '../config/db.php';

$filter_exam    = isset($_GET['exam_id'])    ? (int)$_GET['exam_id']    : 0;
$filter_student = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$filter_type    = isset($_GET['type'])       ? trim($_GET['type'])       : '';
$after_id       = isset($_GET['after_id'])   ? (int)$_GET['after_id']   : 0;
$full_reload    = isset($_GET['full'])       ? (int)$_GET['full']       : 0;

$where   = [];
$params  = [];
$types   = '';

if ($filter_exam)    { $where[] = 'v.exam_id=?';        $params[] = $filter_exam;    $types .= 'i'; }
if ($filter_student) { $where[] = 'v.student_id=?';     $params[] = $filter_student; $types .= 'i'; }
if ($filter_type)    { $where[] = 'v.violation_type=?'; $params[] = $filter_type;    $types .= 's'; }

// Total count matching current filter & overall latest ID
$count_sql = "SELECT COUNT(*) AS total, COALESCE(MAX(v.id), 0) AS max_id FROM exam_violations v"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$c_stmt = mysqli_prepare($conn, $count_sql);
$total_count = 0;
$max_id = 0;
if ($c_stmt) {
    if ($params) {
        mysqli_stmt_bind_param($c_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($c_stmt);
    $c_res = mysqli_stmt_get_result($c_stmt);
    if ($c_row = mysqli_fetch_assoc($c_res)) {
        $total_count = (int)$c_row['total'];
        $max_id      = (int)$c_row['max_id'];
    }
    mysqli_stmt_close($c_stmt);
}

// Optimization: If delta polling and no new violations occurred, exit early without heavy queries
if ($after_id > 0 && !$full_reload && $max_id <= $after_id) {
    echo json_encode([
        'ok'          => true,
        'total_count' => $total_count,
        'max_id'      => $max_id,
        'new_count'   => 0,
        'events'      => [],
        'summaries'   => null,
        'synced_at'   => date('h:i:s A')
    ]);
    exit;
}

// Fetch violations
$fetch_where  = $where;
$fetch_params = $params;
$fetch_types  = $types;

if ($after_id > 0 && !$full_reload) {
    $fetch_where[]  = 'v.id > ?';
    $fetch_params[] = $after_id;
    $fetch_types   .= 'i';
}

$is_delta = ($after_id > 0 && !$full_reload);
$order_clause = $is_delta ? " ORDER BY v.id ASC LIMIT 100" : " ORDER BY v.id DESC LIMIT 500";

$sql = "SELECT v.*, s.name AS student_name, s.email AS student_email, c.name AS class_name, e.title AS exam_title
        FROM exam_violations v
        JOIN students s ON v.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        JOIN exams e    ON v.exam_id    = e.id"
    . ($fetch_where ? ' WHERE ' . implode(' AND ', $fetch_where) : '')
    . $order_clause;

$stmt = mysqli_prepare($conn, $sql);
$violations_list = [];
if ($stmt) {
    if ($fetch_params) {
        mysqli_stmt_bind_param($stmt, $fetch_types, ...$fetch_params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $v_type = $row['violation_type'];
        $badge = match($v_type) {
            'tab_switch'      => ['bg' => 'bg-danger',             'icon' => 'bi-box-arrow-up-right', 'label' => 'Tab Switch'],
            'fullscreen_exit' => ['bg' => 'bg-warning text-dark', 'icon' => 'bi-fullscreen-exit',     'label' => 'Fullscreen Exit'],
            'exit_exam'       => ['bg' => 'bg-danger',             'icon' => 'bi-door-open-fill',     'label' => 'Exited Exam'],
            'blocked_key'     => ['bg' => 'bg-secondary',          'icon' => 'bi-slash-circle',       'label' => 'Blocked Action'],
            default           => ['bg' => 'bg-dark',               'icon' => 'bi-question',           'label' => $v_type],
        };

        $violations_list[] = [
            'id'             => (int)$row['id'],
            'student_id'     => (int)$row['student_id'],
            'student_name'   => $row['student_name'],
            'student_email'  => $row['student_email'],
            'class_name'     => $row['class_name'] ?? '',
            'exam_id'        => (int)$row['exam_id'],
            'exam_title'     => $row['exam_title'],
            'violation_type' => $v_type,
            'badge_bg'       => $badge['bg'],
            'badge_icon'     => $badge['icon'],
            'badge_label'    => $badge['label'],
            'detail'         => $row['detail'] ?? '—',
            'ip_address'     => $row['ip_address'] ?? '—',
            'occurred_at'    => date('d M Y, h:i:s A', strtotime($row['occurred_at'])),
            'is_serious'     => in_array($v_type, ['tab_switch', 'fullscreen_exit', 'exit_exam']),
        ];
    }
    mysqli_stmt_close($stmt);
}

// Summary: top recent offenders (scoped to current exam if filtered)
$summary_where = $filter_exam ? " WHERE v.exam_id = " . (int)$filter_exam : "";
$summary_sql = "SELECT v.student_id, s.name, s.email, e.title AS exam_title, v.exam_id,
                       COUNT(*) AS total,
                       SUM(v.violation_type IN ('tab_switch','fullscreen_exit','exit_exam')) AS serious,
                       MAX(v.occurred_at) AS latest_violation
                FROM exam_violations v
                JOIN students s ON v.student_id=s.id
                JOIN exams e ON v.exam_id=e.id
                $summary_where
                GROUP BY v.student_id, v.exam_id
                ORDER BY latest_violation DESC, serious DESC, total DESC LIMIT 100";
$summary_res = mysqli_query($conn, $summary_sql);
$summaries   = [];
if ($summary_res) {
    while ($r = mysqli_fetch_assoc($summary_res)) {
        $serious = (int)$r['serious'];
        $risk = $serious >= 3 ? 'danger' : ($serious >= 1 ? 'warning' : 'secondary');
        $summaries[] = [
            'student_id'   => (int)$r['student_id'],
            'name'         => $r['name'],
            'email'        => $r['email'],
            'exam_title'   => $r['exam_title'],
            'exam_id'      => (int)$r['exam_id'],
            'total'        => (int)$r['total'],
            'serious'      => $serious,
            'risk_badge'   => $risk,
            'latest_time'  => !empty($r['latest_violation']) ? date('d M, h:i A', strtotime($r['latest_violation'])) : '',
        ];
    }
}

echo json_encode([
    'ok'           => true,
    'total_count'  => $total_count,
    'max_id'       => $max_id,
    'new_count'    => count($violations_list),
    'events'       => $violations_list,
    'summaries'    => $summaries,
    'synced_at'    => date('h:i:s A')
]);
