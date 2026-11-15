<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Structural guarantees of company isolation that must hold for every future model and route, not only the ones
 * with their own test: a table with company_id has a model that scopes by it, and a route never binds a model that
 * is not scoped unless that is a documented exception with its own check in the controller.
 */
class TenantInvariantsTest extends TestCase
{
    use RefreshDatabase;

    /** Models bound in routes that are platform-level or check the company by hand in their controller. */
    protected const BOUND_WITHOUT_SCOPE = [
        'App\Models\Company' => 'platform: super-admin routes only',
        'App\Models\SubscriptionPlan' => 'platform: super-admin routes only',
        'App\Models\SubscriptionInvoice' => 'tenant routes compare company_id in BillingController; super-admin routes are platform',
        'App\Models\User' => 'UserController::assertCompanyUser / edit() compare company_id',
        'Spatie\Permission\Models\Role' => 'RoleController::assertCompanyRole',
    ];

    /** Tables that carry company_id but are not modelled (pivot or platform bookkeeping), each with the reason. */
    protected const TABLES_WITHOUT_MODEL = [
        'document_sequences' => 'numbering counters, only touched by DocumentNumberService with the company id',
        'numbering_rules' => 'numbering prefixes, only touched by NumberingRules with the company id',
    ];

    /** Models with company_id that are deliberately not auto-scoped, each with the reason. */
    protected const MODELS_WITHOUT_SCOPE = [
        'App\Models\User' => 'accounts are global (login, super admin); every tenant query filters company_id and is covered by TenantSecurityTest',
        'App\Models\SubscriptionInvoice' => 'platform billing: read by the tenant only through BillingController, which compares company_id',
        'App\Models\PlatformAuditLog' => 'platform audit trail (company_id is the subject), read by super admins only',
    ];

    /** Children reached only through a company-scoped parent record. */
    protected const CHILDREN_OF_SCOPED = [
        'App\Models\PortalLoginCode' => 'one-time code of a portal user (portal_users is company-scoped); read by hashed code + portal_user_id',
    ];

    protected function models(): array
    {
        $models = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        return $models;
    }

    public function test_every_model_whose_table_has_company_id_scopes_by_it(): void
    {
        $unscoped = [];
        foreach ($this->models() as $class) {
            $table = (new $class)->getTable();
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id') && ! array_key_exists($class, self::MODELS_WITHOUT_SCOPE) && ! in_array('App\Traits\BelongsToCompany', class_uses_recursive($class), true)) {
                $unscoped[] = $class;
            }
        }
        $this->assertSame([], $unscoped, 'These models have a company_id column but no BelongsToCompany scope: '.implode(', ', $unscoped));
    }

    public function test_every_table_with_company_id_has_a_model(): void
    {
        $modelled = array_map(fn ($c) => (new $c)->getTable(), $this->models());
        $orphans = collect(Schema::getTables())->pluck('name')
            ->filter(fn ($t) => Schema::hasColumn($t, 'company_id') && ! in_array($t, $modelled, true) && ! array_key_exists($t, self::TABLES_WITHOUT_MODEL))->values()->all();
        $this->assertSame([], $orphans, 'Tables with company_id and no Eloquent model (add a scoped model or list it with a reason): '.implode(', ', $orphans));
    }

    public function test_routes_only_bind_scoped_models_or_documented_exceptions(): void
    {
        $unexpected = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('controller');
            if (! $action || ! str_contains($action, '@')) {
                continue;
            }
            [$controller, $method] = explode('@', $action);
            if (! class_exists($controller) || ! method_exists($controller, $method)) {
                continue;
            }
            foreach ((new ReflectionMethod($controller, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (! $type || $type->isBuiltin() || ! is_subclass_of($type->getName(), Model::class)) {
                    continue;
                }
                $class = $type->getName();
                $scoped = in_array('App\Traits\BelongsToCompany', class_uses_recursive($class), true) || method_exists($class, 'tenantParent');
                if (! $scoped && ! array_key_exists($class, self::BOUND_WITHOUT_SCOPE)) {
                    $unexpected[$class][] = $route->getName() ?: $route->uri();
                }
            }
        }
        $this->assertSame([], $unexpected, 'Routes bind models that are not company-scoped: '.json_encode($unexpected, JSON_UNESCAPED_UNICODE));
    }

    public function test_scoped_models_with_a_parent_only_scope_through_it_on_purpose(): void
    {
        // A child without company_id of its own (items, lines) is reached through its scoped parent: it must say so.
        $withoutBoth = [];
        foreach ($this->models() as $class) {
            $table = (new $class)->getTable();
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            $uses = class_uses_recursive($class);
            // Platform and framework tables are not tenant data; children declare tenantParent().
            if (! method_exists($class, 'tenantParent') && ! in_array($class, ['App\Models\Company', 'App\Models\User'], true) && ! array_key_exists($class, self::CHILDREN_OF_SCOPED) && str_starts_with($class, 'App\Models\\') && $this->looksTenantOwned($table)) {
                $withoutBoth[] = $class;
            }
        }
        $this->assertSame([], $withoutBoth, 'Models without company_id or tenantParent(): '.implode(', ', $withoutBoth));
    }

    /** A table is tenant data unless it is platform bookkeeping (no foreign key to a company-scoped table is a good proxy). */
    protected function looksTenantOwned(string $table): bool
    {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if (Schema::hasColumn($fk['foreign_table'], 'company_id')) {
                return true;
            }
        }

        return false;
    }
}
