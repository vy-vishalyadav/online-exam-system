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
$exam_id    = (int)($_GET['exam_id'] ?? 0);

if (!$exam_id) {
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

$stmt = mysqli_prepare($conn,
    "SELECT started_at, duration_minutes, submitted FROM exam_sessions
     WHERE student_id = ? AND exam_id = ? LIMIT 1");

if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

mysqli_stmt_bind_param($stmt, "ii", $student_id, $exam_id);
mysqli_stmt_execute($stmt);
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

$elapsed   = (int)(time() - strtotime($sess['started_at']));
$total_sec = (int)$sess['duration_minutes'] * 60;
$remaining = max(0, $total_sec - $elapsed);

echo json_encode(['ok' => true, 'remaining' => $remaining, 'submitted' => false]);
