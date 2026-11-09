<?php

namespace Tests\Feature;

use App\Models\BookableService;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DeliveryNote;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\YearEndClosing;
use App\Services\ActiveBranch;
use App\Services\Inventory\StockService;
use App\Services\TenantManager;
use App\Support\BranchScopeSwitch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A platform admin inside a company (support and training mode) has no branch
 * of their own and no choice in the top bar: what they save goes to the
 * company's main branch instead of to no branch at all, while the rules for
 * the company's own staff stay as they are.
 */
class SupportModeBranchTest extends TestCase
{
    use RefreshDatabase;

    protected const DATE = '2026-06-15';

    protected const REFUSED = 'لا تملك صلاحية العمل على هذا الفرع.';

    protected Company $company;

    protected Branch $main;

    protected Branch $other;

    protected Warehouse $warehouse;

    protected Customer $customer;

    protected Supplier $supplier;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create(['currency' => 'SAR', 'country_code' => 'SA', 'tax_number' => '310122393500003']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        // Created first on purpose: the main branch is not simply the lowest id.
        $this->other = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        $this->main = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $this->product = Product::create(['name_ar' => 'صنف', 'sku' => 'P-1', 'selling_price' => 100, 'purchase_price' => 10, 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 20, 'unit_cost' => 10, 'type' => 'opening']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /** The real way in: a signed-in platform admin, a confirmed password and a reason. */
    protected function enterCompany(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'company_id' => null, 'status' => 'active']));
        session(['auth.password_confirmed_at' => now()->timestamp]);
        $this->post(route('super-admin.impersonate.enter', $this->company), ['reason' => 'دعم فني للعميل'])->assertRedirect(route('dashboard'));
    }

    protected function assertInMainBranch(?Model $document): void
    {
        $this->assertNotNull($document);
        $this->assertSame($this->main->id, $document->branch_id, class_basename($document).' was not saved in the main branch.');
    }

    protected function assertEntryInMainBranch(Model $source): void
    {
        $entry = JournalEntry::withoutGlobalScopes()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->latest('id')->first();
        $this->assertNotNull($entry, 'No journal entry was posted for '.class_basename($source).'.');
        $this->assertSame($this->main->id, $entry->branch_id, 'The entry of '.class_basename($source).' was not posted in the main branch.');
    }

    protected function line(float $quantity = 2, float $price = 50): array
    {
        return ['product_id' => $this->product->id, 'item_name' => 'صنف', 'quantity' => $quantity, 'unit_price' => $price, 'tax_rate' => 15];
    }

    protected function quotation(): array
    {
        return ['customer_id' => $this->customer->id, 'quotation_date' => self::DATE, 'items' => [$this->line()]];
    }

    protected function purchaseInvoice(array $extra = []): array
    {
        return $extra + [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'invoice_date' => self::DATE,
            'payment_method' => 'credit', 'payment_status' => 'unpaid', 'items' => [$this->line()],
        ];
    }

    protected function salesOrder(): array
    {
        return ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => self::DATE, 'items' => [$this->line()]];
    }

    protected function expense(float $amount = 500): array
    {
        return ['title' => 'إيجار', 'category' => 'إيجار', 'amount' => $amount, 'expense_date' => self::DATE, 'payment_method' => 'cash'];
    }

    protected function journal(string $date = self::DATE): array
    {
        $accounts = app(AccountMap::class);

        return ['date' => $date, 'description' => 'قيد يدوي', 'save_as' => 'posted', 'lines' => [
            ['account_id' => $accounts->id('general_expense'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $accounts->id('default_cash'), 'debit' => 0, 'credit' => 100],
        ]];
    }

    // ------------------------------------------------------------ documents

    public function test_a_quotation_goes_to_the_main_branch(): void
    {
        $this->enterCompany();

        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();

        $this->assertInMainBranch(Quotation::latest('id')->first());
    }

    public function test_a_purchase_invoice_with_its_payment_and_entry_goes_to_the_main_branch(): void
    {
        $this->enterCompany();

        $this->post(route('purchases.invoices.store'), $this->purchaseInvoice(['payment_method' => 'cash', 'payment_status' => 'paid']))->assertSessionHasNoErrors();

        $invoice = PurchaseInvoice::latest('id')->first();
        $this->assertInMainBranch($invoice);
        $this->assertInMainBranch(SupplierPayment::latest('id')->first());
        $this->assertEntryInMainBranch($invoice);
    }

    public function test_a_sales_order_and_its_delivery_go_to_the_main_branch(): void
    {
        $this->enterCompany();

        $this->post(route('sales.orders.store'), $this->salesOrder())->assertSessionHasNoErrors();
        $order = SalesOrder::latest('id')->first();
        $this->assertInMainBranch($order);

        $this->post(route('sales.orders.deliver', $order), ['delivery_date' => self::DATE, 'lines' => [['sales_order_item_id' => $order->items()->first()->id, 'quantity' => 2]]])->assertSessionHasNoErrors();
        $note = DeliveryNote::latest('id')->first();
        $this->assertInMainBranch($note);
        $this->assertEntryInMainBranch($note);
    }

    public function test_a_purchase_request_goes_to_the_main_branch(): void
    {
        $this->enterCompany();

        $this->post(route('purchases.requests.store'), ['request_date' => self::DATE, 'items' => [['item_name' => 'صنف', 'quantity' => 1]]])->assertSessionHasNoErrors();

        $this->assertInMainBranch(PurchaseRequest::latest('id')->first());
    }

    public function test_a_purchase_order_its_receipt_and_the_bill_for_it_go_to_the_main_branch(): void
    {
        $this->enterCompany();

        $this->post(route('purchases.orders.store'), ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => self::DATE, 'submit' => true, 'items' => [$this->line()]])->assertSessionHasNoErrors();
        $order = PurchaseOrder::latest('id')->first();
        $this->assertInMainBranch($order);

        $this->post(route('purchases.orders.receive', $order), ['receipt_date' => self::DATE, 'lines' => [['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 2]]])->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::latest('id')->first();
        $this->assertInMainBranch($receipt);
        $this->assertEntryInMainBranch($receipt);

        $this->post(route('purchases.invoices.store-from-receipts'), [
            'supplier_id' => $this->supplier->id, 'supplier_invoice_number' => 'SUP-1', 'invoice_date' => self::DATE, 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'lines' => [['goods_receipt_item_id' => $receipt->items()->first()->id, 'quantity' => 2, 'unit_price' => 50]],
        ])->assertSessionHasNoErrors();
        $this->assertInMainBranch(PurchaseInvoice::latest('id')->first());
    }

    public function test_a_project_goes_to_the_main_branch(): void
    {
        $this->enterCompany();

        $this->post(route('projects.store'), ['customer_id' => $this->customer->id, 'name' => 'مشروع', 'boq' => []])->assertSessionHasNoErrors();

        $this->assertInMainBranch(Project::latest('id')->first());
    }

    public function test_a_manual_journal_entry_goes_to_the_main_branch(): void
    {
        $this->enterCompany();

        $this->post(route('accounting.journals.store'), $this->journal())->assertSessionHasNoErrors();

        $this->assertInMainBranch(JournalEntry::withoutGlobalScopes()->where('entry_type', 'manual')->latest('id')->first());
    }

    /** These go straight to the model (no service picks a branch for them). */
    public function test_records_created_directly_go_to_the_main_branch(): void
    {
        $this->enterCompany();

        $this->post(route('finance.expenses.store'), $this->expense())->assertSessionHasNoErrors();
        $expense = Expense::latest('id')->first();
        $this->assertInMainBranch($expense);
        $this->assertEntryInMainBranch($expense);

        $this->post(route('sales.payments.store'), ['customer_id' => $this->customer->id, 'payment_date' => self::DATE, 'amount' => 100, 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertInMainBranch(CustomerPayment::latest('id')->first());
    }

    public function test_a_sales_invoice_still_goes_to_the_main_branch(): void
    {
        $this->enterCompany();

        $this->post(route('sales.invoices.store'), ['customer_id' => $this->customer->id, 'invoice_date' => self::DATE, 'payment_method' => 'credit', 'payment_status' => 'unpaid', 'items' => [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]]])->assertSessionHasNoErrors();

        $invoice = Invoice::latest('id')->first();
        $this->assertInMainBranch($invoice);
        $this->assertEntryInMainBranch($invoice);
    }

    // ------------------------------------------------------------ which branch

    public function test_forms_start_on_the_main_branch_with_its_tax(): void
    {
        $this->enterCompany();

        foreach (['sales.quotations.create', 'sales.invoices.create', 'sales.orders.create', 'purchases.invoices.create', 'purchases.orders.create', 'projects.create'] as $form) {
            $this->get(route($form))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defaultBranchId', $this->main->id)
                ->has('branches', 1)
                ->where('branches.0.id', $this->main->id)
                ->where('branches.0.tax.rate', 15));
        }
    }

    public function test_a_branch_sent_with_the_form_is_kept(): void
    {
        $this->enterCompany();

        $this->post(route('sales.quotations.store'), ['branch_id' => $this->other->id] + $this->quotation())->assertSessionHasNoErrors();

        $this->assertSame($this->other->id, Quotation::latest('id')->first()->branch_id);
    }

    public function test_an_inactive_main_branch_is_passed_over(): void
    {
        $this->main->update(['is_active' => false]);
        $this->enterCompany();

        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();

        $this->assertSame($this->other->id, Quotation::latest('id')->first()->branch_id);
    }

    public function test_a_company_without_branches_can_still_be_served(): void
    {
        $this->main->delete();
        $this->other->delete();
        $this->enterCompany();

        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();

        $this->assertNull(Quotation::latest('id')->first()->branch_id);
    }

    public function test_a_catalogue_shared_by_every_branch_stays_without_a_branch(): void
    {
        $this->enterCompany();

        $shared = BranchScopeSwitch::off(fn () => BookableService::create(['name' => 'مشتركة', 'duration_minutes' => 30, 'price' => 1, 'tax_rate' => 0]));

        $this->assertNull($shared->branch_id);
    }

    public function test_only_support_mode_falls_back_to_the_main_branch(): void
    {
        // The platform console: the admin is signed in but has not entered this company.
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'company_id' => null, 'status' => 'active']));
        $this->assertNull(app(ActiveBranch::class)->forWrite());

        // A job or a console command: nobody is signed in.
        app('auth')->forgetGuards();
        $this->assertNull(app(ActiveBranch::class)->forWrite());
    }

    // ------------------------------------------------------------ the company's own staff

    public function test_a_limited_user_whose_branch_was_deactivated_is_still_refused(): void
    {
        $this->assertLimitedUserIsRefused(Branch::create(['name_ar' => 'فرع موقوف', 'code' => 'OLD', 'is_active' => false]));
    }

    /** No branch assigned: User::allowedBranchIds gives them the main branch, which is deactivated here. */
    public function test_a_limited_user_with_no_branch_at_all_is_still_refused(): void
    {
        $this->main->update(['is_active' => false]);

        $this->assertLimitedUserIsRefused(null);
    }

    protected function assertLimitedUserIsRefused(?Branch $only): void
    {
        $clerk = $this->userWithRole($this->company, 'sales');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $clerk->givePermissionTo('purchases.invoices.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        if ($only) {
            $clerk->forceFill(['branch_id' => $only->id])->save();
            $clerk->branches()->sync([$only->id]);
        }
        $this->actingAs($clerk->fresh());

        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasErrors(['branch_id' => self::REFUSED]);
        $this->post(route('purchases.invoices.store'), $this->purchaseInvoice())->assertSessionHasErrors(['branch_id' => self::REFUSED]);
        $this->post(route('sales.orders.store'), $this->salesOrder())->assertSessionHasErrors(['branch_id' => self::REFUSED]);

        $this->assertSame(0, Quotation::withoutGlobalScope('branch')->count());
        $this->assertSame(0, PurchaseInvoice::withoutGlobalScope('branch')->count());
        $this->assertSame(0, SalesOrder::withoutGlobalScope('branch')->count());
    }

    // ------------------------------------------------------------ ledger and branch reports

    protected function trialBalanceDebit(): float
    {
        app(ActiveBranch::class)->flush();

        return (float) app(AccountingReports::class)->trialBalance(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))['totals']['debit'];
    }

    public function test_what_support_posts_reaches_the_reports_of_the_main_branch(): void
    {
        $this->enterCompany();
        $this->post(route('finance.expenses.store'), $this->expense(500))->assertSessionHasNoErrors();
        $this->post(route('super-admin.impersonate.leave'))->assertRedirect(route('super-admin.dashboard'));

        $this->actingAs($this->userWithRole($this->company));
        $this->post(route('context.branch'), ['branch_id' => $this->main->id])->assertSessionHasNoErrors();
        $this->assertSame(500.0, $this->trialBalanceDebit());
        $this->post(route('context.branch'), ['branch_id' => $this->other->id])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->trialBalanceDebit());

        // Nothing is left over for a separate «بدون فرع» closing at year end.
        $this->assertNotContains(null, array_column(app(YearEndClosing::class)->branchStatus(FiscalYear::firstOrFail()), 'branch_id'));
    }

    /** The main branch closed for 2026, the other one (with a draft) still open. */
    protected function closeOnlyTheMainBranch(): void
    {
        $this->actingAs($this->userWithRole($this->company));
        $posting = app(PostingService::class);
        foreach ([[$this->main, 1000, 'posted'], [$this->other, 400, 'posted'], [$this->other, 50, 'draft']] as [$branch, $amount, $status]) {
            $posting->post(['date' => '2026-03-05', 'description' => 'بيع', 'branch_id' => $branch->id, 'status' => $status, 'lines' => [['role' => 'default_cash', 'debit' => $amount], ['role' => 'sales_revenue', 'credit' => $amount]]]);
        }

        Carbon::setTestNow('2027-01-10 09:00:00');
        $this->company->update(['trial_ends_at' => now()->addDays(14)]); // still subscribed the following January
        $year = FiscalYear::firstOrFail();
        app(YearEndClosing::class)->close($year, 'passed');
        $this->assertFalse($year->fresh()->isClosed());
    }

    public function test_a_branch_closed_for_the_year_refuses_what_support_posts(): void
    {
        $this->closeOnlyTheMainBranch();
        $this->enterCompany();
        $before = JournalEntry::withoutGlobalScopes()->count();

        $this->post(route('accounting.journals.store'), $this->journal('2026-03-10'))->assertSessionHasErrors('accounting');

        $this->assertSame($before, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_a_branch_closed_for_the_year_refuses_a_manual_entry_from_the_top_bar(): void
    {
        $this->closeOnlyTheMainBranch();
        $before = JournalEntry::withoutGlobalScopes()->count();

        $this->post(route('context.branch'), ['branch_id' => $this->main->id])->assertSessionHasNoErrors();
        $this->post(route('accounting.journals.store'), $this->journal('2026-03-10'))->assertSessionHasErrors('accounting');
        $this->assertSame($before, JournalEntry::withoutGlobalScopes()->count());

        // The branch still open for the year keeps posting.
        $this->post(route('context.branch'), ['branch_id' => $this->other->id])->assertSessionHasNoErrors();
        $this->post(route('accounting.journals.store'), $this->journal('2026-03-10'))->assertSessionHasNoErrors();
        $this->assertSame($before + 1, JournalEntry::withoutGlobalScopes()->count());
    }
}
