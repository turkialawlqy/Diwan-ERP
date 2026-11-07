<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Accounting\AccountingControls;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** The accounting control reports: exceptions, sub-ledger reconciliation, party balances, cash movement, dormant accounts. */
class AccountingControlsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active', 'credit_limit' => 1000]);
        $this->invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10', 'due_date' => '2026-06-20'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false, 'check_credit' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function sheet(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ctl').'.xlsx';
        file_put_contents($path, $content);
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        unlink($path);

        return $rows;
    }

    public function test_exceptions_list_drafts_missing_references_and_unbalanced_entries(): void
    {
        $map = app(AccountMap::class);
        app(PostingService::class)->post(['date' => '2026-06-01', 'description' => 'مسودة قديمة', 'entry_type' => 'manual', 'status' => 'draft', 'lines' => [
            ['account_id' => $map->id('default_cash'), 'debit' => 10], ['account_id' => $map->id('general_expense'), 'credit' => 10],
        ]]);
        app(PostingService::class)->post(['date' => '2026-06-05', 'description' => 'قيد بلا مرجع', 'entry_type' => 'manual', 'lines' => [
            ['account_id' => $map->id('default_cash'), 'debit' => 20], ['account_id' => $map->id('general_expense'), 'credit' => 20],
        ]]);
        $broken = app(PostingService::class)->post(['date' => '2026-06-06', 'description' => 'قيد معطوب', 'entry_type' => 'manual', 'reference' => 'X', 'lines' => [
            ['account_id' => $map->id('default_cash'), 'debit' => 30], ['account_id' => $map->id('general_expense'), 'credit' => 30],
        ]]);
        $broken->forceFill(['total_credit' => 5])->saveQuietly();   // simulate corruption

        $sections = app(AccountingControls::class)->exceptions(now());
        $this->assertSame(['مسودة قديمة'], array_column($sections['draft']['rows'], 'description'));
        $this->assertSame(29, $sections['draft']['rows'][0]['age']);                       // 1 June → 30 June
        $this->assertSame(['قيد بلا مرجع'], array_column($sections['no_reference']['rows'], 'description'));
        $this->assertSame([$broken->entry_number], array_column($sections['unbalanced']['rows'], 'reference'));

        $this->get(route('accounting.controls.show', 'controls-exceptions'))->assertOk()->assertInertia(fn ($p) => $p->component('Accounting/Controls/Show')->has('table.rows'));
    }

    public function test_party_reconciliation_shows_the_control_account_and_parties_that_disagree(): void
    {
        $this->customer->forceFill(['current_balance' => 900])->saveQuietly();   // the ledger says 1,150

        $r = app(AccountingControls::class)->partyReconciliation();
        $customers = collect($r['control'])->firstWhere('label', 'العملاء');
        $this->assertEquals([1150, 1150, 0], [$customers['control'], $customers['tagged'], $customers['untagged']]);
        $this->assertSame([-250.0], array_column($r['parties'], 'difference'));
    }

    public function test_customer_balances_carry_opening_movement_credit_limit_and_unapplied_receipts(): void
    {
        $payment = CustomerPayment::create(['customer_id' => $this->customer->id, 'payment_date' => '2026-06-25', 'amount' => 300, 'payment_method' => 'cash', 'payment_number' => 'RCT-1',
            'status' => 'completed', 'currency_code' => null, 'exchange_rate' => 1]);

        $rows = app(AccountingControls::class)->partyBalances('customer', Carbon::parse('2026-06-15'), Carbon::parse('2026-06-30'));
        $this->assertCount(1, $rows);
        $this->assertEquals([1150, 0, 0, 1150, 1000, true, 300], [$rows[0]['opening'], $rows[0]['debit'], $rows[0]['credit'], $rows[0]['closing'], $rows[0]['limit'], $rows[0]['over_limit'], $rows[0]['unapplied']]);
        $this->assertNotNull($payment->id);

        $before = app(AccountingControls::class)->partyBalances('customer', Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        $this->assertEquals([0, 1150], [$before[0]['opening'], $before[0]['debit']]);
    }

    public function test_cash_movement_lists_each_box_and_ages_unreconciled_bank_lines(): void
    {
        $map = app(AccountMap::class);
        $bank = BankAccount::create(['name' => 'الراجحي', 'type' => 'bank', 'currency' => 'SAR', 'opening_balance' => 0, 'current_balance' => 0, 'account_id' => $map->id('default_bank'), 'is_active' => true]);
        app(PostingService::class)->post(['date' => '2026-04-01', 'description' => 'إيداع قديم', 'entry_type' => 'manual', 'reference' => 'D1', 'lines' => [
            ['account_id' => $map->id('default_bank'), 'debit' => 500], ['account_id' => $map->id('general_expense'), 'credit' => 500],
        ]]);
        app(PostingService::class)->post(['date' => '2026-06-20', 'description' => 'إيداع حديث', 'entry_type' => 'manual', 'reference' => 'D2', 'lines' => [
            ['account_id' => $map->id('default_bank'), 'debit' => 200], ['account_id' => $map->id('general_expense'), 'credit' => 200],
        ]]);

        $d = app(AccountingControls::class)->cashMovement(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        $row = collect($d['accounts'])->firstWhere('name', 'الراجحي');
        $this->assertEquals([500, 200, 0, 700], [$row['opening'], $row['in'], $row['out'], $row['closing']]);
        $buckets = collect($d['unreconciled'])->mapWithKeys(fn ($u) => [$u['label'] => [$u['count'], $u['net']]]);
        $this->assertEquals([1, 200], $buckets['0-30 يوم']);       // 20 June deposit, 10 days old
        $this->assertEquals([1, 500], $buckets['61-90 يوم']);      // 1 April deposit, exactly 90 days old
        $this->assertEquals([0, 0], $buckets['أكثر من 90 يوم']);
        $this->assertNotNull($bank->id);
    }

    public function test_dormant_accounts_are_active_leaf_accounts_without_recent_movement(): void
    {
        $map = app(AccountMap::class);
        $make = fn ($code, $name) => Account::create(['code' => $code, 'name_ar' => $name, 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'is_system' => false]);
        $used = $make('59001', 'مصروف مستعمل');
        $idle = $make('59002', 'مصروف خامل');
        app(PostingService::class)->post(['date' => '2026-06-20', 'description' => 'استعمال', 'entry_type' => 'manual', 'reference' => 'U1', 'lines' => [
            ['account_id' => $used->id, 'debit' => 15], ['account_id' => $map->id('default_cash'), 'credit' => 15],
        ]]);

        $codes = array_column(app(AccountingControls::class)->dormantAccounts(30, now()), 'code');
        $this->assertContains('59002', $codes);
        $this->assertNotContains('59001', $codes);

        $later = collect(app(AccountingControls::class)->dormantAccounts(30, now()->addDays(40)))->firstWhere('code', '59001');
        $this->assertSame(['2026-06-20', 15.0], [$later['last'], $later['balance']]);
        $this->assertSame('لا حركة', collect(app(AccountingControls::class)->dormantAccounts(30, now()))->firstWhere('code', '59002')['last']);
    }

    public function test_every_report_opens_and_exports_with_the_control_permission(): void
    {
        $this->get(route('accounting.controls.index'))->assertOk()->assertInertia(fn ($p) => $p->has('reports', 6));
        foreach (['controls-exceptions', 'party-reconciliation', 'customer-balances', 'supplier-balances', 'cash-movement', 'dormant-accounts'] as $key) {
            $this->get(route('accounting.controls.show', $key))->assertOk();
            $rows = $this->sheet($this->get(route('reports.export', ['report' => $key, 'format' => 'xlsx']))->assertOk()->getContent());
            $this->assertNotEmpty($rows, $key);
        }
        $this->get(route('accounting.controls.show', 'nonsense'))->assertNotFound();

        $this->actingAs($this->userWithRole($this->company, 'hr_manager'));
        $this->get(route('accounting.controls.index'))->assertForbidden();
        $this->get(route('reports.export', ['report' => 'party-reconciliation', 'format' => 'xlsx']))->assertForbidden();
    }
}
