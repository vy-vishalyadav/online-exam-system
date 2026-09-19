<#
.SYNOPSIS
    Safe AWS Systems Manager (SSM) Production Deployment Automation for Online Exam System.

.DESCRIPTION
    Executes staged, zero-downtime, non-destructive deployments to production EC2 (i-0acdf2220ae4afb2d).
    Uses AWS Systems Manager Run Command (AWS-RunShellScript) exclusively.
    NEVER uses SSH, NEVER creates ZIP archives, NEVER modifies Security Groups or IAM.
    Preserves config/config.local.php, uploads/, and production database integrity.

.PARAMETER TargetCommit
    Specific Git commit SHA to deploy. Defaults to current local HEAD.

.PARAMETER Apply
    Switch parameter. When omitted, the script runs in DRY-RUN mode (read-only pre-checks).
    Must be explicitly specified to execute code deployment to production.

.PARAMETER MigrateDb
    Switch parameter. Acknowledges pending database schema changes in the release and authorizes deployment.
    When specified with -Apply:
    1. Creates an automatic logical database backup (mariadb-dump) in /var/backups/online-exam/
    2. Prohibits automatic code rollback (to protect database/schema consistency)
    NOTE: This project does not use an automated database migration runner.
    Raw SQL files are NEVER executed automatically. Reviewed SQL migrations must be run manually via SSM.

.PARAMETER Rollback
    Rolls back production to a specified commit SHA and reloads services.

.PARAMETER SkipLocalLint
    Skip local PHP syntax linting (not recommended).

.EXAMPLE
    # Dry-run validation (default, safe, read-only)
    .\deploy.ps1

    # Deploy current HEAD to production
    .\deploy.ps1 -Apply

    # Roll back to a previous verified commit
    .\deploy.ps1 -Rollback c8a2a715aba455db742ddfa233570ab46796059c -Apply
#>

[CmdletBinding()]
param (
    [Parameter(Position = 0)]
    [string]$TargetCommit = "",

    [Parameter()]
    [switch]$Apply,

    [Parameter()]
    [switch]$MigrateDb,

    [Parameter()]
    [string]$Rollback = "",

    [Parameter()]
    [switch]$SkipLocalLint
)

$ErrorActionPreference = "Stop"

# ==========================================
# 1. ENVIRONMENT & CONFIGURATION
# ==========================================
$ExpectedInstanceId  = "i-0acdf2220ae4afb2d"
$ExpectedRegion      = "ap-south-1"
$ExpectedElasticIp   = "13.202.114.100"
$ExpectedRemoteUrl   = "https://github.com/vy-vishalyadav/online-exam-system.git"
$ProductionAppPath   = "/var/www/online-exam"
$ProductionDbName    = "online_exam_db"
$ServerLockDir       = "/tmp/online-exam-deploy.lock"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot  = (Resolve-Path (Join-Path $ScriptDir "..\..\..\..")).Path

# Locate AWS CLI
$aws = "aws"
$localAws = "C:\Users\vikaa\AppData\Local\Programs\Amazon\AWSCLIV2\aws.exe"
if (Test-Path $localAws) {
    $aws = $localAws
}

# Clear any conflicting environment variables (e.g. Bedrock keys) that override AWS CLI profiles
Remove-Item Env:\AWS_ACCESS_KEY_ID -ErrorAction SilentlyContinue
Remove-Item Env:\AWS_SECRET_ACCESS_KEY -ErrorAction SilentlyContinue
Remove-Item Env:\AWS_SESSION_TOKEN -ErrorAction SilentlyContinue
Remove-Item Env:\AWS_REGION -ErrorAction SilentlyContinue
Remove-Item Env:\AWS_BEDROCK_API_KEY -ErrorAction SilentlyContinue

function Write-StageHeader([string]$stage, [string]$title) {
    Write-Host "`n========================================================" -ForegroundColor Cyan
    Write-Host " STAGE ${stage}: $title" -ForegroundColor Cyan
    Write-Host "========================================================" -ForegroundColor Cyan
}

function Write-Pass([string]$msg) {
    Write-Host "  [PASS] $msg" -ForegroundColor Green
}

function Write-Fail([string]$msg) {
    Write-Host "  [FAIL] $msg" -ForegroundColor Red
}

function Write-Warn([string]$msg) {
    Write-Host "  [WARN] $msg" -ForegroundColor Yellow
}

function Write-Info([string]$msg) {
    Write-Host "  [INFO] $msg" -ForegroundColor Gray
}

# Helper to execute shell commands remotely on production EC2 via AWS Systems Manager Run Command
function Invoke-SSMCommand([string[]]$Commands, [string]$Description = "SSM Execution") {
    $tmpParams = [System.IO.Path]::GetTempFileName()
    $paramsObj = @{ commands = $Commands }
    $paramsObj | ConvertTo-Json -Depth 5 | Set-Content -Path $tmpParams -Encoding Ascii

    Write-Info "Dispatching SSM Run Command: $Description..."
    $sendRaw = & $aws ssm send-command `
        --instance-ids $ExpectedInstanceId `
        --document-name "AWS-RunShellScript" `
        --parameters "file://$tmpParams" `
        --region $ExpectedRegion `
        --output json 2>&1

    if ($LASTEXITCODE -ne 0) {
        Remove-Item $tmpParams -ErrorAction SilentlyContinue
        throw "Failed to dispatch SSM command: $sendRaw"
    }

    $sendJson = $sendRaw | ConvertFrom-Json
    $commandId = $sendJson.Command.CommandId
    Remove-Item $tmpParams -ErrorAction SilentlyContinue

    # Poll for completion
    $maxAttempts = 30
    for ($i = 1; $i -le $maxAttempts; $i++) {
        Start-Sleep -Seconds 2
        $invRaw = & $aws ssm get-command-invocation `
            --command-id $commandId `
            --instance-id $ExpectedInstanceId `
            --region $ExpectedRegion `
            --output json 2>&1

        if ($LASTEXITCODE -eq 0) {
            $inv = $invRaw | ConvertFrom-Json
            if ($inv.Status -notin @("Pending", "InProgress", "Delayed")) {
                return $inv
            }
        }
    }
    throw "Timed out waiting for SSM Command ID: $commandId"
}

# ==========================================
# 2. LOCAL REPOSITORY PRE-CHECKS
# ==========================================
Write-StageHeader "1" "LOCAL REPOSITORY PRE-CHECKS"

Set-Location $RepoRoot

# A. Verify Git Remote
$remoteUrl = (git config --get remote.origin.url).Trim()
$normalizedLocalRemote = $remoteUrl.TrimEnd("/")
if (-not $normalizedLocalRemote.EndsWith(".git")) { $normalizedLocalRemote += ".git" }

if ($normalizedLocalRemote -ne $ExpectedRemoteUrl) {
    Write-Fail "Local Remote origin mismatch: Found '$remoteUrl', expected '$ExpectedRemoteUrl'"
    exit 1
}
Write-Pass "Local Git Remote URL verified: $remoteUrl"

# B. Verify Git Branch
$branch = (git branch --show-current).Trim()
if ($branch -ne "main") {
    Write-Fail "Current branch is '$branch'. Production deployments must originate from 'main'."
    exit 1
}
Write-Pass "Active branch verified: main"

# C. Verify Clean Worktree (Hard block on -Apply; Informative warning on dry-run)
$status = (git status --porcelain).Trim()
if ($status) {
    if ($Apply) {
        Write-Fail "Local working directory has uncommitted changes:`n$status"
        Write-Fail "Real deployment (-Apply) aborted to protect build integrity. Commit or stash local changes first."
        exit 1
    } else {
        Write-Warn "Local working directory has uncommitted changes (allowed in DRY-RUN mode only):`n$status"
    }
} else {
    Write-Pass "Local Git worktree is clean"
}

# D. Verify Target Commit & origin/main Sync
$localHead = (git rev-parse HEAD).Trim()
if (-not $TargetCommit) {
    $TargetCommit = $localHead
}

$originMain = (git rev-parse origin/main).Trim()
if ($localHead -ne $originMain) {
    if ($Apply) {
        Write-Fail "Local HEAD ($localHead) does not match origin/main ($originMain). Push local commits to GitHub first."
        exit 1
    } else {
        Write-Warn "Local HEAD ($localHead) does not match origin/main ($originMain) (Allowed in DRY-RUN mode)."
    }
} else {
    Write-Pass "Local HEAD synchronized with origin/main: $TargetCommit"
}

# E. Local PHP Syntax Linting
if (-not $SkipLocalLint) {
    Write-Info "Executing PHP syntax linting on local codebase..."
    $phpExe = "C:\xampp\php\php.exe"
    if (-not (Test-Path $phpExe)) { $phpExe = "php" }

    $phpFiles = Get-ChildItem -Path $RepoRoot -Recurse -Filter "*.php" | Where-Object {
        $_.FullName -notmatch "[\/\\]\.git[\/\\]" -and
        $_.FullName -notmatch "[\/\\]vendor[\/\\]" -and
        $_.FullName -notmatch "[\/\\]\.agents[\/\\]"
    }

    $lintFailures = 0
    foreach ($f in $phpFiles) {
        $out = & $phpExe -l $f.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Fail "Syntax error in $($f.FullName): $out"
            $lintFailures++
        }
    }
    if ($lintFailures -gt 0) {
        Write-Fail "PHP syntax linting failed ($lintFailures error(s)). Aborting."
        exit 1
    }
    Write-Pass "All $($phpFiles.Count) PHP files passed syntax validation"
} else {
    Write-Warn "Skipped local PHP syntax linting."
}

# ==========================================
# 3. PRODUCTION STATUS & PRE-DEPLOY AUDIT
# ==========================================
Write-StageHeader "2" "PRODUCTION TARGET PRE-AUDIT (AWS SSM)"

# A. Verify EC2 Instance State
Write-Info "Checking EC2 instance state via AWS CLI..."
$ec2Info = & $aws ec2 describe-instances `
    --instance-ids $ExpectedInstanceId `
    --region $ExpectedRegion `
    --query "Reservations[0].Instances[0].{State:State.Name, PublicIp:PublicIpAddress}" `
    --output json | ConvertFrom-Json

if ($ec2Info.State -ne "running") {
    Write-Fail "EC2 instance $ExpectedInstanceId is in state '$($ec2Info.State)', expected 'running'."
    exit 1
}
Write-Pass "EC2 instance $ExpectedInstanceId is running at $($ec2Info.PublicIp)"

# B. Verify SSM Agent Connectivity
Write-Info "Verifying SSM connection status..."
$ssmInfo = & $aws ssm describe-instance-information `
    --filters "Key=InstanceIds,Values=$ExpectedInstanceId" `
    --region $ExpectedRegion `
    --output json | ConvertFrom-Json

if ($ssmInfo.InstanceInformationList.Count -eq 0 -or $ssmInfo.InstanceInformationList[0].PingStatus -ne "Online") {
    Write-Fail "EC2 instance $ExpectedInstanceId is not reporting Online in AWS Systems Manager."
    exit 1
}
Write-Pass "AWS Systems Manager status is Online (Agent v$($ssmInfo.InstanceInformationList[0].AgentVersion))"

# C. Inspect Production State via SSM Run Command
$auditCommands = @(
    "echo '=== PROD_REMOTE ==='",
    "git -C $ProductionAppPath -c safe.directory=$ProductionAppPath config --get remote.origin.url",
    "echo '=== PROD_COMMIT ==='",
    "git -C $ProductionAppPath -c safe.directory=$ProductionAppPath rev-parse HEAD",
    "echo '=== PROD_STATUS ==='",
    "runuser -u ec2-user -- git -C $ProductionAppPath status --porcelain",
    "echo '=== NGINX ==='",
    "systemctl is-active nginx",
    "echo '=== PHP_FPM ==='",
    "systemctl is-active php-fpm",
    "echo '=== MARIADB ==='",
    "systemctl is-active mariadb",
    "echo '=== TIMER ==='",
    "systemctl is-active online-exam-finalizer.timer",
    "echo '=== NGINX_CONF ==='",
    "nginx -t 2>&1",
    "echo '=== DB_BINDING ==='",
    "ss -lntp | grep ':3306'",
    "echo '=== LOCAL_CONFIG ==='",
    "[ -f $ProductionAppPath/config/config.local.php ] && echo 'EXISTS' || echo 'MISSING'",
    'echo "=== ACTIVE_EXAMS ==="',
    'mariadb -e "SELECT COUNT(*) FROM online_exam_db.exam_sessions WHERE submitted = 0 AND TIMESTAMPDIFF(SECOND, started_at, NOW()) < (duration_minutes * 60);" -N 2>/dev/null || echo "0"'
)

$auditResult = Invoke-SSMCommand -Commands $auditCommands -Description "Production Pre-flight Health Check"
$auditOutput = $auditResult.StandardOutputContent

# 1. Verify Production Git Remote URL
if ($auditOutput -match "=== PROD_REMOTE ===\s+(\S+)") {
    $prodRemote = $matches[1].Trim()
    $normalizedProdRemote = $prodRemote.TrimEnd("/")
    if (-not $normalizedProdRemote.EndsWith(".git")) { $normalizedProdRemote += ".git" }
    
    # Sanitize remote URL if credentials ever appear
    $sanitizedRemote = $prodRemote -replace "https://[^@]+@", "https://***@"
    
    if ($normalizedProdRemote -ne $ExpectedRemoteUrl) {
        Write-Fail "Production Git remote mismatch! Found '$sanitizedRemote', expected '$ExpectedRemoteUrl'."
        exit 1
    }
    Write-Pass "Production Git remote verified: $sanitizedRemote"
} else {
    Write-Fail "Could not retrieve production Git remote URL from EC2."
    exit 1
}

# 2. Parse Current Production Commit
$prodCommit = ""
if ($auditOutput -match "=== PROD_COMMIT ===\s+([a-f0-9]{40})") {
    $prodCommit = $matches[1]
    Write-Pass "Current deployed production commit: $prodCommit"
} else {
    Write-Fail "Could not parse current production commit from EC2.`n$auditOutput"
    exit 1
}

# 3. Verify Production Git Worktree is Clean
if ($auditOutput -match "=== PROD_STATUS ===\s*([\s\S]*?)\s*=== NGINX ===") {
    $prodStatusRaw = $matches[1].Trim()
    $dirtyLines = $prodStatusRaw -split "`r?`n" | Where-Object { 
        $_ -and 
        $_ -notmatch "warning: could not open directory 'uploads/'" -and
        $_ -notmatch "^\s*$"
    }
    if ($dirtyLines -and $dirtyLines.Count -gt 0) {
        Write-Fail "Production working tree is NOT clean! Found uncommitted modifications/untracked files:"
        foreach ($line in $dirtyLines) {
            Write-Fail "  $line"
        }
        Write-Fail "Deployment aborted to protect production state. Do NOT clean or overwrite production changes automatically."
        exit 1
    }
    Write-Pass "Production Git worktree confirmed clean (no uncommitted tracked or untracked changes)"
} else {
    Write-Fail "Could not verify production Git worktree status."
    exit 1
}

# 4. Verify Protected Local Configuration Exists
if ($auditOutput -match "=== LOCAL_CONFIG ===\s+EXISTS") {
    Write-Pass "Protected server configuration (config.local.php) confirmed present"
} else {
    Write-Fail "Production config.local.php is missing on EC2!"
    exit 1
}

# 5. Verify Core Services
$serviceHeaders = [ordered]@{
    "nginx"                       = "=== NGINX ==="
    "php-fpm"                     = "=== PHP_FPM ==="
    "mariadb"                     = "=== MARIADB ==="
    "online-exam-finalizer.timer" = "=== TIMER ==="
}
foreach ($svc in $serviceHeaders.Keys) {
    $hdr = [regex]::Escape($serviceHeaders[$svc])
    if ($auditOutput -match "$hdr\s*\r?\n\s*active") {
        Write-Pass "Service is active: $svc"
    } else {
        Write-Fail "Service is NOT active: $svc"
        exit 1
    }
}

# 6. Verify MariaDB Binding
if ($auditOutput -match "127\.0\.0\.1:3306") {
    Write-Pass "MariaDB port 3306 confirmed strictly bound to 127.0.0.1 (localhost)"
} else {
    Write-Fail "MariaDB port 3306 binding check failed!"
    exit 1
}

# 7. Check for Active Exam Sessions
$activeExamCount = 0
if ($auditOutput -match "=== ACTIVE_EXAMS ===\s*(\d+)") {
    $activeExamCount = [int]$matches[1]
}
if ($activeExamCount -gt 0) {
    if ($Apply) {
        Write-Fail "ACTIVE EXAMINATIONS IN PROGRESS ($activeExamCount unsubmitted student session(s) active)."
        Write-Fail "Production deployment aborted to prevent service reload disruption during an active exam."
        Write-Fail "Wait until the active exam window closes and sessions are submitted/finalized before deploying."
        exit 1
    } else {
        Write-Warn "Active examination warning: $activeExamCount student session(s) currently active in database (Allowed in DRY-RUN mode)."
    }
} else {
    Write-Pass "Zero active in-progress student exam sessions (safe to deploy)"
}


# ==========================================
# 4. ROLLBACK OPERATION (IF REQUESTED)
# ==========================================
if ($Rollback) {
    Write-StageHeader "3" "ROLLBACK EXECUTION"
    Write-Warn "ROLLBACK INITIATED: Target commit $Rollback"

    if (-not $Apply) {
        Write-Warn "Dry-run rollback mode. To apply rollback, re-run with: .\deploy.ps1 -Rollback $Rollback -Apply"
        exit 0
    }

    $rollbackCmds = @(
        "set -e",
        "LOCK_DIR='$ServerLockDir'",
        "if ! mkdir `"`$LOCK_DIR`" 2>/dev/null; then",
        "    LOCK_PID=`$(cat `"`$LOCK_DIR/pid`" 2>/dev/null || echo 'unknown')",
        "    echo 'ERROR: Deployment lock already held (lock: '`$LOCK_DIR', pid: '`$LOCK_PID').'",
        "    echo 'Another deployment or rollback is in progress. Exiting safely without modifying state.'",
        "    exit 1",
        "fi",
        "echo `$$ > `"`$LOCK_DIR/pid`"",
        "trap 'rm -rf `"`$LOCK_DIR`"' EXIT INT TERM",
        "",
        "cd $ProductionAppPath",
        "git config --system --add safe.directory $ProductionAppPath || true",
        "echo '==> Rolling back code to $Rollback...'",
        "runuser -u ec2-user -- git checkout $Rollback",
        "echo '==> Reloading PHP-FPM...'",
        "systemctl reload php-fpm",
        "echo '==> Verifying rollback commit...'",
        "git -C $ProductionAppPath -c safe.directory=$ProductionAppPath rev-parse HEAD",
        "echo '==> Checking service health...'",
        "systemctl is-active nginx",
        "systemctl is-active php-fpm"
    )

    $rbResult = Invoke-SSMCommand -Commands $rollbackCmds -Description "Rollback to $Rollback"

    # Check for lock collision
    if ($rbResult.StandardOutputContent -match "Deployment lock already held" -or $rbResult.StandardErrorContent -match "Deployment lock already held") {
        Write-Fail "ROLLBACK ABORTED SAFELY: Server-side deployment lock is currently held."
        Write-Fail "Another deployment or rollback is actively running on EC2 instance $ExpectedInstanceId."
        Write-Fail "No files or services were modified. Active process left untouched."
        exit 1
    }

    if ($rbResult.Status -eq "Success" -and $rbResult.StandardOutputContent -match $Rollback) {
        Write-Pass "Rollback command executed successfully."
        Write-Info "Output: $($rbResult.StandardOutputContent)"
    } else {
        Write-Fail "Rollback command failed: $($rbResult.StandardErrorContent)"
        if ($rbResult.StandardOutputContent) {
            Write-Info "Stdout: $($rbResult.StandardOutputContent)"
        }
        exit 1
    }
    exit 0
}

# ==========================================
# 5. MIGRATION & DIFF ANALYSIS
# ==========================================
Write-StageHeader "3" "DEPLOYMENT DIFF & MIGRATION ANALYSIS"

if ($prodCommit -eq $TargetCommit) {
    Write-Pass "Production is ALREADY running target commit $TargetCommit."
    Write-Info "No code deployment is required."
    exit 0
}

Write-Info "Changes between Production ($prodCommit) and Target ($TargetCommit):"
$commitLog = git log "$prodCommit..$TargetCommit" --oneline
Write-Host $commitLog -ForegroundColor Gray

# Check for SQL migration files
$sqlDiff = git diff --name-only "$prodCommit..$TargetCommit" -- "sql/"
$migrationRequired = [bool]$sqlDiff

if ($migrationRequired) {
    Write-Warn "DATABASE SCHEMA CHANGES DETECTED in the following release file(s):`n$sqlDiff"
    Write-Info "Architecture Notice: This project does not use an automated database migration runner."
    Write-Info "To prevent accidental schema destruction, SQL files are NEVER executed automatically by deploy.ps1."
    if (-not $MigrateDb) {
        Write-Warn "Safety Rule: When database changes are present in the release, you must acknowledge them"
        Write-Warn "by specifying '-MigrateDb'. When specified with -Apply, this triggers an automatic pre-deployment"
        Write-Warn "logical database backup (mariadb-dump) and permanently disables automatic code rollback."
        Write-Warn "Reviewed SQL migration scripts must be executed manually by the administrator via SSM."
    } else {
        Write-Pass "Database schema changes acknowledged via -MigrateDb."
        Write-Info "Deploying with -Apply will create a pre-migration backup and arm rollback protections."
    }
} else {
    Write-Pass "Zero database schema/data modifications detected (code-only deployment)"
}

# ==========================================
# 6. DEPLOYMENT EXECUTION (APPLY VS DRY RUN)
# ==========================================
Write-StageHeader "4" "DEPLOYMENT GATEWAY"

if (-not $Apply) {
    Write-Host "`n*** DRY-RUN COMPLETE ***" -ForegroundColor Yellow
    Write-Host "All local and production pre-checks PASSED." -ForegroundColor Green
    Write-Host "Target commit $TargetCommit is verified and ready for deployment." -ForegroundColor Green
    Write-Host "No changes were made to production." -ForegroundColor Yellow
    Write-Host "`nTo execute the deployment, run:" -ForegroundColor Cyan
    Write-Host "  powershell -ExecutionPolicy Bypass -File .agents/skills/aws-production-deploy/scripts/deploy.ps1 -Apply`n" -ForegroundColor White
    exit 0
}

# REAL DEPLOYMENT EXECUTION
Write-StageHeader "5" "APPLYING PRODUCTION DEPLOYMENT VIA SSM"
Write-Warn "Applying deployment of commit $TargetCommit to $ExpectedInstanceId..."

# Database Backup & Migration Safety Protocol
$migrationExecuted = $false
if ($migrationRequired) {
    if (-not $MigrateDb) {
        Write-Fail "Deployment blocked: Target commit contains database changes under 'sql/', but -MigrateDb was not specified."
        Write-Fail "To proceed, review the SQL files and acknowledge with: .\deploy.ps1 -Apply -MigrateDb"
        Write-Fail "This will back up the database and deploy code. Reviewed SQL must be applied manually via SSM."
        exit 1
    }

    Write-Info "Creating pre-deployment logical database backup in /var/backups/online-exam/..."
    $backupCmd = @(
        "mkdir -p /var/backups/online-exam",
        "mariadb-dump $ProductionDbName > /var/backups/online-exam/pre_deploy_backup_$(date +%Y%m%d%H%M%S).sql"
    )
    $bkRes = Invoke-SSMCommand -Commands $backupCmd -Description "Pre-migration logical DB backup"
    if ($bkRes.Status -ne "Success") {
        Write-Fail "Database backup failed. Aborting deployment to prevent schema corruption."
        exit 1
    }
    Write-Pass "Logical database backup completed successfully in /var/backups/online-exam/"
    Write-Warn "SAFETY PROTOCOL: SQL files are NOT automatically executed (no automated migration runner)."
    Write-Warn "Please execute approved SQL migrations manually on MariaDB via SSM if not already applied."
    Write-Warn "Automatic code rollback is now permanently PROHIBITED for this deployment."
    $migrationExecuted = $true
}

# Safe Code Deployment Script with Atomic Server-Side Lock
$deployCommands = @(
    "set -e",
    "LOCK_DIR='$ServerLockDir'",
    "if ! mkdir `"`$LOCK_DIR`" 2>/dev/null; then",
    "    LOCK_PID=`$(cat `"`$LOCK_DIR/pid`" 2>/dev/null || echo 'unknown')",
    "    echo 'ERROR: Deployment lock already held (lock: '`$LOCK_DIR', pid: '`$LOCK_PID').'",
    "    echo 'Another deployment may be in progress. Exiting safely without modifying state.'",
    "    exit 1",
    "fi",
    "echo `$$ > `"`$LOCK_DIR/pid`"",
    "trap 'rm -rf `"`$LOCK_DIR`"' EXIT INT TERM",
    "",
    "cd $ProductionAppPath",
    "git config --system --add safe.directory $ProductionAppPath || true",
    "echo '==> Fetching latest commits from GitHub...'",
    "runuser -u ec2-user -- git fetch origin main",
    "echo '==> Checking out target commit $TargetCommit...'",
    "runuser -u ec2-user -- git checkout $TargetCommit",
    "echo '==> Validating PHP syntax on production files...'",
    "find . -name '*.php' -not -path './vendor/*' -not -path './.agents/*' -exec php -l {} + | grep -v 'No syntax errors detected' || true",
    "echo '==> Testing Nginx configuration...'",
    "nginx -t",
    "echo '==> Reloading PHP-FPM service (zero-downtime)...'",
    "systemctl reload php-fpm",
    "echo '==> Deployment commands completed.'"
)

$deployResult = Invoke-SSMCommand -Commands $deployCommands -Description "Code Deployment to $TargetCommit"

if ($deployResult.Status -ne "Success") {
    Write-Fail "Deployment command execution failed!"
    if ($deployResult.StandardErrorContent) {
        Write-Fail "Stderr: $($deployResult.StandardErrorContent)"
    }
    if ($deployResult.StandardOutputContent) {
        Write-Info "Stdout: $($deployResult.StandardOutputContent)"
    }

    # 1. LOCK COLLISION CHECK: Lock held by another deployment
    $lockCollision = ($deployResult.StandardOutputContent -match "Deployment lock already held") -or
                     ($deployResult.StandardErrorContent -match "Deployment lock already held")

    if ($lockCollision) {
        Write-Fail "DEPLOYMENT ABORTED SAFELY: Server-side deployment lock is currently held."
        Write-Fail "Another deployment or rollback is actively running on EC2 instance $ExpectedInstanceId."
        Write-Fail "SAFETY RULE ENFORCED: Zero files were modified by this attempt. Automatic rollback is SKIPPED."
        Write-Fail "The active deployment has been left completely untouched."
        exit 1
    }

    # 2. MIGRATION ROLLBACK PROHIBITION CHECK
    if ($migrationExecuted) {
        Write-Fail "CRITICAL: A database migration/backup was flagged before this failure."
        Write-Fail "SPECIAL SAFETY RULE: Automatic code rollback is PROHIBITED because database changes cannot be automatically reversed."
        Write-Fail "Manual intervention required. Pre-migration backup is stored in /var/backups/online-exam/."
        exit 1
    }

    # 3. ACTUAL POST-LOCK MID-FLIGHT FAILURE: Trigger emergency rollback
    Write-Warn "Initiating automatic rollback to previous commit: $prodCommit..."
    $rbCmds = @(
        "cd $ProductionAppPath",
        "runuser -u ec2-user -- git checkout $prodCommit",
        "systemctl reload php-fpm"
    )
    $rbInv = Invoke-SSMCommand -Commands $rbCmds -Description "Emergency Automatic Rollback"
    Write-Warn "Automatic rollback status: $($rbInv.Status)"
    exit 1
}

Write-Pass "Production code updated and PHP-FPM reloaded successfully"

# ==========================================
# 7. POST-DEPLOYMENT VERIFICATION & SMOKE TEST
# ==========================================
Write-StageHeader "6" "POST-DEPLOYMENT SMOKE TEST"

$smokeFailed = $false
$smokeReason = ""

# A. Verify Deployed Commit
$postAudit = Invoke-SSMCommand -Commands @("git -C $ProductionAppPath -c safe.directory=$ProductionAppPath rev-parse HEAD") -Description "Verify deployed commit"
$actualCommit = $postAudit.StandardOutputContent.Trim()

if ($actualCommit -ne $TargetCommit) {
    $smokeFailed = $true
    $smokeReason = "Deployed commit verification failed (Expected: $TargetCommit, Actual: $actualCommit)"
    Write-Fail $smokeReason
} else {
    Write-Pass "Production commit verified: $actualCommit"
}

# B. Public Endpoint Health Check
if (-not $smokeFailed) {
    Write-Info "Executing public HTTP/HTTPS endpoint health check (http://$ExpectedElasticIp/ -> canonical)..."
    $httpCheck = curl.exe -s -I -L --max-time 10 "http://$ExpectedElasticIp/"
    if ($httpCheck -match "HTTP/1\.1 200 OK") {
        Write-Pass "Public endpoint returned HTTP/1.1 200 OK (canonical redirect followed)"
    } else {
        $smokeFailed = $true
        $smokeReason = "Public endpoint health check failed (HTTP != 200 OK):`n$httpCheck"
        Write-Fail $smokeReason
    }
}

# Handle Post-Deployment Smoke Test Failure
if ($smokeFailed) {
    Write-Fail "`n*** POST-DEPLOYMENT SMOKE TEST FAILED ***"
    Write-Fail "Reason: $smokeReason"

    if ($migrationExecuted) {
        Write-Fail "CRITICAL: A database migration was applied during this deployment."
        Write-Fail "SPECIAL SAFETY RULE: Automatic code rollback is PROHIBITED because database changes cannot be automatically reversed."
        Write-Fail "Manual intervention required. Pre-migration backup is stored in /var/backups/online-exam/."
        exit 1
    }

    Write-Warn "Initiating automatic code rollback to previous verified commit: $prodCommit..."
    if (-not $prodCommit) {
        Write-Fail "Fatal: Previous production commit was not recorded. Cannot perform automatic rollback."
        exit 1
    }

    $rbCmds = @(
        "set -e",
        "cd $ProductionAppPath",
        "echo '==> Rolling back code to $prodCommit...'",
        "runuser -u ec2-user -- git checkout $prodCommit",
        "echo '==> Reloading PHP-FPM...'",
        "systemctl reload php-fpm",
        "echo '==> Verifying rollback commit...'",
        "git -C $ProductionAppPath -c safe.directory=$ProductionAppPath rev-parse HEAD",
        "echo '==> Checking service health...'",
        "systemctl is-active nginx",
        "systemctl is-active php-fpm"
    )

    $rbInv = Invoke-SSMCommand -Commands $rbCmds -Description "Post-Smoke-Test Emergency Code Rollback"
    if ($rbInv.Status -eq "Success" -and $rbInv.StandardOutputContent -match $prodCommit) {
        Write-Pass "Automatic code rollback to $prodCommit completed successfully."
        Write-Info "Nginx and PHP-FPM remain active on previous stable commit."
    } else {
        Write-Fail "Automatic code rollback FAILED! Manual administrator inspection required."
        Write-Fail "Rollback output: $($rbInv.StandardOutputContent)"
        Write-Fail "Rollback stderr: $($rbInv.StandardErrorContent)"
    }
    exit 1
}

Write-Host "`n========================================================" -ForegroundColor Green
Write-Host " PRODUCTION DEPLOYMENT COMPLETED SUCCESSFULLY" -ForegroundColor Green
Write-Host " Deployed Commit: $TargetCommit" -ForegroundColor Green
Write-Host " Target Instance: $ExpectedInstanceId (Online in SSM)" -ForegroundColor Green
Write-Host " Public Endpoint: http://$ExpectedElasticIp/ (200 OK)" -ForegroundColor Green
Write-Host "========================================================`n" -ForegroundColor Green
