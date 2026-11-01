<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\ActiveBranch;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The insights page and the dashboard cache their figures for a few minutes.
 * The figures follow the branch scope (the branch chosen in «سياق العمل», or
 * every branch the user may use under «كل الفروع»), so each scope has its own
 * cache: nobody gets figures cached for branches they may not see, nor the
 * previous branch's figures after switching.
 */
class InsightsBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Branch $riyadh;

    protected Branch $jeddah;

    /** @var array<int, Invoice> the overdue invoice of each branch, by branch id */
    protected array $invoices = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create(['currency' => 'SAR', 'country_code' => 'SA', 'tax_number' => '310122393500003']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->jeddah = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);

        // Each branch: a customer with an overdue invoice (aging, cash forecast, budget actual) and a project.
        foreach ([[$this->riyadh, 1000], [$this->jeddah, 3000]] as [$branch, $amount]) {
            $customer = Customer::create(['name' => 'عميل '.$branch->name_ar, 'customer_type' => 'individual', 'status' => 'active']);
            $this->invoices[$branch->id] = $this->invoice($customer, $branch, '2026-03-01', $amount);
            Project::create(['customer_id' => $customer->id, 'branch_id' => $branch->id, 'code' => 'P-'.$branch->code, 'name' => 'مشروع '.$branch->name_ar, 'status' => 'active']);
        }

        $year = FiscalYear::whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->firstOrFail();
        $budget = Budget::create(['fiscal_year_id' => $year->id, 'name' => 'موازنة 2026', 'status' => 'approved']);
        BudgetLine::create(['budget_id' => $budget->id, 'account_id' => app(AccountMap::class)->id('sales_revenue'), 'period' => 202603, 'amount' => 5000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /** A credit invoice due on its date: overdue from the next day. */
    protected function invoice(Customer $customer, Branch $branch, string $date, float $amount): Invoice
    {
        return app(InvoiceService::class)->create(
            ['customer_id' => $customer->id, 'branch_id' => $branch->id, 'invoice_date' => $date, 'due_date' => $date, 'payment_method' => 'credit', 'payment_status' => 'unpaid'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    /** An accountant (may open the insights page) limited to these branches. */
    protected function accountant(Branch ...$branches): User
    {
        $user = $this->userWithRole($this->company, 'accountant', ['branch_id' => $branches[0]->id]);
        $user->branches()->sync(collect($branches)->pluck('id'));

        return $user->fresh();
    }

    /** The insights page shows these branches' figures, and no other branch's. */
    protected function assertInsightsCover(Branch ...$branches): void
    {
        $props = $this->get(route('reports.insights'))->assertOk()->viewData('page')['props'];
        $invoices = collect($branches)->map(fn (Branch $b) => $this->invoices[$b->id]);
        $names = fn (string $prefix) => collect($branches)->map(fn (Branch $b) => $prefix.$b->name_ar)->sort()->values()->all();
        $sorted = fn (array $rows) => collect($rows)->pluck('name')->sort()->values()->all();
        $scope = implode(' + ', $names(''));

        $this->assertEqualsWithDelta($invoices->sum('total'), $props['aging']['receivables']['totals']['total'], 0.01, "aging total: {$scope}");
        $this->assertSame($names('عميل '), $sorted($props['aging']['receivables']['top']), "aging parties: {$scope}");
        $this->assertEqualsWithDelta($invoices->sum('subtotal'), $props['budget']['totals']['revenue']['actual'], 0.01, "budget actual: {$scope}");
        $this->assertEqualsWithDelta($invoices->sum('total'), $props['cash']['overdue']['receivables'], 0.01, "cash forecast: {$scope}");
        $this->assertSame($names('مشروع '), $sorted($props['profitability']['projects']), "projects: {$scope}");
    }

    public function test_a_branch_user_does_not_get_the_figures_cached_for_all_branches(): void
    {
        $this->post(route('context.update'), ['branch_id' => 'all'])->assertSessionHasNoErrors();
        $this->assertInsightsCover($this->riyadh, $this->jeddah);

        $this->actingAs($this->accountant($this->riyadh));
        $this->assertInsightsCover($this->riyadh);
    }

    public function test_the_owner_does_not_get_the_figures_cached_for_one_branch(): void
    {
        $this->actingAs($this->accountant($this->riyadh));
        $this->assertInsightsCover($this->riyadh);

        $this->actingAs($this->owner);
        $this->post(route('context.update'), ['branch_id' => 'all'])->assertSessionHasNoErrors();
        $this->assertInsightsCover($this->riyadh, $this->jeddah);
    }

    public function test_switching_branch_shows_the_new_branch_at_once(): void
    {
        $this->assertInsightsCover($this->riyadh); // the main branch until another is chosen

        $this->post(route('context.update'), ['branch_id' => $this->jeddah->id])->assertSessionHasNoErrors();
        $this->assertInsightsCover($this->jeddah);

        $this->post(route('context.update'), ['branch_id' => 'all'])->assertSessionHasNoErrors();
        $this->assertInsightsCover($this->riyadh, $this->jeddah);
    }

    public function test_the_dashboard_keeps_each_scope_apart(): void
    {
        // A third branch with this month's only sale: "all branches" of a user given
        // Riyadh and Jeddah is not the owner's "all branches".
        $dammam = Branch::create(['name_ar' => 'الدمام', 'code' => 'DMM', 'is_active' => true]);
        app(ActiveBranch::class)->flush();
        $sale = $this->invoice(Customer::create(['name' => 'عميل الدمام', 'customer_type' => 'individual', 'status' => 'active']), $dammam, '2026-06-01', 500);
        $dashboard = fn () => $this->get(route('dashboard'))->assertOk()->viewData('page')['props'];
        $overdue = fn (Invoice ...$invoices) => collect($invoices)->sum('total');

        $this->post(route('context.update'), ['branch_id' => 'all'])->assertSessionHasNoErrors();
        $owner = $dashboard();
        $this->actingAs($this->accountant($this->riyadh, $this->jeddah));
        $this->post(route('context.update'), ['branch_id' => 'all'])->assertSessionHasNoErrors();
        $twoBranches = $dashboard();

        $this->assertSame(number_format((float) $sale->total, 2), $owner['kpis']['month_sales']);
        $this->assertSame('0.00', $twoBranches['kpis']['month_sales']);
        $this->assertEqualsWithDelta($overdue($sale, ...array_values($this->invoices)), $owner['cashOutlook']['overdue'], 0.01);
        $this->assertEqualsWithDelta($overdue(...array_values($this->invoices)), $twoBranches['cashOutlook']['overdue'], 0.01);
    }
}
