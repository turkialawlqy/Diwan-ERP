#Requires -Version 5.1
<#
    Pester 5 tests of how the Start menu tools (local-tools.ps1) and the daily update (local-update.ps1)
    of a Windows install find the install folder, on Windows PowerShell 5.1:
        Invoke-Pester -Path tests/windows
    The Find-DiwanRoot of each script runs on installs laid out in temporary folders as the installer
    lays them out (diwan.json, releases\<release>, and the junction current to it). The lines of the
    script that set $Root also run as a script through that junction, the way its Start menu shortcut
    and the "Diwan Update" task run it.
    Keep this file UTF-8 with BOM, like the other Windows scripts.
#>

BeforeAll {
    $ErrorActionPreference = 'Stop'
    $script:Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    $script:PowerShellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
}

Describe 'The install folder in <File>' -ForEach @(@{ File = 'local-tools.ps1' }, @{ File = 'local-update.ps1' }) {
    BeforeAll {
        $ast = [Management.Automation.Language.Parser]::ParseFile((Join-Path $Repo "deploy\windows\$File"), [ref]$null, [ref]$null)
        $function = $ast.Find({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Find-DiwanRoot' }, $false)
        $setRoot = $ast.Find({ param($node) $node -is [Management.Automation.Language.AssignmentStatementAst] -and $node.Left.Extent.Text -eq '$Root' }, $false)
        if (-not $function -or -not $setRoot) { throw "$File has no function Find-DiwanRoot, or no line that sets `$Root." }
        # Its Find-DiwanRoot on its own; and its lines that set $Root, as a script that prints $Root.
        $script:FindRoot = [scriptblock]::Create($function.Extent.Text + "`r`nFind-DiwanRoot @args")
        $script:RootLines = $function.Extent.Text + "`r`n" + $setRoot.Extent.Text + "`r`n`$Root`r`n"
        $script:Junctions = New-Object 'System.Collections.Generic.List[string]'

        # An install in $Dir as the installer lays it out: diwan.json, a release, and the junction current
        # to that release (mklink /J, as install.ps1 and local-update.ps1 make it). Returns the release.
        # .NET makes the folders and files, so the brackets of a name are never read as a wildcard.
        function New-Install([string]$Dir) {
            $release = Join-Path $Dir 'releases\20261123033000-1.8.2'
            [IO.Directory]::CreateDirectory((Join-Path $release 'deploy\windows')) | Out-Null
            [IO.File]::WriteAllText((Join-Path $Dir 'diwan.json'), '{"port": 8000}')
            $current = Join-Path $Dir 'current'
            cmd /c mklink /J "$current" "$release" | Out-Null
            if ((Get-Item -LiteralPath $current).LinkType -ne 'Junction') { throw "mklink /J did not make the junction $current" }
            $Junctions.Add($current)
            return $release
        }
    }

    BeforeEach { $script:Work = Join-Path $TestDrive ([guid]::NewGuid().ToString('N')) }

    # rmdir removes a junction and never what it points to; TestDrive removes the rest.
    AfterAll { foreach ($junction in $Junctions) { if (Test-Path -LiteralPath $junction) { cmd /c rmdir "$junction" | Out-Null } } }

    It 'is three folders up through the junction current (current\deploy\windows)' {
        $root = Join-Path $Work 'Diwan'
        New-Install $root | Out-Null
        & $FindRoot (Join-Path $root 'current\deploy\windows') | Should -Be $root
    }

    It 'is four folders up in a release folder (releases\x\deploy\windows)' {
        $root = Join-Path $Work 'Diwan'
        $release = New-Install $root
        & $FindRoot (Join-Path $release 'deploy\windows') | Should -Be $root
    }

    It 'is found in a folder whose name has spaces and brackets' {
        $root = Join-Path $Work 'Diwan files [2]'
        New-Install $root | Out-Null
        & $FindRoot (Join-Path $root 'current\deploy\windows') | Should -Be $root
    }

    It 'is C:\Diwan, as before, where no folder above has diwan.json' {
        $root = Join-Path $Work 'Diwan'
        $release = New-Install $root
        [IO.File]::Delete((Join-Path $root 'diwan.json'))
        & $FindRoot (Join-Path $root 'current\deploy\windows') | Should -Be 'C:\Diwan'
        & $FindRoot (Join-Path $release 'deploy\windows') | Should -Be 'C:\Diwan'
        # A release unpacked anywhere else, a script two folders below the root of the drive (the search
        # stops at the root), and a script without a folder.
        $elsewhere = Join-Path $Work 'Downloads\diwan-erp\deploy\windows'
        [IO.Directory]::CreateDirectory($elsewhere) | Out-Null
        & $FindRoot $elsewhere | Should -Be 'C:\Diwan'
        & $FindRoot (Join-Path ([IO.Path]::GetPathRoot($TestDrive)) 'deploy\windows') | Should -Be 'C:\Diwan'
        & $FindRoot '' | Should -Be 'C:\Diwan'
    }

    It 'looks no further than five folders up' {
        $top = Join-Path $Work 'top'
        [IO.Directory]::CreateDirectory($top) | Out-Null
        [IO.File]::WriteAllText((Join-Path $top 'diwan.json'), '{}')
        & $FindRoot (Join-Path $top '1\2\3\4\windows') | Should -Be $top
        & $FindRoot (Join-Path $top '1\2\3\4\5\windows') | Should -Be 'C:\Diwan'
    }

    It 'is what the script sets $Root to when it runs through the junction, as its shortcut and task run it' {
        $root = Join-Path $Work 'Diwan install'
        $release = New-Install $root
        [IO.File]::WriteAllText((Join-Path $release 'deploy\windows\probe.ps1'), $RootLines)
        $found = & $PowerShellExe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'current\deploy\windows\probe.ps1')
        $LASTEXITCODE | Should -Be 0
        $found | Should -Be $root
    }
}
