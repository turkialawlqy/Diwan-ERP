<?php

namespace Tests\Feature;

use App\Mail\DocumentMail;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DocumentDispatch;
use App\Models\Invoice;
use App\Models\ZatcaDevice;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Documents\DocumentSharing;
use App\Services\Sales\CustomerStatement;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use App\Support\Tafqeet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DocumentSharingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create(['email' => 'billing@seller.test']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'شركة العميل', 'customer_type' => 'commercial', 'status' => 'active', 'email' => 'ap@customer.test', 'mobile' => '0551234567',
            'tax_number' => '399999999900003', 'street_name' => 'العليا', 'building_number' => '1234', 'district' => 'العليا', 'city' => 'الرياض', 'postal_code' => '12345']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function invoice(array $data = []): Invoice
    {
        return app(InvoiceService::class)->create(array_merge(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15'], $data),
            [['item_name' => 'خدمة استشارية', 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 15]], ['issue_stock' => false]);
    }

    protected function onboardZatca(): void
    {
        foreach (['seller_street' => 'الأمير سلطان', 'seller_building_number' => '2322', 'seller_district' => 'المربع', 'seller_city' => 'الرياض', 'seller_postal_code' => '23333'] as $k => $v) {
            $this->company->setSetting($k, $v, 'zatca');
        }
        $this->company->update(['tax_number' => '310122393500003', 'commercial_registration' => '1010010000']);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'test'], $key, ['digest_alg' => 'sha256']), null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($key, $keyPem);
        ZatcaDevice::create(['name' => 'T', 'serial_number' => '1-a|2-b|3-c', 'environment' => 'sandbox', 'status' => 'active',
            'private_key' => $keyPem, 'production_token' => base64_encode(preg_replace('/-----[^-]+-----|\s+/', '', $pem)), 'production_secret' => 's']);
        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED', 'clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS']])]);
    }

    public function test_invoice_pdf_is_generated(): void
    {
        $invoice = $this->invoice();

        $response = $this->get(route('documents.pdf', ['type' => 'invoice', 'id' => $invoice->id]))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString($invoice->invoice_number.'.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_zatca_invoice_pdf_embeds_the_signed_xml_as_pdf_a3(): void
    {
        $this->onboardZatca();
        $invoice = $this->invoice();

        $pdf = $this->get(route('documents.pdf', ['type' => 'invoice', 'id' => $invoice->id]))->assertOk()->getContent();

        $this->assertStringContainsString('/EmbeddedFile', $pdf);
        $this->assertStringContainsString('/AFRelationship /Data', $pdf);
        $this->assertStringContainsString($invoice->invoice_number.'.xml', $pdf);
        $this->assertStringContainsString('pdfaid:part>3<', $pdf);
    }

    public function test_every_document_type_renders(): void
    {
        $invoice = $this->invoice();
        $note = CreditNote::create(['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_number' => 'CN-1', 'credit_note_date' => '2026-06-16', 'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'status' => 'approved', 'reason' => 'مرتجع']);

        foreach ([['credit_note', $note->id], ['statement', $this->customer->id]] as [$type, $id]) {
            $this->assertStringStartsWith('%PDF', $this->get(route('documents.pdf', compact('type', 'id')))->assertOk()->getContent(), $type);
        }
    }

    public function test_email_sends_the_pdf_and_logs_the_dispatch(): void
    {
        Mail::fake();
        $invoice = $this->invoice();

        $this->postJson(route('documents.send', ['type' => 'invoice', 'id' => $invoice->id]), ['channel' => 'email', 'to' => 'ap@customer.test', 'cc' => 'cfo@customer.test'])
            ->assertOk();

        Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) use ($invoice) {
            return $mail->hasTo('ap@customer.test') && $mail->hasCc('cfo@customer.test')
                && $mail->filename === $invoice->invoice_number.'.pdf' && str_starts_with($mail->pdf, '%PDF')
                && str_contains($mail->body, $invoice->invoice_number) && $mail->hasReplyTo('billing@seller.test');
        });
        $this->assertSame('sent', DocumentDispatch::first()->status);
    }

    public function test_send_validates_addresses_in_arabic(): void
    {
        $invoice = $this->invoice();

        $this->postJson(route('documents.send', ['type' => 'invoice', 'id' => $invoice->id]), ['channel' => 'email', 'to' => 'not-an-email', 'cc' => 'x@y.test, bad'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to', 'cc']);
    }

    public function test_whatsapp_returns_a_signed_link_that_works_without_login(): void
    {
        $invoice = $this->invoice();

        $data = $this->postJson(route('documents.send', ['type' => 'invoice', 'id' => $invoice->id]), ['channel' => 'whatsapp', 'phone' => '055 123 4567'])
            ->assertOk()->json();

        $this->assertStringStartsWith('https://wa.me/966551234567?text=', $data['url']);
        preg_match('#(https?://\S+/share/\S+/pdf/invoice/\d+\S*)#', urldecode($data['url']), $m);
        $link = $m[1];

        Auth::logout();
        app(TenantManager::class)->forget();
        $this->assertStringStartsWith('%PDF', $this->get($link)->assertOk()->getContent());

        // Tampering with the document id or the company breaks the signature.
        $this->get(str_replace("/invoice/{$invoice->id}", '/invoice/'.($invoice->id + 1), $link))->assertForbidden();
        $this->get(preg_replace('#/share/\d+/#', '/share/999/', $link))->assertForbidden();
    }

    public function test_portal_shows_only_this_customers_documents(): void
    {
        $mine = $this->invoice();
        $other = Customer::create(['name' => 'عميل آخر', 'customer_type' => 'individual', 'status' => 'active']);
        $theirs = $this->invoice(['customer_id' => $other->id]);
        $link = app(DocumentSharing::class)->portalLink($this->customer);

        Auth::logout();
        app(TenantManager::class)->forget();
        $this->get($link)->assertOk()->assertSee($mine->invoice_number)->assertDontSee($theirs->invoice_number)->assertSee('شركة العميل');
        $this->get($link.'&pdf=1')->assertForbidden(); // altering the signed query is rejected
    }

    public function test_sharing_needs_the_document_permission(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->userWithRole($this->company, 'warehouse_keeper'));

        $this->get(route('documents.pdf', ['type' => 'invoice', 'id' => $invoice->id]))->assertForbidden();
        $this->postJson(route('documents.send', ['type' => 'invoice', 'id' => $invoice->id]), ['channel' => 'whatsapp', 'phone' => '0551234567'])->assertForbidden();
        $this->get(route('documents.pdf', ['type' => 'unknown', 'id' => 1]))->assertForbidden();
    }

    public function test_statement_includes_credit_notes_and_amount_in_words(): void
    {
        $invoice = $this->invoice();
        CreditNote::create(['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_number' => 'CN-1', 'credit_note_date' => '2026-06-16', 'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'status' => 'approved']);
        CreditNote::create(['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_number' => 'CN-2', 'credit_note_date' => '2026-06-16', 'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'status' => 'refunded', 'refund_method' => 'cash']);

        $statement = app(CustomerStatement::class)->build($this->customer->fresh());

        $this->assertSame(1150.0 - 115.0, $statement['closing']);
        $this->assertSame('فقط ألف ومائة وخمسون ريال سعودي لا غير', Tafqeet::amount(1150));
        $this->assertSame('فقط ثلاثة عشر ألف وسبعمائة وستة عشر ريال سعودي وستة وثمانون هللة لا غير', Tafqeet::amount(13716.86));
    }
}
