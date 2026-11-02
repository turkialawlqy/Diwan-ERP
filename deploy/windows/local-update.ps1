<#
.SYNOPSIS
    Diwan ERP - updates the Windows install (made by Diwan-Setup.bat) to the latest release.

.DESCRIPTION
    Runs every day at 3:30 (task "Diwan Update") and from Start menu > Diwan >
    Update Diwan. Downloads the latest release next to the running one, takes
    a database backup when the update changes the database, switches over, and
    switches back by itself if the system does not answer afterwards.
    Console messages are English: Windows consoles do not render Arabic well.
#>
param([switch]$Force)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Root = Split-Path (Split-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) -Parent) -Parent
if (-not (Test-Path (Join-Path $Root 'diwan.json'))) { $Root = 'C:\Diwan' }
$config = Get-Content (Join-Path $Root 'diwan.json') -Raw | ConvertFrom-Json
$PackageUrl = if ($env:DIWAN_PACKAGE) { $env:DIWAN_PACKAGE } else { 'https://github.com/turkialawlqy/Diwan-ERP/releases/latest/download/diwan-erp.zip' }
$Php = Join-Path $Root 'bin\php\php.exe'
$Current = Join-Path $Root 'current'
$Releases = Join-Path $Root 'releases'
$Shared = Join-Path $Root 'shared'
$Keep = 3

New-Item -ItemType Directory -Force -Path (Join-Path $Root 'logs') | Out-Null
Start-Transcript -Path (Join-Path $Root 'logs\update.log') -Append | Out-Null

# What the dashboard shows the owner (state: running | done | failed | uptodate); the dashboard
# asks for an update by dropping a file in update-queue and starting this task.
$StatusFile = Join-Path $Shared 'storage\app\update-status.json'
Remove-Item (Join-Path $Shared 'storage\app\update-queue\*') -Force -ErrorAction SilentlyContinue
function Write-Status($state, $message = '') {
    try {
        @{ state = $state; from = "$script:installed"; to = "$script:version"; message = $message; at = [int][double]::Parse((Get-Date -UFormat %s)) } |
            ConvertTo-Json -Compress | Set-Content -Path $StatusFile -Encoding ASCII
    } catch { }
}

function Set-Junction($link, $target) {
    if (Test-Path $link) { cmd /c rmdir "$link" | Out-Null }
    cmd /c mklink /J "$link" "$target" | Out-Null
}
# Runs an artisan command in a release folder; returns only its exit code (output goes to the console/log).
function Artisan($dir) {
    Push-Location $dir
    try { & $Php artisan @args | Out-Host; return $LASTEXITCODE } finally { Pop-Location }
}
# PHP workers and background processes start again from the new release (run-web / run-worker loops).
function Restart-Php {
    Get-Process php-cgi -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match 'queue:work|schedule:work' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}
function Healthy {
    for ($i = 0; $i -lt 15; $i++) {
        Start-Sleep -Seconds 2
        try { if ((Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$($config.port)/up" -TimeoutSec 10).StatusCode -eq 200) { return $true } } catch { }
    }
    return $false
}
function Remove-Release($dir) {
    # A release's storage folders are junctions to the shared data: remove the links, never what they point to.
    foreach ($l in @('storage', 'public\storage')) { $p = Join-Path $dir $l; if (Test-Path $p) { cmd /c rmdir "$p" | Out-Null } }
    Remove-Item $dir -Recurse -Force -ErrorAction SilentlyContinue
}
# Queue workers set up by earlier installers served only the "default" queue: tax-authority
# submissions (zatca), webhooks and data exports waited in the jobs table for good.
$QueueArg = '--queue=zatca,default,webhooks,exports'
function Update-QueueWorker {
    $worker = Join-Path $Root 'scripts\run-worker.ps1'
    if (-not (Test-Path $worker)) { return }
    $text = [IO.File]::ReadAllText($worker)
    if ($text.Contains("'$QueueArg'")) { return }
    $text = ($text -replace "'--queue=[^']*', ", '').Replace("'queue:work', 'database', ", "'queue:work', 'database', '$QueueArg', ")
    if (-not $text.Contains("'$QueueArg'")) { throw "$worker does not start the queue worker the way the installer wrote it." }
    [IO.File]::WriteAllText($worker, $text, (New-Object System.Text.UTF8Encoding($true)))
    # The "Diwan Worker" loop reads its script only when it starts: stop it and what it started, then start it again.
    Stop-ScheduledTask -TaskName 'Diwan Worker' -ErrorAction SilentlyContinue
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match 'queue:work|schedule:work' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-ScheduledTask -TaskName 'Diwan Worker'
    Write-Host 'The queue worker now serves every queue.' -ForegroundColor Green
}
try { Update-QueueWorker } catch { Write-Host "Could not set up the queue worker again: $($_.Exception.Message)" -ForegroundColor Yellow }

$new = $null
$switched = $false
try {
    $running = (Get-Item $Current).Target
    if ($running -is [array]) { $running = $running[0] }
    $installed = (Get-Content (Join-Path $Current 'VERSION') -Raw).Trim()

    Write-Status 'running' 'Downloading'
    Write-Host 'Downloading the latest release...'
    $zip = Join-Path $env:TEMP 'diwan-update.zip'
    if (Test-Path $PackageUrl) { Copy-Item $PackageUrl $zip -Force } else {
        Invoke-WebRequest -UseBasicParsing -Uri $PackageUrl -OutFile $zip
        $haveSha = $true
        try { Invoke-WebRequest -UseBasicParsing -Uri "$PackageUrl.sha256" -OutFile "$zip.sha256" } catch { $haveSha = $false }
        if ($haveSha -and (Get-FileHash $zip -Algorithm SHA256).Hash -ne (Get-Content "$zip.sha256" -Raw).Trim().ToUpper()) { throw 'The downloaded update is corrupted. Try again.' }
    }
    $unpack = Join-Path $env:TEMP 'diwan-update'
    if (Test-Path $unpack) { Remove-Item $unpack -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $unpack | Out-Null
    & (Join-Path $env:SystemRoot 'System32\tar.exe') -xf $zip -C $unpack
    if ($LASTEXITCODE -ne 0) { Expand-Archive -Path $zip -DestinationPath $unpack -Force }
    $version = (Get-Content (Join-Path $unpack 'diwan-erp\VERSION') -Raw).Trim()
    if ($version -eq $installed -and -not $Force) {
        Write-Host "Diwan is up to date (version $installed)." -ForegroundColor Green
        Write-Status 'uptodate' 'Up to date'
        Stop-Transcript | Out-Null
        exit 0
    }

    Write-Status 'running' 'Installing'
    Write-Host "Preparing version $version (the system keeps running meanwhile)..."
    $new = Join-Path $Releases ((Get-Date -Format 'yyyyMMddHHmmss') + "-$version")
    Move-Item (Join-Path $unpack 'diwan-erp') $new
    Remove-Item (Join-Path $new 'storage') -Recurse -Force
    Set-Junction (Join-Path $new 'storage') (Join-Path $Shared 'storage')
    Set-Junction (Join-Path $new 'public\storage') (Join-Path $Shared 'storage\app\public')
    Copy-Item (Join-Path $Shared '.env') (Join-Path $new '.env') -Force

    # Until the switch, the running version is untouched.
    if ((Artisan $new optimize) -ne 0) { throw 'Could not prepare the new version.' }
    Push-Location $new
    try { $pending = [bool]((& $Php artisan migrate:status --pending) -match 'Pending') } finally { Pop-Location }
    if ($pending) {
        Write-Host 'Backing up the database before updating it...'
        if ((Artisan $new erp:backup --tag=preupdate) -ne 0) { throw 'The backup failed, so the update was cancelled.' }
        if ((Artisan $new migrate --force) -ne 0) { throw 'Could not update the database.' }
    }

    Set-Junction $Current $new
    $switched = $true
    Restart-Php
    Artisan $new queue:restart | Out-Null
    if (-not (Healthy)) {
        Set-Junction $Current $running
        Restart-Php
        Remove-Release $new
        throw "Version $version did not start; switched back to $installed automatically."
    }

    Get-ChildItem $Releases -Directory | Sort-Object Name -Descending | Select-Object -Skip $Keep |
        Where-Object { $_.FullName -ne $new } | ForEach-Object { Remove-Release $_.FullName }
    Write-Host "Updated from $installed to $version." -ForegroundColor Green
    Write-Status 'done' 'Updated'
} catch {
    Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
    Write-Status 'failed' $_.Exception.Message
    if ($new -and -not $switched -and (Test-Path $new)) { Remove-Release $new }
    Stop-Transcript | Out-Null
    exit 1
} finally {
    Remove-Item (Join-Path $env:TEMP 'diwan-update.zip*'), (Join-Path $env:TEMP 'diwan-update') -Recurse -Force -ErrorAction SilentlyContinue
}
Stop-Transcript | Out-Null
