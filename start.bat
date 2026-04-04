@echo off
cd /d "%~dp0"
echo ==============================================================
echo Menjalankan Sistem Billing RT RW Net
echo Akses Lokal   : http://localhost:8000
echo Akses Jaringan: http://(IP-PC-ANDA):8000
echo ==============================================================
php -S 0.0.0.0:8000
pause
