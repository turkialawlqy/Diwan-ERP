<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\RemoteAccess;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Internet access of a Windows local install: the page shows up, the request is handed to the SYSTEM task, the helper script is sound. */
class RemoteAccessWindowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.local_install' => true]);
        File::deleteDirectory(storage_path('app/remote-access-queue'));
        @unlink(storage_path('app/remote-access-status.json'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/remote-access-queue'));
        @unlink(storage_path('app/remote-access-status.json'));
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /** The service as it behaves on the given operating system, without running Windows commands here. */
    protected function on(string $os): void
    {
        $this->app->bind(RemoteAccess::class, fn () => new class($os) extends RemoteAccess
        {
            public function __construct(protected string $fakeOs) {}

            public function os(): string
            {
                return $this->fakeOs;
            }

            protected function trigger(): void {}
        });
    }

    protected function owner(): void
    {
        $company = Company::factory()->create();
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
    }

    public function test_the_page_is_there_on_windows_and_linux_but_not_on_other_systems(): void
    {
        $this->owner();
        foreach (['Windows', 'Linux'] as $os) {
            $this->on($os);
            $this->get(route('settings.remote-access'))->assertOk()->assertInertia(fn ($p) => $p->component('Settings/RemoteAccess'));
        }
        $this->on('Darwin');
        $this->get(route('settings.remote-access'))->assertNotFound();

        $this->on('Windows');
        config(['app.local_install' => false]);
        $this->get(route('settings.remote-access'))->assertNotFound();
    }

    public function test_a_windows_request_is_written_for_the_system_task_and_marks_the_status_requested(): void
    {
        $this->owner();
        $this->on('Windows');
        $token = 'eyJhIjoiYWJjZGVmMDEyMzQ1Njc4OSIsInQiOiIxMjM0NTY3OC05MDEyIiwicyI6InNlY3JldC1zZWNyZXQifQ==';

        $this->post(route('settings.remote-access.apply'), ['mode' => 'cloudflare', 'domain' => 'erp.example.com', 'secret' => $token])->assertSessionHasNoErrors();

        $request = json_decode(file_get_contents(storage_path('app/remote-access-queue/request')), true);
        $this->assertSame(['mode' => 'cloudflare', 'domain' => 'erp.example.com', 'secret' => $token], $request);
        $status = json_decode(file_get_contents(storage_path('app/remote-access-status.json')), true);
        $this->assertSame('requested', $status['state']);
        $this->assertStringNotContainsString($token, json_encode($status));
    }

    public function test_the_task_points_through_the_current_junction_so_it_survives_an_update(): void
    {
        $remote = app(RemoteAccess::class);
        $this->assertSame('C:\\Diwan\\current\\deploy\\windows\\remote-access.ps1', $remote->windowsScript('C:\\Diwan\\releases\\20261123-1.8.2'));
        $this->assertSame('D:\\Apps\\Diwan\\current\\deploy\\windows\\remote-access.ps1', $remote->windowsScript('D:\\Apps\\Diwan\\releases\\x'));
    }

    public function test_the_windows_helper_and_its_task_registration_are_sound(): void
    {
        $script = file_get_contents(base_path('deploy/windows/remote-access.ps1'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $script, 'UTF-8 with BOM, for Windows PowerShell 5.1');
        $this->assertDoesNotMatchRegularExpression('/\?\?|\?\.|\s\?\s.*\s:\s/', $script, 'PowerShell 5.1 has no ?? / ?. / ternary');
        $this->assertDoesNotMatchRegularExpression('/[^\x00-\x7F]/', substr($script, 3), 'English only, so the console shows it right on any Windows');
        foreach (['APP_URL', 'APP_DOMAIN', 'FORCE_HTTPS', 'SESSION_SECURE_COOKIE', 'TRUSTED_PROXIES', "Set-Bind '127.0.0.1'", 'cloudflared', 'tailscale', 'DIWAN_DRY_RUN', 'Clear-Request'] as $needle) {
            $this->assertStringContainsString($needle, $script);
        }
        // The request (it holds a secret) is cleared on the error path too.
        $this->assertGreaterThanOrEqual(3, substr_count($script, 'Clear-Request'));

        foreach (['install.ps1', 'local-tools.ps1', 'local-update.ps1'] as $file) {
            $this->assertStringContainsString('Diwan Remote Access', file_get_contents(base_path('deploy/windows/'.$file)), $file);
        }
    }

    public function test_the_helper_dry_run_when_powershell_is_installed(): void
    {
        $pwsh = trim((string) Process::run('command -v pwsh')->output());
        if ($pwsh === '') {
            $this->markTestSkipped('PowerShell is not installed here; the Windows helper is checked as text only.');
        }
        $dir = sys_get_temp_dir().'/diwan-ra-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/shared/storage/app/remote-access-queue');
        file_put_contents($dir.'/shared/.env', "APP_NAME=Diwan\nAPP_URL=http://localhost:8000\nFORCE_HTTPS=false\n");
        file_put_contents($dir.'/Caddyfile', "{\n\tadmin off\n}\n\n:8000 {\n\troot * x\n}\n");
        file_put_contents($dir.'/shared/storage/app/remote-access-queue/request', json_encode(['mode' => 'cloudflare', 'domain' => 'erp.example.com', 'secret' => str_repeat('a', 60)]));

        $result = Process::env(['DIWAN_DIR' => $dir, 'DIWAN_DRY_RUN' => '1', 'DIWAN_DRY_LOG' => $dir.'/dry.log'])->run([$pwsh, '-NoProfile', '-File', base_path('deploy/windows/remote-access.ps1')]);
        $env = file_get_contents($dir.'/shared/.env');
        $status = json_decode(file_get_contents($dir.'/shared/storage/app/remote-access-status.json'), true);
        File::deleteDirectory($dir);

        $this->assertTrue($result->successful(), 'exit '.$result->exitCode().' | '.$result->output().' | '.$result->errorOutput().' | status: '.json_encode($status));
        $this->assertStringContainsString('APP_URL=https://erp.example.com', $env);
        $this->assertStringContainsString('TRUSTED_PROXIES=127.0.0.1', $env);
        $this->assertSame('done', $status['state']);
        $this->assertSame('https://erp.example.com', $status['url']);
    }
}
