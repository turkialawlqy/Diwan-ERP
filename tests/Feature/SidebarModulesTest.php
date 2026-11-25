<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The sidebar keeps the main pages of every module. Moving the reports into «التقارير» (5f7a301) dropped
 * the whole HR group, so employees and payroll were left reachable only from the «عرض»/«إضافة» menus.
 */
class SidebarModulesTest extends TestCase
{
    /** Module (config/saas.php) => pages the sidebar always links to, in whichever group. */
    private const MAIN_PAGES = [
        'sales' => ['sales.invoices.index', 'sales.quotations.index', 'sales.orders.index', 'sales.returns.index', 'sales.clients.index', 'sales.payments.index'],
        'pos' => ['pos.index', 'pos.history'],
        'inventory' => ['inventory.products.index', 'inventory.warehouses.index', 'inventory.counts.index', 'inventory.stock-transfers.index'],
        'purchases' => ['purchases.orders.index', 'purchases.invoices.index', 'purchases.returns.index', 'purchases.suppliers.index', 'purchases.payments.index'],
        'accounting' => [
            'accounting.accounts.index', 'accounting.journals.index', 'accounting.ledger.index', 'accounting.trial-balance.index',
            'accounting.reports.income-statement', 'accounting.reports.balance-sheet', 'finance.expenses.index', 'finance.revenues.index', 'finance.banks.index',
        ],
        'projects' => ['projects.index'],
        'equipment' => ['equipment.index', 'equipment.rentals.index', 'equipment.maintenance.index'],
        'manufacturing' => ['manufacturing.orders.index', 'manufacturing.boms.index', 'manufacturing.work-centers.index'],
        'hr' => ['hr.employees.index', 'hr.payroll.index', 'hr.attendance.index', 'hr.leaves.index', 'hr.loans.index', 'hr.settlements.index', 'hr.me'],
        'zatca' => ['zatca.index'],
        'crm' => ['crm.leads.index'],
        'support' => ['support.tickets.index'],
        'contracts' => ['contracts.index'],
        'portal' => ['portal.users.index'],
        'bookings' => ['bookings.calendar', 'bookings.index'],
        'work_orders' => ['work-orders.index'],
        'clinic' => ['clinic.patients.index'],
        'gym' => ['gym.checkin'],
        'workshop' => ['workshop.vehicles.index'],
        'real_estate' => ['real-estate.properties.index', 'real-estate.leases.index'],
    ];

    /** Modules without a page of their own (the API tokens and webhooks live on Settings → Integrations). */
    private const WITHOUT_PAGES = ['api'];

    public function test_each_module_keeps_its_main_pages_in_the_sidebar(): void
    {
        $linked = array_merge(...array_values($this->sidebarGroups()));

        foreach (self::MAIN_PAGES as $module => $pages) {
            foreach ($pages as $name) {
                $this->assertContains($name, $linked, "The sidebar lost [{$name}] ({$module}): it would be reachable only from the «عرض»/«إضافة» menus.");
                $this->assertSame($module, $this->moduleOf($name), "[{$name}] is not a page of the {$module} module (config/saas.php route_modules).");
            }
        }
    }

    public function test_every_module_lists_its_main_pages_here(): void
    {
        $this->assertEqualsCanonicalizing(
            array_values(array_diff(array_keys(config('saas.modules')), self::WITHOUT_PAGES)),
            array_keys(self::MAIN_PAGES),
            'config/saas.php gained or lost a module: list its main sidebar pages in MAIN_PAGES.',
        );
    }

    public function test_hr_is_a_group_of_its_own_and_its_reports_stay_under_reports(): void
    {
        $groups = $this->sidebarGroups();

        $this->assertArrayHasKey('hr', $groups, 'The HR group is missing from the sidebar.');
        foreach (['hr.employees.index', 'hr.payroll.index'] as $name) {
            $this->assertContains($name, $groups['hr']);
        }
        foreach (['hr.reports.gosi', 'hr.reports.saudization'] as $report) {
            $this->assertContains($report, $groups['reports']);
            $this->assertNotContains($report, $groups['hr'], "[{$report}] lives under «التقارير» only.");
        }
    }

    public function test_every_sidebar_link_is_a_permission_mapped_route_linked_once(): void
    {
        $linked = array_merge(...array_values($this->sidebarGroups()));
        $map = config('erp_permissions.routes');

        foreach ($linked as $name) {
            $this->assertTrue(Route::has($name), "Sidebar route [{$name}] does not exist.");
            $this->assertArrayHasKey($name, $map, "Sidebar route [{$name}] has no permission mapping (the sidebar hides it for everyone).");
        }
        $twice = array_keys(array_filter(array_count_values($linked), fn ($count) => $count > 1));
        $this->assertSame([], $twice, 'These pages are linked more than once in the sidebar.');
    }

    /** Sidebar group key => the routes it links to (a top-level link is a group of one). */
    private function sidebarGroups(): array
    {
        $nav = file_get_contents(resource_path('js/navigation.js'));
        preg_match_all("/key: '([\\w-]+)'/", $nav, $keys, PREG_OFFSET_CAPTURE);

        $groups = [];
        foreach ($keys[1] as $i => [$key, $at]) {
            $end = $keys[1][$i + 1][1] ?? strlen($nav);
            preg_match_all("/route: '([\\w.-]+)'/", substr($nav, $at, $end - $at), $m);
            $groups[$key] = $m[1];
        }
        $this->assertGreaterThan(10, count($groups), 'navigation.js could not be read');

        return $groups;
    }

    private function moduleOf(string $route): ?string
    {
        foreach (config('saas.route_modules') as $prefix => $module) {
            if (str_starts_with($route, $prefix)) {
                return $module;
            }
        }

        return null;
    }
}
