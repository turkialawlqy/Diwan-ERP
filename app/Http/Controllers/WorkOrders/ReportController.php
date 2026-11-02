<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TimeEntry;
use App\Models\Warehouse;
use App\Services\WorkOrders\WorkOrderSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Timesheet report and the module settings. */
class ReportController extends Controller
{
    public function timesheet(Request $request): Response
    {
        $f = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'employee' => 'nullable|integer']);
        $from = Carbon::parse($f['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($f['to'] ?? now());

        $rows = TimeEntry::with(['employee:id,name', 'workOrder:id,number,title'])->whereNotNull('ended_at')
            ->whereDate('started_at', '>=', $from)->whereDate('started_at', '<=', $to)
            ->when($f['employee'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->orderBy('started_at')->get();

        return Inertia::render('WorkOrders/Timesheet', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'employee' => $f['employee'] ?? ''],
            'employees' => Employee::orderBy('name')->limit(500)->get(['id', 'name']),
            'entries' => $rows->map(fn ($e) => ['id' => $e->id, 'date' => $e->started_at->toDateString(), 'employee' => $e->employee?->name, 'order' => $e->workOrder?->number, 'title' => $e->workOrder?->title,
                'minutes' => $e->minutes, 'billable' => $e->billable, 'cost' => (float) $e->labour_cost, 'auto_closed' => $e->auto_closed])->values(),
            'byEmployee' => $rows->groupBy('employee_id')->map(fn ($g) => ['name' => $g->first()->employee?->name, 'minutes' => (int) $g->sum('minutes'), 'billable_minutes' => (int) $g->where('billable', true)->sum('minutes'), 'cost' => round((float) $g->sum('labour_cost'), 2)])->values(),
        ]);
    }

    public function settings(WorkOrderSettings $settings): Response
    {
        return Inertia::render('WorkOrders/Settings', ['settings' => $settings->all(), 'warehouses' => Warehouse::orderBy('name_ar')->get(['id', 'name_ar'])]);
    }

    public function updateSettings(Request $request, WorkOrderSettings $settings)
    {
        $data = $request->validate(['require_checklist' => 'nullable|boolean', 'require_signature' => 'nullable|boolean', 'default_warehouse' => 'nullable|tenant_exists:warehouses,id', 'stale_timer_hours' => 'required|integer|min:1|max:72']);
        $settings->save($data);

        return back()->with('success', 'تم حفظ إعدادات أوامر العمل');
    }
}
