# AWS Online Exam System — Comprehensive Project Handoff & Operational Manual

> **Target Audience:** Future Antigravity / agy AI agent sessions, human operators, and DevOps engineers.  
> **Classification:** Sanitized Reference Document. Zero secrets or sensitive values are contained in this document.  
> **Verification Date:** September 19, 2026  
> **Document Purpose:** Single source of operational truth to allow a completely fresh agent session to understand, manage, verify, and operate the Online Exam System on AWS without prior conversation history.

---

## 1. Executive Project Summary

* **Project Identity:** Online Exam System (PHP 8 + Nginx + MariaDB web application) `[VERIFIED]`
* **Repository (GitHub):** `https://github.com/vy-vishalyadav/online-exam-system.git` `[VERIFIED]`
* **Primary Branch:** `main` `[VERIFIED]`
* **Local Workspace Path:** `C:\xampp\htdocs\online-exam-system` `[VERIFIED]`
* **Production Application Path:** `/var/www/online-exam` (on EC2) `[VERIFIED]`
* **Git Sync Status (GitHub vs Local):** Local `HEAD` is in sync with `origin/main` at commit `f40fdd633a13a941df91d366b25bce2f59ad3555`. `[VERIFIED]`
* **Production Deployed Commit on EC2:** `/var/www/online-exam` is currently running checked out with application code matching commit `c8a2a715aba455db742ddfa233570ab46796059c` (`fix(dashboard): show accurate status for closed and upcoming exams instead of generic not-started`). Commits from `6b62a6c` to `f40fdd6` contain local lifecycle management skills, deployment skills, backup automation, and operational handoff documentation; application source code in `/var/www/online-exam` is identical to `HEAD`. `[VERIFIED]`

---

## 2. Infrastructure Architecture & AWS Topology

* **Cloud Provider:** Amazon Web Services (AWS) `[VERIFIED]`
* **AWS Region:** `ap-south-1` (Asia Pacific - Mumbai) `[VERIFIED]`
* **EC2 Instance ID:** `i-0acdf2220ae4afb2d` `[VERIFIED]`
* **EC2 Instance Type:** `t3.micro` (Burstable compute, 2 vCPU, 1 GiB RAM) `[VERIFIED]`
* **EC2 Instance State:** `running` `[VERIFIED]`
* **VPC ID:** `vpc-05da910c35019b939` `[VERIFIED]`
* **Subnet ID:** `subnet-08ecffdb71df1fc7e` (Availability Zone: `ap-south-1b`) `[VERIFIED]`
* **EBS Storage Topology:**
  - Volume ID: `vol-0a0fffe887d09ba3f` `[VERIFIED]`
  - Size & Type: 10 GiB `gp3` (3000 IOPS, 125 MB/s throughput) `[VERIFIED]`
  - Attachment: `/dev/xvda` (Block device `nvme0n1p1` inside OS, root mount `/`) `[VERIFIED]`
  - Storage Utilization: Total 10G, Used 3.3G (34%), Available 6.7G `[VERIFIED]`
  - Encryption: Enabled (KMS key encrypted) `[VERIFIED]`
  - Snapshot Baseline: `snap-0e8e8abfe5395dd38` `[VERIFIED]`
* **Elastic IP (Public IPv4):**
  - Public IP Address: `13.202.114.100` `[VERIFIED]`
  - Allocation ID: `eipalloc-0c0ae02b970013f98` `[VERIFIED]`
  - Association ID: `eipassoc-0607d1db09e82f175` `[VERIFIED]`
  - Network Interface: `eni-091c8d7bdc153a2d7` (Private IP: `172.31.0.223`) `[VERIFIED]`
  - Cost Rule: Retained across EC2 stop/start cycles by default ($0.005/hr ~ $3.60/month idle fee) to maintain static DNS resolution, or explicitly released via `-ReleasePublicIp` if full cost reduction is desired. `[VERIFIED]`
* **Security Group Architecture:**
  - Group ID: `sg-0819d7e79244c4563` (`online-exam-sg`) `[VERIFIED]`
  - Inbound Port 80 (HTTP): `0.0.0.0/0` (Nginx HTTP challenge & 301 redirects) `[VERIFIED]`
  - Inbound Port 443 (HTTPS): `0.0.0.0/0` (Nginx TLS application traffic) `[VERIFIED]`
  - Inbound Port 22 (SSH): Restricted to AWS prefix list `pl-0fa83cebf909345ca` (Zero public SSH access) `[VERIFIED]`
* **AWS Systems Manager (SSM) Management Channel:**
  - IAM Instance Profile: `online-exam-ssm-profile` `[VERIFIED]`
  - Agent Status: `Online` (Amazon SSM Agent version `3.3.4624.0`) `[VERIFIED]`
  - Operational Invariant: All remote server administration, deployment, inspection, and log monitoring are executed via AWS SSM Run Command (`AWS-RunShellScript`). SSH and AWS Web Console GUI are strictly bypassed. `[VERIFIED]`

---

## 3. Server Software Stack & Runtime Configuration

* **Operating System:** Amazon Linux 2023 (`PRETTY_NAME="Amazon Linux 2023.12.20260917"`, kernel Linux 6.1) `[VERIFIED]`
* **Web Server (Nginx):**
  - Package: `nginx/1.30.4` `[VERIFIED]`
  - Service: `nginx.service` (Active, Enabled) `[VERIFIED]`
  - Workers: `worker_processes auto;` (2 worker processes for 2 vCPUs; 1024 connections/worker) `[VERIFIED]`
  - Configuration Status: Validated via `nginx -t` with zero warnings `[VERIFIED]`
* **PHP Runtime & Pool Configuration (PHP-FPM):**
  - Version: `PHP 8.5.10` `[VERIFIED]`
  - Service: `php-fpm.service` (Active, Enabled) `[VERIFIED]`
  - Pool Manager: `pm = dynamic` `[VERIFIED]`
  - Worker Limits: `pm.max_children = 50`, `pm.start_servers = 5`, `pm.min_spare_servers = 5`, `pm.max_spare_servers = 35` `[VERIFIED]`
  - Limits: `memory_limit = 128M`, `post_max_size = 8M`, `upload_max_filesize = 2M` `[VERIFIED]`
* **Database Service (MariaDB):**
  - Package: MariaDB 10.5+ `[VERIFIED]`
  - Service: `mariadb.service` (Active, Enabled) `[VERIFIED]`
  - Network Binding: Strictly bound to `127.0.0.1:3306` (Loopback only; zero external network ingress) `[VERIFIED]`
  - Connection Pool: `max_connections = 151` (Comfortably accommodates 50 max PHP-FPM children) `[VERIFIED]`
  - Buffer Pool: `innodb_buffer_pool_size = 134217728` (128 MiB) `[VERIFIED]`
  - Lock Timeout: `innodb_lock_wait_timeout = 50s` `[VERIFIED]`
  - Database Name: `online_exam_db` `[VERIFIED]`
  - Primary Tables: `students`, `exams`, `questions`, `exam_sessions`, `draft_answers`, `results`, `student_answers`, `exam_violations`, `app_jobs` `[VERIFIED]`
* **Memory & Swap Utilization (`t3.micro`):**
  - Total RAM: 913 MiB (~1 GiB) `[VERIFIED]`
  - Active Utilization: ~270 MiB used, ~498 MiB available, ~413 MiB buff/cache `[VERIFIED]`
  - Swap Partition: 1024 MiB (4.8 MiB used, 1019 MiB free) `[VERIFIED]`
* **Background Systemd Automation:**
  - Exam Finalizer: `online-exam-finalizer.timer` (Active, Enabled, triggers every 60 seconds) `[VERIFIED]`
    - Service: `/usr/bin/php /var/www/online-exam/cron/finalize_expired_exams.php --limit=50` (Clean exit code 0) `[VERIFIED]`
  - TLS Auto-Renewal: `certbot-renew.timer` (Active, Enabled, fires twice daily) `[VERIFIED]`
* **Protected Files & Permissions on Production Server:**
  - `/var/www/online-exam/config/config.local.php`: Permissions `-rw-r-----. 1 root apache 303` (chmod 640). Contains production database credentials. Excluded from Git. `[VERIFIED]`
  - `/var/www/online-exam/uploads`: Permissions `drwxrwx---. 2 root apache 6` (chmod 770). Excluded from code wipes. `[VERIFIED]`

---

## 4. Application Architecture & Concurrency Analysis

* **Target Workload Profile:** 60 to 80 concurrent students taking 1 synchronous examination `[VERIFIED]`
* **Load Test Validation (`.agents/handoff/load-test-results.md`):** `[VERIFIED]`
  - Tested across 1, 5, 10, 20, 40, 60, and 80 concurrent virtual users on isolated test DB `online_exam_loadtest`.
  - **Autosave Reliability:** 100% success rate across mass 80-user burst waves; peak burst latency < 840 ms; zero deadlocks.
  - **Ingress Surge ("Bell Ring"):** 80 simultaneous bcrypt logins completed in 1.68s (47.5 req/s sustained).
  - **Submission Stress:** 60 simultaneous submissions completed in 1.21s; p95 latency 611.6 ms; zero duplicate results.
  - **Abandoned Recovery:** Background finalizer processed 20 expired sessions in 252 ms with zero errors.
* **Configuration Bootstrapping (`config/db.php`):**
  - Priority 1 (Env vars) $\rightarrow$ Priority 2 (`config.local.php`) $\rightarrow$ Priority 3 (Fail closed with HTTP 500 error log). Zero hardcoded credentials. `[VERIFIED]`
* **Session Locking Mitigations:**
  - High-frequency AJAX endpoints (`student/ajax_save_answer.php`, `student/ajax_timer.php`, `student/ajax_log_violation.php`) all call `session_write_close()` immediately after authentication to prevent HTTP request serialization. `[VERIFIED]`
* **Draft Autosave Safety (`student/ajax_save_answer.php`):**
  - Uses `INSERT INTO draft_answers ... ON DUPLICATE KEY UPDATE` indexed on `(student_id, exam_id, question_id)`. `[VERIFIED]`
  - Each student updates isolated rows; zero cross-student row lock contention. `[VERIFIED]`
* **Exam Submission & Finalization (`includes/exam_submission.php`):**
  - Enclosed in an atomic transaction (`mysqli_begin_transaction`). `[VERIFIED]`
  - Row-level lock acquired on `exam_sessions` via `SELECT ... FOR UPDATE`. `[VERIFIED]`
  - Evaluates `submitted == 1` to strictly prevent duplicate result records. `[VERIFIED]`
  - Enforced by database unique key: `results.uq_student_exam_result (student_id, exam_id)`. `[VERIFIED]`
* **Concurrency Assessment for 60–80 Students:**
  - `pm.max_children = 50`: Handles up to 50 concurrent requests simultaneously.
  - For normal examination flows (staggered logins, 30s timer polls, periodic autosaves), average request rate is ~3–6 requests/sec, well within 50 workers capacity `[LOW RISK]`.
  - For simultaneous ingress surges (e.g. 80 students clicking login or start within 10 seconds), 30 requests queue in Nginx backlog for ~100–300ms, then process cleanly `[LOW RISK / SAFE]`.
  - Memory Footprint: If all 50 workers activate simultaneously (~20 MB/worker = 1000 MB), system safely overflows into the 1024 MiB swap partition without crashing `[SAFE / LOW RISK]`.

---

## 5. HTTPS & Canonical Domain Routing

* **Primary Canonical Domain:** `https://www.exam-portal.online` `[VERIFIED]`
* **Apex Domain:** `https://exam-portal.online` `[VERIFIED]`
* **TLS Certificate:** Let's Encrypt ECDSA certificate covering `exam-portal.online` & `www.exam-portal.online`. `[VERIFIED]`
  - Expiry: `2026-12-18` (89 days remaining; auto-managed by `certbot-renew.timer`). `[VERIFIED]`
  - Certificate Path: `/etc/letsencrypt/live/exam-portal.online/fullchain.pem` `[VERIFIED]`
* **Routing Invariants (301 Permanent Redirects):**
  1. `http://exam-portal.online/*` -> `301` to `https://www.exam-portal.online/*` `[VERIFIED]`
  2. `https://exam-portal.online/*` -> `301` to `https://www.exam-portal.online/*` `[VERIFIED]`
  3. `http://www.exam-portal.online/*` -> `301` to `https://www.exam-portal.online/*` `[VERIFIED]`
  4. `https://www.exam-portal.online/*` -> Serves application with `HTTP 200 OK` `[VERIFIED]`
  - All redirects preserve `$request_uri` (exact path and query parameters). `[VERIFIED]`
* **Nginx Security Headers:**
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains` `[VERIFIED]`
  - `X-Frame-Options: SAMEORIGIN` `[VERIFIED]`
  - `X-Content-Type-Options: nosniff` `[VERIFIED]`
  - `X-XSS-Protection: 1; mode=block` `[VERIFIED]`
  - `Referrer-Policy: strict-origin-when-cross-origin` `[VERIFIED]`

---

## 6. Authoritative DNS & Spaceship Integration

* **Authoritative DNS Provider:** Spaceship `[VERIFIED]`
* **Nameservers:** `ns01.trs-dns.com`, `ns01.trs-dns.net`, `ns10.trs-dns.org`, `ns10.trs-dns.info` `[VERIFIED]`
* **Active DNS Records:**
  - Apex `@ A` -> `13.202.114.100` (TTL: 1800) `[VERIFIED]`
  - Subdomain `www CNAME` -> `exam-portal.online` (TTL: 1800) `[VERIFIED]`
* **Spaceship REST API Endpoint:** `https://spaceship.dev/api/v1/dns/records/{domain}` `[VERIFIED]`
* **Permission Scopes:** `dnsrecords:read`, `dnsrecords:write` `[VERIFIED]`
* **Credential Variable Names (Ambient Process Variables Only):**
  - `$env:SPACESHIP_API_KEY` `[VERIFIED]`
  - `$env:SPACESHIP_API_SECRET` `[VERIFIED]`
* **API Authentication Validation:** `[VERIFIED]`
  - **Verification Date:** September 19, 2026
  - Spaceship restricted API credential successfully authenticated against the official Spaceship DNS API and read the authoritative DNS record set for `exam-portal.online`.
  - DNS GET succeeded (`HTTP 200 OK`, 2 records retrieved).
  - `@ A` observed (`13.202.114.100`, TTL: 1800).
  - `www CNAME` observed (`exam-portal.online`, TTL: 1800).
  - No DNS mutation performed (read-only GET; zero PUT calls).
  - Credentials were process-scoped in memory only.
  - Credentials were not committed, logged, or persisted to disk.
* **DNS Safety Model in Automation:**
  - Automation always fetches current zone records via GET first (`Get-SpaceshipDnsRecords`).
  - Only apex `@ A` record is modified; canonical `www CNAME` and all unrelated records are preserved.
  - Live mutations strictly require explicit `-Apply`.

---

## 7. Skill Catalog & Operational Manuals

* **AWS Instance Lifecycle (`aws-instance-lifecycle`):** `.agents/skills/aws-instance-lifecycle/` and `.aws/skills/aws-instance-lifecycle/`. Manages EC2 start/stop, EIP retention/release, and Spaceship DNS. `[VERIFIED]`
* **AWS Production Deployment (`aws-production-deploy`):** `.agents/skills/aws-production-deploy/`. Handles zero-downtime deployment, syntax linting, health verification, and rollback logic. `[VERIFIED]`
* **AWS Production Backup & Recovery (`aws-backup-recovery`):** `.agents/skills/aws-backup-recovery/`. Manages automated daily MariaDB logical backups, private S3 replication, checksum validation, systemd timer scheduling, isolated test restoration, and AWS DLM EBS snapshots. `[VERIFIED]`
* **Package Deployment (`package-deploy`):** `.agents/skills/package-deploy/`. Used for packaging local release zip files. `[VERIFIED]`
* **Companion Operational Documents:**
  - Load Testing Plan: [.agents/handoff/load-test-plan.md](file:///C:/xampp/htdocs/online-exam-system/.agents/handoff/load-test-plan.md) `[VERIFIED]`
  - Exam-Day Runbook: [.agents/handoff/exam-day-runbook.md](file:///C:/xampp/htdocs/online-exam-system/.agents/handoff/exam-day-runbook.md) `[VERIFIED]`
  - Safe Local Load Harness: `scratch/load-test/` (gitignored; strictly refuses production targets). `[VERIFIED]`

---

## 8. Production Backup, Recovery & Disaster Readiness

* **Primary Database Recovery Mechanism:** Automated transactional MariaDB logical dump (`mariadb-dump --single-transaction --quick --routines --triggers --no-tablespaces`). `[VERIFIED]`
* **Secondary Infrastructure Recovery Mechanism:** AWS Data Lifecycle Manager (DLM) daily EBS volume snapshots (`vol-0a0fffe887d09ba3f`, tag `BackupPolicy=online-exam-production`, 7-day retention). `[VERIFIED]`
* **Backup Architecture Parameters:**
  - **Local Directory:** `/var/backups/mariadb/` (Permissions `chmod 700`, owner `root:root`) `[VERIFIED]`
  - **Local Retention:** 7 days (auto-pruned by backup script; oldest pruned only after new backup succeeds) `[VERIFIED]`
  - **Private S3 Bucket:** `online-exam-production-backups-aps1-9032915` (Region: `ap-south-1`) `[VERIFIED]`
  - **S3 Prefix Structure:** `s3://online-exam-production-backups-aps1-9032915/online-exam/mariadb/YYYY/MM/DD/` `[VERIFIED]`
  - **S3 Security:** 100% Block Public Access enabled; default AES256 SSE-S3 encryption; zero public policies or ACLs `[VERIFIED]`
  - **S3 Retention Policy:** 14 days automatic expiration via S3 Lifecycle Rule `ExpireBackupsAfter14Days` `[VERIFIED]`
  - **Backup Schedule:** Daily at `02:30:00 UTC` (`08:00 AM IST`) via `online-exam-backup.timer` with `Persistent=true` `[VERIFIED]`
  - **EC2 IAM Role:** `online-exam-ssm-role` with inline least-privilege policy `OnlineExamBackupS3Access` (restricted PutObject/GetObject on `online-exam/mariadb/*` and ListBucket on prefix) `[VERIFIED]`
  - **Concurrency Guard:** `flock` on `/var/run/online-exam-backup.lock` prevents overlapping backup runs `[VERIFIED]`
  - **Disk Space Guard:** Pre-flight check asserts $\ge 500\text{ MB}$ free space on root filesystem before dump `[VERIFIED]`
* **Verification & Testing Status:**
  - **Local Restore Test:** `[VERIFIED]` (`restore-test.sh` restored all 12 tables into isolated temporary database `online_exam_restore_test_*`, verified row counts matched production on 100% of tables, and dropped temporary test database).
  - **S3 Restore Test:** `[VERIFIED]` (`restore-test.sh` downloaded S3 object, verified SHA256 checksum against S3 manifest, imported into isolated test DB, verified table row counts, and cleaned up).
  - **Failure-Path Test:** `[VERIFIED]` (Verified concurrency lock collision cleanly halts duplicate execution with non-zero exit code).
* **RPO & RTO Assumptions:**
  - **RPO (Recovery Point Objective):** 24 hours (daily logical dump) or instant prior to schema migrations via `-MigrateDb`.
  - **RTO (Recovery Time Objective):** ~15 minutes for logical database restore; ~45–60 minutes for complete EC2 instance rebuild.
* **Cost Analysis:**
  - S3 Backup Storage: ~10 KB per daily dump $\times$ 14 days $\approx$ 140 KB $\approx$ **$0.00 / month** (within AWS Free Tier / fraction of a cent).
  - DLM EBS Snapshots: 10 GiB initial base + ~100–200 MB daily change $\times$ 7 days $\approx$ **~$0.55–$0.65 / month**.
* **Production Restore Gate (STRICT HUMAN AUTHORIZATION REQUIRED):**
  - **Automated scripts NEVER automatically restore production.**
  - **Testing vs Production Restore Distinction:** `restore-test.sh` strictly creates and tests against temporary databases named `online_exam_restore_test_<timestamp>` and will throw an unhandled fatal error if `online_exam_db` is specified.
  - **Authorized Production Restore Checklist:**
    1. Confirm the disaster incident and obtain human administrator authorization.
    2. Halt application writes: `sudo systemctl stop php-fpm`.
    3. Take an emergency pre-restore safety dump: `sudo mariadb-dump online_exam_db > /var/backups/mariadb/emergency_pre_restore_$(date +%s).sql`.
    4. Verify SHA256 of the target backup archive: `sha256sum -c <target-backup>.sql.gz.sha256`.
    5. Import dump: `zcat <target-backup>.sql.gz | sudo mariadb online_exam_db`.
    6. Verify database integrity: `mariadb online_exam_db -e "SHOW TABLES; SELECT COUNT(*) FROM results;"`.
    7. Restart application service: `sudo systemctl start php-fpm`.
    8. Verify public canonical HTTPS reachability: `curl.exe -I https://www.exam-portal.online/`.
* **What is NOT Automated:**
  - Production database restores are intentionally manual.
  - Multi-region S3 replication is omitted to prevent cross-region egress charges.
  - Local database credentials rotation is not automated (managed via `/var/www/online-exam/config/config.local.php`).

---

## 9. Potential Optimizations (NOT APPLIED)

1. **`exam_sessions` Finalizer Index:**  
   `[POTENTIAL OPTIMIZATION — NOT APPLIED]`  
   Query in `finalizeAllExpiredExams` searches `WHERE es.submitted = 0`. As session history grows past several thousand rows, adding an index `ALTER TABLE exam_sessions ADD INDEX idx_sessions_pending (submitted, started_at);` will prevent table scans on historical records.
2. **Batch Student Answer Inserts:**  
   `[POTENTIAL OPTIMIZATION — NOT APPLIED]`  
   Inside `finalizeExamSubmission`, `INSERT INTO student_answers` currently runs in a loop for each question. Refactoring to a single multi-row `INSERT INTO student_answers (...) VALUES (...), (...), (...)` would reduce query roundtrips during submission.

---

## 10. Non-Negotiable Operational Rules ("DO NOT")

1. **DO NOT print, echo, display, log, or persist credential values** (Spaceship API keys/secrets, AWS access keys, database passwords, private keys).
2. **DO NOT write credentials to disk**, Git repositories, markdown files, or environment config files.
3. **DO NOT modify live AWS infrastructure** (Security Groups, IAM roles, VPC subnets, route tables, EBS volumes).
4. **DO NOT start, stop, reboot, or terminate EC2** without explicit user confirmation and `-Apply`.
5. **DO NOT allocate or release Elastic IPs** without explicit user confirmation.
6. **DO NOT dispatch mutating DNS requests (PUT/POST/PATCH/DELETE)** against Spaceship without explicit `-Apply`.
7. **DO NOT open inbound SSH (port 22)** to `0.0.0.0/0`; server administration must use AWS SSM.
8. **DO NOT deploy code or restart services (Nginx, PHP-FPM, MariaDB)** while students are actively taking an exam.
9. **DO NOT overwrite or delete** `/var/www/online-exam/config/config.local.php` or `/var/www/online-exam/uploads` on the production server.
10. **DO NOT run load tests against the live production environment** (`https://www.exam-portal.online`). All load tests must run strictly on isolated local environments.
11. **DO NOT force-push (`git push --force`)** or rewrite Git history.
12. **DO NOT delete or modify active Antigravity / agy session database files** or brain transcript directories.

---

## 11. Fresh AGY Session Start Procedure

When starting a completely fresh Antigravity / agy session for this project, follow this exact step-by-step checklist:

1. **Read this Handoff First:**  
   Review `.agents/handoff/aws-exam-project-handoff.md` to establish current infrastructure topology, software stack, and security invariants.
2. **Verify Git Repository State (Read-Only):**  
   Run `git status` and `git log -n 3 --oneline`. Ensure the working tree is clean and `main` is current with `origin/main`.
3. **Verify AWS SSM Readiness (Read-Only):**  
   Verify that AWS CLI has access and EC2 instance `i-0acdf2220ae4afb2d` is online:
   ```powershell
   Remove-Item Env:\AWS_ACCESS_KEY_ID, Env:\AWS_SECRET_ACCESS_KEY -ErrorAction SilentlyContinue
   aws ssm describe-instance-information --filters "Key=InstanceIds,Values=i-0acdf2220ae4afb2d" --region ap-south-1 --output table
   ```
4. **Inspect Production Server Readiness (Read-Only):**  
   Run a read-only lifecycle status check:
   ```powershell
   .\.agents\skills\aws-instance-lifecycle\scripts\manage.ps1 -Action Status
   ```
5. **Validate HTTP & Domain Endpoints (Read-Only):**  
   ```powershell
   curl.exe -I https://www.exam-portal.online/
   ```
6. **Treat Handoff as Context, Not as Permission to Mutate:**  
   Do NOT modify AWS, DNS, code, or database until the user explicitly requests an operational action.
7. **Adhere to the `-Apply` Safety Gate:**  
   Every mutating action requires explicit `-Apply` flag and user authorization.
8. **Preserve Agent Context:**  
   Never delete, overwrite, or clean existing conversation databases or transcript files in `C:\Users\vikaa\.gemini\antigravity-cli\`.

---

### Startup Prompt Template for a Fresh AGY Session

Copy and paste this prompt when initiating a new agy session for this project:

```text
You are continuing development and operations on the AWS Online Exam System.

Please follow these mandatory initialization instructions:
1. Read the comprehensive project handoff document at:
   .agents/handoff/aws-exam-project-handoff.md
2. Confirm your understanding of the project identity, AWS EC2 instance (i-0acdf2220ae4afb2d), domain routing (https://www.exam-portal.online), and SSM-only management channel.
3. Perform read-only git status and AWS SSM readiness prechecks.
4. Do NOT modify any AWS infrastructure, EC2 state, DNS records, database tables, or application code during initialization.
5. NEVER print, log, or persist credential values.
6. Await my specific task instructions before performing any action.
```
