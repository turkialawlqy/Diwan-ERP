<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Files attached to documents: upload, list, download, delete, and who may do what. */
class AttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

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
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
        $this->invoice = app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString()],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_file_is_attached_listed_downloaded_and_deleted(): void
    {
        $file = UploadedFile::fake()->create('عقد.pdf', 120, 'application/pdf');
        $created = $this->post(route('attachments.store', ['kind' => 'invoice', 'id' => $this->invoice->id]), ['file' => $file])->assertCreated()->json('data');

        $this->assertSame('عقد.pdf', $created['original_name']);
        $attachment = Attachment::firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringStartsWith("companies/{$this->company->id}/attachments/", $attachment->path);

        $this->get(route('attachments.index', ['kind' => 'invoice', 'id' => $this->invoice->id]))->assertOk()->assertJsonCount(1, 'data');
        $this->get(route('attachments.download', $attachment))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->delete(route('attachments.destroy', $attachment))->assertOk();
        $this->assertSame(0, Attachment::count());
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_unsafe_or_oversized_files_and_unknown_documents_are_refused(): void
    {
        $url = route('attachments.store', ['kind' => 'invoice', 'id' => $this->invoice->id]);
        $this->postJson($url, ['file' => UploadedFile::fake()->create('x.html', 5, 'text/html')])->assertStatus(422);
        $this->postJson($url, ['file' => UploadedFile::fake()->create('big.pdf', 20000, 'application/pdf')])->assertStatus(422);
        $this->postJson(route('attachments.store', ['kind' => 'nonsense', 'id' => 1]), ['file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')])->assertNotFound();
        $this->assertSame(0, Attachment::count());
    }

    public function test_another_company_cannot_see_or_download_the_file_and_a_clerk_without_permission_is_refused(): void
    {
        $this->post(route('attachments.store', ['kind' => 'invoice', 'id' => $this->invoice->id]), ['file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')])->assertCreated();
        $attachment = Attachment::firstOrFail();

        $other = Company::factory()->create(['subdomain' => 'beta']);
        app(TenantManager::class)->setTenant($other);
        $this->actingAs($this->userWithRole($other));
        $this->get(route('attachments.download', $attachment))->assertNotFound();
        $this->get(route('attachments.index', ['kind' => 'invoice', 'id' => $this->invoice->id]))->assertNotFound();

        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company, 'hr_manager'));
        $this->get(route('attachments.download', $attachment))->assertForbidden();
    }
}
