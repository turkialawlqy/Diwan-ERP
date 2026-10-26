<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SubscriptionPlan;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guests on the platform domain see the landing page with the active plans.
     */
    public function test_guests_see_the_landing_page_with_active_plans(): void
    {
        SubscriptionPlan::create(['name_ar' => 'الأساسية', 'name_en' => 'Basic', 'slug' => 'basic', 'price_monthly' => 199, 'price_yearly' => 1990, 'is_active' => true]);
        SubscriptionPlan::create(['name_ar' => 'قديمة', 'name_en' => 'Old', 'slug' => 'old', 'price_monthly' => 99, 'price_yearly' => 990, 'is_active' => false]);

        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Landing')
            ->has('plans', 1)
            ->where('plans.0.name_ar', 'الأساسية')
            ->where('countries', count(config('countries'))));
    }

    /**
     * A company's own subdomain sends guests straight to its login.
     */
    public function test_a_company_subdomain_redirects_guests_to_login(): void
    {
        config(['tenancy.central_domain' => 'diwan.test']);
        Company::factory()->create(['subdomain' => 'alostaz']);

        $this->get('http://alostaz.diwan.test/')->assertRedirect();
        $this->assertStringEndsWith('/login', $this->get('http://alostaz.diwan.test/')->headers->get('Location'));
        app(TenantManager::class)->forget();
    }
}
