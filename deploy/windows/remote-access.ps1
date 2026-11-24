<#
.SYNOPSIS
    Diwan ERP - internet access for a Windows install (Cloudflare Tunnel or Tailscale).

.DESCRIPTION
    Started by the dashboard through the SYSTEM task "Diwan Remote Access" when the owner drops a request in
      <install>\shared\storage\app\remote-access-queue\request   (JSON: mode, domain, secret)
    Installs and starts the connector, tunes .env (HTTPS behind the tunnel, trusted proxy) and the Caddyfile,
    restarts the web processes, deletes the request (it holds a secret) and writes
      <install>\shared\storage\app\remote-access-status.json

    Options (environment): DIWAN_DIR=C:\Diwan
      DIWAN_DRY_RUN=1  nothing is downloaded, installed or restarted; the commands are listed in DIWAN_DRY_LOG (used by the tests)
#>
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch { }

# <install>\current\deploy\windows (this file, run through the "current" junction), three up in
# the install folder; DIWAN_DIR overrides it (tests).
function Find-DiwanRoot([string]$From) {
    if ($env:DIWAN_DIR) { return $env:DIWAN_DIR }
    $dir = $From
    for ($i = 0; $i -lt 3 -and $dir; $i++) { $dir = Split-Path -Parent $dir }
    if ($dir -and (Test-Path (Join-Path $dir 'diwan.json'))) { return $dir }
    return 'C:\Diwan'
}
$Root = Find-DiwanRoot $PSScriptRoot
$Dry = ($env:DIWAN_DRY_RUN -eq '1')
$DryLog = $env:DIWAN_DRY_LOG
$Shared = Join-Path $Root 'shared'
$EnvFile = Join-Path $Shared '.env'
$Queue = Join-Path (Join-Path (Join-Path $Shared 'storage') 'app') 'remote-access-queue'
$Request = Join-Path $Queue 'request'
$StatusFile = Join-Path (Join-Path (Join-Path $Shared 'storage') 'app') 'remote-access-status.json'
$Caddyfile = Join-Path $Root 'Caddyfile'
$Bin = Join-Path $Root 'bin'
$Php = Join-Path (Join-Path $Bin 'php') 'php.exe'
$Current = Join-Path $Root 'current'
$Utf8 = New-Object System.Text.UTF8Encoding($false)

$script:Mode = 'none'; $script:Domain = ''; $script:Secret = ''; $script:Url = ''; $script:Ip = ''

# What the dashboard shows (state: running | done | failed). Values never contain quotes: they are validated below.
function Write-Status($state, $message = '') {
    try {
        $json = [ordered]@{ state = $state; mode = $script:Mode; domain = $script:Domain; url = $script:Url; ip = $script:Ip; message = $message; at = [int][double]::Parse((Get-Date -UFormat %s)) } |
            ConvertTo-Json -Compress
        $tmp = "$StatusFile.tmp"
        [IO.File]::WriteAllText($tmp, $json, $Utf8)
        Move-Item -LiteralPath $tmp -Destination $StatusFile -Force
    } catch { }
}
# The request holds a secret: it is overwritten, then deleted.
function Clear-Request {
    if (Test-Path -LiteralPath $Request) {
        try { [IO.File]::WriteAllText($Request, ('0' * 64), $Utf8) } catch { }
        Remove-Item -LiteralPath $Request -Force -ErrorAction SilentlyContinue
    }
}
function Stop-WithError($message) {
    Write-Host "x $message"
    Clear-Request
    Write-Status 'failed' $message
    exit 1
}
# Nothing is installed, started or restarted in a dry run.
function Invoke-Step([string]$label, [scriptblock]$action) {
    if ($Dry) {
        if ($DryLog) { Add-Content -LiteralPath $DryLog -Value "[dry-run] $label" -Encoding UTF8 }
    } else {
        & $action
    }
}

if (-not (Test-Path -LiteralPath $Request)) { exit 0 }

# One run at a time.
$mutex = New-Object System.Threading.Mutex($false, 'Global\DiwanRemoteAccess')
if (-not $mutex.WaitOne(0)) { exit 0 }

try {
    # The request is JSON written by the app.
    $req = Get-Content -LiteralPath $Request -Raw -Encoding UTF8 | ConvertFrom-Json
    $script:Mode = [string]$req.mode; $script:Domain = [string]$req.domain; $script:Secret = [string]$req.secret
    Write-Status 'running' 'Applying'

    # Whatever came through, only these characters are ever used below.
    if ($script:Mode -notmatch '^(none|cloudflare|tailscale)$') { Stop-WithError 'Unknown mode.' }
    if ($script:Domain -and $script:Domain -notmatch '^[a-z0-9.-]{4,253}$') { Stop-WithError 'Invalid domain.' }
    if ($script:Secret -and $script:Secret -notmatch '^[A-Za-z0-9_=.-]{10,2000}$') { Stop-WithError 'Invalid token.' }
    if (-not (Test-Path -LiteralPath $EnvFile)) { Stop-WithError "Settings file not found: $EnvFile" }

    $port = 8000
    $configFile = Join-Path $Root 'diwan.json'
    if (Test-Path -LiteralPath $configFile) { try { $port = [int](Get-Content -LiteralPath $configFile -Raw | ConvertFrom-Json).port } catch { } }

    # ---- .env: replace the line or add it (the file keeps its own line endings and no BOM)
    function Read-Lines($path) {
        $text = [IO.File]::ReadAllText($path)
        $nl = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
        return @{ nl = $nl; lines = @($text -split "`r?`n") }
    }
    function Write-Lines($path, $doc) {
        $lines = @($doc.lines)
        while ($lines.Count -gt 0 -and $lines[$lines.Count - 1] -eq '') { $lines = $lines[0..($lines.Count - 2)] }
        [IO.File]::WriteAllText($path, (($lines -join $doc.nl) + $doc.nl), $Utf8)
    }
    function Set-Env($key, $value) {
        $doc = Read-Lines $EnvFile
        $found = $false
        $doc.lines = @($doc.lines | ForEach-Object { if ($_ -match ('^' + [regex]::Escape($key) + '=')) { $found = $true; "$key=$value" } else { $_ } })
        if (-not $found) { $doc.lines = @($doc.lines) + "$key=$value" }
        Write-Lines $EnvFile $doc
    }
    function Remove-Env($key) {
        $doc = Read-Lines $EnvFile
        $doc.lines = @($doc.lines | Where-Object { $_ -notmatch ('^' + [regex]::Escape($key) + '=') })
        Write-Lines $EnvFile $doc
    }
    # Behind a tunnel only this computer may reach the web server; otherwise the office network can.
    function Set-Bind($address) {
        if (-not (Test-Path -LiteralPath $Caddyfile)) { return }
        $doc = Read-Lines $Caddyfile
        $lines = @($doc.lines | Where-Object { $_ -notmatch '^\s*bind\s+' })
        if ($address) {
            $out = @()
            $done = $false
            foreach ($line in $lines) {
                $out += $line
                if (-not $done -and $line -match '^:\d+\s*\{\s*$') { $out += "`tbind $address"; $done = $true }
            }
            $lines = $out
        }
        $doc.lines = $lines
        Write-Lines $Caddyfile $doc
    }
    function Set-LanEnv { # back to the office-network setup written by the installer
        Set-Env 'APP_URL' "http://localhost:$port"; Set-Env 'APP_DOMAIN' 'localhost'
        Set-Env 'FORCE_HTTPS' 'false'; Set-Env 'SESSION_SECURE_COOKIE' 'false'; Remove-Env 'TRUSTED_PROXIES'
        Set-Bind $null
    }

    $cloudflared = Join-Path $Bin 'cloudflared.exe'
    $programFiles = if ($env:ProgramFiles) { $env:ProgramFiles } else { 'C:\Program Files' }
    $tailscale = Join-Path (Join-Path $programFiles 'Tailscale') 'tailscale.exe'
    function Get-Arch { if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { 'arm64' } elseif ([Environment]::Is64BitOperatingSystem) { 'amd64' } else { '386' } }
    function Get-File($url, $out) {
        Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $out -TimeoutSec 600
        if (-not (Test-Path -LiteralPath $out) -or (Get-Item -LiteralPath $out).Length -lt 100000) { throw "The download from $url is incomplete." }
    }

    function Stop-Connectors {
        if (Test-Path -LiteralPath $cloudflared) { Invoke-Step 'cloudflared service uninstall' { & $cloudflared service uninstall 2>&1 | Out-Null } }
        if (Test-Path -LiteralPath $tailscale) { Invoke-Step 'tailscale down' { & $tailscale down 2>&1 | Out-Null } }
        Invoke-Step 'remove the Tailscale firewall rule' { Remove-NetFirewallRule -DisplayName 'Diwan ERP Tailscale' -ErrorAction SilentlyContinue }
    }

    switch ($script:Mode) {
        'none' {
            Stop-Connectors
            Set-LanEnv
            $script:Url = ''
        }
        'cloudflare' {
            if (-not $script:Domain -or -not $script:Secret) { Stop-WithError 'Domain and tunnel token are required.' }
            if (-not (Test-Path -LiteralPath $cloudflared)) {
                $arch = Get-Arch
                if ($arch -eq 'arm64') { Stop-WithError 'Cloudflare Tunnel needs a 64-bit Intel/AMD or 32-bit Windows; this computer is ARM.' }
                Invoke-Step "download cloudflared-windows-$arch.exe" {
                    try { Get-File "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-$arch.exe" $cloudflared }
                    catch { Remove-Item -LiteralPath $cloudflared -Force -ErrorAction SilentlyContinue; Stop-WithError 'Could not download cloudflared (internet connection?).' }
                }
            }
            Invoke-Step 'cloudflared service uninstall' { & $cloudflared service uninstall 2>&1 | Out-Null }
            Invoke-Step 'cloudflared service install <token>' {
                & $cloudflared service install $script:Secret 2>&1 | Out-Null
                if ($LASTEXITCODE -ne 0) { Stop-WithError 'cloudflared did not accept the token.' }
            }
            # Behind the tunnel the app is HTTPS to the outside; only this computer may reach the local port.
            Set-Env 'APP_URL' "https://$($script:Domain)"; Set-Env 'APP_DOMAIN' $script:Domain
            Set-Env 'FORCE_HTTPS' 'true'; Set-Env 'SESSION_SECURE_COOKIE' 'true'; Set-Env 'TRUSTED_PROXIES' '127.0.0.1'
            Set-Bind '127.0.0.1'
            $script:Url = "https://$($script:Domain)"
        }
        'tailscale' {
            if (-not $script:Secret) { Stop-WithError 'Auth key is required.' }
            if (-not (Test-Path -LiteralPath $tailscale)) {
                $arch = Get-Arch
                $msi = Join-Path $env:TEMP 'tailscale-setup.msi'
                $msiArch = if ($arch -eq '386') { 'x86' } else { $arch }
                Invoke-Step "download and install tailscale-setup-latest-$msiArch.msi" {
                    try { Get-File "https://pkgs.tailscale.com/stable/tailscale-setup-latest-$msiArch.msi" $msi }
                    catch { Stop-WithError 'Could not download Tailscale (internet connection?).' }
                    $p = Start-Process -FilePath 'msiexec.exe' -ArgumentList '/i', "`"$msi`"", '/qn', '/norestart' -Wait -PassThru
                    if ($p.ExitCode -ne 0 -and $p.ExitCode -ne 3010) { Stop-WithError "Could not install Tailscale (msiexec exit code $($p.ExitCode))." }
                    Remove-Item -LiteralPath $msi -Force -ErrorAction SilentlyContinue
                }
            }
            Invoke-Step 'tailscale up --authkey=<key> --accept-dns=false --unattended' {
                & $tailscale up "--authkey=$($script:Secret)" '--accept-dns=false' '--unattended' 2>&1 | Out-Null
                if ($LASTEXITCODE -ne 0) { Stop-WithError 'Tailscale did not accept the key.' }
            }
            if ($Dry) { $script:Ip = '100.64.0.1' } else { $script:Ip = ((& $tailscale ip -4 2>$null) | Select-Object -First 1) }
            # Traffic inside Tailscale is encrypted already: keep plain HTTP on the private address, no public link.
            Set-LanEnv
            Invoke-Step 'allow the Tailscale interface on the web port' {
                Remove-NetFirewallRule -DisplayName 'Diwan ERP Tailscale' -ErrorAction SilentlyContinue
                New-NetFirewallRule -DisplayName 'Diwan ERP Tailscale' -Direction Inbound -Protocol TCP -LocalPort $port -Action Allow -InterfaceAlias 'Tailscale' | Out-Null
            }
            if ($script:Ip) { $script:Url = "http://$($script:Ip):$port" }
        }
    }

    Invoke-Step 'copy .env, artisan optimize, restart the web processes' {
        # The running release reads its own copy of .env; rebuild the cache, then stop the web server and workers:
        # the "Diwan Web" and "Diwan Worker" loops start them again with the new settings.
        Copy-Item -LiteralPath $EnvFile -Destination (Join-Path $Current '.env') -Force
        Push-Location $Current
        try { & $Php artisan optimize 2>&1 | Out-Null } finally { Pop-Location }
        Get-Process caddy, php-cgi -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
        Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
            Where-Object { $_.CommandLine -match 'queue:work|schedule:work' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    }

    Clear-Request
    Write-Status 'done' 'OK'
    $suffix = if ($script:Url) { " ($($script:Url))" } else { '' }
    Write-Host "Internet access: $($script:Mode)$suffix"
} catch {
    $failure = "Stopped: " + ($_.Exception.Message -replace '["\\\r\n]', ' ') + ' at ' + ($_.InvocationInfo.PositionMessage -replace '["\\\r\n]', ' ')
    Write-Host $failure
    Clear-Request
    Write-Status 'failed' $failure
    exit 1
} finally {
    $mutex.ReleaseMutex()
}
