<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\PrintTemplate;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Documents\FreeformLayout;
use App\Services\Documents\FreeformRenderer;
use App\Services\Documents\FreeformTemplates;
use App\Services\Documents\TemplateData;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FreeformTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->withoutVite();
        $this->company = Company::factory()->create(['name' => 'شركة ريهيدا', 'tax_number' => '314514975500003']);
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
        $this->customer = Customer::create(['name' => 'شركة مجموعة المجال العربي', 'status' => 'active', 'tax_number' => '300231461500003',
            'building_number' => '2851', 'street_name' => 'الملك عبدالله', 'district' => 'الملك سلمان', 'city' => 'الرياض', 'postal_code' => '12444']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    protected function invoice(int $lines = 1): Invoice
    {
        $items = array_map(fn ($i) => ['item_name' => "بند {$i}", 'description' => 'تنظيف الخزانات', 'quantity' => 5, 'unit_price' => 450, 'tax_rate' => 15], range(1, $lines));

        return app(InvoiceService::class)->create(['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15'], $items, ['issue_stock' => false]);
    }

    protected function freeform(string $kind = 'invoice'): PrintTemplate
    {
        $this->post(route('templates.store'), ['name' => 'تصميمي', 'mode' => 'freeform', 'template_type' => $kind])->assertSessionHasNoErrors();

        return PrintTemplate::latest('id')->first();
    }

    protected function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_creating_a_freeform_template_starts_from_the_kinds_layout(): void
    {
        $template = $this->freeform();

        $this->assertTrue(FreeformTemplates::isFreeform($template));
        $types = collect($template->layout_data['freeform']['elements'])->pluck('type');
        $this->assertTrue($types->contains('table') && $types->contains('qr') && $types->contains('image'));
        $this->get(route('templates.designer', $template))->assertOk()->assertInertia(fn ($page) => $page->component('Templates/FreeformDesigner')
            ->has('catalogue.groups')->has('preview.fields'));
    }

    public function test_saving_replaces_the_layout_and_sanitizes_it(): void
    {
        $template = $this->freeform();
        $this->put(route('templates.update', $template), ['name' => 'تصميم جديد', 'freeform' => [
            'page' => ['size' => 'A5', 'orientation' => 'landscape'],
            'elements' => [
                ['id' => 'a', 'type' => 'text', 'x' => 10, 'y' => 10, 'w' => 50, 'h' => 8, 'content' => 'العميل: {{party.name}}', 'style' => ['color' => 'red;}', 'size' => 500]],
                ['id' => 'b', 'type' => 'image', 'x' => 5, 'y' => 5, 'w' => 20, 'h' => 20, 'src' => '../../.env'],
                ['id' => 'c', 'type' => 'script', 'x' => 0, 'y' => 0],
            ],
        ]])->assertSessionHasNoErrors();

        $layout = $template->fresh()->layout_data['freeform'];
        $this->assertSame('تصميم جديد', $template->fresh()->name);
        $this->assertEquals([210, 148], [$layout['page']['width'], $layout['page']['height']]);
        $this->assertCount(2, $layout['elements']); // preset elements are gone, unknown type dropped
        $this->assertSame('#000000', $layout['elements'][0]['style']['color']);
        $this->assertEquals(96, $layout['elements'][0]['style']['size']);
        $this->assertNull($layout['elements'][1]['src']);
    }

    public function test_default_freeform_invoice_prints_with_pagination_and_wins_over_the_quick_template(): void
    {
        PrintTemplate::create(['name' => 'سريع', 'template_type' => 'invoice_a4', 'layout_data' => PrintTemplate::getDefaultLayoutData(), 'is_default' => true]);
        $template = $this->freeform();
        $this->post(route('templates.default', $template))->assertSessionHasNoErrors();
        $this->assertFalse(PrintTemplate::where('template_type', 'invoice_a4')->first()->is_default);

        $invoice = $this->invoice(25);
        $table = collect($template->layout_data['freeform']['elements'])->firstWhere('type', 'table');
        $expected = (int) ceil(25 / FreeformLayout::rowsPerPage(FreeformLayout::sanitize(['elements' => [$table]], $this->company->id)['elements'][0]));

        $response = $this->get(route('documents.pdf', ['type' => 'invoice', 'id' => $invoice->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(1, $expected);
        $this->assertSame($expected, $this->pages($response->getContent()));

        // The invoice page offers the free-form template and prints from the PDF.
        $this->get(route('sales.invoices.show', $invoice))->assertInertia(fn ($page) => $page->where('template.id', $template->id));
    }

    public function test_page_rules_and_field_placeholders(): void
    {
        $data = ['fields' => ['party.name' => 'عميل <b>خطر</b>', 'doc.number' => 'INV-1'], 'items' => array_fill(0, 5, ['name' => 'x']), 'qr' => null];
        $layout = FreeformLayout::sanitize(['elements' => [
            ['type' => 'table', 'x' => 10, 'y' => 10, 'w' => 100, 'h' => 30, 'header_height' => 10, 'row_height' => 10, 'columns' => [['key' => 'name', 'width' => 100]]],
            ['type' => 'text', 'x' => 10, 'y' => 50, 'w' => 80, 'h' => 8, 'content' => 'الإجمالي: {{doc.number}}', 'pages' => 'last'],
            ['type' => 'text', 'x' => 10, 'y' => 60, 'w' => 80, 'h' => 8, 'content' => 'يتبع...', 'pages' => 'not_last'],
            ['type' => 'text', 'x' => 10, 'y' => 70, 'w' => 80, 'h' => 8, 'content' => '{{party.name}} {{page.number}}/{{page.total}}'],
        ]], $this->company->id);

        $pages = app(FreeformRenderer::class)->pages($layout, $data, $this->company);

        $this->assertCount(3, $pages); // 2 rows per page
        $this->assertStringContainsString('يتبع...', $pages[0]);
        $this->assertStringNotContainsString('الإجمالي', $pages[0]);
        $this->assertStringContainsString('الإجمالي: INV-1', $pages[2]);
        $this->assertStringNotContainsString('يتبع...', $pages[2]);
        $this->assertStringContainsString('عميل &lt;b&gt;خطر&lt;/b&gt; 3/3', $pages[2]);
    }

    public function test_vouchers_print_without_a_designed_template(): void
    {
        $payment = CustomerPayment::create(['customer_id' => $this->customer->id, 'payment_number' => 'REC-26-000001', 'payment_date' => '2026-06-15',
            'amount' => 2587.5, 'payment_method' => 'bank_transfer']);

        $fields = app(TemplateData::class)->build($payment)['fields'];
        $this->assertSame('سند قبض', $fields['doc.title']);
        $this->assertSame('2,587.50', $fields['totals.total']);
        $this->assertStringContainsString('ألفان وخمسمائة وسبعة وثمانون', $fields['totals.words']);

        $response = $this->get(route('documents.pdf', ['type' => 'receipt', 'id' => $payment->id]))->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_invoice_fields_cover_the_sample_form(): void
    {
        $fields = app(TemplateData::class)->build($this->invoice())['fields'];

        $this->assertSame('فاتورة ضريبية', $fields['doc.title']);
        $this->assertSame('شركة مجموعة المجال العربي', $fields['party.name']);
        $this->assertSame('2851', $fields['party.building_number']);
        $this->assertSame('2,250.00', $fields['totals.net']);
        $this->assertSame('337.50', $fields['totals.vat']);
        $this->assertSame('2,587.50', $fields['totals.total']);
        $this->assertSame('15%', $fields['totals.vat_rate']);
        $this->assertNotEmpty($fields['doc.hijri_date']);
    }

    public function test_preview_upload_and_tenant_isolation(): void
    {
        Storage::fake('public');
        $template = $this->freeform('quotation');

        $this->post(route('templates.preview', $template), ['freeform' => $template->layout_data['freeform']])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('عرض سعر', $this->get(route('templates.data', $template))->assertOk()->json()['fields']['doc.title']);

        $upload = $this->post(route('templates.images'), ['image' => UploadedFile::fake()->image('stamp.png', 200, 200)])->assertOk()->json();
        $this->assertMatchesRegularExpression('#^templates/'.$this->company->id.'/[A-Za-z0-9]+\.png$#', $upload['src']);
        Storage::disk('public')->assertExists($upload['src']);
        $this->get($upload['url'])->assertOk();
        $this->get(route('templates.image', 'notafile.php'))->assertNotFound();
        $this->get(route('templates.fonts', 'xbriyaz'))->assertOk();
        $this->get(route('templates.fonts', '..%2F..%2F.env'))->assertNotFound();

        $other = Company::factory()->create();
        app(TenantManager::class)->setTenant($other);
        app(AccountingSetupService::class)->provision($other);
        $this->actingAs($this->userWithRole($other));
        $this->get(route('templates.designer', $template->id))->assertNotFound();
        $this->post(route('templates.preview', $template->id), ['freeform' => []])->assertNotFound();
    }
}
