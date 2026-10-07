@echo off
rem Avvia AMIR Team Manager in locale: backend (API, porta 8000) + frontend (sito, porta 8080)
start "AMIR - backend (porta 8000)" /D "%~dp0backend" cmd /k php artisan serve --host=127.0.0.1 --port=8000
start "AMIR - frontend (porta 8080)" /D "%~dp0frontend" cmd /k bun run dev
timeout /t 10 /nobreak >nul
start "" http://localhost:8080
