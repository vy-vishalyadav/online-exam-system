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

function run_migrations($conn) {
    if (!$conn) {
        die("Error: Database connection is not active.\n");
    }

    $messages = [];
    $log = function($msg) use (&$messages) {
        $messages[] = $msg;
        if (php_sapi_name() === 'cli') {
            echo "[MIGRATE] $msg\n";
        }
    };

    $log("Starting database migrations...");

    // 1. exams.result_mode ('instant' or 'pending')
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'result_mode'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE exams ADD COLUMN result_mode VARCHAR(20) NOT NULL DEFAULT 'instant'");
        $log("Added exams.result_mode");
    }

    // 2. questions.question_type & nullable options
    $check = mysqli_query($conn, "SHOW COLUMNS FROM questions LIKE 'question_type'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE questions ADD COLUMN question_type VARCHAR(20) NOT NULL DEFAULT 'mcq'");
        mysqli_query($conn, "ALTER TABLE questions MODIFY option_a VARCHAR(255) NULL");
        mysqli_query($conn, "ALTER TABLE questions MODIFY option_b VARCHAR(255) NULL");
        mysqli_query($conn, "ALTER TABLE questions MODIFY option_c VARCHAR(255) NULL");
        mysqli_query($conn, "ALTER TABLE questions MODIFY option_d VARCHAR(255) NULL");
        mysqli_query($conn, "ALTER TABLE questions MODIFY correct_option CHAR(1) NULL");
        $log("Configured questions.question_type and nullable options");
    }

    // 3. results.status & feedback
    $check = mysqli_query($conn, "SHOW COLUMNS FROM results LIKE 'status'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE results ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'published'");
        mysqli_query($conn, "ALTER TABLE results ADD COLUMN admin_feedback TEXT NULL");
        mysqli_query($conn, "ALTER TABLE results ADD COLUMN evaluated_at DATETIME NULL");
        $log("Added results.status and evaluation fields");
    }

    // 4. student_answers table
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_answers (
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
    )");

    // 5. Unique index on students.email
    $check = mysqli_query($conn, "SHOW INDEX FROM students WHERE Key_name = 'idx_students_email'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE students ADD UNIQUE INDEX idx_students_email (email)");
        $log("Added unique index idx_students_email");
    }

    // 6. questions.marks
    $check = mysqli_query($conn, "SHOW COLUMNS FROM questions LIKE 'marks'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE questions ADD COLUMN marks DECIMAL(5,2) NOT NULL DEFAULT 1 AFTER question_type");
        $log("Added questions.marks");
    }

    // 7. exam_sessions
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exam_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        duration_minutes INT NOT NULL DEFAULT 30,
        question_seed VARCHAR(64) NOT NULL DEFAULT '',
        submitted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_student_exam (student_id, exam_id)
    )");

    // 8. draft_answers
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS draft_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        question_id INT NOT NULL,
        answer TEXT NULL,
        saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_draft (student_id, exam_id, question_id)
    )");

    // 9. exam_violations
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exam_violations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        violation_type VARCHAR(50) NOT NULL,
        detail VARCHAR(255) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_violations (student_id, exam_id)
    )");

    // 10. Performance Indexes on exam_violations
    $check = mysqli_query($conn, "SHOW INDEX FROM exam_violations WHERE Key_name = 'idx_violations_lookup'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE exam_violations ADD INDEX idx_violations_lookup (student_id, exam_id, violation_type)");
        $log("Added index idx_violations_lookup on exam_violations");
    }
    $check = mysqli_query($conn, "SHOW INDEX FROM exam_violations WHERE Key_name = 'idx_violations_exam_time'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE exam_violations ADD INDEX idx_violations_exam_time (exam_id, occurred_at)");
        $log("Added index idx_violations_exam_time on exam_violations");
    }

    // 11. exams schedule window
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'start_at'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE exams ADD COLUMN start_at DATETIME NULL AFTER result_mode");
        mysqli_query($conn, "ALTER TABLE exams ADD COLUMN end_at   DATETIME NULL AFTER start_at");
        $log("Added exams schedule window (start_at, end_at)");
    }

    // 12. exam_sessions columns: time_taken_seconds, question_seed, duration_minutes
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'time_taken_seconds'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE exam_sessions ADD COLUMN time_taken_seconds INT NULL AFTER submitted");
    }
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'question_seed'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE exam_sessions ADD COLUMN question_seed VARCHAR(64) NOT NULL DEFAULT '' AFTER duration_minutes");
    }
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'duration_minutes'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE exam_sessions ADD COLUMN duration_minutes INT NOT NULL DEFAULT 30 AFTER started_at");
    }

    // 13. classes table & seeding
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS classes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(50)  NOT NULL,
        description VARCHAR(200) NULL,
        sort_order  INT          NOT NULL DEFAULT 0,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_class_name (name)
    )");
    mysqli_query($conn, "INSERT IGNORE INTO classes (name, description, sort_order) VALUES
        ('FYIT', 'First Year Information Technology', 1),
        ('SYIT', 'Second Year Information Technology', 2),
        ('TYIT', 'Third Year Information Technology', 3)");

    // 14. students.class_id
    $check = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'class_id'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE students ADD COLUMN class_id INT NULL AFTER name");
        $log("Added students.class_id");
    }

    // 15. exam_class_assignments
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exam_class_assignments (
        exam_id  INT NOT NULL,
        class_id INT NOT NULL,
        PRIMARY KEY (exam_id, class_id)
    )");

    // 16. admin.created_at
    $check = mysqli_query($conn, "SHOW COLUMNS FROM admin LIKE 'created_at'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE admin ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $log("Added admin.created_at");
    }

    // 17. exams.questions_to_display
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'questions_to_display'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE exams ADD COLUMN questions_to_display INT NOT NULL DEFAULT 0 AFTER end_at");
        $log("Added exams.questions_to_display");
    }

    // 18. exam_sessions.assigned_questions
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'assigned_questions'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE exam_sessions ADD COLUMN assigned_questions TEXT NULL AFTER question_seed");
        $log("Added exam_sessions.assigned_questions");
    }

    // 19. results.score DECIMAL(6,2)
    $check = mysqli_query($conn, "SHOW COLUMNS FROM results LIKE 'score'");
    if ($check) {
        $col = mysqli_fetch_assoc($check);
        if ($col && strpos(strtolower($col['Type'] ?? ''), 'decimal') === false && strpos(strtolower($col['Type'] ?? ''), 'float') === false) {
            mysqli_query($conn, "ALTER TABLE results MODIFY COLUMN score DECIMAL(6,2) NOT NULL DEFAULT 0.00");
            $log("Modified results.score to DECIMAL(6,2)");
        }
    }

    $log("All migrations completed successfully.");
    return $messages;
}

// Auto-run if executed directly via CLI
if (php_sapi_name() === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    run_migrations($conn);
    exit(0);
}
