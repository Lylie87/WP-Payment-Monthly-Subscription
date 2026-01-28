@echo off
setlocal DisableDelayedExpansion

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
set SOURCE_PATH=F:\Github Repo's\WP Woocommerce Subscription Payments
set LOCAL_PATH=%SOURCE_PATH%\dist\process-subscriptions
set BACKUP_PATH=%SOURCE_PATH%\backups

:: Get timestamp for backup folder
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set datetime=%%I
set TIMESTAMP=%datetime:~0,4%-%datetime:~4,2%-%datetime:~6,2%_%datetime:~8,2%-%datetime:~10,2%-%datetime:~12,2%

echo Local:  %LOCAL_PATH%
echo Remote: sftp://%HOST%%REMOTE_PATH%
echo.

:: ========================================
:: Step 1: Copy source files to dist
:: ========================================
echo [1/3] Copying source files to dist...
if not exist "%LOCAL_PATH%" mkdir "%LOCAL_PATH%"
if not exist "%LOCAL_PATH%\includes" mkdir "%LOCAL_PATH%\includes"
if not exist "%LOCAL_PATH%\admin" mkdir "%LOCAL_PATH%\admin"

xcopy /Y /E /I "%SOURCE_PATH%\includes" "%LOCAL_PATH%\includes" >nul 2>&1
xcopy /Y /E /I "%SOURCE_PATH%\admin" "%LOCAL_PATH%\admin" >nul 2>&1
copy /Y "%SOURCE_PATH%\process-subscriptions.php" "%LOCAL_PATH%\" >nul 2>&1

echo       Source files copied to dist.
echo.

:: ========================================
:: Step 2: Backup remote files
:: ========================================
echo [2/3] Creating backup of remote files...

:: Create backup directory
if not exist "%BACKUP_PATH%" mkdir "%BACKUP_PATH%"
set BACKUP_DEST=%BACKUP_PATH%\%TIMESTAMP%
mkdir "%BACKUP_DEST%"

:: Create WinSCP backup script
set BACKUP_SCRIPT=%TEMP%\winscp_plugin_backup.txt
(
echo option batch continue
echo option confirm off
echo open sftp://%USER%@%HOST% -privatekey="%PPK_PATH%" -passphrase="Lylie87!@@"
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
echo.

:: ========================================
:: Step 3: Deploy to server
:: ========================================
echo [3/3] Deploying to server...

:: Create WinSCP deploy script
set SCRIPT_FILE=%TEMP%\winscp_plugin_deploy.txt
(
echo option batch continue
echo option confirm off
echo open sftp://%USER%@%HOST% -privatekey="%PPK_PATH%" -passphrase="Lylie87!@@"
echo synchronize remote "%LOCAL_PATH%" "%REMOTE_PATH%"
echo exit
) > "%SCRIPT_FILE%"

:: Run WinSCP
"%WINSCP_PATH%" /script="%SCRIPT_FILE%" /log="%TEMP%\winscp_plugin_deploy.log"

if %ERRORLEVEL% equ 0 (
    echo.
    echo ========================================
    echo   Deployment successful!
    echo   Backup: %TIMESTAMP%
    echo ========================================
) else (
    echo.
    echo ========================================
    echo   Deployment FAILED! Check log:
    echo   %TEMP%\winscp_plugin_deploy.log
    echo
    echo   Restore from: %BACKUP_DEST%
    echo ========================================
)

:: Cleanup
del "%SCRIPT_FILE%" 2>nul

echo.
