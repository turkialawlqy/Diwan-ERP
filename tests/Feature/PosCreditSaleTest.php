<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
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
use Tests\TestCase;

/** «بيع آجل» at the POS: a named customer, an optional cash down payment, the rest on his account. */
class PosCreditSaleTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected PosSession $session;

    protected Product $product;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-07-05 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));

        $drawer = BankAccount::create(['name' => 'درج الكاشير', 'type' => 'cash', 'currency' => 'SAR', 'account_id' => $this->role('petty_cash'), 'current_balance' => 0, 'is_active' => true]);
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $register = PosRegister::create(['name' => 'كاشير 1', 'warehouse_id' => $warehouse->id, 'bank_account_id' => $drawer->id, 'is_active' => true]);
        $this->product = Product::create(['name_ar' => 'خدمة', 'sku' => 'P1', 'selling_price' => 1000, 'track_inventory' => false, 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'عميل آجل', 'customer_type' => 'commercial', 'status' => 'active']);

        $this->post(route('pos.session.open'), ['pos_register_id' => $register->id, 'opening_cash' => 0])->assertSessionHasNoErrors();
        $this->session = PosSession::latest('id')->firstOrFail();
        $this->company->setSetting('pos_allow_credit', '1', 'pos', 'boolean');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function role(string $role): int
    {
        return app(AccountMap::class)->id($role, $this->company->id);
    }

    protected function balance(string $role): float
    {
        return round((float) Account::find($this->role($role))->balance, 2);
    }

    protected function sell(array $extra = [])
    {
        return $this->post(route('pos.order.process'), array_merge([
            'session_id' => $this->session->id, 'customer_id' => $this->customer->id, 'payment_method' => 'credit', 'paid_amount' => 0,
            'items' => [['product_id' => $this->product->id, 'item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000]],
        ], $extra));
    }

    public function test_a_credit_sale_with_a_down_payment_leaves_the_rest_on_the_customer(): void
    {
        $this->sell(['paid_amount' => 300])->assertSessionHasNoErrors();

        $order = PosOrder::firstOrFail();
        $invoice = Invoice::firstOrFail();
        $this->assertSame('credit', $order->payment_method);
        $this->assertSame([1150.0, 300.0, 850.0], [(float) $invoice->total, (float) $invoice->paid_amount, (float) $invoice->remaining_amount]);
        $this->assertSame('partially_paid', $invoice->payment_status);
        $this->assertSame($this->customer->id, $invoice->customer_id);
        $this->assertEquals(850, $this->customer->fresh()->current_balance);
        $this->assertSame(850.0, $this->balance('receivables'));
        $this->assertSame(300.0, $this->balance('petty_cash'));

        // The drawer holds the 300 down payment when the shift closes.
        $this->post(route('pos.session.close', $this->session), ['closing_cash' => 300])->assertSessionHasNoErrors();
        $this->assertSame(300.0, (float) $this->session->fresh()->expected_cash);
    }

    public function test_a_credit_sale_with_nothing_paid_is_all_on_account(): void
    {
        $this->sell()->assertSessionHasNoErrors();

        $this->assertSame('unpaid', Invoice::firstOrFail()->payment_status);
        $this->assertSame(1150.0, $this->balance('receivables'));
        $this->assertSame(0.0, $this->balance('petty_cash'));
    }

    public function test_credit_is_refused_when_the_company_has_not_switched_it_on(): void
    {
        $this->company->setSetting('pos_allow_credit', '0', 'pos', 'boolean');

        $this->sell()->assertSessionHasErrors('paid_amount');
        $this->assertSame(0, PosOrder::count());
    }

    public function test_credit_is_refused_for_the_cash_customer(): void
    {
        $this->sell(['customer_id' => null])->assertSessionHasErrors('customer_id');
        $this->sell(['customer_id' => Customer::walkIn()->id])->assertSessionHasErrors('customer_id');
        $this->assertSame(0, PosOrder::count());
    }

    public function test_the_credit_limit_stops_the_sale(): void
    {
        $this->customer->forceFill(['credit_limit' => 1000])->save();

        $this->sell()->assertSessionHasErrors('customer_id');
        $this->assertSame(0, PosOrder::count());

        // A down payment that keeps the account inside the limit is fine.
        $this->sell(['paid_amount' => 200])->assertSessionHasNoErrors();
        $this->assertSame(1, PosOrder::count());
    }

    public function test_paying_everything_up_front_makes_it_a_plain_cash_sale(): void
    {
        $this->sell(['paid_amount' => 1150, 'customer_id' => null])->assertSessionHasNoErrors();

        $this->assertSame('cash', PosOrder::firstOrFail()->payment_method);
        $this->assertSame('paid', Invoice::firstOrFail()->payment_status);
        $this->assertSame(0.0, $this->balance('receivables'));
    }

    public function test_the_terminal_knows_whether_credit_is_allowed(): void
    {
        $this->get(route('pos.index'))->assertOk()->assertInertia(fn ($p) => $p->where('allowCredit', true));

        $this->company->setSetting('pos_allow_credit', '0', 'pos', 'boolean');
        $this->get(route('pos.index'))->assertInertia(fn ($p) => $p->where('allowCredit', false));
    }
}
