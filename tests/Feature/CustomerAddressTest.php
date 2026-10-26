<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * National address of a VAT-registered customer: the building number is
 * its own field (ZATCA), also when typed in front of the street.
 */
class CustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function customer(array $address): array
    {
        return array_merge(['name' => 'شركة صفوة التكامل', 'customer_type' => 'commercial', 'tax_number' => '311547810000003',
            'commercial_registration' => '1111111111', 'district' => 'حي الجامعة', 'city' => 'جدة', 'postal_code' => '22254', 'country' => 'المملكة العربية السعودية'], $address);
    }

    protected function invoice(Customer $customer)
    {
        return $this->post(route('sales.invoices.store'), [
            'customer_id' => $customer->id, 'invoice_date' => '2026-06-15', 'payment_method' => 'credit', 'payment_status' => 'unpaid',
            'items' => [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);
    }

    public function test_building_number_typed_before_the_street_is_split_and_the_invoice_issues(): void
    {
        $this->post(route('sales.clients.store'), $this->customer(['building_number' => '', 'street_name' => '4002, الشارع العام', 'additional_details' => '7007']))
            ->assertSessionHasNoErrors();
        $customer = Customer::where('name', 'شركة صفوة التكامل')->firstOrFail();
        $this->assertSame(['4002', 'الشارع العام'], [$customer->building_number, $customer->street_name]);

        $this->invoice($customer)->assertSessionHasNoErrors();
        $this->assertSame(1, Invoice::count());
    }

    public function test_national_address_numbers_are_validated_and_arabic_digits_read(): void
    {
        $this->post(route('sales.clients.store'), $this->customer(['building_number' => '402', 'street_name' => 'الشارع العام']))->assertSessionHasErrors('building_number');
        $this->post(route('sales.clients.store'), $this->customer(['building_number' => '4002', 'street_name' => 'الشارع العام', 'postal_code' => '2225']))->assertSessionHasErrors('postal_code');
        $this->post(route('sales.clients.store'), $this->customer(['building_number' => '٤٠٠٢', 'street_name' => 'الشارع العام']))->assertSessionHasNoErrors();
        $this->assertSame('4002', Customer::latest('id')->value('building_number'));
    }

    public function test_saudi_vat_number_format_is_checked_and_foreign_numbers_are_free(): void
    {
        $this->post(route('sales.clients.store'), $this->customer(['tax_number' => '31154781000000']))->assertSessionHasErrors('tax_number');
        $this->post(route('sales.clients.store'), $this->customer(['tax_number' => '211547810000003']))->assertSessionHasErrors('tax_number');
        $this->post(route('sales.clients.store'), $this->customer(['tax_number' => '٣١١ ٥٤٧ ٨١٠ ٠٠٠٠٠٣']))->assertSessionHasNoErrors();
        $this->assertSame('311547810000003', Customer::latest('id')->value('tax_number'));
        // Each country checks its own format; a country outside the catalogue keeps its own.
        $this->post(route('sales.clients.store'), $this->customer(['name' => 'Gulf Co', 'tax_number' => '311547810000003', 'country' => 'AE']))->assertSessionHasErrors('tax_number');
        $this->post(route('sales.clients.store'), $this->customer(['name' => 'Gulf Co', 'tax_number' => '100200300400003', 'country' => 'AE']))->assertSessionHasNoErrors();
        $this->post(route('sales.clients.store'), $this->customer(['name' => 'Berlin GmbH', 'tax_number' => 'DE123456789', 'country' => 'Germany', 'building_number' => '12', 'postal_code' => '10115']))->assertSessionHasNoErrors();
    }

    public function test_a_missing_building_number_names_the_customer_and_where_to_fix_it(): void
    {
        $customer = Customer::create($this->customer(['street_name' => 'الشارع العام', 'status' => 'active']));
        $this->invoice($customer)->assertSessionHasErrors('customer_id');
        $message = session('errors')->first('customer_id');
        $this->assertStringContainsString('شركة صفوة التكامل', $message);
        $this->assertStringContainsString('رقم المبنى', $message);
    }

    public function test_migration_splits_existing_customers(): void
    {
        $customer = Customer::create($this->customer(['street_name' => '4002, الشارع العام', 'status' => 'active']));
        $plain = Customer::create($this->customer(['name' => 'آخر', 'street_name' => 'طريق الملك فهد', 'status' => 'active']));

        (require database_path('migrations/2026_10_24_000001_split_customer_building_numbers.php'))->up();

        $this->assertSame(['4002', 'الشارع العام'], [$customer->fresh()->building_number, $customer->fresh()->street_name]);
        $this->assertNull($plain->fresh()->building_number);
        $this->assertSame(1, DB::table('customers')->where('building_number', '4002')->count());
    }
}
