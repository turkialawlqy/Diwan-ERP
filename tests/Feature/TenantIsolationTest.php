<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $userA;

    protected Customer $customerA;

    protected Customer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();

        $this->userA = $this->userWithRole($this->companyA);

        $this->customerA = Customer::create([
            'company_id' => $this->companyA->id,
            'customer_type' => 'commercial',
            'code' => 'A-0001',
            'name' => 'Customer A',
            'status' => 'active',
        ]);

        $this->customerB = Customer::create([
            'company_id' => $this->companyB->id,
            'customer_type' => 'commercial',
            'code' => 'B-0001',
            'name' => 'Customer B',
            'status' => 'active',
        ]);
    }

    public function test_global_scope_excludes_other_companys_records_at_query_level(): void
    {
        app(TenantManager::class)->setTenant($this->companyA);

        $ids = Customer::all()->pluck('id');

        $this->assertTrue($ids->contains($this->customerA->id));
        $this->assertFalse($ids->contains($this->customerB->id));
    }

    public function test_index_does_not_list_other_companys_customers(): void
    {
        $response = $this->actingAs($this->userA)->get(route('sales.clients.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('customers.data', fn ($customers) => $customers->pluck('id')->doesntContain($this->customerB->id)
                && $customers->pluck('id')->contains($this->customerA->id))
        );
    }

    public function test_cannot_view_other_companys_customer(): void
    {
        $response = $this->actingAs($this->userA)->get(route('sales.clients.show', $this->customerB));

        $response->assertNotFound();
    }

    public function test_cannot_update_other_companys_customer(): void
    {
        $response = $this->actingAs($this->userA)->put(route('sales.clients.update', $this->customerB), [
            'name' => 'Hacked Name',
            'status' => 'active',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('customers', [
            'id' => $this->customerB->id,
            'name' => 'Customer B',
        ]);
    }

    public function test_cannot_delete_other_companys_customer(): void
    {
        $response = $this->actingAs($this->userA)->delete(route('sales.clients.destroy', $this->customerB));

        $response->assertNotFound();
        $this->assertDatabaseHas('customers', ['id' => $this->customerB->id]);
    }

    public function test_cannot_create_record_for_another_company_via_mass_assignment(): void
    {
        app(TenantManager::class)->setTenant($this->companyA);

        $customer = Customer::create([
            'company_id' => $this->companyB->id,
            'customer_type' => 'commercial',
            'code' => 'FORGED-001',
            'name' => 'Forged Customer',
            'status' => 'active',
        ]);

        $this->assertNotEquals($this->companyB->id, $customer->company_id);
    }
}
