-- ============================================================
--  Online Exam System — Complete Database Setup
-- ============================================================

-- Automatically selects your InfinityFree database:
USE `if0_42825922_exam`;

-- ─────────────────────────────────────────────
--  TABLE: admin
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `admin` (
    `id`       INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50)  NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL
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
    `start_at`         DATETIME     NULL,
    `end_at`           DATETIME     NULL
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
    `score`        INT          NOT NULL,
    `status`       VARCHAR(20)  NOT NULL DEFAULT 'published',
    `admin_feedback` TEXT       NULL,
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `evaluated_at` DATETIME     NULL,
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
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`exam_id`)    REFERENCES `exams`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
--  SAMPLE DATA (optional — delete this section for production)
-- ============================================================

-- Sample classes
INSERT IGNORE INTO `classes` (`name`, `description`, `sort_order`) VALUES
    ('FYIT', 'First Year Information Technology', 1),
    ('SYIT', 'Second Year Information Technology', 2),
    ('TYIT', 'Third Year Information Technology', 3);

-- Sample students  (password: student)
INSERT INTO `students` (`id`, `name`, `email`, `password`) VALUES
    (1, 'John Doe',     '10001@rclasses.com', '$2y$10$0c2AG/wWtuQK3tOH9yjSrOQwo39tEEVhvJanencPZKxVD5HsAQvNK'),
    (2, 'Jane Smith',   '10002@rclasses.com', '$2y$10$0c2AG/wWtuQK3tOH9yjSrOQwo39tEEVhvJanencPZKxVD5HsAQvNK'),
    (3, 'Demo Student', '10003@rclasses.com', '$2y$10$0c2AG/wWtuQK3tOH9yjSrOQwo39tEEVhvJanencPZKxVD5HsAQvNK')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Sample exams
INSERT INTO `exams` (`id`, `title`, `duration_minutes`) VALUES
    (1, 'General Knowledge Quiz',  15),
    (2, 'Computer Fundamentals',   20),
    (3, 'Mathematics Basics',      25),
    (4, 'Web Development Basics',  30)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `duration_minutes` = VALUES(`duration_minutes`);

-- Sample questions — Exam 1: General Knowledge
INSERT IGNORE INTO `questions` (`id`, `exam_id`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
    (1,  1, 'Which planet is known as the Red Planet?',       'Venus',            'Mars',     'Jupiter',          'Saturn',   'B'),
    (2,  1, 'What is the capital city of France?',           'Madrid',            'Berlin',   'Paris',            'Rome',     'C'),
    (3,  1, 'Who painted the Mona Lisa?',                    'Vincent van Gogh',  'Picasso',  'Leonardo da Vinci','Monet',    'C'),
    (4,  1, 'Which element has the chemical symbol "O"?',    'Gold',              'Oxygen',   'Osmium',           'Silver',   'B'),
    (5,  1, 'What is the largest ocean on Earth?',           'Atlantic',          'Indian',   'Arctic',           'Pacific',  'D');

-- Sample questions — Exam 2: Computer Fundamentals
INSERT IGNORE INTO `questions` (`id`, `exam_id`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
    (6,  2, 'What does CPU stand for?',                                  'Central Processing Unit','Computer Personal Unit','Central Process Utility','Central Peripheral Unit','A'),
    (7,  2, 'Which of the following is volatile memory?',                'ROM','RAM','Hard Disk','SSD','B'),
    (8,  2, 'What is the main function of an Operating System?',         'Manage hardware and software','Design graphics','Compile programs','Create spreadsheets','A'),
    (9,  2, 'Which protocol transfers web pages over the internet?',     'FTP','SMTP','HTTP','SNMP','C'),
    (10, 2, 'Which binary digit represents TRUE state?',                 '0','1','-1','NULL','B');

-- Sample questions — Exam 3: Mathematics
INSERT IGNORE INTO `questions` (`id`, `exam_id`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
    (11, 3, 'What is the square root of 144?',              '10','11','12','14','C'),
    (12, 3, 'Value of Pi rounded to 2 decimal places?',     '3.14','3.16','3.12','3.18','A'),
    (13, 3, 'Solve for x: 2x + 5 = 15',                    'x=3','x=5','x=10','x=7','B'),
    (14, 3, 'What is 15% of 200?',                          '20','25','30','35','C'),
    (15, 3, 'How many sides does a hexagon have?',          '5','6','7','8','B');

-- Sample questions — Exam 4: Web Development
INSERT IGNORE INTO `questions` (`id`, `exam_id`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`) VALUES
    (16, 4, 'What does HTML stand for?',                                'Hyper Text Markup Language','High Tech Markup Language','Hyperlink Text Mgmt Language','Home Tool Markup Language','A'),
    (17, 4, 'Which CSS property changes text color?',                   'font-color','text-color','color','background-color','C'),
    (18, 4, 'Which HTML tag defines an internal style sheet?',          '<script>','<style>','<css>','<link>','B'),
    (19, 4, 'PHP superglobal for POST form data?',                      '$_GET','$_REQUEST','$_SESSION','$_POST','D'),
    (20, 4, 'Which SQL command retrieves data from a database?',        'GET','SELECT','FETCH','EXTRACT','B');
