@echo off
cd /d C:\xampp\sms_app\backend
start "SMS Backend :8000" php artisan serve --host=0.0.0.0 --port=8000
start "SMS Sockets :8080" php artisan reverb:start --host=0.0.0.0 --port=8080
start "SMS Queue" php artisan queue:work --tries=3 --timeout=120
cd /d C:\xampp\sms_app\frontend
start "SMS Frontend :5173" cmd /k npm run dev
echo All services starting in separate windows. Close a window to stop it.
