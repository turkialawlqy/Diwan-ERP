<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** «كشف حساب مجمع»: several accounts in one statement that agrees with the single-account ledgers. */
class ConsolidatedStatementTest extends TestCase
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
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-10', 'due_date' => '2026-06-10'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /** Leaf accounts that carry lines in the invoice entry. */
    protected function usedAccounts(): Collection
    {
        return Account::whereIn('id', \DB::table('journal_entry_lines')->where('company_id', $this->company->id)->pluck('account_id')->unique())->where('is_parent', false)->get();
    }

    public function test_it_adds_up_the_single_account_ledgers(): void
    {
        $accounts = $this->usedAccounts();
        $this->assertGreaterThanOrEqual(2, $accounts->count());
        $reports = app(AccountingReports::class);
        $from = Carbon::parse('2026-06-01');
        $to = Carbon::parse('2026-06-30');

        $both = $reports->consolidated($accounts->pluck('id')->all(), $from, $to);
        $debit = $credit = 0;
        foreach ($accounts as $a) {
            $l = $reports->ledger($a, $from, $to);
            $debit += $l['period_debit'];
            $credit += $l['period_credit'];
        }

        $this->assertEqualsWithDelta($debit, $both['period_debit'], 0.001);
        $this->assertEqualsWithDelta($credit, $both['period_credit'], 0.001);
        $this->assertCount($accounts->count(), $both['accounts']);
        // The whole invoice entry is balanced, so everything nets to zero.
        $this->assertEqualsWithDelta($both['opening_balance'], $both['closing_balance'], 0.001);
        $this->assertGreaterThanOrEqual($accounts->count(), $both['lines']->total());
    }

    public function test_a_parent_account_stands_for_every_leaf_below_it(): void
    {
        $reports = app(AccountingReports::class);
        $parent = Account::where('is_parent', true)->get()->first(fn ($p) => Account::where('parent_id', $p->id)->exists());
        $this->assertNotNull($parent);
        $leaves = $reports->leafIdsUnder([$parent->id]);

        $this->assertNotEmpty($leaves);
        $this->assertNotContains($parent->id, $leaves);
        foreach ($leaves as $id) {
            $this->assertFalse((bool) Account::find($id)->is_parent);
        }
    }

    public function test_the_page_and_the_export_work_and_are_permissioned(): void
    {
        $ids = $this->usedAccounts()->pluck('id')->take(2)->all();

        $this->get(route('accounting.statements.consolidated', ['account_ids' => $ids, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Accounting/Statements/Consolidated')->has('statement.accounts', 2));

        $this->get(route('accounting.statements.consolidated'))->assertOk()->assertInertia(fn ($p) => $p->where('statement', null));

        $response = $this->get(route('reports.export', ['report' => 'consolidated-statement', 'format' => 'xlsx', 'account_ids' => $ids, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'cs').'.xlsx';
        file_put_contents($path, $response->getContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        unlink($path);
        $this->assertNotEmpty(collect($rows)->firstWhere(2, 'الإجمالي'));

        // Another company's account is refused.
        $other = Company::factory()->create();
        $foreign = app(TenantManager::class)->runAs($other, fn () => Account::create(['code' => '9999', 'name_ar' => 'x', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false]));
        $this->get(route('accounting.statements.consolidated', ['account_ids' => [$foreign->id]]))->assertSessionHasErrors('account_ids.0');

        $this->actingAs($this->userWithRole($this->company, 'sales'));
        $this->get(route('accounting.statements.consolidated'))->assertForbidden();
    }
}
