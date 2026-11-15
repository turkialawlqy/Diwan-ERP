<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\RemoteAccess;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Internet access of a Linux local install: the request the page makes and what the root helper does with it. */
class RemoteAccessTest extends TestCase
{
    use RefreshDatabase;

    protected string $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.local_install' => true]);
        $this->queue = storage_path('app/remote-access-queue');
        File::deleteDirectory($this->queue);
        @unlink(storage_path('app/remote-access-status.json'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->queue);
        @unlink(storage_path('app/remote-access-status.json'));
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function owner(): void
    {
        $company = Company::factory()->create();
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
    }

    protected function token(): string
    {
        return 'eyJhIjoiYWJjZGVmMDEyMzQ1Njc4OSIsInQiOiIxMjM0NTY3OC05MDEyIiwicyI6InNlY3JldC1zZWNyZXQifQ==';
    }

    public function test_only_the_owner_of_a_linux_local_install_sees_the_page(): void
    {
        $this->owner();
        $this->get(route('settings.remote-access'))->assertOk()->assertInertia(fn ($p) => $p->component('Settings/RemoteAccess'));

        config(['app.local_install' => false]);
        $this->get(route('settings.remote-access'))->assertNotFound();
        $this->post(route('settings.remote-access.apply'), ['mode' => 'none'])->assertNotFound();

        config(['app.local_install' => true]);
        $this->actingAs($this->userWithRole(Company::first(), 'sales'));
        $this->get(route('settings.remote-access'))->assertNotFound();
    }

    public function test_the_request_goes_to_the_helper_with_a_private_file_and_no_database_copy(): void
    {
        $this->owner();
        $this->post(route('settings.remote-access.apply'), ['mode' => 'cloudflare', 'domain' => 'ERP.Example.com', 'secret' => $this->token()])->assertSessionHasNoErrors();

        $file = $this->queue.'/request';
        $this->assertFileExists($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $data = json_decode(file_get_contents($file), true);
        $this->assertSame(['mode' => 'cloudflare', 'domain' => 'erp.example.com', 'secret' => $this->token()], $data);
        $this->assertSame('requested', app(RemoteAccess::class)->status()['state']);
        // The secret is not in the status file either (the browser reads that one).
        $this->assertStringNotContainsString($this->token(), (string) file_get_contents(storage_path('app/remote-access-status.json')));
        $this->get(route('settings.remote-access.status'))->assertOk()->assertJsonMissingExact(['secret' => $this->token()]);
    }

    public function test_bad_values_never_reach_the_root_helper(): void
    {
        $this->owner();
        foreach ([
            ['mode' => 'cloudflare', 'domain' => 'erp.example.com; rm -rf /', 'secret' => $this->token()],
            ['mode' => 'cloudflare', 'domain' => 'erp.example.com', 'secret' => 'short'],
            ['mode' => 'cloudflare', 'domain' => 'erp.example.com', 'secret' => $this->token().'$(id)'],
            ['mode' => 'tailscale', 'secret' => 'not-a-key'],
            ['mode' => 'wireguard'],
        ] as $input) {
            $this->post(route('settings.remote-access.apply'), $input)->assertSessionHasErrors();
        }
        $this->assertFileDoesNotExist($this->queue.'/request');

        $this->expectException(ValidationException::class);
        app(RemoteAccess::class)->validate(['mode' => 'tailscale', 'secret' => 'tskey-auth-$(id)']);
    }

    public function test_a_second_request_while_one_is_running_is_refused(): void
    {
        $this->owner();
        $this->post(route('settings.remote-access.apply'), ['mode' => 'tailscale', 'secret' => 'tskey-auth-abc123XYZ-def456'])->assertSessionHasNoErrors();
        $this->post(route('settings.remote-access.apply'), ['mode' => 'none'])->assertSessionHasErrors('mode');
    }

    /** Runs the real helper in dry-run mode against a temporary install folder. */
    protected function helper(string $request): array
    {
        $dir = sys_get_temp_dir().'/diwan-ra-'.bin2hex(random_bytes(4));
        mkdir($dir.'/shared/storage/app/remote-access-queue', 0777, true);
        file_put_contents($dir.'/shared/.env', "APP_URL=http://localhost:8000\nAPP_DOMAIN=localhost\nFORCE_HTTPS=false\nSESSION_SECURE_COOKIE=false\nDB_PASSWORD=keep-me\n");
        file_put_contents($dir.'/shared/storage/app/remote-access-queue/request', $request);
        file_put_contents($dir.'/web.service', "ExecStart=/usr/bin/php artisan serve --host=0.0.0.0 --port=8000 --no-reload\n");

        $result = Process::env(['DIWAN_DIR' => $dir, 'DIWAN_DRY_RUN' => '1', 'DIWAN_PORT' => '8000', 'DIWAN_LOCK' => $dir.'/lock', 'DIWAN_DRY_LOG' => $dir.'/dry.log', 'DIWAN_WEB_UNIT' => $dir.'/web.service'])
            ->run(['bash', base_path('deploy/linux/remote-access.sh')]);

        $out = [
            'ok' => $result->successful(), 'output' => $result->output().$result->errorOutput().(string) @file_get_contents($dir.'/dry.log'),
            'env' => (string) file_get_contents($dir.'/shared/.env'),
            'unit' => (string) file_get_contents($dir.'/web.service'),
            'status' => json_decode((string) @file_get_contents($dir.'/shared/storage/app/remote-access-status.json'), true),
            'request_left' => file_exists($dir.'/shared/storage/app/remote-access-queue/request'),
        ];
        File::deleteDirectory($dir);

        return $out;
    }

    public function test_the_helper_turns_on_a_cloudflare_tunnel_and_tunes_the_settings(): void
    {
        $r = $this->helper(json_encode(['mode' => 'cloudflare', 'domain' => 'erp.example.com', 'secret' => $this->token()]));

        $this->assertTrue($r['ok'], $r['output']);
        $this->assertStringContainsString('[dry-run] cloudflared service install', $r['output']);
        foreach (['APP_URL=https://erp.example.com', 'APP_DOMAIN=erp.example.com', 'FORCE_HTTPS=true', 'SESSION_SECURE_COOKIE=true', 'TRUSTED_PROXIES=127.0.0.1', 'DB_PASSWORD=keep-me'] as $line) {
            $this->assertStringContainsString($line, $r['env']);
        }
        $this->assertStringContainsString('--host=127.0.0.1', $r['unit']);
        $this->assertSame('done', $r['status']['state']);
        $this->assertSame('https://erp.example.com', $r['status']['url']);
        $this->assertFalse($r['request_left'], 'the request holds a secret and must be deleted');
        $this->assertStringNotContainsString($this->token(), json_encode($r['status']));
    }

    public function test_the_helper_joins_tailscale_without_a_public_link_and_can_switch_it_all_off(): void
    {
        $r = $this->helper(json_encode(['mode' => 'tailscale', 'domain' => '', 'secret' => 'tskey-auth-abc123XYZ-def456']));
        $this->assertTrue($r['ok'], $r['output']);
        $this->assertStringContainsString('[dry-run] tailscale up', $r['output']);
        $this->assertStringContainsString('FORCE_HTTPS=false', $r['env']);
        $this->assertStringNotContainsString('TRUSTED_PROXIES', $r['env']);
        $this->assertStringContainsString('--host=0.0.0.0', $r['unit']);
        $this->assertSame('http://100.64.0.1:8000', $r['status']['url']);

        $off = $this->helper(json_encode(['mode' => 'none', 'domain' => '', 'secret' => '']));
        $this->assertTrue($off['ok'], $off['output']);
        $this->assertSame('done', $off['status']['state']);
        $this->assertSame('none', $off['status']['mode']);
    }

    public function test_the_helper_refuses_a_hostile_request_and_still_deletes_it(): void
    {
        $r = $this->helper(json_encode(['mode' => 'cloudflare', 'domain' => 'a.example.com', 'secret' => 'abc$(touch /tmp/pwned)abcdefghij']));
        $this->assertFalse($r['ok']);
        $this->assertSame('failed', $r['status']['state']);
        $this->assertFalse($r['request_left']);
        $this->assertFileDoesNotExist('/tmp/pwned');
        $this->assertStringContainsString('FORCE_HTTPS=false', $r['env']);
    }

    public function test_the_scripts_are_valid_bash(): void
    {
        foreach (['remote-access.sh', 'install.sh', 'update.sh'] as $script) {
            $this->assertTrue(Process::run(['bash', '-n', base_path('deploy/linux/'.$script)])->successful(), $script);
        }
    }
}
