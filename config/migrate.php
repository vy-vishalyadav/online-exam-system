<?php
/**
 * Standalone Database Migration Script
 *
 * Usage:
 *   CLI:    php config/migrate.php
 *   Web:    Run once after deployment by an authorized administrator.
 *
 * Moves heavy DDL / schema alterations out of the normal HTTP request path.
 */

// If accessed directly from browser, redirect to the protected admin/migrate.php interface
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    header("Location: ../admin/migrate.php");
    exit;
}

require_once __DIR__ . '/db.php';

function run_migrations($conn): array {
    if (!$conn) {
        error_log("[Migration Fatal] Database connection is not active.");
        return [
            'success'  => false,
            'messages' => [],
            'errors'   => ['Database service is unavailable. Could not establish connection.']
        ];
    }

    $messages = [];
    $errors   = [];

    $log = function(string $msg) use (&$messages) {
        $messages[] = $msg;
        if (php_sapi_name() === 'cli') {
            echo "[MIGRATE] $msg\n";
        }
    };

    $exec = function(string $sql, string $success_msg, string $error_label) use ($conn, $log, &$errors): bool {
        $res = mysqli_query($conn, $sql);
        if (!$res) {
            $db_err = mysqli_error($conn);
            error_log("[Migration Failure] {$error_label} — Error: {$db_err} — Query: " . substr($sql, 0, 200));
            $errors[] = $error_label;
            return false;
        }
        if ($success_msg !== '') {
            $log($success_msg);
        }
        return true;
    };

    $log("Starting database migrations...");

    // 1. exams.result_mode ('instant' or 'pending')
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'result_mode'");
    if (!$check) {
        $errors[] = "Failed to inspect exams table structure.";
        error_log("[Migration Failure] SHOW COLUMNS FROM exams LIKE 'result_mode': " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE exams ADD COLUMN result_mode VARCHAR(20) NOT NULL DEFAULT 'instant'", "Added exams.result_mode", "Failed to add column exams.result_mode");
    }

    // 2. questions.question_type & nullable options
    $check = mysqli_query($conn, "SHOW COLUMNS FROM questions LIKE 'question_type'");
    if (!$check) {
        $errors[] = "Failed to inspect questions table structure.";
        error_log("[Migration Failure] SHOW COLUMNS FROM questions LIKE 'question_type': " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $q1 = $exec("ALTER TABLE questions ADD COLUMN question_type VARCHAR(20) NOT NULL DEFAULT 'mcq'", "", "Failed to add questions.question_type");
        $q2 = $exec("ALTER TABLE questions MODIFY option_a VARCHAR(255) NULL", "", "Failed to modify questions.option_a");
        $q3 = $exec("ALTER TABLE questions MODIFY option_b VARCHAR(255) NULL", "", "Failed to modify questions.option_b");
        $q4 = $exec("ALTER TABLE questions MODIFY option_c VARCHAR(255) NULL", "", "Failed to modify questions.option_c");
        $q5 = $exec("ALTER TABLE questions MODIFY option_d VARCHAR(255) NULL", "", "Failed to modify questions.option_d");
        $q6 = $exec("ALTER TABLE questions MODIFY correct_option CHAR(1) NULL", "", "Failed to modify questions.correct_option");
        if ($q1 && $q2 && $q3 && $q4 && $q5 && $q6) {
            $log("Configured questions.question_type and nullable options");
        }
    }

    // 3. results.status & feedback
    $check = mysqli_query($conn, "SHOW COLUMNS FROM results LIKE 'status'");
    if (!$check) {
        $errors[] = "Failed to inspect results table structure.";
        error_log("[Migration Failure] SHOW COLUMNS FROM results LIKE 'status': " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $r1 = $exec("ALTER TABLE results ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'published'", "", "Failed to add results.status");
        $r2 = $exec("ALTER TABLE results ADD COLUMN admin_feedback TEXT NULL", "", "Failed to add results.admin_feedback");
        $r3 = $exec("ALTER TABLE results ADD COLUMN evaluated_at DATETIME NULL", "", "Failed to add results.evaluated_at");
        if ($r1 && $r2 && $r3) {
            $log("Added results.status and evaluation fields");
        }
    }

    // 4. student_answers table
    $exec("CREATE TABLE IF NOT EXISTS student_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        result_id INT NOT NULL,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        question_id INT NOT NULL,
        user_answer TEXT NULL,
        is_correct TINYINT(1) DEFAULT NULL,
        marks_awarded DECIMAL(5,2) DEFAULT NULL,
        FOREIGN KEY (result_id) REFERENCES results(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
    )", "Verified table student_answers", "Failed to create table student_answers");

    // 5. Unique index on students.email
    $check = mysqli_query($conn, "SHOW INDEX FROM students WHERE Key_name = 'idx_students_email'");
    if (!$check) {
        $errors[] = "Failed to inspect students table indexes.";
        error_log("[Migration Failure] SHOW INDEX FROM students: " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE students ADD UNIQUE INDEX idx_students_email (email)", "Added unique index idx_students_email", "Failed to add unique index idx_students_email");
    }

    // 6. questions.marks
    $check = mysqli_query($conn, "SHOW COLUMNS FROM questions LIKE 'marks'");
    if (!$check) {
        $errors[] = "Failed to inspect questions.marks column.";
        error_log("[Migration Failure] SHOW COLUMNS FROM questions LIKE 'marks': " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE questions ADD COLUMN marks DECIMAL(5,2) NOT NULL DEFAULT 1 AFTER question_type", "Added questions.marks", "Failed to add questions.marks column");
    }

    // 7. exam_sessions
    $exec("CREATE TABLE IF NOT EXISTS exam_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        duration_minutes INT NOT NULL DEFAULT 30,
        question_seed VARCHAR(64) NOT NULL DEFAULT '',
        submitted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_student_exam (student_id, exam_id)
    )", "Verified table exam_sessions", "Failed to create table exam_sessions");

    // 8. draft_answers
    $exec("CREATE TABLE IF NOT EXISTS draft_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        question_id INT NOT NULL,
        answer TEXT NULL,
        saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_draft (student_id, exam_id, question_id)
    )", "Verified table draft_answers", "Failed to create table draft_answers");

    // 9. exam_violations
    $exec("CREATE TABLE IF NOT EXISTS exam_violations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        violation_type VARCHAR(50) NOT NULL,
        detail VARCHAR(255) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_violations (student_id, exam_id)
    )", "Verified table exam_violations", "Failed to create table exam_violations");

    // 10. Performance Indexes on exam_violations
    $check = mysqli_query($conn, "SHOW INDEX FROM exam_violations WHERE Key_name = 'idx_violations_lookup'");
    if (!$check) {
        $errors[] = "Failed to inspect exam_violations indexes.";
        error_log("[Migration Failure] SHOW INDEX FROM exam_violations: " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE exam_violations ADD INDEX idx_violations_lookup (student_id, exam_id, violation_type)", "Added index idx_violations_lookup on exam_violations", "Failed to add index idx_violations_lookup");
    }

    $check = mysqli_query($conn, "SHOW INDEX FROM exam_violations WHERE Key_name = 'idx_violations_exam_time'");
    if (!$check) {
        $errors[] = "Failed to inspect exam_violations indexes.";
        error_log("[Migration Failure] SHOW INDEX FROM exam_violations: " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE exam_violations ADD INDEX idx_violations_exam_time (exam_id, occurred_at)", "Added index idx_violations_exam_time on exam_violations", "Failed to add index idx_violations_exam_time");
    }

    // 11. exams schedule window
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'start_at'");
    if (!$check) {
        $errors[] = "Failed to inspect exams schedule columns.";
        error_log("[Migration Failure] SHOW COLUMNS FROM exams LIKE 'start_at': " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $w1 = $exec("ALTER TABLE exams ADD COLUMN start_at DATETIME NULL AFTER result_mode", "", "Failed to add exams.start_at");
        $w2 = $exec("ALTER TABLE exams ADD COLUMN end_at DATETIME NULL AFTER start_at", "", "Failed to add exams.end_at");
        if ($w1 && $w2) {
            $log("Added exams schedule window (start_at, end_at)");
        }
    }

    // 12. exam_sessions columns: time_taken_seconds, question_seed, duration_minutes
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'time_taken_seconds'");
    if (!$check) {
        $errors[] = "Failed to inspect exam_sessions table structure.";
        error_log("[Migration Failure] SHOW COLUMNS FROM exam_sessions: " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE exam_sessions ADD COLUMN time_taken_seconds INT NULL AFTER submitted", "Added exam_sessions.time_taken_seconds", "Failed to add exam_sessions.time_taken_seconds");
    }

    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'question_seed'");
    if ($check && mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE exam_sessions ADD COLUMN question_seed VARCHAR(64) NOT NULL DEFAULT '' AFTER duration_minutes", "Added exam_sessions.question_seed", "Failed to add exam_sessions.question_seed");
    }

    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'duration_minutes'");
    if ($check && mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE exam_sessions ADD COLUMN duration_minutes INT NOT NULL DEFAULT 30 AFTER started_at", "Added exam_sessions.duration_minutes", "Failed to add exam_sessions.duration_minutes");
    }

    // 13. classes table & seeding
    $exec("CREATE TABLE IF NOT EXISTS classes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(50)  NOT NULL,
        description VARCHAR(200) NULL,
        sort_order  INT          NOT NULL DEFAULT 0,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_class_name (name)
    )", "Verified table classes", "Failed to create table classes");

    $exec("INSERT IGNORE INTO classes (name, description, sort_order) VALUES
        ('FYIT', 'First Year Information Technology', 1),
        ('SYIT', 'Second Year Information Technology', 2),
        ('TYIT', 'Third Year Information Technology', 3)", "Verified default classes seeded", "Failed to seed default classes");

    // 14. students.class_id
    $check = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'class_id'");
    if (!$check) {
        $errors[] = "Failed to inspect students.class_id column.";
        error_log("[Migration Failure] SHOW COLUMNS FROM students: " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE students ADD COLUMN class_id INT NULL AFTER name", "Added students.class_id", "Failed to add students.class_id");
    }

    // 15. exam_class_assignments
    $exec("CREATE TABLE IF NOT EXISTS exam_class_assignments (
        exam_id  INT NOT NULL,
        class_id INT NOT NULL,
        PRIMARY KEY (exam_id, class_id)
    )", "Verified table exam_class_assignments", "Failed to create table exam_class_assignments");

    // 16. admin.created_at
    $check = mysqli_query($conn, "SHOW COLUMNS FROM admin LIKE 'created_at'");
    if (!$check) {
        $errors[] = "Failed to inspect admin.created_at column.";
        error_log("[Migration Failure] SHOW COLUMNS FROM admin: " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE admin ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP", "Added admin.created_at", "Failed to add admin.created_at");
    }

    // 17. exams.questions_to_display
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'questions_to_display'");
    if (!$check) {
        $errors[] = "Failed to inspect exams.questions_to_display column.";
        error_log("[Migration Failure] SHOW COLUMNS FROM exams: " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE exams ADD COLUMN questions_to_display INT NOT NULL DEFAULT 0 AFTER end_at", "Added exams.questions_to_display", "Failed to add exams.questions_to_display");
    }

    // 18. exam_sessions.assigned_questions
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'assigned_questions'");
    if (!$check) {
        $errors[] = "Failed to inspect exam_sessions.assigned_questions column.";
        error_log("[Migration Failure] SHOW COLUMNS FROM exam_sessions: " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        $exec("ALTER TABLE exam_sessions ADD COLUMN assigned_questions TEXT NULL AFTER question_seed", "Added exam_sessions.assigned_questions", "Failed to add exam_sessions.assigned_questions");
    }

    // 19. results.score DECIMAL(6,2)
    $check = mysqli_query($conn, "SHOW COLUMNS FROM results LIKE 'score'");
    if (!$check) {
        $errors[] = "Failed to inspect results.score column.";
        error_log("[Migration Failure] SHOW COLUMNS FROM results: " . mysqli_error($conn));
    } else {
        $col = mysqli_fetch_assoc($check);
        if ($col && strpos(strtolower($col['Type'] ?? ''), 'decimal') === false && strpos(strtolower($col['Type'] ?? ''), 'float') === false) {
            $exec("ALTER TABLE results MODIFY COLUMN score DECIMAL(6,2) NOT NULL DEFAULT 0.00", "Modified results.score to DECIMAL(6,2)", "Failed to modify results.score to DECIMAL(6,2)");
        }
    }

    // 20. Unique index on results(student_id, exam_id)
    $check = mysqli_query($conn, "SHOW INDEX FROM results WHERE Key_name = 'uq_student_exam_result'");
    if (!$check) {
        $errors[] = "Failed to inspect results indexes.";
        error_log("[Migration Failure] SHOW INDEX FROM results: " . mysqli_error($conn));
    } elseif (mysqli_num_rows($check) === 0) {
        // Safety check: ensure no existing duplicate rows exist before adding UNIQUE index
        $dup_chk = mysqli_query($conn, "SELECT student_id, exam_id, COUNT(*) AS cnt FROM results GROUP BY student_id, exam_id HAVING cnt > 1");
        if ($dup_chk && mysqli_num_rows($dup_chk) > 0) {
            $dup_rows = [];
            while ($dr = mysqli_fetch_assoc($dup_chk)) {
                $dup_rows[] = "Student {$dr['student_id']} - Exam {$dr['exam_id']} ({$dr['cnt']} results)";
            }
            $err_desc = "Cannot add unique index uq_student_exam_result: duplicate results exist (" . implode(', ', $dup_rows) . "). Please resolve duplicates before applying unique constraint.";
            $errors[] = $err_desc;
            error_log("[Migration Error] " . $err_desc);
        } else {
            $exec("ALTER TABLE results ADD UNIQUE KEY uq_student_exam_result (student_id, exam_id)", "Added unique constraint uq_student_exam_result on results", "Failed to add unique constraint uq_student_exam_result on results");
        }
    }

    // 21. app_jobs table for throttled background and opportunistic tasks
    $exec("CREATE TABLE IF NOT EXISTS app_jobs (
        job_name VARCHAR(50) NOT NULL PRIMARY KEY,
        last_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_until DATETIME NULL
    )", "Verified table app_jobs", "Failed to create table app_jobs");

    $exec("INSERT IGNORE INTO app_jobs (job_name, last_run_at) VALUES ('finalize_expired_exams', '2000-01-01 00:00:00')", "Initialized job tracker for finalize_expired_exams", "Failed to initialize job tracker for finalize_expired_exams");

    if (empty($errors)) {
        $log("All migrations completed successfully.");
        return [
            'success'  => true,
            'messages' => $messages,
            'errors'   => []
        ];
    } else {
        $log("Database migration finished with " . count($errors) . " error(s).");
        return [
            'success'  => false,
            'messages' => $messages,
            'errors'   => $errors
        ];
    }
}

// Auto-run if executed directly via CLI
if (php_sapi_name() === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    $res = run_migrations($conn);
    if (!$res['success']) {
        echo "\n[ERROR] Migration failed with " . count($res['errors']) . " error(s):\n";
        foreach ($res['errors'] as $err) {
            echo "  - $err\n";
        }
        exit(1);
    }
    echo "\n[SUCCESS] All migrations completed successfully.\n";
    exit(0);
}
