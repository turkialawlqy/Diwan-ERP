<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Documents\DocumentNumberService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** «دليل الترميز»: prefixes are set by the company and locked once a document used them. */
class NumberingGuideTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
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

    protected function rule(string $prefix, array $extra = []): array
    {
        return array_merge(['prefix' => $prefix, 'pad' => 6, 'start_number' => 1, 'with_year' => true], $extra);
    }

    public function test_the_guide_lists_every_numbered_type_with_its_state(): void
    {
        $this->get(route('settings.numbering'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('Settings/Numbering')
            ->where('groups', function ($groups) {
                $types = collect($groups)->flatMap(fn ($g) => collect($g['types']));
                $invoice = $types->firstWhere('type', 'sales_invoice');

                return $types->count() === count(config('documents.types'))
                    && $invoice['rules'][0]['prefix'] === 'INV-' && $invoice['rules'][0]['used'] === false
                    && $types->firstWhere('type', 'supplier') !== null;
            }));
    }

    public function test_an_unused_prefix_can_be_changed_and_numbers_follow(): void
    {
        $date = Carbon::parse('2026-03-01');
        $this->assertSame('INV-26-000001', $this->numbers()->peek('sales_invoice', $date));

        $this->put(route('settings.numbering.update', ['type' => 'sales_invoice', 'year' => 0]), $this->rule('RYD-', ['pad' => 5, 'start_number' => 500]))->assertSessionHasNoErrors();

        $this->assertSame('RYD-26-00500', $this->numbers()->next('sales_invoice', $date));
        $this->assertSame('RYD-26-00501', $this->numbers()->next('sales_invoice', $date));
    }

    public function test_a_used_prefix_is_locked_even_for_a_direct_request(): void
    {
        $this->numbers()->next('sales_invoice', Carbon::parse('2026-03-01'));

        $this->get(route('settings.numbering'))->assertInertia(fn ($p) => $p->where('groups', fn ($groups) => collect($groups)
            ->flatMap(fn ($g) => collect($g['types']))->firstWhere('type', 'sales_invoice')['rules'][0]['used'] === true));

        $this->put(route('settings.numbering.update', ['type' => 'sales_invoice', 'year' => 0]), $this->rule('NEW-'))->assertSessionHasErrors('prefix');
        $this->assertSame('INV-26-000002', $this->numbers()->peek('sales_invoice', Carbon::parse('2026-03-01')));
    }

    public function test_a_new_prefix_can_start_from_a_later_year_without_touching_the_old_one(): void
    {
        $this->numbers()->next('sales_invoice', now());
        $next = now()->year + 1;

        $this->post(route('settings.numbering.add', ['type' => 'sales_invoice']), $this->rule('FAC-', ['year' => $next]))->assertSessionHasNoErrors();

        $this->assertSame('INV-'.now()->format('y').'-000002', $this->numbers()->next('sales_invoice', now()));
        $this->assertSame('FAC-'.substr((string) $next, 2).'-000001', $this->numbers()->next('sales_invoice', Carbon::create($next, 2, 1)));

        // The new rule is still unused, so it can be edited or deleted; a year already used can't be added.
        $this->put(route('settings.numbering.update', ['type' => 'sales_invoice', 'year' => $next]), $this->rule('FAC-', ['pad' => 4]))->assertSessionHasErrors('prefix');
        $this->post(route('settings.numbering.add', ['type' => 'sales_invoice']), $this->rule('X-', ['year' => now()->year]))->assertSessionHasErrors('year');
    }

    public function test_an_unused_future_rule_can_be_edited_and_deleted(): void
    {
        $next = now()->year + 1;
        $this->post(route('settings.numbering.add', ['type' => 'quotation']), $this->rule('OFR-', ['year' => $next]))->assertSessionHasNoErrors();
        $this->put(route('settings.numbering.update', ['type' => 'quotation', 'year' => $next]), $this->rule('OFR2-'))->assertSessionHasNoErrors();
        $this->assertSame('OFR2-'.substr((string) $next, 2).'-000001', $this->numbers()->peek('quotation', Carbon::create($next, 1, 5)));

        $this->delete(route('settings.numbering.destroy', ['type' => 'quotation', 'year' => $next]))->assertSessionHasNoErrors();
        $this->assertSame('QUO-'.substr((string) $next, 2).'-000001', $this->numbers()->peek('quotation', Carbon::create($next, 1, 5)));
        $this->delete(route('settings.numbering.destroy', ['type' => 'quotation', 'year' => 0]))->assertSessionHasErrors('rule');
    }

    public function test_types_numbering_the_same_column_cannot_share_a_prefix(): void
    {
        $this->put(route('settings.numbering.update', ['type' => 'pos_invoice', 'year' => 0]), $this->rule('INV-'))->assertSessionHasErrors('prefix');
        $this->put(route('settings.numbering.update', ['type' => 'pos_invoice', 'year' => 0]), $this->rule('INV'))->assertSessionHasErrors('prefix');
        $this->put(route('settings.numbering.update', ['type' => 'pos_invoice', 'year' => 0]), $this->rule('بادئة'))->assertSessionHasErrors('prefix');
    }

    public function test_records_without_a_year_are_locked_for_good_and_take_no_new_year_rule(): void
    {
        Customer::create(['name' => 'عميل', 'customer_type' => 'individual', 'status' => 'active']);
        $this->assertSame('C-00002', $this->numbers()->peek('customer'));

        $this->put(route('settings.numbering.update', ['type' => 'customer', 'year' => 0]), $this->rule('CU-', ['pad' => 5]))->assertSessionHasErrors('prefix');
        $this->post(route('settings.numbering.add', ['type' => 'customer']), $this->rule('CU-', ['year' => now()->year + 1]))->assertSessionHasErrors('year');
    }

    public function test_suppliers_get_a_numbered_code_per_company(): void
    {
        $a = Supplier::create(['name' => 'مورد 1', 'status' => 'active']);
        $b = Supplier::create(['name' => 'مورد 2', 'status' => 'active']);
        $this->assertSame(['S-00001', 'S-00002'], [$a->code, $b->code]);

        $other = Company::factory()->create();
        app(TenantManager::class)->setTenant($other);
        $this->assertSame('S-00001', Supplier::create(['name' => 'مورد آخر', 'status' => 'active'])->code);
    }

    public function test_the_guide_needs_its_own_permission(): void
    {
        $this->actingAs($this->userWithRole($this->company, 'accountant'));

        $this->get(route('settings.numbering'))->assertForbidden();
        $this->put(route('settings.numbering.update', ['type' => 'sales_invoice', 'year' => 0]), $this->rule('X-'))->assertForbidden();
    }
}
