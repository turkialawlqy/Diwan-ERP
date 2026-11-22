<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentRequest;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Documents\DocumentNumberService;
use App\Services\Imports\LineImporter;
use App\Services\Permissions\PermissionRegistry;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use App\Support\PickerOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sales and payment request forms over long lists: each sends its first records and
 * searches the rest, keeps listing what the document already chose, and gives a user
 * no more about a record through the search than the form itself always showed.
 */
class LongFormListsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function customers(int $count): void
    {
        $now = now();
        DB::table('customers')->insert(collect(range(1, $count))->map(fn ($i) => ['company_id' => $this->company->id, 'name' => sprintf('عميل %04d', $i), 'customer_type' => 'commercial',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now])->all());
    }

    protected function suppliers(int $count): void
    {
        $now = now();
        DB::table('suppliers')->insert(collect(range(1, $count))->map(fn ($i) => ['company_id' => $this->company->id, 'name' => sprintf('مورد %04d', $i),
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now])->all());
    }

    protected function products(int $count): void
    {
        $now = now();
        DB::table('products')->insert(collect(range(1, $count))->map(fn ($i) => ['company_id' => $this->company->id, 'name_ar' => sprintf('صنف %04d', $i), 'sku' => sprintf('S%04d', $i),
            'type' => 'product', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    protected function purchaseInvoice(int $supplierId, string $number, float $remaining): int
    {
        return DB::table('purchase_invoices')->insertGetId(['company_id' => $this->company->id, 'supplier_id' => $supplierId, 'invoice_number' => $number, 'invoice_date' => '2026-06-01',
            'subtotal' => 100, 'total' => 100, 'remaining_amount' => $remaining, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function userWithPermission(string $permission): User
    {
        app(PermissionRegistry::class)->seedCompanyRoles($this->company);
        $user = User::factory()->create(['company_id' => $this->company->id, 'status' => 'active']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $user->givePermissionTo($permission);

        return $user->fresh();
    }

    public function test_the_quotation_form_sends_the_first_customers_and_products(): void
    {
        $this->customers(PickerOptions::FIRST + 5);
        $this->products(PickerOptions::FIRST + 5);

        $this->get(route('sales.quotations.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Sales/Quotations/Create')
                ->has('customers', PickerOptions::FIRST)
                ->has('products', PickerOptions::FIRST));
    }

    public function test_the_invoice_form_sends_the_first_customers_and_products_without_their_whole_relations(): void
    {
        $this->customers(PickerOptions::FIRST + 5);
        $unit = Unit::create(['name_ar' => 'حبة', 'name_en' => 'Piece', 'code' => 'pcs']);
        $category = Category::create(['name_ar' => 'عام', 'is_active' => true]);
        Product::create(['name_ar' => 'صنف', 'sku' => 'U1', 'type' => 'product', 'is_active' => true, 'unit_id' => $unit->id, 'category_id' => $category->id]);
        $this->products(PickerOptions::FIRST + 5);
        Product::create(['name_ar' => 'صنف موقوف', 'sku' => 'U2', 'type' => 'product', 'is_active' => false]);

        $this->get(route('sales.invoices.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Sales/Invoices/Create')
                ->has('customers', PickerOptions::FIRST)
                ->has('products', PickerOptions::FIRST)
                ->where('products', function ($products) {
                    $row = collect($products)->firstWhere('sku', 'U1');

                    return $row['unit']['name_ar'] === 'حبة' && ! isset($row['unit']['code']) && ! array_key_exists('category', $row);
                }));
    }

    public function test_the_new_product_dialog_of_the_invoice_takes_its_code_from_the_numbering_service(): void
    {
        $this->products(3);
        $suggested = app(DocumentNumberService::class)->peek('product');

        $this->get(route('sales.invoices.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('suggestedSku', $suggested));

        // A code kept as suggested is sent empty: the server reserves it, then the next free one, so two products never share it.
        $create = fn (string $name) => $this->postJson(route('inventory.products.store'), ['name_ar' => $name, 'selling_price' => 1, 'sku' => ''])->assertCreated()->json('product.sku');
        $first = $create('أول من الفاتورة');
        $second = $create('ثان من الفاتورة');
        $this->assertSame($suggested, $first);
        $this->assertNotSame($first, $second);
    }

    public function test_the_return_form_keeps_the_invoices_customer_and_names_only_those_of_recent_invoices(): void
    {
        $this->customers(PickerOptions::FIRST + 5);
        $last = Customer::query()->orderByDesc('name')->firstOrFail();
        $invoice = app(InvoiceService::class)->create(['customer_id' => $last->id, 'invoice_date' => '2026-06-01', 'due_date' => '2026-06-01'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15]], ['issue_stock' => false]);

        $this->get(route('sales.returns.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Sales/Returns/Create')
                ->has('customers', PickerOptions::FIRST)
                ->where('recentInvoices.0.customer', ['id' => $last->id, 'name' => $last->name]));

        $this->get(route('sales.returns.create', ['invoice_id' => $invoice->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('customers', PickerOptions::FIRST + 1)
                ->where('customers.'.PickerOptions::FIRST.'.id', $last->id));
    }

    public function test_the_promotion_form_keeps_the_product_of_the_promotion(): void
    {
        $this->products(PickerOptions::FIRST + 5);
        $last = Product::query()->orderByDesc('name_ar')->firstOrFail();
        $promotion = Promotion::create(['name' => 'عرض', 'type' => 'percent', 'value' => 10, 'product_id' => $last->id, 'documents' => ['invoice'], 'priority' => 0, 'is_active' => true]);

        $this->get(route('sales.promotions.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Sales/Promotions/Form')->has('products', PickerOptions::FIRST));

        $this->get(route('sales.promotions.edit', $promotion))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('products', PickerOptions::FIRST + 1)
                ->where('products.'.PickerOptions::FIRST.'.id', $last->id));
    }

    public function test_the_payment_request_form_sends_the_first_records_and_keeps_what_the_request_chose(): void
    {
        $this->suppliers(PickerOptions::FIRST + 5);
        $last = Supplier::query()->orderByDesc('name')->firstOrFail();
        $other = Supplier::query()->orderBy('name')->firstOrFail();
        foreach (range(1, PickerOptions::FIRST + 3) as $i) {
            $this->purchaseInvoice($last->id, 'P-'.$i, 10);
        }
        $this->purchaseInvoice($last->id, 'P-PAID', 0);
        $this->purchaseInvoice($other->id, 'OTHER-1', 10);
        $chosen = DB::table('purchase_invoices')->where('invoice_number', 'P-'.(PickerOptions::FIRST + 3))->value('id');

        $this->get(route('finance.payment-requests.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Finance/PaymentRequests/Form')
                ->has('suppliers', PickerOptions::FIRST)
                ->has('unpaidInvoices', PickerOptions::FIRST));

        $request = PaymentRequest::create(['request_number' => 'PRQ-1', 'request_date' => '2026-06-02', 'kind' => 'supplier_payment', 'payee_type' => 'supplier', 'supplier_id' => $last->id,
            'purchase_invoice_id' => $chosen, 'purpose' => 'دفعة', 'amount' => 10, 'payment_method' => 'cash', 'status' => 'draft', 'requested_by' => auth()->id()]);

        $this->get(route('finance.payment-requests.edit', $request))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('suppliers', PickerOptions::FIRST + 1)
                ->where('suppliers.'.PickerOptions::FIRST.'.id', $last->id)
                ->has('unpaidInvoices', PickerOptions::FIRST + 1)
                ->where('unpaidInvoices.'.PickerOptions::FIRST.'.id', $chosen)
                ->where('unpaidInvoices', fn ($rows) => collect($rows)->every(fn ($row) => $row['supplier_id'] === $last->id && $row['invoice_number'] !== 'P-PAID')));
    }

    public function test_the_open_invoices_are_the_chosen_suppliers_or_everyones_and_match_what_the_list_shows(): void
    {
        $this->suppliers(2);
        [$first, $second] = Supplier::query()->orderBy('name')->get()->all();
        $this->purchaseInvoice($first->id, 'P-100', 1500);
        $this->purchaseInvoice($first->id, 'P-200', 0);
        $this->purchaseInvoice($second->id, 'Q-300', 75.5);
        $search = fn (array $params = []) => $this->getJson(route('finance.payment-requests.open-invoices', $params))->assertOk();

        $search()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.invoice_number', 'P-100')->assertJsonPath('data.0.supplier_id', $first->id)->assertJsonPath('data.0.remaining_amount', '1500.00')
            ->assertJsonPath('data.1.invoice_number', 'Q-300')->assertJsonMissingPath('data.0.total');
        $search(['supplier_id' => $first->id])->assertJsonCount(1, 'data')->assertJsonPath('data.0.invoice_number', 'P-100');
        $search(['q' => 'Q-3'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.invoice_number', 'Q-300');
        $search(['q' => '1500'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.invoice_number', 'P-100');
        $search(['supplier_id' => $first->id, 'q' => 'Q-3'])->assertJsonCount(0, 'data');

        foreach (range(1, PickerOptions::FIRST + 2) as $i) {
            $this->purchaseInvoice($first->id, 'BULK-'.$i, 5);
        }
        $search(['supplier_id' => $first->id])->assertJsonCount(PickerOptions::FIRST, 'data');

        $elsewhere = Company::factory()->create();
        $foreign = DB::table('suppliers')->insertGetId(['company_id' => $elsewhere->id, 'name' => 'مورد آخر', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson(route('finance.payment-requests.open-invoices', ['supplier_id' => $foreign]))->assertUnprocessable();
    }

    public function test_a_user_who_may_only_name_the_record_gets_no_more_than_its_name(): void
    {
        Product::create(['name_ar' => 'صنف بسعر', 'sku' => 'M1', 'type' => 'product', 'is_active' => true, 'selling_price' => 90, 'purchase_price' => 60]);
        Supplier::create(['name' => 'مورد البنك', 'status' => 'active', 'iban' => 'SA0000000000000000000000', 'phone' => '0500000000']);

        foreach (['sales.promotions.create', 'sales.promotions.edit'] as $permission) {
            $this->actingAs($this->userWithPermission($permission));
            $this->getJson(route('lookup.products', ['q' => 'صنف']))->assertOk()->assertJsonPath('data.0.name_ar', 'صنف بسعر')->assertJsonPath('data.0.sku', 'M1')
                ->assertJsonMissingPath('data.0.selling_price')->assertJsonMissingPath('data.0.purchase_price')->assertJsonMissingPath('data.0.description');
        }
        foreach (['finance.payment_requests.create', 'finance.payment_requests.edit'] as $permission) {
            $this->actingAs($this->userWithPermission($permission));
            $this->getJson(route('lookup.suppliers', ['q' => 'مورد']))->assertOk()->assertJsonPath('data.0.name', 'مورد البنك')
                ->assertJsonMissingPath('data.0.iban')->assertJsonMissingPath('data.0.phone')->assertJsonMissingPath('data.0.current_balance');
        }

        // Those who already see more keep seeing it, whatever else they may do.
        $this->actingAs($this->userWithPermission('sales.invoices.create'));
        $this->assertEquals(60, (float) $this->getJson(route('lookup.products', ['q' => 'صنف']))->assertOk()->json('data.0.purchase_price'));
        $this->actingAs($this->userWithPermission('purchases.suppliers.view'));
        $this->getJson(route('lookup.suppliers', ['q' => 'مورد']))->assertOk()->assertJsonPath('data.0.iban', 'SA0000000000000000000000');
    }

    public function test_those_who_use_these_forms_may_call_their_searches(): void
    {
        $routes = config('erp_permissions.routes');
        foreach ([
            'lookup.customers' => ['sales.quotations.create', 'sales.invoices.create', 'sales.invoices.edit', 'sales.returns.create'],
            'lookup.products' => ['sales.quotations.create', 'sales.invoices.create', 'sales.invoices.edit', 'sales.promotions.create', 'sales.promotions.edit'],
            'lookup.suppliers' => ['finance.payment_requests.create', 'finance.payment_requests.edit'],
            'finance.payment-requests.open-invoices' => ['finance.payment_requests.create', 'finance.payment_requests.edit'],
        ] as $route => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertContains($permission, (array) $routes[$route], "{$permission} needs {$route}");
            }
        }
    }

    public function test_an_imported_sales_line_carries_what_the_forms_product_list_needs(): void
    {
        $product = Product::create(['name_ar' => 'صنف', 'name_en' => 'Item', 'sku' => 'X1', 'type' => 'product', 'is_active' => true, 'selling_price' => 9, 'tax_rate' => 15]);
        $importer = app(LineImporter::class);
        $rows = [2 => ['code' => 'X1', 'quantity' => 1]];

        $line = $importer->items(['code', 'quantity'], $rows, 'sales', null)['lines'][0];
        $this->assertSame($product->id, $line['product']['id']);
        $this->assertEquals(9, (float) $line['product']['selling_price']);

        $this->assertNull($importer->items(['code', 'quantity'], $rows, 'purchase', null)['lines'][0]['product']);

        // An inactive product is not offered by the form's list, so it does not join it.
        $product->update(['is_active' => false]);
        $this->assertNull($importer->items(['code', 'quantity'], $rows, 'sales', null)['lines'][0]['product']);
    }
}
