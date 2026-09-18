<?php
/**
 * Shared Exam Submission & Server-Side Finalization Service
 *
 * Provides a single authoritative implementation for finalizing exam submissions:
 *   - Manual student submission (POST result.php)
 *   - Browser timer expiration / auto-submit (GET result.php?timeout=1)
 *   - CLI / scheduled background cron (cron/finalize_expired_exams.php)
 *   - Opportunistic server-side fallback for InfinityFree (runOpportunisticExpiredExamCleanup)
 *
 * Guarantees:
 *   - Atomic transactions with row-level locking (SELECT ... FOR UPDATE) to prevent race conditions.
 *   - Deterministic option shuffling from exam_sessions.question_seed without PHP session dependency.
 *   - Safe draft preservation and rollback on any failure.
 *   - Strict server-authoritative time calculation (MySQL TIMESTAMPDIFF).
 */

/**
 * Deterministically reconstructs the shuffled options map for an MCQ question
 * based on question_seed and question_id, matching the exact shuffle rendered in student/exam.php.
 *
 * @param string $question_seed
 * @param int $q_id
 * @param array $q
 * @return array
 */
function getShuffledQuestionOptions(string $question_seed, int $q_id, array $q): array {
    $orig_opts = [
        'A' => (string)($q['option_a'] ?? ''),
        'B' => (string)($q['option_b'] ?? ''),
        'C' => (string)($q['option_c'] ?? ''),
        'D' => (string)($q['option_d'] ?? ''),
    ];
    $orig_correct = strtoupper(trim((string)($q['correct_option'] ?? 'A')));
    $labels       = ['A', 'B', 'C', 'D'];

    if ($question_seed !== '') {
        $opt_seed = hexdec(substr(md5($question_seed . '_opt_' . $q_id), 0, 8));
        mt_srand($opt_seed);
        $orig_keys = ['A', 'B', 'C', 'D'];
        for ($i = 3; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$orig_keys[$i], $orig_keys[$j]] = [$orig_keys[$j], $orig_keys[$i]];
        }
        $shuffled_to_orig = array_combine($labels, $orig_keys);
        $orig_to_shuffled = array_flip($shuffled_to_orig);

        $shuffled_opts = [];
        foreach ($labels as $lbl) {
            $shuffled_opts[$lbl] = $orig_opts[$shuffled_to_orig[$lbl]] ?? '';
        }
        $shuffled_correct = $orig_to_shuffled[$orig_correct] ?? 'A';
    } else {
        $shuffled_opts    = $orig_opts;
        $shuffled_correct = $orig_correct;
        $shuffled_to_orig = ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'];
        $orig_to_shuffled = ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'];
    }

    return [
        'map'              => $shuffled_opts,
        'correct'          => $shuffled_correct,
        'shuffled_to_orig' => $shuffled_to_orig,
        'orig_to_shuffled' => $orig_to_shuffled,
    ];
}

/**
 * Retrieves the assigned questions for an exam session in their locked order,
 * or deterministically calculates the assigned pool if not yet set in exam_sessions.
 *
 * @param mysqli $conn
 * @param int $exam_id
 * @param string $question_seed
 * @param string|null $assigned_questions
 * @param int $pool_limit
 * @return array
 */
function getAssignedQuestionsForSession(mysqli $conn, int $exam_id, string $question_seed, ?string $assigned_questions, int $pool_limit = 0): array {
    $questions = [];
    $assigned_ids = [];
    if (!empty($assigned_questions)) {
        $assigned_ids = array_filter(array_map('intval', explode(',', $assigned_questions)));
    }

    if (!empty($assigned_ids)) {
        $in_clause = implode(',', $assigned_ids);
        $qs = mysqli_query($conn, "SELECT * FROM questions WHERE id IN ($in_clause) AND exam_id = " . (int)$exam_id);
        $q_map = [];
        if ($qs) {
            while ($row = mysqli_fetch_assoc($qs)) {
                $q_map[(int)$row['id']] = $row;
            }
        }
        foreach ($assigned_ids as $aid) {
            if (isset($q_map[$aid])) {
                $questions[] = $q_map[$aid];
            }
        }
    } else {
        $q_stmt = mysqli_prepare($conn, "SELECT * FROM questions WHERE exam_id = ? ORDER BY id ASC");
        $all_pool = [];
        if ($q_stmt) {
            mysqli_stmt_bind_param($q_stmt, "i", $exam_id);
            mysqli_stmt_execute($q_stmt);
            $qr = mysqli_stmt_get_result($q_stmt);
            if ($qr) {
                while ($row = mysqli_fetch_assoc($qr)) {
                    $all_pool[] = $row;
                }
            }
            mysqli_stmt_close($q_stmt);
        }

        $total_in_pool = count($all_pool);
        if ($total_in_pool > 0) {
            if ($question_seed !== '') {
                $seed_int = hexdec(substr(md5($question_seed), 0, 8));
                mt_srand($seed_int);
                $indices = range(0, $total_in_pool - 1);
                for ($i = $total_in_pool - 1; $i > 0; $i--) {
                    $j = mt_rand(0, $i);
                    [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
                }
                $shuffled = [];
                foreach ($indices as $idx) {
                    $shuffled[] = $all_pool[$idx];
                }
            } else {
                $shuffled = $all_pool;
            }

            if ($pool_limit > 0 && $pool_limit < count($shuffled)) {
                $questions = array_slice($shuffled, 0, $pool_limit);
            } else {
                $questions = $shuffled;
            }
        }
    }

    return $questions;
}

/**
 * Atomically finalizes an exam submission.
 *
 * @param mysqli $conn
 * @param int $student_id
 * @param int $exam_id
 * @param string $reason 'manual' | 'timeout' | 'cron' | 'fallback' | 'disqualified'
 * @param array $user_mcq_answers
 * @param array $user_desc_answers
 * @return array
 */
function finalizeExamSubmission(mysqli $conn, int $student_id, int $exam_id, string $reason = 'manual', array $user_mcq_answers = [], array $user_desc_answers = []): array {
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection unavailable'];
    }

    // 1. Begin Atomic Transaction
    if (!mysqli_begin_transaction($conn)) {
        return ['success' => false, 'error' => 'Failed to start transaction: ' . mysqli_error($conn)];
    }

    try {
        // 2. Lock matching exam_sessions row FOR UPDATE to prevent race conditions or duplicate submissions
        $lock_stmt = mysqli_prepare($conn,
            "SELECT es.id AS session_id, es.submitted, es.duration_minutes, es.assigned_questions, es.question_seed,
                    TIMESTAMPDIFF(SECOND, es.started_at, NOW()) AS elapsed_seconds,
                    e.id AS exam_id, e.title, e.end_at, e.result_mode, e.duration_minutes AS exam_duration_minutes,
                    e.questions_to_display,
                    TIMESTAMPDIFF(SECOND, NOW(), e.end_at) AS window_rem_sec
             FROM exam_sessions es
             JOIN exams e ON e.id = es.exam_id
             WHERE es.student_id = ? AND es.exam_id = ? FOR UPDATE");

        if (!$lock_stmt) {
            throw new Exception("Lock prepare failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($lock_stmt, "ii", $student_id, $exam_id);
        if (!mysqli_stmt_execute($lock_stmt)) {
            $err = mysqli_stmt_error($lock_stmt);
            mysqli_stmt_close($lock_stmt);
            throw new Exception("Lock execute failed: " . $err);
        }

        $lock_res = mysqli_stmt_get_result($lock_stmt);
        $ss_row = $lock_res ? mysqli_fetch_assoc($lock_res) : null;
        mysqli_stmt_close($lock_stmt);

        if (!$ss_row) {
            mysqli_rollback($conn);
            return ['success' => false, 'error' => 'invalid_session'];
        }

        if ((int)$ss_row['submitted'] === 1) {
            mysqli_rollback($conn);
            return ['success' => false, 'already_submitted' => true];
        }

        // 3. Expiry verification for non-manual submission
        $elapsed_sec = (int)($ss_row['elapsed_seconds'] ?? 0);
        $allowed_sec = ((int)($ss_row['duration_minutes'] ?? 30)) * 60;
        $personal_rem = max(0, $allowed_sec - $elapsed_sec);
        $window_rem   = (!empty($ss_row['end_at']) && isset($ss_row['window_rem_sec'])) ? (int)$ss_row['window_rem_sec'] : null;

        $is_time_up   = ($personal_rem <= 0);
        $is_window_up = ($window_rem !== null && $window_rem <= 0);

        if ($reason !== 'manual') {
            $is_disqualified = ($reason === 'disqualified');
            if (!$is_disqualified) {
                // Check if student reached 3 integrity violation strikes
                $v_chk = mysqli_prepare($conn,
                    "SELECT COUNT(*) AS v_cnt FROM exam_violations 
                     WHERE student_id = ? AND exam_id = ? 
                       AND violation_type IN ('tab_switch','fullscreen_exit','exit_exam')");
                if ($v_chk) {
                    mysqli_stmt_bind_param($v_chk, "ii", $student_id, $exam_id);
                    mysqli_stmt_execute($v_chk);
                    $v_res = mysqli_stmt_get_result($v_chk);
                    $v_row = $v_res ? mysqli_fetch_assoc($v_res) : null;
                    mysqli_stmt_close($v_chk);
                    if ($v_row && (int)$v_row['v_cnt'] >= 3) {
                        $is_disqualified = true;
                    }
                }
            }

            if (!$is_time_up && !$is_window_up && !$is_disqualified) {
                // Attempt is still active; do not finalize prematurely
                mysqli_rollback($conn);
                return ['success' => false, 'error' => 'not_expired'];
            }
        }

        // 4. Retrieve saved drafts from draft_answers
        $draft_mcq  = [];
        $draft_desc = [];
        $df = mysqli_prepare($conn, "SELECT question_id, answer FROM draft_answers WHERE student_id = ? AND exam_id = ?");
        if ($df) {
            mysqli_stmt_bind_param($df, "ii", $student_id, $exam_id);
            mysqli_stmt_execute($df);
            $df_res = mysqli_stmt_get_result($df);
            if ($df_res) {
                while ($dr = mysqli_fetch_assoc($df_res)) {
                    $qid = (int)$dr['question_id'];
                    $ans = (string)$dr['answer'];
                    if (in_array(strtoupper($ans), ['A', 'B', 'C', 'D']) && strlen($ans) <= 1) {
                        $draft_mcq[$qid] = strtoupper($ans);
                    } else {
                        $draft_desc[$qid] = $ans;
                    }
                }
            }
            mysqli_stmt_close($df);
        }

        // If automated (timeout / cron / fallback) or empty POST, load exclusively from drafts
        if ($reason !== 'manual' || (empty($user_mcq_answers) && empty($user_desc_answers))) {
            $user_mcq_answers  = $draft_mcq;
            $user_desc_answers = $draft_desc;
        } else {
            // For manual submit, POST takes priority; supplement unanswered questions from drafts
            foreach ($draft_mcq as $dqid => $dans) {
                if (!isset($user_mcq_answers[$dqid]) || $user_mcq_answers[$dqid] === '') {
                    $user_mcq_answers[$dqid] = $dans;
                }
            }
            foreach ($draft_desc as $dqid => $dans) {
                if (!isset($user_desc_answers[$dqid]) || trim((string)$user_desc_answers[$dqid]) === '') {
                    $user_desc_answers[$dqid] = $dans;
                }
            }
        }

        // 5. Fetch assigned questions in locked order
        $questions_to_score = getAssignedQuestionsForSession(
            $conn,
            $exam_id,
            (string)($ss_row['question_seed'] ?? ''),
            $ss_row['assigned_questions'] ?? null,
            (int)($ss_row['questions_to_display'] ?? 0)
        );

        // 6. Score answers
        $total_questions  = 0;
        $mcq_count        = 0;
        $desc_count       = 0;
        $correct_count    = 0;
        $earned_mcq_marks = 0.0;
        $total_exam_marks = 0.0;
        $recorded_answers = [];

        foreach ($questions_to_score as $q) {
            $total_questions++;
            $q_id    = (int)$q['id'];
            $q_type  = $q['question_type'] ?? 'mcq';
            $q_marks = (isset($q['marks']) && (float)$q['marks'] > 0) ? (float)$q['marks'] : ($q_type === 'descriptive' ? 5.0 : 1.0);
            $total_exam_marks += $q_marks;

            if ($q_type === 'descriptive') {
                $desc_count++;
                $desc_text = trim((string)($user_desc_answers[$q_id] ?? ''));
                if (strlen($desc_text) > 5000) {
                    $desc_text = substr($desc_text, 0, 5000);
                }
                $recorded_answers[] = [
                    'question_id'    => $q_id,
                    'question_type'  => 'descriptive',
                    'question_text'  => $q['question_text'],
                    'user_ans'       => $desc_text,
                    'is_correct'     => null,
                    'marks'          => 0.0,
                    'question_marks' => $q_marks
                ];
            } else {
                $mcq_count++;
                $raw_ans      = strtoupper(trim((string)($user_mcq_answers[$q_id] ?? '')));
                $user_clicked = in_array($raw_ans, ['A', 'B', 'C', 'D']) ? $raw_ans : null;

                $shuf             = getShuffledQuestionOptions((string)($ss_row['question_seed'] ?? ''), $q_id, $q);
                $original_correct = strtoupper(trim((string)($q['correct_option'] ?? 'A')));

                $is_correct   = ($user_clicked !== null && $user_clicked === $shuf['correct']);
                $earned_marks = $is_correct ? $q_marks : 0.0;
                if ($is_correct) {
                    $correct_count++;
                    $earned_mcq_marks += $q_marks;
                }

                // Map clicked letter back to the original option key so stored user_answer matches question options
                $user_orig_ans = ($user_clicked !== null && isset($shuf['shuffled_to_orig'][$user_clicked]))
                                 ? $shuf['shuffled_to_orig'][$user_clicked]
                                 : $user_clicked;

                $recorded_answers[] = [
                    'question_id'    => $q_id,
                    'question_type'  => 'mcq',
                    'question_text'  => $q['question_text'],
                    'option_a'       => $q['option_a'],
                    'option_b'       => $q['option_b'],
                    'option_c'       => $q['option_c'],
                    'option_d'       => $q['option_d'],
                    'user_ans'       => $user_orig_ans,
                    'correct_ans'    => $original_correct,
                    'is_correct'     => $is_correct ? 1 : 0,
                    'marks'          => $earned_marks,
                    'question_marks' => $q_marks
                ];
            }
        }

        $exam_mode   = $ss_row['result_mode'] ?? 'instant';
        $status      = ($desc_count > 0 || $exam_mode === 'pending') ? 'pending' : 'published';
        $final_marks = round((float)$earned_mcq_marks, 2);
        $passed      = ($total_exam_marks > 0) ? (($final_marks / $total_exam_marks) >= 0.5) : false;

        // 7. Insert row into results
        $ins_res = mysqli_prepare($conn, "INSERT INTO results (student_id, exam_id, score, status, attempted_at) VALUES (?, ?, ?, ?, NOW())");
        if (!$ins_res) {
            throw new Exception("Results insert prepare failed: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($ins_res, "iids", $student_id, $exam_id, $final_marks, $status);
        if (!mysqli_stmt_execute($ins_res)) {
            $err = mysqli_stmt_error($ins_res);
            mysqli_stmt_close($ins_res);
            throw new Exception("Results insert execute failed: " . $err);
        }
        $result_id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($ins_res);

        if ($result_id <= 0) {
            throw new Exception("Invalid result ID generated");
        }

        // 8. Insert student_answers
        $sa_stmt = mysqli_prepare($conn, "INSERT INTO student_answers (result_id, student_id, exam_id, question_id, user_answer, is_correct, marks_awarded) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$sa_stmt) {
            throw new Exception("Student answers insert prepare failed: " . mysqli_error($conn));
        }
        foreach ($recorded_answers as $ans) {
            $qid     = (int)$ans['question_id'];
            $u_ans   = $ans['user_ans'] ?? '';
            $is_c    = $ans['is_correct'];
            $is_c_b  = ($is_c === null) ? null : (int)$is_c;
            $m_award = (float)$ans['marks'];
            mysqli_stmt_bind_param($sa_stmt, "iiiisid", $result_id, $student_id, $exam_id, $qid, $u_ans, $is_c_b, $m_award);
            if (!mysqli_stmt_execute($sa_stmt)) {
                $err = mysqli_stmt_error($sa_stmt);
                mysqli_stmt_close($sa_stmt);
                throw new Exception("Student answers insert execute failed: " . $err);
            }
        }
        mysqli_stmt_close($sa_stmt);

        // 9. Update exam_sessions: mark submitted and save accurate duration
        $time_taken = ($reason === 'manual') ? min($elapsed_sec, $allowed_sec) : $allowed_sec;
        if (!empty($ss_row['end_at']) && isset($ss_row['window_rem_sec']) && (int)$ss_row['window_rem_sec'] <= 0) {
            // If bounded by synchronous schedule window, clamp time taken to maximum window allowed
            $time_taken = min($time_taken, max(0, $allowed_sec + (int)$ss_row['window_rem_sec']));
        }

        $upd = mysqli_prepare($conn, "UPDATE exam_sessions SET submitted = 1, time_taken_seconds = ? WHERE student_id = ? AND exam_id = ?");
        if (!$upd) {
            throw new Exception("Session update prepare failed: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($upd, "iii", $time_taken, $student_id, $exam_id);
        if (!mysqli_stmt_execute($upd)) {
            $err = mysqli_stmt_error($upd);
            mysqli_stmt_close($upd);
            throw new Exception("Session update execute failed: " . $err);
        }
        mysqli_stmt_close($upd);

        // 10. Delete saved drafts from draft_answers only after results & answers are committed
        $del = mysqli_prepare($conn, "DELETE FROM draft_answers WHERE student_id = ? AND exam_id = ?");
        if (!$del) {
            throw new Exception("Draft answers cleanup prepare failed: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($del, "ii", $student_id, $exam_id);
        if (!mysqli_stmt_execute($del)) {
            $err = mysqli_stmt_error($del);
            mysqli_stmt_close($del);
            throw new Exception("Draft answers cleanup execute failed: " . $err);
        }
        mysqli_stmt_close($del);

        // 11. Commit Transaction
        if (!mysqli_commit($conn)) {
            throw new Exception("Transaction commit failed: " . mysqli_error($conn));
        }

        $review = [
            'result_id'       => $result_id,
            'exam_title'      => $ss_row['title'] ?? 'Examination',
            'status'          => $status,
            'has_descriptive' => $desc_count > 0,
            'desc_count'      => $desc_count,
            'total'           => $total_questions,
            'total_marks'     => $total_exam_marks,
            'mcq_count'       => $mcq_count,
            'correct'         => $correct_count,
            'wrong'           => max(0, $mcq_count - $correct_count),
            'score'           => $final_marks,
            'passed'          => $passed,
            'items'           => $recorded_answers,
            'timed_out'       => ($reason !== 'manual')
        ];

        return ['success' => true, 'result_id' => $result_id, 'review' => $review];

    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log("[Exam Finalize Error] Student {$student_id}, Exam {$exam_id}, Reason {$reason}: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Scans unsubmitted exam sessions and finalizes all attempts that have reached their
 * personal duration deadline or synchronous scheduled end_at.
 *
 * @param mysqli $conn
 * @param int $limit Max attempts to process in one batch
 * @return array
 */
function finalizeAllExpiredExams(mysqli $conn, int $limit = 50): array {
    $scanned   = 0;
    $finalized = 0;
    $skipped   = 0;
    $failed    = 0;
    $details   = [];

    if (!$conn) {
        return [
            'scanned'   => 0,
            'finalized' => 0,
            'skipped'   => 0,
            'failed'    => 1,
            'details'   => ['Database service unavailable']
        ];
    }

    $limit = max(1, min(200, $limit));

    $sql = "SELECT es.student_id, es.exam_id
            FROM exam_sessions es
            JOIN exams e ON e.id = es.exam_id
            WHERE es.submitted = 0
              AND (
                TIMESTAMPDIFF(SECOND, es.started_at, NOW()) >= (es.duration_minutes * 60)
                OR (e.end_at IS NOT NULL AND TIMESTAMPDIFF(SECOND, NOW(), e.end_at) <= 0)
              )
            ORDER BY es.started_at ASC
            LIMIT ?";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("[Expired Finalizer] Query prepare failed: " . mysqli_error($conn));
        return [
            'scanned'   => 0,
            'finalized' => 0,
            'skipped'   => 0,
            'failed'    => 1,
            'details'   => ['Query prepare failed: ' . mysqli_error($conn)]
        ];
    }

    mysqli_stmt_bind_param($stmt, "i", $limit);
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        error_log("[Expired Finalizer] Query execute failed: " . $err);
        return [
            'scanned'   => 0,
            'finalized' => 0,
            'skipped'   => 0,
            'failed'    => 1,
            'details'   => [$err]
        ];
    }

    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
    }
    mysqli_stmt_close($stmt);

    foreach ($rows as $r) {
        $scanned++;
        $sid = (int)$r['student_id'];
        $eid = (int)$r['exam_id'];

        $sub = finalizeExamSubmission($conn, $sid, $eid, 'cron');
        if (!empty($sub['success'])) {
            $finalized++;
            $details[] = "Finalized student {$sid}, exam {$eid} (Result ID: {$sub['result_id']})";
        } elseif (!empty($sub['already_submitted'])) {
            $skipped++;
        } elseif (($sub['error'] ?? '') === 'not_expired') {
            $skipped++;
        } else {
            $failed++;
            $details[] = "Failed student {$sid}, exam {$eid}: " . ($sub['error'] ?? 'Unknown error');
        }
    }

    return [
        'scanned'   => $scanned,
        'finalized' => $finalized,
        'skipped'   => $skipped,
        'failed'    => $failed,
        'details'   => $details
    ];
}

/**
 * Opportunistic background runner for shared hosting environments (InfinityFree).
 * Executes finalization at most once every $throttle_seconds (default 60s) globally
 * using a single atomic update query on app_jobs.
 *
 * @param mysqli $conn
 * @param int $throttle_seconds
 * @param int $batch_limit
 * @return array|null
 */
function runOpportunisticExpiredExamCleanup(mysqli $conn, int $throttle_seconds = 60, int $batch_limit = 20): ?array {
    if (!$conn) return null;

    // Single atomic UPDATE: acquires run lock only if throttle interval has elapsed and not currently locked
    $stmt = @mysqli_prepare($conn,
        "UPDATE app_jobs 
         SET last_run_at = NOW(), locked_until = DATE_ADD(NOW(), INTERVAL 30 SECOND)
         WHERE job_name = 'finalize_expired_exams'
           AND (last_run_at IS NULL OR TIMESTAMPDIFF(SECOND, last_run_at, NOW()) >= ?)
           AND (locked_until IS NULL OR locked_until <= NOW())");

    if (!$stmt) {
        // app_jobs table may not exist yet if migration hasn't run; fail silently without impacting user request
        return null;
    }

    mysqli_stmt_bind_param($stmt, "i", $throttle_seconds);
    $ok = @mysqli_stmt_execute($stmt);
    $affected = $ok ? mysqli_stmt_affected_rows($stmt) : 0;
    mysqli_stmt_close($stmt);

    if ($affected <= 0) {
        // Throttled: another request executed within the last $throttle_seconds or is actively running
        return null;
    }

    try {
        $summary = finalizeAllExpiredExams($conn, $batch_limit);
        // Release execution lock
        @mysqli_query($conn, "UPDATE app_jobs SET locked_until = NULL WHERE job_name = 'finalize_expired_exams'");
        return $summary;
    } catch (Throwable $e) {
        @mysqli_query($conn, "UPDATE app_jobs SET locked_until = NULL WHERE job_name = 'finalize_expired_exams'");
        error_log("[Opportunistic Cleanup Error] " . $e->getMessage());
        return null;
    }
}
