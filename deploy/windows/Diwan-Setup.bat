@echo off
setlocal EnableExtensions DisableDelayedExpansion
rem Diwan ERP - Windows setup. Double-click this file.
rem It asks Windows for administrator rights, downloads the installer of the
rem latest release, checks that all of it arrived and runs it. Safe to run
rem again (keeps your data). Exit code: the installer's (0 = installed).
rem Developers: "Diwan-Setup.bat /local" runs the install.ps1 next to this file
rem (deploy\windows in a checkout) instead of downloading one.
rem Keep this file ASCII with CRLF line ends: cmd reads it in the console code page.

rem Read before the loop below: shift moves the name of this file along with the arguments.
set "DIWAN_SETUP=%~f0"
set "DIWAN_HERE=%~dp0"
set "DIWAN_LOCAL="
set "DIWAN_ELEVATED="
set "DIWAN_RC=1"
:args
if "%~1"=="" goto :args_done
if /i "%~1"=="/local" set "DIWAN_LOCAL=/local"
if /i "%~1"=="/elevated" set "DIWAN_ELEVATED=1"
shift
goto :args
:args_done

rem Windows PowerShell 5.1 by its full path: PATH may lack it, and a 32-bit window gets the 64-bit one.
set "DIWAN_POWERSHELL=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
if exist "%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe" set "DIWAN_POWERSHELL=%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe"
if not exist "%DIWAN_POWERSHELL%" goto :no_powershell

rem Administrator rights, tested the way the installer tests them ("net session" also needs the Server service).
"%DIWAN_POWERSHELL%" -NoProfile -NonInteractive -Command "if (([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { exit 0 }; exit 1"
if not errorlevel 1 goto :admin
rem This copy was started to get the rights and still lacks them: stop instead of asking again and again.
if defined DIWAN_ELEVATED goto :no_admin
echo Asking Windows for administrator rights...
"%DIWAN_POWERSHELL%" -NoProfile -NonInteractive -Command "$a = @('/elevated'); if ($env:DIWAN_LOCAL) { $a += $env:DIWAN_LOCAL }; try { Start-Process -FilePath $env:DIWAN_SETUP -ArgumentList $a -Verb RunAs -ErrorAction Stop } catch { exit 1 }"
if errorlevel 1 goto :no_admin
rem The setup goes on in the new window.
exit /b 0

:admin
title Diwan ERP setup
if defined DIWAN_LOCAL goto :local
set "DIWAN_URL=https://github.com/turkialawlqy/Diwan-ERP/releases/latest/download/install-windows.ps1"
rem A new file for each run, so an older download is never the one that runs.
set "DIWAN_PS1=%TEMP%\diwan-install-%RANDOM%%RANDOM%.ps1"
if exist "%DIWAN_PS1%" del /f /q "%DIWAN_PS1%" >nul 2>&1
echo Downloading the Diwan installer of the latest release...
set "DIWAN_CURL=%SystemRoot%\System32\curl.exe"
if not exist "%DIWAN_CURL%" goto :download_ps
"%DIWAN_CURL%" -fsSL --retry 3 -A Diwan-Setup -o "%DIWAN_PS1%" "%DIWAN_URL%"
if not errorlevel 1 goto :check
:download_ps
rem No curl, or it failed (a proxy set only in Windows, or a folder name curl cannot write): PowerShell.
if exist "%DIWAN_PS1%" del /f /q "%DIWAN_PS1%" >nul 2>&1
"%DIWAN_POWERSHELL%" -NoProfile -NonInteractive -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; $ProgressPreference = 'SilentlyContinue'; try { Invoke-WebRequest -UseBasicParsing -UserAgent 'Diwan-Setup' -Uri $env:DIWAN_URL -OutFile $env:DIWAN_PS1 } catch { Write-Host $_.Exception.Message; exit 1 }"
if errorlevel 1 goto :download_failed
goto :check

:local
set "DIWAN_PS1=%DIWAN_HERE%install.ps1"
echo Using the installer next to this file: "%DIWAN_PS1%"
if not exist "%DIWAN_PS1%" goto :no_local

:check
rem Only a complete installer runs: its last line is "# Diwan installer end". An interrupted
rem download, an empty file or a page from a proxy instead of the file does not end so.
"%DIWAN_POWERSHELL%" -NoProfile -NonInteractive -Command "try { $lines = @([IO.File]::ReadAllLines($env:DIWAN_PS1) | Where-Object { $_.Trim() }) } catch { exit 1 }; if ($lines.Count -and $lines[-1].Trim() -eq '# Diwan installer end') { exit 0 }; exit 1"
if errorlevel 1 goto :incomplete

"%DIWAN_POWERSHELL%" -NoProfile -ExecutionPolicy Bypass -File "%DIWAN_PS1%"
set "DIWAN_RC=%ERRORLEVEL%"
if not defined DIWAN_LOCAL del /f /q "%DIWAN_PS1%" >nul 2>&1
echo.
if "%DIWAN_RC%"=="0" goto :finished
echo Diwan setup did not finish (code %DIWAN_RC%): the messages above say why.
echo Running Diwan-Setup.bat again is safe (it keeps your data).
goto :end
:finished
echo Diwan setup finished.
goto :end

:no_powershell
echo.
echo ERROR: Windows PowerShell is missing ("%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe").
echo Diwan needs Windows 10 (version 1809 or newer) or Windows 11.
goto :end

:no_admin
echo.
echo ERROR: Diwan setup needs administrator rights. Run Diwan-Setup.bat again and answer "Yes"
echo when Windows asks. On an account that is not an administrator, ask an administrator to run it.
goto :end

:download_failed
echo.
echo ERROR: Could not download the Diwan installer. Check the internet connection (github.com
echo must open in the browser), then run Diwan-Setup.bat again.
goto :end

:no_local
echo.
echo ERROR: /local runs the install.ps1 next to this file, and there is none.
goto :end

:incomplete
if not defined DIWAN_LOCAL del /f /q "%DIWAN_PS1%" >nul 2>&1
echo.
echo ERROR: The installer did not arrive complete (an interrupted download, or a proxy or
echo antivirus that changed it), so it was not run. Run Diwan-Setup.bat again.
goto :end

:end
pause
exit /b %DIWAN_RC%
