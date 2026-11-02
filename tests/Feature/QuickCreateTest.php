<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingSetupService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "إنشاء «...»" option of every master-data dropdown posts the entity's own
 * store route as an AJAX call and gets the new record back.
 */
class QuickCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    public function test_every_creatable_entity_answers_json_with_its_record(): void
    {
        $unit = Unit::create(['name_ar' => 'قطعة', 'code' => 'PCS']);
        $category = Category::create(['name_ar' => 'عام', 'is_active' => true]);
        Warehouse::create(['name_ar' => 'رئيسي', 'code' => 'W1', 'is_main' => true, 'is_active' => true]);

        $cases = [
            'inventory.warehouses.store' => ['name_ar' => 'مستودع جدة', 'code' => 'JED'],
            'inventory.categories.store' => ['name_ar' => 'أدوات'],
            'inventory.units.store' => ['name_ar' => 'كرتون'],
            'purchases.suppliers.store' => ['name' => 'مورد سريع'],
            'finance.banks.store' => ['name' => 'صندوق فرعي', 'type' => 'cash', 'currency' => 'SAR', 'opening_balance' => 0],
            'accounting.accounts.store' => ['code' => '599901', 'name_ar' => 'حساب جديد', 'type' => 'expense', 'nature' => 'debit'],
            'accounting.cost-centers.store' => ['code' => 'CC9', 'name_ar' => 'مركز جديد'],
            'hr.departments.store' => ['name' => 'إدارة جديدة'],
            'hr.positions.store' => ['title' => 'مسمى جديد'],
            'hr.shifts.store' => ['name' => 'مسائية', 'start_time' => '16:00', 'end_time' => '23:00', 'break_minutes' => 30, 'grace_minutes' => 10, 'work_days' => [7, 1, 2]],
            'manufacturing.work-centers.store' => ['code' => 'WC9', 'name' => 'مركز', 'type' => 'machine', 'capacity_hours_per_day' => 8, 'efficiency' => 100, 'labour_rate_per_hour' => 0, 'overhead_rate_per_hour' => 0],
            'accounting.fixed-assets.categories.store' => ['name_ar' => 'سيارات', 'useful_life_months' => 60, 'depreciation_method' => 'straight_line'],
            'hr.employees.store' => ['name' => 'موظف سريع', 'nationality' => 'SA', 'id_type' => 'national_id', 'job_title' => 'محاسب', 'hire_date' => '2026-01-01', 'contract_type' => 'unlimited', 'basic_salary' => 5000, 'status' => 'active'],
            'inventory.products.store' => ['name_ar' => 'صنف سريع', 'selling_price' => 10, 'category_id' => $category->id, 'unit_id' => $unit->id],
        ];

        foreach ($cases as $route => $data) {
            $response = $this->postJson(route($route), $data);
            $response->assertCreated();
            $body = $response->json();
            $record = $body['record'] ?? $body['category'] ?? $body['supplier'] ?? $body['product'] ?? null;
            $this->assertNotNull($record, "[$route] did not return the new record");
            $this->assertNotEmpty($record['id'], "[$route] record has no id");
        }
    }

    public function test_lookup_lists_return_id_and_label(): void
    {
        Unit::create(['name_ar' => 'قطعة', 'code' => 'PCS']);
        $this->getJson(route('lookup.list', 'units'))->assertOk()->assertJsonPath('data.0.label', 'قطعة');
        $this->getJson(route('lookup.list', 'accounts'))->assertOk()->assertJsonStructure(['data' => [['id', 'label']]]);
        $this->getJson(route('lookup.list', 'nope'))->assertNotFound();
    }

    /** New dropdowns on the master-data lists must use SelectCreate (or be a documented filter). */
    public function test_master_data_dropdowns_use_select_create(): void
    {
        $lists = ['warehouses', 'branchWarehouses', 'bankAccounts', 'boxes', 'accounts', 'expenseAccounts', 'revenueAccounts', 'chartAccounts', 'costCenters', 'categories', 'categoriesList', 'units', 'unitsList', 'employees', 'departments', 'positions', 'shifts', 'workCenters', 'suppliers', 'products', 'customers', 'parentAccounts'];
        // Report filters and fixed lists are not creation points (the portal customer only picks a category, staff own them; the timesheet's technician is a report filter).
        $allowed = ['Reports/', 'Accounting/Mappings', 'TrialBalance', 'Ledger/Index', 'IncomeStatement', 'POS/Terminal', 'VatFields', 'Equipment/Index', 'Products/Index', 'Reconciliations', 'EquipmentForm', 'Inventory/Reports', 'Portal/TicketForm', 'WorkOrders/Timesheet'];

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'vue' || collect($allowed)->contains(fn ($a) => str_contains($file->getPathname(), $a))) {
                continue;
            }
            preg_match_all('/<select\b[^>]*>.*?<\/select>/s', file_get_contents($file->getPathname()), $blocks);
            foreach ($blocks[0] as $block) {
                if (preg_match('/v-for="\w+ in (\w+)/', $block, $m) && in_array($m[1], $lists, true)) {
                    $offenders[] = str_replace(resource_path('js').'/', '', $file->getPathname()).' ('.$m[1].')';
                }
            }
        }
        $this->assertSame([], $offenders, "Use <SelectCreate entity=\"...\"> instead of a bare <select> for:\n".implode("\n", $offenders));
    }

    public function test_select_create_entities_exist_in_the_registry(): void
    {
        $registry = file_get_contents(resource_path('js/quickCreate.js'));
        preg_match_all('/^    (\w+): \{$/m', $registry, $keys);
        $known = array_merge($keys[1], ['customer']);

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }
            preg_match_all('/<SelectCreate[^>]*\bentity="(\w+)"/', file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $entity) {
                $this->assertContains($entity, $known, "Unknown quick-create entity [$entity] in {$file->getPathname()}");
            }
        }
    }

    /** Dates use the app's DatePicker and numbers a data-num text field (Western digits) — never the browser's own widgets. */
    public function test_no_native_date_or_number_inputs(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'vue' || str_ends_with($file->getPathname(), 'UI/DatePicker.vue')) {
                continue;
            }
            if (preg_match('/<input\b[^>]*\btype="(date|month|number)"/s', file_get_contents($file->getPathname()), $m)) {
                $offenders[] = str_replace(resource_path('js').'/', '', $file->getPathname()).' ('.$m[1].')';
            }
        }
        $this->assertSame([], $offenders, "Use <DatePicker> for dates and type=\"text\" inputmode=\"decimal\" data-num for numbers:\n".implode("\n", $offenders));
    }

    /** Create / edit pages ask before losing unsaved changes. */
    public function test_form_pages_use_the_unsaved_changes_guard(): void
    {
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js/Pages')));
        foreach ($files as $file) {
            if (! preg_match('/(Create|Edit|Form)\.vue$/', $file->getFilename()) || str_contains($file->getPathname(), 'Profile/Partials')) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            // Pages built on useForm (not the axios-based customer pages) must call the guard.
            if (preg_match('/const \w+ = useForm\(/', $code) && ! str_contains($code, 'useUnsavedGuard(')) {
                $missing[] = str_replace(resource_path('js/Pages').'/', '', $file->getPathname());
            }
        }
        $this->assertSame([], $missing, "Add useUnsavedGuard(form) to:\n".implode("\n", $missing));
    }

    /** Native selects keep room for their arrow on the end side: app.css gives it unless the select's own padding classes take it away. */
    public function test_selects_leave_room_for_the_arrow(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            preg_match_all("/const (\w+) = '([^']*)'/", $code, $consts);
            $consts = array_combine($consts[1], $consts[2]);
            // Quote-aware, so a "=>" inside an attribute does not end the tag.
            preg_match_all('/<select\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', $code, $tags, PREG_OFFSET_CAPTURE);
            foreach ($tags[0] as [$tag, $offset]) {
                // Its classes: class="...", plus the quoted strings and the `const` names used in :class="...".
                preg_match('/(?:^|\s)class="([^"]*)"/', $tag, $static);
                preg_match('/:class="([^"]*)"/', $tag, $bound);
                preg_match_all("/'([^']*)'/", $bound[1] ?? '', $quoted);
                preg_match_all('/\b[A-Za-z_]\w*\b/', preg_replace("/'[^']*'/", '', $bound[1] ?? ''), $names);
                $classes = implode(' ', [$static[1] ?? '', ...$quoted[1], ...array_map(fn ($n) => $consts[$n] ?? '', $names[0])]);
                if (str_contains($classes, 'bg-none')) {
                    continue;
                }
                // The end side: pe-* beats px-*, which beats p-* (Tailwind's order). pl-/pr- cannot follow the direction.
                $end = null;
                foreach (['p', 'px', 'pe'] as $prefix) {
                    if (preg_match('/(?:^|[\s:])'.$prefix.'-([\d.]+)(?=\s|$)/', $classes, $m)) {
                        $end = (float) $m[1];
                    }
                }
                if (($end !== null && $end < 6) || preg_match('/(?:^|[\s:])p[lr]-/', $classes)) {
                    $offenders[] = str_replace(resource_path('js').'/', '', $file->getPathname()).':'.(substr_count(substr($code, 0, $offset), "\n") + 1);
                }
            }
        }
        $this->assertSame([], $offenders, "Leave a select's horizontal padding to app.css (2.5rem for the arrow), or give the end side pe-6 or more (ps-/pe-, not pl-/pr-):\n".implode("\n", $offenders));
    }
}
