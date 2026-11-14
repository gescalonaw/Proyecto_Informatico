@echo off
chcp 65001 >nul
set PYTHONUTF8=1
set PY=python
where py >nul 2>nul && set PY=py -3
cd /d "%~dp0colas"
set /p LAMBDA=Solicitudes por segundo [30]: 
if "%LAMBDA%"=="" set LAMBDA=30
set /p SERV=Tiempo de servicio en ms [50, o la mediana medida en la PoC-2]: 
if "%SERV%"=="" set SERV=50
%PY% colas.py %LAMBDA% %SERV%
pause
