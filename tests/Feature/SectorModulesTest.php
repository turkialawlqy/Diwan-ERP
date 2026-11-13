<?php

namespace Tests\Feature;

use App\Http\Controllers\FileController;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerSubscription;
use App\Models\CustomerVehicle;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MemberCheckin;
use App\Models\PatientFile;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\ServicePlan;
use App\Models\User;
use App\Models\VisitPackage;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Notifications\BookingMessage;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Bookings\PackageService;
use App\Services\Sales\RecurringInvoiceRunner;
use App\Services\Sectors\GymCheckinService;
use App\Services\Sectors\LeaseService;
use App\Services\Sectors\WorkshopService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\TenantManager;
use App\Services\WorkOrders\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The light sector modules: clinic files, gym check-in, workshop vehicles, real-estate leases. */
class SectorModulesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'سارة', 'customer_type' => 'individual', 'status' => 'active', 'code' => 'C-00001', 'phone' => '0555000111', 'email' => 'sara@example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ clinic

    public function test_a_patient_file_is_numbered_unique_per_customer_and_visits_keep_private_files(): void
    {
        $this->post(route('clinic.patients.store'), ['customer_id' => $this->customer->id, 'gender' => 'female', 'blood_type' => 'O+', 'allergies' => 'البنسلين'])->assertSessionHasNoErrors();
        $patient = PatientFile::firstOrFail();
        $this->assertSame('PF-00001', $patient->file_number);
        $this->post(route('clinic.patients.store'), ['customer_id' => $this->customer->id])->assertSessionHasErrors('customer_id');

        $this->post(route('clinic.visits.store', $patient->id), ['visit_date' => '2026-06-15', 'complaint' => 'صداع', 'diagnosis' => 'إجهاد', 'next_visit_date' => '2026-06-29', 'files' => [UploadedFile::fake()->image('xray.jpg')]])->assertSessionHasNoErrors();
        $visit = $patient->visits()->firstOrFail();
        $this->assertSame('2026-06-29', $visit->next_visit_date->toDateString());
        Storage::disk('local')->assertExists($visit->attachments[0]['path']);
        $this->post(route('clinic.visits.store', $patient->id), ['visit_date' => '2026-06-15', 'files' => [UploadedFile::fake()->create('x.exe', 5)]])->assertSessionHasErrors('files.0');

        $this->get(route('clinic.patients.show', $patient->id))->assertOk()->assertInertia(fn ($p) => $p->component('Clinic/Patients/Show')->has('visits', 1)->where('visits.0.files.0.name', 'xray.jpg'));
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => PatientFile::class, 'auditable_id' => $patient->id]);
    }

    /** A role with nothing in the clinic, plus exactly these permissions. */
    protected function clinicStaff(array $permissions): User
    {
        $user = $this->userWithRole($this->company, 'warehouse_keeper');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    public function test_the_patient_page_carries_visits_only_with_the_visits_permission(): void
    {
        $patient = PatientFile::create(['customer_id' => $this->customer->id, 'file_number' => 'PF-1']);
        $patient->visits()->create(['visit_date' => '2026-06-15', 'diagnosis' => 'تشخيص سري', 'prescription' => 'وصفة سرية', 'attachments' => [['name' => 'xray.jpg', 'path' => "companies/{$this->company->id}/clinic/xray.jpg"]]]);
        $page = route('clinic.patients.show', $patient->id);

        // The patient file alone: the page opens, but nothing of the medical record reaches the browser by any prop.
        $this->actingAs($this->clinicStaff(['clinic.patients.view']));
        $hidden = $this->get($page)->assertOk()->assertInertia(fn ($p) => $p->component('Clinic/Patients/Show')->where('patient.file_number', 'PF-1')->missing('visits')->missing('patient.visits'));
        $sent = json_encode($hidden->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
        foreach (['تشخيص سري', 'وصفة سرية', 'xray.jpg'] as $medical) {
            $this->assertStringNotContainsString($medical, $sent);
        }

        $this->actingAs($this->clinicStaff(['clinic.patients.view', 'clinic.visits.view']));
        $this->get($page)->assertOk()->assertInertia(fn ($p) => $p->has('visits', 1)->where('visits.0.diagnosis', 'تشخيص سري')->where('visits.0.prescription', 'وصفة سرية')->where('visits.0.files.0.name', 'xray.jpg')->missing('patient.visits'));

        // Recording a visit does not open the record either.
        $this->actingAs($this->clinicStaff(['clinic.patients.view', 'clinic.visits.create']));
        $this->get($page)->assertOk()->assertInertia(fn ($p) => $p->missing('visits')->missing('patient.visits'));
    }

    public function test_each_visit_route_needs_its_matching_action(): void
    {
        $patient = PatientFile::create(['customer_id' => $this->customer->id, 'file_number' => 'PF-1']);
        $visit = $patient->visits()->create(['visit_date' => '2026-06-15']);
        $data = ['visit_date' => '2026-06-16'];

        $this->actingAs($this->clinicStaff(['clinic.patients.view', 'clinic.visits.view']));
        $this->post(route('clinic.visits.store', $patient->id), $data)->assertForbidden();
        $this->post(route('clinic.visits.update', $visit->id), $data)->assertForbidden();

        $this->actingAs($this->clinicStaff(['clinic.visits.create']));
        $this->post(route('clinic.visits.update', $visit->id), $data)->assertForbidden();
        $this->post(route('clinic.visits.store', $patient->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $patient->visits()->count());

        $this->actingAs($this->clinicStaff(['clinic.visits.edit']));
        $this->post(route('clinic.visits.store', $patient->id), $data)->assertForbidden();
        $this->assertSame('2026-06-15', $visit->fresh()->visit_date->toDateString());
        $this->post(route('clinic.visits.update', $visit->id), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-06-16', $visit->fresh()->visit_date->toDateString());
        $this->assertSame(2, $patient->visits()->count());
    }

    public function test_medical_data_needs_its_own_permission(): void
    {
        $patient = PatientFile::create(['customer_id' => $this->customer->id, 'file_number' => 'PF-1']);
        $this->actingAs($this->userWithRole($this->company, 'warehouse_keeper'));
        $this->get(route('clinic.patients.index'))->assertForbidden();
        $this->get(route('clinic.patients.show', $patient->id))->assertForbidden();
        $this->post(route('clinic.visits.store', $patient->id), ['visit_date' => '2026-06-15'])->assertForbidden();
        $this->actingAs($this->userWithRole($this->company, 'accountant'));
        $this->get(route('clinic.patients.index'))->assertForbidden(); // not granted by the migration either
    }

    public function test_a_clinic_file_needs_the_medical_visits_permission(): void
    {
        $patient = PatientFile::create(['customer_id' => $this->customer->id, 'file_number' => 'PF-1']);
        $this->post(route('clinic.visits.store', $patient->id), ['visit_date' => '2026-06-15', 'files' => [UploadedFile::fake()->image('xray.jpg')]])->assertSessionHasNoErrors();
        $url = $this->get(route('clinic.patients.show', $patient->id))->viewData('page')['props']['visits'][0]['files'][0]['url'];
        $this->get($url)->assertOk(); // the owner
        $missing = route('files.show', ['token' => Crypt::encryptString("companies/{$this->company->id}/clinic/missing.jpg")], false);
        $other = FileController::storeForTenant(UploadedFile::fake()->create('contract.pdf', 5), 'attachments')['url'];

        $clerk = $this->userWithRole($this->company, 'accountant');
        $this->actingAs($clerk);
        $this->get($url)->assertForbidden();
        $this->get($missing)->assertForbidden(); // whether the file exists stays hidden too
        $this->get($other)->assertOk(); // other private files still follow the company alone

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $clerk->givePermissionTo('clinic.patients.view'); // the patient file alone is not the medical record
        $this->actingAs($clerk->fresh());
        $this->get($url)->assertForbidden();

        $clerk->givePermissionTo('clinic.visits.view');
        $this->actingAs($clerk->fresh());
        $this->get($url)->assertOk();
        $this->get($missing)->assertNotFound();
    }

    // --------------------------------------------------------------------- gym

    public function test_the_gym_checkin_allows_live_members_and_explains_every_denial(): void
    {
        $plan = ServicePlan::create(['code' => 'PLN-1', 'name' => 'عضوية', 'price' => 100, 'tax_rate' => 0, 'cycle' => 'monthly', 'trial_days' => 0, 'is_active' => true, 'is_membership' => true]);
        // Another kind of subscription (not a membership) never lets anyone in.
        $other = ServicePlan::create(['code' => 'PLN-2', 'name' => 'صيانة شهرية', 'price' => 50, 'tax_rate' => 0, 'cycle' => 'monthly', 'trial_days' => 0, 'is_active' => true]);
        $stranger = Customer::create(['name' => 'غير عضو', 'customer_type' => 'individual', 'status' => 'active', 'code' => 'C-00099']);
        app(SubscriptionService::class)->start(['customer_id' => $stranger->id, 'service_plan_id' => $other->id, 'quantity' => 1, 'start_date' => '2026-06-15']);
        $this->assertFalse(app(GymCheckinService::class)->scan('C-00099')['allowed']);
        MemberCheckin::query()->delete();

        $sub = app(SubscriptionService::class)->start(['customer_id' => $this->customer->id, 'service_plan_id' => $plan->id, 'quantity' => 1, 'start_date' => '2026-06-15']);
        $gym = app(GymCheckinService::class);

        $ok = $gym->scan('c-00001'); // case-insensitive
        $this->assertTrue($ok['allowed']);
        $this->assertSame('عضوية', $ok['subscription']['plan']);
        $this->assertTrue($gym->scan('0555000111')['allowed']); // by phone too

        app(SubscriptionService::class)->pause($sub->fresh());
        $paused = $gym->scan('C-00001');
        $this->assertFalse($paused['allowed']);
        $this->assertStringContainsString('موقوفة', $paused['reason']);

        $sub->fresh()->update(['status' => 'past_due']);
        $this->assertStringContainsString('متعثرة', $gym->scan('C-00001')['reason']);
        $sub->fresh()->update(['status' => 'expired']);
        $this->assertStringContainsString('منتهية', $gym->scan('C-00001')['reason']);

        $unknown = $gym->scan('NOPE');
        $this->assertFalse($unknown['allowed']);
        $this->assertSame('الكود غير معروف', $unknown['reason']);

        $day = $gym->day('2026-06-15');
        $this->assertSame(2, $day['allowed']);
        $this->assertSame(4, $day['denied']);
        $this->assertSame(1, $day['unique']);
        $this->assertSame(6, MemberCheckin::count());
    }

    public function test_a_visit_package_lets_a_non_member_in_and_deducts_a_visit(): void
    {
        $template = VisitPackage::create(['name' => '12 حصة', 'visits' => 2, 'price' => 0, 'tax_rate' => 0, 'is_active' => true]);
        $pack = app(PackageService::class)->sell($template, $this->customer->id);
        $gym = app(GymCheckinService::class);

        $this->assertTrue($gym->scan('C-00001')['allowed']);
        $this->assertSame(1, $pack->fresh()->remaining());
        $this->assertTrue($gym->scan('C-00001')['allowed']); // a second scan the same day: in, no visit used
        $this->assertSame(1, $pack->fresh()->remaining());

        Carbon::setTestNow('2026-06-16 09:00:00');
        $this->assertTrue($gym->scan('C-00001')['allowed']);
        $this->assertSame('used_up', $pack->fresh()->status);
        Carbon::setTestNow('2026-06-17 09:00:00');
        $this->assertFalse($gym->scan('C-00001')['allowed']); // nothing left
        $this->assertSame(2, $pack->usages()->count());
    }

    public function test_a_scan_that_loses_the_race_for_the_package_uses_no_second_visit(): void
    {
        $template = VisitPackage::create(['name' => '12 حصة', 'visits' => 10, 'price' => 0, 'tax_rate' => 0, 'is_active' => true]);
        $pack = app(PackageService::class)->sell($template, $this->customer->id);

        // Another reception screen finishes the same card's check-in after this scan has looked and before it locks the package.
        $finished = false;
        CustomerPackage::retrieved(function (CustomerPackage $p) use (&$finished) {
            if (! $finished) {
                $finished = true;
                MemberCheckin::create(['customer_id' => $p->customer_id, 'customer_package_id' => $p->id, 'code_entered' => 'C-00001', 'method' => 'scan', 'allowed' => true, 'checked_in_at' => now()]);
                CustomerPackage::whereKey($p->id)->increment('visits_used');
            }
        });

        $this->assertTrue(app(GymCheckinService::class)->scan('C-00001')['allowed']);

        $this->assertTrue($finished);
        $this->assertSame(1, (int) $pack->fresh()->visits_used);
        $this->assertDatabaseHas('member_checkins', ['customer_id' => $this->customer->id, 'reason' => 'دخول ثانٍ اليوم (لا يُخصم)']);
    }

    public function test_the_checkin_endpoint_answers_json_and_respects_the_permission(): void
    {
        $this->postJson(route('gym.checkin.scan'), ['code' => 'C-00001'])->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('customer.name', 'سارة')->assertJsonStructure(['today' => ['allowed', 'denied', 'rows']]);
        $this->get(route('gym.checkin'))->assertOk();
        $this->get(route('gym.attendance'))->assertOk();
        $this->actingAs($this->userWithRole($this->company, 'warehouse_keeper'));
        $this->postJson(route('gym.checkin.scan'), ['code' => 'C-00001'])->assertForbidden();
    }

    // ---------------------------------------------------------------- workshop

    public function test_a_vehicle_belongs_to_its_customer_and_completion_updates_the_card(): void
    {
        $other = Customer::create(['name' => 'غيري', 'customer_type' => 'individual', 'status' => 'active']);
        $this->post(route('workshop.vehicles.store'), ['customer_id' => $this->customer->id, 'plate' => 'أ ب ج 1234', 'make' => 'تويوتا', 'model' => 'كامري', 'year' => 2020, 'odometer' => 50000])->assertSessionHasNoErrors();
        $vehicle = CustomerVehicle::firstOrFail();
        $type = WorkType::create(['name' => 'تغيير زيت', 'hourly_rate' => 100, 'tax_rate' => 0, 'is_active' => true, 'service_interval_days' => 120, 'service_interval_km' => 5000]);
        $svc = app(WorkOrderService::class);

        try {
            $svc->create(['customer_id' => $other->id, 'title' => 'x', 'vehicle_id' => $vehicle->id]);
            $this->fail("another customer's vehicle must be refused");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vehicle_id', $e->errors());
        }

        $order = $svc->create(['customer_id' => $this->customer->id, 'title' => 'تغيير زيت', 'work_type_id' => $type->id, 'vehicle_id' => $vehicle->id, 'odometer_in' => 52000]);
        $svc->complete($order);

        $vehicle->refresh();
        $this->assertSame(52000, $vehicle->odometer);
        $this->assertSame('2026-10-13', $vehicle->next_service_date->toDateString()); // today + 120 days
        $this->assertSame(57000, $vehicle->next_service_km);

        $this->get(route('workshop.vehicles.show', $vehicle->id))->assertOk()->assertInertia(fn ($p) => $p->has('orders', 1));
        $this->delete(route('workshop.vehicles.destroy', $vehicle->id))->assertSessionHasErrors('vehicle'); // it has an order
        $this->postJson(route('workshop.vehicles.store'), ['customer_id' => $this->customer->id, 'plate' => 'QUICK 1'])->assertCreated()->assertJsonPath('record.label', 'QUICK 1 —');
    }

    public function test_the_service_reminder_goes_out_once_per_due_date(): void
    {
        Notification::fake();
        $v = CustomerVehicle::create(['customer_id' => $this->customer->id, 'plate' => 'P1', 'next_service_date' => '2026-06-20', 'odometer' => 1000]);
        CustomerVehicle::create(['customer_id' => $this->customer->id, 'plate' => 'P2', 'next_service_date' => '2026-09-01', 'odometer' => 1000]); // far away

        $this->assertSame(1, app(WorkshopService::class)->remind());
        $this->assertSame(0, app(WorkshopService::class)->remind());
        Notification::assertSentOnDemandTimes(BookingMessage::class, 1);

        $v->update(['next_service_date' => '2026-06-22']); // moved: a new due date is a new reminder
        $this->assertSame(1, app(WorkshopService::class)->remind());
    }

    // -------------------------------------------------------------- real estate

    protected function unit(string $number = '101', float $rent = 3000): PropertyUnit
    {
        $property = Property::firstOrCreate(['name' => 'عمارة النخيل'], ['type' => 'building']);

        return PropertyUnit::create(['property_id' => $property->id, 'unit_number' => $number, 'type' => 'apartment', 'rent_amount' => $rent, 'status' => 'vacant']);
    }

    protected function lease(PropertyUnit $unit, array $extra = []): Lease
    {
        return app(LeaseService::class)->create(array_merge(['customer_id' => $this->customer->id, 'property_unit_id' => $unit->id, 'start_date' => '2026-06-15', 'end_date' => '2027-06-14',
            'rent_amount' => 3000, 'cycle' => 'monthly', 'security_deposit' => 3000], $extra));
    }

    public function test_a_lease_creates_a_contract_a_rent_subscription_and_the_first_invoice(): void
    {
        $unit = $this->unit();
        $lease = $this->lease($unit);

        $this->assertSame('LSE-26-00001', $lease->number);
        $this->assertSame('occupied', $unit->fresh()->status);
        $contract = Contract::findOrFail($lease->contract_id);
        $this->assertSame('lease', $contract->kind);
        $this->assertEquals(36000.0, (float) $contract->value); // 12 months × 3000
        $this->assertSame($lease->subscription_id, $contract->subscription_id);

        $sub = CustomerSubscription::findOrFail($lease->subscription_id);
        $this->assertSame('active', $sub->status);
        $this->assertEquals(3000.0, (float) $sub->unit_price);
        $this->assertSame('إيجار شهري', $sub->plan->name);
        $invoice = Invoice::firstOrFail(); // the first month is billed at once
        $this->assertEquals(3000.0, (float) $invoice->subtotal);
        $this->assertStringContainsString('101', $invoice->items()->first()->description);
        $this->assertCount(1, app(LeaseService::class)->invoices($lease));
    }

    public function test_an_occupied_unit_cannot_be_rented_twice_and_ending_frees_everything(): void
    {
        $unit = $this->unit();
        $lease = $this->lease($unit);
        try {
            $this->lease($unit);
            $this->fail('an occupied unit must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('property_unit_id', $e->errors());
        }

        app(LeaseService::class)->end($lease, 'انتقل المستأجر');
        $this->assertSame('ended', $lease->fresh()->status);
        $this->assertSame('vacant', $unit->fresh()->status);
        $this->assertSame('cancelled', CustomerSubscription::find($lease->subscription_id)->status);
        $this->assertSame('terminated', Contract::find($lease->contract_id)->status);

        $again = $this->lease($unit->fresh()); // rentable again
        $this->assertNotSame($lease->id, $again->id);
    }

    public function test_lease_dates_and_rent_are_validated(): void
    {
        $unit = $this->unit();
        $this->assertInvalid(fn () => $this->lease($unit, ['end_date' => '2026-06-01']), 'end_date');
        $this->assertInvalid(fn () => $this->lease($unit, ['rent_amount' => 0]), 'rent_amount');
        $this->assertSame('vacant', $unit->fresh()->status);
        $this->assertSame(0, Lease::count());
    }

    public function test_the_real_estate_screens_and_the_report(): void
    {
        $this->post(route('real-estate.properties.store'), ['name' => 'برج الندى', 'type' => 'building'])->assertSessionHasNoErrors();
        $property = Property::where('name', 'برج الندى')->firstOrFail();
        $this->post(route('real-estate.units.store'), ['property_id' => $property->id, 'unit_number' => '5', 'type' => 'shop', 'rent_amount' => 8000])->assertSessionHasNoErrors();
        $this->post(route('real-estate.units.store'), ['property_id' => $property->id, 'unit_number' => '5', 'type' => 'shop'])->assertSessionHasErrors('unit_number'); // unique in the property
        $unit = PropertyUnit::where('unit_number', '5')->firstOrFail();

        $this->post(route('real-estate.leases.store'), ['customer_id' => $this->customer->id, 'property_unit_id' => $unit->id, 'start_date' => '2026-06-15', 'end_date' => '2026-08-01',
            'rent_amount' => 8000, 'cycle' => 'monthly'])->assertSessionHasNoErrors();
        $lease = Lease::firstOrFail();
        $this->get(route('real-estate.properties.show', $property->id))->assertOk()->assertInertia(fn ($p) => $p->where('units.0.tenant', 'سارة'));
        $this->get(route('real-estate.leases.show', $lease->id))->assertOk()->assertInertia(fn ($p) => $p->has('invoices', 1));
        $this->get(route('real-estate.report'))->assertOk()->assertInertia(fn ($p) => $p->has('expiring', 1)->where('occupancy.occupied', 1));
        $this->delete(route('real-estate.units.destroy', $unit->id))->assertSessionHasErrors('unit');
        $this->delete(route('real-estate.properties.destroy', $property->id))->assertSessionHasErrors('property');
        $this->post(route('real-estate.leases.end', $lease->id))->assertSessionHasNoErrors();
        $this->assertSame('vacant', $unit->fresh()->status);
    }

    public function test_a_unit_on_a_work_order_is_linked_and_the_module_switch_applies(): void
    {
        $unit = $this->unit();
        $order = app(WorkOrderService::class)->create(['customer_id' => $this->customer->id, 'title' => 'تسريب', 'property_unit_id' => $unit->id]);
        $this->assertSame($unit->id, WorkOrder::find($order->id)->property_unit_id);

        $this->company->setSetting('disabled_modules', ['real_estate'], 'general', 'json');
        $this->get(route('real-estate.leases.index'))->assertForbidden();
    }

    protected function assertInvalid(callable $fn, string $key): void
    {
        try {
            $fn();
            $this->fail("expected a validation error on [{$key}]");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_rent_stops_at_the_lease_end_and_the_lease_ends_on_its_own(): void
    {
        $unit = $this->unit();
        $lease = $this->lease($unit, ['start_date' => '2026-06-15', 'end_date' => '2026-09-14']); // 3 months
        foreach (['2026-07-15', '2026-08-15', '2026-09-15', '2026-10-15', '2026-11-15'] as $day) {
            Carbon::setTestNow($day.' 02:00:00');
            app(RecurringInvoiceRunner::class)->runDue();
        }
        $this->assertCount(3, app(LeaseService::class)->invoices($lease)); // Jun, Jul, Aug — nothing after the end

        Carbon::setTestNow('2026-09-15 01:00:00');
        $this->artisan('erp:contracts')->assertSuccessful();
        $this->assertSame('ended', $lease->fresh()->status);
        $this->assertSame('vacant', $unit->fresh()->status);
    }

    public function test_a_rent_plan_the_user_switched_off_is_not_reused(): void
    {
        $first = $this->lease($this->unit('101'));
        $plan = CustomerSubscription::findOrFail($first->subscription_id)->plan;
        $plan->update(['is_active' => false]);
        $second = $this->lease($this->unit('102'));
        $this->assertNotSame($plan->id, CustomerSubscription::findOrFail($second->subscription_id)->service_plan_id);
    }

    public function test_residential_rent_is_vat_exempt_and_commercial_rent_is_not(): void
    {
        $this->lease($this->unit('101'), ['residential' => true]);
        $home = Invoice::latest('id')->firstOrFail();
        $this->assertEquals(0.0, (float) $home->tax_amount);
        $this->assertSame('E', $home->items()->first()->vat_category);
        $this->assertSame('VATEX-SA-30', $home->items()->first()->vat_exemption_code);

        $this->lease($this->unit('102'));
        $this->assertEquals(450.0, (float) Invoice::latest('id')->firstOrFail()->tax_amount); // 3000 × 15 %
    }

    public function test_the_lease_prints_as_a_text_document(): void
    {
        $lease = $this->lease($this->unit());
        $res = $this->get(route('documents.pdf', ['type' => 'lease', 'id' => $lease->id]))->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
    }
}
