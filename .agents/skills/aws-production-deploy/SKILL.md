---
name: aws-production-deploy
description: >-
  Automates safe, verified, and zero-downtime production deployment of the Online Exam System
  to AWS EC2 (i-0acdf2220ae4afb2d in ap-south-1) using AWS Systems Manager (SSM) Run Command.
  Features strict dry-run validation by default, production remote URL verification,
  clean worktree enforcement, atomic server-side deployment locking, protected file isolation,
  logical database backup safeguards, and health-check failure automatic rollback protocols.
---

# AWS Production Deployment Skill

This skill provides a secure, audited, zero-downtime deployment workflow for the **Online Exam System** on AWS EC2.

---

## 1. Production Target Profile

* **Cloud Provider:** Amazon Web Services (AWS)
* **Region:** `ap-south-1` (Mumbai)
* **EC2 Instance:** `i-0acdf2220ae4afb2d`
* **Elastic IP:** `13.202.114.100`
* **Application Path:** `/var/www/online-exam`
* **Git Repository:** `https://github.com/vy-vishalyadav/online-exam-system.git`
* **Database:** `online_exam_db` (MariaDB, bound exclusively to `127.0.0.1:3306`)
* **Management Channel:** **AWS Systems Manager (SSM)** (`AWS-RunShellScript` / Run Command)
* **Runtime Deployment Lock:** `/tmp/online-exam-deploy.lock`

---

## 2. Inviolable Safety Rules

The deployment automation enforces these strict non-negotiable boundaries:
1. **DRY-RUN by Default:** Running the deployment script without `-Apply` executes only read-only pre-checks, remote verification, and diff inspections.
2. **Zero Inbound SSH Dependency:** All administration uses AWS Systems Manager. Port 22 remains closed to public traffic and is never modified.
3. **Production Remote Verification:** Asserts that the remote EC2 repository origin matches `https://github.com/vy-vishalyadav/online-exam-system.git` exactly before pulling.
4. **Production Worktree Safety:** Requires the production working tree to be completely clean (`git status --porcelain`). If unexpected changes exist, deployment aborts. Production changes are **never** automatically deleted, stashed, reset, or overwritten.
5. **Atomic Server-Side Lock:** A lock at `/tmp/online-exam-deploy.lock` guarantees mutual exclusion for all deployments and manual rollbacks. If another operation holds the lock, the competing process exits immediately. **A lock collision NEVER triggers an automatic rollback**; the active operation is left completely undisturbed.
6. **Protected Files & Data:**
   * Server-side `config/config.local.php` is **never** printed, modified, or overwritten.
   * `uploads/` directory is **never** deleted or overwritten.
   * MariaDB database is **never** dropped or reset automatically (`DROP DATABASE` is prohibited).
7. **Database Migration Safety & No Auto-SQL:** Because the project does not use an automated migration framework, SQL files are **never** executed automatically. When changes under `sql/` exist, passing `-MigrateDb` creates a pre-deployment logical backup (`mariadb-dump`) and prohibits automatic code rollback. Reviewed SQL must be applied manually via SSM.
8. **No ZIP Uploads:** Deployments pull directly from the verified GitHub repository commit via Git over SSM.
9. **Infrastructure Immutability:** Security Groups, IAM roles, and EC2 instance lifecycle states (reboot/stop/terminate) are never touched.

---

## 3. Deployment Workflow Architecture

The deployment pipeline is strictly structured into five consecutive gates:

```
[STAGE 1: LOCAL PRE-CHECKS]
       │  • Correct repo remote (vy-vishalyadav/online-exam-system.git)
       │  • Branch: main
       │  • Clean local worktree (enforced on -Apply)
       │  • Local HEAD == origin/main (all commits pushed to GitHub)
       │  • Local PHP syntax lint (php -l across all .php files)
       ▼
[STAGE 2: PRODUCTION PRE-AUDIT VIA SSM]
       │  • EC2 state == running
       │  • AWS SSM status == Online
       │  • Production remote URL verified (https://github.com/vy-vishalyadav/online-exam-system.git)
       │  • Production worktree verified clean (git status --porcelain)
       │  • Server config.local.php exists
       │  • MariaDB bound strictly to localhost (127.0.0.1:3306)
       │  • Services active (nginx, php-fpm, mariadb, online-exam-finalizer.timer)
       │  • Record current production commit SHA
       ▼
[STAGE 3: DRY RUN & DIFF ANALYSIS]
       │  • Log commits to be deployed (git log prod..target)
       │  • Inspect modified files (git diff --stat)
       │  • Detect SQL changes in sql/
       │  • HALT IF NO -Apply FLAG
       ▼
[STAGE 4: DEPLOYMENT EXECUTION (Requires -Apply)]
       │  • Atomic server-side lock acquired: /tmp/online-exam-deploy.lock
       │  • If SQL migrations present in release:
       │      1. Requires explicit acknowledgment via -MigrateDb
       │      2. Takes logical database dump (mariadb-dump) to /var/backups/
       │      3. SQL files are NOT automatically executed (manual execution required)
       │      4. Arms permanent prohibition against automatic code rollback
       │  • SSM fetches target commit: git fetch origin main
       │  • SSM checks out exact commit: git checkout <TargetCommit>
       │  • SSM validates syntax on production files: php -l
       │  • SSM verifies Nginx syntax: nginx -t
       │  • Zero-downtime service reload: systemctl reload php-fpm
       │  • Lock automatically released via trap on exit/completion
       │  • (Automatic rollback initiated if execution fails, but NEVER on lock collision)
       ▼
[STAGE 5: POST-DEPLOYMENT VERIFICATION & SMOKE TEST]
          • Query actual commit on EC2 (git rev-parse HEAD)
          • Verify all systemd services remain active
          • External HTTP health check (curl -I http://13.202.114.100/ returns 200 OK)
          • Failure Handling: If commit verification or HTTP fails:
              - If NO database migration was run: Automatically rolls back code to previous commit
              - If database migration WAS run: Aborts code rollback to prevent schema mismatch; flags for manual recovery
```

---

## 4. Usage Instructions

### A. Dry-Run Pre-flight Check (Default)
Run without flags to test all pre-checks, inspect the diff, and confirm that the target commit is ready without touching production:

```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-production-deploy/scripts/deploy.ps1
```

### B. Execute Code Deployment
When all local and production pre-checks pass and the code is pushed to GitHub, apply the deployment:

```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-production-deploy/scripts/deploy.ps1 -Apply
```

### C. Deploy a Specific Commit
To deploy a specific verified historical or tagged commit:

```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-production-deploy/scripts/deploy.ps1 -TargetCommit <commit-sha> -Apply
```

### D. Emergency Manual Rollback
To roll back production to a known healthy commit, acquiring `/tmp/online-exam-deploy.lock` atomically before proceeding:

```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-production-deploy/scripts/deploy.ps1 -Rollback <previous-commit-sha> -Apply
```

### E. Handling Database Schema Changes
Database schema changes require explicit human oversight. If changes in `sql/` are detected:
1. Review the SQL diff manually.
2. Note that `deploy.ps1` **does NOT automatically execute SQL scripts** because the project lacks an automated migration runner.
3. Pass `-MigrateDb` to acknowledge the schema changes. When run with `-Apply`, the script:
   - Creates a full logical backup in `/var/backups/online-exam/` (`mariadb-dump`).
   - Permanently disables automatic code rollback for this deployment.
4. Deploy the code:
```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-production-deploy/scripts/deploy.ps1 -MigrateDb -Apply
```
5. Apply the reviewed SQL migration scripts manually to MariaDB using SSM Run Command or an SSM Session.

---

## 5. Failure Recovery & Rollback Protocols

* **Lock Collision NEVER Triggers Rollback:**  
  If a deployment or manual rollback fails because `/tmp/online-exam-deploy.lock` is held by another process, the script exits immediately with an error. It **never** dispatches a rollback command, checks out commits, or reloads services. The ongoing deployment is completely protected.
* **Automatic Code Rollback on Mid-Flight Failure:**  
  If any deployment command (`git fetch`, `git checkout`, `php -l`, `nginx -t`, or `systemctl reload php-fpm`) fails after the lock is acquired, the script automatically checks out the recorded pre-deploy commit SHA and reloads PHP-FPM.
* **Automatic Code Rollback on Post-Deployment Smoke Test Failure:**  
  If the deployed commit check or the public HTTP health check fails during Stage 5, the script enters a failed state and automatically triggers code rollback to the previous production commit.
* **CRITICAL DATABASE MIGRATION RULE:**  
  Automatic code rollback is **STRICTLY PROHIBITED** if database schema changes were acknowledged (`-MigrateDb`). Reverting application code against an updated database schema causes data corruption. In such cases, the script halts, reports that manual migration-aware recovery is required, and points to the pre-migration logical database dump stored in `/var/backups/online-exam/`. Database changes are **never** reversed automatically.
