<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Supplier;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\PartyBalanceReconciler;
use App\Services\Accounting\YearEndClosing;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Medium-severity audit fixes: guards against orphaned or inconsistent records. */
class MediumFixesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_customer_or_supplier_with_a_balance_cannot_be_deleted(): void
    {
        $customer = Customer::create(['name' => 'عميل برصيد', 'customer_type' => 'commercial', 'status' => 'active', 'opening_balance' => 500]);
        $this->delete(route('sales.clients.destroy', $customer))->assertSessionHas('error');
        $this->assertNotNull($customer->fresh());

        $supplier = Supplier::create(['name' => 'مورد برصيد', 'status' => 'active', 'opening_balance' => 300]);
        $this->delete(route('purchases.suppliers.destroy', $supplier))->assertSessionHas('error');
        $this->assertNotNull($supplier->fresh());

        $clean = Customer::create(['name' => 'عميل نظيف', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->delete(route('sales.clients.destroy', $clean))->assertSessionHas('success');
        $this->assertNull(Customer::find($clean->id));
    }

    public function test_a_draft_credit_note_can_be_discarded_but_an_issued_one_cannot(): void
    {
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        $invoice = app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-01', 'due_date' => '2026-06-01'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);

        $base = ['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => '2026-06-20', 'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'reason' => 'خصم'];
        $this->post(route('sales.returns.store'), $base + ['status' => 'draft'])->assertSessionHasNoErrors();
        $draft = CreditNote::firstOrFail();
        $this->delete(route('sales.returns.destroy', $draft))->assertSessionHasNoErrors();
        $this->assertSame(0, CreditNote::count());

        $this->post(route('sales.returns.store'), $base + ['status' => 'approved'])->assertSessionHasNoErrors();
        $this->delete(route('sales.returns.destroy', CreditNote::firstOrFail()))->assertSessionHasErrors('status');
        $this->assertSame(1, CreditNote::count());
    }

    public function test_a_year_cannot_be_reopened_while_a_later_year_is_closed(): void
    {
        $year = FiscalYear::firstOrFail();
        $year->update(['status' => 'closed']);
        FiscalYear::create(['company_id' => $this->company->id, 'name' => 'اللاحقة', 'start_date' => $year->end_date->copy()->addDay(), 'end_date' => $year->end_date->copy()->addYear(), 'period_type' => 'monthly', 'status' => 'closed']);

        $this->assertThrows(fn () => app(YearEndClosing::class)->reopen($year->fresh()), AccountingException::class);
        $this->assertTrue($year->fresh()->isClosed());
    }

    public function test_a_cheque_receipt_can_carry_a_later_due_date(): void
    {
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        $base = ['customer_id' => $customer->id, 'amount' => 500, 'payment_date' => '2026-06-30', 'payment_method' => 'cheque', 'reference_number' => 'CH-1'];

        $this->post(route('sales.payments.store'), $base + ['cheque_due_date' => '2026-06-01'])->assertSessionHasErrors('cheque_due_date');
        $this->post(route('sales.payments.store'), $base + ['cheque_due_date' => '2026-09-15'])->assertSessionHasNoErrors();
        $this->assertSame('2026-09-15', Cheque::firstOrFail()->due_date->toDateString());
    }

    public function test_stored_party_balances_are_compared_with_the_ledger_and_can_be_fixed(): void
    {
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-01', 'due_date' => '2026-06-01'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
        $customer->forceFill(['current_balance' => 40])->saveQuietly();           // drifted

        $reconciler = app(PartyBalanceReconciler::class);
        $rows = $reconciler->differences($this->company->id);
        $this->assertSame([40.0, 1150.0], [$rows[0]['stored'], $rows[0]['ledger']]);
        $this->assertEquals(40, $customer->fresh()->current_balance);

        $reconciler->differences($this->company->id, true);
        $this->assertEquals(1150, $customer->fresh()->current_balance);
        $this->assertSame([], $reconciler->differences($this->company->id));
    }
}
