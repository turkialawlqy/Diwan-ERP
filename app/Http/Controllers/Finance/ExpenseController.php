<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\BudgetService;
use App\Services\Accounting\DimensionPolicy;
use App\Services\Accounting\DocumentPoster;
use App\Services\ActiveBranch;
use App\Services\Finance\PaymentRequestService;
use App\Services\Tax\TaxProfile;
use App\Services\Workflow\ApprovalGate;
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

        $expenses = $query->inActiveYear('expense_date')->paginate(15)->withQueryString();

        $stats = [
            'total_expenses' => Expense::sum('total_amount'),
            'total_tax' => Expense::sum('tax_amount'),
            'count' => Expense::count(),
            'categories' => Expense::distinct()->pluck('category'),
        ];

        $expenseAccounts = Account::where('type', 'expense')->where('is_parent', false)->get(['id', 'code', 'name_ar', 'requires_cost_center']);
        $bankAccounts = BankAccount::withLedgerBalances(BankAccount::where('is_active', true)->get(['id', 'name', 'type', 'account_id', 'current_balance']));

        return Inertia::render('Finance/Expenses/Index', [
            'expenses' => $expenses,
            'stats' => $stats,
            'expenseAccounts' => $expenseAccounts,
            'bankAccounts' => $bankAccounts,
            'filters' => $request->only(['search', 'category']),
        ]);
    }

    public function create(Request $request): Response
    {
        $prefill = null;
        if ($request->filled('payment_request')) {
            $paymentRequest = PaymentRequest::where('status', 'approved')->where('kind', 'expense')->find($request->integer('payment_request'));
            $prefill = $paymentRequest ? app(PaymentRequestService::class)->prefill($paymentRequest) : null;
        }

        return Inertia::render('Finance/Expenses/Form', [
            'prefill' => $prefill,
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'dimensionPolicy' => app(DimensionPolicy::class)->modes(),
            'expenseAccounts' => Account::where('type', 'expense')->where('is_parent', false)->get(['id', 'code', 'name_ar', 'requires_cost_center']),
            'bankAccounts' => BankAccount::withLedgerBalances(BankAccount::where('is_active', true)->get(['id', 'name', 'type', 'account_id', 'current_balance'])),
        ]);
    }

    public function store(Request $request, ?Expense $replace = null)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'account_id' => 'nullable|tenant_exists:accounts,id',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'project_id' => 'nullable|tenant_exists:projects,id',
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
            'payment_request_id' => 'nullable|tenant_exists:payment_requests,id',
        ]);
        $requests = app(PaymentRequestService::class);
        $requestId = $replace ? null : ($validated['payment_request_id'] ?? null);

        // No VAT unless the voucher is marked taxable (government fees, salaries,
        // unregistered vendors carry none).
        $tax = ($validated['taxable'] ?? false) ? (float) ($validated['tax_amount'] ?? round($validated['amount'] * TaxProfile::rate() / 100, 2)) : 0.0;
        if ($tax > 0 && empty($validated['vendor_tax_number'])) {
            throw ValidationException::withMessages(['vendor_tax_number' => 'ضريبة المدخلات تُسترد فقط بفاتورة ضريبية من مورد مسجل: أدخل الرقم الضريبي للمورد.']);
        }
        if ($tax > 0 && ! TaxProfile::current()->vatMatches((float) $validated['amount'], $tax)) {
            throw ValidationException::withMessages(['tax_amount' => 'الضريبة يجب أن تكون '.TaxProfile::current()->ratesLabel().' من المبلغ قبل الضريبة.']);
        }
        $total = $validated['amount'] + $tax;
        // A voucher made for an approved payment request needs no second approval: the request's approval is it.
        if (! $requests->covering($requestId, 'expense', (float) $total)) {
            app(ApprovalGate::class)->check('expense', ApprovalGate::keyFor(['t' => $validated['title'], 'a' => round((float) $validated['amount'], 2), 'd' => $validated['expense_date'] ?? null, 'acc' => (int) ($validated['account_id'] ?? 0), 'r' => $replace?->id]), (float) $total, 'مصروف: '.$validated['title']);
        }

        $budgetWarning = app(BudgetService::class)->check((int) ($validated['account_id'] ?? app(AccountMap::class)->id('general_expense')), $validated['expense_date'] ?? now(), (float) $validated['amount'], 'مصروف', ['cost_center_id' => $validated['cost_center_id'] ?? null, 'project_id' => $validated['project_id'] ?? null, 'branch_id' => $validated['branch_id'] ?? app(ActiveBranch::class)->forWrite()]);

        $expense = DB::transaction(function () use ($validated, $tax, $total, $replace, $requests, $requestId) {
            // Editing: undo the old voucher (entry and box balance), keep its record and number.
            if ($replace) {
                $this->undo($replace, 'تعديل سند رقم '.$replace->expense_number);
            }
            $expenseNumber = $replace?->expense_number ?? $this->nextNumber('expense', $validated['expense_date'] ?? null);

            $attributes = [
                'expense_number' => $expenseNumber,
                'account_id' => $validated['account_id'] ?? null,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'cost_center_id' => $validated['cost_center_id'] ?? null,
                'project_id' => $validated['project_id'] ?? null,
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
                'created_by' => $replace?->created_by ?? auth()->id(),
            ];
            $expense = $replace ? tap($replace)->update($attributes) : Expense::create($attributes);

            // Deduct from bank account balance if specified
            if (! empty($validated['bank_account_id'])) {
                $bank = BankAccount::find($validated['bank_account_id']);
                if ($bank) {
                    $bank->decrement('current_balance', $total);
                }
            }

            app(DocumentPoster::class)->expense($expense);
            if ($paid = $requests->covering($requestId, 'expense', (float) $total)) {
                $requests->markPaid($paid, $expense);
            }

            return $expense;
        });
        $expenseNumber = $expense->expense_number;

        return redirect()->route('finance.expenses.index')
            ->with('success', $replace ? "تم تعديل سند المصروف رقم {$expenseNumber}" : "تم تسجيل سند المصروف بنجاح برقم {$expenseNumber}")
            ->with($budgetWarning ? ['warning' => $budgetWarning] : []);
    }

    public function destroy(Expense $expense)
    {
        // Cancelling a voucher reverses its journal entry; the audit log keeps the record.
        DB::transaction(function () use ($expense) {
            $this->undo($expense, 'إلغاء سند رقم '.$expense->expense_number);
            $expense->delete();
        });

        return redirect()->route('finance.expenses.index')
            ->with('success', 'تم حذف سند المصروف بنجاح');
    }

    public function edit(Expense $expense): Response
    {
        return $this->create()->with('expense', $expense);
    }

    public function update(Request $request, Expense $expense)
    {
        return $this->store($request, $expense);
    }

    /** Reverse the voucher's entry and give its amount back to the cash / bank box. */
    protected function undo(Expense $expense, string $description): void
    {
        app(DocumentPoster::class)->reverse($expense, $description);
        if ($expense->bank_account_id && ($bank = BankAccount::find($expense->bank_account_id))) {
            $bank->increment('current_balance', $expense->total_amount);
        }
    }
}
