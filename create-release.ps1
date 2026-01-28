# WordPress Plugin GitHub Release Creator (PowerShell)
# Usage: .\create-release.ps1
#
# This script uploads the process-subscriptions zip from the dist folder to GitHub releases
# Run build-release.ps1 first to create the distribution package

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "GitHub Release Uploader" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Get version from process-subscriptions.php
$versionLine = Get-Content "process-subscriptions.php" | Select-String -Pattern "Version:" | Select-Object -First 1
$version = ($versionLine -replace '.*Version:\s*', '').Trim()

Write-Host "Preparing Release: v$version" -ForegroundColor Yellow
Write-Host ""

# Look for zip in dist folder
$rootDir = Get-Location
$distDir = Join-Path $rootDir "dist"
$zipName = "process-subscriptions.zip"
$zipPath = Join-Path $distDir $zipName

# Check if dist folder ZIP exists
Write-Host "Step 1: Checking for distribution package..." -ForegroundColor Green

if (-Not (Test-Path $zipPath)) {
    Write-Host ""
    Write-Host "=========================================" -ForegroundColor Red
    Write-Host "ERROR: ZIP file not found!" -ForegroundColor Red
    Write-Host "=========================================" -ForegroundColor Red
    Write-Host ""
    Write-Host "Expected location: $zipPath" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Please run build-release.ps1 first to create the distribution package:" -ForegroundColor Yellow
    Write-Host "  .\build-release.ps1" -ForegroundColor White
    Write-Host ""
    Write-Host "This will create a clean distribution in the dist folder." -ForegroundColor White
    Write-Host ""
    exit 1
}

$zipSize = (Get-Item $zipPath).Length / 1KB
$zipSizeRounded = [math]::Round($zipSize, 2)
Write-Host "SUCCESS: Found ZIP - $zipName ($zipSizeRounded KB)" -ForegroundColor Green
Write-Host ""

Write-Host "Step 2: Creating GitHub release..." -ForegroundColor Green

# Create GitHub release
$releaseNotes = "Release v$version - Bug fixes and improvements"
gh release create "v$version" $zipPath --title "v$version" --notes $releaseNotes --repo Lylie87/WP-Payment-Monthly-Subscription

if ($LASTEXITCODE -eq 0) {
    Write-Host ""
    Write-Host "=========================================" -ForegroundColor Green
    Write-Host "SUCCESS: Release v$version created!" -ForegroundColor Green
    Write-Host "=========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "View release: https://github.com/Lylie87/WP-Payment-Monthly-Subscription/releases/tag/v$version" -ForegroundColor Cyan
    Write-Host ""

    # Clean up dist folder after successful upload
    Write-Host "Step 3: Cleaning up distribution folder..." -ForegroundColor Green
    try {
        if (Test-Path $distDir) {
            Remove-Item $distDir -Recurse -Force
            Write-Host "SUCCESS: Dist folder cleaned up" -ForegroundColor Green
        }
    } catch {
        Write-Host "WARNING: Could not delete dist folder automatically" -ForegroundColor Yellow
        Write-Host "$distDir" -ForegroundColor Yellow
    }
    Write-Host ""
} else {
    Write-Host ""
    Write-Host "ERROR: Failed to create GitHub release" -ForegroundColor Red
    Write-Host "Make sure you're authenticated with: gh auth login" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "NOTE: The distribution package is still in the dist folder." -ForegroundColor Yellow
    Write-Host "      You can run build-release.ps1 again once the issue is resolved." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Done!" -ForegroundColor Green
