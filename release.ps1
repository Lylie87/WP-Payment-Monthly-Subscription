# Complete Release Script for Pro-cess Subscriptions
# This script handles the entire release process:
# 1. Clean up old dist folder
# 2. Pull latest changes from GitHub
# 3. Build the distribution package
# 4. Upload to GitHub releases

$ErrorActionPreference = "Stop"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Pro-cess Subscriptions - Complete Release Process" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Clean up dist folder
Write-Host "Step 1: Cleaning up old dist folder..." -ForegroundColor Yellow
$distDir = Join-Path (Get-Location) "dist"
if (Test-Path $distDir) {
    Remove-Item $distDir -Recurse -Force
    Write-Host "  OK: Dist folder cleaned" -ForegroundColor Green
} else {
    Write-Host "  OK: No dist folder to clean" -ForegroundColor Green
}
Write-Host ""

# Step 2: Pull latest changes
Write-Host "Step 2: Pulling latest changes from GitHub..." -ForegroundColor Yellow
try {
    git pull origin main
    if ($LASTEXITCODE -ne 0) {
        throw "Git pull failed"
    }
    Write-Host "  OK: Latest changes pulled successfully" -ForegroundColor Green
} catch {
    Write-Host "  WARNING: Could not pull changes (may be a new repo): $_" -ForegroundColor Yellow
    Write-Host "  Continuing with local files..." -ForegroundColor Yellow
}
Write-Host ""

# Step 3: Build distribution package
Write-Host "Step 3: Building distribution package..." -ForegroundColor Yellow
try {
    & .\build-release.ps1
    if ($LASTEXITCODE -ne 0) {
        throw "Build failed"
    }
    Write-Host "  OK: Build completed successfully" -ForegroundColor Green
} catch {
    Write-Host "  ERROR: Build failed: $_" -ForegroundColor Red
    exit 1
}
Write-Host ""

# Step 4: Upload to GitHub releases
Write-Host "Step 4: Uploading to GitHub releases..." -ForegroundColor Yellow
try {
    & .\create-release.ps1
    if ($LASTEXITCODE -ne 0) {
        throw "Release upload failed"
    }
    Write-Host "  OK: Release uploaded successfully" -ForegroundColor Green
} catch {
    Write-Host "  ERROR: Release upload failed: $_" -ForegroundColor Red
    exit 1
}
Write-Host ""

Write-Host "========================================" -ForegroundColor Green
Write-Host "Release Process Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Your plugin is now live on GitHub!" -ForegroundColor Cyan
Write-Host "https://github.com/Lylie87/WP-Payment-Monthly-Subscription/releases" -ForegroundColor Cyan
Write-Host ""
