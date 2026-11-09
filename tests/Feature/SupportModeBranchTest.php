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
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Subcontract;
use App\Models\SubcontractCertificate;
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
use App\Services\Saas\PlanGuard;
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
 * of their own. Until they choose one in the top bar, what they see covers
 * every branch and what they save goes to the company's main branch instead of
 * to no branch at all; a branch they choose applies as it does for the owner.
 * The rules for the company's own staff stay as they are.
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

    protected ?User $admin = null;

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
    protected function enterCompany(?Company $company = null): void
    {
        $this->actingAs($this->admin ??= User::factory()->create(['is_super_admin' => true, 'company_id' => null, 'status' => 'active']));
        session(['auth.password_confirmed_at' => now()->timestamp]);
        $this->post(route('super-admin.impersonate.enter', $company ?? $this->company), ['reason' => 'دعم فني للعميل'])->assertRedirect(route('dashboard'));
    }

    protected function assertInBranch(Branch $branch, ?Model $document): void
    {
        $this->assertNotNull($document);
        $this->assertSame($branch->id, $document->branch_id, class_basename($document).' was not saved in '.$branch->name_ar.'.');
    }

    protected function assertInMainBranch(?Model $document): void
    {
        $this->assertInBranch($this->main, $document);
    }

    protected function assertEntryInBranch(Branch $branch, Model $source): void
    {
        $entry = JournalEntry::withoutGlobalScopes()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->latest('id')->first();
        $this->assertNotNull($entry, 'No journal entry was posted for '.class_basename($source).'.');
        $this->assertSame($branch->id, $entry->branch_id, 'The entry of '.class_basename($source).' was not posted in '.$branch->name_ar.'.');
    }

    protected function assertEntryInMainBranch(Model $source): void
    {
        $this->assertEntryInBranch($this->main, $source);
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

    // ------------------------------------------------------------ the branch switcher

    protected function chooseBranch(int|string $branch): void
    {
        $this->post(route('context.update'), ['branch_id' => $branch])->assertSessionHasNoErrors();
    }

    /** Ordered and received in whichever branch applies now: 100 sits in GRNI until it is billed. */
    protected function receivedOrder(): GoodsReceipt
    {
        $this->post(route('purchases.orders.store'), ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => self::DATE, 'submit' => true, 'items' => [$this->line()]])->assertSessionHasNoErrors();
        $order = PurchaseOrder::withoutGlobalScope('branch')->latest('id')->first();
        $this->post(route('purchases.orders.receive', $order), ['receipt_date' => self::DATE, 'lines' => [['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 2]]])->assertSessionHasNoErrors();

        return GoodsReceipt::withoutGlobalScope('branch')->latest('id')->first();
    }

    protected function billReceipt(GoodsReceipt $receipt): PurchaseInvoice
    {
        $this->post(route('purchases.invoices.store-from-receipts'), [
            'supplier_id' => $this->supplier->id, 'supplier_invoice_number' => 'SUP-1', 'invoice_date' => self::DATE, 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'lines' => [['goods_receipt_item_id' => $receipt->items()->first()->id, 'quantity' => 2, 'unit_price' => 50]],
        ])->assertSessionHasNoErrors();

        return PurchaseInvoice::withoutGlobalScope('branch')->latest('id')->first();
    }

    /** Goods received and not yet billed, as the ledger of one branch shows it. */
    protected function grniBalance(Branch $branch): float
    {
        $entries = JournalEntry::withoutGlobalScopes()->where('branch_id', $branch->id)->pluck('id');
        $lines = JournalEntryLine::whereIn('journal_entry_id', $entries)->where('account_id', app(AccountMap::class)->id('grni'));

        return (float) $lines->sum('credit') - (float) $lines->sum('debit');
    }

    /** What the admin sees now: the branches covered, the quotations list and the trial balance. */
    protected function assertSees(?array $branches, int $quotations, float $debit): void
    {
        $this->get(route('sales.quotations.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('quotations.data', $quotations));
        $this->assertSame($branches, app(ActiveBranch::class)->scopeIds());
        $this->assertSame($debit, $this->trialBalanceDebit());
    }

    protected function anotherCompany(): array
    {
        $company = Company::factory()->create(['currency' => 'SAR', 'country_code' => 'SA']);

        return app(TenantManager::class)->runAs($company, function () use ($company) {
            app(AccountingSetupService::class)->provision($company);

            return [$company, Branch::create(['name_ar' => 'مكة', 'code' => 'MKK', 'is_main' => true, 'is_active' => true])];
        });
    }

    public function test_the_switcher_is_offered_inside_a_company_and_starts_on_all_branches(): void
    {
        $this->enterCompany();

        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('branchSwitcher.items', 2)
            ->where('branchSwitcher.items.0.id', $this->main->id)
            ->where('branchSwitcher.items.1.id', $this->other->id)
            ->where('branchSwitcher.active', ActiveBranch::ALL)
            ->where('branchSwitcher.can_all', true));
    }

    public function test_the_platform_console_has_no_switcher(): void
    {
        $this->enterCompany();
        $this->post(route('super-admin.impersonate.leave'))->assertRedirect(route('super-admin.dashboard'));

        $this->get(route('super-admin.dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('branchSwitcher', null));
    }

    /** Both companies exist before the first page: nothing but the company can tell their branches apart. */
    public function test_another_company_shows_its_own_branches(): void
    {
        [$second, $branch] = $this->anotherCompany();

        $this->enterCompany();
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('branchSwitcher.items', 2));
        $this->post(route('super-admin.impersonate.leave'))->assertRedirect(route('super-admin.dashboard'));

        $this->enterCompany($second);
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('branchSwitcher.items', 1)
            ->where('branchSwitcher.items.0.id', $branch->id)
            ->where('branchSwitcher.active', $branch->id)
            ->where('branchSwitcher.can_all', false));
    }

    public function test_a_branch_of_another_company_cannot_be_chosen(): void
    {
        [, $foreign] = $this->anotherCompany();
        $this->enterCompany();

        $this->post(route('context.update'), ['branch_id' => $foreign->id])->assertSessionHasErrors('context');
        $this->assertFalse(session()->has(ActiveBranch::SESSION_KEY));

        // Nor does one that reached the session some other way count.
        session([ActiveBranch::SESSION_KEY => $foreign->id]);
        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();
        $this->assertInMainBranch(Quotation::latest('id')->first());
        $this->assertNull(app(ActiveBranch::class)->scopeIds());
    }

    public function test_a_bill_from_a_receipt_in_the_chosen_branch_stays_in_that_branch(): void
    {
        $this->enterCompany();
        $this->chooseBranch($this->other->id);

        $receipt = $this->receivedOrder();
        $this->assertInBranch($this->other, $receipt->purchaseOrder);
        $this->assertInBranch($this->other, $receipt);
        $this->assertEntryInBranch($this->other, $receipt);
        $this->assertSame(100.0, $this->grniBalance($this->other));

        $bill = $this->billReceipt($receipt);

        $this->assertInBranch($this->other, $bill);
        $this->assertEntryInBranch($this->other, $bill);
        $this->assertSame(0.0, $this->grniBalance($this->other));
        $this->assertSame(0.0, $this->grniBalance($this->main));
    }

    /** The known limit of saving with nothing chosen: the bill does not follow the receipt. */
    public function test_with_all_branches_shown_a_bill_still_goes_to_the_main_branch(): void
    {
        $this->enterCompany();
        $this->chooseBranch($this->other->id);
        $receipt = $this->receivedOrder();
        $this->chooseBranch(ActiveBranch::ALL);

        $this->assertInMainBranch($this->billReceipt($receipt));

        $this->assertSame(100.0, $this->grniBalance($this->other));
        $this->assertSame(-100.0, $this->grniBalance($this->main));
    }

    public function test_an_order_converted_from_a_request_follows_the_chosen_branch(): void
    {
        $this->enterCompany();
        $this->chooseBranch($this->other->id);
        $this->post(route('purchases.requests.store'), ['request_date' => self::DATE, 'items' => [['item_name' => 'صنف', 'quantity' => 1]]])->assertSessionHasNoErrors();
        $request = PurchaseRequest::withoutGlobalScope('branch')->latest('id')->first();
        $this->assertInBranch($this->other, $request);
        $request->update(['status' => 'approved']);

        $this->post(route('purchases.requests.convert', $request), ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id])->assertSessionHasNoErrors();

        $this->assertInBranch($this->other, PurchaseOrder::withoutGlobalScope('branch')->latest('id')->first());
    }

    /** Project costs and subcontract certificates carry no branch: their entries follow the one chosen. */
    public function test_project_costs_and_subcontracts_post_in_the_chosen_branch(): void
    {
        $this->enterCompany();
        $this->chooseBranch($this->other->id);
        $this->post(route('projects.store'), ['customer_id' => $this->customer->id, 'name' => 'مشروع', 'boq' => []])->assertSessionHasNoErrors();
        $project = Project::withoutGlobalScope('branch')->latest('id')->first();
        $this->assertInBranch($this->other, $project);

        $this->post(route('projects.costs.store', $project), ['date' => self::DATE, 'category' => 'other', 'description' => 'تكلفة', 'amount' => 100, 'paid_from' => 'accrued'])->assertSessionHasNoErrors();
        $this->assertEntryInBranch($this->other, ProjectCost::latest('id')->first());

        $this->post(route('projects.subcontracts.store', $project), ['supplier_id' => $this->supplier->id, 'scope' => 'أعمال', 'contract_value' => 1000])->assertSessionHasNoErrors();
        $this->post(route('projects.subcontracts.certify', Subcontract::latest('id')->first()), ['date' => self::DATE, 'cumulative_value' => 500])->assertSessionHasNoErrors();
        $this->assertEntryInBranch($this->other, SubcontractCertificate::latest('id')->first());
    }

    public function test_going_back_to_all_branches_saves_in_the_main_branch_again(): void
    {
        $this->enterCompany();

        $this->chooseBranch($this->other->id);
        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();
        $this->assertInBranch($this->other, Quotation::withoutGlobalScope('branch')->latest('id')->first());

        $this->chooseBranch(ActiveBranch::ALL);
        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();
        $this->assertInMainBranch(Quotation::withoutGlobalScope('branch')->latest('id')->first());
    }

    public function test_a_chosen_branch_that_was_deactivated_no_longer_applies(): void
    {
        $this->enterCompany();
        $this->chooseBranch($this->other->id);
        $this->other->update(['is_active' => false]);

        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();

        $this->assertInMainBranch(Quotation::withoutGlobalScope('branch')->latest('id')->first());
        $this->assertNull(app(ActiveBranch::class)->scopeIds());
    }

    public function test_lists_and_reports_cover_every_branch_until_one_is_chosen(): void
    {
        $this->enterCompany();
        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();
        $this->post(route('finance.expenses.store'), $this->expense(500))->assertSessionHasNoErrors();
        $this->chooseBranch($this->other->id);
        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();
        $this->post(route('finance.expenses.store'), $this->expense(200))->assertSessionHasNoErrors();

        // A new visit: nothing chosen, with documents in both branches.
        $this->post(route('super-admin.impersonate.leave'))->assertRedirect(route('super-admin.dashboard'));
        $this->enterCompany();
        $this->assertSees(null, 2, 700.0);

        $this->chooseBranch($this->other->id);
        $this->assertSees([$this->other->id], 1, 200.0);

        $this->chooseBranch($this->main->id);
        $this->assertSees([$this->main->id], 1, 500.0);

        $this->chooseBranch(ActiveBranch::ALL);
        $this->assertSees(null, 2, 700.0);
    }

    public function test_forms_and_tax_follow_the_chosen_branch(): void
    {
        $this->other->update(['country_code' => 'AE', 'currency' => 'AED', 'tax_number' => '100123456700003']);
        $this->enterCompany();

        $this->get(route('sales.quotations.create'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tax.country', 'SA')
            ->where('tax.rate', 15)
            ->where('defaultBranchId', $this->main->id));

        $this->chooseBranch($this->other->id);

        $this->get(route('sales.quotations.create'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tax.country', 'AE')
            ->where('tax.rate', 5)
            ->where('defaultBranchId', $this->other->id)
            ->where('branches.0.id', $this->other->id)
            ->where('branches.0.tax.rate', 5));
    }

    public function test_optional_modules_follow_only_a_chosen_branch(): void
    {
        $this->other->update(['modules' => []]);
        $this->enterCompany();
        $guard = app(PlanGuard::class);
        $this->assertTrue($guard->branchAllows('clinic'));

        $this->chooseBranch($this->other->id);
        $this->assertFalse($guard->branchAllows('clinic'));

        $this->chooseBranch(ActiveBranch::ALL);
        $this->assertTrue($guard->branchAllows('clinic'));
    }

    public function test_every_visit_starts_on_all_branches(): void
    {
        $this->enterCompany();
        $this->chooseBranch($this->other->id);
        $this->post(route('super-admin.impersonate.leave'))->assertRedirect(route('super-admin.dashboard'));
        $this->assertFalse(session()->has(ActiveBranch::SESSION_KEY));

        $this->enterCompany();
        $this->chooseBranch($this->other->id);

        // The visit runs out instead of being ended.
        session(['impersonate_expires_at' => now()->subMinute()->timestamp]);
        $this->get(route('dashboard'));
        $this->assertFalse(session()->has('impersonate_company_id'));
        $this->assertFalse(session()->has(ActiveBranch::SESSION_KEY));

        session([ActiveBranch::SESSION_KEY => $this->other->id]);
        $this->enterCompany();
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('branchSwitcher.active', ActiveBranch::ALL));
    }

    // ------------------------------------------------------------ the switcher for the company's own staff

    public function test_a_limited_user_keeps_their_own_branch_and_cannot_choose_another(): void
    {
        $clerk = $this->userWithRole($this->company, 'sales');
        $clerk->forceFill(['branch_id' => $this->other->id])->save();
        $clerk->branches()->sync([$this->other->id]);
        $this->actingAs($clerk->fresh());

        $this->get(route('sales.quotations.create'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('branchSwitcher.items', 1)
            ->where('branchSwitcher.items.0.id', $this->other->id)
            ->where('branchSwitcher.active', $this->other->id)
            ->where('branchSwitcher.can_all', false)
            ->where('defaultBranchId', $this->other->id));

        $this->post(route('context.update'), ['branch_id' => $this->main->id])->assertSessionHasErrors('context');
        $this->post(route('context.update'), ['branch_id' => ActiveBranch::ALL])->assertSessionHasErrors('context');
        $this->assertSame([$this->other->id], app(ActiveBranch::class)->scopeIds());

        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHasNoErrors();
        $this->assertInBranch($this->other, Quotation::withoutGlobalScope('branch')->latest('id')->first());

        $this->post(route('sales.quotations.store'), ['branch_id' => $this->main->id] + $this->quotation())->assertSessionHasErrors(['branch_id' => self::REFUSED]);
        $this->assertSame(1, Quotation::withoutGlobalScope('branch')->count());
    }

    public function test_the_owner_starts_on_a_branch_and_must_choose_one_before_saving(): void
    {
        $this->actingAs($this->userWithRole($this->company));

        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('branchSwitcher.active', $this->main->id));

        $this->chooseBranch(ActiveBranch::ALL);
        $this->post(route('sales.quotations.store'), $this->quotation())->assertSessionHas('error');

        $this->assertSame(0, Quotation::withoutGlobalScope('branch')->count());
    }
}
