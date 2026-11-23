<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Equipment;
use App\Models\EquipmentPreparation;
use App\Models\EquipmentReading;
use App\Models\MaintenanceOrder;
use App\Services\Equipment\EquipmentReports;
use App\Services\Equipment\ReadingService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Equipment check-in/out times and the reports built on them: daily attendance, maintenance detail, preparation, operators. */
class EquipmentAttendanceReportsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Equipment $a;

    protected Equipment $b;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 09:00:00');
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->a = Equipment::create(['code' => 'A', 'name' => 'حفار', 'meter_type' => 'hours', 'current_hours' => 0, 'status' => 'available']);
        $this->b = Equipment::create(['code' => 'B', 'name' => 'شيول', 'meter_type' => 'hours', 'current_hours' => 0, 'status' => 'available']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_check_times_are_saved_and_give_the_worked_hours_when_nothing_else_does(): void
    {
        $service = app(ReadingService::class);
        $reading = $service->record($this->a, ['date' => '2026-06-08', 'check_in' => '07:30', 'check_out' => '15:00']);
        $this->assertSame('07:30', substr($reading->check_in, 0, 5));
        $this->assertSame(7.5, (float) $reading->worked_hours);

        $explicit = $service->record($this->a, ['date' => '2026-06-09', 'check_in' => '07:00', 'check_out' => '17:00', 'worked_hours' => 8]);
        $this->assertSame(8.0, (float) $explicit->worked_hours);

        $this->assertThrows(fn () => $service->record($this->a, ['date' => '2026-06-07', 'check_in' => '15:00', 'check_out' => '07:00']), ValidationException::class);
    }

    public function test_the_daily_sheet_saves_times_through_the_page(): void
    {
        $this->post(route('equipment.readings.store'), ['date' => '2026-06-08', 'rows' => [['equipment_id' => $this->a->id, 'check_in' => '08:00', 'check_out' => '16:00']]])->assertSessionHasNoErrors();
        $this->assertSame(8.0, (float) EquipmentReading::first()->worked_hours);
        $this->post(route('equipment.readings.store'), ['date' => '2026-06-08', 'rows' => [['equipment_id' => $this->a->id, 'check_in' => '9am']]])->assertSessionHasErrors('rows.0.check_in');
    }

    public function test_attendance_marks_each_equipment_day(): void
    {
        $service = app(ReadingService::class);
        $service->record($this->a, ['date' => '2026-06-08', 'check_in' => '08:00', 'check_out' => '16:00', 'operator' => 'سالم']);
        EquipmentPreparation::create(['equipment_id' => $this->b->id, 'prep_date' => '2026-06-08', 'status' => 'ready', 'number' => 'PRP-1', 'operator' => 'ناصر']);
        MaintenanceOrder::create(['equipment_id' => $this->b->id, 'number' => 'MO-1', 'type' => 'corrective', 'date' => '2026-06-09', 'status' => 'open']);

        $report = app(EquipmentReports::class)->attendance(Carbon::parse('2026-06-08'), Carbon::parse('2026-06-09'));
        $cell = fn (string $code, string $date) => collect($report['rows'])->first(fn ($r) => $r['code'] === $code && $r['date'] === $date);

        $this->assertCount(4, $report['rows']);
        $this->assertSame('worked', $cell('A', '2026-06-08')['state']);
        $this->assertSame('08:00', $cell('A', '2026-06-08')['check_in']);
        $this->assertSame('16:00', $cell('A', '2026-06-08')['check_out']);
        $this->assertSame('idle', $cell('A', '2026-06-09')['state']);
        $this->assertSame('prepared', $cell('B', '2026-06-08')['state']);
        $this->assertSame('maintenance', $cell('B', '2026-06-09')['state']);
        $this->assertSame(8.0, (float) $report['totals']['worked_hours']);
        $this->assertSame(1, collect($report['by_equipment'])->firstWhere('code', 'A')['worked']);

        $this->assertThrows(fn () => app(EquipmentReports::class)->attendance(Carbon::parse('2026-01-01'), Carbon::parse('2026-06-09')), ValidationException::class);
    }

    public function test_maintenance_detail_counts_downtime_and_breakdowns(): void
    {
        MaintenanceOrder::create(['equipment_id' => $this->a->id, 'number' => 'MO-1', 'type' => 'corrective', 'date' => '2026-06-01', 'completed_at' => '2026-06-03 10:00:00', 'status' => 'completed', 'technician' => 'خالد']);
        MaintenanceOrder::create(['equipment_id' => $this->a->id, 'number' => 'MO-2', 'type' => 'preventive', 'date' => '2026-06-08', 'status' => 'open']);

        $report = app(EquipmentReports::class)->maintenanceLog(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-10'));
        $this->assertCount(2, $report['rows']);
        $closed = collect($report['rows'])->firstWhere('number', 'MO-1');
        $this->assertSame(3, $closed['downtime_days']);
        $this->assertSame('خالد', $closed['technician']);
        $row = $report['by_equipment'][0];
        $this->assertSame(2, $row['orders']);
        $this->assertSame(1, $row['corrective']);
        $this->assertSame(1, $row['open']);
        $this->assertSame(3.0, (float) $row['avg_repair_days']);
    }

    public function test_preparation_and_operator_reports(): void
    {
        $service = app(ReadingService::class);
        $service->record($this->a, ['date' => '2026-06-08', 'worked_hours' => 6, 'operator' => 'سالم']);
        $service->record($this->a, ['date' => '2026-06-09', 'worked_hours' => 5, 'operator' => 'سالم']);
        EquipmentPreparation::create(['equipment_id' => $this->a->id, 'prep_date' => '2026-06-08', 'status' => 'needs_attention', 'number' => 'PRP-1']);

        $reports = app(EquipmentReports::class);
        $prep = $reports->preparation(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-10'));
        $row = collect($prep['rows'])->firstWhere('code', 'A');
        $this->assertSame(1, $row['prepared_days']);
        $this->assertSame(1, $row['needs_attention']);
        $this->assertSame(2, $row['work_days']);
        $this->assertSame(1, $row['worked_unprepared']);
        $this->assertSame(50.0, (float) $row['rate']);

        $ops = $reports->operators(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-10'));
        $this->assertSame('سالم', $ops['rows'][0]['operator']);
        $this->assertSame(2, $ops['rows'][0]['days']);
        $this->assertSame(11.0, (float) $ops['rows'][0]['hours']);
    }

    public function test_the_pages_open_and_export_for_every_new_report_and_need_the_permission(): void
    {
        foreach (['attendance', 'maintenance_log', 'preparation', 'operators'] as $type) {
            $this->get(route('equipment.reports.index', ['type' => $type, 'from' => '2026-06-01', 'to' => '2026-06-10']))->assertOk()->assertInertia(fn ($p) => $p->where('type', $type));
            $this->get(route('equipment.reports.export', ['type' => $type, 'from' => '2026-06-01', 'to' => '2026-06-10']))->assertOk();
        }

        $this->actingAs($this->userWithRole($this->company, 'sales'));
        $this->get(route('equipment.reports.index', ['type' => 'attendance']))->assertForbidden();
        $this->get(route('equipment.reports.export', ['type' => 'attendance']))->assertForbidden();
    }
}
