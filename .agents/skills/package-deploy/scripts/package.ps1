# Automated Production Packaging Script for Online Exam System
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

Write-Host "==> Step 2: Collecting strictly essential website runtime files..." -ForegroundColor Cyan

# Runtime folders and files strictly needed for the website to function
$allowedFolders = @('admin', 'config', 'css', 'includes', 'sql', 'student')
$allowedRootFiles = @('index.php', 'logout.php', 'version.txt', '.htaccess')

$runtimeFiles = [System.Collections.Generic.List[System.IO.FileInfo]]::new()

# Collect allowed root files
foreach ($rootFile in $allowedRootFiles) {
    $filePath = Join-Path $repoRoot $rootFile
    if (Test-Path $filePath -PathType Leaf) {
        $runtimeFiles.Add((Get-Item $filePath))
    }
}

# Collect files inside allowed folders
foreach ($folder in $allowedFolders) {
    $folderPath = Join-Path $repoRoot $folder
    if (Test-Path $folderPath -PathType Container) {
        $items = Get-ChildItem -Path $folderPath -Recurse -File | Where-Object {
            $_.Extension -in @('.php', '.css', '.sql', '.js', '.txt', '.png', '.jpg', '.jpeg', '.svg', '.webp', '.htaccess') -and
            $_.Name -ne 'config.local.php'
        }
        foreach ($item in $items) {
            $runtimeFiles.Add($item)
        }
    }
}

Write-Host "Collected $($runtimeFiles.Count) essential website files." -ForegroundColor Cyan

Add-Type -AssemblyName 'System.IO.Compression.FileSystem'

# Targets: Update zip.zip (primary requested) and site.zip
$targets = @("zip.zip", "site.zip")

foreach ($target in $targets) {
    $targetPath = Join-Path $repoRoot $target
    if (Test-Path $targetPath) {
        Remove-Item $targetPath -Force
    }

    $zip = [System.IO.Compression.ZipFile]::Open($targetPath, 'Create')
    foreach ($file in $runtimeFiles) {
        $rel = $file.FullName.Substring($repoRoot.Length).TrimStart('\', '/')
        # Normalize entry paths with forward slashes for Linux/cPanel compatibility
        $entryName = $rel.Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $file.FullName, $entryName) | Out-Null
    }
    $zip.Dispose()

    $item = Get-Item $targetPath
    $sizeKb = [math]::Round($item.Length / 1024, 2)
    Write-Host "[SUCCESS] Package generated: $target ($sizeKb KB, $($runtimeFiles.Count) files)" -ForegroundColor Green
}

Write-Host "Ready for upload to InfinityFree / cPanel (htdocs/ or public_html/)." -ForegroundColor Yellow
