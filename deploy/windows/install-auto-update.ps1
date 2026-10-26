<#
.SYNOPSIS
    Registers the Windows scheduled tasks of the system:
      - "Diwan Auto Update": update.ps1 -Auto every hour (while you are
        logged in, so GitHub sign-in from Git Credential Manager is available).
      - "Diwan Scheduler": php artisan schedule:run every minute (backups,
        ZATCA reporting, recurring entries, depreciation, alerts).
      - "Diwan Queue": processes queued jobs every minute (ZATCA submission,
        e-mails, webhooks, exports).
    Run as administrator so the scheduler and queue keep running when nobody
    is logged in; otherwise they run while you are logged in.
#>
param([int]$EveryMinutes = 60)

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

$script = Join-Path $PSScriptRoot 'update.ps1'

# Tasks registered under the product's former name would run twice beside the new ones.
foreach ($old in @('Alostaz Auto Update', 'Alostaz Scheduler', 'Alostaz Queue')) {
    Unregister-ScheduledTask -TaskName $old -Confirm:$false -ErrorAction SilentlyContinue
}
$name = 'Diwan Auto Update'

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -Auto' -f $script)
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
$principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Pulls new Diwan updates from GitHub and prepares them (deploy/windows/update.ps1).' -Force | Out-Null

Write-Host "تم تفعيل التحديث التلقائي: كل $EveryMinutes دقيقة (أثناء تسجيل دخولك إلى ويندوز)." -ForegroundColor Green

# ---------------------------------------------------------------- scheduler and queue
$root = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$artisan = Join-Path $root 'artisan'
$php = Get-ChildItem 'C:\MAMP\bin\php\php8*\php.exe' -ErrorAction SilentlyContinue |
    Where-Object { $_.Directory.Name -match '^php8\.(\d+)' -and [int]$Matches[1] -ge 2 } |
    Sort-Object { [version]($_.Directory.Name -replace '^php', '') } -Descending | Select-Object -First 1 -ExpandProperty FullName
if (-not $php) { $php = (Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1).Source }

if (-not $php) {
    Write-Host 'لم يُعثر على PHP 8.2+؛ لم تُسجَّل مهمتا المُجدول والطوابير. فعّل PHP في MAMP ثم أعد التشغيل.' -ForegroundColor Red
} else {
    $hidden = Join-Path $PSScriptRoot 'run-hidden.vbs'
    $isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    # S4U runs without a logged-in user (needs administrator to register); otherwise while logged in.
    $runAs = if ($isAdmin) { New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType S4U -RunLevel Limited }
             else { New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited }
    $everyMinute = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $minuteSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

    $jobs = @{
        'Diwan Scheduler' = @('schedule:run')
        'Diwan Queue' = @('queue:work', '--stop-when-empty', '--queue=zatca,default,webhooks,exports', '--tries=5', '--max-time=50')
    }
    foreach ($task in $jobs.Keys) {
        $arguments = ('"{0}" "{1}" "{2}" {3}' -f $hidden, $php, $artisan, ($jobs[$task] -join ' '))
        $taskAction = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument $arguments -WorkingDirectory $root
        Register-ScheduledTask -TaskName $task -Action $taskAction -Trigger $everyMinute -Settings $minuteSettings -Principal $runAs -Description "Diwan: php artisan $($jobs[$task] -join ' ')" -Force | Out-Null
    }
    $mode = if ($isAdmin) { 'تعمل دائماً حتى بدون تسجيل دخول' } else { 'تعمل أثناء تسجيل دخولك (شغّل كمسؤول لتعمل دائماً)' }
    Write-Host "تم تفعيل المُجدول والطوابير كل دقيقة ($mode)." -ForegroundColor Green
    Write-Host 'تأكد أن QUEUE_CONNECTION=database في ملف .env (MAMP لا يحتوي Redis).' -ForegroundColor Gray
}
Write-Host 'السجل: storage\logs\update.log — للإيقاف: uninstall-auto-update.ps1' -ForegroundColor Gray
