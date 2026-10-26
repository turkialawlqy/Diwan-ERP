<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FileController;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\EmployeeSalaryChange;
use App\Models\JobPosition;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\HR\BankSalaryFormats;
use App\Services\HR\EmployeeFinanceService;
use App\Services\HR\LeaveService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'department_id' => 'nullable|integer', 'status' => 'nullable|in:active,on_leave,suspended,terminated']);
        $canSalaries = $request->user()->can('hr.salaries.view');

        $employees = Employee::with(['branch:id,name_ar', 'departmentRef:id,name', 'position:id,title'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v), fn ($q) => $q->where('status', '!=', 'terminated'))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('employee_number', 'like', "%{$s}%")->orWhere('national_id', 'like', "%{$s}%")->orWhere('job_title', 'like', "%{$s}%")))
            ->orderBy('name')->paginate($this->perPage($request))->withQueryString();

        if (! $canSalaries) {
            $employees->getCollection()->each->makeHidden(['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'total_salary', 'iban']);
        }

        $soon = now()->addDays(60);

        return Inertia::render('HR/Employees/Index', [
            'employees' => $employees,
            'stats' => [
                'active' => Employee::where('status', 'active')->count(),
                'saudi' => Employee::where('status', '!=', 'terminated')->where('nationality', 'SA')->count(),
                'non_saudi' => Employee::where('status', '!=', 'terminated')->where('nationality', '!=', 'SA')->count(),
                'monthly_payroll' => $canSalaries ? (float) Employee::where('status', 'active')->sum('total_salary') : null,
            ],
            'expiring' => collect()
                ->merge(Employee::where('status', '!=', 'terminated')->whereNotNull('id_expiry')->whereDate('id_expiry', '<=', $soon)->get(['id', 'name', 'id_type', 'id_expiry'])
                    ->map(fn ($e) => ['employee_id' => $e->id, 'name' => $e->name, 'type' => $e->id_type, 'expiry' => $e->id_expiry?->toDateString()]))
                ->merge(EmployeeDocument::with('employee:id,name,status')->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $soon)->get()
                    ->filter(fn ($d) => $d->employee && $d->employee->status !== 'terminated')
                    ->map(fn ($d) => ['employee_id' => $d->employee_id, 'name' => $d->employee->name, 'type' => $d->type, 'expiry' => $d->expiry_date?->toDateString()]))
                ->sortBy('expiry')->values()->take(30),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('HR/Employees/Form', $this->formOptions() + ['employee' => null]);
    }

    public function store(Request $request)
    {
        $employee = Employee::create($this->validated($request));

        return redirect()->route('hr.employees.show', $employee->id)->with('success', 'تم تسجيل الموظف');
    }

    public function edit(Request $request, Employee $employee): Response
    {
        if (! $request->user()->can('hr.salaries.view')) {
            $employee->makeHidden(self::SALARY_FIELDS);
        }

        return Inertia::render('HR/Employees/Form', $this->formOptions() + ['employee' => $employee]);
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $this->validated($request, $employee);
        if (($validated['status'] ?? null) === 'terminated' && $employee->status !== 'terminated'
            && EmployeeLoan::where('employee_id', $employee->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['status' => 'للموظف سلف قائمة؛ أنهِ خدمته من شاشة المخالصة لتُخصم السلف ويُقيَّد المستحق.']);
        }
        DB::transaction(function () use ($request, $employee, $validated) {
            $this->recordSalaryChange($request, $employee, $validated);
            $employee->update($validated);
        });

        return redirect()->route('hr.employees.show', $employee->id)->with('success', 'تم تحديث بيانات الموظف');
    }

    public function show(Request $request, Employee $employee, LeaveService $leaves, EmployeeFinanceService $finance): Response
    {
        $canSalaries = $request->user()->can('hr.salaries.view');
        $employee->load(['branch:id,name_ar', 'departmentRef:id,name', 'position:id,title', 'manager:id,name', 'shift:id,name', 'user:id,name,email',
            'documents', 'payItems', 'leaveRequests.type:id,name', 'loans', 'salaryChanges.creator:id,name']);
        $employee->documents->each(fn ($d) => $d->url = $d->file_path ? route('files.show', ['token' => Crypt::encryptString($d->file_path)], false) : null);
        if (! $canSalaries) {
            $employee->makeHidden(self::SALARY_FIELDS);
        }

        $month = Carbon::parse($request->input('month', now()->format('Y-m').'-01'));

        return Inertia::render('HR/Employees/Show', [
            'employee' => $employee,
            'leaveBalance' => $leaves->balance($employee),
            'attendance' => AttendanceRecord::where('employee_id', $employee->id)->whereYear('date', $month->year)->whereMonth('date', $month->month)->orderBy('date')->get(),
            'month' => $month->format('Y-m'),
            'payslips' => $canSalaries ? PayrollItem::with('payroll:id,payroll_number,year,month,status')->where('employee_id', $employee->id)->latest('id')->limit(24)->get() : [],
            'gratuity' => $canSalaries && $employee->hire_date ? $finance->gratuity($employee, now(), 'termination') : null,
        ]);
    }

    public function destroy(Employee $employee)
    {
        if (PayrollItem::where('employee_id', $employee->id)->exists()) {
            return back()->with('error', 'لا يمكن حذف موظف له مسيرات رواتب. أنهِ خدمته بمخالصة.');
        }
        $employee->delete();

        return redirect()->route('hr.employees.index')->with('success', 'تم حذف ملف الموظف');
    }

    public function storeDocument(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'type' => 'required|in:iqama,passport,contract,medical_insurance,driving_license,certificate,other',
            'number' => 'nullable|string|max:100',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'file' => 'nullable|file|max:10240|mimes:'.FileController::DOCUMENT_MIMES,
        ]);

        if ($request->hasFile('file')) {
            $validated['file_path'] = FileController::storeForTenant($request->file('file'), 'employees/'.$employee->id)['path'];
        }
        unset($validated['file']);
        $employee->documents()->create($validated);

        return back()->with('success', 'تم حفظ الوثيقة');
    }

    public function destroyDocument(Employee $employee, EmployeeDocument $document)
    {
        abort_unless((int) $document->employee_id === (int) $employee->id, 404);
        $document->delete();

        return back()->with('success', 'تم حذف الوثيقة');
    }

    public function storePayItem(Request $request, Employee $employee)
    {
        abort_unless($request->user()->can('hr.salaries.view'), 403);
        $employee->payItems()->create($request->validate([
            'kind' => 'required|in:allowance,deduction',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'gosi_applicable' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]));

        return back()->with('success', 'تم حفظ البند');
    }

    public function destroyPayItem(Request $request, Employee $employee, EmployeePayItem $item)
    {
        abort_unless($request->user()->can('hr.salaries.view'), 403);
        abort_unless((int) $item->employee_id === (int) $employee->id, 404);
        $item->delete();

        return back()->with('success', 'تم حذف البند');
    }

    /**
     * A pay change is kept with its effective date (the payroll prorates a
     * change in mid-month). A date inside an approved payroll is refused:
     * the difference for a closed month is paid as a bonus instead.
     */
    protected function recordSalaryChange(Request $request, Employee $employee, array $validated): void
    {
        $previous = array_map(fn ($f) => round((float) $employee->getRawOriginal($f), 2), array_combine(EmployeeSalaryChange::FIELDS, EmployeeSalaryChange::FIELDS));
        $new = array_map(fn ($f) => round((float) ($validated[$f] ?? $previous[$f]), 2), array_combine(EmployeeSalaryChange::FIELDS, EmployeeSalaryChange::FIELDS));
        if (! $request->user()->can('hr.salaries.view') || $previous == $new) {
            return;
        }

        $data = $request->validate([
            'salary_effective_date' => 'required|date',
            'salary_change_reason' => 'nullable|string|max:255',
        ], ['salary_effective_date.required' => 'حدد تاريخ سريان تعديل الراتب.']);
        $effective = Carbon::parse($data['salary_effective_date']);
        $closed = Payroll::whereIn('status', ['approved', 'paid'])
            ->where(fn ($q) => $q->where('year', '>', $effective->year)->orWhere(fn ($q) => $q->where('year', $effective->year)->where('month', '>=', $effective->month)))
            ->whereHas('items', fn ($q) => $q->where('employee_id', $employee->id))->exists();
        if ($closed) {
            throw ValidationException::withMessages(['salary_effective_date' => 'يوجد مسير معتمد من هذا التاريخ؛ اجعل السريان بعده وأضف فرق الأشهر السابقة كمكافأة.']);
        }

        EmployeeSalaryChange::create($new + [
            'employee_id' => $employee->id, 'effective_date' => $effective->toDateString(), 'previous' => $previous,
            'reason' => $data['salary_change_reason'] ?? null, 'created_by' => $request->user()->id,
        ]);
    }

    /** Pay and bank details only users with hr.salaries.view may see or change. */
    public const SALARY_FIELDS = ['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'total_salary', 'iban', 'bank_name', 'bank_code', 'payItems', 'loans', 'salaryChanges'];

    protected function validated(Request $request, ?Employee $employee = null): array
    {
        $companyId = app(TenantManager::class)->getTenantId();
        $canSalaries = $request->user()->can('hr.salaries.view');
        $idPrefix = ['national_id' => '1', 'iqama' => '2', 'border_number' => '[34]'][$request->input('id_type')] ?? '\d';
        $rules = [
            'employee_number' => ['nullable', 'string', 'max:30', Rule::unique('employees')->where('company_id', $companyId)->ignore($employee?->id)],
            'name' => 'required|string|max:255',
            'nationality' => 'required|string|size:2',
            'gender' => 'nullable|in:M,F',
            'birth_date' => 'nullable|date',
            'marital_status' => 'nullable|string|max:20',
            'id_type' => 'required|in:national_id,iqama,border_number',
            'national_id' => ['nullable', 'string', 'regex:/^'.$idPrefix.'\d{9}$/', Rule::unique('employees')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($employee?->id)],
            'id_expiry' => 'nullable|date',
            'passport_number' => 'nullable|string|max:30',
            'passport_expiry' => 'nullable|date',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'job_title' => 'required|string|max:255',
            'profession' => 'nullable|string|max:255',
            'department_id' => 'nullable|tenant_exists:departments,id',
            'position_id' => 'nullable|tenant_exists:job_positions,id',
            'manager_id' => 'nullable|tenant_exists:employees,id',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'shift_id' => 'nullable|tenant_exists:work_shifts,id',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'user_id' => ['nullable', Rule::exists('users', 'id')->where('company_id', $companyId)],
            'hire_date' => 'required|date',
            'contract_type' => 'required|in:limited,unlimited',
            'contract_end' => 'nullable|required_if:contract_type,limited|date|after:hire_date',
            'probation_end' => 'nullable|date',
            'basic_salary' => 'required|numeric|min:0',
            'housing_allowance' => 'nullable|numeric|min:0',
            'transport_allowance' => 'nullable|numeric|min:0',
            'other_allowances' => 'nullable|numeric|min:0',
            'gosi_registered' => 'boolean',
            'gosi_number' => 'nullable|string|max:30',
            'gosi_join_date' => 'nullable|date',
            'annual_leave_days' => 'nullable|integer|min:21|max:60',
            'leave_opening_balance' => 'nullable|numeric',
            'bank_name' => 'nullable|string|max:100',
            'bank_code' => 'nullable|string|max:10',
            'iban' => ['nullable', function ($attribute, $value, $fail) {
                if (! BankSalaryFormats::validIban($value)) {
                    $fail('رقم الآيبان غير صحيح: 24 خانة تبدأ بـ SA، وتحقق من صحة الأرقام.');
                }
            }],
            'status' => 'required|in:active,on_leave,suspended,terminated',
            'notes' => 'nullable|string|max:5000',
        ];
        // Without salary access the pay fields are neither read nor overwritten.
        if (! $canSalaries) {
            $rules = array_diff_key($rules, array_flip(self::SALARY_FIELDS));
        }
        $validated = $request->validate($rules, [
            'national_id.regex' => 'رقم الهوية 10 أرقام: يبدأ بـ 1 للمواطن، و2 للإقامة، و3 أو 4 لرقم الحدود.',
            'national_id.unique' => 'رقم الهوية مسجل لموظف آخر.',
        ]);
        if (isset($validated['iban'])) {
            $validated['iban'] = strtoupper(str_replace(' ', '', $validated['iban']));
        }
        if (! $canSalaries) {
            $validated['department'] = ! empty($validated['department_id']) ? Department::find($validated['department_id'])->name : ($employee?->department ?? 'عام');
            $validated['annual_leave_days'] ??= 21;
            $validated['employee_number'] ??= $employee?->employee_number ?? $this->nextNumber('employee');
            if (! $employee) {
                $validated += ['basic_salary' => 0, 'housing_allowance' => 0, 'transport_allowance' => 0, 'other_allowances' => 0, 'total_salary' => 0];
            }

            return $validated;
        }

        $validated['department'] = ! empty($validated['department_id']) ? Department::find($validated['department_id'])->name : ($employee?->department ?? 'عام');
        $validated['total_salary'] = round((float) $validated['basic_salary'] + (float) ($validated['housing_allowance'] ?? 0)
            + (float) ($validated['transport_allowance'] ?? 0) + (float) ($validated['other_allowances'] ?? 0), 2);
        $validated['annual_leave_days'] ??= 21;
        $validated['employee_number'] ??= $employee?->employee_number ?? $this->nextNumber('employee');

        return $validated;
    }

    protected function formOptions(): array
    {
        return [
            'branches' => Branch::where('is_active', true)->get(['id', 'name_ar']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => JobPosition::orderBy('title')->get(['id', 'title']),
            'shifts' => WorkShift::orderBy('name')->get(['id', 'name']),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'managers' => Employee::where('status', '!=', 'terminated')->orderBy('name')->limit(500)->get(['id', 'name']),
            'users' => User::where('company_id', app(TenantManager::class)->getTenantId())->orderBy('name')->get(['id', 'name', 'email']),
        ];
    }
}
