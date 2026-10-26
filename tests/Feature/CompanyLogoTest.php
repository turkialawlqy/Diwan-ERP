<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_logo_is_served_without_storage_symlink(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));

        $response = $this->postJson(route('settings.logo.upload'), ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)])
            ->assertOk();

        $url = $response->json('logo');
        $this->assertStringStartsWith('/company/logo?v=', $url);
        $this->assertSame($url, $company->fresh()->logo_url);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_logo_route_only_serves_current_company(): void
    {
        Storage::fake('public');
        $other = Company::factory()->create();
        Storage::disk('public')->put("logos/{$other->id}/x.png", 'img');
        $company = Company::factory()->create(['logo' => "/storage/logos/{$other->id}/x.png"]);
        app(TenantManager::class)->setTenant($company);
        $this->actingAs($this->userWithRole($company));

        // A logo path pointing at another company's folder is never served.
        $this->get(route('company.logo'))->assertNotFound();
        $this->assertSame("/storage/logos/{$other->id}/x.png", $company->logo_url);
    }
}
