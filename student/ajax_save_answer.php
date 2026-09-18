<?php
// AJAX endpoint: auto-save a single answer draft while student is mid-exam
// Called via fetch() on every option select / textarea keyup
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

include '../config/db.php';

$student_id  = (int)$_SESSION['student_id'];
$exam_id     = (int)($_POST['exam_id']    ?? 0);
$question_id = (int)($_POST['question_id'] ?? 0);
$answer      = trim($_POST['answer']       ?? '');
$csrf        = $_POST['csrf_token']        ?? '';

// Basic validation
if (!$exam_id || !$question_id) {
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

// CSRF check
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
    exit;
}

// Release session lock immediately so concurrent requests (e.g. ajax_timer.php) do not serialize
session_write_close();

// Truncate descriptive answers to 5000 chars
if (strlen($answer) > 5000) {
    $answer = substr($answer, 0, 5000);
}

// Validate exam session hasn't timed out and schedule window hasn't closed (server-side check)
$sess_stmt = mysqli_prepare($conn,
    "SELECT es.duration_minutes, es.submitted,
            TIMESTAMPDIFF(SECOND, es.started_at, NOW()) AS elapsed_seconds,
            e.end_at,
            TIMESTAMPDIFF(SECOND, NOW(), e.end_at) AS window_rem_sec
     FROM exam_sessions es
     JOIN exams e ON e.id = es.exam_id
     WHERE es.student_id = ? AND es.exam_id = ? LIMIT 1");
if ($sess_stmt) {
    mysqli_stmt_bind_param($sess_stmt, "ii", $student_id, $exam_id);
    mysqli_stmt_execute($sess_stmt);
    $sess_res = mysqli_stmt_get_result($sess_stmt);
    $sess     = $sess_res ? mysqli_fetch_assoc($sess_res) : null;
    mysqli_stmt_close($sess_stmt);

    if (!$sess) {
        echo json_encode(['ok' => false, 'error' => 'no_active_session']);
        exit;
    }

    if ($sess['submitted']) {
        echo json_encode(['ok' => false, 'error' => 'already_submitted']);
        exit;
    }
    // Strict schedule deadline check: if end_at passed by > 15s grace period
    if (!empty($sess['end_at']) && isset($sess['window_rem_sec']) && (int)$sess['window_rem_sec'] < -15) {
        echo json_encode(['ok' => false, 'error' => 'exam_window_closed']);
        exit;
    }
    $elapsed  = max(0, (int)($sess['elapsed_seconds'] ?? 0));
    $allowed  = (int)$sess['duration_minutes'] * 60;
    if ($elapsed > $allowed + 30) { // 30s grace
        echo json_encode(['ok' => false, 'error' => 'time_expired']);
        exit;
    }
} else {
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

// Verify that question_id actually belongs to this exam
$q_chk = mysqli_prepare($conn, "SELECT id FROM questions WHERE id = ? AND exam_id = ? LIMIT 1");
if ($q_chk) {
    mysqli_stmt_bind_param($q_chk, "ii", $question_id, $exam_id);
    mysqli_stmt_execute($q_chk);
    $q_res = mysqli_stmt_get_result($q_chk);
    $valid_q = $q_res ? mysqli_fetch_assoc($q_res) : null;
    mysqli_stmt_close($q_chk);

    if (!$valid_q) {
        echo json_encode(['ok' => false, 'error' => 'invalid_question']);
        exit;
    }
}

// Upsert draft answer (INSERT … ON DUPLICATE KEY UPDATE)
$stmt = mysqli_prepare($conn,
    "INSERT INTO draft_answers (student_id, exam_id, question_id, answer, saved_at)
     VALUES (?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE answer = VALUES(answer), saved_at = NOW()");

if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

mysqli_stmt_bind_param($stmt, "iiis", $student_id, $exam_id, $question_id, $answer);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode(['ok' => $ok, 'saved_at' => date('H:i:s')]);
