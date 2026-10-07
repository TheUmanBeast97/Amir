@echo off
rem Avvia il backend di AMIR Team Manager (API) su http://127.0.0.1:8000
cd /d "%~dp0backend"
echo.
echo  AMIR Team Manager - backend
echo  API:  http://127.0.0.1:8000/api/v1/public/home
echo  Per fermarlo premi CTRL+C o chiudi questa finestra.
echo.
php artisan serve --host=127.0.0.1 --port=8000
pause
