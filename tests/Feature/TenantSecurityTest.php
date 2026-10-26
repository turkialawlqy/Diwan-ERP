<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.central_domain' => 'diwan.test']);

        $this->companyA = Company::factory()->create(['subdomain' => 'alpha']);
        $this->companyB = Company::factory()->create(['subdomain' => 'beta']);

        $this->userA = $this->userWithRole($this->companyA);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_user_cannot_use_session_on_another_companys_subdomain(): void
    {
        $this->actingAs($this->userA)
            ->get('http://beta.diwan.test/dashboard')
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_user_can_use_own_companys_subdomain(): void
    {
        $this->actingAs($this->userA)
            ->get('http://alpha.diwan.test/dashboard')
            ->assertOk();
    }

    public function test_unknown_subdomain_returns_404(): void
    {
        $this->get('http://nobody.diwan.test/login')->assertNotFound();
    }

    public function test_login_on_other_companys_subdomain_is_rejected(): void
    {
        $this->post('http://beta.diwan.test/login', [
            'email' => $this->userA->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_on_own_subdomain_succeeds(): void
    {
        $this->post('http://alpha.diwan.test/login', [
            'email' => $this->userA->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($this->userA);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->userA->forceFill(['status' => 'inactive'])->save();

        $this->post('/login', ['email' => $this->userA->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_user_without_company_cannot_reach_erp_routes(): void
    {
        $orphan = User::factory()->create(['company_id' => null]);

        $this->actingAs($orphan)->get('/dashboard')->assertForbidden();
    }

    public function test_scoped_queries_fail_closed_without_tenant(): void
    {
        Customer::create(['company_id' => $this->companyA->id, 'code' => 'C1', 'name' => 'A', 'customer_type' => 'commercial', 'status' => 'active']);

        $manager = app(TenantManager::class);
        $manager->forget();
        $manager->enforce();

        $this->assertSame(0, Customer::count());
    }

    public function test_cannot_reference_another_companys_record(): void
    {
        $foreignBranch = Branch::create(['company_id' => $this->companyB->id, 'name_ar' => 'فرع ب']);

        $this->actingAs($this->userA)
            ->post('/inventory/warehouses', [
                'name_ar' => 'مستودع',
                'code' => 'WH-X',
                'branch_id' => $foreignBranch->id,
            ])
            ->assertSessionHasErrors('branch_id');
    }

    public function test_attachment_upload_rejects_html_and_stores_privately(): void
    {
        Storage::fake('local');

        $this->actingAs($this->userA)
            ->postJson('/sales/clients/upload-attachment', [
                'file' => UploadedFile::fake()->createWithContent('x.html', '<script>alert(1)</script>'),
            ])
            ->assertStatus(422);

        $response = $this->actingAs($this->userA)
            ->postJson('/sales/clients/upload-attachment', [
                'file' => UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf'),
            ])
            ->assertOk();

        $url = $response->json('file.url');
        $this->assertStringStartsWith('/files/', $url);
        $this->assertNotEmpty(Storage::disk('local')->allFiles("companies/{$this->companyA->id}"));

        $this->actingAs($this->userA)->get($url)->assertOk();
    }

    public function test_customer_attachment_urls_must_point_to_own_files(): void
    {
        $this->actingAs($this->userA)
            ->post('/sales/clients', [
                'name' => 'عميل',
                'attachments' => [['name' => 'x', 'url' => 'javascript:alert(1)']],
            ])
            ->assertSessionHasErrors('attachments.0.url');
    }

    public function test_super_admin_role_cannot_be_assigned(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->actingAs($this->userA)
            ->post('/users', [
                'name' => 'X',
                'email' => 'x@example.com',
                'password' => 'password123',
                'role' => 'super_admin',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('role');
    }
}
