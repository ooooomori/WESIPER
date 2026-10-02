@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0run-remote-php.ps1" -Script inspect-player-movements.php
