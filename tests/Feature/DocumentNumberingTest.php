<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Documents\DocumentNumberService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentNumberingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        app(TenantManager::class)->setTenant($this->company);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function numbers(): DocumentNumberService
    {
        return app(DocumentNumberService::class);
    }

    /** Invoices as an import would leave them: numbered in the app's format, bypassing the sequence. */
    protected function insertInvoices(array $numbers): void
    {
        $customer = Customer::create(['code' => 'X1', 'name' => 'A', 'customer_type' => 'commercial', 'status' => 'active']);

        foreach (array_chunk($numbers, 100) as $chunk) {
            DB::table('invoices')->insert(array_map(fn ($number) => [
                'company_id' => $this->company->id,
                'customer_id' => $customer->id,
                'invoice_number' => is_int($number) ? sprintf('INV-26-%06d', $number) : $number,
                'invoice_date' => '2026-01-01',
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }
    }

    public function test_numbers_are_sequential_per_type_and_year(): void
    {
        $date = Carbon::parse('2026-03-01');

        $this->assertSame('INV-26-000001', $this->numbers()->next('sales_invoice', $date));
        $this->assertSame('INV-26-000002', $this->numbers()->next('sales_invoice', $date));
        $this->assertSame('JV-26-000001', $this->numbers()->next('journal_entry', $date));

        // A new year restarts the sequence.
        $this->assertSame('INV-27-000001', $this->numbers()->next('sales_invoice', Carbon::parse('2027-01-02')));
    }

    public function test_peek_does_not_consume_a_number(): void
    {
        $date = Carbon::parse('2026-03-01');

        $this->assertSame('QUO-26-000001', $this->numbers()->peek('quotation', $date));
        $this->assertSame('QUO-26-000001', $this->numbers()->peek('quotation', $date));
        $this->assertSame('QUO-26-000001', $this->numbers()->next('quotation', $date));
        $this->assertSame('QUO-26-000002', $this->numbers()->peek('quotation', $date));
    }

    public function test_sequences_are_independent_per_company(): void
    {
        $date = Carbon::parse('2026-03-01');
        $other = Company::factory()->create();

        $this->numbers()->next('sales_invoice', $date);
        $this->numbers()->next('sales_invoice', $date);

        $this->assertSame('INV-26-000001', $this->numbers()->next('sales_invoice', $date, $other->id));
    }

    public function test_continues_after_existing_numbers_and_skips_used_ones(): void
    {
        $customer = Customer::create(['code' => 'X1', 'name' => 'A', 'customer_type' => 'commercial', 'status' => 'active']);

        foreach (['INV-26-000007', 'INV-26-000009'] as $number) {
            DB::table('invoices')->insert([
                'company_id' => $this->company->id,
                'customer_id' => $customer->id,
                'invoice_number' => $number,
                'invoice_date' => '2026-01-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $date = Carbon::parse('2026-05-01');
        $this->assertSame('INV-26-000010', $this->numbers()->next('sales_invoice', $date));
    }

    public function test_a_long_run_of_used_numbers_is_skipped_in_a_few_queries(): void
    {
        $date = Carbon::parse('2026-05-01');
        $this->assertSame('INV-26-000001', $this->numbers()->next('sales_invoice', $date));
        $this->insertInvoices(range(2, 1300));

        DB::enableQueryLog();
        $number = $this->numbers()->next('sales_invoice', $date);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('INV-26-001301', $number);
        // One query per used number made saving time out once an import used thousands of them.
        $this->assertLessThan(15, $queries);
        $this->assertSame('INV-26-001302', $this->numbers()->next('sales_invoice', $date));
    }

    public function test_a_gap_between_used_numbers_is_still_filled(): void
    {
        $date = Carbon::parse('2026-05-01');
        $this->assertSame('INV-26-000001', $this->numbers()->next('sales_invoice', $date));
        $this->insertInvoices([2, 3, 5]);

        $this->assertSame('INV-26-000004', $this->numbers()->next('sales_invoice', $date));
        $this->assertSame('INV-26-000006', $this->numbers()->next('sales_invoice', $date));
    }

    public function test_a_new_sequence_starts_after_the_highest_number_across_pages(): void
    {
        $this->insertInvoices([...range(1, 2500), 'INV-26-ABC']);

        $date = Carbon::parse('2026-05-01');
        $this->assertSame('INV-26-002501', $this->numbers()->peek('sales_invoice', $date));
        $this->assertSame('INV-26-002501', $this->numbers()->next('sales_invoice', $date));
    }

    public function test_company_can_override_prefix(): void
    {
        $this->company->setSetting('sales_invoice_prefix', 'RYD-', 'sales');

        $this->assertSame('RYD-26-000001', $this->numbers()->next('sales_invoice', Carbon::parse('2026-01-01')));
    }

    public function test_customer_codes_are_generated(): void
    {
        $a = Customer::create(['name' => 'A', 'customer_type' => 'commercial', 'status' => 'active']);
        $b = Customer::create(['name' => 'B', 'customer_type' => 'commercial', 'status' => 'active']);

        $this->assertSame('C-00001', $a->code);
        $this->assertSame('C-00002', $b->code);
    }
}
