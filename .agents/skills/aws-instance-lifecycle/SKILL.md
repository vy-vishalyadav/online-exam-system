---
name: aws-instance-lifecycle
description: >-
  Safely manages AWS EC2 instance lifecycle (start, stop, health verification), public IPv4 / Elastic IP
  cost optimization, and Spaceship DNS automation for the Online Exam System (i-0acdf2220ae4afb2d in ap-south-1,
  domain exam-portal.online). Enforces dry-run by default, atomic concurrency locking, strict non-destructive
  resource handling, decoupled IP release, Spaceship batch record preservation (apex A update + www CNAME retention),
  and canonical HTTPS verification.
---

# AWS Instance Lifecycle, Public IPv4 Cost Management & Spaceship DNS Skill

This skill provides verified, safe, cost-optimized lifecycle automation for the **Online Exam System** production infrastructure on Amazon Web Services (AWS) with integrated **Spaceship DNS** management.

---

## 1. Context & Architecture

* **Target EC2 Instance:** `i-0acdf2220ae4afb2d` (`online-exam-production`)
* **Instance Type:** `t3.micro`
* **Region:** `ap-south-1` (Mumbai)
* **Operating System:** Amazon Linux 2023
* **Production Path:** `/var/www/online-exam`
* **Database:** `online_exam_db` (MariaDB, strictly bound to `127.0.0.1:3306`)
* **Services:** `nginx`, `php-fpm`, `mariadb`, `online-exam-finalizer.timer`
* **Production Domain:** `exam-portal.online`
* **Canonical Access URL:** `https://www.exam-portal.online/`
* **Apex URL:** `https://exam-portal.online/` (301 redirect to canonical WWW)
* **DNS Provider:** Spaceship (Authoritative nameservers, managed via Spaceship Public REST API)
* **Current DNS Records:**
  * Apex A Record: `exam-portal.online` (`@`) &rarr; `13.202.114.100`
  * Canonical CNAME Record: `www` &rarr; `exam-portal.online`
* **HTTPS / SSL:** Let's Encrypt certificate configured in Nginx covering both `exam-portal.online` and `www.exam-portal.online` (auto-renewal managed via certbot systemd timer).
* **Management Channel:** AWS Systems Manager (SSM) & AWS CLI (No SSH permitted)
* **Lifecycle Lock:** `.aws-instance-lifecycle.lock` in repository root
* **Usage Cycle:** The application is typically active ~7 days per month during exam schedules and remains idle during the remaining ~23 days.

---

## 2. When to Use / When NOT to Use

### Use This Skill For:
* Post-exam shutdown to halt compute charges while keeping code, databases, and configuration safe on persistent EBS storage (`vol-0a0fffe887d09ba3f`).
* Releasing public IPv4 / Elastic IPs during long idle windows to eliminate AWS idle IPv4 charges.
* Pre-exam startup, status check verification, SSM readiness checks, and server service verification.
* Updating the domain apex A record on Spaceship when restarting with a new public IP.
* Validating public HTTP, HTTPS, 301 redirects, and domain DNS resolution before students and teachers log in.
* Inspecting live instance metadata, EIP allocations, DNS records, and operational cost metrics.

### Do NOT Use This Skill For:
* Code deployments or Git checkouts (use `aws-production-deploy` instead).
* Database migrations or schema modifications.
* Modifying EC2 Security Groups, IAM roles, or VPC configurations.
* Terminating EC2 instances or deleting EBS volumes (strictly prohibited).

---

## 3. Inviolable Safety Rules

1. **DRY-RUN by Default:** Any command executed without `-Apply` performs purely read-only pre-flight checks and prints a planned mutation summary.
2. **Explicit Decoupling of Actions:** Stopping EC2, releasing an Elastic IP, allocating an IP, and modifying DNS are independent actions. Stopping an instance **NEVER** releases an IP automatically.
3. **No Termination / No Volume Deletion:** The skill will **NEVER** terminate the EC2 instance or delete EBS volumes. All data and databases remain preserved on `vol-0a0fffe887d09ba3f`.
4. **Running Instance IP Protection:** The skill **STRICTLY PROHIBITS** releasing the public IP of a running instance. The instance must be cleanly stopped before an IP can be released.
5. **Exact Target Identification:** All operations assert that the region is `ap-south-1` and the instance ID is strictly `i-0acdf2220ae4afb2d`.
6. **Local Concurrency Protection:** An atomic lock file (`.aws-instance-lifecycle.lock`) guarantees mutual exclusion between lifecycle tasks. Competing operations abort safely without modifying AWS state.
7. **No Hardcoded Credentials:** Operates exclusively using configured AWS CLI profiles and local environment variables (`SPACESHIP_API_KEY`, `SPACESHIP_API_SECRET`). Credentials must NEVER be committed to Git.
8. **Spaceship Batch Update Safety:** The Spaceship DNS API (`PUT /v1/dns/records/{domain}`) replaces the entire record set. To prevent accidental deletion of existing records, `manage.ps1` always fetches the current record set, updates only the apex `@` A record, explicitly preserves the `www` CNAME record and any other records (MX, TXT, etc.), and submits the combined authoritative batch.

---

## 4. Spaceship DNS Integration & Credential Configuration

### Spaceship API Specification
* **Base Endpoint:** `https://spaceship.dev/api/v1/dns/records/{domain}`
* **Authentication Headers:**
  * `X-API-Key: <SPACESHIP_API_KEY>`
  * `X-API-Secret: <SPACESHIP_API_SECRET>`
* **Required Spaceship Permissions:**
  * `dnsrecords:read` &mdash; Allows reading existing DNS records to prepare the safe update batch.
  * `dnsrecords:write` &mdash; Allows updating the apex A record to route traffic to the active EC2 instance.

### Secure Local Credential Setup (Outside Git)
Credentials must be supplied through local terminal environment variables. They must **never** be placed in scripts, markdown files, or committed to Git.

In your local PowerShell terminal session:
```powershell
$env:SPACESHIP_API_KEY    = "your_spaceship_api_key_here"
$env:SPACESHIP_API_SECRET = "your_spaceship_api_secret_here"
```

### Missing Credential Guard & Safe Error Handling
If Spaceship API credentials are not set in the environment:
* In **DRY-RUN mode** (without `-Apply`), `manage.ps1` previews the planned DNS change, performs read-only public DNS lookups, and prints guidance on setting credentials.
* In **APPLY mode** (with `-Apply`), `manage.ps1` immediately stops before making any network calls and outputs clear instructions on configuring the environment variables (separately diagnosing if the key, secret, or both are missing).
* **Sanitized Errors:** Network, HTTP 401/403, and JSON parsing errors are caught and sanitized so API secrets and request headers are never revealed in terminal output or error messages.

### Strict Target Domain Enforcement
To prevent unintended zone modifications, `manage.ps1` strictly validates that the target domain is exactly `exam-portal.online`. Any attempt to pass an arbitrary or mismatched domain through `-DomainName` is blocked immediately with a security violation error before any API interaction.

### Authoritative Post-Update Verification
After a mutating DNS update with `-Apply`, the skill automatically executes `Verify-PostDnsUpdate`:
1. Queries Spaceship API `GET` to confirm the authoritative `@` A record matches the target IP.
2. Asserts that the canonical `www` CNAME record is intact.
3. Tests public DNS resolution for both `exam-portal.online` and `www.exam-portal.online`.
4. Probes HTTP/HTTPS endpoints to verify 301 redirects and canonical 200 OK reachability.

---

## 5. Workflows & Command Reference

The PowerShell entry point is located at:
`scripts/manage.ps1`

### Action 1: Status Audit (Default, Read-Only)
Inspects current EC2 instance state, Elastic IP association, EBS attachment, SSM agent status, systemd services, Spaceship DNS resolution, and canonical HTTPS routing:

```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action Status
```

### Action 2: Full Readiness Verification Matrix
Runs complete end-to-end verification across AWS, SSM, Nginx, MariaDB, PHP-FPM, HTTP redirect, apex HTTPS, and canonical HTTPS (`https://www.exam-portal.online`):

```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action Verify
```

### Action 3: Post-Exam Shutdown (Retaining Elastic IP)
Safely stops the EC2 instance to eliminate compute charges while retaining the current public Elastic IP:

```powershell
# Dry-run preview
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action StopIdle

# Execute shutdown
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action StopIdle -Apply
```

### Action 4: Post-Exam Shutdown (Releasing Public IP for Max Savings)
Stops the EC2 instance and explicitly releases the Elastic IP to avoid both compute and idle IPv4 hourly charges:

```powershell
# Dry-run preview
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action StopIdle -ReleasePublicIp

# Execute shutdown and release IP
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action StopIdle -ReleasePublicIp -Apply
```

### Action 5: Exam Day Startup & Health Verification
Starts the EC2 instance, waits for AWS status checks (2/2) and SSM Online, verifies all 4 core services, probes public HTTP, and verifies canonical HTTPS reachability:

```powershell
# Dry-run preview
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action StartExam

# Execute startup
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action StartExam -Apply
```

### Action 6: Exam Day Startup with Automated Spaceship DNS Update
Starts the instance, performs all health checks, and automatically updates the Spaceship apex A record if the public IP has changed:

```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action StartExam -UpdateDns -Apply
```

### Action 7: Standalone Spaceship DNS Update
Updates only the apex A record on Spaceship to the active EC2 public IP while preserving the `www` CNAME and all other records:

```powershell
# Dry-run preview (queries live records if credentials configured, or shows planned payload)
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action DnsUpdate

# Execute update
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action DnsUpdate -Apply
```

### Action 8: Explicit Elastic IP Allocation
Allocates a new VPC Elastic IP in `ap-south-1` and associates it with the instance:

```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action AllocateIp -Apply
```

### Action 9: Explicit Elastic IP Release
Explicitly disassociates and releases an allocated Elastic IP (requires instance to be stopped):

```powershell
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action ReleaseIp -Apply
```

### Action 10: Secondary Provider (Route 53)
If migrating to AWS Route 53 in the future, the skill continues to support Route 53 via `-DnsProvider Route53`:

```powershell
# Dry-run Route 53
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action DnsUpdate -DnsProvider Route53

# Apply Route 53
powershell -ExecutionPolicy Bypass -File .agents/skills/aws-instance-lifecycle/scripts/manage.ps1 -Action DnsUpdate -DnsProvider Route53 -Apply
```

---

## 6. Cost Implications & Economics

| Resource State | Compute Cost | Storage (EBS) Cost | Public IPv4 Cost | Typical 30-Day Monthly Cost |
|----------------|--------------|-------------------|------------------|-----------------------------|
| **Always Running + EIP** | `$7.50` / mo | `$0.64` / mo | `$3.60` / mo | **`~$11.74` / month** |
| **Stopped + Retain EIP** | `$0.00` (idle) | `$0.64` / mo | `$3.60` / mo (idle fee) | **`~$4.24` / month** |
| **7-Day Exam Mode (Stop + Release IP)** | `$1.75` (7 days) | `$0.64` / mo | `$0.84` (7 days) | **`~$3.23` / month (~73% savings)** |

> [!TIP]
> Releasing the Elastic IP during long idle periods saves approximately `$2.76` per idle month in IPv4 fees. When restarting the instance on exam day, you can either allocate a new Elastic IP or use an ephemeral public IP and update your Spaceship apex A record using `manage.ps1 -Action DnsUpdate -Apply`.

---

## 7. DNS, Routing & HTTPS Readiness Matrix

The production routing model enforces canonical HTTPS:

```
http://exam-portal.online/*      --[301 Moved Permanently]--> https://www.exam-portal.online/*
https://exam-portal.online/*     --[301 Moved Permanently]--> https://www.exam-portal.online/*
http://www.exam-portal.online/*  --[301 Moved Permanently]--> https://www.exam-portal.online/*
https://www.exam-portal.online/* --[200 OK]--> PHP-FPM / MariaDB Application
```

* **Apex Record:** `exam-portal.online` &rarr; points to the active EC2 public IPv4.
* **Canonical Host:** `www.exam-portal.online` &rarr; CNAME to `exam-portal.online`.
* **Let's Encrypt Certificate:** Active and valid for both domains.
* **HSTS:** Enabled with `max-age=31536000; includeSubDomains`.
* **Security Headers:** CSP, X-Frame-Options (`SAMEORIGIN`), X-Content-Type-Options (`nosniff`), Referrer-Policy intact.
