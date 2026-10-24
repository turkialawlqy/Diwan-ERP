<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Services\Sales\SalesCycleService;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DebitNoteController extends Controller
{
    public function __construct(protected SalesCycleService $cycle) {}

    public function index(Request $request)
    {
        $search = $request->input('search');

        $notes = DebitNote::with(['customer:id,name', 'invoice:id,invoice_number'])
            ->when($search, fn ($q, $s) => $q->where(fn ($q) => $q->where('debit_note_number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('debit_note_date')->orderByDesc('id')
            ->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Sales/DebitNotes/Index', ['notes' => $notes, 'filters' => ['search' => $search]]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            // ZATCA: a debit note always references the invoice it corrects.
            'invoice_id' => 'required|tenant_exists:invoices,id',
            'debit_note_date' => 'required|date|before_or_equal:today',
            'reason' => 'required|string|max:500',
            'subtotal' => 'required|numeric|gt:0',
            'tax_rate' => ['nullable', 'numeric', TaxProfile::rateRule()], // a rate of the country, or zero-rated/exempt
        ], ['invoice_id.required' => 'اختر الفاتورة الأصلية التي يعدّلها الإشعار المدين.']);

        if (! empty($validated['invoice_id']) && (int) Invoice::find($validated['invoice_id'])->customer_id !== (int) $validated['customer_id']) {
            throw ValidationException::withMessages(['invoice_id' => 'الفاتورة المحددة لا تخص هذا العميل.']);
        }

        $note = $this->cycle->debitNote($validated);

        return back()->with('success', "تم إصدار الإشعار المدين {$note->debit_note_number}");
    }
}
