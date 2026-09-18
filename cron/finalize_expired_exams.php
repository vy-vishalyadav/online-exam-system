<?php
/**
 * Server-Side Cron Job: Finalize Expired Unfinished Exam Attempts
 *
 * Automatically finds exam sessions where:
 *   1. Personal duration deadline has passed: started_at + duration_minutes <= NOW()
 *   2. Scheduled exam window has closed: end_at <= NOW()
 *
 * And finalizes them using the shared finalization service (includes/exam_submission.php).
 *
 * Usage:
 *   CLI:  php cron/finalize_expired_exams.php [--limit=50]
 *   Web:  curl "https://example.com/cron/finalize_expired_exams.php?token=SECRET"
 */

// 1. Authorization check
if (php_sapi_name() !== 'cli') {
    $secret = getenv('CRON_SECRET') ?: '';
    $token  = (string)($_GET['token'] ?? '');

    session_start();
    $is_admin = isset($_SESSION['admin_id']);
    session_write_close();

    if ((empty($secret) || !hash_equals($secret, $token)) && !$is_admin) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'Access denied. Valid CRON_SECRET token or administrator session required.']);
        exit;
    }
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/exam_submission.php';

// 2. Parse batch limit
$limit = 50;
if (php_sapi_name() === 'cli') {
    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $arg) {
            if (strpos($arg, '--limit=') === 0) {
                $limit = max(1, min(200, (int)substr($arg, 8)));
            }
        }
    }
} elseif (isset($_GET['limit'])) {
    $limit = max(1, min(200, (int)$_GET['limit']));
}

// 3. Execute finalizer
$summary = finalizeAllExpiredExams($conn, $limit);

// 4. Output results
if (php_sapi_name() === 'cli') {
    echo "====================================================\n";
    echo "  Online Exam System — Expired Attempt Finalizer\n";
    echo "====================================================\n";
    echo "Execution Time:  " . date('Y-m-d H:i:s') . "\n";
    echo "Batch Limit:     " . $limit . "\n";
    echo "Scanned:         " . $summary['scanned'] . "\n";
    echo "Finalized:       " . $summary['finalized'] . "\n";
    echo "Skipped:         " . $summary['skipped'] . "\n";
    echo "Failed:          " . $summary['failed'] . "\n";
    if (!empty($summary['details'])) {
        echo "----------------------------------------------------\n";
        echo "Details:\n";
        foreach ($summary['details'] as $line) {
            echo "  * " . $line . "\n";
        }
    }
    echo "====================================================\n";
    exit($summary['failed'] > 0 ? 1 : 0);
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'        => ($summary['failed'] === 0),
        'timestamp' => date('Y-m-d H:i:s'),
        'summary'   => $summary
    ]);
    exit;
}
