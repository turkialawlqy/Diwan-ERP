<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bom;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MemberCheckin;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Permissions\PermissionRegistry;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use App\Support\PickerOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * What keeps pages quick on a large company: reports answer the same with far fewer
 * queries, and forms send the first records of a long list and search the rest.
 */
class LargeDataTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function customer(string $name = 'عميل', string $status = 'active'): Customer
    {
        return Customer::create(['name' => $name, 'customer_type' => 'commercial', 'status' => $status]);
    }

    protected function invoice(Customer $customer, string $date, float $price): void
    {
        app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => $date, 'due_date' => $date],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    /** @return array<int, string> the queries $work ran */
    protected function queries(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $log;
    }

    protected function products(int $count): void
    {
        $now = now();
        DB::table('products')->insert(collect(range(1, $count))->map(fn ($i) => ['company_id' => $this->company->id, 'name_ar' => sprintf('صنف %04d', $i), 'sku' => sprintf('S%04d', $i),
            'type' => 'product', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    // ------------------------------------------------------------------ reports

    public function test_aging_reads_each_batch_of_customers_once_and_spreads_their_own_documents(): void
    {
        $older = $this->customer('الأقدم');
        $this->invoice($older, '2026-03-01', 1000);                // 1,150 due 1 March: 121 days at the end of June
        $this->invoice($older, '2026-05-20', 2000);                // 2,300 due 20 May: 41 days
        $newer = $this->customer('الأحدث');
        $this->invoice($newer, '2026-06-15', 4000);                // 4,600 due 15 June: 15 days

        $reports = app(AccountingReports::class);
        $today = null;
        $log = $this->queries(function () use ($reports, &$today) {
            $today = $reports->aging('receivables', Carbon::parse('2026-06-30'));
        });

        $this->assertCount(1, array_filter($log, fn ($sql) => preg_match('/from ["`]invoices["`]/', $sql)), 'One query for all the customers\' documents.');
        $rows = collect($today['rows'])->keyBy('id');
        $this->assertSame([2300.0, 1150.0], [$rows[$older->id]['d60'], $rows[$older->id]['d90plus']]);
        $this->assertSame([4600.0, 8050.0], [$rows[$newer->id]['d30'], $today['totals']['total']]);

        $past = collect($reports->aging('receivables', Carbon::parse('2026-05-31'))['rows'])->keyBy('id');
        $this->assertSame([2300.0, 1150.0], [$past[$older->id]['d30'], $past[$older->id]['d90plus']]);
        $this->assertFalse($past->has($newer->id), 'No balance yet at that date.');
    }

    public function test_the_account_map_reads_all_roles_at_once_and_answers_like_each_role_alone(): void
    {
        $map = app(AccountMap::class);
        $parent = Account::where('is_parent', true)->firstOrFail();
        $map->set('rent_expense', $parent->id, $this->company->id);        // a group account is not postable: null
        $map->set('marketing_expense', null, $this->company->id);

        $one = new AccountMap(app(TenantManager::class));
        $alone = collect(array_keys(AccountMap::ROLES))->mapWithKeys(fn ($role) => [$role => $one->find($role)])->all();

        $fresh = new AccountMap(app(TenantManager::class));
        $all = null;
        $log = $this->queries(function () use ($fresh, &$all) {
            $all = $fresh->all();
        });

        $this->assertCount(2, $log);
        $this->assertSame($alone, $all);
        $this->assertNull($all['rent_expense']);
        $this->assertNull($all['marketing_expense']);
        $this->assertNotNull($all['receivables']);
    }

    public function test_sharing_answers_as_each_query_alone(): void
    {
        $this->invoice($this->customer(), '2026-03-01', 1000);
        $this->invoice($this->customer('ثان'), '2026-06-20', 500);
        $reports = app(AccountingReports::class);
        $from = Carbon::parse('2026-01-01');
        $to = Carbon::parse('2026-06-30');
        $some = [app(AccountMap::class)->id('sales_revenue')];

        $direct = [$reports->totalsBetween($from, $to), $reports->totalsBetween($from, $to, $some), $reports->totalsBetween($from, $to, $some, null, true)];
        $shared = null;
        $log = $this->queries(function () use ($reports, $from, $to, $some, &$shared) {
            $shared = $reports->sharing(fn () => [$reports->totalsBetween($from, $to), $reports->totalsBetween($from, $to, $some), $reports->totalsBetween($from, $to, $some, null, true)]);
        });

        $this->assertEquals($direct, $shared);
        $this->assertLessThan(5, count(array_filter($log, fn ($sql) => str_contains($sql, 'SUM('))));

        // An empty list of accounts means every account, as outside sharing().
        $this->assertEquals($reports->totalsBetween($from, $to, []), $reports->sharing(fn () => $reports->totalsBetween($from, $to, [])));
    }

    public function test_a_day_of_checkins_is_the_whole_calendar_day(): void
    {
        foreach (['2026-06-29 23:59:59', '2026-06-30 00:00:00', '2026-06-30 23:59:59', '2026-07-01 00:00:00'] as $moment) {
            MemberCheckin::create(['method' => 'scan', 'allowed' => true, 'code_entered' => 'X', 'checked_in_at' => $moment]);
        }

        $this->assertSame(2, MemberCheckin::onDay('2026-06-30')->count());
        $this->assertSame(2, MemberCheckin::onDay(today())->count());
    }

    // ------------------------------------------------------------------ long lists

    public function test_a_long_list_sends_its_first_records_and_the_one_already_chosen(): void
    {
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->products(PickerOptions::FIRST + 5);
        $last = Product::query()->orderByDesc('id')->firstOrFail();

        $this->get(route('inventory.operations.adjust.create', ['product_id' => $last->id, 'warehouse_id' => 1]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Inventory/Operations/AdjustForm')
                ->has('products', PickerOptions::FIRST + 1)
                ->where('products.'.PickerOptions::FIRST.'.id', $last->id));
        $this->get(route('inventory.operations.transfer.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('products', PickerOptions::FIRST));
    }

    public function test_editing_a_bill_of_materials_keeps_its_chosen_products(): void
    {
        $this->products(PickerOptions::FIRST + 5);
        [$finished, $component] = Product::query()->orderByDesc('id')->limit(2)->get()->all();
        $bom = Bom::create(['product_id' => $finished->id, 'name' => 'قائمة', 'version' => '1', 'output_quantity' => 1, 'scrap_percent' => 0, 'is_default' => true, 'is_active' => true]);
        $bom->items()->create(['component_id' => $component->id, 'quantity' => 2, 'scrap_percent' => 0]);

        $this->get(route('manufacturing.boms.edit', $bom))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Manufacturing/Boms/Form')
                ->has('products', PickerOptions::FIRST + 2)
                ->where('products', fn ($products) => collect([$finished->id, $component->id])->diff(collect($products)->pluck('id'))->isEmpty()));

        // A new one needs none of them.
        $this->get(route('manufacturing.boms.create'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('products', PickerOptions::FIRST));
    }

    public function test_the_stock_movements_list_no_longer_sends_every_product(): void
    {
        $this->products(3);

        $this->get(route('inventory.operations.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->missing('products')->missing('warehouses'));
    }

    public function test_product_search_can_leave_out_services_and_add_the_cost_for_bom_builders(): void
    {
        Product::create(['name_ar' => 'مادة خام', 'sku' => 'M1', 'type' => 'product', 'is_active' => true, 'average_cost' => 7]);
        Product::create(['name_ar' => 'مادة خدمة', 'sku' => 'M2', 'type' => 'service', 'is_active' => true]);

        $this->getJson(route('lookup.products', ['q' => 'مادة']))->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.average_cost');
        $this->getJson(route('lookup.products', ['q' => 'مادة', 'stockable' => 1]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'M1');
        $this->assertEquals(7, (float) $this->getJson(route('lookup.products', ['q' => 'خام', 'cost' => 1]))->assertOk()->json('data.0.average_cost'));

        // Someone who only sells does not get the cost, whatever the request says.
        $this->actingAs($this->userWithPermission('sales.invoices.create'));
        $this->getJson(route('lookup.products', ['q' => 'خام', 'cost' => 1]))->assertOk()->assertJsonMissingPath('data.0.average_cost');
    }

    public function test_customer_and_supplier_search_filter_status_on_request(): void
    {
        $this->customer('نشط');
        $this->customer('موقوف عميل', 'inactive');
        Supplier::create(['name' => 'مورد نشط', 'status' => 'active']);
        Supplier::create(['name' => 'مورد موقوف', 'status' => 'inactive']);

        $this->getJson(route('lookup.customers', ['q' => 'موقوف']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('lookup.customers', ['q' => 'موقوف', 'any_status' => 1]))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(route('lookup.suppliers', ['q' => 'مورد']))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(route('lookup.suppliers', ['q' => 'مورد', 'active' => 1]))->assertOk()->assertJsonCount(1, 'data');

        // Inactive customers are the journal form's: someone who only sells does not get them by asking.
        $this->actingAs($this->userWithPermission('sales.invoices.create'));
        $this->getJson(route('lookup.customers', ['q' => 'موقوف', 'any_status' => 1]))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_payment_voucher_loads_only_the_chosen_suppliers_open_invoices(): void
    {
        $supplier = Supplier::create(['name' => 'المورد', 'status' => 'active']);
        $other = Supplier::create(['name' => 'آخر', 'status' => 'active']);
        foreach ([[$supplier, 100], [$supplier, 0], [$other, 50]] as $i => [$party, $left]) {
            DB::table('purchase_invoices')->insert(['company_id' => $this->company->id, 'supplier_id' => $party->id, 'invoice_number' => 'P-'.$i, 'invoice_date' => '2026-06-01',
                'subtotal' => 100, 'total' => 100, 'remaining_amount' => $left, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->get(route('purchases.payments.create'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->missing('unpaidInvoices'));
        $this->get(route('purchases.payments.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->missing('unpaidInvoices')->missing('suppliers'));

        $this->getJson(route('purchases.payments.open-invoices', ['supplier_id' => $supplier->id]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.invoice_number', 'P-0');
        $this->getJson(route('purchases.payments.open-invoices'))->assertUnprocessable();
        $this->getJson(route('purchases.payments.open-invoices', ['supplier_id' => 999999]))->assertUnprocessable();
        $this->assertSame(3, PurchaseInvoice::count());
    }

    public function test_those_who_use_the_searching_forms_may_call_the_search(): void
    {
        $routes = config('erp_permissions.routes');
        foreach (['inventory.operations.create', 'manufacturing.boms.create', 'manufacturing.orders.create', 'manufacturing.routings.create', 'manufacturing.quality.create', 'manufacturing.process.create', 'manufacturing.subcontracting.create'] as $permission) {
            $this->assertContains($permission, $routes['lookup.products']);
        }
        foreach (['lookup.customers', 'lookup.suppliers'] as $route) {
            $this->assertContains('accounting.journals.create', $routes[$route]);
        }
        $this->assertContains('manufacturing.subcontracting.create', $routes['lookup.suppliers']);
    }

    protected function userWithPermission(string $permission)
    {
        $registry = app(PermissionRegistry::class);
        $registry->seedCompanyRoles($this->company);
        $user = User::factory()->create(['company_id' => $this->company->id, 'status' => 'active']);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->company->id);
        $user->givePermissionTo($permission);

        return $user->fresh();
    }
}
