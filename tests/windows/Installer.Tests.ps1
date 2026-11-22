#Requires -Version 5.1
<#
    Pester 5 tests of the Windows installer (deploy/windows), on Windows PowerShell 5.1:
        Invoke-Pester -Path tests/windows
    They load the functions of install.ps1 without running its steps, with stand-ins for caddy.exe
    and for the GitHub release, in temporary folders. Tag Network downloads the real latest Caddy
    from GitHub (-ExcludeTag Network to skip it). The tests of Diwan-Setup.bat run only in an
    administrator window: elsewhere the file would ask Windows for the rights.
    Keep this file UTF-8 with BOM (Windows PowerShell 5.1 reads its Arabic names otherwise).
#>

BeforeDiscovery {
    $script:IsAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

BeforeAll {
    # As at the top of install.ps1: an error stops what is running (and reaches its try/catch).
    $ErrorActionPreference = 'Stop'
    $script:Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    $script:Installer = Join-Path $Repo 'deploy\windows\install.ps1'
    $script:SetupBat = Join-Path $Repo 'deploy\windows\Diwan-Setup.bat'
    $script:PowerShellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

    $parseErrors = $null
    $ast = [Management.Automation.Language.Parser]::ParseFile($Installer, [ref]$null, [ref]$parseErrors)
    if ($parseErrors) { throw "install.ps1 does not parse: $($parseErrors[0])" }
    # The functions of the installer, not its steps.
    foreach ($function in $ast.FindAll({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] }, $false)) {
        . ([scriptblock]::Create($function.Extent.Text))
    }
    # What those functions read from the top of the installer.
    $script:CaddyReleases = 'https://github.com/caddyserver/caddy/releases'
    $script:Root = 'C:\Diwan'
    $script:LogFile = 'C:\Diwan\logs\install.log'

    # A console program that prints $Output and exits with $ExitCode: a stand-in for caddy.exe.
    function New-FakeProgram([string]$Path, [string]$Output, [int]$ExitCode = 0) {
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Path) | Out-Null
        $class = 'Fake' + [guid]::NewGuid().ToString('N')
        $source = "public static class $class { public static int Main(string[] args) { System.Console.WriteLine(""$Output""); return $ExitCode; } }"
        Add-Type -TypeDefinition $source -OutputAssembly $Path -OutputType ConsoleApplication
    }
    $script:Programs = Join-Path ([IO.Path]::GetTempPath()) ('diwan-tests-' + [guid]::NewGuid().ToString('N'))
    $script:Caddy299 = Join-Path $Programs '299\caddy.exe'
    $script:Caddy298 = Join-Path $Programs '298\caddy.exe'
    $script:Caddy250 = Join-Path $Programs '250\caddy.exe'
    $script:Failing = Join-Path $Programs 'failing\caddy.exe'
    $script:NotAProgram = Join-Path $Programs 'damaged\caddy.exe'
    New-FakeProgram $Caddy299 'v2.99.0 h1:test='
    New-FakeProgram $Caddy298 'v2.98.0 h1:test='
    New-FakeProgram $Caddy250 'v2.50.0 h1:test='
    New-FakeProgram $Failing 'Error: no such command' 1
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $NotAProgram) | Out-Null
    # What an interrupted or refused download leaves.
    [IO.File]::WriteAllText($NotAProgram, '401 Unauthorized: Please log in or use an API key to build Caddy.')
}

AfterAll {
    Remove-Item -LiteralPath $Programs -Recurse -Force -ErrorAction SilentlyContinue
}

Describe 'Windows PowerShell 5.1' {
    It 'runs these tests (the PowerShell of every Windows 10 and 11)' {
        $PSVersionTable.PSEdition | Should -Be 'Desktop'
        $PSVersionTable.PSVersion.Major | Should -Be 5
    }

    It '<_> parses' -ForEach @('install.ps1', 'local-tools.ps1', 'local-update.ps1') {
        $errors = $null
        [Management.Automation.Language.Parser]::ParseFile((Join-Path $Repo "deploy\windows\$_"), [ref]$null, [ref]$errors) | Out-Null
        $errors | Should -BeNullOrEmpty
    }

    It '<_> gives cmdlets only parameters they have in Windows PowerShell 5.1' -ForEach @('install.ps1', 'local-tools.ps1') {
        $file = Join-Path $Repo "deploy\windows\$_"
        $calls = [Management.Automation.Language.Parser]::ParseFile($file, [ref]$null, [ref]$null).FindAll({ param($node) $node -is [Management.Automation.Language.CommandAst] }, $true)
        # Added by the file system to Get-Content, Set-Content, Get-ChildItem...: not in Parameters.
        $fileSystem = @('Encoding', 'Raw', 'Stream', 'Delimiter', 'Wait', 'File', 'Directory', 'Attributes', 'Hidden', 'ReadOnly', 'System', 'NoNewline')
        $unknown = foreach ($call in $calls) {
            $name = $call.GetCommandName()
            if (-not $name) { continue }
            $command = Get-Command -Name $name -CommandType Cmdlet, Function -ErrorAction SilentlyContinue | Select-Object -First 1
            if (-not $command) { continue }
            $known = @($command.Parameters.Values | ForEach-Object { $_.Name; $_.Aliases }) + $fileSystem
            foreach ($parameter in @($call.CommandElements | Where-Object { $_ -is [Management.Automation.Language.CommandParameterAst] })) {
                if (-not ($known | Where-Object { $_ -like "$($parameter.ParameterName)*" })) { "$name -$($parameter.ParameterName) (line $($parameter.Extent.StartLineNumber))" }
            }
        }
        $unknown | Should -BeNullOrEmpty
    }
}

Describe 'Reading a Caddy release' {
    It 'takes the stable version from the address releases/latest leads to' {
        Get-ReleaseVersion 'https://github.com/caddyserver/caddy/releases/tag/v2.11.9' | Should -Be '2.11.9'
        Get-ReleaseVersion 'https://github.com/caddyserver/caddy/releases/tag/v2.12.0-beta.1' | Should -BeNullOrEmpty
        Get-ReleaseVersion 'https://github.com/caddyserver/caddy/releases' | Should -BeNullOrEmpty
        Get-ReleaseVersion 'https://github.com/someone/caddy/releases/tag/v2.11.9' | Should -BeNullOrEmpty
        Get-ReleaseVersion 'https://github.com/login?return_to=caddy' | Should -BeNullOrEmpty
        Get-ReleaseVersion $null | Should -BeNullOrEmpty
    }

    It 'names the processors Caddy builds for Windows, and reads the processor of this one' {
        ConvertTo-CaddyArch 'AMD64' | Should -Be 'amd64'
        ConvertTo-CaddyArch 'arm64' | Should -Be 'arm64'
        ConvertTo-CaddyArch 'X86' | Should -BeNullOrEmpty
        ConvertTo-CaddyArch '' | Should -BeNullOrEmpty
        Get-WindowsArch | Should -BeIn @('AMD64', 'ARM64')
    }

    It 'finds the digest of a file in the checksums of a release' {
        $sums = Join-Path $TestDrive 'checksums.txt'
        # One string per line (a comma binds before +, so "('a' * 128) + '...', ..." would be one line).
        Set-Content -Path $sums -Encoding Ascii -Value @(
            "$('a' * 128)  caddy_2.99.0_windows_amd64.zip",
            "$('B' * 64) *caddy_2.99.0_windows_arm64.zip",
            'not a checksum line'
        )
        $found = Find-ReleaseChecksum $sums 'caddy_2.99.0_windows_amd64.zip'
        $found.Algorithm | Should -Be 'SHA512'
        $found.Hash | Should -Be ('A' * 128)
        (Find-ReleaseChecksum $sums 'caddy_2.99.0_windows_arm64.zip').Algorithm | Should -Be 'SHA256'
        Find-ReleaseChecksum $sums 'caddy_2.99.0_windows_386.zip' | Should -BeNullOrEmpty
    }

    It 'reads the version a caddy.exe reports, and nothing from one that does not run' {
        Get-CaddyVersion $Caddy299 | Should -Be '2.99.0'
        Get-CaddyVersion $Failing | Should -BeNullOrEmpty
        Get-CaddyVersion $NotAProgram | Should -BeNullOrEmpty
        Get-CaddyVersion (Join-Path $TestDrive 'none.exe') | Should -BeNullOrEmpty
    }
}

Describe 'Installing Caddy' {
    BeforeAll {
        # A Caddy release in $Dir as GitHub serves it: a zip per processor and the checksums file.
        function New-CaddyRelease([string]$Dir, [string]$Program, [string[]]$Arch = @('amd64'), [string]$Version = '2.99.0') {
            New-Item -ItemType Directory -Force -Path $Dir | Out-Null
            $lines = foreach ($a in $Arch) {
                $name = "caddy_$($Version)_windows_$a.zip"
                $stage = Join-Path $Dir "stage-$a"
                New-Item -ItemType Directory -Force -Path $stage | Out-Null
                Set-Content -Path (Join-Path $stage 'README.md') -Value 'Caddy'
                if ($Program) { Copy-Item -LiteralPath $Program -Destination (Join-Path $stage 'caddy.exe') }
                Compress-Archive -Path (Join-Path $stage '*') -DestinationPath (Join-Path $Dir $name) -Force
                '{0}  {1}' -f (Get-FileHash (Join-Path $Dir $name) -Algorithm SHA512).Hash.ToLowerInvariant(), $name
            }
            Set-Content -Path (Join-Path $Dir "caddy_$($Version)_checksums.txt") -Value $lines -Encoding Ascii
        }
    }

    BeforeEach {
        $script:Work = Join-Path $TestDrive ([guid]::NewGuid().ToString('N'))
        $script:Release = Join-Path $Work 'release'
        $script:Caddy = Join-Path $Work 'Diwan\bin\caddy.exe'
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Caddy) | Out-Null
        $script:SavedTemp = $env:TEMP
        $env:TEMP = Join-Path $Work 'temp'
        New-Item -ItemType Directory -Force -Path $env:TEMP | Out-Null
        Mock Get-WindowsArch { 'AMD64' }
        Mock Get-LatestCaddyVersion { '2.99.0' }
        # GitHub: the files of $Release by name.
        Mock Download {
            $file = Join-Path $script:Release (($url -split '/')[-1])
            if (-not (Test-Path -LiteralPath $file)) { throw "The remote server returned an error: (404) Not Found. $url" }
            Copy-Item -LiteralPath $file -Destination $out -Force
        }
        Mock Stop-ScheduledTask { }
        Mock Get-CimInstance { }
        Mock Write-Host { }
    }

    AfterEach { $env:TEMP = $script:SavedTemp }

    It 'installs it where there is none (a first install), and leaves no temporary files' {
        New-CaddyRelease $Release $Caddy299
        Initialize-Caddy $Caddy | Should -Be '2.99.0'
        Get-CaddyVersion $Caddy | Should -Be '2.99.0'
        Should -Invoke Download -Times 2 -Exactly
        Should -Invoke Download -Times 1 -Exactly -ParameterFilter { $url -eq 'https://github.com/caddyserver/caddy/releases/download/v2.99.0/caddy_2.99.0_windows_amd64.zip' }
        Should -Invoke Download -Times 1 -Exactly -ParameterFilter { $url -eq 'https://github.com/caddyserver/caddy/releases/download/v2.99.0/caddy_2.99.0_checksums.txt' }
        @(Get-ChildItem -LiteralPath $env:TEMP -Force) | Should -BeNullOrEmpty
        Test-Path -LiteralPath "$Caddy.new" | Should -BeFalse
    }

    It 'keeps a caddy.exe that runs, without downloading anything' {
        Copy-Item -LiteralPath $Caddy250 -Destination $Caddy
        Initialize-Caddy $Caddy | Should -Be '2.50.0'
        Should -Invoke Get-LatestCaddyVersion -Times 0 -Exactly
        Should -Invoke Download -Times 0 -Exactly
        (Get-FileHash $Caddy).Hash | Should -Be (Get-FileHash $Caddy250).Hash
    }

    It 'stops with a clear message when GitHub cannot be reached' {
        Mock Get-LatestCaddyVersion { throw 'The remote name could not be resolved: github.com' }
        { Initialize-Caddy $Caddy } | Should -Throw '*Could not find the latest Caddy release on github.com*'
        Test-Path -LiteralPath $Caddy | Should -BeFalse
    }

    It 'stops with a clear message when the download fails, and leaves nothing behind' {
        New-CaddyRelease $Release $Caddy299
        Mock Download { throw 'The operation has timed out' }
        { Initialize-Caddy $Caddy } | Should -Throw '*Could not download the checksums of Caddy 2.99.0*'
        Test-Path -LiteralPath $Caddy | Should -BeFalse
        @(Get-ChildItem -LiteralPath $env:TEMP -Force) | Should -BeNullOrEmpty
    }

    It 'stops when the zip cannot be downloaded' {
        New-CaddyRelease $Release $Caddy299
        Remove-Item -LiteralPath (Join-Path $Release 'caddy_2.99.0_windows_amd64.zip')
        { Initialize-Caddy $Caddy } | Should -Throw '*Could not download Caddy 2.99.0*'
        Test-Path -LiteralPath $Caddy | Should -BeFalse
    }

    It 'refuses a zip that does not match the checksum of its release' {
        New-CaddyRelease $Release $Caddy299
        [IO.File]::AppendAllText((Join-Path $Release 'caddy_2.99.0_windows_amd64.zip'), 'changed on the way')
        { Initialize-Caddy $Caddy } | Should -Throw '*does not match the SHA512 checksum of its release*'
        Test-Path -LiteralPath $Caddy | Should -BeFalse
    }

    It 'refuses a download that is not a zip' {
        New-Item -ItemType Directory -Force -Path $Release | Out-Null
        $zip = Join-Path $Release 'caddy_2.99.0_windows_amd64.zip'
        [IO.File]::WriteAllText($zip, '<html>Not a zip</html>')
        Set-Content -Path (Join-Path $Release 'caddy_2.99.0_checksums.txt') -Encoding Ascii -Value ('{0}  caddy_2.99.0_windows_amd64.zip' -f (Get-FileHash $zip -Algorithm SHA512).Hash)
        { Initialize-Caddy $Caddy } | Should -Throw '*Could not unpack caddy_2.99.0_windows_amd64.zip*'
        Test-Path -LiteralPath $Caddy | Should -BeFalse
    }

    It 'refuses a zip without caddy.exe' {
        New-CaddyRelease $Release $null
        { Initialize-Caddy $Caddy } | Should -Throw '*caddy_2.99.0_windows_amd64.zip has no caddy.exe*'
    }

    It 'refuses a caddy.exe that does not run' {
        New-CaddyRelease $Release $NotAProgram
        { Initialize-Caddy $Caddy } | Should -Throw '*The downloaded caddy.exe does not run on this computer*'
        Test-Path -LiteralPath $Caddy | Should -BeFalse
    }

    It 'refuses a caddy.exe of another version than its release' {
        New-CaddyRelease $Release $Caddy298
        { Initialize-Caddy $Caddy } | Should -Throw '*reports version 2.98.0 instead of 2.99.0*'
        Test-Path -LiteralPath $Caddy | Should -BeFalse
    }

    It 'stops on a processor Caddy has no Windows build for' {
        Mock Get-WindowsArch { 'X86' }
        { Initialize-Caddy $Caddy } | Should -Throw '*no Windows build for this processor (X86)*'
        Should -Invoke Download -Times 0 -Exactly
    }

    It 'takes the ARM64 build on an ARM computer' {
        Mock Get-WindowsArch { 'ARM64' }
        New-CaddyRelease $Release $Caddy299 -Arch amd64, arm64
        Initialize-Caddy $Caddy | Should -Be '2.99.0'
        Should -Invoke Download -Times 1 -Exactly -ParameterFilter { $url -like '*/caddy_2.99.0_windows_arm64.zip' }
    }

    It 'stops when the release has no build for this processor' {
        Mock Get-WindowsArch { 'ARM64' }
        New-CaddyRelease $Release $Caddy299 -Arch amd64
        { Initialize-Caddy $Caddy } | Should -Throw '*its release has no caddy_2.99.0_windows_arm64.zip*'
        Should -Invoke Download -Times 0 -Exactly -ParameterFilter { $url -like '*.zip' }
    }

    It 'replaces a caddy.exe that does not run by the official release' {
        New-CaddyRelease $Release $Caddy299
        Copy-Item -LiteralPath $NotAProgram -Destination $Caddy
        Initialize-Caddy $Caddy | Should -Be '2.99.0'
        Get-CaddyVersion $Caddy | Should -Be '2.99.0'
        Test-Path -LiteralPath "$Caddy.damaged" | Should -BeFalse
        Should -Invoke Stop-ScheduledTask -Times 1 -Exactly -ParameterFilter { $TaskName -eq 'Diwan Web' }
    }

    It 'puts that caddy.exe back when its replacement cannot be installed' {
        Copy-Item -LiteralPath $NotAProgram -Destination $Caddy
        Mock Download { throw 'The operation has timed out' }
        { Initialize-Caddy $Caddy } | Should -Throw '*Could not download*'
        (Get-FileHash $Caddy).Hash | Should -Be (Get-FileHash $NotAProgram).Hash
        Test-Path -LiteralPath "$Caddy.damaged" | Should -BeFalse
    }
}

Describe 'The real latest Caddy release' -Tag Network {
    It 'is found on GitHub, downloaded for this processor, checked, unpacked and run' {
        $saved = $env:TEMP
        try {
            $env:TEMP = Join-Path $TestDrive 'temp'
            New-Item -ItemType Directory -Force -Path $env:TEMP | Out-Null
            $version = Get-LatestCaddyVersion
            $version | Should -Match '^\d+\.\d+\.\d+$'
            $exe = Join-Path $TestDrive 'bin\caddy.exe'
            New-Item -ItemType Directory -Force -Path (Split-Path -Parent $exe) | Out-Null
            Initialize-Caddy $exe | Should -Be $version
            Get-CaddyVersion $exe | Should -Be $version
            @(Get-ChildItem -LiteralPath $env:TEMP -Force) | Should -BeNullOrEmpty
        } finally { $env:TEMP = $saved }
    }
}

Describe 'Shortcuts' {
    BeforeEach {
        $script:Work = Join-Path $TestDrive ([guid]::NewGuid().ToString('N'))
        $script:Desktop = Join-Path $Work 'Users\Public\Desktop'
        $script:StartMenu = Join-Path $Work 'ProgramData\Microsoft\Windows\Start Menu\Programs'
        $script:Menu = Join-Path $StartMenu 'Diwan ERP'
        $script:Tools = Join-Path $Work 'Diwan\current\deploy\windows\local-tools.ps1'
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Tools) | Out-Null
        Set-Content -LiteralPath $Tools -Value '# the tools'
        $script:Url = 'http://localhost:8000'
        Mock Warn { }
    }

    It 'makes the desktop shortcut and the Start menu folder, and the folders they need' {
        @(Install-Shortcuts $Url $Desktop $StartMenu $Tools $PowerShellExe $Work) | Should -BeNullOrEmpty
        Get-Content -LiteralPath (Join-Path $Desktop 'Diwan ERP.url') | Should -Contain "URL=$Url"
        Get-Content -LiteralPath (Join-Path $Menu 'Open Diwan ERP.url') | Should -Contain "URL=$Url"
        @(Get-ChildItem -LiteralPath $Menu | Sort-Object Name | ForEach-Object { $_.Name }) |
            Should -Be @('Backup Diwan.lnk', 'Open Diwan ERP.url', 'Restart Diwan.lnk', 'Uninstall Diwan.lnk', 'Update Diwan.lnk')
        $shell = New-Object -ComObject WScript.Shell
        foreach ($tool in @(@('Update Diwan', 'update'), @('Backup Diwan', 'backup'), @('Restart Diwan', 'restart'), @('Uninstall Diwan', 'uninstall'))) {
            $link = $shell.CreateShortcut((Join-Path $Menu "$($tool[0]).lnk"))
            $link.TargetPath | Should -Be $PowerShellExe
            $link.Arguments | Should -Be "-NoProfile -ExecutionPolicy Bypass -File `"$Tools`" -Action $($tool[1])"
            $link.WorkingDirectory | Should -Be $Work
        }
        Should -Invoke Warn -Times 0 -Exactly
    }

    It 'gives every shortcut a name of ASCII characters' {
        Install-Shortcuts $Url $Desktop $StartMenu $Tools $PowerShellExe $Work | Out-Null
        foreach ($item in @(Get-ChildItem -LiteralPath $Desktop, $StartMenu -Recurse)) { $item.Name | Should -Match '^[\x20-\x7E]+$' }
    }

    It 'can run again over its own shortcuts' {
        Install-Shortcuts $Url $Desktop $StartMenu $Tools $PowerShellExe $Work | Out-Null
        @(Install-Shortcuts 'http://localhost:8001' $Desktop $StartMenu $Tools $PowerShellExe $Work) | Should -BeNullOrEmpty
        Get-Content -LiteralPath (Join-Path $Desktop 'Diwan ERP.url') | Should -Contain 'URL=http://localhost:8001'
        @(Get-ChildItem -LiteralPath $Menu).Count | Should -Be 5
    }

    It 'replaces the Arabic shortcuts of earlier installers and the .lnk files of the interim one' {
        $oldMenu = Join-Path $StartMenu 'الديوان'
        $old = @(
            (Join-Path $Desktop 'الديوان.url'), (Join-Path $Desktop 'Diwan ERP.lnk'), (Join-Path $Menu 'Open Diwan ERP.lnk'),
            (Join-Path $oldMenu 'فتح الديوان.url'), (Join-Path $oldMenu 'تحديث الديوان.lnk'), (Join-Path $oldMenu 'نسخة احتياطية الآن.lnk'),
            (Join-Path $oldMenu 'إعادة تشغيل الديوان.lnk'), (Join-Path $oldMenu 'إلغاء تثبيت الديوان.lnk')
        )
        foreach ($file in $old) {
            New-Item -ItemType Directory -Force -Path (Split-Path -Parent $file) | Out-Null
            [IO.File]::WriteAllText($file, 'an earlier shortcut')
        }
        @(Install-Shortcuts $Url $Desktop $StartMenu $Tools $PowerShellExe $Work) | Should -BeNullOrEmpty
        foreach ($file in $old) { Test-Path -LiteralPath $file | Should -BeFalse -Because "$file is replaced" }
        Test-Path -LiteralPath $oldMenu | Should -BeFalse
    }

    It 'keeps an earlier shortcut when its replacement cannot be made' {
        $oldDesktop = Join-Path $Desktop 'الديوان.url'
        New-Item -ItemType Directory -Force -Path $Desktop | Out-Null
        [IO.File]::WriteAllText($oldDesktop, 'an earlier shortcut')
        # A folder where the new shortcut goes: it cannot be written.
        New-Item -ItemType Directory -Force -Path (Join-Path $Desktop 'Diwan ERP.url') | Out-Null
        $missing = @(Install-Shortcuts $Url $Desktop $StartMenu $Tools $PowerShellExe $Work)
        $missing | Should -Be @((Join-Path $Desktop 'Diwan ERP.url'))
        Test-Path -LiteralPath $oldDesktop | Should -BeTrue
        Should -Invoke Warn -Times 1 -Exactly -ParameterFilter { $text -like 'Shortcut not created:*Diwan ERP.url*' }
    }

    It 'reports the shortcuts it cannot save, and goes on with the others' {
        # The Start menu folder is a file here: nothing can be saved in it.
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $StartMenu) | Out-Null
        [IO.File]::WriteAllText($StartMenu, 'not a folder')
        $missing = @(Install-Shortcuts $Url $Desktop $StartMenu $Tools $PowerShellExe $Work)
        $missing.Count | Should -Be 5
        Test-Path -LiteralPath (Join-Path $Desktop 'Diwan ERP.url') | Should -BeTrue
        Should -Invoke Warn -Times 5 -Exactly -ParameterFilter { $text -like 'Shortcut not created:*' }
    }

    It 'skips the tools when local-tools.ps1 is missing, and says so' {
        Remove-Item -LiteralPath $Tools
        $missing = @(Install-Shortcuts $Url $Desktop $StartMenu $Tools $PowerShellExe $Work)
        $missing.Count | Should -Be 1
        @(Get-ChildItem -LiteralPath $Menu | ForEach-Object { $_.Name }) | Should -Be @('Open Diwan ERP.url')
        Test-Path -LiteralPath (Join-Path $Desktop 'Diwan ERP.url') | Should -BeTrue
        Should -Invoke Warn -Times 1 -Exactly -ParameterFilter { $text -like 'The Diwan tools are missing*' }
    }

    It 'does not save a shortcut to a program that is missing' {
        $path = Join-Path $Work 'x.lnk'
        New-ProgramShortcut $path (Join-Path $Work 'missing.exe') '' $Work 'x' | Should -BeFalse
        Test-Path -LiteralPath $path | Should -BeFalse
        Should -Invoke Warn -Times 1 -Exactly -ParameterFilter { $text -like '*its program is missing*' }
    }

    It 'goes without a desktop or Start menu folder, and says so' {
        $missing = @(Install-Shortcuts $Url $null $null $Tools $PowerShellExe $Work)
        $missing.Count | Should -Be 2
        Should -Invoke Warn -Times 2 -Exactly
    }

    It 'shows why the Arabic names failed: WScript.Shell cannot save them on this Windows' {
        $path = Join-Path $Work 'الديوان\تحديث الديوان.lnk'
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $path) | Out-Null
        $link = (New-Object -ComObject WScript.Shell).CreateShortcut($path)
        $link.TargetPath = $PowerShellExe
        $saved = $true
        try { $link.Save() } catch { $saved = $false }
        if ($saved) {
            Set-ItResult -Skipped -Because 'this Windows saves Arabic shortcut names (its code page for programs is Arabic)'
            return
        }
        New-ProgramShortcut $path $PowerShellExe '' $Work 'x' | Should -BeFalse
        Should -Invoke Warn -Times 1 -Exactly -ParameterFilter { $text -like 'Shortcut not created:*' }
    }
}

Describe 'Diwan-Setup.bat' -Skip:(-not $IsAdmin) {
    BeforeAll {
        # cmd /c ""<bat>" /local < NUL": the pause at its end reads NUL and returns at once.
        function Invoke-Setup([string]$Bat) {
            $process = Start-Process -FilePath $env:ComSpec -ArgumentList ('/d /c ""' + $Bat + '" /local < NUL"') -Wait -PassThru -NoNewWindow
            return $process.ExitCode
        }
        # A stand-in installer that notes it ran and exits with 7; -Complete gives it the end line.
        function New-Installer([string]$Path, [switch]$Complete) {
            $text = "Set-Content -LiteralPath `$env:DIWAN_TEST_MARK -Value 'ran'`r`nexit 7`r`n"
            if ($Complete) { $text += "# Diwan installer end`r`n" }
            [IO.File]::WriteAllText($Path, $text)
        }
        function New-SetupFolder([string]$Name) {
            $folder = Join-Path $TestDrive $Name
            New-Item -ItemType Directory -Force -Path $folder | Out-Null
            Copy-Item -LiteralPath $SetupBat -Destination (Join-Path $folder 'Diwan-Setup (1).bat')
            $env:DIWAN_TEST_MARK = Join-Path $folder 'ran.txt'
            return $folder
        }
    }
    AfterEach { Remove-Item Env:\DIWAN_TEST_MARK -ErrorAction SilentlyContinue }

    It 'runs the complete install.ps1 next to it from a folder with spaces, brackets and &, and returns its exit code' {
        $folder = New-SetupFolder 'Diwan setup (1) & co'
        New-Installer (Join-Path $folder 'install.ps1') -Complete
        Invoke-Setup (Join-Path $folder 'Diwan-Setup (1).bat') | Should -Be 7
        Test-Path -LiteralPath $env:DIWAN_TEST_MARK | Should -BeTrue
    }

    It 'runs from a folder with an Arabic name' {
        $folder = New-SetupFolder 'تثبيت الديوان'
        New-Installer (Join-Path $folder 'install.ps1') -Complete
        Invoke-Setup (Join-Path $folder 'Diwan-Setup (1).bat') | Should -Be 7
        Test-Path -LiteralPath $env:DIWAN_TEST_MARK | Should -BeTrue
    }

    It 'does not run an installer that did not arrive complete' {
        $folder = New-SetupFolder 'cut'
        New-Installer (Join-Path $folder 'install.ps1')
        Invoke-Setup (Join-Path $folder 'Diwan-Setup (1).bat') | Should -Be 1
        Test-Path -LiteralPath $env:DIWAN_TEST_MARK | Should -BeFalse
    }

    It 'does not run an empty file' {
        $folder = New-SetupFolder 'empty'
        [IO.File]::WriteAllText((Join-Path $folder 'install.ps1'), '')
        Invoke-Setup (Join-Path $folder 'Diwan-Setup (1).bat') | Should -Be 1
        Test-Path -LiteralPath $env:DIWAN_TEST_MARK | Should -BeFalse
    }

    It 'stops with exit code 1 when there is no install.ps1 next to it' {
        $folder = New-SetupFolder 'none'
        Invoke-Setup (Join-Path $folder 'Diwan-Setup (1).bat') | Should -Be 1
    }
}
