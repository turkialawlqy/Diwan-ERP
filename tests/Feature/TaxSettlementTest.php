<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TaxSettlement;
use App\Services\Accounting\AccountingReports;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\TaxSettlementService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($company);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));
        // Output VAT 150, input VAT 40 booked in the ledger.
        app(PostingService::class)->post(['date' => '2026-03-10', 'description' => 'ضريبة', 'lines' => [
            ['role' => 'default_cash', 'debit' => 150, 'credit' => 0],
            ['role' => 'vat_output', 'debit' => 0, 'credit' => 150],
        ]]);
        app(PostingService::class)->post(['date' => '2026-03-12', 'description' => 'ضريبة مدخلات', 'lines' => [
            ['role' => 'vat_input', 'debit' => 40, 'credit' => 0],
            ['role' => 'default_cash', 'debit' => 0, 'credit' => 40],
        ]]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_settlement_clears_output_and_input_into_the_payable_account(): void
    {
        $settlement = app(TaxSettlementService::class)->settleVat(Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'));

        $this->assertSame('110.00', $settlement->net_amount);
        $lines = $settlement->journalEntry->lines->keyBy('account_id');
        $map = app(AccountMap::class);
        $this->assertEquals(150, $lines[$map->id('vat_output')]->debit);
        $this->assertEquals(40, $lines[$map->id('vat_input')]->credit);
        $this->assertEquals(110, $lines[$map->id('vat_settlement')]->credit);
    }

    public function test_a_settled_period_cannot_be_settled_again_until_cancelled(): void
    {
        $service = app(TaxSettlementService::class);
        $settlement = $service->settleVat(Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'));

        try {
            $service->settleVat(Carbon::parse('2026-03-01'), Carbon::parse('2026-04-30'));
            $this->fail('overlap not blocked');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_date', $e->errors());
        }

        $service->cancel($settlement);
        $this->assertSame(0, TaxSettlement::count());
        $this->assertSame('110.00', $service->settleVat(Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'))->net_amount);
    }

    public function test_the_vat_return_still_compares_with_the_ledger_after_the_settlement(): void
    {
        [$from, $to] = [Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31')];
        $reports = app(AccountingReports::class);
        $before = $reports->vatReturn($from, $to);

        $settlement = app(TaxSettlementService::class)->settleVat($from, $to);
        $after = $reports->vatReturn($from, $to);

        $this->assertSame($before['ledger_output_vat'], $after['ledger_output_vat'], 'The settlement entry is not tax of the period.');
        $this->assertSame($before['ledger_input_vat'], $after['ledger_input_vat']);
        $this->assertSame($before['ledger_matches'], $after['ledger_matches']);

        app(TaxSettlementService::class)->cancel($settlement);
        $this->assertSame($before['ledger_output_vat'], $reports->vatReturn($from, $to)['ledger_output_vat']);
    }

    public function test_the_vat_return_page_offers_the_settle_button(): void
    {
        $this->get(route('accounting.reports.vat-return', ['start_date' => '2026-01-01', 'end_date' => '2026-03-31']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('settlement.net', 110));
        $this->post(route('accounting.reports.vat-return.settle'), ['start_date' => '2026-01-01', 'end_date' => '2026-03-31'])->assertRedirect();
        $this->assertSame(1, TaxSettlement::count());
    }
}
