@echo off
cd /d "%~dp0"
echo Verifique que MySQL este en verde (Start) en el panel de XAMPP.
echo Abra: http://127.0.0.1:8080   (diagnostico: http://127.0.0.1:8080/api/estado-bd)
C:\xampp\php\php.exe -S 0.0.0.0:8080 router.php
pause
