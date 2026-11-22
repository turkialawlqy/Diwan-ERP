<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Documents\DocumentNumberService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The product code a form suggests comes from the numbering service and is never one already
 * taken: saving it as suggested moves on to the next, and a code another product holds is
 * refused with a message instead of failing on the unique index (company_id, sku).
 */
class ProductCodeSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function suggested(string $route): string
    {
        $sku = $this->get(route($route))->assertOk()->viewData('page')['props']['suggestedSku'] ?? null;
        $this->assertIsString($sku, "{$route} sends no suggestedSku");

        return $sku;
    }

    public function test_a_code_saved_as_suggested_is_not_suggested_again(): void
    {
        // A product numbered by the service gives the sequence its row: the case where the page repeated its code.
        $this->post(route('inventory.products.store'), ['name_ar' => 'أول', 'selling_price' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $first = $this->suggested('inventory.products.create');
        $this->post(route('inventory.products.store'), ['name_ar' => 'ثان', 'selling_price' => 1, 'sku' => $first])->assertRedirect()->assertSessionHasNoErrors();

        $second = $this->suggested('inventory.products.create');
        $this->assertNotSame($first, $second);
        $this->post(route('inventory.products.store'), ['name_ar' => 'ثالث', 'selling_price' => 1, 'sku' => $second])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, Product::count());
    }

    public function test_a_code_another_product_holds_is_refused_with_a_message(): void
    {
        Product::create(['name_ar' => 'قائم', 'sku' => 'DUP-1', 'type' => 'product', 'is_active' => true]);
        // Deleted products keep their code in the unique index.
        Product::create(['name_ar' => 'محذوف', 'sku' => 'DUP-2', 'type' => 'product', 'is_active' => true])->delete();

        foreach (['DUP-1', 'DUP-2'] as $sku) {
            $this->post(route('inventory.products.store'), ['name_ar' => 'مكرر', 'selling_price' => 1, 'sku' => $sku])->assertSessionHasErrors('sku');
            $this->postJson(route('inventory.products.store'), ['name_ar' => 'مكرر', 'selling_price' => 1, 'sku' => $sku])->assertStatus(422)->assertJsonValidationErrors('sku');
        }
    }

    public function test_editing_a_product_to_another_products_code_is_refused(): void
    {
        Product::create(['name_ar' => 'أ', 'sku' => 'A-1', 'type' => 'product', 'is_active' => true]);
        $b = Product::create(['name_ar' => 'ب', 'sku' => 'B-1', 'type' => 'product', 'is_active' => true]);
        $data = ['name_ar' => 'ب', 'purchase_price' => 0, 'selling_price' => 1, 'type' => 'product'];

        $this->put(route('inventory.products.update', $b->id), $data + ['sku' => 'A-1'])->assertSessionHasErrors('sku');
        $this->put(route('inventory.products.update', $b->id), $data + ['sku' => 'B-1'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_the_purchase_invoice_dialog_takes_its_code_from_the_numbering_service(): void
    {
        // More products than the form lists: the list length can no longer give a free code.
        $now = now();
        DB::table('products')->insert(collect(range(1, 210))->map(fn ($i) => ['company_id' => $this->company->id, 'name_ar' => sprintf('صنف %04d', $i),
            'sku' => sprintf('PRD-%05d', $i), 'type' => 'product', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());

        $suggested = $this->suggested('purchases.invoices.create');
        $this->assertSame(app(DocumentNumberService::class)->peek('product'), $suggested);
        $this->assertSame('PRD-00211', $suggested);
    }
}
