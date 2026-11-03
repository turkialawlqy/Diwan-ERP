<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Product pictures: uploaded on the product form, streamed by the app, shown in the cashier. */
class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
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

    public function test_an_image_is_saved_with_a_new_product_streamed_replaced_and_deleted(): void
    {
        $this->post(route('inventory.products.store'), ['name_ar' => 'قهوة', 'selling_price' => 10, 'type' => 'service', 'image' => UploadedFile::fake()->image('a.jpg', 200, 200)])->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        Storage::disk('public')->assertExists($product->image);
        $this->assertNotNull($product->image_url);
        $this->get($product->image_url)->assertOk();

        $old = $product->image;
        $this->post(route('inventory.products.image.upload', $product->id), ['image' => UploadedFile::fake()->image('b.png')])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($old);
        $this->assertNotSame($old, $product->fresh()->image);

        $this->delete(route('inventory.products.image.delete', $product->id));
        $this->assertNull($product->fresh()->image);
        $this->get(route('inventory.products.image.show', $product->id))->assertNotFound();
    }

    public function test_only_images_are_accepted_and_editing_the_product_keeps_its_picture(): void
    {
        $this->post(route('inventory.products.store'), ['name_ar' => 'شاي', 'selling_price' => 5, 'type' => 'service', 'image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');

        $this->post(route('inventory.products.store'), ['name_ar' => 'شاي', 'selling_price' => 5, 'type' => 'service', 'image' => UploadedFile::fake()->image('a.jpg')]);
        $product = Product::firstOrFail();
        $path = $product->image;
        $this->put(route('inventory.products.update', $product->id), ['name_ar' => 'شاي أخضر', 'purchase_price' => 1, 'selling_price' => 6, 'type' => 'service'])->assertSessionHasNoErrors();
        $this->assertSame($path, $product->fresh()->image);
    }
}
