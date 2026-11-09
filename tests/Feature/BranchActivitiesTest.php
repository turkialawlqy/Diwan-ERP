<?php

namespace Tests\Feature;

use App\Models\BookableService;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Employee;
use App\Models\Lease;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkType;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Sectors\SectorPackService;
use App\Services\TenantManager;
use App\Support\BranchScopeSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Activities per branch and strict isolation: a user reaches only his branches' activities and data. */
class BranchActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Branch $clinic;

    protected Branch $shop;

    protected Branch $general;

    protected Customer $customer;

    protected const PERMS = ['clinic.patients.view', 'clinic.patients.create', 'workshop.vehicles.view', 'workshop.vehicles.create', 'work_orders.orders.view', 'work_orders.orders.create',
        'bookings.bookings.view', 'bookings.bookings.create', 'bookings.services.view', 'bookings.resources.view', 'real_estate.leases.view', 'real_estate.properties.view', 'real_estate.units.view', 'sales.clients.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
        $this->clinic = Branch::create(['name_ar' => 'فرع العيادة', 'code' => 'CLN', 'is_main' => true, 'is_active' => true, 'modules' => ['clinic', 'bookings']]);
        $this->shop = Branch::create(['name_ar' => 'فرع الورشة', 'code' => 'SHP', 'is_active' => true, 'modules' => ['workshop', 'work_orders']]);
        $this->general = Branch::create(['name_ar' => 'الفرع العام', 'code' => 'GEN', 'is_active' => true]); // no list: every activity
        $this->customer = Customer::create(['name' => 'العميل', 'customer_type' => 'individual', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function staff(array $branches): User
    {
        $u = $this->userWithRole($this->company, 'sales');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $u->givePermissionTo(self::PERMS);
        $u->forceFill(['branch_id' => $branches[0]->id])->save();
        $u->branches()->sync(collect($branches)->pluck('id'));

        return $u->fresh();
    }

    protected function asStaff(array $branches, ?Branch $active = null): User
    {
        $u = $this->staff($branches);
        $this->actingAs($u);
        $this->post(route('context.branch'), ['branch_id' => ($active ?? $branches[0])->id]);

        return $u;
    }

    protected function routes(): array
    {
        $res = $this->get(route('dashboard'))->assertOk();

        return array_keys($res->viewData('page')['props']['routePermissions']);
    }

    // ------------------------------------------------------------ activities

    public function test_a_branch_without_the_activity_answers_403_and_its_menus_disappear(): void
    {
        $this->asStaff([$this->clinic, $this->shop], $this->clinic);
        $this->get(route('clinic.patients.index'))->assertOk();
        $this->get(route('workshop.vehicles.index'))->assertForbidden();
        $routes = $this->routes();
        $this->assertContains('clinic.patients.index', $routes);
        $this->assertNotContains('workshop.vehicles.index', $routes);
        $this->assertNotContains('work-orders.index', $routes);

        $this->post(route('context.branch'), ['branch_id' => $this->shop->id]);
        $this->get(route('workshop.vehicles.index'))->assertOk();
        $this->get(route('clinic.patients.index'))->assertForbidden();
        $routes = $this->routes();
        $this->assertContains('work-orders.index', $routes);
        $this->assertNotContains('clinic.patients.index', $routes);
        $this->assertNotContains('bookings.index', $routes);
    }

    public function test_a_branch_with_no_list_runs_everything_and_all_mode_is_the_union(): void
    {
        $this->asStaff([$this->general]);
        $this->get(route('clinic.patients.index'))->assertOk();
        $this->get(route('workshop.vehicles.index'))->assertOk();

        $this->asStaff([$this->clinic, $this->shop]);
        $this->post(route('context.branch'), ['branch_id' => 'all']);
        $this->get(route('clinic.patients.index'))->assertOk();
        $this->get(route('workshop.vehicles.index'))->assertOk();
    }

    public function test_the_company_switch_still_wins_over_a_branch_that_lists_the_activity(): void
    {
        $this->asStaff([$this->clinic]);
        $this->company->setSetting('disabled_modules', ['clinic'], 'general', 'json');
        $this->get(route('clinic.patients.index'))->assertForbidden();
    }

    public function test_switching_branch_from_an_activity_page_the_new_branch_lacks_goes_to_the_dashboard(): void
    {
        $this->asStaff([$this->clinic, $this->shop], $this->clinic);
        $this->from(route('clinic.patients.index'))->post(route('context.branch'), ['branch_id' => $this->shop->id])->assertRedirect(route('dashboard'));
        $this->from(route('workshop.vehicles.index'))->post(route('context.branch'), ['branch_id' => $this->shop->id])->assertRedirect(route('workshop.vehicles.index'));
    }

    public function test_the_branch_admin_saves_and_validates_activities(): void
    {
        $base = ['name_ar' => 'فرع', 'code' => 'X1', 'country_code' => 'SA'];
        $this->put(route('branches.update', $this->clinic->id), $base + ['modules' => ['clinic', 'gym']])->assertSessionHasNoErrors();
        $this->assertSame(['clinic', 'gym'], $this->clinic->fresh()->modules);
        $this->put(route('branches.update', $this->clinic->id), $base + ['modules' => ['not-a-module']])->assertSessionHasErrors('modules.0');
        $this->put(route('branches.update', $this->clinic->id), $base)->assertSessionHasNoErrors();
        $this->assertNull($this->clinic->fresh()->modules); // no list = every activity
        $this->put(route('branches.update', $this->clinic->id), $base + ['modules' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $this->clinic->fresh()->modules); // a list can be empty: only the core
        $this->get(route('branches.index'))->assertOk()->assertInertia(fn ($p) => $p->has('activityOptions.clinic'));
    }

    // ------------------------------------------------------------- isolation

    protected function records(Branch $branch): array
    {
        $property = Property::create(['name' => 'ع'.$branch->id, 'type' => 'building', 'branch_id' => $branch->id]);
        $unit = PropertyUnit::create(['property_id' => $property->id, 'unit_number' => '1', 'type' => 'apartment', 'branch_id' => $branch->id]);
        $resource = BookingResource::create(['name' => 'مورد', 'type' => 'room', 'branch_id' => $branch->id]);
        $service = BookableService::create(['name' => 'خدمة'.$branch->id, 'duration_minutes' => 30, 'price' => 10, 'tax_rate' => 0, 'branch_id' => $branch->id]);

        $patientCustomer = Customer::create(['name' => 'مريض'.$branch->id, 'customer_type' => 'individual', 'status' => 'active']);
        $patient = PatientFile::create(['customer_id' => $patientCustomer->id, 'file_number' => 'PF-'.$branch->id, 'branch_id' => $branch->id]);
        PatientVisit::create(['patient_file_id' => $patient->id, 'visit_date' => '2026-06-15', 'branch_id' => $branch->id]);

        return [
            'patient' => $patient,
            'order' => WorkOrder::create(['number' => 'WO-'.$branch->id, 'customer_id' => $this->customer->id, 'title' => 'x', 'branch_id' => $branch->id]),
            'vehicle' => CustomerVehicle::create(['customer_id' => $this->customer->id, 'plate' => 'V'.$branch->id, 'branch_id' => $branch->id]),
            'booking' => Booking::create(['number' => 'BKG-'.$branch->id, 'customer_id' => $this->customer->id, 'bookable_service_id' => $service->id, 'booking_resource_id' => $resource->id,
                'starts_at' => '2026-06-20 10:00', 'ends_at' => '2026-06-20 10:30', 'status' => 'confirmed', 'price' => 10, 'branch_id' => $branch->id]),
            'lease' => Lease::create(['number' => 'LSE-'.$branch->id, 'property_unit_id' => $unit->id, 'customer_id' => $this->customer->id, 'start_date' => '2026-06-15', 'end_date' => '2027-06-14',
                'rent_amount' => 1000, 'cycle' => 'monthly', 'branch_id' => $branch->id]),
        ];
    }

    public function test_a_user_never_reaches_another_branchs_records_by_list_or_by_url(): void
    {
        $mine = $this->records($this->general);
        $other = $this->records($this->clinic);   // another branch's data
        $a = $this->staff([$this->general]);
        $this->actingAs($a);
        $this->post(route('context.branch'), ['branch_id' => $this->general->id]);

        // A patient file is the customer's one file for the company; its visits stay in their branch.
        $this->get(route('clinic.patients.show', $other['patient']->id))->assertOk()->assertInertia(fn ($p) => $p->has('visits', 0));
        $this->get(route('clinic.patients.show', $mine['patient']->id))->assertOk()->assertInertia(fn ($p) => $p->has('visits', 1));
        $this->get(route('work-orders.show', $mine['order']->id))->assertOk();
        $this->get(route('work-orders.show', $other['order']->id))->assertNotFound();
        $this->get(route('workshop.vehicles.show', $mine['vehicle']->id))->assertOk();
        $this->get(route('workshop.vehicles.show', $other['vehicle']->id))->assertNotFound();
        $this->get(route('bookings.show', $mine['booking']->id))->assertOk();
        $this->get(route('bookings.show', $other['booking']->id))->assertNotFound();
        $this->get(route('real-estate.leases.show', $mine['lease']->id))->assertOk();
        $this->get(route('real-estate.leases.show', $other['lease']->id))->assertNotFound();

        // lists carry only my branch's rows
        $this->get(route('clinic.patients.index'))->assertInertia(fn ($p) => $p->has('patients.data', 2)); // one file per patient, company-wide
        $this->get(route('work-orders.index'))->assertInertia(fn ($p) => $p->has('orders.data', 1));
        $this->get(route('bookings.index'))->assertInertia(fn ($p) => $p->has('bookings.data', 1));

        // actions on the other branch's record are refused too
        $this->post(route('work-orders.hold', $other['order']->id))->assertNotFound();
        $this->post(route('bookings.cancel', $other['booking']->id))->assertNotFound();
    }

    public function test_the_owner_sees_every_branch_and_rows_without_a_branch_stay_shared(): void
    {
        $this->records($this->general);
        $this->records($this->clinic);
        BranchScopeSwitch::off(fn () => BookableService::create(['name' => 'مشتركة', 'duration_minutes' => 30, 'price' => 1, 'tax_rate' => 0])); // branch NULL
        $this->post(route('context.branch'), ['branch_id' => 'all']);
        $this->assertSame(3, BookableService::count()); // owner in all-branches mode sees everything

        $this->actingAs($this->staff([$this->general]));
        $this->post(route('context.branch'), ['branch_id' => $this->general->id]);
        $names = BookableService::pluck('name')->all();
        $this->assertContains('مشتركة', $names);
        $this->assertContains('خدمة'.$this->general->id, $names);
        $this->assertNotContains('خدمة'.$this->clinic->id, $names);
    }

    public function test_new_records_take_the_active_branch_and_a_foreign_branch_is_refused(): void
    {
        $u = $this->asStaff([$this->clinic, $this->shop], $this->clinic);
        $this->post(route('clinic.patients.store'), ['customer_id' => $this->customer->id])->assertSessionHasNoErrors();
        $this->assertSame($this->clinic->id, PatientFile::firstOrFail()->branch_id);

        try {
            CustomerVehicle::create(['customer_id' => $this->customer->id, 'plate' => 'X-1', 'branch_id' => $this->general->id]);
            $this->fail('a branch outside the user\'s own must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }
        $this->assertSame($u->id, auth()->id());
    }

    public function test_a_record_cannot_be_moved_to_a_branch_the_user_may_not_use(): void
    {
        $vehicle = $this->records($this->clinic)['vehicle'];
        $this->asStaff([$this->clinic, $this->shop], $this->clinic);

        foreach ([$this->general->id, null] as $target) { // another branch, or none (shared with every branch)
            try {
                $vehicle->update(['branch_id' => $target]);
                $this->fail('a move out of the user\'s branches must be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('branch_id', $e->errors());
            }
            $this->assertSame($this->clinic->id, $vehicle->fresh()->branch_id);
            $vehicle->refresh();
        }

        // Editing in place and moving between the user's own branches go through.
        $vehicle->update(['plate' => 'V-EDITED', 'branch_id' => (string) $this->clinic->id]);
        $this->assertSame('V-EDITED', $vehicle->fresh()->plate);
        $vehicle->update(['branch_id' => $this->shop->id]);
        $this->assertSame($this->shop->id, $vehicle->fresh()->branch_id);

        // The owner works in every branch.
        $this->actingAs($this->owner);
        $vehicle->update(['branch_id' => $this->clinic->id]);
        $this->assertSame($this->clinic->id, $vehicle->fresh()->branch_id);
    }

    public function test_moving_a_record_is_not_restricted_outside_a_users_own_work(): void
    {
        $vehicle = $this->records($this->clinic)['vehicle'];
        $this->asStaff([$this->clinic]);

        BranchScopeSwitch::off(fn () => $vehicle->update(['branch_id' => $this->shop->id]));
        $this->assertSame($this->shop->id, $vehicle->fresh()->branch_id);

        $this->app['env'] = 'production'; // running in the console, not a test run: a job or a command
        $vehicle->update(['branch_id' => $this->general->id]);
        $this->assertSame($this->general->id, $vehicle->fresh()->branch_id);
        $this->app['env'] = 'testing';

        $this->app['auth']->forgetGuards(); // nobody signed in
        $this->assertNull(auth()->user());
        $vehicle->update(['branch_id' => $this->shop->id]);
        $this->assertSame($this->shop->id, $vehicle->fresh()->branch_id);
    }

    // ---------------------------------------------------------------- packs

    public function test_a_pack_applies_to_one_branch_and_adds_its_modules_there(): void
    {
        $this->assertNotContains('real_estate', $this->shop->modules);
        app(SectorPackService::class)->apply($this->company, 'real_estate', $this->shop->id);

        $this->assertContains('real_estate', $this->shop->fresh()->modules);
        $this->assertSame(['workshop', 'work_orders', 'real_estate'], array_values(array_intersect($this->shop->fresh()->modules, ['workshop', 'work_orders', 'real_estate'])));
        $this->assertSame($this->shop->id, WorkType::withoutGlobalScopes()->where('name', 'صيانة سباكة')->value('branch_id'));
        $this->assertTrue(ContractTemplate::withoutGlobalScopes()->where('name', 'عقد إيجار وحدة سكنية')->exists()); // contract templates are company-wide: they have no branch

        // the clinic branch's user does not see the shop's work types
        $this->asStaff([$this->clinic]);
        $this->assertSame(0, WorkType::where('name', 'صيانة سباكة')->count());

        // applied state is per branch and re-applying adds nothing
        $again = app(SectorPackService::class)->apply($this->company, 'real_estate', $this->shop->id);
        $this->assertSame(0, array_sum($again['created']));
        $applied = app(SectorPackService::class)->applied($this->company->fresh());
        $this->assertArrayHasKey('real_estate@'.$this->shop->id, $applied);
        $this->assertArrayNotHasKey('real_estate', $applied);
    }

    public function test_pack_permissions_follow_the_users_branches(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('settings.sector-packs.apply', 'workshop'), ['branch_id' => $this->shop->id])->assertSessionHasNoErrors();
        $this->assertSame($this->shop->id, WorkType::withoutGlobalScopes()->where('name', 'فحص شامل')->value('branch_id'));
        $this->post(route('settings.sector-packs.apply', 'workshop'))->assertSessionHasNoErrors(); // owner: a shared catalogue
        $this->assertNull(WorkType::withoutGlobalScopes()->where('name', 'فحص شامل')->whereNull('branch_id')->value('branch_id'));
        $this->post(route('settings.sector-packs.apply', 'workshop'), ['branch_id' => 99999])->assertSessionHasErrors('branch_id');
    }

    public function test_time_entries_of_another_branchs_order_are_out_of_reach(): void
    {
        $other = $this->records($this->clinic)['order'];
        $tech = Employee::create(['name' => 'فني', 'employee_number' => 'T1', 'job_title' => 'فني', 'hire_date' => '2026-01-01', 'basic_salary' => 1, 'status' => 'active']);
        $entry = TimeEntry::create(['work_order_id' => $other->id, 'employee_id' => $tech->id, 'started_at' => '2026-06-15 08:00', 'ended_at' => '2026-06-15 09:00', 'minutes' => 60, 'billable' => true, 'labour_cost' => 10]);

        $u = $this->staff([$this->general]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $u->givePermissionTo(['work_orders.orders.edit', 'work_orders.timesheets.view']);
        $this->actingAs($u->fresh());
        $this->post(route('context.branch'), ['branch_id' => $this->general->id]);

        $this->put(route('work-orders.time.update', $entry->id), ['minutes' => 600])->assertNotFound();
        $this->post(route('work-orders.time.stop', $entry->id))->assertNotFound();
        $this->assertSame(60, $entry->fresh()->minutes);
        $this->get(route('work-orders.timesheet', ['from' => '2026-06-01', 'to' => '2026-06-30']))->assertOk()->assertInertia(fn ($p) => $p->has('entries', 0));
    }

    public function test_a_patient_with_a_file_in_another_branch_keeps_it(): void
    {
        $customer = Customer::create(['name' => 'مريض', 'customer_type' => 'individual', 'status' => 'active']);
        PatientFile::create(['customer_id' => $customer->id, 'file_number' => 'PF-1', 'branch_id' => $this->clinic->id]);
        $this->asStaff([$this->general]);
        $this->post(route('clinic.patients.store'), ['customer_id' => $customer->id])->assertSessionHasErrors('customer_id'); // a message, not a 500
        $this->assertSame(1, PatientFile::count());
    }

    public function test_search_leaves_out_systems_the_branch_does_not_run(): void
    {
        $this->records($this->shop);
        $this->asStaff([$this->clinic, $this->shop], $this->clinic); // clinic branch: no work orders, no real estate
        $kinds = collect($this->getJson(route('search', ['q' => 'WO-']))->assertOk()->json('kinds'))->pluck('key');
        $this->assertNotContains('work_orders', $kinds);
        $this->assertNotContains('leases', $kinds);
        $this->assertContains('patients', $kinds);

        $this->post(route('context.branch'), ['branch_id' => $this->shop->id]);
        $kinds = collect($this->getJson(route('search', ['q' => 'WO-']))->json('kinds'))->pluck('key');
        $this->assertContains('work_orders', $kinds);
    }
}
