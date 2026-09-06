@echo off
cd /d "%~dp0"
echo ==============================================================
echo Menjalankan Sistem Billing RT RW Net (MODE PENGEMBANGAN)
echo Akses Lokal   : http://localhost:8000
echo Akses Jaringan: http://(IP-PC-ANDA):8000
echo.
echo PERINGATAN: server bawaan PHP hanya untuk uji coba lokal.
echo Untuk produksi gunakan Apache atau Nginx sesuai TUTORIAL.md.
echo ==============================================================
php -S 0.0.0.0:8000 router.php
pause
