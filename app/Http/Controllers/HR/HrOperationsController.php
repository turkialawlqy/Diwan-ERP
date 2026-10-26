<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\BankAccount;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EndOfServiceSettlement;
use App\Models\JobPosition;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\WorkShift;
use App\Services\HR\AttendanceService;
use App\Services\HR\EmployeeFinanceService;
use App\Services\HR\LeaveService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Organisation, attendance, leave, loans and end-of-service settlements.
 */
class HrOperationsController extends Controller
{
    // ----- Organisation -----

    public function organization()
    {
        return Inertia::render('HR/Organization', [
            'departments' => Department::with(['parent:id,name', 'costCenter:id,code,name_ar'])->withCount('employees')->orderBy('name')->get(),
            'positions' => JobPosition::orderBy('title')->get(),
            'shifts' => WorkShift::orderBy('name')->get(),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
        ]);
    }

    public function storeDepartment(Request $request)
    {
        Department::create($request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|tenant_exists:departments,id',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
        ]));

        return back()->with('success', 'تمت إضافة الإدارة');
    }

    public function storePosition(Request $request)
    {
        JobPosition::create($request->validate(['title' => 'required|string|max:255', 'grade' => 'nullable|string|max:30']));

        return back()->with('success', 'تمت إضافة المسمى الوظيفي');
    }

    public function storeShift(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'break_minutes' => 'required|integer|min:0|max:240',
            'grace_minutes' => 'required|integer|min:0|max:120',
            'work_days' => 'required|array|min:1',
            'work_days.*' => 'integer|between:1,7',
            'is_default' => 'boolean',
        ]);
        if (! empty($validated['is_default'])) {
            WorkShift::where('is_default', true)->update(['is_default' => false]);
        }
        WorkShift::create($validated);

        return back()->with('success', 'تمت إضافة الوردية');
    }

    // ----- Attendance -----

    public function attendance(Request $request)
    {
        $date = Carbon::parse($request->input('date', now()->toDateString()));

        return Inertia::render('HR/Attendance', [
            'date' => $date->toDateString(),
            'employees' => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'employee_number', 'shift_id']),
            'records' => AttendanceRecord::whereDate('date', $date)->get()->keyBy('employee_id'),
            'summary' => AttendanceRecord::whereYear('date', $date->year)->whereMonth('date', $date->month)
                ->selectRaw('status, COUNT(*) as total, SUM(late_minutes) as late, SUM(overtime_minutes) as overtime')->groupBy('status')->get(),
        ]);
    }

    public function storeAttendance(Request $request, AttendanceService $service)
    {
        $validated = $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'rows' => 'required|array|max:2000',
            'rows.*.employee_id' => 'required|tenant_exists:employees,id',
            'rows.*.check_in' => 'nullable|date_format:H:i',
            'rows.*.check_out' => 'nullable|date_format:H:i',
            'rows.*.status' => 'nullable|in:present,absent,leave,holiday,weekend',
        ]);

        foreach ($validated['rows'] as $row) {
            $service->record(Employee::find($row['employee_id']), $validated['date'], $row['check_in'] ?? null, $row['check_out'] ?? null, 'manual', $row['status'] ?? null);
        }

        return back()->with('success', 'تم حفظ الحضور');
    }

    public function importAttendance(Request $request, AttendanceService $service)
    {
        $request->validate(['file' => 'required|file|max:5120|mimes:csv,txt']);
        $result = $service->import(file_get_contents($request->file('file')->getRealPath()));

        return back()->with('success', "تم استيراد {$result['imported']} سجل".($result['errors'] ? ' مع '.count($result['errors']).' أخطاء' : ''));
    }

    // ----- Leave -----

    public function leaves(Request $request)
    {
        $this->ensureLeaveTypes();
        $status = $request->input('status', 'pending');

        return Inertia::render('HR/Leaves', [
            'requests' => LeaveRequest::with(['employee:id,name,employee_number', 'type:id,name'])
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest('start_date')->paginate($this->perPage($request))->withQueryString(),
            'types' => LeaveType::orderBy('id')->get(),
            'employees' => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'status' => $status,
        ]);
    }

    public function storeLeave(Request $request, LeaveService $leaves)
    {
        $validated = $request->validate([
            'employee_id' => 'required|tenant_exists:employees,id',
            'leave_type_id' => 'required|tenant_exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
        ]);
        $leaves->request(Employee::findOrFail($validated['employee_id']), $validated);

        return back()->with('success', 'تم تسجيل طلب الإجازة');
    }

    public function decideLeave(Request $request, LeaveRequest $leave, LeaveService $leaves)
    {
        $note = $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null;
        $leaves->decide($leave, $request->boolean('approve'), $note);

        return back()->with('success', $request->boolean('approve') ? 'تم اعتماد الإجازة' : 'تم رفض الإجازة');
    }

    // ----- Loans -----

    public function loans(Request $request)
    {
        return Inertia::render('HR/Loans', [
            'loans' => EmployeeLoan::with('employee:id,name,employee_number')->latest('id')->paginate($this->perPage($request)),
            'employees' => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'total_salary']),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    public function storeLoan(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|tenant_exists:employees,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|gt:0',
            'monthly_installment' => 'required|numeric|gt:0|lte:amount',
            'first_deduction_month' => 'required|date',
            'reason' => 'nullable|string|max:500',
        ]);
        EmployeeLoan::create($validated + ['status' => 'pending']);

        return back()->with('success', 'تم تسجيل طلب السلفة');
    }

    public function decideLoan(Request $request, EmployeeLoan $loan, EmployeeFinanceService $finance)
    {
        if ($request->boolean('approve')) {
            $finance->approveLoan($loan, $request->integer('bank_account_id') ?: null);

            return back()->with('success', 'تم اعتماد وصرف السلفة');
        }
        abort_unless($loan->status === 'pending', 422);
        EmployeeFinanceService::assertNotOwnRequest($loan->employee);
        $loan->update(['status' => 'rejected', 'decision_note' => $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null, 'approved_by' => auth()->id()]);

        return back()->with('success', 'تم رفض السلفة');
    }

    // ----- End of service -----

    public function settlements(Request $request, EmployeeFinanceService $finance)
    {
        return Inertia::render('HR/Settlements', [
            'settlements' => EndOfServiceSettlement::with('employee:id,name,employee_number')->latest('id')->paginate($this->perPage($request)),
            'employees' => Employee::where('status', '!=', 'terminated')->orderBy('name')->get(['id', 'name', 'hire_date']),
            'reasons' => EmployeeFinanceService::REASONS,
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    public function storeSettlement(Request $request, EmployeeFinanceService $finance)
    {
        $validated = $request->validate([
            'employee_id' => 'required|tenant_exists:employees,id',
            'termination_date' => 'required|date',
            'reason' => 'required|in:'.implode(',', array_keys(EmployeeFinanceService::REASONS)),
            'salary_days' => 'nullable|numeric|min:0|max:31',
            'other_dues' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);
        $finance->draftSettlement(Employee::findOrFail($validated['employee_id']), $validated);

        return back()->with('success', 'تم احتساب المخالصة كمسودة');
    }

    public function approveSettlement(EndOfServiceSettlement $settlement, EmployeeFinanceService $finance)
    {
        $finance->approveSettlement($settlement);

        return back()->with('success', 'تم اعتماد المخالصة وإنهاء خدمة الموظف');
    }

    public function paySettlement(Request $request, EndOfServiceSettlement $settlement, EmployeeFinanceService $finance)
    {
        $validated = $request->validate(['date' => 'required|date', 'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id']);
        $finance->paySettlement($settlement, $validated['bank_account_id'] ?? null, $validated['date']);

        return back()->with('success', 'تم صرف المخالصة');
    }

    public function accrueProvision(Request $request, EmployeeFinanceService $finance)
    {
        $validated = $request->validate(['date' => 'required|date']);
        $difference = $finance->accrueProvision(Carbon::parse($validated['date']));
        $leave = $finance->accrueLeaveProvision(Carbon::parse($validated['date']));

        return back()->with('success', 'تم تحديث مخصص نهاية الخدمة بفرق '.number_format($difference, 2).' ومخصص الإجازات بفرق '.number_format($leave, 2));
    }

    public function ensureLeaveTypes(): void
    {
        app(LeaveService::class)->ensureTypes(app(TenantManager::class)->getCompany());
    }
}
