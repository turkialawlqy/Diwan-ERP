<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['backup.disk' => 'backups', 'backup.password' => 'test-secret']);
    }

    public function test_backup_is_encrypted_and_restores(): void
    {
        Company::factory()->count(3)->create();

        $run = app(BackupService::class)->create();

        $this->assertSame('ok', $run->status, (string) $run->error);
        $this->assertTrue($run->details['encrypted']);
        Storage::disk('backups')->assertExists($run->file);

        // Entries are AES-encrypted: unreadable without the password.
        $path = Storage::disk('backups')->path($run->file);
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertSame(ZipArchive::EM_AES_256, $zip->statName('manifest.json')['encryption_method']);
        $this->assertFalse($zip->getFromName('manifest.json'));
        $zip->setPassword('test-secret');
        $this->assertGreaterThanOrEqual(3, json_decode($zip->getFromName('manifest.json'), true)['tables']['companies']);
        $zip->close();

        $verify = app(BackupService::class)->verify();
        $this->assertSame('ok', $verify->status, (string) $verify->error);
        $this->assertSame(\DB::getDriverName() === 'sqlite' ? 'sqlite-open' : 'dump-structure', $verify->details['method']);
    }

    public function test_verify_fails_loudly_with_the_wrong_password(): void
    {
        app(BackupService::class)->create();
        config(['backup.password' => 'other']);

        $run = app(BackupService::class)->verify();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('كلمة مرور', $run->error);
    }

    public function test_retention_keeps_daily_weekly_and_monthly(): void
    {
        config(['backup.keep' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6]]);
        $start = Carbon::parse('2026-01-01');
        for ($d = 0; $d < 280; $d++) {
            Storage::disk('backups')->put(BackupService::PREFIX.$start->copy()->addDays($d)->format('Y-m-d').'-024000.zip', 'x');
        }

        app(BackupService::class)->prune();

        $kept = collect(Storage::disk('backups')->files())->map(fn ($f) => substr($f, strlen(BackupService::PREFIX), 10))->sort()->values();
        $this->assertSame('2026-10-07', $kept->last());
        $this->assertContains('2026-10-01', $kept->all());   // monthly
        $this->assertContains('2026-05-01', $kept->all());   // 6th monthly
        $this->assertNotContains('2026-04-01', $kept->all());
        $this->assertLessThanOrEqual(7 + 4 + 6, $kept->count());
    }

    public function test_command_and_admin_page(): void
    {
        $this->artisan('erp:backup')->assertSuccessful();
        $this->artisan('erp:backup --verify')->assertSuccessful();
        $this->assertSame(2, BackupRun::where('status', 'ok')->count());

        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin)->get(route('super-admin.backups'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('SuperAdmin/Backups')->has('runs', 2)->where('config.encrypted', true));
    }
}
