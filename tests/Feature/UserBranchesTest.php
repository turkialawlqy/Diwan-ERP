<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Branches per user: the owner works in every branch; other users create in
 * and see only the branches given to them, and new documents start on their
 * default branch instead of the first one.
 */
class UserBranchesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Branch $riyadh;

    protected Branch $jeddah;

    protected Branch $dammam;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create(['currency' => 'SAR', 'country_code' => 'SA', 'tax_number' => '310122393500003']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
        Currency::create(['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol_ar' => 'ر.س', 'symbol_en' => 'SAR', 'exchange_rate' => 1, 'is_default' => true]);
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->jeddah = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        $this->dammam = Branch::create(['name_ar' => 'الدمام', 'code' => 'DMM', 'is_active' => true]);
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function clerk(array $branches, ?Branch $default = null): User
    {
        $clerk = $this->userWithRole($this->company, 'sales');
        $clerk->forceFill(['branch_id' => ($default ?? $branches[0])->id])->save();
        $clerk->branches()->sync(collect($branches)->pluck('id'));

        return $clerk->fresh();
    }

    protected function invoiceIn(Branch $branch): Invoice
    {
        return app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'branch_id' => $branch->id, 'payment_method' => 'credit'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]]);
    }

    protected function invoicePayload(Branch $branch): array
    {
        return [
            'customer_id' => $this->customer->id, 'branch_id' => $branch->id, 'invoice_date' => '2026-06-15', 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'items' => [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]],
        ];
    }

    public function test_the_owner_chooses_any_branch_and_starts_on_the_main_one(): void
    {
        $this->get(route('sales.invoices.create'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sales/Invoices/Create')
            ->has('branches', 3)
            ->where('defaultBranchId', $this->riyadh->id)
            ->where('branches.0.tax.rate', 15));

        $this->post(route('sales.invoices.store'), $this->invoicePayload($this->dammam))->assertSessionHasNoErrors();
        $this->assertSame($this->dammam->id, Invoice::latest('id')->first()->branch_id);
    }

    public function test_a_user_works_only_in_their_branches(): void
    {
        $this->invoiceIn($this->riyadh);
        $this->invoiceIn($this->jeddah);
        $this->invoiceIn($this->dammam);

        $this->actingAs($this->clerk([$this->riyadh, $this->jeddah], $this->jeddah));

        $this->get(route('sales.invoices.create'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('branches', 2)
            ->where('defaultBranchId', $this->jeddah->id));

        // Another branch is refused, theirs is accepted.
        $this->post(route('sales.invoices.store'), $this->invoicePayload($this->dammam))->assertSessionHasErrors('branch_id');
        $this->post(route('sales.invoices.store'), $this->invoicePayload($this->riyadh))->assertSessionHasNoErrors();
        // Without a branch: their default one.
        $this->post(route('sales.invoices.store'), array_diff_key($this->invoicePayload($this->riyadh), ['branch_id' => 1]))->assertSessionHasNoErrors();
        $this->assertSame($this->jeddah->id, Invoice::latest('id')->first()->branch_id);

        $this->get(route('sales.invoices.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('invoices.data', 4));
        $this->assertSame([$this->riyadh->id, $this->jeddah->id], Invoice::pluck('branch_id')->unique()->sort()->values()->all());
    }

    public function test_the_users_page_saves_branches_and_their_default(): void
    {
        $this->post(route('users.store'), [
            'name' => 'محاسب', 'email' => 'acc@example.test', 'password' => 'Secret-Pass-2026!', 'role' => 'sales', 'status' => 'active',
            'branch_ids' => [$this->jeddah->id, $this->dammam->id], 'branch_id' => $this->dammam->id,
        ])->assertSessionHasNoErrors();
        $user = User::where('email', 'acc@example.test')->firstOrFail();
        $this->assertSame([$this->jeddah->id, $this->dammam->id], $user->branches()->pluck('branches.id')->sort()->values()->all());
        $this->assertSame($this->dammam->id, $user->branch_id);

        // A default outside the ticked branches is refused.
        $this->put(route('users.update', $user), [
            'name' => 'محاسب', 'email' => 'acc@example.test', 'role' => 'sales', 'status' => 'active',
            'branch_ids' => [$this->jeddah->id], 'branch_id' => $this->riyadh->id,
        ])->assertSessionHasErrors('branch_id');

        // The owner role needs no branches: all of them.
        $this->put(route('users.update', $user), [
            'name' => 'محاسب', 'email' => 'acc@example.test', 'role' => 'admin', 'status' => 'active', 'branch_ids' => [$this->jeddah->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $user->branches()->count());
        $this->assertNull($user->fresh()->allowedBranchIds());
    }

    public function test_existing_users_keep_their_branch_and_others_get_the_main_one(): void
    {
        $withBranch = $this->userWithRole($this->company, 'sales', ['branch_id' => $this->jeddah->id]);
        $without = $this->userWithRole($this->company, 'sales');

        Schema::drop('branch_user');
        (require base_path('database/migrations/2026_10_26_000001_create_branch_user_table.php'))->up();

        $rows = DB::table('branch_user')->get()->groupBy('user_id');
        $this->assertSame([$this->jeddah->id], $rows[$withBranch->id]->pluck('branch_id')->all());
        $this->assertSame([$this->riyadh->id], $rows[$without->id]->pluck('branch_id')->all());
        $this->assertSame($this->riyadh->id, $without->fresh()->branch_id);
        $this->assertArrayNotHasKey($this->owner->id, $rows->all());
        $this->assertNull($this->owner->allowedBranchIds());
    }

    public function test_an_order_follows_the_tax_of_its_branch(): void
    {
        $this->post(route('branches.store'), ['name_ar' => 'القاهرة', 'code' => 'CAI', 'country_code' => 'EG', 'currency' => 'SAR'])->assertSessionHasNoErrors();
        $cairo = Branch::where('code', 'CAI')->firstOrFail();

        $this->get(route('sales.orders.create'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('branches.3.tax.rate', 14));

        $this->post(route('sales.orders.store'), [
            'customer_id' => $this->customer->id, 'branch_id' => $cairo->id, 'order_date' => '2026-06-15',
            'items' => [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->first();
        $this->assertSame([$cairo->id, 14.0], [$order->branch_id, (float) $order->items->first()->tax_rate]);
    }
}
