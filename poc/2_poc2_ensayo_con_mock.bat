@echo off
chcp 65001 >nul
set PYTHONUTF8=1
set PY=python
where py >nul 2>nul && set PY=py -3
cd /d "%~dp0poc2_carga"
echo === PoC-2 (ensayo): probar que k6 funciona contra un servidor FALSO ===
echo Este resultado NO es la PoC-2; solo confirma que la herramienta esta bien instalada.
start "Mock API (cerrar al terminar)" %PY% mock_api.py
timeout /t 2 >nul
k6 run -e DURATION=1m --summary-export resultados\resumen_mock.json carga.js
%PY% leer_resultado.py resultados\resumen_mock.json
echo Cierra la ventana "Mock API" cuando termines.
pause
