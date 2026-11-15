<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Each user orders the side menu; the order is theirs alone and only the order is stored. */
class SidebarPreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(TenantManager::class)->setTenant($this->company);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_order_is_saved_shared_with_the_page_and_reset(): void
    {
        $user = $this->userWithRole($this->company);
        $this->actingAs($user);

        $this->post(route('preferences.sidebar.update'), [
            'groups' => ['accounting', 'sales', 'dashboard'],
            'children' => ['accounting' => ['accounting.ledger.index', 'accounting.accounts.index']],
        ])->assertRedirect();

        $this->assertSame(['accounting', 'sales', 'dashboard'], $user->fresh()->sidebar_order['groups']);
        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('auth.user.sidebar_order.groups.0', 'accounting')
            ->where('auth.user.sidebar_order.children.accounting.0', 'accounting.ledger.index'));

        $this->delete(route('preferences.sidebar.reset'))->assertRedirect();
        $this->assertNull($user->fresh()->sidebar_order);
    }

    public function test_it_is_per_user_and_refuses_junk(): void
    {
        $a = $this->userWithRole($this->company);
        $b = $this->userWithRole($this->company);

        $this->actingAs($a)->post(route('preferences.sidebar.update'), ['groups' => ['sales', 'dashboard']]);
        $this->assertNull($b->fresh()->sidebar_order);

        $this->post(route('preferences.sidebar.update'), ['groups' => ['<script>alert(1)</script>']])->assertSessionHasErrors('groups.0');
        $this->post(route('preferences.sidebar.update'), ['groups' => []])->assertSessionHasErrors('groups');
        $this->post(route('preferences.sidebar.update'), ['groups' => ['sales'], 'children' => ['sales' => ['a b']]])->assertSessionHasErrors('children.sales.0');
        $this->assertSame(['sales', 'dashboard'], $a->fresh()->sidebar_order['groups']);
    }
}
