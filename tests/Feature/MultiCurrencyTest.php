<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Sales\CustomerStatement;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use App\Services\Zatca\DocumentMapper;
use App\Services\Zatca\UblInvoiceBuilder;
use App\Support\Hijri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create(['currency' => 'SAR']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        Currency::create(['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol_ar' => 'ر.س', 'symbol_en' => 'SAR', 'exchange_rate' => 1, 'is_default' => true]);
        Currency::create(['code' => 'USD', 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar', 'symbol_ar' => '$', 'symbol_en' => '$', 'exchange_rate' => 3.75]);
        $this->customer = Customer::create(['name' => 'عميل خارجي', 'customer_type' => 'commercial', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function balance(string $role): float
    {
        return (float) Account::find(app(AccountMap::class)->id($role, $this->company->id))->balance;
    }

    protected function assertLedgerBalanced(): void
    {
        $totals = DB::table('account_balances')->where('company_id', $this->company->id)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertEqualsWithDelta((float) $totals->d, (float) $totals->c, 0.001, 'Ledger is not balanced');
    }

    protected function usdInvoice(float $price = 1000, ?float $rate = null): Invoice
    {
        return app(InvoiceService::class)->create(
            ['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'payment_status' => 'unpaid', 'payment_method' => 'credit', 'currency_code' => 'USD', 'exchange_rate' => $rate],
            [['item_name' => 'خدمة تصدير', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 15]],
            ['issue_stock' => false],
        );
    }

    public function test_foreign_invoice_posts_in_base_currency(): void
    {
        $invoice = $this->usdInvoice();

        $this->assertSame('USD', $invoice->currency_code);
        $this->assertEquals(3.75, (float) $invoice->exchange_rate);
        $this->assertEquals(1150.0, (float) $invoice->total, 'document amounts stay in USD');
        $this->assertEqualsWithDelta(4312.50, $this->balance('receivables'), 0.001);
        // Credit-nature accounts carry positive balances.
        $this->assertEqualsWithDelta(3750.00, $this->balance('sales_revenue'), 0.001);
        $this->assertEqualsWithDelta(562.50, $this->balance('vat_output'), 0.001);
        $this->assertEqualsWithDelta(4312.50, (float) $this->customer->fresh()->current_balance, 0.001);
        $this->assertLedgerBalanced();
    }

    public function test_dated_rate_is_used_unless_overridden(): void
    {
        ExchangeRate::create(['currency_code' => 'USD', 'date' => '2026-06-01', 'rate' => 3.70]);
        ExchangeRate::create(['currency_code' => 'USD', 'date' => '2026-06-20', 'rate' => 3.80]);

        $this->assertEquals(3.70, (float) $this->usdInvoice()->exchange_rate);
        $this->assertEquals(3.7512, (float) $this->usdInvoice(1000, 3.7512)->exchange_rate);
    }

    public function test_payment_at_another_rate_books_exchange_difference(): void
    {
        $invoice = $this->usdInvoice();

        $this->post(route('sales.payments.store'), [
            'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'amount' => 1150, 'payment_date' => '2026-06-20',
            'payment_method' => 'bank_transfer', 'exchange_rate' => 3.76,
        ])->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->payment_status);
        // 1150 x (3.76 - 3.75) = 11.50 gain; the receivable is fully cleared.
        $this->assertEqualsWithDelta(0.0, $this->balance('receivables'), 0.001);
        $this->assertEqualsWithDelta(-11.50, $this->balance('fx_difference'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->customer->fresh()->current_balance, 0.001);
        $this->assertLedgerBalanced();

        $statement = app(CustomerStatement::class)->build($this->customer->fresh());
        $this->assertEqualsWithDelta((float) $this->customer->fresh()->current_balance, $statement['closing'], 0.001, 'statement agrees with the balance');
    }

    public function test_payment_currency_must_match_the_invoice(): void
    {
        $invoice = $this->usdInvoice();

        $this->post(route('sales.payments.store'), [
            'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'payment_date' => '2026-06-20',
            'payment_method' => 'cash', 'currency_code' => 'SAR',
        ])->assertSessionHasErrors('currency_code');
    }

    public function test_return_follows_the_invoice_rate(): void
    {
        $invoice = $this->usdInvoice();
        $this->post(route('sales.returns.store'), [
            'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'credit_note_date' => '2026-06-15',
            'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'reason' => 'خصم', 'status' => 'approved',
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(4312.50 - 431.25, $this->balance('receivables'), 0.001);
        $this->assertLedgerBalanced();
    }

    public function test_zatca_xml_reports_vat_in_sar(): void
    {
        $invoice = $this->usdInvoice();
        $this->company->update(['tax_number' => '310122393500003']);

        $data = app(DocumentMapper::class)->map($invoice->fresh(['items', 'customer']), $this->company->fresh());
        $xml = (new UblInvoiceBuilder)->build($data, 1, base64_encode(hash('sha256', '0', true)))->saveXML();

        $this->assertStringContainsString('<cbc:DocumentCurrencyCode>USD</cbc:DocumentCurrencyCode>', $xml);
        $this->assertStringContainsString('<cbc:TaxCurrencyCode>SAR</cbc:TaxCurrencyCode>', $xml);
        $this->assertStringContainsString('<cbc:TaxAmount currencyID="SAR">562.50</cbc:TaxAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxAmount currencyID="USD">150.00</cbc:TaxAmount>', $xml);
    }

    public function test_foreign_purchase_values_stock_in_base_and_books_loss_on_payment(): void
    {
        $supplier = Supplier::create(['name' => 'مورد صيني', 'status' => 'active']);
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $product = Product::create(['name_ar' => 'جهاز', 'sku' => 'IMP-1', 'selling_price' => 0, 'purchase_price' => 0, 'track_inventory' => true, 'is_active' => true]);

        $this->post(route('purchases.invoices.store'), [
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-06-15', 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'currency_code' => 'USD', 'exchange_rate' => 3.75,
            'items' => [['product_id' => $product->id, 'item_name' => 'جهاز', 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 15]],
        ])->assertSessionHasNoErrors();

        $bill = PurchaseInvoice::firstOrFail();
        $this->assertEqualsWithDelta(375.0, (float) $product->fresh()->average_cost, 0.001, 'stock cost in SAR');
        $this->assertEqualsWithDelta(3750.0, $this->balance('inventory'), 0.001);
        $this->assertEqualsWithDelta(4312.50, $this->balance('payables'), 0.001);

        $this->post(route('purchases.payments.store'), [
            'supplier_id' => $supplier->id, 'purchase_invoice_id' => $bill->id, 'amount' => 1150, 'payment_date' => '2026-06-30',
            'payment_method' => 'bank_transfer', 'exchange_rate' => 3.80,
        ])->assertSessionHasNoErrors();

        // Paid 4370 SAR for a 4312.50 SAR payable: 57.50 exchange loss.
        $this->assertEqualsWithDelta(0.0, $this->balance('payables'), 0.001);
        $this->assertEqualsWithDelta(57.50, $this->balance('fx_difference'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $supplier->fresh()->current_balance, 0.001);
        $this->assertLedgerBalanced();
    }

    public function test_currency_settings_page_and_rates(): void
    {
        $this->get(route('settings.currencies'))->assertOk();
        $this->post(route('settings.currencies.rates'), ['currency_code' => 'USD', 'date' => '2026-06-15', 'rate' => 3.7525])->assertSessionHasNoErrors();
        $this->assertEquals(3.7525, (float) Currency::where('code', 'USD')->value('exchange_rate'));
        $this->post(route('settings.currencies.rates'), ['currency_code' => 'SAR', 'date' => '2026-06-15', 'rate' => 2])->assertSessionHasErrors('currency_code');
        $this->post(route('settings.currencies.store'), ['code' => 'eur', 'name_ar' => 'يورو', 'exchange_rate' => 4.1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('currencies', ['company_id' => $this->company->id, 'code' => 'EUR']);
    }

    public function test_hijri_dates(): void
    {
        $this->assertSame('1 رمضان 1445 هـ', Hijri::format('2024-03-11'));
        $this->assertSame('29 ربيع الآخر 1448 هـ', Hijri::format('2026-10-10'));
        $this->assertSame('1448/04/29', Hijri::numeric('2026-10-10'));
        $this->assertNull(Hijri::format(null));
    }
}
