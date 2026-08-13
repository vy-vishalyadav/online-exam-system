-- Online Exam System Database Setup
-- Run this file in phpMyAdmin or MySQL CLI

CREATE DATABASE IF NOT EXISTS online_exam_db;
USE online_exam_db;

-- Students table
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

-- Admin table
CREATE TABLE IF NOT EXISTS admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

-- Default admin account: admin / admin123
INSERT INTO admin (username, password) 
SELECT 'admin', 'admin123'
WHERE NOT EXISTS (SELECT * FROM admin WHERE username = 'admin');

-- Exams table
CREATE TABLE IF NOT EXISTS exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 30
);

-- Questions table
CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_text TEXT NOT NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_option CHAR(1) NOT NULL,
    exam_id INT NOT NULL,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
);

-- Results table
CREATE TABLE IF NOT EXISTS results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    score INT NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
);

-- Insert sample students if not exists
INSERT INTO students (id, name, email, password) VALUES
(1, 'John Doe', 'john@example.com', 'student123'),
(2, 'Jane Smith', 'jane@example.com', 'student123'),
(3, 'Demo Student', 'student@example.com', 'student123')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Insert sample exams if not exists
INSERT INTO exams (id, title, duration_minutes) VALUES
(1, 'General Knowledge Quiz', 15),
(2, 'Computer Fundamentals', 20),
(3, 'Mathematics Basics', 25),
(4, 'Web Development Basics', 30)
ON DUPLICATE KEY UPDATE title=VALUES(title), duration_minutes=VALUES(duration_minutes);

-- Sample Questions for Exam 1: General Knowledge Quiz
INSERT IGNORE INTO questions (id, exam_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES
(1, 1, 'Which planet is known as the Red Planet?', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'B'),
(2, 1, 'What is the capital city of France?', 'Madrid', 'Berlin', 'Paris', 'Rome', 'C'),
(3, 1, 'Who painted the Mona Lisa?', 'Vincent van Gogh', 'Pablo Picasso', 'Leonardo da Vinci', 'Claude Monet', 'C'),
(4, 1, 'Which element has the chemical symbol "O"?', 'Gold', 'Oxygen', 'Osmium', 'Silver', 'B'),
(5, 1, 'What is the largest ocean on Earth?', 'Atlantic Ocean', 'Indian Ocean', 'Arctic Ocean', 'Pacific Ocean', 'D');

-- Sample Questions for Exam 2: Computer Fundamentals
INSERT IGNORE INTO questions (id, exam_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES
(6, 2, 'What does CPU stand for?', 'Central Processing Unit', 'Computer Personal Unit', 'Central Process Utility', 'Central Peripheral Unit', 'A'),
(7, 2, 'Which of the following is volatile memory?', 'ROM', 'RAM', 'Hard Disk', 'SSD', 'B'),
(8, 2, 'What is the main function of an Operating System?', 'Manage hardware and software resources', 'Design graphics', 'Compile programs', 'Create spreadsheets', 'A'),
(9, 2, 'Which protocol is used to transfer web pages over the internet?', 'FTP', 'SMTP', 'HTTP', 'SNMP', 'C'),
(10, 2, 'What binary digit represents TRUE state?', '0', '1', '-1', 'NULL', 'B');

-- Sample Questions for Exam 3: Mathematics Basics
INSERT IGNORE INTO questions (id, exam_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES
(11, 3, 'What is the square root of 144?', '10', '11', '12', '14', 'C'),
(12, 3, 'What is the value of Pi rounded to 2 decimal places?', '3.14', '3.16', '3.12', '3.18', 'A'),
(13, 3, 'Solve for x: 2x + 5 = 15', 'x = 3', 'x = 5', 'x = 10', 'x = 7', 'B'),
(14, 3, 'What is 15% of 200?', '20', '25', '30', '35', 'C'),
(15, 3, 'How many sides does a hexagon have?', '5', '6', '7', '8', 'B');

-- Sample Questions for Exam 4: Web Development Basics
INSERT IGNORE INTO questions (id, exam_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES
(16, 4, 'What does HTML stand for?', 'Hyper Text Markup Language', 'High Tech Markup Language', 'Hyperlink Text Management Language', 'Home Tool Markup Language', 'A'),
(17, 4, 'Which CSS property is used to change text color?', 'font-color', 'text-color', 'color', 'background-color', 'C'),
(18, 4, 'Which HTML tag is used to define an internal style sheet?', '<script>', '<style>', '<css>', '<link>', 'B'),
(19, 4, 'Which superglobal variable in PHP is used to collect form data sent with method="POST"?', '$_GET', '$_REQUEST', '$_SESSION', '$_POST', 'D'),
(20, 4, 'Which SQL command is used to retrieve data from a database?', 'GET', 'SELECT', 'FETCH', 'EXTRACT', 'B');

-- Sample Exam Results
INSERT IGNORE INTO results (id, student_id, exam_id, score, attempted_at) VALUES
(1, 1, 1, 80, NOW() - INTERVAL 2 DAY),
(2, 2, 2, 60, NOW() - INTERVAL 1 DAY);
