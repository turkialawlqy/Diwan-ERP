<?php

namespace App\Services\Saas;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLoan;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\LetterOfGuarantee;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\ZatcaDocument;
use App\Notifications\ErpAlert;

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

        $followUps = Lead::whereNotIn('status', ['won', 'lost'])->whereDate('next_follow_up', '<=', $today)->count();
        if ($followUps) {
            $alerts[] = $this->alert('crm_followups', 'crm.leads.view', 'متابعات عملاء محتملين مستحقة', "{$followUps} متابعة مستحقة اليوم", route('crm.leads.index', [], false));
        }

        return $alerts;
    }

    protected function alert(string $kind, string $permission, string $title, string $body, ?string $url, string $level = 'info'): array
    {
        return compact('kind', 'permission', 'title', 'body', 'url', 'level');
    }
}
