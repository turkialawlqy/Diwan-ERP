<#
.SYNOPSIS
    Diwan ERP - complete one-click install on Windows 10/11 (run Diwan-Setup.bat).

.DESCRIPTION
    Sets up everything under C:\Diwan, on a computer with nothing installed:
      - Microsoft Visual C++ runtime (needed by PHP)
      - portable PHP 8.3, MariaDB (MySQL-compatible) and the Caddy web server
      - the latest ready-built release of the system (libraries + interface)
      - database, .env (local install mode), database tables
      - background tasks at start-up: database service, web server (Caddy +
        php-cgi workers), queue and scheduler, daily update
      - firewall rule for the office network, desktop and Start menu shortcuts
    Safe to run again: keeps the database, settings and uploaded files.

    Console messages are English: Windows consoles do not render Arabic well.
    Windows PowerShell 5.1 compatible (no ?? / ternaries). Run as administrator.

.NOTES
    Options (environment variables): DIWAN_DIR (C:\Diwan), DIWAN_PORT (8000),
    DIWAN_PACKAGE (path or URL of diwan-erp.zip).
#>

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue' # Invoke-WebRequest is very slow with a progress bar
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Repo = 'turkialawlqy/Diwan-ERP'
$Root = if ($env:DIWAN_DIR) { $env:DIWAN_DIR } else { 'C:\Diwan' }
$PackageUrl = if ($env:DIWAN_PACKAGE) { $env:DIWAN_PACKAGE } else { "https://github.com/$Repo/releases/latest/download/diwan-erp.zip" }
$Bin = Join-Path $Root 'bin'
$PhpDir = Join-Path $Bin 'php'
$DbDir = Join-Path $Bin 'mariadb'
$DataDir = Join-Path $Root 'data'
$Shared = Join-Path $Root 'shared'
$Releases = Join-Path $Root 'releases'
$Current = Join-Path $Root 'current'
$Scripts = Join-Path $Root 'scripts'
$Logs = Join-Path $Root 'logs'
$ConfigFile = Join-Path $Root 'diwan.json'
$DbService = 'DiwanDB'
$Workers = 6

function Step($n, $text) { Write-Host ''; Write-Host "==> [$n/9] $text" -ForegroundColor Cyan }
function Ok($text) { Write-Host "    OK  $text" -ForegroundColor Green }
function Warn($text) { Write-Host "    !   $text" -ForegroundColor Yellow }
function Fail($text) {
    Write-Host ''
    Write-Host "    ERROR: $text" -ForegroundColor Red
    Write-Host '    Take a screenshot of this window to ask for help, then run Diwan-Setup.bat again (it is safe to repeat).' -ForegroundColor Red
    throw $text
}

# UTF-8 with BOM, so Windows PowerShell 5.1 reads generated scripts correctly.
function Write-Utf8Bom($path, $text) { [IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding($true))) }
function Write-Utf8($path, $text) { [IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding($false))) }

function Download($url, $out) {
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    if (Test-Path $curl) {
        & $curl -fL --retry 3 --progress-bar -A 'Diwan-Installer' -o $out $url
        if ($LASTEXITCODE -eq 0) { return }
    }
    Invoke-WebRequest -UseBasicParsing -UserAgent 'Diwan-Installer' -Uri $url -OutFile $out
}

function Expand-Zip($zip, $dest) {
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    $tar = Join-Path $env:SystemRoot 'System32\tar.exe'
    if (Test-Path $tar) {
        & $tar -xf $zip -C $dest
        if ($LASTEXITCODE -eq 0) { return }
    }
    Expand-Archive -Path $zip -DestinationPath $dest -Force
}

function Random-Secret($length) {
    $chars = [char[]]'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    $bytes = New-Object byte[] $length
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}

function Port-Free($port) {
    -not (Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
}

# Directory junction (no developer mode needed, unlike symbolic links).
function Set-Junction($link, $target) {
    if (Test-Path $link) { cmd /c rmdir "$link" | Out-Null }
    cmd /c mklink /J "$link" "$target" | Out-Null
}

# ------------------------------------------------------------------ checks
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Please double-click Diwan-Setup.bat and answer "Yes" when Windows asks for permission.' -ForegroundColor Yellow
    exit 1
}

Write-Host '==============================================' -ForegroundColor Cyan
Write-Host '   Diwan ERP setup' -ForegroundColor Cyan
Write-Host '   Takes 10-20 minutes depending on your internet speed.' -ForegroundColor Cyan
Write-Host '   Keep this window open until it says "Installed successfully".' -ForegroundColor Cyan
Write-Host '==============================================' -ForegroundColor Cyan

Step 1 'Checking this computer'
if (-not [Environment]::Is64BitOperatingSystem) { Fail 'Diwan needs 64-bit Windows.' }
if ([Environment]::OSVersion.Version.Build -lt 17763) { Fail 'Diwan needs Windows 10 (version 1809 or newer) or Windows 11. Update Windows, then try again.' }
$drive = (Get-Item ([IO.Path]::GetPathRoot($Root))).PSDrive
if ($drive.Free -lt 3GB) { Fail "Less than 3 GB free on drive $($drive.Name):. Free some space, then try again." }
foreach ($d in @($Root, $Bin, $Shared, $Releases, $Scripts, $Logs, $DataDir)) { New-Item -ItemType Directory -Force -Path $d | Out-Null }

$config = @{}
if (Test-Path $ConfigFile) {
    $saved = Get-Content $ConfigFile -Raw | ConvertFrom-Json
    $saved.PSObject.Properties | ForEach-Object { $config[$_.Name] = $_.Value }
}
if (-not $config.port) {
    $port = 8000
    if ($env:DIWAN_PORT) { $port = [int]$env:DIWAN_PORT }
    while (-not (Port-Free $port)) { $port++ }
    $config.port = $port
}
if (-not $config.db_port) {
    $dbPort = 3306
    while (-not (Port-Free $dbPort)) { $dbPort++ }
    $config.db_port = $dbPort
}
if (-not $config.db_root_password) { $config.db_root_password = Random-Secret 24 }
if (-not $config.db_password) { $config.db_password = Random-Secret 24 }
if (-not $config.backup_password) { $config.backup_password = Random-Secret 32 }
$config | ConvertTo-Json | Set-Content -Path $ConfigFile -Encoding UTF8
Ok "Folder $Root, port $($config.port)"

# ------------------------------------------------------------------ runtime
Step 2 'Microsoft Visual C++ runtime (needed by PHP)'
$vc = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -ErrorAction SilentlyContinue
if (-not $vc -or -not $vc.Installed -or $vc.Minor -lt 30) {
    $vcExe = Join-Path $env:TEMP 'vc_redist.x64.exe'
    Download 'https://aka.ms/vs/17/release/vc_redist.x64.exe' $vcExe
    $p = Start-Process -FilePath $vcExe -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    if (@(0, 1638, 3010) -notcontains $p.ExitCode) { Fail "Could not install Visual C++ (code $($p.ExitCode))." }
}
Ok 'Visual C++ is ready.'

# ------------------------------------------------------------------ PHP
Step 3 'PHP 8.3'
$phpExe = Join-Path $PhpDir 'php.exe'
if (-not (Test-Path $phpExe)) {
    $index = Invoke-RestMethod -UseBasicParsing -UserAgent 'Diwan-Installer' -Uri 'https://downloads.php.net/~windows/releases/releases.json'
    $build83 = $index.'8.3'.'nts-vs16-x64'.zip
    $phpZip = Join-Path $env:TEMP 'php.zip'
    Download "https://downloads.php.net/~windows/releases/$($build83.path)" $phpZip
    if ((Get-FileHash $phpZip -Algorithm SHA256).Hash -ne $build83.sha256.ToUpper()) { Fail 'The PHP download is corrupted. Try again.' }
    Expand-Zip $phpZip $PhpDir
    Remove-Item $phpZip -Force
}
$caBundle = Join-Path $Bin 'cacert.pem'
if (-not (Test-Path $caBundle)) { Download 'https://curl.se/ca/cacert.pem' $caBundle }

# php.ini: the production defaults plus what Diwan needs (only extensions this build ships as DLLs).
$ini = Get-Content (Join-Path $PhpDir 'php.ini-production') -Raw
$lines = @('', '; ---- Diwan ERP ----', 'extension_dir = "ext"')
foreach ($e in @('curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'mysqli', 'sodium', 'zip', 'bcmath', 'exif')) {
    if (Test-Path (Join-Path $PhpDir "ext\php_$e.dll")) { $lines += "extension=$e" }
}
if (Test-Path (Join-Path $PhpDir 'ext\php_opcache.dll')) { $lines += @('zend_extension=opcache', 'opcache.enable=1', 'opcache.memory_consumption=192') }
$lines += @(
    'memory_limit = 512M', 'max_execution_time = 300', 'upload_max_filesize = 32M', 'post_max_size = 40M',
    'date.timezone = Asia/Riyadh', "curl.cainfo = `"$caBundle`"", "openssl.cafile = `"$caBundle`"", 'realpath_cache_ttl = 5'
)
Write-Utf8 (Join-Path $PhpDir 'php.ini') ($ini + "`r`n" + ($lines -join "`r`n") + "`r`n")
$modules = & $phpExe -m
foreach ($need in @('pdo_mysql', 'mbstring', 'intl', 'gd', 'zip', 'openssl', 'curl', 'fileinfo')) {
    if ($modules -notcontains $need) { Fail "PHP extension missing: $need" }
}
Ok "PHP $(& $phpExe -r 'echo PHP_VERSION;')"

# ------------------------------------------------------------------ database
Step 4 'Database (MariaDB, MySQL-compatible)'
$mysqld = Join-Path $DbDir 'bin\mariadbd.exe'
if (-not (Test-Path $mysqld)) {
    $info = Invoke-RestMethod -UseBasicParsing -Uri 'https://downloads.mariadb.org/rest-api/mariadb/11.4/latest/'
    $mdbRelease = $info.releases.PSObject.Properties | Select-Object -First 1
    $file = $mdbRelease.Value.files | Where-Object { $_.file_name -like '*-winx64.zip' } | Select-Object -First 1
    if (-not $file) { Fail 'Could not find the MariaDB package for Windows.' }
    $dbZip = Join-Path $env:TEMP 'mariadb.zip'
    Download $file.file_download_url $dbZip
    if ((Get-FileHash $dbZip -Algorithm SHA256).Hash -ne $file.checksum.sha256sum.ToUpper()) { Fail 'The MariaDB download is corrupted. Try again.' }
    $tmpDb = Join-Path $env:TEMP 'diwan-mariadb'
    if (Test-Path $tmpDb) { Remove-Item $tmpDb -Recurse -Force }
    Expand-Zip $dbZip $tmpDb
    Move-Item (Get-ChildItem $tmpDb -Directory | Select-Object -First 1).FullName $DbDir
    Remove-Item $dbZip, $tmpDb -Recurse -Force -ErrorAction SilentlyContinue
}
$dbCli = Join-Path $DbDir 'bin\mariadb.exe'
$myIni = Join-Path $DataDir 'my.ini'
if (-not (Get-Service $DbService -ErrorAction SilentlyContinue)) {
    if (-not (Test-Path (Join-Path $DataDir 'mysql'))) {
        & (Join-Path $DbDir 'bin\mariadb-install-db.exe') "--datadir=$DataDir" "--password=$($config.db_root_password)" "--port=$($config.db_port)" "--service=$DbService" | Out-Null
    } else {
        & $mysqld --install $DbService "--defaults-file=$myIni" | Out-Null
    }
    if ($LASTEXITCODE -ne 0) { Fail 'Could not set up the database.' }
}
# The database answers this computer only (the office devices reach the web server, not the database).
if ((Test-Path $myIni) -and -not (Select-String -Path $myIni -Pattern '^bind-address' -Quiet)) {
    Write-Utf8 $myIni ((Get-Content $myIni -Raw) -replace '\[mysqld\]', "[mysqld]`r`nbind-address=127.0.0.1")
    Restart-Service $DbService -ErrorAction SilentlyContinue
}
Set-Service $DbService -StartupType Automatic
Start-Service $DbService
function Invoke-Db($query) {
    # Native stderr redirected under 'Stop' would end the script on the first error line (PowerShell 5.1).
    $ErrorActionPreference = 'Continue'
    & $dbCli -uroot "-p$($config.db_root_password)" -h localhost "-P$($config.db_port)" -e $query 2>$null
    if ($LASTEXITCODE -ne 0) { & $dbCli -uroot "-p$($config.db_root_password)" -h 127.0.0.1 "-P$($config.db_port)" -e $query 2>$null }
}
$ready = $false
for ($i = 0; $i -lt 30; $i++) {
    Invoke-Db 'SELECT 1' | Out-Null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Start-Sleep -Seconds 2
}
if (-not $ready) { Fail 'The database did not start.' }
Invoke-Db ("CREATE DATABASE IF NOT EXISTS diwan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
    "CREATE USER IF NOT EXISTS 'diwan'@'localhost' IDENTIFIED BY '$($config.db_password)'; " +
    "CREATE USER IF NOT EXISTS 'diwan'@'127.0.0.1' IDENTIFIED BY '$($config.db_password)'; " +
    "GRANT ALL PRIVILEGES ON diwan.* TO 'diwan'@'localhost'; GRANT ALL PRIVILEGES ON diwan.* TO 'diwan'@'127.0.0.1'; FLUSH PRIVILEGES;")
if ($LASTEXITCODE -ne 0) { Fail 'Could not create the database.' }
Ok "MariaDB runs as the Windows service $DbService (port $($config.db_port))."

# ------------------------------------------------------------------ the system
Step 5 'Downloading the latest Diwan release'
$zip = Join-Path $env:TEMP 'diwan-erp.zip'
if (Test-Path $PackageUrl) {
    Copy-Item $PackageUrl $zip -Force
} else {
    try { Download $PackageUrl $zip } catch { Fail 'Could not download Diwan. Check the internet connection (or that a release is published on the project''s Releases page).' }
    $haveSha = $true
    try { Download "$PackageUrl.sha256" "$zip.sha256" } catch { $haveSha = $false }
    if ($haveSha -and (Get-FileHash $zip -Algorithm SHA256).Hash -ne (Get-Content "$zip.sha256" -Raw).Trim().ToUpper()) { Fail 'The download is corrupted. Try again.' }
}
$unpack = Join-Path $env:TEMP 'diwan-unpack'
if (Test-Path $unpack) { Remove-Item $unpack -Recurse -Force }
Expand-Zip $zip $unpack
$version = (Get-Content (Join-Path $unpack 'diwan-erp\VERSION') -Raw).Trim()
$release = Join-Path $Releases ((Get-Date -Format 'yyyyMMddHHmmss') + "-$version")
Move-Item (Join-Path $unpack 'diwan-erp') $release
Remove-Item $zip, "$zip.sha256", $unpack -Recurse -Force -ErrorAction SilentlyContinue

# What survives updates: settings and storage (uploads, backups, logs).
$sharedStorage = Join-Path $Shared 'storage'
if (-not (Test-Path $sharedStorage)) { Move-Item (Join-Path $release 'storage') $sharedStorage } else { Remove-Item (Join-Path $release 'storage') -Recurse -Force }
Set-Junction (Join-Path $release 'storage') $sharedStorage
Set-Junction (Join-Path $release 'public\storage') (Join-Path $sharedStorage 'app\public')
Ok "Version $version"

# ------------------------------------------------------------------ settings
Step 6 'Settings and database tables'
$envFile = Join-Path $Shared '.env'
if (-not (Test-Path $envFile)) {
    $dumpExe = Join-Path $DbDir 'bin\mariadb-dump.exe'
    $text = Get-Content (Join-Path $release '.env.local-install.example') -Raw -Encoding UTF8
    $text = $text.Replace('__PORT__', [string]$config.port).Replace('__DB_PORT__', [string]$config.db_port)
    $text = $text.Replace('__DB_PASSWORD__', $config.db_password).Replace('__BACKUP_PASSWORD__', $config.backup_password)
    $text = $text.Replace('__MYSQLDUMP__', "'$dumpExe'").Replace('__MYSQL__', "'$dbCli'")
    Write-Utf8 $envFile $text
}
# Linking a single file needs developer mode: each release gets a copy, refreshed on update and restart.
Copy-Item $envFile (Join-Path $release '.env') -Force
Push-Location $release
try {
    if (-not (Select-String -Path $envFile -Pattern '^APP_KEY=base64' -Quiet)) {
        & $phpExe artisan key:generate --force | Out-Null
        Copy-Item (Join-Path $release '.env') $envFile -Force
    }
    & $phpExe artisan migrate --force --seed
    if ($LASTEXITCODE -ne 0) { Fail 'Could not create the database tables.' }
    & $phpExe artisan optimize | Out-Null
} finally { Pop-Location }
Set-Junction $Current $release
Write-Utf8 (Join-Path $Root 'secrets.txt') (
    "Diwan ERP - keep these passwords somewhere safe, outside this computer.`r`n" +
    "DB_ROOT_PASSWORD=$($config.db_root_password)`r`nDB_PASSWORD=$($config.db_password)`r`nBACKUP_PASSWORD=$($config.backup_password)`r`n")
Ok "Ready. Passwords saved in $Root\secrets.txt"

# ------------------------------------------------------------------ background tasks
Step 7 'Starting Diwan automatically with Windows'
$caddyExe = Join-Path $Bin 'caddy.exe'
if (-not (Test-Path $caddyExe)) { Download 'https://caddyserver.com/api/download?os=windows&arch=amd64' $caddyExe }
$upstreams = (1..$Workers | ForEach-Object { "127.0.0.1:$(9000 + $_)" }) -join ' '
$publicDir = (Join-Path $Current 'public').Replace('\', '/')
$caddyLog = (Join-Path $Logs 'web.log').Replace('\', '/')
Write-Utf8 (Join-Path $Root 'Caddyfile') @"
{
	admin off
	auto_https off
}

:$($config.port) {
	root * $publicDir
	encode gzip
	php_fastcgi $upstreams {
		lb_try_duration 10s
	}
	file_server
	log {
		output file $caddyLog
	}
}
"@

# The web server and $Workers PHP workers, restarted whenever one stops (php-cgi exits after N requests by design).
Write-Utf8Bom (Join-Path $Scripts 'run-web.ps1') @"
`$ErrorActionPreference = 'Continue'
`$env:PHP_FCGI_MAX_REQUESTS = '2000'
`$cgi = @{}
`$caddy = `$null
while (`$true) {
    if (-not `$caddy -or `$caddy.HasExited) {
        `$caddy = Start-Process -FilePath '$caddyExe' -ArgumentList 'run', '--config', '$(Join-Path $Root 'Caddyfile')', '--adapter', 'caddyfile' -WorkingDirectory '$Root' -WindowStyle Hidden -PassThru
    }
    foreach (`$port in $(9001)..$(9000 + $Workers)) {
        `$p = `$cgi[`$port]
        if (-not `$p -or `$p.HasExited) {
            `$cgi[`$port] = Start-Process -FilePath '$(Join-Path $PhpDir 'php-cgi.exe')' -ArgumentList '-b', "127.0.0.1:`$port" -WorkingDirectory '$Current' -WindowStyle Hidden -PassThru
        }
    }
    Start-Sleep -Seconds 2
}
"@

# Queue (invoices to the tax authority, mail, exports) and scheduler (backups, recurring entries, alerts).
Write-Utf8Bom (Join-Path $Scripts 'run-worker.ps1') @"
`$ErrorActionPreference = 'Continue'
`$queue = `$null
`$schedule = `$null
while (`$true) {
    if (-not `$queue -or `$queue.HasExited) {
        `$queue = Start-Process -FilePath '$phpExe' -ArgumentList 'artisan', 'queue:work', 'database', '--queue=zatca,default,webhooks,exports', '--sleep=3', '--tries=3', '--max-time=3600' -WorkingDirectory '$Current' -WindowStyle Hidden -PassThru
    }
    if (-not `$schedule -or `$schedule.HasExited) {
        `$schedule = Start-Process -FilePath '$phpExe' -ArgumentList 'artisan', 'schedule:work' -WorkingDirectory '$Current' -WindowStyle Hidden -PassThru
    }
    Start-Sleep -Seconds 5
}
"@

$psExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$system = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$forever = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 10 -RestartInterval (New-TimeSpan -Minutes 1) `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew
# A re-run replaces the tasks: stop them, then the processes they started.
foreach ($t in @('Diwan Web', 'Diwan Worker', 'Diwan Update')) {
    Stop-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $t -Confirm:$false -ErrorAction SilentlyContinue
}
Get-Process caddy, php-cgi -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match 'queue:work|schedule:work' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
function Register-DiwanTask($name, $script, $trigger) {
    $action = New-ScheduledTaskAction -Execute $psExe -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$script`""
    Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Principal $system -Settings $forever | Out-Null
}
Register-DiwanTask 'Diwan Web' (Join-Path $Scripts 'run-web.ps1') (New-ScheduledTaskTrigger -AtStartup)
Register-DiwanTask 'Diwan Worker' (Join-Path $Scripts 'run-worker.ps1') (New-ScheduledTaskTrigger -AtStartup)
Register-DiwanTask 'Diwan Update' (Join-Path $Current 'deploy\windows\local-update.ps1') (New-ScheduledTaskTrigger -Daily -At '3:30AM')
Start-ScheduledTask -TaskName 'Diwan Web'
Start-ScheduledTask -TaskName 'Diwan Worker'
$up = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 2
    try { if ((Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$($config.port)/up" -TimeoutSec 10).StatusCode -eq 200) { $up = $true; break } } catch { }
}
if (-not $up) { Fail "Diwan did not answer on port $($config.port). See $Logs\web.log" }
Ok 'Diwan is running, and starts with Windows even before anyone logs in. It updates itself daily at 3:30 AM.'

# ------------------------------------------------------------------ office network
Step 8 'Allowing the office devices to connect'
Remove-NetFirewallRule -DisplayName 'Diwan ERP' -ErrorAction SilentlyContinue
New-NetFirewallRule -DisplayName 'Diwan ERP' -Direction Inbound -Protocol TCP -LocalPort $config.port -Action Allow -Profile Private, Domain | Out-Null
if (Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.NetworkCategory -eq 'Public' }) {
    Warn 'Your network is set to "Public": other devices cannot connect until you set it to "Private" (see the guide).'
}
Ok "The firewall allows port $($config.port) on private networks only."

# ------------------------------------------------------------------ shortcuts
Step 9 'Shortcuts'
$url = "http://localhost:$($config.port)"
$desktop = [Environment]::GetFolderPath('CommonDesktopDirectory')
# Shortcut names are Arabic: Windows Explorer shows them correctly (only consoles do not).
Write-Utf8 (Join-Path $desktop 'الديوان.url') "[InternetShortcut]`r`nURL=$url`r`n"
$menu = Join-Path ([Environment]::GetFolderPath('CommonPrograms')) 'الديوان'
New-Item -ItemType Directory -Force -Path $menu | Out-Null
Write-Utf8 (Join-Path $menu 'فتح الديوان.url') "[InternetShortcut]`r`nURL=$url`r`n"
$shell = New-Object -ComObject WScript.Shell
function Add-Shortcut($name, $action) {
    $lnk = $shell.CreateShortcut((Join-Path $menu "$name.lnk"))
    $lnk.TargetPath = $psExe
    $lnk.Arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$(Join-Path $Current 'deploy\windows\local-tools.ps1')`" -Action $action"
    $lnk.Save()
}
Add-Shortcut 'تحديث الديوان' 'update'
Add-Shortcut 'نسخة احتياطية الآن' 'backup'
Add-Shortcut 'إعادة تشغيل الديوان' 'restart'
Add-Shortcut 'إلغاء تثبيت الديوان' 'uninstall'
Ok 'Desktop shortcut created; tools are in Start menu > Diwan.'

$lan = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -match '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' } |
    Select-Object -First 1 -ExpandProperty IPAddress
Write-Host ''
Write-Host '======================================================' -ForegroundColor Green
Write-Host '   Installed successfully!' -ForegroundColor Green
Write-Host "   On this computer:           $url"
if ($lan) { Write-Host "   From office devices/phones: http://$($lan):$($config.port)" }
Write-Host '   First time: enter your company details and the manager account'
Write-Host '   in the page that opens now.'
Write-Host "   Passwords: $Root\secrets.txt (copy it somewhere safe)"
Write-Host '======================================================' -ForegroundColor Green
Start-Process $url
