<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\AccountMap;
use App\Services\ActiveBranch;
use App\Services\TenantManager;
use App\Support\BranchScopeSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A fixed asset belongs to a branch: a new one takes the branch chosen in the top bar, and its
 * entries (purchase, depreciation, disposal) are filed there whichever branch is shown later.
 */
class FixedAssetBranchTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Branch $riyadh;

    protected Branch $jeddah;

    protected AssetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->jeddah = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        $map = app(AccountMap::class);
        $this->category = AssetCategory::create(['name_ar' => 'معدات', 'useful_life_months' => 60, 'depreciation_method' => 'straight_line',
            'asset_account_id' => $map->id('fixed_assets', $this->company->id), 'accumulated_account_id' => $map->id('accumulated_depreciation', $this->company->id),
            'expense_account_id' => $map->id('depreciation_expense', $this->company->id)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function box(string $name, ?Branch $branch, string $code): BankAccount
    {
        $ledger = Account::create(['code' => $code, 'name_ar' => $name, 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'is_active' => true]);
        $create = fn () => BankAccount::create(['name' => $name, 'type' => 'cash', 'currency' => 'SAR', 'branch_id' => $branch?->id, 'is_active' => true, 'account_id' => $ledger->id]);

        return $branch ? $create() : BranchScopeSwitch::off($create);
    }

    /** What the asset dialog posts: it has no branch field, so branch_id is empty. */
    protected function register(int|string $branch, array $data = [])
    {
        return $this->withSession([ActiveBranch::SESSION_KEY => $branch])->from(route('accounting.fixed-assets.index'))->post(route('accounting.fixed-assets.store'), array_merge([
            'asset_category_id' => $this->category->id, 'branch_id' => '', 'code' => 'FA-1', 'name_ar' => 'مولد', 'acquisition_date' => '2026-01-01',
            'cost' => 12000, 'salvage_value' => 0, 'paid_from' => 'cash',
        ], $data));
    }

    public function test_an_asset_and_all_its_entries_stay_in_the_branch_it_was_bought_in(): void
    {
        $box = $this->box('صندوق جدة', $this->jeddah, '11901');
        $this->register($this->jeddah->id, ['bank_account_id' => $box->id])->assertSessionHasNoErrors();

        $asset = FixedAsset::firstOrFail();
        $this->assertSame($this->jeddah->id, (int) $asset->branch_id);
        $this->assertSame($this->jeddah->id, (int) JournalEntry::withoutGlobalScope('branch')->findOrFail($asset->acquisition_entry_id)->branch_id);

        // Later, with Riyadh in the top bar: the depreciation and the disposal are Jeddah's entries.
        $shared = $this->box('صندوق مشترك', null, '11902');
        $riyadh = fn () => $this->withSession([ActiveBranch::SESSION_KEY => $this->riyadh->id]);
        $riyadh()->post(route('accounting.fixed-assets.depreciate'), ['month' => '2026-05'])->assertSessionHasNoErrors();
        $riyadh()->post(route('accounting.fixed-assets.dispose', $asset), ['date' => '2026-06-30', 'proceeds' => 9000, 'method' => 'cash', 'bank_account_id' => $shared->id])
            ->assertSessionHasNoErrors();

        $entries = JournalEntry::withoutGlobalScope('branch')->where('source_type', $asset->getMorphClass())->where('source_id', $asset->id);
        $this->assertSame([$this->jeddah->id], $entries->pluck('branch_id')->map(fn ($b) => (int) $b)->unique()->values()->all());
        $this->assertSame('disposed', $asset->fresh()->status);
        $this->assertEquals(9000.0, (float) JournalEntry::withoutGlobalScope('branch')->findOrFail($asset->fresh()->disposal_entry_id)->lines()->where('account_id', $shared->account_id)->sum('debit'));
    }

    public function test_the_disposal_dialog_offers_the_boxes_of_the_asset_branch(): void
    {
        $this->box('صندوق الرياض', $this->riyadh, '11901');
        $jeddahBox = $this->box('صندوق جدة', $this->jeddah, '11902');
        $shared = $this->box('صندوق مشترك', null, '11903');
        $this->register($this->jeddah->id, ['paid_from' => 'opening'])->assertSessionHasNoErrors();
        $asset = FixedAsset::firstOrFail();
        $boxes = fn ($branch) => array_column($this->withSession([ActiveBranch::SESSION_KEY => $branch])->get(route('accounting.fixed-assets.show', $asset))
            ->assertOk()->inertiaProps('bankAccounts'), 'id');

        // The asset's branch, or every branch: its boxes and the shared ones. Never another branch's box.
        $this->assertEqualsCanonicalizing([$jeddahBox->id, $shared->id], $boxes($this->jeddah->id));
        $this->assertEqualsCanonicalizing([$jeddahBox->id, $shared->id], $boxes(ActiveBranch::ALL));
        // Another branch in the top bar: only the shared ones (its posting finds no box outside that branch).
        $this->assertEqualsCanonicalizing([$shared->id], $boxes($this->riyadh->id));
    }

    public function test_an_asset_cannot_be_registered_under_all_branches(): void
    {
        $this->register(ActiveBranch::ALL)->assertRedirect(route('accounting.fixed-assets.index'))->assertSessionHas('error');

        $this->assertSame(0, FixedAsset::count());
        $this->assertSame(0, JournalEntry::withoutGlobalScope('branch')->count());
    }

    public function test_assets_saved_without_a_branch_take_the_branch_of_their_purchase_entry(): void
    {
        $this->register($this->jeddah->id)->assertSessionHasNoErrors();
        $this->register($this->riyadh->id, ['code' => 'FA-2'])->assertSessionHasNoErrors();
        [$jeddahAsset, $orphan] = FixedAsset::orderBy('id')->get()->all();
        // As saved before the fix; the second one's purchase entry has no branch either.
        DB::table('fixed_assets')->update(['branch_id' => null]);
        DB::table('journal_entries')->where('id', $orphan->acquisition_entry_id)->update(['branch_id' => null]);

        (require database_path('migrations/2026_11_22_000001_fill_fixed_asset_branches.php'))->up();

        $this->assertSame($this->jeddah->id, (int) $jeddahAsset->fresh()->branch_id);
        $this->assertNull($orphan->fresh()->branch_id);
    }
}
