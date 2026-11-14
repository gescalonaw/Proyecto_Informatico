@echo off
chcp 65001 >nul
cd /d "%~dp0pos-prototipo" || (echo No encuentro la carpeta pos-prototipo & pause & exit /b 1)
if not exist vendor\autoload.php (echo Primero ejecuta 1_instalar.bat & pause & exit /b 1)
echo === Base de datos y publicacion en Apache (XAMPP) ===
echo OJO: borra y vuelve a crear la base con los datos de prueba.
echo.
set XAMPP=C:\xampp
if not exist "%XAMPP%\php\php.exe" set /p XAMPP=Carpeta donde esta instalado XAMPP [C:\xampp]: 
if not exist "%XAMPP%\php\php.exe" (echo No encuentro XAMPP en "%XAMPP%". Instalalo desde https://www.apachefriends.org & pause & exit /b 1)
set XPHP=%XAMPP%\php\php.exe

echo Abre el panel de XAMPP (xampp-control.exe) y presiona Start en Apache y en MySQL.
echo Cuando ambos esten en verde, presiona una tecla aqui.
pause >nul

"%XAMPP%\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS pos_multisede CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" || goto sin_mysql
echo Base de datos pos_multisede lista.

echo Configurando .env para MySQL de XAMPP...
powershell -NoProfile -Command "$e = Get-Content .env; $e = $e -replace '^#?\s*DB_CONNECTION=.*','DB_CONNECTION=mariadb' -replace '^#?\s*DB_HOST=.*','DB_HOST=127.0.0.1' -replace '^#?\s*DB_PORT=.*','DB_PORT=3306' -replace '^#?\s*DB_DATABASE=.*','DB_DATABASE=pos_multisede' -replace '^#?\s*DB_USERNAME=.*','DB_USERNAME=root' -replace '^#?\s*DB_PASSWORD=.*','DB_PASSWORD='; foreach ($k in 'DB_HOST=127.0.0.1','DB_PORT=3306','DB_DATABASE=pos_multisede','DB_USERNAME=root','DB_PASSWORD=') { if (-not ($e -match ('^' + $k.Split('=')[0] + '='))) { $e += $k } }; Set-Content .env $e" || goto error

echo Creando tablas y datos de prueba (incluye 14 dias de ventas sinteticas)...
"%XPHP%" artisan config:clear >nul
"%XPHP%" artisan migrate:fresh --seed --force || goto error
rem Apache en Windows lee el .env de forma poco confiable con solicitudes simultaneas: se deja en cache.
"%XPHP%" artisan config:cache >nul || goto error

rem Enlace desde htdocs a esta carpeta (si ya existia, se reemplaza el enlace; no borra archivos).
if exist "%XAMPP%\htdocs\pos-multisede" rmdir "%XAMPP%\htdocs\pos-multisede"
mklink /J "%XAMPP%\htdocs\pos-multisede" "%cd%" >nul || goto error

echo.
echo === Listo ===
echo POS (cajeras):          http://localhost/pos-multisede/public/pos/negocio-1
echo Panel (duena/encargado): http://localhost/pos-multisede/public/admin
echo Clave de todos los usuarios: secreto123  (ver README)
start "" "http://localhost/pos-multisede/public/pos/negocio-1"
pause
exit /b 0

:sin_mysql
echo No pude conectarme a MySQL. Revisa que este en verde en XAMPP.
echo Si dice "Port 3306 in use", tienes otro MySQL/MariaDB instalado: mira "Problemas comunes" en el README.
pause
exit /b 1
:error
echo.
echo [ERROR] Algo fallo. Copia lo que aparece arriba y mandalo al grupo.
pause
exit /b 1
