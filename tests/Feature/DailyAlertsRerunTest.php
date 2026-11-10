<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * erp:alerts may run again the same day, by hand or after a failure: what went out must not go
 * out twice, and what did not go out must still be able to.
 */
class DailyAlertsRerunTest extends TestCase
{
    use RefreshDatabase;

    private const ONCE = ['overdue_invoices' => 1, 'reorder' => 1];

    public function test_a_second_run_the_same_day_repeats_nothing_and_the_next_day_sends_as_usual(): void
    {
        [, $first, $second] = $this->companyWithAlertsDue();

        $this->runAlerts();
        $this->assertSame(self::ONCE, $this->received($first));
        $this->assertSame(self::ONCE, $this->received($second));

        $this->runAlerts();
        $this->assertSame(self::ONCE, $this->received($first), 'the second run repeated an alert');
        $this->assertSame(self::ONCE, $this->received($second), 'the second run repeated an alert');

        $this->travel(1)->days();
        $this->runAlerts();
        $this->assertSame(['overdue_invoices' => 2, 'reorder' => 2], $this->received($first), 'the next day sends again');
        $this->assertSame(['overdue_invoices' => 2, 'reorder' => 2], $this->received($second), 'the next day sends again');
    }

    public function test_a_second_run_still_sends_what_had_not_gone_out(): void
    {
        [$company, $first] = $this->companyWithAlertsDue();
        $this->runAlerts();

        $joinedLater = $this->userWithRole($company);
        $this->runAlerts();

        $this->assertSame(self::ONCE, $this->received($first));
        $this->assertSame(self::ONCE, $this->received($joinedLater));
    }

    public function test_a_company_that_failed_midway_is_completed_by_the_next_run_and_the_others_are_unaffected(): void
    {
        [, $first, $second] = $this->companyWithAlertsDue();
        [, $nextCompanyOwner] = $this->companyWithAlertsDue();
        $this->failDeliveryNumber(2);

        $this->runAlerts();

        $this->assertStringContainsString('delivery is down', Artisan::output());
        $this->assertSame(1, array_sum($this->received($first)) + array_sum($this->received($second)), 'the company stops at its failure');
        $this->assertSame(self::ONCE, $this->received($nextCompanyOwner), 'a failure in one company must not stop the next');

        $this->runAlerts();

        $this->assertSame(self::ONCE, $this->received($first), 'the retry sends only what is missing');
        $this->assertSame(self::ONCE, $this->received($second), 'the retry sends only what is missing');
        $this->assertSame(self::ONCE, $this->received($nextCompanyOwner));
    }

    public function test_a_subscription_reminder_that_failed_is_sent_by_the_next_run_and_only_once(): void
    {
        $company = Company::factory()->create(['status' => 'trial', 'trial_ends_at' => now()->addDays(3)]);
        $first = $this->userWithRole($company);
        $second = $this->userWithRole($company);
        $this->failDeliveryNumber(2);

        $this->runAlerts();
        $this->assertSame(1, array_sum($this->received($first)) + array_sum($this->received($second)), 'the second owner failed');

        $this->runAlerts();
        $this->assertSame(['subscription_before_3' => 1], $this->received($first));
        $this->assertSame(['subscription_before_3' => 1], $this->received($second));

        $this->runAlerts();
        $this->assertSame(['subscription_before_3' => 1], $this->received($first), 'its own once-per-step guard still holds');
        $this->assertSame(['subscription_before_3' => 1], $this->received($second), 'its own once-per-step guard still holds');
    }

    /** @return array{0: Company, 1: User, 2: User} a company with two owners and two alerts due */
    private function companyWithAlertsDue(): array
    {
        $company = Company::factory()->create();
        $owners = [$this->userWithRole($company), $this->userWithRole($company)];

        app(TenantManager::class)->runAs($company, function () use ($company) {
            $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active']);
            Invoice::create(['customer_id' => $customer->id, 'invoice_number' => 'INV-'.$company->id, 'invoice_date' => now()->subDays(40), 'due_date' => now()->subDays(10),
                'subtotal' => 100, 'tax_amount' => 15, 'total' => 115, 'remaining_amount' => 115, 'payment_status' => 'unpaid']);
            Product::create(['name_ar' => 'صنف', 'sku' => 'P-'.$company->id, 'selling_price' => 100, 'track_inventory' => true, 'is_active' => true, 'reorder_level' => 5]);
        });

        return [$company, ...$owners];
    }

    private function runAlerts(): void
    {
        $this->assertSame(0, Artisan::call('erp:alerts'));
    }

    /** @return array<string, int> how many times each alert reached the user, by kind */
    private function received(User $user): array
    {
        return $user->notifications()->get()->countBy(fn ($notification) => $notification->data['kind'])->sortKeys()->all();
    }

    /** That delivery blows up, as a queue or mail outage would; the ones around it go through. */
    private function failDeliveryNumber(int $number): void
    {
        $deliveries = 0;
        Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$deliveries, $number) {
            if ($event->channel === 'database' && ++$deliveries === $number) {
                throw new RuntimeException('delivery is down');
            }
        });
    }
}
