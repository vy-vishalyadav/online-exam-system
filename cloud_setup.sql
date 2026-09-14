CREATE TABLE IF NOT EXISTS `student_answers` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `result_id`     INT NOT NULL,
    `student_id`    INT NOT NULL,
    `exam_id`       INT NOT NULL,
    `question_id`   INT NOT NULL,
    `user_answer`   TEXT NULL,
    `is_correct`    TINYINT(1) DEFAULT NULL,
    `marks_awarded` DECIMAL(5,2) DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS `exam_sessions` (
    `id`                 INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`         INT NOT NULL,
    `exam_id`            INT NOT NULL,
    `started_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `duration_minutes`   INT NOT NULL DEFAULT 30,
    `question_seed`      VARCHAR(64) NOT NULL DEFAULT '',
    `submitted`          TINYINT(1) NOT NULL DEFAULT 0,
    `time_taken_seconds` INT NULL,
    UNIQUE KEY `uq_student_exam` (`student_id`, `exam_id`)
);

CREATE TABLE IF NOT EXISTS `draft_answers` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`  INT NOT NULL,
    `exam_id`     INT NOT NULL,
    `question_id` INT NOT NULL,
    `answer`      TEXT NULL,
    `saved_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_draft` (`student_id`, `exam_id`, `question_id`)
);

CREATE TABLE IF NOT EXISTS `exam_violations` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`     INT NOT NULL,
    `exam_id`        INT NOT NULL,
    `violation_type` VARCHAR(50) NOT NULL,
    `detail`         VARCHAR(255) NULL,
    `ip_address`     VARCHAR(45) NULL,
    `user_agent`     TEXT NULL,
    `occurred_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_violations` (`student_id`, `exam_id`)
);

CREATE TABLE IF NOT EXISTS `classes` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(50) NOT NULL,
    `description` VARCHAR(200) NULL,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_class_name` (`name`)
);

INSERT IGNORE INTO `classes` (`name`, `description`, `sort_order`) VALUES
    ('FYIT', 'First Year Information Technology', 1),
    ('SYIT', 'Second Year Information Technology', 2),
    ('TYIT', 'Third Year Information Technology', 3);

CREATE TABLE IF NOT EXISTS `exam_class_assignments` (
    `exam_id`  INT NOT NULL,
    `class_id` INT NOT NULL,
    PRIMARY KEY (`exam_id`, `class_id`)
);

ALTER TABLE `questions` ADD COLUMN `marks` DECIMAL(5,2) NOT NULL DEFAULT 1;
ALTER TABLE `students` ADD COLUMN `class_id` INT NULL;
ALTER TABLE `exams` ADD COLUMN `start_at` DATETIME NULL;
ALTER TABLE `exams` ADD COLUMN `end_at` DATETIME NULL;
