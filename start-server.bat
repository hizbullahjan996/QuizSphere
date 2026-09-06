@echo off
rem QuizSphere local server launcher.
rem Starts PHP's built-in server with the bundled php.ini (curl, openssl,
rem mbstring, fileinfo). Open http://localhost:8000 when it starts.
cd /d "%~dp0"
php -c "%~dp0php.ini" -S localhost:8000
