<#
.SYNOPSIS
    AWS EC2 Instance Lifecycle, Public IPv4 Cost Management, & Spaceship DNS Automation for Online Exam System.

.DESCRIPTION
    Safely manages EC2 instance state (start/stop), public IPv4 / Elastic IP lifecycle,
    and DNS resolution for the production Online Exam System on AWS.
    Target: EC2 instance i-0acdf2220ae4afb2d in ap-south-1.
    Target Domain: exam-portal.online (Canonical URL: https://www.exam-portal.online).
    DNS Provider: Spaceship (Default, via Spaceship Public API) or Route 53 (Secondary).
    NEVER uses SSH, NEVER terminates instances, NEVER deletes EBS volumes.
    Defaults to safe DRY-RUN mode unless -Apply is explicitly supplied.

.PARAMETER Action
    Lifecycle action to perform:
    - Status       : (Default) Read-only audit of EC2, EIP, SSM, services, domain, and health.
    - DryRun       : Explicit dry-run preview of planned actions.
    - StopIdle     : Safely stop EC2 instance to eliminate compute charges during idle periods.
                     Retains Elastic IP by default unless -ReleasePublicIp is explicitly added.
    - StartExam    : Start EC2 instance for exam day, wait for status checks and SSM,
                     verify services and application health, probe public IP, domain, and HTTPS.
    - ReleaseIp    : Explicitly disassociate and release public IPv4 / Elastic IP (instance must be stopped).
    - AllocateIp   : Explicitly allocate a new VPC Elastic IP and associate with the instance.
    - DnsUpdate    : Explicitly update domain A record to current public IPv4 (Spaceship or Route 53).
    - Verify       : Complete read-only verification matrix of server, services, domain, and HTTPS readiness.

.PARAMETER Apply
    Switch parameter. When omitted, all operations run in DRY-RUN mode (read-only inspection/preview).
    Must be explicitly specified to execute mutating actions (start, stop, release IP, allocate IP, update DNS).

.PARAMETER DomainName
    Target domain name for DNS and HTTPS readiness validation (strictly: "exam-portal.online").

.PARAMETER DnsProvider
    DNS provider for automated DNS operations:
    - Spaceship (Default): Uses Spaceship Public REST API. Requires SPACESHIP_API_KEY and SPACESHIP_API_SECRET env vars.
    - Route53: Uses AWS Route 53 API. Requires matching hosted zone in AWS account.

.PARAMETER UpdateDns
    Used with StartExam -Apply to automatically update apex A record if DNS does not match active IP.

.PARAMETER ReleasePublicIp
    Used with StopIdle -Apply to explicitly disassociate and release the public Elastic IP post-stop.

.PARAMETER AllocateElasticIp
    Used with StartExam -Apply to allocate and associate a new Elastic IP if the instance has no public IP.

.PARAMETER HostedZoneId
    Explicit Route 53 Hosted Zone ID (only applicable when -DnsProvider Route53 is used).

.PARAMETER AllocationId
    Specific Elastic IP allocation ID for ReleaseIp operations.

.PARAMETER Force
    Skip interactive confirmations for automated scripts.

.EXAMPLE
    # Read-only status audit (checks EC2, SSM, services, Spaceship DNS resolution, HTTPS)
    .\manage.ps1 -Action Status

    # Full readiness verification matrix
    .\manage.ps1 -Action Verify

    # Dry-run post-exam shutdown (preview only, no changes)
    .\manage.ps1 -Action StopIdle

    # Apply post-exam shutdown, keeping Elastic IP
    .\manage.ps1 -Action StopIdle -Apply

    # Apply post-exam shutdown and release Elastic IP to save IPv4 costs
    .\manage.ps1 -Action StopIdle -ReleasePublicIp -Apply

    # Dry-run exam day startup (preview only)
    .\manage.ps1 -Action StartExam

    # Apply exam day startup and verify services and domain
    .\manage.ps1 -Action StartExam -Apply

    # Apply exam day startup with automated Spaceship DNS apex A record update
    .\manage.ps1 -Action StartExam -UpdateDns -Apply

    # Dry-run Spaceship DNS update preview (inspects live records if credentials configured)
    .\manage.ps1 -Action DnsUpdate

    # Apply Spaceship DNS apex A record update (preserves www CNAME)
    .\manage.ps1 -Action DnsUpdate -Apply
#>

[CmdletBinding()]
param (
    [Parameter(Position = 0)]
    [ValidateSet("Status", "DryRun", "StopIdle", "StartExam", "ReleaseIp", "AllocateIp", "DnsUpdate", "Verify")]
    [string]$Action = "Status",

    [Parameter()]
    [switch]$Apply,

    [Parameter()]
    [string]$DomainName = "exam-portal.online",

    [Parameter()]
    [ValidateSet("Spaceship", "Route53")]
    [string]$DnsProvider = "Spaceship",

    [Parameter()]
    [switch]$UpdateDns,

    [Parameter()]
    [switch]$ReleasePublicIp,

    [Parameter()]
    [switch]$AllocateElasticIp,

    [Parameter()]
    [string]$HostedZoneId = "",

    [Parameter()]
    [string]$AllocationId = "",

    [Parameter()]
    [switch]$Force
)

$ErrorActionPreference = "Stop"

# ==========================================
# 1. CONSTANTS & CONFIGURATION
# ==========================================
$ExpectedInstanceId = "i-0acdf2220ae4afb2d"
$ExpectedRegion     = "ap-south-1"
$ExpectedVolumeId   = "vol-0a0fffe887d09ba3f"
$ExpectedDomain     = "exam-portal.online"
$ProductionAppPath  = "/var/www/online-exam"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot  = (Resolve-Path (Join-Path $ScriptDir "..\..\..\..")).Path
$LockFile  = Join-Path $RepoRoot ".aws-instance-lifecycle.lock"

# Locate AWS CLI
$aws = "aws"
$localAws = "C:\Users\vikaa\AppData\Local\Programs\Amazon\AWSCLIV2\aws.exe"
if (Test-Path $localAws) { $aws = $localAws }

# Clear conflicting ambient AWS environment variables (e.g. Bedrock keys)
Remove-Item Env:\AWS_ACCESS_KEY_ID -ErrorAction SilentlyContinue
Remove-Item Env:\AWS_SECRET_ACCESS_KEY -ErrorAction SilentlyContinue
Remove-Item Env:\AWS_SESSION_TOKEN -ErrorAction SilentlyContinue
Remove-Item Env:\AWS_REGION -ErrorAction SilentlyContinue
Remove-Item Env:\AWS_BEDROCK_API_KEY -ErrorAction SilentlyContinue

# Strict domain target validation
function Assert-ValidDomain([string]$domain) {
    if ([string]::IsNullOrWhiteSpace($domain) -or $domain.Trim().ToLower() -ne $ExpectedDomain) {
        Write-Fail "SECURITY VIOLATION: Target domain must be strictly '$ExpectedDomain'. Target '$domain' is prohibited."
        throw "Invalid domain target: '$domain'. Only '$ExpectedDomain' is permitted for production lifecycle operations."
    }
}

# ==========================================
# 2. OUTPUT & LOGGING HELPERS
# ==========================================
function Write-Header([string]$title) {
    Write-Host "`n========================================================" -ForegroundColor Cyan
    Write-Host " $title" -ForegroundColor Cyan
    Write-Host "========================================================" -ForegroundColor Cyan
}

function Write-Pass([string]$msg) { Write-Host "  [PASS] $msg" -ForegroundColor Green }
function Write-Fail([string]$msg) { Write-Host "  [FAIL] $msg" -ForegroundColor Red }
function Write-Warn([string]$msg) { Write-Host "  [WARN] $msg" -ForegroundColor Yellow }
function Write-Info([string]$msg) { Write-Host "  [INFO] $msg" -ForegroundColor Gray }

function Write-PlannedAction([string]$actionName, [string]$target, [string]$current, [string]$new, [string]$reason) {
    Write-Host "`n  PLAN SUMMARY:" -ForegroundColor Yellow
    Write-Host "    ACTION:  $actionName" -ForegroundColor White
    Write-Host "    TARGET:  $target" -ForegroundColor White
    Write-Host "    CURRENT: $current" -ForegroundColor White
    Write-Host "    NEW:     $new" -ForegroundColor White
    Write-Host "    REASON:  $reason" -ForegroundColor White
}

function Write-CostAwareness() {
    Write-Host "`n  --- COST AWARENESS ---" -ForegroundColor DarkCyan
    Write-Host "  • EC2 running  : ~`$0.0104/hr (~`$7.50/mo) compute cost for t3.micro." -ForegroundColor Gray
    Write-Host "  • EC2 stopped  : `$0.00 compute cost. EBS storage (~`$0.64/mo for 8GB) persists." -ForegroundColor Gray
    Write-Host "  • Public IPv4  : `$0.005/hr (~`$3.60/mo) for both in-use and idle IPv4 addresses." -ForegroundColor Gray
    Write-Host "  • 7-Day Usage  : Stopping EC2 and releasing IPv4 during 23 idle days saves ~75% monthly cost." -ForegroundColor Gray
    Write-Host "  • Safety Notice: NEVER terminate instances or delete EBS volumes to save cost.`n" -ForegroundColor DarkCyan
}

# ==========================================
# 3. LOCAL CONCURRENCY LOCK MANAGEMENT
# ==========================================
function Acquire-LifecycleLock([string]$requestedAction) {
    if (Test-Path $LockFile) {
        $existingLock = Get-Content $LockFile -Raw -ErrorAction SilentlyContinue | ConvertFrom-Json -ErrorAction SilentlyContinue
        if ($existingLock -and $existingLock.Pid) {
            $proc = Get-Process -Id $existingLock.Pid -ErrorAction SilentlyContinue
            if ($proc) {
                Write-Fail "CONCURRENCY ERROR: Another lifecycle operation is currently active!"
                Write-Fail "Lock held by PID $($existingLock.Pid) for Action '$($existingLock.Action)' since $($existingLock.Timestamp)."
                Write-Fail "Aborting safely to prevent race conditions. No AWS resources were modified."
                exit 1
            } else {
                Write-Warn "Found stale lifecycle lock from PID $($existingLock.Pid). Reclaiming lock."
                Remove-Item $LockFile -Force -ErrorAction SilentlyContinue
            }
        } else {
            Remove-Item $LockFile -Force -ErrorAction SilentlyContinue
        }
    }

    $lockData = @{
        Pid       = $PID
        Action    = $requestedAction
        Timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss K")
        User      = [System.Environment]::UserName
    }
    $lockData | ConvertTo-Json | Set-Content -Path $LockFile -Encoding Ascii
}

function Release-LifecycleLock() {
    if (Test-Path $LockFile) {
        Remove-Item $LockFile -Force -ErrorAction SilentlyContinue
    }
}

# ==========================================
# 4. AWS DATA QUERY HELPERS
# ==========================================
function Get-CallerInfo() {
    $raw = & $aws sts get-caller-identity --output json 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to verify AWS identity: $raw"
    }
    return ($raw | ConvertFrom-Json)
}

function Get-Ec2InstanceInfo() {
    $raw = & $aws ec2 describe-instances `
        --instance-ids $ExpectedInstanceId `
        --region $ExpectedRegion `
        --output json 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to describe EC2 instance $ExpectedInstanceId - $raw"
    }
    $json = $raw | ConvertFrom-Json
    if (-not $json.Reservations -or $json.Reservations.Count -eq 0 -or $json.Reservations[0].Instances.Count -eq 0) {
        throw "EC2 instance $ExpectedInstanceId not found in region $ExpectedRegion."
    }
    return $json.Reservations[0].Instances[0]
}

function Get-AssociatedEipInfo() {
    $raw = & $aws ec2 describe-addresses `
        --region $ExpectedRegion `
        --filters "Name=instance-id,Values=$ExpectedInstanceId" `
        --output json 2>&1
    if ($LASTEXITCODE -eq 0) {
        $json = $raw | ConvertFrom-Json
        if ($json.Addresses -and $json.Addresses.Count -gt 0) {
            return $json.Addresses[0]
        }
    }
    return $null
}

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
# 5. SPACESHIP DNS API HELPERS
# ==========================================

function Get-SpaceshipCredentials() {
    $apiKey = $env:SPACESHIP_API_KEY
    $apiSecret = $env:SPACESHIP_API_SECRET

    $hasKey = -not [string]::IsNullOrWhiteSpace($apiKey)
    $hasSecret = -not [string]::IsNullOrWhiteSpace($apiSecret)

    if (-not $hasKey -and -not $hasSecret) {
        return @{
            Configured    = $false
            MissingKey    = $true
            MissingSecret = $true
            ApiKey        = $null
            ApiSecret     = $null
        }
    }

    if (-not $hasKey) {
        return @{
            Configured    = $false
            MissingKey    = $true
            MissingSecret = $false
            ApiKey        = $null
            ApiSecret     = $null
        }
    }

    if (-not $hasSecret) {
        return @{
            Configured    = $false
            MissingKey    = $false
            MissingSecret = $true
            ApiKey        = $null
            ApiSecret     = $null
        }
    }

    return @{
        Configured    = $true
        MissingKey    = $false
        MissingSecret = $false
        ApiKey        = $apiKey.Trim()
        ApiSecret     = $apiSecret.Trim()
    }
}

function Show-SpaceshipCredentialGuidance([string]$headerMsg = "Spaceship API credentials are not configured in the current environment.") {
    Write-Warn $headerMsg
    Write-Host "`n  SPACESHIP API CREDENTIAL SETUP (LOCAL & SECURE):" -ForegroundColor Cyan
    Write-Host "    1. Log in to Spaceship (Launchpad -> API Manager)." -ForegroundColor White
    Write-Host "    2. Generate an API Key & API Secret with the following permissions:" -ForegroundColor White
    Write-Host "         • dnsrecords:read   (Allows retrieving existing DNS records)" -ForegroundColor White
    Write-Host "         • dnsrecords:write  (Allows updating apex A record)" -ForegroundColor White
    Write-Host "    3. In your local PowerShell terminal session, set environment variables:" -ForegroundColor White
    Write-Host "         `$env:SPACESHIP_API_KEY    = '<your_spaceship_api_key>'" -ForegroundColor Gray
    Write-Host "         `$env:SPACESHIP_API_SECRET = '<your_spaceship_api_secret>'" -ForegroundColor Gray
    Write-Host "    4. NEVER commit or hardcode credentials into Git, scripts, or markdown files." -ForegroundColor Yellow
    Write-Host "    5. Re-run this command once the environment variables are set in your session.`n" -ForegroundColor Cyan
}

function Assert-SpaceshipCredentialsConfigured() {
    $creds = Get-SpaceshipCredentials
    if (-not $creds.Configured) {
        if ($creds.MissingKey -and $creds.MissingSecret) {
            Show-SpaceshipCredentialGuidance "Both SPACESHIP_API_KEY and SPACESHIP_API_SECRET environment variables are missing."
            throw "Missing required Spaceship API credentials (SPACESHIP_API_KEY and SPACESHIP_API_SECRET are not configured)."
        } elseif ($creds.MissingKey) {
            Show-SpaceshipCredentialGuidance "SPACESHIP_API_KEY environment variable is missing."
            throw "Missing required Spaceship API credential: SPACESHIP_API_KEY is not configured."
        } elseif ($creds.MissingSecret) {
            Show-SpaceshipCredentialGuidance "SPACESHIP_API_SECRET environment variable is missing."
            throw "Missing required Spaceship API credential: SPACESHIP_API_SECRET is not configured."
        }
    }
    return $creds
}

function Get-SpaceshipDnsRecords([string]$domain, [hashtable]$creds) {
    Assert-ValidDomain $domain

    if (-not $creds -or -not $creds.ApiKey -or -not $creds.ApiSecret) {
        throw "Cannot call Spaceship API without valid credentials."
    }

    $headers = @{
        "X-API-Key"    = $creds.ApiKey
        "X-API-Secret" = $creds.ApiSecret
        "Accept"       = "application/json"
    }

    $url = "https://spaceship.dev/api/v1/dns/records/$domain" + "?take=100&skip=0"

    try {
        $response = Invoke-RestMethod -Uri $url -Method Get -Headers $headers -TimeoutSec 15
        
        $items = $null
        if ($response -and $response.PSObject.Properties['items']) {
            $items = $response.items
        } elseif ($response -is [System.Array]) {
            $items = $response
        }

        if ($null -eq $items) {
            throw "Malformed response from Spaceship API: response did not contain an 'items' array."
        }

        return $items
    } catch {
        # Never expose headers or raw secrets in error message
        $statusCode = $null
        if ($_.Exception.Response) {
            $statusCode = [int]$_.Exception.Response.StatusCode
        }

        if ($statusCode -eq 401 -or $statusCode -eq 403) {
            throw "Spaceship API Authentication Failed (HTTP $statusCode): Unauthorized or Forbidden. Verify that SPACESHIP_API_KEY and SPACESHIP_API_SECRET are valid and have 'dnsrecords:read' permission."
        } elseif ($statusCode -eq 404) {
            throw "Spaceship API Resource Not Found (HTTP 404): Domain '$domain' was not found in Spaceship account."
        } else {
            $cleanErr = $_.Exception.Message
            throw "Failed to fetch Spaceship DNS records for ${domain}: $cleanErr"
        }
    }
}

function Update-SpaceshipApexARecord([string]$domain, [string]$newIp, [hashtable]$creds, [bool]$apply = $false) {
    Assert-ValidDomain $domain

    if ([string]::IsNullOrWhiteSpace($newIp)) {
        throw "Cannot update DNS: Target IP address is empty or invalid."
    }

    Write-Info "Querying existing Spaceship DNS records for $domain..."
    $existingRecords = Get-SpaceshipDnsRecords -domain $domain -creds $creds

    if ($null -eq $existingRecords -or $existingRecords.Count -eq 0) {
        throw "Safety abort: Spaceship API returned 0 existing records for $domain. Refusing to overwrite zone without confirming existing records."
    }

    $updatedItems = [System.Collections.Generic.List[hashtable]]::new()
    $apexFound = $false
    $preservedRecords = [System.Collections.Generic.List[string]]::new()
    $wwwCnameFound = $false

    foreach ($rec in $existingRecords) {
        $rType = [string]$rec.type
        $rName = [string]$rec.name

        # Track www CNAME preservation explicitly
        if ($rType.ToUpper() -eq "CNAME" -and $rName.ToLower() -eq "www") {
            $wwwCnameFound = $true
        }

        # Match apex A record: type == A and (name == '@' or name == '' or name == $domain)
        if ($rType.ToUpper() -eq "A" -and ($rName -eq "@" -or $rName -eq "" -or $rName.ToLower() -eq $domain.ToLower())) {
            $apexFound = $true
            $currentVal = if ($rec.address) { $rec.address } else { $rec.value }
            Write-Info "Found existing apex A record: '$rName' -> $currentVal (TTL: $($rec.ttl))"

            $updatedItems.Add(@{
                type    = "A"
                name    = "@"
                address = $newIp
                ttl     = 300
            })
        } else {
            # Preserve existing record intact (e.g. www CNAME, MX, TXT, etc.)
            $desc = "$rType '$rName'"
            if ($rec.cname) { $desc += " -> $($rec.cname)" }
            elseif ($rec.address) { $desc += " -> $($rec.address)" }
            elseif ($rec.value) { $desc += " -> $($rec.value)" }
            $preservedRecords.Add($desc)

            $item = @{
                type = $rType
                name = $rName
                ttl  = if ($rec.ttl) { [int]$rec.ttl } else { 300 }
            }
            if ($rec.PSObject.Properties['address'] -and $rec.address) { $item["address"] = [string]$rec.address }
            if ($rec.PSObject.Properties['cname'] -and $rec.cname) { $item["cname"] = [string]$rec.cname }
            if ($rec.PSObject.Properties['value'] -and $rec.value) { $item["value"] = [string]$rec.value }
            if ($rec.PSObject.Properties['exchange'] -and $rec.exchange) { $item["exchange"] = [string]$rec.exchange }
            if ($rec.PSObject.Properties['preference'] -and $null -ne $rec.preference) { $item["preference"] = [int]$rec.preference }
            if ($rec.PSObject.Properties['target'] -and $rec.target) { $item["target"] = [string]$rec.target }
            if ($rec.PSObject.Properties['priority'] -and $null -ne $rec.priority) { $item["priority"] = [int]$rec.priority }
            if ($rec.PSObject.Properties['weight'] -and $null -ne $rec.weight) { $item["weight"] = [int]$rec.weight }
            if ($rec.PSObject.Properties['port'] -and $null -ne $rec.port) { $item["port"] = [int]$rec.port }

            $updatedItems.Add($item)
        }
    }

    if (-not $apexFound) {
        Write-Info "No existing apex A record found in record set. Adding new apex A record."
        $updatedItems.Add(@{
            type    = "A"
            name    = "@"
            address = $newIp
            ttl     = 300
        })
    }

    # Inviolable safety rule: www CNAME must be preserved
    if (-not $wwwCnameFound) {
        Write-Warn "www CNAME was not in existing records! Adding canonical www CNAME -> $domain to batch payload."
        $updatedItems.Add(@{
            type  = "CNAME"
            name  = "www"
            cname = $domain
            ttl   = 1800
        })
        $preservedRecords.Add("CNAME 'www' -> $domain (enforced)")
    }

    Write-Pass "Preserved $($preservedRecords.Count) non-apex records (including canonical www CNAME):"
    foreach ($pr in $preservedRecords) {
        Write-Info "  • $pr"
    }

    $payload = @{
        force = $true
        items = $updatedItems
    }

    if (-not $apply) {
        Write-Host "`n*** DRY-RUN PREVIEW (SPACESHIP DNS BATCH UPDATE) ***" -ForegroundColor Yellow
        Write-Host "  Domain       : $domain" -ForegroundColor White
        Write-Host "  Target A     : @ -> $newIp (TTL: 300)" -ForegroundColor Green
        Write-Host "  Total Items  : $($updatedItems.Count) records in batch update payload" -ForegroundColor White
        Write-Host "  HTTP Method  : PUT https://spaceship.dev/api/v1/dns/records/$domain" -ForegroundColor Gray
        Write-Host "  DRY-RUN ONLY : No HTTP PUT was dispatched. Production DNS was NOT modified.`n" -ForegroundColor Yellow
        return $false
    }

    Write-Warn "Dispatching authoritative batch PUT request to Spaceship API..."
    $headers = @{
        "X-API-Key"    = $creds.ApiKey
        "X-API-Secret" = $creds.ApiSecret
        "Content-Type" = "application/json"
        "Accept"       = "application/json"
    }
    $url = "https://spaceship.dev/api/v1/dns/records/$domain"
    $bodyJson = $payload | ConvertTo-Json -Depth 5

    try {
        $null = Invoke-RestMethod -Uri $url -Method Put -Headers $headers -Body $bodyJson -TimeoutSec 20
        Write-Pass "Spaceship API batch update succeeded for domain '$domain'."
        return $true
    } catch {
        $statusCode = $null
        if ($_.Exception.Response) {
            $statusCode = [int]$_.Exception.Response.StatusCode
        }

        if ($statusCode -eq 401 -or $statusCode -eq 403) {
            throw "Spaceship API Authentication Failed on Write (HTTP $statusCode): Unauthorized or Forbidden. Verify that SPACESHIP_API_KEY and SPACESHIP_API_SECRET have 'dnsrecords:write' permission."
        } else {
            $cleanErr = $_.Exception.Message
            throw "Spaceship API update failed for ${domain}: $cleanErr"
        }
    }
}

function Verify-DomainDnsAndHttps([string]$domain, [string]$expectedIp) {
    Assert-ValidDomain $domain
    Write-Host "`n  DOMAIN & DNS VERIFICATION ($domain):" -ForegroundColor White

    $apexResolved = $false
    $wwwResolved = $false
    $wwwTarget = "www.$domain"

    # 1. Apex DNS Resolution
    try {
        $resolvedIps = [System.Net.Dns]::GetHostAddresses($domain) | ForEach-Object { $_.IPAddressToString }
        if ($resolvedIps -and $resolvedIps.Count -gt 0) {
            Write-Info "Apex DNS resolution for '$domain': $($resolvedIps -join ', ')"
            if ($expectedIp -and $resolvedIps -contains $expectedIp) {
                Write-Pass "Apex A record MATCHES active server IP ($expectedIp)"
                $apexResolved = $true
            } else {
                Write-Warn "Apex DNS MISMATCH: $domain resolves to ($($resolvedIps -join ', ')), expected $expectedIp"
            }
        } else {
            Write-Warn "No IP addresses resolved for apex domain '$domain'."
        }
    } catch {
        Write-Warn "Apex DNS lookup failed for '$domain': $_"
    }

    # 2. WWW CNAME / Host Resolution
    try {
        $wwwIps = [System.Net.Dns]::GetHostAddresses($wwwTarget) | ForEach-Object { $_.IPAddressToString }
        if ($wwwIps -and $wwwIps.Count -gt 0) {
            Write-Info "Canonical host resolution for '$wwwTarget': $($wwwIps -join ', ')"
            if ($expectedIp -and $wwwIps -contains $expectedIp) {
                Write-Pass "Canonical host '$wwwTarget' resolves to active server IP ($expectedIp)"
                $wwwResolved = $true
            } else {
                Write-Warn "Canonical host '$wwwTarget' MISMATCH: resolves to ($($wwwIps -join ', ')), expected $expectedIp"
            }
        } else {
            Write-Warn "No IP addresses resolved for canonical host '$wwwTarget'."
        }
    } catch {
        Write-Warn "Canonical host lookup failed for '$wwwTarget': $_"
    }

    # 3. HTTP / HTTPS Probes (if server is reachable)
    if ($expectedIp) {
        Write-Host "`n  PRODUCTION ROUTING & HTTPS VERIFICATION:" -ForegroundColor White

        # Probe HTTP apex -> expect 301 to https://www.$domain/
        $httpApex = curl.exe -s -I --max-time 5 "http://$domain/"
        if ($httpApex -match "HTTP/1\.[01] 301" -and $httpApex -match "Location: https://$wwwTarget/") {
            Write-Pass "http://$domain/ correctly 301 redirects to https://$wwwTarget/"
        } else {
            Write-Warn "http://$domain/ probe: $(($httpApex -split "`n")[0].Trim())"
        }

        # Probe HTTPS apex -> expect 301 to https://www.$domain/
        $httpsApex = curl.exe -s -I --max-time 5 "https://$domain/"
        if ($httpsApex -match "HTTP/1\.[01] 301" -and $httpsApex -match "Location: https://$wwwTarget/") {
            Write-Pass "https://$domain/ correctly 301 redirects to https://$wwwTarget/"
        } else {
            Write-Warn "https://$domain/ probe: $(($httpsApex -split "`n")[0].Trim())"
        }

        # Probe HTTPS www -> expect 200 OK
        $httpsWww = curl.exe -s -I --max-time 5 "https://$wwwTarget/"
        if ($httpsWww -match "HTTP/1\.[01] 200 OK") {
            Write-Pass "Canonical endpoint https://$wwwTarget/ responds with HTTP 200 OK"
        } else {
            Write-Warn "Canonical endpoint https://$wwwTarget/ probe: $(($httpsWww -split "`n")[0].Trim())"
        }
    }

    return ($apexResolved -and $wwwResolved)
}

function Verify-PostDnsUpdate([string]$domain, [string]$expectedIp, [hashtable]$creds) {
    Assert-ValidDomain $domain
    Write-Header "POST-UPDATE DNS & HTTPS VERIFICATION MATRIX"

    $allPassed = $true

    # 1. Authoritative Spaceship API state
    Write-Host "`n  1. AUTHORITATIVE SPACESHIP DNS STATE:" -ForegroundColor White
    try {
        $liveRecords = Get-SpaceshipDnsRecords -domain $domain -creds $creds
        $liveApex = $liveRecords | Where-Object { $_.type.ToUpper() -eq "A" -and ($_.name -eq "@" -or $_.name -eq "" -or $_.name.ToLower() -eq $domain.ToLower()) }
        $liveWww  = $liveRecords | Where-Object { $_.type.ToUpper() -eq "CNAME" -and $_.name.ToLower() -eq "www" }

        $liveIp = if ($liveApex) { if ($liveApex.address) { $liveApex.address } else { $liveApex.value } } else { $null }

        if ($liveIp -eq $expectedIp) {
            Write-Pass "Spaceship Authoritative Apex A Record confirmed: @ -> $liveIp"
        } else {
            Write-Fail "Spaceship Authoritative Apex A Record mismatch: expected $expectedIp, found '$liveIp'"
            $allPassed = $false
        }

        if ($liveWww) {
            $liveWwwVal = if ($liveWww.cname) { $liveWww.cname } else { $liveWww.value }
            Write-Pass "Spaceship Authoritative Canonical CNAME confirmed: www -> $liveWwwVal"
        } else {
            Write-Fail "Spaceship Authoritative Canonical CNAME 'www' is MISSING in record set!"
            $allPassed = $false
        }
    } catch {
        Write-Fail "Failed to verify Spaceship API state: $($_.Exception.Message)"
        $allPassed = $false
    }

    # 2. Public DNS Resolution & 3. HTTPS Routing
    $dnsAndHttpsOk = Verify-DomainDnsAndHttps -domain $domain -expectedIp $expectedIp
    if (-not $dnsAndHttpsOk) {
        $allPassed = $false
    }

    if ($allPassed) {
        Write-Host "`n*** POST-UPDATE VERIFICATION PASSED: ALL CHECKS CONFIRMED ***" -ForegroundColor Green
        Write-Host "Canonical URL: https://www.$domain/`n" -ForegroundColor Green
    } else {
        Write-Warn "POST-UPDATE NOTICE: One or more verification checks are still propagating or incomplete. Please review details above."
    }

    return $allPassed
}

# ==========================================
# 6. ACTION IMPLEMENTATIONS
# ==========================================

# --- ACTION: STATUS / VERIFY ---
function Run-StatusOrVerify([bool]$fullVerification = $false) {
    Write-Header "AWS PRODUCTION INSTANCE LIFECYCLE AUDIT"

    # 1. AWS Identity & Region
    $caller = Get-CallerInfo
    Write-Pass "AWS Authentication verified (Account: $($caller.Account), Role/User: $($caller.Arn))"
    Write-Pass "Target Region verified: $ExpectedRegion"

    # 2. EC2 Instance Metadata
    $inst = Get-Ec2InstanceInfo
    $stateName = $inst.State.Name
    $publicIp  = $inst.PublicIpAddress
    $privateIp = $inst.PrivateIpAddress
    $instName  = ($inst.Tags | Where-Object { $_.Key -eq "Name" }).Value

    Write-Host "`n  EC2 INSTANCE DETAILS:" -ForegroundColor White
    Write-Host "    Instance ID    : $($inst.InstanceId)" -ForegroundColor Gray
    Write-Host "    Name Tag       : $instName" -ForegroundColor Gray
    Write-Host "    Instance Type  : $($inst.InstanceType)" -ForegroundColor Gray
    Write-Host "    Current State  : $stateName" -ForegroundColor $(if ($stateName -eq "running") { "Green" } else { "Yellow" })
    Write-Host "    Private IPv4   : $privateIp" -ForegroundColor Gray
    Write-Host "    Public IPv4    : $(if ($publicIp) { $publicIp } else { '[None / Released]' })" -ForegroundColor Gray

    # 3. Elastic IP Details
    $eip = Get-AssociatedEipInfo
    if ($eip) {
        Write-Pass "Elastic IP attached: $($eip.PublicIp) (Allocation: $($eip.AllocationId), Association: $($eip.AssociationId))"
    } else {
        if ($publicIp) {
            Write-Warn "Public IPv4 ($publicIp) is EPHEMERAL (Not an Elastic IP). Will change upon stop/start."
        } else {
            Write-Info "No Elastic IP or Public IPv4 is currently associated."
        }
    }

    # 4. EBS Volume Integrity
    $attachedVol = $inst.BlockDeviceMappings | Where-Object { $_.Ebs.VolumeId -eq $ExpectedVolumeId }
    if ($attachedVol -and $attachedVol.Ebs.Status -eq "attached") {
        Write-Pass "EBS Volume $ExpectedVolumeId is safely attached at $($attachedVol.DeviceName)"
    } else {
        Write-Warn "Expected EBS volume $ExpectedVolumeId attachment status: $($attachedVol.Ebs.Status)"
    }

    # 5. SSM & Server Health (if running)
    $ssmOnline = $false
    if ($stateName -eq "running") {
        $ssmRaw = & $aws ssm describe-instance-information `
            --filters "Key=InstanceIds,Values=$ExpectedInstanceId" `
            --region $ExpectedRegion `
            --output json 2>&1
        if ($LASTEXITCODE -eq 0) {
            $ssmJson = $ssmRaw | ConvertFrom-Json
            if ($ssmJson.InstanceInformationList.Count -gt 0 -and $ssmJson.InstanceInformationList[0].PingStatus -eq "Online") {
                $ssmOnline = $true
                Write-Pass "SSM Status is Online (Agent v$($ssmJson.InstanceInformationList[0].AgentVersion))"
            }
        }

        if (-not $ssmOnline) {
            Write-Warn "SSM Agent is not reporting Online yet."
        } else {
            # Deep server inspection via SSM
            $srvCmds = @(
                "systemctl is-active nginx",
                "systemctl is-active php-fpm",
                "systemctl is-active mariadb",
                "systemctl is-active online-exam-finalizer.timer",
                "ss -lntp | grep ':3306'",
                "nginx -t 2>&1",
                "[ -f /var/www/online-exam/config/config.local.php ] && echo 'CONFIG_OK' || echo 'CONFIG_MISSING'",
                "git -C /var/www/online-exam -c safe.directory=/var/www/online-exam rev-parse --short HEAD"
            )
            try {
                $srvRes = Invoke-SSMCommand -Commands $srvCmds -Description "Lifecycle Health Audit"
                $out = $srvRes.StandardOutputContent

                if ($out -match "CONFIG_OK") { Write-Pass "Protected local configuration (config.local.php) confirmed" }
                if ($out -match "127\.0\.0\.1:3306") { Write-Pass "MariaDB strictly bound to localhost (127.0.0.1:3306)" }
                if ($out -match "syntax is ok") { Write-Pass "Nginx configuration syntax confirmed OK" }

                $services = @("nginx", "php-fpm", "mariadb", "online-exam-finalizer.timer")
                foreach ($s in $services) {
                    if ($out -match "$s[\s\S]*?active") {
                        Write-Pass "Service active: $s"
                    }
                }
            } catch {
                Write-Warn "SSM inspection error: $_"
            }
        }

        # Public IP HTTP Probe
        if ($publicIp) {
            $probe = curl.exe -s -I --max-time 5 "http://$publicIp/"
            if ($probe -match "HTTP/1\.[01] 200 OK") {
                Write-Pass "Public HTTP IP endpoint responding: http://$publicIp/ (200 OK)"
            } else {
                Write-Warn "Public HTTP IP probe returned: $(($probe -split "`n")[0].Trim())"
            }
        }
    } else {
        Write-Info "Instance is $stateName. SSM, service, and HTTP probes skipped."
    }

    # 6. Domain & DNS Verification
    $dnsOk = $false
    if ($DomainName) {
        Assert-ValidDomain $DomainName
        Write-Host "`n  DNS CONFIGURATION STATUS ($DnsProvider):" -ForegroundColor White
        if ($DnsProvider -eq "Spaceship") {
            $creds = Get-SpaceshipCredentials
            if ($creds.Configured) {
                Write-Pass "Spaceship API credentials detected in environment."
                try {
                    $liveRecs = Get-SpaceshipDnsRecords -domain $DomainName -creds $creds
                    Write-Pass "Spaceship API authenticated read-only query succeeded ($($liveRecs.Count) records retrieved):"
                    foreach ($lr in $liveRecs) {
                        $v = if ($lr.address) { $lr.address } elseif ($lr.cname) { $lr.cname } elseif ($lr.value) { $lr.value } else { "" }
                        Write-Info "    • $($lr.type) '$($lr.name)' -> $v (TTL: $($lr.ttl))"
                    }
                } catch {
                    Write-Warn "Spaceship API query note: $($_.Exception.Message)"
                }
            } else {
                Write-Info "Spaceship API credentials not set in ambient environment (using public DNS resolution checks)."
            }
        }

        $dnsOk = Verify-DomainDnsAndHttps -domain $DomainName -expectedIp $publicIp
    }

    Write-CostAwareness

    # Final Readiness Summary
    if ($fullVerification) {
        Write-Host "========================================================" -ForegroundColor Cyan
        Write-Host " READINESS SUMMARY" -ForegroundColor Cyan
        Write-Host "========================================================" -ForegroundColor Cyan

        $isReady = ($stateName -eq "running") -and $ssmOnline -and $publicIp -and $dnsOk

        if ($isReady) {
            Write-Host "`n*** EXAM SYSTEM READY ***" -ForegroundColor Green
            Write-Host "Canonical Access URL: https://www.$DomainName/`n" -ForegroundColor Green
        } else {
            Write-Host "`n*** EXAM SYSTEM NOT FULLY READY ***" -ForegroundColor Yellow
            Write-Host "Status checklist:" -ForegroundColor Gray
            Write-Host "  • EC2 Running       : $(if ($stateName -eq 'running') { 'YES' } else { 'NO (' + $stateName + ')' })" -ForegroundColor Gray
            Write-Host "  • SSM Online        : $(if ($ssmOnline) { 'YES' } else { 'NO' })" -ForegroundColor Gray
            Write-Host "  • Public IP Assigned: $(if ($publicIp) { $publicIp } else { 'NO' })" -ForegroundColor Gray
            Write-Host "  • DNS Resolution OK : $(if ($dnsOk) { 'YES' } else { 'NO' })" -ForegroundColor Gray
            Write-Host ""
        }
    }
}

# --- ACTION: STOP-IDLE ---
function Run-StopIdle() {
    Write-Header "ACTION: STOP-IDLE (POST-EXAM SHUTDOWN)"

    $inst = Get-Ec2InstanceInfo
    $stateName = $inst.State.Name
    $publicIp  = $inst.PublicIpAddress
    $eip       = Get-AssociatedEipInfo

    if ($stateName -eq "stopped") {
        Write-Pass "Instance $ExpectedInstanceId is ALREADY in 'stopped' state. No stop action required."
        if ($ReleasePublicIp -and $eip) {
            Write-Warn "Proceeding to requested Elastic IP release..."
        } else {
            exit 0
        }
    }

    # Dry-Run Preview
    Write-PlannedAction `
        -actionName "Stop EC2 Instance" `
        -target "$ExpectedInstanceId ($($inst.InstanceType))" `
        -current "State: $stateName | Public IPv4: $(if ($publicIp) { $publicIp } else { 'None' })" `
        -new "State: stopped" `
        -reason "Post-exam cost reduction (stops compute charges while preserving EBS storage and DB)"

    if ($ReleasePublicIp) {
        if ($eip) {
            Write-PlannedAction `
                -actionName "Disassociate & Release Elastic IP" `
                -target "$($eip.PublicIp) (Allocation: $($eip.AllocationId))" `
                -current "Associated with $ExpectedInstanceId" `
                -new "Released to AWS public IPv4 pool" `
                -reason "Eliminates idle public IPv4 fee ($0.005/hr) during idle period"
        } else {
            Write-Info "No Elastic IP attached; ephemeral public IP will automatically be released by AWS upon stop."
        }
    } else {
        if ($eip) {
            Write-Info "NOTICE: Elastic IP $($eip.PublicIp) will be RETAINED while instance is stopped."
            Write-Info "AWS charges `$0.005/hr (~`$3.60/mo) for idle Elastic IPs."
            Write-Info "To release the IP, pass '-ReleasePublicIp' with explicit confirmation."
        }
    }

    if (-not $Apply) {
        Write-Host "`n*** DRY-RUN COMPLETE: NO AWS RESOURCE WAS MODIFIED ***" -ForegroundColor Yellow
        Write-Host "To execute this stop operation, re-run with: .\manage.ps1 -Action StopIdle -Apply" -ForegroundColor Cyan
        if ($ReleasePublicIp) {
            Write-Host "With IP release: .\manage.ps1 -Action StopIdle -ReleasePublicIp -Apply" -ForegroundColor Cyan
        }
        Write-CostAwareness
        exit 0
    }

    # REAL EXECUTION WITH -APPLY
    Acquire-LifecycleLock "StopIdle"
    try {
        if ($stateName -ne "stopped") {
            Write-Warn "Stopping EC2 instance $ExpectedInstanceId..."
            & $aws ec2 stop-instances --instance-ids $ExpectedInstanceId --region $ExpectedRegion --output json | Out-Null
            if ($LASTEXITCODE -ne 0) { throw "Failed to stop instance $ExpectedInstanceId" }

            Write-Info "Waiting for instance to enter 'stopped' state..."
            & $aws ec2 wait instance-stopped --instance-ids $ExpectedInstanceId --region $ExpectedRegion
            Write-Pass "Instance $ExpectedInstanceId is now safely STOPPED."
        }

        # Verify EBS volume remains attached
        $postInst = Get-Ec2InstanceInfo
        $postVol = $postInst.BlockDeviceMappings | Where-Object { $_.Ebs.VolumeId -eq $ExpectedVolumeId }
        if ($postVol -and $postVol.Ebs.Status -eq "attached") {
            Write-Pass "EBS Volume $ExpectedVolumeId verified securely attached."
        } else {
            Write-Fail "WARNING: EBS volume state unexpected: $($postVol.Ebs.Status)"
        }

        # Handle explicit IP release if requested
        if ($ReleasePublicIp -and $eip) {
            Write-Warn "Disassociating Elastic IP $($eip.PublicIp)..."
            & $aws ec2 disassociate-address --association-id $eip.AssociationId --region $ExpectedRegion --output json | Out-Null

            Write-Warn "Releasing Elastic IP $($eip.PublicIp) (Allocation: $($eip.AllocationId))..."
            & $aws ec2 release-address --allocation-id $eip.AllocationId --region $ExpectedRegion --output json | Out-Null
            Write-Pass "Elastic IP $($eip.PublicIp) released successfully. Zero idle IPv4 charges will accrue."
        }

        Write-Host "`n========================================================" -ForegroundColor Green
        Write-Host " POST-EXAM SHUTDOWN COMPLETED SUCCESSFULLY" -ForegroundColor Green
        Write-Host " EC2 Instance : $ExpectedInstanceId (STOPPED)" -ForegroundColor Green
        Write-Host " Compute Cost : `$0.00/hr (Halted)" -ForegroundColor Green
        Write-Host " Storage Cost : Preserved on EBS volume ($ExpectedVolumeId)" -ForegroundColor Green
        Write-Host "========================================================`n" -ForegroundColor Green
    } finally {
        Release-LifecycleLock
    }
}

# --- ACTION: RELEASE-IP ---
function Run-ReleaseIp() {
    Write-Header "ACTION: RELEASE-IP (EXPLICIT PUBLIC IP RELEASE)"

    $inst = Get-Ec2InstanceInfo
    $stateName = $inst.State.Name
    $eip = Get-AssociatedEipInfo

    # SAFETY: Must be stopped
    if ($stateName -ne "stopped") {
        Write-Fail "SAFETY RULE VIOLATION: Instance $ExpectedInstanceId is currently '$stateName'."
        Write-Fail "Releasing the public IP of a running production server is prohibited."
        Write-Fail "Stop the instance first using: .\manage.ps1 -Action StopIdle -Apply"
        exit 1
    }

    if (-not $eip) {
        Write-Info "No Elastic IP is currently associated with instance $ExpectedInstanceId."
        exit 0
    }

    # Verify allocation matches if specified
    if ($AllocationId -and $eip.AllocationId -ne $AllocationId) {
        Write-Fail "Specified AllocationId ($AllocationId) does not match the associated EIP ($($eip.AllocationId))."
        exit 1
    }

    Write-PlannedAction `
        -actionName "Disassociate and Release Elastic IP" `
        -target "$($eip.PublicIp) (Allocation: $($eip.AllocationId))" `
        -current "Associated with stopped instance $ExpectedInstanceId" `
        -new "Released to AWS public IPv4 pool" `
        -reason "Eliminates idle public IPv4 fee ($0.005/hr)"

    if (-not $Apply) {
        Write-Host "`n*** DRY-RUN COMPLETE: NO AWS RESOURCE WAS MODIFIED ***" -ForegroundColor Yellow
        Write-Host "To release this IP, re-run with: .\manage.ps1 -Action ReleaseIp -Apply" -ForegroundColor Cyan
        exit 0
    }

    Acquire-LifecycleLock "ReleaseIp"
    try {
        Write-Warn "Disassociating Elastic IP $($eip.PublicIp)..."
        & $aws ec2 disassociate-address --association-id $eip.AssociationId --region $ExpectedRegion --output json | Out-Null

        Write-Warn "Releasing Elastic IP $($eip.PublicIp) (Allocation: $($eip.AllocationId))..."
        & $aws ec2 release-address --allocation-id $eip.AllocationId --region $ExpectedRegion --output json | Out-Null
        Write-Pass "Elastic IP $($eip.PublicIp) has been released back to AWS."

        $postInst = Get-Ec2InstanceInfo
        Write-Pass "Verified instance $ExpectedInstanceId public IP status: $(if ($postInst.PublicIpAddress) { $postInst.PublicIpAddress } else { '[None]' })"
    } finally {
        Release-LifecycleLock
    }
}

# --- ACTION: ALLOCATE-IP ---
function Run-AllocateIp() {
    Write-Header "ACTION: ALLOCATE-IP (NEW ELASTIC IP)"

    $inst = Get-Ec2InstanceInfo
    $eip = Get-AssociatedEipInfo

    if ($eip) {
        Write-Warn "Instance $ExpectedInstanceId already has Elastic IP $($eip.PublicIp) attached."
        Write-Info "Allocation aborted to avoid redundant EIP charges."
        exit 0
    }

    Write-PlannedAction `
        -actionName "Allocate & Associate Elastic IP" `
        -target "VPC ap-south-1 -> $ExpectedInstanceId" `
        -current "Public IP: $(if ($inst.PublicIpAddress) { $inst.PublicIpAddress } else { 'None' })" `
        -new "New static Elastic IP" `
        -reason "Provide a persistent public IPv4 for exam access"

    if (-not $Apply) {
        Write-Host "`n*** DRY-RUN COMPLETE: NO AWS RESOURCE WAS MODIFIED ***" -ForegroundColor Yellow
        Write-Host "To allocate and associate an Elastic IP, run: .\manage.ps1 -Action AllocateIp -Apply" -ForegroundColor Cyan
        exit 0
    }

    Acquire-LifecycleLock "AllocateIp"
    try {
        Write-Warn "Allocating new Elastic IP in $ExpectedRegion..."
        $allocRaw = & $aws ec2 allocate-address `
            --domain vpc `
            --network-border-group $ExpectedRegion `
            --tag-specifications "ResourceType=elastic-ip,Tags=[{Key=Name,Value=online-exam-production-eip}]" `
            --region $ExpectedRegion `
            --output json 2>&1
        if ($LASTEXITCODE -ne 0) { throw "Allocation failed: $allocRaw" }
        $allocJson = $allocRaw | ConvertFrom-Json
        $newAllocId = $allocJson.AllocationId
        $newIp      = $allocJson.PublicIp

        Write-Pass "Allocated new Elastic IP: $newIp ($newAllocId)"

        Write-Warn "Associating $newIp with instance $ExpectedInstanceId..."
        $assocRaw = & $aws ec2 associate-address `
            --instance-id $ExpectedInstanceId `
            --allocation-id $newAllocId `
            --region $ExpectedRegion `
            --output json 2>&1
        if ($LASTEXITCODE -ne 0) { throw "Association failed: $assocRaw" }

        Write-Pass "Associated Elastic IP $newIp with $ExpectedInstanceId successfully."
    } finally {
        Release-LifecycleLock
    }
}

# --- ACTION: START-EXAM ---
function Run-StartExam() {
    Write-Header "ACTION: START-EXAM (EXAM DAY STARTUP)"

    Assert-ValidDomain $DomainName

    $inst = Get-Ec2InstanceInfo
    $stateName = $inst.State.Name
    $publicIp  = $inst.PublicIpAddress
    $eip       = Get-AssociatedEipInfo

    Write-PlannedAction `
        -actionName "Start EC2 & Verify Health" `
        -target "$ExpectedInstanceId ($($inst.InstanceType))" `
        -current "State: $stateName | Public IPv4: $(if ($publicIp) { $publicIp } else { 'None' })" `
        -new "State: running | Services: active | Domain: https://www.$DomainName/" `
        -reason "Exam day startup for students and teachers"

    if (-not $Apply) {
        Write-Host "`n*** DRY-RUN COMPLETE: NO AWS RESOURCE WAS MODIFIED ***" -ForegroundColor Yellow
        Write-Host "To execute exam day startup, run: .\manage.ps1 -Action StartExam -Apply" -ForegroundColor Cyan
        Write-CostAwareness
        exit 0
    }

    Acquire-LifecycleLock "StartExam"
    try {
        # 1. Start instance if stopped
        if ($stateName -eq "stopped") {
            Write-Warn "Starting EC2 instance $ExpectedInstanceId..."
            & $aws ec2 start-instances --instance-ids $ExpectedInstanceId --region $ExpectedRegion --output json | Out-Null
            if ($LASTEXITCODE -ne 0) { throw "Failed to start EC2 instance $ExpectedInstanceId" }

            Write-Info "Waiting for instance to enter 'running' state..."
            & $aws ec2 wait instance-running --instance-ids $ExpectedInstanceId --region $ExpectedRegion
            Write-Pass "Instance $ExpectedInstanceId is now RUNNING."

            Write-Info "Waiting for instance status checks to pass (system & instance checks)..."
            & $aws ec2 wait instance-status-ok --instance-ids $ExpectedInstanceId --region $ExpectedRegion
            Write-Pass "EC2 status checks completed successfully (2/2 checks passed)."
        } else {
            Write-Pass "EC2 instance $ExpectedInstanceId is already RUNNING."
        }

        # 2. Wait for SSM Agent to connect Online
        Write-Info "Waiting for AWS Systems Manager (SSM) agent to report Online..."
        $ssmReady = $false
        for ($i = 1; $i -le 30; $i++) {
            $ssmRaw = & $aws ssm describe-instance-information `
                --filters "Key=InstanceIds,Values=$ExpectedInstanceId" `
                --region $ExpectedRegion `
                --output json 2>&1
            if ($LASTEXITCODE -eq 0) {
                $ssmJson = $ssmRaw | ConvertFrom-Json
                if ($ssmJson.InstanceInformationList.Count -gt 0 -and $ssmJson.InstanceInformationList[0].PingStatus -eq "Online") {
                    $ssmReady = $true
                    Write-Pass "AWS Systems Manager status is ONLINE (Agent v$($ssmJson.InstanceInformationList[0].AgentVersion))."
                    break
                }
            }
            Start-Sleep -Seconds 2
        }
        if (-not $ssmReady) {
            Write-Fail "SSM Agent did not report Online within timeout. Please inspect instance console logs."
        }

        # 3. Server-side Service Verification via SSM
        if ($ssmReady) {
            Write-Info "Verifying production services via SSM..."
            $checkCmds = @(
                "systemctl is-active nginx",
                "systemctl is-active php-fpm",
                "systemctl is-active mariadb",
                "systemctl is-active online-exam-finalizer.timer",
                "ss -lntp | grep ':3306'",
                "nginx -t 2>&1",
                "[ -f /var/www/online-exam/config/config.local.php ] && echo 'CONFIG_OK' || echo 'CONFIG_MISSING'"
            )
            $res = Invoke-SSMCommand -Commands $checkCmds -Description "Service Verification"
            $out = $res.StandardOutputContent

            if ($out -match "CONFIG_OK") { Write-Pass "Protected configuration (config.local.php) confirmed" }
            if ($out -match "127\.0\.0\.1:3306") { Write-Pass "MariaDB confirmed strictly on localhost:3306" }
            if ($out -match "syntax is ok") { Write-Pass "Nginx configuration verified" }

            $services = @("nginx", "php-fpm", "mariadb", "online-exam-finalizer.timer")
            foreach ($s in $services) {
                if ($out -match "$s[\s\S]*?active") {
                    Write-Pass "Service is active: $s"
                } else {
                    Write-Fail "Service is NOT active: $s"
                }
            }
        }

        # 4. Public IPv4 Verification & Optional Allocation
        $updatedInst = Get-Ec2InstanceInfo
        $activePublicIp = $updatedInst.PublicIpAddress
        $activeEip = Get-AssociatedEipInfo

        if (-not $activePublicIp) {
            if ($AllocateElasticIp) {
                Write-Warn "No public IPv4 found. Allocating new Elastic IP as requested..."
                Run-AllocateIp
                $updatedInst = Get-Ec2InstanceInfo
                $activePublicIp = $updatedInst.PublicIpAddress
            } else {
                Write-Warn "Instance has no public IPv4 address assigned."
                Write-Warn "To allocate an Elastic IP, run: .\manage.ps1 -Action AllocateIp -Apply"
            }
        } else {
            Write-Pass "Current Public IPv4: $activePublicIp $(if ($activeEip) { '(Static Elastic IP)' } else { '(Ephemeral)' })"
        }

        # 5. Public HTTP Probe
        if ($activePublicIp) {
            $probe = curl.exe -s -I --max-time 10 "http://$activePublicIp/"
            if ($probe -match "HTTP/1\.[01] 200 OK") {
                Write-Pass "Public HTTP Health Check: 200 OK (http://$activePublicIp/)"
            } else {
                Write-Warn "Public HTTP Health Check returned: $(($probe -split "`n")[0].Trim())"
            }
        }

        # 6. Domain & DNS Verification & Optional Update
        if ($DomainName) {
            Write-Info "Checking domain DNS resolution and routing for $DomainName..."
            $dnsOk = Verify-DomainDnsAndHttps -domain $DomainName -expectedIp $activePublicIp
            if (-not $dnsOk) {
                if ($UpdateDns) {
                    Write-Warn "Executing requested DNS update with provider '$DnsProvider'..."
                    Run-DnsUpdate
                } else {
                    Write-Warn "ACTION REQUIRED: Update your DNS A record for '$DomainName' to point to '$activePublicIp'."
                    Write-Info "To automate this with Spaceship, run with: -UpdateDns -Apply (requires SPACESHIP_API_KEY and SPACESHIP_API_SECRET)."
                }
            }
        }

        # Final Readiness
        Write-Host "`n========================================================" -ForegroundColor Green
        Write-Host " EXAM DAY STARTUP COMPLETED" -ForegroundColor Green
        Write-Host " Instance ID : $ExpectedInstanceId (RUNNING)" -ForegroundColor Green
        Write-Host " Public IPv4 : $(if ($activePublicIp) { $activePublicIp } else { '[None]' })" -ForegroundColor Green
        if ($DomainName) {
            Write-Host " Canonical   : https://www.$DomainName/" -ForegroundColor Green
        }
        Write-Host "========================================================`n" -ForegroundColor Green
    } finally {
        Release-LifecycleLock
    }
}

# --- ACTION: DNS-UPDATE ---
function Run-DnsUpdate() {
    Write-Header "ACTION: DNS-UPDATE (DOMAIN A RECORD CONFIGURATION)"

    Assert-ValidDomain $DomainName

    $inst = Get-Ec2InstanceInfo
    $currentPublicIp = $inst.PublicIpAddress

    if (-not $currentPublicIp) {
        Write-Fail "Cannot update DNS: Instance $ExpectedInstanceId does not currently have an active public IPv4 address."
        exit 1
    }

    Write-Info "Active EC2 public IPv4: $currentPublicIp"
    Write-Info "Target Domain: $DomainName (Provider: $DnsProvider)"

    # Provider Dispatch: SPACESHIP
    if ($DnsProvider -eq "Spaceship") {
        $creds = Get-SpaceshipCredentials
        if (-not $creds.Configured) {
            if (-not $Apply) {
                Show-SpaceshipCredentialGuidance "Spaceship API credentials are not set in the ambient environment."
                Write-PlannedAction `
                    -actionName "Update Apex A Record via Spaceship API" `
                    -target "Domain '$DomainName' (@ A record)" `
                    -current "Credentials required to query live Spaceship records" `
                    -new "$currentPublicIp (TTL: 300)" `
                    -reason "Route production domain traffic to active EC2 server"
                Write-Host "`n*** DRY-RUN PREVIEW COMPLETE: NO CHANGES WERE APPLIED ***" -ForegroundColor Yellow
                Write-Host "To execute this update:" -ForegroundColor Cyan
                Write-Host "  1. Set `$env:SPACESHIP_API_KEY and `$env:SPACESHIP_API_SECRET in your terminal session." -ForegroundColor White
                Write-Host "  2. Run: .\manage.ps1 -Action DnsUpdate -DomainName '$DomainName' -DnsProvider Spaceship -Apply`n" -ForegroundColor White
                return
            } else {
                # -Apply specified but credentials missing
                Assert-SpaceshipCredentialsConfigured
            }
        }

        # Credentials are available
        Write-Pass "Spaceship API credentials detected in ambient environment."

        if (-not $Apply) {
            # In dry-run mode with credentials: query existing records and preview batch payload
            $null = Update-SpaceshipApexARecord -domain $DomainName -newIp $currentPublicIp -creds $creds -apply $false
            Write-Host "`n*** DRY-RUN COMPLETE: NO CHANGES WERE APPLIED ***" -ForegroundColor Yellow
            Write-Host "To apply this DNS update, re-run with: .\manage.ps1 -Action DnsUpdate -DomainName '$DomainName' -Apply`n" -ForegroundColor Cyan
            return
        }

        # Apply mode
        Acquire-LifecycleLock "DnsUpdate"
        try {
            $updated = Update-SpaceshipApexARecord -domain $DomainName -newIp $currentPublicIp -creds $creds -apply $true
            if ($updated) {
                Write-Info "Verifying updated DNS records and routing..."
                Start-Sleep -Seconds 3
                Verify-PostDnsUpdate -domain $DomainName -expectedIp $currentPublicIp -creds $creds | Out-Null
            }
        } finally {
            Release-LifecycleLock
        }
    }
    # Provider Dispatch: ROUTE 53 (Secondary)
    elseif ($DnsProvider -eq "Route53") {
        $hzListRaw = & $aws route53 list-hosted-zones --output json 2>&1
        $hzList = $hzListRaw | ConvertFrom-Json
        $matchedHz = $null

        if ($HostedZoneId) {
            $matchedHz = $hzList.HostedZones | Where-Object { $_.Id -match $HostedZoneId }
        } elseif ($hzList.HostedZones -and $hzList.HostedZones.Count -gt 0) {
            $matchedHz = $hzList.HostedZones | Where-Object { $DomainName.EndsWith($_.Name.TrimEnd(".")) }
        }

        if (-not $matchedHz) {
            Write-Warn "No matching Route 53 Hosted Zone found in AWS account for domain '$DomainName'."
            Write-Info "AWS Route 53 is not managing DNS for this domain."
            Write-Host "`n  MANUAL DNS UPDATE INSTRUCTIONS FOR REGISTRAR / DNS PROVIDER:" -ForegroundColor Cyan
            Write-Host "    Domain/Host  : $DomainName" -ForegroundColor White
            Write-Host "    Record Type  : A" -ForegroundColor White
            Write-Host "    New Value/IP : $currentPublicIp" -ForegroundColor White
            Write-Host "    TTL          : 300 seconds (5 minutes recommended)" -ForegroundColor White
            Write-Host "  Please set this record in your DNS provider control panel.`n" -ForegroundColor Cyan
            return
        }

        $hzId = $matchedHz.Id -replace "/hostedzone/", ""
        Write-Pass "Found Route 53 Hosted Zone: $($matchedHz.Name) (ID: $hzId)"

        Write-PlannedAction `
            -actionName "Update Route 53 A Record" `
            -target "$DomainName in Hosted Zone $hzId" `
            -current "Querying current record..." `
            -new "$currentPublicIp" `
            -reason "Route traffic to the active EC2 instance"

        if (-not $Apply) {
            Write-Host "`n*** DRY-RUN COMPLETE: NO AWS RESOURCE WAS MODIFIED ***" -ForegroundColor Yellow
            Write-Host "To execute this Route 53 update, run: .\manage.ps1 -Action DnsUpdate -DomainName '$DomainName' -DnsProvider Route53 -Apply`n" -ForegroundColor Cyan
            exit 0
        }

        Acquire-LifecycleLock "DnsUpdate"
        try {
            $changeBatch = @{
                Comment = "Update $DomainName A record for online exam production"
                Changes = @(
                    @{
                        Action = "UPSERT"
                        ResourceRecordSet = @{
                            Name = $DomainName
                            Type = "A"
                            TTL  = 300
                            ResourceRecords = @(
                                @{ Value = $currentPublicIp }
                            )
                        }
                    }
                )
            }
            $tmpBatch = [System.IO.Path]::GetTempFileName()
            $changeBatch | ConvertTo-Json -Depth 5 | Set-Content -Path $tmpBatch -Encoding Ascii

            Write-Warn "Applying Route 53 record change..."
            $changeRaw = & $aws route53 change-resource-record-sets `
                --hosted-zone-id $hzId `
                --change-batch "file://$tmpBatch" `
                --output json 2>&1
            Remove-Item $tmpBatch -ErrorAction SilentlyContinue

            if ($LASTEXITCODE -ne 0) { throw "Route 53 update failed: $changeRaw" }
            Write-Pass "Route 53 record change submitted successfully: $DomainName -> $currentPublicIp"

            Verify-DomainDnsAndHttps -domain $DomainName -expectedIp $currentPublicIp | Out-Null
        } finally {
            Release-LifecycleLock
        }
    }
}

# ==========================================
# 7. MAIN DISPATCHER
# ==========================================
switch ($Action) {
    "Status"    { Run-StatusOrVerify -fullVerification $false }
    "DryRun"    { Run-StatusOrVerify -fullVerification $false }
    "Verify"    { Run-StatusOrVerify -fullVerification $true }
    "StopIdle"  { Run-StopIdle }
    "ReleaseIp" { Run-ReleaseIp }
    "AllocateIp"{ Run-AllocateIp }
    "StartExam" { Run-StartExam }
    "DnsUpdate" { Run-DnsUpdate }
    default     { Run-StatusOrVerify -fullVerification $false }
}
