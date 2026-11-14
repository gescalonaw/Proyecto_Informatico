@echo off
chcp 65001 >nul
set PYTHONUTF8=1
set PY=python
where py >nul 2>nul && set PY=py -3
cd /d "%~dp0pos-prototipo" || (echo No encuentro la carpeta pos-prototipo & pause & exit /b 1)
set XAMPP=C:\xampp
if not exist "%XAMPP%\php\php.exe" set /p XAMPP=Carpeta donde esta instalado XAMPP [C:\xampp]: 
if not exist "%XAMPP%\php\php.exe" (echo No encuentro XAMPP en "%XAMPP%". Instalalo desde https://www.apachefriends.org & pause & exit /b 1)
set XPHP=%XAMPP%\php\php.exe
echo === PoC-2: prueba de carga (RNF-R-1 y RNF-R-2). Apache y MySQL en verde. ===
echo No ejecutes 2_preparar_xampp.bat mientras corre: borra los tokens y todo da 401.
set BASE_URL=
set /p BASE_URL=URL [http://localhost/pos-multisede/public]: 
if "%BASE_URL%"=="" set BASE_URL=http://localhost/pos-multisede/public
set DURACION=
set /p DURACION=Duracion [2m para ensayar; 30m para el resultado]: 
if "%DURACION%"=="" set DURACION=2m
for /f %%t in ('call "%XPHP%" artisan poc:token') do set TOKEN=%%t
cd /d "%~dp0poc\poc2_carga"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmm"') do set FECHA=%%i
k6 run -e BASE_URL=%BASE_URL% -e TOKEN=%TOKEN% -e SEDE=2 -e DURATION=%DURACION% --summary-export resultados\resumen_%FECHA%.json carga.js
%PY% leer_resultado.py resultados\resumen_%FECHA%.json
echo Anota en el informe: CPU y RAM del computador, y que corrio en XAMPP (Apache + MariaDB) local.
pause
