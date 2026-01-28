# Build Release Script for Pro-cess Subscriptions
# Creates a clean distribution folder and zip file ready for release

param(
    [string]$Version = $null
)

# Set error action preference
$ErrorActionPreference = "Stop"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Pro-cess Subscriptions - Build Release Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Get version from plugin file if not provided
if (-not $Version) {
    $pluginFile = Get-Content "process-subscriptions.php" -Raw
    if ($pluginFile -match 'Version:\s*(\d+\.\d+\.\d+)') {
        $Version = $Matches[1]
        Write-Host "Detected version: $Version" -ForegroundColor Green
    } else {
        Write-Host "Error: Could not detect version from process-subscriptions.php" -ForegroundColor Red
        exit 1
    }
}

# Define paths
$rootDir = Get-Location
$distDir = Join-Path $rootDir "dist"
$pluginDir = Join-Path $distDir "process-subscriptions"
$zipFile = Join-Path $distDir "process-subscriptions.zip"

# Clean up old dist folder if it exists
if (Test-Path $distDir) {
    Write-Host "Cleaning up old dist folder..." -ForegroundColor Yellow
    Remove-Item $distDir -Recurse -Force
}

# Create dist directory structure
Write-Host "Creating dist folder structure..." -ForegroundColor Yellow
New-Item -ItemType Directory -Path $pluginDir -Force | Out-Null

# Define files/folders to exclude
$excludePatterns = @(
    '.git',
    '.gitignore',
    '.gitattributes',
    'dist',
    'build-release.ps1',
    'build-local.ps1',
    'create-release.ps1',
    'release.ps1',
    '*.ps1',
    'DEVELOPER.md',
    'CLAUDE.md',
    '*.md',
    '.vscode',
    '.idea',
    '.claude',
    'node_modules',
    'tests',
    'package.json',
    'package-lock.json',
    'tsconfig.json',
    'playwright.config.ts',
    'playwright.config.js',
    '*.log',
    '.DS_Store',
    'Thumbs.db',
    'nul',
    '*.bak',
    '*.tmp'
)

Write-Host "Copying plugin files (excluding development files)..." -ForegroundColor Yellow

# Get all items in root directory
$items = Get-ChildItem -Path $rootDir -Force

foreach ($item in $items) {
    # Always include README.md for new users
    if ($item.Name -eq "README.md") {
        $destination = Join-Path $pluginDir $item.Name
        Write-Host "  Copying file: $($item.Name) (user documentation)" -ForegroundColor Green
        Copy-Item -Path $item.FullName -Destination $destination -Force
        continue
    }

    # Check if item should be excluded
    $shouldExclude = $false
    foreach ($pattern in $excludePatterns) {
        if ($item.Name -like $pattern) {
            $shouldExclude = $true
            break
        }
    }

    if (-not $shouldExclude) {
        $destination = Join-Path $pluginDir $item.Name

        if ($item.PSIsContainer) {
            # Copy directory
            Write-Host "  Copying folder: $($item.Name)" -ForegroundColor Gray
            Copy-Item -Path $item.FullName -Destination $destination -Recurse -Force
        } else {
            # Copy file
            Write-Host "  Copying file: $($item.Name)" -ForegroundColor Gray
            Copy-Item -Path $item.FullName -Destination $destination -Force
        }
    } else {
        Write-Host "  Excluding: $($item.Name)" -ForegroundColor DarkGray
    }
}

Write-Host ""
Write-Host "Creating zip file with 7-Zip..." -ForegroundColor Yellow
Write-Host "  Output: process-subscriptions.zip" -ForegroundColor Gray

# Remove old zip if exists
if (Test-Path $zipFile) {
    Remove-Item $zipFile -Force
}

# Find 7-Zip executable
$7zipPaths = @(
    "C:\Program Files\7-Zip\7z.exe",
    "C:\Program Files (x86)\7-Zip\7z.exe",
    "$env:ProgramFiles\7-Zip\7z.exe",
    "${env:ProgramFiles(x86)}\7-Zip\7z.exe"
)

$7zipExe = $null
foreach ($path in $7zipPaths) {
    if (Test-Path $path) {
        $7zipExe = $path
        break
    }
}

if (-not $7zipExe) {
    Write-Host ""
    Write-Host "ERROR: 7-Zip not found!" -ForegroundColor Red
    Write-Host "Please install 7-Zip from https://www.7-zip.org/" -ForegroundColor Yellow
    Write-Host "Or make sure it's installed in the default location" -ForegroundColor Yellow
    exit 1
}

Write-Host "  Using 7-Zip: $7zipExe" -ForegroundColor Gray

# Create zip file using 7-Zip (creates Linux-compatible archives)
# Zip the process-subscriptions folder (not just its contents) so WordPress extracts it correctly
Push-Location $distDir
& $7zipExe a -tzip "$zipFile" "process-subscriptions" -mx=9 | Out-Null
Pop-Location

if (-not (Test-Path $zipFile)) {
    Write-Host ""
    Write-Host "ERROR: Failed to create zip file" -ForegroundColor Red
    exit 1
}

# Get file size
$fileSize = (Get-Item $zipFile).Length
$fileSizeMB = [math]::Round($fileSize / 1MB, 2)

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Build Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Version: $Version" -ForegroundColor Cyan
Write-Host "Zip file: $zipFile" -ForegroundColor Cyan
Write-Host "File size: $fileSizeMB MB" -ForegroundColor Cyan
Write-Host ""
Write-Host "The release is ready in the 'dist' folder!" -ForegroundColor Green
Write-Host ""

# Show contents
Write-Host "Distribution folder contents:" -ForegroundColor Yellow
Get-ChildItem $distDir -Recurse | Select-Object FullName | Format-Table -AutoSize
