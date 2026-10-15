<#
.SYNOPSIS
    Updates the local Alostaz copy (Windows + MAMP) from GitHub and prepares it.

.DESCRIPTION
    - First run on a folder that is not a git clone: takes a full backup copy,
      then links the folder to the repository (.env, storage and uploads stay).
    - Later runs: pulls new commits only when there are any.
    - Then: maintenance mode, database backup, composer, npm build, migrations,
      permission sync, cache clear, back online. Any failure brings the site
      back online and stops with the error.

    Double-click update.bat, or run every hour via install-auto-update.ps1.

.PARAMETER Auto
    Scheduled mode: quiet, and never touches a folder with local code edits.
.PARAMETER Force
    Prepare again even when there is nothing new to pull.
#>
param(
    [string]$Path = '',
    [string]$Branch = 'claude/vigilant-cerf-uwqevd',
    [string]$Repository = 'https://github.com/turkialawlqy/AlOstaz.git',
    [switch]$Auto,
    [switch]$Force
)

# Windows PowerShell 5.1 compatible (no ?? / ternaries).
$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

if (-not $Path) {
    $candidate = Resolve-Path (Join-Path $PSScriptRoot '../..') -ErrorAction SilentlyContinue
    if ($candidate -and (Test-Path (Join-Path $candidate 'artisan'))) { $Path = $candidate.Path }
    else { $Path = 'C:\MAMP\htdocs\alostaz' }
}
if (-not (Test-Path (Join-Path $Path 'artisan'))) {
    Write-Host "لم يُعثر على النظام في: $Path" -ForegroundColor Red
    exit 1
}
Set-Location $Path

$logDir = Join-Path $Path 'storage/logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$logFile = Join-Path $logDir 'update.log'

function Write-Log([string]$Message, [string]$Color = 'Gray', [switch]$Always) {
    Add-Content -Path $logFile -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message) -Encoding UTF8
    if (-not $Auto -or $Always) { Write-Host $Message -ForegroundColor $Color }
}
function Step([string]$Message) { Write-Log "==> $Message" 'Cyan' }

# Runs a program, logs its output, throws on a non-zero exit code. stderr is
# captured as text so git progress messages are not treated as errors.
function Invoke-Tool([string]$Exe, [string[]]$Arguments, [switch]$AllowFail) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $output = & $Exe @Arguments 2>&1 | ForEach-Object { "$_" }
    $code = $LASTEXITCODE
    $ErrorActionPreference = $previous
    foreach ($line in $output) { if ($line) { Write-Log "    $line" 'DarkGray' } }
    if ($code -ne 0 -and -not $AllowFail) {
        throw ("فشل الأمر: {0} {1} (رمز {2})" -f (Split-Path $Exe -Leaf), ($Arguments -join ' '), $code)
    }
    return $output
}

function Find-Tool([string[]]$Names, [string[]]$Candidates) {
    $cmd = Get-Command $Names -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($cmd) { return $cmd.Source }
    foreach ($c in $Candidates) {
        $found = Get-ChildItem -Path $c -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1
        if ($found) { return $found.FullName }
    }
    return $null
}

function Copy-Backup([string]$Reason) {
    $target = '{0}-backup-{1}' -f $Path.TrimEnd('\'), (Get-Date -Format 'yyyyMMdd-HHmm')
    Step "نسخة احتياطية من المجلد ($Reason) إلى: $target"
    # robocopy exit codes below 8 mean success.
    & robocopy $Path $target /E /XD vendor node_modules .git /R:1 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "فشل إنشاء النسخة الاحتياطية للمجلد (robocopy $LASTEXITCODE)" }
    Write-Log "    تم حفظ النسخة الاحتياطية: $target" 'Green'
    return $target
}

$siteDown = $false
$php = $null
try {
    Write-Log ("بدء التحديث — {0} — الفرع {1}{2}" -f $Path, $Branch, $(if ($Auto) { ' (تلقائي)' } else { '' })) 'White'

    # ---------------------------------------------------------------- tools
    $git = Find-Tool 'git' @("$env:ProgramFiles\Git\cmd\git.exe", "${env:ProgramFiles(x86)}\Git\cmd\git.exe", "$env:LOCALAPPDATA\Programs\Git\cmd\git.exe")
    $phpCandidates = Get-ChildItem 'C:\MAMP\bin\php\php8*\php.exe' -ErrorAction SilentlyContinue |
        Where-Object { $_.Directory.Name -match '^php8\.(\d+)' -and [int]$Matches[1] -ge 2 } |
        Sort-Object { [version]($_.Directory.Name -replace '^php', '') } -Descending
    $php = Find-Tool 'php' @()
    if ($php) {
        $phpVersion = (& $php -r 'echo PHP_VERSION;') 2>$null
        if (-not $phpVersion -or [version]($phpVersion -replace '[^0-9.].*$', '') -lt [version]'8.2') { $php = $null }
    }
    if (-not $php -and $phpCandidates) { $php = ($phpCandidates | Select-Object -First 1).FullName }
    $node = Find-Tool 'node' @("$env:ProgramFiles\nodejs\node.exe")
    $npm = Find-Tool @('npm.cmd', 'npm') @("$env:ProgramFiles\nodejs\npm.cmd")

    $missing = @()
    if (-not $git) { $missing += 'Git for Windows: https://git-scm.com/download/win' }
    if (-not $php) { $missing += 'PHP 8.2 أو أحدث (فعّله في MAMP أو ثبّته): https://windows.php.net/download' }
    if (-not $node -or -not $npm) { $missing += 'Node.js LTS: https://nodejs.org' }
    if ($missing.Count) {
        Write-Log 'أدوات مطلوبة غير مثبتة؛ ثبّتها ثم أعد التشغيل:' 'Red' -Always
        $missing | ForEach-Object { Write-Log "  - $_" 'Yellow' -Always }
        exit 2
    }
    $env:Path = (Split-Path $php) + ';' + (Split-Path $node) + ';' + $env:Path
    Write-Log "    PHP: $php" 'DarkGray'

    $composer = Find-Tool @('composer.bat', 'composer') @()
    $composerArgs = @()
    if (-not $composer) {
        $phar = Join-Path $PSScriptRoot 'tools/composer.phar'
        if (-not (Test-Path $phar)) {
            Step 'تنزيل Composer'
            New-Item -ItemType Directory -Force -Path (Split-Path $phar) | Out-Null
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            Invoke-WebRequest -UseBasicParsing 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile $phar
        }
        $composer = $php
        $composerArgs = @($phar)
    }
    $mysqldump = Get-ChildItem 'C:\MAMP\bin\mysql\bin\mysqldump.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($mysqldump) { $env:BACKUP_MYSQLDUMP = $mysqldump.FullName; $env:BACKUP_MYSQL = Join-Path $mysqldump.DirectoryName 'mysql.exe' }

    # ---------------------------------------------------------------- code
    # Linked = a git folder whose "origin" already points at this repository.
    # A folder copied by hand, or a local git folder without that remote,
    # is backed up in full before being linked.
    $isRepo = Test-Path (Join-Path $Path '.git')
    $origin = ''
    if ($isRepo) { $origin = "$(@(Invoke-Tool $git @('remote', 'get-url', 'origin') -AllowFail)[0])".Trim() }
    $linked = $isRepo -and ($origin -match 'turkialawlqy/AlOstaz' -or $origin -eq $Repository)
    if (-not $linked) {
        if ($Auto) { Write-Log 'المجلد غير مرتبط بالمستودع بعد؛ شغّل update.bat يدوياً أول مرة.' 'Yellow' -Always; exit 3 }
        Copy-Backup 'أول ربط بالمستودع' | Out-Null
        Step 'ربط المجلد بالمستودع'
        if (-not $isRepo) { Invoke-Tool $git @('init', '-q') | Out-Null }
        if ($origin) { Invoke-Tool $git @('remote', 'set-url', 'origin', $Repository) | Out-Null }
        else { Invoke-Tool $git @('remote', 'add', 'origin', $Repository) | Out-Null }
        # The local history is unrelated: switch to the repository's version
        # (untracked .env, storage and uploads are not touched).
        $isRepo = $false
    } elseif ($origin -ne $Repository) {
        Invoke-Tool $git @('remote', 'set-url', 'origin', $Repository) -AllowFail | Out-Null
    }

    Step 'جلب آخر التعديلات من GitHub'
    $remoteBranch = @(Invoke-Tool $git @('ls-remote', '--heads', 'origin', $Branch))
    if (-not ($remoteBranch -join '').Trim()) {
        Write-Log "الفرع $Branch غير موجود (ربما دُمج)؛ سيُستخدم main." 'Yellow' -Always
        $Branch = 'main'
    }
    Invoke-Tool $git @('fetch', '--prune', 'origin', $Branch) | Out-Null
    $remoteHead = (@(Invoke-Tool $git @('rev-parse', "origin/$Branch"))[0]).Trim()

    $localHead = ''
    if ($isRepo) { $localHead = "$(@(Invoke-Tool $git @('rev-parse', 'HEAD') -AllowFail)[0])".Trim() }
    if ($isRepo -and $localHead -eq $remoteHead -and -not $Force) {
        Write-Log ("النظام محدّث (الإصدار {0})." -f $remoteHead.Substring(0, 7)) 'Green'
        exit 0
    }

    if ($isRepo) {
        $dirty = @(Invoke-Tool $git @('status', '--porcelain', '--untracked-files=no'))
        if (($dirty -join '').Trim()) {
            if ($Auto) {
                Write-Log 'توجد تعديلات يدوية على ملفات الكود؛ لم يُحدَّث تلقائياً. شغّل update.bat يدوياً.' 'Yellow' -Always
                exit 4
            }
            $stash = 'auto-update ' + (Get-Date -Format 'yyyy-MM-dd HH:mm')
            Step "حفظ تعديلاتك المحلية جانباً (git stash: $stash)"
            Invoke-Tool $git @('stash', 'push', '-m', $stash) | Out-Null
            Write-Log '    لاستعادتها لاحقاً: git stash list ثم git stash pop' 'Yellow'
        }
    }

    Step 'تجهيز وضع الصيانة'
    if (Test-Path (Join-Path $Path 'vendor/autoload.php')) {
        Invoke-Tool $php @('artisan', 'down', '--retry=60') -AllowFail | Out-Null
        $siteDown = $true

        Step 'نسخ احتياطي لقاعدة البيانات'
        $backup = Invoke-Tool $php @('artisan', 'erp:backup') -AllowFail
        if ($LASTEXITCODE -ne 0) { Write-Log '    تعذّر النسخ الاحتياطي لقاعدة البيانات (سيُكمل التحديث). راجع BACKUP_PASSWORD و BACKUP_MYSQLDUMP.' 'Yellow' }
    }

    Step 'تحديث ملفات الكود'
    if ($isRepo) {
        $merge = Invoke-Tool $git @('merge', '--ff-only', "origin/$Branch") -AllowFail
        if ($LASTEXITCODE -ne 0) {
            Copy-Backup 'تعارض مع النسخة المحلية' | Out-Null
            Invoke-Tool $git @('checkout', '-f', '-B', $Branch, "origin/$Branch") | Out-Null
        }
    } else {
        Invoke-Tool $git @('checkout', '-f', '-B', $Branch, "origin/$Branch") | Out-Null
    }
    Invoke-Tool $git @('branch', "--set-upstream-to=origin/$Branch", $Branch) -AllowFail | Out-Null

    Step 'تثبيت مكتبات PHP (composer)'
    Invoke-Tool $composer ($composerArgs + @('install', '--no-interaction', '--prefer-dist', '--optimize-autoloader')) | Out-Null
    if (-not $siteDown) { Invoke-Tool $php @('artisan', 'down', '--retry=60') -AllowFail | Out-Null; $siteDown = $true }

    Step 'بناء الواجهة (npm)'
    Invoke-Tool $npm @('ci', '--no-audit', '--no-fund') | Out-Null
    Invoke-Tool $npm @('run', 'build') | Out-Null

    Step 'تحديث قاعدة البيانات'
    Invoke-Tool $php @('artisan', 'migrate', '--force') | Out-Null
    Invoke-Tool $php @('artisan', 'erp:sync-permissions') | Out-Null
    Invoke-Tool $php @('artisan', 'erp:accounting', 'provision') | Out-Null
    Invoke-Tool $php @('artisan', 'optimize:clear') | Out-Null

    Invoke-Tool $php @('artisan', 'up') | Out-Null
    $siteDown = $false

    # Settings added to .env.example that this .env does not have yet.
    $keys = { param($file) if (Test-Path $file) { Get-Content $file -Encoding UTF8 | Where-Object { $_ -match '^\s*([A-Z0-9_]+)\s*=' } | ForEach-Object { $Matches[1] } } }
    $missingKeys = & $keys (Join-Path $Path '.env.example') | Where-Object { (& $keys (Join-Path $Path '.env')) -notcontains $_ }
    if ($missingKeys) {
        Write-Log 'إعدادات جديدة غير موجودة في ملف .env (القيم الافتراضية مستخدمة؛ أضفها إن احتجت):' 'Yellow'
        $missingKeys | ForEach-Object { Write-Log "  - $_" 'Yellow' }
    }

    $version = @(Invoke-Tool $git @('log', '-1', '--format=%h %s'))[0]
    Write-Log "تم التحديث بنجاح: $version" 'Green' -Always
    exit 0
}
catch {
    Write-Log ("خطأ: " + $_.Exception.Message) 'Red' -Always
    if ($siteDown -and $php) {
        & $php artisan up 2>&1 | Out-Null
        Write-Log 'أُعيد النظام للعمل؛ راجع الخطأ أعلاه ثم أعد تشغيل التحديث.' 'Yellow' -Always
    }
    Write-Log "السجل الكامل: $logFile" 'Gray' -Always
    exit 1
}
