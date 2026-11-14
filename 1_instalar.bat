@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion
cd /d "%~dp0pos-prototipo" || (echo No encuentro la carpeta pos-prototipo & pause & exit /b 1)
echo === POS Multisede: instalacion (solo la primera vez, o si cambia composer.lock) ===
echo.
set XAMPP=C:\xampp
if not exist "%XAMPP%\php\php.exe" set /p XAMPP=Carpeta donde esta instalado XAMPP [C:\xampp]: 
if not exist "%XAMPP%\php\php.exe" (echo No encuentro XAMPP en "%XAMPP%". Instalalo desde https://www.apachefriends.org & pause & exit /b 1)
set XPHP=%XAMPP%\php\php.exe

for /f %%v in ('call "%XPHP%" -r "echo PHP_VERSION_ID;"') do set XV=%%v
for /f %%v in ('call "%XPHP%" -r "echo PHP_VERSION;"') do set XVS=%%v
echo PHP de XAMPP: %XVS%
if %XV% LSS 80200 goto php_viejo

echo Revisando extensiones de PHP...
set FALTAN=
for %%e in (openssl mbstring fileinfo curl zip pdo_mysql pdo_sqlite) do (
  "%XPHP%" -r "exit(extension_loaded('%%e') ? 0 : 1);" || set "FALTAN=!FALTAN! %%e"
)
if not "!FALTAN!"=="" goto faltan_ext
echo Extensiones OK.

if exist composer.phar goto composer_ok
echo Descargando Composer...
"%XPHP%" -r "copy('https://getcomposer.org/download/latest-stable/composer.phar', 'composer.phar');" || goto error
:composer_ok

if %XV% GEQ 80400 goto instalar_lock
echo.
echo [AVISO] El proyecto se fijo con PHP 8.4 y tu XAMPP trae %XVS%.
echo Se ajustaran las dependencias a tu version. NO subas al repo el composer.json ni el composer.lock que cambien.
echo Lo ideal es instalar XAMPP con PHP 8.4 o superior, igual que el resto del equipo.
pause
"%XPHP%" composer.phar config platform.php 8.2.12 || goto error
"%XPHP%" composer.phar update --no-interaction || goto error
goto deps_ok
:instalar_lock
"%XPHP%" composer.phar install --no-interaction || goto error
:deps_ok

if not exist .env copy .env.example .env >nul
findstr /b /c:"APP_KEY=base64" .env >nul || "%XPHP%" artisan key:generate --force || goto error
echo.
echo === Listo. Ahora ejecuta 2_preparar_xampp.bat ===
pause
exit /b 0

:php_viejo
echo Laravel 12 necesita PHP 8.2 o superior y tu XAMPP trae %XVS%. Instala un XAMPP mas nuevo.
pause
exit /b 1
:faltan_ext
echo [FALTAN EXTENSIONES]!FALTAN!
echo Abre %XAMPP%\php\php.ini, quita el ";" al inicio de cada linea extension=NOMBRE que falte, guarda y vuelve a ejecutar.
pause
exit /b 1
:error
echo.
echo [ERROR] Algo fallo. Copia lo que aparece arriba y mandalo al grupo.
pause
exit /b 1
