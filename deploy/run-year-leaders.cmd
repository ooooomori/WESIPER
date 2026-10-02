@echo off
echo [1/2] CHECK: compute 2025 only (no save)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0run-year-leaders.ps1" -Year 2025 -KeyPath "C:\Users\smart\Desktop\WESIPER\wesiperxyz.pem"
echo.
echo If there is no ERROR above, press any key to compute and SAVE all years (1982-now). Close this window to cancel.
pause
echo [2/2] APPLY: compute and save all years
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0run-year-leaders.ps1" -Apply -KeyPath "C:\Users\smart\Desktop\WESIPER\wesiperxyz.pem"
echo.
echo Finished. Log: deploy\run-year-leaders.out.log
pause
