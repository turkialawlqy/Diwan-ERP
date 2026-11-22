<#
.SYNOPSIS
    Diwan ERP - the Start menu tools of the Windows install: update, backup, restart, uninstall.
    Console messages are English: Windows consoles do not render Arabic well.
#>
param([ValidateSet('update', 'backup', 'restart', 'uninstall')][string]$Action = 'restart')

$ErrorActionPreference = 'Stop'

# The tools change services and tasks: ask Windows for administrator rights first.
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Action $Action"
    exit
}

$Root = Split-Path (Split-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) -Parent) -Parent
if (-not (Test-Path (Join-Path $Root 'diwan.json'))) { $Root = 'C:\Diwan' }
$Php = Join-Path $Root 'bin\php\php.exe'
$Current = Join-Path $Root 'current'
$config = Get-Content (Join-Path $Root 'diwan.json') -Raw | ConvertFrom-Json

function Done($text) { Write-Host ''; Write-Host $text -ForegroundColor Green; Write-Host ''; Read-Host 'Press Enter to close' | Out-Null }
function Stop-Diwan {
    foreach ($t in @('Diwan Web', 'Diwan Worker')) { Stop-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue }
    Get-Process caddy, php-cgi -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match 'queue:work|schedule:work' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

switch ($Action) {
    'update' {
        & (Join-Path $PSScriptRoot 'local-update.ps1')
        Done 'Update finished. Details: C:\Diwan\logs\update.log'
    }
    'backup' {
        Push-Location $Current
        try { & $Php artisan erp:backup } finally { Pop-Location }
        Done "Backup saved in $Root\shared\storage\app\backups (copy it to a USB drive or the cloud too)."
    }
    'restart' {
        # Picks up changes made to C:\Diwan\shared\.env (for example mail settings).
        Copy-Item (Join-Path $Root 'shared\.env') (Join-Path $Current '.env') -Force
        Push-Location $Current
        try { & $Php artisan optimize | Out-Null } finally { Pop-Location }
        Stop-Diwan
        Start-Service DiwanDB -ErrorAction SilentlyContinue
        foreach ($t in @('Diwan Web', 'Diwan Worker')) { Start-ScheduledTask -TaskName $t }
        Start-Sleep -Seconds 5
        Start-Process "http://localhost:$($config.port)"
        Done 'Diwan restarted.'
    }
    'uninstall' {
        Write-Host 'This removes Diwan from this computer.' -ForegroundColor Yellow
        $answer = Read-Host 'Type YES to continue'
        if ($answer -ne 'YES') { exit }
        $keep = Read-Host 'Keep your data (database, uploaded files, backups) in C:\Diwan for later? Type NO to delete everything'
        Stop-Diwan
        foreach ($t in @('Diwan Web', 'Diwan Worker', 'Diwan Update')) { Unregister-ScheduledTask -TaskName $t -Confirm:$false -ErrorAction SilentlyContinue }
        Stop-Service DiwanDB -ErrorAction SilentlyContinue
        & sc.exe delete DiwanDB | Out-Null
        Remove-NetFirewallRule -DisplayName 'Diwan ERP' -ErrorAction SilentlyContinue
        # The shortcuts: their English names, and the Arabic ones of earlier installs.
        $desktop = [Environment]::GetFolderPath('CommonDesktopDirectory')
        $programs = [Environment]::GetFolderPath('CommonPrograms')
        if ($desktop) { foreach ($name in @('Diwan ERP.url', 'Diwan ERP.lnk', 'الديوان.url')) { Remove-Item -LiteralPath (Join-Path $desktop $name) -Force -ErrorAction SilentlyContinue } }
        if ($programs) { foreach ($name in @('Diwan ERP', 'الديوان')) { Remove-Item -LiteralPath (Join-Path $programs $name) -Recurse -Force -ErrorAction SilentlyContinue } }
        Set-Location $env:TEMP
        if ($keep -eq 'NO') {
            # Junctions first, so deleting a release never follows them into the shared data twice.
            Get-ChildItem (Join-Path $Root 'releases') -Directory -ErrorAction SilentlyContinue | ForEach-Object {
                foreach ($l in @('storage', 'public\storage')) { $p = Join-Path $_.FullName $l; if (Test-Path $p) { cmd /c rmdir "$p" | Out-Null } }
            }
            cmd /c rmdir (Join-Path $Root 'current') | Out-Null
            Remove-Item $Root -Recurse -Force -ErrorAction SilentlyContinue
            Done 'Diwan and all its data were removed.'
        } else {
            Done "Diwan was removed. Your data is still in $Root (data, shared, secrets.txt): running Diwan-Setup.bat again brings everything back."
        }
    }
}
