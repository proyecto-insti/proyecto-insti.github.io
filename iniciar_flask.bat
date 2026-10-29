@echo off
cd /d "%~dp0"
echo Verifique que MySQL este en verde (Start) en el panel de XAMPP.
python -m pip install -r requirements.txt
echo.
echo Abra: http://127.0.0.1:5000   (diagnostico: http://127.0.0.1:5000/api/estado-bd)
python app.py
pause
