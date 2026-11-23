#Requires -Version 5.1
<#
.SYNOPSIS
    End-to-end test of the Windows installer, only for a throwaway Windows (a CI runner, or a virtual
    machine made for it): it installs Diwan in C:\Diwan, damages it on purpose and installs again.

.DESCRIPTION
    1. Runs this checkout's Diwan-Setup.bat /local (with its install.ps1) from a folder whose name has
       spaces and brackets, like a second download "Diwan-Setup (1).bat", with the latest release.
    2. Checks the exit code, Diwan answering, the database service, the tasks, Caddy, the firewall
       rule, the shortcuts and their English names, the queues of the worker, the install log.
    3. Leaves what a run again must keep or repair: a table and an uploaded file, a caddy.exe that
       does not run (as an interrupted download leaves it), the Arabic shortcuts of earlier installers.
    4. Runs the setup again and checks everything again: the data, settings and passwords are the
       same, Caddy works again, the earlier shortcuts made way for the English ones.
        powershell -NoProfile -ExecutionPolicy Bypass -File tests\windows\Test-FullInstall.ps1 -ThrowawayWindows
    Keep this file UTF-8 with BOM (Windows PowerShell 5.1 reads its Arabic names otherwise).
#>
param([switch]$ThrowawayWindows)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
if (-not $ThrowawayWindows) {
    throw 'This test installs Diwan in C:\Diwan and damages it on purpose: run it only on a throwaway Windows, with -ThrowawayWindows.'
}
$Root = 'C:\Diwan'
if (Test-Path -LiteralPath $Root) { throw "$Root exists: this is not a fresh throwaway Windows." }

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$folder = Join-Path $env:PUBLIC 'Diwan setup (test)'
$bat = Join-Path $folder 'Diwan-Setup (1).bat'
$failures = New-Object 'System.Collections.Generic.List[string]'

function Check($ok, $what) {
    if ($ok) { Write-Host "  ok    $what" -ForegroundColor Green }
    else { Write-Host "  FAIL  $what" -ForegroundColor Red; $failures.Add($what) }
}

# cmd /c ""<bat>" /local < NUL": the pause at its end reads NUL and returns at once. It waits for cmd
# alone: Start-Process -Wait of Windows PowerShell 5.1 also waits for what cmd started and left running
# (the browser the installer opens at its end), which kept this test waiting until the job's time limit.
function Invoke-Setup {
    Write-Host ''
    Write-Host "==== $bat /local" -ForegroundColor Cyan
    $process = Start-Process -FilePath $env:ComSpec -ArgumentList ('/d /c ""' + $bat + '" /local < NUL"') -PassThru -NoNewWindow
    $null = $process.Handle # without it, ExitCode stays empty once the process has ended
    if (-not $process.WaitForExit(30 * 60000)) {
        Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
        throw "Diwan-Setup.bat did not end within 30 minutes: see $Root\logs\install.log"
    }
    if ($process.ExitCode -ne 0) { throw "Diwan-Setup.bat ended with exit code $($process.ExitCode): see $Root\logs\install.log" }
    Write-Host '  ok    Diwan-Setup.bat ended with exit code 0' -ForegroundColor Green
}

function Invoke-Db($config, $query) {
    $ErrorActionPreference = 'Continue'
    $out = & "$Root\bin\mariadb\bin\mariadb.exe" -uroot "-p$($config.db_root_password)" -h 127.0.0.1 "-P$($config.db_port)" -N -B diwan -e $query 2>$null
    return ("$out").Trim()
}

function Test-Installed {
    Write-Host ''
    Write-Host '==== Checks' -ForegroundColor Cyan
    $config = Get-Content -LiteralPath "$Root\diwan.json" -Raw | ConvertFrom-Json
    $up = $null
    try { $up = (Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$($config.port)/up" -TimeoutSec 30).StatusCode } catch { }
    Check ($up -eq 200) "Diwan answers on port $($config.port) (/up)"
    $page = $null
    try { $page = (Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$($config.port)/" -TimeoutSec 30).StatusCode } catch { }
    Check ($page -eq 200) 'its first page opens'
    Check ((Get-Service DiwanDB -ErrorAction SilentlyContinue).Status -eq 'Running') 'the database service DiwanDB runs'
    foreach ($task in 'Diwan Web', 'Diwan Worker', 'Diwan Update') { Check ([bool](Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue)) "task $task" }
    $caddy = & "$Root\bin\caddy.exe" version
    Check ($LASTEXITCODE -eq 0 -and "$caddy" -match '^v\d+\.\d+\.\d+') "caddy.exe runs ($caddy)"
    Check (-not (Test-Path -LiteralPath "$Root\bin\caddy.exe.damaged")) 'no caddy.exe is left set aside'
    Check ([bool](Get-NetFirewallRule -DisplayName 'Diwan ERP' -ErrorAction SilentlyContinue)) 'firewall rule Diwan ERP'
    $desktop = [Environment]::GetFolderPath('CommonDesktopDirectory')
    $menu = Join-Path ([Environment]::GetFolderPath('CommonPrograms')) 'Diwan ERP'
    foreach ($shortcut in @((Join-Path $desktop 'Diwan ERP.url'), (Join-Path $menu 'Open Diwan ERP.url'), (Join-Path $menu 'Update Diwan.lnk'),
            (Join-Path $menu 'Backup Diwan.lnk'), (Join-Path $menu 'Restart Diwan.lnk'), (Join-Path $menu 'Uninstall Diwan.lnk'))) {
        Check (Test-Path -LiteralPath $shortcut) "shortcut $shortcut"
    }
    Check (Select-String -LiteralPath "$Root\scripts\run-worker.ps1" -SimpleMatch "'--queue=zatca,default,webhooks,exports'" -Quiet) 'the queue worker serves zatca, default, webhooks and exports'
    Check (Select-String -LiteralPath "$Root\logs\install.log" -SimpleMatch 'Installed successfully' -Quiet) 'logs\install.log has the run'
    return $config
}

New-Item -ItemType Directory -Force -Path $folder | Out-Null
Copy-Item -LiteralPath (Join-Path $repo 'deploy\windows\Diwan-Setup.bat') -Destination $bat
Copy-Item -LiteralPath (Join-Path $repo 'deploy\windows\install.ps1') -Destination (Join-Path $folder 'install.ps1')

Invoke-Setup
$before = Test-Installed

Write-Host ''
Write-Host '==== Data to keep, damage to repair' -ForegroundColor Cyan
Invoke-Db $before "CREATE TABLE ci_kept (v VARCHAR(20)); INSERT INTO ci_kept VALUES ('kept');" | Out-Null
Check ((Invoke-Db $before 'SELECT v FROM ci_kept') -eq 'kept') 'a table with a row'
$upload = "$Root\shared\storage\app\public\ci-kept.txt"
Set-Content -LiteralPath $upload -Value 'kept'
$envHash = (Get-FileHash -LiteralPath "$Root\shared\.env").Hash
$secretsHash = (Get-FileHash -LiteralPath "$Root\secrets.txt").Hash
# A caddy.exe that does not run, as an interrupted or refused download leaves it.
Stop-ScheduledTask -TaskName 'Diwan Web'
Get-Process caddy -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Seconds 2
[IO.File]::WriteAllText("$Root\bin\caddy.exe", '401 Unauthorized: Please log in or use an API key to build Caddy.')
# The Arabic shortcuts of earlier installers.
$desktop = [Environment]::GetFolderPath('CommonDesktopDirectory')
$oldMenu = Join-Path ([Environment]::GetFolderPath('CommonPrograms')) 'الديوان'
New-Item -ItemType Directory -Force -Path $oldMenu | Out-Null
$old = @((Join-Path $desktop 'الديوان.url'), (Join-Path $oldMenu 'فتح الديوان.url'), (Join-Path $oldMenu 'تحديث الديوان.lnk'))
foreach ($file in $old) { [IO.File]::WriteAllText($file, "[InternetShortcut]`r`nURL=http://localhost:$($before.port)`r`n") }

Invoke-Setup
$after = Test-Installed
Check (Select-String -LiteralPath "$Root\logs\install.log" -SimpleMatch 'does not run (damaged or incomplete)' -Quiet) 'the run again found the damaged caddy.exe and replaced it'
Check ((Invoke-Db $after 'SELECT v FROM ci_kept') -eq 'kept') 'the database kept its data'
Check ((Get-Content -LiteralPath $upload -Raw).Trim() -eq 'kept') 'the uploaded files stayed'
Check ((Get-FileHash -LiteralPath "$Root\shared\.env").Hash -eq $envHash) 'shared\.env is unchanged'
Check ((Get-FileHash -LiteralPath "$Root\secrets.txt").Hash -eq $secretsHash) 'secrets.txt is unchanged'
foreach ($key in 'port', 'db_port', 'db_root_password', 'db_password', 'backup_password') { Check ("$($after.$key)" -eq "$($before.$key)") "diwan.json kept $key" }
foreach ($file in $old) { Check (-not (Test-Path -LiteralPath $file)) "the earlier shortcut $file made way" }
Check (-not (Test-Path -LiteralPath $oldMenu)) 'the Start menu folder of earlier installers is gone'

Write-Host ''
if ($failures.Count) { throw "$($failures.Count) check(s) failed: $($failures -join '; ')" }
Write-Host 'All checks passed.' -ForegroundColor Green
