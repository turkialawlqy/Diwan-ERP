<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** 1.8.0: a double click must not double the effect, and a cashier does not set prices. */
class MoneySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected PosSession $session;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-07-05 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company, 'sales'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        auth()->user()->givePermissionTo(['pos.terminal.use', 'pos.sessions.create', 'pos.sessions.edit']);

        $drawer = BankAccount::create(['name' => 'درج', 'type' => 'cash', 'currency' => 'SAR', 'account_id' => app(AccountMap::class)->id('petty_cash', $this->company->id), 'current_balance' => 0, 'is_active' => true]);
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $register = PosRegister::create(['name' => 'كاشير', 'warehouse_id' => $warehouse->id, 'bank_account_id' => $drawer->id, 'is_active' => true]);
        $this->product = Product::create(['name_ar' => 'خدمة', 'sku' => 'P1', 'selling_price' => 100, 'track_inventory' => false, 'is_active' => true]);
        $this->post(route('pos.session.open'), ['pos_register_id' => $register->id, 'opening_cash' => 0])->assertSessionHasNoErrors();
        $this->session = PosSession::latest('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function sell(float $price, float $discount = 0)
    {
        return $this->post(route('pos.order.process'), ['session_id' => $this->session->id, 'payment_method' => 'cash', 'paid_amount' => 1000,
            'items' => [['product_id' => $this->product->id, 'item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 15, 'discount' => $discount]]]);
    }

    public function test_a_cashier_cannot_sell_far_below_the_catalogue_price_but_a_manager_can(): void
    {
        $this->sell(0.01)->assertSessionHasErrors('items');           // a one-halala invoice
        $this->sell(100, 60)->assertSessionHasErrors('items');        // 60 % off
        $this->sell(95, 5)->assertSessionHasNoErrors();               // within the cap (≈ 14 %)
        $this->assertSame(1, PosOrder::count());

        $this->company->setSetting('pos_max_discount_percent', '50', 'pos', 'string');
        $this->sell(100, 40)->assertSessionHasNoErrors();             // the company raised the cap

        auth()->user()->givePermissionTo('pos.discounts.manage');
        $this->sell(0.01)->assertSessionHasNoErrors();                // a user who may override
    }

    public function test_a_draft_entry_cannot_be_posted_twice(): void
    {
        $posting = app(PostingService::class);
        $cash = app(AccountMap::class)->id('petty_cash', $this->company->id);
        $revenue = app(AccountMap::class)->id('other_revenue', $this->company->id);
        $draft = $posting->post(['date' => '2026-07-05', 'description' => 'مسودة', 'status' => JournalEntry::STATUS_DRAFT, 'entry_type' => 'manual',
            'lines' => [['account_id' => $cash, 'debit' => 100], ['account_id' => $revenue, 'credit' => 100]]]);
        $stale = JournalEntry::findOrFail($draft->id);               // a second tab still shows it as a draft

        $posting->postDraft($draft);
        $balance = (float) Account::find($cash)->balance;
        $this->expectException(AccountingException::class);
        try {
            $posting->postDraft($stale);
        } finally {
            $this->assertEquals($balance, (float) Account::find($cash)->balance); // applied once
        }
    }

    public function test_a_shift_cannot_be_closed_twice_nor_a_second_opened_on_the_same_register(): void
    {
        $this->post(route('pos.session.open'), ['pos_register_id' => $this->session->pos_register_id, 'opening_cash' => 0])->assertSessionHasErrors('pos_register_id');
        $this->post(route('pos.session.close', $this->session->id), ['closing_cash' => 50])->assertSessionHasNoErrors();
        $entries = JournalEntry::count();
        $this->post(route('pos.session.close', $this->session->id), ['closing_cash' => 50])->assertForbidden();
        $this->assertSame($entries, JournalEntry::count());           // the shortage was not posted again
    }
}
