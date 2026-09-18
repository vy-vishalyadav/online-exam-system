-- ============================================================
--  Online Exam System — Complete Database Setup
-- ============================================================

-- USE `your_database_name`; -- For Remote/Cloud MySQL
-- USE `online_exam_db`;    -- For Local XAMPP

-- ─────────────────────────────────────────────
--  TABLE: admin
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `admin` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `username`   VARCHAR(50)  NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin account  (username: admin | password: admin123)
INSERT INTO `admin` (`username`, `password`)
SELECT 'admin', '$2y$10$jBSJ8A5wVyRRgyh0pHet0e3FteEY/UNRI8ON27SoQ8hqTBtX6NeG2'
WHERE NOT EXISTS (SELECT 1 FROM `admin` WHERE `username` = 'admin');

-- ─────────────────────────────────────────────
--  TABLE: classes
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `classes` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(50)  NOT NULL,
    `description` VARCHAR(200) NULL,
    `sort_order`  INT          NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_class_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: students
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `students` (
    `id`       INT AUTO_INCREMENT PRIMARY KEY,
    `name`     VARCHAR(100) NOT NULL,
    `email`    VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `class_id` INT NULL,
    FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: exams
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `exams` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `title`            VARCHAR(150) NOT NULL,
    `duration_minutes` INT          NOT NULL DEFAULT 30,
    `result_mode`      VARCHAR(20)  NOT NULL DEFAULT 'instant',
    `start_at`             DATETIME     NULL,
    `end_at`               DATETIME     NULL,
    `questions_to_display` INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: questions
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `questions` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `exam_id`        INT          NOT NULL,
    `question_text`  TEXT         NOT NULL,
    `question_type`  VARCHAR(20)  NOT NULL DEFAULT 'mcq',
    `option_a`       VARCHAR(255) NULL,
    `option_b`       VARCHAR(255) NULL,
    `option_c`       VARCHAR(255) NULL,
    `option_d`       VARCHAR(255) NULL,
    `correct_option` CHAR(1)      NULL,
    `marks`          DECIMAL(5,2) NOT NULL DEFAULT 1,
    FOREIGN KEY (`exam_id`) REFERENCES `exams`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: exam_class_assignments
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `exam_class_assignments` (
    `exam_id`  INT NOT NULL,
    `class_id` INT NOT NULL,
    PRIMARY KEY (`exam_id`, `class_id`),
    FOREIGN KEY (`exam_id`)  REFERENCES `exams`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: results
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `results` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`   INT          NOT NULL,
    `exam_id`      INT          NOT NULL,
    `score`        DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `status`       VARCHAR(20)  NOT NULL DEFAULT 'published',
    `admin_feedback` TEXT       NULL,
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `evaluated_at` DATETIME     NULL,
    UNIQUE KEY `uq_student_exam_result` (`student_id`, `exam_id`),
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`exam_id`)    REFERENCES `exams`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: student_answers
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_answers` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `result_id`     INT          NOT NULL,
    `student_id`    INT          NOT NULL,
    `exam_id`       INT          NOT NULL,
    `question_id`   INT          NOT NULL,
    `user_answer`   TEXT         NULL,
    `is_correct`    TINYINT(1)   DEFAULT NULL,
    `marks_awarded` DECIMAL(5,2) DEFAULT NULL,
    FOREIGN KEY (`result_id`)   REFERENCES `results`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`student_id`)  REFERENCES `students`(`id`)  ON DELETE CASCADE,
    FOREIGN KEY (`exam_id`)     REFERENCES `exams`(`id`)     ON DELETE CASCADE,
    FOREIGN KEY (`question_id`) REFERENCES `questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: exam_sessions
--  (server-authoritative timer & retake tracking)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `exam_sessions` (
    `id`                 INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`         INT          NOT NULL,
    `exam_id`            INT          NOT NULL,
    `started_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `duration_minutes`   INT          NOT NULL DEFAULT 30,
    `question_seed`      VARCHAR(64)  NOT NULL DEFAULT '',
    `assigned_questions` TEXT         NULL,
    `submitted`          TINYINT(1)   NOT NULL DEFAULT 0,
    `time_taken_seconds` INT          NULL,
    UNIQUE KEY `uq_student_exam` (`student_id`, `exam_id`),
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`exam_id`)    REFERENCES `exams`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: draft_answers
--  (per-answer auto-save while exam is in progress)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `draft_answers` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`  INT      NOT NULL,
    `exam_id`     INT      NOT NULL,
    `question_id` INT      NOT NULL,
    `answer`      TEXT     NULL,
    `saved_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_draft` (`student_id`, `exam_id`, `question_id`),
    FOREIGN KEY (`student_id`)  REFERENCES `students`(`id`)  ON DELETE CASCADE,
    FOREIGN KEY (`exam_id`)     REFERENCES `exams`(`id`)     ON DELETE CASCADE,
    FOREIGN KEY (`question_id`) REFERENCES `questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: exam_violations
--  (anti-cheat: tab switches, fullscreen exits, blocked keys)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `exam_violations` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`     INT          NOT NULL,
    `exam_id`        INT          NOT NULL,
    `violation_type` VARCHAR(50)  NOT NULL,
    `detail`         VARCHAR(255) NULL,
    `ip_address`     VARCHAR(45)  NULL,
    `user_agent`     TEXT         NULL,
    `occurred_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_violations` (`student_id`, `exam_id`),
    INDEX `idx_violations_lookup` (`student_id`, `exam_id`, `violation_type`),
    INDEX `idx_violations_exam_time` (`exam_id`, `occurred_at`),
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`exam_id`)    REFERENCES `exams`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────
--  TABLE: app_jobs
--  (throttled background and maintenance tasks)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `app_jobs` (
    `job_name`     VARCHAR(50) NOT NULL PRIMARY KEY,
    `last_run_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `locked_until` DATETIME    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `app_jobs` (`job_name`, `last_run_at`) VALUES ('finalize_expired_exams', '2000-01-01 00:00:00');



-- ============================================================
--  SAMPLE DATA (Realistic collegiate testing & demonstration data)
-- ============================================================

-- 1. Sample Admins (Passwords: admin123)
INSERT INTO `admin` (`id`, `username`, `password`, `created_at`) VALUES
    (1, 'admin',      '$2y$10$jBSJ8A5wVyRRgyh0pHet0e3FteEY/UNRI8ON27SoQ8hqTBtX6NeG2', NOW()),
    (2, 'supervisor', '$2y$10$jBSJ8A5wVyRRgyh0pHet0e3FteEY/UNRI8ON27SoQ8hqTBtX6NeG2', NOW())
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

-- 2. Sample Academic Classes
INSERT INTO `classes` (`id`, `name`, `description`, `sort_order`, `created_at`) VALUES
    (1, 'FYIT', 'First Year Information Technology', 1, NOW()),
    (2, 'SYIT', 'Second Year Information Technology', 2, NOW()),
    (3, 'TYIT', 'Third Year Information Technology', 3, NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);

-- 3. Sample Students (Password: student)
INSERT INTO `students` (`id`, `name`, `email`, `password`, `class_id`) VALUES
    (1, 'John Doe',     '10001@fyit.com', '$2y$10$0c2AG/wWtuQK3tOH9yjSrOQwo39tEEVhvJanencPZKxVD5HsAQvNK', 1),
    (2, 'Jane Smith',   '10002@syit.com', '$2y$10$0c2AG/wWtuQK3tOH9yjSrOQwo39tEEVhvJanencPZKxVD5HsAQvNK', 2),
    (3, 'Demo Student', '10003@tyit.com', '$2y$10$0c2AG/wWtuQK3tOH9yjSrOQwo39tEEVhvJanencPZKxVD5HsAQvNK', 3),
    (4, 'Alex Turner',  '10004@fyit.com', '$2y$10$0c2AG/wWtuQK3tOH9yjSrOQwo39tEEVhvJanencPZKxVD5HsAQvNK', 1),
    (5, 'Priya Sharma', '10005@syit.com', '$2y$10$0c2AG/wWtuQK3tOH9yjSrOQwo39tEEVhvJanencPZKxVD5HsAQvNK', 2),
    (6, 'Rahul Verma',  '10006@tyit.com', '$2y$10$0c2AG/wWtuQK3tOH9yjSrOQwo39tEEVhvJanencPZKxVD5HsAQvNK', 3)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `email` = VALUES(`email`), `class_id` = VALUES(`class_id`);

-- 4. Sample Scheduled Exams
INSERT INTO `exams` (`id`, `title`, `duration_minutes`, `result_mode`, `start_at`, `end_at`) VALUES
    (1, 'General Knowledge Quiz',        15, 'instant', '2026-09-01 08:00:00', '2026-12-31 23:59:59'),
    (2, 'Computer Fundamentals',         20, 'instant', '2026-09-01 08:00:00', '2026-12-31 23:59:59'),
    (3, 'Mathematics Basics',            25, 'instant', '2026-09-01 08:00:00', '2026-12-31 23:59:59'),
    (4, 'Web Development Basics',        30, 'instant', '2026-09-01 08:00:00', '2026-12-31 23:59:59'),
    (5, 'Database Management Systems',   35, 'pending', '2026-09-01 08:00:00', '2026-12-31 23:59:59')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `duration_minutes` = VALUES(`duration_minutes`), `result_mode` = VALUES(`result_mode`), `start_at` = VALUES(`start_at`), `end_at` = VALUES(`end_at`);

-- 5. Exam Class Assignments
INSERT IGNORE INTO `exam_class_assignments` (`exam_id`, `class_id`) VALUES
    (1, 1), (1, 2), (1, 3), -- Exam 1 open to all classes
    (2, 1), (2, 2),         -- Exam 2 assigned to FYIT & SYIT
    (3, 2), (3, 3),         -- Exam 3 assigned to SYIT & TYIT
    (4, 3),                 -- Exam 4 assigned to TYIT
    (5, 1), (5, 2), (5, 3); -- Exam 5 open to all classes

-- 6. Sample Questions — Exam 1: General Knowledge (MCQ) - 2 marks each (10 marks total)
INSERT INTO `questions` (`id`, `exam_id`, `question_text`, `question_type`, `marks`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
    (1, 1, 'Which planet is known as the Red Planet?',    'mcq', 2.00, 'Venus',           'Mars',    'Jupiter',           'Saturn',  'B'),
    (2, 1, 'What is the capital city of France?',        'mcq', 2.00, 'Madrid',          'Berlin',  'Paris',             'Rome',    'C'),
    (3, 1, 'Who painted the Mona Lisa?',                 'mcq', 2.00, 'Vincent van Gogh','Picasso', 'Leonardo da Vinci', 'Monet',   'C'),
    (4, 1, 'Which element has chemical symbol "O"?',     'mcq', 2.00, 'Gold',            'Oxygen',  'Osmium',            'Silver',  'B'),
    (5, 1, 'What is the largest ocean on Earth?',        'mcq', 2.00, 'Atlantic',        'Indian',  'Arctic',            'Pacific', 'D')
ON DUPLICATE KEY UPDATE `question_text` = VALUES(`question_text`), `marks` = VALUES(`marks`), `correct_option` = VALUES(`correct_option`);

-- 7. Sample Questions — Exam 2: Computer Fundamentals (MCQ)
INSERT INTO `questions` (`id`, `exam_id`, `question_text`, `question_type`, `marks`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
    (6,  2, 'What does CPU stand for?',                               'mcq', 1.00, 'Central Processing Unit','Computer Personal Unit','Central Process Utility','Central Peripheral Unit','A'),
    (7,  2, 'Which of the following is volatile memory?',             'mcq', 1.00, 'ROM','RAM','Hard Disk','SSD','B'),
    (8,  2, 'What is the main function of an Operating System?',      'mcq', 1.00, 'Manage hardware and software','Design graphics','Compile programs','Create spreadsheets','A'),
    (9,  2, 'Which protocol transfers web pages over the internet?',  'mcq', 1.00, 'FTP','SMTP','HTTP','SNMP','C'),
    (10, 2, 'Which binary digit represents TRUE state?',              'mcq', 1.00, '0','1','-1','NULL','B')
ON DUPLICATE KEY UPDATE `question_text` = VALUES(`question_text`), `correct_option` = VALUES(`correct_option`);

-- 8. Sample Questions — Exam 3: Mathematics Basics (MCQ)
INSERT INTO `questions` (`id`, `exam_id`, `question_text`, `question_type`, `marks`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
    (11, 3, 'What is the square root of 144?',           'mcq', 1.00, '10','11','12','14','C'),
    (12, 3, 'Value of Pi rounded to 2 decimal places?',  'mcq', 1.00, '3.14','3.16','3.12','3.18','A'),
    (13, 3, 'Solve for x: 2x + 5 = 15',                 'mcq', 1.00, 'x=3','x=5','x=10','x=7','B'),
    (14, 3, 'What is 15% of 200?',                       'mcq', 1.00, '20','25','30','35','C'),
    (15, 3, 'How many sides does a hexagon have?',       'mcq', 1.00, '5','6','7','8','B')
ON DUPLICATE KEY UPDATE `question_text` = VALUES(`question_text`), `correct_option` = VALUES(`correct_option`);

-- 9. Sample Questions — Exam 4: Web Development Basics (MCQ)
INSERT INTO `questions` (`id`, `exam_id`, `question_text`, `question_type`, `marks`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
    (16, 4, 'What does HTML stand for?',                               'mcq', 1.00, 'Hyper Text Markup Language','High Tech Markup Language','Hyperlink Text Mgmt Language','Home Tool Markup Language','A'),
    (17, 4, 'Which CSS property changes text color?',                  'mcq', 1.00, 'font-color','text-color','color','background-color','C'),
    (18, 4, 'Which HTML tag defines an internal style sheet?',         'mcq', 1.00, '<script>','<style>','<css>','<link>','B'),
    (19, 4, 'PHP superglobal for POST form data?',                     'mcq', 1.00, '$_GET','$_REQUEST','$_SESSION','$_POST','D'),
    (20, 4, 'Which SQL command retrieves data from a database?',       'mcq', 1.00, 'GET','SELECT','FETCH','EXTRACT','B')
ON DUPLICATE KEY UPDATE `question_text` = VALUES(`question_text`), `correct_option` = VALUES(`correct_option`);

-- 10. Sample Questions — Exam 5: Database Management Systems (MCQs + Descriptive)
INSERT INTO `questions` (`id`, `exam_id`, `question_text`, `question_type`, `marks`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
    (21, 5, 'What does DBMS stand for?',                                                                                                   'mcq',         1.00,  'Database Management System','Data Business Model System','Digital Base Manage Server','Distributed Binary Media System','A'),
    (22, 5, 'Which normal form deals with removing transitive dependencies?',                                                               'mcq',         1.00,  '1NF','2NF','3NF','BCNF','C'),
    (23, 5, 'Which SQL DDL command deletes a table structure along with all its records permanently?',                                     'mcq',         1.00,  'DELETE','TRUNCATE','DROP','REMOVE','C'),
    (24, 5, 'Explain the difference between a Primary Key and a Foreign Key with a real-world collegiate database example.',              'descriptive', 5.00,  NULL, NULL, NULL, NULL, NULL),
    (25, 5, 'What are ACID properties in database management systems? Explain Atomicity, Consistency, Isolation, and Durability briefly.', 'descriptive', 10.00, NULL, NULL, NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE `question_text` = VALUES(`question_text`), `question_type` = VALUES(`question_type`), `marks` = VALUES(`marks`), `correct_option` = VALUES(`correct_option`);

-- 11. Sample Exam Sessions
INSERT INTO `exam_sessions` (`id`, `student_id`, `exam_id`, `started_at`, `duration_minutes`, `question_seed`, `submitted`, `time_taken_seconds`) VALUES
    (1, 1, 1, '2026-09-15 10:00:00', 15, 'seed_101', 1, 480),
    (2, 2, 1, '2026-09-15 10:05:00', 15, 'seed_102', 1, 540),
    (3, 3, 1, '2026-09-15 10:10:00', 15, 'seed_103', 1, 620),
    (4, 3, 5, '2026-09-16 14:00:00', 35, 'seed_104', 1, 1120)
ON DUPLICATE KEY UPDATE `submitted` = VALUES(`submitted`), `time_taken_seconds` = VALUES(`time_taken_seconds`);

-- 12. Sample Results (Published & Pending Review)
INSERT INTO `results` (`id`, `student_id`, `exam_id`, `score`, `status`, `admin_feedback`, `attempted_at`, `evaluated_at`) VALUES
    (1, 1, 1, 4, 'published', 'Great performance on General Knowledge.', '2026-09-15 10:08:00', '2026-09-15 10:08:00'),
    (2, 2, 1, 3, 'published', 'Good effort, revise capital cities.',      '2026-09-15 10:14:00', '2026-09-15 10:14:00'),
    (3, 3, 1, 5, 'published', 'Perfect score! Excellent work.',           '2026-09-15 10:20:20', '2026-09-15 10:20:20'),
    (4, 3, 5, 3, 'pending',   NULL,                                        '2026-09-16 14:18:40', NULL)
ON DUPLICATE KEY UPDATE `score` = VALUES(`score`), `status` = VALUES(`status`), `admin_feedback` = VALUES(`admin_feedback`);

-- 13. Sample Student Answers
INSERT INTO `student_answers` (`id`, `result_id`, `student_id`, `exam_id`, `question_id`, `user_answer`, `is_correct`, `marks_awarded`) VALUES
    -- Answers for Result 1 (Student 1, Exam 1)
    (1,  1, 1, 1, 1, 'B', 1, 1.00),
    (2,  1, 1, 1, 2, 'C', 1, 1.00),
    (3,  1, 1, 1, 3, 'C', 1, 1.00),
    (4,  1, 1, 1, 4, 'B', 1, 1.00),
    (5,  1, 1, 1, 5, 'A', 0, 0.00),
    -- Answers for Result 4 (Student 3, Exam 5 - Pending Review)
    (6,  4, 3, 5, 21, 'A', 1, 1.00),
    (7,  4, 3, 5, 22, 'C', 1, 1.00),
    (8,  4, 3, 5, 23, 'C', 1, 1.00),
    (9,  4, 3, 5, 24, 'A Primary Key uniquely identifies a record within a single table (e.g., student_id in students table). A Foreign Key is a column that refers to the Primary Key of another table to establish a relationship (e.g., class_id in students pointing to id in classes table).', NULL, NULL),
    (10, 4, 3, 5, 25, 'ACID stands for:\n1. Atomicity: The entire transaction completes or fails completely (all-or-nothing).\n2. Consistency: The database must transition from one valid state to another, upholding all constraints.\n3. Isolation: Concurrent transactions execute independently without interfering with each other.\n4. Durability: Once a transaction is committed, changes persist permanently even in power loss.', NULL, NULL)
ON DUPLICATE KEY UPDATE `user_answer` = VALUES(`user_answer`), `is_correct` = VALUES(`is_correct`), `marks_awarded` = VALUES(`marks_awarded`);

-- 14. Sample Anti-Cheat Violations (Provides realistic flagged offenders for audit log)
INSERT INTO `exam_violations` (`id`, `student_id`, `exam_id`, `violation_type`, `detail`, `ip_address`, `user_agent`, `occurred_at`) VALUES
    (1, 1, 3, 'tab_switch',      'Switched out of exam tab to external window',               '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 14:10:15'),
    (2, 1, 3, 'fullscreen_exit', 'Exited browser fullscreen mode during active exam',         '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 14:12:00'),
    (3, 1, 3, 'tab_switch',      'Switched active browser tab',                               '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 14:14:22'),
    (4, 1, 3, 'fullscreen_exit', 'Attempted window resize / split screen',                   '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 14:15:05'),
    (5, 3, 2, 'fullscreen_exit', 'Exited fullscreen mode',                                    '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 13:45:10'),
    (6, 3, 2, 'tab_switch',      'Window lost focus (blur event)',                            '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 13:48:30'),
    (7, 3, 2, 'tab_switch',      'Switched to external communication application',            '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 13:51:12'),
    (8, 3, 2, 'blocked_key',     'Attempted prohibited shortcut Ctrl+C (Copy prompt)',        '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 13:52:00'),
    (9, 2, 2, 'fullscreen_exit', 'Exited fullscreen mode',                                    '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 12:30:18'),
    (10, 2, 2, 'tab_switch',     'Switched browser tab',                                      '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 12:33:45'),
    (11, 2, 2, 'tab_switch',     'Switched browser tab',                                      '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 12:35:20'),
    (12, 4, 1, 'tab_switch',     'Switched tab during exam',                                  '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 11:20:00'),
    (13, 4, 1, 'fullscreen_exit','Left fullscreen',                                           '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 11:21:40'),
    (14, 5, 3, 'fullscreen_exit','Exited fullscreen mode',                                    '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 10:15:30'),
    (15, 5, 3, 'tab_switch',     'Switched to another window',                                '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 10:18:10'),
    (16, 6, 4, 'tab_switch',     'Tab switch violation',                                      '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 09:40:00'),
    (17, 6, 4, 'blocked_key',    'Attempted prohibited shortcut Ctrl+V (Clipboard paste)',    '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2026-09-17 09:42:15')
ON DUPLICATE KEY UPDATE `detail` = VALUES(`detail`);
