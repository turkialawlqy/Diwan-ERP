<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\ZatcaDevice;
use App\Models\ZatcaDocument;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Inventory\StockService;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesCycleService;
use App\Services\TenantManager;
use App\Services\Zatca\Certificate;
use App\Services\Zatca\InvoiceSigner;
use App\Services\Zatca\OnboardingService;
use App\Services\Zatca\Tlv;
use App\Services\Zatca\UblInvoiceBuilder;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ZatcaTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected Product $product;

    /** Fatoora behaviour per endpoint (overridable per test). */
    protected array $clearance = [200, ['clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]]];

    protected array $reporting = [200, ['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]]];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');

        $this->company = Company::factory()->create(['name' => 'شركة الأستاذ التجارية', 'tax_number' => '310122393500003', 'commercial_registration' => '1010010000']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));

        foreach (['seller_street' => 'الأمير سلطان', 'seller_building_number' => '2322', 'seller_district' => 'المربع', 'seller_city' => 'الرياض', 'seller_postal_code' => '23333'] as $k => $v) {
            $this->company->setSetting($k, $v, 'zatca');
        }

        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل', 'company_name' => 'شركة المشتري', 'customer_type' => 'commercial', 'status' => 'active',
            'tax_number' => '399999999900003', 'street_name' => 'العليا', 'building_number' => '1234', 'district' => 'العليا', 'city' => 'الرياض', 'postal_code' => '12345']);
        $this->product = Product::create(['name_ar' => 'صنف', 'sku' => 'P1', 'selling_price' => 100, 'track_inventory' => true, 'is_active' => true]);
        app(StockService::class)->receive(['product_id' => $this->product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100, 'unit_cost' => 40, 'type' => 'opening']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /**
     * Fake Fatoora: a test CA signs the device CSR into its CSID certificate.
     */
    protected function fakeFatoora(): void
    {
        $caKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        $caCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'TSZEINVOICE-SubCA-1', 'domainComponent' => 'extgazt'], $caKey, ['digest_alg' => 'sha256']), null, $caKey, 365, ['digest_alg' => 'sha256']);

        $token = function (Request $request) use ($caKey, $caCert) {
            $csr = base64_decode($request['csr'] ?? '') ?: ZatcaDevice::withoutGlobalScopes()->latest('id')->value('csr');
            $cert = openssl_csr_sign($csr, $caCert, $caKey, 365, ['digest_alg' => 'sha256'], random_int(1000, 99999));
            openssl_x509_export($cert, $pem);

            return base64_encode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
        };

        Http::fake(function (Request $request) use ($token) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/compliance') => Http::response(['requestID' => 1234567, 'binarySecurityToken' => $token($request), 'secret' => 'compliance-secret', 'dispositionMessage' => 'ISSUED']),
                str_ends_with($path, '/compliance/invoices') => Http::response(['validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]]),
                str_ends_with($path, '/production/csids') => Http::response(['requestID' => 7654321, 'binarySecurityToken' => $token($request), 'secret' => 'production-secret']),
                str_ends_with($path, '/clearance/single') => Http::response(array_merge($this->clearance[1], $this->clearance[0] === 200 ? ['clearedInvoice' => $request['invoice']] : []), $this->clearance[0]),
                str_ends_with($path, '/reporting/single') => Http::response($this->reporting[1], $this->reporting[0]),
                default => Http::response([], 404),
            };
        });
    }

    protected function onboard(): ZatcaDevice
    {
        $this->fakeFatoora();
        $this->post(route('zatca.devices.store'), ['name' => 'POS-1', 'environment' => 'sandbox', 'invoice_types' => '1100', 'business_category' => 'Retail'])->assertSessionHasNoErrors();
        $device = ZatcaDevice::firstOrFail();
        $this->post(route('zatca.devices.onboard', $device), ['otp' => '123345'])->assertSessionHasNoErrors();

        return $device->fresh();
    }

    protected function invoice(string $type = 'standard'): Invoice
    {
        return app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'invoice_type' => $type],
            [['product_id' => $this->product->id, 'item_name' => 'صنف', 'quantity' => 3, 'unit_price' => 100, 'discount' => 15, 'tax_rate' => 15]]);
    }

    public function test_tlv_handles_long_arabic_values_and_round_trips(): void
    {
        $name = str_repeat('مؤسسة', 40); // 400 bytes
        $decoded = Tlv::decode(Tlv::encode([1 => $name, 2 => '310122393500003', 4 => '100.00']));

        $this->assertLessThanOrEqual(255, strlen($decoded[1]));
        $this->assertTrue(mb_check_encoding($decoded[1], 'UTF-8'));
        $this->assertSame('310122393500003', $decoded[2]);
        $this->assertSame('100.00', $decoded[4]);
    }

    public function test_phase_one_qr_without_device(): void
    {
        $invoice = $this->invoice();
        $tags = Tlv::decode($invoice->zatca_qr);

        $this->assertSame('generated', $invoice->zatca_status);
        $this->assertSame('شركة الأستاذ التجارية', $tags[1]);
        $this->assertSame('310122393500003', $tags[2]);
        $this->assertSame('327.75', $tags[4]); // (300 - 15) * 1.15
        $this->assertSame('42.75', $tags[5]);
        $this->assertSame(0, ZatcaDocument::count());
    }

    public function test_device_requires_complete_seller_profile(): void
    {
        $this->company->setSetting('seller_postal_code', '', 'zatca');
        $this->expectException(ValidationException::class);
        app(OnboardingService::class)->createDevice($this->company->fresh(), ['name' => 'X', 'environment' => 'sandbox']);
    }

    public function test_onboarding_and_signed_invoice_chain_with_clearance(): void
    {
        $device = $this->onboard();
        $this->assertSame('active', $device->status);
        $this->assertCount(6, $device->compliance_checks);
        $this->assertStringContainsString('PRIVATE KEY-----', $device->private_key);
        $this->assertNotSame($device->private_key, $device->getRawOriginal('private_key'), 'Private key is encrypted at rest');

        $first = $this->invoice();
        $second = $this->invoice();

        $docs = ZatcaDocument::orderBy('icv')->get();
        $this->assertSame([1, 2], $docs->pluck('icv')->all());
        $this->assertSame(ZatcaDevice::INITIAL_HASH, $docs[0]->previous_hash);
        $this->assertSame($docs[0]->invoice_hash, $docs[1]->previous_hash, 'PIH chains to the previous invoice hash');
        $this->assertSame($docs[1]->invoice_hash, $device->fresh()->last_hash);

        $signer = app(InvoiceSigner::class);
        $this->assertTrue($signer->verify($docs[0]->signed_xml));
        $this->assertSame('clearance', $docs[0]->submission);
        $this->assertSame('cleared', $docs[0]->status);
        $this->assertSame('cleared', $first->fresh()->zatca_status);

        // QR phase 2 tags.
        $tags = Tlv::decode($first->fresh()->zatca_qr);
        $this->assertSame($docs[0]->invoice_hash, $tags[6]);
        $this->assertSame((new Certificate(base64_decode($device->production_token)))->publicKey(), $tags[8]);
        $this->assertArrayHasKey(9, $tags);

        // Tampering breaks the signature.
        $this->assertFalse($signer->verify(str_replace('<cbc:PayableAmount currencyID="SAR">327.75', '<cbc:PayableAmount currencyID="SAR">1.00', $docs[0]->signed_xml)));
    }

    public function test_ubl_content_and_totals(): void
    {
        $this->onboard();
        $invoice = $this->invoice();
        $xml = ZatcaDocument::firstOrFail()->signed_xml;

        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xpath = new \DOMXPath($doc);
        foreach (UblInvoiceBuilder::NS as $p => $uri) {
            $xpath->registerNamespace($p, $uri);
        }
        $value = fn ($q) => $xpath->query($q)->item(0)?->nodeValue;

        $this->assertSame($invoice->invoice_number, $value('/inv:Invoice/cbc:ID'));
        $this->assertSame($invoice->zatca_uuid, $value('/inv:Invoice/cbc:UUID'));
        $this->assertSame('0100000', $xpath->query('/inv:Invoice/cbc:InvoiceTypeCode')->item(0)->getAttribute('name'));
        $this->assertSame('388', $value('/inv:Invoice/cbc:InvoiceTypeCode'));
        $this->assertSame('310122393500003', $value('//cac:AccountingSupplierParty//cbc:CompanyID'));
        $this->assertSame('399999999900003', $value('//cac:AccountingCustomerParty//cbc:CompanyID'));
        $this->assertSame('285.00', $value('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
        $this->assertSame('327.75', $value('//cac:LegalMonetaryTotal/cbc:PayableAmount'));
        $this->assertSame('15.00', $value('//cac:InvoiceLine/cac:AllowanceCharge/cbc:Amount'));
        $this->assertSame('1', $value('//cac:AdditionalDocumentReference[cbc:ID="ICV"]/cbc:UUID'));
    }

    public function test_simplified_invoice_is_reported_and_return_references_it(): void
    {
        $this->onboard();
        $invoice = $this->invoice('simplified');
        $this->assertSame('reported', $invoice->fresh()->zatca_status);
        $this->assertSame('0200000', ZatcaDocument::firstOrFail()->subtype);

        $note = app(SalesCycleService::class)->returnInvoice($invoice, ['credit_note_date' => '2026-06-16', 'reason' => 'إرجاع'],
            [['invoice_item_id' => $invoice->items()->first()->id, 'quantity' => 1]]);

        $record = ZatcaDocument::where('documentable_id', $note->id)->where('type_code', '381')->firstOrFail();
        $this->assertSame('reporting', $record->submission);
        $this->assertStringContainsString('<cbc:ID>'.$invoice->invoice_number.'</cbc:ID>', $record->signed_xml);
        $this->assertStringContainsString('<cbc:InstructionNote>إرجاع</cbc:InstructionNote>', $record->signed_xml);
        $this->assertSame('reported', $note->fresh()->zatca_status);
    }

    public function test_rejection_and_failure_states(): void
    {
        $this->onboard();

        $this->clearance = [400, ['clearanceStatus' => 'NOT_CLEARED', 'validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-01', 'message' => 'invalid']], 'warningMessages' => []]]];
        $rejected = $this->invoice();
        $this->assertSame('rejected', $rejected->fresh()->zatca_status);
        $this->assertSame('BR-KSA-01', ZatcaDocument::firstOrFail()->errors[0]['code']);

        $this->clearance = [503, ['message' => 'Service unavailable']];
        $failed = $this->invoice();
        $record = ZatcaDocument::where('documentable_id', $failed->id)->firstOrFail();
        $this->assertSame('failed', $record->status);

        // The safety-net command re-queues it; ZATCA is back.
        $this->clearance = [200, ['clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]]];
        $record->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $this->artisan('erp:zatca-submit')->assertSuccessful();
        $this->assertSame('cleared', $record->fresh()->status);
    }

    public function test_compliance_page_and_xml_download(): void
    {
        $this->onboard();
        $this->invoice();
        $document = ZatcaDocument::firstOrFail();

        $this->get(route('zatca.index'))->assertOk();
        $response = $this->get(route('zatca.documents.xml', $document));
        $response->assertOk();
        $response->assertHeader('X-Signature-Valid', '1');
        $this->assertStringContainsString('<ds:SignatureValue>', $response->getContent());
    }
}
