<?php
// AJAX endpoint: log anti-cheat violations during exam
// Called by JS on tab-switch, fullscreen exit, blocked key press
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

include '../config/db.php';

$student_id = (int)$_SESSION['student_id'];
$exam_id    = (int)($_POST['exam_id']         ?? 0);
$type       = substr(trim($_POST['type']      ?? ''), 0, 50);
$detail     = substr(trim($_POST['detail']    ?? ''), 0, 255);
$csrf       = $_POST['csrf_token']             ?? '';

if (!$exam_id || !$type) {
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
    exit;
}

// Release session lock immediately so other student requests do not block
session_write_close();

// Verify student has an active unsubmitted exam session for this exam
$sess_chk = mysqli_prepare($conn, "SELECT submitted FROM exam_sessions WHERE student_id = ? AND exam_id = ? LIMIT 1");
if ($sess_chk) {
    mysqli_stmt_bind_param($sess_chk, "ii", $student_id, $exam_id);
    if (!mysqli_stmt_execute($sess_chk)) {
        mysqli_stmt_close($sess_chk);
        echo json_encode(['ok' => false, 'error' => 'db_error']);
        exit;
    }
    $s_res = mysqli_stmt_get_result($sess_chk);
    $session_row = $s_res ? mysqli_fetch_assoc($s_res) : null;
    mysqli_stmt_close($sess_chk);

    if (!$session_row || (int)$session_row['submitted'] === 1) {
        echo json_encode(['ok' => false, 'error' => 'invalid_session']);
        exit;
    }
} else {
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

// Get real IP
$ip = $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['HTTP_CLIENT_IP']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';
$ip = substr(trim(explode(',', $ip)[0]), 0, 45);

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

// Debounce: prevent duplicate violation logging within 3 seconds (e.g. event burst, double-send)
$dup_check = mysqli_prepare($conn,
    "SELECT id FROM exam_violations
     WHERE student_id = ? AND exam_id = ?
       AND (violation_type = ? OR (violation_type IN ('tab_switch','fullscreen_exit','exit_exam') AND ? IN ('tab_switch','fullscreen_exit','exit_exam')))
       AND occurred_at >= DATE_SUB(NOW(), INTERVAL 3 SECOND)
     LIMIT 1");
if ($dup_check) {
    mysqli_stmt_bind_param($dup_check, "iiss", $student_id, $exam_id, $type, $type);
    mysqli_stmt_execute($dup_check);
    $dup_res = mysqli_stmt_get_result($dup_check);
    if ($dup_res && mysqli_num_rows($dup_res) > 0) {
        mysqli_stmt_close($dup_check);
        // Duplicate event within 3 seconds: return existing count without duplicate insert
        $cnt_stmt = mysqli_prepare($conn,
            "SELECT COUNT(*) as cnt FROM exam_violations
             WHERE student_id=? AND exam_id=? AND violation_type IN ('tab_switch','fullscreen_exit','exit_exam')");
        $count = 0;
        if ($cnt_stmt) {
            mysqli_stmt_bind_param($cnt_stmt, "ii", $student_id, $exam_id);
            mysqli_stmt_execute($cnt_stmt);
            $cnt_res = mysqli_stmt_get_result($cnt_stmt);
            $cnt_row = $cnt_res ? mysqli_fetch_assoc($cnt_res) : null;
            $count   = (int)($cnt_row['cnt'] ?? 0);
            mysqli_stmt_close($cnt_stmt);
        }
        echo json_encode(['ok' => true, 'violation_count' => $count, 'duplicate' => true]);
        exit;
    }
    mysqli_stmt_close($dup_check);
}

$stmt = mysqli_prepare($conn,
    "INSERT INTO exam_violations (student_id, exam_id, violation_type, detail, ip_address, user_agent)
     VALUES (?, ?, ?, ?, ?, ?)");

if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

mysqli_stmt_bind_param($stmt, "iissss", $student_id, $exam_id, $type, $detail, $ip, $ua);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$ok) {
    echo json_encode(['ok' => false, 'error' => 'log_failed']);
    exit;
}

// Return current violation count for this student+exam
$cnt_stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) as cnt FROM exam_violations
     WHERE student_id=? AND exam_id=? AND violation_type IN ('tab_switch','fullscreen_exit','exit_exam')");
$count = 0;
if ($cnt_stmt) {
    mysqli_stmt_bind_param($cnt_stmt, "ii", $student_id, $exam_id);
    mysqli_stmt_execute($cnt_stmt);
    $cnt_res = mysqli_stmt_get_result($cnt_stmt);
    $cnt_row = $cnt_res ? mysqli_fetch_assoc($cnt_res) : null;
    $count   = (int)($cnt_row['cnt'] ?? 0);
    mysqli_stmt_close($cnt_stmt);
}

echo json_encode(['ok' => true, 'violation_count' => $count]);
