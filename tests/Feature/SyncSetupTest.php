<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class SyncSetupTest extends TestCase
{
    protected string $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = sys_get_temp_dir().'/diwan-env-'.uniqid();
        file_put_contents($this->env, "APP_NAME=Diwan\nSYNC_ROLE=off\nAPP_ENV=local\n");
    }

    protected function tearDown(): void
    {
        File::delete($this->env);
        parent::tearDown();
    }

    protected function setup_(array $options): PendingCommand
    {
        return $this->artisan('sync:setup', $options + ['--env' => $this->env, '--skip-join' => true, '--no-interaction' => true]);
    }

    public function test_a_head_office_is_set_up_with_one_option_and_other_settings_are_kept(): void
    {
        $this->setup_(['--role' => 'hub'])->assertSuccessful();
        $text = file_get_contents($this->env);
        $this->assertStringContainsString("SYNC_ROLE=hub\n", $text);
        $this->assertStringContainsString("APP_NAME=Diwan\n", $text);
        $this->assertStringContainsString("APP_ENV=local\n", $text);
        $this->assertSame(1, substr_count($text, 'SYNC_ROLE='));
        $this->assertStringContainsString("SYNC_NODE=1\n", $text);
    }

    public function test_a_branch_needs_its_number_the_head_office_address_and_the_token(): void
    {
        Http::fake(['https://hq.example.com/api/sync/handshake' => Http::response(['ok' => true, 'version' => trim(file_get_contents(base_path('VERSION'))), 'node_id' => 2])]);
        $this->setup_(['--role' => 'branch', '--node' => 2, '--hub-url' => 'https://hq.example.com/', '--token' => 'tok123'])
            ->expectsOutputToContain('تم الاتصال بالمكتب الرئيسي والرمز مقبول')->assertSuccessful();
        $text = file_get_contents($this->env);
        $this->assertStringContainsString("SYNC_ROLE=branch\n", $text);
        $this->assertStringContainsString("SYNC_HUB_URL=https://hq.example.com\n", $text);
        $this->assertStringContainsString("SYNC_TOKEN=tok123\n", $text);
        $this->assertStringContainsString("SYNC_NODE=2\n", $text);

        // Missing or wrong answers change nothing.
        file_put_contents($this->env, "SYNC_ROLE=off\n");
        $this->setup_(['--role' => 'branch', '--node' => 1, '--hub-url' => 'https://hq.example.com', '--token' => 'x'])->assertFailed();
        $this->setup_(['--role' => 'branch', '--node' => 3, '--hub-url' => 'not a url', '--token' => 'x'])->assertFailed();
        $this->setup_(['--role' => 'moon'])->assertFailed();
        $this->assertSame("SYNC_ROLE=off\n", file_get_contents($this->env));
    }

    public function test_an_unreachable_head_office_or_another_release_warns_but_the_settings_are_kept(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'version' => '0.0.1'])]);
        $this->setup_(['--role' => 'branch', '--node' => 4, '--hub-url' => 'https://hq.example.com', '--token' => 'abc'])
            ->expectsOutputToContain('حدّث الأقدم منهما')->assertSuccessful();
        $this->assertStringContainsString('SYNC_TOKEN=abc', file_get_contents($this->env));

        Http::fake(['*' => Http::response('no', 401)]);
        $this->setup_(['--role' => 'branch', '--node' => 4, '--hub-url' => 'https://hq.example.com', '--token' => 'abc'])->assertSuccessful();
    }

    public function test_a_stand_alone_server_turns_sync_off(): void
    {
        file_put_contents($this->env, "SYNC_ROLE=hub\n");
        $this->setup_(['--role' => 'standalone'])->assertSuccessful();
        $this->assertStringContainsString("SYNC_ROLE=off\n", file_get_contents($this->env));
    }

    public function test_both_installers_ask_for_the_synchronisation_settings_and_apply_the_database_id_stepping(): void
    {
        $linux = file_get_contents(base_path('deploy/linux/install.sh'));
        $windows = file_get_contents(base_path('deploy/windows/install.ps1'));
        foreach ([$linux, $windows] as $script) {
            $this->assertStringContainsString('sync:setup', $script);
            $this->assertStringContainsString('sync:join', $script);
            $this->assertStringContainsString('auto_increment_offset', $script);
            $this->assertStringContainsString('DIWAN_SYNC_ROLE', $script);
        }
        $this->assertStringContainsString('Configure-Sync $phpExe $release', $windows);
        $this->assertStringContainsString('configure_sync', $linux);
    }
}
