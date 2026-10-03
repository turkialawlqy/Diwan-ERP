<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\ZatcaDevice;
use App\Models\ZatcaDocument;
use App\Services\Accounting\AccountingSetupService;
use App\Services\HR\LeaveService;
use App\Services\HR\PayrollCalculator;
use App\Services\Sales\InvoiceService;
use App\Services\Settings\CompanyPolicy;
use App\Services\TenantManager;
use App\Services\Zatca\InvoiceSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompanyPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function employee(Company $company, array $attributes = []): Employee
    {
        return app(TenantManager::class)->runAs($company, fn () => Employee::create(array_merge([
            'name' => 'موظف', 'job_title' => 'محاسب', 'department' => 'عام', 'hire_date' => '2020-01-01', 'nationality' => 'SA',
            'basic_salary' => 10000, 'housing_allowance' => 0, 'transport_allowance' => 0, 'other_allowances' => 0, 'total_salary' => 10000,
            'status' => 'active', 'gosi_registered' => true,
        ], $attributes)));
    }

    public function test_gosi_rates_are_per_company(): void
    {
        $other = Company::factory()->create();
        $mine = $this->employee($this->company);
        $theirs = $this->employee($other);

        $this->put(route('hr.settings.update', 'hr.gosi'), ['value' => [
            'saudi_employee_rate' => 10, 'saudi_employer_rate' => 12, 'non_saudi_employee_rate' => 0, 'non_saudi_employer_rate' => 2, 'base_annuity_rate' => 9,
            'min_wage' => 1500, 'max_wage' => 45000, 'new_law_start' => '2024-07-03', 'new_entrant_annuity' => [],
        ]])->assertSessionHasNoErrors();

        $june = Carbon::parse('2026-06-30');
        [$ee, $er] = app(PayrollCalculator::class)->gosi($mine, 10000, 1, $june);
        $this->assertSame(1000.0, $ee);
        $this->assertSame(1200.0, $er);

        // Another company keeps the platform defaults.
        [$ee] = app(TenantManager::class)->runAs($other, fn () => app(PayrollCalculator::class)->gosi($theirs, 10000, 1, $june));
        $this->assertSame(975.0, $ee);

        // Reset restores the default for this company too.
        $this->delete(route('hr.settings.reset', 'hr.gosi'))->assertSessionHasNoErrors();
        [$ee] = app(PayrollCalculator::class)->gosi($mine, 10000, 1, $june);
        $this->assertSame(975.0, $ee);
    }

    public function test_labour_rules_overrides_apply_and_are_validated(): void
    {
        $this->put(route('hr.settings.update', 'hr.annual_leave'), ['value' => ['days' => 30, 'senior_days' => 35, 'senior_after_years' => 5]])->assertSessionHasNoErrors();
        $this->put(route('hr.settings.update', 'hr.max_deduction_ratio'), ['value' => ['value' => 25]])->assertSessionHasNoErrors();
        $this->assertSame(0.25, CompanyPolicy::get('hr.max_deduction_ratio'));

        $e = $this->employee($this->company, ['hire_date' => '2025-06-15']);
        $this->assertEqualsWithDelta(30.0, app(LeaveService::class)->balance($e)['balance'], 0.1);

        // Below the legal minimum is refused.
        $this->put(route('hr.settings.update', 'hr.annual_leave'), ['value' => ['days' => 15, 'senior_days' => 30, 'senior_after_years' => 5]])
            ->assertSessionHasErrors('value.days');
        $this->get(route('hr.settings'))->assertOk();
    }

    public function test_settings_need_permission(): void
    {
        $this->actingAs($this->userWithRole($this->company, 'accountant'));
        $this->get(route('hr.settings'))->assertForbidden();
        $this->put(route('hr.settings.update', 'hr.month_days'), ['value' => ['value' => 30]])->assertForbidden();
    }

    protected function onboardZatca(): void
    {
        foreach (['seller_street' => 'الأمير سلطان', 'seller_building_number' => '2322', 'seller_district' => 'المربع', 'seller_city' => 'الرياض', 'seller_postal_code' => '23333'] as $k => $v) {
            $this->company->setSetting($k, $v, 'zatca');
        }
        $this->company->update(['tax_number' => '310122393500003', 'commercial_registration' => '1010010000']);

        // A device with a self-signed certificate is enough to exercise signing.
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'test'], $key, ['digest_alg' => 'sha256']), null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($key, $keyPem);
        $token = base64_encode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
        ZatcaDevice::create(['name' => 'T', 'serial_number' => '1-a|2-b|3-c', 'environment' => 'sandbox', 'status' => 'active',
            'private_key' => $keyPem, 'production_token' => $token, 'production_secret' => 's']);
        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED', 'clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS']])]);
    }

    public function test_product_exemption_reason_reaches_signed_xml(): void
    {
        $this->onboardZatca();
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active', 'tax_number' => '399999999900003',
            'street_name' => 'العليا', 'building_number' => '1234', 'district' => 'العليا', 'city' => 'الرياض', 'postal_code' => '12345']);
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $export = Product::create(['name_ar' => 'صنف تصدير', 'sku' => 'X1', 'selling_price' => 100, 'tax_rate' => 0, 'type' => 'service',
            'track_inventory' => false, 'is_active' => true, 'vat_category' => 'Z', 'vat_exemption_code' => 'VATEX-SA-32']);

        $invoice = app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-15'],
            [['product_id' => $export->id, 'item_name' => 'صنف تصدير', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15]], ['issue_stock' => false]);

        $this->assertSame(0.0, (float) $invoice->tax_amount, 'Zero-rated product carries no VAT');
        $this->assertSame('Z', $invoice->items()->first()->vat_category);
        $xml = ZatcaDocument::firstOrFail()->signed_xml;
        $this->assertStringContainsString('<cbc:TaxExemptionReasonCode>VATEX-SA-32</cbc:TaxExemptionReasonCode>', $xml);
        $this->assertStringContainsString('<cbc:ID>Z</cbc:ID>', $xml);
        $this->assertTrue(app(InvoiceSigner::class)->verify($xml));
    }

    public function test_zero_rate_without_reason_is_refused_until_a_default_is_set(): void
    {
        $this->onboardZatca();
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
        $line = [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]];

        try {
            app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-15', 'invoice_type' => 'simplified'], $line, ['issue_stock' => false]);
            $this->fail('Invoice without exemption reason accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('zatca', $e->errors());
        }
        $this->assertSame(0, ZatcaDocument::count());

        $this->put(route('zatca.tax-defaults'), ['zero_category' => 'Z', 'Z' => 'VATEX-SA-35'])->assertSessionHasNoErrors();
        app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => '2026-06-15', 'invoice_type' => 'simplified'], $line, ['issue_stock' => false]);
        $this->assertStringContainsString('VATEX-SA-35', ZatcaDocument::firstOrFail()->signed_xml);
    }
}
