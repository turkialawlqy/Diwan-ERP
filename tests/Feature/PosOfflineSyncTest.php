<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The terminal's offline queue: a sale made without a connection is sent again and again, and is recorded once. */
class PosOfflineSyncTest extends TestCase
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
        $this->actingAs($this->userWithRole($this->company));

        $drawer = BankAccount::create(['name' => 'درج الكاشير', 'type' => 'cash', 'currency' => 'SAR', 'account_id' => app(AccountMap::class)->id('petty_cash', $this->company->id), 'current_balance' => 0, 'is_active' => true]);
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $register = PosRegister::create(['name' => 'كاشير 1', 'warehouse_id' => $warehouse->id, 'bank_account_id' => $drawer->id, 'is_active' => true]);
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

    protected function sale(array $extra = []): array
    {
        return array_merge([
            'session_id' => $this->session->id, 'payment_method' => 'cash', 'paid_amount' => 115,
            'items' => [['product_id' => $this->product->id, 'item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]],
        ], $extra);
    }

    public function test_a_sale_sent_twice_is_recorded_once(): void
    {
        $uuid = (string) Str::uuid();

        $first = $this->postJson(route('pos.order.sync'), $this->sale(['client_uuid' => $uuid]))->assertOk()->assertJsonPath('duplicate', false);
        $again = $this->postJson(route('pos.order.sync'), $this->sale(['client_uuid' => $uuid]))->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame($first->json('order.order_number'), $again->json('order.order_number'));
        $this->assertSame(1, PosOrder::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame($uuid, PosOrder::first()->client_uuid);
    }

    public function test_the_sale_keeps_the_time_it_was_made_and_dates_the_invoice_by_it(): void
    {
        $this->postJson(route('pos.order.sync'), $this->sale(['client_uuid' => (string) Str::uuid(), 'sold_at' => '2026-07-03 18:30:00']))->assertOk();

        $this->assertSame('2026-07-03 18:30:00', PosOrder::first()->sold_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-03', Invoice::first()->invoice_date->toDateString());

        // Not from the future, and not older than a week.
        $this->postJson(route('pos.order.sync'), $this->sale(['client_uuid' => (string) Str::uuid(), 'sold_at' => '2026-07-06 10:00:00']))->assertJsonValidationErrors('sold_at');
        $this->postJson(route('pos.order.sync'), $this->sale(['client_uuid' => (string) Str::uuid(), 'sold_at' => '2026-06-20 10:00:00']))->assertJsonValidationErrors('sold_at');
        $this->assertSame(1, PosOrder::count());
    }

    public function test_a_sale_of_a_closed_shift_is_refused_with_its_reason_and_records_nothing(): void
    {
        $this->session->update(['status' => 'closed']);

        $this->postJson(route('pos.order.sync'), $this->sale(['client_uuid' => (string) Str::uuid()]))->assertStatus(422)->assertJsonValidationErrors('session_id');
        $this->assertSame(0, PosOrder::count());
    }

    public function test_a_sale_without_an_id_still_works_and_the_screen_post_is_unchanged(): void
    {
        $this->postJson(route('pos.order.sync'), $this->sale())->assertOk();
        $this->post(route('pos.order.process'), $this->sale())->assertSessionHasNoErrors()->assertSessionHas('latest_order');
        $this->assertSame(2, PosOrder::count());
        $this->assertSame(0, PosOrder::whereNotNull('client_uuid')->count());
    }

    public function test_only_a_cashier_may_sync(): void
    {
        $this->actingAs($this->userWithRole($this->company, 'hr_manager'));
        $this->postJson(route('pos.order.sync'), $this->sale(['client_uuid' => (string) Str::uuid()]))->assertForbidden();
    }
}
