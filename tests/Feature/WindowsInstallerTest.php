<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Windows installer (deploy/windows), as far as it can be checked without Windows. Its
 * functions run on Windows in tests/windows (Pester), and a full install, then a run again over
 * it, in the "Windows installer" workflow (.github/workflows/windows-installer.yml).
 */
class WindowsInstallerTest extends TestCase
{
    /** The last line of install.ps1: Diwan-Setup.bat runs a download only when it arrived. */
    private const END_LINE = '# Diwan installer end';

    public function test_caddy_comes_from_its_official_github_release(): void
    {
        $ps1 = $this->file('deploy/windows/install.ps1');

        // The build service answers 401 without an API key; the REST API limits anonymous calls.
        $this->assertStringNotContainsString('caddyserver.com/api', $ps1);
        $this->assertStringNotContainsString('api.github.com', $ps1);
        $this->assertStringContainsString("\$CaddyReleases = 'https://github.com/caddyserver/caddy/releases'", $ps1);
        $this->assertStringContainsString('"$CaddyReleases/latest"', $ps1);
        $this->assertStringContainsString('"$CaddyReleases/download/v$version"', $ps1);
        // A stable version only, from the official repository.
        $this->assertStringContainsString("'^https://github\\.com/caddyserver/caddy/releases/tag/v(\\d+\\.\\d+\\.\\d+)$'", $ps1);
        // The zip of this processor, as its release lists it in its checksums, checked before use.
        $this->assertStringContainsString('"caddy_$($version)_windows_$arch.zip"', $ps1);
        $this->assertStringContainsString('caddy_$($version)_checksums.txt', $ps1);
        $this->assertStringContainsString('(Get-FileHash -LiteralPath $zip -Algorithm $expected.Algorithm).Hash -ne $expected.Hash', $ps1);
        $this->assertStringNotContainsString('windows_amd64', $ps1, 'The processor is read from Windows, not assumed.');
        $this->assertMatchesRegularExpression("/'AMD64' \\{ return 'amd64' \\}\\s+'ARM64' \\{ return 'arm64' \\}/", $ps1);
        // Step 7 keeps a caddy.exe that runs and replaces only one that does not.
        $this->assertStringContainsString('$caddyVersion = Initialize-Caddy $caddyExe', $ps1);
    }

    public function test_shortcuts_have_ascii_names_and_the_uninstaller_removes_old_and_new(): void
    {
        $ps1 = $this->file('deploy/windows/install.ps1');
        $body = $this->functionBody($ps1, 'Install-Shortcuts');

        // What it creates: the desktop shortcut, the Start menu folder and what is in it.
        preg_match_all("/\\\$path = Join-Path \\\$(?:desktop|menu) '([^']+)'/", $body, $made);
        preg_match_all("/Name = '([^']+)'/", $body, $tools);
        preg_match_all("/\\\$menu = Join-Path \\\$programs '([^']+)'/", $body, $folder);
        $names = array_merge($made[1], $tools[1], $folder[1]);
        $this->assertSame(['Diwan ERP.url', 'Open Diwan ERP.url', 'Update Diwan', 'Backup Diwan', 'Restart Diwan', 'Uninstall Diwan', 'Diwan ERP'], $names);
        foreach ($names as $name) {
            // WScript.Shell saves a shortcut only when its path fits the code page of Windows.
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $name);
        }
        // Arabic text in the installer only names the shortcuts of earlier installs, to remove them.
        foreach (preg_split('/\r?\n/', substr($ps1, 3)) as $number => $line) {
            if (preg_match('/[^\x00-\x7F]/', $line) && ! preg_match("/Old = '|Join-Path \\\$(?:desktop|oldMenu|programs) '[^']*[^\\x00-\\x7F]/", $line)) {
                $this->fail('install.ps1:'.($number + 1).' has non-ASCII text outside the names of old shortcuts: '.trim($line));
            }
        }
        $this->assertStringContainsString('Add-Result (New-UrlShortcut $path $url) $path', $body, 'An old shortcut goes only once its replacement exists.');

        $tools = $this->file('deploy/windows/local-tools.ps1');
        foreach (["'Diwan ERP.url'", "'Diwan ERP.lnk'", "'الديوان.url'", "'Diwan ERP'", "'الديوان'"] as $name) {
            $this->assertStringContainsString($name, $tools, "The uninstaller removes {$name}.");
        }
    }

    public function test_a_shortcut_that_fails_does_not_fail_the_installation(): void
    {
        $ps1 = $this->file('deploy/windows/install.ps1');

        foreach (['New-UrlShortcut', 'New-ProgramShortcut'] as $function) {
            $body = $this->functionBody($ps1, $function);
            $this->assertStringContainsString('} catch { Warn "Shortcut not created: $path ($($_.Exception.Message))" }', $body, "{$function} reports, never throws.");
            $this->assertStringContainsString('New-Item -ItemType Directory -Force -Path (Split-Path -Parent $path)', $body, "{$function} makes the folder first.");
            $this->assertStringContainsString('if (Test-Path -LiteralPath $path -PathType Leaf) { return $true }', $body, "{$function} checks the saved file.");
        }
        $this->assertStringContainsString('if (-not (Test-Path -LiteralPath $target -PathType Leaf))', $this->functionBody($ps1, 'New-ProgramShortcut'));
        $this->assertStringContainsString('if (Test-Path -LiteralPath $toolsScript -PathType Leaf)', $this->functionBody($ps1, 'Install-Shortcuts'));
        // Step 9 comes after the check that Diwan answers (step 7), so a missing shortcut is only a warning.
        $this->assertLessThan(strpos($ps1, "Step 9 'Shortcuts'"), strpos($ps1, 'if (-not $up) { Fail "Diwan did not answer'));
        $this->assertStringContainsString('$missingShortcuts = @(Install-Shortcuts ', $ps1);
        $this->assertStringContainsString('try { Start-Process $url } catch {', $ps1);
    }

    public function test_setup_runs_only_a_complete_download_of_the_latest_installer(): void
    {
        $bat = $this->file('deploy/windows/Diwan-Setup.bat');
        $ps1 = $this->file('deploy/windows/install.ps1');

        // The end line is the last line of the installer, and only there: a cut copy lacks it.
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $ps1)), 'strlen'));
        $this->assertSame(self::END_LINE, end($lines));
        $this->assertSame(1, substr_count($ps1, self::END_LINE));
        $this->assertStringContainsString("\$lines[-1].Trim() -eq '".self::END_LINE."'", $bat);
        $this->assertLessThan(strpos($bat, '-File "%DIWAN_PS1%"'), strpos($bat, "-eq '".self::END_LINE."'"), 'The check comes before the run.');

        // The installer of the latest release, into a new file each time; never an older download.
        $this->assertStringContainsString('set "DIWAN_URL=https://github.com/turkialawlqy/Diwan-ERP/releases/latest/download/install-windows.ps1"', $bat);
        $this->assertStringContainsString('set "DIWAN_PS1=%TEMP%\diwan-install-%RANDOM%%RANDOM%.ps1"', $bat);
        $this->assertStringContainsString('if exist "%DIWAN_PS1%" del /f /q "%DIWAN_PS1%"', $bat);
        // Its exit code comes back, after the window waits for a key.
        $this->assertStringContainsString('set "DIWAN_RC=%ERRORLEVEL%"', $bat);
        $this->assertStringEndsWith("pause\r\nexit /b %DIWAN_RC%\r\n", $bat);
        // Administrator rights: the installer's own test, and asked for once.
        $this->assertStringContainsString('IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)', $bat);
        $this->assertStringContainsString('if defined DIWAN_ELEVATED goto :no_admin', $bat);
        $this->assertDoesNotMatchRegularExpression('/^\s*net session/mi', $bat, 'net session needs the Server service: without it the setup asked for the rights again and again.');
        // Paths reach PowerShell through the environment, never inside its quoted command.
        $this->assertStringNotContainsString("'%~f0'", $bat);
        $this->assertStringContainsString('$env:DIWAN_SETUP', $bat);
    }

    public function test_the_release_publishes_these_files_under_the_names_the_guides_download(): void
    {
        $release = $this->file('.github/workflows/release.yml');

        $this->assertStringContainsString('cp deploy/windows/Diwan-Setup.bat dist/Diwan-Setup.bat', $release);
        $this->assertStringContainsString('cp deploy/windows/install.ps1 dist/install-windows.ps1', $release);
        preg_match('/gh release upload (.+?)--clobber/s', $release, $upload);
        $this->assertNotEmpty($upload, 'release.yml uploads the files with gh release upload.');
        $this->assertStringContainsString('dist/Diwan-Setup.bat', $upload[1]);
        $this->assertStringContainsString('dist/install-windows.ps1', $upload[1]);
        foreach (['README.md', 'docs/INSTALL-WINDOWS.md'] as $guide) {
            $this->assertStringContainsString('https://github.com/turkialawlqy/Diwan-ERP/releases/latest/download/Diwan-Setup.bat', $this->file($guide), $guide);
        }
    }

    public function test_windows_scripts_keep_the_encoding_windows_needs(): void
    {
        $files = glob(base_path('deploy/windows/*.{ps1,bat}'), GLOB_BRACE);
        $this->assertNotEmpty($files);
        foreach ($files as $path) {
            $text = file_get_contents($path);
            $name = basename($path);
            // .gitattributes checks them out with CRLF: cmd needs it to find its labels.
            $this->assertSame(0, preg_match("/(?<!\r)\n/", $text), "{$name}: CRLF line ends");
            if (str_ends_with($name, '.bat')) {
                // cmd reads a .bat in the console code page.
                $this->assertSame(0, preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $text), "{$name}: ASCII only");
            } elseif (preg_match('/[^\x00-\x7F]/', $text)) {
                // Windows PowerShell 5.1 reads a file without a BOM in the ANSI code page: its Arabic text would break.
                $this->assertStringStartsWith("\xEF\xBB\xBF", $text, "{$name}: UTF-8 with BOM");
            }
        }
        foreach (glob(base_path('tests/windows/*.ps1')) as $path) {
            if (preg_match('/[^\x00-\x7F]/', file_get_contents($path))) {
                $this->assertStringStartsWith("\xEF\xBB\xBF", file_get_contents($path), basename($path).': UTF-8 with BOM');
            }
        }
    }

    public function test_a_run_again_keeps_the_data_settings_and_passwords(): void
    {
        $ps1 = $this->file('deploy/windows/install.ps1');

        // Settings and secrets of an earlier run are reused; only missing ones are made.
        foreach (['port', 'db_port', 'db_root_password', 'db_password', 'backup_password'] as $key) {
            $this->assertStringContainsString("if (-not \$config.{$key})", $ps1);
        }
        $this->assertStringContainsString('if (-not (Test-Path $envFile)) {', $ps1, '.env is written only when there is none.');
        $this->assertStringContainsString("if (-not (Select-String -Path \$envFile -Pattern '^APP_KEY=base64' -Quiet))", $ps1, 'APP_KEY is made only once.');
        $this->assertMatchesRegularExpression("/if \\(-not \\(Test-Path \\(Join-Path \\\$DataDir 'mysql'\\)\\)\\) \\{\\s*& \\(Join-Path \\\$DbDir 'bin\\\\mariadb-install-db\\.exe'\\)/", $ps1, 'A database is made only where there is none.');
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS diwan', $ps1);
        $this->assertStringContainsString("if (-not (Test-Path \$sharedStorage)) { Move-Item (Join-Path \$release 'storage') \$sharedStorage }", $ps1);

        // Nothing it removes is the database, the shared settings and files, or the configuration.
        preg_match_all('/Remove-Item\s+(?:-LiteralPath\s+)?(.+?)(?=\s+-(?:Recurse|Force|ErrorAction)\b|\s*[;}]|\r?$)/m', $ps1, $removed);
        $this->assertNotEmpty($removed[1]);
        foreach ($removed[1] as $target) {
            foreach (['$DataDir', '$Shared', '$sharedStorage', '$envFile', '$ConfigFile', 'secrets.txt', '$Root', '$Bin'] as $kept) {
                $this->assertStringNotContainsString($kept, $target, "install.ps1 removes {$target}");
            }
        }
    }

    private function file(string $path): string
    {
        return file_get_contents(base_path($path));
    }

    /** The text of a top-level PowerShell function, from its line to the closing brace at the start of a line. */
    private function functionBody(string $ps1, string $name): string
    {
        $this->assertMatchesRegularExpression('/^function '.preg_quote($name, '/').'\b.*?^\}/ms', $ps1, "install.ps1 defines {$name}");
        preg_match('/^function '.preg_quote($name, '/').'\b.*?^\}/ms', $ps1, $m);

        return $m[0];
    }
}
