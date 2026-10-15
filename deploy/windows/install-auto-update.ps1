<#
.SYNOPSIS
    Registers a Windows scheduled task that runs update.ps1 -Auto every hour
    (only while you are logged in, so GitHub sign-in from Git Credential
    Manager is available). Nothing changes unless there is a new update.
#>
param([int]$EveryMinutes = 60)

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

$script = Join-Path $PSScriptRoot 'update.ps1'
$name = 'Alostaz Auto Update'

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -Auto' -f $script)
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
$principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Pulls new Alostaz updates from GitHub and prepares them (deploy/windows/update.ps1).' -Force | Out-Null

Write-Host "تم تفعيل التحديث التلقائي: كل $EveryMinutes دقيقة (أثناء تسجيل دخولك إلى ويندوز)." -ForegroundColor Green
Write-Host 'السجل: storage\logs\update.log — للإيقاف: uninstall-auto-update.ps1' -ForegroundColor Gray
