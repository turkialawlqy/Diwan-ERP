<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\SupportCategory;
use App\Models\Ticket;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Support\SlaService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Support tickets: SLA clocks, conversation, status changes, breaches, survey and module switch. */
class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'شركة العميل', 'customer_type' => 'commercial', 'status' => 'active', 'email' => 'c@example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function open(array $extra = []): Ticket
    {
        $this->post(route('support.tickets.store'), array_merge(['customer_id' => $this->customer->id, 'subject' => 'الطابعة لا تعمل', 'channel' => 'phone', 'priority' => 'normal'], $extra))->assertSessionHasNoErrors();

        return Ticket::latest('id')->firstOrFail();
    }

    protected function sla(): SlaService
    {
        return app(SlaService::class);
    }

    public function test_a_ticket_gets_a_number_and_the_deadlines_of_its_priority(): void
    {
        $t = $this->open();

        $this->assertSame('TKT-26-00001', $t->number);
        $this->assertSame('open', $t->status);
        $this->assertSame('2026-06-15 14:00:00', $t->first_response_due_at->format('Y-m-d H:i:s')); // normal: 4 hours
        $this->assertSame('2026-06-17 10:00:00', $t->resolution_due_at->format('Y-m-d H:i:s')); // normal: 2 days

        $urgent = $this->open(['priority' => 'urgent']);
        $this->assertSame('2026-06-15 10:30:00', $urgent->first_response_due_at->format('Y-m-d H:i:s'));
    }

    public function test_the_company_can_set_its_own_sla_targets(): void
    {
        $policies = collect(['low', 'normal', 'high', 'urgent'])->map(fn ($p) => ['priority' => $p, 'first_response_minutes' => 30, 'resolution_minutes' => 120])->all();
        $this->put(route('support.settings.sla'), ['policies' => $policies])->assertSessionHasNoErrors();

        $this->assertSame('2026-06-15 12:00:00', $this->open()->resolution_due_at->format('Y-m-d H:i:s'));

        // Resolution can't be shorter than the first response.
        $policies[0]['resolution_minutes'] = 10;
        $this->put(route('support.settings.sla'), ['policies' => $policies])->assertSessionHasErrors();
    }

    public function test_a_customer_or_a_contact_name_is_required(): void
    {
        $this->post(route('support.tickets.store'), ['subject' => 'بلا عميل', 'channel' => 'phone', 'priority' => 'normal'])->assertSessionHasErrors('customer_id');
        $this->post(route('support.tickets.store'), ['contact_name' => 'زائر', 'subject' => 'استفسار', 'channel' => 'visit', 'priority' => 'low'])->assertSessionHasNoErrors();
        $this->assertNull(Ticket::latest('id')->first()->customer_id);
    }

    public function test_the_first_public_reply_stops_the_response_clock_and_the_conversation_moves_the_status(): void
    {
        $t = $this->open();

        // An internal note answers nobody.
        $this->post(route('support.tickets.reply', $t->id), ['body' => 'نتحقق من المخزون', 'type' => 'internal'])->assertSessionHasNoErrors();
        $this->assertNull($t->fresh()->first_response_at);
        $this->assertSame('open', $t->fresh()->status);

        Carbon::setTestNow('2026-06-15 11:00:00');
        $this->post(route('support.tickets.reply', $t->id), ['body' => 'أرسلنا فنيًا', 'type' => 'public'])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame('2026-06-15 11:00:00', $t->first_response_at->format('Y-m-d H:i:s'));
        $this->assertFalse($t->response_breached);
        $this->assertSame('pending', $t->status);

        // The customer answers: back to open.
        $this->post(route('support.tickets.reply', $t->id), ['body' => 'ما زالت المشكلة', 'type' => 'customer']);
        $this->assertSame('open', $t->fresh()->status);
        $this->assertSame(3, $t->messages()->count());
    }

    public function test_a_late_first_response_is_a_breach(): void
    {
        $t = $this->open(['priority' => 'high']); // first response due within an hour

        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->post(route('support.tickets.reply', $t->id), ['body' => 'آسفون للتأخير', 'type' => 'public']);

        $this->assertTrue($t->fresh()->response_breached);
    }

    public function test_resolving_closing_and_reopening(): void
    {
        $t = $this->open();

        Carbon::setTestNow('2026-06-15 15:00:00');
        $this->patch(route('support.tickets.update', $t->id), ['status' => 'resolved'])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame('resolved', $t->status);
        $this->assertNotNull($t->resolved_at);
        $this->assertFalse($t->resolution_breached);
        $this->assertTrue($t->response_breached, 'resolved without any reply after the response deadline');

        $this->patch(route('support.tickets.update', $t->id), ['status' => 'closed']);
        $this->assertNotNull($t->fresh()->closed_at);
        $this->post(route('support.tickets.reply', $t->id), ['body' => 'متأخر', 'type' => 'public'])->assertSessionHasErrors('body');

        $this->patch(route('support.tickets.update', $t->id), ['status' => 'open']);
        $t->refresh();
        $this->assertSame('open', $t->status);
        $this->assertNull($t->resolved_at);
        $this->assertNull($t->closed_at);
    }

    public function test_changing_the_priority_moves_the_running_deadlines(): void
    {
        $t = $this->open(); // normal

        $this->patch(route('support.tickets.update', $t->id), ['priority' => 'urgent'])->assertSessionHasNoErrors();

        $t->refresh();
        $this->assertSame('urgent', $t->priority);
        $this->assertSame('2026-06-15 10:30:00', $t->first_response_due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 14:00:00', $t->resolution_due_at->format('Y-m-d H:i:s'));
    }

    public function test_missed_deadlines_are_flagged_once_and_the_assignee_is_told(): void
    {
        $user = auth()->user();
        $t = $this->open(['priority' => 'urgent', 'assigned_to' => $user->id]);

        $this->assertSame(0, $this->sla()->checkBreaches());

        Carbon::setTestNow('2026-06-15 11:00:00'); // past the 30-minute response, before the 4-hour resolution
        $this->assertSame(1, $this->sla()->checkBreaches());
        $this->assertTrue($t->fresh()->response_breached);
        $this->assertFalse($t->fresh()->resolution_breached);
        $this->assertSame(1, $user->fresh()->notifications()->count());
        $this->assertSame(0, $this->sla()->checkBreaches(), 'the same breach is not announced twice');

        Carbon::setTestNow('2026-06-15 15:00:00');
        $this->assertSame(1, $this->sla()->checkBreaches()); // now the resolution deadline too
        $this->assertTrue($t->fresh()->resolution_breached);
        $this->assertTrue($t->fresh()->isOverdue());
    }

    public function test_the_list_filters_and_the_metrics(): void
    {
        $a = $this->open();
        $b = $this->open(['priority' => 'urgent', 'subject' => 'عطل في الخادم']);
        $this->patch(route('support.tickets.update', $a->id), ['status' => 'resolved']);

        $this->get(route('support.tickets.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Support/Tickets/Index')->has('tickets.data', 1)->where('metrics.open', 1)->where('metrics.unassigned', 1));
        $this->get(route('support.tickets.index', ['status' => 'all', 'search' => 'الخادم']))->assertInertia(fn ($p) => $p->has('tickets.data', 1)->where('tickets.data.0.number', $b->number));
        $this->get(route('support.tickets.index', ['status' => 'resolved']))->assertInertia(fn ($p) => $p->has('tickets.data', 1)->where('tickets.data.0.id', $a->id));
        $this->assertNotNull($this->sla()->metrics()['avg_resolution_hours']);
    }

    public function test_the_customer_rates_the_service_through_a_signed_link_only_after_it_is_resolved(): void
    {
        $t = $this->open();
        $link = $this->sla()->surveyLink($t);

        $this->get($link)->assertNotFound(); // still active

        $this->patch(route('support.tickets.update', $t->id), ['status' => 'resolved']);
        $this->get($this->sla()->surveyLink($t))->assertOk()->assertSee('كيف تقيّم الخدمة');
        $this->get(route('share.ticket-survey', ['company' => $this->company->id, 'ticket' => $t->id]))->assertForbidden(); // unsigned

        $post = URL::temporarySignedRoute('share.ticket-survey.rate', now()->addDay(), ['company' => $this->company->id, 'ticket' => $t->id]);
        $this->post($post, ['rating' => 5, 'comment' => 'خدمة ممتازة'])->assertOk()->assertSee('شكرًا لتقييمك');
        $this->assertSame(5, $t->fresh()->rating);
        $this->post($post, ['rating' => 9])->assertSessionHasErrors('rating');
    }

    public function test_categories_and_the_pages_follow_permissions_and_the_module_switch(): void
    {
        $this->post(route('support.settings.categories.store'), ['name' => 'أعطال'])->assertSessionHasNoErrors();
        $cat = SupportCategory::firstOrFail();
        $t = $this->open(['category_id' => $cat->id]);

        $this->get(route('support.tickets.show', $t->id))->assertOk()->assertInertia(fn ($p) => $p->component('Support/Tickets/Show')->where('ticket.category.name', 'أعطال'));
        $this->get(route('support.settings.index'))->assertOk();
        $this->delete(route('support.settings.categories.destroy', $cat->id));
        $this->assertNull($t->fresh()->category_id);

        // Switched off by the company: pages refuse, data stays.
        $this->company->setSetting('disabled_modules', ['support'], 'general', 'json');
        $this->get(route('support.tickets.index'))->assertForbidden();
        $this->assertSame(1, Ticket::withoutGlobalScopes()->count());
        $this->company->setSetting('disabled_modules', [], 'general', 'json');

        $this->actingAs($this->userWithRole($this->company, 'warehouse_keeper'));
        $this->get(route('support.tickets.index'))->assertForbidden();
        $this->post(route('support.tickets.reply', $t->id), ['body' => 'x', 'type' => 'public'])->assertForbidden();
    }

    public function test_the_clock_stops_while_waiting_for_the_customer_and_the_team_hears_about_replies(): void
    {
        $agent = $this->userWithRole($this->company, 'sales');
        $t = $this->open(['priority' => 'urgent', 'assigned_to' => $agent->id]); // resolve by 14:00
        $this->assertSame(1, $agent->fresh()->notifications()->count()); // told about the assignment

        $this->sla()->reply($t->fresh(), auth()->user(), 'نحتاج صورة الفاتورة', 'public'); // waits for the customer
        Carbon::setTestNow('2026-06-15 16:00:00'); // past the deadline, but the ball is with the customer
        $this->sla()->checkBreaches();
        $this->assertFalse($t->fresh()->resolution_breached);

        $this->sla()->reply($t->fresh(), null, 'هذه الصورة', 'customer'); // back after 6 hours
        $this->assertSame('2026-06-15 20:00:00', $t->fresh()->resolution_due_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $agent->fresh()->notifications()->count()); // told about the customer's reply
    }

    public function test_a_ticket_is_assigned_only_to_someone_who_works_tickets(): void
    {
        $t = $this->open();
        $outsider = $this->userWithRole($this->company, 'employee');
        $this->patch(route('support.tickets.update', $t->id), ['assigned_to' => $outsider->id])->assertSessionHasErrors('assigned_to');
        $this->assertNull($t->fresh()->assigned_to);
    }
}
