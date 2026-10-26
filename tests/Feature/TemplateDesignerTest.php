<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PrintTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateDesignerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = $this->userWithRole($this->company);
    }

    public function test_can_view_templates_hub()
    {
        $response = $this->actingAs($this->user)->get(route('templates.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Templates/Index'));
    }

    public function test_can_open_template_designer()
    {
        $template = PrintTemplate::first();
        if (! $template) {
            $template = PrintTemplate::create([
                'company_id' => $this->company->id,
                'name' => 'فاتورة تجريبية',
                'template_type' => 'invoice_a4',
                'primary_color' => '#1e293b',
                'layout_data' => PrintTemplate::getDefaultLayoutData(),
            ]);
        }

        $response = $this->actingAs($this->user)->get(route('templates.designer', $template->id));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Templates/Designer'));
    }

    public function test_can_create_and_duplicate_template()
    {
        $response = $this->actingAs($this->user)->post(route('templates.store'), [
            'name' => 'قالب مخصص لاختبار النظام',
            'template_type' => 'invoice_a4',
            'primary_color' => '#0284c7',
        ]);

        $created = PrintTemplate::where('name', 'قالب مخصص لاختبار النظام')->first();
        $this->assertNotNull($created);
        $this->assertEquals('#0284c7', $created->primary_color);
        $this->assertNotNull($created->layout_data);

        // Test duplicate
        $dupResponse = $this->actingAs($this->user)->post(route('templates.duplicate', $created->id));
        $this->assertDatabaseHas('print_templates', [
            'name' => 'نسخة من '.$created->name,
        ]);

        // Cleanup
        PrintTemplate::where('name', 'like', '%لاختبار النظام%')->delete();
    }
}
