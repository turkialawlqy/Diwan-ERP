<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BookableService;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\PublicHoliday;
use App\Models\ServicePlan;
use App\Models\User;
use App\Models\VisitPackage;
use App\Notifications\BookingMessage;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingClock;
use App\Services\Bookings\BookingReminders;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\PackageService;
use App\Services\Documents\TemplateData;
use App\Services\TenantManager;
use App\Support\BranchScopeSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Bookings: slots, conflicts, status actions, billing on completion, prepaid packages, member discount, reminders. */
class BookingsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Customer $customer;

    protected BookingResource $resource;

    protected BookableService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 08:00:00'); // a Monday
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
        $this->customer = Customer::create(['name' => 'سارة', 'customer_type' => 'individual', 'status' => 'active', 'email' => 'sara@example.test']);

        $this->resource = BookingResource::create(['name' => 'غرفة 1', 'type' => 'room', 'capacity' => 1, 'is_active' => true]);
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $this->resource->hours()->create(['weekday' => $day, 'start_time' => '09:00', 'end_time' => '12:00']);
            $this->resource->hours()->create(['weekday' => $day, 'start_time' => '13:00', 'end_time' => '17:00']); // a break 12–13
        }
        $this->service = BookableService::create(['name' => 'جلسة', 'duration_minutes' => 60, 'buffer_minutes' => 0, 'price' => 100, 'tax_rate' => 15, 'online_bookable' => true, 'free_cancel_hours' => 24, 'is_active' => true]);
        $this->service->resources()->attach($this->resource->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function book(string $at = '2026-06-16 10:00', array $extra = []): Booking
    {
        return app(BookingService::class)->create(array_merge(['customer_id' => $this->customer->id, 'bookable_service_id' => $this->service->id, 'booking_resource_id' => $this->resource->id, 'starts_at' => $at], $extra), 'staff', $this->owner);
    }

    protected function times(?string $day = '2026-06-16', ?BookingResource $r = null): array
    {
        return app(AvailabilityService::class)->slotsFor($r ?? $this->resource, $this->service, Carbon::parse($day), false)->pluck('time')->all();
    }

    public function test_slots_follow_hours_breaks_and_step(): void
    {
        $times = $this->times();
        $this->assertContains('09:00', $times);
        $this->assertContains('11:00', $times);        // ends at 12:00 exactly
        $this->assertNotContains('11:30', $times);     // would run into the break
        $this->assertNotContains('12:00', $times);
        $this->assertContains('13:00', $times);
        $this->assertContains('16:00', $times);
        $this->assertNotContains('16:30', $times);     // past closing
    }

    public function test_holidays_time_off_and_inactive_resources_remove_slots(): void
    {
        PublicHoliday::create(['name' => 'عيد', 'start_date' => '2026-06-16', 'end_date' => '2026-06-16']);
        $this->assertSame([], $this->times('2026-06-16'));

        $this->resource->timeOffs()->create(['starts_at' => '2026-06-17 09:00', 'ends_at' => '2026-06-17 11:00']);
        $times = $this->times('2026-06-17');
        $this->assertNotContains('09:00', $times);
        $this->assertNotContains('10:00', $times);
        $this->assertContains('11:00', $times);

        $this->resource->update(['is_active' => false]);
        $this->assertSame([], $this->times('2026-06-18', $this->resource->fresh()));
    }

    public function test_a_taken_slot_is_refused_and_buffer_and_capacity_are_honoured(): void
    {
        $this->book('2026-06-16 10:00');
        $this->assertNotContains('10:00', $this->times());
        $this->assertNotContains('10:30', $this->times());
        $this->assertContains('11:00', $this->times());

        try {
            $this->book('2026-06-16 10:30');
            $this->fail('the overlapping booking should be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('starts_at', $e->errors());
        }

        $this->service->update(['buffer_minutes' => 30]);
        $this->assertNotContains('11:00', $this->times()); // the buffer after the 10:00 booking
        $this->assertContains('13:00', $this->times()); // 11:30 would run into the break

        $this->resource->update(['capacity' => 2]);
        $this->assertContains('10:00', $this->times()); // two at a time
        $this->book('2026-06-16 10:00');
        $this->assertNotContains('10:00', $this->times());
    }

    public function test_any_resource_picks_a_free_one(): void
    {
        $second = BookingResource::create(['name' => 'غرفة 2', 'type' => 'room', 'capacity' => 1, 'is_active' => true]);
        $second->hours()->create(['weekday' => 2, 'start_time' => '09:00', 'end_time' => '12:00']);
        $this->service->resources()->attach($second->id);

        $a = app(BookingService::class)->create(['customer_id' => $this->customer->id, 'bookable_service_id' => $this->service->id, 'starts_at' => '2026-06-16 10:00'], 'staff', $this->owner);
        $b = app(BookingService::class)->create(['customer_id' => $this->customer->id, 'bookable_service_id' => $this->service->id, 'starts_at' => '2026-06-16 10:00'], 'staff', $this->owner);
        $this->assertNotSame($a->booking_resource_id, $b->booking_resource_id);

        $this->expectException(ValidationException::class);
        app(BookingService::class)->create(['customer_id' => $this->customer->id, 'bookable_service_id' => $this->service->id, 'starts_at' => '2026-06-16 10:00'], 'staff', $this->owner);
    }

    public function test_reschedule_confirm_cancel_and_no_show(): void
    {
        Notification::fake();
        $b = $this->book('2026-06-16 10:00');
        $this->assertSame('confirmed', $b->status);
        $this->assertSame('BKG-26-00001', $b->number);
        Notification::assertSentOnDemand(BookingMessage::class);

        $other = $this->book('2026-06-16 14:00');
        try {
            app(BookingService::class)->reschedule($b, '2026-06-16 14:00');
            $this->fail('clash expected');
        } catch (ValidationException) {
        }
        app(BookingService::class)->reschedule($b, '2026-06-16 11:00'); // it frees 10:00 and takes 11:00
        $this->assertSame('11:00', $b->fresh()->starts_at->format('H:i'));
        $this->assertContains('10:00', $this->times());

        app(BookingService::class)->cancel($b, 'تعذّر الحضور');
        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertContains('11:00', $this->times()); // the slot is free again
        $this->expectException(ValidationException::class);
        app(BookingService::class)->noShow($b->fresh());
    }

    public function test_a_customer_cannot_cancel_inside_the_free_window(): void
    {
        Carbon::setTestNow('2026-06-16 08:00:00');
        $b = $this->book('2026-06-16 10:00');
        try {
            app(BookingService::class)->cancel($b, null, true);
            $this->fail('inside the 24 h window');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $far = $this->book('2026-06-19 10:00');
        app(BookingService::class)->cancel($far, null, true);
        $this->assertSame('cancelled', $far->fresh()->status);
    }

    public function test_completing_issues_an_invoice_with_tax_and_the_deposit(): void
    {
        $b = $this->book('2026-06-16 10:00', ['deposit_amount' => 40]);
        $done = app(BookingService::class)->complete($b);

        $this->assertSame('completed', $done->status);
        $invoice = Invoice::findOrFail($done->invoice_id);
        $this->assertEquals(115.0, (float) $invoice->total); // 100 + 15 %
        $this->assertEquals(40.0, (float) $invoice->paid_amount);
        $this->assertEquals(75.0, (float) $invoice->remaining_amount);
        $this->assertSame('partially_paid', $invoice->payment_status);
        $this->assertSame($b->number, $invoice->reference_number);

        $this->expectException(ValidationException::class);
        app(BookingService::class)->complete($done);
    }

    public function test_a_prepaid_package_pays_the_visit_without_an_invoice_and_never_goes_negative(): void
    {
        $template = VisitPackage::create(['name' => '10 جلسات', 'visits' => 2, 'price' => 1000, 'tax_rate' => 15, 'valid_days' => 30, 'is_active' => true]);
        $pack = app(PackageService::class)->sell($template, $this->customer->id, false);
        $this->assertSame('PKG-00001', $pack->number);
        $this->assertSame(1, Invoice::count()); // the package sale
        $this->assertSame('2026-07-15', $pack->expires_at->toDateString());

        $one = app(BookingService::class)->complete($this->book('2026-06-16 09:00'));
        $this->assertSame($pack->id, $one->customer_package_id);
        $this->assertNull($one->invoice_id);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, $pack->fresh()->remaining());

        app(BookingService::class)->complete($this->book('2026-06-16 11:00'));
        $this->assertSame('used_up', $pack->fresh()->status);
        $this->assertSame(0, $pack->fresh()->remaining());

        // Nothing left: the next visit is billed normally.
        $third = app(BookingService::class)->complete($this->book('2026-06-16 14:00'));
        $this->assertNotNull($third->invoice_id);

        app(PackageService::class)->giveBack($pack);
        $this->assertSame('active', $pack->fresh()->status);
        $this->assertSame(1, $pack->fresh()->remaining());
        $this->assertSame(3, $pack->usages()->count());
    }

    public function test_package_expiry_service_scope_and_explicit_choice(): void
    {
        $other = BookableService::create(['name' => 'تدليك', 'duration_minutes' => 30, 'price' => 80, 'tax_rate' => 0, 'is_active' => true]);
        $template = VisitPackage::create(['name' => 'تدليك فقط', 'bookable_service_id' => $other->id, 'visits' => 5, 'price' => 0, 'valid_days' => 10, 'is_active' => true]);
        $pack = app(PackageService::class)->sell($template, $this->customer->id);

        $this->assertNull(app(PackageService::class)->pick($this->customer->id, $this->service->id, now())); // other service
        try {
            app(PackageService::class)->pick($this->customer->id, $this->service->id, now(), $pack->id);
            $this->fail('the package does not cover this service');
        } catch (ValidationException) {
        }

        Carbon::setTestNow('2026-07-01 08:00:00');
        $this->assertSame(1, app(PackageService::class)->expire());
        $this->assertSame('expired', $pack->fresh()->status);
    }

    public function test_a_subscription_plan_gives_members_a_discount_and_100_percent_is_free(): void
    {
        $plan = ServicePlan::create(['code' => 'PLN-1', 'name' => 'عضوية ذهبية', 'price' => 200, 'tax_rate' => 0, 'cycle' => 'monthly', 'trial_days' => 0, 'booking_discount_percent' => 50, 'is_active' => true]);
        $this->post(route('sales.subscriptions.store'), ['customer_id' => $this->customer->id, 'service_plan_id' => $plan->id, 'quantity' => 1, 'start_date' => '2026-06-15'])->assertSessionHasNoErrors();

        $b = $this->book('2026-06-16 10:00');
        $this->assertEquals(50.0, (float) $b->member_discount);
        $done = app(BookingService::class)->complete($b);
        $this->assertEquals(57.5, (float) Invoice::findOrFail($done->invoice_id)->total); // (100 - 50) + 15 %

        $plan->update(['booking_discount_percent' => 100]);
        $free = app(BookingService::class)->complete($this->book('2026-06-16 13:00'));
        $this->assertSame('completed', $free->status);
        $this->assertNull($free->invoice_id);
    }

    public function test_reminders_go_out_once_per_step_and_pending_bookings_alert_staff(): void
    {
        Notification::fake();
        $this->book('2026-06-15 10:00'); // two hours after "now" (08:00)
        $b = app(BookingReminders::class)->tick();
        $this->assertSame(1, $b['reminders']);
        Notification::assertSentOnDemandTimes(BookingMessage::class, 2); // the confirmation + the reminder

        $again = app(BookingReminders::class)->tick();
        $this->assertSame(0, $again['reminders']); // the 24 h step is covered by the 2 h one

        $pending = Booking::create(['number' => 'X', 'customer_id' => $this->customer->id, 'bookable_service_id' => $this->service->id, 'booking_resource_id' => $this->resource->id,
            'starts_at' => '2026-06-15 15:00', 'ends_at' => '2026-06-15 16:00', 'status' => 'pending', 'source' => 'portal', 'price' => 100]);
        $this->assertSame(1, app(BookingReminders::class)->tick()['unconfirmed']);
        $this->assertSame(0, app(BookingReminders::class)->tick()['unconfirmed']);
        $this->assertTrue($pending->fresh()->reminder_state['unconfirmed']);
    }

    public function test_staff_screens_and_the_module_switch(): void
    {
        $this->get(route('bookings.calendar', ['date' => '2026-06-16']))->assertOk()->assertInertia(fn ($p) => $p->component('Bookings/Calendar')->has('resources', 1));
        $this->get(route('bookings.slots', ['service' => $this->service->id, 'resource' => $this->resource->id, 'date' => '2026-06-16']))->assertOk()->assertJsonPath('data.0.time', '09:00');

        $this->post(route('bookings.store'), ['customer_id' => $this->customer->id, 'bookable_service_id' => $this->service->id, 'booking_resource_id' => $this->resource->id, 'starts_at' => '2026-06-16 10:00', 'deposit_amount' => 20])->assertSessionHasNoErrors();
        $b = Booking::firstOrFail();
        $this->get(route('bookings.show', $b->id))->assertOk()->assertInertia(fn ($p) => $p->component('Bookings/Show')->where('net', 100));
        $this->post(route('bookings.complete', $b->id))->assertSessionHasNoErrors();
        $this->assertNotNull($b->fresh()->invoice_id);
        // an invoice a booking created cannot be deleted
        $this->delete(route('sales.invoices.destroy', $b->fresh()->invoice_id))->assertSessionHas('error');

        $this->company->setSetting('disabled_modules', ['bookings'], 'general', 'json');
        $this->get(route('bookings.index'))->assertForbidden();
    }

    public function test_resources_and_services_are_created_with_hours_and_quick_create(): void
    {
        $this->postJson(route('bookings.resources.store'), ['name' => 'سريع', 'type' => 'court'])->assertCreated()->assertJsonPath('record.label', 'سريع');
        $this->post(route('bookings.resources.store'), [
            'name' => 'كوتش', 'type' => 'staff', 'hours' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']], 'service_ids' => [$this->service->id],
        ])->assertSessionHasNoErrors();
        $coach = BookingResource::where('name', 'كوتش')->firstOrFail();
        $this->assertSame(1, $coach->hours()->count());
        $this->assertTrue($coach->services()->whereKey($this->service->id)->exists());

        $this->post(route('bookings.resources.store'), ['name' => 'خطأ', 'type' => 'staff', 'hours' => [['weekday' => 1, 'start_time' => '10:00', 'end_time' => '10:00']]])->assertSessionHasErrors('hours'); // the same time twice
        $this->postJson(route('bookings.services.store'), ['name' => 'قص', 'duration_minutes' => 20, 'price' => 30])->assertCreated();

        $this->book('2026-06-16 10:00');
        $this->delete(route('bookings.resources.destroy', $this->resource->id))->assertSessionHasErrors('resource');
    }

    public function test_a_deposit_is_a_receipt_that_settles_the_invoice_when_done(): void
    {
        $booking = $this->book('2026-06-16 10:00', ['deposit_amount' => 40, 'deposit_method' => 'bank_transfer']);
        $payment = CustomerPayment::findOrFail($booking->fresh()->deposit_payment_id);
        $this->assertEquals(40.0, (float) $payment->amount);
        $this->assertSame('bank_transfer', $payment->payment_method);
        $this->assertSame('held', $booking->fresh()->deposit_status);
        $this->assertEquals(-40.0, (float) $this->customer->fresh()->current_balance); // in credit from today

        $done = app(BookingService::class)->complete($booking->fresh());
        $invoice = Invoice::findOrFail($done->invoice_id);
        $this->assertEquals(115.0, (float) $invoice->total);
        $this->assertEquals(75.0, (float) $invoice->remaining_amount);   // the deposit settled 40 of it
        $this->assertSame('applied', $done->fresh()->deposit_status);
        $this->assertEquals(75.0, (float) $this->customer->fresh()->current_balance);
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);
    }

    public function test_a_cancelled_booking_keeps_the_deposit_as_credit_or_forfeits_it(): void
    {
        $kept = $this->book('2026-06-16 10:00', ['deposit_amount' => 30]);
        app(BookingService::class)->cancel($kept->fresh(), 'ظرف');
        $this->assertSame('credited', $kept->fresh()->deposit_status);
        $this->assertEquals(-30.0, (float) $this->customer->fresh()->current_balance);

        $this->company->setSetting('bookings_deposit_on_cancel', 'forfeit', 'bookings', 'string');
        $lost = $this->book('2026-06-16 13:00', ['deposit_amount' => 20]);
        app(BookingService::class)->noShow($lost->fresh());
        $this->assertSame('forfeited', $lost->fresh()->deposit_status);
        $this->assertEquals(-30.0, (float) $this->customer->fresh()->current_balance); // the forfeited 20 is income now
        $other = Account::find(app(AccountMap::class)->id('other_revenue'));
        $this->assertEquals(20.0, abs(round((float) $other->balance, 2)));
    }

    public function test_a_shared_resource_is_one_calendar_for_every_branch(): void
    {
        $a = Branch::create(['name_ar' => 'أ', 'code' => 'A', 'is_main' => true, 'is_active' => true]);
        $b = Branch::create(['name_ar' => 'ب', 'code' => 'B', 'is_active' => true]);
        $this->post(route('context.branch'), ['branch_id' => $a->id]);
        $this->book('2026-06-16 10:00'); // the resource has no branch: shared
        $this->post(route('context.branch'), ['branch_id' => $b->id]);

        $this->assertNotContains('10:00', $this->times());
        $this->expectException(ValidationException::class);
        $this->book('2026-06-16 10:00');
    }

    public function test_the_booking_confirmation_prints_as_a_pdf(): void
    {
        $b = $this->book('2026-06-16 10:00', ['deposit_amount' => 30]);
        $this->get(route('documents.pdf', ['type' => 'booking', 'id' => $b->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $fields = app(TemplateData::class)->build($b->fresh())['fields'];
        $this->assertSame('30.00', $fields['doc.deposit']);
    }

    public function test_a_shift_past_midnight_belongs_to_its_start_day_and_opens_the_next_morning(): void
    {
        $night = BookingResource::create(['name' => 'مناوب ليلي', 'type' => 'staff', 'capacity' => 1, 'is_active' => true]);
        $night->hours()->create(['weekday' => 2, 'start_time' => '22:00', 'end_time' => '02:00']); // Tuesday night
        $this->service->resources()->attach($night->id);

        $tuesday = $this->times('2026-06-16', $night);
        $this->assertContains('22:00', $tuesday);
        $this->assertContains('23:00', $tuesday);
        $this->assertNotContains('00:00', $tuesday);          // after midnight is Wednesday's list
        $wednesday = $this->times('2026-06-17', $night);
        $this->assertContains('00:00', $wednesday);
        $this->assertContains('01:00', $wednesday);           // 01:00–02:00 still inside the shift
        $this->assertNotContains('02:00', $wednesday);
        $this->assertSame([], $this->times('2026-06-18', $night));

        $b = $this->book('2026-06-17 00:30', ['booking_resource_id' => $night->id]);
        $this->assertSame('2026-06-17 00:30', $b->starts_at->format('Y-m-d H:i'));
        $this->assertNotContains('00:30', $this->times('2026-06-17', $night));
    }

    public function test_a_branch_in_another_time_zone_counts_the_notice_on_its_own_clock(): void
    {
        $uae = Branch::create(['name_ar' => 'فرع بعيد', 'code' => 'FAR', 'is_active' => true, 'country_code' => 'SA', 'timezone' => 'Pacific/Auckland']); // far ahead of Riyadh
        $this->assertGreaterThan(8 * 3600, BookingClock::offsetSeconds($uae->id));
        $this->assertSame(0, BookingClock::offsetSeconds(null));

        $near = BookingResource::create(['name' => 'مورد بعيد', 'type' => 'room', 'capacity' => 1, 'is_active' => true, 'branch_id' => $uae->id]);
        $near->hours()->create(['weekday' => 1, 'start_time' => '00:00', 'end_time' => '12:00']);
        $this->service->resources()->attach($near->id);
        // Monday 15 Jun 08:00 in Riyadh is already evening there: Monday's morning is over, so no slot for it with a notice window.
        $slots = app(AvailabilityService::class)->slotsFor($near, $this->service, Carbon::parse('2026-06-15'), true)->pluck('time')->all();
        $this->assertSame([], $slots);
        // The same resource without a branch (Riyadh clock) still has its late-morning slots.
        $local = BranchScopeSwitch::off(fn () => BookingResource::create(['name' => 'مورد محلي', 'type' => 'room', 'capacity' => 1, 'is_active' => true])); // no branch: the company's clock
        $local->hours()->create(['weekday' => 1, 'start_time' => '00:00', 'end_time' => '12:00']);
        $this->service->resources()->attach($local->id);
        $this->assertContains('11:00', app(AvailabilityService::class)->slotsFor($local, $this->service, Carbon::parse('2026-06-15'), true)->pluck('time')->all());
    }
}
