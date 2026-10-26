<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Revenue;
use App\Services\Accounting\DocumentPoster;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RevenueController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Revenue::with(['account', 'bankAccount', 'creator'])->latest('revenue_date');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('revenue_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        $revenues = $query->paginate(15)->withQueryString();

        $stats = [
            'total_revenues' => Revenue::sum('total_amount'),
            'total_tax' => Revenue::sum('tax_amount'),
            'count' => Revenue::count(),
            'categories' => Revenue::distinct()->pluck('category'),
        ];

        $revenueAccounts = Account::where('type', 'revenue')->where('is_parent', false)->get(['id', 'code', 'name_ar']);
        $bankAccounts = BankAccount::withLedgerBalances(BankAccount::where('is_active', true)->get(['id', 'name', 'type', 'account_id', 'current_balance']));

        return Inertia::render('Finance/Revenues/Index', [
            'revenues' => $revenues,
            'stats' => $stats,
            'revenueAccounts' => $revenueAccounts,
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
            'revenue_date' => 'required|date',
            'payment_method' => 'required|string',
            'reference_number' => 'nullable|string|max:100',
            'customer_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // No VAT unless the voucher is marked taxable (government fees, salaries,
        // unregistered vendors carry none).
        $tax = ($validated['taxable'] ?? false) ? (float) ($validated['tax_amount'] ?? round($validated['amount'] * TaxProfile::rate() / 100, 2)) : 0.0;
        $total = $validated['amount'] + $tax;

        $revenue = DB::transaction(function () use ($validated, $tax, $total) {
            $revenueNumber = $this->nextNumber('revenue', $validated['revenue_date'] ?? null);

            $revenue = Revenue::create([
                'revenue_number' => $revenueNumber,
                'account_id' => $validated['account_id'] ?? null,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'cost_center_id' => $validated['cost_center_id'] ?? null,
                'title' => $validated['title'],
                'category' => $validated['category'],
                'amount' => $validated['amount'],
                'tax_amount' => $tax,
                'total_amount' => $total,
                'revenue_date' => $validated['revenue_date'],
                'payment_method' => $validated['payment_method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'customer_name' => $validated['customer_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Add to bank account balance if specified
            if (! empty($validated['bank_account_id'])) {
                $bank = BankAccount::find($validated['bank_account_id']);
                if ($bank) {
                    $bank->increment('current_balance', $total);
                }
            }

            app(DocumentPoster::class)->revenue($revenue);

            return $revenue;
        });
        $revenueNumber = $revenue->revenue_number;

        return redirect()->route('finance.revenues.index')
            ->with('success', "تم تسجيل سند الإيراد بنجاح برقم {$revenueNumber}");
    }

    public function destroy(Revenue $revenue)
    {
        // Cancelling a voucher reverses its journal entry; the audit log keeps the record.
        DB::transaction(function () use ($revenue) {
            app(DocumentPoster::class)->reverse($revenue, 'إلغاء سند رقم '.$revenue->revenue_number);

            if ($revenue->bank_account_id) {
                $bank = BankAccount::find($revenue->bank_account_id);
                if ($bank) {
                    $bank->decrement('current_balance', $revenue->total_amount);
                }
            }

            $revenue->delete();
        });

        return redirect()->route('finance.revenues.index')
            ->with('success', 'تم حذف سند الإيراد بنجاح');
    }
}
