<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DimensionPolicy;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cost-centre / project policy: optional, required, or only on profit-and-loss accounts. */
class DimensionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected CostCenter $cc;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->cc = CostCenter::create(['code' => 'CC1', 'name_ar' => 'الصيانة', 'is_active' => true]);
        $customer = Customer::create(['name' => 'مالك', 'customer_type' => 'individual', 'status' => 'active']);
        $this->project = Project::create(['customer_id' => $customer->id, 'code' => 'P1', 'name' => 'مشروع أ']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function role(string $role): int
    {
        return app(AccountMap::class)->id($role);
    }

    protected function policy(string $cost, string $project = 'optional'): void
    {
        $policy = app(DimensionPolicy::class);
        $policy->set('cost_center', $cost, $this->company->id);
        $policy->set('project', $project, $this->company->id);
    }

    protected function journal(array $expenseLine = [], string $save = 'posted', string $type = 'manual')
    {
        return $this->post(route('accounting.journals.store'), [
            'date' => '2026-06-10', 'description' => 'قيد', 'save_as' => $save, 'entry_type' => $type,
            'lines' => [
                ['account_id' => $this->role('general_expense'), 'debit' => 100, 'credit' => 0] + $expenseLine,
                ['account_id' => $this->role('default_cash'), 'debit' => 0, 'credit' => 100],
            ],
        ]);
    }

    public function test_optional_requires_nothing(): void
    {
        $this->policy('optional');
        $this->journal()->assertSessionHasNoErrors();
    }

    public function test_pnl_requires_the_dimension_on_profit_and_loss_lines_only(): void
    {
        $this->policy('pnl');

        // The expense line needs a cost centre; the cash line (balance sheet) never does.
        $this->journal()->assertSessionHasErrors('accounting');
        $this->assertSame(0, JournalEntry::count());

        $this->journal(['cost_center_id' => $this->cc->id])->assertSessionHasNoErrors();
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_required_applies_to_every_distribution_line_and_to_projects(): void
    {
        $this->policy('required', 'required');
        $this->journal(['cost_center_id' => $this->cc->id])->assertSessionHasErrors('accounting');

        $response = $this->post(route('accounting.journals.store'), [
            'date' => '2026-06-10', 'description' => 'قيد', 'save_as' => 'posted',
            'lines' => [
                ['account_id' => $this->role('general_expense'), 'debit' => 100, 'credit' => 0, 'cost_center_id' => $this->cc->id, 'project_id' => $this->project->id],
                ['account_id' => $this->role('default_cash'), 'debit' => 0, 'credit' => 100, 'cost_center_id' => $this->cc->id, 'project_id' => $this->project->id],
            ],
        ]);
        $response->assertSessionHasNoErrors();
        $this->assertSame($this->project->id, JournalEntryLine::where('account_id', $this->role('general_expense'))->value('project_id'));
    }

    public function test_a_draft_may_stay_incomplete_until_it_is_posted(): void
    {
        $this->policy('pnl');
        $this->journal([], 'draft')->assertSessionHasNoErrors();
        $draft = JournalEntry::where('status', 'draft')->firstOrFail();

        $this->post(route('accounting.journals.post', $draft->id))->assertSessionHasErrors('accounting');
        $this->assertSame('draft', $draft->fresh()->status);

        $draft->lines()->where('account_id', $this->role('general_expense'))->update(['cost_center_id' => $this->cc->id]);
        $this->post(route('accounting.journals.post', $draft->id))->assertSessionHasNoErrors();
        $this->assertSame('posted', $draft->fresh()->status);
    }

    public function test_opening_entries_are_exempt(): void
    {
        $this->policy('required', 'required');
        $this->journal([], 'posted', 'opening')->assertSessionHasNoErrors();
    }

    public function test_the_account_flag_still_wins_when_the_policy_is_optional(): void
    {
        $this->policy('optional');
        Account::whereKey($this->role('general_expense'))->update(['requires_cost_center' => true]);
        $this->journal()->assertSessionHasErrors('accounting');
    }

    public function test_expense_voucher_follows_the_policy_and_saves_the_project(): void
    {
        $this->policy('pnl', 'required');
        $data = ['title' => 'صيانة', 'category' => 'صيانة', 'amount' => 200, 'expense_date' => '2026-06-10', 'payment_method' => 'cash'];

        $this->post(route('finance.expenses.store'), $data)->assertSessionHasErrors('accounting');
        $this->assertSame(0, Expense::count());

        $this->post(route('finance.expenses.store'), $data + ['cost_center_id' => $this->cc->id, 'project_id' => $this->project->id])->assertSessionHasNoErrors();
        $expense = Expense::firstOrFail();
        $this->assertSame($this->project->id, $expense->project_id);
        $line = JournalEntryLine::where('cost_center_id', $this->cc->id)->firstOrFail();
        $this->assertSame($this->project->id, $line->project_id);
    }

    public function test_policy_page_saves_and_validates(): void
    {
        $this->put(route('accounting.mappings.dimensions'), ['cost_center' => 'pnl', 'project' => 'required'])->assertSessionHasNoErrors();
        $this->assertSame(['cost_center' => 'pnl', 'project' => 'required'], app(DimensionPolicy::class)->modes());
        $this->put(route('accounting.mappings.dimensions'), ['cost_center' => 'sometimes', 'project' => 'optional'])->assertSessionHasErrors('cost_center');

        $this->get(route('accounting.mappings.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('dimensionPolicy.project', 'required'));
    }

    public function test_an_invoice_carries_its_cost_centre_and_project_to_the_revenue_line_and_the_policy_applies(): void
    {
        $customer = Customer::first();
        $invoices = app(InvoiceService::class);
        $line = [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]];

        $invoice = $invoices->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-10', 'due_date' => '2026-06-10', 'cost_center_id' => $this->cc->id, 'project_id' => $this->project->id], $line, ['issue_stock' => false, 'dimensions' => true]);
        $revenueLine = JournalEntryLine::where('account_id', $this->role('sales_revenue'))->whereHas('journalEntry', fn ($q) => $q->where('source_id', $invoice->id))->firstOrFail();
        $this->assertSame([$this->cc->id, $this->project->id], [(int) $revenueLine->cost_center_id, (int) $revenueLine->project_id]);
        // The party line and the VAT line stay without a dimension.
        $this->assertNull(JournalEntryLine::where('account_id', $this->role('vat_output'))->first()->cost_center_id);

        // With the policy «required on profit and loss lines», an invoice without a cost centre is refused.
        $this->policy('pnl');
        $this->assertThrows(fn () => $invoices->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-11', 'due_date' => '2026-06-11'], $line, ['issue_stock' => false, 'dimensions' => true]), AccountingException::class);
        $invoices->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-11', 'due_date' => '2026-06-11', 'cost_center_id' => $this->cc->id], $line, ['issue_stock' => false, 'dimensions' => true]);
        // A system invoice (POS, recurring, billing...) is not held to the policy.
        $invoices->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-12', 'due_date' => '2026-06-12'], $line, ['issue_stock' => false]);

        // The bill form hands the choices to the page.
        $this->get(route('sales.invoices.create'))->assertOk()->assertInertia(fn ($p) => $p->has('costCenters', 1)->has('projects', 1)->where('dimensionPolicy.cost_center', 'pnl'));
    }
}
