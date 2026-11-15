<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\EquipmentPreparation;
use App\Services\Saas\AlertService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** «تحضير المعدات»: the day's sheet, one preparation per equipment per day, cards attached as files. */
class EquipmentPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->company = Company::factory()->create();
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function equipment(string $code = 'EX-1', string $status = 'available'): Equipment
    {
        return Equipment::create(['code' => $code, 'name' => 'حفار '.$code, 'status' => $status, 'ownership' => 'owned', 'meter_type' => 'hours']);
    }

    public function test_the_sheet_shows_who_is_prepared_and_who_is_missing(): void
    {
        $a = $this->equipment('A');
        $this->equipment('B');
        $this->equipment('C', 'out_of_service');
        $today = now()->toDateString();

        $this->post(route('equipment.preparations.store'), ['equipment_id' => $a->id, 'prep_date' => $today, 'operator' => 'سالم', 'status' => 'ready'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->get(route('equipment.preparations.index'))->assertOk()->assertInertia(fn ($p) => $p->where('counts.total', 2)->where('counts.ready', 1)->where('counts.missing', 1)
            ->where('rows.0.preparation.operator', 'سالم'));
        $this->assertMatchesRegularExpression('/^PRP-/', EquipmentPreparation::first()->number);
    }

    public function test_one_preparation_per_equipment_per_day_and_no_future_dates(): void
    {
        $a = $this->equipment();
        $today = now()->toDateString();
        $payload = ['equipment_id' => $a->id, 'prep_date' => $today, 'status' => 'ready'];

        $this->post(route('equipment.preparations.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('equipment.preparations.store'), $payload)->assertSessionHasErrors('equipment_id');
        $this->post(route('equipment.preparations.store'), ['prep_date' => now()->addDay()->toDateString()] + $payload)->assertSessionHasErrors('prep_date');
        $this->assertSame(1, EquipmentPreparation::count());
    }

    public function test_cards_attach_at_creation_and_later_and_go_with_the_preparation(): void
    {
        $a = $this->equipment();
        $ids = collect([1, 2])->map(fn ($n) => $this->post(route('attachments.stage', ['kind' => 'equipment-preparation']), ['file' => UploadedFile::fake()->create("card-$n.pdf", 20, 'application/pdf')])->assertCreated()->json('data.id'))->all();

        $this->post(route('equipment.preparations.store'), ['equipment_id' => $a->id, 'prep_date' => now()->toDateString(), 'status' => 'needs_attention', 'attachment_ids' => $ids])->assertSessionHasNoErrors();
        $prep = EquipmentPreparation::first();
        $this->assertSame(2, $prep->attachments()->count());

        // A third card on the saved preparation, then deleting the preparation removes every file.
        $this->post(route('attachments.store', ['kind' => 'equipment-preparation', 'id' => $prep->id]), ['file' => UploadedFile::fake()->create('card-3.pdf', 20, 'application/pdf')])->assertCreated();
        $this->assertSame(3, $prep->attachments()->count());
        $paths = $prep->attachments()->pluck('path');
        $this->delete(route('equipment.preparations.destroy', $prep->id))->assertRedirect();
        $this->assertSame(0, Attachment::count());
        $paths->each(fn ($p) => Storage::disk('local')->assertMissing($p));
    }

    public function test_it_is_company_scoped_and_permissioned(): void
    {
        $a = $this->equipment();
        $this->post(route('equipment.preparations.store'), ['equipment_id' => $a->id, 'prep_date' => now()->toDateString(), 'status' => 'ready']);
        $prep = EquipmentPreparation::first();

        $other = Company::factory()->create();
        $foreign = app(TenantManager::class)->runAs($other, fn () => Equipment::create(['code' => 'ZZ', 'name' => 'x', 'status' => 'available', 'ownership' => 'owned', 'meter_type' => 'hours']));
        $this->post(route('equipment.preparations.store'), ['equipment_id' => $foreign->id, 'prep_date' => now()->toDateString(), 'status' => 'ready'])->assertSessionHasErrors('equipment_id');

        $this->actingAs($this->userWithRole($this->company, 'sales'));
        $this->get(route('equipment.preparations.index'))->assertForbidden();
        $this->get(route('equipment.preparations.edit', $prep->id))->assertForbidden();
    }

    public function test_the_morning_alert_names_working_equipment_not_prepared_yesterday(): void
    {
        $a = $this->equipment('A', 'internal');
        $b = $this->equipment('B', 'rented');
        $this->equipment('C', 'available');
        $kinds = fn () => collect(app(AlertService::class)->collect())->firstWhere('kind', 'equipment_not_prepared');

        // A company that never prepares equipment is not nagged.
        $this->assertNull($kinds());

        $yesterday = now()->subDay()->toDateString();
        EquipmentPreparation::create(['company_id' => $this->company->id, 'number' => 'PRP-T1', 'equipment_id' => $a->id, 'prep_date' => $yesterday, 'status' => 'ready']);
        $alert = $kinds();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('1 معدة', $alert['body']); // only B (rented): A was prepared, C is not working

        EquipmentPreparation::create(['company_id' => $this->company->id, 'number' => 'PRP-T2', 'equipment_id' => $b->id, 'prep_date' => $yesterday, 'status' => 'ready']);
        $this->assertNull($kinds());
    }
}
