<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Services\HR\LeaveService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Employee self service: own profile, payslips, leave balance and requests.
 */
class SelfServiceController extends Controller
{
    protected function me(): Employee
    {
        return Employee::where('user_id', auth()->id())->firstOr(fn () => abort(404, 'حسابك غير مرتبط بملف موظف. تواصل مع الموارد البشرية.'));
    }

    public function index(LeaveService $leaves)
    {
        $employee = Employee::where('user_id', auth()->id())->first();
        if (! $employee) {
            // Users without an employee file get an explanation, not a 404.
            return Inertia::render('HR/SelfService', ['employee' => null]);
        }
        $employee->load(['departmentRef:id,name', 'position:id,title', 'leaveRequests.type:id,name', 'loans']);
        $leaves->ensureTypes(app(TenantManager::class)->getCompany());

        return Inertia::render('HR/SelfService', [
            'employee' => $employee->only(['id', 'name', 'employee_number', 'job_title', 'hire_date', 'id_expiry', 'passport_expiry', 'iban']) + [
                'department' => $employee->departmentRef?->name, 'position' => $employee->position?->title,
            ],
            'leaveBalance' => $leaves->balance($employee),
            'leaveRequests' => $employee->leaveRequests->sortByDesc('start_date')->values(),
            'loans' => $employee->loans,
            'payslips' => PayrollItem::with('payroll:id,payroll_number,year,month,status')->where('employee_id', $employee->id)
                ->whereHas('payroll', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->latest('id')->limit(24)->get(),
            'leaveTypes' => LeaveType::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function requestLeave(Request $request, LeaveService $leaves)
    {
        $leaves->request($this->me(), $request->validate([
            'leave_type_id' => 'required|tenant_exists:leave_types,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
        ]));

        return back()->with('success', 'تم إرسال طلب الإجازة');
    }

    public function requestLoan(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'monthly_installment' => 'required|numeric|gt:0|lte:amount',
            'reason' => 'nullable|string|max:500',
        ]);
        EmployeeLoan::create($validated + ['employee_id' => $this->me()->id, 'date' => now()->toDateString(), 'first_deduction_month' => now()->addMonth()->startOfMonth()->toDateString(), 'status' => 'pending']);

        return back()->with('success', 'تم إرسال طلب السلفة');
    }

    /** An employee withdraws their own request while it is still pending. */
    public function cancelLeave(LeaveRequest $leave)
    {
        abort_unless((int) $leave->employee_id === (int) $this->me()->id, 404);
        abort_unless($leave->status === 'pending', 422, 'لا يمكن سحب طلب تم البت فيه.');
        $leave->update(['status' => 'cancelled']);

        return back()->with('success', 'تم سحب طلب الإجازة');
    }

    public function cancelLoan(EmployeeLoan $loan)
    {
        abort_unless((int) $loan->employee_id === (int) $this->me()->id, 404);
        abort_unless($loan->status === 'pending', 422, 'لا يمكن سحب طلب تم البت فيه.');
        $loan->update(['status' => 'cancelled']);

        return back()->with('success', 'تم سحب طلب السلفة');
    }

    public function payslip(PayrollItem $item)
    {
        abort_unless((int) $item->employee_id === (int) $this->me()->id && in_array($item->payroll->status, ['approved', 'paid'], true), 404);

        return Inertia::render('HR/Payroll/Payslip', [
            'payroll' => $item->payroll,
            'item' => $item->load('employee:id,name,employee_number,job_title,national_id,iban,bank_name,hire_date'),
            'company' => app(TenantManager::class)->getCompany()?->only(['name', 'tax_number', 'commercial_registration']),
        ]);
    }
}
