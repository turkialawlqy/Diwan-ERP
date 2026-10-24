<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\ZatcaDocument;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Documents\TemplateData;
use App\Services\Sales\InvoiceService;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Branches in several countries: each follows its country's tax (rates,
 * registration number, invoice title, e-invoicing system) and currency,
 * while Saudi branches behave exactly as before.
 */
class MultiCountryTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Branch $riyadh;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create(['currency' => 'SAR', 'country_code' => 'SA', 'tax_number' => '310122393500003']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        Currency::create(['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol_ar' => 'ر.س', 'symbol_en' => 'SAR', 'exchange_rate' => 1, 'is_default' => true]);
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function branch(string $country, array $extra = []): Branch
    {
        $this->post(route('branches.store'), ['name_ar' => "فرع {$country}", 'code' => "BR-{$country}", 'country_code' => $country] + $extra)->assertSessionHasNoErrors();

        return Branch::where('code', "BR-{$country}")->firstOrFail();
    }

    protected function invoice(Branch $branch, array $line = [], array $data = []): Invoice
    {
        return app(InvoiceService::class)->create(array_merge(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'branch_id' => $branch->id, 'payment_method' => 'credit'], $data),
            [array_merge(['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000], $line)]);
    }

    public function test_an_egyptian_branch_invoices_in_pounds_at_egyptian_vat_without_zatca(): void
    {
        $cairo = $this->branch('EG', ['tax_number' => '123456789']);
        $this->assertSame(['EG', 'EGP', 'Africa/Cairo'], [$cairo->country_code, TaxProfile::forBranch($cairo)->currency(), $cairo->timezone]);
        // The branch currency was added for exchange rates, with no rate until the user enters one.
        $egp = Currency::where('code', 'EGP')->firstOrFail();
        $this->assertSame(0.0, (float) $egp->exchange_rate);
        $egp->update(['exchange_rate' => 0.075]);

        $invoice = $this->invoice($cairo);
        $this->assertSame(['EGP', 14.0, 140.0, 1140.0], [$invoice->currency_code, (float) $invoice->items->first()->tax_rate, (float) $invoice->tax_amount, (float) $invoice->total]);
        $this->assertSame(0, ZatcaDocument::count());
        $this->assertNull($invoice->fresh()->zatca_qr);
        $this->assertSame('not_applicable', $invoice->fresh()->zatca_status);

        $fields = app(TemplateData::class)->build($invoice->fresh())['fields'];
        $this->assertSame('فاتورة ضريبية', $fields['doc.title']);
        $this->assertStringContainsString('جنيه مصري', $fields['totals.words']);
        $this->assertSame('EGP', $fields['totals.currency']);
    }

    public function test_tax_numbers_follow_the_country(): void
    {
        $this->post(route('branches.store'), ['name_ar' => 'القاهرة', 'code' => 'CAI', 'country_code' => 'EG', 'tax_number' => '310122393500003'])->assertSessionHasErrors('tax_number');
        $this->post(route('branches.store'), ['name_ar' => 'دبي', 'code' => 'DXB', 'country_code' => 'AE', 'tax_number' => '100123456700003'])->assertSessionHasNoErrors();

        $this->post(route('sales.clients.store'), ['name' => 'شركة النيل', 'customer_type' => 'commercial', 'country' => 'مصر', 'tax_number' => '123456789'])->assertSessionHasNoErrors();
        $this->post(route('sales.clients.store'), ['name' => 'شركة النيل 2', 'customer_type' => 'commercial', 'country' => 'مصر', 'tax_number' => '310122393500003'])->assertSessionHasErrors('tax_number');
        // An Egyptian buyer's address has no Saudi national-address format.
        $this->post(route('sales.clients.store'), ['name' => 'شركة النيل 3', 'customer_type' => 'commercial', 'country' => 'Egypt', 'building_number' => '12', 'postal_code' => '11511'])->assertSessionHasNoErrors();
    }

    public function test_a_uae_line_takes_the_uae_rate_and_a_reduced_algerian_rate_is_kept(): void
    {
        $dubai = $this->branch('AE', ['currency' => 'SAR']);
        $invoice = $this->invoice($dubai, ['tax_rate' => 15]);
        $this->assertSame(5.0, (float) $invoice->items->first()->tax_rate); // a Saudi rate is not a UAE rate

        $algiers = $this->branch('DZ', ['currency' => 'SAR']);
        $this->assertSame(9.0, (float) $this->invoice($algiers, ['tax_rate' => 9])->items->first()->tax_rate);
        $this->assertSame(19.0, (float) $this->invoice($algiers)->items->first()->tax_rate);
    }

    public function test_a_country_without_vat_issues_plain_invoices(): void
    {
        $doha = $this->branch('QA', ['currency' => 'SAR']);
        $invoice = $this->invoice($doha, ['tax_rate' => 15]);
        $this->assertSame([0.0, 1000.0, 'O'], [(float) $invoice->tax_amount, (float) $invoice->total, $invoice->items->first()->vat_category]);
        $this->assertSame('فاتورة', app(TemplateData::class)->build($invoice->fresh())['fields']['doc.title']);
    }

    public function test_saudi_branches_are_unchanged(): void
    {
        $invoice = $this->invoice($this->riyadh, ['tax_rate' => 5]);
        $this->assertSame([15.0, 150.0], [(float) $invoice->items->first()->tax_rate, (float) $invoice->tax_amount]);
        $this->assertNotNull($invoice->fresh()->zatca_qr); // phase 1 QR
        $this->assertNull($invoice->currency_code); // base currency
    }

    public function test_tax_summary_per_branch_and_the_zatca_return_leaves_foreign_branches_out(): void
    {
        $cairo = $this->branch('EG');
        Currency::where('code', 'EGP')->update(['exchange_rate' => 0.075]);
        $this->invoice($this->riyadh);
        $this->invoice($cairo);

        $reports = app(AccountingReports::class);
        $byBranch = collect($reports->taxByBranch(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')))->keyBy('country');
        $this->assertSame(['SAR', 150.0], [$byBranch['SA']['currency'], $byBranch['SA']['output']]);
        $this->assertSame(['EGP', 140.0], [$byBranch['EG']['currency'], $byBranch['EG']['output']]);

        $boxes = collect($reports->vatReturn(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'))['boxes'])->keyBy('no');
        $this->assertSame(150.0, (float) $boxes[1]['vat']); // the Egyptian invoice is not in the Saudi form
    }

    public function test_a_rate_change_applies_from_its_date(): void
    {
        $cairo = $this->branch('EG', ['currency' => 'SAR']);
        $this->post(route('branches.tax-rates'), ['country' => 'EG', 'standard' => 15, 'rates' => '5', 'from' => '2026-07-01'])->assertSessionHasNoErrors();

        $this->assertSame(14.0, (float) $this->invoice($cairo)->items->first()->tax_rate);
        Carbon::setTestNow('2026-07-02 10:00:00');
        $this->assertSame(15.0, (float) $this->invoice($cairo, [], ['invoice_date' => '2026-07-02'])->items->first()->tax_rate);
    }

    public function test_the_country_of_a_branch_with_invoices_is_locked(): void
    {
        $cairo = $this->branch('EG', ['currency' => 'SAR']);
        $this->invoice($cairo);
        $this->put(route('branches.update', $cairo), ['name_ar' => 'القاهرة', 'code' => 'BR-EG', 'country_code' => 'JO'])->assertSessionHasErrors('country_code');
        $this->put(route('branches.update', $cairo), ['name_ar' => 'القاهرة الجديدة', 'code' => 'BR-EG', 'country_code' => 'EG', 'currency' => 'SAR'])->assertSessionHasNoErrors();
        $this->assertSame('القاهرة الجديدة', $cairo->fresh()->name_ar);
    }

    public function test_registration_in_egypt_sets_currency_tax_and_time_zone(): void
    {
        auth()->logout();
        app(TenantManager::class)->forget();
        $this->post(route('register'), [
            'company_name' => 'شركة القاهرة', 'subdomain' => 'cairo-co', 'country_code' => 'EG', 'currency' => 'EGP', 'name' => 'أحمد', 'email' => 'ahmed@cairo.test',
            'phone' => '01000000000', 'password' => 'Secret-Pass-2026!', 'password_confirmation' => 'Secret-Pass-2026!', 'terms' => true,
        ])->assertSessionHasNoErrors();

        $company = Company::where('subdomain', 'cairo-co')->firstOrFail();
        $this->assertSame(['EG', 'EGP', 'Africa/Cairo'], [$company->country_code, $company->currency, $company->timezone]);
        $this->assertSame(14.0, TaxProfile::forBranch(Branch::withoutGlobalScopes()->where('company_id', $company->id)->first(), $company)->standardRate());
        $this->assertTrue(User::where('email', 'ahmed@cairo.test')->exists());
    }
}
