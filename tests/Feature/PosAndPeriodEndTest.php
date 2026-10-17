<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PeriodEndService;
use App\Services\Accounting\PostingService;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POS shift cash (float, split tender, over/short, deposit), month-end FX
 * revaluation and the zakat provision.
 */
class PosAndPeriodEndTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-05 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
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

    protected function assertLedgerBalanced(): void
    {
        $totals = DB::table('account_balances')->where('company_id', $this->company->id)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertEqualsWithDelta((float) $totals->d, (float) $totals->c, 0.001);
    }

    public function test_pos_shift_float_split_tender_shortage_and_deposit(): void
    {
        $drawer = BankAccount::create(['name' => 'درج الكاشير', 'type' => 'cash', 'currency' => 'SAR', 'account_id' => $this->role('petty_cash'), 'current_balance' => 0, 'is_active' => true]);
        $treasury = BankAccount::create(['name' => 'الخزينة', 'type' => 'cash', 'currency' => 'SAR', 'account_id' => $this->role('default_cash'), 'current_balance' => 0, 'is_active' => true]);
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $register = PosRegister::create(['name' => 'كاشير 1', 'warehouse_id' => $warehouse->id, 'bank_account_id' => $drawer->id, 'is_active' => true]);
        $product = Product::create(['name_ar' => 'خدمة', 'sku' => 'P1', 'selling_price' => 1000, 'track_inventory' => false, 'is_active' => true]);

        $this->post(route('pos.session.open'), ['pos_register_id' => $register->id, 'opening_cash' => 500, 'float_bank_account_id' => $treasury->id])->assertSessionHasNoErrors();
        $session = PosSession::latest('id')->first();
        $this->assertSame(500.0, $this->balance('petty_cash'));

        // 1,150 paid 1,000 by card and 200 cash: 50 change.
        $this->post(route('pos.order.process'), ['session_id' => $session->id, 'payment_method' => 'multiple', 'paid_amount' => 1200, 'cash_amount' => 200, 'card_amount' => 1000,
            'items' => [['product_id' => $product->id, 'item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000]]])->assertSessionHasNoErrors();
        $this->assertSame(1000.0, $this->balance('default_bank'));
        $this->assertSame(650.0, $this->balance('petty_cash'));

        // Card part above the total is refused.
        $this->post(route('pos.order.process'), ['session_id' => $session->id, 'payment_method' => 'multiple', 'paid_amount' => 1200, 'cash_amount' => 0, 'card_amount' => 1200,
            'items' => [['product_id' => $product->id, 'item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000]]])->assertSessionHasErrors('card_amount');

        // 640 counted against 650 expected: 10 short; the counted cash goes back to the treasury.
        $this->post(route('pos.session.close', $session), ['closing_cash' => 640, 'deposit_bank_account_id' => $treasury->id])->assertSessionHasNoErrors();
        $session->refresh();
        $this->assertSame([650.0, -10.0], [(float) $session->expected_cash, (float) $session->difference]);
        $this->assertSame(10.0, $this->balance('cash_over_short'));
        $this->assertSame(0.0, $this->balance('petty_cash'));
        $this->assertSame(140.0, $this->balance('default_cash')); // -500 float + 640 deposit
        $this->assertLedgerBalanced();
    }

    protected function usd(): void
    {
        Currency::create(['code' => 'SAR', 'name_ar' => 'ريال', 'name_en' => 'Riyal', 'symbol_ar' => 'ر.س', 'symbol_en' => 'SAR', 'exchange_rate' => 1, 'is_default' => true]);
        Currency::create(['code' => 'USD', 'name_ar' => 'دولار', 'name_en' => 'Dollar', 'symbol_ar' => '$', 'symbol_en' => '$', 'exchange_rate' => 3.75]);
    }

    public function test_month_end_revaluation_is_posted_and_reversed_next_month(): void
    {
        $this->usd();
        $customer = Customer::create(['name' => 'عميل خارجي', 'customer_type' => 'commercial', 'status' => 'active']);
        app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-10', 'payment_status' => 'unpaid', 'payment_method' => 'credit', 'currency_code' => 'USD', 'exchange_rate' => 3.75],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
        ExchangeRate::create(['currency_code' => 'USD', 'date' => '2026-06-30', 'rate' => 3.80]);

        $preview = $this->get(route('accounting.period-end.index', ['month' => '2026-06']))->assertOk()->viewData('page')['props']['fx'];
        $this->assertSame(57.5, $preview['net_gain']); // 1,150 USD x (3.80 - 3.75)

        $this->post(route('accounting.period-end.revalue'), ['month' => '2026-06'])->assertSessionHasNoErrors();
        $entry = JournalEntry::where('source_type', 'fx_revaluation')->whereNull('reversal_of_id')->first();
        $this->assertSame('2026-06-30', $entry->date->toDateString());
        $this->assertSame(57.5, (float) $entry->total_debit);
        $this->assertSame('2026-07-01', JournalEntry::where('reversal_of_id', $entry->id)->first()->date->toDateString());
        $this->assertSame(0.0, $this->balance('fx_difference')); // reversed: the realised difference is booked on payment
        $this->assertSame(4312.5, $this->balance('receivables'));

        // June's balance sheet shows the receivable at the closing rate.
        $june = app(AccountingReports::class)->totalsBetween(null, Carbon::parse('2026-06-30'), [$this->role('receivables')]);
        $this->assertSame(4370.0, round($june[$this->role('receivables')]['debit'] - $june[$this->role('receivables')]['credit'], 2));

        $this->post(route('accounting.period-end.revalue'), ['month' => '2026-06'])->assertSessionHasErrors('month');
        $this->post(route('accounting.period-end.revalue'), ['month' => '2026-07'])->assertSessionHasErrors('month'); // month not over
    }

    public function test_zakat_base_by_net_assets_and_provision(): void
    {
        $posting = app(PostingService::class);
        $posting->post(['date' => '2026-01-01', 'description' => 'رأس المال', 'lines' => [['role' => 'default_bank', 'debit' => 100000], ['role' => 'capital', 'credit' => 100000]]]);
        $posting->post(['date' => '2026-02-01', 'description' => 'إيراد', 'lines' => [['role' => 'default_bank', 'debit' => 20000], ['role' => 'other_revenue', 'credit' => 20000]]]);
        $posting->post(['date' => '2026-03-01', 'description' => 'شراء أصل', 'lines' => [['role' => 'fixed_assets', 'debit' => 30000], ['role' => 'default_bank', 'credit' => 30000]]]);
        $posting->post(['date' => '2026-04-01', 'description' => 'قرض', 'lines' => [['role' => 'default_bank', 'debit' => 10000], ['role' => 'long_term_loans', 'credit' => 10000]]]);
        $year = FiscalYear::whereYear('start_date', 2026)->firstOrFail();

        $zakat = app(PeriodEndService::class)->zakatPreview($year);
        // 100,000 equity + 20,000 profit + 10,000 long-term loan - 30,000 fixed assets.
        $this->assertSame([100000.0, 20000.0, 10000.0, 30000.0, 100000.0], [$zakat['equity'], $zakat['adjusted_profit'], $zakat['long_term_liabilities'], $zakat['fixed_assets'], $zakat['base']]);
        $this->assertSame(2577.6, $zakat['amount']); // Gregorian year 2.5776%
        $this->assertSame(1250.0, app(PeriodEndService::class)->zakatPreview($year, ['calendar' => 'hijri', 'saudi_share' => 50])['amount']);

        $this->post(route('accounting.period-end.zakat'), ['fiscal_year_id' => $year->id])->assertSessionHasNoErrors();
        $this->assertSame(2577.6, $this->balance('zakat_payable'));
        $this->post(route('accounting.period-end.zakat'), ['fiscal_year_id' => $year->id])->assertSessionHasErrors('year');

        // Zakat booked as an expense is added back, so the base does not shrink.
        $this->assertSame(100000.0, app(PeriodEndService::class)->zakatPreview($year)['base']);
    }
}
