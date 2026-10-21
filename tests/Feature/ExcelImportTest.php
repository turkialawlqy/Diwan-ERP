<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
}
