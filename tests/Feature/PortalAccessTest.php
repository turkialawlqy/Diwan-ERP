<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAcceptance;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Invoice;
use App\Models\PortalUser;
use App\Models\ServicePlan;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\PortalInvitation;
use App\Notifications\PortalTicketReply;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Contracts\ContractService;
use App\Services\Support\SlaService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** What a signed-in portal customer can and cannot reach. */
class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $staff;

    protected Customer $mine;

    protected Customer $theirs;

    protected PortalUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->staff = $this->userWithRole($this->company);
        $this->actingAs($this->staff);
        $this->mine = Customer::create(['name' => 'عميلي', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->theirs = Customer::create(['name' => 'عميل آخر', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->user = PortalUser::create(['customer_id' => $this->mine->id, 'name' => 'سارة', 'email' => 'sara@example.test', 'status' => 'active']);
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
        $this->app['auth']->guard('portal')->setUser($this->user->fresh()); // the default guard stays «web», as in production

        return $this;
    }

    protected function url(string $name, array $extra = []): string
    {
        return route($name, ['company' => $this->company->id] + $extra);
    }

    protected function subscribe(Customer $customer): CustomerSubscription
    {
        if (! ServicePlan::exists()) {
            $this->post(route('sales.service-plans.store'), ['name' => 'الباقة', 'price' => 100, 'tax_rate' => 15, 'cycle' => 'monthly', 'trial_days' => 0])->assertSessionHasNoErrors();
        }
        $plan = ServicePlan::firstOrFail();
        $this->post(route('sales.subscriptions.store'), ['customer_id' => $customer->id, 'service_plan_id' => $plan->id, 'quantity' => 1, 'start_date' => '2026-06-15'])->assertSessionHasNoErrors();

        return CustomerSubscription::where('customer_id', $customer->id)->latest('id')->firstOrFail();
    }

    protected function contract(Customer $customer, array $extra = []): Contract
    {
        $this->post(route('contracts.store'), array_merge(['title' => 'عقد', 'kind' => 'service', 'party_type' => 'customer', 'customer_id' => $customer->id, 'start_date' => '2026-06-15', 'end_date' => '2027-06-14', 'value' => 1000, 'body' => 'النص الأول'], $extra))->assertSessionHasNoErrors();

        return Contract::where('customer_id', $customer->id)->latest('id')->firstOrFail();
    }

    public function test_the_customer_sees_only_his_own_invoices_and_others_answer_404(): void
    {
        $mineSub = $this->subscribe($this->mine);
        $theirSub = $this->subscribe($this->theirs);
        $mine = Invoice::where('customer_id', $this->mine->id)->firstOrFail();
        $theirs = Invoice::where('customer_id', $this->theirs->id)->firstOrFail();

        $this->asCustomer();
        $this->get($this->url('customer-portal.invoices.index'))->assertOk()->assertInertia(fn ($p) => $p->has('invoices.data', 1)->where('invoices.data.0.id', $mine->id));
        $this->get($this->url('customer-portal.invoices.show', ['invoice' => $mine->id]))->assertOk()->assertInertia(fn ($p) => $p->component('Portal/InvoiceShow')->whereNot('pdfUrl', null));
        $this->get($this->url('customer-portal.invoices.show', ['invoice' => $theirs->id]))->assertNotFound();
        $this->get($this->url('customer-portal.subscriptions.show', ['subscription' => $mineSub->id]))->assertOk();
        $this->get($this->url('customer-portal.subscriptions.show', ['subscription' => $theirSub->id]))->assertNotFound();
        $this->get($this->url('customer-portal.statement'))->assertOk();
        $this->get($this->url('customer-portal.payments'))->assertOk();
    }

    public function test_internal_notes_never_reach_the_customer_and_a_public_reply_notifies_him(): void
    {
        Notification::fake();
        $sla = app(SlaService::class);
        $ticket = $sla->open(['customer_id' => $this->mine->id, 'subject' => 'عطل', 'description' => 'لا يعمل', 'channel' => 'phone', 'priority' => 'normal']);
        $other = $sla->open(['customer_id' => $this->theirs->id, 'subject' => 'تذكرة غيره', 'channel' => 'phone', 'priority' => 'normal']);
        $sla->reply($ticket, $this->staff, 'ملاحظة داخلية سرية', 'internal');
        $sla->reply($ticket, $this->staff, 'تم فحص العطل', 'public');
        Notification::assertSentTo($this->user, PortalTicketReply::class);

        $this->asCustomer();
        $res = $this->get($this->url('customer-portal.tickets.show', ['ticket' => $ticket->id]))->assertOk();
        $res->assertInertia(fn ($p) => $p->has('messages', 1)->where('messages.0.author', 'فريق الدعم'));
        $this->assertStringNotContainsString('سرية', $res->getContent());
        $this->assertStringNotContainsString($this->staff->name, $res->getContent());
        $this->get($this->url('customer-portal.tickets.show', ['ticket' => $other->id]))->assertNotFound();
        $this->post($this->url('customer-portal.tickets.reply', ['ticket' => $other->id]), ['body' => 'x'])->assertNotFound();
    }

    public function test_a_customer_opens_a_ticket_and_replies(): void
    {
        $this->asCustomer();
        $this->post($this->url('customer-portal.tickets.store'), ['subject' => 'طلب', 'description' => 'تفاصيل'])->assertSessionHasNoErrors();
        $t = Ticket::firstOrFail();
        $this->assertSame($this->mine->id, $t->customer_id);
        $this->assertSame($this->user->id, $t->portal_user_id);
        $this->post($this->url('customer-portal.tickets.reply', ['ticket' => $t->id]), ['body' => 'إضافة'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ticket_messages', ['ticket_id' => $t->id, 'type' => 'customer', 'portal_user_id' => $this->user->id]);
    }

    public function test_only_shared_contracts_show_and_acceptance_is_per_version_with_evidence(): void
    {
        $hidden = $this->contract($this->mine, ['title' => 'غير مشارك']);
        $shared = $this->contract($this->mine, ['title' => 'مشارك']);
        $foreign = $this->contract($this->theirs, ['title' => 'عقد غيره']);
        foreach ([$hidden, $shared, $foreign] as $c) {
            $this->post(route('contracts.submit', $c->id))->assertSessionHasNoErrors();
        }
        foreach ([$shared, $foreign] as $c) {
            $this->post(route('contracts.share', $c->id), ['shared_with_customer' => true, 'requires_acceptance' => true])->assertSessionHasNoErrors();
        }
        $shared->refresh();
        $this->assertSame('active', $shared->status);

        $this->asCustomer();
        $this->get($this->url('customer-portal.contracts.index'))->assertInertia(fn ($p) => $p->has('contracts.data', 1)->where('contracts.data.0.id', $shared->id));
        $this->get($this->url('customer-portal.contracts.show', ['contract' => $hidden->id]))->assertNotFound();
        $this->get($this->url('customer-portal.contracts.show', ['contract' => $foreign->id]))->assertNotFound();
        $this->post($this->url('customer-portal.contracts.accept', ['contract' => $foreign->id]), ['name' => 'سارة محمد', 'agree' => true])->assertNotFound();

        $this->get($this->url('customer-portal.contracts.show', ['contract' => $shared->id]))->assertInertia(fn ($p) => $p->where('canAccept', true));
        $this->post($this->url('customer-portal.contracts.accept', ['contract' => $shared->id]), ['name' => 'سارة محمد'])->assertSessionHasErrors('agree');
        $this->post($this->url('customer-portal.contracts.accept', ['contract' => $shared->id]), ['name' => 'سارة محمد', 'agree' => true])->assertSessionHasNoErrors();

        $a = ContractAcceptance::firstOrFail();
        $this->assertSame(1, (int) $a->version);
        $this->assertSame(hash('sha256', 'النص الأول'), $a->body_hash);
        $this->assertNotNull($a->ip);
        $this->post($this->url('customer-portal.contracts.accept', ['contract' => $shared->id]), ['name' => 'سارة محمد', 'agree' => true])->assertSessionHasErrors('agree');
        $this->assertSame(1, ContractAcceptance::count());

        // An amendment is a new version, so the customer is asked again.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff);
        $shared = Contract::find($shared->id);
        app(ContractService::class)->amend($shared, 'النص المعدّل', 'تعديل');
        $this->asCustomer();
        $this->get($this->url('customer-portal.contracts.show', ['contract' => $shared->id]))->assertInertia(fn ($p) => $p->where('canAccept', true)->where('accepted', false));
    }

    public function test_a_subscription_request_opens_a_linked_ticket_and_changes_nothing(): void
    {
        $sub = $this->subscribe($this->mine);
        $this->asCustomer();
        $this->post($this->url('customer-portal.subscriptions.request', ['subscription' => $sub->id]), ['type' => 'cancel', 'note' => 'لا حاجة'])->assertSessionHasNoErrors();

        $t = Ticket::firstOrFail();
        $this->assertSame($sub->id, $t->subscription_id);
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertFalse((bool) $sub->fresh()->cancel_at_period_end);
    }

    public function test_switching_a_module_off_hides_its_pages(): void
    {
        $this->company->setSetting('disabled_modules', ['support'], 'general', 'json');
        $this->asCustomer();
        $this->get($this->url('customer-portal.tickets.index'))->assertNotFound();
        $this->get($this->url('customer-portal.home'))->assertOk()->assertInertia(fn ($p) => $p->where('portal.menu.support', false));

        $this->company->setSetting('disabled_modules', ['portal'], 'general', 'json');
        $this->get($this->url('customer-portal.home'))->assertNotFound();
    }

    public function test_staff_and_portal_sessions_do_not_cross(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff); // a staff session is no portal session
        $this->get($this->url('customer-portal.home'))->assertRedirect($this->url('customer-portal.login'));

        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('portal')->setUser($this->user); // and a portal session is no staff session
        $this->get(route('sales.invoices.index'))->assertRedirect();
        $this->get(route('portal.users.index'))->assertRedirect();
    }

    public function test_staff_invite_a_portal_user_and_can_disable_or_remove_it(): void
    {
        Notification::fake();
        $this->post(route('portal.users.store'), ['customer_id' => $this->mine->id, 'name' => 'خالد', 'email' => 'Khaled@Example.test'])->assertSessionHasNoErrors();
        $u = PortalUser::where('email', 'khaled@example.test')->firstOrFail();
        Notification::assertSentTo($u, PortalInvitation::class);
        $this->post(route('portal.users.store'), ['customer_id' => $this->mine->id, 'name' => 'مكرر', 'email' => 'khaled@example.test'])->assertSessionHasErrors('email');

        $this->put(route('portal.users.update', $u->id), ['status' => 'disabled'])->assertSessionHasNoErrors();
        $this->assertSame('disabled', $u->fresh()->status);
        $this->delete(route('portal.users.destroy', $u->id));
        $this->assertDatabaseMissing('portal_users', ['id' => $u->id]);
    }
}
