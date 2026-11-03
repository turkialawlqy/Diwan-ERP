<?php

namespace Tests\Feature;

use App\Models\BookableService;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** A card per enabled system on the dashboard, following the company, the branch and the user. */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function widgets(): array
    {
        Cache::flush();
        $res = $this->get(route('dashboard'))->assertOk();

        return collect($res->viewData('page')['props']['widgets'])->keyBy('key')->all();
    }

    public function test_enabled_systems_show_a_card_with_live_numbers(): void
    {
        Ticket::create(['number' => 'TKT-1', 'customer_id' => $this->customer->id, 'subject' => 'عطل', 'status' => 'open', 'priority' => 'normal']);
        WorkOrder::create(['number' => 'WO-1', 'customer_id' => $this->customer->id, 'title' => 'x', 'status' => 'in_progress']);
        $w = $this->widgets();

        $this->assertArrayHasKey('support', $w);
        $this->assertSame('1', $w['support']['metrics'][0]['value']);
        $this->assertSame('1', $w['work_orders']['metrics'][1]['value']); // in progress
        $this->assertArrayHasKey('bookings', $w);
    }

    public function test_a_system_the_company_switched_off_has_no_card(): void
    {
        $this->company->setSetting('disabled_modules', ['support', 'work_orders'], 'general', 'json');
        $w = $this->widgets();
        $this->assertArrayNotHasKey('support', $w);
        $this->assertArrayNotHasKey('work_orders', $w);
        $this->assertArrayHasKey('bookings', $w);
    }

    public function test_a_branch_without_the_activity_has_no_card_for_it(): void
    {
        $a = Branch::create(['name_ar' => 'أ', 'code' => 'A', 'is_main' => true, 'is_active' => true, 'modules' => ['bookings']]);
        $this->post(route('context.branch'), ['branch_id' => $a->id]);
        $w = $this->widgets();
        $this->assertArrayHasKey('bookings', $w);
        $this->assertArrayNotHasKey('support', $w);
    }

    public function test_a_user_sees_only_the_systems_he_may_open(): void
    {
        $clerk = $this->userWithRole($this->company, 'sales');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $clerk->givePermissionTo(['dashboard.main.view', 'support.tickets.view']);
        $this->actingAs($clerk->fresh());
        $w = $this->widgets();
        $this->assertArrayHasKey('support', $w);
        foreach (['hr', 'contracts', 'work_orders', 'bookings', 'projects', 'real_estate'] as $none) {
            $this->assertArrayNotHasKey($none, $w);
        }
    }

    public function test_each_user_hides_and_shows_a_card_for_himself(): void
    {
        $this->post(route('dashboard.widgets'), ['key' => 'support', 'hidden' => true])->assertSessionHasNoErrors();
        $this->assertSame(['support'], $this->owner->fresh()->dashboard_hidden_widgets);
        $this->assertTrue($this->widgets()['support']['hidden']);   // kept in the list for the customise menu, without numbers
        $this->assertSame([], $this->widgets()['support']['metrics']);

        $this->post(route('dashboard.widgets'), ['key' => 'support', 'hidden' => false]);
        $this->assertNull($this->owner->fresh()->dashboard_hidden_widgets);
        $this->post(route('dashboard.widgets'), ['key' => 'nope', 'hidden' => true])->assertNotFound();
    }

    public function test_numbers_follow_the_working_branch(): void
    {
        $a = Branch::create(['name_ar' => 'أ', 'code' => 'A', 'is_main' => true, 'is_active' => true]);
        $b = Branch::create(['name_ar' => 'ب', 'code' => 'B', 'is_active' => true]);
        $service = BookableService::create(['name' => 'خ', 'duration_minutes' => 30, 'price' => 1, 'tax_rate' => 0, 'branch_id' => $a->id]);
        $resource = BookingResource::create(['name' => 'م', 'type' => 'room', 'branch_id' => $a->id]);
        Booking::create(['number' => 'BKG-1', 'customer_id' => $this->customer->id, 'bookable_service_id' => $service->id, 'booking_resource_id' => $resource->id,
            'starts_at' => now()->setTime(15, 0), 'ends_at' => now()->setTime(15, 30), 'status' => 'pending', 'price' => 1, 'branch_id' => $a->id]);

        $this->post(route('context.branch'), ['branch_id' => $a->id]);
        $this->assertSame('1', $this->widgets()['bookings']['metrics'][1]['value']); // pending
        $this->post(route('context.branch'), ['branch_id' => $b->id]);
        $this->assertSame('0', $this->widgets()['bookings']['metrics'][1]['value']);
    }

    public function test_the_dashboard_page_still_renders_for_a_plain_employee(): void
    {
        $emp = $this->userWithRole($this->company, 'employee');
        $this->actingAs($emp)->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('widgets', 0));
    }
}
