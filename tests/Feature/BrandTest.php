<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The product name and platform domain come from configuration, so a
 * rebrand or a new domain needs no code change.
 */
class BrandTest extends TestCase
{
    public function test_pages_receive_the_configured_brand_and_domain(): void
    {
        config(['app.name' => 'الديوان', 'app.brand.name_en' => 'Diwan', 'tenancy.central_domain' => 'diwan.test']);

        $brand = $this->get('/login')->assertOk()->viewData('page')['props']['brand'];

        $this->assertSame('الديوان', $brand['name']);
        $this->assertSame('Diwan', $brand['name_en']);
        $this->assertSame('diwan.test', $brand['domain']);
    }

    public function test_the_default_product_name_is_diwan(): void
    {
        $this->assertStringContainsString("env('APP_NAME', 'الديوان')", file_get_contents(base_path('config/app.php')));
    }
}
