<?php
// Auto-detect environment (Localhost vs InfinityFree Cloud)
if (isset($_SERVER['SERVER_NAME']) && ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1')) {
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

// Auto-migrate schema updates if not present
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
}

run_auto_migrations($conn);
?>
