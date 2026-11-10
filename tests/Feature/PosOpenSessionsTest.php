<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\ActiveBranch;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «الورديات المفتوحة»: a holder of pos.sessions.manage sees the open shifts of every branch
 * they may work in, and closes someone else's through the terminal's own closing, in the
 * shift's branch whichever one their top bar shows.
 */
class PosOpenSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Branch $riyadh;

    protected Branch $jeddah;

    protected Branch $dammam;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-07-05 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->jeddah = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        $this->dammam = Branch::create(['name_ar' => 'الدمام', 'code' => 'DMM', 'is_active' => true]);
        $this->product = Product::create(['name_ar' => 'خدمة', 'sku' => 'P1', 'selling_price' => 1000, 'track_inventory' => false, 'is_active' => true]);
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
        return round((float) Account::withoutGlobalScopes()->whereKey($this->role($role))->value('balance'), 2);
    }

    protected function userIn(string $role, array $branches): User
    {
        $user = $this->userWithRole($this->company, $role, ['branch_id' => $branches[0]->id]);
        $user->branches()->sync(collect($branches)->pluck('id'));

        return $user->fresh();
    }

    protected function manager(array $branches): User
    {
        $manager = $this->userIn('cashier', $branches);
        $manager->givePermissionTo('pos.sessions.manage');

        return $manager->fresh();
    }

    protected function box(Branch $branch, string $name, string $role = 'petty_cash'): BankAccount
    {
        $this->actingAs($this->owner);

        return BankAccount::create(['branch_id' => $branch->id, 'name' => $name, 'type' => 'cash', 'currency' => 'SAR', 'account_id' => $this->role($role), 'current_balance' => 0, 'is_active' => true]);
    }

    /** A cashier's shift in $branch through the real terminal routes: float 100, one cash sale of 1,150 (VAT included). */
    protected function shiftIn(Branch $branch): PosSession
    {
        $drawer = $this->box($branch, 'درج '.$branch->name_ar);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name_ar' => 'مستودع '.$branch->name_ar, 'code' => 'W'.$branch->id, 'is_active' => true]);
        $register = PosRegister::create(['branch_id' => $branch->id, 'name' => 'جهاز '.$branch->name_ar, 'warehouse_id' => $warehouse->id, 'bank_account_id' => $drawer->id, 'is_active' => true]);

        $this->actingAs($this->userIn('cashier', [$branch]))
            ->post(route('pos.session.open'), ['pos_register_id' => $register->id, 'opening_cash' => 100])
            ->assertSessionHasNoErrors();
        $session = PosSession::where('pos_register_id', $register->id)->firstOrFail();
        $this->sell($session)->assertSessionHasNoErrors();

        return $session;
    }

    protected function sell(PosSession $session)
    {
        return $this->actingAs(User::findOrFail($session->user_id))->post(route('pos.order.process'), [
            'session_id' => $session->id, 'payment_method' => 'cash', 'paid_amount' => 1150,
            'items' => [['product_id' => $this->product->id, 'item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);
    }

    protected function closeAs(User $manager, PosSession $session, array $data, int|string|null $activeBranch = null)
    {
        return $this->actingAs($manager)
            ->withSession([ActiveBranch::SESSION_KEY => $activeBranch ?? $manager->branch_id])
            ->post(route('pos.open-sessions.close', $session->id), $data);
    }

    protected function entriesOf(PosSession $session)
    {
        return JournalEntry::withoutGlobalScopes()->where('source_type', $session->getMorphClass())->where('source_id', $session->id)->get();
    }

    public function test_the_list_covers_every_allowed_branch_with_each_shifts_real_expected_cash(): void
    {
        $riyadh = $this->shiftIn($this->riyadh);
        $jeddah = $this->shiftIn($this->jeddah);
        $this->shiftIn($this->dammam);                                 // a branch the manager may not use
        $closed = $this->shiftIn($this->riyadh);
        $this->closeAs($this->owner, $closed, ['closing_cash' => 1250], $this->riyadh->id)->assertSessionHasNoErrors();

        // Jeddah: 100 float + two sales of 1,150 - one cash refund of 1,150.
        $this->sell($jeddah)->assertSessionHasNoErrors();
        $invoice = Invoice::withoutGlobalScopes()->with('items')->where('branch_id', $this->jeddah->id)->oldest('id')->firstOrFail();
        $this->actingAs($this->owner)->withSession([ActiveBranch::SESSION_KEY => $this->jeddah->id])->post(route('sales.returns.store'), [
            'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id, 'credit_note_date' => today()->toDateString(), 'reason' => 'إرجاع', 'refund_method' => 'cash',
            'items' => [['invoice_item_id' => $invoice->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $manager = $this->manager([$this->riyadh, $this->jeddah]);
        $this->assertSame([$this->riyadh->id, $this->jeddah->id], [$riyadh->branch_id, $jeddah->branch_id]);

        $props = $this->actingAs($manager)->withSession([ActiveBranch::SESSION_KEY => $this->riyadh->id])
            ->get(route('pos.open-sessions.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('POS/OpenSessions'))
            ->viewData('page')['props'];

        $rows = collect($props['sessions']['data'])->keyBy('id');
        $this->assertSame([$riyadh->id, $jeddah->id], $rows->keys()->all());
        $this->assertSame(1250.0, $rows[$riyadh->id]['expected_cash']);

        $row = $rows[$jeddah->id];
        $this->assertSame(User::findOrFail($jeddah->user_id)->name, $row['cashier']);
        $this->assertSame(['جهاز جدة', 'جدة', 100.0, 1250.0], [$row['register'], $row['branch'], $row['opening_cash'], $row['expected_cash']]);
        $this->assertNotEmpty($row['opened_at']);

        // The accounts offered: the manager's branches only.
        $this->assertEqualsCanonicalizing(['درج الرياض', 'درج الرياض', 'درج جدة'], collect($props['boxes'])->pluck('name')->all());
    }

    public function test_a_manager_closes_a_colleagues_shift_in_another_allowed_branch_within_that_branch(): void
    {
        $session = $this->shiftIn($this->jeddah);                      // 1,250 expected
        $drawer = BankAccount::withoutGlobalScopes()->findOrFail(PosRegister::withoutGlobalScopes()->findOrFail($session->pos_register_id)->bank_account_id);
        $treasury = $this->box($this->jeddah, 'خزينة جدة', 'default_cash');
        $manager = $this->manager([$this->riyadh, $this->jeddah]);

        // The manager is looking at Riyadh: nothing of Jeddah is in the top bar's scope.
        $this->closeAs($manager, $session, ['closing_cash' => 1200, 'deposit_bank_account_id' => $treasury->id, 'notes' => 'نسي الكاشير الإغلاق'], $this->riyadh->id)
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $closed = $session->fresh();
        $this->assertSame('closed', $closed->status);
        $this->assertSame([1200.0, 1250.0, -50.0], [(float) $closed->closing_cash, (float) $closed->expected_cash, (float) $closed->difference]);
        $this->assertSame('نسي الكاشير الإغلاق', $closed->notes);

        // The shortage: against the register's own drawer, in Jeddah's books.
        $this->assertSame(50.0, $this->balance('cash_over_short'));
        $shortage = $this->entriesOf($session)->sole();
        $this->assertSame($this->jeddah->id, (int) $shortage->branch_id);
        $this->assertSame(50.0, (float) $shortage->lines()->where('account_id', $this->role('petty_cash'))->value('credit'));

        // The deposit: Jeddah's books as well, and both balances moved.
        $transfer = JournalEntry::withoutGlobalScopes()->where('source_type', 'transfer')->sole();
        $this->assertSame([$this->jeddah->id, 1200.0], [(int) $transfer->branch_id, (float) $transfer->total_debit]);
        $this->assertSame([-1200.0, 1200.0], [(float) $drawer->fresh()->current_balance, (float) $treasury->fresh()->current_balance]);

        // The device is free again.
        $this->actingAs(User::findOrFail($session->user_id))
            ->post(route('pos.session.open'), ['pos_register_id' => $session->pos_register_id, 'opening_cash' => 0])
            ->assertSessionHasNoErrors();
    }

    public function test_it_also_works_while_the_top_bar_shows_all_branches(): void
    {
        $session = $this->shiftIn($this->jeddah);
        $treasury = $this->box($this->jeddah, 'خزينة جدة', 'default_cash');

        $this->closeAs($this->manager([$this->riyadh, $this->jeddah]), $session, ['closing_cash' => 1250, 'deposit_bank_account_id' => $treasury->id], ActiveBranch::ALL)
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame(1250.0, (float) $treasury->fresh()->current_balance);
    }

    public function test_without_the_permission_the_screen_and_the_closing_are_refused(): void
    {
        $session = $this->shiftIn($this->jeddah);
        $cashier = $this->userIn('cashier', [$this->jeddah]);          // may close only their own, from the terminal

        $this->actingAs($cashier)->get(route('pos.open-sessions.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('pos.open-sessions.close', $session->id), ['closing_cash' => 0])->assertForbidden();

        $this->assertSame('opened', $session->fresh()->status);
        $this->assertCount(0, $this->entriesOf($session));
    }

    public function test_a_shift_in_a_branch_the_manager_may_not_use_is_out_of_reach(): void
    {
        $session = $this->shiftIn($this->dammam);
        $manager = $this->manager([$this->riyadh, $this->jeddah]);

        $this->closeAs($manager, $session, ['closing_cash' => 0], ActiveBranch::ALL)->assertNotFound();

        $this->assertSame('opened', $session->fresh()->status);
        $this->assertCount(0, $this->entriesOf($session));
    }

    public function test_a_shift_of_another_company_is_out_of_reach_even_for_an_all_branches_owner(): void
    {
        $other = Company::factory()->create();
        $register = DB::table('pos_registers')->insertGetId(['company_id' => $other->id, 'name' => 'جهاز', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $session = DB::table('pos_sessions')->insertGetId(['company_id' => $other->id, 'user_id' => $this->owner->id, 'pos_register_id' => $register, 'opening_cash' => 0, 'status' => 'opened', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->post(route('pos.open-sessions.close', $session), ['closing_cash' => 0])->assertNotFound();

        $this->assertSame('opened', DB::table('pos_sessions')->where('id', $session)->value('status'));
    }

    public function test_the_counted_cash_cannot_go_to_another_branchs_account(): void
    {
        $session = $this->shiftIn($this->jeddah);
        $elsewhere = $this->box($this->riyadh, 'خزينة الرياض', 'default_cash');

        $this->closeAs($this->manager([$this->riyadh, $this->jeddah]), $session, ['closing_cash' => 1200, 'deposit_bank_account_id' => $elsewhere->id])
            ->assertSessionHasErrors('deposit_bank_account_id');

        // All or nothing: the shortage was not left behind either.
        $this->assertSame('opened', $session->fresh()->status);
        $this->assertCount(0, $this->entriesOf($session));
    }

    public function test_two_simultaneous_closes_post_the_shortage_once(): void
    {
        $session = $this->shiftIn($this->jeddah);
        $manager = $this->manager([$this->jeddah]);

        // The rival request commits after this one has read the shift and before it takes the lock.
        $raced = false;
        PosSession::retrieved(function (PosSession $read) use (&$raced) {
            if (! $raced) {
                $raced = true;
                app(PosController::class)->closeManagedSession(request(), $read->id);
            }
        });

        $locked = false;
        DB::listen(function ($query) use (&$locked) {
            $locked = $locked || (str_contains($query->sql, 'from `pos_sessions`') && str_ends_with($query->sql, 'for update'));
        });

        $this->closeAs($manager, $session, ['closing_cash' => 1200])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('error');

        $this->assertTrue($raced);
        $this->assertTrue($locked || DB::getDriverName() === 'sqlite', 'The re-check must run under a row lock (SQLite has none).');
        $this->assertCount(1, $this->entriesOf($session));
        $this->assertSame(50.0, $this->balance('cash_over_short'));
        $this->assertSame(1200.0, (float) $session->fresh()->closing_cash);
    }

    public function test_a_sale_that_loses_the_race_to_the_close_is_refused(): void
    {
        $session = $this->shiftIn($this->jeddah);
        $orders = PosOrder::withoutGlobalScopes()->count();

        // The manager's close lands after the sale has read the shift as open.
        $raced = false;
        PosSession::retrieved(function (PosSession $read) use (&$raced) {
            if (! $raced) {
                $raced = true;
                DB::table('pos_sessions')->where('id', $read->id)->update(['status' => 'closed']);
            }
        });

        $this->sell($session)->assertSessionHasErrors('session_id');

        $this->assertTrue($raced);
        $this->assertSame($orders, PosOrder::withoutGlobalScopes()->count());
    }
}
