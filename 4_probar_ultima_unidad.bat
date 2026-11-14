@echo off
chcp 65001 >nul
cd /d "%~dp0pos-prototipo" || (echo No encuentro la carpeta pos-prototipo & pause & exit /b 1)
set XAMPP=C:\xampp
if not exist "%XAMPP%\php\php.exe" set /p XAMPP=Carpeta donde esta instalado XAMPP [C:\xampp]: 
if not exist "%XAMPP%\php\php.exe" (echo No encuentro XAMPP en "%XAMPP%". Instalalo desde https://www.apachefriends.org & pause & exit /b 1)
set XPHP=%XAMPP%\php\php.exe
echo === RB-1: 20 cajas compran la ultima unidad a la vez (Apache y MySQL en verde) ===
set BASE_URL=
set /p BASE_URL=URL [http://localhost/pos-multisede/public]: 
if "%BASE_URL%"=="" set BASE_URL=http://localhost/pos-multisede/public
rem Sede 2 = Tienda Centro del Negocio 1. Sin caja abierta no se vende (RB-3), asi que se abre antes.
"%XPHP%" artisan poc:caja 2
"%XPHP%" artisan poc:stock 1 2 5
for /f %%t in ('call "%XPHP%" artisan poc:token') do set TOKEN=%%t
k6 run -q -e BASE_URL=%BASE_URL% -e TOKEN=%TOKEN% -e SEDE=2 "%~dp0poc\poc2_carga\ultima_unidad.js"
echo.
echo Esperado: ventas_aceptadas = 5, ventas_rechazadas = 15, y stock final 0:
"%XPHP%" artisan poc:stock 1 2
pause
