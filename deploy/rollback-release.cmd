@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0rollback-release.ps1" -KeyPath "C:\Users\smart\Desktop\WESIPER\wesiperxyz.pem"
echo.
pause
