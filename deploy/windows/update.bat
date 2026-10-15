@echo off
chcp 65001 >nul
title Alostaz - Update
echo تحديث نظام الأستاذ من GitHub...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0update.ps1" %*
echo.
pause
