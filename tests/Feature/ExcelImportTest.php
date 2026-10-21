<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Quotation;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Spreadsheet import: document lines, whole quotations and list screens.
 */
class ExcelImportTest extends TestCase
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

    protected function csv(string $content, string $name = 'lines.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xEF\xBB\xBF".$content);
    }

    protected function lines(string $kind, string $csv, array $extra = [])
    {
        return $this->post(route('lookup.lines.import'), ['file' => $this->csv($csv), 'kind' => $kind] + $extra, ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------------ S1/S2

    public function test_quotation_lines_match_by_code_barcode_and_keep_free_text(): void
    {
        Product::create(['name_ar' => 'كابل نحاس', 'sku' => 'CB-10', 'selling_price' => 40, 'purchase_price' => 25, 'tax_rate' => 15, 'is_active' => true]);
        Product::create(['name_ar' => 'قاطع كهربائي', 'sku' => 'BR-1', 'barcode' => '6281000000011', 'selling_price' => 90, 'tax_rate' => 15, 'is_active' => true]);

        $json = $this->lines('sales', "رمز الصنف,الصنف,الكمية,السعر,الخصم,الضريبة %\nCB-10,,100,35,50,15\n6281000000011,,4,,,\n,أعمال تركيب,1,2500,,15\nXX-9,,1,,,\n")
            ->assertOk()->json();

        $this->assertCount(3, $json['lines']);
        $this->assertSame(['matched' => 2, 'free' => 1, 'errors' => 1], $json['summary']);
        [$cable, $breaker, $free] = $json['lines'];
        $this->assertSame([100, 35, 50], [(int) $cable['quantity'], (int) $cable['unit_price'], (int) $cable['discount']]);
        $this->assertSame(90.0, (float) $breaker['unit_price']);               // product price when the file has none
        $this->assertNull($free['product_id']);
        $this->assertSame('أعمال تركيب', $free['item_name']);
        $this->assertStringContainsString('سطر 5', $json['errors'][0]);
    }

    public function test_customer_price_list_applies_when_the_file_has_no_price(): void
    {
        $product = Product::create(['name_ar' => 'كابل', 'sku' => 'CB-10', 'selling_price' => 40, 'tax_rate' => 15, 'is_active' => true]);
        $list = PriceList::create(['name' => 'جملة', 'is_active' => true]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $product->id, 'min_quantity' => 1, 'price' => 33]);
        $customer = Customer::create(['name' => 'عميل جملة', 'customer_type' => 'commercial', 'status' => 'active', 'price_list_id' => $list->id]);

        $line = $this->lines('sales', "code,qty\nCB-10,10\n", ['customer_id' => $customer->id])->assertOk()->json('lines.0');
        $this->assertSame(33.0, (float) $line['unit_price']);
        $this->assertSame(0.0, (float) $this->lines('purchase', "code,qty\nCB-10,10\n")->json('lines.0.unit_price'));
    }

    public function test_stock_lines_reject_unknown_products(): void
    {
        Product::create(['name_ar' => 'كابل', 'sku' => 'CB-10', 'selling_price' => 40, 'is_active' => true]);

        $json = $this->lines('stock', "الكود,الصنف,الكمية\nCB-10,,5\n,أعمال تركيب,1\n")->assertOk()->json();
        $this->assertCount(1, $json['lines']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame(5.0, (float) $json['lines'][0]['quantity']);
    }

    public function test_journal_lines_match_accounts_and_refuse_parents(): void
    {
        $rent = Account::find(app(AccountMap::class)->id('rent_expense'))->code;
        $bank = Account::find(app(AccountMap::class)->id('default_bank'))->code;
        $parent = Account::where('is_parent', true)->value('code');

        $json = $this->lines('journal', "رمز الحساب,مدين,دائن,البيان\n{$rent},1000,,إيجار\n{$bank},,1000,سداد\n{$parent},5,,\n")->assertOk()->json();
        $this->assertCount(2, $json['lines']);
        $this->assertSame(1000.0, (float) $json['summary']['debit']);
        $this->assertSame(1000.0, (float) $json['summary']['credit']);
        $this->assertStringContainsString('رئيسي', $json['errors'][0]);
    }

    public function test_line_import_needs_a_product_column_and_a_template_exists(): void
    {
        $this->lines('sales', "الكمية,السعر\n1,2\n")->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString('رمز الصنف', $this->get(route('lookup.lines.template', ['kind' => 'sales']))->assertOk()->streamedContent());
    }

    // ------------------------------------------------------------------ S3

    public function test_whole_quotations_import_as_drafts_with_manual_totals(): void
    {
        Product::create(['name_ar' => 'كابل', 'sku' => 'CB-10', 'selling_price' => 40, 'tax_rate' => 15, 'is_active' => true]);
        Customer::create(['code' => 'C-1', 'name' => 'شركة البناء', 'customer_type' => 'commercial', 'status' => 'active']);
        Customer::create(['code' => 'C-2', 'name' => 'مؤسسة النور', 'customer_type' => 'commercial', 'status' => 'active']);

        $csv = 'quotation_ref,customer_code,customer_name,date,valid_until,notes,code,name,quantity,unit_price,discount,tax_rate
'
            .'Q-OLD-1,C-1,,2026-06-01,2026-06-15,عرض توريد,CB-10,,100,35,50,15
'
            .'Q-OLD-1,C-1,,2026-06-01,2026-06-15,,,أعمال تركيب,1,2500,,15
'
            .'Q-OLD-2,,مؤسسة النور,2026-06-03,,,CB-10,,10,,,
'
            .'Q-OLD-2,,مؤسسة النور,2026-06-03,,,,نقل,1,300,,0
'
            .'Q-OLD-2,,مؤسسة النور,2026-06-03,,,CB-10,,5,40,,15
';
        $this->post(route('data-transfer.import', 'quotations'), ['file' => $this->csv($csv, 'q.csv')])->assertSessionHasNoErrors();

        $this->assertSame(2, Quotation::count());
        $first = Quotation::with('items')->whereHas('customer', fn ($q) => $q->where('code', 'C-1'))->first();
        $this->assertSame('draft', $first->status);
        $this->assertCount(2, $first->items);
        // (100 x 35 - 50) + 2500 = 5950 net; VAT 15% = 892.5
        $this->assertSame(5950.0, (float) $first->subtotal);
        $this->assertSame(6842.5, (float) $first->total);
        $this->assertStringContainsString('Q-OLD-1', $first->notes);

        // The same quotation through the form gives the same totals.
        $this->post(route('sales.quotations.store'), ['customer_id' => $first->customer_id, 'quotation_date' => '2026-06-01', 'items' => [
            ['item_name' => 'كابل', 'quantity' => 100, 'unit_price' => 35, 'discount' => 50, 'tax_rate' => 15],
            ['item_name' => 'أعمال تركيب', 'quantity' => 1, 'unit_price' => 2500, 'tax_rate' => 15],
        ]])->assertRedirect();
        $this->assertSame(6842.5, (float) Quotation::latest('id')->first()->total);

        $second = Quotation::with('items')->whereHas('customer', fn ($q) => $q->where('code', 'C-2'))->first();
        $this->assertCount(3, $second->items);
        $this->assertSame(40.0, (float) $second->items->first()->unit_price);   // product price
    }

    public function test_a_quotation_file_with_an_unknown_customer_saves_nothing(): void
    {
        Customer::create(['code' => 'C-1', 'name' => 'شركة البناء', 'customer_type' => 'commercial', 'status' => 'active']);
        $csv = 'quotation_ref,customer_code,date,name,quantity,unit_price
A,C-1,2026-06-01,خدمة,1,100
B,C-9,2026-06-01,خدمة,1,100
';

        $this->post(route('data-transfer.import', 'quotations'), ['file' => $this->csv($csv, 'q.csv')])->assertSessionHasErrors('file');
        $this->assertStringContainsString('C-9', session('errors')->first('file'));
        $this->assertSame(0, Quotation::count());
    }

    // ------------------------------------------------------------------ S4

    protected function import(string $type, string $csv)
    {
        return $this->post(route('data-transfer.import', $type), ['file' => $this->csv($csv, "{$type}.csv"), 'opening_date' => '2026-06-01']);
    }

    public function test_employees_import_validates_ids_and_keeps_pay_for_salary_access(): void
    {
        $header = "employee_number,name,nationality,national_id,job_title,hire_date,basic_salary,housing_allowance,iban\n";
        $this->import('employees', $header."E-1,أحمد,SA,2012345678,محاسب,2025-01-01,8000,2000,\n")->assertSessionHasErrors('file');
        $this->import('employees', $header."E-1,أحمد,SA,1012345678,محاسب,2025-01-01,8000,2000,SA0480000000608010167519\n")->assertSessionHasErrors('file');
        $this->import('employees', $header."E-1,أحمد,SA,1012345678,محاسب,2025-01-01,8000,2000,SA0380000000608010167519\nE-2,راج,IN,2012345678,فني,2025-03-01,3000,750,\n")->assertSessionHasNoErrors();
        $this->assertSame(2, Employee::count());
        $this->assertSame(10000.0, (float) Employee::where('employee_number', 'E-1')->value('total_salary'));
        $this->assertSame('iqama', Employee::where('employee_number', 'E-2')->value('id_type'));

        // Re-import updates the profile, never the pay; the same ID twice is refused.
        $this->import('employees', $header."E-1,أحمد العلي,SA,1012345678,رئيس حسابات,2025-01-01,99999,0,\n")->assertSessionHasNoErrors();
        $e = Employee::where('employee_number', 'E-1')->first();
        $this->assertSame(['أحمد العلي', 'رئيس حسابات', 8000.0], [$e->name, $e->job_title, (float) $e->basic_salary]);
        $this->import('employees', $header."E-9,مكرر,SA,1012345678,فني,2025-01-01,,,\n")->assertSessionHasErrors('file');

        // HR clerk without salary access: pay columns are ignored.
        $clerk = $this->userWithRole($this->company, 'employee');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $clerk->givePermissionTo(['hr.employees.view', 'hr.employees.create']);
        $this->actingAs($clerk->fresh());
        $this->import('employees', $header."E-3,سالم,SA,1098765432,سائق,2025-05-01,7000,1000,\n")->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) Employee::where('employee_number', 'E-3')->value('basic_salary'));
    }

    public function test_chart_of_accounts_import_builds_under_parents(): void
    {
        $parent = Account::where('is_parent', true)->where('type', 'expense')->first();
        $csv = "code,name_ar,type,parent_code\n{$parent->code}9901,مصروفات سفر,,{$parent->code}\n{$parent->code}990101,تذاكر طيران,,{$parent->code}9901\n";
        $this->import('accounts', $csv)->assertSessionHasNoErrors();

        $travel = Account::where('code', $parent->code.'9901')->first();
        $this->assertTrue((bool) $travel->is_parent);
        $this->assertSame('expense', $travel->type);
        $this->assertSame($travel->id, Account::where('code', $parent->code.'990101')->value('parent_id'));

        $this->import('accounts', "code,name_ar,type,parent_code\nX1,حساب,revenue,{$parent->code}\n")->assertSessionHasErrors('file');
        $this->import('accounts', "code,name_ar,parent_code\nX2,حساب,NOPE\n")->assertSessionHasErrors('file');
    }

    public function test_fixed_assets_import_as_opening_assets(): void
    {
        $map = app(AccountMap::class);
        AssetCategory::create(['name_ar' => 'سيارات', 'useful_life_months' => 48, 'depreciation_method' => 'straight_line',
            'asset_account_id' => $map->id('fixed_assets'), 'accumulated_account_id' => $map->id('accumulated_depreciation'), 'expense_account_id' => $map->id('depreciation_expense')]);

        $this->import('fixed_assets', "code,name_ar,category,acquisition_date,cost,opening_accumulated\nCAR-1,سيارة,شاحنات,2024-01-01,96000,24000\n")->assertSessionHasErrors('file');
        $this->import('fixed_assets', "code,name_ar,category,acquisition_date,cost,opening_accumulated\nCAR-1,سيارة,سيارات,2024-01-01,96000,24000\n")->assertSessionHasNoErrors();

        $asset = FixedAsset::where('code', 'CAR-1')->first();
        $this->assertSame(72000.0, (float) $asset->book_value);
        $this->assertSame('2026-06-01', $asset->depreciation_start_date->toDateString());
        $this->assertSame(96000.0, round((float) Account::find($map->id('fixed_assets'))->balance, 2));
        $this->assertSame(24000.0, round((float) Account::find($map->id('accumulated_depreciation'))->balance, 2));
        $this->import('fixed_assets', "code,name_ar,category,acquisition_date,cost\nCAR-1,مكرر,سيارات,2024-01-01,100\n")->assertSessionHasErrors('file');
    }

    public function test_list_screen_imports_need_the_type_permission(): void
    {
        $salesUser = $this->userWithRole($this->company, 'sales');
        $this->actingAs($salesUser);
        $this->import('accounts', "code,name_ar,type\nZ1,حساب,asset\n")->assertForbidden();
        $this->get(route('data-transfer.template', 'customers'))->assertOk();
    }
}
