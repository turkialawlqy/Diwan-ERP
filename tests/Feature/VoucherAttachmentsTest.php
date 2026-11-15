<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Files attached while a receipt voucher, a credit note or a debit note is written stay with it once saved. */
class VoucherAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->invoice = app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString()],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function stage(string $kind, string $name = 'scan.pdf'): int
    {
        return $this->post(route('attachments.stage', ['kind' => $kind]), ['file' => UploadedFile::fake()->create($name, 20, 'application/pdf')])->assertCreated()->json('data.id');
    }

    public function test_a_receipt_voucher_keeps_the_file_staged_for_it(): void
    {
        $id = $this->stage('customer-payment', 'transfer-slip.pdf');
        $this->post(route('sales.payments.store'), [
            'customer_id' => $this->customer->id, 'invoice_id' => $this->invoice->id, 'amount' => 500, 'payment_date' => now()->toDateString(),
            'payment_method' => 'cash', 'attachment_ids' => [$id],
        ])->assertSessionHasNoErrors();

        $payment = CustomerPayment::latest('id')->first();
        $this->assertSame($payment->id, Attachment::find($id)->attachable_id);
        $this->getJson(route('attachments.index', ['kind' => 'customer-payment', 'id' => $payment->id]))->assertOk()->assertJsonPath('data.0.original_name', 'transfer-slip.pdf');
    }

    public function test_a_credit_note_and_a_debit_note_keep_their_files(): void
    {
        $credit = $this->stage('credit-note');
        $this->post(route('sales.returns.store'), [
            'customer_id' => $this->customer->id, 'invoice_id' => $this->invoice->id, 'credit_note_date' => now()->toDateString(),
            'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'reason' => 'خصم', 'status' => 'approved', 'attachment_ids' => [$credit],
        ])->assertSessionHasNoErrors();
        $this->assertSame(CreditNote::latest('id')->first()->id, Attachment::find($credit)->attachable_id);

        $debit = $this->stage('debit-note');
        $this->post(route('sales.debit-notes.store'), [
            'customer_id' => $this->customer->id, 'invoice_id' => $this->invoice->id, 'debit_note_date' => now()->toDateString(),
            'reason' => 'فرق سعر', 'subtotal' => 50, 'tax_rate' => 15, 'attachment_ids' => [$debit],
        ])->assertSessionHasNoErrors();
        $this->assertSame(DebitNote::latest('id')->first()->id, Attachment::find($debit)->attachable_id);
    }

    public function test_a_file_staged_for_one_kind_is_not_taken_by_another_and_staging_needs_the_create_permission(): void
    {
        $slip = $this->stage('customer-payment');
        $this->post(route('sales.debit-notes.store'), [
            'customer_id' => $this->customer->id, 'invoice_id' => $this->invoice->id, 'debit_note_date' => now()->toDateString(),
            'reason' => 'x', 'subtotal' => 10, 'tax_rate' => 15, 'attachment_ids' => [$slip],
        ])->assertSessionHasNoErrors();
        $this->assertNull(Attachment::find($slip)->attachable_id);

        // A role that cannot create such documents cannot stage files for them either.
        $this->actingAs($this->userWithRole($this->company, 'hr_manager'));
        $this->post(route('attachments.stage', ['kind' => 'supplier-payment']), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertForbidden();
    }

    public function test_a_supplier_payment_voucher_keeps_its_file(): void
    {
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $id = $this->stage('supplier-payment');
        $this->post(route('purchases.payments.store'), [
            'supplier_id' => $supplier->id, 'amount' => 200, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'attachment_ids' => [$id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(SupplierPayment::latest('id')->first()->id, Attachment::find($id)->attachable_id);
    }
}
