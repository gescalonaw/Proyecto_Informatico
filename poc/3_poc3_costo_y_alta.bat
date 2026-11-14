@echo off
chcp 65001 >nul
set PYTHONUTF8=1
set PY=python
where py >nul 2>nul && set PY=py -3
cd /d "%~dp0poc3_costo_alta"
echo === PoC-3: costo mensual ===
%PY% costo.py
echo.
echo === PoC-3: tiempo de alta de un negocio (completa registro_alta.csv) ===
%PY% analizar_alta.py registro_alta.csv
pause
