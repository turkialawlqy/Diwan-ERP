<?php

namespace Tests\Feature;

use App\Models\BookableService;
use App\Models\BookingResource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PatientFile;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A patient's visit can only be linked to that patient's own booking. */
class ClinicVisitBookingLinkTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected PatientFile $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $customer = Customer::create(['name' => 'سارة', 'customer_type' => 'individual', 'status' => 'active']);
        $this->post(route('clinic.patients.store'), ['customer_id' => $customer->id])->assertSessionHasNoErrors();
        $this->patient = PatientFile::where('customer_id', $customer->id)->firstOrFail();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function bookingOf(Customer $customer): int
    {
        $service = BookableService::create(['name' => 'كشف', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'price' => 100, 'tax_rate' => 15, 'online_bookable' => false, 'free_cancel_hours' => 0, 'is_active' => true]);
        $resource = BookingResource::create(['name' => 'عيادة', 'type' => 'room', 'capacity' => 1, 'is_active' => true]);

        return DB::table('bookings')->insertGetId(['company_id' => $this->company->id, 'number' => 'B-'.uniqid(), 'customer_id' => $customer->id, 'bookable_service_id' => $service->id, 'booking_resource_id' => $resource->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30), 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_visit_cannot_be_linked_to_another_customers_booking(): void
    {
        $someoneElse = Customer::create(['name' => 'آخر', 'customer_type' => 'individual', 'status' => 'active']);

        $this->post(route('clinic.visits.store', $this->patient->id), ['visit_date' => today()->toDateString(), 'booking_id' => $this->bookingOf($someoneElse)])->assertSessionHasErrors('booking_id');

        $this->assertSame(0, $this->patient->visits()->count());
    }

    public function test_a_visit_links_to_the_patients_own_booking(): void
    {
        $booking = $this->bookingOf($this->patient->customer);

        $this->post(route('clinic.visits.store', $this->patient->id), ['visit_date' => today()->toDateString(), 'booking_id' => $booking])->assertSessionHasNoErrors();

        $this->assertSame($booking, (int) $this->patient->visits()->firstOrFail()->booking_id);
    }

    public function test_editing_a_visit_applies_the_same_rule(): void
    {
        $this->post(route('clinic.visits.store', $this->patient->id), ['visit_date' => today()->toDateString()])->assertSessionHasNoErrors();
        $visit = $this->patient->visits()->firstOrFail();
        $someoneElse = Customer::create(['name' => 'آخر', 'customer_type' => 'individual', 'status' => 'active']);

        $this->post(route('clinic.visits.update', $visit->id), ['visit_date' => today()->toDateString(), 'booking_id' => $this->bookingOf($someoneElse)])->assertSessionHasErrors('booking_id');
    }

    public function test_a_visit_needs_no_booking(): void
    {
        $this->post(route('clinic.visits.store', $this->patient->id), ['visit_date' => today()->toDateString()])->assertSessionHasNoErrors();

        $this->assertNull($this->patient->visits()->firstOrFail()->booking_id);
    }
}
