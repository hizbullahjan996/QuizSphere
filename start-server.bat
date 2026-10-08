@echo off
rem QuizSphere local server launcher.
rem Starts PHP's built-in server with the bundled php.ini (curl, openssl,
rem mbstring, fileinfo). Default port is 8000, or pass port as first argument (e.g. start-server.bat 8080).
cd /d "%~dp0"
set PORT=%1
if "%PORT%"=="" set PORT=8000
echo Starting QuizSphere PHP frontend on http://localhost:%PORT% ...
php -c "%~dp0php.ini" -S localhost:%PORT%
