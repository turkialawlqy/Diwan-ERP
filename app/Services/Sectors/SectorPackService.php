<?php

namespace App\Services\Sectors;

use App\Models\BookableService;
use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ContractTemplate;
use App\Models\ServicePlan;
use App\Models\SupportCategory;
use App\Models\VisitPackage;
use App\Models\WorkType;
use App\Services\Documents\DocumentNumberService;
use App\Services\Saas\PlanGuard;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Support\BranchScopeSwitch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sector packs: seeds a sector's ready-made services, plans, work types, categories and contract templates
 * on top of the generic blocks. Idempotent (matched by name); it only ever switches modules ON.
 */
class SectorPackService
{
    /** What the preview / apply report calls each kind of record. */
    public const KINDS = [
        'resources' => 'موارد حجز', 'bookable_services' => 'خدمات حجز', 'visit_packages' => 'باقات زيارات', 'service_plans' => 'خطط اشتراك',
        'work_types' => 'أنواع أعمال', 'support_categories' => 'تصنيفات دعم', 'contract_templates' => 'نماذج عقود',
    ];

    public function __construct(protected TenantManager $tenants, protected DocumentNumberService $numbers, protected PlanGuard $plans) {}

    public function definitions(): array
    {
        return config('sector_packs.packs');
    }

    public function definition(string $key): array
    {
        $def = $this->definitions()[$key] ?? null;
        if (! $def) {
            throw ValidationException::withMessages(['pack' => 'حزمة غير معروفة.']);
        }

        return $def;
    }

    /** @return array<string,array{label:string,description:string,applied:?array,items:array}> */
    public function overview(Company $company, ?int $branchId = null): array
    {
        $applied = $this->applied($company);
        $suffix = $branchId ? '@'.$branchId : '';

        return collect($this->definitions())->map(fn ($def, $key) => [
            'key' => $key, 'label' => $def['label'], 'description' => $def['description'], 'modules' => $this->moduleLabels($def['modules']),
            'applied' => $applied[$key.$suffix] ?? null, 'items' => $this->preview($key, $branchId),
        ])->values()->all();
    }

    /** The records the pack would create, each flagged when it already exists. */
    public function preview(string $key, ?int $branchId = null): array
    {
        $def = $this->definition($key);
        $out = [];
        foreach (self::KINDS as $kind => $label) {
            foreach ($def[$kind] ?? [] as $row) {
                $name = is_array($row) ? $row['name'] : $row;
                $out[] = ['kind' => $label, 'name' => $name, 'exists' => $this->exists($kind, $name, $branchId)];
            }
        }

        return $out;
    }

    /** @return array{created:array<string,int>,existing:int} */
    public function apply(Company $company, string $key, ?int $branchId = null): array
    {
        $def = $this->definition($key);
        if ($branchId !== null && ! Branch::where('company_id', $company->id)->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'الفرع غير موجود.']);
        }

        // The catalogue is created for one branch (or shared by all, branch NULL) whatever the signed-in user's own scope.
        return BranchScopeSwitch::off(fn () => $this->tenants->runAs($company, function () use ($company, $key, $def, $branchId) {
            return DB::transaction(function () use ($company, $key, $def, $branchId) {
                $created = array_fill_keys(array_keys(self::KINDS), 0);
                $existing = 0;
                $count = function (string $kind, bool $new) use (&$created, &$existing) {
                    $new ? $created[$kind]++ : $existing++;
                };

                $resourceIds = [];
                foreach ($def['resources'] ?? [] as $row) {
                    [$resource, $new] = $this->first($branchId, BookingResource::class, $row['name'], ['type' => $row['type'], 'capacity' => $row['capacity'] ?? 1, 'is_active' => true]);
                    if ($new) {
                        foreach ($row['days'] ?? [] as $day) {
                            foreach ($row['hours'] ?? [] as [$from, $to]) {
                                $resource->hours()->create(['weekday' => $day, 'start_time' => $from, 'end_time' => $to]);
                            }
                        }
                    }
                    $resourceIds[] = $resource->id;
                    $count('resources', $new);
                }

                $serviceIds = [];
                foreach ($def['bookable_services'] ?? [] as $row) {
                    [$service, $new] = $this->first($branchId, BookableService::class, $row['name'], $row + ['tax_rate' => TaxProfile::rate(), 'online_bookable' => true, 'is_active' => true]);
                    $serviceIds[$row['name']] = $service->id;
                    if ($resourceIds && $new) {
                        $service->resources()->syncWithoutDetaching($resourceIds);
                    }
                    $count('bookable_services', $new);
                }

                foreach ($def['visit_packages'] ?? [] as $row) {
                    $serviceId = ! empty($row['service']) ? ($serviceIds[$row['service']] ?? BookableService::where('name', $row['service'])->value('id')) : null;
                    [, $new] = $this->first($branchId, VisitPackage::class, $row['name'], ['bookable_service_id' => $serviceId, 'visits' => $row['visits'], 'price' => $row['price'], 'tax_rate' => TaxProfile::rate(), 'valid_days' => $row['valid_days'] ?? null, 'is_active' => true]);
                    $count('visit_packages', $new);
                }

                foreach ($def['service_plans'] ?? [] as $row) {
                    [, $new] = $this->first($branchId, ServicePlan::class, $row['name'], ['code' => fn () => $this->numbers->next('service_plan'), 'price' => $row['price'], 'tax_rate' => TaxProfile::rate(), 'cycle' => $row['cycle'],
                        'interval' => 1, 'trial_days' => 0, 'booking_discount_percent' => $row['booking_discount_percent'] ?? 0, 'is_active' => true]);
                    $count('service_plans', $new);
                }

                foreach ($def['work_types'] ?? [] as $row) {
                    [, $new] = $this->first($branchId, WorkType::class, $row['name'], $row + ['tax_rate' => TaxProfile::rate(), 'is_active' => true]);
                    $count('work_types', $new);
                }

                foreach ($def['support_categories'] ?? [] as $name) {
                    [, $new] = $this->first($branchId, SupportCategory::class, $name, ['is_active' => true]);
                    $count('support_categories', $new);
                }

                foreach ($def['contract_templates'] ?? [] as $row) {
                    [, $new] = $this->first($branchId, ContractTemplate::class, $row['name'], ['kind' => $row['kind'], 'body' => $row['body'], 'is_active' => true]);
                    $count('contract_templates', $new);
                }

                $this->enable($company, $def['modules'], $branchId);
                $applied = $this->applied($company);
                $applied[$key.($branchId ? '@'.$branchId : '')] = ['version' => config('sector_packs.version'), 'at' => now()->toDateTimeString()];
                $company->setSetting('sector_packs', $applied, 'general', 'json');

                return ['created' => $created, 'existing' => $existing];
            });
        }));
    }

    /** @return array<string,array{version:int,at:string}> */
    public function applied(Company $company): array
    {
        return (array) $company->setting('sector_packs', []);
    }

    /** True while the company has applied no pack (the onboarding checklist asks for one). */
    public function hasNone(Company $company): bool
    {
        return $this->applied($company) === [];
    }

    protected function exists(string $kind, string $name, ?int $branchId = null): bool
    {
        $model = match ($kind) {
            'resources' => BookingResource::class, 'bookable_services' => BookableService::class, 'visit_packages' => VisitPackage::class,
            'service_plans' => ServicePlan::class, 'work_types' => WorkType::class, 'support_categories' => SupportCategory::class, 'contract_templates' => ContractTemplate::class,
        };

        return BranchScopeSwitch::off(fn () => $model::query()->where('name', $name)->when($this->branched($model), fn ($q) => $q->where('branch_id', $branchId))->exists());
    }

    /** Support categories and contract templates stay company-wide; the other catalogues carry a branch. */
    protected function branched(string $model): bool
    {
        return in_array('branch_id', (new $model)->getFillable(), true);
    }

    /** @return array{0:Model,1:bool} the record and whether it was just created */
    protected function first(?int $branchId, string $model, string $name, array $values): array
    {
        $found = $model::query()->when(method_exists($model, 'bootSoftDeletes'), fn ($q) => $q->withTrashed())->where('name', $name)->when($this->branched($model), fn ($q) => $q->where('branch_id', $branchId))->first();
        if ($found) {
            return [$found, false];
        }
        $values = array_map(fn ($v) => $v instanceof \Closure ? $v() : $v, $values) + ($this->branched($model) ? ['branch_id' => $branchId] : []);
        $fillable = (new $model)->getFillable();

        return [$model::create(['name' => $name] + array_intersect_key($values, array_flip($fillable))), true];
    }

    /** Switch the pack's modules on (never anything off). */
    protected function enable(Company $company, array $modules, ?int $branchId = null): void
    {
        $disabled = array_values(array_diff($this->plans->disabledModules($company), $modules));
        $company->setSetting('disabled_modules', $disabled, 'general', 'json');

        // A branch that lists its activities gets the pack's added (a branch with no list already runs them all).
        if ($branchId && ($branch = Branch::find($branchId)) && $branch->modules !== null) {
            $branch->update(['modules' => array_values(array_unique(array_merge($branch->modules, array_intersect($modules, array_keys(config('saas.optional_modules'))))))]);
        }
    }

    protected function moduleLabels(array $keys): array
    {
        return collect($keys)->map(fn ($k) => config("saas.modules.{$k}", $k))->all();
    }
}
