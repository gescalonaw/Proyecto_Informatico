@echo off
chcp 65001 >nul
set PYTHONUTF8=1
set PY=python
where py >nul 2>nul && set PY=py -3
cd /d "%~dp0matriz_decision"
%PY% matriz.py > resultados\matriz.txt
type resultados\matriz.txt
pause
