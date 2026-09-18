<?php
// AJAX endpoint: returns remaining seconds based on server-side start time
// Client calls this every 30 seconds to reconcile its timer
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

include '../config/db.php';

$student_id = (int)$_SESSION['student_id'];
session_write_close(); // Release session file lock immediately to optimize concurrent AJAX requests
$exam_id    = (int)($_GET['exam_id'] ?? 0);

if (!$exam_id) {
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

$stmt = mysqli_prepare($conn,
    "SELECT es.duration_minutes, es.submitted,
            TIMESTAMPDIFF(SECOND, es.started_at, NOW()) AS elapsed_seconds,
            e.end_at,
            TIMESTAMPDIFF(SECOND, NOW(), e.end_at) AS window_rem_sec
     FROM exam_sessions es
     JOIN exams e ON e.id = es.exam_id
     WHERE es.student_id = ? AND es.exam_id = ? LIMIT 1");

if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

mysqli_stmt_bind_param($stmt, "ii", $student_id, $exam_id);
if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}
$res  = mysqli_stmt_get_result($stmt);
$sess = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$sess) {
    echo json_encode(['ok' => false, 'error' => 'no_session']);
    exit;
}

if ($sess['submitted']) {
    echo json_encode(['ok' => true, 'remaining' => 0, 'submitted' => true]);
    exit;
}

$elapsed   = max(0, (int)($sess['elapsed_seconds'] ?? 0));
$total_sec = (int)$sess['duration_minutes'] * 60;
$personal_remaining = max(0, $total_sec - $elapsed);

if (!empty($sess['end_at'])) {
    $window_rem = (int)($sess['window_rem_sec'] ?? 0);
    $remaining  = max(0, min($personal_remaining, $window_rem));
} else {
    $remaining  = $personal_remaining;
}

echo json_encode(['ok' => true, 'remaining' => $remaining, 'submitted' => false]);
