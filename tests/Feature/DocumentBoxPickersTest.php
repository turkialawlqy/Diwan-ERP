<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BookableService;
use App\Models\Booking;
use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Accounting\AccountingSetupService;
use App\Services\ActiveBranch;
use App\Services\TenantManager;
use App\Support\BranchScopeSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The supplier payment voucher and the booking deposit choose the box they pay from or into:
 * one of the document's branch or one shared by all branches (they always went to the default).
 */
class DocumentBoxPickersTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Branch $riyadh;

    protected Branch $jeddah;

    protected BankAccount $riyadhBox;

    protected BankAccount $jeddahBox;

    protected BankAccount $sharedBank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-06-15 08:00:00');
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->riyadh = Branch::create(['name_ar' => 'الرياض', 'code' => 'RUH', 'is_main' => true, 'is_active' => true]);
        $this->jeddah = Branch::create(['name_ar' => 'جدة', 'code' => 'JED', 'is_active' => true]);
        $this->riyadhBox = $this->box('صندوق الرياض', $this->riyadh, '11901');
        $this->jeddahBox = $this->box('صندوق جدة', $this->jeddah, '11902');
        $this->sharedBank = $this->box('البنك المركزي', null, '11903', 'bank');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function box(string $name, ?Branch $branch, string $code, string $type = 'cash'): BankAccount
    {
        $ledger = Account::create(['code' => $code, 'name_ar' => $name, 'type' => 'asset', 'nature' => 'debit', 'is_parent' => false, 'is_active' => true]);
        $create = fn () => BankAccount::create(['name' => $name, 'type' => $type, 'currency' => 'SAR', 'branch_id' => $branch?->id, 'is_active' => true, 'account_id' => $ledger->id]);

        // A box shared by all branches has no branch: made outside the signed-in user's branch.
        return $branch ? $create() : BranchScopeSwitch::off($create);
    }

    protected function ledgerLine(string $sourceType, int $sourceId, int $accountId, string $side): float
    {
        $entry = JournalEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->firstOrFail();

        return (float) $entry->lines()->where('account_id', $accountId)->sum($side);
    }

    public function test_the_supplier_payment_voucher_offers_the_boxes_and_tells_each_bill_branch(): void
    {
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $bill = PurchaseInvoice::create(['supplier_id' => $supplier->id, 'invoice_number' => 'PI-1', 'invoice_date' => '2026-06-01', 'branch_id' => $this->jeddah->id,
            'subtotal' => 1000, 'tax_amount' => 150, 'total' => 1150, 'paid_amount' => 0, 'remaining_amount' => 1150, 'payment_status' => 'unpaid', 'status' => 'approved']);

        // Under «all branches» the form gets every box with its branch, and narrows them to the chosen bill's.
        $boxes = $this->withSession([ActiveBranch::SESSION_KEY => ActiveBranch::ALL])->get(route('purchases.payments.create'))->assertOk()->inertiaProps('bankAccounts');
        $this->assertEqualsCanonicalizing([[$this->riyadhBox->id, $this->riyadh->id], [$this->jeddahBox->id, $this->jeddah->id], [$this->sharedBank->id, null]],
            array_map(fn ($b) => [$b['id'], $b['branch_id']], $boxes));
        $this->withSession([ActiveBranch::SESSION_KEY => ActiveBranch::ALL])->getJson(route('purchases.payments.open-invoices', ['supplier_id' => $supplier->id]))
            ->assertOk()->assertJsonPath('data.0.id', $bill->id)->assertJsonPath('data.0.branch_id', $this->jeddah->id);

        // A branch shown in the top bar: its boxes and the shared ones.
        $boxes = $this->withSession([ActiveBranch::SESSION_KEY => $this->riyadh->id])->get(route('purchases.payments.create'))->inertiaProps('bankAccounts');
        $this->assertEqualsCanonicalizing([$this->riyadhBox->id, $this->sharedBank->id], array_column($boxes, 'id'));

        $form = File::get(resource_path('js/Pages/Purchases/Payments/Form.vue'));
        $this->assertStringContainsString('v-model="form.bank_account_id" :options="boxes"', $form);
    }

    public function test_a_supplier_payment_is_paid_from_the_chosen_box(): void
    {
        $supplier = Supplier::create(['name' => 'مورد', 'status' => 'active']);
        $this->withSession([ActiveBranch::SESSION_KEY => $this->riyadh->id])->post(route('purchases.payments.store'), [
            'supplier_id' => $supplier->id, 'amount' => 300, 'payment_date' => '2026-06-15', 'payment_method' => 'cash', 'bank_account_id' => $this->riyadhBox->id,
        ])->assertSessionHasNoErrors();

        $payment = SupplierPayment::firstOrFail();
        $this->assertSame($this->riyadhBox->id, (int) $payment->bank_account_id);
        $this->assertEquals(300.0, $this->ledgerLine($payment->getMorphClass(), $payment->id, $this->riyadhBox->account_id, 'credit'));
    }

    public function test_a_booking_deposit_goes_into_the_chosen_box_of_the_booking_branch(): void
    {
        $customer = Customer::create(['name' => 'سارة', 'customer_type' => 'individual', 'status' => 'active']);
        $room = BookingResource::create(['name' => 'غرفة جدة', 'type' => 'room', 'capacity' => 1, 'is_active' => true, 'branch_id' => $this->jeddah->id]);
        $room->hours()->create(['weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00']);
        $service = BookableService::create(['name' => 'جلسة', 'duration_minutes' => 60, 'buffer_minutes' => 0, 'price' => 100, 'tax_rate' => 15, 'is_active' => true, 'branch_id' => $this->jeddah->id]);
        $service->resources()->attach($room->id);

        // The form gets the boxes and each resource's branch, to offer the booking branch's boxes.
        $page = $this->withSession([ActiveBranch::SESSION_KEY => ActiveBranch::ALL])->get(route('bookings.create'))->assertOk();
        $this->assertSame($this->jeddah->id, collect($page->inertiaProps('resources'))->firstWhere('id', $room->id)['branch_id']);
        $this->assertEqualsCanonicalizing([$this->riyadhBox->id, $this->jeddahBox->id, $this->sharedBank->id], array_column($page->inertiaProps('bankAccounts'), 'id'));

        $this->withSession([ActiveBranch::SESSION_KEY => $this->jeddah->id])->post(route('bookings.store'), [
            'customer_id' => $customer->id, 'bookable_service_id' => $service->id, 'booking_resource_id' => $room->id, 'starts_at' => '2026-06-16 10:00',
            'deposit_amount' => 40, 'deposit_method' => 'cash', 'deposit_bank_account_id' => $this->jeddahBox->id,
        ])->assertSessionHasNoErrors();

        $receipt = CustomerPayment::findOrFail(Booking::firstOrFail()->deposit_payment_id);
        $this->assertSame([$this->jeddahBox->id, $this->jeddah->id], [(int) $receipt->bank_account_id, (int) $receipt->branch_id]);
        $this->assertEquals(40.0, $this->ledgerLine($receipt->getMorphClass(), $receipt->id, $this->jeddahBox->account_id, 'debit'));

        $form = File::get(resource_path('js/Pages/Bookings/Form.vue'));
        $this->assertStringContainsString('v-model="form.deposit_bank_account_id" :options="depositBoxes"', $form);
    }
}
