@echo off
chcp 65001 >nul
set PYTHONUTF8=1
set PY=python
where py >nul 2>nul && set PY=py -3
cd /d "%~dp0poc1_offline"
echo === PoC-1: vender sin internet (1.000 corridas por semilla) ===
%PY% poc1_stock_offline.py 1000 2026 > resultados\semilla_2026.txt
type resultados\semilla_2026.txt
echo.
echo Repitiendo con otras semillas para comprobar que no es suerte...
for %%s in (1 2 3 4 5 7) do %PY% poc1_stock_offline.py 1000 %%s > resultados\semilla_%%s.txt
for %%s in (1 2 3 4 5 7) do (echo Semilla %%s: & findstr /b "A " resultados\semilla_%%s.txt)
echo.
echo Resultados guardados en poc1_offline\resultados
pause
