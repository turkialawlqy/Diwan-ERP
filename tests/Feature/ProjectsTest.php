<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProgressBilling;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Inventory\StockService;
use App\Services\Projects\ProjectReport;
use App\Services\Projects\ProjectService;
use App\Services\Projects\ProjectWip;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'وزارة', 'customer_type' => 'government', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function service(): ProjectService
    {
        return app(ProjectService::class);
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find(app(AccountMap::class)->id($role))->balance, 2);
    }

    protected function project(): Project
    {
        return $this->service()->create(
            ['customer_id' => $this->customer->id, 'name' => 'مبنى إداري', 'advance_percent' => 10, 'retention_percent' => 5],
            [
                ['code' => 'A', 'description' => 'أعمال خرسانية', 'is_group' => true],
                ['code' => 'A.1', 'parent_code' => 'A', 'description' => 'خرسانة', 'unit' => 'm3', 'quantity' => 100, 'unit_price' => 500, 'unit_cost' => 350],
                ['code' => 'A.2', 'parent_code' => 'A', 'description' => 'حديد', 'unit' => 'ton', 'quantity' => 20, 'unit_price' => 2500, 'unit_cost' => 2000],
            ],
        );
    }

    public function test_contract_value_from_boq_and_cost_center(): void
    {
        $project = $this->project();

        $this->assertSame(100000.0, (float) $project->contract_value);
        $this->assertSame($project->code, $project->costCenter->code);
        $this->assertNotNull($project->boqItems()->where('code', 'A.1')->value('parent_id'));
    }

    public function test_advance_billing_retention_and_release(): void
    {
        $project = $this->project();
        $advance = $this->service()->receiveAdvance($project, ['date' => '2026-06-15', 'amount' => 10000]);

        $this->assertSame(11500.0, (float) $advance->total);
        $this->assertSame(10000.0, $this->balance('customer_advances'));
        $this->assertSame(1500.0, $this->balance('vat_output'));

        $a1 = $project->boqItems()->where('code', 'A.1')->first();
        $billing = $this->service()->draftBilling($project, ['billing_date' => '2026-06-30'], [['boq_item_id' => $a1->id, 'current_quantity' => 40]]);

        $this->assertSame(20000.0, (float) $billing->current_value);
        $this->assertSame(2000.0, (float) $billing->advance_recovery);   // 10%
        $this->assertSame(1000.0, (float) $billing->retention);          // 5%
        $this->assertSame(18000.0, (float) $billing->taxable_amount);
        $this->assertSame(2700.0, (float) $billing->vat_amount);
        $this->assertSame(19700.0, (float) $billing->net_payable);

        $invoice = $this->service()->approveBilling($billing);
        $this->assertSame(20700.0, (float) $invoice->total);              // taxable + VAT on the tax invoice
        $this->assertSame(19700.0, (float) $invoice->remaining_amount);   // retention held back
        $this->assertSame(20000.0, $this->balance('contract_revenue'));
        $this->assertSame(8000.0, $this->balance('customer_advances'));
        $this->assertSame(1000.0, $this->balance('retention_receivable'));
        $this->assertSame(40.0, (float) $a1->fresh()->billed_quantity);
        $this->assertSame(19700.0, (float) $this->customer->fresh()->current_balance);

        // Cannot bill beyond the contract quantity.
        try {
            $this->service()->draftBilling($project, ['billing_date' => '2026-07-31'], [['boq_item_id' => $a1->id, 'current_quantity' => 61]]);
            $this->fail('Over-billing accepted');
        } catch (ValidationException) {
        }

        $this->service()->releaseRetention($project->fresh(), 1000, '2026-12-31');
        $this->assertSame(0.0, $this->balance('retention_receivable'));
        $this->assertSame(20700.0, (float) $this->customer->fresh()->current_balance);

        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);
    }

    public function test_variation_becomes_billable_and_changes_contract(): void
    {
        $project = $this->project();
        $variation = $this->service()->addVariation($project, ['date' => '2026-06-20', 'description' => 'أعمال إضافية', 'amount' => 15000, 'cost_impact' => 9000]);
        $this->service()->approveVariation($variation);

        $this->assertSame(115000.0, (float) $project->fresh()->contract_value);
        $vo = $project->boqItems()->where('variation_id', $variation->id)->first();
        $billing = $this->service()->draftBilling($project, ['billing_date' => '2026-06-30'], [['boq_item_id' => $vo->id, 'current_quantity' => 1]]);
        $this->assertSame(15000.0, (float) $billing->current_value);
    }

    public function test_subcontract_certificate_and_costs_roll_into_profitability(): void
    {
        $project = $this->project();
        $supplier = Supplier::create(['name' => 'مقاول باطن', 'status' => 'active']);
        $sub = $this->service()->createSubcontract($project, ['supplier_id' => $supplier->id, 'scope' => 'أعمال الحديد', 'contract_value' => 40000, 'retention_percent' => 10]);
        $this->service()->paySubcontractAdvance($sub, ['date' => '2026-06-15', 'amount' => 4000]);

        $certificate = $this->service()->certify($sub, ['date' => '2026-06-30', 'cumulative_value' => 20000]);
        $this->assertSame(2000.0, (float) $certificate->advance_recovery); // 10% of the work
        $this->assertSame(2000.0, (float) $certificate->retention);
        $this->assertSame(3000.0, (float) $certificate->vat_amount);
        $this->assertSame(19000.0, (float) $certificate->net_payable);       // 20000 + 3000 - 2000 - 2000
        $this->assertSame(2000.0, $this->balance('retention_payable'));
        $this->assertSame(19000.0, (float) $supplier->fresh()->current_balance);

        // Materials and labour.
        $cement = Product::create(['name_ar' => 'أسمنت', 'sku' => 'C1', 'selling_price' => 20, 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $cement->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'unit_cost' => 15, 'type' => 'opening']);
        $this->service()->moveMaterials($project, ['date' => '2026-06-16'], [['product_id' => $cement->id, 'quantity' => 60]]);
        $this->service()->moveMaterials($project, ['date' => '2026-06-20'], [['product_id' => $cement->id, 'quantity' => 10]], true);
        $this->assertSame(50.0, app(StockService::class)->onHand($cement->id));

        $this->service()->recordCost($project, ['date' => '2026-06-30', 'category' => 'labour', 'description' => 'عمالة يونيو', 'amount' => 5000, 'paid_from' => 'accrued']);

        $summary = app(ProjectReport::class)->summary($project->fresh());
        $this->assertSame(25750.0, $summary['actual_cost']); // 20000 sub + 750 materials + 5000 labour
        $this->assertSame(20000.0, $summary['costs_by_category']['subcontract']);
        $this->assertSame(750.0, $summary['costs_by_category']['material']);
        $this->assertSame(75000.0, $summary['estimated_cost']);
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);
    }

    public function test_project_pages_render(): void
    {
        $project = $this->project();
        $a1 = $project->boqItems()->where('code', 'A.1')->first();
        $this->post(route('projects.billings.store', $project), ['billing_date' => '2026-06-30', 'lines' => [['boq_item_id' => $a1->id, 'current_quantity' => 10]]])->assertSessionHasNoErrors();
        $billing = ProgressBilling::firstOrFail();
        $this->post(route('projects.billings.approve', [$project, $billing]))->assertSessionHasNoErrors();
        $this->post(route('projects.daily-reports.store', $project), ['date' => '2026-06-15', 'work_done' => 'صب أعمدة'])->assertSessionHasNoErrors();
        $this->post(route('projects.guarantees.store'), ['project_id' => $project->id, 'type' => 'performance', 'number' => 'LG-1', 'bank_name' => 'الراجحي',
            'beneficiary' => 'وزارة', 'amount' => 5000, 'issue_date' => '2026-06-01', 'expiry_date' => '2026-07-01'])->assertSessionHasNoErrors();

        foreach ([route('projects.index'), route('projects.create'), route('projects.show', $project), route('projects.billings.show', [$project, $billing]), route('projects.guarantees.index')] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertNotNull($billing->fresh()->invoice_id);
    }

    public function test_input_vat_on_a_project_cost_reaches_the_vat_return_and_matches_the_ledger(): void
    {
        $project = $this->project();
        $this->service()->recordCost($project, ['date' => '2026-06-20', 'category' => 'labour', 'description' => 'مقاول عمالة', 'amount' => 1000, 'vat_amount' => 150, 'paid_from' => 'accrued']);

        $return = app(AccountingReports::class)->vatReturn(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        $box7 = collect($return['boxes'])->firstWhere('no', 7);
        $this->assertEquals(150.0, $box7['vat']);
        $this->assertEquals(1000.0, $box7['amount']);
    }

    public function test_returns_are_limited_to_what_was_issued_and_a_closed_project_takes_nothing(): void
    {
        $project = $this->project();
        $cement = Product::create(['name_ar' => 'أسمنت', 'sku' => 'C9', 'selling_price' => 20, 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $cement->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'unit_cost' => 15, 'type' => 'opening']);
        $this->service()->moveMaterials($project, ['date' => '2026-06-16'], [['product_id' => $cement->id, 'quantity' => 10]]);

        $this->assertThrows(fn () => $this->service()->moveMaterials($project, ['date' => '2026-06-20'], [['product_id' => $cement->id, 'quantity' => 11]], true), ValidationException::class);
        $this->assertSame(90.0, app(StockService::class)->onHand($cement->id));      // nothing came back

        $project->update(['status' => 'closed']);
        $this->assertThrows(fn () => $this->service()->recordCost($project->fresh(), ['date' => '2026-06-21', 'category' => 'labour', 'description' => 'x', 'amount' => 100, 'paid_from' => 'accrued']), ValidationException::class);
    }

    public function test_subcontractor_retention_is_released_to_his_account_and_closing_checks_drafts(): void
    {
        $project = $this->project();
        $supplier = Supplier::create(['name' => 'مقاول باطن', 'status' => 'active']);
        $sub = $this->service()->createSubcontract($project, ['supplier_id' => $supplier->id, 'scope' => 'أعمال', 'contract_value' => 40000, 'retention_percent' => 10]);
        $this->service()->certify($sub, ['date' => '2026-06-30', 'cumulative_value' => 20000]);
        $this->assertSame(2000.0, $this->balance('retention_payable'));
        $owedBefore = (float) $supplier->fresh()->current_balance;

        $this->post(route('projects.subcontracts.release-retention', $sub->id), ['date' => '2026-07-10', 'amount' => 2500])->assertSessionHasErrors('amount');
        $this->post(route('projects.subcontracts.release-retention', $sub->id), ['date' => '2026-07-10', 'amount' => 1500])->assertSessionHasNoErrors();

        $this->assertSame(500.0, $this->balance('retention_payable'));
        $this->assertSame($owedBefore + 1500, (float) $supplier->fresh()->current_balance);
        $this->assertSame(1500.0, (float) $sub->fresh()->retention_released);

        // A project with a draft billing cannot be closed; unreleased retention is only warned about.
        ProgressBilling::unguarded(fn () => ProgressBilling::create(['project_id' => $project->id, 'number' => 'B-DRAFT', 'sequence' => 1, 'billing_date' => '2026-06-30', 'status' => 'draft']));
        $payload = ['name' => $project->name, 'status' => 'closed', 'advance_percent' => 0, 'retention_percent' => 0];
        $this->put(route('projects.update', $project->id), $payload)->assertSessionHasErrors('status');
        $this->assertNotSame('closed', $project->fresh()->status);
    }

    public function test_revenue_by_progress_posts_a_contract_asset_reversed_the_next_day(): void
    {
        $project = $this->project();
        $a1 = $project->boqItems()->where('code', 'A.1')->first();
        $billing = $this->service()->draftBilling($project, ['billing_date' => '2026-06-20'], [['boq_item_id' => $a1->id, 'current_quantity' => 40]]);
        $this->service()->approveBilling($billing);                         // 20,000 billed and recognised

        $wip = app(ProjectWip::class);
        $wip->updateProgress($project, [['boq_item_id' => $a1->id, 'executed_quantity' => 80]]);   // 40,000 earned
        $this->assertThrows(fn () => $wip->updateProgress($project, [['boq_item_id' => $a1->id, 'executed_quantity' => 30]]), ValidationException::class);   // below what was certified
        $this->assertThrows(fn () => $wip->updateProgress($project, [['boq_item_id' => $a1->id, 'executed_quantity' => 101]]), ValidationException::class);  // beyond the contract

        $figures = $wip->figures($project);
        $this->assertSame([100000.0, 40000.0, 20000.0, 20000.0, 40.0], [$figures['contract_value'], $figures['earned'], $figures['billed'], $figures['wip'], $figures['percent']]);

        $entry = $wip->post($project, '2026-06-30');
        $this->assertSame(20000.0, (float) $entry->amount);
        $june = app(AccountingReports::class)->incomeStatement(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        $this->assertSame(40000.0, (float) collect($june['revenue']['rows'])->firstWhere('id', app(AccountMap::class)->id('contract_revenue'))['amount']);
        $july = app(AccountingReports::class)->incomeStatement(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
        $this->assertSame(-20000.0, (float) collect($july['revenue']['rows'])->firstWhere('id', app(AccountMap::class)->id('contract_revenue'))['amount']);   // the adjustment comes back
        $this->assertSame(0.0, $this->balance('contract_asset'));

        $this->assertThrows(fn () => $wip->post($project, '2026-06-30'), ValidationException::class);   // one adjustment per date
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.001);
    }
}
