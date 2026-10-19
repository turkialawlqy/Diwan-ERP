<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryStock;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\HR\BankSalaryFormats;
use App\Services\HR\PayrollService;
use App\Services\Settings\CompanyPolicy;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Post-launch: opening balances import, payroll compliance files and
 * reports, global search.
 */
class PhaseBTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
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

    protected function account(string $role): Account
    {
        return Account::find(app(AccountMap::class)->id($role, $this->company->id));
    }

    protected function balance(string $role): float
    {
        return round((float) $this->account($role)->balance, 2);
    }

    protected function upload(string $type, string $csv, string $date = '2026-06-01')
    {
        return $this->post(route('data-transfer.import', $type), [
            'file' => UploadedFile::fake()->createWithContent("{$type}.csv", $csv),
            'opening_date' => $date,
        ]);
    }

    // ------------------------------------------------------------------ B1 opening balances

    public function test_customer_and_supplier_import_posts_opening_balances_once(): void
    {
        $this->upload('customers', "code,name,opening_balance\nC1,أحمد,1500\nC2,سارة,-200\nC3,خالد,\n")->assertSessionHasNoErrors();
        $this->assertSame(3, Customer::count());
        $this->assertSame(1300.0, $this->balance('receivables'));
        $this->assertSame(1500.0, (float) Customer::where('code', 'C1')->value('current_balance'));
        $entry = JournalEntry::where('entry_type', 'opening')->where('source_id', Customer::where('code', 'C1')->value('id'))->first();
        $this->assertSame('2026-06-01', $entry->date->toDateString());

        // Re-importing to update contacts never books the balance twice.
        $this->upload('customers', "code,name,phone,opening_balance\nC1,أحمد,0500000000,1500\n")->assertSessionHasNoErrors();
        $this->assertSame(1300.0, $this->balance('receivables'));
        $this->assertSame('0500000000', Customer::where('code', 'C1')->value('phone'));

        $this->upload('suppliers', "name,opening_balance\nمورد أ,2000\n")->assertSessionHasNoErrors();
        $this->assertSame(2000.0, $this->balance('payables'));
        $this->assertSame(2000.0, (float) Supplier::first()->current_balance);
        // receivables 1300 Dr, payables 2000 Cr -> opening equity 700 Dr.
        $this->assertSame(-700.0, $this->balance('opening_balance_equity'));
    }

    public function test_product_import_receives_opening_stock_into_the_warehouse(): void
    {
        $main = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $branch = Warehouse::create(['name_ar' => 'فرع', 'code' => 'W2', 'is_active' => true]);

        $this->upload('products', "sku,name_ar,selling_price,purchase_price,opening_quantity,opening_cost,warehouse_code\nP1,صنف,20,10,5,12,W2\nP2,صنف 2,30,8,4,,\n")->assertSessionHasNoErrors();

        $p1 = Product::where('sku', 'P1')->first();
        $this->assertSame(5.0, (float) InventoryStock::where('product_id', $p1->id)->where('warehouse_id', $branch->id)->value('quantity'));
        $p2 = Product::where('sku', 'P2')->first();
        $this->assertSame(4.0, (float) InventoryStock::where('product_id', $p2->id)->where('warehouse_id', $main->id)->value('quantity'));
        // 5 x 12 + 4 x 8 (purchase price when no opening cost).
        $this->assertSame(92.0, $this->balance('inventory'));
        $this->assertSame(92.0, $this->balance('opening_balance_equity'));
    }

    public function test_product_import_rejects_unknown_warehouse_and_service_stock(): void
    {
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);

        $this->upload('products', "sku,name_ar,selling_price,type,opening_quantity,opening_cost,warehouse_code\nP1,صنف,20,product,5,10,XX\nS1,خدمة,50,service,1,10,\n")
            ->assertSessionHasErrors('file');
        $this->assertSame(0, Product::count());
        $this->assertSame(0.0, $this->balance('inventory'));
    }

    public function test_opening_trial_balance_is_validated_then_posted_once(): void
    {
        $bank = $this->account('default_bank')->code;
        $capital = $this->account('capital')->code;
        $loans = $this->account('long_term_loans')->code;
        $receivables = $this->account('receivables')->code;

        $this->upload('opening_balances', "account_code,debit,credit\n{$bank},100000,\n{$capital},,90000\n")->assertSessionHasErrors('file');
        $this->upload('opening_balances', "account_code,debit,credit\n{$bank},100000,\n{$receivables},5000,\n{$capital},,105000\n")->assertSessionHasErrors('file');
        $this->upload('opening_balances', "account_code,debit,credit\n9999999,100,\n{$capital},,100\n")->assertSessionHasErrors('file');
        $this->assertSame(0, JournalEntry::count());

        $this->upload('opening_balances', "account_code,debit,credit,description\n{$bank},100000,,رصيد البنك\n{$capital},,70000,\n{$loans},,30000,\n")->assertSessionHasNoErrors();
        $entry = JournalEntry::where('source_type', 'opening_trial_balance')->firstOrFail();
        $this->assertSame('opening', $entry->entry_type);
        $this->assertSame(100000.0, (float) $entry->total_debit);
        $this->assertSame(100000.0, $this->balance('default_bank'));
        $this->assertSame(70000.0, $this->balance('capital'));

        $this->upload('opening_balances', "account_code,debit,credit\n{$bank},1,\n{$capital},,1\n")->assertSessionHasErrors('file');
        $this->assertSame(1, JournalEntry::where('source_type', 'opening_trial_balance')->count());
    }

    public function test_opening_trial_balance_requires_journal_permission(): void
    {
        $user = $this->userWithRole($this->company, 'sales');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $user->givePermissionTo('admin.data.use');
        $this->actingAs($user->fresh());

        $this->get(route('data-transfer.index'))->assertOk();
        $this->upload('opening_balances', "account_code,debit,credit\n1,1,\n")->assertForbidden();
    }

    // ------------------------------------------------------------------ B2 payroll compliance

    protected int $sequence = 100;

    protected function employee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'name' => 'أحمد', 'job_title' => 'محاسب', 'department' => 'المالية', 'hire_date' => '2020-01-01', 'nationality' => 'SA',
            'basic_salary' => 10000, 'housing_allowance' => 2500, 'transport_allowance' => 1000, 'other_allowances' => 0, 'total_salary' => 13500,
            'status' => 'active', 'gosi_registered' => true, 'employee_number' => 'E'.(++$this->sequence),
            'national_id' => '1012345678', 'iban' => 'SA0380000000608010167519', 'gosi_number' => '555',
        ], $attributes));
    }

    protected function payroll(): Payroll
    {
        $service = app(PayrollService::class);
        $payroll = $service->generate(2026, 6);
        $service->approve($payroll);

        return $payroll->fresh();
    }

    public function test_iban_validation(): void
    {
        $this->assertTrue(BankSalaryFormats::validIban('SA03 8000 0000 6080 1016 7519'));
        $this->assertFalse(BankSalaryFormats::validIban('SA0480000000608010167519'));
        $this->assertFalse(BankSalaryFormats::validIban('AE070331234567890123456'));
        $this->assertSame('80', BankSalaryFormats::bankCode('SA0380000000608010167519'));
    }

    public function test_mudad_file_is_refused_with_the_employees_to_fix(): void
    {
        $this->employee();
        $this->employee(['name' => 'سالم', 'iban' => 'SA0480000000608010167519', 'national_id' => '12345']);
        $payroll = $this->payroll();

        $this->from(route('hr.payroll.show', $payroll))->get(route('hr.payroll.wps', ['payroll' => $payroll->id, 'format' => 'mudad']))
            ->assertRedirect(route('hr.payroll.show', $payroll))->assertSessionHasErrors('wps');
        $message = session('errors')->first('wps');
        $this->assertStringContainsString('سالم: رقم الهوية', $message);
        $this->assertStringContainsString('سالم: رقم الآيبان', $message);
        $this->assertStringNotContainsString('أحمد', $message);
    }

    public function test_mudad_and_bank_files_balance_to_the_payroll(): void
    {
        app(CompanyPolicy::class)->set($this->company, 'hr.wps', ['mol_establishment_id' => '1-99999', 'gosi_establishment_id' => '777',
            'company_iban' => 'SA1510000000608010167519', 'bank_format' => 'alrajhi']);
        $this->employee();
        $this->employee(['name' => 'راج', 'nationality' => 'IN', 'national_id' => '2123456789', 'iban' => 'SA1510000000608010167519', 'basic_salary' => 4000, 'housing_allowance' => 1000, 'transport_allowance' => 0]);
        $payroll = $this->payroll();

        $mudad = $this->get(route('hr.payroll.wps', ['payroll' => $payroll->id, 'format' => 'mudad']))->assertOk()->getContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $mudad)))));
        $this->assertCount(3, $lines);
        $this->assertSame('Establishment ID', end($lines[0]));
        $net = array_sum(array_map(fn ($l) => (float) $l[8], array_slice($lines, 1)));
        $this->assertEqualsWithDelta((float) $payroll->net_total, $net, 0.001);
        foreach (array_slice($lines, 1) as $l) {
            // basic + housing + other - deductions = net
            $this->assertEqualsWithDelta((float) $l[8], (float) $l[4] + (float) $l[5] + (float) $l[6] - (float) $l[7], 0.001);
            $this->assertSame('1-99999', $l[10]);
        }
        $this->assertSame('10', collect($lines)->firstWhere(1, 'راج')[2]);

        $bank = $this->get(route('hr.payroll.wps', ['payroll' => $payroll->id, 'format' => 'bank']))->assertOk()->getContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($bank))));
        $this->assertSame(['H', '1-99999', 'SA1510000000608010167519'], array_slice($rows[0], 0, 3));
        $this->assertSame('2', $rows[0][4]);
        $this->assertEqualsWithDelta((float) $payroll->net_total, (float) $rows[0][5], 0.001);
        $this->assertSame('D', $rows[1][0]);
    }

    public function test_gosi_report_reconciles_to_the_payable_account(): void
    {
        $this->employee();
        $this->employee(['name' => 'راج', 'nationality' => 'IN', 'national_id' => '2123456789', 'gosi_number' => '', 'basic_salary' => 4000, 'housing_allowance' => 1000]);
        $payroll = $this->payroll();

        $response = $this->get(route('hr.reports.gosi', ['month' => '2026-06']))->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(2, $props['rows']);
        $this->assertEqualsWithDelta((float) $payroll->total_gosi_employee + (float) $payroll->total_gosi_employer, $props['totals']['total'], 0.001);
        $this->assertEqualsWithDelta($props['totals']['total'], $this->balance('gosi_payable'), 0.001);
        $this->assertSame(0.0, (float) $props['difference']);
        $this->assertSame(1, $props['missing_numbers']);
        // Saudi on 12,500: 9.75% / 11.75%; expatriate: 2% hazards only.
        $saudi = collect($props['rows'])->firstWhere('name', 'أحمد');
        $this->assertSame([12500.0, 1218.75, 1468.75], [$saudi['base'], $saudi['employee'], $saudi['employer']]);

        $csv = $this->get(route('hr.reports.gosi', ['month' => '2026-06', 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Contributory Wage', $csv);
    }

    public function test_saudization_indicator_weights_saudis_by_wage(): void
    {
        $this->employee(['basic_salary' => 4000, 'housing_allowance' => 1000]);                 // full
        $this->employee(['name' => 'ب', 'basic_salary' => 3000, 'housing_allowance' => 500]);  // half
        $this->employee(['name' => 'ج', 'basic_salary' => 2000, 'housing_allowance' => 500]);  // not counted
        $this->employee(['name' => 'د', 'nationality' => 'EG']);
        $this->employee(['name' => 'هـ', 'nationality' => 'EG', 'status' => 'terminated', 'termination_date' => '2026-05-31']);
        $this->employee(['name' => 'و', 'hire_date' => '2026-07-15']);

        $props = $this->get(route('hr.reports.saudization', ['date' => '2026-06-30']))->assertOk()->viewData('page')['props'];
        $this->assertSame(4, $props['total']['headcount']);
        $this->assertSame(3, $props['total']['saudis']);
        $this->assertSame(1.5, (float) $props['total']['counted']);
        $this->assertSame(37.5, (float) $props['total']['ratio']);
        $this->assertSame(1, $props['total']['below_half']);
    }

    // ------------------------------------------------------------------ B3 global search

    public function test_search_returns_only_the_groups_the_user_may_view(): void
    {
        Customer::create(['name' => 'مؤسسة النخبة', 'code' => 'C-77', 'customer_type' => 'commercial', 'status' => 'active']);
        Supplier::create(['name' => 'النخبة للتوريد', 'status' => 'active']);
        Product::create(['name_ar' => 'صنف النخبة', 'sku' => 'NK-1', 'is_active' => true]);
        $this->employee(['name' => 'النخبة موظف']);

        $groups = collect($this->getJson(route('search', ['q' => 'النخبة']))->assertOk()->json('groups'))->keyBy('key');
        $this->assertEqualsCanonicalizing(['customers', 'suppliers', 'products', 'employees'], $groups->keys()->all());
        $this->assertSame(route('sales.clients.show', Customer::first()->id), $groups['customers']['items'][0]['url']);
        $this->assertSame([], $this->getJson(route('search', ['q' => 'ن']))->json('groups'));

        // A cashier sees customers and products, never employees or suppliers.
        $this->actingAs($this->userWithRole($this->company, 'cashier'));
        $keys = collect($this->getJson(route('search', ['q' => 'النخبة']))->assertOk()->json('groups'))->pluck('key')->all();
        $this->assertNotContains('employees', $keys);
        $this->assertNotContains('suppliers', $keys);
    }

    public function test_search_never_crosses_companies(): void
    {
        $other = Company::factory()->create();
        app(TenantManager::class)->runAs($other, fn () => Customer::create(['name' => 'عميل سري', 'customer_type' => 'commercial', 'status' => 'active']));

        $this->assertSame([], $this->getJson(route('search', ['q' => 'سري']))->assertOk()->json('groups'));
    }
}
