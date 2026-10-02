@echo off
set KEY=C:\Users\smart\Desktop\WESIPER\wesiperxyz.pem
echo [1/4] publish release
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0publish-release.ps1" -KeyPath "%KEY%"
echo [2/4] year leaders 2021
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0run-year-leaders.ps1" -Apply -Year 2021 -KeyPath "%KEY%"
copy /y "%~dp0run-year-leaders.out.log" "%~dp0run-year-leaders-2021.out.log" >nul
echo [3/4] year leaders 2024
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0run-year-leaders.ps1" -Apply -Year 2024 -KeyPath "%KEY%"
echo [4/4] refresh daily job files
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-year-leaders-daily.ps1" -KeyPath "%KEY%"
echo.
echo Finished.
pause
