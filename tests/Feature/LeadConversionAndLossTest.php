<?php

namespace Tests\Feature;

use App\Http\Controllers\Crm\LeadController;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadConversionAndLossTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_converting_from_a_stale_page_finds_the_customer_the_first_click_made(): void
    {
        $lead = Lead::create(['name' => 'محتمل واحد', 'status' => 'qualified']);
        $stale = Lead::findOrFail($lead->id);          // a second click, before the first one finished
        $controller = app(LeadController::class);

        $first = $controller->convert($lead);
        $second = $controller->convert($stale);

        $this->assertSame(1, Customer::where('name', 'محتمل واحد')->count());
        $this->assertSame($first->getTargetUrl(), $second->getTargetUrl());
        $this->assertSame('won', $lead->fresh()->status);
    }

    public function test_a_lead_cannot_be_dragged_to_lost_without_a_reason(): void
    {
        $lead = Lead::create(['name' => 'فرصة', 'status' => 'proposal']);

        $this->post(route('crm.leads.move', $lead), ['status' => 'lost'])->assertSessionHasErrors('lost_reason');
        $this->assertSame('proposal', $lead->fresh()->status);

        $this->post(route('crm.leads.move', $lead), ['status' => 'lost', 'lost_reason' => 'السعر مرتفع'])->assertSessionHasNoErrors();
        $this->assertSame('lost', $lead->fresh()->status);
        $this->assertSame('السعر مرتفع', $lead->fresh()->lost_reason);
    }

    public function test_the_other_stages_need_no_reason(): void
    {
        $lead = Lead::create(['name' => 'فرصة', 'status' => 'new']);

        $this->post(route('crm.leads.move', $lead), ['status' => 'qualified'])->assertSessionHasNoErrors();

        $this->assertSame('qualified', $lead->fresh()->status);
    }
}
