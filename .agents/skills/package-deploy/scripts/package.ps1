# Automated Packaging Script for Online Exam System
# Usage: powershell -ExecutionPolicy Bypass -File .agents/skills/package-deploy/scripts/package.ps1

$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..\..\..")).Path
Set-Location $repoRoot

Write-Host "==> Step 1: Linting all PHP files in repository..." -ForegroundColor Cyan

$phpExe = "C:\xampp\php\php.exe"
if (-not (Test-Path $phpExe)) {
    $phpExe = "php"
}

$phpFiles = Get-ChildItem -Path $repoRoot -Recurse -Filter "*.php" | Where-Object {
    $_.FullName -notmatch "[\/\\]\.git[\/\\]" -and
    $_.FullName -notmatch "[\/\\]vendor[\/\\]" -and
    $_.FullName -notmatch "[\/\\]\.agents[\/\\]"
}

$hasError = $false
foreach ($file in $phpFiles) {
    $relPath = Resolve-Path -Relative $file.FullName
    $lintOutput = & $phpExe -l $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host "[FAIL] $relPath : $lintOutput" -ForegroundColor Red
        $hasError = $true
    }
}

if ($hasError) {
    Write-Error "PHP syntax errors found. Aborting package build."
    exit 1
} else {
    Write-Host "[OK] Verified $($phpFiles.Count) PHP files without errors." -ForegroundColor Green
}

Write-Host "==> Step 2: Creating production deployment archive (site.zip)..." -ForegroundColor Cyan

$zipPath = Join-Path $repoRoot "site.zip"
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

$tempDir = Join-Path $repoRoot "temp_package_build"
if (Test-Path $tempDir) {
    Remove-Item $tempDir -Recurse -Force
}
New-Item -ItemType Directory -Path $tempDir | Out-Null

try {
    # Copy files excluding development / VCS artifacts
    $excludedPatterns = @(
        '^\.git',
        '^\.agents',
        '^\.gemini',
        '^\.vscode',
        '^site\.zip$',
        '^temp_package_build',
        '\.log$',
        '\.tmp$'
    )

    Get-ChildItem -Path $repoRoot -Recurse | Where-Object {
        $item = $_
        $rel = $item.FullName.Substring($repoRoot.Length).TrimStart('\', '/')
        if ([string]::IsNullOrWhiteSpace($rel)) { return $false }
        foreach ($pat in $excludedPatterns) {
            if ($rel -match $pat) { return $false }
        }
        return $true
    } | ForEach-Object {
        $src = $_.FullName
        $rel = $src.Substring($repoRoot.Length).TrimStart('\', '/')
        $dest = Join-Path $tempDir $rel
        if ($_.PSIsContainer) {
            if (-not (Test-Path $dest)) {
                New-Item -ItemType Directory -Path $dest -Force | Out-Null
            }
        } else {
            $destDir = Split-Path $dest
            if (-not (Test-Path $destDir)) {
                New-Item -ItemType Directory -Path $destDir -Force | Out-Null
            }
            Copy-Item -Path $src -Destination $dest -Force
        }
    }

    Compress-Archive -Path "$tempDir\*" -DestinationPath $zipPath -CompressionLevel Optimal
    $size = (Get-Item $zipPath).Length
    $sizeKb = [math]::Round($size / 1024, 2)
    Write-Host "[SUCCESS] Package generated: site.zip ($sizeKb KB)" -ForegroundColor Green
    Write-Host "Ready for InfinityFree / cPanel upload to htdocs/ or public_html/." -ForegroundColor Yellow
}
finally {
    if (Test-Path $tempDir) {
        Remove-Item $tempDir -Recurse -Force
    }
}
