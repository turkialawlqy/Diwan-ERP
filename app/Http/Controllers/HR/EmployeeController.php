<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Employee::with('branch')->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('job_title', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('national_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('department')) {
            $query->where('department', $request->input('department'));
        }

        $employees = $query->paginate(15)->withQueryString();

        $stats = [
            'total_employees' => Employee::count(),
            'active_employees' => Employee::where('status', 'active')->count(),
            'total_monthly_payroll' => Employee::where('status', 'active')->sum('total_salary'),
            'departments' => Employee::distinct()->pluck('department'),
        ];

        $branches = Branch::where('is_active', true)->get(['id', 'name_ar', 'name_en']);

        return Inertia::render('HR/Employees/Index', [
            'employees' => $employees,
            'stats' => $stats,
            'branches' => $branches,
            'filters' => $request->only(['search', 'department']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'national_id' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'job_title' => 'required|string|max:255',
            'department' => 'required|string|max:100',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'hire_date' => 'nullable|date',
            'basic_salary' => 'required|numeric|min:0',
            'housing_allowance' => 'nullable|numeric|min:0',
            'transport_allowance' => 'nullable|numeric|min:0',
            'other_allowances' => 'nullable|numeric|min:0',
            'bank_name' => 'nullable|string|max:100',
            'iban' => 'nullable|string|max:50',
            'status' => 'required|in:active,on_leave,terminated',
            'notes' => 'nullable|string',
        ]);

        $basic = $validated['basic_salary'];
        $housing = $validated['housing_allowance'] ?? 0;
        $transport = $validated['transport_allowance'] ?? 0;
        $other = $validated['other_allowances'] ?? 0;
        $total = $basic + $housing + $transport + $other;

        Employee::create(array_merge($validated, [
            'total_salary' => $total,
        ]));

        return redirect()->route('hr.employees.index')
            ->with('success', 'تم تسجيل الموظف بنجاح في المنظومة');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();

        return redirect()->route('hr.employees.index')
            ->with('success', 'تم حذف ملف الموظف بنجاح');
    }
}
