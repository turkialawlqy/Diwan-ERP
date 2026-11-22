<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\ActiveBranch;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use App\Support\BranchScopeSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The credit note screen (sales returns): a saved note opens on its page, an amount note on an
 * invoice is accepted with the empty line list the form sends, and «refund now» pays from a box.
 */
class CreditNoteFormTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Branch $riyadh;

    protected Branch $jeddah;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->jeddah = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function invoice(array $data = []): Invoice
    {
        return app(InvoiceService::class)->create(array_merge(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-01', 'branch_id' => $this->riyadh->id], $data),
            [['item_name' => 'خدمة', 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    /** What the form posts for an amount credit note: an empty line list beside the amounts. */
    protected function amountNote(Invoice $invoice, array $data = []): array
    {
        return array_merge(['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => '2026-06-30', 'reason' => 'خصم',
            'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'status' => 'approved', 'refund_method' => 'credit', 'items' => []], $data);
    }

    protected function box(string $name, ?Branch $branch, string $type = 'cash'): BankAccount
    {
        static $code = 11900;
        $ledger = Account::create(['code' => (string) ++$code, 'name_ar' => $name, 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'is_active' => true]);
        $create = fn () => BankAccount::create(['name' => $name, 'type' => $type, 'currency' => 'SAR', 'branch_id' => $branch?->id, 'is_active' => true, 'account_id' => $ledger->id]);

        // A box shared by all branches has no branch: made outside the signed-in user's branch.
        return $branch ? $create() : BranchScopeSwitch::off($create);
    }

    public function test_a_saved_credit_note_opens_on_its_page(): void
    {
        $invoice = $this->invoice();

        // Amount note: the save redirects to the note's page, which must open.
        $this->followingRedirects()->post(route('sales.returns.store'), $this->amountNote($invoice, ['mode' => 'amount']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Sales/Returns/Show')->where('creditNote.kind', 'amount'));

        // Goods return: its page shows the warehouse the items went back to.
        $this->followingRedirects()->post(route('sales.returns.store'), ['mode' => 'items', 'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id,
            'credit_note_date' => '2026-06-30', 'reason' => 'إرجاع', 'refund_method' => 'credit', 'items' => [['invoice_item_id' => $invoice->items->first()->id, 'quantity' => 1]]])
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Sales/Returns/Show')
            ->where('creditNote.kind', 'return')->where('creditNote.warehouse.name_ar', 'رئيسي'));

        $this->assertSame(2, CreditNote::count());
    }

    public function test_an_amount_credit_note_on_an_invoice_is_saved_with_the_empty_line_list(): void
    {
        $invoice = $this->invoice();

        // As the form sent it before it named the mode: an empty list is still an amount note.
        $this->post(route('sales.returns.store'), $this->amountNote($invoice))->assertSessionHasNoErrors();
        $this->post(route('sales.returns.store'), $this->amountNote($invoice, ['mode' => 'amount']))->assertSessionHasNoErrors();
        $this->assertSame(['amount', 'amount'], CreditNote::orderBy('id')->pluck('kind')->all());
        $this->assertEquals(1150 - 230, (float) $invoice->fresh()->remaining_amount);

        // A goods return without a returned quantity asks for one.
        $this->post(route('sales.returns.store'), $this->amountNote($invoice, ['mode' => 'items']))
            ->assertSessionHasErrors(['items' => 'حدد كمية مرتجعة واحدة على الأقل.']);
        $this->assertSame(2, CreditNote::count());
    }

    public function test_an_immediate_refund_is_paid_from_the_chosen_box(): void
    {
        $invoice = $this->invoice(['payment_method' => 'cash', 'payment_status' => 'paid']);
        $drawer = $this->box('صندوق الرياض', $this->riyadh);
        $bank = $this->box('بنك الرياض', $this->riyadh, 'bank');

        // «Refund now» with a cash box: paid out of that box, the customer's account is not credited.
        $this->post(route('sales.returns.store'), $this->amountNote($invoice, ['mode' => 'amount', 'status' => 'refunded', 'refund_method' => 'cash', 'bank_account_id' => $drawer->id]))
            ->assertSessionHasNoErrors();
        $note = CreditNote::latest('id')->firstOrFail();
        $this->assertSame(['refunded', 'cash', $drawer->id], [$note->status, $note->refund_method, (int) $note->bank_account_id]);
        $this->assertEquals(115.0, $this->creditOn($note, $drawer->account_id));
        $this->assertEquals(0.0, $this->creditOn($note, app(AccountMap::class)->id('receivables', $this->company->id)));

        // A bank box refunds by transfer, and a goods return too.
        $this->post(route('sales.returns.store'), ['mode' => 'items', 'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => '2026-06-30',
            'reason' => 'إرجاع', 'refund_method' => 'bank_transfer', 'bank_account_id' => $bank->id, 'items' => [['invoice_item_id' => $invoice->items->first()->id, 'quantity' => 1]]])
            ->assertSessionHasNoErrors();
        $return = CreditNote::latest('id')->firstOrFail();
        $this->assertSame(['refunded', 'bank_transfer'], [$return->status, $return->refund_method]);
        $this->assertEquals(575.0, $this->creditOn($return, $bank->account_id));
    }

    public function test_the_form_offers_the_boxes_of_the_invoice_branch_and_the_shared_ones(): void
    {
        $this->box('صندوق الرياض', $this->riyadh);
        $this->box('صندوق جدة', $this->jeddah);
        $this->box('البنك المركزي', null, 'bank');
        $invoice = $this->invoice(['branch_id' => $this->jeddah->id]);

        $names = fn ($session) => $this->withSession([ActiveBranch::SESSION_KEY => $session])->get(route('sales.returns.create', ['invoice_id' => $invoice->id]))
            ->assertOk()->inertiaProps('bankAccounts');

        // «All branches» lists every box; the Jeddah invoice's note is paid from Jeddah's or a shared one.
        $this->assertEqualsCanonicalizing(['صندوق جدة', 'البنك المركزي'], array_column($names(ActiveBranch::ALL), 'name'));
        $this->assertEqualsCanonicalizing(['صندوق جدة', 'البنك المركزي'], array_column($names($this->jeddah->id), 'name'));
    }

    public function test_the_return_pages_show_dates_formatted(): void
    {
        // A date-cast attribute reaches the page as an ISO timestamp ("2026-06-30T00:00:00.000000Z"); its field's error message is no date.
        foreach (['Index', 'Show', 'Create'] as $page) {
            $source = File::get(resource_path("js/Pages/Sales/Returns/{$page}.vue"));
            $this->assertDoesNotMatchRegularExpression('/\{\{\s*(?![\w.?]*errors\.)[\w.?]+_date\s*\}\}/', $source, "Sales/Returns/{$page}.vue prints a raw date");
        }
    }

    protected function creditOn(CreditNote $note, int $accountId): float
    {
        $entry = JournalEntry::where('source_type', $note->getMorphClass())->where('source_id', $note->id)->firstOrFail();

        return (float) $entry->lines()->where('account_id', $accountId)->sum('credit');
    }
}
