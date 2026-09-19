# Online Exam System — Exam-Day Operational Runbook

> **Scope:** Practical step-by-step standard operating procedure for system administrators, DevOps engineers, and AI agents operating the Online Exam System on AWS.  
> **Security Invariant:** Zero passwords or secrets are documented here. All remote checks use AWS SSM. Inbound SSH is not required.

---

## 1. Before Exam (T-60 to T-15 Minutes)

Execute these checks at least 30–60 minutes before the scheduled student examination window opens.

### Step 1: Verify EC2 Instance State & Start if Stopped
If the instance was stopped for idle cost reduction, start it via the lifecycle skill or AWS CLI:
```powershell
# Lifecycle skill execution (recommended)
.\.agents\skills\aws-instance-lifecycle\scripts\manage.ps1 -Action StartExam -Apply
```
Or directly via AWS CLI:
```powershell
Remove-Item Env:\AWS_ACCESS_KEY_ID, Env:\AWS_SECRET_ACCESS_KEY -ErrorAction SilentlyContinue
aws ec2 start-instances --instance-ids i-0acdf2220ae4afb2d --region ap-south-1
aws ec2 wait instance-status-ok --instance-ids i-0acdf2220ae4afb2d --region ap-south-1
```

### Step 2: Verify AWS Systems Manager (SSM) Connectivity
Ensure the EC2 instance is online and reachable via SSM:
```powershell
aws ssm describe-instance-information --filters "Key=InstanceIds,Values=i-0acdf2220ae4afb2d" --region ap-south-1 --output table
```
*Expected Result:* `PingStatus: Online`.

### Step 3: Verify Core Linux Services Status
Dispatch read-only service health checks via SSM Run Command:
```powershell
# Command payload:
# systemctl is-active nginx php-fpm mariadb online-exam-finalizer.timer certbot-renew.timer
```
*Expected Result:* All services return `active`.

### Step 4: Verify Authoritative DNS Resolution
Confirm that both apex and canonical www domains resolve to the active Elastic IP (`13.202.114.100`):
```powershell
Resolve-DnsName -Name "exam-portal.online" -Type A
Resolve-DnsName -Name "www.exam-portal.online" -Type CNAME
```
*Expected Result:*
- `exam-portal.online` -> `13.202.114.100`
- `www.exam-portal.online` -> `exam-portal.online`

### Step 5: Verify HTTPS & Canonical 301 Redirects
Verify that external HTTP/HTTPS routing and TLS handshakes succeed:
```powershell
# 1. Canonical HTTPS endpoint (must return HTTP 200)
curl.exe -I https://www.exam-portal.online/

# 2. Apex HTTPS redirect (must return HTTP 301 -> https://www.exam-portal.online/)
curl.exe -I https://exam-portal.online/

# 3. HTTP redirect (must return HTTP 301 -> https://www.exam-portal.online/)
curl.exe -I http://exam-portal.online/
```

### Step 6: Verify MariaDB Port Isolation & Data Integrity
Ensure MariaDB is listening strictly on localhost and verify existing row counts:
```bash
# Via SSM Run Command:
mariadb -e "SELECT 'students' AS tbl, COUNT(*) FROM online_exam_db.students UNION ALL SELECT 'exams', COUNT(*) FROM online_exam_db.exams;"
```

### Step 7: Verify Storage & Available Memory
Ensure root filesystem has at least 5 GB free and RAM has at least 400 MB available:
```bash
# Via SSM Run Command:
df -h /
free -m
```

---

## 2. During Exam (Active Examination Window)

### Critical Rules ("WHAT NOT TO DO"):
* **DO NOT deploy application code.** Never run `deploy.ps1 -Apply` while students are actively testing.
* **DO NOT reload or restart Nginx or PHP-FPM.** Reloading FastCGI worker processes terminates in-flight autosave requests.
* **DO NOT restart or alter MariaDB.**
* **DO NOT reboot or stop EC2.**
* **DO NOT modify DNS records or release the Elastic IP.**

### What to Monitor (Safe Read-Only Operations):

1. **System Load Average & Memory Pressure:**
   ```bash
   # Via SSM Run Command:
   uptime
   free -m
   ```
   *Warning Indicator:* If `used` memory exceeds 800 MB and `swap` used exceeds 300 MB, the system is under heavy paging.

2. **Active PHP-FPM Workers:**
   ```bash
   # Via SSM Run Command:
   ps aux | grep php-fpm | grep -v grep | wc -l
   ```
   *Note:* Default `pm.max_children = 50`. If process count hits 50, incoming requests queue in Nginx backlog.

3. **Database Connection Count:**
   ```bash
   # Via SSM Run Command:
   mariadb -e "SHOW STATUS LIKE 'Threads_connected';"
   ```
   *Note:* Maximum configured is 151. Normal active connections should be 5–30.

4. **Background Exam Finalizer Activity:**
   ```bash
   # Via SSM Run Command:
   systemctl status online-exam-finalizer.service --no-pager
   ```
   *Note:* Runs every 60s. Normal exit code is `0/SUCCESS`.

### Diagnosing a Perceived Outage vs. Client-Side Issue:
* If a student reports connection loss, run `curl.exe -I https://www.exam-portal.online/` from an independent network.
* If HTTP 200 returns immediately, the server is healthy and the student is experiencing local network drops.
* Remind students that their drafts are saved continuously in `draft_answers` and will not be lost if they reload after reconnecting.

---

## 3. After Exam (Post-Exam Finalization & Teardown)

Execute these steps 15–30 minutes after the scheduled exam window closes.

### Step 1: Verify Finalized Submissions & Result Counts
Confirm that all student attempts have been submitted or auto-finalized:
```bash
# Via SSM Run Command:
mariadb -e "
SELECT 
    COUNT(*) AS total_sessions,
    SUM(CASE WHEN submitted = 1 THEN 1 ELSE 0 END) AS submitted_sessions,
    SUM(CASE WHEN submitted = 0 THEN 1 ELSE 0 END) AS unsubmitted_sessions
FROM online_exam_db.exam_sessions;
"
```
*Note:* If `unsubmitted_sessions > 0`, force an immediate finalizer sweep:
```bash
# Via SSM Run Command:
/usr/bin/php /var/www/online-exam/cron/finalize_expired_exams.php --limit=100
```

### Step 2: Confirm Result Rows & Score Generation
Verify that `results` matches the submitted count:
```bash
# Via SSM Run Command:
mariadb -e "SELECT COUNT(*) AS total_results FROM online_exam_db.results;"
```

### Step 3: Perform Post-Exam Logical Database Backup
Trigger the production backup automation immediately following examination completion:
```bash
# Via SSM Run Command:
sudo /usr/local/sbin/online-exam-db-backup.sh

# Or trigger via systemd:
sudo systemctl start online-exam-backup.service

# Verify backup execution and S3 upload:
sudo journalctl -u online-exam-backup.service -n 25 --no-pager
aws s3 ls s3://online-exam-production-backups-aps1-9032915/online-exam/mariadb/ --recursive --human-readable --region ap-south-1
```
> [!NOTE]
> This command creates a consistent InnoDB snapshot, verifies archive integrity, computes a SHA256 checksum manifest, uploads both files to the private S3 bucket, and verifies the remote object via S3 `HeadObject`.

### Step 4: Optional Cost-Saving Shutdown (Post-Exam Idle)
If no further exams are scheduled for days/weeks:
```powershell
# 1. Stop EC2 while RETAINING Elastic IP (Costs ~$3.60/mo for static IP, $0 compute):
.\.agents\skills\aws-instance-lifecycle\scripts\manage.ps1 -Action StopIdle -Apply

# 2. Stop EC2 and RELEASE Elastic IP (Costs $0 compute, $0 IP, requires DNS update on next start):
.\.agents\skills\aws-instance-lifecycle\scripts\manage.ps1 -Action StopIdle -ReleasePublicIp -Apply
```

---

## 4. Production Database Restore Protocol (HUMAN AUTHORIZATION GATE)

> [!CAUTION]
> **PRODUCTION RESTORE IS DANGEROUS AND INTENTIONALLY MANUAL.**
> Automated tools and recovery test harnesses (`restore-test.sh`) are strictly prohibited from touching `online_exam_db`.
> Live production database restoration requires explicit human authorization and adherence to the following sequence:

1. **Verify Incident & Authorize**: Confirm data loss or corruption with the project lead.
2. **Halt Application Traffic**: Stop PHP-FPM to prevent ongoing student writes:
   ```bash
   sudo systemctl stop php-fpm
   ```
3. **Emergency Pre-Restore Safety Dump**: Take a snapshot of the current database state before modifying anything:
   ```bash
   sudo mariadb-dump online_exam_db > /var/backups/mariadb/emergency_before_restore_$(date +%s).sql
   ```
4. **Select Target Backup Archive**:
   ```bash
   # From local disk:
   TARGET_BACKUP="/var/backups/mariadb/online_exam_db_<YYYY-MM-DD_HH-MM-SS>.sql.gz"

   # Or download from private S3 bucket:
   aws s3 cp s3://online-exam-production-backups-aps1-9032915/online-exam/mariadb/YYYY/MM/DD/<file>.sql.gz .
   aws s3 cp s3://online-exam-production-backups-aps1-9032915/online-exam/mariadb/YYYY/MM/DD/<file>.sql.gz.sha256 .
   ```
5. **Verify Checksum**:
   ```bash
   sha256sum -c "${TARGET_BACKUP}.sha256"
   ```
6. **Import Dump into Production Database**:
   ```bash
   zcat "${TARGET_BACKUP}" | sudo mariadb online_exam_db
   ```
7. **Verify Database Integrity**:
   ```bash
   sudo mariadb online_exam_db -e "SHOW TABLES; SELECT COUNT(*) FROM results; SELECT COUNT(*) FROM exam_sessions;"
   ```
8. **Reopen Application Service**:
   ```bash
   sudo systemctl start php-fpm
   curl.exe -I https://www.exam-portal.online/
   ```

---

## 5. Concurrency Capacities & Operational Thresholds (Benchmarked)

> **Reference:** Complete benchmark data is documented in [.agents/handoff/load-test-results.md](file:///C:/xampp/htdocs/online-exam-system/.agents/handoff/load-test-results.md).

* **Tested & Validated Capacity:** **60 to 80 concurrent students** taking a synchronous 30-question examination.
* **Student Ingress Protocol:** Exam administrators must advise students to log in over a **3 to 5-minute arrival window** prior to the start time. While the server can handle 80 simultaneous logins in 1.68s, staggering prevents burst CPU credit exhaustion on `t3.micro`.
* **Autosave Safety:** `student/ajax_save_answer.php` releases session locks (`session_write_close()`) immediately. Mass bursts of 80 simultaneous answers achieve 100% success with zero deadlocks and peak latency < 840 ms.
* **Timer Polling Overhead:** 30-second client polling interval across 80 students produces only ~2.6 req/sec, safely below server capacity (45+ req/sec).
* **Submission Mutual Exclusion:** Atomic row locks (`SELECT ... FOR UPDATE`) in `includes/exam_submission.php` guarantee zero duplicate scorecards during simultaneous submissions or race conditions with the background finalizer.
* **Automated Finalizer Service:** `online-exam-finalizer.timer` scans every 60 seconds; batch finalization of 20 abandoned/expired attempts takes ~250 ms with zero database degradation.

---

## 6. Emergency Incident Decision Tree

| Failure Symptom | Probable Cause | Immediate Diagnostic Command | Corrective Action |
| :--- | :--- | :--- | :--- |
| **Website Completely Unreachable (Connection Timed Out / Refused)** | EC2 stopped, Nginx crashed, or EIP disassociated | `aws ec2 describe-instances --instance-ids i-0acdf2220ae4afb2d --query "Reservations[0].Instances[0].State.Name"`<br>`curl.exe -I https://www.exam-portal.online/` | 1. If stopped, start instance via lifecycle skill.<br>2. If running, verify EIP is attached.<br>3. Check Nginx via SSM: `systemctl restart nginx`. |
| **Slow Login / "Bell Ring" Bottleneck** | Students authenticating simultaneously; bcrypt CPU queue | `uptime`<br>`ps aux \| grep php-fpm \| grep -v grep \| wc -l` | Instruct proctors to stagger student logins over 3–5 minutes. DO NOT restart PHP-FPM; requests in Nginx backlog will clear within 5–10 seconds. |
| **Autosave Red Error Notification in Student UI** | Internet disconnection, expired session, or CSRF mismatch | `tail -n 30 /var/log/nginx/error.log`<br>`ss -tulpn \| grep 3306` | 1. Advise student not to close the tab; drafts are re-attempted on input.<br>2. If local network dropped, student refreshes after reconnecting (saved drafts reload automatically). |
| **Timer Display Freezes / Out of Sync** | Client JavaScript timer paused by browser sleep or background tab throttling | `GET /student/ajax_timer.php?exam_id=X` (via browser network tab) | Client automatically resynchronizes with server timestamp every 30s. The server timer (TIMESTAMPDIFF in MariaDB) is authoritative. |
| **Student Clicks "Submit Exam" and Page Spins / Error 504** | FastCGI timeout during high concurrency burst | `mariadb -e "SELECT id, submitted FROM online_exam_db.exam_sessions WHERE student_id=X AND exam_id=Y;"` | 1. Check if session was already marked `submitted=1`.<br>2. If submitted, reassure student their answers were committed.<br>3. If still 0, background finalizer will automatically commit their saved drafts once duration expires. |
| **Accidental Browser Closure / Tab Crash** | Client-side crash or hardware issue | None required on server | Student simply re-opens browser, navigates to `https://www.exam-portal.online`, and logs back in. Exam resumes exactly where they left off with all drafts intact. |
| **Database Connection Refused (Error 2002)** | MariaDB process terminated due to OOM | `systemctl status mariadb`<br>`free -m`<br>`dmesg \| grep -i oom` | 1. Restart MariaDB via SSM: `systemctl start mariadb`.<br>2. Verify memory availability.<br>3. If swap was unmounted, re-enable swapfile: `swapon /swapfile`. |
| **EC2 Hardware Failure / Unresponsive SSM** | Underlying AWS hypervisor impairment | `aws ec2 describe-instance-status --instance-ids i-0acdf2220ae4afb2d` | If AWS status check fails, stop and start instance to migrate to healthy host hardware: `aws ec2 stop-instances ...` then `aws ec2 start-instances ...`. Elastic IP remains preserved. |


