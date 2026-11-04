<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractObligation;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Contracts\ContractService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Contracts: versions, approval by amount, activation, renewal, expiry, reminders and obligations. */
class ContractsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->owner = $this->userWithRole($this->company);
        $this->actingAs($this->owner);
        $this->customer = Customer::create(['name' => 'شركة العميل', 'customer_type' => 'commercial', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function make(array $extra = [], array $obligations = []): Contract
    {
        $this->post(route('contracts.store'), array_merge([
            'title' => 'عقد صيانة سنوي', 'kind' => 'service', 'party_type' => 'customer', 'customer_id' => $this->customer->id,
            'start_date' => '2026-06-15', 'end_date' => '2027-06-14', 'value' => 12000, 'body' => 'البند الأول: الصيانة الدورية.',
            'obligations' => $obligations,
        ], $extra))->assertSessionHasNoErrors();

        return Contract::latest('id')->firstOrFail();
    }

    protected function service(): ContractService
    {
        return app(ContractService::class);
    }

    public function test_a_contract_gets_a_number_a_first_version_and_obligations(): void
    {
        $c = $this->make([], [['title' => 'الدفعة الأولى', 'kind' => 'payment', 'due_date' => '2026-07-01', 'amount' => 6000]]);

        $this->assertSame('CTR-26-00001', $c->number);
        $this->assertSame('draft', $c->status);
        $this->assertSame(1, $c->version);
        $this->assertSame(1, $c->versions()->count());
        $this->assertSame(1, $c->obligations()->count());
        $this->assertEquals(6000, $c->obligations()->first()->amount);
    }

    public function test_a_supplier_contract_and_the_party_rules(): void
    {
        $this->post(route('contracts.store'), ['title' => 'x', 'kind' => 'sales', 'party_type' => 'customer', 'start_date' => '2026-06-15'])->assertSessionHasErrors('customer_id');
        $this->post(route('contracts.store'), ['title' => 'x', 'kind' => 'purchase', 'party_type' => 'supplier', 'start_date' => '2026-06-15'])->assertSessionHasErrors('supplier_id');

        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $c = $this->make(['kind' => 'purchase', 'party_type' => 'supplier', 'supplier_id' => $supplier->id, 'customer_id' => $this->customer->id]);

        $this->assertSame($supplier->id, $c->supplier_id);
        $this->assertNull($c->customer_id, 'only the chosen side is kept');
        $this->assertSame('مورد', $c->partyName());
    }

    public function test_editing_the_text_of_a_draft_saves_a_new_version_but_other_edits_do_not(): void
    {
        $c = $this->make();

        $this->put(route('contracts.update', $c->id), ['title' => 'عقد صيانة معدّل', 'kind' => 'service', 'party_type' => 'customer', 'customer_id' => $this->customer->id,
            'start_date' => '2026-06-15', 'end_date' => '2027-06-14', 'body' => 'البند الأول: الصيانة الدورية.'])->assertSessionHasNoErrors();
        $this->assertSame(1, $c->fresh()->version, 'same text: no new version');

        $this->put(route('contracts.update', $c->id), ['title' => 'عقد صيانة معدّل', 'kind' => 'service', 'party_type' => 'customer', 'customer_id' => $this->customer->id,
            'start_date' => '2026-06-15', 'end_date' => '2027-06-14', 'body' => 'البند الأول: صيانة شهرية.', 'version_note' => 'تغيير التكرار'])->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame(2, $c->version);
        $this->assertSame('تغيير التكرار', $c->versions()->first()->note);
        $this->assertSame(['البند الأول: صيانة شهرية.', 'البند الأول: الصيانة الدورية.'], $c->versions->pluck('body')->all());
    }

    public function test_without_a_rule_submitting_approves_and_activates_at_once(): void
    {
        $c = $this->make();

        $this->post(route('contracts.submit', $c->id))->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('active', $c->status);
        $this->assertNotNull($c->approved_at);
        $this->assertNotNull($c->signed_at);
    }

    public function test_a_rule_holds_big_contracts_for_another_user_to_approve(): void
    {
        ApprovalRule::create(['document_type' => 'contract', 'min_amount' => 10000, 'is_active' => true]);
        $c = $this->make(['value' => 12000]);

        $this->post(route('contracts.submit', $c->id));
        $this->assertSame('pending_approval', $c->fresh()->status);

        // The creator (here the owner) may approve; another approver rejects first.
        $approver = $this->userWithRole($this->company, 'accountant');
        $this->actingAs($approver);
        $this->post(route('contracts.approve', $c->id))->assertForbidden(); // accountant has no approve permission

        $this->actingAs($this->owner);
        $this->post(route('contracts.reject', $c->id), ['comment' => 'راجعوا البند 3'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $c->fresh()->status);

        // A rejected contract can be fixed and sent again.
        $this->post(route('contracts.submit', $c->id));
        $this->post(route('contracts.approve', $c->id), ['comment' => 'تمام'])->assertSessionHasNoErrors();
        $this->assertSame('active', $c->fresh()->status);
        $this->assertGreaterThanOrEqual(3, Approval::where('approvable_id', $c->id)->count());
    }

    public function test_an_approved_contract_with_a_future_start_activates_on_that_day(): void
    {
        $c = $this->make(['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
        $this->post(route('contracts.submit', $c->id));
        $this->assertSame('approved', $c->fresh()->status);

        Carbon::setTestNow('2026-06-30 01:00:00');
        $this->assertSame(0, $this->service()->nightly()['activated']);
        Carbon::setTestNow('2026-07-01 01:00:00');
        $this->assertSame(1, $this->service()->nightly()['activated']);
        $this->assertSame('active', $c->fresh()->status);
    }

    public function test_an_active_contract_can_be_amended_terminated_but_not_edited_in_place(): void
    {
        $c = $this->make();
        $this->post(route('contracts.submit', $c->id));

        $this->get(route('contracts.edit', $c->id))->assertForbidden();
        $this->post(route('contracts.amend', $c->id), ['body' => 'البند الأول: الصيانة الدورية.'])->assertSessionHasErrors('body');
        $this->post(route('contracts.amend', $c->id), ['body' => 'نص معدّل', 'note' => 'ملحق 1'])->assertSessionHasNoErrors();
        $this->assertSame(2, $c->fresh()->version);

        $this->post(route('contracts.terminate', $c->id), [])->assertSessionHasErrors('reason');
        $this->post(route('contracts.terminate', $c->id), ['reason' => 'إخلال بالتوريد'])->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('terminated', $c->status);
        $this->assertSame('إخلال بالتوريد', $c->termination_reason);
        $this->delete(route('contracts.destroy', $c->id))->assertSessionHasErrors('status');
    }

    public function test_renewal_continues_the_contract_in_a_chain(): void
    {
        $c = $this->make(['auto_renew' => false, 'renewal_term_months' => 12]);
        $this->post(route('contracts.submit', $c->id));

        $this->post(route('contracts.renew', $c->id))->assertSessionHasNoErrors();

        $new = Contract::where('parent_contract_id', $c->id)->firstOrFail();
        // Renewed ahead of time: the running contract keeps running, the new one is approved and waits for its start.
        $this->assertSame('active', $c->fresh()->status);
        $this->assertSame('approved', $new->status);
        $this->post(route('contracts.renew', $c->id))->assertSessionHasErrors('status'); // not twice
        $this->assertSame('2027-06-15', $new->start_date->toDateString());
        $this->assertSame('2028-06-14', $new->end_date->toDateString());
        $this->assertSame('CTR-27-00001', $new->number);
        $this->assertSame(1, $new->version);

        // On its start date the new one takes over.
        Carbon::setTestNow('2027-06-15 01:00:00');
        app(ContractService::class)->nightly();
        $this->assertSame('active', $new->fresh()->status);
        $this->assertSame('renewed', $c->fresh()->status);
        Carbon::setTestNow('2026-06-15 10:00:00');

        // Nothing to renew with: no term.
        $other = $this->make(['renewal_term_months' => null]);
        $this->post(route('contracts.submit', $other->id));
        $this->post(route('contracts.renew', $other->id))->assertSessionHasErrors('renewal_term_months');
    }

    public function test_the_night_renews_auto_renew_contracts_and_expires_the_others(): void
    {
        $auto = $this->make(['title' => 'تلقائي', 'auto_renew' => true, 'renewal_term_months' => 6, 'end_date' => '2026-08-31']);
        $manual = $this->make(['title' => 'يدوي', 'end_date' => '2026-08-31']);
        $this->post(route('contracts.submit', $auto->id));
        $this->post(route('contracts.submit', $manual->id));

        Carbon::setTestNow('2026-09-01 00:50:00');
        $done = $this->service()->nightly();

        $this->assertSame(1, $done['renewed']);
        $this->assertSame(1, $done['expired']);
        $this->assertSame('renewed', $auto->fresh()->status);
        $this->assertSame('expired', $manual->fresh()->status);
        $this->assertSame('2027-02-28', Contract::where('parent_contract_id', $auto->id)->first()->end_date->toDateString());
        $this->assertGreaterThanOrEqual(2, $this->owner->fresh()->notifications()->count());
    }

    public function test_expiry_reminders_go_out_once_per_step(): void
    {
        $c = $this->make(['end_date' => '2026-07-15', 'renewal_notice_days' => 30]);
        $this->post(route('contracts.submit', $c->id));

        Carbon::setTestNow('2026-06-15 00:50:00'); // 30 days before
        $this->assertSame(1, $this->service()->nightly()['reminders']);
        $this->assertSame(0, $this->service()->nightly()['reminders'], 'not twice');
        Carbon::setTestNow('2026-07-08 00:50:00'); // 7 days
        $this->assertSame(1, $this->service()->nightly()['reminders']);
        Carbon::setTestNow('2026-07-10 00:50:00'); // not a reminder day
        $this->assertSame(0, $this->service()->nightly()['reminders']);
        $this->assertSame(2, $this->owner->fresh()->notifications()->count());
    }

    public function test_obligations_are_completed_and_reminded_when_close_or_late(): void
    {
        $c = $this->make(['end_date' => '2027-06-14'], [
            ['title' => 'دفعة 1', 'kind' => 'payment', 'due_date' => '2026-06-17', 'amount' => 1000],
            ['title' => 'تسليم', 'kind' => 'delivery', 'due_date' => '2026-06-10'],
        ]);
        $this->post(route('contracts.submit', $c->id));
        [$late, $soon] = [ContractObligation::where('title', 'تسليم')->first(), ContractObligation::where('title', 'دفعة 1')->first()];

        $done = $this->service()->nightly();
        $this->assertSame(2, $done['reminders']); // one close, one late
        $this->assertSame(0, $this->service()->nightly()['reminders']);

        $this->post(route('contracts.obligations.toggle', $soon->id));
        $this->assertSame('done', $soon->fresh()->status);
        $this->assertSame($this->owner->id, $soon->fresh()->done_by);
        $this->post(route('contracts.obligations.toggle', $soon->id));
        $this->assertSame('pending', $soon->fresh()->status);
        $this->assertTrue($late->fresh()->isOverdue());
    }

    public function test_editing_syncs_pending_obligations(): void
    {
        $c = $this->make([], [['title' => 'أ', 'kind' => 'payment', 'due_date' => '2026-07-01'], ['title' => 'ب', 'kind' => 'payment', 'due_date' => '2026-08-01']]);
        $keep = $c->obligations()->where('title', 'أ')->first();

        $this->put(route('contracts.update', $c->id), ['title' => $c->title, 'kind' => 'service', 'party_type' => 'customer', 'customer_id' => $this->customer->id, 'start_date' => '2026-06-15', 'body' => $c->body,
            'obligations' => [['id' => $keep->id, 'title' => 'أ معدّل', 'kind' => 'payment', 'due_date' => '2026-07-05'], ['title' => 'ج', 'kind' => 'delivery', 'due_date' => '2026-09-01']]])->assertSessionHasNoErrors();

        $this->assertSame(['أ معدّل', 'ج'], $c->obligations()->pluck('title')->all());
    }

    public function test_templates_attachments_and_the_pages(): void
    {
        $this->post(route('contracts.templates.store'), ['name' => 'عقد صيانة', 'kind' => 'service', 'body' => 'نص النموذج'])->assertSessionHasNoErrors();
        $this->assertSame(1, ContractTemplate::count());

        Storage::fake('local');
        $this->postJson(route('contracts.attachments.upload'), ['file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf')])->assertOk()->assertJsonPath('success', true);

        $c = $this->make();
        $this->get(route('contracts.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Contracts/Index')->where('stats.active', 0)->has('contracts.data', 1));
        $this->get(route('contracts.show', $c->id))->assertOk()->assertInertia(fn ($p) => $p->component('Contracts/Show')->where('contract.number', $c->number));
        $this->get(route('contracts.create'))->assertOk()->assertInertia(fn ($p) => $p->has('templates', 1));
        $this->get(route('contracts.templates.index'))->assertOk();
        $this->get(route('contracts.index', ['search' => 'صيانة', 'status' => 'draft']))->assertInertia(fn ($p) => $p->has('contracts.data', 1));
    }

    public function test_the_module_switch_and_permissions_apply(): void
    {
        $c = $this->make();

        $this->company->setSetting('disabled_modules', ['contracts'], 'general', 'json');
        $this->get(route('contracts.index'))->assertForbidden();
        $this->company->setSetting('disabled_modules', [], 'general', 'json');

        $this->actingAs($this->userWithRole($this->company, 'warehouse_keeper'));
        $this->get(route('contracts.index'))->assertForbidden();
        $this->post(route('contracts.submit', $c->id))->assertForbidden();
    }
}
