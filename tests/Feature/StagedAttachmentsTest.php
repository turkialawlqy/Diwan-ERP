<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\AttachmentService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Files attached while an invoice is being written are kept with the invoice when it is saved. */
class StagedAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function invoicePayload(array $extra = []): array
    {
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);

        return $extra + [
            'customer_id' => $customer->id, 'invoice_date' => '2026-06-10', 'due_date' => '2026-06-10', 'supply_date' => '2026-06-10',
            'payment_method' => 'bank_transfer', 'payment_status' => 'unpaid',
            'items' => [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15]],
        ];
    }

    public function test_a_file_staged_before_the_invoice_exists_belongs_to_it_after_saving(): void
    {
        $this->actingAs($this->userWithRole($this->company));

        $id = $this->post(route('attachments.stage', ['kind' => 'invoice']), ['file' => UploadedFile::fake()->create('delivery-note.pdf', 50, 'application/pdf')])
            ->assertCreated()->json('data.id');
        $this->assertNull(Attachment::find($id)->attachable_id);

        $this->post(route('sales.invoices.store'), $this->invoicePayload(['attachment_ids' => [$id]]))->assertSessionHasNoErrors()->assertRedirect();

        $invoice = Invoice::latest('id')->first();
        $attachment = Attachment::find($id);
        $this->assertSame($invoice->id, $attachment->attachable_id);
        $this->assertNull($attachment->stage_kind);
        $this->getJson(route('attachments.index', ['kind' => 'invoice', 'id' => $invoice->id]))->assertOk()->assertJsonPath('data.0.original_name', 'delivery-note.pdf');
    }

    public function test_only_the_uploader_can_claim_or_see_a_staged_file_and_kinds_must_match(): void
    {
        $a = $this->userWithRole($this->company);
        $b = $this->userWithRole($this->company);
        $id = $this->actingAs($a)->post(route('attachments.stage', ['kind' => 'invoice']), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->json('data.id');

        // Another user cannot download it nor take it into their invoice.
        $this->actingAs($b)->get(route('attachments.download', $id))->assertForbidden();
        $this->post(route('sales.invoices.store'), $this->invoicePayload(['attachment_ids' => [$id]]));
        $this->assertNull(Attachment::find($id)->attachable_id);

        // A file staged for an invoice cannot be claimed by a purchase document.
        $this->actingAs($a);
        $invoice = Invoice::latest('id')->first();
        $other = Invoice::latest('id')->first() ?? null;
        $request = Request::create('/', 'POST', ['attachment_ids' => [$id]]);
        $request->setUserResolver(fn () => $a);
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $this->assertSame(0, app(AttachmentService::class)->claim($request, $supplier, 'purchase-invoice'));
    }

    public function test_bad_files_and_missing_permission_are_refused_and_stale_files_are_pruned(): void
    {
        $this->actingAs($this->userWithRole($this->company));
        $this->post(route('attachments.stage', ['kind' => 'invoice']), ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('file');
        $this->post(route('attachments.stage', ['kind' => 'nonsense']), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertNotFound();

        $this->actingAs($this->userWithRole($this->company, 'warehouse_keeper'));
        $this->post(route('attachments.stage', ['kind' => 'invoice']), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertForbidden();

        $this->actingAs($this->userWithRole($this->company));
        $id = $this->post(route('attachments.stage', ['kind' => 'invoice']), ['file' => UploadedFile::fake()->create('old.pdf', 10, 'application/pdf')])->json('data.id');
        Attachment::whereKey($id)->update(['created_at' => now()->subDays(2)]);
        $this->assertSame(1, app(AttachmentService::class)->pruneStaged(24));
        $this->assertNull(Attachment::find($id));
    }
}
