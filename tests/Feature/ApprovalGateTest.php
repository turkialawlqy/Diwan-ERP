<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use App\Services\Workflow\ApprovalGate;
use App\Services\Workflow\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Actions without a status of their own (payments, expenses, journals...) wait for an approver when a rule applies. */
class ApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_supplier_payment_over_the_limit_waits_for_an_approver_and_then_passes_once(): void
    {
        ApprovalRule::create(['document_type' => 'supplier_payment', 'min_amount' => 1000, 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $payload = ['supplier_id' => $supplier->id, 'amount' => 5000, 'payment_date' => '2026-06-20', 'payment_method' => 'cash'];

        // Below the limit: no approval.
        $this->post(route('purchases.payments.store'), ['amount' => 500] + $payload)->assertSessionHasNoErrors();

        // Over it: refused, and a request is raised (once).
        $this->post(route('purchases.payments.store'), $payload)->assertSessionHasErrors('approval');
        $this->post(route('purchases.payments.store'), $payload)->assertSessionHasErrors('approval');
        $this->assertSame(1, SupplierPayment::count());
        $this->assertSame(1, ApprovalRequest::where('status', 'pending')->count());

        // Another approver accepts; the same payment now goes through, and only once.
        $request = ApprovalRequest::firstOrFail();
        $request->update(['requested_by' => User::factory()->create(['company_id' => $this->company->id])->id]);   // not the owner's own request
        $this->post(route('approval-requests.decide', ['approvalRequest' => $request->id, 'decision' => 'approve']))->assertSessionHasNoErrors();
        $this->post(route('purchases.payments.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, SupplierPayment::count());
        $this->post(route('purchases.payments.store'), $payload)->assertSessionHasErrors('approval');   // the approval was consumed
    }

    public function test_every_type_is_listed_and_a_rejected_request_does_not_pass(): void
    {
        foreach (['supplier_payment', 'expense', 'credit_note', 'journal', 'stock_count', 'payroll', 'loan', 'settlement', 'progress_billing'] as $type) {
            $this->assertArrayHasKey($type, ApprovalService::DOCUMENT_TYPES);
            $this->post(route('approval-rules.store'), ['document_type' => $type, 'min_amount' => 0, 'is_active' => true])->assertSessionHasNoErrors();
        }

        $this->post(route('finance.expenses.store'), ['title' => 'صيانة', 'category' => 'صيانة', 'amount' => 300, 'expense_date' => '2026-06-10', 'payment_method' => 'cash'])->assertSessionHasErrors('approval');
        $request = ApprovalRequest::where('document_type', 'expense')->firstOrFail();
        $request->update(['requested_by' => User::factory()->create(['company_id' => $this->company->id])->id]);
        $this->post(route('approval-requests.decide', ['approvalRequest' => $request->id, 'decision' => 'reject']))->assertSessionHasNoErrors();
        $this->post(route('finance.expenses.store'), ['title' => 'صيانة', 'category' => 'صيانة', 'amount' => 300, 'expense_date' => '2026-06-10', 'payment_method' => 'cash'])->assertSessionHasErrors('approval');

        // The page lists what waits.
        $this->get(route('approval-rules.index'))->assertOk()->assertInertia(fn ($p) => $p->has('requests'));

        $gate = app(ApprovalGate::class);
        $this->assertNotSame(ApprovalGate::keyFor(['a' => 1]), ApprovalGate::keyFor(['a' => 2]));
    }
}
