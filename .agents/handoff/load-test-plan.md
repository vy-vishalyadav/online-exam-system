# Online Exam System — Local Pre-Exam Load Testing Plan

> **WARNING: DO NOT RUN LOAD TESTS AGAINST THE PRODUCTION ENVIRONMENT.**  
> This load test plan and all associated test scripts are designed strictly for local test environments (e.g. Localhost XAMPP, staging VM, or containerized MariaDB) using isolated, disposable synthetic datasets.

---

## 1. Objective

To validate the stability, concurrency handling, database locking behavior, and resource utilization of the **Online Exam System** under a simulated workload of **60 to 80 concurrent students** taking a single synchronized examination.

---

## 2. Target Concurrency Profile

* **Concurrent Active Students:** 60 – 80 virtual users.
* **Concurrent Exams:** 1 active exam.
* **Exam Composition:** 30 Questions (25 MCQs + 5 Short Descriptive questions).
* **Workload Phasing:**
  - **Phase 1: Staggered Ingress (Minutes 0 – 5):** 60–80 students authenticate via `login.php` over a 5-minute ramp-up window (~0.25 logins/second).
  - **Phase 2: Synchronous Exam Launch (Minute 5):** 60–80 students load `student/exam.php` within a 30-second window.
  - **Phase 3: Active Examination & Autosave (Minutes 5 – 20):** Students answer questions at realistic intervals (~1 click every 20–40 seconds), generating ~1.5 to 3 autosave requests/sec (`student/ajax_save_answer.php`). Background timer reconciliation polls every 30 seconds (~2.5 requests/sec). Random anti-cheat events (~5–10 violation logs per minute).
  - **Phase 4: Clustered Submission & Finalization (Minutes 20 – 25):** 50% of students submit manually within the final 2 minutes; remaining 50% let the timer expire, triggering automated server-side finalization (`cron/finalize_expired_exams.php`).

---

## 3. Test Scenarios

1. **Scenario A: Normal Staggered Exam Flow (Baseline)**  
   80 students log in over 5 minutes, answer questions smoothly, and submit manually with 30-second intervals between completions.
2. **Scenario B: "Bell Ring" Ingress Surge**  
   60 students submit login credentials in the exact same 15-second window to test bcrypt CPU saturation and PHP-FPM queueing.
3. **Scenario C: Mass Autosave Burst**  
   Simultaneous option clicks by 80 students within a 3-second burst to observe InnoDB row-level locking on `draft_answers`.
4. **Scenario D: Race Condition Stress Test**  
   Simultaneous trigger of manual student submit (`POST result.php`) and automated server-side finalizer (`cron/finalize_expired_exams.php`) for the same student attempt to verify `SELECT ... FOR UPDATE` mutual exclusion.
5. **Scenario E: Abandoned Attempt Recovery**  
   20 simulated students abruptly disconnect at Minute 10. Verify that `online-exam-finalizer.timer` accurately scans, grades, and commits their saved drafts once their duration expires.

---

## 4. Test Data Model & Isolation Safeguards

All test data must use an isolated synthetic class ID and email domain to guarantee zero collision with real academic records:
* **Academic Class:** `Class: "LOAD_TEST_CLASS_2026"`
* **Synthetic Student ID Range:** `8001` through `8080`
* **Email Pattern:** `synth_student_XXXX@loadtest.local`
* **Synthetic Exam:** `Title: "Synthetic Performance Benchmark 80U"` (Duration: 15 minutes)

---

## 5. Environment Requirements

* **Local Test Web Server:** Apache / Nginx on Localhost (XAMPP or local container).
* **PHP Runtime:** PHP 8.0+ (PHP-FPM or Apache mod_php).
* **Database:** MariaDB / MySQL 10.5+ running on `127.0.0.1:3306`.
* **Client Test Driver:** Node.js (k6 / autocannon), Python (Locust), or PowerShell multi-threaded worker script.
* **Safety Assertion:** All test scripts must assert that the target hostname is strictly `localhost` or `127.0.0.1`. Requests targeting `exam-portal.online` or any external IP must immediately abort with a fatal error.

---

## 6. Local Setup & Synthetic Data Generation

### Step 1: Create Isolated Test Database
```sql
-- Run in local MariaDB/MySQL (NOT production)
CREATE DATABASE IF NOT EXISTS `online_exam_loadtest` DEFAULT CHARACTER SET utf8mb4;
USE `online_exam_loadtest`;
SOURCE sql/setup.sql;
```

### Step 2: Seed 80 Synthetic Students & Benchmark Exam
```sql
-- Insert Synthetic Class
INSERT INTO `classes` (`id`, `name`, `description`) 
VALUES (999, 'LOAD_TEST_CLASS', 'Synthetic Load Testing Class')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Insert Benchmark Exam
INSERT INTO `exams` (`id`, `title`, `duration_minutes`, `result_mode`, `questions_to_display`)
VALUES (999, 'Benchmark Exam 80 Users', 15, 'instant', 30)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT INTO `exam_class_assignments` (`exam_id`, `class_id`) VALUES (999, 999)
ON DUPLICATE KEY UPDATE `class_id` = VALUES(`class_id`);
```

*(See `scratch/load-test/seed_test_data.php` for the complete automated seeder).*

---

## 7. Performance & Health Metrics Matrix

| Category | Metric | Acceptable Target | Warning Threshold | Critical Failure |
| :--- | :--- | :---: | :---: | :---: |
| **Latency** | Autosave (`ajax_save_answer`) p95 | < 150 ms | > 500 ms | > 1500 ms |
| **Latency** | Timer Poll (`ajax_timer`) p95 | < 50 ms | > 200 ms | > 800 ms |
| **Latency** | Submission (`result.php`) p95 | < 400 ms | > 1200 ms | > 3000 ms |
| **Error Rate** | HTTP 500 / 502 / 504 | 0.0% | > 0.5% | > 2.0% |
| **Integrity** | Duplicate Result Rows | **0** | **0** | $\ge 1$ (Integrity Bug) |
| **Integrity** | Lost Answers on Disconnect | **0** | **0** | $\ge 1$ |
| **Integrity** | Deadlocks (`1213 Deadlock found`) | **0** | $\ge 1$ | $> 5$ |
| **Memory** | Peak PHP Memory per Worker | < 25 MB | > 40 MB | > 80 MB |
| **CPU** | Sustained CPU on 2 vCPU | < 65% | > 85% | 100% (Throttling) |
| **Database** | Active DB Connections | < 30 | > 45 | $\ge 50$ (Pool Exhaustion) |

---

## 8. Observation Points & Diagnostic Commands

During local load testing, monitor these internal system components:

1. **PHP-FPM Worker Pool Status:**
   ```bash
   # Check active vs idle PHP-FPM children
   watch -n 1 'ps aux | grep php-fpm | grep -v grep | wc -l'
   ```
2. **MariaDB Connection & Lock Monitor:**
   ```sql
   SHOW STATUS LIKE 'Threads_connected';
   SHOW STATUS LIKE 'Threads_running';
   SHOW STATUS LIKE 'Innodb_row_lock_waits';
   SHOW STATUS LIKE 'Innodb_row_lock_time_avg';
   SHOW ENGINE INNODB STATUS\G
   ```
3. **Database Data Consistency Verification:**
   ```sql
   -- Verify exact result count matches submitted sessions
   SELECT 
       (SELECT COUNT(*) FROM results WHERE exam_id = 999) AS total_results,
       (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = 999 AND submitted = 1) AS total_submitted_sessions,
       (SELECT COUNT(DISTINCT student_id) FROM results WHERE exam_id = 999) AS unique_students_evaluated;
   ```

---

## 9. Teardown & Cleanup Procedure

After executing local load tests, purge the synthetic records:
```sql
USE `online_exam_loadtest`;
DELETE FROM `exam_violations` WHERE `exam_id` = 999;
DELETE FROM `draft_answers` WHERE `exam_id` = 999;
DELETE FROM `student_answers` WHERE `exam_id` = 999;
DELETE FROM `results` WHERE `exam_id` = 999;
DELETE FROM `exam_sessions` WHERE `exam_id` = 999;
DELETE FROM `questions` WHERE `exam_id` = 999;
DELETE FROM `exam_class_assignments` WHERE `exam_id` = 999;
DELETE FROM `exams` WHERE `id` = 999;
DELETE FROM `students` WHERE `class_id` = 999;
DELETE FROM `classes` WHERE `id` = 999;
```
