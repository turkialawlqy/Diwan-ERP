<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Services\Accounting\DocumentPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $payrolls = Payroll::with(['creator', 'items.employee'])->latest()->paginate(15);

        $stats = [
            'total_payrolls_count' => Payroll::count(),
            'total_paid_amount' => Payroll::sum('net_total'),
            'current_month_payroll' => Payroll::where('month', (int) date('m'))->where('year', (int) date('Y'))->value('net_total') ?? 0,
        ];

        $employees = Employee::where('status', 'active')->get(['id', 'name', 'job_title', 'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'total_salary']);

        return Inertia::render('HR/Payroll/Index', [
            'payrolls' => $payrolls,
            'stats' => $stats,
            'employees' => $employees,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2030',
            'notes' => 'nullable|string',
        ]);

        if (Payroll::where('month', $validated['month'])->where('year', $validated['year'])->exists()) {
            return back()->withErrors(['month' => 'تم إصدار مسير رواتب لهذا الشهر مسبقاً.']);
        }

        $activeEmployees = Employee::where('status', 'active')->get();

        if ($activeEmployees->isEmpty()) {
            return back()->withErrors(['employees' => 'لا يوجد موظفون نشطون لإصدار مسير الرواتب.']);
        }

        return DB::transaction(function () use ($validated, $activeEmployees) {
            $payrollNumber = $this->nextNumber('payroll');

            $totalBasic = $activeEmployees->sum('basic_salary');
            $totalAllowances = $activeEmployees->sum(fn ($e) => $e->housing_allowance + $e->transport_allowance + $e->other_allowances);
            $netTotal = $totalBasic + $totalAllowances;

            $payroll = Payroll::create([
                'payroll_number' => $payrollNumber,
                'month' => $validated['month'],
                'year' => $validated['year'],
                'total_basic' => $totalBasic,
                'total_allowances' => $totalAllowances,
                'total_deductions' => 0.00,
                'net_total' => $netTotal,
                'status' => 'approved',
                'payment_date' => Carbon::create($validated['year'], $validated['month'], 1)->endOfMonth()->toDateString(),
                'notes' => $validated['notes'] ?? "مسير رواتب شهر {$validated['month']}-{$validated['year']}",
                'created_by' => auth()->id(),
            ]);

            foreach ($activeEmployees as $emp) {
                PayrollItem::create([
                    'payroll_id' => $payroll->id,
                    'employee_id' => $emp->id,
                    'basic_salary' => $emp->basic_salary,
                    'allowances' => $emp->housing_allowance + $emp->transport_allowance + $emp->other_allowances,
                    'deductions' => 0.00,
                    'net_salary' => $emp->total_salary,
                    'notes' => 'راتب كامل مسير معتمد',
                ]);
            }

            // Accrue the payroll in the ledger (salary expense / salaries payable).
            app(DocumentPoster::class)->payroll($payroll);

            return redirect()->route('hr.payroll.index')
                ->with('success', "تم توليد واعتماد مسير الرواتب بنجاح برقم {$payrollNumber}");
        });
    }
}
