<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BookableService;
use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerPayment;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EndOfServiceSettlement;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentRequest;
use App\Models\Payroll;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\RecurringJournal;
use App\Models\Revenue;
use App\Models\Subcontract;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingControls;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DocumentBackfill;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\RecurringJournalRunner;
use App\Services\ActiveBranch;
use App\Services\HR\EmployeeFinanceService;
use App\Services\HR\PayrollService;
use App\Services\TenantManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A cash box / bank account belongs to its branch. Someone working in branch A cannot pay from, collect into or
 * post to a box of branch B, or to its ledger account, whatever id the request carries: the request is refused with
 * a validation error and nothing is stored or posted. A box shared by every branch (no branch) is anyone's, a
 * document of branch A takes no box of branch B even from a user who sees both, and a transfer between two boxes
 * the user sees is still a transfer.
 *
 * Box ledgers are kept apart in the configuration too (account mapping, company settings, asset categories,
 * customer groups, the chart of accounts, a new box's account), and at posting time whatever the source: an entry
 * of branch A moves no ledger of branch B's boxes unless the caller checked the user may (a manual entry by someone
 * of both branches). Accounts the system posts to for every branch (AccountMap roles) are company-wide: a box left on
 * one by older data shares it with every branch (listed in the accounting exceptions report).
 *
 * World: branches A (main) and B. «صندوق أ» is opened in A, «صندوق ب» and «بنك ب» in B, through the boxes screen,
 * so each has its own ledger account and a 5000 opening balance.
 */
class BranchBoxIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected const DATE = '2026-06-15';

    protected Company $company;

    protected User $owner;

    protected Branch $a;

    protected Branch $b;

    protected BankAccount $boxA;

    protected BankAccount $boxB;

    protected BankAccount $bankB;

    protected Customer $customer;

    protected Supplier $supplier;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(self::DATE.' 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ world

    /** SA by default; BH (no e-invoicing) where a purchase bill must stay editable. */
    protected function world(string $country = 'SA'): void
    {
        $this->company = Company::factory()->create($country === 'BH'
            ? ['currency' => 'BHD', 'country_code' => 'BH']
            : ['currency' => 'SAR', 'country_code' => 'SA']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);

        $this->a = Branch::create(['name_ar' => 'فرع أ', 'code' => 'BRA', 'is_main' => true, 'is_active' => true]);
        $this->b = Branch::create(['name_ar' => 'فرع ب', 'code' => 'BRB', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['branch_id' => $this->a->id, 'name_ar' => 'مستودع أ', 'code' => 'WA', 'is_main' => true, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
        $this->supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);

        $this->inBranch($this->owner, $this->b->id);
        $this->boxB = $this->openBox('صندوق ب', 'cash');
        $this->bankB = $this->openBox('بنك ب', 'bank');
        $this->inBranch($this->owner, $this->a->id);
        $this->boxA = $this->openBox('صندوق أ', 'cash');
        $this->flushSession();

        $this->assertSame([$this->a->id, $this->b->id, $this->b->id], [(int) $this->boxA->branch_id, (int) $this->boxB->branch_id, (int) $this->bankB->branch_id]);
    }

    protected function openBox(string $name, string $type, float $opening = 5000): BankAccount
    {
        $this->post(route('finance.banks.store'), ['name' => $name, 'type' => $type, 'currency' => $this->company->currency, 'opening_balance' => $opening])
            ->assertSessionHasNoErrors();

        return BankAccount::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', $name)->firstOrFail();
    }

    /** A box shared by every branch (no branch), with a ledger account of its own. */
    protected function sharedBox(): BankAccount
    {
        $this->inBranch($this->owner, $this->a->id);
        $box = $this->openBox('صندوق مشترك', 'cash', 0);
        DB::table('bank_accounts')->where('id', $box->id)->update(['branch_id' => null]);
        $this->flushSession();

        return $box->fresh();
    }

    /** A user of the given branches (default branch = the first one). */
    protected function userIn(array $branches, string $role, array $extraPermissions = []): User
    {
        $user = $this->userWithRole($this->company, $role, ['branch_id' => $branches[0]->id]);
        $user->branches()->sync(collect($branches)->pluck('id')->all());
        if ($extraPermissions) {
            $registrar = app(PermissionRegistrar::class);
            $previous = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($this->company->id);
            $user->givePermissionTo($extraPermissions);
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }

        return $user->fresh();
    }

    /** Sign in as $user with a clean session; optionally choose a branch (or 'all') in the top bar. */
    protected function inBranch(User $user, int|string|null $branch = null): void
    {
        $this->flushSession();
        app(ActiveBranch::class)->flush();
        $this->actingAs($user->fresh());
        if ($branch !== null) {
            $this->post(route('context.branch'), ['branch_id' => $branch])->assertSessionHasNoErrors();
        }
    }

    // ------------------------------------------------------------------ observation

    protected function role(string $role): int
    {
        return (int) app(AccountMap::class)->id($role);
    }

    protected function mark(): int
    {
        return (int) DB::table('journal_entry_lines')->max('id');
    }

    /** Journal lines written since $mark: [account id, debit, credit, entry branch]. */
    protected function linesSince(int $mark): array
    {
        return DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.id', '>', $mark)->orderBy('l.id')
            ->get(['l.account_id', 'l.debit', 'l.credit', 'e.branch_id'])
            ->map(fn ($l) => [(int) $l->account_id, round((float) $l->debit, 2), round((float) $l->credit, 2), $l->branch_id === null ? null : (int) $l->branch_id])
            ->all();
    }

    /** Ledger balance and stored current_balance of every box, and the default cash / bank accounts. */
    protected function balances(): array
    {
        $ledger = fn ($id) => round((float) DB::table('accounts')->where('id', $id)->value('balance'), 2);
        $out = [];
        foreach (DB::table('bank_accounts')->where('company_id', $this->company->id)->orderBy('id')->get() as $box) {
            $out[$box->name] = [$box->account_id ? $ledger($box->account_id) : null, round((float) $box->current_balance, 2)];
        }
        $out['default_cash'] = $ledger($this->role('default_cash'));
        $out['default_bank'] = $ledger($this->role('default_bank'));

        return $out;
    }

    /** Refused on $fields, with nothing posted and no balance moved since $mark / $before. */
    protected function assertRefused(TestResponse $response, string|array $fields, int $mark, array $before): void
    {
        $response->assertSessionHasErrors($fields);
        $this->assertSame([], $this->linesSince($mark), 'Nothing may be posted.');
        $this->assertSame($before, $this->balances(), 'No balance may move.');
    }

    protected function assertLine(array $lines, int $accountId, float $debit, float $credit): void
    {
        $this->assertContains([$accountId, $debit, $credit], array_map(fn ($l) => array_slice($l, 0, 3), $lines), json_encode($lines));
    }

    protected function receipt(BankAccount $box, array $extra = []): array
    {
        return $extra + ['customer_id' => $this->customer->id, 'payment_date' => self::DATE, 'amount' => 100, 'payment_method' => 'cash', 'bank_account_id' => $box->id];
    }

    // ------------------------------------------------------------------ vouchers and documents

    public function test_vouchers_refuse_a_box_of_another_branch_and_keep_nothing(): void
    {
        $this->world();
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->post(route('sales.payments.store'), $this->receipt($this->boxB)), 'bank_account_id', $mark, $before);
        $this->assertRefused($this->post(route('sales.payments.store'), $this->receipt($this->bankB, [
            'payment_method' => 'cheque', 'reference_number' => 'CHQ-IN-1', 'cheque_due_date' => self::DATE,
        ])), 'bank_account_id', $mark, $before);
        $this->assertRefused($this->post(route('purchases.payments.store'), [
            'supplier_id' => $this->supplier->id, 'payment_date' => self::DATE, 'amount' => 40, 'payment_method' => 'cash', 'bank_account_id' => $this->boxB->id,
        ]), 'bank_account_id', $mark, $before);
        $this->assertRefused($this->post(route('finance.expenses.store'), [
            'title' => 'ضيافة', 'category' => 'ضيافة', 'amount' => 300, 'expense_date' => self::DATE, 'payment_method' => 'cash', 'bank_account_id' => $this->boxB->id,
        ]), 'bank_account_id', $mark, $before);
        $this->assertRefused($this->post(route('finance.revenues.store'), [
            'title' => 'عمولة', 'category' => 'عام', 'amount' => 400, 'revenue_date' => self::DATE, 'payment_method' => 'cash', 'bank_account_id' => $this->boxB->id,
        ]), 'bank_account_id', $mark, $before);
        $this->assertRefused($this->post(route('accounting.cheques.store'), [
            'direction' => 'incoming', 'cheque_number' => 'CHQ-IN-2', 'bank_account_id' => $this->bankB->id, 'party_type' => 'customer',
            'party_id' => $this->customer->id, 'amount' => 300, 'issue_date' => self::DATE, 'due_date' => self::DATE,
        ]), 'bank_account_id', $mark, $before);
        $this->assertRefused($this->post(route('finance.payment-requests.store'), [
            'request_date' => self::DATE, 'kind' => 'expense', 'payee_type' => 'other', 'payee_name' => 'مورد', 'purpose' => 'ضيافة',
            'amount' => 250, 'payment_method' => 'cash', 'bank_account_id' => $this->boxB->id, 'send_for_approval' => true,
        ]), 'bank_account_id', $mark, $before);

        foreach ([CustomerPayment::class, SupplierPayment::class, Expense::class, Revenue::class, Cheque::class, PaymentRequest::class] as $model) {
            $this->assertSame(0, $model::withoutGlobalScopes()->count(), class_basename($model).' was stored.');
        }
    }

    public function test_a_box_of_the_documents_own_branch_or_a_shared_one_is_still_taken(): void
    {
        $this->world();
        $shared = $this->sharedBox();
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);

        $mark = $this->mark();
        $this->post(route('sales.payments.store'), $this->receipt($this->boxA))->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $this->boxA->account_id, 100, 0);

        $mark = $this->mark();
        $this->post(route('sales.payments.store'), $this->receipt($shared))->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $shared->account_id, 100, 0);

        $mark = $this->mark();
        $this->post(route('finance.expenses.store'), [
            'title' => 'ضيافة', 'category' => 'ضيافة', 'amount' => 300, 'expense_date' => self::DATE, 'payment_method' => 'cash', 'bank_account_id' => $shared->id,
        ])->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $shared->account_id, 0, 300);

        // A user of branch B takes the shared box too.
        $this->inBranch($this->userIn([$this->b], 'accountant'));
        $mark = $this->mark();
        $this->post(route('sales.payments.store'), $this->receipt($shared))->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $shared->account_id, 100, 0);
    }

    /** The owner sees every box from «all branches»; a document of branch A still takes none of branch B's. */
    public function test_a_user_who_sees_both_branches_cannot_put_a_branch_b_box_on_a_branch_a_document(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $this->post(route('finance.revenues.store'), [
            'title' => 'عمولة', 'category' => 'عام', 'amount' => 400, 'revenue_date' => self::DATE, 'payment_method' => 'cash', 'bank_account_id' => $this->boxA->id,
        ])->assertSessionHasNoErrors();
        $revenue = Revenue::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->post(route('purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'branch_id' => $this->a->id, 'invoice_date' => self::DATE,
            'payment_method' => 'credit', 'payment_status' => 'unpaid', 'items' => [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();
        $bill = PurchaseInvoice::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->inBranch($this->owner, 'all');
        $before = $this->balances();
        $mark = $this->mark();

        // Editing the branch-A revenue (this screen does not ask for a branch to be chosen).
        $this->assertRefused($this->put(route('finance.revenues.update', $revenue->id), [
            'title' => 'عمولة', 'category' => 'عام', 'amount' => 400, 'revenue_date' => self::DATE, 'payment_method' => 'cash', 'bank_account_id' => $this->boxB->id,
        ]), 'bank_account_id', $mark, $before);
        $this->assertSame($this->boxA->id, (int) $revenue->fresh()->bank_account_id);

        // Paying the branch-A bill (a payment takes its bill's branch), e.g. from a payment request planted earlier.
        $this->assertRefused($this->post(route('purchases.payments.store'), [
            'supplier_id' => $this->supplier->id, 'purchase_invoice_id' => $bill->id, 'payment_date' => self::DATE, 'amount' => 120,
            'payment_method' => 'cash', 'bank_account_id' => $this->boxB->id,
        ]), 'bank_account_id', $mark, $before);
        $this->assertSame(0, SupplierPayment::withoutGlobalScopes()->count());

        // The bill's own branch box is fine.
        $this->post(route('purchases.payments.store'), [
            'supplier_id' => $this->supplier->id, 'purchase_invoice_id' => $bill->id, 'payment_date' => self::DATE, 'amount' => 120,
            'payment_method' => 'cash', 'bank_account_id' => $this->boxA->id,
        ])->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $this->boxA->account_id, 0, 120);
    }

    public function test_purchase_bill_payment_rows_take_only_boxes_of_the_bills_branch(): void
    {
        $this->world('BH');
        $bill = fn (?int $branch, array $rows) => array_filter([
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'branch_id' => $branch, 'invoice_date' => self::DATE,
            'payments' => $rows, 'items' => [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 10]],
        ], fn ($v) => $v !== null);
        $buyer = $this->userIn([$this->a], 'purchasing');
        $this->inBranch($buyer);
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->post(route('purchases.invoices.store'), $bill(null, [
            ['method' => 'cash', 'bank_account_id' => $this->boxB->id, 'amount' => 50],
            ['method' => 'bank_transfer', 'bank_account_id' => $this->bankB->id, 'amount' => 30],
        ])), ['payments.0.bank_account_id', 'payments.1.bank_account_id'], $mark, $before);
        $this->assertSame(0, PurchaseInvoice::withoutGlobalScopes()->count());

        // The owner files it in branch A with box A, then saves it again from «all branches» with branch B's box.
        $this->inBranch($this->owner, $this->a->id);
        $this->post(route('purchases.invoices.store'), $bill($this->a->id, [['method' => 'cash', 'bank_account_id' => $this->boxA->id, 'amount' => 50]]))->assertSessionHasNoErrors();
        $saved = PurchaseInvoice::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->post(route('context.branch'), ['branch_id' => 'all'])->assertSessionHasNoErrors();
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->put(route('purchases.invoices.update', $saved->id), $bill($this->a->id, [
            ['method' => 'cash', 'bank_account_id' => $this->boxB->id, 'amount' => 50],
        ])), 'payments.0.bank_account_id', $mark, $before);
        $this->assertSame([$this->boxA->id], SupplierPayment::withoutGlobalScopes()->where('purchase_invoice_id', $saved->id)->pluck('bank_account_id')->map(fn ($id) => (int) $id)->all());
    }

    /** A cheque stored with branch B's bank (before this check existed) is not cleared through it, whoever clears it. */
    public function test_a_cheque_holding_another_branchs_bank_is_not_cleared_through_it(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $cheque = function (string $number): Cheque {
            $this->post(route('accounting.cheques.store'), [
                'direction' => 'incoming', 'cheque_number' => $number, 'party_type' => 'customer', 'party_id' => $this->customer->id,
                'amount' => 300, 'issue_date' => self::DATE, 'due_date' => self::DATE,
            ])->assertSessionHasNoErrors();

            return Cheque::withoutGlobalScopes()->where('cheque_number', $number)->firstOrFail();
        };
        $planted = $cheque('CHQ-1');
        $bounced = $cheque('CHQ-2');
        DB::table('cheques')->whereIn('id', [$planted->id, $bounced->id])->update(['bank_account_id' => $this->bankB->id]);

        $manager = $this->userIn([$this->a, $this->b], 'accountant');
        $this->inBranch($manager, 'all');
        $before = $this->balances();
        $mark = $this->mark();
        $clear = fn (array $extra = []) => $this->post(route('accounting.cheques.transition', $planted->id), $extra + ['status' => 'cleared', 'date' => self::DATE]);

        $this->assertRefused($clear(['bank_account_id' => $this->bankB->id]), 'bank_account_id', $mark, $before); // what the register sends back
        $this->assertRefused($clear(), 'bank_account_id', $mark, $before); // the stored bank is checked too
        $this->assertSame('pending', $planted->fresh()->status);

        // Bouncing posts nothing to a bank: the stored one does not stand in the way.
        $this->post(route('accounting.cheques.transition', $bounced->id), ['status' => 'bounced', 'date' => self::DATE, 'bank_account_id' => $this->bankB->id])->assertSessionHasNoErrors();
        $this->assertSame('bounced', $bounced->fresh()->status);
        $this->assertSame($before[$this->bankB->name], $this->balances()[$this->bankB->name]);
    }

    // ------------------------------------------------------------------ ledger accounts of boxes

    public function test_manual_and_recurring_entries_refuse_the_ledger_account_of_another_branchs_box(): void
    {
        $this->world();
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);
        $lines = [
            ['account_id' => $this->boxA->account_id, 'debit' => 700, 'credit' => 0],
            ['account_id' => $this->bankB->account_id, 'debit' => 0, 'credit' => 700],
        ];
        $entries = JournalEntry::withoutGlobalScopes()->count();
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->post(route('accounting.journals.store'), ['date' => self::DATE, 'description' => 'تسوية', 'save_as' => 'posted', 'lines' => $lines]), 'lines.1.account_id', $mark, $before);
        $this->assertRefused($this->post(route('accounting.journals.store'), ['date' => self::DATE, 'description' => 'تسوية', 'save_as' => 'draft', 'lines' => $lines]), 'lines.1.account_id', $mark, $before);
        $this->assertRefused($this->post(route('accounting.recurring.store'), [
            'name' => 'شهري', 'description' => 'تسوية', 'frequency' => 'monthly', 'next_run_date' => '2026-07-01', 'lines' => $lines,
        ]), 'lines.1.account_id', $mark, $before);
        $this->assertSame($entries, JournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, RecurringJournal::withoutGlobalScopes()->count());

        // Another branch's box named as a line's sub-account gets the answer of a box that does not exist.
        foreach ([$this->bankB->id, 999999] as $id) {
            $this->post(route('accounting.journals.store'), ['date' => self::DATE, 'description' => 'تسوية', 'save_as' => 'posted', 'lines' => [
                ['account_id' => $this->boxA->account_id, 'debit' => 5, 'credit' => 0, 'party_type' => 'bank', 'party_id' => $id],
                ['account_id' => $this->role('general_expense'), 'debit' => 0, 'credit' => 5],
            ]])->assertSessionHasErrors(['lines.0.party_id' => 'الطرف المحدد في السطر غير موجود في منشأتك.']);
        }

        // Its own box's ledger account is fine.
        $this->post(route('accounting.journals.store'), ['date' => self::DATE, 'description' => 'إيداع', 'save_as' => 'posted', 'lines' => [
            ['account_id' => $this->boxA->account_id, 'debit' => 50, 'credit' => 0],
            ['account_id' => $this->role('other_revenue'), 'debit' => 0, 'credit' => 50],
        ]])->assertSessionHasNoErrors();

        // Someone who sees branch B may post to its bank's ledger account.
        $manager = $this->userIn([$this->a, $this->b], 'accountant');
        $this->inBranch($manager, $this->b->id);
        $mark = $this->mark();
        $this->post(route('accounting.journals.store'), ['date' => self::DATE, 'description' => 'تحويل داخلي', 'save_as' => 'posted', 'lines' => [
            ['account_id' => $this->bankB->account_id, 'debit' => 200, 'credit' => 0],
            ['account_id' => $this->boxB->account_id, 'debit' => 0, 'credit' => 200],
        ]])->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $this->bankB->account_id, 200, 0);
        $this->post(route('accounting.recurring.store'), [
            'name' => 'شهري', 'description' => 'تحويل', 'frequency' => 'monthly', 'next_run_date' => '2026-07-01', 'lines' => [
                ['account_id' => $this->bankB->account_id, 'debit' => 10, 'credit' => 0],
                ['account_id' => $this->boxB->account_id, 'debit' => 0, 'credit' => 10],
            ],
        ])->assertSessionHasNoErrors();
    }

    public function test_voucher_accounts_are_posting_accounts_of_their_own_type(): void
    {
        $this->world();
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);
        $before = $this->balances();
        $mark = $this->mark();

        foreach ([$this->boxB->account_id, $this->role('default_cash')] as $account) {
            $this->assertRefused($this->post(route('finance.expenses.store'), [
                'title' => 'ضيافة', 'category' => 'ضيافة', 'amount' => 300, 'expense_date' => self::DATE, 'payment_method' => 'cash', 'account_id' => $account,
            ]), 'account_id', $mark, $before);
            $this->assertRefused($this->post(route('finance.revenues.store'), [
                'title' => 'عمولة', 'category' => 'عام', 'amount' => 400, 'revenue_date' => self::DATE, 'payment_method' => 'cash', 'account_id' => $account,
            ]), 'account_id', $mark, $before);
            $this->assertRefused($this->post(route('finance.payment-requests.store'), [
                'request_date' => self::DATE, 'kind' => 'expense', 'payee_type' => 'other', 'payee_name' => 'مورد', 'purpose' => 'ضيافة',
                'amount' => 250, 'payment_method' => 'cash', 'account_id' => $account, 'send_for_approval' => true,
            ]), 'account_id', $mark, $before);
        }
        foreach ([Expense::class, Revenue::class, PaymentRequest::class] as $model) {
            $this->assertSame(0, $model::withoutGlobalScopes()->count(), class_basename($model).' was stored.');
        }

        // What the forms list goes through.
        $this->post(route('finance.expenses.store'), [
            'title' => 'ضيافة', 'category' => 'ضيافة', 'amount' => 300, 'expense_date' => self::DATE, 'payment_method' => 'cash',
            'account_id' => $this->role('general_expense'), 'bank_account_id' => $this->boxA->id,
        ])->assertSessionHasNoErrors();
        $lines = $this->linesSince($mark);
        $this->assertLine($lines, $this->role('general_expense'), 300, 0);
        $this->assertLine($lines, (int) $this->boxA->account_id, 0, 300);
        $this->post(route('finance.revenues.store'), [
            'title' => 'عمولة', 'category' => 'عام', 'amount' => 400, 'revenue_date' => self::DATE, 'payment_method' => 'cash', 'account_id' => $this->role('other_revenue'),
        ])->assertSessionHasNoErrors();
    }

    public function test_a_new_box_gets_its_own_ledger_account_and_never_another_branchs(): void
    {
        $this->world();
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->post(route('finance.banks.store'), [
            'name' => 'بنك آخر', 'type' => 'bank', 'currency' => 'SAR', 'opening_balance' => 0, 'account_id' => $this->bankB->account_id,
        ]), 'account_id', $mark, $before);
        $this->assertNull(BankAccount::withoutGlobalScopes()->where('name', 'بنك آخر')->first());

        $this->post(route('finance.banks.store'), ['name' => 'بنك آخر', 'type' => 'bank', 'currency' => 'SAR', 'opening_balance' => 0])->assertSessionHasNoErrors();
        $box = BankAccount::withoutGlobalScopes()->where('name', 'بنك آخر')->firstOrFail();
        $this->assertSame($this->a->id, (int) $box->branch_id);
        $this->assertNotContains((int) $box->account_id, [$this->role('default_cash'), $this->role('default_bank'), (int) $this->boxA->account_id, (int) $this->boxB->account_id, (int) $this->bankB->account_id]);
    }

    // ------------------------------------------------------------------ point of sale

    public function test_a_pos_register_drawer_is_a_box_of_its_branch_and_an_older_one_is_not_used(): void
    {
        $this->world();
        $supervisor = $this->userIn([$this->a], 'cashier', ['pos.registers.view', 'pos.registers.create', 'pos.registers.edit']);
        $this->inBranch($supervisor);
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->post(route('pos.registers.store'), [
            'name' => 'كاشير ب', 'warehouse_id' => $this->warehouse->id, 'bank_account_id' => $this->boxB->id, 'is_active' => true,
        ]), 'bank_account_id', $mark, $before);
        $this->assertSame(0, PosRegister::withoutGlobalScopes()->count());

        $this->post(route('pos.registers.store'), ['name' => 'كاشير أ', 'warehouse_id' => $this->warehouse->id, 'bank_account_id' => $this->boxA->id, 'is_active' => true])->assertSessionHasNoErrors();
        $register = PosRegister::withoutGlobalScopes()->where('name', 'كاشير أ')->firstOrFail();
        $this->assertSame($this->a->id, (int) $register->branch_id);

        // A register linked to branch B's box before this check existed: no sale or shift close goes through that box.
        DB::table('pos_registers')->where('id', $register->id)->update(['bank_account_id' => $this->boxB->id]);
        $this->post(route('pos.session.open'), ['pos_register_id' => $register->id, 'opening_cash' => 0])->assertSessionHasNoErrors();
        $session = PosSession::withoutGlobalScopes()->where('pos_register_id', $register->id)->firstOrFail();
        $product = Product::create(['name_ar' => 'خدمة', 'sku' => 'SRV-1', 'selling_price' => 1000, 'track_inventory' => false, 'is_active' => true]);
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->post(route('pos.order.process'), [
            'session_id' => $session->id, 'payment_method' => 'cash', 'paid_amount' => 1150,
            'items' => [['product_id' => $product->id, 'item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ]), ['session_id' => 'صندوق نقطة البيع ليس من فرع الوردية؛ اربطها بصندوق من فرعها (من إعدادات نقاط البيع).'], $mark, $before);
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());

        // Counted 50 over an expected 0: the overage would go to the drawer's box.
        $this->assertRefused($this->post(route('pos.session.close', $session->id), ['closing_cash' => 50, 'deposit_bank_account_id' => $this->boxA->id]), 'closing_cash', $mark, $before);
        $this->assertSame('opened', $session->fresh()->status);
    }

    public function test_a_shift_deposit_goes_only_to_a_box_of_the_shifts_branch(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $this->post(route('pos.registers.store'), ['name' => 'كاشير أ', 'warehouse_id' => $this->warehouse->id, 'bank_account_id' => $this->boxA->id, 'is_active' => true])->assertSessionHasNoErrors();
        $register = PosRegister::withoutGlobalScopes()->where('name', 'كاشير أ')->firstOrFail();
        $cashier = $this->userIn([$this->a], 'cashier');
        $this->inBranch($cashier);
        $this->post(route('pos.session.open'), ['pos_register_id' => $register->id, 'opening_cash' => 0])->assertSessionHasNoErrors();
        $session = PosSession::withoutGlobalScopes()->where('pos_register_id', $register->id)->firstOrFail();
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->post(route('pos.session.close', $session->id), ['closing_cash' => 0, 'deposit_bank_account_id' => $this->boxB->id]), 'deposit_bank_account_id', $mark, $before);
        $this->assertSame('opened', $session->fresh()->status);

        $this->post(route('pos.session.close', $session->id), ['closing_cash' => 0, 'deposit_bank_account_id' => $this->boxA->id])->assertSessionHasNoErrors();
        $this->assertSame('closed', $session->fresh()->status);
    }

    // ------------------------------------------------------------------ reconciliation, HR

    public function test_a_reconciliation_is_opened_only_for_a_bank_the_user_sees(): void
    {
        $this->world();
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);

        $this->post(route('accounting.reconciliations.store'), ['bank_account_id' => $this->bankB->id, 'statement_date' => '2026-06-30', 'statement_balance' => 123456.78])
            ->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, BankReconciliation::withoutGlobalScopes()->count());

        $this->post(route('accounting.reconciliations.store'), ['bank_account_id' => $this->boxA->id, 'statement_date' => '2026-06-30', 'statement_balance' => 5000])
            ->assertSessionHasNoErrors();
        $this->assertSame([$this->boxA->id], BankReconciliation::withoutGlobalScopes()->pluck('bank_account_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_loan_is_paid_only_from_a_box_of_the_users_branch(): void
    {
        $this->world();
        $outsider = Company::factory()->create();
        $foreignBox = DB::table('bank_accounts')->insertGetId([
            'company_id' => $outsider->id, 'name' => 'صندوق منشأة أخرى', 'type' => 'cash', 'currency' => 'SAR',
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employee = Employee::create([
            'name' => 'سالم', 'job_title' => 'محاسب', 'department' => 'المالية', 'hire_date' => '2020-01-01', 'nationality' => 'SA', 'basic_salary' => 6000,
            'housing_allowance' => 0, 'transport_allowance' => 0, 'other_allowances' => 0, 'total_salary' => 6000, 'status' => 'active',
            'gosi_registered' => false, 'employee_number' => 'E-1', 'branch_id' => $this->a->id,
        ]);
        $loan = EmployeeLoan::create(['employee_id' => $employee->id, 'date' => self::DATE, 'amount' => 1000, 'monthly_installment' => 500, 'first_deduction_month' => '2026-07-01', 'status' => 'pending']);
        $hr = $this->userIn([$this->a], 'hr_manager');
        $this->inBranch($hr);
        $before = $this->balances();
        $mark = $this->mark();

        // Refused by the request's own rule, before anything is paid out (not by a check further down).
        $this->mock(EmployeeFinanceService::class, fn ($mock) => $mock->shouldNotReceive('approveLoan'));
        foreach ([$this->boxB->id, $foreignBox] as $box) {
            $this->assertRefused($this->post(route('hr.loans.decide', $loan), ['approve' => true, 'bank_account_id' => $box]), 'bank_account_id', $mark, $before);
        }
        $this->app->forgetInstance(EmployeeFinanceService::class);
        $this->assertSame('pending', $loan->fresh()->status);
        $this->assertNull($loan->fresh()->bank_account_id);

        $this->post(route('hr.loans.decide', $loan), ['approve' => true, 'bank_account_id' => $this->boxA->id])->assertSessionHasNoErrors();
        $this->assertSame('active', $loan->fresh()->status);
        $this->assertLine($this->linesSince($mark), (int) $this->boxA->account_id, 0, 1000);
    }

    // ------------------------------------------------------------------ posting, transfers

    /** A box id the user cannot see is refused at posting too: never swapped for the default cash / bank account. */
    public function test_posting_refuses_an_unseen_box_instead_of_using_the_default_account(): void
    {
        $this->world();
        $this->inBranch($this->userIn([$this->a], 'accountant'));
        $poster = app(DocumentPoster::class);

        $this->assertSame((int) $this->boxA->account_id, $poster->cashAccount($this->boxA->id, 'cash'));
        $this->assertSame($this->role('default_cash'), $poster->cashAccount(null, 'cash'));
        $this->assertSame($this->role('default_bank'), $poster->cashAccount(null, 'bank_transfer'));
        try {
            $poster->cashAccount($this->boxB->id, 'cash');
            $this->fail('Branch B\'s box was taken.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bank_account_id', $e->errors());
        }
    }

    public function test_a_transfer_takes_any_two_boxes_the_user_sees(): void
    {
        $this->world();
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->post(route('finance.transfers.store'), ['from_account_id' => $this->boxB->id, 'to_account_id' => $this->boxA->id, 'amount' => 100, 'transfer_date' => self::DATE]), 'from_account_id', $mark, $before);
        $this->assertRefused($this->post(route('finance.transfers.store'), ['from_account_id' => $this->boxA->id, 'to_account_id' => $this->boxB->id, 'amount' => 100, 'transfer_date' => self::DATE]), 'to_account_id', $mark, $before);

        // A platform admin in support mode sees every branch's boxes (and files in the main branch): a transfer between branches goes through.
        $this->flushSession();
        app(ActiveBranch::class)->flush();
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'company_id' => null, 'status' => 'active']));
        session(['auth.password_confirmed_at' => now()->timestamp]);
        $this->post(route('super-admin.impersonate.enter', $this->company), ['reason' => 'دعم فني للعميل'])->assertRedirect(route('dashboard'));

        $this->post(route('finance.transfers.store'), ['from_account_id' => $this->boxB->id, 'to_account_id' => $this->boxA->id, 'amount' => 100, 'transfer_date' => self::DATE])
            ->assertSessionHasNoErrors();
        $lines = $this->linesSince($mark);
        $this->assertLine($lines, (int) $this->boxA->account_id, 100, 0);
        $this->assertLine($lines, (int) $this->boxB->account_id, 0, 100);
    }

    // ------------------------------------------------------------------ every document that has a branch of its own

    /** @return array<string, array{0: string}> */
    public static function documentSites(): array
    {
        $sites = ['receipt_on_invoice', 'sales_invoice', 'credit_note_items', 'credit_note_amount', 'bill_payment', 'purchase_return', 'landed_cost', 'order_to_bill',
            'expense_edit', 'payment_request_edit', 'cheque_clearing', 'asset_purchase', 'asset_disposal', 'project_advance', 'project_cost', 'subcontract_advance',
            'payroll_payment', 'booking_deposit', 'pos_float', 'pos_register_edit'];

        return array_combine($sites, array_map(fn ($site) => [$site], $sites));
    }

    /**
     * Every request that takes a box for a document of a known branch (its own, or its invoice's, bill's, order's, project's,
     * payroll's, resource's, register's). From «all branches» the owner sees branch B's boxes too: a branch-A document still
     * takes none of them, on its own field, before anything is stored or posted. Its own branch's box, and a shared one, are taken.
     */
    #[DataProvider('documentSites')]
    public function test_a_document_of_branch_a_takes_a_box_of_branch_a_or_a_shared_one_only(string $site): void
    {
        $this->world();
        $shared = $this->sharedBox();
        $this->inBranch($this->owner, $this->a->id);
        ['field' => $field, 'send' => $send, 'boxes' => $boxes, 'kept' => $kept] = $this->site($site, $shared);

        $this->post(route('context.branch'), ['branch_id' => 'all'])->assertSessionHasNoErrors();
        $before = $this->balances();
        $mark = $this->mark();
        $this->assertRefused($send($this->boxB), $field, $mark, $before);
        if ($kept) {
            $this->assertFalse($kept($this->boxB), "{$site}: branch B's box was kept.");
        }

        $this->post(route('context.branch'), ['branch_id' => $this->a->id])->assertSessionHasNoErrors();
        foreach ($boxes === 'both' ? [$this->boxA, $shared] : [$boxes === 'shared' ? $shared : $this->boxA] as $box) {
            $mark = $this->mark();
            $send($box)->assertSessionHasNoErrors();
            if ($kept) {
                $this->assertTrue($kept($box), "{$site}: {$box->name} was not kept.");
            } else {
                $this->assertContains((int) $box->account_id, array_column($this->linesSince($mark), 0), "{$site}: nothing posted on {$box->name}.");
            }
        }
    }

    /**
     * The site's branch-A parent, made by the owner in branch A, and how to send the request with a box:
     * field (the error key), send(box), boxes (both | own | shared: what is accepted afterwards), kept(box) for a request that stores the box without posting.
     */
    protected function site(string $site, BankAccount $shared): array
    {
        $date = self::DATE;
        $make = fn (string $field, Closure $send, string $boxes = 'both', ?Closure $kept = null) => compact('field', 'send', 'boxes', 'kept');

        return match ($site) {
            'receipt_on_invoice' => (function () use ($make) {
                $invoice = $this->salesInvoiceA();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('sales.payments.store'), $this->receipt($box, ['invoice_id' => $invoice->id, 'amount' => 10])));
            })(),
            'sales_invoice' => $make('bank_account_id', fn (BankAccount $box) => $this->post(route('sales.invoices.store'), $this->invoicePayload([
                'payment_method' => 'cash', 'payment_status' => 'paid', 'paid_amount' => 115, 'bank_account_id' => $box->id,
            ]))),
            'credit_note_items' => (function () use ($make, $date) {
                $invoice = $this->salesInvoiceA(['payment_method' => 'cash', 'payment_status' => 'paid', 'paid_amount' => 230, 'bank_account_id' => $this->boxA->id], 2);
                $item = $invoice->items()->firstOrFail();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('sales.returns.store'), [
                    'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => $date, 'reason' => 'مرتجع', 'refund_method' => 'cash',
                    'bank_account_id' => $box->id, 'items' => [['invoice_item_id' => $item->id, 'quantity' => 1]],
                ]));
            })(),
            'credit_note_amount' => (function () use ($make, $date) {
                $invoice = $this->salesInvoiceA(['payment_method' => 'cash', 'payment_status' => 'paid', 'paid_amount' => 115, 'bank_account_id' => $this->boxA->id]);

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('sales.returns.store'), [
                    'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => $date, 'subtotal' => 10, 'tax_amount' => 1.5, 'total' => 11.5,
                    'reason' => 'خصم', 'status' => 'refunded', 'refund_method' => 'cash', 'bank_account_id' => $box->id,
                ]));
            })(),
            'bill_payment' => (function () use ($make, $date) {
                $bill = $this->billA();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('purchases.payments.store'), [
                    'supplier_id' => $this->supplier->id, 'purchase_invoice_id' => $bill->id, 'payment_date' => $date, 'amount' => 10, 'payment_method' => 'cash', 'bank_account_id' => $box->id,
                ]));
            })(),
            'purchase_return' => (function () use ($make, $date) {
                $bill = $this->billA();
                $item = $bill->items()->firstOrFail();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('purchases.returns.store'), [
                    'purchase_invoice_id' => $bill->id, 'return_date' => $date, 'settlement' => 'cash', 'bank_account_id' => $box->id,
                    'items' => [['purchase_invoice_item_id' => $item->id, 'quantity' => 1]],
                ]));
            })(),
            'landed_cost' => (function () use ($make, $date) {
                $bill = $this->billA();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('purchases.landed-costs.store'), [
                    'purchase_invoice_id' => $bill->id, 'date' => $date, 'description' => 'شحن', 'amount' => 50, 'paid_from' => 'cash', 'bank_account_id' => $box->id,
                ]));
            })(),
            'order_to_bill' => (function () use ($make, $date) {
                $this->post(route('purchases.orders.store'), ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => $date, 'submit' => true,
                    'items' => [['product_id' => $this->stockProduct()->id, 'item_name' => 'صنف', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 15]]])->assertSessionHasNoErrors();
                $order = PurchaseOrder::withoutGlobalScopes()->latest('id')->firstOrFail();

                return $make('payments.0.bank_account_id', fn (BankAccount $box) => $this->post(route('purchases.orders.convert', $order->id), [
                    'payments' => [['method' => 'cash', 'bank_account_id' => $box->id, 'amount' => 30]],
                ]), 'own');
            })(),
            'expense_edit' => (function () use ($make, $date) {
                $voucher = fn (BankAccount $box) => ['title' => 'ضيافة', 'category' => 'ضيافة', 'amount' => 70, 'expense_date' => $date, 'payment_method' => 'cash', 'bank_account_id' => $box->id];
                $this->post(route('finance.expenses.store'), $voucher($this->boxA))->assertSessionHasNoErrors();
                $expense = Expense::withoutGlobalScopes()->latest('id')->firstOrFail();

                return $make('bank_account_id', fn (BankAccount $box) => $this->put(route('finance.expenses.update', $expense->id), $voucher($box)));
            })(),
            'payment_request_edit' => (function () use ($make, $date) {
                $request = fn (?BankAccount $box) => ['request_date' => $date, 'kind' => 'expense', 'payee_type' => 'other', 'payee_name' => 'مورد', 'purpose' => 'ضيافة',
                    'amount' => 250, 'payment_method' => 'cash', 'bank_account_id' => $box?->id];
                $this->post(route('finance.payment-requests.store'), $request(null))->assertSessionHasNoErrors();
                $paymentRequest = PaymentRequest::withoutGlobalScopes()->latest('id')->firstOrFail();

                return $make('bank_account_id', fn (BankAccount $box) => $this->put(route('finance.payment-requests.update', $paymentRequest->id), $request($box)), 'both',
                    fn (BankAccount $box) => (int) $paymentRequest->fresh()->bank_account_id === (int) $box->id);
            })(),
            'cheque_clearing' => (function () use ($make, $date) {
                $this->post(route('accounting.cheques.store'), ['direction' => 'incoming', 'cheque_number' => 'CHQ-9', 'party_type' => 'customer', 'party_id' => $this->customer->id,
                    'amount' => 300, 'issue_date' => $date, 'due_date' => $date])->assertSessionHasNoErrors();
                $cheque = Cheque::withoutGlobalScopes()->where('cheque_number', 'CHQ-9')->firstOrFail();
                // Deposited first without naming a bank (the cheque has none), then cleared into the box.
                $this->post(route('accounting.cheques.transition', $cheque->id), ['status' => 'deposited', 'date' => $date])->assertSessionHasNoErrors();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('accounting.cheques.transition', $cheque->id), ['status' => 'cleared', 'date' => $date, 'bank_account_id' => $box->id]), 'own');
            })(),
            'asset_purchase' => (function () use ($make, $date) {
                $category = $this->assetCategory();
                $code = 0;

                return $make('bank_account_id', function (BankAccount $box) use ($category, $date, &$code) {
                    return $this->post(route('accounting.fixed-assets.store'), ['asset_category_id' => $category->id, 'branch_id' => $this->a->id, 'code' => 'FA-'.(++$code),
                        'name_ar' => 'حاسوب', 'acquisition_date' => $date, 'cost' => 900, 'paid_from' => 'cash', 'bank_account_id' => $box->id]);
                });
            })(),
            'asset_disposal' => (function () use ($make, $date) {
                $this->post(route('accounting.fixed-assets.store'), ['asset_category_id' => $this->assetCategory()->id, 'branch_id' => $this->a->id, 'code' => 'FA-OLD',
                    'name_ar' => 'سيارة', 'acquisition_date' => '2026-01-01', 'cost' => 10000, 'paid_from' => 'opening'])->assertSessionHasNoErrors();
                $asset = FixedAsset::withoutGlobalScopes()->where('code', 'FA-OLD')->firstOrFail();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('accounting.fixed-assets.dispose', $asset->id), [
                    'date' => $date, 'proceeds' => 4000, 'method' => 'cash', 'bank_account_id' => $box->id,
                ]), 'shared');
            })(),
            'project_advance' => (function () use ($make, $date) {
                $project = $this->projectA();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('projects.advance', $project->id), ['date' => $date, 'amount' => 100, 'payment_method' => 'cash', 'bank_account_id' => $box->id]));
            })(),
            'project_cost' => (function () use ($make, $date) {
                $project = $this->projectA();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('projects.costs.store', $project->id), [
                    'date' => $date, 'category' => 'other', 'description' => 'تكلفة', 'amount' => 50, 'paid_from' => 'cash', 'bank_account_id' => $box->id,
                ]));
            })(),
            'subcontract_advance' => (function () use ($make, $date) {
                $project = $this->projectA();
                $this->post(route('projects.subcontracts.store', $project->id), ['supplier_id' => $this->supplier->id, 'scope' => 'أعمال', 'contract_value' => 1000])->assertSessionHasNoErrors();
                $subcontract = Subcontract::withoutGlobalScopes()->latest('id')->firstOrFail();

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('projects.subcontracts.advance', $subcontract->id), [
                    'date' => $date, 'amount' => 50, 'payment_method' => 'cash', 'bank_account_id' => $box->id,
                ]));
            })(),
            'payroll_payment' => (function () use ($make, $date) {
                $this->employeeIn($this->a);
                $this->post(route('hr.payroll.store'), ['month' => 6, 'year' => 2026])->assertSessionHasNoErrors();
                $payroll = Payroll::withoutGlobalScopes()->latest('id')->firstOrFail();
                $this->assertSame($this->a->id, (int) $payroll->branch_id);
                app(PayrollService::class)->approve($payroll);

                return $make('bank_account_id', fn (BankAccount $box) => $this->post(route('hr.payroll.pay', $payroll->id), ['date' => $date, 'bank_account_id' => $box->id]), 'own');
            })(),
            'booking_deposit' => (function () use ($make) {
                [$resource, $service] = $this->bookable();
                $slot = 9;

                return $make('deposit_bank_account_id', function (BankAccount $box) use ($resource, $service, &$slot) {
                    $slot++;

                    return $this->post(route('bookings.store'), ['customer_id' => $this->customer->id, 'bookable_service_id' => $service->id, 'booking_resource_id' => $resource->id,
                        'starts_at' => "2026-06-16 {$slot}:00", 'deposit_amount' => 20, 'deposit_method' => 'cash', 'deposit_bank_account_id' => $box->id]);
                });
            })(),
            'pos_float' => (function () use ($make) {
                $drawer = $this->openBox('درج أ', 'cash', 0);
                $this->post(route('pos.registers.store'), ['name' => 'كاشير أ', 'warehouse_id' => $this->warehouse->id, 'bank_account_id' => $drawer->id, 'is_active' => true])->assertSessionHasNoErrors();
                $register = PosRegister::withoutGlobalScopes()->where('name', 'كاشير أ')->firstOrFail();

                return $make('float_bank_account_id', fn (BankAccount $box) => $this->post(route('pos.session.open'), [
                    'pos_register_id' => $register->id, 'opening_cash' => 100, 'float_bank_account_id' => $box->id,
                ]), 'own');
            })(),
            'pos_register_edit' => (function () use ($make) {
                $this->post(route('pos.registers.store'), ['name' => 'كاشير أ', 'warehouse_id' => $this->warehouse->id, 'is_active' => true])->assertSessionHasNoErrors();
                $register = PosRegister::withoutGlobalScopes()->where('name', 'كاشير أ')->firstOrFail();

                return $make('bank_account_id', fn (BankAccount $box) => $this->put(route('pos.registers.update', $register->id), [
                    'name' => 'كاشير أ', 'warehouse_id' => $this->warehouse->id, 'bank_account_id' => $box->id, 'is_active' => true,
                ]), 'both', fn (BankAccount $box) => (int) $register->fresh()->bank_account_id === (int) $box->id);
            })(),
        };
    }

    protected function invoicePayload(array $extra = [], float $quantity = 1): array
    {
        return $extra + [
            'customer_id' => $this->customer->id, 'branch_id' => $this->a->id, 'invoice_date' => self::DATE, 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'items' => [['item_name' => 'خدمة', 'quantity' => $quantity, 'unit_price' => 100, 'tax_rate' => 15]],
        ];
    }

    protected function salesInvoiceA(array $extra = [], float $quantity = 1): Invoice
    {
        $this->post(route('sales.invoices.store'), $this->invoicePayload($extra, $quantity))->assertSessionHasNoErrors();

        return Invoice::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    protected function stockProduct(): Product
    {
        return Product::withoutGlobalScopes()->where('sku', 'STK-1')->first()
            ?? Product::create(['name_ar' => 'صنف مخزني', 'sku' => 'STK-1', 'selling_price' => 150, 'track_inventory' => true, 'is_active' => true]);
    }

    /** A credit bill of branch A for 4 stock items (landed costs and returns need stock lines). */
    protected function billA(): PurchaseInvoice
    {
        $this->post(route('purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'branch_id' => $this->a->id, 'invoice_date' => self::DATE,
            'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'items' => [['product_id' => $this->stockProduct()->id, 'item_name' => 'صنف مخزني', 'quantity' => 4, 'unit_price' => 100, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();

        return PurchaseInvoice::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    protected function assetCategory(): AssetCategory
    {
        $this->post(route('accounting.fixed-assets.categories.store'), ['name_ar' => 'معدات', 'useful_life_months' => 60, 'depreciation_method' => 'straight_line'])->assertSessionHasNoErrors();

        return AssetCategory::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    protected function projectA(): Project
    {
        $this->post(route('projects.store'), ['customer_id' => $this->customer->id, 'name' => 'مشروع أ', 'boq' => []])->assertSessionHasNoErrors();
        $project = Project::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame($this->a->id, (int) $project->branch_id);

        return $project;
    }

    protected function employeeIn(Branch $branch, string $number = 'E-1'): Employee
    {
        return Employee::create([
            'name' => 'سالم', 'job_title' => 'محاسب', 'department' => 'المالية', 'hire_date' => '2020-01-01', 'nationality' => 'SA', 'basic_salary' => 6000,
            'housing_allowance' => 0, 'transport_allowance' => 0, 'other_allowances' => 0, 'total_salary' => 6000, 'status' => 'active',
            'gosi_registered' => false, 'employee_number' => $number, 'branch_id' => $branch->id,
        ]);
    }

    /** A resource of branch A open every day 09:00-17:00 and a service it gives. */
    protected function bookable(): array
    {
        $resource = BookingResource::create(['name' => 'غرفة أ', 'type' => 'room', 'capacity' => 1, 'is_active' => true]);
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $resource->hours()->create(['weekday' => $day, 'start_time' => '09:00', 'end_time' => '17:00']);
        }
        $service = BookableService::create(['name' => 'جلسة', 'duration_minutes' => 60, 'buffer_minutes' => 0, 'price' => 100, 'tax_rate' => 15, 'online_bookable' => true, 'free_cancel_hours' => 24, 'is_active' => true]);
        $service->resources()->attach($resource->id);
        $this->assertSame($this->a->id, (int) $resource->fresh()->branch_id);

        return [$resource, $service];
    }

    // ------------------------------------------------------------------ salaries, settlements, loans

    public function test_salaries_and_settlements_are_paid_from_a_box_of_their_branch(): void
    {
        $this->world();
        $hr = $this->userIn([$this->a], 'hr_manager');
        $this->inBranch($hr);
        $employee = $this->employeeIn($this->a);
        $this->post(route('hr.payroll.store'), ['month' => 6, 'year' => 2026])->assertSessionHasNoErrors();
        $payroll = Payroll::withoutGlobalScopes()->latest('id')->firstOrFail();
        app(PayrollService::class)->approve($payroll);
        $this->post(route('hr.settlements.store'), ['employee_id' => $employee->id, 'termination_date' => self::DATE, 'reason' => 'resignation'])->assertSessionHasNoErrors();
        $settlement = EndOfServiceSettlement::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->post(route('hr.settlements.approve', $settlement->id))->assertSessionHasNoErrors();
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($this->post(route('hr.payroll.pay', $payroll->id), ['date' => self::DATE, 'bank_account_id' => $this->boxB->id]), 'bank_account_id', $mark, $before);
        $this->assertRefused($this->post(route('hr.settlements.pay', $settlement->id), ['date' => self::DATE, 'bank_account_id' => $this->boxB->id]), 'bank_account_id', $mark, $before);

        $this->post(route('hr.payroll.pay', $payroll->id), ['date' => self::DATE, 'bank_account_id' => $this->boxA->id])->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $this->boxA->account_id, 0, round((float) $payroll->fresh()->net_total, 2));
        $mark = $this->mark();
        $this->post(route('hr.settlements.pay', $settlement->id), ['date' => self::DATE, 'bank_account_id' => $this->boxA->id])->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $this->boxA->account_id, 0, round((float) $settlement->fresh()->net_amount, 2));
    }

    // ------------------------------------------------------------------ configuration that points at accounts

    public function test_account_mapping_and_company_settings_never_put_a_role_on_a_branch_box(): void
    {
        $this->world();
        $shared = $this->sharedBox();
        $clerk = $this->userIn([$this->a], 'accountant', ['admin.settings.view', 'admin.settings.edit']);
        $this->inBranch($clerk);
        $map = app(AccountMap::class);
        $cash = $this->role('default_cash');

        // Every branch's box-less cash would land in that box.
        $this->put(route('accounting.mappings.update'), ['mapping' => ['default_cash' => $this->boxB->account_id]])->assertSessionHasErrors('mapping.default_cash');
        $this->put(route('accounting.mappings.update'), ['mapping' => ['default_cash' => $this->boxA->account_id]])->assertSessionHasErrors('mapping.default_cash');
        // Any other role takes no box at all, a shared one included; nothing of a refused request is saved.
        $this->put(route('accounting.mappings.update'), ['mapping' => ['receivables' => $this->role('receivables'), 'vat_input' => $shared->account_id]])->assertSessionHasErrors('mapping.vat_input');
        // Nor does a cash role sit on an account that needs a cost center: every box-less voucher, transfer and POS
        // posting would be refused.
        $costed = Account::create(['code' => '11197', 'name_ar' => 'صندوق بمركز تكلفة', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'requires_cost_center' => true]);
        foreach (['default_cash', 'petty_cash'] as $role) {
            $this->put(route('accounting.mappings.update'), ['mapping' => [$role => $costed->id]])->assertSessionHasErrors("mapping.{$role}");
        }
        $settings = ['name' => $this->company->name];
        foreach (['sales_revenue', 'purchases_cogs', 'vat_output', 'vat_input', 'receivables', 'payables', 'default_cash', 'default_bank'] as $role) {
            $settings["acc_{$role}_id"] = $this->role($role);
        }
        $this->put(route('settings.update'), ['acc_default_cash_id' => $this->boxB->account_id] + $settings)->assertSessionHasErrors('acc_default_cash_id');
        $this->put(route('settings.update'), ['acc_receivables_id' => $this->boxA->account_id] + $settings)->assertSessionHasErrors('acc_receivables_id');
        $this->put(route('settings.update'), ['acc_default_bank_id' => $costed->id] + $settings)->assertSessionHasErrors('acc_default_bank_id');
        $map->flush();
        $this->assertSame($cash, $this->role('default_cash'));
        $this->assertSame($this->role('receivables'), (int) $map->find('receivables'));
        $this->assertNotContains($costed->id, array_map('intval', array_filter($map->all())));

        // A cash role may sit on a box shared by all branches; saving the page unchanged is fine.
        $this->put(route('accounting.mappings.update'), ['mapping' => ['default_cash' => $shared->account_id]])->assertSessionHasNoErrors();
        $this->put(route('settings.update'), ['acc_default_cash_id' => $shared->account_id] + $settings)->assertSessionHasNoErrors();
        $map->flush();
        $this->assertSame((int) $shared->account_id, $this->role('default_cash'));

        // Older data with a branch box already on a role is left as it is: saving the same mapping again goes through.
        DB::table('bank_accounts')->where('id', $this->boxB->id)->update(['account_id' => $this->role('default_bank')]);
        $this->put(route('accounting.mappings.update'), ['mapping' => ['default_bank' => $this->role('default_bank')]])->assertSessionHasNoErrors();
    }

    public function test_asset_categories_take_accounts_of_their_type_and_never_a_box(): void
    {
        $this->world();
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);
        $category = fn (array $accounts) => $this->post(route('accounting.fixed-assets.categories.store'), $accounts + ['name_ar' => 'سيارات', 'useful_life_months' => 60, 'depreciation_method' => 'straight_line']);

        $category(['accumulated_account_id' => $this->boxB->account_id])->assertSessionHasErrors('accumulated_account_id');
        $category(['asset_account_id' => $this->boxA->account_id])->assertSessionHasErrors('asset_account_id');
        $category(['expense_account_id' => $this->role('fixed_assets')])->assertSessionHasErrors('expense_account_id');
        $category(['accumulated_account_id' => $this->role('general_expense')])->assertSessionHasErrors('accumulated_account_id');
        $this->assertSame(0, AssetCategory::withoutGlobalScopes()->count());

        $category(['asset_account_id' => $this->role('fixed_assets'), 'accumulated_account_id' => $this->role('accumulated_depreciation'), 'expense_account_id' => $this->role('depreciation_expense')])
            ->assertSessionHasNoErrors();
    }

    public function test_a_customer_group_posts_to_a_receivables_account_never_a_box(): void
    {
        $this->world();
        $clerk = $this->userIn([$this->a], 'accountant', ['sales.customer_groups.view', 'sales.customer_groups.create', 'sales.customer_groups.edit']);
        $this->inBranch($clerk);
        $group = fn (string $code, ?int $account) => $this->post(route('sales.customer-groups.store'), ['code' => $code, 'name' => 'كبار', 'account_id' => $account, 'is_active' => true]);

        foreach ([$this->boxB->account_id, $this->boxA->account_id, $this->role('default_cash'), $this->role('vat_input')] as $i => $account) {
            $group("G{$i}", (int) $account)->assertSessionHasErrors('account_id');
        }
        $this->assertSame(0, CustomerGroup::withoutGlobalScopes()->count());
        // The form and its quick-create dialog offer only what is accepted: receivables, never a box's ledger or another role.
        $options = collect($this->get(route('sales.customer-groups.create'))->assertOk()->viewData('page')['props']['accounts'])->pluck('id')->map(fn ($id) => (int) $id);
        $looked = collect($this->getJson(route('lookup.list', 'accounts').'?type=asset&receivables=1')->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        foreach ([$options, $looked] as $list) {
            $this->assertSame([], $list->intersect([(int) $this->boxB->account_id, (int) $this->boxA->account_id, $this->role('default_cash'), $this->role('vat_input')])->values()->all());
            $this->assertContains($this->role('receivables'), $list->all());
        }

        // A group without an account of its own: its customers' receipts post to the general receivables account,
        // so giving it one later would split their balances (as moving it from one account to another does).
        $group('G9', null)->assertSessionHasNoErrors();
        $vip = CustomerGroup::withoutGlobalScopes()->where('code', 'G9')->firstOrFail();
        DB::table('customers')->where('id', $this->customer->id)->update(['customer_group_id' => $vip->id]);
        $this->inBranch($this->owner, $this->a->id);
        $this->salesInvoiceA();
        $this->inBranch($clerk);
        $own = Account::create(['code' => '11299', 'name_ar' => 'ذمم كبار العملاء', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true]);
        $this->put(route('sales.customer-groups.update', $vip->id), ['code' => 'G9', 'name' => 'كبار', 'account_id' => $own->id, 'is_active' => true])->assertSessionHasErrors('account_id');
        $this->assertNull($vip->fresh()->account_id);
    }

    public function test_chart_edits_leave_the_ledgers_of_boxes_alone(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->b->id);
        $fresh = $this->openBox('صندوق ب2', 'cash', 0);
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);
        $edit = fn (int $id, array $changes) => $this->put(route('accounting.accounts.update', $id), $changes + Account::withoutGlobalScopes()->findOrFail($id)->only(['code', 'name_ar', 'type', 'nature', 'parent_id', 'is_active']));

        // A child would turn the ledger into a group account: every posting to the box would be refused.
        $this->post(route('accounting.accounts.store'), ['code' => '999001', 'name_ar' => 'فرعي', 'parent_id' => $this->boxB->account_id, 'type' => 'asset', 'nature' => 'debit'])->assertSessionHasErrors('parent_id');
        $this->post(route('accounting.accounts.store'), ['code' => '999002', 'name_ar' => 'فرعي', 'parent_id' => $this->role('vat_input'), 'type' => 'asset', 'nature' => 'debit'])->assertSessionHasErrors('parent_id');
        $this->assertFalse((bool) Account::withoutGlobalScopes()->whereKey($this->boxB->account_id)->value('is_parent'));

        // Branch B's bank switched off or moved by a branch-A user; a box ledger without postings yet retyped as revenue.
        $edit((int) $this->bankB->account_id, ['is_active' => false])->assertSessionHas('error');
        $edit((int) $this->bankB->account_id, ['parent_id' => null])->assertSessionHas('error');
        // Nor renamed, recoded or made to need a cost center (which would refuse every voucher on it just the same).
        $before = Account::withoutGlobalScopes()->findOrFail($this->bankB->account_id)->only(['code', 'name_ar', 'cash_flow_category', 'requires_cost_center']);
        foreach ([['name_ar' => 'بنك ب (معدّل)'], ['code' => '99990'], ['requires_cost_center' => true], ['cash_flow_category' => 'financing']] as $change) {
            $edit((int) $this->bankB->account_id, $change)->assertSessionHas('error', 'هذا الحساب مربوط بصندوق أو بنك لفرع آخر.');
        }
        $this->assertSame($before, Account::withoutGlobalScopes()->findOrFail($this->bankB->account_id)->only(['code', 'name_ar', 'cash_flow_category', 'requires_cost_center']));
        $edit((int) $this->bankB->account_id, [])->assertSessionMissing('error'); // saved as it is: nothing to refuse
        $edit((int) $fresh->account_id, ['type' => 'revenue', 'nature' => 'credit'])->assertSessionHas('error');
        $this->delete(route('accounting.accounts.destroy', $fresh->account_id))->assertSessionHas('error');
        $this->assertSame(['asset', true], array_values(Account::withoutGlobalScopes()->findOrFail($fresh->account_id)->only(['type', 'is_active'])));
        $this->assertTrue((bool) Account::withoutGlobalScopes()->whereKey($this->bankB->account_id)->value('is_active'));

        // Vouchers post to a box's ledger (and to the default cash they fall back on) without a cost center: no box's ledger
        // needs one, its own branch's included. Any other account still may.
        foreach ([(int) $this->boxA->account_id, $this->role('default_cash')] as $account) {
            $edit($account, ['requires_cost_center' => true])->assertSessionHas('error', 'يرحّل النظام على حسابات الصناديق والبنوك بلا مركز تكلفة؛ لا يُشترط لهذا الحساب مركز تكلفة.');
            $this->assertFalse((bool) Account::withoutGlobalScopes()->whereKey($account)->value('requires_cost_center'));
        }
        $edit($this->role('general_expense'), ['requires_cost_center' => true])->assertSessionMissing('error');
        $this->assertTrue((bool) Account::withoutGlobalScopes()->whereKey($this->role('general_expense'))->value('requires_cost_center'));

        // Its own branch's box: renamed and switched off by a user of that branch.
        $edit((int) $this->boxA->account_id, ['name_ar' => 'صندوق الفرع أ'])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->inBranch($this->userIn([$this->b], 'accountant'));
        $edit((int) $fresh->account_id, ['is_active' => false])->assertSessionMissing('error');
        $this->assertFalse((bool) Account::withoutGlobalScopes()->whereKey($fresh->account_id)->value('is_active'));
    }

    /** The chart's Excel import keeps the chart screen's rules: no box ledger or role account turns into a group, and another branch's box ledger keeps its name. */
    public function test_the_chart_import_leaves_the_ledgers_of_boxes_alone(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->b->id);
        $fresh = $this->openBox('صندوق ب2', 'cash', 0); // its ledger has no postings yet
        $code = fn (int $accountId) => (string) Account::withoutGlobalScopes()->whereKey($accountId)->value('code');
        [$freshCode, $bankCode, $roleCode] = [$code((int) $fresh->account_id), $code((int) $this->bankB->account_id), $code($this->role('vat_input'))];
        $this->inBranch($this->userIn([$this->a], 'accountant', ['admin.data.use']));
        $import = fn (string $csv) => $this->post(route('data-transfer.import', 'accounts'), ['file' => UploadedFile::fake()->createWithContent('accounts.csv', $csv)]);

        // A child under a box's ledger or a role account (neither has postings yet) would make it a group account.
        $import("code,name_ar,parent_code\n999101,فرعي,{$freshCode}")->assertSessionHasErrors('file');
        $import("code,name_ar,parent_code\n999102,فرعي,{$roleCode}")->assertSessionHasErrors('file');
        // Another branch's bank renamed.
        $import("code,name_ar,name_en\n{$bankCode},حساب مزوّر,Fake")->assertSessionHasErrors('file');
        $this->assertSame([false, false], [(bool) Account::withoutGlobalScopes()->whereKey($fresh->account_id)->value('is_parent'), (bool) Account::withoutGlobalScopes()->whereKey($this->role('vat_input'))->value('is_parent')]);
        $this->assertSame('بنك ب', Account::withoutGlobalScopes()->whereKey($this->bankB->account_id)->value('name_ar'));
        $this->assertSame(0, Account::withoutGlobalScopes()->whereIn('code', ['999101', '999102'])->count());

        // A chart exported and imported again (that ledger's name unchanged) still goes through, new accounts included.
        $import("code,name_ar,type\n{$bankCode},بنك ب,\n999103,حساب عادي,expense")->assertSessionHasNoErrors();
        $this->assertSame(1, Account::withoutGlobalScopes()->where('code', '999103')->count());
    }

    /** A new box on an account nobody else posts to only: not a company-wide role account, another box's ledger, nor one with another branch's postings. */
    public function test_a_new_box_is_never_put_on_an_account_other_branches_post_to(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->b->id);
        $advances = Account::create(['code' => '11399', 'name_ar' => 'سلف موظفي ب', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true]);
        $this->post(route('accounting.journals.store'), ['date' => self::DATE, 'description' => 'سلفة', 'save_as' => 'posted', 'lines' => [
            ['account_id' => $advances->id, 'debit' => 400, 'credit' => 0], ['account_id' => $this->boxB->account_id, 'debit' => 0, 'credit' => 400],
        ]])->assertSessionHasNoErrors();
        $clerk = $this->userIn([$this->a], 'accountant');
        $this->inBranch($clerk);
        $box = fn (string $name, int $account) => $this->post(route('finance.banks.store'), ['name' => $name, 'type' => 'cash', 'currency' => 'SAR', 'opening_balance' => 0, 'account_id' => $account]);

        foreach ([$this->role('default_cash'), $this->role('default_bank'), $this->role('vat_input'), $this->boxA->account_id, $advances->id, $this->role('general_expense')] as $i => $account) {
            $box("ظل {$i}", (int) $account)->assertSessionHasErrors('account_id');
        }
        $this->assertSame(0, BankAccount::withoutGlobalScopes()->where('name', 'like', 'ظل%')->count());

        // What the form offers: none of those.
        $offered = collect($this->get(route('finance.banks.create'))->assertOk()->viewData('page')['props']['chartAccounts'])->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertSame([], $offered->intersect([$this->role('default_cash'), $this->role('vat_input'), (int) $this->boxA->account_id, (int) $this->boxB->account_id])->values()->all());

        // Nor one that needs a cost center: every voucher, transfer and POS posting to the box would be refused. The form
        // does not offer it, and opens an account made from its picker as an asset under the group of the automatic ledgers.
        $costed = Account::create(['code' => '11198', 'name_ar' => 'بنك بمركز تكلفة', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true, 'requires_cost_center' => true]);
        $box('بنك بمركز تكلفة', $costed->id)->assertSessionHasErrors('account_id');
        $this->assertSame(0, BankAccount::withoutGlobalScopes()->where('account_id', $costed->id)->count());
        $props = $this->get(route('finance.banks.create'))->assertOk()->viewData('page')['props'];
        $this->assertNotContains($costed->id, collect($props['chartAccounts'])->pluck('id')->map(fn ($id) => (int) $id)->all());
        $groupOf = fn (string $role) => (int) Account::withoutGlobalScopes()->whereKey($this->role($role))->value('parent_id');
        $this->assertSame(['cash' => $groupOf('default_cash'), 'bank' => $groupOf('default_bank')], array_map('intval', $props['ledgerParents']));

        // Nor an account the settings post to for other branches (a customer group's receivables, an asset category's
        // account, another branch's recurring entry), a switched-off account, or one of credit nature. Neither the form
        // nor the quick-create dialog offers one; a recurring entry of the box's own branch is no obstacle.
        $make = fn (string $code, array $extra = []) => Account::create($extra + ['code' => $code, 'name_ar' => "حساب {$code}", 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true]);
        $template = fn (int $branch, int $account) => RecurringJournal::withoutEvents(fn () => RecurringJournal::create(['company_id' => $this->company->id, 'branch_id' => $branch,
            'name' => 'شهري', 'description' => 'شهري', 'frequency' => 'monthly', 'next_run_date' => self::DATE, 'is_active' => true, 'created_by' => $this->owner->id, 'lines' => [
                ['account_id' => $account, 'debit' => 10, 'credit' => 0], ['account_id' => $this->role('general_expense'), 'debit' => 0, 'credit' => 10],
            ]]));
        [$receivables, $equipment, $planned, $off, $contra, $ownPlanned] = [$make('11291'), $make('11292'), $make('11293'), $make('11294', ['is_active' => false]), $make('11295', ['nature' => 'credit']), $make('11296')];
        CustomerGroup::create(['code' => 'VIP', 'name' => 'كبار العملاء', 'account_id' => $receivables->id, 'is_active' => true]);
        AssetCategory::create(['name_ar' => 'معدات', 'useful_life_months' => 60, 'depreciation_method' => 'straight_line', 'asset_account_id' => $equipment->id,
            'accumulated_account_id' => $make('11297', ['nature' => 'credit'])->id, 'expense_account_id' => $this->role('general_expense')]);
        $template($this->b->id, $planned->id);
        $template($this->a->id, $ownPlanned->id);
        $refused = [$receivables->id, $equipment->id, $planned->id, $off->id, $contra->id];
        $offered = collect($this->get(route('finance.banks.create'))->viewData('page')['props']['chartAccounts'])->pluck('id')->map(fn ($id) => (int) $id);
        $looked = collect($this->getJson(route('lookup.list', 'accounts').'?type=asset&box_ledger=1')->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        foreach ([$offered, $looked] as $list) {
            $this->assertSame([], $list->intersect([...$refused, $costed->id, $this->role('default_cash'), (int) $this->boxA->account_id, (int) $this->boxB->account_id])->values()->all());
            $this->assertContains($ownPlanned->id, $list->all());
        }
        foreach ($refused as $account) {
            $box("صندوق على {$account}", $account)->assertSessionHasErrors('account_id');
        }
        $this->assertSame(0, BankAccount::withoutGlobalScopes()->whereIn('account_id', $refused)->count());
        $box('صندوق قيد متكرر أ', $ownPlanned->id)->assertSessionHasNoErrors();

        // An asset account of its own branch with no other postings is fine.
        $own = Account::create(['code' => '11199', 'name_ar' => 'بنك أ', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true]);
        $box('بنك أ', $own->id)->assertSessionHasNoErrors();
        $this->assertSame([$this->a->id, $own->id], array_map('intval', array_values(BankAccount::withoutGlobalScopes()->where('name', 'بنك أ')->firstOrFail()->only(['branch_id', 'account_id']))));
    }

    // ------------------------------------------------------------------ ledgers shared by every branch, users of several branches

    /** Someone allowed in both branches moves cash between their boxes with one entry, whichever branch the top bar shows. */
    public function test_a_user_of_both_branches_moves_cash_between_their_boxes_by_a_manual_entry(): void
    {
        $this->world();
        $entry = fn (string $saveAs) => ['date' => self::DATE, 'description' => 'نقل نقدية بين الفروع', 'save_as' => $saveAs, 'lines' => [
            ['account_id' => $this->boxA->account_id, 'debit' => 100, 'credit' => 0], ['account_id' => $this->boxB->account_id, 'debit' => 0, 'credit' => 100],
        ]];
        $manager = $this->userIn([$this->a, $this->b], 'accountant');

        foreach ([[$this->owner, $this->a->id], [$manager, $this->a->id], [$manager, $this->b->id]] as [$user, $branch]) {
            $this->inBranch($user, $branch);
            $mark = $this->mark();
            $this->post(route('accounting.journals.store'), $entry('posted'))->assertSessionHasNoErrors();
            $lines = $this->linesSince($mark);
            $this->assertLine($lines, (int) $this->boxB->account_id, 0, 100);
            $this->assertSame($branch, $lines[0][3]);
        }

        // A draft is posted later through the same check.
        $this->post(route('accounting.journals.store'), $entry('draft'))->assertSessionHasNoErrors();
        $draft = JournalEntry::withoutGlobalScopes()->where('status', JournalEntry::STATUS_DRAFT)->latest('id')->firstOrFail();
        $this->post(route('accounting.journals.post', $draft->id))->assertSessionHasNoErrors();
        $this->assertSame(JournalEntry::STATUS_POSTED, $draft->fresh()->status);

        // Nobody else: a user of branch A only.
        $this->inBranch($this->userIn([$this->a], 'accountant'));
        $this->post(route('accounting.journals.store'), $entry('posted'))->assertSessionHasErrors('lines.1.account_id');
    }

    /**
     * The default cash account is every branch's (box-less vouchers of all branches post there): a box of branch A left on it
     * by older data does not close it to branch B. Such a box shares the account and cannot be kept apart; the exceptions
     * report lists it to be given a ledger of its own.
     */
    public function test_an_account_every_branch_posts_to_stays_open_to_every_branch(): void
    {
        $this->world();
        DB::table('bank_accounts')->where('id', $this->boxA->id)->update(['account_id' => $this->role('default_cash')]);
        $this->inBranch($this->userIn([$this->b], 'accountant'));

        $mark = $this->mark();
        $this->post(route('accounting.journals.store'), ['date' => self::DATE, 'description' => 'تسوية نقدية', 'save_as' => 'posted', 'lines' => [
            ['account_id' => $this->role('default_cash'), 'debit' => 20, 'credit' => 0], ['account_id' => $this->role('other_revenue'), 'debit' => 0, 'credit' => 20],
        ]])->assertSessionHasNoErrors();
        $this->post(route('accounting.recurring.store'), ['name' => 'شهري', 'description' => 'تسوية', 'frequency' => 'monthly', 'next_run_date' => '2026-07-01', 'lines' => [
            ['account_id' => $this->role('default_cash'), 'debit' => 10, 'credit' => 0], ['account_id' => $this->role('other_revenue'), 'debit' => 0, 'credit' => 10],
        ]])->assertSessionHasNoErrors();
        $this->post(route('finance.expenses.store'), ['title' => 'ضيافة', 'category' => 'ضيافة', 'amount' => 30, 'expense_date' => self::DATE, 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $lines = $this->linesSince($mark);
        $this->assertLine($lines, $this->role('default_cash'), 20, 0);
        $this->assertLine($lines, $this->role('default_cash'), 0, 30);

        $this->inBranch($this->owner, $this->a->id);
        $exceptions = app(AccountingControls::class)->exceptions();
        $this->assertSame([$this->boxA->name], array_column($exceptions['shared_box_ledgers']['rows'], 'reference'));
    }

    // ------------------------------------------------------------------ posting, whatever the source

    /** A line on branch B's box ledger in an entry of branch A, from configuration or data stored before these checks, is refused when posted. */
    public function test_posting_keeps_an_entry_of_one_branch_off_the_boxes_of_another(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $posting = app(PostingService::class);
        $entry = fn (array $extra = []) => $extra + ['date' => self::DATE, 'description' => 'قيد', 'branch_id' => $this->a->id, 'lines' => [
            ['account_id' => $this->role('general_expense'), 'debit' => 70], ['account_id' => $this->bankB->account_id, 'credit' => 70],
        ]];
        $before = $this->balances();
        try {
            $posting->post($entry());
            $this->fail('Branch B\'s bank was posted from branch A.');
        } catch (AccountingException $e) {
            $this->assertStringContainsString('لفرع آخر', $e->getMessage());
        }
        $this->assertSame($before, $this->balances());

        // Meant by the caller (it checked the user may move both), or a mirror of what is already in the ledger.
        $moved = $posting->post($entry(['cross_branch' => true]));
        $posting->reverse($moved);
        $this->assertSame($before, $this->balances());
        // Branch B's own entries and a shared box are not concerned.
        $posting->reverse($posting->post($entry(['branch_id' => $this->b->id])));

        // An asset category planted on branch B's box before the category check: registering an asset in A posts nothing.
        $this->post(route('accounting.fixed-assets.categories.store'), ['name_ar' => 'سيارات', 'useful_life_months' => 60, 'depreciation_method' => 'straight_line'])->assertSessionHasNoErrors();
        $category = AssetCategory::withoutGlobalScopes()->latest('id')->firstOrFail();
        DB::table('asset_categories')->where('id', $category->id)->update(['accumulated_account_id' => $this->boxB->account_id]);
        $mark = $this->mark();
        $this->post(route('accounting.fixed-assets.store'), ['asset_category_id' => $category->id, 'branch_id' => $this->a->id, 'code' => 'FA-1', 'name_ar' => 'سيارة',
            'acquisition_date' => '2026-01-01', 'cost' => 10000, 'paid_from' => 'opening', 'opening_accumulated' => 4000])->assertSessionHasErrors('accounting');
        $this->assertSame([], $this->linesSince($mark));
        $this->assertSame($before, $this->balances());
    }

    /** A recurring entry planted on branch B's bank before the check: the run records the refusal and skips it, the others still post. */
    public function test_a_recurring_entry_moves_only_the_boxes_of_its_own_branch(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $template = fn (string $name, int $account) => RecurringJournal::create(['name' => $name, 'description' => $name, 'frequency' => 'monthly', 'next_run_date' => self::DATE,
            'is_active' => true, 'created_by' => $this->owner->id, 'lines' => [
                ['account_id' => $this->role('general_expense'), 'debit' => 700, 'credit' => 0], ['account_id' => $account, 'debit' => 0, 'credit' => 700],
            ]]);
        $planted = $template('مزروع', (int) $this->bankB->account_id);
        $own = $template('إيجار', (int) $this->boxA->account_id);
        $this->assertSame([$this->a->id, $this->a->id], [(int) $planted->branch_id, (int) $own->branch_id]);
        $bankB = $this->balances()[$this->bankB->name];

        auth()->logout();
        $this->assertSame(1, app(RecurringJournalRunner::class)->runDue(Carbon::parse(self::DATE)));

        $this->assertSame($bankB, $this->balances()[$this->bankB->name]);
        $this->assertNotNull($planted->fresh()->last_error);
        $this->assertSame(self::DATE, $planted->fresh()->next_run_date->toDateString());
        $this->assertNull($own->fresh()->last_error);

        // Scheduling one now: refused for branch B's box whoever schedules it, as it posts unattended in its own branch.
        $this->inBranch($this->userIn([$this->a, $this->b], 'accountant'), $this->a->id);
        $this->post(route('accounting.recurring.store'), ['name' => 'شهري', 'description' => 'تحويل', 'frequency' => 'monthly', 'next_run_date' => '2026-07-01', 'lines' => [
            ['account_id' => $this->boxA->account_id, 'debit' => 10, 'credit' => 0], ['account_id' => $this->bankB->account_id, 'debit' => 0, 'credit' => 10],
        ]])->assertSessionHasErrors('lines.1.account_id');
    }

    public function test_the_opening_trial_balance_import_takes_only_boxes_the_user_may_work_in(): void
    {
        $this->world();
        $code = fn (int $accountId) => Account::withoutGlobalScopes()->whereKey($accountId)->value('code');
        $upload = fn (string $csv) => $this->post(route('data-transfer.import', 'opening_balances'), [
            'file' => UploadedFile::fake()->createWithContent('opening_balances.csv', $csv), 'opening_date' => '2026-06-01',
        ]);
        $capital = $code($this->role('capital'));

        $this->inBranch($this->userIn([$this->a], 'accountant', ['admin.data.use']));
        $upload("account_code,debit,credit\n{$code((int) $this->bankB->account_id)},100,\n{$capital},,100\n")->assertSessionHasErrors('file');
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('source_type', 'opening_trial_balance')->count());

        // The owner opens every branch's boxes in one entry.
        $this->inBranch($this->owner, $this->a->id);
        $mark = $this->mark();
        $upload("account_code,debit,credit\n{$code((int) $this->boxA->account_id)},100,\n{$code((int) $this->bankB->account_id)},200,\n{$capital},,300\n")->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $this->bankB->account_id, 200, 0);
    }

    /** erp:accounting backfill posts legacy vouchers of an archived box to it, and counts the one it cannot post instead of stopping. */
    public function test_the_backfill_posts_vouchers_of_an_archived_box_and_goes_on_after_a_refused_one(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $old = $this->openBox('صندوق قديم', 'cash', 0);
        $voucher = fn (string $route, string $title, ?BankAccount $box) => $this->post(route($route), array_filter(['title' => $title, 'category' => 'عام', 'amount' => 100,
            str_contains($route, 'expense') ? 'expense_date' : 'revenue_date' => self::DATE, 'payment_method' => 'cash', 'bank_account_id' => $box?->id]))->assertSessionHasNoErrors();
        $voucher('finance.revenues.store', 'إيراد قديم', $old);
        $voucher('finance.expenses.store', 'مصروف مزروع', $this->boxA);
        $voucher('finance.revenues.store', 'إيراد بلا صندوق', null);
        // Before integrated posting: no entries. The old box was emptied and archived since; one expense names branch B's box.
        $morphs = [(new Expense)->getMorphClass(), (new Revenue)->getMorphClass()];
        $entries = DB::table('journal_entries')->whereIn('source_type', $morphs)->pluck('id');
        DB::table('journal_entry_lines')->whereIn('journal_entry_id', $entries)->delete();
        DB::table('journal_entries')->whereIn('id', $entries)->delete();
        DB::table('bank_accounts')->where('id', $old->id)->update(['deleted_at' => now()]);
        DB::table('expenses')->where('title', 'مصروف مزروع')->update(['bank_account_id' => $this->boxB->id]);

        auth()->logout();
        $mark = $this->mark();
        $summary = app(DocumentBackfill::class)->run($this->company);

        $this->assertSame(['posted' => 0, 'failed' => 1], $summary['expenses']);
        $this->assertSame(['posted' => 2, 'failed' => 0], $summary['revenues']);
        $lines = $this->linesSince($mark);
        $this->assertLine($lines, (int) $old->account_id, 100, 0);
        $this->assertNotContains((int) $this->boxB->account_id, array_column($lines, 0));
    }

    // ------------------------------------------------------------------ point of sale, bookings, reconciliations, vouchers

    public function test_a_box_a_register_counts_into_is_not_archived_and_misplaced_drawers_are_listed(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $drawer = $this->openBox('درج أ', 'cash', 0);
        $this->post(route('pos.registers.store'), ['name' => 'كاشير أ', 'warehouse_id' => $this->warehouse->id, 'bank_account_id' => $drawer->id, 'is_active' => true])->assertSessionHasNoErrors();
        $register = PosRegister::withoutGlobalScopes()->where('name', 'كاشير أ')->firstOrFail();

        $this->delete(route('finance.banks.destroy', $drawer->id))->assertSessionHas('error');
        $this->assertFalse($drawer->fresh()->trashed());

        // Linked to branch B's box before the check: the exceptions report names it.
        DB::table('pos_registers')->where('id', $register->id)->update(['bank_account_id' => $this->boxB->id]);
        $rows = app(AccountingControls::class)->exceptions()['pos_drawers']['rows'];
        $this->assertSame([['كاشير أ', 'صندوق الدرج من فرع آخر']], array_map(fn ($r) => [$r['reference'], $r['detail']], $rows));

        // Once the register counts into another box, the old one is archived as before.
        $this->delete(route('finance.banks.destroy', $drawer->id))->assertSessionHas('success');
        $this->assertTrue(BankAccount::withTrashed()->find($drawer->id)->trashed());
    }

    /** «Any free resource» from «all branches»: the resource picked decides the branch, then the deposit box is checked against it. */
    public function test_a_booking_on_any_free_resource_takes_a_deposit_box_of_the_picked_resources_branch(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        [, $service] = $this->bookable();
        $this->post(route('context.branch'), ['branch_id' => 'all'])->assertSessionHasNoErrors();
        $book = fn (BankAccount $box, string $time) => $this->post(route('bookings.store'), ['customer_id' => $this->customer->id, 'bookable_service_id' => $service->id,
            'starts_at' => "2026-06-16 {$time}", 'deposit_amount' => 20, 'deposit_method' => 'cash', 'deposit_bank_account_id' => $box->id]);
        $before = $this->balances();
        $mark = $this->mark();

        $this->assertRefused($book($this->boxB, '10:00'), 'deposit_bank_account_id', $mark, $before);
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->count());

        $book($this->boxA, '11:00')->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), (int) $this->boxA->account_id, 20, 0);
    }

    public function test_reconciliations_of_another_branchs_bank_are_neither_listed_nor_opened(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->b->id);
        $this->post(route('accounting.reconciliations.store'), ['bank_account_id' => $this->bankB->id, 'statement_date' => '2026-06-30', 'statement_balance' => 4321.5])->assertRedirect();
        $reconciliation = BankReconciliation::withoutGlobalScopes()->where('bank_account_id', $this->bankB->id)->firstOrFail();
        // And a completed one (a statement of 0: nothing to tick).
        $this->post(route('accounting.reconciliations.store'), ['bank_account_id' => $this->boxB->id, 'statement_date' => '2026-06-30', 'statement_balance' => 0])->assertRedirect();
        $completed = BankReconciliation::withoutGlobalScopes()->where('bank_account_id', $this->boxB->id)->firstOrFail();
        $this->put(route('accounting.reconciliations.update', $completed->id), ['line_ids' => [], 'complete' => true])->assertSessionHasNoErrors();
        $this->assertSame('completed', $completed->fresh()->status);

        $this->inBranch($this->userIn([$this->a], 'accountant'));
        $listed = $this->get(route('accounting.reconciliations.index'))->assertOk()->viewData('page')['props']['reconciliations']['data'];
        $this->assertSame([], $listed);
        $this->get(route('accounting.reconciliations.show', $reconciliation->id))->assertNotFound();
        $this->put(route('accounting.reconciliations.update', $reconciliation->id), ['line_ids' => [], 'complete' => false])->assertNotFound();
        // Whatever the request carries (here nothing valid) and whatever its status, the answer is that of a reconciliation
        // that does not exist: neither its existence nor its status shows.
        $answer = fn (int $id) => str_replace((string) $id, '#', (string) $this->putJson(route('accounting.reconciliations.update', $id), [])->assertNotFound()->json('message'));
        $this->assertSame($answer(999999), $answer($reconciliation->id));
        $this->assertSame($answer(999999), $answer($completed->id));
        $this->assertSame(['draft', 'completed'], [$reconciliation->fresh()->status, $completed->fresh()->status]);

        $this->inBranch($this->owner, $this->b->id);
        $this->assertCount(2, $this->get(route('accounting.reconciliations.index'))->assertOk()->viewData('page')['props']['reconciliations']['data']);
        $this->get(route('accounting.reconciliations.show', $reconciliation->id))->assertOk();
    }

    public function test_a_voucher_account_of_the_wrong_type_is_named_as_such(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $expenseType = Account::create(['code' => '4999', 'name_ar' => 'إيراد إيجار', 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true]);

        $this->post(route('finance.revenues.store'), ['title' => 'إيجار', 'category' => 'عام', 'amount' => 400, 'revenue_date' => self::DATE, 'payment_method' => 'cash', 'account_id' => $expenseType->id])
            ->assertSessionHasErrors(['account_id' => 'اختر حساب إيرادات فرعيًا.']);
        $this->post(route('finance.expenses.store'), ['title' => 'إيجار', 'category' => 'عام', 'amount' => 400, 'expense_date' => self::DATE, 'payment_method' => 'cash', 'account_id' => $this->role('other_revenue')])
            ->assertSessionHasErrors(['account_id' => 'اختر حساب مصروفات فرعيًا.']);
    }

    // ------------------------------------------------------------------ accounts the system posts to by itself

    /** Saves $id from the chart form with $changes over what the form shows. */
    protected function editAccount(int $id, array $changes): TestResponse
    {
        return $this->put(route('accounting.accounts.update', $id), $changes + Account::withoutGlobalScopes()->findOrFail($id)->only(['code', 'name_ar', 'type', 'nature', 'parent_id', 'is_parent', 'is_active']));
    }

    protected function accountActive(int $id): bool
    {
        return (bool) Account::withoutGlobalScopes()->whereKey($id)->value('is_active');
    }

    /**
     * Every branch posts to a role account (box-less vouchers and POS to the default cash, every sale to output VAT) and
     * to the ledger of a box shared by all branches: switched off, each of those postings is refused («موقوف»), so no
     * one switches them off or retypes them, the owner included, until the role is mapped elsewhere. A user still
     * switches off the ledger of their own branch's box, but not one another branch's box shares.
     */
    public function test_accounts_every_branch_posts_to_are_neither_switched_off_nor_retyped(): void
    {
        $this->world();
        $shared = $this->sharedBox();
        $this->inBranch($this->userIn([$this->a], 'accountant'));
        $role = 'هذا الحساب مربوط في «ربط الحسابات» ويرحّل عليه النظام لكل الفروع؛ لا يُوقف ولا يتغير نوعه أو طبيعته. اربط الدور بحساب آخر من «ربط الحسابات» أولًا.';

        foreach (['default_cash', 'default_bank', 'petty_cash', 'vat_output'] as $name) {
            $this->editAccount($this->role($name), ['is_active' => false])->assertSessionHas('error', $role);
            $this->assertTrue($this->accountActive($this->role($name)), $name);
        }
        // Retyped before any posting (with postings, the chart refuses it anyway).
        $this->editAccount($this->role('petty_cash'), ['nature' => 'credit'])->assertSessionHas('error', $role);
        $this->editAccount($this->role('other_revenue'), ['type' => 'expense', 'nature' => 'debit'])->assertSessionHas('error', $role);
        $this->assertSame(['asset', 'debit'], array_values(Account::withoutGlobalScopes()->findOrFail($this->role('petty_cash'))->only(['type', 'nature'])));

        // The ledger of a box every branch's vouchers may go through.
        $this->editAccount((int) $shared->account_id, ['is_active' => false])->assertSessionHas('error', 'هذا الحساب حساب صندوق أو بنك مشترك بين كل الفروع؛ لا يُوقف.');
        $this->assertTrue($this->accountActive((int) $shared->account_id));
        // A ledger branch A's box shares with a box of branch B (older data): switched off, branch B's box stops too.
        DB::table('bank_accounts')->where('id', $this->boxB->id)->update(['account_id' => $this->boxA->account_id]);
        $this->editAccount((int) $this->boxA->account_id, ['is_active' => false])->assertSessionHas('error', 'هذا الحساب مربوط بصندوق أو بنك لفرع آخر.');
        DB::table('bank_accounts')->where('id', $this->boxB->id)->update(['account_id' => $this->boxB->account_id]);
        // Its own branch's box alone: switched off and on again.
        $this->editAccount((int) $this->boxA->account_id, ['is_active' => false])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertFalse($this->accountActive((int) $this->boxA->account_id));
        $this->editAccount((int) $this->boxA->account_id, ['is_active' => true])->assertSessionMissing('error');

        // The owner neither, while the role is mapped to it; mapped to another account first, the old one is switched off.
        $this->inBranch($this->owner, $this->a->id);
        $petty = $this->role('petty_cash');
        $this->editAccount($petty, ['is_active' => false])->assertSessionHas('error', $role);
        $custody = Account::create(['code' => '11196', 'name_ar' => 'عهد نقدية', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true]);
        $this->put(route('accounting.mappings.update'), ['mapping' => ['petty_cash' => $custody->id]])->assertSessionHasNoErrors();
        app(AccountMap::class)->flush();
        $this->editAccount($petty, ['is_active' => false])->assertSessionMissing('error');
        $this->assertFalse($this->accountActive($petty));

        // So branch B's box-less vouchers still post to the default cash.
        $this->inBranch($this->userIn([$this->b], 'accountant'));
        $mark = $this->mark();
        $this->post(route('finance.expenses.store'), ['title' => 'ضيافة', 'category' => 'ضيافة', 'amount' => 30, 'expense_date' => self::DATE, 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertLine($this->linesSince($mark), $this->role('default_cash'), 0, 30);
    }

    /** Deleted, a role account leaves AccountMap with nothing: box-less vouchers fail, and new boxes (opened beside the default cash or bank). */
    public function test_a_role_account_is_not_deleted_while_a_role_is_mapped_to_it(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $message = 'هذا الحساب مربوط في «ربط الحسابات» ويرحّل عليه النظام؛ لا يُحذف. اربط الدور بحساب آخر من «ربط الحسابات» أولًا.';
        [$cash, $petty] = [$this->role('default_cash'), $this->role('petty_cash')];

        foreach ([$cash, $this->role('default_bank'), $petty, $this->role('vat_input')] as $account) {
            $this->delete(route('accounting.accounts.destroy', $account))->assertSessionHas('error', $message);
            $this->assertTrue(Account::withoutGlobalScopes()->whereKey($account)->exists());
        }
        app(AccountMap::class)->flush();
        $this->assertSame($cash, $this->role('default_cash'));
        $box = $this->openBox('صندوق جديد', 'cash', 0);
        $parentOf = fn (int $account) => (int) Account::withoutGlobalScopes()->whereKey($account)->value('parent_id');
        $this->assertSame($parentOf($cash), $parentOf((int) $box->account_id));

        // Mapped to another account first, it goes.
        $custody = Account::create(['code' => '11196', 'name_ar' => 'عهد نقدية', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true]);
        $this->put(route('accounting.mappings.update'), ['mapping' => ['petty_cash' => $custody->id]])->assertSessionHasNoErrors();
        app(AccountMap::class)->flush();
        $this->delete(route('accounting.accounts.destroy', $petty))->assertSessionHas('success');
        $this->assertFalse(Account::withoutGlobalScopes()->whereKey($petty)->exists());
    }

    public function test_a_switched_off_account_is_never_a_new_choice_for_a_role(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $off = Account::create(['code' => '11197', 'name_ar' => 'صندوق موقوف', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => false]);
        $message = 'هذا الحساب موقوف؛ اختر حسابًا نشطًا.';
        $settings = ['name' => $this->company->name];
        foreach (['sales_revenue', 'purchases_cogs', 'vat_output', 'vat_input', 'receivables', 'payables', 'default_cash', 'default_bank'] as $role) {
            $settings["acc_{$role}_id"] = $this->role($role);
        }

        foreach (['default_cash', 'petty_cash', 'receivables'] as $role) {
            $this->put(route('accounting.mappings.update'), ['mapping' => [$role => $off->id]])->assertSessionHasErrors(["mapping.{$role}" => $message]);
        }
        $this->put(route('settings.update'), ['acc_default_bank_id' => $off->id] + $settings)->assertSessionHasErrors(['acc_default_bank_id' => $message]);
        app(AccountMap::class)->flush();
        $this->assertNotContains($off->id, array_map('intval', array_filter(app(AccountMap::class)->all())));

        // A mapped account older data switched off: saving either page unchanged still goes through.
        DB::table('accounts')->where('id', $this->role('default_bank'))->update(['is_active' => false]);
        $this->put(route('accounting.mappings.update'), ['mapping' => ['default_bank' => $this->role('default_bank')]])->assertSessionHasNoErrors();
        $this->put(route('settings.update'), $settings)->assertSessionHasNoErrors();

        // The settings page's role pickers offer no switched-off account, but keep showing the one mapped now.
        $offered = collect($this->get(route('settings.index'))->assertOk()->viewData('page')['props']['accounts'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($off->id, $offered);
        $this->assertContains($this->role('default_bank'), $offered);
    }

    /**
     * Each purchase, depreciation run and disposal of a category's assets posts to its asset and accumulated-depreciation
     * accounts: neither is another role's account (it would move the default cash, the customers' receivables, input
     * VAT...) nor a switched-off one. The form and its quick-create dialog offer only what the category accepts.
     */
    public function test_an_asset_category_takes_no_other_role_account_nor_a_switched_off_one(): void
    {
        $this->world();
        $this->inBranch($this->userIn([$this->a], 'accountant'));
        $category = fn (array $accounts) => $this->post(route('accounting.fixed-assets.categories.store'), $accounts + ['name_ar' => 'سيارات', 'useful_life_months' => 60, 'depreciation_method' => 'straight_line']);
        $roleMessage = 'هذا الحساب مربوط بدور آخر في «ربط الحسابات»؛ لا يكون حسابًا لفئة أصول.';
        $off = Account::create(['code' => '15991', 'name_ar' => 'أصل موقوف', 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => false]);

        foreach (['asset_account_id' => ['default_cash', 'receivables', 'vat_input', 'accumulated_depreciation'], 'accumulated_account_id' => ['default_bank', 'petty_cash', 'inventory', 'fixed_assets']] as $field => $roles) {
            foreach ($roles as $role) {
                $category([$field => $this->role($role)])->assertSessionHasErrors([$field => $roleMessage]);
            }
        }
        $category(['asset_account_id' => $off->id])->assertSessionHasErrors(['asset_account_id' => 'هذا الحساب موقوف؛ اختر حسابًا نشطًا.']);
        $this->assertSame(0, AssetCategory::withoutGlobalScopes()->count());

        $refused = [$this->role('default_cash'), $this->role('receivables'), $this->role('vat_input'), (int) $this->boxA->account_id, (int) $this->boxB->account_id, $off->id];
        $offered = collect($this->get(route('accounting.fixed-assets.index'))->assertOk()->viewData('page')['props']['categoryAccounts'])
            ->map(fn ($accounts) => collect($accounts)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $looked = fn (string $field) => collect($this->getJson(route('lookup.list', 'accounts').'?asset_category='.$field)->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (['asset_account_id' => ['fixed_assets', 'accumulated_depreciation'], 'accumulated_account_id' => ['accumulated_depreciation', 'fixed_assets']] as $field => [$own, $other]) {
            foreach ([$offered[$field], $looked($field)] as $list) {
                $this->assertContains($this->role($own), $list, $field);
                $this->assertSame([], array_values(array_intersect($list, [...$refused, $this->role($other)])), $field);
            }
        }
        // The expense account is not concerned: depreciation may go to the general expenses.
        $this->assertContains($this->role('general_expense'), $offered['expense_account_id']);
        $this->assertContains($this->role('general_expense'), $looked('expense_account_id'));

        $category(['asset_account_id' => $this->role('fixed_assets'), 'accumulated_account_id' => $this->role('accumulated_depreciation'), 'expense_account_id' => $this->role('general_expense')])->assertSessionHasNoErrors();
        $this->assertSame(1, AssetCategory::withoutGlobalScopes()->count());
    }

    /** The database refuses a second account with the same code: the chart answers with a message, not a server error. */
    public function test_a_duplicate_account_code_is_refused_with_a_message(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $taken = (string) Account::withoutGlobalScopes()->whereKey($this->role('general_expense'))->value('code');
        $message = 'رقم الحساب مستخدم لحساب آخر في الدليل.';
        $new = fn (string $code) => ['code' => $code, 'name_ar' => 'حساب جديد', 'type' => 'expense', 'nature' => 'debit'];

        $this->post(route('accounting.accounts.store'), $new($taken))->assertSessionHasErrors(['code' => $message]);
        $this->postJson(route('accounting.accounts.store'), $new($taken))->assertUnprocessable()->assertJsonValidationErrors(['code' => $message]);
        $this->assertSame(1, Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $taken)->count());

        // A code only another company uses is free; recoded onto another account's code, an account is refused.
        $other = Company::factory()->create();
        DB::table('accounts')->insert(['company_id' => $other->id, 'code' => '889001', 'name_ar' => 'حساب منشأة أخرى', 'type' => 'expense', 'nature' => 'debit', 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('accounting.accounts.store'), $new('889001'))->assertSessionHasNoErrors();
        $mine = (int) Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '889001')->value('id');
        $this->editAccount($mine, ['code' => $taken])->assertSessionHasErrors(['code' => $message]);
        $this->editAccount($mine, ['name_ar' => 'حساب معدّل'])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(['889001', 'حساب معدّل'], array_values(Account::withoutGlobalScopes()->findOrFail($mine)->only(['code', 'name_ar'])));
    }

    /**
     * The trial balance rolls an account up into its parent: under a posting account it would be added to that account's
     * own postings, and under one of its sub-accounts the chart would loop. A posting account with no postings becomes a
     * group, as when a sub-account is added under it.
     */
    public function test_an_account_is_moved_only_under_a_group_and_never_under_its_own_sub_account(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        $make = fn (string $code, array $extra = []) => (int) Account::create($extra + ['code' => $code, 'name_ar' => "حساب {$code}", 'type' => 'expense', 'nature' => 'debit', 'is_parent' => false, 'balance' => 0, 'is_active' => true])->id;
        $value = fn (int $account, string $column) => Account::withoutGlobalScopes()->whereKey($account)->value($column);

        [$leaf, $moved, $posted] = [$make('59101'), $make('59102'), $make('59103')];
        $this->editAccount($moved, ['parent_id' => $leaf])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame([true, $leaf], [(bool) $value($leaf, 'is_parent'), (int) $value($moved, 'parent_id')]);

        // Not under one with postings, nor one the system posts to.
        $this->post(route('accounting.journals.store'), ['date' => self::DATE, 'description' => 'قيد', 'save_as' => 'posted', 'lines' => [
            ['account_id' => $posted, 'debit' => 50, 'credit' => 0], ['account_id' => $this->boxA->account_id, 'debit' => 0, 'credit' => 50],
        ]])->assertSessionHasNoErrors();
        $this->editAccount($moved, ['parent_id' => $posted])->assertSessionHas('error', 'لا يمكن تحويل حساب عليه حركات إلى حساب رئيسي.');
        $this->editAccount($moved, ['parent_id' => $this->role('general_expense')])->assertSessionHas('error', 'لا يمكن تحويل حساب يرحّل عليه النظام (صندوق أو بنك أو حساب مربوط في ربط الحسابات) إلى حساب رئيسي.');
        $this->assertSame([false, false, $leaf], [(bool) $value($posted, 'is_parent'), (bool) $value($this->role('general_expense'), 'is_parent'), (int) $value($moved, 'parent_id')]);

        // A group keeps its sub-accounts as a group.
        $this->editAccount($leaf, ['is_parent' => false])->assertSessionHas('error', 'لا يمكن تحويل حساب رئيسي له حسابات فرعية إلى حساب فرعي. انقل حساباته الفرعية أولًا.');
        $this->assertTrue((bool) $value($leaf, 'is_parent'));

        // Never under one of its own sub-accounts.
        $this->editAccount($moved, ['is_parent' => true])->assertSessionMissing('error');
        $grandchild = $make('59104', ['parent_id' => $moved]);
        foreach ([$moved, $grandchild] as $below) {
            $this->editAccount($leaf, ['parent_id' => $below])->assertSessionHas('error', 'لا يمكن جعل الحساب تابعاً لأحد حساباته الفرعية.');
        }
        $this->assertNull($value($leaf, 'parent_id'));

        // Older data with an account under a posting account: saved unchanged, it goes through.
        $legacy = $make('59105');
        DB::table('accounts')->where('id', $legacy)->update(['parent_id' => $posted]);
        $this->editAccount($legacy, ['name_ar' => 'حساب قديم'])->assertSessionHasNoErrors()->assertSessionMissing('error');
    }

    /** Another branch's register answers as one that does not exist: neither its existence, nor its branch, nor an open shift shows. */
    public function test_a_shift_is_opened_only_on_a_register_of_the_users_branches(): void
    {
        $this->world();
        $this->inBranch($this->owner, $this->b->id);
        $warehouseB = Warehouse::create(['branch_id' => $this->b->id, 'name_ar' => 'مستودع ب', 'code' => 'WB', 'is_active' => true]);
        $this->post(route('pos.registers.store'), ['name' => 'كاشير ب', 'warehouse_id' => $warehouseB->id, 'bank_account_id' => $this->boxB->id, 'is_active' => true])->assertSessionHasNoErrors();
        $register = PosRegister::withoutGlobalScopes()->where('name', 'كاشير ب')->firstOrFail();
        $this->assertSame($this->b->id, (int) $register->branch_id);

        $cashier = $this->userIn([$this->a], 'cashier');
        $this->inBranch($cashier);
        // Without a float (the shift itself was refused as one of another branch), and with one from the cashier's own
        // box (refused as a box of another branch).
        $answer = fn (int $id, array $float = []) => $this->postJson(route('pos.session.open'), ['pos_register_id' => $id, 'opening_cash' => 0] + $float)
            ->assertUnprocessable()->json('errors');
        $float = ['opening_cash' => 100, 'float_bank_account_id' => $this->boxA->id];
        $missing = $answer(999999);
        $this->assertSame($missing, $answer($register->id));
        $this->assertSame($missing, $answer(999999, $float));
        $this->assertSame($missing, $answer($register->id, $float));
        $this->assertSame(['pos_register_id' => ['جهاز نقاط البيع المحدد غير موجود.']], $missing);

        // A cashier of both branches opens a shift on it, from branch A's top bar, in branch B.
        $this->inBranch($this->userIn([$this->a, $this->b], 'cashier'), $this->a->id);
        $this->post(route('pos.session.open'), ['pos_register_id' => $register->id, 'opening_cash' => 0])->assertSessionHasNoErrors();
        $this->assertSame([$this->b->id], PosSession::withoutGlobalScopes()->pluck('branch_id')->map(fn ($id) => (int) $id)->all());

        // The open shift does not show either.
        $this->inBranch($cashier);
        $this->assertSame($missing, $answer($register->id));
        $this->assertSame($missing, $answer($register->id, $float));
        $this->assertSame(1, PosSession::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ quick-create from the pickers of these forms

    /** The <SelectCreate> tag bound to $model on a page (attribute values may hold «=>»). */
    protected function picker(string $page, string $model): string
    {
        preg_match_all('/<SelectCreate\b(?:\s+[^\s=>"\/]+(?:="[^"]*")?)*\s*\/?>/', file_get_contents(resource_path("js/Pages/{$page}.vue")), $m);
        $tags = array_values(array_filter($m[0], fn (string $tag) => str_contains($tag, "v-model=\"{$model}\"")));
        $this->assertCount(1, $tags, "{$page}: the picker of {$model}");

        return $tags[0];
    }

    /** The asset form's category picker creates an asset category (it made an inventory category), on the default accounts unless its dialog picks others. */
    public function test_the_asset_forms_category_picker_creates_an_asset_category(): void
    {
        $this->assertStringContainsString('entity="asset_category"', $this->picker('Accounting/FixedAssets/Index', 'assetForm.asset_category_id'));
        $registry = file_get_contents(resource_path('js/quickCreate.js'));
        $this->assertMatchesRegularExpression("/asset_category: \\{\\s*title: [^\\n]*route: 'accounting\\.fixed-assets\\.categories\\.store'/", $registry);
        foreach (['asset_account_id', 'accumulated_account_id', 'expense_account_id'] as $field) {
            $this->assertStringContainsString("query: { asset_category: '{$field}' }", $registry);
        }

        $this->world();
        $this->inBranch($this->userIn([$this->a], 'accountant'));
        $inventoryCategories = Category::withoutGlobalScopes()->count();
        // What the dialog sends with its account pickers left empty.
        $record = $this->postJson(route('accounting.fixed-assets.categories.store'), ['name_ar' => 'سيارات', 'name_en' => '', 'useful_life_months' => 60, 'depreciation_method' => 'straight_line',
            'asset_account_id' => '', 'accumulated_account_id' => '', 'expense_account_id' => ''])->assertCreated()->json('record');
        $category = AssetCategory::withoutGlobalScopes()->findOrFail($record['id']);
        $this->assertSame([$this->role('fixed_assets'), $this->role('accumulated_depreciation'), $this->role('depreciation_expense')],
            array_map('intval', array_values($category->only(['asset_account_id', 'accumulated_account_id', 'expense_account_id']))));
        $this->assertSame($inventoryCategories, Category::withoutGlobalScopes()->count());

        // An asset registered on it right away.
        $this->post(route('accounting.fixed-assets.store'), ['asset_category_id' => $category->id, 'branch_id' => $this->a->id, 'code' => 'FA-1', 'name_ar' => 'سيارة',
            'acquisition_date' => '2026-01-01', 'cost' => 10000, 'paid_from' => 'opening'])->assertSessionHasNoErrors();
        $this->assertSame($category->id, (int) FixedAsset::withoutGlobalScopes()->where('code', 'FA-1')->value('asset_category_id'));
    }

    /** The register's drawer and the shift's float list cash boxes only: a box made from either picker is a cash box. */
    public function test_the_pos_box_pickers_create_a_cash_box(): void
    {
        foreach (['POS/Registers/Form' => 'form.bank_account_id', 'POS/OpenSession' => 'form.float_bank_account_id'] as $page => $model) {
            $tag = $this->picker($page, $model);
            $this->assertStringContainsString('entity="bank"', $tag, $page);
            $this->assertMatchesRegularExpression("/:defaults=\"\\{ ?type: 'cash' ?\\}\"/", $tag, $page);
        }

        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        // What the dialog sends once its type is preset to cash (the bank fields are hidden then).
        $box = $this->postJson(route('finance.banks.store'), ['name' => 'درج أ', 'type' => 'cash', 'currency' => 'SAR', 'opening_balance' => 0, 'account_id' => ''])->assertCreated()->json('record');
        $this->assertSame('cash', $box['type']);
        $listed = collect($this->get(route('pos.registers.create'))->assertOk()->viewData('page')['props']['boxes'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $box['id'], $listed);
        $this->post(route('pos.registers.store'), ['name' => 'كاشير أ', 'warehouse_id' => $this->warehouse->id, 'bank_account_id' => $box['id'], 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertSame((int) $box['id'], (int) PosRegister::withoutGlobalScopes()->where('name', 'كاشير أ')->value('bank_account_id'));
    }

    /** The parent picker of the account form creates a group account of the account's type (it made an expense posting account). */
    public function test_the_parent_account_picker_creates_a_group_of_the_accounts_type(): void
    {
        $tag = $this->picker('Accounting/Accounts/Form', 'editForm.parent_id');
        $this->assertStringContainsString('entity="account"', $tag);
        $this->assertMatchesRegularExpression('/:defaults="\{[^"]*type: editForm\.type[^"]*is_parent: true[^"]*\}"/', $tag);

        $this->world();
        $this->inBranch($this->owner, $this->a->id);
        // What its dialog sends for an asset account.
        $group = $this->postJson(route('accounting.accounts.store'), ['code' => '1199', 'name_ar' => 'عهد الفروع', 'name_en' => '', 'parent_id' => '', 'type' => 'asset', 'nature' => 'debit',
            'cash_flow_category' => '', 'is_parent' => true, 'requires_cost_center' => false])->assertCreated()->json('record');
        $this->assertSame([true, 'asset'], [(bool) $group['is_parent'], $group['type']]);
        $parents = collect($this->get(route('accounting.accounts.create'))->assertOk()->viewData('page')['props']['parentAccounts'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $group['id'], $parents);
        $this->post(route('accounting.accounts.store'), ['code' => '119901', 'name_ar' => 'عهدة فرع أ', 'parent_id' => $group['id'], 'type' => 'asset', 'nature' => 'debit'])->assertSessionHasNoErrors();
        $this->assertSame((int) $group['id'], (int) Account::withoutGlobalScopes()->where('code', '119901')->value('parent_id'));
    }
}
