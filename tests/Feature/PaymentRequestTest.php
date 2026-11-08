<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApprovalRule;
use App\Models\Attachment;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Expense;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Notifications\ErpAlert;
use App\Services\Accounting\AccountingControls;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Saas\AlertService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** «طلبات الصرف»: request → approval → carried out as an expense voucher or a supplier payment. */
class PaymentRequestTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected User $clerk;

    protected Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->clerk = $this->userWithRole($this->company, 'accountant');
        $this->expenseAccount = Account::create(['code' => '59401', 'name_ar' => 'ضيافة', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'is_system' => false]);
        $this->actingAs($this->clerk);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function payload(array $extra = []): array
    {
        return $extra + ['request_date' => '2026-06-30', 'kind' => 'expense', 'payee_type' => 'other', 'payee_name' => 'مطعم الواحة', 'purpose' => 'ضيافة اجتماع', 'account_id' => $this->expenseAccount->id,
            'amount' => 500, 'payment_method' => 'cash'];
    }

    protected function expensePayload(array $extra = []): array
    {
        return $extra + ['title' => 'ضيافة اجتماع', 'category' => 'ضيافة', 'account_id' => $this->expenseAccount->id, 'amount' => 500, 'expense_date' => '2026-06-30', 'payment_method' => 'cash'];
    }

    protected function createRequest(array $extra = []): PaymentRequest
    {
        $this->post(route('finance.payment-requests.store'), $this->payload($extra))->assertSessionHasNoErrors();

        return PaymentRequest::latest('id')->firstOrFail();
    }

    public function test_a_request_is_numbered_submitted_approved_by_someone_else_and_carried_out(): void
    {
        Notification::fake();
        ApprovalRule::create(['document_type' => 'payment_request', 'min_amount' => 100, 'is_active' => true]);

        $r = $this->createRequest();
        $this->assertSame('draft', $r->status);
        $this->assertSame('PRQ-26-000001', $r->request_number);

        $this->post(route('finance.payment-requests.submit', $r->id))->assertSessionHasNoErrors();
        $this->assertSame('pending_approval', $r->fresh()->status);
        Notification::assertSentTo($this->owner, ErpAlert::class, fn ($n) => $n->kind === 'payment_request');

        // The requester cannot decide his own request; the owner can.
        $this->post(route('finance.payment-requests.approve', $r->id))->assertSessionHasErrors('approval');
        $this->actingAs($this->owner);
        $this->post(route('finance.payment-requests.approve', $r->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $r->fresh()->status);

        // Carry it out: the voucher form is prefilled, saving ties the request to the voucher.
        $this->get(route('finance.expenses.create', ['payment_request' => $r->id]))->assertOk()
            ->assertInertia(fn ($p) => $p->where('prefill.payment_request_id', $r->id)->where('prefill.amount', 500)->where('prefill.title', 'ضيافة اجتماع'));
        $this->post(route('finance.expenses.store'), $this->expensePayload(['payment_request_id' => $r->id]))->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        $r->refresh();
        $this->assertEquals(['paid', $expense->id, $expense->getMorphClass()], [$r->status, $r->paid_id, $r->paid_type]);
        $this->assertNotNull($r->paid_at);

        // Nothing more to do on a paid request.
        $this->get(route('finance.payment-requests.show', $r->id))->assertOk()->assertInertia(fn ($p) => $p->where('executeUrl', null));
        $this->post(route('finance.expenses.store'), $this->expensePayload(['payment_request_id' => $r->id]))->assertSessionHasErrors('payment_request_id');
    }

    public function test_without_a_matching_rule_the_request_is_approved_on_submit_and_a_rejected_one_can_be_reworked(): void
    {
        $r = $this->createRequest();
        $this->post(route('finance.payment-requests.submit', $r->id));
        $this->assertSame('approved', $r->fresh()->status);

        ApprovalRule::create(['document_type' => 'payment_request', 'min_amount' => 100, 'is_active' => true]);
        $second = $this->createRequest(['purpose' => 'إصلاح']);
        $this->post(route('finance.payment-requests.submit', $second->id));
        $this->actingAs($this->owner);
        $this->post(route('finance.payment-requests.reject', $second->id), ['comment' => 'مرتفع'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $second->fresh()->status);

        $this->actingAs($this->clerk);
        $this->put(route('finance.payment-requests.update', $second->id), $this->payload(['purpose' => 'إصلاح مخفض', 'amount' => 200, 'send_for_approval' => true]))->assertSessionHasNoErrors();
        $this->assertEquals(['pending_approval', 200.0], [$second->fresh()->status, (float) $second->fresh()->amount]);
        $this->put(route('finance.payment-requests.update', $r->id), $this->payload())->assertSessionHasErrors('status');   // an approved request is locked
    }

    public function test_the_requests_approval_replaces_the_vouchers_own_approval_gate_but_only_within_what_was_approved(): void
    {
        ApprovalRule::create(['document_type' => 'expense', 'min_amount' => 1, 'is_active' => true]);
        $r = $this->createRequest();

        // Not approved yet, and no request at all: the voucher is stopped by its own gate.
        $this->post(route('finance.expenses.store'), $this->expensePayload(['payment_request_id' => $r->id]))->assertSessionHasErrors('payment_request_id');
        $this->post(route('finance.expenses.store'), $this->expensePayload())->assertSessionHasErrors('approval');
        $this->assertSame(0, Expense::count());

        $this->post(route('finance.payment-requests.submit', $r->id));
        $this->assertSame('approved', $r->fresh()->status);

        $this->post(route('finance.expenses.store'), $this->expensePayload(['payment_request_id' => $r->id, 'amount' => 900]))->assertSessionHasErrors('payment_request_id');   // more than approved
        $this->assertSame(0, Expense::count());
        $this->post(route('finance.expenses.store'), $this->expensePayload(['payment_request_id' => $r->id]))->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count());
        $this->assertSame('paid', $r->fresh()->status);
    }

    public function test_a_supplier_payment_request_is_carried_out_by_the_supplier_payment_voucher(): void
    {
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active', 'current_balance' => 2000]);
        $other = Supplier::create(['name' => 'مورد آخر', 'status' => 'active']);
        $bank = BankAccount::create(['name' => 'الراجحي', 'type' => 'bank', 'currency' => 'SAR', 'opening_balance' => 0, 'current_balance' => 0, 'account_id' => Account::where('code', '1120')->value('id') ?? app(AccountMap::class)->id('default_bank'), 'is_active' => true]);
        $r = $this->createRequest(['kind' => 'supplier_payment', 'payee_type' => 'supplier', 'supplier_id' => $supplier->id, 'account_id' => null, 'amount' => 800, 'bank_account_id' => $bank->id, 'payment_method' => 'bank_transfer']);
        $this->post(route('finance.payment-requests.submit', $r->id));

        $this->get(route('purchases.payments.create', ['payment_request' => $r->id]))->assertOk()
            ->assertInertia(fn ($p) => $p->where('prefill.supplier_id', $supplier->id)->where('prefill.amount', 800));
        $voucher = ['supplier_id' => $other->id, 'amount' => 800, 'payment_date' => '2026-06-30', 'payment_method' => 'bank_transfer', 'payment_request_id' => $r->id];
        $this->post(route('purchases.payments.store'), $voucher)->assertSessionHasErrors('payment_request_id');   // not the requested supplier
        $this->post(route('purchases.payments.store'), ['supplier_id' => $supplier->id] + $voucher)->assertSessionHasNoErrors();

        $payment = SupplierPayment::firstOrFail();
        $this->assertEquals(['paid', $payment->id], [$r->fresh()->status, $r->fresh()->paid_id]);
        $this->assertEquals(1200.0, (float) $supplier->fresh()->current_balance);
    }

    public function test_a_kind_mismatch_is_refused_a_request_can_be_cancelled_and_only_a_draft_deleted(): void
    {
        $r = $this->createRequest();
        $this->post(route('finance.payment-requests.submit', $r->id));
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $this->post(route('purchases.payments.store'), ['supplier_id' => $supplier->id, 'amount' => 500, 'payment_date' => '2026-06-30', 'payment_method' => 'cash', 'payment_request_id' => $r->id])->assertSessionHasErrors('payment_request_id');

        $this->delete(route('finance.payment-requests.destroy', $r->id))->assertForbidden();   // approved, not a draft
        $this->post(route('finance.payment-requests.cancel', $r->id));
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->post(route('finance.expenses.store'), $this->expensePayload(['payment_request_id' => $r->id]))->assertSessionHasErrors('payment_request_id');

        $draft = $this->createRequest(['purpose' => 'مسودة']);
        $this->delete(route('finance.payment-requests.destroy', $draft->id))->assertRedirect(route('finance.payment-requests.index'));
        $this->assertNull(PaymentRequest::find($draft->id));
    }

    public function test_attachments_search_alerts_pages_and_permissions(): void
    {
        $this->actingAs($this->owner);
        $r = $this->createRequest();
        $this->post(route('attachments.store', ['kind' => 'payment-request', 'id' => $r->id]), ['file' => UploadedFile::fake()->create('عرض.pdf', 30, 'application/pdf')])->assertCreated();
        $this->assertSame(1, Attachment::where('attachable_id', $r->id)->count());

        $this->get(route('finance.payment-requests.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Finance/PaymentRequests/Index')->has('requests.data', 1));
        $this->get(route('finance.payment-requests.create'))->assertOk()->assertInertia(fn ($p) => $p->component('Finance/PaymentRequests/Form'));
        $this->get(route('finance.payment-requests.edit', $r->id))->assertOk();
        $this->get(route('finance.payment-requests.show', $r->id))->assertOk()->assertInertia(fn ($p) => $p->component('Finance/PaymentRequests/Show')->where('paymentRequest.request_number', $r->request_number));
        $this->getJson('/search?q=ضيافة')->assertOk();

        // Pending requests show in the approvers' daily digest.
        ApprovalRule::create(['document_type' => 'payment_request', 'min_amount' => 1, 'is_active' => true]);
        $this->post(route('finance.payment-requests.submit', $r->id));
        $kinds = collect(app(AlertService::class)->collect())->pluck('kind');
        $this->assertTrue($kinds->contains('pending_finance.payment-requests.index'));

        // A role without finance permissions sees nothing.
        $this->actingAs($this->userWithRole($this->company, 'hr_manager'));
        $this->get(route('finance.payment-requests.index'))->assertForbidden();
        $this->post(route('finance.payment-requests.store'), $this->payload())->assertForbidden();
        $this->post(route('attachments.store', ['kind' => 'payment-request', 'id' => $r->id]), ['file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')])->assertForbidden();
    }

    public function test_the_approval_flow_report_counts_payment_requests_too(): void
    {
        ApprovalRule::create(['document_type' => 'payment_request', 'min_amount' => 1, 'is_active' => true]);
        $a = $this->createRequest();
        $b = $this->createRequest(['purpose' => 'ثان', 'amount' => 300]);
        $this->post(route('finance.payment-requests.submit', $a->id));
        $this->post(route('finance.payment-requests.submit', $b->id));
        $this->actingAs($this->owner);
        $this->post(route('finance.payment-requests.approve', $b->id));

        $rows = collect(app(AccountingControls::class)->approvalFlow('type', 7, now()))->keyBy('group');
        $this->assertEquals([1, 500.0, 1], [$rows['طلبات الصرف']['pending'], $rows['طلبات الصرف']['pending_amount'], $rows['طلبات الصرف']['approved']]);
        $byApprover = collect(app(AccountingControls::class)->approvalFlow('approver', 7, now()))->keyBy('group');
        $this->assertEquals(1, $byApprover[$this->owner->name]['approved']);
    }
}
