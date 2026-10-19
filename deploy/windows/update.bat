@echo off
chcp 65001 >nul
title Diwan - Update
echo تحديث نظام الديوان من GitHub...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0update.ps1" %*
echo.
pause
