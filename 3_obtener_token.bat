@echo off
chcp 65001 >nul
cd /d "%~dp0pos-prototipo" || (echo No encuentro la carpeta pos-prototipo & pause & exit /b 1)
set XAMPP=C:\xampp
if not exist "%XAMPP%\php\php.exe" set /p XAMPP=Carpeta donde esta instalado XAMPP [C:\xampp]: 
if not exist "%XAMPP%\php\php.exe" (echo No encuentro XAMPP en "%XAMPP%". Instalalo desde https://www.apachefriends.org & pause & exit /b 1)
set XPHP=%XAMPP%\php\php.exe
echo Token de la cajera del Negocio 1 (vale 8 horas):
"%XPHP%" artisan poc:token
pause
