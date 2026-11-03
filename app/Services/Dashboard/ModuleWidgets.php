<?php

namespace App\Services\Dashboard;

use App\Models\AdminRequestLine;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractObligation;
use App\Models\CustomerSubscription;
use App\Models\CustomerVehicle;
use App\Models\EmployeeLoan;
use App\Models\EquipmentRental;
use App\Models\Lead;
use App\Models\Lease;
use App\Models\LeaveRequest;
use App\Models\MaintenanceOrder;
use App\Models\MemberCheckin;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use App\Models\PosOrder;
use App\Models\PosSession;
use App\Models\ProductionOrder;
use App\Models\Project;
use App\Models\PropertyUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ActiveBranch;
use App\Services\Saas\PlanGuard;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;

/**
 * One small card per enabled system on the dashboard: a few live numbers, each linking to its page.
 * A system's card shows only when the company, its plan and the working branch run it, the user
 * may open it and has not hidden it. To add a system: one entry in definitions() (CLAUDE.md rule 19).
 */
class ModuleWidgets
{
    public function __construct(protected PlanGuard $guard) {}

    /**
     * module => [title, permission (any of), link route, metrics closure → [[label, value, route?, tone?]]].
     * Tones: neutral | good | warn | danger.
     */
    public function definitions(): array
    {
        $today = fn () => now()->toDateString();

        return [
            'bookings' => ['title' => 'الحجوزات', 'permission' => 'bookings.bookings.view', 'link' => 'bookings.index', 'metrics' => fn () => [
                ['حجوزات اليوم', Booking::whereDate('starts_at', $today())->whereIn('status', ['pending', 'confirmed', 'completed'])->count(), 'bookings.index'],
                ['بانتظار التأكيد', Booking::where('status', 'pending')->count(), 'bookings.index', 'warn'],
                ['لم يحضروا هذا الأسبوع', Booking::where('status', 'no_show')->where('starts_at', '>=', now()->startOfWeek())->count(), null, 'danger'],
            ]],
            'work_orders' => ['title' => 'أوامر العمل', 'permission' => 'work_orders.orders.view', 'link' => 'work-orders.index', 'metrics' => fn () => [
                ['مفتوحة', WorkOrder::whereIn('status', WorkOrder::OPEN)->count(), 'work-orders.index'],
                ['قيد التنفيذ', WorkOrder::where('status', 'in_progress')->count(), 'work-orders.index', 'good'],
                ['بانتظار الفوترة', WorkOrder::where('status', 'done')->count(), 'work-orders.index', 'warn'],
            ]],
            'support' => ['title' => 'خدمة العملاء', 'permission' => 'support.tickets.view', 'link' => 'support.tickets.index', 'metrics' => fn () => [
                ['تذاكر مفتوحة', Ticket::whereIn('status', ['open', 'pending'])->count(), 'support.tickets.index'],
                ['متجاوزة المهلة', Ticket::whereIn('status', ['open', 'pending'])->where(fn ($q) => $q->where('response_breached', true)->orWhere('resolution_breached', true))->count(), 'support.tickets.index', 'danger'],
                ['غير مسندة', Ticket::whereIn('status', ['open', 'pending'])->whereNull('assigned_to')->count(), 'support.tickets.index', 'warn'],
            ]],
            'contracts' => ['title' => 'العقود', 'permission' => 'contracts.contracts.view', 'link' => 'contracts.index', 'metrics' => fn () => [
                ['عقود سارية', Contract::where('status', 'active')->count(), 'contracts.index', 'good'],
                ['تنتهي خلال 30 يومًا', Contract::where('status', 'active')->whereBetween('end_date', [$today(), now()->addDays(30)->toDateString()])->count(), 'contracts.index', 'warn'],
                ['التزامات متأخرة', ContractObligation::where('status', 'pending')->where('due_date', '<', $today())->count(), 'contracts.index', 'danger'],
            ]],
            'subscriptions' => ['title' => 'اشتراكات العملاء', 'module' => 'sales', 'permission' => 'sales.subscriptions.view', 'link' => 'sales.subscriptions.index', 'metrics' => fn () => [
                ['اشتراكات سارية', CustomerSubscription::whereIn('status', ['trial', 'active', 'past_due'])->count(), 'sales.subscriptions.index', 'good'],
                ['الإيراد الشهري المتكرر', number_format(app(SubscriptionService::class)->mrr(), 0), 'sales.subscriptions.index'],
                ['متعثرة', CustomerSubscription::where('status', 'past_due')->count(), 'sales.subscriptions.index', 'danger'],
            ]],
            'hr' => ['title' => 'الموارد البشرية', 'permission' => ['hr.leaves.view', 'hr.admin_requests.view', 'hr.admin_requests.handle'], 'link' => 'hr.leaves.index', 'metrics' => fn () => [
                ['إجازات بانتظار القرار', LeaveRequest::where('status', 'pending')->count(), 'hr.leaves.index', 'warn'],
                ['سلف بانتظار القرار', EmployeeLoan::where('status', 'pending')->count(), 'hr.loans.index', 'warn'],
                ['موظفون مطلوب تبليغهم', AdminRequestLine::whereNotIn('status', AdminRequestLine::FINAL)->whereHas('request', fn ($q) => $q->where('status', '!=', 'draft'))->count(), 'hr.admin-requests.inbox'],
            ]],
            'clinic' => ['title' => 'العيادات', 'permission' => 'clinic.patients.view', 'link' => 'clinic.patients.index', 'metrics' => fn () => [
                ['زيارات اليوم', PatientVisit::whereDate('visit_date', $today())->count(), 'clinic.patients.index', 'good'],
                ['ملفات المرضى', PatientFile::count(), 'clinic.patients.index'],
                ['مراجعات هذا الأسبوع', PatientVisit::whereBetween('next_visit_date', [$today(), now()->addDays(7)->toDateString()])->count(), 'clinic.patients.index'],
            ]],
            'gym' => ['title' => 'النوادي', 'permission' => ['gym.attendance.view', 'gym.checkin.use'], 'link' => 'gym.checkin', 'metrics' => fn () => [
                ['حضور اليوم', MemberCheckin::where('allowed', true)->whereDate('checked_in_at', $today())->count(), 'gym.attendance', 'good'],
                ['مرفوض اليوم', MemberCheckin::where('allowed', false)->whereDate('checked_in_at', $today())->count(), 'gym.attendance', 'danger'],
            ]],
            'workshop' => ['title' => 'الورش', 'permission' => 'workshop.vehicles.view', 'link' => 'workshop.vehicles.index', 'metrics' => fn () => [
                ['مركبات', CustomerVehicle::count(), 'workshop.vehicles.index'],
                ['صيانة مستحقة خلال 14 يومًا', CustomerVehicle::whereNotNull('next_service_date')->where('next_service_date', '<=', now()->addDays(14)->toDateString())->count(), 'workshop.vehicles.index', 'warn'],
            ]],
            'real_estate' => ['title' => 'العقارات', 'permission' => ['real_estate.properties.view', 'real_estate.leases.view'], 'link' => 'real-estate.properties.index', 'metrics' => fn () => [
                ['وحدات مؤجّرة', PropertyUnit::where('status', 'occupied')->count(), 'real-estate.properties.index', 'good'],
                ['وحدات شاغرة', PropertyUnit::where('status', 'vacant')->count(), 'real-estate.properties.index', 'warn'],
                ['عقود تنتهي خلال 60 يومًا', Lease::where('status', 'active')->whereBetween('end_date', [$today(), now()->addDays(60)->toDateString()])->count(), 'real-estate.leases.index', 'warn'],
            ]],
            'projects' => ['title' => 'المقاولات والمشاريع', 'permission' => 'projects.projects.view', 'link' => 'projects.index', 'metrics' => fn () => [
                ['مشاريع جارية', Project::where('status', 'active')->count(), 'projects.index', 'good'],
                ['متوقفة', Project::where('status', 'on_hold')->count(), 'projects.index', 'warn'],
            ]],
            'equipment' => ['title' => 'المعدات', 'permission' => ['equipment.assets.view', 'equipment.rentals.view'], 'link' => 'equipment.index', 'metrics' => fn () => [
                ['تأجير جارٍ', EquipmentRental::where('status', 'active')->count(), 'equipment.rentals.index', 'good'],
                ['أوامر صيانة مفتوحة', MaintenanceOrder::where('status', 'open')->count(), 'equipment.maintenance.index', 'warn'],
            ]],
            'manufacturing' => ['title' => 'المصانع', 'permission' => 'manufacturing.orders.view', 'link' => 'manufacturing.dashboard', 'metrics' => fn () => [
                ['أوامر قيد التنفيذ', ProductionOrder::whereIn('status', ['released', 'in_progress'])->count(), 'manufacturing.orders.index', 'good'],
                ['مسودات', ProductionOrder::where('status', 'draft')->count(), 'manufacturing.orders.index'],
            ]],
            'crm' => ['title' => 'العملاء المحتملون', 'permission' => 'crm.leads.view', 'link' => 'crm.leads.index', 'metrics' => fn () => [
                ['فرص مفتوحة', Lead::whereNotIn('status', ['won', 'lost'])->count(), 'crm.leads.index'],
                ['متابعات مستحقة', Lead::whereNotIn('status', ['won', 'lost'])->whereNotNull('next_follow_up')->where('next_follow_up', '<=', now())->count(), 'crm.leads.index', 'warn'],
            ]],
            'pos' => ['title' => 'نقاط البيع', 'permission' => ['pos.terminal.use', 'pos.history.view'], 'link' => 'pos.history', 'metrics' => fn () => [
                ['مبيعات اليوم', number_format((float) PosOrder::where('status', 'completed')->whereDate('created_at', $today())->sum('total'), 0), 'pos.history', 'good'],
                ['ورديات مفتوحة', PosSession::where('status', 'opened')->count(), 'pos.registers.index'],
            ]],
        ];
    }

    /** Widgets the user may see right now (hidden ones excluded unless $all, for the customise list). */
    public function forUser(User $user, ?Company $company = null, bool $all = false): array
    {
        $company ??= app(TenantManager::class)->getCompany();
        if (! $company) {
            return [];
        }
        $hidden = (array) ($user->dashboard_hidden_widgets ?? []);
        $scope = app(ActiveBranch::class)->scopeKey();
        $out = [];
        foreach ($this->definitions() as $key => $def) {
            if (! $this->guard->hasModuleHere($company, $def['module'] ?? $key) || ! $this->allowed($user, $def['permission'])) {
                continue;
            }
            $isHidden = in_array($key, $hidden, true);
            if ($isHidden && ! $all) {
                continue;
            }
            $out[] = ['key' => $key, 'title' => $def['title'], 'link' => $def['link'], 'hidden' => $isHidden]
                + ($isHidden ? ['metrics' => []] : ['metrics' => $this->metrics($company->id, $scope, $key, $def)]);
        }

        return $out;
    }

    /** Cached per company and branch scope; the per-user filtering happens outside the cache. */
    protected function metrics(int $companyId, string $scope, string $key, array $def): array
    {
        return Cache::remember("dashboard:widgets:{$companyId}:{$scope}:{$key}", now()->addMinutes(5), fn () => collect($def['metrics']())
            ->map(fn ($m) => ['label' => $m[0], 'value' => (string) $m[1], 'route' => $m[2] ?? null, 'tone' => $m[3] ?? 'neutral'])->all());
    }

    protected function allowed(User $user, string|array $permission): bool
    {
        if ($user->isCompanyOwner()) {
            return true;
        }
        foreach ((array) $permission as $p) {
            if ($user->can($p)) {
                return true;
            }
        }

        return false;
    }

    /** Hide / show one widget for the user. */
    public function setHidden(User $user, string $key, bool $hidden): void
    {
        abort_unless(array_key_exists($key, $this->definitions()), 404);
        $list = collect((array) $user->dashboard_hidden_widgets)->reject(fn ($k) => $k === $key);
        if ($hidden) {
            $list->push($key);
        }
        $user->forceFill(['dashboard_hidden_widgets' => $list->values()->all() ?: null])->save();
    }
}
