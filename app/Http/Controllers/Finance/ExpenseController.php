<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Services\Accounting\DocumentPoster;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Expense::with(['account', 'bankAccount', 'creator'])->latest('expense_date');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('expense_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('vendor_name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        $expenses = $query->paginate(15)->withQueryString();

        $stats = [
            'total_expenses' => Expense::sum('total_amount'),
            'total_tax' => Expense::sum('tax_amount'),
            'count' => Expense::count(),
            'categories' => Expense::distinct()->pluck('category'),
        ];

        $expenseAccounts = Account::where('type', 'expense')->where('is_parent', false)->get(['id', 'code', 'name_ar']);
        $bankAccounts = BankAccount::withLedgerBalances(BankAccount::where('is_active', true)->get(['id', 'name', 'type', 'account_id', 'current_balance']));

        return Inertia::render('Finance/Expenses/Index', [
            'expenses' => $expenses,
            'stats' => $stats,
            'expenseAccounts' => $expenseAccounts,
            'bankAccounts' => $bankAccounts,
            'filters' => $request->only(['search', 'category']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'account_id' => 'nullable|tenant_exists:accounts,id',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'category' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01',
            'tax_amount' => 'nullable|numeric|min:0',
            'taxable' => 'boolean',
            'vendor_tax_number' => ['nullable', 'string', 'max:30', fn ($attribute, $value, $fail) => TaxProfile::current()->validTaxNumber($value) ? null : $fail(TaxProfile::current()->taxNumberHint())],
            'expense_date' => 'required|date',
            'payment_method' => 'required|string',
            'reference_number' => 'nullable|string|max:100',
            'vendor_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // No VAT unless the voucher is marked taxable (government fees, salaries,
        // unregistered vendors carry none).
        $tax = ($validated['taxable'] ?? false) ? (float) ($validated['tax_amount'] ?? round($validated['amount'] * TaxProfile::rate() / 100, 2)) : 0.0;
        if ($tax > 0 && empty($validated['vendor_tax_number'])) {
            throw ValidationException::withMessages(['vendor_tax_number' => 'ضريبة المدخلات تُسترد فقط بفاتورة ضريبية من مورد مسجل: أدخل الرقم الضريبي للمورد.']);
        }
        $total = $validated['amount'] + $tax;

        $expense = DB::transaction(function () use ($validated, $tax, $total) {
            $expenseNumber = $this->nextNumber('expense', $validated['expense_date'] ?? null);

            $expense = Expense::create([
                'expense_number' => $expenseNumber,
                'account_id' => $validated['account_id'] ?? null,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'cost_center_id' => $validated['cost_center_id'] ?? null,
                'title' => $validated['title'],
                'category' => $validated['category'],
                'amount' => $validated['amount'],
                'tax_amount' => $tax,
                'total_amount' => $total,
                'expense_date' => $validated['expense_date'],
                'payment_method' => $validated['payment_method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'vendor_name' => $validated['vendor_name'] ?? null,
                'vendor_tax_number' => $validated['vendor_tax_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Deduct from bank account balance if specified
            if (! empty($validated['bank_account_id'])) {
                $bank = BankAccount::find($validated['bank_account_id']);
                if ($bank) {
                    $bank->decrement('current_balance', $total);
                }
            }

            app(DocumentPoster::class)->expense($expense);

            return $expense;
        });
        $expenseNumber = $expense->expense_number;

        return redirect()->route('finance.expenses.index')
            ->with('success', "تم تسجيل سند المصروف بنجاح برقم {$expenseNumber}");
    }

    public function destroy(Expense $expense)
    {
        // Cancelling a voucher reverses its journal entry; the audit log keeps the record.
        DB::transaction(function () use ($expense) {
            app(DocumentPoster::class)->reverse($expense, 'إلغاء سند رقم '.$expense->expense_number);

            // Refund bank account balance if specified
            if ($expense->bank_account_id) {
                $bank = BankAccount::find($expense->bank_account_id);
                if ($bank) {
                    $bank->increment('current_balance', $expense->total_amount);
                }
            }

            $expense->delete();
        });

        return redirect()->route('finance.expenses.index')
            ->with('success', 'تم حذف سند المصروف بنجاح');
    }
}
