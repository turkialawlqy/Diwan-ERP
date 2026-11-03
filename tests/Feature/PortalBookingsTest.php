<?php

namespace Tests\Feature;

use App\Models\BookableService;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PortalUser;
use App\Models\VisitPackage;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Bookings\PackageService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The customer books and cancels from the portal, and sees only his own. */
class PortalBookingsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $mine;

    protected Customer $theirs;

    protected PortalUser $user;

    protected BookableService $service;

    protected BookingResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 08:00:00'); // Monday
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->mine = Customer::create(['name' => 'عميلي', 'customer_type' => 'individual', 'status' => 'active']);
        $this->theirs = Customer::create(['name' => 'غيري', 'customer_type' => 'individual', 'status' => 'active']);
        $this->user = PortalUser::create(['customer_id' => $this->mine->id, 'name' => 'سارة', 'email' => 'sara@example.test', 'status' => 'active']);

        $this->resource = BookingResource::create(['name' => 'غرفة', 'type' => 'room', 'capacity' => 1, 'is_active' => true]);
        foreach (range(1, 7) as $d) {
            $this->resource->hours()->create(['weekday' => $d, 'start_time' => '09:00', 'end_time' => '17:00']);
        }
        $this->service = BookableService::create(['name' => 'جلسة', 'duration_minutes' => 60, 'price' => 100, 'tax_rate' => 0, 'online_bookable' => true, 'free_cancel_hours' => 24, 'is_active' => true]);
        $this->service->resources()->attach($this->resource->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function asCustomer(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('portal')->setUser($this->user->fresh());

        return $this;
    }

    protected function url(string $name, array $extra = []): string
    {
        return route($name, ['company' => $this->company->id] + $extra);
    }

    public function test_the_customer_books_an_online_service_and_it_is_confirmed(): void
    {
        $this->asCustomer();
        $this->get($this->url('customer-portal.bookings.create'))->assertOk()->assertInertia(fn ($p) => $p->has('services', 1));
        $this->get($this->url('customer-portal.bookings.slots', ['service' => $this->service->id, 'date' => '2026-06-17']))->assertOk()->assertJsonMissingPath('data.0.resource_id');

        $this->post($this->url('customer-portal.bookings.store'), ['bookable_service_id' => $this->service->id, 'starts_at' => '2026-06-17 10:00'])->assertSessionHasNoErrors();
        $b = Booking::firstOrFail();
        $this->assertSame($this->mine->id, $b->customer_id);
        $this->assertSame('portal', $b->source);
        $this->assertSame('confirmed', $b->status);
        $this->assertSame($this->user->id, $b->portal_user_id);

        $this->post($this->url('customer-portal.bookings.store'), ['bookable_service_id' => $this->service->id, 'starts_at' => '2026-06-17 10:00'])->assertSessionHasErrors('starts_at');
    }

    public function test_without_auto_confirm_the_booking_waits_for_staff(): void
    {
        $this->company->setSetting('bookings_auto_confirm', '0', 'bookings', 'string');
        $this->asCustomer();
        $this->post($this->url('customer-portal.bookings.store'), ['bookable_service_id' => $this->service->id, 'starts_at' => '2026-06-17 11:00'])->assertSessionHasNoErrors();
        $this->assertSame('pending', Booking::firstOrFail()->status);
    }

    public function test_offline_services_the_notice_window_and_other_days_are_refused(): void
    {
        $private = BookableService::create(['name' => 'خاصة', 'duration_minutes' => 30, 'price' => 10, 'tax_rate' => 0, 'online_bookable' => false, 'is_active' => true]);
        $private->resources()->attach($this->resource->id);
        $this->asCustomer();

        $this->post($this->url('customer-portal.bookings.store'), ['bookable_service_id' => $private->id, 'starts_at' => '2026-06-17 10:00'])->assertSessionHasErrors('bookable_service_id');
        $this->get($this->url('customer-portal.bookings.slots', ['service' => $private->id, 'date' => '2026-06-17']))->assertNotFound();
        // 09:00 today is inside the 2 h minimum notice (it is 08:00 now)
        $this->post($this->url('customer-portal.bookings.store'), ['bookable_service_id' => $this->service->id, 'starts_at' => '2026-06-15 09:00'])->assertSessionHasErrors('starts_at');
        // beyond the 60-day window
        $this->post($this->url('customer-portal.bookings.store'), ['bookable_service_id' => $this->service->id, 'starts_at' => '2026-12-15 10:00'])->assertSessionHasErrors('starts_at');
    }

    public function test_only_my_bookings_show_and_cancel_follows_the_window(): void
    {
        $mine = Booking::create(['number' => 'B1', 'customer_id' => $this->mine->id, 'bookable_service_id' => $this->service->id, 'booking_resource_id' => $this->resource->id,
            'starts_at' => '2026-06-20 10:00', 'ends_at' => '2026-06-20 11:00', 'status' => 'confirmed', 'source' => 'staff', 'price' => 100]);
        $soon = Booking::create(['number' => 'B2', 'customer_id' => $this->mine->id, 'bookable_service_id' => $this->service->id, 'booking_resource_id' => $this->resource->id,
            'starts_at' => '2026-06-15 15:00', 'ends_at' => '2026-06-15 16:00', 'status' => 'confirmed', 'source' => 'staff', 'price' => 100]);
        $theirs = Booking::create(['number' => 'B3', 'customer_id' => $this->theirs->id, 'bookable_service_id' => $this->service->id, 'booking_resource_id' => $this->resource->id,
            'starts_at' => '2026-06-21 10:00', 'ends_at' => '2026-06-21 11:00', 'status' => 'confirmed', 'source' => 'staff', 'price' => 100]);

        $this->asCustomer();
        $this->get($this->url('customer-portal.bookings.index'))->assertOk()->assertInertia(fn ($p) => $p->has('upcoming', 2));
        $this->get($this->url('customer-portal.bookings.show', ['booking' => $theirs->id]))->assertNotFound();
        $this->post($this->url('customer-portal.bookings.cancel', ['booking' => $theirs->id]))->assertNotFound();

        $this->get($this->url('customer-portal.bookings.show', ['booking' => $soon->id]))->assertInertia(fn ($p) => $p->where('canCancel', false));
        $this->post($this->url('customer-portal.bookings.cancel', ['booking' => $soon->id]))->assertSessionHasErrors('status');
        $this->assertSame('confirmed', $soon->fresh()->status);

        $this->get($this->url('customer-portal.bookings.show', ['booking' => $mine->id]))->assertInertia(fn ($p) => $p->where('canCancel', true));
        $this->post($this->url('customer-portal.bookings.cancel', ['booking' => $mine->id]), ['reason' => 'سفر'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $mine->fresh()->status);
    }

    public function test_the_home_shows_package_balance_and_the_module_off_hides_the_pages(): void
    {
        $template = VisitPackage::create(['name' => '5 جلسات', 'visits' => 5, 'price' => 0, 'tax_rate' => 0, 'is_active' => true]);
        app(PackageService::class)->sell($template, $this->mine->id);

        $this->asCustomer();
        $this->get($this->url('customer-portal.home'))->assertOk()->assertInertia(fn ($p) => $p->where('portal.menu.bookings', true)->has('packages', 1)->where('packages.0.remaining', 5));

        $this->company->setSetting('disabled_modules', ['bookings'], 'general', 'json');
        $this->get($this->url('customer-portal.bookings.index'))->assertNotFound();
        $this->get($this->url('customer-portal.home'))->assertInertia(fn ($p) => $p->where('portal.menu.bookings', false)->where('packages', null));
    }
}
