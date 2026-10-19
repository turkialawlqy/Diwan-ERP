<?php

namespace App\Services\Saas;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLoan;
use App\Models\Equipment;
use App\Models\EquipmentRental;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\LetterOfGuarantee;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\ZatcaDevice;
use App\Models\ZatcaDocument;
use App\Notifications\ErpAlert;
use App\Services\Equipment\MaintenanceTracker;
use App\Services\Zatca\EInvoiceManager;

/**
 * Daily digest of what needs attention in a company, sent to the users who
 * hold the related permission (run inside the company's tenant context).
 */
class AlertService
{
    public function run(Company $company): int
    {
        $sent = 0;
        foreach ($this->collect() as $alert) {
            $users = User::where('company_id', $company->id)->where('status', 'active')->get()
                ->filter(fn (User $u) => $u->can($alert['permission']));
            foreach ($users as $user) {
                $user->notify(new ErpAlert($alert['kind'], $alert['title'], $alert['body'], $alert['url'], $alert['level']));
                $sent++;
            }
        }

        return $sent;
    }

    public function collect(): array
    {
        $alerts = [];
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        $overdue = Invoice::where('remaining_amount', '>', 0)->whereNotNull('due_date')->whereDate('due_date', '<', $today)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(remaining_amount), 0) s')->first();
        if ($overdue->c > 0) {
            $alerts[] = $this->alert('overdue_invoices', 'sales.invoices.view', 'فواتير متأخرة السداد', "{$overdue->c} فاتورة متأخرة بمبلغ ".number_format((float) $overdue->s, 2), route('accounting.reports.aging', [], false), 'warning');
        }

        $expiringDocs = EmployeeDocument::whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $soon)->whereHas('employee', fn ($q) => $q->where('status', '!=', 'terminated'))->count()
            + Employee::where('status', '!=', 'terminated')->whereNotNull('id_expiry')->whereDate('id_expiry', '<=', $soon)->count();
        if ($expiringDocs) {
            $alerts[] = $this->alert('expiring_documents', 'hr.employees.view', 'وثائق موظفين تنتهي قريباً', "{$expiringDocs} وثيقة (هوية/إقامة/جواز/عقد) تنتهي خلال 30 يوماً", route('hr.employees.index', [], false), 'warning');
        }

        // Art. 55 notice before a fixed-term contract ends, and the probation decision (Art. 53).
        $active = fn () => Employee::where('status', '!=', 'terminated');
        $contracts = $active()->where('contract_type', 'limited')->whereNotNull('contract_end')
            ->whereDate('contract_end', '>=', $today)->whereDate('contract_end', '<=', now()->addDays(60)->toDateString())->count();
        if ($contracts) {
            $alerts[] = $this->alert('contracts_ending', 'hr.employees.view', 'عقود محددة المدة تنتهي قريباً', "{$contracts} عقد ينتهي خلال 60 يوماً؛ قرّر التجديد أو أشعر الموظف", route('hr.employees.index', [], false), 'warning');
        }
        $probation = $active()->whereNotNull('probation_end')->whereDate('probation_end', '>=', $today)->whereDate('probation_end', '<=', now()->addDays(14)->toDateString())->count();
        if ($probation) {
            $alerts[] = $this->alert('probation_ending', 'hr.employees.view', 'فترات تجربة تنتهي قريباً', "{$probation} موظف تنتهي فترة تجربته خلال 14 يوماً", route('hr.employees.index', [], false));
        }
        $gosiMissing = $active()->where('nationality', 'SA')->where('gosi_registered', true)->where(fn ($q) => $q->whereNull('gosi_number')->orWhere('gosi_number', ''))->count();
        if ($gosiMissing) {
            $alerts[] = $this->alert('gosi_numbers', 'hr.employees.view', 'موظفون بلا رقم اشتراك في التأمينات', "{$gosiMissing} موظف سعودي مسجل في التأمينات دون رقم اشتراك", route('hr.employees.index', [], false));
        }

        $guarantees = LetterOfGuarantee::where('status', 'active')->whereDate('expiry_date', '<=', $soon)->count();
        if ($guarantees) {
            $alerts[] = $this->alert('expiring_guarantees', 'projects.guarantees.view', 'خطابات ضمان تنتهي قريباً', "{$guarantees} خطاب ضمان ينتهي خلال 30 يوماً", route('projects.guarantees.index', [], false), 'warning');
        }

        $reorder = Product::where('track_inventory', true)->whereNotNull('reorder_level')
            ->whereRaw('(SELECT COALESCE(SUM(quantity), 0) FROM inventory_stocks WHERE inventory_stocks.product_id = products.id) <= products.reorder_level')->count();
        if ($reorder) {
            $alerts[] = $this->alert('reorder', 'inventory.reports.view', 'أصناف بلغت حد إعادة الطلب', "{$reorder} صنف بحاجة لإعادة الطلب", route('inventory.reports.reorder', [], false));
        }

        $approvals = [
            ['sales.orders.approve', SalesOrder::where('status', 'pending_approval')->count(), 'أوامر بيع', 'sales.orders.index'],
            ['purchases.orders.approve', PurchaseOrder::where('status', 'pending_approval')->count(), 'أوامر شراء', 'purchases.orders.index'],
            ['purchases.requests.approve', PurchaseRequest::where('status', 'pending_approval')->count(), 'طلبات شراء', 'purchases.requests.index'],
            ['hr.leaves.approve', LeaveRequest::where('status', 'pending')->count(), 'طلبات إجازة', 'hr.leaves.index'],
            ['hr.loans.approve', EmployeeLoan::where('status', 'pending')->count(), 'طلبات سلف', 'hr.loans.index'],
        ];
        foreach ($approvals as [$permission, $count, $label, $routeName]) {
            if ($count) {
                $alerts[] = $this->alert('pending_'.$routeName, $permission, "{$label} بانتظار اعتمادك", "{$count} من {$label} بانتظار الاعتماد", route($routeName, [], false));
            }
        }

        $zatca = ZatcaDocument::whereIn('status', ['rejected', 'failed'])->count();
        if ($zatca) {
            $alerts[] = $this->alert('zatca', 'admin.zatca.view', 'فواتير إلكترونية تحتاج متابعة', "{$zatca} مستند مرفوض أو تعذر إرساله لهيئة الزكاة", route('zatca.index', [], false), 'danger');
        }

        // Simplified invoices must be reported within 24 hours.
        $overdue = ZatcaDocument::where('submission', 'reporting')->whereIn('status', ['pending', 'failed'])->where('created_at', '<', EInvoiceManager::reportingDeadline())->count();
        if ($overdue) {
            $alerts[] = $this->alert('zatca_overdue', 'admin.zatca.view', 'فواتير مبسطة تجاوزت مهلة الإبلاغ', "{$overdue} فاتورة مبسطة لم تُبلَّغ للهيئة خلال 24 ساعة", route('zatca.index', [], false), 'danger');
        }

        // Automatic resubmission gives up after 20 attempts: someone must look.
        $stuck = ZatcaDocument::whereIn('status', ['pending', 'failed'])->where('attempts', '>=', 20)->count();
        if ($stuck) {
            $alerts[] = $this->alert('zatca_stuck', 'admin.zatca.view', 'مستندات توقف إرسالها للهيئة', "{$stuck} مستند استنفد محاولات الإرسال التلقائي", route('zatca.index', [], false), 'danger');
        }

        // A device whose certificate expires stops signing invoices.
        foreach (ZatcaDevice::where('status', 'active')->whereNotNull('certificate_expires_at')->where('certificate_expires_at', '<=', now()->addDays(30))->get() as $device) {
            $days = (int) max(0, now()->startOfDay()->diffInDays($device->certificate_expires_at->copy()->startOfDay(), false));
            $alerts[] = $this->alert('zatca_certificate_'.$device->id, 'admin.zatca.view', 'شهادة ZATCA تقترب من الانتهاء',
                "شهادة جهاز «{$device->name}» تنتهي خلال {$days} يوم. جدّدها من شاشة الفوترة الإلكترونية قبل توقف الإصدار.", route('zatca.index', [], false), $days <= 7 ? 'danger' : 'warning');
        }

        $followUps = Lead::whereNotIn('status', ['won', 'lost'])->whereDate('next_follow_up', '<=', $today)->count();
        if ($followUps) {
            $alerts[] = $this->alert('crm_followups', 'crm.leads.view', 'متابعات عملاء محتملين مستحقة', "{$followUps} متابعة مستحقة اليوم", route('crm.leads.index', [], false));
        }

        array_push($alerts, ...$this->equipment($today));

        return $alerts;
    }

    /** Fleet: maintenance due, contracts ending, rented equipment without readings, parts short. */
    protected function equipment(string $today): array
    {
        $alerts = [];
        $due = app(MaintenanceTracker::class)->dueList();
        if ($due->isEmpty() && ! EquipmentRental::where('status', 'active')->exists()) {
            return [];
        }

        $overdue = $due->where('status.state', 'overdue');
        if ($overdue->count()) {
            $names = $overdue->map(fn ($r) => $r['equipment']->code.': '.$r['schedule']->name)->take(5)->implode('، ');
            $alerts[] = $this->alert('equipment_overdue', 'equipment.maintenance.view', 'صيانة دورية متأخرة', "{$overdue->count()} بند صيانة متأخر ({$names})", route('equipment.maintenance.dashboard', [], false), 'danger');
        }
        $soon = $due->where('status.state', 'due_soon');
        if ($soon->count()) {
            $alerts[] = $this->alert('equipment_due_soon', 'equipment.maintenance.view', 'صيانة دورية تستحق قريباً', "{$soon->count()} بند صيانة يقترب موعده", route('equipment.maintenance.dashboard', [], false), 'warning');
        }

        $ending = EquipmentRental::where('status', 'active')->whereNotNull('end_date')->whereDate('end_date', '<=', now()->addDays(7)->toDateString())->count();
        if ($ending) {
            $alerts[] = $this->alert('equipment_rentals_ending', 'equipment.rentals.view', 'عقود تأجير معدات تنتهي قريباً', "{$ending} عقد ينتهي خلال 7 أيام", route('equipment.rentals.index', [], false), 'warning');
        }

        $silent = Equipment::whereIn('status', ['rented', 'internal'])->where('meter_type', '!=', 'none')
            ->where(fn ($q) => $q->whereNull('last_reading_date')->orWhereDate('last_reading_date', '<', now()->subDays(2)->toDateString()))->count();
        if ($silent) {
            $alerts[] = $this->alert('equipment_no_readings', 'equipment.readings.create', 'معدات عاملة بلا قراءات', "{$silent} معدة مؤجرة أو في مشروع بلا قراءة عداد منذ يومين", route('equipment.readings.index', [], false), 'warning');
        }

        // Parts needed by due items that the stock cannot cover.
        $needed = $due->filter(fn ($r) => $r['schedule']->product_id)->groupBy(fn ($r) => $r['schedule']->product_id)
            ->map(fn ($g) => $g->sum(fn ($r) => $r['schedule']->default_quantity));
        $short = $needed->filter(fn ($qty, $productId) => (float) InventoryStock::where('product_id', $productId)->sum('quantity') < $qty)->count();
        if ($short) {
            $alerts[] = $this->alert('equipment_parts_short', 'inventory.products.view', 'قطع غيار صيانة غير كافية', "{$short} صنف لا يكفي رصيده لبنود الصيانة المستحقة", route('equipment.maintenance.dashboard', [], false), 'warning');
        }

        return $alerts;
    }

    protected function alert(string $kind, string $permission, string $title, string $body, ?string $url, string $level = 'info'): array
    {
        return compact('kind', 'permission', 'title', 'body', 'url', 'level');
    }
}
