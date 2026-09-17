<?php
// Auto-detect environment (Localhost vs InfinityFree Cloud)
$is_local = (php_sapi_name() === 'cli')
    || (isset($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1', '::1']))
    || (isset($_SERVER['HTTP_HOST']) && preg_match('/^(localhost|127\.0\.0\.1|192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+)(:\d+)?$/', $_SERVER['HTTP_HOST']));

if ($is_local) {
    // Local XAMPP Settings
    $host = "localhost";
    $user = "root";
    $pass = "";
    $dbname = "online_exam_db";
} else {
    // InfinityFree Cloud Settings
    $host = "sql309.infinityfree.com";
    $user = "if0_42825922";
    $pass = "exampasswd123";
    $dbname = "if0_42825922_exam";
}

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Force UTF-8 (utf8mb4) to ensure math formulas and unicode symbols are never mangled
mysqli_set_charset($conn, "utf8mb4");

// Align timezone for PHP and MySQL (Asia/Kolkata +05:30)
date_default_timezone_set('Asia/Kolkata');
@mysqli_query($conn, "SET time_zone = '+05:30'");

// Auto-migrate schema updates if not present
if (!function_exists('run_auto_migrations')) {
function run_auto_migrations($conn) {
    if (!$conn) return;

    // 1. exams.result_mode ('instant' or 'pending')
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'result_mode'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE exams ADD COLUMN result_mode VARCHAR(20) NOT NULL DEFAULT 'instant'");
    }

    // 2. questions.question_type ('mcq' or 'descriptive') & nullable options
    $check = mysqli_query($conn, "SHOW COLUMNS FROM questions LIKE 'question_type'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE questions ADD COLUMN question_type VARCHAR(20) NOT NULL DEFAULT 'mcq'");
        @mysqli_query($conn, "ALTER TABLE questions MODIFY option_a VARCHAR(255) NULL");
        @mysqli_query($conn, "ALTER TABLE questions MODIFY option_b VARCHAR(255) NULL");
        @mysqli_query($conn, "ALTER TABLE questions MODIFY option_c VARCHAR(255) NULL");
        @mysqli_query($conn, "ALTER TABLE questions MODIFY option_d VARCHAR(255) NULL");
        @mysqli_query($conn, "ALTER TABLE questions MODIFY correct_option CHAR(1) NULL");
    }

    // 3. results.status ('published' or 'pending') & admin_feedback
    $check = mysqli_query($conn, "SHOW COLUMNS FROM results LIKE 'status'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE results ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'published'");
        @mysqli_query($conn, "ALTER TABLE results ADD COLUMN admin_feedback TEXT NULL");
        @mysqli_query($conn, "ALTER TABLE results ADD COLUMN evaluated_at DATETIME NULL");
    }

    // 4. student_answers table for storing both MCQ and descriptive student responses
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_answers (
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

    // 5. Add unique index on students.email if not already present (prevents duplicate IDs)
    @mysqli_query($conn, "ALTER TABLE students ADD UNIQUE INDEX IF NOT EXISTS idx_students_email (email)");

    // 6. questions.marks column (stores max marks per question; default 1 for MCQ, 5 for descriptive)
    $check = mysqli_query($conn, "SHOW COLUMNS FROM questions LIKE 'marks'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE questions ADD COLUMN marks DECIMAL(5,2) NOT NULL DEFAULT 1 AFTER question_type");
    }

    // 7. exam_sessions — server-authoritative timer + question order seed
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exam_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        duration_minutes INT NOT NULL DEFAULT 30,
        question_seed VARCHAR(64) NOT NULL DEFAULT '',
        submitted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_student_exam (student_id, exam_id)
    )");

    // 8. draft_answers — AJAX auto-save per question before final submission
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS draft_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        question_id INT NOT NULL,
        answer TEXT NULL,
        saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_draft (student_id, exam_id, question_id)
    )");

    // 9. exam_violations — anti-cheat audit log (tab-switch, fullscreen exit, blocked keys)
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exam_violations (
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

    // 10. exams.start_at / end_at — exam schedule window
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'start_at'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE exams ADD COLUMN start_at DATETIME NULL AFTER result_mode");
        @mysqli_query($conn, "ALTER TABLE exams ADD COLUMN end_at   DATETIME NULL AFTER start_at");
    }

    // 11. exam_sessions columns: time_taken_seconds, question_seed, duration_minutes
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'time_taken_seconds'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE exam_sessions ADD COLUMN time_taken_seconds INT NULL AFTER submitted");
    }
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'question_seed'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE exam_sessions ADD COLUMN question_seed VARCHAR(64) NOT NULL DEFAULT '' AFTER duration_minutes");
    }
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'duration_minutes'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE exam_sessions ADD COLUMN duration_minutes INT NOT NULL DEFAULT 30 AFTER started_at");
    }

    // 12. classes — admin-configurable class groups (FYIT, SYIT, TYIT, …)
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS classes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(50)  NOT NULL,
        description VARCHAR(200) NULL,
        sort_order  INT          NOT NULL DEFAULT 0,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_class_name (name)
    )");
    // Seed default classes — INSERT IGNORE is idempotent
    @mysqli_query($conn, "INSERT IGNORE INTO classes (name, description, sort_order) VALUES
        ('FYIT', 'First Year Information Technology', 1),
        ('SYIT', 'Second Year Information Technology', 2),
        ('TYIT', 'Third Year Information Technology', 3)");

    // 13. students.class_id — foreign key to classes
    $check = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'class_id'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE students ADD COLUMN class_id INT NULL AFTER name");
    }

    // 14. exam_class_assignments — many-to-many: exam ↔ class
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exam_class_assignments (
        exam_id  INT NOT NULL,
        class_id INT NOT NULL,
        PRIMARY KEY (exam_id, class_id)
    )");

    // 15. admin.created_at column
    $check = mysqli_query($conn, "SHOW COLUMNS FROM admin LIKE 'created_at'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE admin ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }

    // 16. exams.questions_to_display — anti-cheat question pool limit (0 = all questions)
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'questions_to_display'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE exams ADD COLUMN questions_to_display INT NOT NULL DEFAULT 0 AFTER end_at");
    }

    // 17. exam_sessions.assigned_questions — student-locked question IDs for consistent pool subset & order
    $check = mysqli_query($conn, "SHOW COLUMNS FROM exam_sessions LIKE 'assigned_questions'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE exam_sessions ADD COLUMN assigned_questions TEXT NULL AFTER question_seed");
    }
}
}

run_auto_migrations($conn);
?>
