<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Employee;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\RecurringJournal;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\ChequeService;
use App\Services\Accounting\RecurringJournalRunner;
use App\Services\HR\EmployeeFinanceService;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * As-of aging from the ledger, cheques against invoices, recurring journal
 * isolation and auto-reversal, annual leave provision.
 */
class ProvisionsAgingRecurringTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function role(string $role): int
    {
        return app(AccountMap::class)->id($role, $this->company->id);
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find($this->role($role))->balance, 2);
    }

    protected function invoice(string $date, float $price, ?string $due = null)
    {
        return app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => $date, 'due_date' => $due ?? $date],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    public function test_aging_as_of_a_past_date_matches_the_ledger_then(): void
    {
        $this->invoice('2026-03-01', 1000);          // 1,150 due 1 March
        $this->invoice('2026-05-20', 2000);          // 2,300 due 20 May
        // Paid in June: the March aging must still show both invoices open.
        $this->post(route('sales.payments.store'), ['customer_id' => $this->customer->id, 'amount' => 1150, 'payment_date' => '2026-06-10', 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        $may = app(AccountingReports::class)->aging('receivables', Carbon::parse('2026-05-31'));
        $this->assertSame(3450.0, $may['totals']['total']);
        $this->assertSame([2300.0, 1150.0], [$may['totals']['d30'], $may['totals']['d90plus']]); // 11 days, 91 days

        $june = app(AccountingReports::class)->aging('receivables', Carbon::parse('2026-06-30'));
        $this->assertSame(2300.0, $june['totals']['total']); // equal to the receivables account
        $this->assertSame($this->balance('receivables'), $june['totals']['total']);
        $this->assertSame(2300.0, $june['totals']['d60']); // the oldest invoice is taken as paid first
    }

    public function test_cheque_against_an_invoice_pays_it_and_a_bounced_voucher_cheque_reopens_it(): void
    {
        $first = $this->invoice('2026-06-01', 1000);
        $cheque = app(ChequeService::class)->register(['direction' => 'incoming', 'cheque_number' => 'C1', 'party_type' => 'customer', 'party_id' => $this->customer->id,
            'party_name' => 'عميل', 'amount' => 1150, 'issue_date' => '2026-06-05', 'due_date' => '2026-07-05', 'invoice_id' => $first->id]);
        $this->assertSame('paid', $first->fresh()->payment_status);
        app(ChequeService::class)->transition($cheque, 'bounced', Carbon::parse('2026-06-20'));
        $this->assertSame([1150.0, 'unpaid'], [(float) $first->fresh()->remaining_amount, $first->fresh()->payment_status]);

        // A cheque received through a receipt voucher: the bounce undoes the voucher.
        $second = $this->invoice('2026-06-02', 2000);
        $this->post(route('sales.payments.store'), ['customer_id' => $this->customer->id, 'invoice_id' => $second->id, 'amount' => 2300,
            'payment_date' => '2026-06-06', 'payment_method' => 'cheque', 'reference_number' => 'C2'])->assertSessionHasNoErrors();
        $voucherCheque = Cheque::where('cheque_number', 'C2')->first();
        app(ChequeService::class)->transition($voucherCheque, 'deposited', Carbon::parse('2026-06-07'));
        app(ChequeService::class)->transition($voucherCheque->fresh(), 'bounced', Carbon::parse('2026-06-21'));

        $this->assertSame('bounced', $voucherCheque->fresh()->status);
        $this->assertSame('cancelled', CustomerPayment::where('invoice_id', $second->id)->value('status'));
        $this->assertSame('unpaid', $second->fresh()->payment_status);
        $this->assertSame(3450.0, $this->balance('receivables'));
        $this->assertSame(0.0, $this->balance('cheques_under_collection'));
    }

    public function test_a_failing_recurring_template_does_not_stop_the_others_and_accruals_reverse(): void
    {
        $lines = [['account_id' => $this->role('rent_expense'), 'debit' => 3000, 'credit' => 0], ['account_id' => $this->role('accrued_expenses'), 'debit' => 0, 'credit' => 3000]];
        $broken = RecurringJournal::create(['name' => 'معطل', 'description' => 'قيد على فترة مقفلة', 'lines' => $lines, 'frequency' => 'monthly', 'next_run_date' => '2026-01-31', 'is_active' => true]);
        $accrual = RecurringJournal::create(['name' => 'إيجار مستحق', 'description' => 'استحقاق إيجار', 'lines' => $lines, 'frequency' => 'monthly', 'next_run_date' => '2026-06-30', 'is_active' => true, 'auto_reverse' => true]);
        FiscalPeriod::whereDate('start_date', '2026-01-01')->update(['status' => 'locked']);

        $this->assertSame(1, app(RecurringJournalRunner::class)->runDue());

        $this->assertNotNull($broken->fresh()->last_error);
        $this->assertSame('2026-01-31', $broken->fresh()->next_run_date->toDateString());
        $entry = JournalEntry::where('source_type', $accrual->getMorphClass())->where('source_id', $accrual->id)->whereNull('reversal_of_id')->first();
        $this->assertSame('reversed', $entry->status);
        $this->assertSame('2026-07-01', JournalEntry::where('reversal_of_id', $entry->id)->value('date')->toDateString());
        $this->assertSame('2026-07-30', $accrual->fresh()->next_run_date->toDateString());
    }

    public function test_annual_leave_provision_follows_the_unused_balance(): void
    {
        Employee::create(['name' => 'أحمد', 'job_title' => 'محاسب', 'department' => 'المالية', 'hire_date' => '2025-07-01', 'nationality' => 'SA',
            'basic_salary' => 6000, 'housing_allowance' => 3000, 'transport_allowance' => 0, 'other_allowances' => 0, 'total_salary' => 9000,
            'status' => 'active', 'employee_number' => 'E1', 'annual_leave_days' => 21]);
        $finance = app(EmployeeFinanceService::class);

        // One year of service: 21 days x 9,000 / 30 = 6,300.
        $this->assertSame(6300.0, $finance->accrueLeaveProvision(Carbon::parse('2026-07-01')));
        $this->assertSame(6300.0, $this->balance('leave_provision'));
        $this->assertSame(0.0, $finance->accrueLeaveProvision(Carbon::parse('2026-07-01'))); // nothing new
        $totals = DB::table('account_balances')->where('company_id', $this->company->id)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertEqualsWithDelta((float) $totals->d, (float) $totals->c, 0.001);
    }
}
