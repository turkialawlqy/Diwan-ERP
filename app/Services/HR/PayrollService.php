<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\EmployeeLoan;
use App\Models\EndOfServiceSettlement;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\Documents\DocumentNumberService;
use App\Services\Settings\CompanyPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll: draft (recalculable) → approved (accrued in the ledger,
 * loans and adjustments consumed) → paid (salaries settled from the bank).
 *
 *   Approve   Dr Salaries expense (by cost centre)   Dr GOSI expense (employer)
 *             Cr Salaries payable (net)   Cr GOSI payable (both shares)   Cr Employee advances (loans)
 *   Pay       Dr Salaries payable   Cr Bank
 */
class PayrollService
{
    public function __construct(
        protected PayrollCalculator $calculator,
        protected DocumentNumberService $numbers,
        protected PostingService $posting,
        protected DocumentPoster $poster,
    ) {}

    public function generate(int $year, int $month, ?string $notes = null): Payroll
    {
        return DB::transaction(function () use ($year, $month, $notes) {
            if (Payroll::where('year', $year)->where('month', $month)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['month' => 'يوجد مسير رواتب لهذا الشهر. أعد احتسابه بدلاً من إنشاء مسير جديد.']);
            }

            $payroll = Payroll::create([
                'payroll_number' => $this->numbers->next('payroll', Carbon::create($year, $month, 1)),
                'year' => $year,
                'month' => $month,
                'status' => 'draft',
                'payment_date' => Carbon::create($year, $month, 1)->endOfMonth()->toDateString(),
                'notes' => $notes ?? "مسير رواتب {$month}/{$year}",
                'created_by' => auth()->id(),
            ]);

            $this->fill($payroll);

            return $payroll;
        });
    }

    /** Recalculate a draft (after attendance / adjustments changed). */
    public function recalculate(Payroll $payroll): void
    {
        DB::transaction(function () use ($payroll) {
            $payroll = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            if ($payroll->status !== 'draft') {
                throw ValidationException::withMessages(['payroll' => 'لا يمكن إعادة احتساب مسير معتمد.']);
            }
            PayrollItem::where('payroll_id', $payroll->id)->delete();
            $this->fill($payroll);
        });
    }

    protected function fill(Payroll $payroll): void
    {
        $from = Carbon::create($payroll->year, $payroll->month, 1);
        $to = $from->copy()->endOfMonth();

        // Leavers whose final settlement already pays this month's days are left out,
        // so the last days are never paid twice.
        $settled = EndOfServiceSettlement::whereIn('status', ['approved', 'paid'])->where('salary_due', '>', 0)
            ->whereDate('termination_date', '>=', $from)->whereDate('termination_date', '<=', $to)->pluck('employee_id');

        $employees = Employee::with('departmentRef:id,cost_center_id')
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $to))
            ->where(fn ($q) => $q->where('status', '!=', 'terminated')->orWhereDate('termination_date', '>=', $from))
            ->whereNotIn('id', $settled)
            ->orderBy('id')->get();

        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['employees' => 'لا يوجد موظفون في الخدمة خلال هذا الشهر.']);
        }

        foreach ($employees as $employee) {
            $payroll->items()->create($this->calculator->calculate($employee, $payroll->year, $payroll->month));
        }

        $this->refreshTotals($payroll);
    }

    public function refreshTotals(Payroll $payroll): void
    {
        $items = PayrollItem::where('payroll_id', $payroll->id)->get();
        $payroll->update([
            'total_basic' => round($items->sum('basic_salary'), 2),
            'total_allowances' => round($items->sum('allowances') + $items->sum('bonuses') - $items->sum('absence_deduction'), 2),
            'total_overtime' => round($items->sum('overtime'), 2),
            'total_deductions' => round($items->sum('deductions'), 2),
            'total_gosi_employee' => round($items->sum('gosi_employee'), 2),
            'total_gosi_employer' => round($items->sum('gosi_employer'), 2),
            'total_loans' => round($items->sum('loan_deduction'), 2),
            'net_total' => round($items->sum('net_salary'), 2),
        ]);
    }

    public function approve(Payroll $payroll): void
    {
        DB::transaction(function () use ($payroll) {
            $payroll = Payroll::with('items')->whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            if ($payroll->status !== 'draft') {
                throw ValidationException::withMessages(['payroll' => 'المسير معتمد مسبقاً.']);
            }

            $this->postAccrual($payroll);

            // Loans repaid and adjustments consumed.
            foreach ($payroll->items->where('loan_deduction', '>', 0) as $item) {
                $remaining = (float) $item->loan_deduction;
                foreach (EmployeeLoan::where('employee_id', $item->employee_id)->where('status', 'active')->orderBy('date')->lockForUpdate()->get() as $loan) {
                    $take = min($remaining, (float) $loan->amount - (float) $loan->repaid);
                    if ($take <= 0) {
                        continue;
                    }
                    $repaid = round((float) $loan->repaid + $take, 2);
                    $loan->update(['repaid' => $repaid, 'status' => $repaid >= (float) $loan->amount - 0.005 ? 'settled' : 'active']);
                    $remaining = round($remaining - $take, 2);
                }
            }
            EmployeeAdjustment::where('year', $payroll->year)->where('month', $payroll->month)->whereNull('payroll_id')->update(['payroll_id' => $payroll->id]);

            $payroll->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    /**
     * Back to draft before the salaries are paid (e.g. an error found after
     * approval): the accrual is reversed, the loan instalments it recovered
     * are restored and the month's adjustments released for recalculation.
     */
    public function unapprove(Payroll $payroll): void
    {
        DB::transaction(function () use ($payroll) {
            $payroll = Payroll::with('items')->whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            if ($payroll->status !== 'approved') {
                throw ValidationException::withMessages(['payroll' => $payroll->status === 'paid'
                    ? 'المسير مصروف؛ لا يمكن إلغاء اعتماده. سجّل التصحيح في مسير الشهر التالي.'
                    : 'المسير غير معتمد.']);
            }
            $later = Payroll::whereIn('status', ['approved', 'paid'])
                ->where(fn ($q) => $q->where('year', '>', $payroll->year)->orWhere(fn ($q) => $q->where('year', $payroll->year)->where('month', '>', $payroll->month)))->exists();
            if ($later) {
                throw ValidationException::withMessages(['payroll' => 'يوجد مسير معتمد لشهر لاحق؛ ألغِ اعتماده أولاً.']);
            }

            $this->posting->reverseSource($payroll, 'إلغاء اعتماد مسير '.$payroll->payroll_number);

            // Instalments come back off the most recent loans first, mirroring approve().
            foreach ($payroll->items->where('loan_deduction', '>', 0) as $item) {
                $remaining = (float) $item->loan_deduction;
                foreach (EmployeeLoan::where('employee_id', $item->employee_id)->whereIn('status', ['active', 'settled'])->where('repaid', '>', 0)
                    ->orderByDesc('date')->orderByDesc('id')->lockForUpdate()->get() as $loan) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $back = min($remaining, (float) $loan->repaid);
                    $loan->update(['repaid' => round((float) $loan->repaid - $back, 2), 'status' => 'active']);
                    $remaining = round($remaining - $back, 2);
                }
            }
            EmployeeAdjustment::where('payroll_id', $payroll->id)->update(['payroll_id' => null]);

            $payroll->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
        });
    }

    /**
     * Accrual entry of a payroll: salary expense per cost centre, GOSI, loan
     * repayments, fines held for the workers' fund and the net payable.
     */
    public function postAccrual(Payroll $payroll): JournalEntry
    {
        $payroll->loadMissing('items');
        $date = Carbon::create($payroll->year, $payroll->month, 1)->endOfMonth()->toDateString();
        $lines = [];

        // Salary expense per cost centre (gross less recoveries); disciplinary fines go to the workers' fund.
        $fines = round($payroll->items->sum(fn ($i) => (float) ($i->breakdown['penalties'] ?? 0)), 2);
        foreach ($payroll->items->groupBy(fn ($i) => $i->cost_center_id ?? 0) as $cc => $items) {
            $expense = round($items->sum('gross_salary') - $items->sum('other_deductions') + $items->sum(fn ($i) => (float) ($i->breakdown['penalties'] ?? 0)), 2);
            if ($expense != 0.0) {
                $lines[] = ['role' => 'salaries_expense', 'debit' => $expense, 'cost_center_id' => $cc ?: null, 'description' => 'رواتب وبدلات الشهر'];
            }
            $employer = round($items->sum('gosi_employer'), 2);
            if ($employer > 0) {
                $lines[] = ['role' => 'gosi_expense', 'debit' => $employer, 'cost_center_id' => $cc ?: null, 'description' => 'حصة المنشأة في التأمينات'];
            }
        }

        $gosi = round((float) $payroll->total_gosi_employee + (float) $payroll->total_gosi_employer, 2);
        if ($gosi > 0) {
            $lines[] = ['role' => 'gosi_payable', 'credit' => $gosi, 'description' => 'اشتراكات التأمينات المستحقة'];
        }
        foreach ($payroll->items->where('loan_deduction', '>', 0) as $item) {
            $lines[] = ['role' => 'employee_advances', 'credit' => (float) $item->loan_deduction, 'party_type' => 'employee', 'party_id' => $item->employee_id, 'description' => 'استقطاع سلفة'];
        }
        if ($fines > 0) {
            $lines[] = ['role' => 'workers_fund', 'credit' => $fines, 'description' => 'جزاءات محسومة لصندوق العمال'];
        }
        $lines[] = ['role' => 'salaries_payable', 'credit' => (float) $payroll->net_total, 'description' => 'صافي الرواتب المستحقة'];

        return $this->posting->post([
            'date' => $date,
            'reference' => $payroll->payroll_number,
            'description' => "استحقاق رواتب شهر {$payroll->month}/{$payroll->year}",
            'source' => $payroll,
            'lines' => array_values(array_filter($lines, fn ($l) => ($l['debit'] ?? 0) > 0 || ($l['credit'] ?? 0) > 0)),
        ]);
    }

    public function pay(Payroll $payroll, ?int $bankAccountId, string $date): void
    {
        DB::transaction(function () use ($payroll, $bankAccountId, $date) {
            $payroll = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            if ($payroll->status !== 'approved') {
                throw ValidationException::withMessages(['payroll' => 'يجب اعتماد المسير قبل الصرف.']);
            }

            $this->posting->post([
                'date' => $date,
                'reference' => $payroll->payroll_number,
                'description' => "صرف رواتب شهر {$payroll->month}/{$payroll->year}",
                'source' => $payroll,
                'lines' => [
                    ['role' => 'salaries_payable', 'debit' => (float) $payroll->net_total],
                    ['account_id' => $this->poster->cashAccount($bankAccountId, 'bank_transfer'), 'credit' => (float) $payroll->net_total],
                ],
            ]);

            $payroll->update(['status' => 'paid', 'bank_account_id' => $bankAccountId, 'payment_date' => $date, 'paid_at' => now()]);
        });
    }

    /**
     * One row per employee paid in the payroll, in the units the Mudad and
     * bank files use: deductions are whatever separates the earnings from
     * the net pay, so every row satisfies basic + housing + other - deductions = net.
     */
    public function wpsRows(Payroll $payroll): array
    {
        $payroll->loadMissing('items.employee');

        return $payroll->items->filter(fn ($item) => (float) $item->net_salary > 0)->map(function ($item) {
            $e = $item->employee;
            $iban = strtoupper(str_replace(' ', '', (string) $e?->iban));
            $basic = round((float) $item->basic_salary, 2);
            $housing = round((float) $item->housing_allowance, 2);
            $other = round((float) $item->transport_allowance + (float) $item->other_allowances + (float) $item->overtime + (float) $item->bonuses, 2);
            $net = round((float) $item->net_salary, 2);

            return [
                'employee_id' => $e?->id,
                'employee_number' => (string) $e?->employee_number,
                'national_id' => trim((string) $e?->national_id),
                'name' => (string) $e?->name,
                'iban' => $iban,
                'bank_code' => (string) ($e?->bank_code ?: BankSalaryFormats::bankCode($iban)),
                'basic' => $basic,
                'housing' => $housing,
                'other' => $other,
                'deductions' => round($basic + $housing + $other - $net, 2),
                'net' => $net,
            ];
        })->values()->all();
    }

    /** Problems that would make Mudad or the bank reject the salary file. */
    public function wpsIssues(Payroll $payroll): array
    {
        $issues = [];
        $rows = $this->wpsRows($payroll);
        foreach ($rows as $row) {
            $who = $row['name'] ?: '#'.$row['employee_id'];
            if (! preg_match('/^[12]\d{9}$/', $row['national_id'])) {
                $issues[] = "{$who}: رقم الهوية/الإقامة يجب أن يكون 10 أرقام يبدأ بـ 1 أو 2.";
            }
            if (! BankSalaryFormats::validIban($row['iban'])) {
                $issues[] = "{$who}: رقم الآيبان ".($row['iban'] ?: '(فارغ)').' غير صحيح.';
            }
            if ($row['deductions'] < 0) {
                $issues[] = "{$who}: صافي الراتب يتجاوز مجموع الأجر والبدلات.";
            }
        }
        $total = round(array_sum(array_column($rows, 'net')), 2);
        if (abs($total - round((float) $payroll->net_total, 2)) > 0.01) {
            $issues[] = 'مجموع صافي الملف '.number_format($total, 2).' لا يطابق صافي المسير '.number_format((float) $payroll->net_total, 2).'؛ أعد احتساب المسير.';
        }

        return $issues;
    }

    /**
     * Wage Protection salary file in the Mudad upload template.
     */
    public function wpsFile(Payroll $payroll): string
    {
        $establishment = (string) CompanyPolicy::get('hr.wps.mol_establishment_id', '');
        $period = sprintf('%04d-%02d', $payroll->year, $payroll->month);
        $rows = [['Employee ID', 'Employee Name', 'Bank Code', 'IBAN', 'Basic Salary', 'Housing Allowance', 'Other Earnings', 'Deductions', 'Net Salary', 'Payment Period', 'Establishment ID']];
        foreach ($this->wpsRows($payroll) as $r) {
            $rows[] = [$r['national_id'], $r['name'], $r['bank_code'], $r['iban'],
                ...array_map(fn ($v) => number_format($v, 2, '.', ''), [$r['basic'], $r['housing'], $r['other'], $r['deductions'], $r['net']]),
                $period, $establishment];
        }

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            // Neutralise spreadsheet formulas in text cells.
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r|]/', $v) ? "'".$v : $v, $row));
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /** Salary upload file in the layout of the company's bank (hr.wps.bank_format). */
    public function bankFile(Payroll $payroll): string
    {
        return app(BankSalaryFormats::class)->render((string) CompanyPolicy::get('hr.wps.bank_format', 'alrajhi'), [
            'mol' => (string) CompanyPolicy::get('hr.wps.mol_establishment_id', ''),
            'gosi' => (string) CompanyPolicy::get('hr.wps.gosi_establishment_id', ''),
            'company_iban' => (string) CompanyPolicy::get('hr.wps.company_iban', ''),
            'value_date' => $payroll->payment_date ? Carbon::parse($payroll->payment_date) : now(),
            'reference' => (string) $payroll->payroll_number,
        ], $this->wpsRows($payroll));
    }
}
