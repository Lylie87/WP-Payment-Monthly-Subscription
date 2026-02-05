@echo off
setlocal EnableDelayedExpansion

echo.
echo ========================================
echo   Process Subscriptions Plugin Deployment
echo ========================================
echo.

:: Configuration
set WINSCP_PATH=C:\Program Files (x86)\WinSCP\WinSCP.com
set HOST=pro-cess.co.uk
set USER=processco
set PPK_PATH=F:\Stablepoint.ppk
set REMOTE_PATH=/home/processco/public_html/wp-content/plugins/process-subscriptions
set "SOURCE_PATH=F:\Github Repo's\WP Woocommerce Subscription Payments"
set "LOCAL_PATH=%SOURCE_PATH%\dist\process-subscriptions"
set "BACKUP_PATH=%SOURCE_PATH%\backups"
set "PLUGIN_FILE=%SOURCE_PATH%\process-subscriptions.php"
set GITHUB_REPO=Lylie87/WP-Payment-Monthly-Subscription

:: Get timestamp for backup folder (using PowerShell as wmic is deprecated)
for /f %%I in ('powershell -Command "Get-Date -Format yyyy-MM-dd_HH-mm-ss"') do set TIMESTAMP=%%I

echo Local:  %LOCAL_PATH%
echo Remote: sftp://%HOST%%REMOTE_PATH%
echo.

:: ========================================
:: Version Management
:: ========================================
echo [1/5] Version Management
echo.

:: Extract current version from plugin file
for /f "tokens=4 delims='" %%a in ('findstr /C:"PROCESS_SUBS_VERSION" "%PLUGIN_FILE%"') do set CURRENT_VERSION=%%a

if "%CURRENT_VERSION%"=="" (
    echo       ERROR: Could not read version from process-subscriptions.php
    pause
    goto :end
)

:: Calculate auto-incremented version (increment patch number)
for /f "delims=" %%V in ('powershell -NoProfile -Command "$v = '%CURRENT_VERSION%'.Split('.'); $v[2] = [int]$v[2] + 1; $v -join '.'"') do set SUGGESTED_VERSION=%%V

echo       Current version:   %CURRENT_VERSION%
echo       Suggested version: %SUGGESTED_VERSION%
echo.

:: Set default to suggested, then prompt for override
set "NEW_VERSION=%SUGGESTED_VERSION%"
set "USER_INPUT="
set /p "USER_INPUT=       Enter version (or press Enter for %SUGGESTED_VERSION%): "

:: If user typed something, use that instead
if defined USER_INPUT set "NEW_VERSION=%USER_INPUT%"

:: Validate version format
echo %NEW_VERSION%| findstr /R "^[0-9]*\.[0-9]*\.[0-9]*$" >nul
if errorlevel 1 (
    echo       ERROR: Invalid version format. Use X.Y.Z format.
    pause
    goto :end
)

echo.
echo       Updating to version: !NEW_VERSION!

:: Update version in plugin file using PowerShell
set UPDATE_FILE=%PLUGIN_FILE%
set UPDATE_OLD=%CURRENT_VERSION%
set UPDATE_NEW=%NEW_VERSION%
set UPDATE_CONST=PROCESS_SUBS_VERSION

powershell -ExecutionPolicy Bypass -Command "$f=$env:UPDATE_FILE; $old=$env:UPDATE_OLD; $new=$env:UPDATE_NEW; $const=$env:UPDATE_CONST; $c=Get-Content $f -Raw; $c=$c -replace ('Version: '+[regex]::Escape($old)), ('Version: '+$new); $c=$c -replace [regex]::Escape(\"define( '$const', '$old' )\"), \"define( '$const', '$new' )\"; Set-Content $f $c -NoNewline"

if %ERRORLEVEL% neq 0 (
    echo       ERROR: Failed to update version in plugin file
    pause
    goto :end
)

echo       Version updated successfully!
echo.

:: ========================================
:: Step 2: Copy source files to dist
:: ========================================
echo [2/5] Copying source files to dist...

if exist "%LOCAL_PATH%" rmdir /S /Q "%LOCAL_PATH%"
mkdir "%LOCAL_PATH%"
mkdir "%LOCAL_PATH%\includes"
mkdir "%LOCAL_PATH%\admin"
mkdir "%LOCAL_PATH%\assets"

xcopy /Y /E /I "%SOURCE_PATH%\includes" "%LOCAL_PATH%\includes" >nul 2>&1
xcopy /Y /E /I "%SOURCE_PATH%\admin" "%LOCAL_PATH%\admin" >nul 2>&1
xcopy /Y /E /I "%SOURCE_PATH%\assets" "%LOCAL_PATH%\assets" >nul 2>&1
copy /Y "%SOURCE_PATH%\process-subscriptions.php" "%LOCAL_PATH%\" >nul 2>&1

echo       Source files copied to dist.

:: Create release zip in dist folder
echo       Creating release zip...
set "ZIP_NAME=process-subscriptions-v!NEW_VERSION!.zip"
set "ZIP_SRC=%LOCAL_PATH%"
set "ZIP_DEST=%SOURCE_PATH%\dist\process-subscriptions-v!NEW_VERSION!.zip"
powershell -NoProfile -Command "Compress-Archive -Path $env:ZIP_SRC -DestinationPath $env:ZIP_DEST -Force"

if %ERRORLEVEL% equ 0 (
    echo       Release zip created: dist\%ZIP_NAME%
) else (
    echo       WARNING: Failed to create release zip.
)
echo.

:: ========================================
:: Step 3: Backup remote files
:: ========================================
echo [3/5] Creating backup of remote files...

:: Create backup directory
if not exist "%BACKUP_PATH%" mkdir "%BACKUP_PATH%"
set "BACKUP_DEST=%BACKUP_PATH%\%TIMESTAMP%"
mkdir "%BACKUP_DEST%"

:: Create WinSCP backup script
set BACKUP_SCRIPT=%TEMP%\winscp_plugin_backup.txt
(
echo option batch continue
echo option confirm off
echo open sftp://%USER%@%HOST% -privatekey="%PPK_PATH%" -passphrase="Lylie87^!@@"
echo synchronize local "%BACKUP_DEST%" "%REMOTE_PATH%"
echo exit
) > "%BACKUP_SCRIPT%"

"%WINSCP_PATH%" /script="%BACKUP_SCRIPT%" /log="%TEMP%\winscp_plugin_backup.log"

if %ERRORLEVEL% equ 0 (
    echo       Backup saved to: %BACKUP_DEST%
) else (
    echo       WARNING: Backup may have failed. Check log.
)
del "%BACKUP_SCRIPT%" 2>nul

:: Compress backup to zip and remove folder
echo       Compressing backup...
set "BACKUP_ZIP_SRC=%BACKUP_DEST%"
set "BACKUP_ZIP_DEST=%BACKUP_PATH%\%TIMESTAMP%.zip"
powershell -NoProfile -Command "Compress-Archive -Path (Join-Path $env:BACKUP_ZIP_SRC '*') -DestinationPath $env:BACKUP_ZIP_DEST -Force"

if %ERRORLEVEL% equ 0 (
    rmdir /S /Q "%BACKUP_DEST%"
    echo       Backup compressed: %TIMESTAMP%.zip
) else (
    echo       WARNING: Failed to compress backup. Folder retained.
)
echo.

:: ========================================
:: Step 4: Deploy to server
:: ========================================
echo [4/5] Deploying to server...

:: Create WinSCP deploy script
set SCRIPT_FILE=%TEMP%\winscp_plugin_deploy.txt
(
echo option batch continue
echo option confirm off
echo open sftp://%USER%@%HOST% -privatekey="%PPK_PATH%" -passphrase="Lylie87^!@@"
echo synchronize remote "%LOCAL_PATH%" "%REMOTE_PATH%"
echo exit
) > "%SCRIPT_FILE%"

:: Run WinSCP
"%WINSCP_PATH%" /script="%SCRIPT_FILE%" /log="%TEMP%\winscp_plugin_deploy.log"

if %ERRORLEVEL% neq 0 (
    echo.
    echo ========================================
    echo   Deployment FAILED! Check log:
    echo   %TEMP%\winscp_plugin_deploy.log
    echo.
    echo   Restore from: %BACKUP_PATH%\%TIMESTAMP%.zip
    echo ========================================
    goto :cleanup
)

echo.
echo ========================================
echo   Deployment successful!
echo   Version: !NEW_VERSION!
echo   Backup:  %TIMESTAMP%.zip
echo ========================================
echo.

:: ========================================
:: Step 5: Git Commit, Push & GitHub Release
:: ========================================
echo [5/5] Creating GitHub release...
echo.

cd /d "%SOURCE_PATH%"

:: Stage and commit version bump
git add -A
git commit -m "Release v!NEW_VERSION!"

if %ERRORLEVEL% neq 0 (
    echo WARNING: Git commit may have failed. Check if there were changes to commit.
)

:: Push to remote (detect current branch)
for /f "tokens=*" %%a in ('git rev-parse --abbrev-ref HEAD') do set CURRENT_BRANCH=%%a
echo Pushing to branch: !CURRENT_BRANCH!
git push origin !CURRENT_BRANCH!

if %ERRORLEVEL% neq 0 (
    echo WARNING: Git push failed. You may need to push manually.
    goto :cleanup
)

:: Create GitHub release with the zip as an asset
set "RELEASE_ASSET=%SOURCE_PATH%\dist\%ZIP_NAME%"
gh release create "v!NEW_VERSION!" "%RELEASE_ASSET%" --repo "%GITHUB_REPO%" --title "v!NEW_VERSION!" --notes "Release v!NEW_VERSION!" --generate-notes

if %ERRORLEVEL% equ 0 (
    echo.
    echo ========================================
    echo   GitHub release v!NEW_VERSION! created!
    echo   Asset: %ZIP_NAME%
    echo ========================================
) else (
    echo.
    echo WARNING: GitHub release creation failed.
    echo You may need to create it manually at:
    echo https://github.com/%GITHUB_REPO%/releases/new
)

:cleanup
:: Cleanup
del "%SCRIPT_FILE%" 2>nul

:end
echo.
echo ========================================
echo   Deployment complete!
echo ========================================
echo.
pause
