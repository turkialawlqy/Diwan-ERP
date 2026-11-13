<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\ActiveBranch;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\ObservesRowLocks;
use Tests\TestCase;

/** A register has one open shift at a time, and so does a cashier, whichever branch it is in. */
class PosOneShiftAtATimeTest extends TestCase
{
    use ObservesRowLocks, RefreshDatabase;

    protected Company $company;

    protected Branch $riyadh;

    protected Branch $jeddah;

    protected User $cashier;

    protected PosRegister $first;

    protected PosRegister $second;

    protected PosRegister $inJeddah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->jeddah = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        $this->cashier = $this->cashierOf([$this->riyadh, $this->jeddah]);
        $this->actingAs($this->cashier);
        $warehouse = Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);
        $this->first = PosRegister::create(['name' => 'كاشير 1', 'branch_id' => $this->riyadh->id, 'warehouse_id' => $warehouse->id, 'is_active' => true]);
        $this->second = PosRegister::create(['name' => 'كاشير 2', 'branch_id' => $this->riyadh->id, 'warehouse_id' => $warehouse->id, 'is_active' => true]);
        $this->inJeddah = PosRegister::create(['name' => 'كاشير جدة', 'branch_id' => $this->jeddah->id, 'warehouse_id' => $warehouse->id, 'is_active' => true]);
        $this->inBranch($this->riyadh);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /** @param  array<int, Branch>  $branches */
    protected function cashierOf(array $branches): User
    {
        $cashier = $this->userWithRole($this->company, 'sales');
        $cashier->forceFill(['branch_id' => $branches[0]->id])->save();
        $cashier->branches()->sync(collect($branches)->pluck('id'));
        $cashier->givePermissionTo(['pos.terminal.use', 'pos.sessions.create', 'pos.sessions.edit']);

        return $cashier->fresh();
    }

    protected function inBranch(Branch $branch): void
    {
        $this->withSession([ActiveBranch::SESSION_KEY => $branch->id]);
    }

    protected function open(PosRegister $register)
    {
        return $this->post(route('pos.session.open'), ['pos_register_id' => $register->id, 'opening_cash' => 0]);
    }

    protected function close(PosSession $shift)
    {
        return $this->post(route('pos.session.close', $shift->id), ['closing_cash' => 0]);
    }

    /** A shift as it stands in the database, without going through the checks of openSession. */
    protected function shiftAlreadyOpen(PosRegister $register, string $openedAt, ?User $cashier = null): PosSession
    {
        $shift = new PosSession;
        $shift->forceFill([
            'user_id' => ($cashier ?? $this->cashier)->id,
            'pos_register_id' => $register->id,
            'opening_cash' => 0,
            'status' => 'opened',
            'opened_at' => $openedAt,
            'created_at' => $openedAt,
        ])->save();

        return $shift;
    }

    protected function openShifts()
    {
        return PosSession::withoutGlobalScope('branch')->where('status', 'opened');
    }

    protected function assertTerminalShows(PosSession $shift): void
    {
        $this->get(route('pos.index'))->assertInertia(fn (AssertableInertia $page) => $page->component('POS/Terminal')->where('session.id', $shift->id));
    }

    protected function assertAsksToOpenAShift(): void
    {
        $this->get(route('pos.index'))->assertInertia(fn (AssertableInertia $page) => $page->component('POS/OpenSession'));
    }

    public function test_a_cashier_cannot_open_a_second_shift_on_another_register(): void
    {
        $this->open($this->first)->assertSessionHasNoErrors();

        $this->open($this->second)->assertSessionHasErrors('pos_register_id');

        $this->assertSame(1, $this->openShifts()->count());
    }

    public function test_a_shift_open_in_another_branch_counts_too_and_the_refusal_says_where_it_is(): void
    {
        $this->open($this->first)->assertSessionHasNoErrors();

        // The branch scope hides the shift here: this screen is how a cashier reaches a second one.
        $this->inBranch($this->jeddah);
        $this->assertAsksToOpenAShift();
        $this->open($this->inJeddah)->assertSessionHasErrors('pos_register_id');

        $message = session('errors')->first('pos_register_id');
        $this->assertStringContainsString('كاشير 1', $message);
        $this->assertStringContainsString('الرياض', $message);
        $this->assertSame(1, $this->openShifts()->count());
    }

    public function test_after_closing_the_shift_a_cashier_opens_another(): void
    {
        $this->open($this->first)->assertSessionHasNoErrors();

        $this->close($this->openShifts()->firstOrFail())->assertSessionHasNoErrors();

        $this->open($this->second)->assertSessionHasNoErrors();
        $this->assertSame($this->second->id, (int) $this->openShifts()->sole()->pos_register_id);
    }

    public function test_other_cashiers_keep_their_own_shift_but_not_on_a_busy_register(): void
    {
        $this->open($this->first)->assertSessionHasNoErrors();

        $this->actingAs($colleague = $this->cashierOf([$this->riyadh, $this->jeddah]));
        $this->open($this->first)->assertSessionHasErrors('pos_register_id');
        $this->open($this->second)->assertSessionHasNoErrors();

        $this->assertSame(2, $this->openShifts()->count());
        $this->assertSame($this->second->id, (int) $this->openShifts()->where('user_id', $colleague->id)->sole()->pos_register_id);
    }

    public function test_a_register_busy_in_another_branch_stays_busy(): void
    {
        $this->shiftAlreadyOpen($this->first, '2026-11-08 08:00:00', $this->cashierOf([$this->riyadh]));

        // A form left open in another tab still names the register of the other branch.
        $this->inBranch($this->jeddah);
        $this->open($this->first)->assertSessionHasErrors('pos_register_id');

        $this->assertSame(1, $this->openShifts()->count());
    }

    public function test_opening_locks_the_cashier_then_the_register_before_looking_for_open_shifts(): void
    {
        $queries = $this->queriesOf(fn () => $this->open($this->first)->assertSessionHasNoErrors());
        $locks = array_values(array_filter($queries, fn ($query) => $query['locked']));
        $this->assertSame(['users', 'pos_registers'], array_slice(array_column($locks, 'table'), 0, 2));

        // Plain reads of the shifts come after both locks, so they see what the request that held them committed.
        $position = fn (string $table, bool $locked) => array_key_first(array_filter($queries, fn ($query) => $query['table'] === $table && $query['locked'] === $locked));
        $this->assertLessThan($position('pos_sessions', false), $position('pos_registers', true));
    }

    public function test_two_shifts_left_open_from_before_this_rule_are_closed_one_after_the_other(): void
    {
        $older = $this->shiftAlreadyOpen($this->first, '2026-11-08 08:00:00');
        $newer = $this->shiftAlreadyOpen($this->second, '2026-11-08 12:00:00');

        $this->assertTerminalShows($newer);
        $this->close($newer)->assertRedirect(route('pos.index'))->assertSessionHasNoErrors();

        $this->assertTerminalShows($older);
        $this->close($older)->assertRedirect(route('pos.index'))->assertSessionHasNoErrors();

        $this->assertAsksToOpenAShift();
        $this->open($this->first)->assertSessionHasNoErrors();
    }

    public function test_two_shifts_left_open_in_two_branches_are_closed_from_each_branch(): void
    {
        $here = $this->shiftAlreadyOpen($this->first, '2026-11-08 08:00:00');
        $there = $this->shiftAlreadyOpen($this->inJeddah, '2026-11-08 12:00:00');

        $this->assertTerminalShows($here);
        $this->close($here)->assertSessionHasNoErrors();

        // The other one is out of sight here, and the refusal is what points to it.
        $this->assertAsksToOpenAShift();
        $this->open($this->second)->assertSessionHasErrors('pos_register_id');
        $this->assertStringContainsString('جدة', session('errors')->first('pos_register_id'));

        $this->inBranch($this->jeddah);
        $this->assertTerminalShows($there);
        $this->close($there)->assertSessionHasNoErrors();

        $this->assertSame(0, $this->openShifts()->count());
    }
}
