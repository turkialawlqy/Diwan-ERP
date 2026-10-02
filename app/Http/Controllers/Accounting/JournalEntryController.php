<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class JournalEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $query = JournalEntry::with(['lines.account', 'creator'])->latest('date');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        $entries = $query->paginate(15)->withQueryString();

        $stats = [
            'total_entries' => JournalEntry::count(),
            'total_volume' => JournalEntry::sum('total_debit'),
            'posted_count' => JournalEntry::where('status', 'posted')->count(),
        ];

        $accounts = Account::where('is_parent', false)->orderBy('code')->get(['id', 'code', 'name_ar', 'type']);

        return Inertia::render('Accounting/Journals/Index', [
            'entries' => $entries,
            'stats' => $stats,
            'accounts' => $accounts,
            'filters' => $request->only(['search']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'description' => 'required|string',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|tenant_exists:accounts,id',
            'lines.*.description' => 'nullable|string',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
        ]);

        $totalDebit = collect($validated['lines'])->sum('debit');
        $totalCredit = collect($validated['lines'])->sum('credit');

        if (abs($totalDebit - $totalCredit) > 0.01) {
            return back()->withErrors(['lines' => 'القيد غير متوازن! مجموع المدين يجب أن يساوي مجموع الدائن تماماً.']);
        }

        return DB::transaction(function () use ($validated, $totalDebit, $totalCredit) {
            $entryNumber = $this->nextNumber('journal_entry', $validated['date']);

            $entry = JournalEntry::create([
                'entry_number' => $entryNumber,
                'date' => $validated['date'],
                'reference' => $validated['reference'] ?? null,
                'description' => $validated['description'],
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'status' => 'posted',
                'created_by' => auth()->id(),
            ]);

            foreach ($validated['lines'] as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'description' => $line['description'] ?? $validated['description'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);

                // Update account balance
                $acc = Account::find($line['account_id']);
                if ($acc) {
                    if ($acc->nature === 'debit') {
                        $acc->increment('balance', $line['debit'] - $line['credit']);
                    } else {
                        $acc->increment('balance', $line['credit'] - $line['debit']);
                    }
                }
            }

            return redirect()->route('accounting.journals.index')
                ->with('success', "تم ترحيل القيد اليومي بنجاح برقم {$entryNumber}");
        });
    }

    public function show(JournalEntry $entry): Response
    {
        $entry->load(['lines.account', 'creator', 'branch']);

        return Inertia::render('Accounting/Journals/Show', [
            'entry' => $entry,
        ]);
    }
}
