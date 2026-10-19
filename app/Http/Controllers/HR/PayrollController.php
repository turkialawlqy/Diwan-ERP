<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Services\HR\PayrollService;
use App\Services\Settings\CompanyPolicy;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function __construct(protected PayrollService $payrolls) {}

    public function index(Request $request): Response
    {
        return Inertia::render('HR/Payroll/Index', [
            'payrolls' => Payroll::withCount('items')->orderByDesc('year')->orderByDesc('month')->paginate($this->perPage($request)),
            'stats' => [
                'employees' => Employee::where('status', 'active')->count(),
                'monthly_wages' => (float) Employee::where('status', 'active')->sum('total_salary'),
                'last_net' => (float) Payroll::orderByDesc('year')->orderByDesc('month')->value('net_total'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
            'notes' => 'nullable|string|max:500',
        ]);
        $payroll = $this->payrolls->generate((int) $validated['year'], (int) $validated['month'], $validated['notes'] ?? null);

        return redirect()->route('hr.payroll.show', $payroll->id)->with('success', "تم إعداد مسير الرواتب {$payroll->payroll_number} كمسودة");
    }

    public function show(Payroll $payroll): Response
    {
        return Inertia::render('HR/Payroll/Show', [
            'payroll' => $payroll->load(['items.employee:id,name,employee_number,nationality,iban']),
            'adjustments' => EmployeeAdjustment::with('employee:id,name')->where('year', $payroll->year)->where('month', $payroll->month)->get(),
            'employees' => Employee::where('status', '!=', 'terminated')->orderBy('name')->get(['id', 'name']),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    public function recalculate(Payroll $payroll)
    {
        $this->payrolls->recalculate($payroll);

        return back()->with('success', 'تم إعادة احتساب المسير');
    }

    public function approve(Payroll $payroll)
    {
        $this->payrolls->approve($payroll);

        return back()->with('success', 'تم اعتماد المسير وقيد الاستحقاق');
    }

    public function unapprove(Payroll $payroll)
    {
        $this->payrolls->unapprove($payroll);

        return back()->with('success', 'أُلغي اعتماد المسير وعُكس قيده؛ يمكنك تعديله وإعادة اعتماده.');
    }

    public function pay(Request $request, Payroll $payroll)
    {
        $validated = $request->validate(['date' => 'required|date', 'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id']);
        $this->payrolls->pay($payroll, $validated['bank_account_id'] ?? null, $validated['date']);

        return back()->with('success', 'تم صرف الرواتب');
    }

    public function storeAdjustment(Request $request, Payroll $payroll)
    {
        if ($payroll->status !== 'draft') {
            throw ValidationException::withMessages(['payroll' => 'المسير معتمد؛ سجّل التعديل في الشهر التالي.']);
        }
        $validated = $request->validate([
            'employee_id' => 'required|tenant_exists:employees,id',
            'type' => 'required|in:bonus,commission,overtime_hours,penalty,deduction',
            'amount' => 'required|numeric|gt:0',
            'description' => 'nullable|string|max:500',
        ]);
        // Art. 66: a fine for one violation may not exceed five days' wage.
        if ($validated['type'] === 'penalty') {
            $employee = Employee::findOrFail($validated['employee_id']);
            $daily = ((float) $employee->basic_salary + (float) $employee->housing_allowance + (float) $employee->transport_allowance + (float) $employee->other_allowances)
                / (int) CompanyPolicy::get('hr.month_days', 30);
            if ((float) $validated['amount'] > round($daily * 5, 2) + 0.005) {
                throw ValidationException::withMessages(['amount' => 'الجزاء عن المخالفة الواحدة لا يتجاوز أجر خمسة أيام ('.number_format($daily * 5, 2).') وفق المادة 66.']);
            }
        }
        EmployeeAdjustment::create($validated + ['year' => $payroll->year, 'month' => $payroll->month, 'created_by' => auth()->id()]);
        $this->payrolls->recalculate($payroll);

        return back()->with('success', 'تم تسجيل التعديل وإعادة الاحتساب');
    }

    public function destroyAdjustment(Payroll $payroll, EmployeeAdjustment $adjustment)
    {
        abort_unless($payroll->status === 'draft' && $adjustment->year == $payroll->year && $adjustment->month == $payroll->month, 422);
        $adjustment->delete();
        $this->payrolls->recalculate($payroll);

        return back()->with('success', 'تم حذف التعديل');
    }

    /**
     * Salary file for Mudad (?format=mudad) or the company's bank
     * (?format=bank). Refused with the list of employees to fix when the
     * file would be rejected on upload.
     */
    public function wps(Request $request, Payroll $payroll)
    {
        $bank = $request->query('format') === 'bank';
        if ($issues = $this->payrolls->wpsIssues($payroll)) {
            return back()->withErrors(['wps' => implode("\n", array_slice($issues, 0, 30))]);
        }

        $csv = $bank ? $this->payrolls->bankFile($payroll) : $this->payrolls->wpsFile($payroll);
        $name = ($bank ? 'BANK-' : 'WPS-').$payroll->payroll_number.'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function payslip(Payroll $payroll, PayrollItem $item)
    {
        abort_unless((int) $item->payroll_id === (int) $payroll->id, 404);

        return Inertia::render('HR/Payroll/Payslip', [
            'payroll' => $payroll,
            'item' => $item->load('employee:id,name,employee_number,job_title,national_id,iban,bank_name,hire_date'),
            'company' => app(TenantManager::class)->getCompany()?->only(['name', 'tax_number', 'commercial_registration']),
        ]);
    }
}
