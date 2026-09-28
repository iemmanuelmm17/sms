@echo off
setlocal
rem ============================================================
rem  SMS App - Windows installer
rem  Run from the copied project folder (double-click).
rem  Checks prerequisites, asks for the public hostname/IP,
rem  writes both .env files, installs deps, migrates the DB,
rem  and creates start-all.bat.
rem ============================================================
cd /d "%~dp0"
set ROOT=%~dp0
echo === SMS App installer ===
echo Project: %ROOT%
echo.

rem ---------- 0. Sanity: backend + frontend present ----------
if not exist "%ROOT%backend\composer.json" (echo [FAIL] backend\composer.json not found. Copy the WHOLE project folder first. & pause & exit /b 1)
if not exist "%ROOT%frontend\package.json" (echo [FAIL] frontend\package.json not found. Copy the WHOLE project folder first. & pause & exit /b 1)

rem ---------- 1. Prerequisites ----------
where php >nul 2>nul || (echo [FAIL] php not found. Install XAMPP with PHP 8.2+ and add C:\xampp\php to PATH. & pause & exit /b 1)
for /f %%v in ('php -r "echo PHP_VERSION;"') do set PHPV=%%v
for /f "tokens=1,2 delims=." %%a in ("%PHPV%") do (set PHPA=%%a& set PHPB=%%b)
if %PHPA% LSS 8 (echo [FAIL] PHP %PHPV% too old - need 8.2+. & pause & exit /b 1)
if %PHPA% EQU 8 if %PHPB% LSS 2 (echo [FAIL] PHP %PHPV% too old - need 8.2+. & pause & exit /b 1)
echo [ok] PHP %PHPV%
php -m | findstr /I  "pdo_sqlite" >nul || (echo [FAIL] PHP is missing pdo_sqlite. Enable extension=pdo_sqlite in php.ini. & pause & exit /b 1)
php -m | findstr /I  "mbstring" >nul || (echo [FAIL] PHP is missing mbstring. Enable it in php.ini. & pause & exit /b 1)
echo [ok] PHP extensions

where composer >nul 2>nul || (echo [FAIL] composer not found. Install from getcomposer.org. & pause & exit /b 1)
echo [ok] composer

where node >nul 2>nul || (echo [FAIL] node not found. Install Node.js 20 LTS from nodejs.org. & pause & exit /b 1)
for /f %%v in ('node -p "process.versions.node"') do set NODEV=%%v
for /f "tokens=1 delims=." %%a in ("%NODEV%") do set NODEA=%%a
if %NODEA% LSS 18 (echo [FAIL] Node %NODEV% too old - need 18+. & pause & exit /b 1)
echo [ok] Node %NODEV%
echo.

rem ---------- 2. Ask for the public hostname/IP ----------
:askhost
set "H="
set /p "H=Public hostname or IP (e.g. 192.168.18.5 or sms.example.com): "
if "%H%"=="" (echo Please enter something. & goto askhost)
set H=%H:http://=%
set H=%H:https://=%
set H=%H: =%
for /f "tokens=1 delims=/" %%a in ("%H%") do set H=%%a
if "%H%"=="" (echo Could not parse that - try again. & goto askhost)
echo Using host: %H%
echo NOTE: this stack serves plain http. https needs a reverse proxy in front.
echo.

rem ---------- 3. Dependencies ----------
echo --- composer install ---
cd /d "%ROOT%backend"
call composer install || (echo [FAIL] composer install failed. & pause & exit /b 1)
echo --- npm install ---
cd /d "%ROOT%frontend"
call npm install || (echo [FAIL] npm install failed. & pause & exit /b 1)
echo.

rem ---------- 4. Backend .env ----------
if not exist "%ROOT%backend\.env" (
  if not exist "%ROOT%backend\.env.example" (echo [FAIL] backend\.env and backend\.env.example are both missing. & pause & exit /b 1)
  echo Creating backend\.env from example...
  copy "%ROOT%backend\.env.example" "%ROOT%backend\.env" >nul
) else (
  echo Keeping existing backend\.env (APP_KEY preserved^).
)
powershell -NoProfile -ExecutionPolicy Bypass -Command "$f='%ROOT%backend\.env';function U($k,$v){$l=@();if(Test-Path $f){$l=Get-Content $f};$hit=$false;$n=@($l|ForEach-Object{if($_ -match ('^'+$k+'=')){$hit=$true;$k+'='+$v}else{$_}});if(-not $hit){$n+=$k+'='+$v};$n|Set-Content $f -Encoding Ascii};U 'APP_URL' 'http://%H%:8000';$c=Get-Content $f;function R{-join((48..57)+(65..90)+(97..122)|Get-Random -Count 32|ForEach-Object{[char]$_})};if(-not($c -match '^REVERB_APP_KEY=.+')){U 'REVERB_APP_KEY' (R)};if(-not($c -match '^REVERB_APP_SECRET=.+')){U 'REVERB_APP_SECRET' (R)};Write-Host 'backend .env updated'"
findstr /R /C:"^APP_KEY=base64" "%ROOT%backend\.env" >nul || (
  echo Generating APP_KEY...
  cd /d "%ROOT%backend"
  php artisan key:generate || (echo [FAIL] key:generate failed. & pause & exit /b 1)
)
echo.

rem ---------- 5. Frontend .env ----------
if not exist "%ROOT%frontend\.env" (
  echo Creating frontend\.env from example...
  copy "%ROOT%frontend\.env.example" "%ROOT%frontend\.env" >nul
) else (
  echo Keeping existing frontend\.env (updating host keys^).
)
powershell -NoProfile -ExecutionPolicy Bypass -Command "$f='%ROOT%frontend\.env';$b=(Get-Content '%ROOT%backend\.env'|Where-Object{$_ -match '^REVERB_APP_KEY='}|Select-Object -First 1) -replace '^REVERB_APP_KEY='','';function U($k,$v){$l=@();if(Test-Path $f){$l=Get-Content $f};$hit=$false;$n=@($l|ForEach-Object{if($_ -match ('^'+$k+'=')){$hit=$true;$k+'='+$v}else{$_}});if(-not $hit){$n+=$k+'='+$v};$n|Set-Content $f -Encoding Ascii};U 'VITE_API_URL' '/';U 'VITE_API_PROXY' 'http://localhost:8000';U 'VITE_ALLOWED_HOSTS' '%H%';U 'VITE_REVERB_APP_KEY' $b;U 'VITE_REVERB_HOST' '%H%';U 'VITE_REVERB_PORT' '8080';U 'VITE_REVERB_SCHEME' 'http';if($b -eq ''){Write-Host 'WARNING: backend REVERB_APP_KEY is empty'}else{Write-Host 'frontend .env updated'}"
findstr /C:"VITE_ALLOWED_HOSTS" "%ROOT%frontend\vite.config.js" >nul || (
  echo [WARN] frontend\vite.config.js does not support VITE_ALLOWED_HOSTS.
  echo        Copy the workspace vite.config.js or LAN browsers get "Blocked request".
)
echo.

rem ---------- 6. Database ----------
cd /d "%ROOT%backend"
if not exist "database\database.sqlite" (
  echo Fresh database - creating + migrating...
  type nul > "database\database.sqlite"
) else (
  echo Existing database.sqlite found - keeping data, running migrations...
)
php artisan migrate || (echo [FAIL] migrate failed. & pause & exit /b 1)
php artisan optimize:clear >nul
echo.

rem ---------- 7. Superadmin account (optional) ----------
set /p "MAKESA=Create a superadmin account now? (y/n): "
if /i "%MAKESA%"=="y" php artisan superadmin:create
echo.

rem ---------- 8. Superadmin LAN access (optional) ----------
set /p "LANCIDR=Allow a LAN subnet into the super portal, e.g. 192.168.18.0/24 (blank = skip): "
set LANCIDR=%LANCIDR: =%
if not "%LANCIDR%"=="" php artisan superadmin:ip --add=%LANCIDR% --label=LAN
echo.

rem ---------- 9. Firewall (optional, needs Admin) ----------
set /p "FW=Add Windows Firewall rules for ports 5173/8000/8080? (y/n): "
if /i "%FW%"=="y" (
  netsh advfirewall firewall add rule name="SMS App 5173" dir=in action=allow protocol=TCP localport=5173 profile=private >nul && echo [ok] 5173 || echo [!!] 5173 rule failed - re-run installer as Admin.
  netsh advfirewall firewall add rule name="SMS App 8000" dir=in action=allow protocol=TCP localport=8000 profile=private >nul && echo [ok] 8000 || echo [!!] 8000 rule failed - re-run installer as Admin.
  netsh advfirewall firewall add rule name="SMS App 8080" dir=in action=allow protocol=TCP localport=8080 profile=private >nul && echo [ok] 8080 || echo [!!] 8080 rule failed - re-run installer as Admin.
)
echo.

rem ---------- 10. start-all.bat ----------
(
echo @echo off
echo cd /d %ROOT%backend
echo start "SMS Backend :8000" php artisan serve --host=0.0.0.0 --port=8000
echo start "SMS Sockets :8080" php artisan reverb:start --host=0.0.0.0 --port=8080
echo start "SMS Queue" php artisan queue:work --tries=3 --timeout=120
echo cd /d %ROOT%frontend
echo start "SMS Frontend :5173" cmd /k npm run dev
echo echo All services starting in separate windows. Close a window to stop it.
) > "%ROOT%start-all.bat"
echo Created start-all.bat
echo.
echo ============================================================
echo  DONE. Next steps:
echo  1. Double-click start-all.bat (4 windows open)
echo  2. Open http://%H%:5173 from any machine on the network
echo  3. Sign in at /super/login, create tenants, verify realtime
echo  Full verify list: DEPLOYMENT.md section 2.5
echo ============================================================
pause
