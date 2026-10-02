@echo off
echo Recompute league leaders for 2021 and 2024 (tiebreakers excluded). Run late at night when traffic is low.
set KEY=C:\Users\smart\Desktop\WESIPER\wesiperxyz.pem
echo [1/2] 2021
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0run-year-leaders.ps1" -Apply -Year 2021 -KeyPath "%KEY%"
copy /y "%~dp0run-year-leaders.out.log" "%~dp0run-year-leaders-2021.out.log" >nul
echo [2/2] 2024
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0run-year-leaders.ps1" -Apply -Year 2024 -KeyPath "%KEY%"
echo.
echo Finished.
pause
